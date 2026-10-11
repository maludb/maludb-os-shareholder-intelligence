<?php /** One signal (screen `signal-view`). Data: sig, s, chart, may, canNote, canDelete, here, tz, back, notice */
$sid = (int) $s['signal_id'];
$ev = $sig['evidence'];
$live = in_array($s['status'], ['open', 'escalated'], true);
$key = (string) $s['rule_key'];
$partial = preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) && is_file(dirname(__DIR__) . '/signals/evidence/' . $key . '.php') ? 'signals/evidence/' . $key . '.php' : null;
?>
<?= view('shared/header.php', ['id' => 'signal-view', 'title' => (string) $s['rule_name'], 'crumbs' => [['Home', '/'], ['Signals', '/signals'], ['Signal ' . $sid, null]], 'back' => $back]) ?>
<div class="main-content" id="signal-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3 border-start border-4 border-<?= e(['high' => 'danger', 'watch' => 'warning', 'info' => 'info'][$s['severity']] ?? 'secondary') ?>" id="signal-head"><div class="card-body">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?= signal_severity_chip((string) $s['severity']) ?>
                    <?= view('signals/partials/class-chip.php', ['class' => $s['evidence_class']]) ?>
                    <?= signal_status_chip((string) $s['status'], ' id="signal-status"') ?>
                    <span class="fs-11 text-muted ms-auto">raised <?= e(format_date((string) $s['raised_on'])) ?><?= (int) $s['repeat_count'] > 0 ? ' · fired again ' . (int) $s['repeat_count'] . '×' : '' ?></span>
                </div>
                <p class="mt-2 mb-0 text-break" id="signal-summary"><?= e((string) $s['summary']) ?></p>
                <?= view('signals/partials/basis.php', ['basis' => $s['basis'], 'class' => $s['evidence_class']]) ?>
                <dl class="row fs-12 mb-0 mt-2">
                    <dt class="col-5 col-md-3 text-muted fw-normal">Security</dt><dd class="col-7 col-md-9"><?= e((string) $s['issuer_name']) ?> — <?= e((string) $s['security_name']) ?><?= $s['ticker'] ? ' (' . e((string) $s['ticker']) . ')' : '' ?></dd>
                    <dt class="col-5 col-md-3 text-muted fw-normal">The window</dt><dd class="col-7 col-md-9"><?= e(format_date((string) $s['window_from'])) ?><?= $s['window_to'] !== $s['window_from'] ? ' – ' . e(format_date((string) $s['window_to'])) : '' ?></dd>
                    <?php if ($s['threshold'] !== null || $s['value'] !== null): ?><dt class="col-5 col-md-3 text-muted fw-normal">Value / threshold</dt><dd class="col-7 col-md-9"><?= e(sig_n($s['value'], 2)) ?> against <?= e(sig_n($s['threshold'], 2)) ?></dd><?php endif; ?>
                    <dt class="col-5 col-md-3 text-muted fw-normal"><?= $live ? 'Expires' : 'Would have expired' ?></dt><dd class="col-7 col-md-9"><?= e(format_date((string) $s['expires_on'])) ?></dd>
                </dl>
            </div></div>
            <?php if ($s['proposal_reason'] !== null): ?><?= view('signals/partials/proposal.php', ['s' => $s, 'may' => $may, 'here' => $here]) ?><div class="mb-3"></div><?php endif; ?>
            <div class="card mb-3" id="signal-evidence"><div class="card-header"><h5 class="card-title mb-0">The evidence</h5><span class="fs-11 text-muted ms-2">what the rule saw, as it wrote it</span></div><div class="card-body">
                <?= $partial !== null ? view($partial, ['ev' => $ev, 's' => $s]) : evidence_table(array_map(static fn ($k, $v) => [str_replace('_', ' ', (string) $k), is_scalar($v) ? (string) $v : json_encode($v)], array_keys($ev), $ev)) ?>
            </div></div>
            <?php if ($chart !== ''): ?><div class="card mb-3" id="signal-chart"><div class="card-header"><h5 class="card-title mb-0">The window</h5></div><div class="card-body"><?= $chart ?></div></div><?php endif; ?>
            <div class="card mb-3" id="signal-events"><div class="card-header"><h5 class="card-title mb-0">Events around the window</h5></div><div class="card-body fs-12">
                <?php if ($sig['events'] === []): ?><div class="text-muted" id="signal-events-empty">No event is recorded for the issuer around this window. An event that explains the signal is recorded first (Events), then chosen when dismissing.</div>
                <?php else: foreach ($sig['events'] as $e): ?><div class="d-flex gap-2 mb-1" id="signal-event-<?= (int) $e['event_id'] ?>"><span class="text-muted text-nowrap"><?= e(format_date((string) $e['on_date'])) ?></span>
                    <span class="badge bg-<?= e(['high' => 'danger', 'watch' => 'warning'][$e['severity']] ?? 'light text-dark') ?>"><?= e(str_replace('_', ' ', (string) $e['kind'])) ?></span>
                    <span class="text-break"><?= $e['filing_id'] ? hx_link('/filings/' . (int) $e['filing_id'], e((string) $e['title'])) : e((string) $e['title']) ?></span></div><?php endforeach; endif; ?>
            </div></div>
            <div class="card mb-3" id="signal-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
                <?php if ($canNote): ?>
                <form method="post" action="/signals/note.php" hx-post="/signals/note.php" hx-target="#flash" class="mb-3" id="signal-note-form"><?= csrf_field() ?><input type="hidden" name="signal" value="<?= $sid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <label class="form-label fs-12 text-muted" for="signal-note-body">Add a note</label>
                    <textarea name="body" id="signal-note-body" class="form-control mb-2" rows="2" maxlength="4000" required></textarea>
                    <button type="submit" class="btn btn-light btn-touch" id="signal-note-btn"><i class="feather-message-square me-1"></i>Add the note</button></form>
                <?php endif; ?>
                <?php if ($sig['notes'] === []): ?><div class="fs-12 text-muted" id="notes-empty">No notes yet.</div><?php endif; ?>
                <?php foreach ($sig['notes'] as $n): ?><div class="border-start border-3 ps-3 mb-3" id="note-<?= (int) $n['note_id'] ?>"><div class="fs-11 text-muted"><?= e($n['author'] ?? 'Someone') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, Y g:i A')) ?></div><div class="si-note-body"><?= nl2br(e($n['body'])) ?></div></div><?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-xl-4">
            <?php if ($live && $may): ?>
                <?= view('signals/partials/disposition-form.php', ['s' => $s, 'here' => $here]) ?>
            <?php elseif (!$live): ?>
                <div class="card mb-3" id="signal-disposition"><div class="card-header"><h5 class="card-title mb-0"><?= $s['status'] === 'expired' ? 'Expired' : 'The disposition' ?></h5></div><div class="card-body fs-12">
                    <?php if ($s['status'] === 'expired'): ?><div class="text-muted">No one disposed of it before its window and grace ran out; it stays readable here and in the history.</div>
                    <?php else: ?><dl class="row mb-0">
                        <dt class="col-5 text-muted fw-normal">Status</dt><dd class="col-7"><?= signal_status_chip((string) $s['status']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Why</dt><dd class="col-7"><?= e(SIGNAL_REASON_WORDS[$s['disposition_reason']] ?? (string) $s['disposition_reason']) ?></dd>
                        <?php if ($s['event_title'] !== null): ?><dt class="col-5 text-muted fw-normal">The event</dt><dd class="col-7 text-break" id="signal-disposition-event"><?= e((string) $s['event_title']) ?> (<?= e(format_date((string) $s['event_on'])) ?>)</dd><?php endif; ?>
                        <dt class="col-5 text-muted fw-normal">By</dt><dd class="col-7"><?= e((string) ($s['disposed_by_name'] ?? '—')) ?>, <?= e(format_ts((string) $s['disposed_at'], $tz, 'M j, Y g:i A')) ?></dd>
                        <?php if (($s['disposition_note'] ?? '') !== ''): ?><dt class="col-5 text-muted fw-normal">Note</dt><dd class="col-7 text-break"><?= nl2br(e((string) $s['disposition_note'])) ?></dd><?php endif; ?>
                    </dl><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if ($live && $s['status'] === 'escalated'): ?><div class="alert alert-danger fs-12" id="signal-escalated-note">Escalated by <?= e((string) ($s['disposed_by_name'] ?? 'someone')) ?><?= ($s['disposition_note'] ?? '') !== '' ? ': “' . e((string) $s['disposition_note']) . '”' : '' ?>. It still waits for an acknowledgement or a dismissal.</div><?php endif; ?>
            <?= view('signals/partials/outcome.php', ['outcome' => $sig['outcome'], 's' => $s]) ?>
            <?php if ($canDelete): ?>
            <form method="post" action="/signals/delete.php" hx-post="/signals/delete.php" hx-target="#flash" hx-confirm="Delete this signal? Only for a signal raised on data since corrected; the trail keeps it and your reason." class="card" id="signal-delete-form"><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="signal" value="<?= $sid ?>">
                <label class="form-label fs-12 text-muted" for="signal-delete-reason">Raised on data since corrected? Say which.</label>
                <input type="text" name="reason" id="signal-delete-reason" class="form-control form-control-sm mb-2" maxlength="500" required>
                <button type="submit" class="btn btn-outline-danger btn-sm btn-touch w-100" id="signal-delete-btn">Delete the signal</button>
            </div></form>
            <?php endif; ?>
        </div>
    </div>
</div>
