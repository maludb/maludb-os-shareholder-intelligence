<?php /** The short-interest series (screen `short-interest`). Data: security, from, to, rows, svf */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'short-interest', 'title' => 'Short interest', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Short interest', null]]]) ?>
<div class="main-content" id="short-interest-content">
    <?= short_tabs('interest', $sid) ?>
    <?= short_window_form('/short/interest', $sid, $from, $to) ?>
    <p class="fs-12 text-muted">FINRA's short interest: the shares its member firms held short at each settlement date (the 15th and the month's end), published about a week later. A revised figure is a new observation; the row shows the latest and the chip the earlier one.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="si-table-empty">FINRA has published no short interest for this security in these dates yet.</div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="si-table">
        <thead class="thead-light"><tr><th>Settlement</th><th class="d-none d-md-table-cell">Published</th><th class="text-end">Short</th><th class="text-end d-none d-sm-table-cell">Change</th><th class="text-end">Days to cover</th><th class="text-end">% float</th><th class="text-end d-none d-md-table-cell">% outstanding</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr id="si-row-<?= e((string) $r['settlement_date']) ?>">
            <td class="text-nowrap"><?= e(format_date((string) $r['settlement_date'])) ?><?= view('short/partials/revision-chip.php', ['r' => $r]) ?></td>
            <td class="d-none d-md-table-cell text-nowrap"><?= $r['published_on'] ? e(format_date((string) $r['published_on'])) : '<span class="text-muted">read ' . e(format_date((string) $r['observed_on'])) . '</span>' ?></td>
            <td class="text-end"><?= e(short_n($r['short_shares'])) ?></td>
            <td class="text-end d-none d-sm-table-cell <?= (float) ($r['change_shares'] ?? 0) > 0 ? 'text-danger' : ((float) ($r['change_shares'] ?? 0) < 0 ? 'text-success' : '') ?>"><?= $r['change_shares'] === null ? '—' : ((float) $r['change_shares'] > 0 ? '+' : '') . e(short_n($r['change_shares'])) ?><?= $r['change_percent'] !== null ? ' <span class="text-muted">(' . e(number_format((float) $r['change_percent'], 1)) . '%)</span>' : '' ?></td>
            <td class="text-end"><?= e(short_n($r['days_to_cover'], 2)) ?></td>
            <td class="text-end"><?= $r['pct_of_float'] === null ? '—' : e(number_format((float) $r['pct_of_float'], 2)) . '%' ?></td>
            <td class="text-end d-none d-md-table-cell"><?= $r['pct_of_outstanding'] === null ? '—' : e(number_format((float) $r['pct_of_outstanding'], 2)) . '%' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
    <div class="mt-2"><?= view('shared/source-line.php', ['source' => 'FINRA short interest (consolidatedShortInterest)', 'date' => $rows[0]['settlement_date'] ?? null, 'extra' => 'the float and outstanding from the share counts at each date']) ?></div>
</div>
