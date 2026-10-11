<?php /** Everything the agents proposed (screen `proposals`). Data: rows, counts, kind, issuer, work (may take a signal proposal), decide (may decide a holder one), tz, here, notice */
$total = array_sum($counts);
$qs = static fn (array $over): string => '/proposals?' . http_build_query(array_filter(array_merge(['kind' => $kind, 'issuer' => $issuer], $over), static fn ($v) => $v !== null && $v !== ''));
?>
<?= view('shared/header.php', ['id' => 'proposals', 'title' => 'Proposals', 'crumbs' => [['Home', '/'], ['Proposals', null]]]) ?>
<div class="main-content" id="proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="proposals-kinds">
        <?= hx_link($qs(['kind' => null]), 'All <span class="fw-semibold">' . (int) $total . '</span>', 'btn btn-touch btn-sm ' . ($kind === null ? 'btn-primary' : 'btn-light'), 'id="proposals-kind-all"') ?>
        <?php foreach (PROPOSAL_KINDS as $k => $w): ?>
            <?= hx_link($qs(['kind' => $k]), '<i class="bi ' . e(PROPOSAL_ICONS[$k]) . ' me-1"></i>' . e($w) . ' <span class="fw-semibold">' . (int) $counts[$k] . '</span>', 'btn btn-touch btn-sm ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="proposals-kind-' . e($k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <form method="get" action="/proposals" hx-get="/proposals" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="proposals-filters"><div class="card-body py-2"><div class="row g-2 align-items-end">
        <?php if ($kind !== null): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label fs-11 text-muted mb-1" id="proposals-issuer-label" for="proposals-issuer-open">Issuer</label>
            <?= picker_field(['id' => 'proposals-issuer', 'name' => 'issuer', 'source' => 'issuer', 'value' => $issuer, 'labelledby' => 'proposals-issuer-label', 'placeholder' => 'Every issuer']) ?></div>
        <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="proposals-filter-btn">Show</button></div>
    </div></div></form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="proposals-empty"><?= $total === 0 && $kind === null ? 'Nothing is waiting: no agent has a proposal that nobody has taken.' : 'No proposal of this kind.' ?></div></div>
    <?php else: ?>
        <div id="proposals-cards">
        <?php foreach ($rows as $p): ?><?= view('proposals/partials/' . ['signal' => 'signal', 'holder_match' => 'match', 'holder_kind' => 'kind'][$p['kind']] . '.php', ['p' => $p, 'work' => $work, 'decide' => $decide, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="fs-11 text-muted mt-3" id="proposals-footnote">An agent proposes and a person decides: <strong>Take it</strong> does exactly what the proposal says, in its words, and is logged as yours. A holder's name and address appear to an Analyst and above only.</p>
</div>
