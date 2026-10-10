<?php
declare(strict_types=1);
/** /holders/{id} — one holder (screen `holder-view`): the accounts (the registration lines for the PII roles), the positions by record date and channel, the kind proposals, notes, tags, Merge into… (managers). */
require_once dirname(__DIR__, 2) . '/app/features/holders/screen.php';
require_any_right('holders.read_aggregate|holders.read');
$pdo = db();
$h = holder_from_request($pdo, 'id');
$hid = (int) $h['holder_id'];
$accounts = holder_accounts($pdo, $hid);
$history = holder_history_points($pdo, $hid);
$kindProposals = $pdo->query("SELECT * FROM mcp_holder_kind_proposals WHERE holder_id = $hid AND status = 'proposed' ORDER BY created_at DESC")->fetchAll();
$notes = $h['pii_withheld'] ? [] : find_notes($pdo, 'holder', $hid);
log_screen_view($pdo, 'holder-view');
if (wants_json()) {
    respond_screen(['holder' => present_holder($h), 'accounts' => array_map('present_holder_account', $accounts),
        'history' => array_map(static fn (array $r): array => ['record_date' => $r['record_date'], 'channel' => $r['channel'], 'shares' => (float) $r['shares'], 'accounts' => (int) $r['accounts']], $history),
        'kind_proposals' => array_map(static fn (array $p): array => ['proposal_id' => (int) $p['proposal_id'], 'kind' => $p['kind'], 'entity_type' => $p['entity_type'], 'evidence' => $p['evidence']], $kindProposals),
        'notes' => array_map('present_note', $notes)]);
}
$notices = ['updated' => ['success', 'Saved.'], 'note_added' => ['success', 'Added the note.'], 'kind_set' => ['success', 'Set the kind.'], 'kind_proposed' => ['success', 'Proposed the kind.'],
            'merged' => ['success', 'Merged: this holder has the other\'s accounts, positions and rows.'], 'restored' => ['success', 'Restored.'], 'new_holder' => ['success', 'A new holder was made from the row.'],
            'accepted' => ['success', 'Matched: the row joins this holder.']];
render_screen($h['pii_withheld'] ? 'Individual holder' : (string) $h['display_name'], view('holders/view.php', ['h' => $h, 'accounts' => $accounts, 'history' => $history, 'kindProposals' => $kindProposals, 'notes' => $notes,
    'tz' => member_timezone(), 'back' => back_link(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'holders', 'screen' => 'holder-view', 'entity' => 'holder', 'recordId' => (string) $hid]);
