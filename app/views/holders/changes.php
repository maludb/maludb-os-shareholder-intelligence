<?php /** Holders between two record dates (screen `holders-changes`). Data: security, channel, dates, a, b, state, ch, page, here */
$sid = (int) $security['security_id'];
$labels = ['new' => 'New', 'up' => 'Up', 'same' => 'Same', 'down' => 'Down', 'lost' => 'Lost'];
$chg = static fn ($v) => $v === null ? '—' : ((float) $v > 0 ? '<span class="text-success">+' . e(fmt_n($v)) . '</span>' : ((float) $v < 0 ? '<span class="text-danger">' . e(fmt_n($v)) . '</span>' : '0'));
?>
<?= view('shared/header.php', ['id' => 'holders-changes', 'title' => 'Holder changes', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Changes', null]]]) ?>
<div class="main-content" id="holders-changes-content">
    <?= view('holders/partials/subnav.php', ['active' => 'changes', 'security' => $security]) ?>
    <form method="get" action="/holders/changes" class="row g-2 align-items-end mb-3" hx-get="/holders/changes" hx-target="#page-content" hx-push-url="true" id="changes-form">
        <input type="hidden" name="security" value="<?= $sid ?>">
        <div class="col-12 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="changes-channel">List</label><select name="channel" id="changes-channel" class="form-select btn-touch"><?php foreach (HOLDER_CHANNELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $channel === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="changes-a">From</label><select name="date_a" id="changes-a" class="form-select btn-touch"><?php foreach ($dates as $d): ?><option value="<?= e($d) ?>" <?= $a === $d ? 'selected' : '' ?>><?= e(format_date($d)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="changes-b">To</label><select name="date_b" id="changes-b" class="form-select btn-touch"><?php foreach ($dates as $d): ?><option value="<?= e($d) ?>" <?= $b === $d ? 'selected' : '' ?>><?= e(format_date($d)) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="changes-btn">Compare</button></div>
    </form>
    <?php if ($a === null || $b === null): ?><div class="card"><div class="card-body text-muted" id="changes-empty">Two record dates of the <?= e(strtolower(HOLDER_CHANNELS[$channel])) ?> are needed to compare: <?= count($dates) ?> loaded.</div></div>
    <?php else: ?>
    <div class="row g-2 mb-3" id="changes-summary">
        <?php $sum = []; foreach ($ch['summary'] as $r) { $sum[$r['state']] = $r; } foreach ($labels as $k => $l): $r = $sum[$k] ?? null; ?>
        <div class="col-6 col-md"><a class="card mb-0 h-100 text-decoration-none <?= $state === $k ? 'border-primary' : '' ?>" href="/holders/changes?<?= e(http_build_query(['security' => $sid, 'channel' => $channel, 'date_a' => $a, 'date_b' => $b, 'state' => $state === $k ? null : $k])) ?>" hx-boost="true" hx-target="#page-content" id="changes-state-<?= $k ?>"><div class="card-body p-2 text-center">
            <div class="fs-11 text-muted"><?= e($l) ?></div><div class="fs-4 fw-bold text-dark"><?= number_format((int) ($r['holders'] ?? 0)) ?></div><div class="fs-11"><?= $chg($r['net'] ?? 0) ?></div></div></a></div>
        <?php endforeach; ?>
    </div>
    <?= view('shared/source-line.php', ['source' => 'the ' . strtolower(HOLDER_CHANNELS[$channel]) . 's of ' . format_date($a) . ' and ' . format_date($b), 'date' => $b]) ?>
    <div class="card mt-3" id="changes-holders"><div class="card-header"><h5 class="card-title mb-0"><?= si_sees_pii() ? 'The holders, the largest moves first' : 'The entities, the largest moves first' ?></h5></div><div class="card-body p-0">
        <?php if (!si_sees_pii()): ?><p class="fs-12 text-muted p-3 mb-0"><i class="bi bi-shield-lock me-1"></i>Natural persons are counted above, never named: names are an Analyst's.</p><?php endif; ?>
        <?php if ($ch['rows'] === []): ?><div class="p-3 fs-12 text-muted">None.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="changes-table"><thead class="thead-light"><tr><th>Holder</th><th class="d-none d-sm-table-cell">Change</th><th class="text-end d-none d-sm-table-cell"><?= e(date('M j', strtotime($a))) ?></th><th class="text-end"><?= e(date('M j', strtotime($b))) ?></th><th class="text-end">Net</th></tr></thead><tbody>
        <?php foreach ($ch['rows'] as $r): ?><tr id="changes-row-<?= (int) $r['holder_id'] ?>"><td class="text-wrap"><?= hx_link(with_back('/holders/' . (int) $r['holder_id'], $here), e((string) $r['display_name']), '') ?><div class="d-sm-none fs-11 text-muted"><?= e($labels[$r['state']] ?? $r['state']) ?></div></td>
            <td class="d-none d-sm-table-cell"><?= e($labels[$r['state']] ?? $r['state']) ?></td><td class="text-end d-none d-sm-table-cell"><?= e(fmt_n($r['shares_a'])) ?></td><td class="text-end"><?= e(fmt_n($r['shares_b'])) ?></td><td class="text-end"><?= $chg($r['net']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (($ch['total'] ?? 0) > $page * 50): ?><div class="p-2"><?= hx_link('/holders/changes?' . http_build_query(['security' => $sid, 'channel' => $channel, 'date_a' => $a, 'date_b' => $b, 'state' => $state, 'page' => $page + 1]), 'More', 'btn btn-light btn-touch w-100', 'id="changes-more"') ?></div><?php endif; ?>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
