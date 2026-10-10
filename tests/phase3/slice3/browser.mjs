// Headless Chromium proof of slice 3 (docs/build-specs/holders.md "Proof"), at 375 x 740 and 1280 x 800: an Analyst reads the holders as cards,
// a holder's page, its edit form, the queue (the two sides stacked on a phone; Accept advances it; on a desktop the key `r` rejects), the
// changes, ranges, geography, custodians and register, a NOBO list's upload page; a Viewer's list says "Individual holder" and names no
// person; a manager's merge page compares two holders after choosing one through the picker. No sideways scroll, no control past its card,
// every control ≥ 44 px, no console errors. Run through tests/phase3/slice3/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s3';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-qAt', '-d', process.env.SI_DEV_DB, '-c', q], { encoding: 'utf8' }).trim();
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
ok(seed.waiting >= 2 && seed.person > 0, `seeded: the fixture's list and register matched, ${seed.waiting} rows in the queue`);
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — an Analyst');
  await visit(page, '/holders', '#holder-cards', 'the holder list', 'phone-holders');
  await page.click(`#holder-card-${seed.person}-link`);
  await page.waitForSelector('#holder-accounts');
  ok(page.url().includes(`/holders/${seed.person}`), 'a card opens the holder');
  await check(page, 'a holder');
  await page.screenshot({ path: `${SHOTS}/phone-holder.png`, fullPage: true });
  await visit(page, `/holders/${seed.person}/edit`, '#holder-form', 'the holder\'s edit form');
  await visit(page, '/holders/unmatched', '[id^="queue-row-"]', 'the queue', 'phone-queue');
  const first = await page.locator('[id^="queue-row-"]').first().boundingBox();
  const left = await page.locator('[id^="queue-registration-"]').first().boundingBox();
  const cand = await page.locator('[id^="queue-candidate-"]').first().boundingBox();
  ok(cand.y > left.y + left.height - 1 && first.width <= 375, 'on a phone the registration and the candidates stack');
  const waiting = async () => Number(((await page.locator('#queue-help').innerText()).match(/^([\d,]+) row/) || [0, '-1'])[1].replace(/,/g, ''));
  const before = await waiting();
  const firstRow = await page.locator('[id^="queue-row-"]').first().getAttribute('id');
  await page.locator('[id^="queue-accept-"]').first().click();
  await page.waitForURL(/notice=accepted/);
  await page.waitForLoadState('networkidle');
  ok(await waiting() === before - 1 && await page.locator('#' + firstRow).count() === 0, `Accept: the row joins its holder and the queue advances (${before} → ${await waiting()} waiting)`);
  for (const [p, sel, l, shot] of [['/holders/changes', '#changes-summary', 'the changes', 'phone-changes'], ['/holders/ranges', '#ranges-list', 'the ranges', 'phone-ranges'], ['/holders/geography?record_date=2024-04-04', '#geography-table', 'the geography'],
    ['/holders/custodians?record_date=2024-04-04', '#custodians-table', 'the custodians', 'phone-custodians'], ['/holders/custodians', '#custodians-empty', 'the custodians of a list whose rows wait'], ['/holders/register', '#register-table', 'the register', 'phone-register'], [`/uploads/${seed.nobo}`, '#upload-matcher-counts', 'the NOBO list\'s upload page', 'phone-upload-nobo']]) {
    await visit(page, p, sel, l, shot);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  await visit(page, '/holders', '#holder-cards', 'a Viewer\'s holder list', 'phone-holders-viewer');
  const text = (await page.locator('#page-content').innerText()).toUpperCase();
  const names = sql("SELECT string_agg(upper(display_name), '|') FROM holders WHERE kind = 'person'").split('|').filter((n) => n.length >= 8);
  ok(text.includes('INDIVIDUAL HOLDER') && names.every((n) => !text.includes(n)), `"Individual holder" for every person, and none of the ${names.length} persons named`);
  await visit(page, `/holders/${seed.person}`, '#holder-accounts', 'a Viewer\'s holder page');
  ok((await page.locator('#page-content').innerText()).includes('Individual holder'), 'the page says Individual holder');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — a manager');
  await visit(page, '/holders', '#holder-cards', 'the holder list', 'desktop-holders');
  await page.goto(BASE + `/holders/${seed.person}/merge`, { waitUntil: 'networkidle' });
  await page.click('#merge-into-open');
  await page.waitForSelector('#record-picker.show');
  const name2 = sql(`SELECT display_name FROM holders WHERE id = ${seed.person2}`);
  await page.fill('#record-picker-search', name2);
  await page.waitForSelector(`#record-picker-row-${seed.person2}`);
  await page.click(`#record-picker-row-${seed.person2}`);
  await page.waitForTimeout(300);
  ok(await page.inputValue('#merge-into') === String(seed.person2), 'the picker: searched by name, chosen');
  await page.click('#merge-compare-btn');
  await page.waitForSelector('#merge-side-b');
  ok(await page.locator('#merge-side-a').isVisible() && await page.locator('#merge-side-b').isVisible(), 'the picker chose the other holder; the two columns side by side');
  await check(page, 'the merge page');
  await page.screenshot({ path: `${SHOTS}/desktop-merge.png`, fullPage: true });
  await page.goto(BASE + '/holders/unmatched', { waitUntil: 'networkidle' });
  const n = await page.locator('[id^="queue-row-"]').count();
  await page.keyboard.press('r');
  await page.waitForURL(/notice=rejected/);
  await page.waitForLoadState('networkidle');
  ok(n >= 1 && await page.locator('[id^="queue-row-"]').count() <= n, 'the key r rejects the first candidate (remembered); the queue advances');
  await page.screenshot({ path: `${SHOTS}/desktop-queue.png`, fullPage: true });
  for (const [p, sel, l, shot] of [['/holders/ranges', '#ranges-list', 'the ranges', 'desktop-ranges'], ['/holders/custodians?record_date=2024-04-04', '#custodians-table', 'the custodians', 'desktop-custodians'], [`/holders/${seed.person}`, '#holder-history', 'a holder', 'desktop-holder']]) {
    await visit(page, p, sel, l, shot);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
