<?php /** One participant (screen `participant-view`): the series in the security, the latest and its share of the DTC total, the class and its source, aliases, notes, tags. Data: p, security, from, to, points, presence, notes, tz, back, notice, here */
$pid = (int) $p['participant_id'];
require_once dirname(__DIR__, 2) . '/si/svg.php';
$sources = ['seeded' => 'seeded from DTCC\'s listing', 'directory_name' => 'from the directory\'s name', 'manual' => 'set by a person'];
?>
<?= view('shared/header.php', ['id' => 'participant-view', 'title' => $p['number'] . ' ' . $p['name'], 'crumbs' => [['Home', '/'], ['Participants', '/participants'], [$p['number'], null]], 'back' => $back,
    'action' => has_right('issuers.manage') ? hx_link('/participants/' . $pid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="participant-edit-btn"') : '']) ?>
<div class="main-content" id="participant-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8 order-2 order-xl-1">
            <div class="card mb-3" id="participant-series"><div class="card-header"><h5 class="card-title mb-0"><?= $security !== null ? e($security['ticker'] ?: $security['name']) . ' at this participant' : 'Positions' ?></h5></div><div class="card-body">
                <?php if ($security !== null): ?><form method="get" action="/participants/<?= $pid ?>" class="row g-2 align-items-end mb-3" hx-get="/participants/<?= $pid ?>" hx-target="#page-content" hx-push-url="true" id="participant-window">
                    <input type="hidden" name="security" value="<?= (int) $security['security_id'] ?>">
                    <div class="col-5"><label class="form-label fs-11 text-muted mb-1" for="participant-window-from">From</label><input type="date" name="from" id="participant-window-from" value="<?= e($from) ?>" class="form-control btn-touch fs-12 px-2"></div>
                    <div class="col-5"><label class="form-label fs-11 text-muted mb-1" for="participant-window-to">To</label><input type="date" name="to" id="participant-window-to" value="<?= e($to) ?>" class="form-control btn-touch fs-12 px-2"></div>
                    <div class="col-2"><button type="submit" class="btn btn-light btn-touch w-100" id="participant-window-btn" aria-label="Show the window"><i class="feather-refresh-cw"></i></button></div></form><?php endif; ?>
                <?php if ($security === null): ?><div class="fs-12 text-muted">Choose an issuer with a security to see this participant's positions in it.</div>
                <?php elseif ($points === []): ?><div class="fs-12 text-muted" id="participant-series-empty">No position in <?= e($security['name']) ?> between <?= e(format_date($from)) ?> and <?= e(format_date($to)) ?>.</div>
                <?php else: ?>
                    <?= svg_series(array_map(static fn (array $r): array => ['d' => $r['position_date'], 'v' => $r['shares']], $points), 'participant-series-chart', 'Shares held by ' . $p['name'] . ' by position date') ?>
                    <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports uploaded', 'date' => $points[count($points) - 1]['position_date']]) ?>
                    <details class="mt-2 fs-12"><summary>As a table</summary><div class="table-responsive"><table class="table table-sm mb-0" id="participant-series-table"><thead><tr><th>Date</th><th class="text-end">Shares</th><th class="text-end">Change</th></tr></thead><tbody>
                        <?php foreach (array_reverse($points) as $r): ?><tr><td><?= e(format_date($r['position_date'])) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td><td class="text-end <?= (float) $r['change'] > 0 ? 'text-success' : ((float) $r['change'] < 0 ? 'text-danger' : '') ?>"><?= $r['change'] === null ? '—' : e(((float) $r['change'] > 0 ? '+' : '') . fmt_n($r['change'])) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div></details>
                <?php endif; ?>
            </div></div>
            <div class="card mb-3" id="participant-nobo"><div class="card-body fs-12 text-muted">The NOBO count and shares on each record date, and the OBO remainder beside its SPR position, appear here once NOBO lists are uploaded (slice 3).</div></div>
            <div class="card mb-3" id="participant-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'participant', 'record' => $pid, 'notes' => $notes, 'here' => '/participants/' . $pid, 'tz' => $tz]) ?></div></div>
        </div>
        <div class="col-xl-4 order-1 order-xl-2">
            <div class="card mb-3" id="participant-facts"><div class="card-body fs-12"><dl class="row mb-0">
                <dt class="col-5 text-muted fw-normal">Number</dt><dd class="col-7 fw-semibold"><?= e($p['number']) ?></dd>
                <dt class="col-5 text-muted fw-normal">Class</dt><dd class="col-7"><?= class_chip(db(), (string) $p['class_key']) ?><div class="text-muted"><?= e($sources[$p['class_source']] ?? $p['class_source']) ?></div></dd>
                <?php if ($presence['last'] !== null): ?>
                    <dt class="col-5 text-muted fw-normal">Latest</dt><dd class="col-7" id="participant-latest"><?= e(fmt_n(end($points)['shares'] ?? null)) ?> on <?= e(format_date($presence['last'])) ?><?= $presence['pct_of_total'] !== null ? ' · ' . e($presence['pct_of_total']) . '% of the DTC total' : '' ?></dd>
                    <dt class="col-5 text-muted fw-normal">Present</dt><dd class="col-7"><?= (int) $presence['days'] ?> position date<?= (int) $presence['days'] === 1 ? '' : 's' ?> since <?= e(format_date($presence['first'])) ?></dd>
                <?php endif; ?>
                <dt class="col-5 text-muted fw-normal">In the directory</dt><dd class="col-7"><?= $p['last_seen_in_directory'] !== null ? e(format_date($p['last_seen_in_directory'])) : 'not yet read' ?><?= $p['active'] ? '' : ' <span class="badge bg-soft-secondary text-secondary">inactive</span>' ?></dd>
                <?php $al = pg_text_array((string) $p['aliases']); if ($al !== []): ?><dt class="col-5 text-muted fw-normal">Former names</dt><dd class="col-7" id="participant-aliases"><?= e(implode('; ', $al)) ?></dd><?php endif; ?>
                <?php if ($p['notes']): ?><dt class="col-5 text-muted fw-normal">About</dt><dd class="col-7"><?= nl2br(e($p['notes'])) ?></dd><?php endif; ?>
            </dl></div></div>
            <div class="card mb-3" id="participant-tags"><div class="card-header"><h5 class="card-title mb-0">Tags</h5></div><div class="card-body"><?= view('shared/tags.php', ['kind' => 'participant', 'record' => $pid, 'tags' => pg_text_array((string) $p['tags']), 'here' => '/participants/' . $pid]) ?></div></div>
        </div>
    </div>
</div>
