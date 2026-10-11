<?php
/** A holder match an agent proposed: the raw row beside the candidate holder, the score and the evidence in words, Accept and Reject. PII roles only (the view gives no one else the row). Data: p, decide, tz, here */
$d = $p['detail'];
$pid = (int) $p['proposal_id'];
$row = $d['row'] ?? null;
?>
<div class="card mb-2" id="proposal-match-<?= $pid ?>" data-kind="holder_match">
    <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-people text-muted"></i><span class="fw-semibold">A <?= e(strtoupper((string) ($d['row_kind'] ?? ''))) ?> row may be this holder</span>
            <span class="fs-11 text-muted ms-auto text-nowrap"><?= e((string) ($p['issuer_name'] ?? '')) ?></span>
        </div>
        <?php if ($d !== []): ?>
        <div class="row g-2 mt-1">
            <div class="col-md-6"><div class="border rounded p-2 h-100" id="proposal-row-<?= $pid ?>"><div class="fs-11 text-muted">The row<?= $row ? ' · ' . e(format_date((string) $row['record_date'])) : '' ?></div>
                <div class="fs-13 text-break" style="white-space: pre-line"><?= e((string) ($row['registration'] ?? '—')) ?></div>
                <div class="fs-11 text-muted"><?= e((string) ($row['address'] ?? '')) ?><?= isset($row['shares']) ? ' · ' . e(fmt_n($row['shares'])) . ' shares' : '' ?></div></div></div>
            <div class="col-md-6"><div class="border rounded p-2 h-100" id="proposal-candidate-<?= $pid ?>"><div class="fs-11 text-muted">The holder <?= holder_kind_chip((string) $d['holder_kind']) ?></div>
                <div class="fs-13 text-break"><?= hx_link(with_back('/holders/' . (int) $d['holder_id'], $here), e((string) $d['display_name']), 'fw-semibold text-dark') ?></div>
                <div class="fs-11 text-muted"><?= e(trim(implode(', ', array_filter([$d['address_line'], trim($d['city'] . ' ' . $d['state'] . ' ' . $d['zip'])])))) ?> · <?= (int) $d['accounts_count'] ?> account(s)<?= $d['last_seen_on'] ? ' · ' . e(fmt_n($d['last_shares'])) . ' on ' . e(format_date((string) $d['last_seen_on'])) : '' ?></div></div></div>
        </div>
        <?= score_bar((float) $d['score'], 'proposal-score-' . $pid) ?>
        <div class="fs-12 mt-1"><span class="badge text-bg-primary me-1"><?= e((string) ($p['proposed_by_name'] ?? 'An agent')) ?>'s proposal</span><?= e((string) $d['evidence_words']) ?> <span class="text-muted">· <?= e(format_ts($p['proposed_at'], $tz, 'M j, g:i A')) ?></span></div>
        <?php if ($decide): ?>
        <div class="d-flex gap-2 mt-2">
            <form method="post" action="/holders/matches/accept.php" hx-post="/holders/matches/accept.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-success btn-touch w-100" id="proposal-accept-match-<?= $pid ?>"><i class="feather-check me-1"></i>Accept</button></form>
            <form method="post" action="/holders/matches/reject.php" hx-post="/holders/matches/reject.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="proposal-reject-match-<?= $pid ?>"><i class="feather-x me-1"></i>Reject</button></form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
