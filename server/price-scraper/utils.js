const { CONFIG, logger } = require('./config');
const fs = require('fs').promises;
const path = require('path');
const { exec } = require('child_process');
const util = require('util');

const execPromise = util.promisify(exec);

async function withRetry(fn, operationName, maxAttempts = CONFIG.MAX_BROWSER_ATTEMPTS) {
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const memoryUsage = process.memoryUsage();
      if (memoryUsage.rss / 1024 / 1024 > CONFIG.MEMORY_LIMIT_MB) {
        logger.error({ message: `Retry aborted for ${operationName} due to memory usage`, memory: `${(memoryUsage.rss / 1024 / 1024).toFixed(2)} MB`, limit: CONFIG.MEMORY_LIMIT_MB });
        throw new Error('Memory limit exceeded');
      }
      return await fn();
    } catch (error) {
      logger.warn({ message: `Attempt ${attempt}/${maxAttempts} failed for ${operationName}`, error: error.message, stack: error.stack });
      if (attempt === maxAttempts || !error.message.includes('timeout') && !error.message.includes('detached') && !error.message.includes('403') && !error.message.includes('429')) {
        throw error;
      }
      await new Promise(resolve => setTimeout(resolve, 3000 * attempt));
    }
  }
}

async function simulateTyping(page, logger) {
  try {
    await page.evaluate(() => {
      const input = document.querySelector('input, textarea') || document.createElement('input');
      input.value = 'test';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    logger.debug({ message: 'Simulated typing' });
  } catch (error) {
    logger.warn({ message: 'Typing simulation failed', error: error.message, stack: error.stack });
  }
}

async function simulateRandomUserInteractions(page, logger) {
  try {
    const memoryUsage = process.memoryUsage();
    if (memoryUsage.rss / 1024 / 1024 > CONFIG.MEMORY_LIMIT_MB) {
      logger.error({ message: 'User interactions simulation aborted due to memory usage', memory: `${(memoryUsage.rss / 1024 / 1024).toFixed(2)} MB`, limit: CONFIG.MEMORY_LIMIT_MB });
      throw new Error('Memory limit exceeded');
    }
    await page.evaluate(() => {
      const width = window.innerWidth || 1920;
      const height = window.innerHeight || 1080;
      const moveMouse = (x, y) => {
        const event = new MouseEvent('mousemove', { bubbles: true, cancelable: true, clientX: x, clientY: y });
        document.dispatchEvent(event);
      };
      for (let i = 0; i < 5; i++) {
        const x = width * (0.2 + Math.random() * 0.6);
        const y = height * (0.2 + Math.random() * 0.6);
        setTimeout(() => moveMouse(x, y), i * (200 + Math.random() * 200));
      }
      const clickRandomElement = () => {
        const elements = document.querySelectorAll('a, button, [role="button"]');
        if (elements.length > 0) {
          const element = elements[Math.floor(Math.random() * elements.length)];
          element.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }
      };
      setTimeout(clickRandomElement, 1000 + Math.random() * 1000);
      const scrollRandom = () => {
        if (document.body && document.body.scrollHeight) {
          window.scrollBy({ top: window.innerHeight * (Math.random() * 0.4 + 0.2), behavior: 'smooth' });
        }
      };
      setTimeout(scrollRandom, 1500 + Math.random() * 1500);
    });
    await new Promise(resolve => setTimeout(resolve, 3000));
    logger.debug({ message: 'Simulated random user interactions' });
  } catch (error) {
    logger.warn({ message: 'Random user interactions simulation failed', error: error.message, stack: error.stack });
  }
}

async function randomizeRequestTiming(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      const offset = Math.random() * 8 + 8;
      const originalFetch = window.fetch;
      window.fetch = async (...args) => {
        await new Promise(resolve => setTimeout(resolve, Math.random() * offset));
        return originalFetch.apply(window, args);
      };
      const originalXhrOpen = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function(...args) {
        setTimeout(() => originalXhrOpen.apply(this, args), Math.random() * offset);
      };
    });
    logger.debug({ message: 'Randomized request timing' });
  } catch (error) {
    logger.warn({ message: 'Request timing randomization failed', error: error.message, stack: error.stack });
  }
}

async function spoofCanvasFingerprint(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      const originalCanvas = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function(type) {
        if (type === '2d') {
          const context = originalCanvas.call(this, type);
          const originalGetImageData = context.getImageData;
          context.getImageData = function(...args) {
            const imageData = originalGetImageData.apply(context, args);
            for (let i = 0; i < imageData.data.length; i += 4) {
              imageData.data[i] += Math.floor(Math.random() * 3 - 1);
              imageData.data[i + 1] += Math.floor(Math.random() * 3 - 1);
              imageData.data[i + 2] += Math.floor(Math.random() * 3 - 1);
            }
            return imageData;
          };
          const originalFillText = context.fillText;
          context.fillText = function(text, x, y, maxWidth) {
            x += Math.random() * 0.5 - 0.25;
            y += Math.random() * 0.5 - 0.25;
            originalFillText.apply(context, [text, x, y, maxWidth]);
          };
          return context;
        }
        return originalCanvas.call(this, type);
      };
    });
    logger.debug({ message: 'Spoofed canvas fingerprint' });
  } catch (error) {
    logger.warn({ message: 'Canvas fingerprint spoofing failed', error: error.message, stack: error.stack });
  }
}

async function isCloudflareBlocked(page, logger) {
  try {
    const blocked = await page.evaluate(() => {
      const indicators = [
        document.body.innerHTML.includes('cf_chl_jschl_tk'),
        document.body.innerHTML.includes('turnstile'),
        document.querySelector('[data-widget="turnstile"]'),
        document.querySelector('iframe[src*="cloudflare"]'),
        document.title.toLowerCase().includes('access denied'),
        document.title.toLowerCase().includes('verify'),
        document.body.innerHTML.includes('cf-please-wait'),
        document.body.innerHTML.includes('checking your browser'),
        document.querySelector('[id*="cf-challenge"]'),
        document.querySelector('[class*="cf-challenge"]'),
        document.body.innerHTML.toLowerCase().includes('cloudflare'),
        document.querySelector('meta[name="robots"]')?.content?.includes('noindex'),
        !!window.__cf_chl_jschl_tk__,
        !!window._cf_chl_opt,
        document.body.innerHTML.includes('please wait'),
        document.body.innerHTML.includes('security check'),
        document.querySelector('[id*="cf-turnstile"]'),
        document.querySelector('[class*="cf-turnstile"]'),
        document.body.innerHTML.includes('403 forbidden'),
        document.body.innerHTML.includes('challenge-platform')
      ];
      return indicators.some(indicator => indicator);
    });
    if (blocked) {
      const html = await page.content().catch(() => '');
      const filename = `cloudflare_${Date.now()}_${page.url().replace(/[^a-zA-Z0-9]/g, '_')}.html`;
      await fs.writeFile(path.join(CONFIG.LOG_DIR, filename), html, { mode: 0o666 }).catch(err => {
        logger.error({ message: 'Failed to save Cloudflare DOM', filename, error: err.message, stack: err.stack });
      });
      logger.warn({ message: 'Cloudflare block detected', domPath: `logs/${filename}` });
    } else {
      logger.debug({ message: 'No Cloudflare block detected' });
    }
    return blocked;
  } catch (error) {
    logger.warn({ message: 'Cloudflare block check failed', error: error.message, stack: error.stack });
    return false;
  }
}

async function isDataDomeBlocked(page, logger) {
  try {
    const blocked = await page.evaluate(() => {
      const indicators = [
        document.body.innerHTML.toLowerCase().includes('datadome'),
        document.querySelector('script[src*="datadome"]'),
        document.title.toLowerCase().includes('403 forbidden'),
        document.body.innerHTML.toLowerCase().includes('bot detection'),
        document.body.innerHTML.toLowerCase().includes('datadome'),
        document.body.innerHTML.toLowerCase().includes('access denied'),
        !!window.DataDome,
        !!window.dd,
        document.querySelector('meta[name="robots"]')?.content?.includes('noindex'),
        document.body.innerHTML.includes('captcha'),
        document.querySelector('[id*="datadome"]'),
        document.querySelector('[class*="datadome"]'),
        document.querySelector('iframe[src*="datadome"]'),
        document.body.innerHTML.includes('blocked'),
        document.body.innerHTML.includes('protected by datadome')
      ];
      return indicators.some(indicator => indicator);
    });
    if (blocked) {
      const html = await page.content().catch(() => '');
      const filename = `datadome_${Date.now()}_${page.url().replace(/[^a-zA-Z0-9]/g, '_')}.html`;
      await fs.writeFile(path.join(CONFIG.LOG_DIR, filename), html, { mode: 0o666 }).catch(err => {
        logger.error({ message: 'Failed to save DataDome DOM', filename, error: err.message, stack: err.stack });
      });
      logger.warn({ message: 'DataDome block detected', domPath: `logs/${filename}` });
    } else {
      logger.debug({ message: 'No DataDome block detected' });
    }
    return blocked;
  } catch (error) {
    logger.warn({ message: 'DataDome block check failed', error: error.message, stack: error.stack });
    return false;
  }
}

async function spoofGeolocation(page, logger) {
  try {
    const coordinates = { latitude: 50.0755, longitude: 14.4378, accuracy: 20 };
    await page.evaluateOnNewDocument((coords) => {
      Object.defineProperty(navigator, 'geolocation', {
        value: {
          getCurrentPosition: (cb) => cb({ coords, timestamp: Date.now() }),
          watchPosition: (cb) => cb({ coords, timestamp: Date.now() }),
          clearWatch: () => {}
        }
      });
    }, coordinates);
    logger.debug({ message: 'Spoofed geolocation', coordinates });
  } catch (error) {
    logger.warn({ message: 'Geolocation spoofing failed', error: error.message, stack: error.stack });
  }
}

async function rotateBrowserFingerprint(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      const originalCanvas = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function(type) {
        if (type === '2d') {
          const context = originalCanvas.call(this, type);
          const originalGetImageData = context.getImageData;
          context.getImageData = function(...args) {
            const imageData = originalGetImageData.apply(context, args);
            for (let i = 0; i < imageData.data.length; i += 4) {
              imageData.data[i] = imageData.data[i] ^ (Math.random() > 0.5 ? 1 : 0);
            }
            return imageData;
          };
          return context;
        }
        return originalCanvas.call(this, type);
      };
    });
    logger.debug({ message: 'Rotated browser fingerprint' });
  } catch (error) {
    logger.warn({ message: 'Browser fingerprint rotation failed', error: error.message, stack: error.stack });
  }
}

async function setDynamicHeaders(page, logger) {
  try {
    const headers = {
      'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
      'Accept-Encoding': 'gzip, deflate, br',
      'Accept-Language': 'cs-CZ,cs;q=0.9,en-US;q=0.8,en;q=0.7',
      'Connection': 'keep-alive',
      'Sec-Fetch-Dest': 'document',
      'Sec-Fetch-Mode': 'navigate',
      'Sec-Fetch-Site': 'none',
      'Sec-Fetch-User': '?1',
      'Upgrade-Insecure-Requests': '1',
      'Sec-CH-UA': `"Chromium";v="128", "Not=A?Brand";v="99"`,
      'Sec-CH-UA-Mobile': Math.random() > 0.3 ? '?0' : '?1',
      'Sec-CH-UA-Platform': Math.random() > 0.5 ? '"Windows"' : '"macOS"'
    };
    await page.setExtraHTTPHeaders(headers);
    logger.debug({ message: 'Set dynamic headers', headers });
  } catch (error) {
    logger.warn({ message: 'Dynamic headers setup failed', error: error.message, stack: error.stack });
  }
}

async function minimizeFingerprint(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => false });
      Object.defineProperty(window, 'chrome', { get: () => ({ runtime: {}, app: {} }) });
      Object.defineProperty(navigator, 'plugins', { get: () => [] });
      Object.defineProperty(navigator, 'mimeTypes', { get: () => [] });
      Object.defineProperty(navigator, 'languages', { get: () => ['cs-CZ', 'cs', 'en-US', 'en'] });
    });
    logger.debug({ message: 'Minimized browser fingerprint' });
  } catch (error) {
    logger.warn({ message: 'Fingerprint minimization failed', error: error.message, stack: error.stack });
  }
}

async function spoofScreenProperties(page, logger) {
  try {
    const screen = { 
      width: 1920 + Math.floor(Math.random() * 20 - 10), 
      height: 1080 + Math.floor(Math.random() * 20 - 10), 
      devicePixelRatio: Math.random() > 0.5 ? 1 : 2 
    };
    await page.evaluateOnNewDocument((screen) => {
      Object.defineProperty(window, 'screen', {
        value: {
          width: screen.width,
          height: screen.height,
          availWidth: screen.width,
          availHeight: screen.height - 80,
          colorDepth: 24,
          pixelDepth: 24
        }
      });
      Object.defineProperty(window, 'devicePixelRatio', { get: () => screen.devicePixelRatio });
      Object.defineProperty(window, 'outerWidth', { get: () => screen.width });
      Object.defineProperty(window, 'outerHeight', { get: () => screen.height });
    }, screen);
    logger.debug({ message: 'Spoofed screen properties', screen });
  } catch (error) {
    logger.warn({ message: 'Screen properties spoofing failed', error: error.message, stack: error.stack });
  }
}

async function disableWebRTC(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'mediaDevices', { get: () => undefined });
      Object.defineProperty(window, 'RTCPeerConnection', { get: () => undefined });
      Object.defineProperty(window, 'webkitRTCPeerConnection', { get: () => undefined });
      Object.defineProperty(window, 'mozRTCPeerConnection', { get: () => undefined });
    });
    logger.debug({ message: 'Disabled WebRTC' });
  } catch (error) {
    logger.warn({ message: 'WebRTC disabling failed', error: error.message, stack: error.stack });
  }
}

async function simulateTouchEvents(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      let touchCount = 0;
      const maxTouchPoints = Math.random() > 0.7 ? 1 : 2;
      Object.defineProperty(navigator, 'maxTouchPoints', { get: () => maxTouchPoints });
      function simulateTouch(type, x, y) {
        const touch = new Touch({
          identifier: touchCount++,
          target: document.elementFromPoint(x, y) || document.body,
          clientX: x,
          clientY: y,
          radiusX: 2.5 + Math.random() * 1,
          radiusY: 2.5 + Math.random() * 1,
          rotationAngle: Math.random() * 10,
          force: 0.5 + Math.random() * 0.2
        });
        const touchEvent = new TouchEvent(type, {
          cancelable: true,
          bubbles: true,
          touches: [touch],
          targetTouches: [touch],
          changedTouches: [touch]
        });
        document.dispatchEvent(touchEvent);
      }
      document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
          const x = window.innerWidth * (0.4 + Math.random() * 0.2);
          const y = window.innerHeight * (0.4 + Math.random() * 0.2);
          simulateTouch('touchstart', x, y);
          setTimeout(() => simulateTouch('touchend', x, y), 50 + Math.random() * 50);
        }, 250 + Math.random() * 250);
      });
    });
    logger.debug({ message: 'Simulated touch events' });
  } catch (error) {
    logger.warn({ message: 'Touch events simulation failed', error: error.message, stack: error.stack });
  }
}

async function spoofBrowserFeatures(page, logger, hardwareConcurrency = 4) {
  try {
    await page.evaluateOnNewDocument((features) => {
      Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => features.hardwareConcurrency });
      Object.defineProperty(navigator, 'connection', {
        value: {
          effectiveType: Math.random() > 0.5 ? '4g' : '3g',
          rtt: 50 + Math.floor(Math.random() * 100),
          downlink: 5 + Math.random() * 5,
          saveData: Math.random() > 0.8
        }
      });
    }, { hardwareConcurrency });
    logger.debug({ message: 'Spoofed browser features', hardwareConcurrency });
  } catch (error) {
    logger.warn({ message: 'Browser features spoofing failed', error: error.message, stack: error.stack });
  }
}

async function spoofNavigatorProperties(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
      Object.defineProperty(navigator, 'vendor', { get: () => 'Google Inc.' });
      Object.defineProperty(navigator, 'product', { get: () => 'Gecko' });
      Object.defineProperty(navigator, 'oscpu', { get: () => 'Windows NT 10.0; Win64; x64' });
      Object.defineProperty(navigator, 'buildID', { get: () => '20250426000000' });
      Object.defineProperty(navigator, 'appVersion', { get: () => '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' });
      Object.defineProperty(navigator, 'userAgent', { get: () => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' });
    });
    logger.debug({ message: 'Spoofed navigator properties' });
  } catch (error) {
    logger.warn({ message: 'Navigator properties spoofing failed', error: error.message, stack: error.stack });
  }
}

async function emulateHumanInteractions(page, logger) {
  try {
    await page.evaluate(() => {
      const simulateKeyPress = () => {
        const keys = ['ArrowDown', 'ArrowUp', 'Tab'];
        const key = keys[Math.floor(Math.random() * keys.length)];
        document.dispatchEvent(new KeyboardEvent('keydown', { key }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key }));
      };
      const simulateScroll = () => {
        if (document.body && document.body.scrollHeight) {
          window.scrollBy({ top: window.innerHeight * 0.2, behavior: 'smooth' });
        }
      };
      setTimeout(simulateKeyPress, 400 + Math.random() * 400);
      setTimeout(simulateScroll, 800 + Math.random() * 800);
    });
    await new Promise(resolve => setTimeout(resolve, 1200));
    logger.debug({ message: 'Emulated human interactions' });
  } catch (error) {
    logger.warn({ message: 'Human interactions emulation failed', error: error.message, stack: error.stack });
  }
}

async function randomizeMouseMovements(page, logger) {
  try {
    await page.evaluate(() => {
      const moveMouse = (x, y) => {
        const event = new MouseEvent('mousemove', { bubbles: true, cancelable: true, clientX: x, clientY: y });
        document.dispatchEvent(event);
      };
      const width = window.innerWidth || 1920;
      const height = window.innerHeight || 1080;
      const points = 3;
      for (let i = 0; i < points; i++) {
        const x = width * (0.3 + Math.random() * 0.4);
        const y = height * (0.3 + Math.random() * 0.4);
        setTimeout(() => moveMouse(x, y), i * (150 + Math.random() * 150));
      }
    });
    await new Promise(resolve => setTimeout(resolve, 800));
    logger.debug({ message: 'Randomized mouse movements' });
  } catch (error) {
    logger.warn({ message: 'Mouse movements randomization failed', error: error.message, stack: error.stack });
  }
}

async function spoofTimezone(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      const originalDate = Date;
      const originalGetTimezoneOffset = Date.prototype.getTimezoneOffset;
      Date.prototype.getTimezoneOffset = function() {
        return -120;
      };
      window.Date = class extends originalDate {
        constructor(...args) {
          super(...args);
          this.getTimezoneOffset = () => -120;
        }
      };
      Object.defineProperty(Intl, 'DateTimeFormat', {
        value: function() {
          return {
            resolvedOptions: () => ({ timeZone: 'Europe/Prague', calendar: 'gregory', numberingSystem: 'latn' }),
            format: (date) => new originalDate(date).toLocaleString('cs-CZ', { timeZone: 'Europe/Prague' })
          };
        }
      });
    });
    logger.debug({ message: 'Spoofed timezone to Europe/Prague' });
  } catch (error) {
    logger.warn({ message: 'Timezone spoofing failed', error: error.message, stack: error.stack });
  }
}

async function emulateDeviceMemory(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
    });
    logger.debug({ message: 'Emulated device memory to 8GB' });
  } catch (error) {
    logger.warn({ message: 'Device memory emulation failed', error: error.message, stack: error.stack });
  }
}

async function spoofPerformanceTiming(page, logger) {
  try {
    await page.evaluateOnNewDocument(() => {
      const baseTime = performance.now();
      Object.defineProperty(performance, 'timing', {
        value: {
          navigationStart: baseTime,
          fetchStart: baseTime + 50,
          domainLookupStart: baseTime + 60,
          domainLookupEnd: baseTime + 80,
          connectStart: baseTime + 90,
          connectEnd: baseTime + 120,
          requestStart: baseTime + 130,
          responseStart: baseTime + 150,
          responseEnd: baseTime + 180,
          domLoading: baseTime + 190,
          domInteractive: baseTime + 220,
          domContentLoadedEventStart: baseTime + 230,
          domContentLoadedEventEnd: baseTime + 240,
          domComplete: baseTime + 260,
          loadEventStart: baseTime + 270,
          loadEventEnd: baseTime + 280
        }
      });
    });
    logger.debug({ message: 'Spoofed performance timing' });
  } catch (error) {
    logger.warn({ message: 'Performance timing spoofing failed', error: error.message, stack: error.stack });
  }
}

async function cleanPrice(priceText, currency = 'CZK') {
  if (!priceText || typeof priceText !== 'string' || priceText.trim() === '') {
    logger.warn({ message: 'Invalid or empty price text', priceText, currency });
    return { price: null, currency };
  }
  let cleaned = priceText
    .replace(/[^\d.,-]/g, '')
    .replace(/\s/g, '')
    .replace(/,-$/, '');
  cleaned = cleaned.replace('.', '').replace(',', '.');
  const parsed = parseFloat(cleaned);
  if (isNaN(parsed)) {
    logger.warn({ message: 'Failed to parse price', priceText, cleaned, currency });
    return { price: null, currency };
  }
  return { price: parsed.toFixed(2), currency };
}

async function cleanupSession(page, context, browser, logger) {
  try {
    if (page && !page.isClosed()) {
      try {
        await page.evaluate(() => {
          if (window.turnstile) window.turnstile.reset?.();
          window.sessionStorage?.clear();
          window.localStorage?.clear();
        }).catch(() => {});
        await page.close().catch(() => {});
      } catch (error) {
        logger.warn({ message: 'Page cleanup failed, forcing close', error: error.message, stack: error.stack });
        await page.close().catch(() => {});
      }
    }
    if (context) {
      await closeResource(context, 'BrowserContext');
    }
    if (browser) {
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          const browserProcess = await browser.process();
          if (browserProcess && !browserProcess.signalCode) {
            const pid = browserProcess.pid;
            browserProcess.kill('SIGTERM');
            await new Promise(resolve => setTimeout(resolve, 400));
            try {
              await execPromise(`ps -p ${pid}`);
              logger.warn({ message: `Browser process ${pid} still running after SIGTERM, attempt ${attempt}` });
            } catch (error) {
              logger.debug({ message: `Browser process ${pid} terminated, attempt ${attempt}` });
            }
          }
          await browser.close();
          logger.debug({ message: 'Browser closed successfully' });
          break;
        } catch (error) {
          logger.warn({ message: `Browser cleanup attempt ${attempt}/3 failed`, error: error.message, stack: error.stack });
          if (attempt < 3) {
            await new Promise(resolve => setTimeout(resolve, 600));
            continue;
          }
          logger.error({ message: 'Browser cleanup failed after all attempts', error: error.message, stack: error.stack });
          if (browserProcess && !browserProcess.signalCode) {
            browserProcess.kill('SIGKILL');
            logger.debug({ message: 'Browser process killed with SIGKILL' });
          }
        }
      }
    }
    logger.debug({ message: 'Session cleaned up' });
  } catch (error) {
    logger.error({ message: 'Session cleanup failed', error: error.message, stack: error.stack });
  }
}

async function manageSession(page, url, cache, logger) {
  try {
    if (!cache || typeof cache.set !== 'function') {
      logger.warn({ message: 'No valid cache provided for session management, skipping cache operations', url });
    } else {
      const sessionKey = `session:${url}`;
      const sessionData = cache.get(sessionKey) || {};
      await page.setCookie(...(sessionData.cookies || []));
      cache.set(sessionKey, { cookies: await page.cookies(), timestamp: Date.now() });
      logger.debug({ message: 'Managed session', sessionKey });
    }
  } catch (error) {
    logger.warn({ message: 'Failed to manage session', url, error: error.message, stack: error.stack });
  }
}

async function closeResource(resource, type) {
  if (!resource) return;
  try {
    if (type === 'Browser') {
      await resource.close();
    } else if (type === 'BrowserContext') {
      await resource.close();
    } else if (type === 'Page' && !resource.isClosed()) {
      await resource.close();
    }
    logger.debug({ message: `Closed ${type}` });
  } catch (error) {
    logger.warn({ message: `Failed to close ${type}`, error: error.message, stack: error.stack });
  }
}

module.exports = {
  withRetry,
  simulateTyping,
  simulateRandomUserInteractions,
  randomizeRequestTiming,
  spoofCanvasFingerprint,
  isCloudflareBlocked,
  isDataDomeBlocked,
  spoofGeolocation,
  rotateBrowserFingerprint,
  setDynamicHeaders,
  minimizeFingerprint,
  spoofScreenProperties,
  disableWebRTC,
  simulateTouchEvents,
  spoofBrowserFeatures,
  spoofNavigatorProperties,
  emulateHumanInteractions,
  randomizeMouseMovements,
  spoofTimezone,
  emulateDeviceMemory,
  spoofPerformanceTiming,
  cleanPrice,
  cleanupSession,
  manageSession,
  closeResource
};