<?php /** threshold_list: the list, since, consecutive list days. Data: ev, s */ ?>
<?= evidence_table([["The list", THRESHOLD_LISTS[$ev["list"] ?? ""] ?? ($ev["list"] ?? "—")], ["On it since", sig_d($ev["since"] ?? null)], ["Consecutive list days", sig_n($ev["consecutive_days"] ?? null)],
    ["The latest list read", sig_d($ev["latest_list_date"] ?? null)]]) ?>
