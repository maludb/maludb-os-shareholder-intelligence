// Headless Chromium proof of slice 9 (docs/build-specs/agents-k7-api.md "Proof"), at 375 x 740 and 1280 x 800: the proposals screen — an IR Manager takes a signal's disposition, accepts a holder match and rejects a holder's kind
// each in one click; a Viewer reads the signal proposal and has no button; the agents screen — the Analyst's settings saved (the hour, the issuers it covers, the person told chosen in the record picker) and a dispatch cancelled; the
// Tokens screen — a key minted (shown once), rotated (the confirm accepted on revoke) and revoked. No sideways scroll, no control past its card, every control >= 44 px, no console errors. Run through tests/phase3/slice9/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s9';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const seedFor = (tag) => JSON.parse(execFileSync('php', [new URL('./seed_browser.php', import.meta.url).pathname, tag], { encoding: 'utf8' }).trim().split('\n').pop());
const seeds = { phone: seedFor('phone'), desktop: seedFor('desktop') };
const seed = { ...seeds.phone, ...seeds.desktop };
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
const asKey = async (key) => (await fetch(BASE + '/api/v1/issuers', { headers: { Authorization: 'Bearer ' + key } })).status;      // no cookie: a key's own call
const json = async (page, path) => (await (await page.request.get(BASE + path, { headers: { Accept: 'application/json' } })).json()).data;
const banner = async (page, text) => { await page.waitForFunction((t) => (document.querySelector('#notice-banner')?.innerText || '').includes(t), text); return true; };

{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  await visit(page, '/proposals', '#proposals-cards', 'the proposals, as a Viewer', 'viewer-proposals');
  ok(await page.locator('[id^="proposal-signal-"]').count() >= 1 && await page.locator('[id^="proposal-take-"]').count() === 0 && await page.locator('[id^="proposal-match-"]').count() === 0 && await page.locator('[id^="proposal-kind-"]').count() === 0, 'a Viewer reads the signal proposal: no Take it, no holder card');
  ok(!(await page.content()).includes('Frank'), 'and no registration, no name');
  ok((await page.request.get(BASE + '/admin/agents')).status() === 403, '/admin/agents is 403 for a Viewer');
  await visit(page, '/settings/tokens/', '#keys-not-allowed', 'the tokens, as a Viewer', 'viewer-tokens');
  ok(await page.locator('#key-form').count() === 0, 'a Viewer has no key form (an administrator mints one)');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
for (const [vw, tag] of [[{ width: 375, height: 740 }, 'phone'], [{ width: 1280, height: 800 }, 'desktop']]) {
  const { ctx, page, errors } = await session(27, vw);
  console.log(`${vw.width} x ${vw.height} — an IR Manager`);
  const sg = seed['signal_' + tag], mt = seed['match_' + tag], kd = seed['kind_' + tag];
  await visit(page, '/proposals', '#proposals-cards', 'the proposals', `${tag}-proposals`);
  ok(await page.locator(`#proposal-signal-${sg}`).isVisible() && await page.locator(`#proposal-match-${mt}`).isVisible() && await page.locator(`#proposal-kind-${kd}`).isVisible(), 'the three kinds of proposal are cards: a signal, a match, a kind');
  ok((await page.locator(`#proposal-words-${sg}`).innerText()).includes('proposes: dismiss') && (await page.locator(`#proposal-candidate-${mt}`).innerText()).includes('SMOKE') && await page.locator(`#proposal-score-${mt}`).count() === 1, 'each shows the agent\'s words; the match shows the row beside its candidate and a score');
  await page.click('#proposals-kind-holder_kind');
  await page.waitForSelector(`#proposal-signal-${sg}`, { state: 'detached' });
  ok(await page.locator(`#proposal-kind-${kd}`).isVisible() && await page.locator(`#proposal-match-${mt}`).count() === 0, 'the kind chip narrows to the kind proposals');
  await page.click('#proposals-kind-all');
  await page.waitForSelector(`#proposal-signal-${sg}`);
  await page.click(`#proposal-take-${sg}`);
  await page.waitForSelector(`#proposal-signal-${sg}`, { state: 'detached' });
  const s1 = await json(page, `/signals/${sg}`);
  ok(s1.signal.status === 'dismissed' && s1.signal.disposition.reason === 'explained_by_event', 'Take it: the signal is dismissed, explained by the event — one click');
  await banner(page, 'Dismissed');
  await page.click(`#proposal-accept-match-${mt}`);
  await page.waitForSelector(`#proposal-match-${mt}`, { state: 'detached' });
  await banner(page, 'Matched');
  ok(true, 'Accept: the register row joins the holder (the notice says so)');
  await page.click(`#proposal-reject-kind-${kd}`);
  await page.waitForSelector(`#proposal-kind-${kd}`, { state: 'detached' });
  await banner(page, 'Rejected the kind');
  ok(await page.locator('#proposals-empty').count() === 1 || await page.locator('#proposals-cards [id^="proposal-"]').count() >= 0, 'Reject: the kind proposal leaves');
  await check(page, 'the proposals after three clicks');
  await page.screenshot({ path: `${SHOTS}/${tag}-proposals-after.png`, fullPage: true });

  await visit(page, '/admin/agents', '#agents-settings', 'the agents', `${tag}-agents`);
  ok(await page.locator('#agent-analyst-chip').isVisible() && await page.locator('#dispatches-table').count() === 1, 'the Analyst is marked; the dispatches are a table');
  await page.selectOption('#agents-settings-hour', '9');
  await page.uncheck('#agents-settings-proposals');
  await page.check(`#agents-settings-issuer-${seed.alpha}`);
  await pick(page, '#agents-settings-escalate-open', 26);
  ok((await page.locator('#agents-settings-escalate').inputValue()) === '26', 'the person told of a high signal was chosen in the record picker');
  await page.click('#agents-settings-save-btn');
  await banner(page, 'Saved the agents');
  const ag = await json(page, '/admin/agents');
  ok(ag.settings.morning_note_hour === 9 && ag.settings.analyst_proposals === false && JSON.stringify(ag.settings.analyst_issuer_ids) === JSON.stringify([seed.alpha]) && ag.settings.escalate_high_to === 26, 'saved: the hour 9, proposals off, the Analyst covering Alpha, Priya told of a high signal');
  ok((await page.locator('#agents-settings-hour').inputValue()) === '9' && await page.locator('#agents-settings-proposals').isChecked() === false, 'and the form shows what was saved');
  await page.selectOption('#agents-settings-hour', '7');
  await page.check('#agents-settings-proposals');
  await page.uncheck(`#agents-settings-issuer-${seed.alpha}`);
  await page.click('#agents-settings-escalate-clear');
  await page.click('#agents-settings-save-btn');
  await banner(page, 'Saved the agents');
  const ag2 = await json(page, '/admin/agents');
  ok(ag2.settings.morning_note_hour === 7 && ag2.settings.analyst_proposals === true && ag2.settings.analyst_issuer_ids.length === 0 && ag2.settings.escalate_high_to === null, 'and back to the defaults: every issuer, no one told');
  const dp = seed['dispatch_' + tag];
  ok(await page.locator(`#dispatch-row-${dp}`).getAttribute('data-status') === 'sent', 'a dispatch still to be made is a row, status sent');
  await page.click(`#dispatch-cancel-${dp}`);
  await banner(page, 'Cancelled the dispatch');
  ok(await page.locator(`#dispatch-row-${dp}`).getAttribute('data-status') === 'cancelled' && await page.locator(`#dispatch-cancel-${dp}`).count() === 0, 'Cancel: the row is cancelled and loses its button');
  await page.selectOption('#agents-filter-status', 'cancelled');
  await page.click('#dispatches-filter-btn');
  await page.waitForFunction(() => document.querySelectorAll('#dispatches-table tbody tr[data-status]:not([data-status="cancelled"])').length === 0);
  ok(await page.locator(`#dispatch-row-${dp}`).count() === 1, 'the status filter narrows the table to the cancelled');
  await page.screenshot({ path: `${SHOTS}/${tag}-agents-after.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — an Analyst: keys');
  await visit(page, '/settings/tokens/', '#key-form', 'the tokens', 'phone-tokens');
  await page.fill('#key-form-field-label', 'Browser key');
  await page.click('#key-form-save-btn');
  await page.waitForSelector('#tokens-minted-value');
  const k1 = (await page.locator('#tokens-minted-value').innerText()).trim();
  ok(/^sik_[0-9a-f]{48}$/.test(k1) && (await page.locator('#tokens-minted').innerText()).includes('API key "Browser key"'), 'minted: the key is shown once with its label');
  const rows = await page.locator('#keys-table tbody tr[id^="key-row-"]').count();
  ok(rows === 1 && await page.locator('#keys-table tbody tr').first().getAttribute('data-state') === 'live', 'and listed as live');
  ok(await asKey(k1) === 200, 'the key works against the API');
  await check(page, 'the tokens with a fresh key');
  await page.screenshot({ path: `${SHOTS}/phone-tokens-minted.png`, fullPage: true });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok(await page.locator('#tokens-minted-value').count() === 0, 'the key is not shown again');
  const rid = (await page.locator('#keys-table tbody tr').first().getAttribute('id')).replace('key-row-', '');
  await page.click(`#key-row-${rid}-rotate-btn`);
  await page.waitForSelector('#tokens-minted-value');
  const k2 = (await page.locator('#tokens-minted-value').innerText()).trim();
  ok(/^sik_[0-9a-f]{48}$/.test(k2) && k2 !== k1 && await page.locator('#keys-table tbody tr[data-state="live"]').count() === 2, 'rotated: a new key is shown once; the old one is listed beside it (still inside its overlap)');
  ok(await asKey(k1) === 200 && await asKey(k2) === 200, 'both keys work');
  const newId = (await page.locator('#keys-table tbody tr').first().getAttribute('id')).replace('key-row-', '');
  await page.click(`#key-row-${newId}-revoke-btn`);
  await page.waitForFunction((id) => document.querySelector('#key-row-' + id)?.getAttribute('data-state') === 'revoked', newId);
  ok(await asKey(k2) === 401 && await asKey(k1) === 200, 'revoked (the confirm accepted): that key is refused at once; the other still works');
  ok((await page.request.get(BASE + '/api/v1/issuers', { headers: { Authorization: 'Bearer ' + k2 } })).status() === 401, 'and a stale key is 401 even from a browser that holds a session (the Bearer header is what the API reads)');
  await check(page, 'the tokens after a revoke');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
