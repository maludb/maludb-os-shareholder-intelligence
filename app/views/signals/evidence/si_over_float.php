<?php /** si_over_float: the settlement, short, float, % float, days to cover, average volume. Data: ev, s */ ?>
<?= evidence_table([["Settlement date", sig_d($ev["settlement_date"] ?? null)], ["Short interest", sig_n($ev["short_shares"] ?? null) . " shares"], ["The float", sig_n($ev["float"] ?? null)],
    ["% of the float", sig_pct($ev["pct_of_float"] ?? null)], ["Days to cover", sig_n($ev["days_to_cover"] ?? null, 2)], ["Average daily volume", sig_n($ev["avg_daily_volume"] ?? null)]]) ?>
