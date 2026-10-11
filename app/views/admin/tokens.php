<?php /** Everyone's keys and tokens (screen `admin-tokens`). Data: rows (all_tokens), holders (token_holders), member (the filter or null), tz, here, notice */
?>
<?= view('shared/header.php', ['id' => 'admin-tokens', 'title' => 'Keys and tokens', 'crumbs' => [['Home', '/'], ['Sources and settings', has_right('sources.manage') ? '/admin/sources' : null], ['Keys and tokens', null]]]) ?>
<div class="main-content" id="admin-tokens-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/tabs.php', ['active' => 'tokens']) ?>
    <div class="card" id="tokens-card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0">Everyone's keys and MCP tokens</h5>
            <form method="get" action="/admin/tokens" hx-get="/admin/tokens" hx-target="#page-content" hx-push-url="true" class="ms-auto d-flex gap-2 align-items-center" id="tokens-filter">
                <label class="visually-hidden" for="tokens-filter-member">Person</label>
                <select name="member" id="tokens-filter-member" class="form-select form-select-sm btn-touch"><option value="">Everyone</option>
                <?php foreach ($holders as $h): ?><option value="<?= (int) $h['member_id'] ?>"<?= $member === (int) $h['member_id'] ? ' selected' : '' ?>><?= e($h['member_name']) ?> (<?= (int) $h['n'] ?>)</option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-light btn-sm btn-touch" id="tokens-filter-btn">Show</button></form></div>
        <?php if ($rows === []): ?><div class="card-body text-muted" id="tokens-empty">No token or key<?= $member !== null ? ' for that person' : '' ?>.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="tokens-table">
            <thead class="thead-light"><tr><th>Label</th><th>Person</th><th class="d-none d-md-table-cell">Scope</th><th class="d-none d-lg-table-cell">Limits</th><th class="d-none d-md-table-cell">Calls today</th><th class="d-none d-lg-table-cell">Last used</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): $id = (int) $t['token_id']; $revoked = $t['revoked_at'] !== null; $stopped = !$revoked && $t['expires_at'] !== null && strtotime((string) $t['expires_at']) <= time(); $live = !$revoked && !$stopped; ?>
                <tr id="token-row-<?= $id ?>" class="<?= $live ? '' : 'text-muted' ?>" data-scope="<?= e($t['scope']) ?>" data-state="<?= $revoked ? 'revoked' : ($stopped ? 'stopped' : 'live') ?>">
                    <td class="text-wrap"><span class="wd-10 ht-10 bg-<?= $live ? 'success' : 'secondary' ?> me-2 d-inline-block rounded-circle"></span><?= e($t['label']) ?><?= $revoked ? ' <span class="badge text-bg-secondary">revoked</span>' : ($stopped ? ' <span class="badge text-bg-secondary">stopped</span>' : '') ?><?= $t['rotated_from'] !== null ? ' <span class="badge text-bg-info d-none d-md-inline">rotated</span>' : '' ?>
                        <div class="fs-11 text-muted d-md-none"><?= e($t['scope'] === 'api' ? 'API key' : 'MCP token') ?><?= $t['calls_today'] !== null ? ' · ' . (int) $t['calls_today'] . ' calls today' : '' ?></div></td>
                    <td class="text-wrap"><?= e($t['member_name']) ?><?= $t['member_kind'] === 'agent' ? ' <span class="badge text-bg-light">agent</span>' : '' ?></td>
                    <td class="d-none d-md-table-cell"><?= e($t['scope'] === 'api' ? 'API key' : 'MCP token') ?></td>
                    <td class="d-none d-lg-table-cell"><?= $t['scope'] === 'api' ? (int) $t['rate_per_minute'] . '/min · ' . number_format((int) $t['rate_per_day']) . '/day' : '—' ?></td>
                    <td class="d-none d-md-table-cell"><?= $t['calls_today'] === null ? '—' : (int) $t['calls_today'] ?></td>
                    <td class="d-none d-lg-table-cell"><?= e(format_ts($t['last_used_at'], $tz, 'M j, g:i A') ?: 'never') ?></td>
                    <td class="text-end"><?php if ($live): ?><form method="post" action="/admin/tokens/revoke.php" hx-post="/admin/tokens/revoke.php" hx-target="#flash" hx-confirm="Revoke <?= e($t['label']) ?> (<?= e($t['member_name']) ?>)? Anything using it stops working." class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="token-row-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($t['label']) ?>">Revoke</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </div>
    <p class="fs-12 text-muted mt-2 mb-0">A key is a person's own and acts as them; an MCP token connects their AI tools. Only the label and the limits are kept here — never the key itself. Revoking is at once.</p>
</div>
