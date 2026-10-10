<?php /** The insider ledger (screen `ownership-insiders`). Data: security, rows, summary, f, page, here, notice */
$sid = (int) $security['security_id'];
$pii = si_sees_pii();
$link = has_right('matches.decide') && (current_member()['member_kind'] ?? '') === 'human';
?>
<?= view('shared/header.php', ['id' => 'ownership-insiders', 'title' => 'Insiders', 'crumbs' => [['Home', '/'], ['Ownership', '/ownership/13f'], ['Insiders', null]]]) ?>
<div class="main-content" id="ownership-insiders-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= ownership_tabs('insiders', $sid) ?>
    <?php if ($summary !== []): ?><div class="row g-2 mb-3" id="insider-summary"><?php foreach (array_slice($summary, 0, 4) as $s): ?>
        <div class="col-md-6 col-xl-3"><div class="card mb-0 h-100"><div class="card-body p-2 fs-12"><div class="fw-semibold"><?= hx_link('/ownership/insiders?security=' . $sid . '&owner=' . e((string) $s['reporting_owner_cik']), e((string) $s['reporting_owner_name']), 'text-dark') ?></div>
            <div><?= (int) $s['sales'] ?> sale<?= (int) $s['sales'] === 1 ? '' : 's' ?> (<?= e(fmt_n($s['sale_shares'])) ?>) · <?= (int) $s['buys'] ?> buy<?= (int) $s['buys'] === 1 ? '' : 's' ?></div><div class="text-muted">Owns <?= e(fmt_n($s['last_owned_after'])) ?> after the last</div></div></div></div><?php endforeach; ?></div><?php endif; ?>
    <form method="get" action="/ownership/insiders" class="row g-2 align-items-end mb-3" id="insider-filters" hx-get="/ownership/insiders" hx-target="#page-content" hx-push-url="true"><input type="hidden" name="security" value="<?= $sid ?>">
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="insider-from">From</label><input type="date" name="from" id="insider-from" value="<?= e((string) $f['from']) ?>" class="form-control btn-touch fs-12 px-2"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="insider-to">To</label><input type="date" name="to" id="insider-to" value="<?= e((string) $f['to']) ?>" class="form-control btn-touch fs-12 px-2"></div>
        <div class="col-8 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="insider-code">Code</label><select name="code" id="insider-code" class="form-select btn-touch"><option value="">Every code</option>
            <?php foreach (INSIDER_CODES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['code'] === $k ? 'selected' : '' ?>><?= e($k . ' — ' . $l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="insider-filter-btn">Show</button></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="insiders-empty">No Form 3, 4 or 5 is loaded for this issuer.</div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="insiders-table">
        <thead class="thead-light"><tr><th>Date</th><th>Person</th><th class="d-none d-sm-table-cell">Code</th><th class="text-end">Shares</th><th class="text-end d-none d-md-table-cell">Price</th><th class="text-end d-none d-md-table-cell">Owned after</th><th class="d-none d-lg-table-cell">D/I</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr id="insider-row-<?= (int) $r['transaction_id'] ?>">
            <td class="text-nowrap"><?= e(format_date($r['transaction_date'])) ?><div class="d-sm-none mt-1"><?= code_chip($r['transaction_code']) ?></div><?= $r['is_10b5_1'] ? '<div class="d-sm-none mt-1"><span class="badge bg-soft-info text-info">10b5-1</span></div>' : '' ?></td>
            <td class="text-wrap" style="min-width:9rem"><?= hx_link('/ownership/insiders?security=' . $sid . '&owner=' . e((string) $r['reporting_owner_cik']), e((string) $r['reporting_owner_name']), 'fw-semibold') ?>
                <div class="fs-11 text-muted"><?= e(implode(', ', pg_text_array((string) $r['relationship']))) ?><?= $r['officer_title'] ? ' · ' . e((string) $r['officer_title']) : '' ?><?= $r['is_derivative'] ? ' · ' . e((string) $r['security_title']) : '' ?></div>
                <?php if ($pii && $r['holder_name']): ?><div class="fs-11 text-break"><i class="feather-link me-1"></i>On the register: <?= hx_link('/holders/' . (int) $r['holder_id'], e((string) $r['holder_name']), '', 'id="insider-holder-' . (int) $r['transaction_id'] . '"') ?></div><?php endif; ?>
                <?php if ($link && !$r['holder_name']): ?><form method="post" action="/ownership/insiders/link.php" hx-post="/ownership/insiders/link.php" hx-target="#flash" class="mt-1 d-flex gap-1" id="insider-link-form-<?= (int) $r['transaction_id'] ?>"><?= csrf_field() ?>
                    <input type="hidden" name="reporting_owner_cik" value="<?= e((string) $r['reporting_owner_cik']) ?>"><?= picker_field(['id' => 'insider-link-holder-' . (int) $r['transaction_id'], 'name' => 'holder', 'source' => 'holder', 'params' => ['issuer' => (int) $security['issuer_id']], 'placeholder' => 'Link to a holder', 'title' => 'The register\'s holder for ' . $r['reporting_owner_name']]) ?>
                    <button type="submit" class="btn btn-light btn-touch" id="insider-link-btn-<?= (int) $r['transaction_id'] ?>" aria-label="Link"><i class="feather-link"></i></button></form><?php endif; ?></td>
            <td class="d-none d-sm-table-cell"><?= code_chip($r['transaction_code']) ?><?= $r['is_10b5_1'] ? ' <span class="badge bg-soft-info text-info" title="Under a Rule 10b5-1 plan">10b5-1</span>' : '' ?></td>
            <td class="text-end"><?= $r['acquired_disposed'] === 'D' ? '−' : '' ?><?= e(fmt_n($r['shares'])) ?><div class="d-md-none fs-11 text-muted"><?= $r['price'] !== null ? '@ $' . e(number_format((float) $r['price'], 2)) : '' ?></div></td>
            <td class="text-end d-none d-md-table-cell"><?= $r['price'] === null ? '—' : '$' . e(number_format((float) $r['price'], 2)) ?></td><td class="text-end d-none d-md-table-cell"><?= e(fmt_n($r['shares_owned_after'])) ?></td><td class="d-none d-lg-table-cell"><?= e((string) $r['ownership']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
        <?= view('shared/source-line.php', ['source' => 'the Forms 3, 4 and 5 in the issuer\'s EDGAR index', 'date' => $rows[0]['transaction_date']]) ?>
    <?php endif; ?>
</div>
