<?php
declare(strict_types=1);
/** /settings/tokens/ — a person's own access tokens for the two MCP servers and their API keys, and the URLs to connect their AI to (screen `settings-tokens`). People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/write.php';
require_once dirname(__DIR__, 3) . '/app/api/present.php';
require_login();
require_human();
$pdo = db();
$rows = array_values(array_filter(find_my_tokens($pdo, (int) current_member_id()), static fn (array $t): bool => $t['scope'] === 'mcp'));
$keys = find_keys_of($pdo, (int) current_member_id());
log_screen_view($pdo, 'settings-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $rows), 'keys' => array_map('present_api_key', $keys), 'may_mint_key' => has_right('tokens.own'), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity'),
                    'api_url' => app_url('/api/v1/')]);
}
$raw = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
render_screen('Tokens', view('settings/tokens.php', ['rows' => $rows, 'keys' => $keys, 'mayKey' => has_right('tokens.own'), 'raw' => $raw, 'tz' => member_timezone(),
    'notice' => si_notice($_GET['notice'] ?? null, ['key_revoked' => ['success', 'Revoked the key.']])]),
    ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
