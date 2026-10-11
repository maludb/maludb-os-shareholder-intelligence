<?php /** si_change: the two settlements, short from → to, the volume change. Data: ev, s */ ?>
<?= evidence_table([["From settlement", sig_d($ev["from"] ?? null)], ["To settlement", sig_d($ev["to"] ?? null)], ["Short interest", sig_n($ev["short_from"] ?? null) . " → " . sig_n($ev["short_to"] ?? null)],
    ["Change", sig_pct($s["value"] ?? null, 1)], ["Average volume moved", sig_pct($ev["volume_change_pct"] ?? null, 1)]]) ?>
