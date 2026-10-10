// Headless Chromium proof of slice 2 (docs/build-specs/spr-positions.md "Proof"), at 375 x 740 and 1280 x 800: an Analyst uploads two of the
// owner's real weekly SPRs through the form and lands on each tied; the upload's totals; the position table, the movement matrix (one date
// at a time on a phone), the flow with its caveat, the concentration and the reconciliation — no sideways scroll, no control past its card,
// every control ≥ 44 px, no console errors. Run through tests/phase3/slice2/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s2';
const KEY = process.env.ACTION_TOKEN_KEY;
const REAL = new URL('../../fixtures/real/', import.meta.url).pathname;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-qAt', '-d', process.env.SI_DEV_DB, '-c', q], { encoding: 'utf8' }).trim();
const real = (week) => path.join(REAL, fs.readdirSync(REAL).find((f) => f.includes(`SPR_${week}_09175A107`)));
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

sql("INSERT INTO issuers (name, ticker, cik, exchange) VALUES ('BITMINE IMMERSION TECHNOLOGIES, INC.', 'BMNR', '0001829311', 'nyse_american')");
sql("INSERT INTO securities (issuer_id, name, cusip, ticker, is_primary) SELECT id, 'Common Stock', '09175A107', 'BMNR', true FROM issuers WHERE ticker = 'BMNR'");
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — an Analyst uploads the week');
  await page.goto(BASE + '/uploads/new', { waitUntil: 'networkidle' });
  await check(page, 'the upload form');
  for (const week of ['06-21-2024', '06-28-2024']) {
    await page.goto(BASE + '/uploads/new', { waitUntil: 'networkidle' });
    await page.setInputFiles('#upload-form-field-file', real(week));
    await page.click('#upload-form-save-btn');
    await page.waitForSelector('#upload-totals-table');
    ok(/\/uploads\/\d+\?notice=tied$/.test(page.url()) && (await page.locator('#upload-status').innerText()).includes('tied'), `the week of ${week} uploaded through the form: tied (${page.url()})`);
  }
  ok((await page.locator('#upload-totals-table tbody tr').count()) >= 5, 'its page lists the file\'s total against the rows on each date');
  await check(page, 'an upload');
  await page.screenshot({ path: `${SHOTS}/phone-upload.png`, fullPage: true });
  await page.click('#upload-positions-link');
  await page.waitForSelector('#positions-table');
  await check(page, 'the position table');
  const wide = await page.evaluate(() => { const t = document.getElementById('positions-table'); return t.scrollWidth - t.parentElement.clientWidth; });
  ok(wide <= 1, `the position table fits the phone without scrolling inside its card (${wide} px over)`);
  await page.screenshot({ path: `${SHOTS}/phone-positions.png`, fullPage: true });
  await page.click('#positions-tab-matrix');
  await page.waitForSelector('#matrix-phone-table');
  ok(await page.locator('#matrix-phone').isVisible() && !(await page.locator('#matrix-card').isVisible()), 'on a phone the matrix is one date at a time');
  await page.selectOption('#matrix-focus', '2024-06-18');
  await page.waitForFunction(() => location.search.includes('focus=2024-06-18'));
  await page.waitForSelector('#matrix-phone-table');
  ok(await page.locator('#matrix-focus').inputValue() === '2024-06-18', 'choosing a date shows that date');
  await check(page, 'the matrix');
  await page.click('#positions-tab-flow');
  await page.waitForSelector('#flow-chart');
  ok(await page.locator('#flow-caveat').isVisible() && (await page.locator('#flow-caveat').innerText()).includes('practitioner lore'), 'the flow chart with its caveat under it');
  const chart = await page.locator('#flow-chart').boundingBox();
  ok(chart.x + chart.width <= 375, 'the flow chart fits the phone');
  await check(page, 'the flow');
  await page.screenshot({ path: `${SHOTS}/phone-flow.png`, fullPage: true });
  await page.click('#positions-tab-concentration');
  await page.waitForSelector('#concentration-chart');
  await check(page, 'the concentration');
  await page.click('#positions-tab-reconciliation');
  await page.waitForSelector('#recon-run-form');
  await check(page, 'the reconciliation');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800');
  await page.goto(BASE + '/positions/matrix?from=2024-06-14&to=2024-06-28', { waitUntil: 'networkidle' });
  ok(await page.locator('#matrix-card').isVisible() && !(await page.locator('#matrix-phone').isVisible()) && (await page.locator('#matrix thead th').count()) >= 10, 'on a desktop the full matrix: participants by dates');
  ok((await page.locator('#matrix td.si-absent').count()) >= 0 && await overflow(page) <= 0, 'no sideways scroll (the matrix scrolls inside its card)');
  await page.screenshot({ path: `${SHOTS}/desktop-matrix.png`, fullPage: true });
  await page.goto(BASE + '/positions/flow?from=2024-06-14&to=2024-06-28', { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${SHOTS}/desktop-flow.png`, fullPage: true });
  await page.goto(BASE + '/uploads', { waitUntil: 'networkidle' });
  ok((await page.locator('#upload-table tbody tr').count()) === 2, 'the uploads list: the two weeks');
  await page.screenshot({ path: `${SHOTS}/desktop-uploads.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
