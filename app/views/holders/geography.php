<?php /** Holders by country and state (screen `holders-geography`). Data: security, channel, dates, date, rows */
$total = array_sum(array_map(static fn ($x) => (float) $x['shares'], $rows));
?>
<?= view('shared/header.php', ['id' => 'holders-geography', 'title' => 'Geography', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Geography', null]]]) ?>
<div class="main-content" id="holders-geography-content">
    <?= view('holders/partials/subnav.php', ['active' => 'geography', 'security' => $security, 'channel' => $channel, 'dates' => $dates, 'date' => $date]) ?>
    <div class="card" id="geography"><div class="card-body p-0">
        <?php if ($rows === []): ?><div class="p-3 fs-12 text-muted" id="geography-empty"><?= $date === null ? 'No ' . e(strtolower(HOLDER_CHANNELS[$channel])) . ' loaded.' : 'No holder on the ' . e(strtolower(HOLDER_CHANNELS[$channel])) . ' of ' . e(format_date($date)) . ' yet: its rows may still wait for an Analyst.' ?></div>
        <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="geography-table"><thead class="thead-light"><tr><th>Where</th><th class="text-end">Holders</th><th class="text-end">Shares</th><th class="text-end">%</th></tr></thead><tbody>
            <?php foreach ($rows as $x): ?><tr><td><?= e(($x['state'] ? $x['state'] . ', ' : '') . $x['country']) ?></td><td class="text-end"><?= number_format((int) $x['holders']) ?></td><td class="text-end"><?= e(fmt_n($x['shares'])) ?></td>
                <td class="text-end"><?= $total > 0 ? e(number_format((float) $x['shares'] / $total * 100, 1)) : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="p-3"><?= view('shared/source-line.php', ['source' => 'the ' . strtolower(HOLDER_CHANNELS[$channel]) . ' uploaded (the state as the list writes it)', 'date' => $date]) ?></div><?php endif; ?>
    </div></div>
</div>
