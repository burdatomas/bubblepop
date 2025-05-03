const { cleanPrice } = require('./utils');
const { selectorCache, CONFIG, logger } = require('./config');
const { simulateHumanBehavior } = require('./navigation');

async function extractCssPrices(page, selector, logPrefix, attempt, currency, log) {
  const prices = [];
  const texts = [];
  const contexts = [];
  try {
    const cachedValues = selectorCache.get(`${logPrefix}:${selector}:${currency}`);
    if (cachedValues) {
      log.debug({ message: `${logPrefix} Using cached CSS values`, selector, currency, values: cachedValues });
      for (const text of cachedValues) {
        texts.push(text);
        contexts.push('Cached');
        const price = await cleanPrice(text, currency);
        if (price.price !== null) prices.push(parseFloat(price.price));
      }
      return { prices, texts, contexts };
    }
    await page.waitForSelector(selector, { timeout: CONFIG.SELECTOR_TIMEOUT }).catch(error => {
      log.error({ message: `${logPrefix} Selector not found`, selector, attempt: attempt + 1, error: error.message, stack: error.stack });
      throw new Error(`Selector not found: ${selector}`);
    });
    const values = await page.$$eval(selector, els => els.filter(el => el.textContent.match(/\d/)).map(el => ({
      text: (el.textContent || '').trim(),
      context: el.parentElement ? el.parentElement.outerHTML.slice(0, 500) : 'No parent'
    }))).catch(error => {
      log.error({ message: `${logPrefix} CSS evaluation failed`, selector, currency, attempt: attempt + 1, error: error.message, stack: error.stack });
      throw new Error(`CSS evaluation failed: ${error.message}`);
    });
    log.debug({ message: `${logPrefix} CSS selector matched`, selector, currency, count: values.length, matchedTexts: values.map(v => v.text) });
    for (const { text, context } of values) {
      texts.push(text);
      contexts.push(context);
      const price = await cleanPrice(text, currency);
      if (price.price !== null) prices.push(parseFloat(price.price));
    }
    selectorCache.set(`${logPrefix}:${selector}:${currency}`, values.map(v => v.text));
  } catch (error) {
    log.error({ message: `${logPrefix} CSS extraction failed`, attempt: attempt + 1, selector, currency, error: error.message, stack: error.stack });
    throw error;
  }
  return { prices, texts, contexts };
}

async function extractXPathPrices(page, selector, logPrefix, attempt, currency, log) {
  const prices = [];
  const texts = [];
  const contexts = [];
  try {
    const cachedValues = selectorCache.get(`${logPrefix}:${selector}:${currency}`);
    if (cachedValues) {
      log.debug({ message: `${logPrefix} Using cached XPath values`, selector, currency, values: cachedValues });
      for (const text of cachedValues) {
        texts.push(text);
        contexts.push('Cached');
        const price = await cleanPrice(text, currency);
        if (price.price !== null) prices.push(parseFloat(price.price));
      }
      return { prices, texts, contexts };
    }
    await page.waitForSelector(selector, { timeout: CONFIG.SELECTOR_TIMEOUT }).catch(error => {
      log.error({ message: `${logPrefix} Selector not found`, selector, attempt: attempt + 1, error: error.message, stack: error.stack });
      throw new Error(`Selector not found: ${selector}`);
    });
    const values = await page.evaluate(xp => {
      const results = [];
      const iterator = document.evaluate(xp, document, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null);
      for (let i = 0; i < iterator.snapshotLength; i++) {
        const item = iterator.snapshotItem(i);
        const text = item && item.textContent ? item.textContent.trim() : '';
        if (text && text.match(/\d/)) {
          results.push({
            text,
            context: item.parentElement ? item.parentElement.outerHTML.slice(0, 500) : 'No parent'
          });
        }
      }
      return results;
    }, selector).catch(error => {
      log.error({ message: `${logPrefix} XPath evaluation failed`, selector, currency, attempt: attempt + 1, error: error.message, stack: error.stack });
      throw new Error(`XPath evaluation failed: ${error.message}`);
    });
    log.debug({ message: `${logPrefix} XPath matched`, selector, currency, count: values.length, matchedTexts: values.map(v => v.text) });
    for (const { text, context } of values) {
      texts.push(text);
      contexts.push(context);
      const price = await cleanPrice(text, currency);
      if (price.price !== null) prices.push(parseFloat(price.price));
    }
    selectorCache.set(`${logPrefix}:${selector}:${currency}`, values.map(v => v.text));
  } catch (error) {
    log.error({ message: `${logPrefix} XPath extraction failed`, attempt: attempt + 1, selector, currency, error: error.message, stack: error.stack });
    throw error;
  }
  return { prices, texts, contexts };
}

async function extractPriceWithRetry(page, method, logPrefix, type, currentPrice = null, currency = 'CZK', log) {
  const { mode, parameter } = method;
  log.debug({ message: `${logPrefix} price extraction starting`, mode, parameter, currency });
  const MAX_ATTEMPTS = 5;
  const selectorsToTry = [parameter];

  for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
    const memoryUsage = process.memoryUsage();
    if (memoryUsage.rss / 1024 / 1024 > CONFIG.MEMORY_LIMIT_MB) {
      log.error({ message: `${logPrefix} Memory usage exceeds ${CONFIG.MEMORY_LIMIT_MB}MB, aborting extraction`, memory: `${(memoryUsage.rss / 1024 / 1024).toFixed(2)} MB` });
      throw new Error('Memory limit exceeded');
    }

    for (const sel of selectorsToTry) {
      log.debug({ message: `${logPrefix} price extraction attempt ${attempt + 1}/${MAX_ATTEMPTS} with selector`, selector: sel });
      let result;
      try {
        result = mode === 'css'
          ? await extractCssPrices(page, sel, logPrefix, attempt, currency, log)
          : await extractXPathPrices(page, sel, logPrefix, attempt, currency, log);
      } catch (error) {
        log.warn({ message: `${logPrefix} Extraction failed for selector`, selector: sel, attempt: attempt + 1, error: error.message, stack: error.stack });
        continue;
      }
      const prices = result.prices.filter(p => {
        if (type === 'current') return p >= (currency === 'CZK' ? 10 : currency === 'EUR' ? 1 : 5);
        if (type === 'original' && currentPrice) return p > parseFloat(currentPrice) * 1.02;
        if (type === 'voucher' && currentPrice) return p >= parseFloat(currentPrice) * 0.5 && p <= parseFloat(currentPrice) * 0.99;
        return true;
      });
      if (prices.length > 0) {
        const chosenPrice = prices.sort((a, b) => type === 'voucher' ? a - b : b - a)[0];
        log.info({ message: `${logPrefix} Final chosen ${type} price`, price: chosenPrice, candidates: prices, currency, selector: sel });
        return chosenPrice.toFixed(2);
      }
      log.warn({ message: `${logPrefix} No valid prices found for selector`, selector: sel, attempt: attempt + 1, matchedTexts: result.texts });
    }

    if (attempt < MAX_ATTEMPTS - 1) {
      log.debug({ message: `${logPrefix} Retrying after delay`, attempt: attempt + 1 });
      await new Promise(resolve => setTimeout(resolve, 3000));
      await simulateHumanBehavior(page, [parameter], log);
    }
  }
  log.error({ message: `${logPrefix} No valid ${type} prices found after ${MAX_ATTEMPTS} attempts`, selector: parameter, currency });
  return null;
}

async function extractAllPrices(page, methods, currency = 'CZK', log) {
  const priceResults = { currentPrice: null, originalPrice: null, voucherPrice: null };
  try {
    const memoryUsage = process.memoryUsage();
    if (memoryUsage.rss / 1024 / 1024 > CONFIG.MEMORY_LIMIT_MB) {
      log.error({ message: `Batch extraction aborted due to memory usage`, memory: `${(memoryUsage.rss / 1024 / 1024).toFixed(2)} MB`, limit: CONFIG.MEMORY_LIMIT_MB });
      throw new Error('Memory limit exceeded');
    }

    const [current, original, voucher] = await Promise.all([
      extractPriceWithRetry(page, methods[0], 'Current', 'current', null, currency, log).catch(error => {
        log.error({ message: 'Current price extraction failed', error: error.message, stack: error.stack });
        return null;
      }),
      extractPriceWithRetry(page, methods[1], 'Original', 'original', priceResults.currentPrice, currency, log).catch(error => {
        log.error({ message: 'Original price extraction failed', error: error.message, stack: error.stack });
        return null;
      }),
      extractPriceWithRetry(page, methods[2], 'Voucher', 'voucher', priceResults.currentPrice, currency, log).catch(error => {
        log.error({ message: 'Voucher price extraction failed', error: error.message, stack: error.stack });
        return null;
      })
    ]);
    priceResults.currentPrice = current;
    priceResults.originalPrice = original;
    priceResults.voucherPrice = voucher;
    log.info({ message: 'Batch price extraction completed', results: priceResults });
  } catch (error) {
    log.error({ message: 'Error during batch price extraction', error: error.message, stack: error.stack });
  }
  return priceResults;
}

async function finalizeResult(currentPrice, originalPrice, voucherPrice, url, currency = 'CZK', log) {
  const c = currentPrice ? parseFloat(currentPrice) : null;
  const o = originalPrice ? parseFloat(originalPrice) : null;
  const v = voucherPrice ? parseFloat(voucherPrice) : null;
  let finalCurrentPrice = currentPrice || '';
  let finalOriginalPrice = originalPrice || '';
  let finalVoucherPrice = voucherPrice || '';
  let salePercentage = 0;
  let priceStatus = 'Standard';

  if (c !== null) {
    if (o !== null && o > c * 1.02) {
      salePercentage = Math.round(((o - c) / o) * 100);
      priceStatus = 'Discount';
      if (v !== null && v >= c * 0.99) finalVoucherPrice = '';
      finalOriginalPrice = o.toFixed(2);
    } else if (v !== null && v < c && v >= c * 0.5) {
      salePercentage = Math.round(((c - v) / c) * 100);
      priceStatus = 'Voucher';
      if (o !== null && o <= c * 1.02) finalOriginalPrice = '';
      else if (o !== null) finalOriginalPrice = o.toFixed(2);
      finalVoucherPrice = v.toFixed(2);
    } else {
      if (o !== null && o <= c * 1.02) finalOriginalPrice = '';
      else if (o !== null) finalOriginalPrice = o.toFixed(2);
      if (v !== null && (v >= c || v < c * 0.5)) finalVoucherPrice = '';
      else if (v !== null) finalVoucherPrice = v.toFixed(2);
    }
    finalCurrentPrice = c.toFixed(2);
  } else {
    log.error({ message: 'Could not determine current price', url, currency });
    return {
      current_price: '',
      original_price: '',
      voucher_price: finalVoucherPrice,
      sale_percentage: 0,
      price_status: 'Error - No Current Price',
      currency,
      debug: `Saved DOM at failed_${url.replace(/[^a-zA-Z0-9]/g, '_')}_${new Date().toISOString().replace(/[:.]/g, '')}.html`
    };
  }
  const result = {
    current_price: finalCurrentPrice,
    original_price: finalOriginalPrice,
    voucher_price: finalVoucherPrice,
    sale_percentage: salePercentage,
    price_status: priceStatus,
    currency
  };
  log.info({ message: 'Validation complete', url, input: { current: c, original: o, voucher: v }, output: result, currency });
  return result;
}

module.exports = {
  extractCssPrices,
  extractXPathPrices,
  extractPriceWithRetry,
  extractAllPrices,
  finalizeResult
};