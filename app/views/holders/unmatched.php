<?php /** The queue (screen `holders-unmatched`). Data: security, rows, total, page, upload, kind, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'holders-unmatched', 'title' => 'Unmatched rows', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Unmatched', null]]]) ?>
<div class="main-content" id="holders-unmatched-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($security !== null): ?><?= view('holders/partials/subnav.php', ['active' => 'unmatched', 'security' => $security]) ?><?php endif; ?>
    <p class="fs-12 text-muted" id="queue-help"><?= number_format($total) ?> row<?= $total === 1 ? '' : 's' ?> waiting<?= $upload ? ' from ' . hx_link('/uploads/' . (int) $upload, 'upload ' . (int) $upload, '') : '' ?>. The matcher took what scored <?= e((string) (one_value(db(), 'SELECT auto_match_threshold FROM mcp_settings') ?: '0.95')) ?> or above; these it could not decide.
        <span class="d-none d-md-inline">Keys: <kbd>a</kbd> accept the first candidate, <kbd>r</kbd> reject it, <kbd>n</kbd> a new holder.</span></p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="queue-empty">Nothing waits: every row of the loaded lists has its holder.</div></div><?php endif; ?>
    <?php foreach ($rows as $i => $r): $key = $r['row_kind'] . '-' . (int) $r['row_id']; ?>
    <div class="card mb-3" id="queue-row-<?= e($key) ?>"><div class="card-body"><div class="row g-3">
        <div class="col-md-5"><div class="fs-11 text-muted mb-1">As read</div><?= view('holders/partials/registration.php', ['r' => $r]) ?></div>
        <div class="col-md-7"><div class="fs-11 text-muted mb-1">Candidates</div>
            <?php foreach ($r['candidates'] as $j => $c): ?><?= view('holders/partials/candidate.php', ['c' => $c, 'r' => $r, 'here' => $here, 'first' => $i === 0 && $j === 0]) ?><?php endforeach; ?>
            <?php if ($r['candidates'] === []): ?><div class="fs-12 text-muted mb-2">No candidate scored.</div><?php endif; ?>
            <form method="post" action="/holders/matches/new-holder.php" hx-post="/holders/matches/new-holder.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="row_kind" value="<?= e($r['row_kind']) ?>"><input type="hidden" name="row" value="<?= (int) $r['row_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="queue-new-<?= e($key) ?>"<?= $i === 0 ? ' data-key="n"' : '' ?>><i class="feather-user-plus me-1"></i>None of these: a new holder</button></form>
        </div>
    </div></div></div>
    <?php endforeach; ?>
    <?php if ($total > $page * 20): ?><?= hx_link('/holders/unmatched?' . http_build_query(array_filter(['upload' => $upload, 'kind' => $kind, 'page' => $page + 1])), 'More rows', 'btn btn-light btn-touch w-100', 'id="queue-more"') ?><?php endif; ?>
</div>
<script>
(function () {
    if (window.siQueueKeys) { return; }
    window.siQueueKeys = true;
    document.addEventListener('keydown', function (e) {
        if (!document.getElementById('holders-unmatched-content') || e.ctrlKey || e.metaKey || e.altKey || /input|textarea|select/i.test((e.target.tagName || ''))) { return; }
        var b = document.querySelector('#holders-unmatched-content [data-key="' + e.key + '"]');
        if (b) { e.preventDefault(); b.click(); }
    });
})();
</script>
