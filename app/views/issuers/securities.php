<?php /** An issuer's securities (screen `issuer-securities`): the table, and the add / edit form on the page (?security= edits one). Data: i, rows, editing (a security or null), notice, back */
$id = (int) $i['id'];
$manage = has_right('issuers.manage');
$v = $editing ?? ['class' => 'common', 'name' => $rows === [] ? 'Common Stock' : '', 'cusip' => '', 'isin' => '', 'ticker' => $rows === [] ? (string) $i['ticker'] : '', 'figi' => '', 'par_value' => '', 'is_primary' => $rows === [], 'listed_on' => '', 'delisted_on' => ''];
?>
<?= view('shared/header.php', ['id' => 'issuer-securities', 'title' => $i['name'] . ' — securities', 'crumbs' => [['Home', '/'], ['Issuers', '/issuers'], [$i['ticker'] ?: $i['name'], '/issuers/' . $id], ['Securities', null]], 'back' => $back]) ?>
<div class="main-content" id="issuer-securities-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('issuers/partials/tabs.php', ['i' => $i, 'active' => 'securities']) ?>
    <div class="row g-3">
        <div class="<?= $manage ? 'col-xl-7' : 'col-12' ?>">
            <div class="card" id="securities-card"><div class="card-body p-0">
                <?php if ($rows === []): ?><div class="p-3 fs-12 text-muted" id="securities-empty">No security yet.</div>
                <?php else: ?><div class="table-responsive"><table class="table mb-0 fs-12" id="securities-table"><thead class="thead-light"><tr><th>Security</th><th class="d-none d-md-table-cell">CUSIP</th><th class="d-none d-md-table-cell">Ticker</th><th class="d-none d-md-table-cell">Outstanding</th><th class="text-end"></th></tr></thead><tbody>
                    <?php foreach ($rows as $s): $sid = (int) $s['security_id']; ?><tr id="security-<?= $sid ?>">
                        <td class="text-wrap"><span class="fw-semibold"><?= e($s['name']) ?></span> <span class="text-muted"><?= e(SECURITY_CLASSES[$s['class']] ?? $s['class']) ?></span><?= $s['is_primary'] ? ' <span class="badge bg-soft-primary text-primary">primary</span>' : '' ?><?= $s['delisted_on'] ? ' <span class="badge bg-soft-secondary text-secondary">delisted</span>' : '' ?>
                            <div class="d-md-none text-muted"><?= e(implode(' · ', array_filter([$s['cusip'], $s['ticker']]))) ?></div></td>
                        <td class="d-none d-md-table-cell"><?= e($s['cusip'] ?? '—') ?></td><td class="d-none d-md-table-cell"><?= e($s['ticker'] ?? '—') ?></td><td class="d-none d-md-table-cell"><?= e(fmt_n($s['outstanding'])) ?></td>
                        <td class="text-end text-nowrap"><?php if ($manage): ?><?= hx_link('/issuers/' . $id . '/securities?security=' . $sid, '<i class="feather-edit-2"></i>', 'avatar-text avatar-sm', 'id="security-' . $sid . '-edit-btn" aria-label="Edit ' . e($s['name']) . '"') ?>
                            <form method="post" action="/issuers/securities/delete.php" hx-post="/issuers/securities/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Remove <?= e($s['name']) ?> with its positions, counts and series? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="security" value="<?= $sid ?>">
                                <button type="submit" class="avatar-text avatar-sm border-0" id="security-<?= $sid ?>-delete-btn" aria-label="Remove <?= e($s['name']) ?>"><i class="feather-trash-2"></i></button></form><?php endif; ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div></div>
        </div>
        <?php if ($manage): ?>
        <div class="col-xl-5">
            <form method="post" action="/issuers/securities/save.php" hx-post="/issuers/securities/save.php" hx-target="#flash" class="card" id="security-form"><?= csrf_field() ?>
                <div class="card-header"><h5 class="card-title mb-0"><?= $editing ? 'Edit ' . e($editing['name']) : 'Add a security' ?></h5></div>
                <?php if ($editing): ?><input type="hidden" name="security" value="<?= (int) $editing['security_id'] ?>"><?php else: ?><input type="hidden" name="issuer" value="<?= $id ?>"><?php endif; ?>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-7"><label class="form-label" for="security-form-field-name">Name</label><input type="text" class="form-control btn-touch" id="security-form-field-name" name="name" value="<?= e($v['name']) ?>" maxlength="120" required></div>
                        <div class="col-5"><label class="form-label" for="security-form-field-class">Class</label><select class="form-select btn-touch" id="security-form-field-class" name="class"><?php foreach (SECURITY_CLASSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v['class'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-7"><label class="form-label" for="security-form-field-cusip">CUSIP</label><input type="text" class="form-control btn-touch" id="security-form-field-cusip" name="cusip" value="<?= e((string) $v['cusip']) ?>" maxlength="9" autocapitalize="characters" placeholder="nine characters"></div>
                        <div class="col-5"><label class="form-label" for="security-form-field-ticker">Ticker</label><input type="text" class="form-control btn-touch" id="security-form-field-ticker" name="ticker" value="<?= e((string) $v['ticker']) ?>" maxlength="12" autocapitalize="characters"></div>
                    </div>
                    <details class="mb-3" id="security-form-more"><summary class="fw-semibold mb-2">ISIN, FIGI, par value, listing dates</summary>
                        <div class="row g-3">
                            <div class="col-6"><label class="form-label" for="security-form-field-isin">ISIN</label><input type="text" class="form-control btn-touch" id="security-form-field-isin" name="isin" value="<?= e((string) $v['isin']) ?>" maxlength="12"></div>
                            <div class="col-6"><label class="form-label" for="security-form-field-figi">FIGI</label><input type="text" class="form-control btn-touch" id="security-form-field-figi" name="figi" value="<?= e((string) $v['figi']) ?>" maxlength="12"></div>
                            <div class="col-4"><label class="form-label" for="security-form-field-par">Par value</label><input type="text" class="form-control btn-touch" id="security-form-field-par" name="par_value" value="<?= e((string) $v['par_value']) ?>" inputmode="decimal"></div>
                            <div class="col-4"><label class="form-label" for="security-form-field-listed">Listed</label><input type="date" class="form-control btn-touch" id="security-form-field-listed" name="listed_on" value="<?= e((string) $v['listed_on']) ?>"></div>
                            <div class="col-4"><label class="form-label" for="security-form-field-delisted">Delisted</label><input type="date" class="form-control btn-touch" id="security-form-field-delisted" name="delisted_on" value="<?= e((string) $v['delisted_on']) ?>"></div>
                        </div>
                    </details>
                    <input type="hidden" name="is_primary" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="security-form-field-primary"><input type="checkbox" class="form-check-input mt-0" name="is_primary" value="yes" id="security-form-field-primary" <?= $v['is_primary'] ? 'checked' : '' ?>>The primary security (positions, short interest and prices default to it)</label>
                </div>
                <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="security-form-save-btn"><?= $editing ? 'Save' : 'Add the security' ?></button>
                    <?php if ($editing): ?><?= hx_link('/issuers/' . $id . '/securities', 'Cancel', 'btn btn-light btn-touch', 'id="security-form-cancel-btn"') ?><?php endif; ?></div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
