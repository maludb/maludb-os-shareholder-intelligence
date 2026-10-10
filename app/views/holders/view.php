<?php /** One holder (screen `holder-view`). Data: h, accounts, history, kindProposals, notes, tz, back, notice */
$hid = (int) $h['holder_id'];
$pii = si_sees_pii();
$name = $h['pii_withheld'] ? 'Individual holder' : (string) $h['display_name'];
$actions = '';
if (has_right('matches.decide') && !$h['pii_withheld']) { $actions .= hx_link('/holders/' . $hid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="holder-edit-btn"'); }
if (has_right('holders.merge')) { $actions .= ' ' . hx_link('/holders/' . $hid . '/merge', '<i class="feather-git-merge me-1"></i>Merge into…', 'btn btn-light btn-touch', 'id="holder-merge-btn"'); }
?>
<?= view('shared/header.php', ['id' => 'holder-view', 'title' => $name, 'crumbs' => [['Home', '/'], ['Holders', '/holders'], [$h['pii_withheld'] ? 'A holder' : mb_strimwidth($name, 0, 40, '…'), null]], 'back' => $back, 'action' => $actions]) ?>
<div class="main-content" id="holder-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8 order-2 order-xl-1">
            <div class="card mb-3" id="holder-history"><div class="card-header"><h5 class="card-title mb-0">Positions by record date</h5></div><div class="card-body"><?= view('holders/partials/history-chart.php', ['history' => $history]) ?></div></div>
            <div class="card mb-3" id="holder-accounts"><div class="card-header"><h5 class="card-title mb-0">Accounts</h5></div><div class="card-body"><?= view('holders/partials/accounts.php', ['accounts' => $accounts]) ?></div></div>
            <?php if (!$h['pii_withheld']): ?><div class="card mb-3" id="holder-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'holder', 'record' => $hid, 'notes' => $notes, 'here' => '/holders/' . $hid, 'tz' => $tz]) ?></div></div><?php endif; ?>
        </div>
        <div class="col-xl-4 order-1 order-xl-2">
            <div class="card mb-3" id="holder-facts"><div class="card-body fs-12"><dl class="row mb-0">
                <dt class="col-5 text-muted fw-normal">Kind</dt><dd class="col-7" id="holder-kind"><?= holder_kind_chip((string) $h['kind']) ?><?= $h['entity_type'] ? ' <span class="text-muted">' . e($h['entity_type']) . '</span>' : '' ?></dd>
                <dt class="col-5 text-muted fw-normal">Address</dt><dd class="col-7" id="holder-address"><?= $h['pii_withheld'] ? view('holders/partials/pii-withheld.php') . '<div class="text-muted">' . e(trim(($h['state'] ?? '') . ' ' . ($h['zip'] ? $h['zip'] . '…' : ''))) . '</div>'
                    : (e(trim(implode(', ', array_filter([$h['address_line'], $h['city'], trim(($h['state'] ?? '') . ' ' . ($h['zip'] ?? '')), $h['country'] !== 'US' ? $h['country'] : null])))) ?: '—') ?></dd>
                <dt class="col-5 text-muted fw-normal">Latest</dt><dd class="col-7"><?= e(fmt_n($h['last_shares'])) ?><?= $h['last_seen_on'] ? ' on ' . e(format_date($h['last_seen_on'])) : '' ?></dd>
                <dt class="col-5 text-muted fw-normal">Listed since</dt><dd class="col-7"><?= e(format_date($h['first_seen_on'])) ?></dd>
                <?php if ($h['is_insider']): ?><dt class="col-5 text-muted fw-normal">Insider</dt><dd class="col-7 text-danger">yes<?= $h['insider_cik'] ? ' · CIK ' . e(ltrim((string) $h['insider_cik'], '0')) : '' ?></dd><?php endif; ?>
                <?php if (!$h['pii_withheld'] && $h['notes']): ?><dt class="col-5 text-muted fw-normal">About</dt><dd class="col-7 text-break"><?= nl2br(e((string) $h['notes'])) ?></dd><?php endif; ?>
            </dl></div></div>
            <?php if (has_right('matches.decide')): ?>
            <form method="post" action="/holders/kind.php" hx-post="/holders/kind.php" hx-target="#flash" class="card mb-3" id="holder-kind-form"><?= csrf_field() ?><input type="hidden" name="holder" value="<?= $hid ?>">
                <div class="card-header"><h5 class="card-title mb-0">Kind</h5></div><div class="card-body">
                <?php foreach ($kindProposals as $p): ?><div class="alert alert-info fs-12" id="holder-kind-proposal-<?= (int) $p['proposal_id'] ?>"><span class="badge text-bg-primary me-1">Analyst's proposal</span><?= e(HOLDER_KINDS[$p['kind']] ?? $p['kind']) ?><?= $p['entity_type'] ? ' (' . e($p['entity_type']) . ')' : '' ?><?= $p['evidence'] ? ': ' . e((string) $p['evidence']) : '' ?></div><?php endforeach; ?>
                <label class="form-label fs-12" for="holder-kind-field-kind">The holder is</label>
                <select name="kind" id="holder-kind-field-kind" class="form-select btn-touch mb-2"><?php foreach (HOLDER_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $h['kind'] === $k || (($kindProposals[0]['kind'] ?? null) === $k && $h['kind'] === 'unknown') ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12" for="holder-kind-field-entity_type">An entity's type</label>
                <select name="entity_type" id="holder-kind-field-entity_type" class="form-select btn-touch"><option value="">—</option><?php foreach (HOLDER_ENTITY_TYPES as $t): ?><option value="<?= e($t) ?>" <?= $h['entity_type'] === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?></select>
                </div><div class="card-footer"><button type="submit" class="btn btn-light btn-touch w-100" id="holder-kind-save-btn">Set the kind</button></div></form>
            <?php endif; ?>
            <?php if (!$h['pii_withheld']): ?><div class="card mb-3" id="holder-tags"><div class="card-header"><h5 class="card-title mb-0">Tags</h5></div><div class="card-body"><?= view('shared/tags.php', ['kind' => 'holder', 'record' => $hid, 'tags' => pg_text_array((string) $h['tags']), 'here' => '/holders/' . $hid]) ?></div></div><?php endif; ?>
            <?php if (has_right('holders.merge')): ?><form method="post" action="/holders/delete.php" hx-post="/holders/delete.php" hx-target="#flash" hx-confirm="Move this holder to the tombstone? A manager restores it." class="mb-3"><?= csrf_field() ?><input type="hidden" name="holder" value="<?= $hid ?>"><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="holder-delete-btn">Delete</button></form><?php endif; ?>
        </div>
    </div>
</div>
