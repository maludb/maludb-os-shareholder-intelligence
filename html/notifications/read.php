<?php
declare(strict_types=1);
/** Action `notification_read` (log `notification.read`): mark one of my notifications read (`notification`). Own rows only; another's answers as already read. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification') ?? request_integer('id');
if ($id === null) {
    refuse(422, 'Name the notification to mark read (or use Mark all read).');
}
$pdo->beginTransaction();
$n = mark_notifications_read($pdo, $me, $id);
log_activity($pdo, 'notification.read', 'notification', $id, ['after' => ['count' => $n]]);
$pdo->commit();
emit_action_status(true, ['did' => $n === 1 ? 'Marked the notification read' : 'That notification was already read', 'record_id' => $id, 'count' => $n, 'refresh' => 'notificationChanged']);
saved_go(safe_local_path($_POST['return_to'] ?? null) ?? '/notifications', 'notificationChanged');
