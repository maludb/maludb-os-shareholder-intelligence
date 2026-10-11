<?php /** holder_5pct: the filer, schedule, percent, filed. Data: ev, s */ ?>
<?= evidence_table([["The filer", (string) ($ev["name"] ?? "—")], ["Schedule", (string) ($ev["schedule"] ?? "—")], ["% of the class", sig_pct($ev["percent_of_class"] ?? null)], ["Filed", sig_d($s["window_from"] ?? null)]]) ?>
<?php if (!empty($ev["filing_id"])): ?><p class="fs-12 mt-2 mb-0"><?= hx_link("/filings/" . (int) $ev["filing_id"], "The filing", "fw-semibold", "id=\"signal-filing-link\"") ?></p><?php endif; ?>
