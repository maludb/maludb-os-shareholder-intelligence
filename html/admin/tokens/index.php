<?php
declare(strict_types=1);
/** /admin/tokens?member= — everyone's keys and MCP tokens: person, label, scope, limits, today's calls, last used; Revoke (screen `admin-tokens`). tokens.manage. Never a hash or a key. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_right('tokens.manage');
$pdo = db();
$member = request_integer('member');
$holders = token_holders($pdo);
if ($member !== null && !in_array($member, array_map(static fn (array $h): int => (int) $h['member_id'], $holders), true)) {
    $member = null;
}
$rows = all_tokens($pdo, $member);
log_screen_view($pdo, 'admin-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_admin_token', $rows), 'people' => array_map(static fn (array $h): array => ['member_id' => (int) $h['member_id'], 'person' => $h['member_name'], 'tokens' => (int) $h['n']], $holders), 'filters' => ['member' => $member]]);
}
render_screen('Keys and tokens', view('admin/tokens.php', ['rows' => $rows, 'holders' => $holders, 'member' => $member, 'tz' => member_timezone(), 'here' => here_url(), 'notice' => si_notice($_GET['notice'] ?? null, ['revoked' => ['success', 'Revoked the token.']])]),
    ['activeNav' => 'admin', 'screen' => 'admin-tokens', 'entity' => 'mcp_access_token']);
