<?php /** NOBO holders by custodian, beside the OBO remainder (screen `holders-custodians`). Data: security, dates, date, c, hasListing */
$kinds = ['ira' => 'IRA', 'tod' => 'TOD', 'trust' => 'Trust', 'joint' => 'Joint', 'custodial' => 'Custodial', 'corporate' => 'Corporate', 'individual' => 'Individual', 'unknown' => 'Unknown'];
$byP = [];
foreach ($c['nobo'] as $x) { $k = $x['participant_id'] ?? 0; $byP[$k]['name'] = $x['participant_id'] ? $x['number'] . ' ' . $x['name'] : 'No custodian in the lines'; $byP[$k]['id'] = $x['participant_id']; $byP[$k]['kinds'][$x['account_kind']] = $x; $byP[$k]['holders'] = ($byP[$k]['holders'] ?? 0) + (int) $x['holders']; $byP[$k]['shares'] = ($byP[$k]['shares'] ?? 0) + (float) $x['shares']; }
uasort($byP, static fn ($a, $b) => $b['shares'] <=> $a['shares']);
?>
<?= view('shared/header.php', ['id' => 'holders-custodians', 'title' => 'Custodians', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Custodians', null]]]) ?>
<div class="main-content" id="holders-custodians-content">
    <?= view('holders/partials/subnav.php', ['active' => 'custodians', 'security' => $security, 'dates' => $dates, 'date' => $date]) ?>
    <div class="row g-3">
        <div class="col-xl-6"><div class="card mb-0 h-100" id="custodians-nobo"><div class="card-header"><h5 class="card-title mb-0">NOBO holders by custodian</h5></div><div class="card-body p-0">
            <?php if ($byP === []): ?><div class="p-3 fs-12 text-muted" id="custodians-empty"><?= $date === null ? 'No NOBO list loaded.' : 'No holder on the list of ' . e(format_date($date)) . ' yet: its rows may still wait for an Analyst.' ?></div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="custodians-table"><thead class="thead-light"><tr><th>Custodian</th><th class="text-end">Holders</th><th class="text-end">Shares</th></tr></thead><tbody>
            <?php foreach ($byP as $p): ?><tr><td class="text-wrap"><?= $p['id'] ? hx_link('/participants/' . (int) $p['id'], e($p['name']), '') : e($p['name']) ?>
                <div class="fs-11 text-muted"><?= e(implode(' · ', array_map(static fn ($k, $x) => ($kinds[$k] ?? $k) . ' ' . (int) $x['holders'], array_keys($p['kinds']), $p['kinds']))) ?></div></td><td class="text-end"><?= number_format($p['holders']) ?></td><td class="text-end"><?= e(fmt_n($p['shares'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="p-3 fs-12 text-muted">The custodian is read from the registration's lines (an IRA's "CHARLES SCHWAB &amp; CO INC CUST"); a plain account names none.</div><?php endif; ?>
        </div></div></div>
        <div class="col-xl-6"><div class="card mb-0 h-100" id="custodians-obo"><div class="card-header"><h5 class="card-title mb-0">The OBO remainder</h5></div><div class="card-body p-0">
            <?php if (!$hasListing): ?><div class="p-3 fs-12 text-muted" id="custodians-obo-none">This list carries no Bank/Broker listing (Broadridge's names export does not): the OBO remainder for <?= e(format_date($date)) ?> is not computed.</div>
            <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="custodians-obo-table"><thead class="thead-light"><tr><th>Participant</th><th class="text-end">On the SPR</th><th class="text-end">NOBO</th><th class="text-end">OBO remainder</th></tr></thead><tbody>
                <?php foreach (array_slice($c['obo'], 0, 40) as $x): ?><tr><td class="text-wrap"><?= hx_link('/participants/' . (int) $x['participant_id'], e($x['number'] . ' ' . $x['name']), '') ?></td><td class="text-end"><?= e(fmt_n($x['spr_shares'])) ?></td><td class="text-end"><?= e(fmt_n($x['nobo_shares'])) ?></td>
                    <td class="text-end fw-semibold <?= $x['obo_remainder'] !== null && (float) $x['obo_remainder'] < 0 ? 'text-danger' : '' ?>"><?= e(fmt_n($x['obo_remainder'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="p-3"><?= view('shared/source-line.php', ['source' => 'the SPR position at or before the record date (' . format_date($c['obo'][0]['spr_date'] ?? null) . ') minus the Bank/Broker listing\'s NOBO shares; a negative figure is a data defect', 'date' => $date]) ?></div><?php endif; ?>
        </div></div></div>
    </div>
</div>
