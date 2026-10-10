<?php /** One candidate of a queued row: the score as a bar, the evidence in words, the candidate's latest, Accept and Reject. Data: c, r, here, first */
$pid = (int) $c['proposal_id'];
$ev = match_evidence_words(json_decode((string) $c['evidence'], true) ?: [], (float) $c['score']);
?>
<div class="border rounded p-2 mb-2" id="queue-candidate-<?= $pid ?>">
    <div class="d-flex align-items-start justify-content-between gap-2"><div class="min-w-0"><?= hx_link(with_back('/holders/' . (int) $c['holder_id'], $here), e((string) $c['display_name']), 'fw-semibold text-dark text-break') ?>
        <div class="fs-11 text-muted"><?= e(trim(implode(', ', array_filter([$c['address_line'], trim($c['city'] . ' ' . $c['state'] . ' ' . $c['zip'])])))) ?></div></div><?= holder_kind_chip((string) $c['kind']) ?></div>
    <?= score_bar((float) $c['score'], 'queue-score-' . $pid) ?>
    <div class="fs-12 mt-1"><?= $c['proposed_by'] !== null ? '<span class="badge text-bg-primary me-1">Analyst\'s proposal</span>' : '' ?><?= e($ev) ?></div>
    <div class="fs-11 text-muted"><?= (int) $c['accounts_count'] ?> account(s)<?= $c['last_seen_on'] ? ' · ' . e(fmt_n($c['last_shares'])) . ' on ' . e(format_date($c['last_seen_on'])) : '' ?></div>
    <div class="d-flex gap-2 mt-2">
        <form method="post" action="/holders/matches/accept.php" hx-post="/holders/matches/accept.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-success btn-touch w-100" id="queue-accept-<?= $pid ?>"<?= $first ? ' data-key="a"' : '' ?>><i class="feather-check me-1"></i>Accept</button></form>
        <form method="post" action="/holders/matches/reject.php" hx-post="/holders/matches/reject.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="queue-reject-<?= $pid ?>"<?= $first ? ' data-key="r"' : '' ?>><i class="feather-x me-1"></i>Reject</button></form>
    </div>
</div>
