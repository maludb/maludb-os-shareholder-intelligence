<?php /** The issuer's timeline (screen `event-list`). Data: issuer, f, list, may, here, notice, undo? (a deleted event's id the person may take back) */
$iid = (int) $issuer['issuer_id'];
$undo = $undo ?? null;
$qs = static fn (array $over): string => '/events?' . http_build_query(array_filter(array_merge($f, $over), static fn ($v) => $v !== null && $v !== '' && $v !== []));
$sel = static fn (string $name, array $opts, ?string $val, string $label): string => '<div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="events-filter-' . $name . '">' . e($label) . '</label><select name="' . $name . '[]" id="events-filter-' . $name . '" class="form-select form-select-sm">'
    . implode('', array_map(static fn ($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($opts), $opts)) . '</select></div>';
$action = hx_link('/calendar', '<i class="feather-calendar me-1"></i>Calendar', 'btn btn-light btn-touch btn-sm me-2', 'id="events-calendar-link"')
    . ($may ? hx_link(with_back('/events/new', $here), '<i class="feather-plus me-1"></i>Record an event', 'btn btn-primary btn-touch btn-sm', 'id="events-add-link"') : '');
$byMonth = [];
foreach ($list['rows'] as $r) { $byMonth[substr((string) $r['on_date'], 0, 7)][] = $r; }
?>
<?= view('shared/header.php', ['id' => 'event-list', 'title' => 'Events', 'crumbs' => [['Home', '/'], ['Events', null]], 'action' => $action]) ?>
<div class="main-content" id="event-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($undo !== null): ?><form method="post" action="/events/restore.php" hx-post="/events/restore.php" hx-target="#flash" class="mb-3" id="events-undo-form"><?= csrf_field() ?><input type="hidden" name="event" value="<?= (int) $undo ?>">
        <button type="submit" class="btn btn-light btn-touch btn-sm" id="events-undo-btn"><i class="feather-rotate-ccw me-1"></i>Undo the delete</button></form><?php endif; ?>
    <form method="get" action="/events" hx-get="/events" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="events-filters"><div class="card-body py-2"><div class="row g-2 align-items-end">
        <?= $sel('kind', ['' => 'Any kind'] + EVENT_KINDS, $f['kind'][0] ?? null, 'Kind') ?>
        <?= $sel('severity', ['' => 'Any'] + EVENT_SEVERITIES, $f['severity'][0] ?? null, 'Severity') ?>
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="events-filter-from">From</label><input type="date" name="from" id="events-filter-from" class="form-control form-control-sm" value="<?= e((string) ($f['from'] ?? '')) ?>"></div>
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="events-filter-to">To</label><input type="date" name="to" id="events-filter-to" class="form-control form-control-sm" value="<?= e((string) ($f['to'] ?? '')) ?>"></div>
        <div class="col-12 col-md"><label class="form-label fs-11 text-muted mb-1" for="events-filter-q">Words</label><input type="search" name="q" id="events-filter-q" class="form-control form-control-sm" maxlength="100" value="<?= e((string) ($f['q'] ?? '')) ?>"></div>
        <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="events-filter-btn">Show</button></div>
    </div></div></form>
    <?php if ($list['rows'] === []): ?>
        <div class="card"><div class="card-body text-muted" id="events-empty"><?= $f['kind'] === [] && $f['severity'] === [] && $f['from'] === null && $f['to'] === null && $f['q'] === null
            ? 'No event is recorded for ' . e($issuer['name']) . ' yet. Filings of the watched forms make their own; a person records the rest.' : 'No event matches these filters.' ?></div></div>
    <?php else: ?>
        <div id="events-timeline">
        <?php foreach ($byMonth as $month => $rows): ?>
            <h6 class="fs-12 text-muted text-uppercase mt-3 mb-2" id="events-month-<?= e($month) ?>"><?= e(date('F Y', strtotime($month . '-01'))) ?></h6>
            <div class="card mb-2"><div class="list-group list-group-flush">
            <?php foreach ($rows as $r): $eid = (int) $r['event_id']; ?>
                <div class="list-group-item d-flex align-items-start gap-2" id="event-row-<?= $eid ?>" data-kind="<?= e((string) $r['kind']) ?>" data-severity="<?= e((string) $r['severity']) ?>">
                    <span class="mt-1"><?= event_severity_dot((string) $r['severity']) ?></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2"><span class="fs-12 text-muted text-nowrap"><?= e(format_date((string) $r['on_date'])) ?></span><?= event_kind_chip((string) $r['kind']) ?>
                            <?= hx_link(with_back('/events/' . $eid, $here), e((string) $r['title']), 'fw-semibold text-dark text-break', 'id="event-link-' . $eid . '"') ?></div>
                        <?php if ($r['filing_id'] !== null): ?><div class="fs-11 text-muted"><?= hx_link('/filings/' . (int) $r['filing_id'], e((string) $r['filing_form']) . ' of ' . e(format_date((string) $r['filing_filed_on'])), 'text-muted', 'id="event-filing-' . $eid . '"') ?></div><?php endif; ?>
                    </div>
                    <?php if ($r['source'] !== 'person'): ?><span class="badge bg-soft-secondary text-secondary fs-10"><?= e((string) $r['source']) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div></div>
        <?php endforeach; ?>
        </div>
        <?php if ($list['pages'] > 1): ?><nav class="d-flex justify-content-between align-items-center fs-12 mt-2" id="events-pages">
            <span class="text-muted"><?= (int) $list['total'] ?> events · page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
            <span class="d-flex gap-2"><?= $list['page'] > 1 ? hx_link($qs(['page' => $list['page'] - 1]), 'Newer', 'btn btn-light btn-sm', 'id="events-prev"') : '' ?><?= $list['page'] < $list['pages'] ? hx_link($qs(['page' => $list['page'] + 1]), 'Older', 'btn btn-light btn-sm', 'id="events-next"') : '' ?></span>
        </nav><?php endif; ?>
    <?php endif; ?>
    <p class="fs-11 text-muted mt-3" id="events-footnote">An event is what happened on a day: a filing of a watched form makes its own, a threshold day and a person's short report are recorded by a person. Signals can be explained by one.</p>
</div>
