// Headless Chromium proof of slice 1 (docs/build-specs/issuers-participants.md "Proof"), at 375 x 740 and 1280 x 800: an IR Manager adds an
// issuer by looking up its ticker at the SEC (the stub), lands on its page with the EDGAR entity, adds the common stock, records a count, restricts
// the issuer to Dana through the record picker (the confirm accepted), reads the participants as cards and one participant's page, edits its
// class; no sideways scroll, every control ≥ 44 px, no console errors; the desktop's cards side by side. Run through tests/phase3/slice1/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s1';
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

sql("UPDATE si_settings SET business_name = 'Kinetic Seas', edgar_contact_email = 'claude@kineticseas.com' WHERE id = 1");
// ---- phone: the IR Manager sets up an issuer ----
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  console.log('375 x 740 — an IR Manager sets up an issuer');
  await page.goto(BASE + '/issuers', { waitUntil: 'networkidle' });
  await check(page, 'the issuers list');
  await page.click('#issuer-add-btn');
  await page.waitForSelector('#issuer-lookup-field-ticker');
  await page.fill('#issuer-lookup-field-ticker', 'smok');
  await page.click('#issuer-lookup-btn');
  await page.waitForSelector('#issuer-lookup-found');
  ok((await page.inputValue('#issuer-form-field-name')) === 'SMOKE ISSUER INC' && (await page.inputValue('#issuer-form-field-cik')) === '999999' && (await page.inputValue('#issuer-form-field-exchange')) === 'nasdaq', 'Look up fills the name, the CIK and the exchange from the SEC');
  await check(page, 'the issuer form');
  await page.screenshot({ path: `${SHOTS}/phone-issuer-add.png`, fullPage: true });
  await page.selectOption('#issuer-form-field-ta-connector', 'stc');
  await page.fill('#issuer-form-field-ta', 'Securities Transfer Corporation');
  await page.click('#issuer-form-save-btn');
  await page.waitForSelector('#issuer-entity dl');
  ok(/\/issuers\/\d+\?notice=created$/.test(page.url()), 'saving lands on the issuer with the notice (' + page.url() + ')');
  ok((await page.locator('#issuer-entity').innerText()).includes('SMOKE ACQUISITION CORP'), 'its EDGAR card shows the former name');
  await check(page, 'the issuer page');
  await page.screenshot({ path: `${SHOTS}/phone-issuer-view.png`, fullPage: true });
  const id = page.url().match(/\/issuers\/(\d+)/)[1];
  await page.click('#issuer-tab-securities');
  await page.waitForSelector('#security-form');
  await page.fill('#security-form-field-cusip', '99900S101');
  await page.click('#security-form-save-btn');
  await page.waitForSelector('#securities-table');
  ok((await page.locator('#securities-table').innerText()).includes('99900S101') && (await page.locator('#securities-table').innerText()).includes('primary'), 'the first security is added as the primary, its CUSIP in the table');
  await check(page, 'the securities page');
  await page.click('#issuer-tab-share-counts');
  await page.waitForSelector('#share-count-form');
  await page.fill('#share-count-form-field-value', '17,053,900');
  await page.click('#share-count-form-save-btn');
  await page.waitForSelector('#share-counts-table');
  ok((await page.locator('#share-counts-table').innerText()).includes('17,053,900'), 'a count recorded and listed');
  await page.screenshot({ path: `${SHOTS}/phone-share-counts.png`, fullPage: true });
  await check(page, 'the share counts page');
  await page.click('#issuer-tab-access');
  await page.waitForSelector('#access-form');
  await page.click('#access-form-field-member-open');
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', 'Dana');
  await page.waitForSelector('#record-picker-row-30');
  await page.click('#record-picker-row-30');
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await page.locator('#access-form-field-member').inputValue()) === '30', 'the record picker chooses Dana');
  page.once('dialog', (d) => d.accept());
  await page.click('#access-form-save-btn');
  await page.waitForSelector('#access-member-30');
  ok((await page.locator('#access-card').innerText()).includes('Restricted to 1 named member'), 'restricting (the confirm accepted) lists Dana');
  await check(page, 'the access page');
  await page.screenshot({ path: `${SHOTS}/phone-issuer-access.png`, fullPage: true });
  ok(sql(`SELECT count(*) FROM issuer_members WHERE issuer_id = ${id}`) === '1', 'the restriction is in the database');
  sql(`DELETE FROM issuer_members WHERE issuer_id = ${id}`);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: the participants ----
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  console.log('375 x 740 — the participants');
  const sec = sql('SELECT id FROM securities ORDER BY id LIMIT 1');
  const imp = sql(`INSERT INTO imports (kind, connector, file_name, issuer_id, security_id, record_date, status, tied) SELECT 'spr', 'dtc_spr_csv', 'SMOKE.csv', issuer_id, id, CURRENT_DATE - 1, 'done', true FROM securities WHERE id = ${sec} RETURNING id`).split('\n')[0];
  sql(`INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) SELECT ${sec}, p.id, CURRENT_DATE - d, 5000000 + d * 100000, ${imp} FROM participants p, generate_series(1, 20) d WHERE p.number = '0164' AND extract(isodow FROM CURRENT_DATE - d) < 6`);
  await page.goto(BASE + '/participants', { waitUntil: 'networkidle' });
  await check(page, 'the participants list');
  ok((await page.locator('#participant-cards > div').count()) >= 30, 'the participants as cards');
  await page.click('#participant-class-lending');
  await page.waitForSelector('#participant-class-lending.btn-primary');
  const shown = await page.locator('#participant-cards > div').count();
  ok(shown >= 1 && shown < 30, `a class filter narrows the cards (${shown} lending)`);
  const pid = sql("SELECT id FROM participants WHERE number = '0164'");
  await page.goto(BASE + `/participants/${pid}?security=${sec}`, { waitUntil: 'networkidle' });
  const chart = await page.locator('#participant-series-chart').boundingBox();
  ok(chart !== null && chart.x + chart.width <= 375 && chart.width > 250, 'one participant: its series chart fits the phone');
  await check(page, 'a participant');
  await page.screenshot({ path: `${SHOTS}/phone-participant.png`, fullPage: true });
  await page.click('#participant-edit-btn');
  await page.waitForSelector('#participant-form');
  await page.check('#participant-form-class-clearing_retail');
  await page.click('#participant-form-save-btn');
  await page.waitForSelector('#participant-facts');
  ok((await page.locator('#participant-facts').innerText()).includes('set by a person'), 'editing the class: the page says it was set by a person');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800');
  sql("INSERT INTO issuers (name, ticker) VALUES ('SMOKE SECOND CORP', 'SMSC'), ('SMOKE THIRD CORP', 'SMTC')");
  await page.goto(BASE + '/issuers', { waitUntil: 'networkidle' });
  const cards = await page.locator('#issuer-cards > div').count();
  const first = await page.locator('#issuer-cards > div .card').first().boundingBox();
  ok(cards >= 3 && first.width < 450, `the issuer cards sit side by side (${cards}, ${Math.round(first.width)} px each)`);
  ok(await overflow(page) <= 0, 'no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/desktop-issuers.png`, fullPage: true });
  await page.locator('#issuer-cards a').first().click();
  await page.waitForSelector('#issuer-tabs');
  ok(await page.evaluate(() => document.querySelector('#nav-issuers .nxl-link').classList.contains('active')), 'an issuer\'s page highlights Issuers in the sidebar');
  await page.screenshot({ path: `${SHOTS}/desktop-issuer.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
