<?php /** nobo_drain: the participant, NOBO shares from → to, SPR from → to, the two record dates. Data: ev, s */ ?>
<?= evidence_table([["Participant", ($ev["name"] ?? "—") . " (" . ($ev["number"] ?? "?") . ")"], ["Record dates", sig_d($s["window_from"] ?? null) . " → " . sig_d($s["window_to"] ?? null)],
    ["NOBO shares", sig_n($ev["nobo_from"] ?? null) . " → " . sig_n($ev["nobo_to"] ?? null)], ["Its DTC position", sig_n($ev["spr_from"] ?? null) . " → " . sig_n($ev["spr_to"] ?? null)]]) ?>
<p class="fs-11 text-muted mt-2 mb-0">Known owners fell while the box held: objecting owners or lent shares. Practitioner lore.</p>
