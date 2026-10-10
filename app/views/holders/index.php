<?php /** The holders of the current issuer as cards (screen `holder-list`). Data: security, rows, filters, dates, page, total, here, notice, kinds */
$f = $filters;
?>
<?= view('shared/header.php', ['id' => 'holder-list', 'title' => 'Holders', 'crumbs' => [['Home', '/'], ['Holders', null]]]) ?>
<div class="main-content" id="holder-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('holders/partials/subnav.php', ['active' => 'list', 'security' => $security]) ?>
    <form method="get" action="/holders" class="row g-2 align-items-end mb-3" hx-get="/holders" hx-target="#page-content" hx-push-url="true" id="holder-filters" role="search">
        <input type="hidden" name="security" value="<?= (int) $security['security_id'] ?>">
        <div class="col-12 col-md-4"><label class="form-label fs-11 text-muted mb-1" for="holder-filter-q">Find</label>
            <input type="search" name="q" id="holder-filter-q" value="<?= e($f['q']) ?>" class="form-control btn-touch" placeholder="<?= si_sees_pii() ? 'A name or a city' : 'An entity\'s name or a city' ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 text-muted mb-1" for="holder-filter-channel">List</label>
            <select name="channel" id="holder-filter-channel" class="form-select btn-touch"><option value="">Both</option><?php foreach (HOLDER_CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['channel'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 text-muted mb-1" for="holder-filter-date">Record date</label>
            <select name="record_date" id="holder-filter-date" class="form-select btn-touch"><option value="">Any</option><?php foreach ($dates as $d): ?><option value="<?= e($d) ?>" <?= $f['record_date'] === $d ? 'selected' : '' ?>><?= e(format_date($d)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-11 text-muted mb-1" for="holder-filter-kind">Kind</label>
            <select name="kind" id="holder-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (HOLDER_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="holder-filter-btn"><i class="feather-search me-1"></i>Show</button></div>
    </form>
    <?php if (!si_sees_pii()): ?><p class="fs-12 text-muted" id="holder-list-pii-note"><i class="bi bi-shield-lock me-1"></i>A natural person is listed as "Individual holder": names and addresses are an Analyst's. Entities are named.</p><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="holder-list-empty"><?= $dates === [] ? 'No NOBO list or register is loaded for this security yet: ' . hx_link('/uploads/new', 'upload one', 'fw-semibold') . '.' : 'No holder matches.' ?></div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="holder-list-count"><?= number_format($total) ?> holder<?= $total === 1 ? '' : 's' ?>, the largest first</div>
        <div class="row g-3" id="holder-cards"><?php foreach ($rows as $h): ?><?= view('holders/partials/card.php', ['h' => $h, 'here' => $here]) ?><?php endforeach; ?></div>
        <?php if ($total > $page * HOLDERS_PAGE): ?><div class="mt-3"><?= hx_link('/holders?' . http_build_query(array_filter($f + ['security' => (int) $security['security_id'], 'page' => $page + 1])), 'More holders', 'btn btn-light btn-touch w-100', 'id="holder-list-more"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
