const { CONFIG, jobLogger } = require('./config');
const { isDataDomeBlocked } = require('./utils');

async function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function simulateNaturalMouse(page, log) {
  try {
    const { width, height } = await page.evaluate(() => ({
      width: window.innerWidth,
      height: window.innerHeight
    }));
    const x = Math.random() * width * 0.8 + width * 0.1;
    const y = Math.random() * height * 0.8 + height * 0.1;
    await page.mouse.move(x, y, { steps: 20 });
    log.debug({ message: 'Simulated natural mouse movement', x, y });
  } catch (error) {
    log.warn({ message: 'Failed to simulate mouse movement', error: error.message, stack: error.stack });
  }
}

async function simulateKeyboardEvents(page, log) {
  try {
    await page.keyboard.press('Tab');
    await delay(100);
    await page.keyboard.press('ArrowDown');
    await delay(100);
    await page.keyboard.press('Enter');
    log.debug({ message: 'Simulated keyboard press', keys: ['Tab', 'ArrowDown', 'Enter'] });
  } catch (error) {
    log.warn({ message: 'Failed to simulate keyboard events', error: error.message, stack: error.stack });
  }
}

async function simulateHumanScroll(page, log) {
  try {
    await page.evaluate(() => window.scrollBy(0, window.innerHeight * 0.5));
    await delay(500);
    await page.evaluate(() => window.scrollBy(0, window.innerHeight * 0.3));
    log.debug({ message: 'Simulated scroll', distance: '50% + 30% window height' });
  } catch (error) {
    log.warn({ message: 'Failed to simulate scroll', error: error.message, stack: error.stack });
  }
}

async function simulateHumanBehavior(page, selectors, log) {
  if (!page || page.isClosed()) {
    log.warn({ message: 'Page is closed or null, skipping human behavior simulation' });
    return;
  }
  try {
    const isBotChallenge = await page.evaluate(() => {
      return document.body.textContent.toLowerCase().includes('cloudflare') ||
             document.body.textContent.toLowerCase().includes('verify') ||
             document.querySelector('meta[name="robots"]')?.content?.includes('noindex');
    });
    if (!isBotChallenge) {
      log.debug({ message: 'Skipping human behavior simulation: no bot challenge detected' });
      return;
    }
    for (let i = 0; i < 3; i++) {
      log.debug({ message: `Simulating human behavior, round ${i + 1}/3` });
      await simulateNaturalMouse(page, log);
      await delay(500);
      await simulateHumanScroll(page, log);
      await delay(500);
      await simulateKeyboardEvents(page, log);
      await delay(1000);
    }
    log.debug({ message: 'Simulated human behavior', rounds: 3 });
  } catch (error) {
    log.warn({ message: 'Failed to simulate human behavior', error: error.message, stack: error.stack });
  }
}

async function handleCookieConsent(page, log) {
  try {
    const selectors = [
      'button#cmpwelcomebtnyes',
      'button.accept-all',
      'button#cookie-accept',
      'a.cookie-consent-accept',
      'button[data-test="accept-cookies"]'
    ];
    for (const selector of selectors) {
      const button = await page.$(selector);
      if (button) {
        await button.click();
        log.debug({ message: 'Clicked cookie consent button', selector });
        await delay(1000);
        return;
      }
    }
    log.debug({ message: 'No cookie consent button found' });
  } catch (error) {
    log.warn({ message: 'Cookie consent handling failed', error: error.message, stack: error.stack });
  }
}

async function handleCloudflareChallenge(page, url, log, maxAttempts = 5) {
  try {
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      log.debug({ message: `Attempting Cloudflare challenge resolution, attempt ${attempt}/${maxAttempts}` });
      await simulateHumanBehavior(page, [], log);
      await delay(6000);
      const isChallengeResolved = await page.evaluate(() => {
        return !document.body.textContent.toLowerCase().includes('cloudflare') &&
               !document.body.textContent.toLowerCase().includes('verify') &&
               !document.querySelector('meta[name="robots"]')?.content?.includes('noindex') &&
               !document.querySelector('[id*="cf-challenge"]');
      });
      if (isChallengeResolved) {
        log.info({ message: 'Cloudflare challenge resolved', url });
        return true;
      }
      await page.reload({ waitUntil: 'domcontentloaded', timeout: CONFIG.NAVIGATION_TIMEOUT / 2 }).catch(error => {
        log.warn({ message: 'Reload failed during Cloudflare challenge', error: error.message, stack: error.stack });
      });
    }
    log.error({ message: 'Failed to resolve Cloudflare challenge after all attempts' });
    return false;
  } catch (error) {
    log.error({ message: 'Cloudflare challenge resolution failed', error: error.message, stack: error.stack });
    return false;
  }
}

async function handleDataDomeChallenge(page, url, log, maxAttempts = 5) {
  try {
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      log.debug({ message: `Attempting DataDome challenge resolution, attempt ${attempt}/${maxAttempts}` });
      await simulateHumanBehavior(page, [], log);
      await delay(6000);
      const isChallengeResolved = !(await isDataDomeBlocked(page, log));
      if (isChallengeResolved) {
        log.info({ message: 'DataDome challenge resolved', url });
        return true;
      }
      await page.reload({ waitUntil: 'domcontentloaded', timeout: CONFIG.NAVIGATION_TIMEOUT / 2 }).catch(error => {
        log.warn({ message: 'Reload failed during DataDome challenge', error: error.message, stack: error.stack });
      });
    }
    log.error({ message: 'Failed to resolve DataDome challenge after all attempts' });
    return false;
  } catch (error) {
    log.error({ message: 'DataDome challenge resolution failed', error: error.message, stack: error.stack });
    return false;
  }
}

async function navigatePage(page, url, selectors, log, fallbackMode = false) {
  const maxAttempts = 3;
  const maxFrameDetachments = 2;
  let frameDetachedCount = 0;
  let currentUrl = url;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      log.debug({ message: `Starting navigation attempt ${attempt}/${maxAttempts}`, url: currentUrl });
      await page.evaluate(() => {
        return new Promise(resolve => {
          if (document.readyState === 'complete') resolve();
          else window.addEventListener('load', resolve);
        });
      });
      await handleCookieConsent(page, log);

      const isCloudflare = await page.evaluate(() => {
        return document.body.textContent.toLowerCase().includes('cloudflare') ||
               document.body.textContent.toLowerCase().includes('verify') ||
               document.querySelector('meta[name="robots"]')?.content?.includes('noindex');
      });
      const isDataDome = await isDataDomeBlocked(page, log);

      if (isCloudflare) {
        log.info({ message: 'Cloudflare challenge detected, attempting resolution', url: currentUrl });
        const resolved = await handleCloudflareChallenge(page, currentUrl, log);
        if (!resolved) {
          throw new Error('Failed to resolve Cloudflare challenge');
        }
      }
      if (isDataDome) {
        log.info({ message: 'DataDome challenge detected, attempting resolution', url: currentUrl });
        const resolved = await handleDataDomeChallenge(page, currentUrl, log);
        if (!resolved) {
          throw new Error('Failed to resolve DataDome challenge');
        }
      }

      await page.goto(currentUrl, { waitUntil: 'domcontentloaded', timeout: CONFIG.NAVIGATION_TIMEOUT });
      log.debug({ message: 'Page loaded successfully', url: currentUrl, status: 200 });
      await delay(3000);
      await simulateHumanBehavior(page, selectors, log);
      log.info({ message: 'Navigation successful', url: currentUrl });
      return;
    } catch (error) {
      log.error({ message: 'Navigation failed', url: currentUrl, error: error.message, stack: error.stack });
      if (error.message.includes('detached')) {
        frameDetachedCount++;
        log.warn({ message: `Frame detached during navigation (${frameDetachedCount}/${maxFrameDetachments}), attempting recovery`, url: currentUrl });
        if (frameDetachedCount >= maxFrameDetachments) {
          log.error({ message: 'Max frame detachments reached, aborting navigation', url: currentUrl });
          if (!fallbackMode) {
            log.info({ message: 'Attempting static HTML fallback', url: currentUrl });
            const staticHtml = await page.content().catch(() => '');
            if (staticHtml) {
              return { domContent: staticHtml, debug: 'Static HTML fallback used due to frame detachment' };
            }
          }
          throw new Error('Max frame detachments reached');
        }
        try {
          const cookies = await page.cookies().catch(() => []);
          await page.evaluate(() => window.stop());
          await page.reload({ waitUntil: 'domcontentloaded', timeout: CONFIG.NAVIGATION_TIMEOUT / 2 });
          await page.setCookie(...cookies);
          log.debug({ message: 'Page reloaded after frame detachment', url: currentUrl });
          await delay(3000);
          continue;
        } catch (reloadError) {
          log.error({ message: 'Frame detachment recovery failed', url: currentUrl, error: reloadError.message, stack: reloadError.stack });
          if (!fallbackMode) {
            log.info({ message: 'Attempting static HTML fallback', url: currentUrl });
            const staticHtml = await page.content().catch(() => '');
            if (staticHtml) {
              return { domContent: staticHtml, debug: 'Static HTML fallback used due to frame detachment' };
            }
          }
          throw reloadError;
        }
      }
      if (attempt < maxAttempts) {
        log.info({ message: 'Retrying navigation', url: currentUrl, attempt: attempt + 1 });
        await delay(4000);
        continue;
      }
      if (!fallbackMode) {
        log.info({ message: 'Attempting static HTML fallback', url: currentUrl });
        const staticHtml = await page.content().catch(() => '');
        if (staticHtml) {
          return { domContent: staticHtml, debug: 'Static HTML fallback used due to navigation failure' };
        }
      }
      throw error;
    }
  }
}

module.exports = {
  navigatePage,
  handleCookieConsent,
  simulateHumanBehavior,
  simulateNaturalMouse,
  simulateKeyboardEvents,
  simulateHumanScroll,
  handleCloudflareChallenge,
  handleDataDomeChallenge
};