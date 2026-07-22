// Use puppeteer-core and an existing Chrome/Chromium installation to avoid large downloads.
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const pathModule = require('path');

(async () => {
  const results = [];
  const base = 'http://localhost/dashboard/asl.erpcondominios.com.br/frontend';

  // pages to validate (sample + test page)
  const pages = [
    '/TEST_FASE6_QA.html',
    '/dashboard.html',
    '/estoque.html',
    '/acesso.html'
  ];

  // Determine executablePath from environment or common install locations (Windows)
  const chromePath = process.env.CHROME_PATH ||
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' ||
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe';

  const launchOptions = { headless: true, args: ['--no-sandbox'] };
  if (chromePath) launchOptions.executablePath = chromePath;

  const browser = await puppeteer.launch(launchOptions);
  const page = await browser.newPage();
  page.setDefaultTimeout(20000);

  for (const p of pages) {
    const url = base + p;
    const item = { page: p, url, checks: [] };
    try {
      console.log(`Opening ${url}`);
      await page.goto(url, { waitUntil: 'networkidle2' });

      // 1) Check Core present: either global instance or class defined or script tag present
      const coreInfo = await page.evaluate(() => {
        try {
          const hasInstance = typeof window.sessionManager !== 'undefined';
          const hasClass = typeof window.SessionManagerCore === 'function';
          const scriptEl = document.querySelector('script[src*="session-manager-core.js"]');
          const scriptSrc = scriptEl ? scriptEl.getAttribute('src') : null;
          return { hasInstance, hasClass, scriptSrc };
        } catch (e) {
          return { error: e.message };
        }
      });
      item.checks.push({ name: 'coreLoaded', ok: !!(coreInfo && (coreInfo.hasInstance || coreInfo.hasClass || coreInfo.scriptSrc)), details: coreInfo });

      // 2) localStorage content
      const ls = await page.evaluate(() => {
        try {
          const raw = localStorage.getItem('asl-session');
          if (!raw) return { present: false };
          const parsed = JSON.parse(raw);
          return { present: true, keys: Object.keys(parsed), data: parsed };
        } catch (e) {
          return { error: e.message };
        }
      });
      item.checks.push({ name: 'localStorage', ok: ls.present ? (ls.keys.length <= 2) : true, details: ls });

      // 3) Console errors (capture)
      const consoleMessages = [];
      page.on('console', msg => {
        if (msg.type() === 'error') consoleMessages.push(msg.text());
      });

      // 4) Network offline simulation
      try {
        await page.setOfflineMode(true);
        await page.waitForTimeout(1000);
        const isOnlineFlag = await page.evaluate(() => navigator.onLine);
        item.checks.push({ name: 'offlineDetect', ok: isOnlineFlag === false });
      } catch (e) {
        item.checks.push({ name: 'offlineDetect', ok: false, error: e.message });
      } finally {
        await page.setOfflineMode(false);
      }

      // 5) Try to trigger logout button if present
      const logoutExists = await page.$('#btn-logout');
      if (logoutExists) {
        try {
          await page.click('#btn-logout');
          await page.waitForTimeout(1000);
          item.checks.push({ name: 'logoutClick', ok: true });
        } catch (e) {
          item.checks.push({ name: 'logoutClick', ok: false, error: e.message });
        }
      } else {
        item.checks.push({ name: 'logoutClick', ok: 'not_found' });
      }

      // 6) Capture console errors count
      item.checks.push({ name: 'consoleErrors', ok: consoleMessages.length === 0, errors: consoleMessages });

      // 7) Check whether the session-manager-core.js file is directly available via HTTP and on disk
      try {
        const scriptUrl = base + '/js/session-manager-core.js';
        let httpStatus = null;
        try {
          const resp = await page.goto(scriptUrl, { waitUntil: 'domcontentloaded' });
          httpStatus = resp ? resp.status() : null;
        } catch (e) {
          httpStatus = 'error:' + e.message;
        }
        // navigate back to original page
        await page.goto(url, { waitUntil: 'networkidle2' });

        const diskPath = pathModule.join(__dirname, '..', 'frontend', 'js', 'session-manager-core.js');
        const existsOnDisk = fs.existsSync(diskPath);

        const scriptAvailable = (httpStatus === 200) || existsOnDisk;
        item.checks.push({ name: 'scriptAvailable', ok: scriptAvailable, details: { httpStatus, existsOnDisk, diskPath } });
      } catch (e) {
        item.checks.push({ name: 'scriptAvailable', ok: false, error: e.message });
      }

      item.status = 'ok';
    } catch (err) {
      item.status = 'error';
      item.error = err.message;
    }
    results.push(item);
  }

  await browser.close();

  const path = require('path');
  const out = { timestamp: new Date().toISOString(), results };
  const outFile = path.join(__dirname, 'qa-results.json');
  fs.writeFileSync(outFile, JSON.stringify(out, null, 2));
  console.log('\nQA complete. Results saved to tools/qa-results.json');
  process.exit(0);
})();
