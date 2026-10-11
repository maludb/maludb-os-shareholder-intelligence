<?php /** top_holder_exit: the institution, shares from → to, the two quarters. Data: ev, s */ ?>
<?= evidence_table([["The institution", (string) ($ev["name"] ?? "—")], ["Quarters", sig_d($ev["prior_period"] ?? null) . " → " . sig_d($ev["period"] ?? null)],
    ["Shares", sig_n($ev["prior_shares"] ?? null) . " → " . sig_n($ev["shares"] ?? 0)], ["Change", sig_pct($s["value"] ?? null, 1)], ["State", (string) ($ev["state"] ?? "—")]]) ?>
<?php if (!empty($ev["institution_id"])): ?><p class="fs-12 mt-2 mb-0"><?= hx_link("/institutions/" . (int) $ev["institution_id"], "The institution's page", "fw-semibold", "id=\"signal-institution-link\"") ?> — the 13F data sets lag a quarter by 45 days.</p><?php endif; ?>
