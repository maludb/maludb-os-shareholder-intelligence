<?php /** The email a notification goes out in (the outbox pass): its title and words, a link to the record. To a member only. Data: title, body, url, kind */ ?>
<!doctype html><html lang="en"><body style="font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;max-width:640px">
<h2 style="font-size:18px;margin-bottom:6px"><?= e($title) ?></h2>
<?php if ($body !== '' && $body !== $title): ?><p style="white-space:pre-line"><?= e($body) ?></p><?php endif; ?>
<p><a href="<?= e($url) ?>" style="background:#3454d1;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">Open it in <?= e(app_name()) ?></a></p>
<p style="color:#6b7280;font-size:12px">Sent by <?= e(app_name()) ?> because of your notification settings. It is for the people it was sent to and is not for the market.</p>
</body></html>
