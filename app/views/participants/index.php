<?php /** The DTC participants (screen `participant-list`) as cards, filtered by class; the latest position in the current security on each. Data: rows, classes, class, q, security, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'participant-list', 'title' => 'DTC participants', 'crumbs' => [['Home', '/'], ['Participants', null]]]) ?>
<div class="main-content" id="participant-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/participants" class="mb-2" id="participant-search" hx-get="/participants" hx-target="#page-content" hx-push-url="true" role="search">
        <?php if ($class !== ''): ?><input type="hidden" name="class" value="<?= e($class) ?>"><?php endif; ?>
        <div class="input-group"><input type="search" name="q" value="<?= e($q) ?>" class="form-control btn-touch" placeholder="A number (0164), a name or an old name" id="participant-search-q" aria-label="Find a participant">
            <button class="btn btn-light btn-touch" type="submit" aria-label="Find" id="participant-search-btn"><i class="feather-search"></i></button></div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="participant-classes">
        <?= hx_link('/participants' . ($q !== '' ? '?q=' . rawurlencode($q) : ''), 'Every class', 'btn btn-sm btn-touch ' . ($class === '' ? 'btn-primary' : 'btn-light'), 'id="participant-class-all"') ?>
        <?php foreach ($classes as $k => [$name]): ?><?= hx_link('/participants?' . http_build_query(array_filter(['class' => $k, 'q' => $q])), e($name), 'btn btn-sm btn-touch ' . ($class === $k ? 'btn-primary' : 'btn-light'), 'id="participant-class-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <p class="fs-12 text-muted" id="participant-class-caveat">A class is a setting a manager corrects, not a fact about the participant's customers. Positions shown are in <?= $security !== null ? e($security['name'] . ' (' . ($security['ticker'] ?: $security['cusip']) . ')') : 'no security yet — choose an issuer' ?>.</p>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="participant-list-empty">No participant matches.</div></div>
    <?php else: ?><div class="row g-3" id="participant-cards">
        <?php foreach ($rows as $p): $pid = (int) $p['participant_id']; ?>
            <div class="col-sm-6 col-xl-4" id="participant-card-<?= $pid ?>"><div class="card h-100 mb-0"><div class="card-body">
                <div class="d-flex align-items-start gap-3"><span class="avatar-text avatar-lg rounded fw-bold fs-12"><?= e($p['number']) ?></span>
                    <div class="min-w-0 flex-grow-1"><div class="fw-semibold"><?= hx_link(with_back('/participants/' . $pid, $here), e($p['name']), 'text-dark', 'id="participant-card-' . $pid . '-link"') ?></div>
                        <div class="mt-1"><?= class_chip(db(), (string) $p['class_key']) ?><?= $p['class_source'] === 'manual' ? ' <span class="fs-11 text-muted">set by a person</span>' : '' ?></div></div></div>
                <?php $al = pg_text_array((string) $p['aliases']); if ($al !== []): ?><div class="fs-11 text-muted mt-2">Formerly <?= e(implode('; ', array_slice($al, 0, 2))) ?></div><?php endif; ?>
                <div class="fs-12 mt-2"><?= $p['position_date'] !== null ? '<span class="fw-semibold">' . e(fmt_n($p['shares'])) . '</span> shares on ' . e(format_date($p['position_date'])) : '<span class="text-muted">No position in this security</span>' ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div><?php endif; ?>
</div>
