<?php
declare(strict_types=1);
/** /issuers/{id}?tab=overview|sources|notes — one issuer (screen `issuer-view`). Behind the wall a restricted issuer is a 404, as a missing one. */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_once dirname(__DIR__, 2) . '/app/si/fetch/source.php';
require_right('issuers.read');
$pdo = db();
$i = issuer_from_request($pdo, 'id');
$tab = in_array(request_string('tab'), ['sources', 'notes'], true) ? request_string('tab') : 'overview';
$o = issuer_overview($pdo, (int) $i['id']);
$sources = $tab === 'sources' ? si_issuer_sources($pdo, (int) $i['id']) : [];
log_screen_view($pdo, 'issuer-view');
if (wants_json()) {
    respond_screen(['issuer' => present_issuer($i), 'tab' => $tab, 'securities' => array_map('present_security', $o['securities']),
        'counts' => array_map('present_share_count', array_values($o['counts'])), 'uploads' => array_map(static fn (array $u): array => ['import_id' => (int) $u['import_id'], 'kind' => $u['kind'], 'tied' => $u['tied']], $o['uploads']),
        'notes' => array_map('present_note', $o['notes']),
        'sources' => array_map(static fn (array $s): array => ['source_id' => (int) $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'last_ok_at' => json_ts($s['last_ok_at']), 'last_status' => $s['last_status'], 'paused' => (bool) $s['paused']], $sources)]);
}
$notices = ['created' => ['success', 'Added the issuer and read it from EDGAR.'], 'created_unsynced' => ['warning', 'Added the issuer. EDGAR could not be read yet — see the Sources tab.'],
    'updated' => ['success', 'Saved.'], 'updated_unsynced' => ['warning', 'Saved. EDGAR could not be read yet — see the Sources tab.'], 'synced' => ['success', 'Read from EDGAR.'],
    'restored' => ['success', 'Restored.'], 'note_added' => ['success', 'Added the note.']];
render_screen($i['name'], view('issuers/view.php', ['i' => $i, 'o' => $o, 'tab' => $tab, 'sources' => $sources, 'tz' => member_timezone(),
    'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'here' => here_url(), 'back' => back_link()]), ['activeNav' => 'issuers', 'screen' => 'issuer-view', 'entity' => 'issuer', 'recordId' => (string) $i['id']]);
