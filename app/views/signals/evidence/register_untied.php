<?php /** register_untied: outstanding and the register's components, the difference. Data: ev, s */ ?>
<?= evidence_table([["Shares outstanding", sig_n($ev["outstanding"] ?? null)], ["Cede & Co.", sig_n($ev["cede"] ?? null)], ["DRS (book entry)", sig_n($ev["drs_book"] ?? null)], ["Certificated", sig_n($ev["certificated"] ?? null)],
    ["Plan", sig_n($ev["plan"] ?? null)], ["Other registered", sig_n($ev["other"] ?? null)], ["The difference", sig_n($ev["difference"] ?? null)]]) ?>
<?php if (!empty($ev["reconciliation_id"])): ?><p class="fs-12 mt-2 mb-0"><?= hx_link("/reconciliation?security=" . (int) $s["security_id"], "The reconciliation", "fw-semibold", "id=\"signal-reconciliation-link\"") ?> — a data question before anything else.</p><?php endif; ?>
