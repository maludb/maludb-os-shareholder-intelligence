<?php /** Several issuers visible and none chosen yet: one card each (the cards rule). Data: issuers */ ?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <p class="text-muted" id="home-choose-note">Choose the issuer to work on. You can switch at any time from the header.</p>
    <div class="row g-3" id="home-choose">
        <?php foreach ($issuers as $i): $id = (int) $i['issuer_id']; ?>
            <div class="col-sm-6 col-xl-4"><a class="card h-100 text-reset" href="/?issuer=<?= $id ?>" id="home-choose-<?= $id ?>"><div class="card-body d-flex align-items-center gap-3">
                <span class="avatar-text avatar-lg rounded fw-bold"><?= e(mb_substr((string) ($i['ticker'] ?: $i['name']), 0, 4)) ?></span>
                <div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($i['name']) ?></div><div class="fs-12 text-muted"><?= e(implode(' · ', array_filter([$i['ticker'], $i['exchange']]))) ?></div></div>
                <?php if (!empty($i['restricted'])): ?><i class="feather-lock ms-auto text-warning" title="Restricted to named members"></i><?php endif; ?>
            </div></a></div>
        <?php endforeach; ?>
    </div>
</div>
