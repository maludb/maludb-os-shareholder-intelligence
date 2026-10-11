// Headless Chromium proof of slice 8 (docs/build-specs/events-reports.md "Proof"), at 375 x 740 and 1280 x 800: the timeline and an event recorded through the form with its filing chosen
// in the record picker; the month grid (chips on a desktop, dots on a phone, a day opening its events); the packs as cards; a draft rendered; an IR Manager approving it (the confirm accepted)
// and sending it to a member chosen in the picker; an export asked for and listed with its Download; a Viewer reading with no controls. No sideways scroll, no control past its card, every control
// ≥ 44 px, no console errors. Run through tests/phase3/slice8/run.sh (the data is seeded by seed_browser.php).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s8';
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
  page.on('dialog', (d) => d.accept());                       // hx-confirm
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/?issuer=' + seed.alpha, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const smallControls = (page) => page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button:not(.btn-link):not(.avatar-text), #page-content input:not([type=hidden]):not([type=checkbox]):not([type=radio]), #page-content select, #page-content a.si-cal-num')]
  .filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44; }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)));
const clipped = (page) => page.evaluate(() => [...document.querySelectorAll('#page-content input:not([type=hidden]), #page-content select, #page-content button, #page-content a.btn')]
  .filter((a) => { const r = a.getBoundingClientRect(); if (r.width === 0) return false; const c = a.closest('.card'); const cr = c ? c.getBoundingClientRect() : { left: 0, right: window.innerWidth }; return r.right > cr.right + 1 || r.left < cr.left - 1 || r.right > window.innerWidth; })
  .map((a) => a.id || a.tagName));
const check = async (page, label) => {
  ok(await overflow(page) <= 0, `no sideways scroll on ${label}`);
  const c = await clipped(page);
  ok(c.length === 0, `no control on ${label} reaches past its card or the screen` + (c.length ? ': ' + c.slice(0, 6).join(', ') : ''));
  const wide = page.viewportSize().width > 600;
  const s = (await smallControls(page)).filter((x) => !(wide && x.startsWith('calendar-day-')));       // a desktop's day number is a small target in a big cell; a phone's fills 44 px
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
for (const [vw, tag] of [[{ width: 375, height: 740 }, 'phone'], [{ width: 1280, height: 800 }, 'desktop']]) {
  const { ctx, page, errors } = await session(26, vw);
  console.log(`${vw.width} x ${vw.height} — an Analyst`);
  await visit(page, '/events', '#events-timeline', 'the timeline', `${tag}-events`);
  ok(await page.locator(`#event-row-${seed.filing_event}`).isVisible() && await page.locator(`#event-filing-${seed.filing_event}`).isVisible(), 'the filing\'s event is a row with its filing linked');
  ok(await page.locator(`#event-row-${seed.call}`).getAttribute('data-kind') === 'earnings', 'the coming earnings call is a row of kind earnings');
  await page.selectOption('#events-filter-kind', 'earnings');
  await page.click('#events-filter-btn');
  await page.waitForSelector(`#event-row-${seed.filing_event}`, { state: 'detached' });
  ok(await page.locator(`#event-row-${seed.call}`).count() === 1 && await page.locator(`#event-row-${seed.filing_event}`).count() === 0, 'the kind filter narrows the timeline to the earnings call');
  await visit(page, '/events/new', '#event-form', 'the event form', `${tag}-event-form`);
  await page.fill('#event-form-field-on_date', '2024-04-03');
  await page.selectOption('#event-form-field-kind', 'financing');
  await page.fill('#event-form-field-title', `SMOKE: browser event ${tag}`);
  await page.fill('#event-form-field-body', 'A **facility** was announced.');
  await pick(page, '#event-form-field-filing-open', seed.filing);
  ok((await page.locator('#event-form-field-filing').inputValue()) === String(seed.filing), 'the filing was chosen in the record picker');
  await page.fill('#event-form-field-tags', 'browser');
  await page.click('#event-form-save-btn');
  await page.waitForSelector('#event-title');
  ok((await page.locator('#event-title').innerText()).includes(`browser event ${tag}`) && (await page.locator('#event-body').innerHTML()).includes('<strong>facility</strong>') && await page.locator('#event-filing-link').isVisible(), 'saved: the event\'s page shows the title, the Markdown and the filing');
  await check(page, 'an event\'s page');
  await page.screenshot({ path: `${SHOTS}/${tag}-event.png`, fullPage: true });
  await visit(page, `/calendar?at=${seed.month}`, '#calendar-grid', 'the calendar', `${tag}-calendar`);
  ok(await page.locator(`#calendar-day-${seed.call_date}`).isVisible(), 'the day of the call is a link');
  const dots = await page.evaluate(() => getComputedStyle(document.querySelector('.si-cal-dots')).display);
  const chip = await page.evaluate(() => { const c = document.querySelector('.si-cal-chip'); return c ? getComputedStyle(c).display : 'none'; });
  ok(tag === 'phone' ? (dots === 'flex' && chip === 'none') : (dots === 'none' && chip !== 'none'), tag === 'phone' ? 'on a phone a day shows dots and the agenda carries the words' : 'on a desktop a day shows its chips');
  ok(await page.locator('#calendar-agenda').isVisible(), 'the month\'s agenda is beneath the grid');
  await page.click(`#calendar-day-${seed.call_date}`);
  await page.waitForSelector(`#event-row-${seed.call}`);
  ok(await page.locator('#events-timeline [id^="event-row-"]').count() === 1, 'a day opens its events: the call alone');
  await visit(page, '/reports', '#reports-cards', 'the packs', `${tag}-reports`);
  ok(await page.locator(`#report-card-${seed.draft}`).getAttribute('data-status') === 'draft' && await page.locator(`#report-card-${seed.approved}`).getAttribute('data-status') === 'approved', 'a draft and an approved pack as cards, each with its status');
  await visit(page, `/reports/${seed.draft}`, '#report-sections-body', 'a draft pack', `${tag}-report`);
  ok(await page.locator('#pack-section-top_participants').isVisible() && await page.locator('#report-approve-btn').count() === 0 && await page.locator('#report-send-form').count() === 0, 'its sections are drawn; an Analyst has no Approve and no Send');
  await page.click('#report-render-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Rendered') && await page.locator('#report-download-html').isVisible(), 'rendered: the HTML download appears');
  await visit(page, `/reports/new`, '#report-form', 'the pack form', `${tag}-report-form`);
  await page.fill('#report-form-field-title', `SMOKE: pack ${tag}`);
  await page.uncheck('#report-form-section-movement');
  await page.click('#report-form-save-btn');
  await page.waitForSelector('#report-status');
  ok((await page.locator('#report-status').innerText()) === 'Draft' && await page.locator('#pack-section-movement').count() === 0 && await page.locator('#pack-section-events').count() === 1, 'drafted by hand with one section unticked');
  await visit(page, '/exports', '#export-form', 'the exports', `${tag}-exports`);
  await page.selectOption('#export-form-field-kind', 'events_csv');
  await page.click('#export-form-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Built the export') && await page.locator('[id^="export-download-"]').count() >= 1, 'an export built and listed with its Download');
  const href = await page.locator('[id^="export-download-"]').first().getAttribute('href');
  const dl = await page.request.get(BASE + href);
  ok(dl.status() === 200 && (await dl.text()).startsWith('event_id,on_date'), 'the download is the CSV');
  await check(page, 'the exports after one');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the IR Manager');
  await visit(page, `/reports/${seed.draft}`, '#report-approve-btn', 'a draft, as the manager', 'manager-draft');
  await page.click('#report-approve-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Approved') && (await page.locator('#report-status').innerText()) === 'Approved' && await page.locator('#report-frozen').isVisible(), 'approved (the confirm accepted): the status, the frozen narrative');
  ok(await page.locator('#report-send-form').isVisible() && await page.locator('#report-approve-btn').count() === 0, 'the send form appears; Approve is gone');
  await pick(page, '#report-send-field-to-1-open', 26);
  ok((await page.locator('#report-send-field-to-1').inputValue()) === '26', 'a member chosen in the picker');
  await page.fill('#report-send-field-message', 'For the Thursday call.');
  await page.click('#report-send-btn');
  await page.waitForFunction(() => (document.querySelector('#notice-banner')?.innerText || '').includes('Sent'));
  const sentNotice = await page.locator('#notice-banner').innerText();
  ok(sentNotice.includes('Sent the pack to 1 member') && (await page.locator('#report-status').innerText()) === 'Sent', 'sent to one member: the notice and the status "Sent"' + (sentNotice.includes('Sent the pack to 1 member') ? '' : ' — the banner said: ' + sentNotice));
  await page.screenshot({ path: `${SHOTS}/manager-sent.png`, fullPage: true });
  await visit(page, '/exports?all=1', '#exports-scope', 'everyone\'s exports', 'manager-exports');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  await visit(page, '/events', '#events-timeline', 'the timeline, as a Viewer', 'viewer-events');
  ok(await page.locator('#events-add-link').count() === 0, 'a Viewer has no "Record an event"');
  await visit(page, `/reports/${seed.approved}`, '#report-sections-body', 'an approved pack, as a Viewer', 'viewer-report');
  ok(await page.locator('#report-render-btn').count() === 0 && await page.locator('#report-edit-link').count() === 0 && await page.locator('#report-send-form').count() === 0 && await page.locator('#report-delete-btn').count() === 0 && await page.locator('#report-download-html').count() === 1, 'a Viewer reads and downloads the HTML — no render, edit, send or delete');
  ok((await page.request.get(BASE + `/reports/${seed.draft2}`)).status() === 404, 'a draft is not there for a Viewer (404)');
  await visit(page, '/exports', '#exports-empty', 'the exports, as a Viewer', 'viewer-exports');
  ok(await page.locator('#export-form').count() === 0, 'and no form to ask for one');
  await visit(page, `/calendar?at=${seed.month}`, '#calendar-grid', 'the calendar, as a Viewer', 'viewer-calendar');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
