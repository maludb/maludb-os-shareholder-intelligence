<?php /** The search screen (screen `search`): the box, the kind chips, the results grouped by kind. Data: q, kinds, scope ('issuer'|'all'), issuer, result */
$here = '/search?' . http_build_query(['q' => $q]);
$groups = [];
foreach ($result['rows'] as $r) { $groups[$r['entity_kind']][] = $r; }
$chip = static function (string $label, array $query, bool $on, string $id): string {
    return hx_link('/search?' . http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== [])), e($label), 'btn btn-sm btn-touch ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . e($id) . '"');
};
?>
<?= view('shared/header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]]]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" class="mb-3" id="search-form" hx-get="/search" hx-target="#page-content" hx-push-url="true" role="search">
        <div class="input-group">
            <input type="search" name="q" class="form-control btn-touch" id="search-q" value="<?= e($q) ?>" placeholder="A holder, a filer, a participant, a form, words in a filing…" maxlength="200" autofocus>
            <?php foreach ($kinds as $k): ?><input type="hidden" name="kinds[]" value="<?= e($k) ?>"><?php endforeach; ?>
            <?php if ($scope === 'all'): ?><input type="hidden" name="scope" value="all"><?php endif; ?>
            <button class="btn btn-primary btn-touch" type="submit" id="search-btn" aria-label="Search"><i class="feather-search"></i></button>
        </div>
        <div class="fs-11 text-muted mt-1">Words, "a phrase", or -without. Holders by name are an Analyst's; everyone else sees them counted.</div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="search-kinds">
        <?= $chip('Everything', ['q' => $q, 'scope' => $scope === 'all' ? 'all' : null], $kinds === [], 'search-kind-all') ?>
        <?php foreach (SEARCH_KINDS as $k => [$label]): ?><?= $chip($label, ['q' => $q, 'kinds' => [$k], 'scope' => $scope === 'all' ? 'all' : null], $kinds === [$k], 'search-kind-' . $k) ?><?php endforeach; ?>
    </div>
    <?php if ($issuer !== null): ?><div class="d-flex flex-wrap gap-1 mb-3" id="search-scope">
        <?= $chip($issuer['ticker'] ?: $issuer['name'], ['q' => $q, 'kinds' => $kinds], $scope === 'issuer', 'search-scope-issuer') ?>
        <?= $chip('Every issuer I may see', ['q' => $q, 'kinds' => $kinds, 'scope' => 'all'], $scope === 'all', 'search-scope-all') ?>
    </div><?php endif; ?>
    <?php if ($q === ''): ?>
        <div class="text-muted" id="search-empty">Type what you are looking for.</div>
    <?php elseif ($result['rows'] === []): ?>
        <div class="card"><div class="card-body text-muted" id="search-none">Nothing matches “<?= e($q) ?>”.</div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="search-total"><?= e(number_format($result['total'])) ?> result<?= $result['total'] === 1 ? '' : 's' ?></div>
        <?php foreach (SEARCH_KINDS as $k => [$label, $icon]): if (empty($groups[$k])) { continue; } ?>
            <div class="card mb-3" id="search-group-<?= e($k) ?>"><div class="card-header"><h5 class="card-title mb-0"><i class="<?= e($icon) ?> me-1"></i><?= e($label) ?></h5></div><div class="list-group list-group-flush">
                <?php foreach ($groups[$k] as $r): ?><?= view('search/partials/result.php', ['r' => $r, 'here' => $here]) ?><?php endforeach; ?>
            </div></div>
        <?php endforeach; ?>
        <?php if ($result['page'] < $result['pages']): ?><?= hx_link('/search?' . http_build_query(['q' => $q, 'kinds' => $kinds, 'scope' => $scope === 'all' ? 'all' : null, 'page' => $result['page'] + 1]), 'More results', 'btn btn-light btn-touch w-100', 'id="search-more"') ?><?php endif; ?>
    <?php endif; ?>
</div>
