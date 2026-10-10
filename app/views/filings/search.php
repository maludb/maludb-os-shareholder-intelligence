<?php /** Search the filings' text (screen `filings-search`). Data: q, form, rows, texts, page, here */ ?>
<?= view('shared/header.php', ['id' => 'filings-search', 'title' => 'Search the filings', 'crumbs' => [['Home', '/'], ['Filings', '/filings'], ['Search', null]]]) ?>
<div class="main-content" id="filings-search-content">
    <?= filings_tabs('search') ?>
    <form method="get" action="/filings/search" class="row g-2 mb-3" id="filings-search-form" hx-get="/filings/search" hx-target="#page-content" hx-push-url="true" role="search">
        <div class="col-12 col-md-8"><label class="form-label fs-11 text-muted mb-1" for="filings-search-q">Words</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control btn-touch" id="filings-search-q" placeholder='"equity line" OR warrant' required></div>
        <div class="col-8 col-md-2"><label class="form-label fs-11 text-muted mb-1" for="filings-search-form-field">Form</label><select name="form" id="filings-search-form-field" class="form-select btn-touch"><option value="">Every form</option>
            <?php foreach (array_keys(FILING_FORM_GROUPS) as $g): ?><option value="<?= e($g) ?>" <?= $form === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-touch w-100" id="filings-search-btn">Search</button></div>
    </form>
    <p class="fs-12 text-muted">The text of <?= number_format($texts) ?> filing<?= $texts === 1 ? ' is' : 's are' ?> read and searched; the watched forms are read on arrival, any other on request.</p>
    <?php if ($q === ''): ?><div class="card"><div class="card-body text-muted" id="filings-search-empty">Words, a "quoted phrase", OR, -excluded.</div></div>
    <?php elseif ($rows === []): ?><div class="card"><div class="card-body text-muted" id="filings-search-none">No filing's text holds “<?= e($q) ?>”.</div></div>
    <?php else: ?><div class="card"><div class="card-body py-1" id="filings-search-hits"><?php foreach ($rows as $r): ?><?= view('filings/partials/hit.php', ['r' => $r, 'q' => $q, 'here' => $here]) ?><?php endforeach; ?></div></div>
        <?php if (count($rows) === 20): ?><div class="mt-2"><?= hx_link('/filings/search?' . http_build_query(array_filter(['q' => $q, 'form' => $form, 'page' => $page + 1])), 'More hits', 'btn btn-light btn-touch w-100', 'id="filings-search-more"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
