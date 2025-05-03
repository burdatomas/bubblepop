const { jobLogger } = require('./config');
const { initPage } = require('./browserSetup');
const { execSync } = require('child_process');
const fs = require('fs');

const log = jobLogger.child({ module: 'scraper' });

async function stealthScrape({ browser, url, methods, currency }) {
  log.debug({ message: 'Starting stealth scrape', url });
  return await performStealthScrape({ browser, url, methods, currency, stealthLevel: 'basic' });
}

async function spoofBrowserFingerprint(page) {
  try {
    await page.evaluateOnNewDocument(() => {
      const getParameter = WebGLRenderingContext.prototype.getParameter;
      WebGLRenderingContext.prototype.getParameter = function(parameter) {
        if (parameter === 37445) return 'Intel Inc.';
        if (parameter === 37446) return 'Intel Iris OpenGL Engine';
        return getParameter.apply(this, arguments);
      };

      const toDataURL = HTMLCanvasElement.prototype.toDataURL;
      HTMLCanvasElement.prototype.toDataURL = function() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 1, 1);
        return toDataURL.apply(this, arguments);
      };

      Object.defineProperty(navigator, 'webdriver', { get: () => false });
      Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
    });

    const userAgents = [
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36'
    ];
    await page.setUserAgent(userAgents[Math.floor(Math.random() * userAgents.length)]);
    
    const acceptLanguages = ['en-US,en;q=0.9', 'en-GB,en;q=0.8', 'cs-CZ,cs;q=0.9'];
    const acceptHeaders = [
      'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
      'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
    ];
    await page.setExtraHTTPHeaders({
      'Accept': acceptHeaders[Math.floor(Math.random() * acceptHeaders.length)],
      'Accept-Language': acceptLanguages[Math.floor(Math.random() * acceptLanguages.length)],
      'Sec-Fetch-Site': 'none',
      'Sec-Fetch-Mode': 'navigate',
      'Sec-Fetch-User': '?1',
      'Sec-Fetch-Dest': 'document'
    });

    log.debug({ message: 'Browser fingerprint and headers spoofed' });
  } catch (error) {
    log.warn({ message: 'Failed to spoof browser fingerprint or headers', error: error.message });
  }
}

async function simulateHumanInteractions(page) {
  try {
    await page.evaluate(() => {
      const scrollStep = () => {
        window.scrollBy(0, 100);
        if (window.scrollY < document.body.scrollHeight - window.innerHeight) {
          setTimeout(scrollStep, 100);
        }
      };
      scrollStep();
    });

    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, Math.random() * 2000 + 2000)));

    log.debug({ message: 'Human interactions simulated' });
  } catch (error) {
    log.warn({ message: 'Failed to simulate human interactions', error: error.message });
  }
}

async function waitForDynamicContent(page, selectors, timeout = 30000) {
  try {
    const challengeDetails = await page.evaluate(() => {
      const title = document.querySelector('title')?.textContent || '';
      const isChallenge = title.includes('Just a moment...') || 
                         document.querySelector('div#challenge-spinner') !== null ||
                         document.querySelector('.footer__box-text') !== null ||
                         window.location.href.includes('__cf_chl');
      if (isChallenge) return {
        rayId: document.querySelector('.footer__box-text:not(a)')?.textContent || 'Unknown',
        timestamp: document.querySelector('#footer-date-value')?.textContent || 'Unknown',
        ip: document.querySelector('.footer__box-text:not(a):not(#footer-date-value)')?.textContent || 'Unknown'
      };
      return null;
    });

    if (challengeDetails) {
      log.warn({
        message: 'Cloudflare challenge page detected, waiting for navigation',
        url: page.url(),
        rayId: challengeDetails.rayId,
        timestamp: challengeDetails.timestamp,
        ip: challengeDetails.ip
      });
      await Promise.race([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null),
        new Promise(resolve => setTimeout(resolve, 30000))
      ]);
    }

    for (const selector of selectors) {
      try {
        await page.waitForSelector(selector, { timeout });
        log.debug({ message: 'Selector found', selector });
      } catch (error) {
        log.warn({ message: 'Selector not found within timeout', selector, error: error.message });
        await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 5000)));
      }
    }
    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 5000)));
    log.debug({ message: 'Dynamic content loading attempted' });
  } catch (error) {
    log.warn({ message: 'Failed to wait for dynamic content', error: error.message });
  }
}

async function applyAdvancedStealth(page) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'platform', { 
        get: () => ['Win32', 'MacIntel', 'Linux x86_64'][Math.floor(Math.random() * 3)] 
      });
      Object.defineProperty(navigator, 'language', { 
        get: () => ['en-US', 'en-GB', 'cs-CZ'][Math.floor(Math.random() * 3)] 
      });
      Object.defineProperty(navigator, 'hardwareConcurrency', { 
        get: () => Math.floor(Math.random() * 4) + 2 
      });
      Object.defineProperty(navigator, 'deviceMemory', { 
        get: () => [4, 8, 16][Math.floor(Math.random() * 3)] 
      });
      
      const timezones = ['Europe/Prague', 'Europe/London', 'America/New_York'];
      Intl.DateTimeFormat = class extends Intl.DateTimeFormat {
        static resolvedOptions() {
          return { timeZone: timezones[Math.floor(Math.random() * 3)] };
        }
      };
    });
    log.debug({ message: 'Applied advanced stealth settings', properties: ['platform', 'language', 'hardwareConcurrency', 'deviceMemory', 'timezone'] });
  } catch (error) {
    log.error({ message: 'Failed to apply advanced stealth', error: error.message, stack: error.stack });
  }
}

async function simulateDynamicBehavior(page) {
  try {
    const { width, height } = await page.evaluate(() => ({
      width: window.innerWidth,
      height: window.innerHeight
    }));
    for (let i = 0; i < 2; i++) {
      const x = Math.random() * width * 0.6 + width * 0.2;
      const y = Math.random() * height * 0.6 + height * 0.2;
      await page.mouse.move(x, y, { steps: 15 });
      await page.mouse.click(x, y);
      log.debug({ message: 'Simulated dynamic click', x, y });
      await new Promise(resolve => setTimeout(resolve, 1000 + Math.random() * 1000));
    }
    const hoverX = Math.random() * width * 0.8 + width * 0.1;
    const hoverY = Math.random() * height * 0.8 + height * 0.1;
    await page.mouse.move(hoverX, hoverY, { steps: 10 });
    log.debug({ message: 'Simulated hover', x: hoverX, y: hoverY });
  } catch (error) {
    log.error({ message: 'Failed to simulate dynamic behavior', error: error.message, stack: error.stack });
  }
}

async function spoofBrowserFeatures(page) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'getUserMedia', { value: undefined });
      Object.defineProperty(navigator, 'webkitGetUserMedia', { value: undefined });
      Object.defineProperty(navigator, 'mozGetUserMedia', { value: undefined });
      
      navigator.geolocation.getCurrentPosition = (success) => {
        success({
          coords: {
            latitude: 50.0 + Math.random() * 10,
            longitude: 14.0 + Math.random() * 10
          }
        });
      };
      
      navigator.getBattery = () => Promise.resolve({
        charging: Math.random() > 0.3,
        level: Math.random() * 0.5 + 0.5,
        chargingTime: Math.floor(Math.random() * 3600),
        dischargingTime: Math.floor(Math.random() * 7200)
      });
      
      Object.defineProperty(navigator, 'connection', {
        value: {
          effectiveType: ['4g', '3g'][Math.floor(Math.random() * 2)],
          rtt: 50 + Math.floor(Math.random() * 100),
          downlink: 5 + Math.random() * 5
        }
      });
    });
    log.debug({ message: 'Spoofed browser features', features: ['WebRTC', 'geolocation', 'battery', 'connection'] });
  } catch (error) {
    log.error({ message: 'Failed to spoof browser features', error: error.message, stack: error.stack });
  }
}

async function enhancedStealthScrape({ browser, url, methods, currency }) {
  return await performStealthScrape({ browser, url, methods, currency, stealthLevel: 'enhanced' });
}

async function advancedStealthScrape({ browser, url, methods, currency }) {
  return await performStealthScrape({ browser, url, methods, currency, stealthLevel: 'advanced' });
}

async function superStealthScrape({ browser, url, methods, currency }) {
  return await performStealthScrape({ browser, url, methods, currency, stealthLevel: 'super' });
}

async function performStealthScrape({ browser, url, methods, currency, stealthLevel }) {
  log.debug({ message: `Starting ${stealthLevel} stealth scrape`, url });
  let page;
  let localBrowser;
  const maxAttempts = 3;
  let attempts = 0;
  const result = { url, currency, attempts: 0, current_price: null, original_price: null, voucher_price: null, sale_percentage: null, price_status: 'Standard', debug: '' };

  try {
    try {
      const fdCount = fs.readdirSync('/proc/self/fd').length;
      const memInfo = fs.readFileSync('/proc/meminfo', 'utf8');
      const freeMem = memInfo.match(/MemFree:\s+(\d+)/)?.[1] || 'Unknown';
      const chromeProcs = execSync('pgrep -u scraper-user chrome || true', { encoding: 'utf8' }).trim();
      log.debug({
        message: 'System resource check',
        fileDescriptors: fdCount,
        freeMemoryKB: freeMem,
        chromeProcesses: chromeProcs || 'None'
      });
      if (fdCount > 1000) {
        log.warn({ message: 'High file descriptor count, potential resource issue', fileDescriptors: fdCount });
      }
      if (chromeProcs) {
        log.warn({ message: 'Chrome processes running before scrape', chromeProcesses: chromeProcs });
      }
    } catch (error) {
      log.warn({ message: 'Failed to check system resources', error: error.message });
    }

    try {
      const psOutput = execSync('ps -u scraper-user -o pid,state,comm || true', { encoding: 'utf8' }).trim();
      log.debug({ message: 'Running processes for scraper-user before cleanup', psOutput });
    } catch (error) {
      log.warn({ message: 'Failed to log running processes', error: error.message });
    }

    try {
      const chromeProcs = execSync('pgrep -u scraper-user chrome || true', { encoding: 'utf8' }).trim();
      if (chromeProcs) {
        execSync(`sudo -u scraper-user kill -9 ${chromeProcs} || true`, { stdio: 'ignore' });
        log.debug({ message: 'Killed specific Chrome processes', pids: chromeProcs });
      }
      execSync('sudo -u scraper-user pkill -9 -u scraper-user || true', { stdio: 'ignore' });
      execSync('sudo -u scraper-user killall -9 chrome || true', { stdio: 'ignore' });
      execSync('sudo -u scraper-user pkill -9 -u scraper-user -f chrome || true', { stdio: 'ignore' });
      execSync('sudo -u scraper-user rm -rf /tmp/puppeteer_dev_chrome_profile-* || true', { stdio: 'ignore' });
      log.debug({ message: 'Cleaned up temporary Puppeteer profiles' });
      const allProcs = execSync('ps -u scraper-user -o pid= || true', { encoding: 'utf8' }).trim();
      if (allProcs) {
        execSync(`sudo -u scraper-user kill -9 ${allProcs} || true`, { stdio: 'ignore' });
        log.debug({ message: 'Fallback: Killed all scraper-user PIDs', pids: allProcs });
      }
      await new Promise(resolve => setTimeout(resolve, 10000));
      log.debug({ message: 'Cleaned up all Chrome processes and profiles' });
      const psOutput = execSync('ps aux | grep -v grep | grep chrome || true', { encoding: 'utf8' }).trim();
      if (psOutput) {
        log.warn({ message: 'Chrome processes still running after cleanup', psOutput });
      } else {
        log.debug({ message: 'No Chrome processes remaining after cleanup' });
      }
    } catch (error) {
      log.warn({ message: 'Failed to clean up Chrome processes or profiles', error: error.message });
    }

    let browserInstance = browser;
    let browserRetries = 0;
    const maxBrowserRetries = 2;

    if (!browserInstance || typeof browserInstance.newPage !== 'function') {
      while (browserRetries < maxBrowserRetries) {
        try {
          const puppeteer = require('/home/scraper-user/price-scraper/node_modules/puppeteer');
          localBrowser = await puppeteer.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-setuid-sandbox']
          });
          browserInstance = localBrowser;
          log.debug({ message: 'Local browser launched after retry', attempt: browserRetries + 1 });
          break;
        } catch (error) {
          browserRetries++;
          log.warn({
            message: 'Failed to launch local browser, retrying',
            attempt: browserRetries,
            error: error.message
          });
          await new Promise(resolve => setTimeout(resolve, 5000));
        }
      }
      if (!browserInstance) {
        throw new Error('Failed to initialize browser after retries');
      }
    }

    let pageRetries = 0;
    const maxPageRetries = 3;
    while (pageRetries < maxPageRetries) {
      try {
        page = await initPage(browserInstance);
        break;
      } catch (error) {
        pageRetries++;
        log.warn({
          message: 'Failed to initialize page, retrying',
          attempt: pageRetries,
          error: error.message
        });
        if (page) {
          try {
            await page.close();
          } catch (closeError) {
            log.warn({ message: 'Failed to close page during retry', error: closeError.message });
          }
        }
        await new Promise(resolve => setTimeout(resolve, 10000));
        if (pageRetries === maxPageRetries) {
          throw new Error(`Failed to initialize page after ${maxPageRetries} retries: ${error.message}`);
        }
      }
    }

    await page.setDefaultNavigationTimeout(40000);

    const viewports = [
      { width: 1920, height: 1080 },
      { width: 1366, height: 768 },
      { width: 1440, height: 900 },
      { width: 1280, height: 720 }
    ];
    const { width, height } = viewports[Math.floor(Math.random() * viewports.length)];
    await page.setViewport({ width, height, deviceScaleFactor: 1, isMobile: false, hasTouch: false });
    log.debug({ message: 'Viewport randomized', width, height });

    if (stealthLevel === 'basic' || stealthLevel === 'enhanced') {
      await spoofBrowserFingerprint(page);
    } else {
      await applyAdvancedStealth(page);
      await spoofBrowserFeatures(page);
    }

    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => false });
      window.chrome = { runtime: {} };
      Object.defineProperty(navigator, 'plugins', { 
        get: () => [{ name: 'Chrome PDF Plugin' }, { name: 'Chrome PDF Viewer' }] 
      });
      Object.defineProperty(navigator, 'mimeTypes', { 
        get: () => [{ type: 'application/pdf' }, { type: 'application/x-nacl' }] 
      });
      const originalGetContext = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function (...args) {
        const context = originalGetContext.apply(this, args);
        if (args[0] === 'webgl' || args[0] === 'webgl2') {
          const originalGetParameter = context.getParameter;
          context.getParameter = function (parameter) {
            if (parameter === 37446) return 'Intel Inc.';
            if (parameter === 37447) return 'Intel Iris OpenGL Engine';
            return originalGetParameter.call(this, parameter);
          };
        }
        return context;
      };
    });

    const redirectChain = [];
    let responseStatus = null;
    page.on('response', response => {
      if (response.url().includes('alza.cz') || response.url().includes('datart.cz') || response.url().includes('jrc.cz')) {
        redirectChain.push({ url: response.url(), status: response.status() });
        if (response.url() === url || response.url().startsWith(url)) {
          responseStatus = response.status();
        }
      }
    });

    const maxNavRetries = stealthLevel === 'super' ? 4 : 2;
    for (attempts = 1; attempts <= maxAttempts; attempts++) {
      let navigationSuccess = false;
      for (let i = 0; i < maxNavRetries; i++) {
        try {
          await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40000 });
          const currentUrl = page.url();
          if (currentUrl !== url && !currentUrl.startsWith(url)) {
            log.warn({ message: `Redirect detected on attempt ${i + 1}, retrying with new headers`, url, currentUrl });
            if (stealthLevel !== 'basic' && stealthLevel !== 'enhanced') {
              await applyAdvancedStealth(page);
            } else {
              await spoofBrowserFingerprint(page);
            }
            await new Promise(resolve => setTimeout(resolve, 5000));
            continue;
          }
          navigationSuccess = true;
          break;
        } catch (error) {
          log.warn({ message: `Navigation retry ${i + 1} failed`, url, error: error.message });
          result.debug += ` Attempt ${attempts} nav error: ${error.message};`;
          await new Promise(resolve => setTimeout(resolve, 5000));
        }
      }
      if (!navigationSuccess) {
        log.error({ message: 'Navigation failed after retries', url });
        result.debug += ' Navigation failed after retries;';
        return result;
      }

      await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 5000)));

      const finalUrl = page.url();
      if (finalUrl !== url && !finalUrl.startsWith(url)) {
        log.error({
          message: 'Unexpected redirect detected, marking as invalid page',
          originalUrl: url,
          finalUrl,
          redirectChain
        });
        result.debug += ` Unexpected redirect to ${finalUrl};`;
        return result;
      }

      const pageTitle = await page.evaluate(() => document.querySelector('title')?.textContent || 'Unknown');
      const isErrorPage = pageTitle.includes('503') || pageTitle.includes('404') || pageTitle.includes('Error');
      if (isErrorPage) {
        log.error({
          message: 'Error page detected, marking as invalid page',
          url,
          pageTitle,
          responseStatus
        });
        result.debug += ` Error page detected: ${pageTitle};`;
        return result;
      }

      if (stealthLevel === 'basic' || stealthLevel === 'enhanced') {
        await simulateHumanInteractions(page);
      } else {
        await randomizedHumanInteractions(page);
        if (stealthLevel === 'super') {
          await simulateDynamicBehavior(page);
          try {
            const searchInput = await page.$('input[type="search"], input[name="q"], input[placeholder*="search"]');
            if (searchInput) {
              const queries = ['test query', 'product search', 'price check'];
              const query = queries[Math.floor(Math.random() * queries.length)];
              await searchInput.type(query, { delay: 100 + Math.random() * 50 });
              await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 1000)));
              await searchInput.press('Backspace', { delay: 100 });
              log.debug({ message: 'Simulated typing in search bar', query });
            }
          } catch (error) {
            log.warn({ message: 'Failed to simulate typing', error: error.message });
          }
        }
      }

      const selectors = methods.filter(m => m.mode === 'css').map(m => m.parameter);
      const xpathSelectors = methods.filter(m => m.mode === 'xpath').map(m => m.parameter);
      await waitForDynamicContent(page, selectors);

      const isStillChallengePage = await page.evaluate(() => {
        const title = document.querySelector('title')?.textContent || '';
        return title.includes('Just a moment...') || 
               document.querySelector('div#challenge-spinner') !== null ||
               document.querySelector('.footer__box-text') !== null ||
               window.location.href.includes('__cf_chl');
      });

      if (isStillChallengePage) {
        const challengeDetails = {
          rayId: await page.evaluate(() => document.querySelector('.footer__box-text:not(a)')?.textContent || 'Unknown'),
          timestamp: await page.evaluate(() => document.querySelector('#footer-date-value')?.textContent || 'Unknown'),
          ip: await page.evaluate(() => document.querySelector('.footer__box-text:not(a):not(#footer-date-value)')?.textContent || 'Unknown')
        };
        log.error({
          message: 'Failed to bypass Cloudflare challenge page, marking as invalid page',
          url,
          rayId: challengeDetails.rayId,
          timestamp: challengeDetails.timestamp,
          ip: challengeDetails.ip
        });
        result.debug += ' Failed to bypass Cloudflare challenge;';
        return result;
      }

      const priceData = await page.evaluate((methods, currency) => {
        const results = [];
        for (const method of methods) {
          if (method.mode === 'css') {
            const element = document.querySelector(method.parameter);
            if (element) {
              results.push({ selector: method.parameter, value: element.textContent.trim() });
            } else {
              results.push({ selector: method.parameter, value: null, error: 'Selector not found' });
            }
          } else if (method.mode === 'xpath') {
            const elements = document.evaluate(method.parameter, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null);
            const element = elements.singleNodeValue;
            if (element) {
              results.push({ selector: method.parameter, value: element.textContent.trim() });
            } else {
              results.push({ selector: method.parameter, value: null, error: 'XPath selector not found' });
            }
          }
        }
        return results;
      }, methods, currency);

      const failedSelectors = priceData.filter(p => p.error);
      if (failedSelectors.length > 0) {
        log.warn({ message: 'Selectors failed to match', failedSelectors });
        result.debug += ` Failed selectors: ${JSON.stringify(failedSelectors)};`;
      }

      if (priceData.some(p => p.value)) {
        const cleanPrice = (text) => {
          if (!text) return null;
          const priceMatch = text.match(/[\d\s]+/);
          if (!priceMatch) return null;
          let price = priceMatch[0].replace(/\s/g, '');
          return isNaN(parseFloat(price)) ? null : price;
        };

        const prices = {
          current: cleanPrice(priceData.find(p => p.selector.includes('primary-price') || p.selector.includes('main-price') || p.selector.includes('buyBox-price'))?.value) || null,
          original: cleanPrice(priceData.find(p => p.selector.includes('compare-price') || p.selector.includes('price-before') || p.selector.includes('price-old'))?.value) || null,
          voucher: cleanPrice(priceData.find(p => p.selector.includes('promo') || p.selector.includes('minor-price') || p.selector.includes('pushBox'))?.value) || null
        };

        let currentPrice = prices.current;
        let originalPrice = prices.original;
        let voucherPrice = prices.voucher;
        let salePercentage = null;
        let priceStatus = 'Standard';

        if (currentPrice && originalPrice && parseFloat(currentPrice) === parseFloat(originalPrice)) {
          originalPrice = null;
        }
        if (currentPrice && voucherPrice && parseFloat(currentPrice) === parseFloat(voucherPrice)) {
          voucherPrice = null;
        }

        if (currentPrice && originalPrice && parseFloat(originalPrice) > parseFloat(currentPrice)) {
          salePercentage = Math.round(((parseFloat(originalPrice) - parseFloat(currentPrice)) / parseFloat(originalPrice)) * 100);
          priceStatus = 'Discount';
        } else if (currentPrice && voucherPrice && parseFloat(currentPrice) > parseFloat(voucherPrice)) {
          salePercentage = Math.round(((parseFloat(currentPrice) - parseFloat(voucherPrice)) / parseFloat(currentPrice)) * 100);
          priceStatus = 'Voucher';
        }

        result.current_price = currentPrice;
        result.original_price = originalPrice;
        result.voucher_price = voucherPrice;
        result.sale_percentage = salePercentage;
        result.price_status = priceStatus;
        result.debug += ` Extracted price data after ${attempts} attempts`;
        log.info({ message: 'Price data extracted', prices: { current: result.current_price, original: result.original_price, voucher: result.voucher_price, sale_percentage: result.sale_percentage, price_status: result.price_status } });
        return result;
      }

      log.warn({ message: 'No price data extracted, retrying', attempt: attempts });
      result.debug += ' No price data extracted;';
      await new Promise(resolve => setTimeout(resolve, 5000));
    }

    log.warn({ message: 'Max attempts reached, no price data extracted', url });
    result.debug += ' Max attempts reached, no price data;';
    return result;
  } catch (error) {
    log.error({
      message: `${stealthLevel.charAt(0).toUpperCase() + stealthLevel.slice(1)} stealth scrape failed`,
      url,
      error: error.message,
      stack: error.stack
    });
    result.debug += ` Final error: ${error.message};`;
    return result;
  } finally {
    result.attempts = attempts;
    await cleanupPageAndBrowser(page, browser || localBrowser, url);
  }
}

async function cleanupPageAndBrowser(page, browser, url) {
  if (page) {
    try {
      await page.close();
      log.debug({ message: 'Page closed', url });
    } catch (error) {
      log.warn({ message: 'Failed to close page', url, error: error.message });
    }
  }
  if (browser) {
    try {
      await browser.close();
      log.debug({ message: 'Browser closed', url });
    } catch (error) {
      log.warn({ message: 'Failed to close browser', url, error: error.message });
    }
  }
  try {
    let attempts = 0;
    const maxAttempts = 15;
    while (attempts < maxAttempts) {
      const chromeProcs = execSync('pgrep -u scraper-user chrome || true', { encoding: 'utf8' }).trim();
      if (chromeProcs) {
        try {
          execSync(`sudo -u scraper-user kill -9 ${chromeProcs}`, { stdio: 'ignore' });
          log.debug({ message: 'Killed specific Chrome processes post-scrape', pids: chromeProcs, attempt: attempts + 1 });
        } catch (killError) {
          log.warn({ message: 'Failed to kill specific Chrome processes', pids: chromeProcs, attempt: attempts + 1, error: killError.message });
        }
      }
      try {
        execSync('sudo -u scraper-user pkill -9 -u scraper-user', { stdio: 'ignore' });
        log.debug({ message: 'Killed all scraper-user processes', attempt: attempts + 1 });
      } catch (pkillError) {
        log.warn({ message: 'Failed to pkill scraper-user processes', attempt: attempts + 1, error: pkillError.message });
      }
      try {
        execSync('sudo -u scraper-user killall -9 chrome', { stdio: 'ignore' });
        log.debug({ message: 'Killed all Chrome processes', attempt: attempts + 1 });
      } catch (killallError) {
        log.warn({ message: 'Failed to killall Chrome processes', attempt: attempts + 1, error: killallError.message });
      }
      try {
        execSync('sudo -u scraper-user pkill -9 -u scraper-user -f chrome', { stdio: 'ignore' });
        log.debug({ message: 'Killed all Chrome processes by pattern', attempt: attempts + 1 });
      } catch (pkillChromeError) {
        log.warn({ message: 'Failed to pkill Chrome processes by pattern', attempt: attempts + 1, error: pkillChromeError.message });
      }
      try {
        execSync('sudo -u scraper-user rm -rf /tmp/puppeteer_dev_chrome_profile-* || true', { stdio: 'ignore' });
        log.debug({ message: 'Cleaned up temporary Puppeteer profiles', attempt: attempts + 1 });
      } catch (rmError) {
        log.warn({ message: 'Failed to clean up temporary Puppeteer profiles', attempt: attempts + 1, error: rmError.message });
      }
      const allProcs = execSync('ps -u scraper-user -o pid= || true', { encoding: 'utf8' }).trim();
      if (allProcs) {
        try {
          execSync(`sudo -u scraper-user kill -9 ${allProcs}`, { stdio: 'ignore' });
          log.debug({ message: 'Fallback: Killed all scraper-user PIDs post-scrape', pids: allProcs, attempt: attempts + 1 });
        } catch (fallbackError) {
          log.warn({ message: 'Failed fallback kill of scraper-user PIDs', pids: allProcs, attempt: attempts + 1, error: fallbackError.message });
        }
      }
      await new Promise(resolve => setTimeout(resolve, 10000));
      const psOutput = execSync('ps aux | grep -v grep | grep chrome || true', { encoding: 'utf8' }).trim();
      if (!psOutput) {
        log.debug({ message: 'No Chrome processes remaining after post-scrape cleanup', attempt: attempts + 1 });
        break;
      }
      log.warn({ message: 'Chrome processes still running after post-scrape cleanup', psOutput, attempt: attempts + 1 });
      attempts++;
    }
    if (attempts === maxAttempts) {
      log.error({ message: 'Failed to clean up Chrome processes after maximum attempts' });
    } else {
      log.debug({ message: 'Cleaned up all Chrome processes post-scrape' });
    }
  } catch (error) {
    log.warn({ message: 'Failed post-scrape Chrome process cleanup', error: error.message });
  }
  await new Promise(resolve => setTimeout(resolve, 10000));
}

async function randomizedHumanInteractions(page) {
  try {
    await page.evaluate(() => {
      const moveMouse = (x, y) => {
        const event = new MouseEvent('mousemove', {
          view: window,
          bubbles: true,
          cancelable: true,
          clientX: x,
          clientY: y
        });
        document.dispatchEvent(event);
      };
      const steps = 10;
      const startX = Math.random() * window.innerWidth;
      const startY = Math.random() * window.innerHeight;
      for (let i = 0; i < steps; i++) {
        setTimeout(() => {
          moveMouse(
            startX + (Math.random() - 0.5) * 200,
            startY + (Math.random() - 0.5) * 200
          );
        }, i * 100);
      }
    });

    await page.evaluate(() => {
      const scrollStep = () => {
        window.scrollBy(0, 100 + Math.random() * 50);
        if (window.scrollY < document.body.scrollHeight - window.innerHeight) {
          setTimeout(scrollStep, 200 + Math.random() * 300);
        }
      };
      scrollStep();
    });

    await page.evaluate(() => {
      const safeClick = () => {
        const x = Math.random() * window.innerWidth;
        const y = Math.random() * window.innerHeight;
        const clickEvent = new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          clientX: x,
          clientY: y
        });
        document.elementFromPoint(x, y)?.dispatchEvent(clickEvent);
      };
      setTimeout(safeClick, Math.random() * 2000 + 1000);
    });

    await page.evaluate(() => {
      const focusEvent = new FocusEvent('focus');
      const blurEvent = new FocusEvent('blur');
      setTimeout(() => window.dispatchEvent(focusEvent), Math.random() * 1000);
      setTimeout(() => window.dispatchEvent(blurEvent), Math.random() * 2000 + 2000);
      setTimeout(() => window.dispatchEvent(focusEvent), Math.random() * 3000 + 4000);
    });

    await page.evaluate(() => {
      const currentWidth = window.innerWidth;
      const currentHeight = window.innerHeight;
      const newWidth = currentWidth + Math.floor((Math.random() - 0.5) * 100);
      const newHeight = currentHeight + Math.floor((Math.random() - 0.5) * 100);
      setTimeout(() => window.resizeTo(newWidth, newHeight), Math.random() * 2000 + 1000);
    });

    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, Math.random() * 3000 + 2000)));

    log.debug({ message: 'Randomized human interactions simulated' });
  } catch (error) {
    log.warn({ message: 'Failed to simulate randomized human interactions', error: error.message });
  }
}

module.exports = { stealthScrape, enhancedStealthScrape, advancedStealthScrape, superStealthScrape };