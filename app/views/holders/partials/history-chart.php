<?php /** A holder's shares by record date, one line per channel, and the table under it. Data: history */
require_once dirname(__DIR__, 3) . '/si/svg.php';
$by = [];
foreach ($history as $r) { $by[$r['channel']][] = ['d' => $r['record_date'], 'v' => (float) $r['shares']]; }
?>
<?php if ($history === []): ?><div class="fs-12 text-muted" id="holder-history-empty">No position on a loaded list.</div>
<?php else: ?>
    <?php foreach ($by as $ch => $pts): ?><div class="fs-11 text-muted mb-1"><?= e(HOLDER_CHANNELS[$ch] ?? $ch) ?></div><?= count($pts) > 1 ? svg_series($pts, 'holder-history-chart-' . $ch, 'Shares on the ' . (HOLDER_CHANNELS[$ch] ?? $ch) . ' by record date') : '' ?><?php endforeach; ?>
    <details class="mt-2 fs-12" <?= count($history) <= 3 ? 'open' : '' ?>><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="holder-history-table"><thead><tr><th>Record date</th><th>List</th><th class="text-end">Shares</th><th class="text-end">Accounts</th></tr></thead><tbody>
        <?php foreach (array_reverse($history) as $r): ?><tr><td><?= e(format_date($r['record_date'])) ?></td><td><?= e(HOLDER_CHANNELS[$r['channel']] ?? $r['channel']) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td><td class="text-end"><?= (int) $r['accounts'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
    <?= view('shared/source-line.php', ['source' => 'the NOBO lists and registers uploaded', 'date' => end($history)['record_date']]) ?>
<?php endif; ?>
