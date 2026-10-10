<?php /** The institutions (screen `institution-list`). Data: security, rows, f, here */ ?>
<?= view('shared/header.php', ['id' => 'institution-list', 'title' => 'Institutions', 'crumbs' => [['Home', '/'], ['Ownership', '/ownership/13f'], ['Institutions', null]]]) ?>
<div class="main-content" id="institution-list-content">
    <?php if ($security !== null): ?><?= ownership_tabs('institutions', (int) $security['security_id']) ?><?php endif; ?>
    <form method="get" action="/institutions" class="row g-2 align-items-end mb-3" id="institution-filters" hx-get="/institutions" hx-target="#page-content" hx-push-url="true" role="search">
        <div class="col-12 col-md-6"><label class="form-label fs-11 text-muted mb-1" for="institution-q">Find</label><input type="search" name="q" id="institution-q" value="<?= e($f['q']) ?>" class="form-control btn-touch" placeholder="A name, a CIK or an old name"></div>
        <div class="col-8 col-md-4"><label class="form-label fs-11 text-muted mb-1" for="institution-kind">Kind</label><select name="kind" id="institution-kind" class="form-select btn-touch"><option value="">Every kind</option>
            <?php foreach (INSTITUTION_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="institution-filter-btn">Show</button></div></form>
    <p class="fs-12 text-muted"><?= $security !== null && $f['q'] === '' ? 'The filers that hold or reported ' . e($security['name']) . ', the largest first.' : 'Every filer read from EDGAR.' ?></p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="institution-list-empty">No institution.</div></div><?php endif; ?>
    <div class="row g-3" id="institution-cards"><?php foreach ($rows as $i): ?><?= view('institutions/partials/card.php', ['i' => $i, 'security' => $security, 'here' => $here]) ?><?php endforeach; ?></div>
</div>
