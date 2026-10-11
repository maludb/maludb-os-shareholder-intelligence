<?php /** participant_jump: the participant, number, class, the move, the date, % of total. Data: ev, s */ ?>
<?= evidence_table([["Participant", ($ev["name"] ?? "—") . " (" . ($ev["number"] ?? "?") . ")"], ["Class", (string) ($ev["class"] ?? "—")],
    [isset($ev["move"]) ? "The move" : "Shares, new to the file", sig_n($ev["move"] ?? $ev["shares"] ?? null)], ["On", sig_d($ev["on"] ?? $s["window_to"] ?? null)], ["% of the DTC total", sig_pct($s["value"] ?? null)], ["The DTC total", sig_n($ev["spr_total"] ?? null)]]) ?>
<?php if (!empty($ev["participant_id"])): ?><p class="fs-12 mt-2 mb-0"><?= hx_link("/participants/" . (int) $ev["participant_id"], "The participant's page", "fw-semibold", "id=\"signal-participant-link\"") ?></p><?php endif; ?>
