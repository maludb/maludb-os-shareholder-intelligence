<?php
declare(strict_types=1);
/** Action `borrow_observation_delete` (log `borrow.delete`; borrow.write; confirm; an agent's pauses — deletion): **observation**. */
require_once dirname(__DIR__, 3) . '/app/features/short/screen.php';
si_handler_begin();
require_right('borrow.write');
$pdo = db();
$st = $pdo->prepare('SELECT observation_id, security_id, observed_on FROM mcp_borrow_observations WHERE observation_id = :id');
$st->execute(['id' => (int) (request_integer('observation') ?? 0)]);
$b = $st->fetch() ?: refuse(404, 'Not found.');
si_guard($pdo, static function () use ($pdo, $b): void {
    $pdo->beginTransaction();
    borrow_observation_delete($pdo, (int) $b['observation_id']);
    $pdo->commit();
});
si_done('Removed the borrow observation of ' . $b['observed_on'], (int) $b['observation_id'], si_land('/short/borrow?security=' . (int) $b['security_id'], 'removed'), 'borrowChanged');
