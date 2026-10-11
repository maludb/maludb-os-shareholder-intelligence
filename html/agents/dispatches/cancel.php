<?php
declare(strict_types=1);
/** Action `dispatch_cancel` (log `dispatch.cancel`; agents.settings; PEOPLE): **dispatch** — one not yet answered (sent, failed with attempts left, awaiting approval); the worker never makes it. record_id = the dispatch. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/write.php';
si_handler_begin();
require_human();
require_right('agents.settings');
$pdo = db();
$id = request_integer('dispatch') ?? request_integer('id') ?? si_refuse_fields(['dispatch' => 'Name the dispatch.']);
si_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    dispatch_cancel($pdo, $id);
    $pdo->commit();
});
si_done('Cancelled the dispatch', $id, si_land(return_path('/admin/agents'), 'cancelled', 'dispatch-row-' . $id), 'agentChanged');
