<?php
declare(strict_types=1);
/**
 * Action `report_send` (log `report.send` — the counts, never an address; reports.approve; confirm; an agent's pauses — external_send): `report` (approved), `recipients[]` (member ids — members only,
 * people who may see the issuer), message. One MaluMail message each, a link inside the application. Nothing is sent to an address that is not a member.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/screen.php';
si_handler_begin();
require_right('reports.approve');
$pdo = db();
$r = report_from_request($pdo, 'report');
$id = (int) $r['report_id'];
$raw = request_list('recipients') ?? [];
foreach ($raw as $x) {
    if (!ctype_digit($x)) { si_refuse_fields(['recipients' => 'A pack goes to members, chosen from the list — not to an address.']); }
}
if ($raw === []) { si_refuse_fields(['recipients' => 'Choose who the pack goes to.']); }
$message = req_val('message');
if ($message !== null && mb_strlen($message) > 1000) { si_refuse_fields(['message' => 'The message is up to 1,000 characters.']); }
$failed = 0;
$sent = si_guard($pdo, static function () use ($pdo, $id, $raw, $message, &$failed): int {
    $n = report_send($pdo, $id, array_map('intval', $raw), $message === '' ? null : $message, (int) current_member_id(), $failed);
    return $n;
});
si_done('Sent the pack', $id, si_land('/reports/' . $id . '?count=' . $sent . '&failed=' . $failed, 'sent'), 'reportChanged', ['sent' => $sent, 'failed' => $failed]);
