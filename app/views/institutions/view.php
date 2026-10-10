<?php /** One institution (screen `institution-view`). Data: i, security, h, about, notes, tz, back, notice */ $iid = (int) $i['institution_id']; ?>
<?= view('shared/header.php', ['id' => 'institution-view', 'title' => (string) $i['name'], 'crumbs' => [['Home', '/'], ['Institutions', '/institutions'], [mb_strimwidth((string) $i['name'], 0, 30, '…'), null]], 'back' => $back,
    'action' => has_right('events.write') ? hx_link('/institutions/' . $iid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="institution-edit-btn"') : '']) ?>
<div class="main-content" id="institution-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8 order-2 order-xl-1">
            <div class="card mb-3" id="institution-holdings"><div class="card-header"><h5 class="card-title mb-0"><?= $security ? e($security['ticker'] ?: $security['name']) . ' by quarter' : 'Holdings' ?></h5></div><div class="card-body"><?= view('institutions/partials/holdings-chart.php', ['holdings' => $h['holdings']]) ?></div></div>
            <?php if ($h['beneficial'] !== []): ?><div class="card mb-3" id="institution-beneficial"><div class="card-header"><h5 class="card-title mb-0">Schedules 13D and 13G</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12">
                <thead class="thead-light"><tr><th>Filed</th><th>Schedule</th><th class="text-end">Percent</th><th class="text-end">Shares</th></tr></thead><tbody>
                <?php foreach ($h['beneficial'] as $b): ?><tr><td><?= $b['filing_id'] ? hx_link('/filings/' . (int) $b['filing_id'], e(format_date($b['filed_on'])), '') : e(format_date($b['filed_on'])) ?></td><td><span class="badge <?= $b['schedule'] === '13D' ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= e((string) $b['schedule']) ?></span><?= $b['is_amendment'] ? ' /A' : '' ?></td>
                    <td class="text-end"><?= $b['percent_of_class'] === null ? '—' : e(number_format((float) $b['percent_of_class'], 1)) . '%' ?></td><td class="text-end"><?= e(fmt_n($b['shares_beneficially_owned'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div></div><?php endif; ?>
            <?php if ($about !== []): ?><div class="card mb-3" id="institution-about"><div class="card-header"><h5 class="card-title mb-0">Its filings about the company</h5></div><div class="card-body fs-12"><?php foreach ($about as $a): ?><div><?= hx_link('/filings/' . (int) $a['filing_id'], e($a['form'] . ' of ' . format_date($a['filed_on'])), '') ?></div><?php endforeach; ?></div></div><?php endif; ?>
            <div class="card mb-3" id="institution-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><?= view('shared/notes.php', ['kind' => 'institution', 'record' => $iid, 'notes' => $notes, 'here' => '/institutions/' . $iid, 'tz' => $tz]) ?></div></div>
        </div>
        <div class="col-xl-4 order-1 order-xl-2">
            <div class="card mb-3" id="institution-facts"><div class="card-body fs-12"><dl class="row mb-0">
                <dt class="col-5 text-muted fw-normal">CIK</dt><dd class="col-7"><?= e(ltrim((string) $i['cik'], '0')) ?></dd>
                <dt class="col-5 text-muted fw-normal">Kind</dt><dd class="col-7"><?= institution_kind_chip((string) $i['kind']) ?><div class="text-muted"><?= $i['kind_source'] === 'manual' ? 'set by a person' : 'from the name' ?></div></dd>
                <dt class="col-5 text-muted fw-normal">Activist</dt><dd class="col-7"><?= $i['is_activist'] ? '<span class="text-danger">yes</span>' : 'no' ?></dd>
                <?php $al = pg_text_array((string) $i['aliases']); if ($al !== []): ?><dt class="col-5 text-muted fw-normal">Former names</dt><dd class="col-7"><?= e(implode('; ', $al)) ?></dd><?php endif; ?>
                <?php if ($i['website']): ?><dt class="col-5 text-muted fw-normal">Website</dt><dd class="col-7 text-break"><?= e((string) $i['website']) ?></dd><?php endif; ?>
                <?php if ($i['notes']): ?><dt class="col-5 text-muted fw-normal">About</dt><dd class="col-7 text-break"><?= nl2br(e((string) $i['notes'])) ?></dd><?php endif; ?>
            </dl></div></div>
            <div class="card mb-3" id="institution-tags"><div class="card-header"><h5 class="card-title mb-0">Tags</h5></div><div class="card-body"><?= view('shared/tags.php', ['kind' => 'institution', 'record' => $iid, 'tags' => pg_text_array((string) $i['tags']), 'here' => '/institutions/' . $iid]) ?></div></div>
        </div>
    </div>
</div>
