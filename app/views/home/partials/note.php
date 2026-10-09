<?php /** The Analyst's last morning note (a notification of kind morning_note). Data: note {title, body, created_at}, tz */ ?>
<div class="card border-primary mb-3" id="home-note"><div class="card-body">
    <div class="d-flex align-items-center gap-2 mb-1"><i class="feather-sunrise text-primary"></i><span class="fw-semibold"><?= e($note['title']) ?></span><span class="fs-11 text-muted ms-auto"><?= e(format_ts($note['created_at'], $tz, 'D M j, g:i A')) ?></span></div>
    <div class="fs-13 si-note-body"><?= nl2br(e((string) $note['body'])) ?></div>
</div></div>
