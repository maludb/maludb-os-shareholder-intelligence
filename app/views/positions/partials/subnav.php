<?php /** The positions screens' tabs and, where a window applies, the window form. Data: active, security, from?, to?, extra? (hidden fields) */
$sid = (int) $security['security_id'];
$tabs = [['positions', '/positions', 'Table'], ['matrix', '/positions/matrix', 'Movement'], ['flow', '/positions/flow', 'Flow by class'], ['concentration', '/positions/concentration', 'Concentration'], ['reconciliation', '/reconciliation', 'Reconciliation']];
?>
<div class="d-flex flex-wrap gap-1 mb-3" id="positions-tabs" role="tablist">
    <?php foreach ($tabs as [$k, $u, $l]): ?><?= hx_link($u . '?security=' . $sid, e($l), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="positions-tab-' . $k . '"') ?><?php endforeach; ?>
</div>
<?php if (isset($from)): ?>
<form method="get" action="<?= e(current_path()) ?>" class="row g-2 align-items-end mb-3" hx-get="<?= e(current_path()) ?>" hx-target="#page-content" hx-push-url="true" id="positions-window">
    <input type="hidden" name="security" value="<?= $sid ?>"><?php foreach (($extra ?? []) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div class="col-5 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="positions-window-from">From</label><input type="date" name="from" id="positions-window-from" value="<?= e($from) ?>" class="form-control btn-touch fs-12 px-2"></div>
    <div class="col-5 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="positions-window-to">To</label><input type="date" name="to" id="positions-window-to" value="<?= e($to) ?>" class="form-control btn-touch fs-12 px-2"></div>
    <div class="col-2 col-md-1"><button type="submit" class="btn btn-light btn-touch w-100" id="positions-window-btn" aria-label="Show the window"><i class="feather-refresh-cw"></i></button></div>
</form>
<?php endif; ?>
