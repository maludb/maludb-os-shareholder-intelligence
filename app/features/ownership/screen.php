<?php
declare(strict_types=1);

/** What every ownership and institution screen and handler starts with (slice 5). */
require_once dirname(__DIR__) . '/positions/screen.php';
require_once dirname(__DIR__) . '/institutions/queries.php';
require_once dirname(__DIR__) . '/institutions/write.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/filings/queries.php';
require_once dirname(__DIR__) . '/filings/present.php';
require_once dirname(__DIR__) . '/notes/write.php';
require_once dirname(__DIR__) . '/tags/write.php';

/** [issuer, security] for an ownership screen, or the empty page. */
function ownership_context_or_empty(PDO $pdo, string $screen, string $title): array
{
    [$issuer, $security] = positions_context($pdo);
    if ($security === null) {
        log_screen_view($pdo, $screen);
        if (wants_json()) { respond_screen(['security_id' => null]); }
        render_screen($title, view('positions/partials/no-issuer.php', ['id' => $screen, 'title' => $title, 'issuer' => $issuer]), ['activeNav' => 'ownership', 'screen' => $screen]);
        exit;
    }
    return [$issuer, $security];
}

/** The ownership screens' tabs. */
function ownership_tabs(string $active, int $security): string
{
    $o = '<div class="d-flex flex-wrap gap-1 mb-3" id="ownership-tabs" role="tablist">';
    foreach ([['13f', '/ownership/13f', '13F holders'], ['changes', '/ownership/13f/changes', 'Changes'], ['beneficial', '/ownership/beneficial', '5% holders'],
              ['insiders', '/ownership/insiders', 'Insiders'], ['144', '/ownership/144', 'Form 144'], ['institutions', '/institutions', 'Institutions']] as [$k, $u, $l]) {
        $o .= hx_link($u . '?security=' . $security, e($l), 'btn btn-touch btn-sm ' . ($active === $k ? 'btn-primary' : 'btn-light'), 'id="ownership-tab-' . $k . '"');
    }
    return $o . '</div>';
}

/** The quarter chooser. */
function period_form(string $action, int $security, array $periods, ?string $period, array $extra = []): string
{
    $o = '<form method="get" action="' . e($action) . '" class="row g-2 align-items-end mb-3" id="period-form" hx-get="' . e($action) . '" hx-target="#page-content" hx-push-url="true"><input type="hidden" name="security" value="' . $security . '">';
    foreach ($extra as $k => $v) { $o .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
    $o .= '<div class="col-8 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="period-form-field">Quarter</label><select name="period" id="period-form-field" class="form-select btn-touch" onchange="this.form.requestSubmit()">';
    foreach ($periods as $p) { $o .= '<option value="' . e($p) . '"' . ($p === $period ? ' selected' : '') . '>' . e(format_date($p)) . '</option>'; }
    return $o . '</select></div></form>';
}
