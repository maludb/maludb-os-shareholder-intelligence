<?php
/**
 * The ownership tiles (si_ownership_summary, db/018): each tile a number with its date and source — never a number without them.
 * Data: sum (the summary document, may be empty), counts (the latest outstanding and float rows with as_of and source), issuer
 */
$n = static fn ($v): string => $v === null ? '—' : number_format((float) $v, 0);
$pct = static fn ($v): string => $v === null ? '—' : number_format((float) $v, 2) . '%';
$srcName = ['filing' => 'EDGAR cover page', 'transfer_agent' => 'the transfer agent', 'dtc_letter' => 'a DTC letter', 'register' => 'the register', 'spr' => 'the DTC SPR', 'manual' => 'entered by a person'];
$short = $sum['short'] ?? null;
$dtc = $sum['dtc'] ?? null;
$rec = $sum['reconciliation'] ?? null;
$f13 = $sum['thirteen_f'] ?? null;
$bo = $sum['beneficial_owners'] ?? [];
$out = $counts['outstanding'] ?? null;
$flt = $counts['float'] ?? null;
$side = static function (?array $dtc, string $key): ?float {
    if ($dtc === null || empty($dtc['total'])) { return null; }
    return 100.0 * (float) ($dtc['by_side'][$key] ?? 0) / (float) $dtc['total'];
};
$tile = static function (string $id, string $label, string $value, string $source, ?string $date, string $tone = '', string $extra = ''): string {
    return '<div class="col-6 col-lg-4 col-xl-3"><div class="card h-100 mb-0" id="tile-' . e($id) . '"><div class="card-body">'
        . '<div class="fs-12 text-muted text-uppercase fw-semibold">' . e($label) . '</div>'
        . '<div class="si-tile-value fw-bold' . ($tone !== '' ? ' text-' . e($tone) : '') . '" id="tile-' . e($id) . '-value">' . $value . '</div>'
        . ($extra !== '' ? '<div class="fs-12">' . $extra . '</div>' : '')
        . view('shared/source-line.php', ['source' => $source, 'date' => $date])
        . '</div></div></div>';
};
?>
<div class="row g-3 mb-3" id="home-tiles">
    <?= $tile('outstanding', 'Outstanding', e($n($out['value'] ?? $sum['outstanding'] ?? null)), $out !== null ? ($srcName[$out['source']] ?? $out['source']) : 'no share count yet', $out['as_of'] ?? null) ?>
    <?= $tile('float', 'Float', e($n($flt['value'] ?? $sum['float'] ?? null)), $flt !== null ? ($srcName[$flt['source']] ?? $flt['source']) : 'no float recorded', $flt['as_of'] ?? null) ?>
    <?= $tile('short', 'Short, % of float', e($pct($short['pct_of_float'] ?? null)), 'FINRA short interest, settlement', $short['settlement_date'] ?? null,
        ($short['pct_of_float'] ?? 0) >= 20 ? 'danger' : '', $short !== null ? e($n($short['short_shares'])) . ' shares · ' . e($short['days_to_cover'] === null ? '—' : number_format((float) $short['days_to_cover'], 1)) . ' days to cover' : '') ?>
    <?= $tile('dtc', 'Held at DTC', e($n($dtc['total'] ?? null)), 'the DTC Security Position Report', $dtc['position_date'] ?? null, '',
        $dtc !== null ? 'Retail ' . e(number_format((float) $side($dtc, 'retail'), 1)) . '% · institutional ' . e(number_format((float) $side($dtc, 'institutional'), 1)) . '% · top 5 ' . e($pct($dtc['top5_pct'] ?? null)) : '') ?>
    <?php $recIncomplete = $rec !== null && $rec['difference_registered'] === null && $rec['difference_spr'] === null; ?>
    <?= $tile('reconciliation', 'Reconciliation', $rec === null ? '—' : ($recIncomplete ? '<span class="fs-6">Not enough to compare</span>' : ($rec['tied'] ? '<i class="feather-check-circle me-1"></i>Ties' : '<i class="feather-alert-triangle me-1"></i>Does not tie')),
        'the register and the SPR', $rec['as_of'] ?? null, $rec === null || $recIncomplete ? '' : ($rec['tied'] ? 'success' : 'danger'),
        $rec !== null && !$rec['tied'] && !$recIncomplete ? 'Register off by ' . e($n($rec['difference_registered'])) . ' · SPR off by ' . e($n($rec['difference_spr'])) : '') ?>
    <?= $tile('thirteen-f', '13F holders', e($n($f13['holders'] ?? null)), 'the SEC\'s 13F data set, quarter ending', $f13['period_end'] ?? null, '',
        $f13 !== null ? e($n($f13['shares'])) . ' shares · ' . e($pct($f13['pct_of_outstanding'] ?? null)) . ' of outstanding' : '') ?>
    <?= $tile('beneficial', '5% holders', e((string) count($bo)), 'Schedules 13D and 13G', $bo[0]['filed_on'] ?? null, '',
        $bo !== [] ? e($bo[0]['name']) . ' ' . e($pct($bo[0]['percent_of_class'] ?? null)) : '') ?>
    <?= $tile('insiders', 'Insiders hold', e($n($sum['insider_shares'] ?? null)), 'Forms 3, 4 and 5, after each one\'s last transaction', null) ?>
</div>
