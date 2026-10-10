<?php /** A positions or upload screen with no issuer (or no security) to read. Data: id, title, issuer? */ ?>
<?= view('shared/header.php', ['id' => $id, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]]) ?>
<div class="main-content" id="<?= e($id) ?>-content"><div class="card"><div class="card-body text-muted" id="<?= e($id) ?>-no-issuer">
    <?= ($issuer ?? null) === null ? 'Choose an issuer from the header first.' : e($issuer['name']) . ' has no primary security yet: ' . hx_link('/issuers/' . (int) $issuer['issuer_id'] . '/securities', 'add its securities', 'fw-semibold') . '.' ?>
</div></div></div>
