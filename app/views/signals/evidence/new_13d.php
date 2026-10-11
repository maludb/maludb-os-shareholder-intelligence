<?php /** new_13d: the filer, percent, shares, filed and event dates, the purpose (item 4) — the filing linked, no chart. Data: ev, s */ ?>
<?= evidence_table([["The filer", (string) ($ev["name"] ?? "—")], ["Schedule", "13D" . (!empty($ev["is_amendment"]) ? "/A" : "")], ["% of the class", sig_pct($ev["percent_of_class"] ?? null)],
    ["Shares", sig_n($ev["shares"] ?? null)], ["Filed", sig_d($ev["filed_on"] ?? null)]]) ?>
<?php if (!empty($ev["filing_id"])): ?><p class="fs-12 mt-2 mb-0"><?= hx_link("/filings/" . (int) $ev["filing_id"], "The filing", "fw-semibold", "id=\"signal-filing-link\"") ?> — item 4 states the purpose.</p><?php endif; ?>
