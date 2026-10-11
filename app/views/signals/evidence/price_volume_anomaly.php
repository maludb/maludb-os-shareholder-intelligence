<?php /** price_volume_anomaly: the closes, the drop, the volume against the median, "no event recorded". Data: ev, s */ ?>
<?= evidence_table([["The close three days before", sig_n($ev["close_from"] ?? null, 2)], ["The close on " . sig_d($ev["on"] ?? null), sig_n($ev["close_to"] ?? null, 2)], ["The drop", sig_pct($s["value"] ?? null, 1)],
    ["Volume", sig_n($ev["volume"] ?? null)], ["The 30-day median volume", sig_n($ev["median_volume_30"] ?? null)], ["An event recorded in the three days", "none"]]) ?>
<p class="fs-12 mt-2 mb-0">What happened? Record the event that explains it, then dismiss the signal with it.</p>
