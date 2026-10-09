<?php /** The home of an installation with no issuer this person may see (sso-shell.md). Data: s (home_summary) */ ?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="card" id="home-first-run"><div class="card-body text-center py-5">
        <span class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-layers"></i></span>
        <?php if ($s['may']['add_issuer']): ?>
            <h4 class="mb-2">Start with the company you watch</h4>
            <p class="text-muted mx-auto mb-4" style="max-width: 36rem">Add the issuer by its ticker: its CIK, its filings and its share count come from EDGAR. Then upload the weekly DTC position report, the NOBO list and the transfer agent's register, and the signals start.</p>
            <a href="/issuers/new" class="btn btn-primary btn-touch" id="home-first-run-btn" hx-get="/issuers/new" hx-target="#page-content" hx-push-url="/issuers/new"><i class="feather-plus me-1"></i>Add your first issuer</a>
        <?php else: ?>
            <h4 class="mb-2">No issuer is shared with you yet</h4>
            <p class="text-muted mx-auto mb-0" style="max-width: 36rem" id="home-first-run-note">Ask the IR Manager to add the company, or to add you to its list if it is restricted to named people. Everything here is about an issuer.</p>
        <?php endif; ?>
    </div></div>
</div>
