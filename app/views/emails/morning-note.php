<?php /** The morning note's email (the Analyst's note, kind morning_note): the note as written, each line kept, a link to the home. To a member only. Data: title, body, url, kind */ ?>
<!doctype html><html lang="en"><body style="font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;max-width:640px">
<p style="color:#6b7280;font-size:12px;margin:0">The morning note</p>
<h2 style="font-size:18px;margin:2px 0 8px"><?= e($title) ?></h2>
<div style="white-space:pre-line"><?= e($body) ?></div>
<p><a href="<?= e($url) ?>" style="background:#3454d1;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">Open <?= e(app_name()) ?></a></p>
<p style="color:#6b7280;font-size:12px">The note is written by your Surveillance Analyst from this application's records; every figure has its date and source on its screen. Nothing in it leaves the business.</p>
</body></html>
