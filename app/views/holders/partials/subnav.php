<?php /** The holder screens' tabs, the security's record dates where a screen reads one. Data: active, security, channel?, dates?, date?, extra? */
$sid = (int) $security['security_id'];
$tabs = [['list', '/holders', 'Holders'], ['changes', '/holders/changes', 'Changes'], ['ranges', '/holders/ranges', 'Ranges'], ['geography', '/holders/geography', 'Geography'],
         ['custodians', '/holders/custodians', 'Custodians'], ['register', '/holders/register', 'Register']];
if (si_sees_pii()) { $tabs[] = ['unmatched', '/holders/unmatched', 'Unmatched']; }
?>
<div class="d-flex flex-wrap gap-1 mb-3" id="holders-tabs" role="tablist">
    <?php foreach ($tabs as [$k, $u, $l]): ?><?= hx_link($u . '?security=' . $sid, e($l), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="holders-tab-' . $k . '"') ?><?php endforeach; ?>
</div>
<?php if (isset($dates)): ?>
<form method="get" action="<?= e(current_path()) ?>" class="row g-2 align-items-end mb-3" hx-get="<?= e(current_path()) ?>" hx-target="#page-content" hx-push-url="true" id="holders-date-form">
    <input type="hidden" name="security" value="<?= $sid ?>"><?php foreach (($extra ?? []) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <?php if (isset($channel)): ?><div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="holders-channel">List</label>
        <select name="channel" id="holders-channel" class="form-select btn-touch fs-12" onchange="this.form.requestSubmit()"><?php foreach (HOLDER_CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $channel === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="holders-record-date">Record date</label>
        <select name="record_date" id="holders-record-date" class="form-select btn-touch fs-12" onchange="this.form.requestSubmit()"><?php if ($dates === []): ?><option value="">none loaded</option><?php endif; ?>
        <?php foreach ($dates as $d): ?><option value="<?= e($d) ?>" <?= ($date ?? null) === $d ? 'selected' : '' ?>><?= e(format_date($d)) ?></option><?php endforeach; ?></select></div>
</form>
<?php endif; ?>
