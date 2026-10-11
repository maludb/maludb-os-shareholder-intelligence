<?php /** short_volume_ratio: the 5-day mean now and 20 trading days ago, FINRA's caveat IN FULL. Data: ev, s */ ?>
<?= evidence_table([["Trade date", sig_d($ev["trade_date"] ?? null)], ["5-day mean of the ratio", sig_pct($ev["mean_5_pct"] ?? null, 1)], ["20 trading days before", sig_pct($ev["mean_5_pct_20_days_ago"] ?? null, 1)]]) ?>
<div class="alert alert-warning fs-12 mt-2 mb-0" id="signal-caveat"><i class="feather-alert-circle me-1"></i><?= e((string) ($ev["caveat"] ?? SI_SHORT_VOLUME_CAVEAT)) ?></div>
