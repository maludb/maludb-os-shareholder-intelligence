<?php /** The email a pack goes out in (report_send): the sender's words, the narrative, a link. No attachment: MaluMail's send API takes none. Data: r, url, sender, message, to, narrative (HTML) */ ?>
<!doctype html><html lang="en"><body style="font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;max-width:640px">
<p>Hello <?= e((string) $to) ?>,</p>
<?php if ($message !== null && $message !== ''): ?><p><?= nl2br(e($message)) ?></p><?php endif; ?>
<h2 style="font-size:18px;margin-bottom:2px"><?= e((string) $r['title']) ?></h2>
<p style="color:#6b7280;margin-top:0"><?= e((string) $r['issuer_name']) ?> · <?= e(format_date((string) $r['period_from'])) ?> to <?= e(format_date((string) $r['period_to'])) ?></p>
<?= $narrative ?>
<p><a href="<?= e($url) ?>" style="background:#3454d1;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">Open the pack</a></p>
<p style="color:#6b7280;font-size:12px"><?= e((string) $sender) ?> sent this from <?= e(app_name()) ?>. Sign in to read the pack with its tables. It is for the people it was sent to and is not for the market.</p>
</body></html>
