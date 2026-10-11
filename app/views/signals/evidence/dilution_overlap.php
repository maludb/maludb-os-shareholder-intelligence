<?php /** dilution_overlap: the instrument (kind, counterparty, terms, remaining) and the overlapping signal. Data: ev, s */ ?>
<?= evidence_table([["The instrument", ucfirst(str_replace("_", " ", (string) ($ev["kind"] ?? "—")))], ["Counterparty", (string) ($ev["counterparty"] ?? "unknown")], ["Price terms", (string) ($ev["price_terms"] ?? "unrecorded")],
    ["Remaining", sig_n($ev["remaining_amount"] ?? null)]]) ?>
<p class="fs-12 mt-2 mb-0"><?= !empty($ev["instrument_id"]) ? hx_link("/dilution/" . (int) $ev["instrument_id"], "The instrument", "fw-semibold", "id=\"signal-instrument-link\"") : "" ?><?= !empty($ev["with_signal_id"]) ? " · " . hx_link("/signals/" . (int) $ev["with_signal_id"], "the overlapping signal", "fw-semibold", "id=\"signal-overlap-link\"") : "" ?></p>
