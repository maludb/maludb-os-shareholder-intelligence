<?php
declare(strict_types=1);
/** Action `share_count_delete` (log `share_count.delete`; issuers.manage; confirm; an agent's pauses — deletion): remove one dated count. */
require_once dirname(__DIR__, 3) . '/app/features/issuers/handler.php';
si_handler_begin();
require_right('issuers.manage');
$pdo = db();
$st = $pdo->prepare('SELECT c.*, s.issuer_id FROM mcp_share_counts c JOIN mcp_securities s ON s.security_id = c.security_id WHERE c.share_count_id = :id');
$st->execute(['id' => (int) (request_integer('share_count') ?? 0)]);
$c = $st->fetch();
if ($c === false) {
    refuse(404, 'Not found.');
}
$pdo->beginTransaction();
share_count_delete($pdo, (int) $c['share_count_id']);
log_activity($pdo, 'share_count.delete', 'share_count', (int) $c['share_count_id'], ['issuer_id' => (int) $c['issuer_id'], 'security_id' => (int) $c['security_id'],
    'before' => ['kind' => $c['kind'], 'as_of' => $c['as_of'], 'value' => $c['value'], 'source' => $c['source']]]);
$pdo->commit();
si_done('Removed the ' . (SHARE_COUNT_KINDS[$c['kind']] ?? $c['kind']) . ' count of ' . $c['as_of'], (int) $c['share_count_id'],
    si_land('/issuers/' . (int) $c['issuer_id'] . '/share-counts?security=' . (int) $c['security_id'], 'count_deleted'), 'shareCountChanged');
