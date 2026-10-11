<?php
declare(strict_types=1);
/**
 * Action `notification` (log `notification.send` {to, kind, issuer_id} — never the words; signals.work; an agent's door): **recipient** (a member — a person), **title**, body, kind (morning_note, signal, mention or digest —
 * mention when left out), issuer (the notice is about it: never one the SENDER may not see, and the database sends nothing to a recipient who may not see it). The recipient's bell and the email or text their
 * preferences ask for (the worker's outbox sends them); a notice to an agent is refused — an agent is messaged through the kernel. record_id = the notification.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/write.php';
si_handler_begin();
require_right('signals.work');
$pdo = db();
$errors = [];
$to = request_integer('recipient') ?? request_integer('to');
if ($to === null) {
    $errors['recipient'] = 'Name the person the notice is for.';
}
$title = (string) (req_val('title') ?? '');
if ($title === '' || mb_strlen($title) > 200) {
    $errors['title'] = $title === '' ? 'A notice has a title.' : 'The title is up to 200 characters.';
}
$body = (string) (req_val('body') ?? '');
if (mb_strlen($body) > 4000) {
    $errors['body'] = 'The body is up to 4,000 characters.';
}
$kind = (string) (req_val('kind') ?? '') ?: 'mention';
if (!isset(NOTIFY_KINDS[$kind])) {
    $errors['kind'] = 'The kind is morning_note, signal, mention or digest.';
}
$issuer = null;
if (req_has('issuer') && (string) req_val('issuer') !== '') {
    $issuer = request_integer('issuer');
    if ($issuer === null) {
        $errors['issuer'] = 'Name the issuer by its id.';
    }
}
if ($errors !== []) {
    si_refuse_fields($errors);
}
$id = si_guard($pdo, static function () use ($pdo, $to, $kind, $title, $body, $issuer): ?int {
    $pdo->beginTransaction();
    $id = notify_member($pdo, (int) $to, $kind, $title, $body === '' ? null : $body, $issuer, (int) current_member_id());
    $pdo->commit();
    return $id;
});
if ($id === null) {
    refuse(422, 'That person may not see that issuer: nothing was sent.');
}
si_done('Sent the notice', $id, si_land(return_path('/notifications'), 'sent'), 'notificationChanged');
