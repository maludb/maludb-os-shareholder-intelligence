<?php /** prime_migration: the window, the retail side's net, the other side's net, the DTC total. Data: ev, s */ ?>
<?= evidence_table([["From position date", sig_d($ev["from"] ?? null)], ["To position date", sig_d($ev["to"] ?? null)], ["The retail boxes, net", sig_n($ev["retail_net"] ?? null)],
    ["Prime, lending and market-maker boxes, net", sig_n($ev["other_net"] ?? null)], ["The DTC total", sig_n($ev["spr_total"] ?? null)]]) ?>
<p class="fs-11 text-muted mt-2 mb-0">Practitioner lore: no published study validates it. Which class is retail is a setting of the participant classes.</p>
