<?php /** The merge compare page (screen `holder-merge`). Data: h, cmp (null until a second holder is chosen), into */
$hid = (int) $h['holder_id'];
$side = static function (array $x, array $accounts, array $history, string $key) {
    $o = '<div class="card h-100 mb-0" id="merge-side-' . $key . '"><div class="card-header"><label class="d-flex align-items-center gap-2 mb-0 btn-touch" for="merge-survivor-' . $key . '">'
       . '<input type="radio" class="form-check-input" name="survivor" value="' . (int) $x['holder_id'] . '" id="merge-survivor-' . $key . '"' . ($key === 'a' ? ' checked' : '') . '> <span class="fw-semibold text-break">' . e((string) $x['display_name']) . '</span></label></div><div class="card-body fs-12"><dl class="row mb-2">';
    foreach (['Kind' => holder_kind_chip((string) $x['kind']) . ($x['entity_type'] ? ' ' . e($x['entity_type']) : ''), 'Street' => e((string) $x['address_line']), 'City' => e(trim($x['city'] . ' ' . $x['state'] . ' ' . $x['zip'])),
              'Listed' => e(format_date($x['first_seen_on']) . ' – ' . format_date($x['last_seen_on'])), 'Latest' => e(fmt_n($x['last_shares'])), 'Insider' => $x['is_insider'] ? 'yes' : 'no'] as $k => $v) {
        $o .= '<dt class="col-4 text-muted fw-normal">' . e($k) . '</dt><dd class="col-8 text-break">' . ($v === '' ? '—' : $v) . '</dd>';
    }
    $o .= '</dl><div class="fw-semibold mb-1">Accounts</div>' . view('holders/partials/accounts.php', ['accounts' => $accounts]);
    $o .= '<div class="fw-semibold mt-2 mb-1">Positions</div>';
    foreach (array_reverse($history) as $r) { $o .= '<div>' . e(format_date($r['record_date'])) . ' · ' . e(HOLDER_CHANNELS[$r['channel']] ?? $r['channel']) . ' · ' . e(fmt_n($r['shares'])) . '</div>'; }
    return $o . '</div></div>';
};
?>
<?= view('shared/header.php', ['id' => 'holder-merge', 'title' => 'Merge holders', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], [mb_strimwidth((string) $h['display_name'], 0, 30, '…'), '/holders/' . $hid], ['Merge', null]], 'back' => ['/holders/' . $hid, 'The holder']]) ?>
<div class="main-content" id="holder-merge-content">
    <form method="get" action="/holders/<?= $hid ?>/merge" class="card mb-3" id="merge-choose" hx-get="/holders/<?= $hid ?>/merge" hx-target="#page-content" hx-push-url="true"><div class="card-body">
        <label class="form-label" id="merge-into-label" for="merge-into-open">The other holder</label>
        <?= picker_field(['id' => 'merge-into', 'name' => 'into', 'source' => 'holder', 'value' => $cmp !== null ? (string) $into : '', 'labelledby' => 'merge-into-label', 'required' => true, 'title' => 'The holder to compare with ' . $h['display_name'],
            'params' => ['issuer' => (int) $h['issuer_id']]]) ?>
        <div class="form-text">Holders of the same issuer; a person is never merged with an entity.</div>
    </div><div class="card-footer"><button type="submit" class="btn btn-light btn-touch w-100" id="merge-compare-btn">Compare</button></div></form>
    <?php if ($cmp !== null): ?>
    <form method="post" action="/holders/merge.php" hx-post="/holders/merge.php" hx-target="#flash" id="merge-compare" hx-confirm="Merge the two holders? The one not chosen goes to the tombstone with its accounts moved."><?= csrf_field() ?>
        <p class="fs-12 text-muted">Choose the holder that stays: it keeps its own values and takes the other's where it has none; every account, position and row moves to it.</p>
        <div class="row g-3 mb-3"><div class="col-md-6"><?= $side($cmp['a'], $cmp['a_accounts'], $cmp['a_history'], 'a') ?></div><div class="col-md-6"><?= $side($cmp['b'], $cmp['b_accounts'], $cmp['b_history'], 'b') ?></div></div>
        <input type="hidden" name="loser" value="<?= (int) $cmp['b']['holder_id'] ?>" id="merge-loser">
        <button type="submit" class="btn btn-danger btn-touch w-100" id="merge-btn"><i class="feather-git-merge me-1"></i>Merge</button>
    </form>
    <script>document.getElementById('merge-compare').addEventListener('change', function (e) { if (e.target.name !== 'survivor') return; var a = <?= $hid ?>, b = <?= (int) $cmp['b']['holder_id'] ?>; document.getElementById('merge-loser').value = Number(e.target.value) === a ? b : a; });</script>
    <?php endif; ?>
</div>
