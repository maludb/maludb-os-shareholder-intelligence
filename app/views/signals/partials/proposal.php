<?php
/**
 * An agent's proposal on an open signal (signals.md §4): "<who> proposes: dismiss — explained by <the event>", one sentence of why, and for a person
 * who works signals **Take it** (posts the matching disposition with the proposal's words) and **Not this** (the disposition form). Data: s, may, here, idSuffix?
 */
$sid = (int) $s['signal_id'];
$verb = SIGNAL_PROPOSAL_REASONS[$s['proposal_reason']] ?? 'dismiss';
$why = SIGNAL_REASON_WORDS[$s['proposal_reason']] ?? (string) $s['proposal_reason'];
$ev = $s['proposal_event_title'] !== null ? ' — ' . $s['proposal_event_title'] . ' of ' . format_date((string) $s['proposal_event_on']) : '';
$sfx = (string) ($idSuffix ?? '');
$live = in_array($s['status'], ['open', 'escalated'], true);
?>
<div class="border border-primary-subtle rounded p-2 mt-2 bg-primary-subtle" id="signal-proposal<?= e($sfx) ?>" data-reason="<?= e((string) $s['proposal_reason']) ?>">
    <div class="fs-12"><i class="feather-inbox me-1"></i><span class="fw-semibold"><?= e((string) ($s['proposal_by_name'] ?? 'An agent')) ?> proposes:</span> <?= e($verb) ?> — <?= e($why . $ev) ?></div>
    <?php if (($s['proposal_note'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1 text-break">“<?= e((string) $s['proposal_note']) ?>”</div><?php endif; ?>
    <?php if ($may && $live && ($verb !== 'escalate' || $s['status'] === 'open')): ?>
    <div class="d-flex gap-2 mt-2 flex-wrap">
        <form method="post" action="/signals/<?= e($verb) ?>.php" hx-post="/signals/<?= e($verb) ?>.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?>
            <input type="hidden" name="signal" value="<?= $sid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <?php if ($verb !== 'escalate'): ?><input type="hidden" name="reason" value="<?= e((string) $s['proposal_reason']) ?>"><?php endif; ?>
            <?php if ($s['proposal_event_id'] !== null): ?><input type="hidden" name="explaining_event" value="<?= (int) $s['proposal_event_id'] ?>"><?php endif; ?>
            <input type="hidden" name="note" value="<?= e((string) ($s['proposal_note'] ?? '')) ?>">
            <button type="submit" class="btn btn-primary btn-sm btn-touch" id="signal-take-<?= $sid ?><?= e($sfx) ?>"><i class="feather-check me-1"></i>Take it</button>
        </form>
        <?= hx_link('/signals/' . $sid . '#signal-dispose', 'Not this', 'btn btn-light btn-sm btn-touch', 'id="signal-not-this-' . $sid . e($sfx) . '"') ?>
    </div>
    <?php endif; ?>
</div>
