<?php /** borrow_fee: the observation — date, source ("indicative" for a quote), fee, utilisation. Data: ev, s */ ?>
<?= evidence_table([["Observed", sig_d($ev["observed_on"] ?? null)], ["Source", (BORROW_SOURCES[$ev["source"] ?? ""] ?? ($ev["source"] ?? "—")) . (($ev["source"] ?? "") !== "vendor" ? " — indicative" : "")],
    ["Fee (a year)", sig_pct($ev["fee_rate_percent"] ?? null)], ["Utilisation", sig_pct($ev["utilization_percent"] ?? null, 1)]]) ?>
