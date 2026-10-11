// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md "Proof"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar (Home · Signals · Positions · Holders · Me) on a phone and the
//   sidebar on a desktop, the offcanvas from the menu button, a new installation's one button (Add your first issuer) for a manager and the
//   sentence for a Viewer, then the issuers seeded: the choice of issuer, the tiles with their source lines, the price and events chart inside
//   the screen, the issuer switcher (only what the wall admits), the search box, the placeholders reached by HTMX (title, highlight, context
//   stamp), the bell, the command bar answering the fake kernel and following a navigate, My settings saving preferences, a token minted and
//   shown once then revoked, every control ≥ 44 px, no console errors, the home whole with JavaScript off, the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's
// web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8901';
const SHOTS = process.env.SHOTS || '/tmp/si-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.shareholder_intelligence.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get dana() { return mint(30); }, get analyst() { return mint(26); }, get manager() { return mint(27); }, get viewer() { return mint(29); }, get admin() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const groupsOf = async (page) => (await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a new installation, an IR Manager (Marco) — the one button ----
{
  const { ctx, page, errors } = await session(urls.manager, { width: 375, height: 740 });
  console.log('375 x 740 — a new installation, an IR Manager');
  ok(page.url() === BASE + '/', 'signed on and landed on the home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar') && !(await shown(page, '#left-sidenav')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').allInnerTexts();
  ok(tabs.map((t) => t.trim()).join(',') === 'Home,Signals,Positions,Holders,Me', `five tabs: ${tabs.map((t) => t.trim()).join(', ')}`);
  const small = await smallControls(page, '#app-tabbar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-first-run') && await shown(page, '#home-first-run-btn') && /add your first issuer/i.test(await page.locator('#home-first-run-btn').innerText()), 'a manager on a new installation sees the one button, Add your first issuer');
  const btn = await page.locator('#home-first-run-btn').boundingBox();
  ok(btn.height >= 44 && btn.x >= 0 && btn.x + btn.width <= 375, `the button is ${Math.round(btn.height)} px tall and inside the screen (right edge ${Math.round(btn.x + btn.width)})`);
  ok((await page.locator('#issuer-switcher').count()) === 0, 'no issuer yet: no switcher in the header');
  ok((await page.locator('#header-role-badge').count()) === 1 && (await page.locator('#header-role-badge').textContent()).includes('IR Manager'), 'the header badge reads IR Manager');
  await page.screenshot({ path: `${SHOTS}/phone-home-first-run.png` });
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#left-sidenav') && (await page.locator('#nav-signals').count()) === 1 && (await page.locator('#nav-proposals').count()) === 1 && (await page.locator('#nav-agents').count()) === 1 && (await page.locator('#nav-admin').count()) === 0, 'the menu button opens the sidebar: the nine, Data with Proposals, Agents, no Sources and settings');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'data,me,admin', 'a manager\'s captions: ' + groups.join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#left-sidenav')), 'the menu button closes it again');
  await page.click('#tab-my-settings');
  await page.waitForURL(BASE + '/settings/');
  await page.waitForSelector('#prefs-save-btn');
  ok(page.url() === BASE + '/settings/', 'the Me tab pushed /settings/ without a page load');
  ok((await page.title()).startsWith('My settings'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-my-settings').classList.contains('active') && !document.querySelector('#tab-home').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'settings'), 'and re-stamped #page-content data-screen for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px tall' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png`, fullPage: true });
  await page.uncheck('#prefs-field-morning');
  await page.check('#prefs-field-sev-high');
  await page.selectOption('#prefs-field-hour', '6');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-morning')) && await page.isChecked('#prefs-field-sev-high') && (await page.inputValue('#prefs-field-hour')) === '6', 'and the morning note stayed off, high only, at 06:00');
  await page.click('#tab-signals');
  await page.waitForURL(BASE + '/signals');
  await page.waitForSelector('#signal-list-content');
  ok((await page.title()).startsWith('Signals'), 'the Signals tab opens the signals (slice 7), title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#tab-signals').classList.contains('active') && document.getElementById('page-content').dataset.screen === 'signal-list'), 'the tab is highlighted and the screen stamped signal-list');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the signals');
  await page.screenshot({ path: `${SHOTS}/phone-placeholder-signals.png` });
  for (const u of ['/activity', '/notifications', '/search', '/settings/tokens/']) {
    await page.goto(BASE + u, { waitUntil: 'networkidle' });
    ok((await overflow(page)).sw === 375, `no sideways scroll on ${u}`);
  }
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'Who bought the most last week?');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a Viewer (Ann) on a new installation ----
{
  const { ctx, page, errors } = await session(urls.viewer, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer, no issuer shared yet');
  ok((await page.locator('#header-role-badge').textContent()).includes('Viewer'), 'the badge reads Viewer');
  ok(await shown(page, '#home-first-run-note') && (await page.locator('#home-first-run-btn').count()) === 0, 'a Viewer is told no issuer is shared yet and shown no button');
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  ok((await page.locator('#nav-proposals').count()) === 0 && (await page.locator('#nav-agents').count()) === 0 && (await page.locator('#nav-admin').count()) === 0 && (await page.locator('#nav-holders').count()) === 1, 'a Viewer\'s sidebar: Holders (in aggregate), no Proposals, no Admin');
  await page.screenshot({ path: `${SHOTS}/phone-home-viewer.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- the SMOKE issuers exist from here ----
const seed = JSON.parse(execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { encoding: 'utf8' }).trim());
console.log(`seeded: Alpha ${seed.alpha}, Beta ${seed.beta} (restricted to Dana), Gamma ${seed.gamma}`);

// ---- phone: an Analyst (Priya) chooses an issuer and reads the home ----
{
  const { ctx, page, errors } = await session(urls.analyst, { width: 375, height: 740 });
  console.log('375 x 740 — an Analyst, two issuers');
  ok(await shown(page, '#home-choose') && (await page.locator('#home-choose > div').count()) === 2, 'two issuers visible and none chosen: one card each (Alpha, Gamma — never Beta)');
  await page.click(`#home-choose-${seed.alpha}`);
  await page.waitForSelector('#home-tiles');
  ok(page.url().endsWith('/?issuer=' + seed.alpha), 'a card opens the home for that issuer (' + page.url() + ')');
  ok((await page.locator('#issuer-switcher-label').innerText()).trim() === 'SMKA', 'the header\'s switcher names it: SMKA');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll with the tiles and the chart (scrollWidth ${o.sw})`);
  const tiles = await page.locator('#home-tiles > div').count();
  const t1 = await page.locator('#tile-outstanding').boundingBox();
  ok(tiles === 8 && t1.width < 190, `eight tiles, two to a row on a phone (${Math.round(t1.width)} px each)`);
  ok((await page.locator('#tile-outstanding-value').innerText()).trim() === '20,000,000' && (await page.locator('#tile-short-value').innerText()).trim() === '20.00%', 'the tiles read 20,000,000 outstanding and 20.00% of the float short');
  ok((await page.locator('#home-tiles .si-source-line').count()) === 8, 'every tile carries its source line');
  const wraps = await page.evaluate(() => [...document.querySelectorAll('#home-tiles .si-tile-value')].filter((e) => e.scrollWidth > e.clientWidth + 1 || e.getBoundingClientRect().height > 40).map((e) => e.id));
  ok(wraps.length === 0, 'no tile\'s number wraps or is cut at 375 px' + (wraps.length ? ': ' + wraps.join(', ') : ''));
  const chart = await page.locator('#home-overlay-chart').boundingBox();
  ok(chart.width > 250 && chart.x + chart.width <= 375, `the price and events chart fits the screen (${Math.round(chart.width)} px wide)`);
  ok((await page.locator('#home-overlay-chart .si-event').count()) === 2, 'the two past events are on the chart');
  ok((await page.locator('#home-signals [id^="home-signal-"]').count()) === 1 && (await page.locator('#home-signals').innerText()).includes('Established'), 'the open signal is a card with its class');
  ok((await page.locator('#home-note').count()) === 1, 'the morning note leads the page');
  await page.screenshot({ path: `${SHOTS}/phone-home-issuer.png`, fullPage: true });
  await page.click('#issuer-switcher-btn');
  await page.waitForSelector('#issuer-switcher-menu.show');
  ok((await page.locator('#issuer-switcher-menu .dropdown-item').count()) === 2, 'the switcher lists the two issuers she may see');
  await page.click(`#issuer-switcher-item-${seed.gamma}`);
  await page.waitForSelector('#home-no-security');
  ok((await page.locator('#issuer-switcher-label').innerText()).trim() === 'SMKG', 'switching to Gamma: the header says SMKG and the home says it has no security yet');
  await page.click('#header-search-link');
  await page.waitForSelector('#search-q');
  await page.fill('#search-q', 'Frank');
  await page.click('#search-btn');
  await page.waitForSelector('#search-none');
  ok((await page.locator('#search-none').innerText()).includes('Frank'), 'in Gamma nothing matches (the search stays in the current issuer)');
  await page.click('#search-scope-all');
  await page.waitForSelector('#search-group-holder');
  ok((await page.locator('#search-group-holder').innerText()).includes('SMOKE Frank Person'), 'the search box finds a holder by name for an Analyst (every issuer she may see)');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the results');
  await page.screenshot({ path: `${SHOTS}/phone-search.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/?issuer=SMKA', { waitUntil: 'networkidle' });
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-admin') && await shown(page, '#nav-agents') && await shown(page, '#header-search'), 'the sidebar is shown with Sources and settings and Agents; the header carries the search box');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'data,me,admin', 'the admin\'s captions: ' + groups.join(', '));
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').textContent()).includes('Super-admin'), 'the badge reads Super-admin');
  const t1 = await page.locator('#tile-outstanding').boundingBox();
  ok(t1.width < 300, `the tiles sit four to a row on a desktop (${Math.round(t1.width)} px each)`);
  await page.click('#issuer-switcher-btn');
  await page.waitForSelector('#issuer-switcher-menu.show');
  ok((await page.locator('#issuer-switcher-menu .dropdown-item').count()) === 3 && (await page.locator(`#issuer-switcher-item-${seed.beta} .feather-lock`).count()) === 1, 'the admin\'s switcher lists all three, Beta marked restricted');
  await page.keyboard.press('Escape');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png`, fullPage: true });
  await page.click('#nav-admin .nxl-link');
  await page.waitForURL(BASE + '/admin/sources');
  await page.waitForSelector('#admin-coming');
  ok((await page.title()).startsWith('Sources and settings') && (await page.locator('#admin-coming').innerText()).includes('slice 10'), 'HTMX navigation: /admin/sources pushed, the placeholder names slice 10, title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#nav-admin .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Home');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder-admin.png` });
  await page.fill('#header-search', 'short report');
  await page.press('#header-search', 'Enter');
  await page.waitForSelector('#search-q');
  await page.waitForTimeout(300);
  const found = (await page.locator('#search-content').innerText());
  ok(page.url().includes('/search') && page.url().includes('q=short') && found.includes('SMOKE: a short report is published'), 'the header\'s search box finds the event in the current issuer (' + page.url() + ': ' + found.slice(0, 160).replace(/\s+/g, ' ') + ')');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke-btn"]');
  await page.waitForSelector('.badge:has-text("revoked")');
  ok((await page.locator('.badge:has-text("revoked")').count()) >= 1, 'revoking it (with the confirm) marks it revoked');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#header-bell').count()) === 1 && (await page.locator('#header-bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your tokens.', actions: [], navigate: '/settings/tokens/' }; return s; });
  await page.fill('#assistant-input', 'open my tokens');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/settings/tokens/');
  await page.waitForSelector('#tokens-connect');
  ok(page.url() === BASE + '/settings/tokens/', 'a navigate answer from the command bar opened /settings/tokens/');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.analyst, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/?issuer=SMKA');
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('20,000,000') && text.includes('Source: FINRA short interest') && text.includes('SMOKE: short interest is 20.0% of the float') && text.includes('SMOKE: the record date'), 'the tiles, the signal and the coming event are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-signals').getAttribute('href')) === '/signals' && (await page.locator('#tab-my-settings').getAttribute('href')) === '/settings/', 'the tabs are plain links');
  ok((await page.locator(`#issuer-switcher-item-${seed.gamma}`).getAttribute('href')) === `/?issuer=${seed.gamma}`, 'the switcher\'s items are plain links');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.analyst, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Shareholder Intelligence', 'standalone, start_url /, named Shareholder Intelligence');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
