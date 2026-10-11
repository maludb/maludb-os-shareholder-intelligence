<?php /** One event (screen `event-view`). Data: e, explains, notes, may, trail, tags, tz, back, notice */
$eid = (int) $e['event_id'];
$here = '/events/' . $eid;
$action = $may ? hx_link(with_back('/events/' . $eid . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch btn-sm me-2', 'id="event-edit-link"')
    . '<form method="post" action="/events/delete.php" hx-post="/events/delete.php" hx-target="#flash" hx-confirm="Delete this event? It stays recoverable until the purge window ends." class="d-inline">' . csrf_field()
    . '<input type="hidden" name="event" value="' . $eid . '"><button type="submit" class="btn btn-light-danger btn-touch btn-sm" id="event-delete-btn"><i class="feather-trash-2 me-1"></i>Delete</button></form>' : '';
?>
<?= view('shared/header.php', ['id' => 'event-view', 'title' => $e['title'], 'crumbs' => [['Home', '/'], ['Events', '/events'], [mb_strimwidth((string) $e['title'], 0, 40, '…'), null]], 'back' => $back, 'action' => $action]) ?>
<div class="main-content" id="event-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3" id="event-card"><div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><?= event_kind_chip((string) $e['kind'], ' id="event-kind"') ?><span id="event-severity" class="d-inline-flex align-items-center gap-1 fs-12"><?= event_severity_dot((string) $e['severity']) ?> <?= e(ucfirst((string) $e['severity'])) ?></span>
                    <span class="fs-12 text-muted" id="event-date"><?= e(format_date((string) $e['on_date'])) ?></span><?= $e['ticker'] ? '<span class="fs-12 text-muted">· ' . e((string) $e['ticker']) . '</span>' : '' ?></div>
                <h4 class="mb-3 text-break" id="event-title"><?= e((string) $e['title']) ?></h4>
                <?php if ($e['body'] !== null && $e['body'] !== ''): ?><div class="si-markdown" id="event-body"><?= markdown_html((string) $e['body']) ?></div><?php endif; ?>
                <?php if ($e['url'] !== null && $e['url'] !== ''): ?><p class="mt-2 mb-0"><a href="<?= e((string) $e['url']) ?>" rel="noopener noreferrer" target="_blank" id="event-url" class="fw-semibold"><i class="feather-external-link me-1"></i>The source</a></p><?php endif; ?>
                <p class="fs-11 text-muted mt-3 mb-0">Recorded <?= e(format_ts($e['created_at'], $tz, 'M j, Y')) ?> by <?= $e['source'] === 'worker' ? 'the worker, from a filing' : e((string) ($e['created_by_name'] ?? 'someone')) . ($e['source'] === 'agent' ? ' (agent)' : '') ?>.</p>
            </div></div>
            <?php if ($e['filing_id'] !== null): ?><div class="card mb-3" id="event-filing"><div class="card-header"><h5 class="card-title mb-0">The filing</h5></div><div class="card-body">
                <?= hx_link('/filings/' . (int) $e['filing_id'], e((string) $e['filing_form']) . ' of ' . e(format_date((string) $e['filing_filed_on'])), 'fw-semibold', 'id="event-filing-link"') ?>
                <?php if ($e['filing_accession']): ?><span class="fs-12 text-muted ms-2"><?= e((string) $e['filing_accession']) ?></span><?php endif; ?></div></div><?php endif; ?>
            <div class="card mb-3" id="event-explains"><div class="card-header"><h5 class="card-title mb-0">Signals it explains</h5></div>
                <?php if ($explains === []): ?><div class="card-body text-muted fs-12" id="event-explains-empty">No signal was dismissed with this event as its reason.</div>
                <?php else: ?><div class="list-group list-group-flush"><?php foreach ($explains as $s): ?>
                    <div class="list-group-item fs-12" id="event-signal-<?= (int) $s['signal_id'] ?>"><?= signal_severity_chip((string) $s['severity']) ?> <?= hx_link(with_back('/signals/' . (int) $s['signal_id'], $here), e((string) $s['rule_name']), 'fw-semibold') ?> · <?= e(format_date((string) $s['raised_on'])) ?> · <?= e((string) $s['status']) ?>
                        <div class="text-muted"><?= e((string) $s['summary']) ?></div></div>
                <?php endforeach; ?></div><?php endif; ?></div>
            <div class="card" id="event-notes-card"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'event', 'record' => $eid, 'notes' => $notes, 'here' => $here, 'tz' => $tz]) ?></div></div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-3" id="event-tags"><div class="card-header"><h5 class="card-title mb-0">Tags</h5></div><div class="card-body"><?= view('shared/tags.php', ['kind' => 'event', 'record' => $eid, 'tags' => $tags, 'here' => $here]) ?></div></div>
            <?= view('shared/timeline.php', ['rows' => $trail, 'tz' => $tz, 'prefix' => 'event']) ?>
        </div>
    </div>
</div>
