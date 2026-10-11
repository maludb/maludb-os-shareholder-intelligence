<?php
/**
 * A person's disposition of a live signal (signals.md §4): Acknowledge (known or watching), Dismiss with a reason — explained by an event chosen
 * with the picker over the issuer's events around the window, a data defect, a false positive, another reason said in the note — or Escalate to
 * the IR Manager. People only; an agent proposes. Data: s, here
 */
$sid = (int) $s['signal_id'];
$hidden = csrf_field() . '<input type="hidden" name="signal" value="' . $sid . '"><input type="hidden" name="return_to" value="' . e($here) . '">';
?>
<div class="card mb-3" id="signal-dispose"><div class="card-header"><h5 class="card-title mb-0">Dispose of the signal</h5></div><div class="card-body">
    <p class="fs-12 text-muted">A signal advises; a person decides what it means. Every disposition keeps its reason, and the signal stays readable in the history.</p>
    <form method="post" action="/signals/acknowledge.php" hx-post="/signals/acknowledge.php" hx-target="#flash" class="border rounded p-2 mb-3" id="signal-ack-form"><?= $hidden ?>
        <div class="fw-semibold fs-12 mb-2">Acknowledge</div>
        <div class="d-flex gap-3 flex-wrap mb-2">
            <?php foreach (SIGNAL_ACK_REASONS as $k => $w): ?><div class="form-check"><input class="form-check-input" type="radio" name="reason" value="<?= e($k) ?>" id="signal-ack-<?= e($k) ?>"<?= $k === 'known' ? ' checked' : '' ?>><label class="form-check-label fs-12" for="signal-ack-<?= e($k) ?>"><?= e($w) ?></label></div><?php endforeach; ?>
        </div>
        <label class="form-label fs-12 text-muted" for="signal-ack-note">A note (optional)</label>
        <textarea name="note" id="signal-ack-note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000"></textarea>
        <button type="submit" class="btn btn-info btn-sm btn-touch" id="signal-ack-btn"><i class="feather-eye me-1"></i>Acknowledge</button>
    </form>
    <form method="post" action="/signals/dismiss.php" hx-post="/signals/dismiss.php" hx-target="#flash" class="border rounded p-2 mb-3" id="signal-dismiss-form"><?= $hidden ?>
        <div class="fw-semibold fs-12 mb-2">Dismiss</div>
        <label class="form-label fs-12 text-muted" for="signal-dismiss-reason">Why</label>
        <select name="reason" id="signal-dismiss-reason" class="form-select form-select-sm mb-2" required>
            <?php foreach (SIGNAL_DISMISS_REASONS as $k => $w): ?><option value="<?= e($k) ?>"><?= e($w) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label fs-12 text-muted" id="signal-dismiss-event-label" for="signal-dismiss-event-open">The explaining event (required when an event explains it)</label>
        <?= picker_field(['id' => 'signal-dismiss-event', 'name' => 'explaining_event', 'source' => 'event', 'value' => '', 'labelledby' => 'signal-dismiss-event-label',
            'title' => 'The issuer\'s events around the signal\'s window', 'params' => ['signal' => $sid]]) ?>
        <label class="form-label fs-12 text-muted mt-2" for="signal-dismiss-note">A note (required for another reason)</label>
        <textarea name="note" id="signal-dismiss-note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000"></textarea>
        <button type="submit" class="btn btn-secondary btn-sm btn-touch" id="signal-dismiss-btn"><i class="feather-x me-1"></i>Dismiss</button>
    </form>
    <?php if ($s['status'] === 'open'): ?>
    <form method="post" action="/signals/escalate.php" hx-post="/signals/escalate.php" hx-target="#flash" class="border rounded p-2" id="signal-escalate-form"><?= $hidden ?>
        <div class="fw-semibold fs-12 mb-2">Escalate to the IR Manager</div>
        <label class="form-label fs-12 text-muted" for="signal-escalate-note">A note to the IR Manager</label>
        <textarea name="note" id="signal-escalate-note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000"></textarea>
        <button type="submit" class="btn btn-danger btn-sm btn-touch" id="signal-escalate-btn"><i class="feather-alert-triangle me-1"></i>Escalate</button>
    </form>
    <?php endif; ?>
</div></div>
