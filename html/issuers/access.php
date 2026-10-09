<?php
declare(strict_types=1);
/** /issuers/{id}/access — the restriction list (screen `issuer-access`; restrictions.manage). */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_right('restrictions.manage');
$pdo = db();
$i = issuer_from_request($pdo, 'id');
$rows = issuer_access($pdo, (int) $i['id']);
log_screen_view($pdo, 'issuer-access');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $i['id'], 'restricted' => $rows !== [], 'members' => array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'], 'display_name' => $r['display_name'], 'added_at' => json_ts($r['added_at'])], $rows)]);
}
$notices = ['restricted' => ['success', 'Saved the list.'], 'lifted' => ['success', 'Lifted the restriction: everyone admitted sees this issuer.']];
render_screen($i['name'] . ' — access', view('issuers/access.php', ['i' => $i, 'rows' => $rows, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'tz' => member_timezone(), 'back' => back_link()]),
    ['activeNav' => 'issuers', 'screen' => 'issuer-access', 'entity' => 'issuer', 'recordId' => (string) $i['id']]);
