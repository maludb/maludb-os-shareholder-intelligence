<?php /** The line under every number and chart (sso-shell.md): "Source: FINRA short interest · settlement Mar 28, 2024". Data: source, date?, extra? */ ?>
<div class="fs-11 text-muted si-source-line"><i class="feather-info me-1"></i>Source: <?= e($source) ?><?= ($date ?? null) ? ' · ' . e(format_date((string) $date)) : '' ?><?= ($extra ?? '') !== '' ? ' · ' . e($extra) : '' ?></div>
