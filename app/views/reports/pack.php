<?php /** The pack's body (render_pack / report-view): the title, the period, the narrative, the sections. Data: r, ctx, narrative (HTML), sections (HTML) */ ?>
<!--pack-body-->
<article id="pack-body">
    <h1 id="pack-title"><?= e((string) $r['title']) ?></h1>
    <p class="si-pack-meta text-muted"><?= e((string) ($ctx['issuer']['name'] ?? '')) ?><?= $ctx['security'] ? ' · ' . e((string) ($ctx['security']['ticker'] ?: $ctx['security']['name'])) . ($ctx['security']['cusip'] ? ' · CUSIP ' . e((string) $ctx['security']['cusip']) : '') : '' ?> ·
        <?= e(REPORT_KINDS[$r['kind']] ?? (string) $r['kind']) ?> for <?= e(format_date((string) $r['period_from'])) ?> to <?= e(format_date((string) $r['period_to'])) ?></p>
    <?php if ($narrative !== ''): ?><div id="pack-narrative" class="si-markdown"><?= $narrative ?></div><?php endif; ?>
<!--pack-sections-->
    <?= $sections ?>
<!--/pack-sections-->
    <p class="fs-11 text-muted">Prepared from the data on record on the day it was rendered; every figure carries its source and date. Signals advise and do not score. Not for the market: nothing here is a disclosure.</p>
</article>
<!--/pack-body-->
