<?php /** The signals of the current issuer as cards by severity (screen `signal-list`). Data: issuer, f, list, securities, rules, may, canRun, here, notice */
$iid = (int) $issuer['issuer_id'];
$runSec = (int) ($f['security'] ?? 0) ?: (int) ($issuer['primary_security_id'] ?? 0);
$qs = static fn (array $over): string => '/signals?' . http_build_query(array_filter(array_merge($f, $over), static fn ($v) => $v !== null && $v !== ''));
$sel = static fn (string $name, array $opts, ?string $val, string $label): string => '<div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="signals-filter-' . $name . '">' . e($label) . '</label><select name="' . $name . '" id="signals-filter-' . $name . '" class="form-select form-select-sm">'
    . implode('', array_map(static fn ($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($opts), $opts)) . '</select></div>';
$action = '';
if ($canRun && $runSec > 0) {
    $action = '<form method="post" action="/signals/run.php" hx-post="/signals/run.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="security" value="' . $runSec . '"><input type="hidden" name="return_to" value="' . e($here) . '">'
        . '<button type="submit" class="btn btn-primary btn-touch btn-sm" id="signals-run-btn"><i class="feather-play me-1"></i>Run the rules</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'signal-list', 'title' => 'Signals', 'crumbs' => [['Home', '/'], ['Signals', null]], 'action' => $action]) ?>
<div class="main-content" id="signal-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3 fs-12" id="signals-counts">
        <?php foreach (['high' => 'High', 'watch' => 'Watch', 'info' => 'Info'] as $k => $w): ?>
            <?= hx_link($qs(['severity' => $f['severity'] === $k ? null : $k, 'page' => null]), signal_severity_chip($k) . ' <span class="fw-semibold">' . (int) $list['counts'][$k] . '</span> ' . e($w), 'text-dark text-decoration-none' . ($f['severity'] === $k ? ' fw-bold' : ''), 'id="signals-count-' . $k . '"') ?>
        <?php endforeach; ?>
        <span class="ms-auto d-flex gap-3"><?= hx_link('/signals/history', '<i class="feather-clock me-1"></i>History', 'fw-semibold', 'id="signals-history-link"') ?><?= hx_link('/rules', '<i class="feather-sliders me-1"></i>The rules', 'fw-semibold', 'id="signals-rules-link"') ?></span>
    </div>
    <form method="get" action="/signals" hx-get="/signals" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="signals-filters"><div class="card-body py-2"><div class="row g-2 align-items-end">
        <?= $sel('status', SIGNAL_STATUS_FILTERS, $f['status'], 'Status') ?>
        <?= $sel('severity', ['' => 'Any', 'high' => 'High', 'watch' => 'Watch', 'info' => 'Info'], $f['severity'], 'Severity') ?>
        <?= $sel('class', ['' => 'Any'] + array_map(static fn ($c) => $c[1], SIGNAL_CLASS_CHIPS), $f['class'], 'Evidence class') ?>
        <?= $sel('rule', ['' => 'Any rule'] + array_column($rules, 'name', 'key'), $f['rule'], 'Rule') ?>
        <?php if (count($securities) > 1): ?><?= $sel('security', ['' => 'Every security'] + array_column(array_map(static fn ($x) => ['k' => $x['security_id'], 'v' => $x['name'] . ($x['ticker'] ? ' (' . $x['ticker'] . ')' : '')], $securities), 'v', 'k'), (string) ($f['security'] ?? ''), 'Security') ?><?php endif; ?>
        <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="signals-filter-btn">Show</button></div>
    </div></div></form>
    <?php if ($list['rows'] === []): ?>
        <div class="card"><div class="card-body text-muted" id="signals-empty"><?= $f['status'] === 'live' && $f['severity'] === null && $f['rule'] === null && $f['class'] === null
            ? 'No signal needs a person on ' . e($issuer['name']) . '. The rules run after every upload and every pull, and at 06:45 on market days.' : 'No signal matches these filters.' ?></div></div>
    <?php else: ?>
        <div id="signals-cards"><?php foreach ($list['rows'] as $s): ?><?= view('signals/partials/card.php', ['s' => $s, 'may' => $may, 'here' => $here]) ?><?php endforeach; ?></div>
        <?php if ($list['pages'] > 1): ?><nav class="d-flex justify-content-between align-items-center fs-12 mt-2" id="signals-pages">
            <span class="text-muted"><?= (int) $list['total'] ?> signals · page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
            <span class="d-flex gap-2"><?= $list['page'] > 1 ? hx_link($qs(['page' => $list['page'] - 1]), 'Newer', 'btn btn-light btn-sm', 'id="signals-prev"') : '' ?><?= $list['page'] < $list['pages'] ? hx_link($qs(['page' => $list['page'] + 1]), 'Older', 'btn btn-light btn-sm', 'id="signals-next"') : '' ?></span>
        </nav><?php endif; ?>
    <?php endif; ?>
    <p class="fs-11 text-muted mt-3" id="signals-footnote">A signal advises: every one carries its rule's evidence class — established, contested, lore, or a data defect — and a person decides what it means. Nothing here scores, trades or sends.</p>
</div>
