<?php
declare(strict_types=1);
/**
 * /proposals?kind=&issuer= — everything the agents proposed and nobody has taken (screen `proposals`): a signal's disposition with Take it (the matching disposition, in the proposal's words) and Not this, a holder match
 * with the row beside its candidate and Accept or Reject, a holder's kind with Accept or Reject — each with the agent's name and when. Across the issuers the caller may see; a Viewer reads the signal
 * proposals only (the holder ones are an Analyst's, by the view), and has no button.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/present.php';
require_once dirname(__DIR__, 2) . '/app/features/holders/present.php';
require_once dirname(__DIR__, 2) . '/app/features/issuers/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/issuers/present.php';
require_right('issuers.read');
$pdo = db();
$kind = isset(PROPOSAL_KINDS[request_string('kind')]) ? request_string('kind') : null;
$issuer = request_integer('issuer');
$all = proposals($pdo, $issuer, null);
$rows = $kind === null ? $all : array_values(array_filter($all, static fn (array $p): bool => $p['kind'] === $kind));
$counts = array_fill_keys(array_keys(PROPOSAL_KINDS), 0);
foreach ($all as $p) { $counts[$p['kind']]++; }
log_screen_view($pdo, 'proposals');
if (wants_json()) {
    respond_screen(['filters' => ['kind' => $kind, 'issuer' => $issuer], 'counts' => $counts, 'total' => count($rows), 'kinds' => PROPOSAL_KINDS, 'proposals' => array_map('present_proposal', $rows)]);
}
$human = (current_member()['member_kind'] ?? '') === 'human';
$notices = ['acknowledged' => ['success', 'Acknowledged.'], 'dismissed' => ['success', 'Dismissed.'], 'escalated' => ['success', 'Escalated to the IR Manager.'], 'accepted' => ['success', 'Matched: the row joins the holder.'],
            'rejected' => ['success', 'Rejected (remembered).'], 'kind_set' => ['success', 'Set the kind.'], 'kind_rejected' => ['success', 'Rejected the kind (remembered).']];
render_screen('Proposals', view('proposals/index.php', ['rows' => $rows, 'counts' => $counts, 'kind' => $kind, 'issuer' => $issuer, 'work' => $human && has_right('signals.work'), 'decide' => $human && has_right('matches.decide'),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), ['activeNav' => 'proposals', 'screen' => 'proposals']);
