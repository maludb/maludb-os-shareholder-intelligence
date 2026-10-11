<?php /** A pack's section: the short side — FINRA's short interest as a share of float, and the last settlements. Data: d, ctx, title */ ?>
<section id="pack-section-short_side"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php elseif ($d['now'] === null && $d['series'] === []): ?><p class="text-muted">FINRA has published no short interest for this security yet.</p>
<?php else: ?>
    <?php if ($d['now']): $n = $d['now']; ?><p>At the <?= e(format_date($n['settlement_date'])) ?> settlement <strong><?= e(fmt_n($n['short_shares'])) ?></strong> shares were short<?= $n['pct_of_float'] !== null ? ', <strong>' . e(number_format((float) $n['pct_of_float'], 2)) . '%</strong> of the float' : ', and no float count is on record to compare it with' ?><?= $n['days_to_cover'] !== null ? '; ' . e(number_format((float) $n['days_to_cover'], 2)) . ' days to cover' : '' ?>.</p><?php endif; ?>
    <?php if ($d['series'] !== []): ?><table><thead><tr><th>Settlement</th><th class="text-end">Short shares</th><th class="text-end">Change</th><th class="text-end">Days to cover</th></tr></thead><tbody>
    <?php foreach ($d['series'] as $x): ?><tr><td><?= e(format_date($x['settlement_date'])) ?></td><td class="text-end"><?= e(fmt_n($x['short_shares'])) ?></td><td class="text-end"><?= e(fmt_n($x['change_shares'])) ?></td><td class="text-end"><?= $x['days_to_cover'] === null ? '—' : e(number_format((float) $x['days_to_cover'], 2)) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'FINRA\'s consolidated short interest (published about seven market days after each settlement; a revision is a new row)']) ?>
<?php endif; ?></section>
