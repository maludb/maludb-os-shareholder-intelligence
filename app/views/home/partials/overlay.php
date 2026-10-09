<?php /** The price with the events overlay, 90 days (svg_price_overlay) and its table twin. Data: overlay {prices, events}, security */ require_once dirname(__DIR__, 3) . '/si/svg.php'; ?>
<div class="card mb-3" id="home-overlay"><div class="card-header"><h5 class="card-title mb-0">Price and events, 90 days<?= $security !== null ? ' · ' . e($security['ticker'] ?: $security['cusip']) : '' ?></h5></div><div class="card-body">
<?php if ($overlay['prices'] === []): ?>
    <div class="text-muted fs-12" id="home-overlay-empty">No prices yet. They are fetched every market day once the security has a ticker (slice 6).</div>
<?php else: ?>
    <?= svg_price_overlay($overlay['prices'], $overlay['events'], 'home-overlay-chart') ?>
    <?= view('shared/source-line.php', ['source' => 'daily closes; events as dashed lines (red high, amber watch)', 'date' => $overlay['prices'][count($overlay['prices']) - 1]['trade_date']]) ?>
    <details class="mt-2 fs-12"><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="home-overlay-table"><thead><tr><th>Date</th><th class="text-end">Close</th><th>Event</th></tr></thead><tbody>
        <?php $ev = []; foreach ($overlay['events'] as $e) { $ev[$e['on_date']][] = $e['title']; } ?>
        <?php foreach (array_reverse($overlay['prices']) as $p): ?><tr><td><?= e(format_date($p['trade_date'])) ?></td><td class="text-end"><?= e(number_format((float) $p['close'], 2)) ?></td><td><?= e(implode('; ', $ev[$p['trade_date']] ?? [])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
<?php endif; ?>
</div></div>
