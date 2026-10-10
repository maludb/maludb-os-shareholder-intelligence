<?php /** A holder's accounts: the list, the account kind, the custodian, the registration as read (PII roles), the last position. Data: accounts */ ?>
<?php if ($accounts === []): ?><div class="fs-12 text-muted">No account.</div><?php endif; ?>
<?php foreach ($accounts as $a): ?>
    <div class="border rounded p-2 mb-2" id="holder-account-<?= (int) $a['holder_account_id'] ?>">
        <div class="d-flex flex-wrap align-items-center gap-1"><span class="badge text-bg-light"><?= e(HOLDER_CHANNELS[$a['channel']] ?? $a['channel']) ?></span><?= account_kind_chip((string) $a['account_kind']) ?>
            <?php if ($a['custodian_name']): ?><?= hx_link('/participants/' . (int) $a['custodian_participant_id'], e(($a['custodian_number'] ? $a['custodian_number'] . ' ' : '') . $a['custodian_name']), 'fs-11') ?><?php endif; ?></div>
        <?php if ($a['registration_text'] !== null): ?><div class="fs-12 mt-1 text-break si-registration"><?= nl2br(e(str_replace(' | ', "\n", (string) $a['registration_text']))) ?></div><?php if ($a['holder_ref']): ?><div class="fs-11 text-muted">The agent's account <?= e($a['holder_ref']) ?></div><?php endif; ?>
        <?php else: ?><div class="fs-12 mt-1 text-muted"><i class="bi bi-shield-lock me-1"></i>The registration lines are an Analyst's.</div><?php endif; ?>
        <div class="fs-11 text-muted mt-1"><?= $a['last_date'] ? e(fmt_n($a['last_shares'])) . ' shares on ' . e(format_date($a['last_date'])) : '' ?> · listed <?= e(format_date($a['first_seen_on'])) ?><?= $a['last_seen_on'] !== $a['first_seen_on'] ? ' to ' . e(format_date($a['last_seen_on'])) : '' ?></div>
    </div>
<?php endforeach; ?>
