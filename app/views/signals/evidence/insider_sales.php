<?php /** insider_sales: sales and shares in 30 days; the Form 144 if one. Data: ev, s */
$f = $ev["form144"] ?? null; ?>
<?= evidence_table(array_merge([["Sales (Form 4, code S) in 30 days", sig_n($ev["sales"] ?? null)], ["Shares sold", sig_n($ev["shares_sold"] ?? null)], ["Shares outstanding", sig_n($ev["outstanding"] ?? null)]],
    is_array($f) ? [["Form 144 — the seller", (string) ($f["seller"] ?? "—")], ["Form 144 — shares to sell", sig_n($f["shares"] ?? null)], ["Form 144 — filed", sig_d($f["filed_on"] ?? null)]] : [])) ?>
