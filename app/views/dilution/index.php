<?php /** The dilution instruments (screen `dilution-list`). Data: issuer, rows, filters, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'dilution-list', 'title' => 'Dilution', 'crumbs' => [['Home', '/'], ['Filings', '/filings'], ['Dilution', null]],
    'action' => has_right('events.write') && (current_member()['member_kind'] ?? '') === 'human' ? hx_link('/dilution/new', '<i class="feather-plus me-1"></i>Record an instrument', 'btn btn-primary btn-touch', 'id="dilution-add-btn"') : '']) ?>
<div class="main-content" id="dilution-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= filings_tabs('dilution') ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="dilution-statuses"><?= hx_link('/dilution', 'Open and past', 'btn btn-sm btn-touch ' . ($filters['status'] === null ? 'btn-primary' : 'btn-light'), 'id="dilution-status-all"') ?>
        <?php foreach (DILUTION_STATUSES as $k => $l): ?><?= hx_link('/dilution?status=' . $k, e($l), 'btn btn-sm btn-touch ' . ($filters['status'] === $k ? 'btn-primary' : 'btn-light'), 'id="dilution-status-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="dilution-empty">No instrument. The watch proposes one when a filing speaks of an at-the-market offering, an equity line, a convertible or warrants.</div></div><?php endif; ?>
    <div class="row g-3" id="dilution-cards"><?php foreach ($rows as $d): ?><?= view('dilution/partials/card.php', ['d' => $d, 'here' => $here]) ?><?php endforeach; ?></div>
</div>
