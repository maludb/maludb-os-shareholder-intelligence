<?php /** One instrument (screen `dilution-view`). Data: d, notes, tz, back, notice */
$did = (int) $d['instrument_id'];
$money = static fn ($v, string $c): string => $v === null ? '—' : ($c === 'USD' ? '$' : $c . ' ') . number_format((float) $v, 0);
$human = (current_member()['member_kind'] ?? '') === 'human';
?>
<?= view('shared/header.php', ['id' => 'dilution-view', 'title' => DILUTION_KINDS[$d['kind']] ?? $d['kind'], 'crumbs' => [['Home', '/'], ['Dilution', '/dilution'], ['Instrument ' . $did, null]], 'back' => $back,
    'action' => has_right('events.write') && $human ? hx_link('/dilution/' . $did . '/edit', '<i class="feather-edit-2 me-1"></i>' . ($d['status'] === 'proposed' ? 'Confirm or change' : 'Edit'), 'btn btn-light btn-touch', 'id="dilution-edit-btn"') : '']) ?>
<div class="main-content" id="dilution-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8"><div class="card mb-3" id="dilution-terms"><div class="card-body fs-12"><dl class="row mb-0">
            <dt class="col-5 col-md-3 text-muted fw-normal">Status</dt><dd class="col-7 col-md-9"><?= dilution_status_chip((string) $d['status']) ?><?= $d['floating_price'] ? ' <span class="badge text-bg-danger">floating price</span>' : '' ?><?= $d['confirmed_by_name'] ? ' <span class="text-muted">confirmed by ' . e((string) $d['confirmed_by_name']) . '</span>' : '' ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Counterparty</dt><dd class="col-7 col-md-9"><?= e((string) ($d['counterparty'] ?? '—')) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Facility</dt><dd class="col-7 col-md-9"><?= e($money($d['facility_amount'], (string) $d['currency'])) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Remaining</dt><dd class="col-7 col-md-9"><?= e($money($d['remaining_amount'], (string) $d['currency'])) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Price terms</dt><dd class="col-7 col-md-9 text-break"><?= e((string) ($d['price_terms'] ?? '—')) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Most shares</dt><dd class="col-7 col-md-9"><?= e(fmt_n($d['shares_issuable_max'])) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">Expires</dt><dd class="col-7 col-md-9"><?= e(format_date($d['expires_on'])) ?></dd>
            <dt class="col-5 col-md-3 text-muted fw-normal">The filing</dt><dd class="col-7 col-md-9"><?= $d['filing_id'] ? hx_link('/filings/' . (int) $d['filing_id'] . ($d['watch_term'] ? '?q=' . rawurlencode((string) $d['watch_term']) : ''), e(($d['form'] ?? 'filing') . ' of ' . format_date($d['filing_filed_on'])), 'fw-semibold', 'id="dilution-filing-link"') : '—' ?></dd>
            <?php if ($d['watch_term']): ?><dt class="col-5 col-md-3 text-muted fw-normal">Found by</dt><dd class="col-7 col-md-9">the watch, on “<?= e((string) $d['watch_term']) ?>”</dd><?php endif; ?>
            <?php if ($d['notes']): ?><dt class="col-5 col-md-3 text-muted fw-normal">About</dt><dd class="col-7 col-md-9 text-break"><?= nl2br(e((string) $d['notes'])) ?></dd><?php endif; ?>
        </dl></div></div>
        <div class="card mb-3" id="dilution-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'dilution_instrument', 'record' => $did, 'notes' => $notes, 'here' => '/dilution/' . $did, 'tz' => $tz]) ?></div></div></div>
        <div class="col-xl-4"><div class="card mb-3" id="dilution-signals"><div class="card-body fs-12 text-muted">The signals that name this instrument appear here once the rules run (slice 7).</div></div>
            <?php if (has_right('events.write')): ?><form method="post" action="/dilution/delete.php" hx-post="/dilution/delete.php" hx-target="#flash" hx-confirm="Delete this instrument? It is gone for good."><?= csrf_field() ?><input type="hidden" name="instrument" value="<?= $did ?>">
                <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="dilution-delete-btn">Delete</button></form><?php endif; ?></div>
    </div>
</div>
