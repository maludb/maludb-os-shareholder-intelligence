<?php
/**
 * The issuer switcher (sso-shell.md): the current issuer's ticker (or name) in the header, a dropdown of the issuers the wall lets this
 * person see — a restricted one marked, never one they may not see. One issuer visible = the label alone, no menu. None = nothing.
 * Choosing one is a GET of the same page with ?issuer=<id> (current_issuer() remembers it and logs issuer.switch).
 * Data: issuers [{issuer_id, name, ticker, restricted}], issuer (the current one or null), here (the current path)
 */
if ($issuers === []) {
    return;
}
$label = $issuer !== null ? (string) ($issuer['ticker'] ?: $issuer['name']) : 'Choose an issuer';
$target = in_array($here, ['/settings/', '/settings/tokens/', '/notifications', '/activity', '/search'], true) ? '/' : $here;
?>
<?php if (count($issuers) === 1): ?>
<span class="badge bg-soft-primary text-primary ms-2 text-nowrap fs-12 py-2 px-3" id="issuer-switcher" title="<?= e($issuer['name'] ?? '') ?>"><i class="feather-layers me-1"></i><span id="issuer-switcher-label"><?= e($label) ?></span></span>
<?php else: ?>
<div class="dropdown ms-2" id="issuer-switcher">
    <button type="button" class="btn btn-sm btn-light-brand text-nowrap" data-bs-toggle="dropdown" aria-expanded="false" id="issuer-switcher-btn" aria-label="Issuer: <?= e($label) ?>">
        <i class="feather-layers me-1"></i><span id="issuer-switcher-label"><?= e($label) ?></span><i class="feather-chevron-down ms-1"></i>
    </button>
    <div class="dropdown-menu nxl-h-dropdown" id="issuer-switcher-menu" style="max-height: 70vh; overflow-y: auto;">
        <div class="dropdown-header fs-11 text-uppercase text-muted">Issuers you may see</div>
        <?php foreach ($issuers as $i): $id = (int) $i['issuer_id']; $active = $issuer !== null && (int) $issuer['issuer_id'] === $id; $u = $target . '?issuer=' . $id; ?>
            <a href="<?= e($u) ?>" class="dropdown-item d-flex align-items-center gap-2<?= $active ? ' active' : '' ?>" id="issuer-switcher-item-<?= $id ?>">
                <span class="fw-semibold"><?= e($i['ticker'] ?: '—') ?></span><span class="text-truncate fs-12"><?= e($i['name']) ?></span>
                <?php if (!empty($i['restricted'])): ?><i class="feather-lock ms-auto fs-11" title="Restricted to named members"></i><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
