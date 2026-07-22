#!/usr/bin/env node
// tools/qa-puppeteer-auth-header.js
// Usage: QA_PHPSESSID=... node tools/qa-puppeteer-auth-header.js
// Optional env: QA_BASE_URL (defaults to https://asl.erpcondominios.com.br)

const fs = require('fs');
const puppeteer = require('puppeteer');

const BASE = process.env.QA_BASE_URL || 'https://asl.erpcondominios.com.br';
const PHPSESSID = process.env.QA_PHPSESSID || '';
const OUT = 'tools/qa-results-auth-header.json';

const PAGES = [
  '/frontend/TEST_FASE6_QA.html',
  '/frontend/dashboard.html',
  '/frontend/estoque.html',
  '/frontend/acesso.html'
];

function maskValue(v){
  if(!v) return '';
  return '***masked***';
}

(async ()=>{
  if(!PHPSESSID){
    console.error('Warning: QA_PHPSESSID is empty. Run with QA_PHPSESSID=...');
  }
  const browser = await puppeteer.launch({headless: true});
  const results = [];

  for(const pagePath of PAGES){
    const page = await browser.newPage();

    // Install header injection if PHPSESSID present
    if(PHPSESSID){
      await page.setExtraHTTPHeaders({ 'Cookie': `PHPSESSID=${PHPSESSID}` });
    }

    const full = BASE + pagePath;
    const pageResult = { page: pagePath, url: full, requests: [], cookies: [] };

    // intercept requests to capture whether Cookie header is present
    await page.setRequestInterception(true);
    page.on('request', req => {
      const headers = req.headers();
      pageResult.requests.push({ url: req.url(), method: req.method(), hasCookieHeader: !!headers.cookie });
      req.continue();
    });

    try{
      const resp = await page.goto(full, { waitUntil: 'networkidle2', timeout: 30000 });
      pageResult.status = resp && resp.status();
    }catch(e){
      pageResult.error = String(e.message || e);
    }

    // report page.cookies() (but mask values)
    try{
      const cookies = await page.cookies();
      pageResult.cookies = cookies.map(c=>({ name: c.name, value: maskValue(c.value), domain: c.domain, path: c.path, secure: c.secure, httpOnly: c.httpOnly, sameSite: c.sameSite, expires: c.expires }));
    }catch(e){
      pageResult.cookiesError = String(e.message || e);
    }

    results.push(pageResult);
    await page.close();
  }

  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify({ generatedAt: new Date().toISOString(), base: BASE, results }, null, 2));
  console.log('Wrote', OUT);
})();
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const pathModule = require('path');

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

  const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'], executablePath: chromePath });
  const page = await browser.newPage();
  page.setDefaultTimeout(30000);

  // Set extra HTTP headers to inject Cookie in all requests (QA mode)
  const cookieHeader = `PHPSESSID=${QA_PHPSESSID}`;
  await page.setExtraHTTPHeaders({
    'Cookie': cookieHeader,
    'User-Agent': 'QA-Puppeteer/1.0'
  });

  // results container
  const results = {
    timestamp: new Date().toISOString(),
    base: BASE,
    authMode: 'header-injection',
    pages: []
  };

  for (const p of pagesToTest) {
    const full = BASE + p;
    const resItem = { page: p, url: full, checks: {}, events: [], apiRequests: [], notes: [] };

    try {
      // set up network listeners
      const apiReqs = [];
      page.on('request', req => {
        const u = req.url();
        if (u.includes('/api/')) {
          const headers = req.headers();
          apiReqs.push({
            url: u,
            method: req.method(),
            hasCookie: !!(headers && headers['cookie'])
          });
        }
      });

      const apiResps = [];
      page.on('response', resp => {
        const u = resp.url();
        if (u.includes('/api/')) {
          apiResps.push({ url: u, status: resp.status() });
        }
      });

      // prepare in-page events array
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
        const out = {
          hasSessionManager: false,
          isInstance: false,
          isAuthenticated: null,
          eventsRegistered: false,
          storage: null,
          currentUser: null
        };
        try {
          const sm = window.sessionManager || (window.SessionManagerCore && window.SessionManagerCore.instance) || null;
          out.hasSessionManager = !!sm || !!(window.SessionManagerCore);
          out.isInstance = !!(window.sessionManager && window.sessionManager instanceof window.SessionManagerCore);

          if (window.sessionManager) {
            out.isAuthenticated = typeof window.sessionManager.isAuthenticated !== 'undefined'
              ? window.sessionManager.isAuthenticated
              : (typeof window.sessionManager.isLoggedIn === 'function' ? window.sessionManager.isLoggedIn() : null);

            // Try to register event listeners
            try {
              window.sessionManager.on && window.sessionManager.on('sessionRenewed', () => window._qa_event && window._qa_event('sessionRenewed'));
            } catch (e) {}
            try {
              window.sessionManager.on && window.sessionManager.on('sessionExpired', () => window._qa_event && window._qa_event('sessionExpired'));
            } catch (e) {}
            try {
              window.sessionManager.on && window.sessionManager.on('userDataChanged', () => window._qa_event && window._qa_event('userDataChanged'));
            } catch (e) {}

            // Capture user info if available (but don't log it)
            if (window.sessionManager.currentUser) {
              out.currentUser = 'present_' + typeof window.sessionManager.currentUser;
            }
          }

          // localStorage
          const raw = localStorage.getItem('asl-session');
          if (!raw) {
            out.storage = { present: false };
          } else {
            const parsed = JSON.parse(raw);
            out.storage = {
              present: true,
              keys: Object.keys(parsed),
              safe: !parsed.currentUser && !parsed.sessionExpireTime && !parsed.token
            };
          }
        } catch (e) {
          out.error = e.message;
        }
        return out;
      });

      resItem.checks.basic = checkResult;

      // Trigger renewSession if available
      let renewResult = { triggered: false, apiCallsDuringRenew: 0, hasCredentials: false };
      try {
        const renewInfo = await page.evaluate(async () => {
          if (!window.sessionManager) return { ok: false, reason: 'no_sessionManager' };
          try {
            const before = performance.now();
            await window.sessionManager.renewSession();
            const after = performance.now();
            return { ok: true, duration: after - before };
          } catch (e) {
            return { ok: false, reason: e.message };
          }
        });
        renewResult.triggered = renewInfo.ok === true;
        renewResult.duration = renewInfo.duration || null;
      } catch (e) {
        renewResult.error = e.message;
      }

      // wait for network activity
      await page.waitForTimeout(2000);

      // analyze API requests
      const apiSummary = apiReqs.map(r => ({
        url: r.url,
        method: r.method,
        hasCookie: r.hasCookie
      }));
      resItem.apiRequests = apiSummary;
      renewResult.apiCallsDuringRenew = apiSummary.length;
      renewResult.hasCredentials = apiSummary.some(a => a.hasCookie === true);

      resItem.checks.renew = renewResult;

      // Test logout
      let logoutRes = { attempted: false, redirected: false, storageCleared: false };
      try {
        const logoutAttempt = await page.evaluate(async () => {
          if (!window.sessionManager) return { ok: false, reason: 'no_sessionManager' };
          try {
            await window.sessionManager.logout();
            return { ok: true };
          } catch (e) {
            return { ok: false, reason: e.message };
          }
        });
        logoutRes.attempted = logoutAttempt.ok === true;
        await page.waitForTimeout(1500);

        const storageAfter = await page.evaluate(() => localStorage.getItem('asl-session'));
        logoutRes.storageCleared = !storageAfter;

        const loc = page.url();
        logoutRes.redirected = /login|index|portal/i.test(loc);
      } catch (e) {
        logoutRes.error = e.message;
      }

      resItem.checks.logout = logoutRes;

      // Measure API request reduction
      const observedApiCount = apiSummary.length;
      const observedWindowSec = 5;
      const perMin = Math.round((observedApiCount / observedWindowSec) * 60);
      resItem.metrics = {
        observedApiCount,
        perMinute: perMin,
        baselinePollingPerMin: 360
      };
      resItem.metrics.reduction = Math.round((1 - (perMin / resItem.metrics.baselinePollingPerMin)) * 100);

      resItem.status = 'ok';
    } catch (err) {
      resItem.status = 'error';
      resItem.error = err.message;
    }

    results.pages.push(resItem);
    await page.waitForTimeout(500);
  }

  await browser.close();

  const outFile = pathModule.join(__dirname, 'qa-results-auth-header.json');
  fs.writeFileSync(outFile, JSON.stringify(results, null, 2));

  // Generate markdown summary
  const md = [];
  md.push('# QA Results (Header-Injected Auth Mode)');
  md.push(`Generated: ${new Date().toISOString()}`);
  md.push(`Auth Mode: Cookie injected via HTTP headers (all requests)`);
  md.push('');
  md.push('| Page | Core Init | isAuth | Renew Triggered | Cookie Sent | Logout OK | Storage Safe | req/min | reduction |');
  md.push('|---|---:|---:|---:|---:|---:|---:|---:|---:|');

  let overallPass = true;
  for (const p of results.pages) {
    const basic = p.checks.basic || {};
    const renew = p.checks.renew || {};
    const logout = p.checks.logout || {};
    const storage = basic.storage || { present: false };

    const coreInit = basic.hasSessionManager ? 'YES' : 'NO';
    const isAuth = (basic.isAuthenticated === true) ? 'YES' : 'NO';
    const renewOk = renew.triggered === true ? 'YES' : 'NO';
    const cookieSent = renew.hasCredentials === true ? 'YES' : 'NO';
    const logoutOk = (logout.attempted === true && logout.storageCleared === true) ? 'YES' : 'NO';
    const storageSafe = (!storage.present || (storage.safe === true)) ? 'YES' : 'NO';
    const perMin = p.metrics ? p.metrics.perMinute : 0;
    const reduction = p.metrics ? p.metrics.reduction : 0;

    const pass = coreInit === 'YES' && isAuth === 'YES' && renewOk === 'YES' && cookieSent === 'YES' && logoutOk === 'YES' && storageSafe === 'YES' && reduction >= 80;
    overallPass = overallPass && pass;

    md.push(`| ${p.page} | ${coreInit} | ${isAuth} | ${renewOk} | ${cookieSent} | ${logoutOk} | ${storageSafe} | ${perMin} | ${reduction}% |`);
  }

  md.push('');
  md.push(`**Overall Status:** ${overallPass ? '✅ PASS' : '❌ FAIL'}`);
  md.push('');
  md.push('## Notes');
  md.push('- Cookie injected via HTTP `Cookie` header in all requests (QA mode)');
  md.push('- All pages should show cookie being sent in API requests');
  md.push('- SessionManagerCore should initialize with auth=true');
  md.push('- Renewal and logout should work correctly with header-injected auth');

  const mdFile = pathModule.join(__dirname, 'qa-results-auth-header.md');
  fs.writeFileSync(mdFile, md.join('\n'));

  console.log(`✅ QA (header auth) complete. Results: ${outFile} report: ${mdFile}`);
  process.exit(0);
})();
