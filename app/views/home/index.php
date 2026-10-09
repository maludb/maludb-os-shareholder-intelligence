<?php
/** The home for the current issuer (screen `home`, sso-shell.md). Data: s (home_summary), tz */
$i = $s['issuer'];
$sum = $s['summary'] ?? [];
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => $i['name'], 'crumbs' => [['Home', null]],
    'action' => $s['may']['upload'] ? hx_link('/uploads/new', '<i class="feather-upload-cloud me-1"></i>Upload a file', 'btn btn-primary btn-touch', 'id="home-upload-btn"') : '']) ?>
<div class="main-content" id="home-content">
    <?php if ($s['note'] !== null): ?><?= view('home/partials/note.php', ['note' => $s['note'], 'tz' => $tz]) ?><?php endif; ?>
    <?php if ($s['security'] === null): ?>
        <div class="alert alert-warning" id="home-no-security">This issuer has no primary security yet. <?= hx_link('/issuers/' . (int) $i['issuer_id'] . '/securities', 'Add its securities', 'alert-link') ?> to see positions, short interest and prices.</div>
    <?php endif; ?>
    <?= view('home/partials/tiles.php', ['sum' => $sum, 'counts' => $s['counts'], 'issuer' => $i]) ?>
    <div class="row g-3">
        <div class="col-xl-7">
            <?= view('home/partials/overlay.php', ['overlay' => $s['overlay'], 'security' => $s['security']]) ?>
            <?= view('home/partials/uploads.php', ['uploads' => $s['uploads'], 'tz' => $tz, 'may' => $s['may']]) ?>
        </div>
        <div class="col-xl-5">
            <?= view('home/partials/signals.php', ['signals' => $s['signals']]) ?>
            <?= view('home/partials/events.php', ['events' => $s['events']]) ?>
        </div>
    </div>
</div>
