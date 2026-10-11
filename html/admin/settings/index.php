<?php
declare(strict_types=1);
/**
 * /admin/settings — the application's settings (screen `admin-settings`), grouped: the business and the EDGAR contact; the exchange, time zones and holidays; the fetch rates and the price source; the tolerances and
 * thresholds; names in exports and the escalation member; keys, uploads and retention. A red banner while the EDGAR user-agent is empty. settings.manage. The form posts to save.php (action `settings_save`).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';
require_right('settings.manage');
$pdo = db();
$s = settings_form($pdo);
log_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    respond_screen(['settings' => present_settings($s), 'options' => ['exchanges' => EXCHANGES, 'price_sources' => PRICE_SOURCES, 'pii_in_exports' => PII_IN_EXPORTS]]);
}
render_screen('Settings', view('admin/settings.php', ['s' => $s, 'tz' => member_timezone(), 'notice' => si_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-settings', 'entity' => 'settings']);
