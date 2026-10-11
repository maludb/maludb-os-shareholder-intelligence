<?php /** A pack's section: the SEC's fails to deliver and the Reg SHO threshold lists. Data: d, ctx, title */ ?>
<section id="pack-section-fails_threshold"><h3><?= e($title) ?></h3>
<?php if (isset($d['none'])): ?><p class="text-muted"><?= e($d['none']) ?></p>
<?php else: ?>
    <p><?php if ($d['status'] && $d['status']['on_list']): ?>The security is <strong>on the <?= e((string) $d['status']['list']) ?> threshold list</strong>, since <?= e(format_date($d['status']['since'])) ?> (<?= (int) $d['status']['consecutive_days'] ?> list days in a row).
        <?php else: ?>The security is on no Regulation SHO threshold list<?= $d['status'] && $d['status']['latest_list_date'] ? ' as of ' . e(format_date($d['status']['latest_list_date'])) : '' ?>.<?php endif; ?></p>
    <?php if ($d['runs'] !== []): ?><table><caption class="text-start fw-semibold">Time on a list</caption><thead><tr><th>List</th><th>From</th><th>To</th><th class="text-end">List days</th></tr></thead><tbody>
    <?php foreach ($d['runs'] as $x): ?><tr><td><?= e((string) $x['list']) ?></td><td><?= e(format_date($x['from_date'])) ?></td><td><?= $x['current'] ? 'still on' : e(format_date($x['to_date'])) ?></td><td class="text-end"><?= (int) $x['list_days'] ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    <?php if ($d['fails'] === []): ?><p class="text-muted">The SEC's fails files hold no fail for the period.</p>
    <?php else: ?><table><caption class="text-start fw-semibold">Fails to deliver</caption><thead><tr><th>Settlement</th><th class="text-end">Shares failed</th><th class="text-end">% of outstanding</th><th class="text-end">60-day median</th></tr></thead><tbody>
    <?php foreach ($d['fails'] as $x): ?><tr><td><?= e(format_date($x['settlement_date'])) ?></td><td class="text-end"><?= e(fmt_n($x['quantity'])) ?></td><td class="text-end"><?= $x['pct_of_outstanding'] === null ? '—' : e(number_format((float) $x['pct_of_outstanding'], 3)) . '%' ?></td><td class="text-end"><?= e(fmt_n($x['median_60'])) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'the SEC\'s fails-to-deliver files and the Nasdaq, NYSE and FINRA threshold lists']) ?>
<?php endif; ?></section>
