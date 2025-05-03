const puppeteer = require('puppeteer');
const { jobLogger } = require('./config');

const log = jobLogger.child({ module: 'browserSetup' });

async function initBrowser() {
  log.debug({ message: 'Initializing browser' });
  try {
    const browser = await puppeteer.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-extensions',
        '--window-size=1920,1080'
      ],
      defaultViewport: { width: 1920, height: 1080 }
    });
    log.info({ message: 'Browser launched successfully' });
    return browser;
  } catch (error) {
    log.error({
      message: 'Failed to launch browser',
      error: error.message,
      stack: error.stack,
      operation: 'initBrowser'
    });
    throw error;
  }
}

async function initPage(browser) {
  log.debug({ message: 'Initializing page' });
  try {
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.setExtraHTTPHeaders({
      'Accept-Language': 'en-US,en;q=0.9'
    });
    log.info({ message: 'Page initialized successfully' });
    return page;
  } catch (error) {
    log.error({
      message: 'Failed to initialize page',
      error: error.message,
      stack: error.stack
    });
    throw error;
  }
}

module.exports = { initBrowser, initPage };