// Headless Chromium proof of slice 6 (docs/build-specs/short-side.md "Proof"), at 375 x 740 and 1280 x 800: the short side's one chart with
// every panel, its legend, its source lines and FINRA's caveat; at 375 the window opening at 90 days; the five tables; a borrow observation
// recorded through the form and shown "indicative"; a Viewer reading every screen with no Record button. No sideways scroll, no control past
// its card, every control ≥ 44 px, no console errors. Run through tests/phase3/slice6/run.sh (the data is seeded by seed_browser.php).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s6';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const seed = JSON.parse(execFileSync('php', [new URL('./seed_browser.php', import.meta.url).pathname], { encoding: 'utf8' }).trim().split('\n').pop());
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/?issuer=' + seed.issuer, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const smallControls = (page) => page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button:not(.btn-link):not(.avatar-text), #page-content input:not([type=hidden]):not([type=checkbox]):not([type=radio]), #page-content select')]
  .filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44; }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)));
const clipped = (page) => page.evaluate(() => [...document.querySelectorAll('#page-content input:not([type=hidden]), #page-content select, #page-content button, #page-content a.btn')]
  .filter((a) => { const r = a.getBoundingClientRect(); if (r.width === 0) return false; const c = a.closest('.card'); const cr = c ? c.getBoundingClientRect() : { left: 0, right: window.innerWidth }; return r.right > cr.right + 1 || r.left < cr.left - 1 || r.right > window.innerWidth; })
  .map((a) => a.id || a.tagName));
const check = async (page, label) => {
  ok(await overflow(page) <= 0, `no sideways scroll on ${label}`);
  const c = await clipped(page);
  ok(c.length === 0, `no control on ${label} reaches past its card or the screen` + (c.length ? ': ' + c.slice(0, 6).join(', ') : ''));
  const s = await smallControls(page);
  ok(s.length === 0, `every control on ${label} is at least 44 px tall` + (s.length ? ': ' + s.slice(0, 6).join(', ') : ''));
};
const visit = async (page, path, sel, label, shot) => {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  ok(await page.locator(sel).count() > 0, `${label}: ${sel} is there`);
  await check(page, label);
  if (shot) await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};
const sec = seed.security;
const days = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 86400000);
for (const [vw, tag] of [[{ width: 375, height: 740 }, 'phone'], [{ width: 1280, height: 800 }, 'desktop']]) {
  const { ctx, page, errors } = await session(26, vw);
  console.log(`${vw.width} x ${vw.height} — an Analyst`);
  await page.goto(BASE + `/short?security=${sec}&to=2024-04-04`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.waitForLoadState('networkidle');
  const url = new URL(page.url());
  const span = days(url.searchParams.get('from') || '2023-10-07', url.searchParams.get('to') || '2024-04-04');
  ok(vw.width < 600 ? span === 90 : span === 180, `the window opens at ${vw.width < 600 ? 90 : 180} days (${span})`);
  await page.goto(BASE + `/short?security=${sec}&from=2024-01-01&to=2024-04-04`, { waitUntil: 'networkidle' });
  for (const id of ['short-chart-price', 'short-chart-volume', 'short-chart-si', 'short-chart-fails', 'short-chart-ratio', 'short-chart-threshold', 'short-chart-events']) {
    ok(await page.locator('#' + id).count() === 1, `the chart's ${id.replace('short-chart-', '')} panel is drawn`);
  }
  const box = await page.locator('#short-chart-svg').boundingBox();
  ok(box && box.width <= vw.width && box.height > 300, `the chart fits the screen (${Math.round(box?.width)} x ${Math.round(box?.height)})`);
  ok(await page.locator('#short-chart-si circle').count() === 5 && await page.locator('#short-chart-threshold rect').count() === 6, 'five short-interest points, six threshold bands');
  ok(await page.locator('#short-caveat').isVisible() && (await page.locator('#short-caveat').innerText()).includes('Not comparable with short interest'), 'FINRA\'s caveat visible under the chart');
  ok(await page.locator('#short-chart-sources .si-source-line').count() === 6, 'six source lines');
  ok((await page.locator('#short-tile-threshold').innerText()).includes('On the Nasdaq list since'), 'the threshold tile: on the Nasdaq list');
  await check(page, 'the short side');
  await page.screenshot({ path: `${SHOTS}/${tag}-short.png`, fullPage: true });
  await visit(page, `/short/interest?security=${sec}&from=2024-01-01&to=2024-04-30`, '#si-table', 'short interest', `${tag}-interest`);
  ok(await page.locator('#si-table tbody tr').count() === 5, 'five settlement dates');
  await visit(page, `/short/volume?security=${sec}&from=2024-01-01&to=2024-04-04`, '#volume-table', 'short volume', `${tag}-volume`);
  ok(await page.locator('#short-caveat.alert').isVisible(), 'the caveat in a callout at the top');
  await visit(page, `/short/fails?security=${sec}&from=2024-01-01&to=2024-04-04`, '#fails-table', 'fails', `${tag}-fails`);
  await visit(page, `/short/threshold?security=${sec}`, '#threshold-runs', 'threshold lists', `${tag}-threshold`);
  await visit(page, `/short/borrow/new?security=${sec}`, '#borrow-form', 'the borrow form', `${tag}-borrow-form`);
  await page.fill('#borrow-form-field-observed_on', '2024-04-04');
  await page.fill('#borrow-form-field-fee_rate_percent', vw.width < 600 ? '35.5' : '41.25');
  await page.fill('#borrow-form-field-utilization_percent', '92');
  await page.fill('#borrow-form-field-note', 'SMOKE: from the browser');
  await Promise.all([page.waitForURL(/\/short\/borrow\?/), page.click('#borrow-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#borrow-table').count() === 1 && (await page.locator('#borrow-table').innerText()).includes(vw.width < 600 ? '35.50%' : '41.25%'), 'saved through the form: the observation on the borrow table');
  ok(await page.locator('#borrow-table .badge.text-bg-light', { hasText: 'indicative' }).count() >= 1, '…shown "indicative"');
  await check(page, 'the borrow table');
  await page.screenshot({ path: `${SHOTS}/${tag}-borrow.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
  const v = await session(29, vw);
  console.log(`${vw.width} x ${vw.height} — a Viewer`);
  await visit(v.page, `/short?security=${sec}&from=2024-01-01&to=2024-04-04`, '#short-chart-svg', 'the short side (a Viewer)', `${tag}-short-viewer`);
  ok(await v.page.locator('#short-borrow-add-btn').count() === 0, 'no Record borrow button for a Viewer');
  await visit(v.page, `/short/borrow?security=${sec}`, '#borrow-table', 'the borrow table (a Viewer)');
  ok(await v.page.locator('[id^=borrow-delete-]').count() === 0, 'no remove button for a Viewer');
  ok(v.errors.length === 0, 'no console errors' + (v.errors.length ? ': ' + v.errors.slice(0, 3).join(' | ') : ''));
  await v.ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
