<?php
/**
 * An issuer's form (screens `issuer-add` /issuers/new?ticker=&cik= and `issuer-edit` /issuers/{id}/edit). On add, Look up asks the SEC for the
 * ticker (or CIK) and fills the name, the CIK and the exchange; the person can still change every field. Data: i (null on add), values, lookup, errors
 */
$editing = $i !== null;
$id = $editing ? (int) $i['id'] : null;
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
?>
<?= view('shared/header.php', ['id' => 'issuer-form', 'title' => $editing ? 'Edit ' . $i['name'] : 'Add an issuer', 'crumbs' => [['Home', '/'], ['Issuers', '/issuers'], [$editing ? 'Edit' : 'Add', null]],
    'back' => [$editing ? '/issuers/' . $id : '/issuers', $editing ? $i['name'] : 'Issuers']]) ?>
<div class="main-content" id="issuer-form-content">
    <?php if (!$editing): ?>
    <form method="get" action="/issuers/new" class="card mb-3" id="issuer-lookup" hx-get="/issuers/new" hx-target="#page-content" hx-push-url="true">
        <div class="card-body">
            <label class="form-label" for="issuer-lookup-field-ticker">Find the company at the SEC</label>
            <div class="input-group"><input type="text" name="ticker" id="issuer-lookup-field-ticker" class="form-control btn-touch" value="<?= e($lookup['asked'] ?? '') ?>" placeholder="A ticker (BMNR) or a CIK" maxlength="20" autocapitalize="characters">
                <button type="submit" class="btn btn-light-brand btn-touch" id="issuer-lookup-btn"><i class="feather-search me-1"></i>Look up</button></div>
            <?php if (isset($lookup['error'])): ?><div class="alert alert-warning fs-12 mt-2 mb-0" id="issuer-lookup-error"><?= e($lookup['error']) ?></div>
            <?php elseif (array_key_exists('found', $lookup) && $lookup['found'] === null): ?><div class="alert alert-secondary fs-12 mt-2 mb-0" id="issuer-lookup-none">The SEC lists no company for “<?= e($lookup['asked']) ?>”. Fill the form by hand; an OTC company may be found by its CIK.</div>
            <?php elseif (isset($lookup['found'])): ?><div class="alert alert-success fs-12 mt-2 mb-0" id="issuer-lookup-found">Found <?= e($lookup['found']['name']) ?> — CIK <?= e(ltrim($lookup['found']['cik'], '0')) ?><?= count($lookup['found']['tickers']) > 1 ? ', tickers ' . e(implode(', ', $lookup['found']['tickers'])) : '' ?>. Check the form and save.</div><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
    <form method="post" action="/issuers/save.php" hx-post="/issuers/save.php" hx-target="#flash" id="issuer-form" class="card">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="issuer" value="<?= $id ?>"><?php endif; ?>
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="issuer-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch<?= $inv('name') ?>" id="issuer-form-field-name" name="name" value="<?= e($values['name']) ?>" maxlength="200" required><?= $err('name') ?></div>
            <div class="row g-3 mb-3">
                <div class="col-6"><label class="form-label" for="issuer-form-field-ticker">Ticker</label>
                    <input type="text" class="form-control btn-touch<?= $inv('ticker') ?>" id="issuer-form-field-ticker" name="ticker" value="<?= e($values['ticker']) ?>" maxlength="12" autocapitalize="characters"><?= $err('ticker') ?></div>
                <div class="col-6"><label class="form-label" for="issuer-form-field-cik">CIK</label>
                    <input type="text" class="form-control btn-touch<?= $inv('cik') ?>" id="issuer-form-field-cik" name="cik" value="<?= e($values['cik'] !== null && $values['cik'] !== '' ? ltrim((string) $values['cik'], '0') : '') ?>" inputmode="numeric" maxlength="10"><?= $err('cik') ?></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="issuer-form-field-exchange">Exchange</label>
                    <select class="form-select btn-touch" id="issuer-form-field-exchange" name="exchange"><?php foreach (ISSUER_EXCHANGES as $k => $l): ?><option value="<?= e($k) ?>" <?= $values['exchange'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="issuer-form-field-timezone">Time zone</label>
                    <input type="text" class="form-control btn-touch<?= $inv('timezone') ?>" id="issuer-form-field-timezone" name="timezone" value="<?= e($values['timezone']) ?>" placeholder="the settings' (America/New_York)" list="issuer-timezones"><?= $err('timezone') ?>
                    <datalist id="issuer-timezones"><?php foreach (['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Toronto', 'America/Vancouver'] as $z): ?><option value="<?= e($z) ?>"><?php endforeach; ?></datalist></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="issuer-form-field-ta">Transfer agent</label>
                    <input type="text" class="form-control btn-touch<?= $inv('transfer_agent') ?>" id="issuer-form-field-ta" name="transfer_agent" value="<?= e($values['transfer_agent']) ?>" maxlength="120" placeholder="Securities Transfer Corporation"><?= $err('transfer_agent') ?></div>
                <div class="col-md-6"><label class="form-label" for="issuer-form-field-ta-connector">The agent's register file</label>
                    <select class="form-select btn-touch" id="issuer-form-field-ta-connector" name="transfer_agent_connector"><?php foreach (ISSUER_TA_CONNECTORS as $k => $l): ?><option value="<?= e($k) ?>" <?= $values['transfer_agent_connector'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div>
            <details class="mb-3" <?= array_intersect_key($errors, array_flip(['state_of_incorporation', 'fiscal_year_end', 'sic', 'market_holidays'])) !== [] ? 'open' : '' ?> id="issuer-form-more"><summary class="fw-semibold mb-2">More (EDGAR fills these when they are blank)</summary>
                <div class="row g-3 mb-3">
                    <div class="col-4"><label class="form-label" for="issuer-form-field-state">Incorporated in</label><input type="text" class="form-control btn-touch<?= $inv('state_of_incorporation') ?>" id="issuer-form-field-state" name="state_of_incorporation" value="<?= e($values['state_of_incorporation']) ?>" maxlength="60"><?= $err('state_of_incorporation') ?></div>
                    <div class="col-4"><label class="form-label" for="issuer-form-field-fye">Fiscal year end</label><input type="text" class="form-control btn-touch<?= $inv('fiscal_year_end') ?>" id="issuer-form-field-fye" name="fiscal_year_end" value="<?= e($values['fiscal_year_end']) ?>" placeholder="MMDD" maxlength="5"><?= $err('fiscal_year_end') ?></div>
                    <div class="col-4"><label class="form-label" for="issuer-form-field-sic">SIC</label><input type="text" class="form-control btn-touch<?= $inv('sic') ?>" id="issuer-form-field-sic" name="sic" value="<?= e($values['sic']) ?>" inputmode="numeric" maxlength="4"><?= $err('sic') ?></div>
                </div>
                <div class="mb-3"><label class="form-label" for="issuer-form-field-holidays">The issuer's own market holidays</label>
                    <textarea class="form-control<?= $inv('market_holidays') ?>" id="issuer-form-field-holidays" name="market_holidays" rows="2" placeholder="2026-12-24, 2026-12-31 — blank for the settings'"><?= e(implode(', ', $values['market_holidays'])) ?></textarea><?= $err('market_holidays') ?></div>
            </details>
            <div class="mb-0"><label class="form-label" for="issuer-form-field-notes">Notes</label>
                <textarea class="form-control<?= $inv('notes') ?>" id="issuer-form-field-notes" name="notes" rows="3" maxlength="4000"><?= e($values['notes']) ?></textarea><?= $err('notes') ?></div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="issuer-form-save-btn"><?= $editing ? 'Save' : 'Add the issuer' ?></button>
            <?= hx_link($editing ? '/issuers/' . $id : '/issuers', 'Cancel', 'btn btn-light btn-touch', 'id="issuer-form-cancel-btn"') ?>
        </div>
    </form>
</div>
