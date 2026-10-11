// Headless Chromium proof of slice 7 (docs/build-specs/signals-rules.md "Proof"), at 375 x 740 and 1280 x 800: the signals as cards with their class
// chips; a signal's page with its basis, evidence, chart, events and outcome; the agent's proposal taken in one click; an acknowledgement through the
// form; a dismissal explained by an event chosen through the record picker; the history; the rules and a manager's override for one issuer; a Viewer
// reading a signal with no form. No sideways scroll, no control past its card, every control ≥ 44 px, no console errors. Run through
// tests/phase3/slice7/run.sh (the data is seeded by seed_browser.php).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s7';
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
for (const [vw, tag] of [[{ width: 375, height: 740 }, 'phone'], [{ width: 1280, height: 800 }, 'desktop']]) {
  const { ctx, page, errors } = await session(26, vw);
  console.log(`${vw.width} x ${vw.height} — an Analyst`);
  await visit(page, '/signals', '#signals-cards', 'the signals', `${tag}-signals`);
  const classes = await page.evaluate(() => [...new Set([...document.querySelectorAll('#signals-cards .signal-class')].map((e) => e.dataset.class))].sort());
  ok(JSON.stringify(classes) === JSON.stringify(['contested', 'data_defect', 'established', 'lore']), 'the cards carry all four class chips: ' + classes.join(', '));
  ok((await page.locator(`#signal-card-${seed.sof}`).getAttribute('title')).startsWith('The basis: '), 'a card\'s tooltip is its basis');
  await visit(page, `/signals/${seed.sof}`, '#signal-evidence-table', 'a signal (short interest over float)', `${tag}-signal`);
  ok(await page.locator('#signal-basis').isVisible() && await page.locator('#signal-class').isVisible() && await page.locator('#signal-chart-svg').isVisible(), 'its basis, its class chip and its chart are shown');
  ok(await page.locator('#signal-outcome').isVisible() && await page.locator('#signal-events').isVisible(), 'and the events around it and what the price did after');
  await visit(page, `/signals/${seed.pm}`, '#signal-chart-svg', 'a lore signal (prime migration)', `${tag}-signal-lore`);
  if (tag === 'phone') {
    await page.goto(BASE + '/signals', { waitUntil: 'networkidle' });
    ok(await page.locator(`#signal-proposal-${seed.th}`).isVisible() && (await page.locator(`#signal-proposal-${seed.th}`).innerText()).includes('proposes: dismiss — explained by an event'), 'the threshold card carries the Analyst agent\'s proposal');
    await page.click(`#signal-take-${seed.th}-${seed.th}`);
    await page.waitForSelector('#notice-banner');
    ok((await page.locator('#notice-banner').innerText()).includes('Dismissed'), 'Take it: one click, and the signal is dismissed in the proposal\'s words');
    await page.goto(BASE + `/signals/${seed.th}`, { waitUntil: 'networkidle' });
    ok((await page.locator('#signal-status').innerText()) === 'Dismissed' && (await page.locator('#signal-disposition-event').innerText()).includes('SMOKE Short Research'), 'its page: dismissed, explained by the short report');
    await page.goto(BASE + `/signals/${seed.bf}`, { waitUntil: 'networkidle' });
    await check(page, 'a signal with the disposition form');
    await page.screenshot({ path: `${SHOTS}/phone-dispose.png`, fullPage: true });
    await page.check('#signal-ack-watching');
    await page.click('#signal-ack-btn');
    await page.waitForSelector('#notice-banner');
    ok((await page.locator('#notice-banner').innerText()).includes('Acknowledged') && (await page.locator('#signal-status').innerText()) === 'Acknowledged', 'acknowledged as watching through the form');
  } else {
    await page.goto(BASE + `/signals/${seed.ftd}`, { waitUntil: 'networkidle' });
    await page.selectOption('#signal-dismiss-reason', 'explained_by_event');
    await page.click('#signal-dismiss-event-open');
    await page.waitForSelector('#record-picker.show');
    await page.waitForSelector(`#record-picker-row-${seed.ev_short}`);
    ok((await page.locator('#record-picker').innerText()).includes('SMOKE Short Research'), 'the picker offers the events around the window');
    await page.click(`#record-picker-row-${seed.ev_short}`);
    await page.waitForSelector('#record-picker', { state: 'hidden' });
    ok((await page.locator('#signal-dismiss-event').inputValue()) === String(seed.ev_short), 'the record picker chose the report');
    await page.click('#signal-dismiss-btn');
    await page.waitForSelector('#notice-banner');
    ok((await page.locator('#notice-banner').innerText()).includes('Dismissed') && (await page.locator('#signal-disposition-event').innerText()).includes('SMOKE Short Research'), 'dismissed, explained by the event chosen');
  }
  await visit(page, '/signals/history?from=2024-04-01&to=2024-04-30', '#history-table', 'the history', `${tag}-history`);
  await visit(page, '/rules', '#rule-table', 'the rules', `${tag}-rules`);
  ok(await page.locator('#rule-row-si_over_float').isVisible() && await page.locator('[id^="rule-edit-"]').count() === 0, 'nineteen rules; an Analyst has no edit button');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
for (const [vw, tag] of [[{ width: 375, height: 740 }, 'phone'], [{ width: 1280, height: 800 }, 'desktop']]) {
  const { ctx, page, errors } = await session(27, vw);
  console.log(`${vw.width} x ${vw.height} — the IR Manager`);
  await visit(page, `/rules/${seed.rule}/edit?issuer=${seed.issuer}`, '#rule-override-form', 'the rule form', `${tag}-rule-edit`);
  if (tag === 'desktop') {
    await page.fill('#rule-override-param-dtc_high', '50');
    await page.click('#rule-override-save-btn');
    await page.waitForSelector('#notice-banner');
    ok((await page.locator('#notice-banner').innerText()).includes('this issuer') && await page.locator('#rule-override-si_over_float').isVisible(), 'the override saved for SMOKE alone and marked in the list');
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  await visit(page, `/signals/${seed.sof}`, '#signal-evidence-table', 'a signal, as a Viewer', 'viewer-signal');
  ok(await page.locator('#signal-dispose').count() === 0 && await page.locator('[id^="signal-take-"]').count() === 0 && await page.locator('#signal-delete-form').count() === 0, 'a Viewer reads the signal and disposes of nothing');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
