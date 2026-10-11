<?php /** The caller's tokens, their API keys and the MCP URLs to connect their AI to (screen `settings-tokens`). Data: rows (mcp tokens), keys (api keys), mayKey, raw (just minted, once), tz, notice */ $base = rtrim((string) env('APP_URL', ''), '/'); ?>
<?= view('shared/header.php', ['id' => 'tokens', 'title' => 'Tokens', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['Tokens', null]]]) ?>
<div class="main-content" id="tokens-content">
    <?= view('shared/notice.php', ['notice' => $notice ?? null]) ?>
    <?php if ($raw): ?>
    <div class="row"><div class="col-lg-12"><div class="alert alert-success" id="tokens-minted">
        <div class="fw-bold mb-1">Your new <?= $raw['scope'] === 'api' ? 'API key' : e($raw['scope']) . ' token' ?> "<?= e($raw['label']) ?>" — copy it now; it is shown once.</div>
        <code class="user-select-all text-break" id="tokens-minted-value"><?= e($raw['raw']) ?></code>
    </div></div></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card mb-0 h-100" id="tokens-connect"><div class="card-header"><h5 class="card-title mb-0">Connect your AI to Shareholder Intelligence</h5></div><div class="card-body fs-12">
                <p>Your own AI tools (Claude Desktop, Claude Code, an agent of yours) read Shareholder Intelligence's two memories with an <strong>mcp</strong> token as a Bearer token. They see exactly what you see — the issuers you may reach, and holders by name only when you may see them. An <strong>API key</strong> (below) is for a script, the transfer agent's nightly drop or an outside system: it acts as you, sees what you see, and is limited in calls a minute and a day.</p>
                <div class="mb-2"><span class="text-muted">Records:</span> <code id="tokens-url-records" class="text-break"><?= e($base) ?>/mcp/records</code></div>
                <div class="mb-3"><span class="text-muted">Activity:</span> <code id="tokens-url-activity" class="text-break"><?= e($base) ?>/mcp/activity</code></div>
                <hr>
                <form method="post" action="/settings/tokens/mint.php" id="token-form" class="row g-2 align-items-end" hx-post="/settings/tokens/mint.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <div class="col-9 col-md-10"><label class="form-label fs-12 text-muted" for="token-form-field-label">Label</label><input type="text" name="label" id="token-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Claude Desktop"></div>
                    <input type="hidden" name="scope" value="mcp">
                    <div class="col-3 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="token-form-save-btn" aria-label="Mint"><i class="feather-key"></i></button></div>
                </form>
            </div></div>
        </div>
        <div class="col-xl-7">
            <div class="card mb-0 h-100" id="tokens-card">
                <div class="card-header"><h5 class="card-title mb-0">Your tokens</h5></div>
                <div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="tokens-table">
                    <thead class="thead-light"><tr><th>Label</th><th>Scope</th><th>Created</th><th class="d-none d-md-table-cell">Last used</th><th class="d-none d-md-table-cell">Expires</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="tokens-empty">No tokens yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $t): $id = (int) $t['id']; $live = $t['revoked_at'] === null; ?>
                        <tr id="token-row-<?= $id ?>" class="<?= $live ? '' : 'text-muted' ?>">
                            <td><span class="wd-10 ht-10 bg-<?= $live ? 'success' : 'secondary' ?> me-2 d-inline-block rounded-circle"></span><?= e($t['label']) ?><?= $live ? '' : ' <span class="badge bg-soft-secondary text-secondary">revoked</span>' ?></td>
                            <td><?= e($t['scope']) ?></td><td><?= e(format_ts($t['created_at'], $tz, 'M j, Y')) ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['last_used_at'], $tz) ?: 'never') ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['expires_at'], $tz, 'M j, Y') ?: 'never') ?></td>
                            <td class="text-end"><?php if ($live): ?><form method="post" action="/settings/tokens/revoke.php" hx-post="/settings/tokens/revoke.php" hx-confirm="Revoke the token <?= e($t['label']) ?>? Anything using it stops working." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="token" value="<?= $id ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Revoke" id="token-row-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($t['label']) ?>"><i class="feather-x"></i></button></form><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
            </div>
        </div>
    </div>
    <div class="card mt-3" id="keys-card">
        <div class="card-header"><h5 class="card-title mb-0">API keys</h5></div>
        <div class="card-body fs-12">
            <p class="mb-2">A key reads the records API at <code id="keys-api-url" class="text-break"><?= e($base) ?>/api/v1/</code> (<code>Authorization: Bearer &lt;key&gt;</code>) and can post an upload, an event or a borrow observation. It acts as <strong>you</strong>: your rights, your issuers, and a Viewer's key reads “Individual holder”. It is shown once, when it is minted; only its label is kept.</p>
            <?php if ($mayKey): ?>
            <form method="post" action="/settings/tokens/key-mint.php" hx-post="/settings/tokens/key-mint.php" hx-target="#page-content" hx-swap="innerHTML" class="row g-2 align-items-end mb-3" id="key-form"><?= csrf_field() ?>
                <div class="col-9 col-md-10"><label class="form-label fs-12 text-muted" for="key-form-field-label">Label</label><input type="text" name="label" id="key-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="The transfer agent's nightly drop"></div>
                <div class="col-3 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="key-form-save-btn" aria-label="Mint a key"><i class="feather-key"></i></button></div>
            </form>
            <?php else: ?>
            <p class="text-muted mb-3" id="keys-not-allowed">Your role holds no keys of its own: an administrator mints one for you if you need it.</p>
            <?php endif; ?>
            <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="keys-table">
                <thead class="thead-light"><tr><th>Label</th><th class="d-none d-md-table-cell">Calls today</th><th class="d-none d-md-table-cell">Limits</th><th class="d-none d-md-table-cell">Last used</th><th class="d-none d-md-table-cell">Stops</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php if ($keys === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="keys-empty">No keys yet.</td></tr><?php endif; ?>
                <?php foreach ($keys as $k): $kid = (int) $k['key_id']; $revoked = $k['revoked_at'] !== null; $stopped = !$revoked && $k['expires_at'] !== null && strtotime((string) $k['expires_at']) <= time(); $live = !$revoked && !$stopped; ?>
                    <tr id="key-row-<?= $kid ?>" class="<?= $live ? '' : 'text-muted' ?>" data-state="<?= $revoked ? 'revoked' : ($stopped ? 'expired' : 'live') ?>">
                        <td><span class="wd-10 ht-10 bg-<?= $live ? 'success' : 'secondary' ?> me-2 d-inline-block rounded-circle"></span><?= e($k['label']) ?><?= $revoked ? ' <span class="badge bg-soft-secondary text-secondary">revoked</span>' : ($stopped ? ' <span class="badge bg-soft-secondary text-secondary">stopped</span>' : '') ?><?= $k['rotated_from'] !== null ? ' <span class="badge bg-soft-info text-info d-none d-md-inline">rotated</span>' : '' ?>
                            <div class="fs-11 text-muted d-md-none"><?= (int) $k['calls_today'] ?> calls today</div></td>
                        <td class="d-none d-md-table-cell"><?= (int) $k['calls_today'] ?></td>
                        <td class="d-none d-md-table-cell"><?= (int) $k['rate_per_minute'] ?>/min · <?= number_format((int) $k['rate_per_day']) ?>/day</td>
                        <td class="d-none d-md-table-cell"><?= e(format_ts($k['last_used_at'], $tz, 'M j, g:i A') ?: 'never') ?></td>
                        <td class="d-none d-md-table-cell"><?= $revoked ? e(format_ts($k['revoked_at'], $tz, 'M j')) : e(format_ts($k['expires_at'], $tz, 'M j, g:i A') ?: 'when revoked') ?></td>
                        <td class="text-end text-nowrap">
                        <?php if ($live && $mayKey): ?>
                            <form method="post" action="/settings/tokens/key-rotate.php" hx-post="/settings/tokens/key-rotate.php" hx-target="#page-content" hx-swap="innerHTML" class="d-inline"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $kid ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Rotate (the old key works for a day more)" id="key-row-<?= $kid ?>-rotate-btn" aria-label="Rotate <?= e($k['label']) ?>"><i class="feather-refresh-cw"></i></button></form>
                        <?php endif; ?>
                        <?php if ($live): ?>
                            <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($k['label']) ?>? Anything using it stops working." hx-target="#page-content" hx-swap="innerHTML" class="d-inline"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $kid ?>"><button type="submit" class="avatar-text avatar-sm border-0" title="Revoke" id="key-row-<?= $kid ?>-revoke-btn" aria-label="Revoke <?= e($k['label']) ?>"><i class="feather-x"></i></button></form>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
