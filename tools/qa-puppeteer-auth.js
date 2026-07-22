const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const url = require('url');

(async () => {
  const QA_PHPSESSID = process.env.QA_PHPSESSID;
  const BASE = process.env.BASE_URL || 'https://asl.erpcondominios.com.br';
  if (!QA_PHPSESSID) {
    console.error('Please set QA_PHPSESSID environment variable (do not commit it).');
    process.exit(2);
  }

  const pagesToTest = [
    '/frontend/TEST_FASE6_QA.html',
    '/frontend/dashboard.html',
    '/frontend/estoque.html',
    '/frontend/acesso.html'
  ];

  const host = url.parse(BASE).hostname;

  const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'], executablePath: chromePath });
  const page = await browser.newPage();
  page.setDefaultTimeout(30000);

  // set cookie for domain
  const cookie = {
    name: 'PHPSESSID',
    value: QA_PHPSESSID,
    domain: host,
    path: '/',
    httpOnly: true,
    secure: true
  };

  // results container
  const results = { timestamp: new Date().toISOString(), base: BASE, pages: [] };

  for (const p of pagesToTest) {
    const full = BASE + p;
    const resItem = { page: p, url: full, checks: {}, events: [], apiRequests: [], notes: [] };

    try {
      // navigate to base origin first so cookie domain/url is in scope, then set cookie
      try {
        await page.goto(BASE, { waitUntil: 'networkidle2' });
      } catch(e) {
        // ignore navigation errors to base
      }

      // set cookie using url to ensure it's attached
      const cookieWithUrl = Object.assign({}, cookie, { url: BASE });
      await page.setCookie(cookieWithUrl);

      // confirm cookie present in page
      const cookiesNow = await page.cookies(BASE);
      if (!cookiesNow.some(c => c.name === 'PHPSESSID')) {
        resItem.notes.push('Cookie not present after setCookie');
      }

      // set up network listeners
      const apiReqs = [];
      page.on('request', req => {
        const u = req.url();
        if (u.includes('/api/')) {
          apiReqs.push({ id: req._requestId || null, url: u, method: req.method(), headers: req.headers() });
        }
      });
      const apiResps = [];
      page.on('response', resp => {
        const u = resp.url();
        if (u.includes('/api/')) {
          apiResps.push({ url: u, status: resp.status() });
        }
      });

      // prepare in-page events array and handler
      await page.evaluateOnNewDocument(() => {
        window._qa_events = [];
        window._qa_event = function(ev) { window._qa_events.push(ev); };
      });

      // navigate to page
      await page.goto(full, { waitUntil: 'networkidle2' });

      // wait briefly for initialization
      await page.waitForTimeout(1500);

      // perform checks in page context
      const checkResult = await page.evaluate(() => {
        const out = { hasSessionManager: false, isInstance: false, isAuthenticated: null, eventsRegistered: false, storage: null };
        try {
          const sm = window.sessionManager || (window.SessionManagerCore && window.SessionManagerCore.instance) || null;
          out.hasSessionManager = !!sm || !!(window.SessionManagerCore);
          out.isInstance = !!(window.sessionManager && window.sessionManager instanceof window.SessionManagerCore);
          // fallback checks for property names
          if (window.sessionManager) {
            out.isAuthenticated = typeof window.sessionManager.isAuthenticated !== 'undefined' ? window.sessionManager.isAuthenticated : (typeof window.sessionManager.isLoggedIn === 'function' ? window.sessionManager.isLoggedIn() : null);
            try { window.sessionManager.on && window.sessionManager.on('sessionRenewed', () => window._qa_event && window._qa_event('sessionRenewed')); } catch(e) {}
            try { window.sessionManager.on && window.sessionManager.on('sessionExpired', () => window._qa_event && window._qa_event('sessionExpired')); } catch(e) {}
            try { window.sessionManager.on && window.sessionManager.on('userDataChanged', () => window._qa_event && window._qa_event('userDataChanged')); } catch(e) {}

        // expose collected events array if exists
        try { if (window._qa_events && window._qa_events.length) { /* noop */ } } catch(e) {}
          }
          // localStorage
          const raw = localStorage.getItem('asl-session');
          if (!raw) out.storage = { present: false };
          else {
            const parsed = JSON.parse(raw);
            out.storage = { present: true, keys: Object.keys(parsed), parsed };
          }
        } catch (e) {
          out.error = e.message;
        }
        return out;
      });

      resItem.checks.basic = checkResult;

      // Trigger renewSession if possible and observe API calls and events
      let renewResult = { triggered: false, apiCalls: 0, cookieSent: false };
      try {
        const renewInfo = await page.evaluate(async () => {
          if (!window.sessionManager) return { ok: false, reason: 'no_sessionManager' };
          try {
            const before = performance.now();
            await window.sessionManager.renewSession();
            const after = performance.now();
            return { ok: true, duration: after - before };
          } catch (e) { return { ok: false, reason: e.message }; }
        });
        renewResult.triggered = renewInfo.ok === true;
        renewResult.duration = renewInfo.duration || null;
      } catch (e) {
        renewResult.error = e.message;
      }

      // wait a bit to collect network activity
      await page.waitForTimeout(2000);

      // analyze apiReqs collected (without exposing cookie values)
      const apiSummary = apiReqs.map(r => ({ url: r.url, method: r.method, hasCookieHeader: !!(r.headers && r.headers.cookie) }));
      resItem.apiRequests = apiSummary;
      renewResult.apiCalls = apiSummary.filter(a => a.url.includes('/api/')).length;
      renewResult.cookieSent = apiSummary.some(a => a.hasCookieHeader === true);

      resItem.checks.renew = renewResult;

      // Test logout: call sessionManager.logout() if available
      let logoutRes = { attempted: false, redirected: false, storageCleared: false, cookieCleared: false };
      try {
        const logoutAttempt = await page.evaluate(async () => {
          if (!window.sessionManager) return { ok: false, reason: 'no_sessionManager' };
          try {
            await window.sessionManager.logout();
            return { ok: true };
          } catch (e) { return { ok: false, reason: e.message }; }
        });
        logoutRes.attempted = logoutAttempt.ok === true;
        await page.waitForTimeout(1500);
        // check storage
        const storageAfter = await page.evaluate(() => {
          const raw = localStorage.getItem('asl-session');
          return raw;
        });
        logoutRes.storageCleared = !storageAfter;
        // check redirect
        const loc = page.url();
        logoutRes.redirected = /login|index|portal/i.test(loc);
        // check cookie removal
        const cookies = await page.cookies(BASE);
        logoutRes.cookieCleared = !cookies.some(c => c.name === 'PHPSESSID');
      } catch (e) {
        logoutRes.error = e.message;
      }

      resItem.checks.logout = logoutRes;

      // measure api requests per minute by extrapolating from observed in short window
      const observedApiCount = apiSummary.length;
      const observedWindowSec = 5; // our observation window roughly
      const perMin = Math.round((observedApiCount / observedWindowSec) * 60);
      resItem.metrics = { observedApiCount, perMinute: perMin, baselinePollingPerMin: 360 };
      resItem.metrics.reduction = Math.round((1 - (perMin / resItem.metrics.baselinePollingPerMin)) * 100);

      resItem.status = 'ok';

    } catch (err) {
      resItem.status = 'error';
      resItem.error = err.message;
    }

    results.pages.push(resItem);

    // small pause between pages
    await page.waitForTimeout(500);
  }

  await browser.close();

  const outFile = path.join(__dirname, 'qa-results-auth.json');
  fs.writeFileSync(outFile, JSON.stringify(results, null, 2));

  // summary markdown
  const md = [];
  md.push('# QA Authenticated Results');
  md.push(`Generated: ${new Date().toISOString()}`);
  md.push('');
  let overallPass = true;
  md.push('| Page | Core Init | isAuthenticated | Renew OK | Logout OK | localStorage Safe | req/min | reduction |');
  md.push('|---|---:|---:|---:|---:|---:|---:|---:|');
  for (const p of results.pages) {
    const basic = p.checks.basic || {};
    const renew = p.checks.renew || {};
    const logout = p.checks.logout || {};
    const storage = (basic.storage && basic.storage.present) ? basic.storage : { present: false };

    const coreInit = basic.hasSessionManager ? 'YES' : 'NO';
    const isAuth = (basic.isAuthenticated === true) ? 'YES' : 'NO';
    const renewOk = (renew.triggered === true && renew.cookieSent === true) ? 'YES' : 'NO';
    const logoutOk = (logout.attempted === true && logout.storageCleared === true) ? 'YES' : 'NO';
    const storageSafe = (!storage.present || (storage.keys && storage.keys.length <= 2 && !storage.keys.includes('currentUser') && !storage.keys.includes('sessionExpireTime'))) ? 'YES' : 'NO';
    const perMin = p.metrics ? p.metrics.perMinute : 0;
    const reduction = p.metrics ? p.metrics.reduction : 0;

    const pass = coreInit === 'YES' && isAuth === 'YES' && renewOk === 'YES' && logoutOk === 'YES' && storageSafe === 'YES' && reduction >= 80;
    overallPass = overallPass && pass;

    md.push(`| ${p.page} | ${coreInit} | ${isAuth} | ${renewOk} | ${logoutOk} | ${storageSafe} | ${perMin} | ${reduction}% |`);
  }

  md.push('');
  md.push(`**Overall:** ${overallPass ? 'PASS' : 'FAIL'}`);
  const mdFile = path.join(__dirname, 'qa-results-auth.md');
  fs.writeFileSync(mdFile, md.join('\n'));

  console.log('QA authenticated complete. Results:', outFile, 'report:', mdFile);
  process.exit(0);
})();
