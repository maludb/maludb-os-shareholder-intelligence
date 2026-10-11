<?php /** The borrow observations (screen `short-borrow`). Data: security, from, to, rows, canBorrow */ $sid = (int) $security['security_id'];
$remove = static fn (array $b, string $p): string => '<form method="post" action="/short/borrow/delete.php" hx-post="/short/borrow/delete.php" hx-target="#flash" hx-confirm="Remove the observation of ' . e(format_date((string) $b['observed_on'])) . '?" class="d-inline">'
    . csrf_field() . '<input type="hidden" name="observation" value="' . (int) $b['observation_id'] . '"><button class="btn btn-light btn-sm btn-touch" id="borrow-delete-' . $p . (int) $b['observation_id'] . '" aria-label="Remove"><i class="feather-trash-2"></i></button></form>'; ?>
<?= view('shared/header.php', ['id' => 'short-borrow', 'title' => 'Borrow', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Borrow', null]],
    'action' => $canBorrow ? hx_link('/short/borrow/new?security=' . $sid, '<i class="feather-plus me-1"></i>Record', 'btn btn-primary btn-touch btn-sm', 'id="borrow-add-btn"') : '']) ?>
<div class="main-content" id="short-borrow-content">
    <?= short_tabs('borrow', $sid) ?>
    <?= short_window_form('/short/borrow', $sid, $from ?? '', $to ?? '') ?>
    <p class="fs-12 text-muted">The cost to borrow is not public: these are figures a person recorded — a prime broker's quote or a number from elsewhere — and each is <span class="badge text-bg-light">indicative</span>. A licensed feed is an Extended source.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="borrow-table-empty">No borrow observation is recorded<?= $from ? ' in these dates' : '' ?>.</div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="borrow-table">
        <thead class="thead-light"><tr><th>Observed</th><th>Source</th><th class="text-end">Fee</th><th class="text-end d-none d-sm-table-cell">Utilisation</th><th class="text-end d-none d-md-table-cell">On loan</th><th class="text-end d-none d-md-table-cell">Available</th><th class="d-none d-lg-table-cell">Note</th><th class="d-none d-sm-table-cell"></th></tr></thead><tbody>
        <?php foreach ($rows as $b): ?><tr id="borrow-row-<?= (int) $b['observation_id'] ?>"><td class="text-nowrap"><?= e(format_date((string) $b['observed_on'])) ?><?= $canBorrow ? '<div class="d-sm-none mt-1">' . $remove($b, 'm-') . '</div>' : '' ?></td>
            <td class="text-wrap"><?= e(BORROW_SOURCES[$b['source']] ?? $b['source']) ?><?= $b['source'] !== 'vendor' ? ' <span class="badge text-bg-light">indicative</span>' : '' ?><div class="fs-11 text-muted"><?= e((string) ($b['recorded_by_name'] ?? '')) ?></div></td>
            <td class="text-end"><?= $b['fee_rate_percent'] === null ? '—' : e(number_format((float) $b['fee_rate_percent'], 2)) . '%' ?><?= $b['utilization_percent'] === null ? '' : '<div class="fs-11 text-muted d-sm-none">' . e(number_format((float) $b['utilization_percent'], 1)) . '% used</div>' ?></td><td class="text-end d-none d-sm-table-cell"><?= $b['utilization_percent'] === null ? '—' : e(number_format((float) $b['utilization_percent'], 1)) . '%' ?></td>
            <td class="text-end d-none d-md-table-cell"><?= e(short_n($b['shares_on_loan'])) ?></td><td class="text-end d-none d-md-table-cell"><?= e(short_n($b['shares_available'])) ?></td><td class="d-none d-lg-table-cell text-wrap"><?= e((string) $b['note']) ?></td>
            <td class="text-end d-none d-sm-table-cell"><?= $canBorrow ? $remove($b, '') : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
</div>
