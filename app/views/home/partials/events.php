<?php /** The next 30 days' events. Data: events */ $dot = ['high' => 'danger', 'watch' => 'warning', 'info' => 'info']; ?>
<div class="card mb-3" id="home-events"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Coming 30 days</h5><?= hx_link('/calendar', 'Calendar', 'ms-auto fs-12 fw-semibold') ?></div><div class="card-body">
<?php if ($events === []): ?>
    <div class="text-muted fs-12" id="home-events-empty">Nothing recorded for the next 30 days.</div>
<?php else: ?><ul class="list-unstyled mb-0">
    <?php foreach ($events as $e): ?><li class="d-flex align-items-start gap-2 mb-2" id="home-event-<?= (int) $e['event_id'] ?>">
        <span class="wd-10 ht-10 rounded-circle bg-<?= e($dot[$e['severity']] ?? 'info') ?> mt-1 flex-shrink-0 d-inline-block"></span>
        <div class="min-w-0"><div class="fs-12 text-muted"><?= e(format_date($e['on_date'])) ?> · <?= e(str_replace('_', ' ', $e['kind'])) ?></div><?= hx_link(with_back('/events/' . (int) $e['event_id'], '/'), e($e['title']), 'fw-semibold text-dark') ?></div>
    </li><?php endforeach; ?>
</ul><?php endif; ?>
</div></div>
