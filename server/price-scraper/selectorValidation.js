const { logger, jobLogger, CONFIG } = require('./config');
const { initBrowser, initPage } = require('./browserSetup');
const { navigatePage, simulateHumanBehavior } = require('./navigation');
const { closeResource } = require('./utils');
const fs = require('fs').promises;
const path = require('path');

function validateSelector(method, name) {
  if (!method || !method.mode || !['css', 'xpath'].includes(method.mode) || !method.parameter || typeof method.parameter !== 'string') {
    logger.debug({ message: `Invalid ${name} selector structure`, method });
    throw new Error(`Invalid ${name} selector: ${JSON.stringify(method)}`);
  }
  if (method.patterns && typeof method.patterns !== 'object') {
    logger.debug({ message: `Invalid ${name} selector patterns`, patterns: method.patterns });
    throw new Error(`Invalid ${name} selector patterns: ${JSON.stringify(method.patterns)}`);
  }
  if (method.mode === 'css') {
    try {
      const cssRegex = /^[#.a-zA-Z0-9\s\[\]\-\_\>\+\~\:\(\)\=\*\"\'\[\],]+$/;
      if (!cssRegex.test(method.parameter)) {
        logger.debug({ message: `CSS selector failed regex validation for ${name}`, selector: method.parameter });
        throw new Error(`Invalid CSS selector for ${name}: ${method.parameter}`);
      }
      let openBrackets = 0;
      let inQuotes = false;
      for (let i = 0; i < method.parameter.length; i++) {
        const char = method.parameter[i];
        if (char === '[' && !inQuotes) openBrackets++;
        if (char === ']' && !inQuotes) openBrackets--;
        if (char === '"' || char === "'") inQuotes = !inQuotes;
      }
      if (openBrackets !== 0 || inQuotes) {
        logger.debug({ message: `Invalid CSS selector syntax for ${name}`, selector: method.parameter });
        throw new Error(`Invalid CSS selector for ${name}: ${method.parameter}`);
      }
      if (method.patterns) {
        for (const currency in method.patterns) {
          if (!cssRegex.test(method.patterns[currency])) {
            logger.debug({ message: `CSS selector pattern failed regex validation for ${name}`, selector: method.patterns[currency], currency });
            throw new Error(`Invalid CSS selector pattern for ${name}: ${method.patterns[currency]}`);
          }
        }
      }
    } catch (error) {
      logger.debug({ message: `Invalid CSS selector for ${name}`, selector: method.parameter, error: error.message, stack: error.stack });
      throw new Error(`Invalid CSS selector for ${name}: ${method.parameter}`);
    }
  } else if (method.mode === 'xpath') {
    if (!method.parameter.startsWith('/') && !method.parameter.startsWith('(')) {
      logger.debug({ message: `Invalid XPath selector for ${name}`, selector: method.parameter });
      throw new Error(`Invalid XPath selector for ${name}: ${method.parameter}`);
    }
    if (method.patterns) {
      for (const currency in method.patterns) {
        if (!method.patterns[currency].startsWith('/') && !method.patterns[currency].startsWith('(')) {
          logger.debug({ message: `Invalid XPath selector pattern for ${name}`, selector: method.patterns[currency], currency });
          throw new Error(`Invalid XPath selector pattern for ${name}: ${method.patterns[currency]}`);
        }
      }
    }
  }
}

async function validateSelectors(url, methods, currency = 'CZK', logger) {
  const startTime = Date.now();
  const log = logger || jobLogger(url);
  log.info({ message: 'Starting selector validation', url, currency, methods });
  let browser = null;
  let page = null;
  let context = null;
  let maxBrowserAttempts = CONFIG.MAX_BROWSER_ATTEMPTS;
  let fallbackMode = false;

  for (let browserAttempt = 1; browserAttempt <= maxBrowserAttempts; browserAttempt++) {
    try {
      const memoryUsage = process.memoryUsage();
      if (memoryUsage.rss / 1024 / 1024 > CONFIG.MEMORY_LIMIT_MB) {
        log.error({ message: 'Memory usage exceeds limit', memory: `${(memoryUsage.rss / 1024 / 1024).toFixed(2)} MB`, limit: CONFIG.MEMORY_LIMIT_MB });
        throw new Error('Memory limit exceeded');
      }
      browser = await initBrowser(fallbackMode);
      ({ page, context } = await initPage(browser, url));
      await navigatePage(page, url, methods.map(m => m.parameter), log, fallbackMode);
      const results = await Promise.all(methods.map((method, i) =>
        extractSelectorOutputs(page, method, ['Current Validation', 'Original Validation', 'Voucher Validation'][i], currency, log)
      ));
      const result = {
        url,
        currency,
        validation_attempts: {
          current: results[0],
          original: results[1],
          voucher: results[2]
        },
        potential_cleaned_prices: {
          current: results[0][0].cleanedPrices,
          original: results[1][0].cleanedPrices,
          voucher: results[2][0].cleanedPrices
        },
        debug: results.some(r => r[0].matchCount > 0) ? '' : `Saved DOM at nomatch_${url.replace(/[^a-zA-Z0-9]/g, '_')}_${new Date().toISOString().replace(/[:.]/g, '')}.html`
      };
      log.info({ message: 'Selector validation completed', url, duration: `${((Date.now() - startTime) / 1000).toFixed(2)}s`, currency, result });
      return result;
    } catch (error) {
      const duration = (Date.now() - startTime) / 1000;
      log.error({ message: `Selector validation failed on browser attempt ${browserAttempt}/${maxBrowserAttempts}`, url, duration: `${duration.toFixed(2)}s`, error: error.message, stack: error.stack });
      if (browserAttempt < maxBrowserAttempts && (error.message.includes('timeout') || error.message.includes('detached') || error.message.includes('session closed') || error.message.includes('unhealthy'))) {
        log.info({ message: `Retrying selector validation with new browser instance${browserAttempt === maxBrowserAttempts - 1 ? ' in fallback mode' : ''}`, url, attempt: browserAttempt + 1, currency });
        fallbackMode = browserAttempt === maxBrowserAttempts - 1;
        await new Promise(resolve => setTimeout(resolve, 3000));
        await simulateHumanBehavior(page, methods.map(m => m.parameter), log);
        continue;
      }
      throw error;
    } finally {
      try {
        await closeResource(page, 'Page');
        await closeResource(context, 'BrowserContext');
        await closeResource(browser, 'Browser');
      } catch (error) {
        log.warn({ message: 'Browser cleanup failed', error: error.message, stack: error.stack });
      }
    }
  }
}

async function extractSelectorOutputs(page, method, logPrefix, currency, log) {
  const { mode, parameter } = method;
  log.debug({ message: `${logPrefix} extraction starting`, mode, parameter, currency });
  const selectorsToTry = [parameter];

  let values = [];
  let matchedSelector = null;
  for (const sel of selectorsToTry) {
    try {
      await page.waitForNetworkIdle({ timeout: CONFIG.SELECTOR_TIMEOUT }).catch(error => {
        log.warn({ message: `${logPrefix} Network idle timeout`, selector: sel, error: error.message, stack: error.stack });
      });
      values = mode === 'css'
        ? await page.$$eval(sel, els => els
            .map(el => (el.textContent || '').trim())
            .filter(text => text.match(/^\s*[\d\s,.]+(?:\s*(CZK|€|PLN))?\s*$/))
          ).catch(error => {
            log.error({ message: `${logPrefix} CSS evaluation failed`, selector: sel, error: error.message, stack: error.stack });
            return [];
          })
        : await page.evaluate(xp => {
            const results = [];
            const iterator = document.evaluate(xp, document, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null);
            for (let i = 0; i < iterator.snapshotLength; i++) {
              const item = iterator.snapshotItem(i);
              const text = item && item.textContent ? item.textContent.trim() : '';
              if (text.match(/^\s*[\d\s,.]+(?:\s*(CZK|€|PLN))?\s*$/)) results.push(text);
            }
            return results;
          }, sel).catch(error => {
            log.error({ message: `${logPrefix} XPath evaluation failed`, selector: sel, error: error.message, stack: error.stack });
            return [];
          });
      if (values.length > 0) {
        matchedSelector = sel;
        log.debug({ message: `${logPrefix} Selector matched`, selector: sel, values });
        break;
      }
    } catch (error) {
      log.warn({ message: `${logPrefix} Selector failed`, selector: sel, error: error.message, stack: error.stack });
      continue;
    }
  }

  const cleanedPrices = await Promise.all(values.map(v => require('./utils').cleanPrice(v, currency))).then(prices => prices.filter(p => p.price !== null));
  const confidence = values.length > 0 ? (cleanedPrices.length / values.length) : 0;
  const result = [{
    attempt: 1,
    values,
    cleanedPrices,
    confidence: confidence.toFixed(2),
    matchCount: values.length,
    matchedSelector,
    suggestedSelectors: []
  }];
  if (values.length === 0) {
    log.error({ message: `${logPrefix} Validation: No values found`, mode, parameter, currency });
    result[0].error = 'No price elements matched';
    const html = await page.content().catch(() => '');
    const filename = `nomatch_${url.replace(/[^a-zA-Z0-9]/g, '_')}_${new Date().toISOString().replace(/[:.]/g, '')}.html`;
    await fs.writeFile(path.join(CONFIG.LOG_DIR, filename), html).catch(error => {
      log.error({ message: 'Failed to save nomatch DOM', url, filename, error: error.message, stack: error.stack });
    });
    result[0].debug = `Saved DOM at ${filename}`;
  } else {
    log.debug({ message: `${logPrefix} Validation`, mode, parameter, currency, matchCount: values.length, matchedSelector, cleanedPrices });
  }
  return result;
}

module.exports = {
  validateSelector,
  validateSelectors,
  extractSelectorOutputs
};