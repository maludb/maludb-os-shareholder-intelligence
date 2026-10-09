<?php
declare(strict_types=1);

/**
 * The shell's menu: ONE table — the sidebar and the phone's tab bar read it, so a menu item and the right that opens its screen can
 * never disagree (design §9, sso-shell.md). An item shows only when the person holds its right (several joined by "|": any one).
 * Home · Signals · Positions · Holders · Ownership · Filings · Short side · Events · Reports, then Data (uploads, issuers, participants,
 * proposals), Me (the person's own pages) and Admin. A record's own screens are the slices' and hang under these, not here.
 *   [id, url, icon, label, right]
 */
const NAV_ALL = 'issuers.read';                                     // every role reads the issuers it may see (db/004)

function nav_groups(): array
{
    return [
        'Shareholder Intelligence' => [
            ['home',      '/',               'feather-home',          'Home',       NAV_ALL],
            ['signals',   '/signals',        'feather-flag',          'Signals',    'signals.read'],
            ['positions', '/positions',      'feather-bar-chart-2',   'Positions',  'positions.read'],
            ['holders',   '/holders',        'feather-users',         'Holders',    'holders.read_aggregate|holders.read'],
            ['ownership', '/ownership/13f',  'feather-briefcase',     'Ownership',  'institutions.read'],
            ['filings',   '/filings',        'feather-file-text',     'Filings',    'filings.read'],
            ['short',     '/short',          'feather-trending-down', 'Short side', 'short.read'],
            ['events',    '/events',         'feather-calendar',      'Events',     'events.read'],
            ['reports',   '/reports',        'feather-book-open',     'Reports',    'reports.read|reports.run'],
        ],
        'Data' => [
            ['uploads',      '/uploads',      'feather-upload-cloud', 'Uploads',      'positions.read|uploads.write'],
            ['issuers',      '/issuers',      'feather-layers',       'Issuers',      NAV_ALL],
            ['participants', '/participants', 'feather-share-2',      'Participants', 'positions.read'],
            ['proposals',    '/proposals',    'feather-inbox',        'Proposals',    'signals.work|matches.decide'],
        ],
        'Me' => [
            ['my-settings',   '/settings/',        'feather-settings', 'My settings',   NAV_ALL],
            ['notifications', '/notifications',    'feather-bell',     'Notifications', NAV_ALL],
            ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        NAV_ALL],
            ['activity',      '/activity',         'feather-list',     'My activity',   NAV_ALL],
        ],
        'Admin' => [
            ['admin',  '/admin/sources', 'feather-sliders', 'Sources and settings', 'settings.manage|sources.manage|credentials.manage|tokens.manage|records.purge'],
            ['agents', '/admin/agents',  'feather-cpu',     'Agents',               'agents.settings'],
        ],
    ];
}

/** The phone's tabs (sso-shell.md): Home · Signals · Positions · Holders · Me. */
function nav_tabs(): array
{
    return [
        ['home',        '/',          'feather-home',        'Home',      NAV_ALL],
        ['signals',     '/signals',   'feather-flag',        'Signals',   'signals.read'],
        ['positions',   '/positions', 'feather-bar-chart-2', 'Positions', 'positions.read'],
        ['holders',     '/holders',   'feather-users',       'Holders',   'holders.read_aggregate|holders.read'],
        ['my-settings', '/settings/', 'feather-user',        'Me',        NAV_ALL],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest role the member holds here, in words — the header's badge (db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $held = pg_text_array((string) (one_value(db(), 'SELECT si_member_roles(:id)', ['id' => (int) $m['id']]) ?: '{}'));
    foreach (['admin' => 'SI admin', 'manager' => 'IR Manager', 'user' => 'Analyst', 'viewer' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return '';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/search' => 'Search', '/notifications' => 'Notifications', '/activity' => 'My activity', '/settings/' => 'My settings',
               '/settings/tokens/' => 'Tokens', '/signals/history' => 'Signal history', '/holders/unmatched' => 'Unmatched rows', '/calendar' => 'Calendar',
               '/reconciliation' => 'Reconciliation', '/exports' => 'Exports'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    $records = ['issuers' => 'the issuer', 'participants' => 'the participant', 'uploads' => 'the upload', 'holders' => 'the holder', 'filings' => 'the filing',
                'dilution' => 'the instrument', 'institutions' => 'the institution', 'signals' => 'the signal', 'events' => 'the event', 'reports' => 'the report'];
    if (preg_match('#^/([a-z]+)/\d+$#', $path, $mm) && isset($records[$mm[1]])) {
        return [$back, $records[$mm[1]]];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it. */
const NAV_SLICES = ['issuers' => 'slice 1', 'participants' => 'slice 1', 'uploads' => 'slice 2', 'positions' => 'slice 2', 'holders' => 'slice 3',
    'filings' => 'slice 4', 'ownership' => 'slice 5', 'short' => 'slice 6', 'signals' => 'slice 7', 'events' => 'slice 8', 'reports' => 'slice 8',
    'proposals' => 'slice 9', 'agents' => 'slice 9', 'admin' => 'slice 10', 'calendar' => 'slice 8', 'issuer-add' => 'slice 1', 'upload-add' => 'slice 2'];

/** The manifest's screen id of each placeholder (what the screen log and X-Screen carry). */
const NAV_SCREENS = ['signals' => 'signal-list', 'positions' => 'positions', 'holders' => 'holder-list', 'ownership' => 'ownership-13f', 'filings' => 'filing-list',
    'short' => 'short', 'events' => 'event-list', 'reports' => 'report-list', 'uploads' => 'upload-list', 'upload-add' => 'upload-add', 'issuers' => 'issuer-list',
    'issuer-add' => 'issuer-add', 'participants' => 'participant-list', 'proposals' => 'proposals', 'calendar' => 'calendar', 'admin' => 'admin-sources', 'agents' => 'admin-agents'];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $titles = ['calendar' => 'Calendar', 'issuer-add' => 'Add an issuer', 'upload-add' => 'Upload a file'];
    $item ??= isset($titles[$navId]) ? [$navId, '', match ($navId) { 'calendar' => 'feather-calendar', 'issuer-add' => 'feather-layers', default => 'feather-upload-cloud' }, $titles[$navId]] : null;
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), NAV_SCREENS[$navId] ?? $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => match ($navId) { 'issuer-add' => 'issuers', 'upload-add' => 'uploads', 'calendar' => 'events', default => $navId }, 'screen' => NAV_SCREENS[$navId] ?? $navId]);
}
