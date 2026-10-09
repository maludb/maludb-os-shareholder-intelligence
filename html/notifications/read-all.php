<?php
declare(strict_types=1);
/** Action `notifications_read_all` (log `notification.read_all`): mark every unread notification of mine read. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$pdo->beginTransaction();
$n = mark_notifications_read($pdo, $me, null);
log_activity($pdo, 'notification.read_all', null, null, ['after' => ['count' => $n]]);
$pdo->commit();
emit_action_status(true, ['did' => 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read', 'record_id' => $me, 'count' => $n, 'refresh' => 'notificationChanged']);
saved_go('/notifications', 'notificationChanged');
