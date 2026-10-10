<?php /** One search hit: the form, the date, the headline marked. Data: r, q, here */ ?>
<div class="border-bottom py-2" id="search-hit-<?= (int) $r['filing_id'] ?>">
    <div class="d-flex flex-wrap align-items-center gap-2"><?= form_chip((string) $r['form']) ?><span class="fs-12 text-muted"><?= e(format_date($r['filed_on'])) ?></span>
        <?= hx_link(with_back('/filings/' . (int) $r['filing_id'] . '?q=' . rawurlencode($q), $here), 'Open at the hits', 'fs-12 fw-semibold ms-auto') ?></div>
    <div class="fs-12 mt-1 text-break"><?= search_headline_html((string) $r['headline']) ?></div>
</div>
