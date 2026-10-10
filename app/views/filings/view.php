<?php /** One filing (screen `filing-view`). Data: f, q, win, ctx, back, notice */
$fid = (int) $f['filing_id'];
$items = pg_text_array((string) $f['items']);
$ev = $ctx['event'];
?>
<?= view('shared/header.php', ['id' => 'filing-view', 'title' => $f['form'] . ' — ' . format_date($f['filed_on']), 'crumbs' => [['Home', '/'], ['Filings', '/filings'], [$f['form'], null]], 'back' => $back]) ?>
<div class="main-content" id="filing-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8 order-2 order-xl-1">
            <div class="card mb-3" id="filing-text-card"><div class="card-header"><h5 class="card-title mb-0">The text</h5></div><div class="card-body">
                <form method="get" action="/filings/<?= $fid ?>" class="input-group mb-3" id="filing-find" hx-get="/filings/<?= $fid ?>" hx-target="#page-content" hx-push-url="true" role="search">
                    <input type="search" name="q" value="<?= e($q) ?>" class="form-control btn-touch" placeholder="A word or a phrase in this filing" id="filing-find-q" aria-label="Find in the filing">
                    <button type="submit" class="btn btn-light btn-touch" id="filing-find-btn" aria-label="Find"><i class="feather-search"></i></button></form>
                <?php if (!$f['has_text']): ?><div class="fs-12 text-muted" id="filing-no-text"><?= $f['error'] ? '<span class="text-danger">' . e((string) $f['error']) . '</span> ' : '' ?>The text is not read yet<?= $f['watched'] ? ' (a watched form: the next pull reads it)' : '' ?>.</div>
                <?php else: ?><?= view('filings/partials/text.php', ['f' => $f, 'win' => $win, 'q' => $q]) ?><?php endif; ?>
            </div></div>
        </div>
        <div class="col-xl-4 order-1 order-xl-2">
            <div class="card mb-3" id="filing-facts"><div class="card-body fs-12"><dl class="row mb-0">
                <dt class="col-5 text-muted fw-normal">Form</dt><dd class="col-7"><?= form_chip((string) $f['form']) ?><?= $f['about_us'] ? ' <span class="badge bg-soft-danger text-danger"><i class="bi bi-box-arrow-in-down-left me-1"></i>about us</span>' : '' ?></dd>
                <dt class="col-5 text-muted fw-normal">Filed</dt><dd class="col-7"><?= e(format_date($f['filed_on'])) ?><?= $f['report_date'] ? ' · for ' . e(format_date($f['report_date'])) : '' ?></dd>
                <?php if ($items !== []): ?><dt class="col-5 text-muted fw-normal">Items</dt><dd class="col-7"><?= e(implode(', ', $items)) ?></dd><?php endif; ?>
                <dt class="col-5 text-muted fw-normal">Accession</dt><dd class="col-7 text-break"><?= e($f['accession_number']) ?></dd>
                <?php if ($f['about_us']): ?><dt class="col-5 text-muted fw-normal">Filer</dt><dd class="col-7">CIK <?= e(ltrim((string) $f['filer_cik'], '0')) ?></dd><?php endif; ?>
                <dt class="col-5 text-muted fw-normal">At EDGAR</dt><dd class="col-7 text-break"><a href="<?= e(str_starts_with((string) $f['url'], 'https://www.sec.gov/') ? (string) $f['url'] : '#') ?>" rel="noopener" target="_blank" id="filing-edgar-link">the primary document <i class="feather-external-link"></i></a></dd>
            </dl></div></div>
            <div class="card mb-3" id="filing-event"><div class="card-header"><h5 class="card-title mb-0">The event</h5></div><div class="card-body fs-12">
                <?php if ($ev !== null): ?><div id="filing-event-made"><?= e((string) $ev['title']) ?> <span class="badge bg-soft-<?= $ev['severity'] === 'high' ? 'danger text-danger' : ($ev['severity'] === 'watch' ? 'warning text-warning' : 'secondary text-secondary') ?>"><?= e((string) $ev['severity']) ?></span></div>
                <?php elseif (has_right('events.write')): ?>
                <form method="post" action="/filings/event-make.php" hx-post="/filings/event-make.php" hx-target="#flash" id="filing-event-form"><?= csrf_field() ?><input type="hidden" name="filing" value="<?= $fid ?>">
                    <label class="form-label" for="filing-event-field-title">Title</label><input type="text" name="title" id="filing-event-field-title" class="form-control btn-touch mb-2" maxlength="300" value="<?= e($f['form'] . ' filed' . ($items ? ' — items ' . implode(', ', $items) : '')) ?>">
                    <label class="form-label" for="filing-event-field-severity">Severity</label><select name="severity" id="filing-event-field-severity" class="form-select btn-touch mb-2"><option value="info">info</option><option value="watch">watch</option><option value="high">high</option></select>
                    <button type="submit" class="btn btn-light btn-touch w-100" id="filing-event-btn">Make the event</button></form>
                <?php else: ?><span class="text-muted">No event.</span><?php endif; ?>
            </div></div>
            <?php if ($ctx['structured'] !== []): ?><div class="card mb-3" id="filing-structured"><div class="card-body"><?= view('filings/partials/structured.php', ['structured' => $ctx['structured']]) ?></div></div><?php endif; ?>
            <?php if ($ctx['hits'] !== []): ?><div class="card mb-3" id="filing-watch"><div class="card-body fs-12"><div class="fw-semibold mb-1">The watch found</div>
                <?php foreach ($ctx['hits'] as $w): ?><div><span class="badge text-bg-warning"><?= e($w['term']) ?></span> <span class="text-muted"><?= e($w['status']) ?></span></div><?php endforeach; ?></div></div><?php endif; ?>
            <?php if (has_right('events.write')): ?><form method="post" action="/filings/fetch-text.php" hx-post="/filings/fetch-text.php" hx-target="#flash" class="mb-3"><?= csrf_field() ?><input type="hidden" name="filing" value="<?= $fid ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="filing-fetch-btn"><i class="bi bi-file-text me-1"></i><?= $f['has_text'] ? 'Read the text again' : 'Read the text now' ?></button></form><?php endif; ?>
        </div>
    </div>
</div>
