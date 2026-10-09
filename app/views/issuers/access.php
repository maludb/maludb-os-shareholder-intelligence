<?php /** The restriction list (screen `issuer-access`, restrictions.manage). Each form posts the WHOLE list (restriction_save). Data: i, rows, notice, tz, back */
$id = (int) $i['id'];
$ids = array_map(static fn (array $r): int => (int) $r['member_id'], $rows);
$hidden = static fn (array $list): string => implode('', array_map(static fn (int $m): string => '<input type="hidden" name="members[]" value="' . $m . '">', $list)) . ($list === [] ? '<input type="hidden" name="members" value="">' : '');
?>
<?= view('shared/header.php', ['id' => 'issuer-access', 'title' => $i['name'] . ' — access', 'crumbs' => [['Home', '/'], ['Issuers', '/issuers'], [$i['ticker'] ?: $i['name'], '/issuers/' . $id], ['Access', null]], 'back' => $back]) ?>
<div class="main-content" id="issuer-access-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('issuers/partials/tabs.php', ['i' => $i, 'active' => 'access']) ?>
    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card" id="access-card"><div class="card-header"><h5 class="card-title mb-0"><?= $rows === [] ? 'Open to everyone admitted' : 'Restricted to ' . count($rows) . ' named member' . (count($rows) === 1 ? '' : 's') ?></h5></div><div class="card-body">
                <p class="fs-12 text-muted">An issuer with no list is seen by everyone admitted to Shareholder Intelligence. With a list, only those named — and the IR Managers and the admin — see it, its uploads, holders, signals and reports; nobody else is told it exists. An advisory firm restricts each client this way.</p>
                <?php foreach ($rows as $r): $mid = (int) $r['member_id']; ?>
                    <div class="d-flex align-items-center gap-2 border rounded px-3 py-2 mb-2" id="access-member-<?= $mid ?>">
                        <span class="avatar-text avatar-sm"><?= e(mb_substr((string) $r['display_name'], 0, 1)) ?></span><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($r['display_name']) ?></div><div class="fs-11 text-muted">added <?= e(format_ts($r['added_at'], $tz, 'M j, Y')) ?><?= $r['added_by_name'] ? ' by ' . e($r['added_by_name']) : '' ?></div></div>
                        <form method="post" action="/issuers/access/save.php" hx-post="/issuers/access/save.php" hx-target="#flash" <?= count($ids) === 1 ? 'hx-confirm="Removing the last name lifts the restriction: everyone admitted will see ' . e($i['name']) . '. Go on?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="issuer" value="<?= $id ?>"><?= $hidden(array_values(array_diff($ids, [$mid]))) ?>
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="access-member-<?= $mid ?>-remove-btn" aria-label="Remove <?= e($r['display_name']) ?>"><i class="feather-x"></i></button></form>
                    </div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-xl-5">
            <form method="post" action="/issuers/access/save.php" hx-post="/issuers/access/save.php" hx-target="#flash" class="card" id="access-form" <?= $rows === [] ? 'hx-confirm="Restricting hides ' . e($i['name']) . ' from everyone not on the list (managers and the admin keep it). Go on?"' : '' ?>><?= csrf_field() ?>
                <div class="card-header"><h5 class="card-title mb-0"><?= $rows === [] ? 'Restrict to named members' : 'Add a member' ?></h5></div>
                <input type="hidden" name="issuer" value="<?= $id ?>"><?= implode('', array_map(static fn (int $m): string => '<input type="hidden" name="members[]" value="' . $m . '">', $ids)) ?>
                <div class="card-body"><label class="form-label" id="access-form-member-label">Member</label>
                    <?= picker_field(['id' => 'access-form-field-member', 'name' => 'members[]', 'source' => 'member', 'required' => true, 'labelledby' => 'access-form-member-label', 'title' => 'Who may see ' . $i['name']]) ?></div>
                <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch w-100" id="access-form-save-btn"><?= $rows === [] ? 'Restrict' : 'Add' ?></button></div>
            </form>
        </div>
    </div>
</div>
