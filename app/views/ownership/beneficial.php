<?php /** The 5% filers (screen `ownership-beneficial`). Data: security, rows, here */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'ownership-beneficial', 'title' => '5% holders', 'crumbs' => [['Home', '/'], ['Ownership', '/ownership/13f'], ['5% holders', null]]]) ?>
<div class="main-content" id="ownership-beneficial-content">
    <?= ownership_tabs('beneficial', $sid) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="beneficial-empty">No Schedule 13D or 13G about <?= e($security['name']) ?> is loaded.</div></div><?php endif; ?>
    <div class="row g-3" id="beneficial-cards"><?php foreach ($rows as $r): ?><?= view('ownership/partials/beneficial-card.php', ['r' => $r, 'sid' => $sid, 'here' => $here]) ?><?php endforeach; ?></div>
    <?php if ($rows !== []): ?><div class="mt-3"><?= view('shared/source-line.php', ['source' => 'the latest Schedule 13D or 13G of each filer (a 13D is an active holder\'s; a 13G a passive one\'s)', 'date' => $rows[0]['filed_on']]) ?></div><?php endif; ?>
</div>
