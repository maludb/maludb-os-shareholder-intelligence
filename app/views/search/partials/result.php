<?php /** One search result. The headline is ts_headline's (the matches wrapped in <b>, everything else escaped below). Data: r, here */
$headline = (string) ($r['headline'] ?? '');
$safe = str_replace(['&lt;b&gt;', '&lt;/b&gt;'], ['<mark>', '</mark>'], e($headline));
?>
<div class="list-group-item" id="search-result-<?= e($r['entity_kind']) ?>-<?= (int) $r['entity_id'] ?>">
    <div class="d-flex align-items-center gap-2"><?= hx_link(with_back(search_result_url($r), $here), e($r['title']), 'fw-semibold text-dark text-truncate') ?><?php if (($r['status'] ?? '') !== ''): ?><span class="badge bg-soft-secondary text-secondary ms-auto"><?= e($r['status']) ?></span><?php endif; ?></div>
    <?php if ($headline !== ''): ?><div class="fs-12 text-muted"><?= $safe ?></div><?php endif; ?>
</div>
