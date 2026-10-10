<?php /** A window of a filing's text in a reading column, the q hits marked with ids and "next hit" links. Data: f, win, q */
$fid = (int) $f['filing_id'];
$html = nl2br(e($win['text']));
$n = 0;
if ($q !== '') {
    $html = preg_replace_callback('~' . preg_quote(e($q), '~') . '~iu', static function (array $m) use (&$n): string {
        $n++;
        return '<mark id="filing-hit-' . $n . '">' . $m[0] . '</mark>' . '<a class="si-next-hit fs-11 ms-1" href="#filing-hit-' . ($n + 1) . '" aria-label="The next hit">↓</a>';
    }, $html) ?? $html;
}
?>
<div id="filing-text">
    <?php if ($q !== ''): ?><div class="fs-12 mb-2" id="filing-text-hits"><?= (int) $win['hits'] ?> hit<?= (int) $win['hits'] === 1 ? '' : 's' ?> of “<?= e($q) ?>” in the filing<?= $n > 0 ? ' — ' . $n . ' in this window: <a href="#filing-hit-1" id="filing-first-hit">the first</a>' : '' ?>.</div><?php endif; ?>
    <div class="si-reading fs-13 lh-lg text-break"><?= $html ?></div>
    <?php if ($win['char_count'] > $win['offset'] + mb_strlen($win['text']) || $win['offset'] > 0): ?>
    <div class="d-flex gap-2 mt-3" id="filing-text-pages">
        <?php if ($win['offset'] > 0): ?><?= hx_link('/filings/' . $fid . '?' . http_build_query(array_filter(['q' => $q, 'at' => max(0, $win['offset'] - FILING_TEXT_WINDOW)])), 'Earlier text', 'btn btn-light btn-touch flex-grow-1', 'id="filing-text-prev"') ?><?php endif; ?>
        <?php if ($win['char_count'] > $win['offset'] + mb_strlen($win['text'])): ?><?= hx_link('/filings/' . $fid . '?' . http_build_query(array_filter(['q' => $q, 'at' => $win['offset'] + FILING_TEXT_WINDOW])), 'More of the text', 'btn btn-light btn-touch flex-grow-1', 'id="filing-text-next"') ?><?php endif; ?>
    </div><div class="fs-11 text-muted mt-1">Characters <?= number_format($win['offset'] + 1) ?>–<?= number_format($win['offset'] + mb_strlen($win['text'])) ?> of <?= number_format($win['char_count']) ?></div>
    <?php endif; ?>
</div>
