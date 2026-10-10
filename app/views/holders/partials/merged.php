<?php /** A merged holder's old address (404): where it went. Data: into */ ?>
<?= view('shared/header.php', ['id' => 'holder-merged', 'title' => 'Merged', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Merged', null]]]) ?>
<div class="main-content"><div class="card"><div class="card-body" id="holder-merged-into">This holder was merged into another: <?= hx_link('/holders/' . (int) $into, 'holder ' . (int) $into, 'fw-semibold', 'id="holder-merged-link"') ?>.</div></div></div>
