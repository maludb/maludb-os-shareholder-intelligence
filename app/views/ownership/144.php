<?php /** The Form 144 notices (screen `ownership-144`). Data: security, rows, f */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'ownership-144', 'title' => 'Form 144', 'crumbs' => [['Home', '/'], ['Ownership', '/ownership/13f'], ['Form 144', null]]]) ?>
<div class="main-content" id="ownership-144-content">
    <?= ownership_tabs('144', $sid) ?>
    <p class="fs-12 text-muted">A Form 144 is the notice of a proposed sale of restricted or control shares — an intention, not a trade.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="form144-empty">No Form 144 is loaded for this issuer.</div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="form144-table">
        <thead class="thead-light"><tr><th>Filed</th><th>Seller</th><th class="text-end">Shares</th><th class="text-end d-none d-md-table-cell">Value</th><th class="d-none d-md-table-cell">About</th><th class="d-none d-lg-table-cell">Broker</th></tr></thead><tbody>
        <?php foreach ($rows as $n): ?><tr id="form144-row-<?= (int) $n['notice_id'] ?>"><td class="text-nowrap"><?= $n['filing_id'] ? hx_link('/filings/' . (int) $n['filing_id'], e(format_date($n['filed_on'])), '') : e(format_date($n['filed_on'])) ?></td>
            <td class="text-wrap"><span class="fw-semibold"><?= e((string) $n['seller_name']) ?></span><div class="fs-11 text-muted"><?= e((string) $n['relationship']) ?></div></td><td class="text-end"><?= e(fmt_n($n['shares_to_sell'])) ?></td>
            <td class="text-end d-none d-md-table-cell"><?= $n['aggregate_market_value'] === null ? '—' : '$' . e(number_format((float) $n['aggregate_market_value'], 0)) ?></td><td class="d-none d-md-table-cell"><?= e(format_date($n['approx_sale_date'])) ?></td><td class="d-none d-lg-table-cell"><?= e((string) $n['broker']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
</div>
