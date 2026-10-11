<?php /** activist_short_report: the event — title, date, URL. Data: ev, s */ ?>
<?= evidence_table([["The report", (string) ($ev["title"] ?? "—")], ["On", sig_d($ev["on_date"] ?? null)], ["Where", (string) ($ev["url"] ?? "—")]]) ?>
