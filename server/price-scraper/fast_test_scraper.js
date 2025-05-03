const puppeteer = require('puppeteer');
const { jobLogger } = require('./config');
const { stealthScrape, enhancedStealthScrape, advancedStealthScrape, superStealthScrape } = require('./scraper');
const { execSync } = require('child_process');

const log = jobLogger.child({ module: 'fast_test_scraper' });

async function fastTestScraper(urls, currency, methodsByDomain) {
  const results = [];
  let browser;

  try {
    browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-setuid-sandbox']
    });

    const variants = [
      { name: 'stealthScrape', fn: stealthScrape },
      { name: 'enhancedStealthScrape', fn: enhancedStealthScrape },
      { name: 'advancedStealthScrape', fn: advancedStealthScrape },
      { name: 'superStealthScrape', fn: superStealthScrape }
    ];

    for (const url of urls) {
      // Determine domain and select appropriate methods
      const domain = new URL(url).hostname;
      const methods = methodsByDomain[domain] || methodsByDomain['default'];

      const urlResults = { url, variants: [] };
      for (const variant of variants) {
        log.info({ message: `Testing variant ${variant.name} for URL`, url });
        try {
          // Clean up Chrome processes before each test
          try {
            const chromeProcs = execSync('pgrep -u scraper-user chrome || true', { encoding: 'utf8' }).trim();
            if (chromeProcs) {
              execSync(`sudo kill -9 ${chromeProcs} || true`, { stdio: 'ignore' });
              log.debug({ message: 'Killed specific Chrome processes', pids: chromeProcs });
            }
            execSync('sudo killall -9 chrome || true', { stdio: 'ignore' });
            const psOutput = execSync('ps aux | grep -v grep | grep chrome || true', { encoding: 'utf8' }).trim();
            if (psOutput) {
              log.warn({ message: 'Chrome processes still running before test', psOutput });
            } else {
              log.debug({ message: 'No Chrome processes before test' });
            }
          } catch (error) {
            log.warn({ message: 'Failed to clean up Chrome processes before test', error: error.message });
          }

          const result = await variant.fn({
            browser,
            url,
            methods,
            currency
          });

          urlResults.variants.push({
            variant: variant.name,
            status: 'success',
            result
          });
          log.info({ message: `Variant ${variant.name} succeeded`, url, result });
        } catch (error) {
          urlResults.variants.push({
            variant: variant.name,
            status: 'failure',
            error: error.message,
            stack: error.stack
          });
          log.error({ message: `Variant ${variant.name} failed`, url, error: error.message });
        }
        await new Promise(resolve => setTimeout(resolve, 3000));
      }
      results.push(urlResults);
    }
  } catch (error) {
    log.error({ message: 'Fast test scraper failed', error: error.message, stack: error.stack });
  } finally {
    if (browser) {
      try {
        await browser.close();
        log.debug({ message: 'Browser closed' });
      } catch (error) {
        log.warn({ message: 'Failed to close browser', error: error.message });
      }
    }
    try {
      const chromeProcs = execSync('pgrep -u scraper-user chrome || true', { encoding: 'utf8' }).trim();
      if (chromeProcs) {
        execSync(`sudo kill -9 ${chromeProcs} || true`, { stdio: 'ignore' });
        log.debug({ message: 'Killed specific Chrome processes post-test', pids: chromeProcs });
      }
      execSync('sudo killall -9 chrome || true', { stdio: 'ignore' });
      log.debug({ message: 'Final Chrome process cleanup' });
    } catch (error) {
        log.warn({ message: 'Failed final Chrome process cleanup', error: error.message });
    }
  }

  console.log(JSON.stringify(results, null, 2));
  return results;
}

// Example usage
const testUrls = [
  'https://www.alza.cz/65-lg-oled65c44-d12323461.htm',
  'https://www.alza.cz/aeg-tr939m4c-d7411528.htm',
  'https://www.datart.cz/vodni-filtr-pro-espressa-de-longhi-dlsc002-bily.html',
  'https://www.datart.cz/mobilni-telefon-motorola-edge-60-fusion-5g-8-gb-256-gb-pb7e0038pl-sedy',
  'https://www.jrc.cz/PAC-MAN-hra-v-plechove-krabicce-p37554'
];

const testCurrency = 'CZK';

const methodsByDomain = {
  'www.alza.cz': [
    { mode: 'css', parameter: '.price-box__primary-price__value' },
    { mode: 'css', parameter: '.price-box__compare-price' },
    { mode: 'css', parameter: '.promo-action-prices__price' }
  ],
  'www.datart.cz': [
    { mode: 'xpath', parameter: '//div[contains(@class, "product-price")]//span[contains(@class, "main-price")]' },
    { mode: 'xpath', parameter: '//div[contains(@class, "product-price-before")]//del' },
    { mode: 'xpath', parameter: '//div[@data-test="price-wrap-main"]//span[contains(@class, "minor-price") and not(contains(@class, "price-crossed")) and not(contains(@class, "price-gray"))]' }
  ],
  'www.jrc.cz': [
    { mode: 'css', parameter: '.buyBox-price' },
    { mode: 'css', parameter: '.price-old' },
    { mode: 'css', parameter: '.pushBox.black b' }
  ],
  'default': [
    { mode: 'css', parameter: '.price-box__primary-price__value' },
    { mode: 'css', parameter: '.price-box__compare-price' },
    { mode: 'css', parameter: '.promo-action-prices__price' }
  ]
};

fastTestScraper(testUrls, testCurrency, methodsByDomain).catch(err => {
  console.error('Test failed:', err);
});