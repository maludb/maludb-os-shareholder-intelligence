<?php /** Record a borrow observation (screen `borrow-add`). Data: security, today */ $sid = (int) $security['security_id']; ?>
<?= view('shared/header.php', ['id' => 'borrow-add', 'title' => 'Record borrow', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Borrow', '/short/borrow?security=' . $sid], ['Record', null]], 'back' => ['/short/borrow?security=' . $sid, 'Borrow']]) ?>
<div class="main-content" id="borrow-add-content">
    <form method="post" action="/short/borrow/save.php" hx-post="/short/borrow/save.php" hx-target="#flash" class="card" id="borrow-form">
        <?= csrf_field() ?><input type="hidden" name="security" value="<?= $sid ?>">
        <div class="card-body">
            <p class="fs-12 text-muted">For <?= e($security['name'] . ' · ' . ($security['cusip'] ?? 'no CUSIP')) ?>. A figure here is indicative: a quote, not a market.</p>
            <div class="row g-3">
                <div class="col-12 col-md-4"><label class="form-label" for="borrow-form-field-observed_on">Observed on</label><input type="date" class="form-control btn-touch" name="observed_on" id="borrow-form-field-observed_on" value="<?= e($today) ?>" max="<?= e($today) ?>" required></div>
                <div class="col-12 col-md-8"><label class="form-label d-block">Source</label><div class="d-flex gap-2 flex-wrap" id="borrow-form-sources">
                    <?php foreach (['broker_quote' => 'A broker\'s quote', 'manual' => 'Typed from elsewhere'] as $k => $l): ?><label class="btn btn-light btn-touch" for="borrow-form-source-<?= $k ?>"><input type="radio" class="form-check-input me-2" name="source" value="<?= $k ?>" id="borrow-form-source-<?= $k ?>" <?= $k === 'broker_quote' ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div></div>
                <div class="col-6 col-md-3"><label class="form-label" for="borrow-form-field-fee_rate_percent">Fee, % a year</label><input type="text" inputmode="decimal" class="form-control btn-touch" name="fee_rate_percent" id="borrow-form-field-fee_rate_percent" placeholder="35.5"></div>
                <div class="col-6 col-md-3"><label class="form-label" for="borrow-form-field-utilization_percent">Utilisation, %</label><input type="text" inputmode="decimal" class="form-control btn-touch" name="utilization_percent" id="borrow-form-field-utilization_percent" placeholder="92"></div>
                <div class="col-6 col-md-3"><label class="form-label" for="borrow-form-field-shares_on_loan">Shares on loan</label><input type="text" inputmode="numeric" class="form-control btn-touch" name="shares_on_loan" id="borrow-form-field-shares_on_loan"></div>
                <div class="col-6 col-md-3"><label class="form-label" for="borrow-form-field-shares_available">Shares available</label><input type="text" inputmode="numeric" class="form-control btn-touch" name="shares_available" id="borrow-form-field-shares_available"></div>
                <div class="col-12"><label class="form-label" for="borrow-form-field-note">Note</label><input type="text" class="form-control btn-touch" name="note" id="borrow-form-field-note" maxlength="500" placeholder="whose quote, for what size"></div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="borrow-form-save-btn">Record</button><?= hx_link('/short/borrow?security=' . $sid, 'Cancel', 'btn btn-light btn-touch', 'id="borrow-form-cancel-btn"') ?></div>
    </form>
</div>
