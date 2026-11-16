/* Vibes Team Portal: automated page check.
 * Run against the live site (or staging, by changing BASE) after any deploy.
 * Logs in isn't automated here: pass cookies via COOKIE env (copied from an
 * authenticated browser session) because wp-admin login needs a human.
 * Usage: BASE=https://team.vibesagency.co COOKIE="..." node page-check.js
 */
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'https://team.vibesagency.co';
const ROUTES = [
  'dashboard', 'my-day', 'inquiries', 'quotations', 'client-reviews',
  'projects', 'tasks', 'calendar', 'files',
  'employees', 'directory', 'leave', 'announcements',
  'our-work', 'pricing',
  'invoices', 'analytics', 'payslips',
];

async function checkRoute(page, route) {
  const consoleErrors = [];
  const pageErrors = [];
  const failedRequests = [];
  const onConsole = (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 200)); };
  const onPageError = (err) => pageErrors.push(String(err).slice(0, 200));
  const onResponse = (res) => { if (res.status() >= 400 && res.url().includes('/wp-json/')) failedRequests.push(res.status() + ' ' + res.url().replace(BASE, '')); };
  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);

  const reqCounts = {};
  const onReq = (req) => { if (req.url().includes('/wp-json/')) { const k = req.url().replace(BASE, '').split('?')[0]; reqCounts[k] = (reqCounts[k] || 0) + 1; } };
  page.on('request', onReq);

  const t0 = Date.now();
  await page.goto(BASE + '/team-portal/#' + route, { waitUntil: 'networkidle', timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);
  const ms = Date.now() - t0;
  const bodyText = await page.locator('#vtp-view').innerText().catch(() => '');
  const looksEmpty = bodyText.trim().length < 10;
  const dup = Object.entries(reqCounts).filter(([, n]) => n > 2);

  page.off('console', onConsole); page.off('pageerror', onPageError); page.off('response', onResponse); page.off('request', onReq);
  return { route, ms, consoleErrors, pageErrors, failedRequests, looksEmpty, dup };
}

(async () => {
  const cookie = process.env.COOKIE;
  if (!cookie) { console.error('Set COOKIE to an authenticated session cookie string for ' + BASE); process.exit(1); }
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const url = new URL(BASE);
  const cookies = cookie.split(';').map((p) => p.trim()).filter(Boolean).map((p) => {
    const i = p.indexOf('='); return { name: p.slice(0, i), value: p.slice(i + 1), domain: url.hostname, path: '/' };
  });
  await context.addCookies(cookies);
  const page = await context.newPage();

  const results = [];
  for (const route of ROUTES) results.push(await checkRoute(page, route));
  await browser.close();

  let bad = 0;
  for (const r of results) {
    const problems = [];
    if (r.ms > 4000) problems.push(`slow (${r.ms}ms)`);
    if (r.consoleErrors.length) problems.push(`console errors: ${r.consoleErrors.join(' | ')}`);
    if (r.pageErrors.length) problems.push(`page errors: ${r.pageErrors.join(' | ')}`);
    if (r.failedRequests.length) problems.push(`failed requests: ${r.failedRequests.join(' | ')}`);
    if (r.looksEmpty) problems.push('page looks empty');
    if (r.dup.length) problems.push(`duplicate requests: ${r.dup.map(([k, n]) => `${k}×${n}`).join(', ')}`);
    if (problems.length) { bad++; console.log(`✗ #${r.route} (${r.ms}ms): ${problems.join(' ; ')}`); }
    else console.log(`✓ #${r.route} (${r.ms}ms)`);
  }
  console.log(bad ? `\n${bad} route(s) need attention.` : '\nAll routes look healthy.');
  process.exit(bad ? 1 : 0);
})();
