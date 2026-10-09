<?php /** The issuers (screen `issuer-list`). Data: rows, q, deleted (a manager's tombstones), notice, here */ ?>
<?= view('shared/header.php', ['id' => 'issuer-list', 'title' => 'Issuers', 'crumbs' => [['Home', '/'], ['Issuers', null]],
    'action' => has_right('issuers.manage') ? hx_link('/issuers/new', '<i class="feather-plus me-1"></i>Add an issuer', 'btn btn-primary btn-touch', 'id="issuer-add-btn"') : '']) ?>
<div class="main-content" id="issuer-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/issuers" class="mb-3" id="issuer-search" hx-get="/issuers" hx-target="#page-content" hx-push-url="true" role="search">
        <div class="input-group"><input type="search" name="q" value="<?= e($q) ?>" class="form-control btn-touch" placeholder="A name, ticker or CIK" id="issuer-search-q" aria-label="Find an issuer">
            <button class="btn btn-light btn-touch" type="submit" aria-label="Find" id="issuer-search-btn"><i class="feather-search"></i></button></div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="issuer-list-empty"><?= $q !== '' ? 'No issuer matches “' . e($q) . '”.' : 'No issuer yet.' ?></div></div>
    <?php else: ?>
        <div class="row g-3" id="issuer-cards"><?php foreach ($rows as $i): ?><?= view('issuers/partials/card.php', ['i' => $i, 'here' => $here]) ?><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($deleted !== []): ?>
        <div class="card mt-4" id="issuer-tombstones"><div class="card-header"><h5 class="card-title mb-0">In the tombstone</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12">
            <thead class="thead-light"><tr><th>Issuer</th><th>Deleted</th><th class="text-end"></th></tr></thead><tbody>
            <?php foreach ($deleted as $d): ?><tr id="issuer-tombstone-<?= (int) $d['id'] ?>"><td><?= e($d['name']) ?> <span class="text-muted"><?= e((string) $d['ticker']) ?></span></td><td><?= e(format_ts($d['deleted_at'], member_timezone(), 'M j, Y')) ?></td>
                <td class="text-end"><form method="post" action="/issuers/restore.php" hx-post="/issuers/restore.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="issuer" value="<?= (int) $d['id'] ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="issuer-restore-<?= (int) $d['id'] ?>-btn">Restore</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>
