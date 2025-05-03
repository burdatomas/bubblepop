const express = require('express');
const { jobLogger } = require('./config');
const { initBrowser } = require('./browserSetup');
const { enhancedStealthScrape } = require('./scraper');
const { execSync } = require('child_process');

const log = jobLogger.child({ module: 'server' });
const app = express();
app.use(express.json());

const SUPPORTED_CURRENCIES = ['CZK', 'EUR', 'PLN'];
const PORT = 3000;
let browser = null;

async function initializeBrowserWithRetry(maxRetries = 3, retryDelay = 5000) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      log.debug({ message: `Initializing browser, attempt ${attempt}/${maxRetries}` });
      browser = await initBrowser();
      if (browser && typeof browser.newPage === 'function') {
        log.info({ message: 'Browser initialized successfully' });
        return browser;
      }
      throw new Error('Invalid browser instance');
    } catch (error) {
      log.warn({
        message: `Browser initialization failed, attempt ${attempt}/${maxRetries}`,
        error: error.message
      });
      if (browser) {
        try {
          await browser.close();
        } catch (closeError) {
          log.warn({ message: 'Failed to close browser during retry', error: closeError.message });
        }
        browser = null;
      }
      if (attempt < maxRetries) {
        await new Promise(resolve => setTimeout(resolve, retryDelay));
      } else {
        throw error;
      }
    }
  }
}

async function cleanupChromeProcesses() {
  try {
    execSync('pkill -9 -u scraper-user -f chrome || true');
  } catch (error) {
    log.warn({
      message: 'Failed to clean up Chrome processes/profiles',
      error: error.message
    });
  }
}

app.get('/health', async (req, res) => {
  try {
    const memoryUsage = process.memoryUsage();
    const systemMemory = {
      systemTotal: (require('os').totalmem() / 1024 / 1024).toFixed(2) + ' MB',
      systemFree: (require('os').freemem() / 1024 / 1024).toFixed(2) + ' MB',
      swapUsed: (execSync('free | grep Swap | awk \'{print $3/1024}\'').toString().trim() + ' MB'),
      diskFree: (execSync('df -m / | tail -1 | awk \'{print $4}\'').toString().trim() + ' MB')
    };
    log.info({ message: 'Memory and disk check', metadata: { ...memoryUsage, ...systemMemory, path: req.path } });
    res.json({
      status: 'OK',
      puppeteerVersion: require('puppeteer/package.json').version,
      uptime: process.uptime(),
      memory: {
        rss: (memoryUsage.rss / 1024 / 1024).toFixed(2) + ' MB',
        heapTotal: (memoryUsage.heapTotal / 1024 / 1024).toFixed(2) + ' MB',
        heapUsed: (memoryUsage.heapUsed / 1024 / 1024).toFixed(2) + ' MB',
        external: (memoryUsage.external / 1024 / 1024).toFixed(2) + ' MB',
        ...systemMemory
      }
    });
  } catch (error) {
    log.error({ message: 'Health check failed', error: error.message });
    res.status(500).json({ error: 'Health check failed', debug: error.message });
  }
});

async function withRetry(operation, maxRetries = 3, retryDelay = 5000) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      return await operation();
    } catch (error) {
      log.warn({
        message: `Attempt ${attempt}/${maxRetries} failed for ${operation.name}`,
        error: error.message,
        stack: error.stack
      });
      if (attempt < maxRetries) {
        await new Promise(resolve => setTimeout(resolve, retryDelay));
      } else {
        throw error;
      }
    }
  }
}

app.post('/scrape', async (req, res) => {
  const { url, methods, currency } = req.body;
  if (!url || !methods || !currency || !SUPPORTED_CURRENCIES.includes(currency)) {
    return res.status(400).json({ error: 'Invalid request parameters' });
  }

  try {
    await cleanupChromeProcesses();
    const localBrowser = await initializeBrowserWithRetry();
    if (!localBrowser || typeof localBrowser.newPage !== 'function') {
      throw new Error('Failed to initialize a valid browser instance');
    }

    const memoryUsage = process.memoryUsage();
    const systemMemory = {
      systemTotal: (require('os').totalmem() / 1024 / 1024).toFixed(2) + ' MB',
      systemFree: (require('os').freemem() / 1024 / 1024).toFixed(2) + ' MB',
      swapUsed: (execSync('free | grep Swap | awk \'{print $3/1024}\'').toString().trim() + ' MB'),
      diskFree: (execSync('df -m / | tail -1 | awk \'{print $4}\'').toString().trim() + ' MB')
    };
    log.info({
      message: 'Memory and disk check',
      metadata: { ...memoryUsage, ...systemMemory, operation: 'scrape', url }
    });

    const results = await withRetry(async () => {
      return await enhancedStealthScrape({ browser: localBrowser, url, methods, currency });
    });

    res.json(results);
  } catch (error) {
    log.error({
      message: 'Scraping failed',
      error: error.message,
      stack: error.stack,
      url,
      freeMemory: (require('os').freemem() / 1024 / 1024).toFixed(2) + ' MB',
      memoryUsage: {
        ...Object.fromEntries(
          Object.entries(process.memoryUsage()).map(([k, v]) => [k, (v / 1024 / 1024).toFixed(2) + ' MB'])
        ),
        systemTotal: (require('os').totalmem() / 1024 / 1024).toFixed(2) + ' MB',
        systemFree: (require('os').freemem() / 1024 / 1024).toFixed(2) + ' MB',
        swapUsed: (execSync('free | grep Swap | awk \'{print $3/1024}\'').toString().trim() + ' MB'),
        diskFree: (execSync('df -m / | tail -1 | awk \'{print $4}\'').toString().trim() + ' MB')
      }
    });
    res.status(500).json({ error: error.message, debug: error.message });
  } finally {
    if (browser) {
      try {
        await browser.close();
        log.info({ message: 'Browser closed successfully' });
      } catch (error) {
        log.warn({ message: 'Failed to close browser', error: error.message });
      }
      browser = null;
    }
    await cleanupChromeProcesses();
  }
});

app.listen(PORT, '0.0.0.0', () => {
  log.info({
    message: 'Price scraper server running',
    metadata: {
      url: `http://0.0.0.0:${PORT}`,
      endpoints: [
        'POST /scrape',
        'POST /scrape-batch',
        'POST /scrape-html',
        'POST /validate-selectors',
        'GET /health',
        'GET /metrics',
        'POST /debug-state'
      ],
      puppeteerVersion: require('puppeteer/package.json').version,
      supportedCurrencies: SUPPORTED_CURRENCIES
    }
  });
});

process.on('SIGTERM', async () => {
  log.info({ message: 'Received shutdown signal, closing server' });
  if (browser) {
    try {
      await browser.close();
      log.info({ message: 'Browser closed successfully' });
    } catch (error) {
      log.warn({ message: 'Failed to close browser during shutdown', error: error.message });
    }
    browser = null;
  }
  await cleanupChromeProcesses();
  process.exit(0);
});