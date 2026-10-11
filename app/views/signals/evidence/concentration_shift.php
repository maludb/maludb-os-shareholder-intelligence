<?php /** concentration_shift: top-5 from → to, the index from → to. Data: ev, s */ ?>
<?= evidence_table([["From position date", sig_d($ev["from"] ?? null)], ["To position date", sig_d($ev["to"] ?? null)], ["Top-5 share of the DTC total", sig_pct($ev["top5_from"] ?? null) . " → " . sig_pct($ev["top5_to"] ?? null)],
    ["The concentration index (Herfindahl)", sig_n($ev["hhi_from"] ?? null) . " → " . sig_n($ev["hhi_to"] ?? null)]]) ?>
