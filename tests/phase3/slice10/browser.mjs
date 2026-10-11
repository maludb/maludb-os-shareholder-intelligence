// Headless Chromium proof of slice 10 (docs/build-specs/admin-settings.md "Proof") at 375 x 740 and 1280 x 800: the sources table and a source's view, the schedule, pause and resume, the credential form
// (the secret never shown back), the settings (the banner gone once the contact is saved), a participant reclassed through the record picker, the templates, everyone's keys (revoke) and the purge.
// No sideways scroll, no control past its card, every control >= 44 px, no console errors. Run through tests/phase3/slice10/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s10';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());                       // hx-confirm
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/?issuer=' + seed.alpha, { waitUntil: 'networkidle' });
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
const pick = async (page, openSel, rowId) => {
  await page.click(openSel);
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector(`#record-picker-row-${rowId}`);
  await page.click(`#record-picker-row-${rowId}`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
};
const seedFor = (tag) => JSON.parse(execFileSync('php', [new URL('./seed_browser.php', import.meta.url).pathname, tag], { encoding: 'utf8' }).trim().split('\n').pop());
const seed = { ...seedFor('phone'), ...seedFor('desktop') };
const sourceId = (c) => Number(execFileSync('php', ['-r', `require '${new URL('./lib.php', import.meta.url).pathname}'; echo one("SELECT id FROM sources WHERE connector = :c ORDER BY id LIMIT 1", ['c' => '${c}']);`], { encoding: 'utf8' }).trim().split('\n').pop());
const dtcc = sourceId('dtcc_participants');
const av = sourceId('prices_alphavantage');

async function run(vw, tag) {
  const { ctx, page, errors } = await session(1, vw);
  console.log(`${vw.width} x ${vw.height} — the admin`);
  await visit(page, '/admin/sources', '#sources-table', 'the sources', `sources-${tag}`);
  if (tag === 'phone') ok(await page.locator('#ua-banner').count() === 1, 'the red banner while the contact is empty');
  await visit(page, '/admin/sources/' + dtcc, '#source-pulls-card', 'a source', `source-${tag}`);
  await page.fill('#source-schedule-minutes', '120');
  await page.click('#source-schedule-save-btn');
  await page.waitForSelector('#notice-banner, #flash .alert', { timeout: 8000 }).catch(() => {});
  await page.goto(BASE + '/admin/sources/' + dtcc, { waitUntil: 'networkidle' });
  ok((await page.locator('#source-schedule-words').innerText()).includes('2 hours'), 'the schedule saved (every 2 hours)');
  await page.fill('#source-pause-reason', 'SMOKE browser pause');
  await page.click('#source-pause-btn');
  await page.waitForSelector('#source-resume-btn', { timeout: 8000 }).catch(() => {});
  await page.goto(BASE + '/admin/sources/' + dtcc, { waitUntil: 'networkidle' });
  ok(await page.locator('#source-resume-btn').count() === 1, 'paused: Resume is offered');
  await page.click('#source-resume-btn');
  await page.goto(BASE + '/admin/sources/' + dtcc, { waitUntil: 'networkidle' });
  ok(await page.locator('#source-pause-btn').count() === 1, 'resumed');
  await visit(page, '/admin/sources/' + dtcc + '/edit', '#source-form', 'the source form', `source-edit-${tag}`);
  await visit(page, '/admin/sources/' + av + '/credential', '#credential-form', 'the credential form', `credential-${tag}`);
  await page.fill('#credential-form-secret', 'SMOKE-BROWSER-SECRET-9876');
  await page.click('#credential-form-save-btn');
  await page.goto(BASE + '/admin/sources/' + av + '/credential', { waitUntil: 'networkidle' });
  const html = await page.content();
  ok(html.includes('9876') && !html.includes('SMOKE-BROWSER-SECRET'), 'the credential: only its last four come back');
  await visit(page, '/admin/settings', '#settings-form', 'the settings', `settings-${tag}`);
  await page.fill('#settings-form-edgar-contact-email', 'ir@example.invalid');
  await page.click('#settings-form-save-btn');
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  ok(await page.locator('#ua-banner').count() === 0, 'the contact saved: the banner is gone');
  await visit(page, '/admin/participant-classes?class=other', '#class-form', 'the participant classes', `classes-${tag}`);
  await page.click('#class-form-participant-open');
  await page.waitForSelector('#record-picker.show');
  await page.locator('#record-picker [id^="record-picker-row-"]').first().click();
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.selectOption('#class-form-class', 'prime');
  await page.click('#class-form-save-btn');
  await page.waitForTimeout(800);
  ok(await page.evaluate(() => document.querySelector('#class-form-participant') !== null), 'a participant reclassed through the record picker');
  await visit(page, '/admin/templates', '#templates', 'the templates', `templates-${tag}`);
  await visit(page, '/admin/tokens', '#tokens-table', 'the keys and tokens', `tokens-${tag}`);
  const kid = seed['key_' + tag];
  await page.click(`#token-row-${kid}-revoke-btn`);
  await page.waitForFunction((id) => !document.querySelector(`#token-row-${id}-revoke-btn`), kid, { timeout: 8000 }).catch(() => {});
  await page.goto(BASE + '/admin/tokens', { waitUntil: 'networkidle' });
  ok(await page.locator(`#token-row-${kid}-revoke-btn`).count() === 0 && await page.locator(`#token-row-${kid}[data-state="revoked"]`).count() === 1, 'the key revoked from the list (confirm accepted)');
  await visit(page, '/admin/purge', '#purge-table', 'the purge', `purge-${tag}`);
  await page.click(`#purge-row-event-${seed['due_' + tag]}-purge-btn`);
  await page.waitForFunction((id) => !document.querySelector(`#purge-row-event-${id}`), seed['due_' + tag], { timeout: 8000 }).catch(() => {});
  await page.goto(BASE + '/admin/purge', { waitUntil: 'networkidle' });
  ok(await page.locator(`#purge-row-event-${seed['due_' + tag]}`).count() === 0 && await page.locator(`#purge-row-event-${seed['young_' + tag]}`).count() === 1, 'Purge now removed the due record and left the one inside its window');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await run({ width: 375, height: 740 }, 'phone');
await run({ width: 1280, height: 800 }, 'desktop');
{
  const { ctx, page } = await session(27, { width: 375, height: 740 });
  ok((await page.request.get(BASE + '/admin/sources')).status() === 403 && (await page.request.get(BASE + '/admin/purge')).status() === 403, 'an IR Manager is refused the sources and the purge');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
