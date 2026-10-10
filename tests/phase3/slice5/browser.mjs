// Headless Chromium proof of slice 5 (docs/build-specs/ownership-forms.md "Proof"), at 375 x 740 and 1280 x 800: the 13F table and the quarter
// chooser, the changes and their state chips, an institution's chart, its edit form (saved through it), the 5% cards, the insider ledger and the
// link form through the picker, the Form 144 table; a Viewer's ledger without the register's name or the link. No sideways scroll, no control past
// its card, every control ≥ 44 px, no console errors. Run through tests/phase3/slice5/run.sh (the data is seeded by seed_browser.php).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots-s5';
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
  await visit(page, '/ownership/13f', '#thirteenf-table', 'the 13F holders', `${tag}-13f`);
  ok(await page.locator('#thirteenf-table tbody tr').count() === 3, 'two holders and the total row');
  await page.selectOption('#period-form-field', '2023-12-31');
  await page.waitForURL(/period=2023-12-31/);
  ok((await page.locator('#thirteenf-total').innerText()).includes('150,000'), 'the quarter chooser: 2023-12-31\'s total, 150,000');
  await visit(page, '/ownership/13f/changes', '#changes-table', 'the changes', `${tag}-changes`);
  await page.click('#changes-state-out');
  await page.waitForURL(/state=out/);
  ok(await page.locator('#changes-table tbody tr').count() === 1, 'the Out chip: one holder');
  await visit(page, `/institutions/${seed.alpha}`, '#holdings-chart', 'an institution', `${tag}-institution`);
  await visit(page, '/institutions', '#institution-cards', 'the institutions', `${tag}-institutions`);
  await visit(page, '/ownership/beneficial', `#beneficial-card-${seed.delta}`, 'the 5% holders', `${tag}-beneficial`);
  await visit(page, '/ownership/insiders', '#insiders-table', 'the insiders', `${tag}-insiders`);
  ok(await page.locator(`#insider-holder-${seed.tx}`).count() === 1, 'the register link shown to an Analyst');
  await visit(page, '/ownership/144', '#form144-table', 'the Form 144', `${tag}-144`);
  if (tag === 'phone') {
    await page.goto(BASE + `/institutions/${seed.alpha}/edit`, { waitUntil: 'networkidle' });
    await check(page, 'the institution form');
    await page.selectOption('#institution-form-field-kind', 'activist');
    await page.check('#institution-form-field-is_activist');
    await page.click('#institution-form-save-btn');
    await page.waitForURL(/\/institutions\/\d+\?notice=updated/);
    ok((await page.locator('#institution-facts').innerText()).includes('set by a person'), 'saved through the form: the kind set by a person');
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  await visit(page, '/ownership/insiders', '#insiders-table', 'a Viewer\'s insiders');
  ok(await page.locator('[id^="insider-holder-"]').count() === 0 && await page.locator('[id^="insider-link-form-"]').count() === 0, 'no register name and no link form for a Viewer');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
