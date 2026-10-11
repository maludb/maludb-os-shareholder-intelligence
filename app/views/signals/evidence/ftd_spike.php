<?php /** ftd_spike: the settlement date, quantity, % outstanding, the 60-day median. Data: ev, s */ ?>
<?= evidence_table([["Settlement date", sig_d($ev["settlement_date"] ?? null)], ["Fails to deliver", sig_n($ev["quantity"] ?? null) . " shares"], ["% of outstanding", sig_pct($ev["pct_of_outstanding"] ?? null, 3)],
    ["The 60-day median", sig_n($ev["median_60"] ?? null)]]) ?>
<p class="fs-11 text-muted mt-2 mb-0">A Rule 204 flag, not proof of anything: the research found fails followed failures more than they caused them.</p>
