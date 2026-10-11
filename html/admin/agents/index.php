<?php
declare(strict_types=1);
/**
 * /admin/agents?status=&kind=&agent=&page= — the agents (screen `admin-agents`): each agent admitted here with its roles and its last dispatch, the Analyst's settings (the morning note and its hour, proposals on or off,
 * the pack's day, the issuers it covers, the person told of a high signal — action `agents_settings_save`), and the dispatches with their status and Cancel (action `dispatch_cancel`). Hires and grants are the kernel's.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/write.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/present.php';
require_right('agents.settings');
$pdo = db();
$filters = ['status' => isset(DISPATCH_STATUSES[request_string('status')]) ? request_string('status') : '', 'kind' => isset(DISPATCH_KINDS[request_string('kind')]) ? request_string('kind') : '',
            'agent' => request_integer('agent')];
$list = find_dispatches($pdo, $filters, request_integer('page') ?? 1);
$ov = agents_overview($pdo);
log_screen_view($pdo, 'admin-agents');
if (wants_json()) {
    respond_screen(['agents' => array_map('present_agent', $ov['agents']), 'analyst' => $ov['analyst'], 'settings' => present_agent_settings($ov['settings']),
        'issuers' => array_map(static fn (array $i): array => ['issuer_id' => (int) $i['issuer_id'], 'name' => $i['name'], 'ticker' => $i['ticker']], $ov['issuers']),
        'filters' => $filters, 'counts' => $list['counts'], 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages'], 'dispatches' => array_map('present_dispatch', $list['rows']),
        'kinds' => DISPATCH_KINDS, 'statuses' => DISPATCH_STATUSES]);
}
$notices = ['saved' => ['success', 'Saved the agents\' settings.'], 'cancelled' => ['success', 'Cancelled the dispatch.']];
render_screen('Agents', view('admin/agents.php', ['ov' => $ov, 'list' => $list, 'f' => $filters, 'tz' => member_timezone(), 'here' => here_url(), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'agents', 'screen' => 'admin-agents']);
