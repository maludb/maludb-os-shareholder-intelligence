<?php /** An issuer's tabs: Overview · Securities · Share counts · Access (managers) · Sources · Notes. Data: i (the issuer row), active */
$id = (int) $i['id'];
$tabs = [['overview', '/issuers/' . $id, 'Overview'], ['securities', '/issuers/' . $id . '/securities', 'Securities'], ['share-counts', '/issuers/' . $id . '/share-counts', 'Share counts']];
if (has_right('restrictions.manage')) { $tabs[] = ['access', '/issuers/' . $id . '/access', 'Access']; }
$tabs[] = ['sources', '/issuers/' . $id . '?tab=sources', 'Sources'];
$tabs[] = ['notes', '/issuers/' . $id . '?tab=notes', 'Notes'];
?>
<div class="d-flex flex-wrap gap-1 mb-3" id="issuer-tabs" role="tablist">
    <?php foreach ($tabs as [$k, $u, $label]): ?><?= hx_link($u, e($label), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="issuer-tab-' . $k . '"' . ($active === $k ? ' aria-current="page"' : '')) ?><?php endforeach; ?>
</div>
