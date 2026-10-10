<?php /** The filings (screen `filing-list`). Data: issuer, rows, f, page, total, src, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'filing-list', 'title' => 'Filings', 'crumbs' => [['Home', '/'], ['Filings', null]],
    'action' => has_right('uploads.write') && $src ? '<form method="post" action="/admin/sources/pull.php" hx-post="/admin/sources/pull.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="source" value="' . (int) $src['source_id'] . '"><input type="hidden" name="issuer" value="' . (int) $issuer['issuer_id'] . '"><input type="hidden" name="return_to" value="/filings"><button type="submit" class="btn btn-light btn-touch" id="filings-pull-btn"><i class="feather-refresh-cw me-1"></i>Pull from EDGAR now</button></form>' : '']) ?>
<div class="main-content" id="filing-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= filings_tabs('list') ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="filing-forms">
        <?= hx_link('/filings', 'Every form', 'btn btn-sm btn-touch ' . ($f['form'] === '' && !$f['about_us'] ? 'btn-primary' : 'btn-light'), 'id="filing-form-all"') ?>
        <?php foreach (array_merge(array_keys(FILING_FORM_GROUPS), ['other']) as $g): ?><?= hx_link('/filings?form=' . rawurlencode($g), e($g), 'btn btn-sm btn-touch ' . ($f['form'] === $g ? 'btn-primary' : 'btn-light'), 'id="filing-form-' . e(preg_replace('~\W~', '', $g)) . '"') ?><?php endforeach; ?>
        <?= hx_link('/filings?about_us=1', '<i class="bi bi-box-arrow-in-down-left me-1"></i>About us', 'btn btn-sm btn-touch ' . ($f['about_us'] ? 'btn-primary' : 'btn-light'), 'id="filing-form-about"') ?>
    </div>
    <p class="fs-12 text-muted" id="filing-list-source"><?= $src && $src['last_ok_at'] ? 'EDGAR\'s index was read ' . e(format_ts((string) $src['last_ok_at'], member_timezone(), 'M j, g:i A')) . '.' : 'EDGAR has not been read yet.' ?> <?= number_format($total) ?> filing<?= $total === 1 ? '' : 's' ?>.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="filing-list-empty"><?= ($issuer['cik'] ?? null) === null ? 'This issuer has no CIK: EDGAR is read once its CIK is set.' : 'No filing matches.' ?></div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="filings-table">
        <thead class="thead-light"><tr><th>Filed</th><th>Form</th><th>Filing</th><th class="d-none d-md-table-cell">Read</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><?= view('filings/partials/row.php', ['r' => $r, 'here' => $here]) ?><?php endforeach; ?>
        </tbody></table></div></div></div>
        <?php if ($total > $page * FILINGS_PAGE): ?><div class="mt-2"><?= hx_link('/filings?' . http_build_query(array_filter($f + ['page' => $page + 1])), 'Older filings', 'btn btn-light btn-touch w-100', 'id="filing-list-more"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
