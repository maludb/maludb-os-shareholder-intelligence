<?php
/** A holder's kind an agent (or the worker) proposed: the holder, the kind and entity type proposed, the evidence; Accept and Reject. Data: p, decide, tz, here */
$d = $p['detail'];
$pid = (int) $p['proposal_id'];
?>
<div class="card mb-2" id="proposal-kind-<?= $pid ?>" data-kind="holder_kind">
    <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-tag text-muted"></i>
            <?php if ($d !== []): ?><?= hx_link(with_back('/holders/' . (int) $d['holder_id'], $here), e((string) $d['display_name']), 'fw-semibold text-dark text-break') ?> <span class="fs-12 text-muted">is now</span> <?= holder_kind_chip((string) $d['holder_kind']) ?>
                <span class="fs-12 text-muted">proposed</span> <?= holder_kind_chip((string) $d['kind']) ?><?= $d['entity_type'] ? ' <span class="badge text-bg-light">' . e((string) $d['entity_type']) . '</span>' : '' ?><?php endif; ?>
            <span class="fs-11 text-muted ms-auto text-nowrap"><?= e((string) ($p['issuer_name'] ?? '')) ?></span>
        </div>
        <div class="fs-12 mt-1"><span class="badge text-bg-primary me-1"><?= e((string) ($p['proposed_by_name'] ?? 'The worker')) ?>'s proposal</span><?= e((string) ($d['evidence'] ?? '')) ?> <span class="text-muted">· <?= e(format_ts($p['proposed_at'], $tz, 'M j, g:i A')) ?></span></div>
        <?php if ($decide && $d !== []): ?>
        <div class="d-flex gap-2 mt-2">
            <form method="post" action="/holders/kind.php" hx-post="/holders/kind.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="holder" value="<?= (int) $d['holder_id'] ?>"><input type="hidden" name="kind" value="<?= e((string) $d['kind']) ?>">
                <input type="hidden" name="entity_type" value="<?= e((string) ($d['entity_type'] ?? '')) ?>"><input type="hidden" name="proposal" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-success btn-touch w-100" id="proposal-accept-kind-<?= $pid ?>"><i class="feather-check me-1"></i>Accept</button></form>
            <form method="post" action="/holders/kind.php" hx-post="/holders/kind.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="holder" value="<?= (int) $d['holder_id'] ?>"><input type="hidden" name="proposal" value="<?= $pid ?>">
                <input type="hidden" name="decision" value="reject"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="proposal-reject-kind-<?= $pid ?>"><i class="feather-x me-1"></i>Reject</button></form>
        </div>
        <?php endif; ?>
    </div>
</div>
