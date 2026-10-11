<?php
/** A signal disposition an agent proposed: the signal, its class, the proposal in words, and for a person who works signals Take it and Not this. Data: p (a proposals() row), work, tz, here */
$d = $p['detail'];
$sid = (int) $p['proposal_id'];
$verb = $d['action'] ?? 'dismiss';
$why = SIGNAL_REASON_WORDS[$d['proposal_reason'] ?? ''] ?? (string) $p['reason'];
$ev = ($d['event_title'] ?? null) !== null ? ' — ' . $d['event_title'] . ($d['event_on'] ? ' of ' . format_date((string) $d['event_on']) : '') : '';
?>
<div class="card mb-2 border-start border-4 border-<?= e(['high' => 'danger', 'watch' => 'warning', 'info' => 'info'][$d['severity'] ?? ''] ?? 'secondary') ?>" id="proposal-signal-<?= $sid ?>" data-kind="signal">
    <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-flag text-muted"></i>
            <?= isset($d['severity']) ? signal_severity_chip((string) $d['severity']) : '' ?>
            <?= isset($d['evidence_class']) ? signal_class_chip((string) $d['evidence_class'], 'proposal-class-' . $sid) : '' ?>
            <?= $d !== [] ? hx_link(with_back('/signals/' . $sid, $here), e((string) $d['rule_name']), 'fw-semibold text-dark', 'id="proposal-signal-link-' . $sid . '"') : '<span class="fw-semibold">' . e((string) $p['title']) . '</span>' ?>
            <span class="fs-11 text-muted ms-auto text-nowrap"><?= e((string) ($p['issuer_name'] ?? '')) ?><?= isset($d['raised_on']) ? ' · ' . e(format_date((string) $d['raised_on'])) : '' ?></span>
        </div>
        <?php if ($d !== []): ?><div class="fs-12 mt-1 text-break"><?= e((string) $d['summary']) ?></div><?php endif; ?>
        <div class="border border-primary-subtle rounded p-2 mt-2 bg-primary-subtle" id="proposal-words-<?= $sid ?>">
            <div class="fs-12"><i class="feather-inbox me-1"></i><span class="fw-semibold"><?= e((string) ($p['proposed_by_name'] ?? 'An agent')) ?> proposes:</span> <?= e($verb) ?> — <?= e($why . $ev) ?>
                <span class="text-muted">· <?= e(format_ts($p['proposed_at'], $tz, 'M j, g:i A')) ?></span></div>
            <?php if (($p['note'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1 text-break">“<?= e((string) $p['note']) ?>”</div><?php endif; ?>
            <?php if ($work && $d !== []): ?>
            <div class="d-flex gap-2 mt-2 flex-wrap">
                <form method="post" action="/signals/<?= e($verb) ?>.php" hx-post="/signals/<?= e($verb) ?>.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="signal" value="<?= $sid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <?php if ($verb !== 'escalate'): ?><input type="hidden" name="reason" value="<?= e((string) $d['proposal_reason']) ?>"><?php endif; ?>
                    <?php if ($d['proposal_event_id'] !== null): ?><input type="hidden" name="explaining_event" value="<?= (int) $d['proposal_event_id'] ?>"><?php endif; ?>
                    <input type="hidden" name="note" value="<?= e((string) ($p['note'] ?? '')) ?>">
                    <button type="submit" class="btn btn-primary btn-sm btn-touch" id="proposal-take-<?= $sid ?>"><i class="feather-check me-1"></i>Take it</button>
                </form>
                <?= hx_link('/signals/' . $sid . '#signal-dispose', 'Not this', 'btn btn-light btn-sm btn-touch', 'id="proposal-not-this-' . $sid . '"') ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
