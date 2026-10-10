<?php /** One filing in the ledger. Data: r, here */ $fid = (int) $r['filing_id']; $items = pg_text_array((string) $r['items']); ?>
<tr id="filing-row-<?= $fid ?>">
    <td class="text-nowrap"><?= e(format_date($r['filed_on'])) ?></td>
    <td><?= form_chip((string) $r['form']) ?><?php if ($r['about_us']): ?> <i class="bi bi-box-arrow-in-down-left text-danger" title="Filed by another filer about the company"></i><?php endif; ?></td>
    <td class="text-wrap"><?= hx_link(with_back('/filings/' . $fid, $here), e($r['primary_doc_description'] ?: $r['form']), 'fw-semibold') ?>
        <?php if ($items !== []): ?><div class="fs-11 text-muted">Items <?= e(implode(', ', $items)) ?></div><?php endif; ?>
        <div class="d-md-none fs-11 text-muted"><?= $r['has_text'] ? '<i class="bi bi-file-text me-1"></i>text' : '' ?><?= $r['structured_at'] ? ' <i class="bi bi-diagram-3 ms-1"></i>structured' : '' ?></div></td>
    <td class="d-none d-md-table-cell text-nowrap fs-11"><?= $r['has_text'] ? '<span class="text-success"><i class="bi bi-file-text me-1"></i>text</span>' : '<span class="text-muted">—</span>' ?><?= $r['structured_at'] ? ' <i class="bi bi-diagram-3 ms-1" title="structured"></i>' : '' ?></td>
</tr>
