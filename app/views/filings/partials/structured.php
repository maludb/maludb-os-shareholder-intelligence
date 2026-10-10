<?php /** The structured rows a filing produced (slice 5's tables: a 13D/G, Forms 3/4/5, a 144), shown when present. Data: structured */ ?>
<?php foreach ($structured as $k => $rows): ?>
<div class="mb-2" id="filing-structured-<?= e($k) ?>"><div class="fw-semibold fs-12"><?= e(['beneficial' => 'Beneficial ownership (13D/G)', 'insider' => 'Insider transactions', 'form144' => 'Form 144 notice'][$k] ?? $k) ?></div>
    <div class="fs-12 text-muted"><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> read from the filing's XML.</div></div>
<?php endforeach; ?>
