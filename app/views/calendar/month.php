<?php /** The month grid (screen `calendar`). Data: issuer, cal, may, here */
$at = $cal['month'];
$nav = static fn (string $m): string => '/calendar?at=' . $m . ($cal['kinds'] !== [] ? '&kinds=' . rawurlencode(implode(',', $cal['kinds'])) : '');
$dot = ['high' => 'bg-danger', 'watch' => 'bg-warning', 'info' => 'bg-info'];
$action = hx_link($nav($cal['prev']), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch btn-sm me-1', 'id="calendar-prev" aria-label="Previous month"')
    . hx_link('/calendar', 'Today', 'btn btn-light btn-touch btn-sm me-1', 'id="calendar-today"')
    . hx_link($nav($cal['next']), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch btn-sm me-2', 'id="calendar-next" aria-label="Next month"')
    . ($may ? hx_link(with_back('/events/new', $here), '<i class="feather-plus me-1"></i>Record an event', 'btn btn-primary btn-touch btn-sm', 'id="calendar-add-link"') : '');
?>
<?= view('shared/header.php', ['id' => 'calendar', 'title' => 'Calendar', 'crumbs' => [['Home', '/'], ['Events', '/events'], ['Calendar', null]], 'action' => $action]) ?>
<div class="main-content" id="calendar-content">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h5 class="mb-0" id="calendar-month-label"><?= e($cal['label']) ?></h5><span class="fs-12 text-muted"><?= e($issuer['name']) ?></span>
        <form method="get" action="/calendar" hx-get="/calendar" hx-target="#page-content" hx-push-url="true" class="ms-auto d-flex gap-2 align-items-center" id="calendar-filter"><input type="hidden" name="at" value="<?= e($at) ?>">
            <label class="visually-hidden" for="calendar-filter-kinds">Kind</label><select name="kinds" id="calendar-filter-kinds" class="form-select form-select-sm"><option value="">Every kind</option>
                <?php foreach (EVENT_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $cal['kinds'] === [$k] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="calendar-filter-btn">Show</button></form></div>
    <div class="si-cal mb-3" id="calendar-grid" role="grid" aria-label="<?= e($cal['label']) ?>">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $h): ?><div class="si-cal-head" role="columnheader"><?= $h ?></div><?php endforeach; ?>
        <?php foreach ($cal['weeks'] as $week): foreach ($week as $d): $date = $d['date']; $n = count($d['items']); ?>
            <div class="si-cal-day<?= $d['in_month'] ? '' : ' si-cal-out' ?><?= $date === $cal['today'] ? ' si-cal-today' : '' ?>" id="calendar-cell-<?= e($date) ?>" role="gridcell" data-count="<?= $n ?>">
                <?= $n > 0 ? hx_link('/events?from=' . $date . '&to=' . $date, e((string) (int) substr($date, 8, 2)), 'si-cal-num text-dark', 'id="calendar-day-' . e($date) . '" title="' . $n . ' on this day"')
                           : '<span class="si-cal-num" id="calendar-day-' . e($date) . '">' . e((string) (int) substr($date, 8, 2)) . '</span>' ?>
                <?php foreach (array_slice($d['items'], 0, 3) as $i): $href = upcoming_href($i); ?>
                    <?php $chipClass = 'si-cal-chip ' . (EVENT_KIND_CHIPS[$i['kind']] ?? 'bg-soft-secondary text-secondary') . ($i['type'] === 'computed' ? ' si-cal-muted' : ''); ?>
                    <?= $href !== null ? hx_link($href, e((string) $i['title']), $chipClass . ' text-decoration-none', 'title="' . e(event_kind_label((string) $i['kind']) . ': ' . $i['title']) . '" data-kind="' . e((string) $i['kind']) . '" data-when="' . e((string) $i['when']) . '"')
                                      : '<span class="' . e($chipClass) . '" title="' . e(event_kind_label((string) $i['kind']) . ': ' . $i['title']) . '" data-kind="' . e((string) $i['kind']) . '" data-when="' . e((string) $i['when']) . '">' . e((string) $i['title']) . '</span>' ?>
                <?php endforeach; ?>
                <?php if ($n > 3): ?><?= hx_link('/events?from=' . $date . '&to=' . $date, '+' . ($n - 3) . ' more', 'fs-10 text-muted', 'id="calendar-more-' . e($date) . '"') ?><?php endif; ?>
                <?php if ($n > 0): ?><span class="si-cal-dots" aria-hidden="true"><?php foreach ($d['items'] as $i): ?><span class="si-cal-dot <?= $i['type'] === 'computed' ? 'bg-secondary' : e($dot[$i['severity']] ?? 'bg-secondary') ?>"></span><?php endforeach; ?></span><?php endif; ?>
            </div>
        <?php endforeach; endforeach; ?>
    </div>
    <div class="card" id="calendar-agenda"><div class="card-header"><h5 class="card-title mb-0">This month</h5></div>
        <?php if ($cal['agenda'] === []): ?><div class="card-body text-muted fs-12" id="calendar-empty">Nothing is recorded or expected in <?= e($cal['label']) ?>.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($cal['agenda'] as $date => $items): ?>
                <div class="list-group-item" id="calendar-agenda-<?= e($date) ?>"><div class="fs-12 fw-semibold mb-1"><?= hx_link('/events?from=' . $date . '&to=' . $date, e(format_date($date)), 'text-dark') ?></div>
                    <?php foreach ($items as $i): $href = upcoming_href($i); ?>
                        <div class="d-flex flex-wrap align-items-center gap-2 fs-12 mb-1"><?= event_kind_chip((string) $i['kind']) ?><?= $href !== null ? hx_link($href, e((string) $i['title']), 'text-dark') : '<span class="' . ($i['type'] === 'computed' ? 'text-muted fst-italic' : '') . '">' . e((string) $i['title']) . '</span>' ?>
                            <?= $i['when'] === 'coming' ? '<span class="badge bg-soft-info text-info fs-10">coming</span>' : '' ?></div>
                    <?php endforeach; ?></div>
            <?php endforeach; ?></div><?php endif; ?></div>
    <p class="fs-11 text-muted mt-3" id="calendar-footnote">Past events are chips by kind. Coming: the record dates, earnings and expiries a person recorded, and a dilution instrument's expiry. The muted, italic days are computed, not recorded: FINRA publishes about seven market days after each settlement date, and the 13F is due 45 days after each quarter.</p>
</div>
