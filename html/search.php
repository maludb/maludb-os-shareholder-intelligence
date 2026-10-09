<?php
declare(strict_types=1);
/**
 * /search?q=&kinds[]=&scope= — the one search box (screen `search`, sso-shell.md): results grouped by kind, the current issuer's by default
 * (scope=all: every issuer I may see). si_search keeps both walls. Logged `search.keyword` with the query cut at 120 characters and the count.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_login();
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 200);
$kinds = $_GET['kinds'] ?? [];
$kinds = array_values(array_intersect(is_array($kinds) ? array_map('strval', $kinds) : explode(',', (string) $kinds), array_keys(SEARCH_KINDS)));
$issuer = current_issuer($pdo);
$scope = request_string('scope') === 'all' || $issuer === null ? 'all' : 'issuer';
$page = max(1, request_integer('page') ?? 1);
$result = search($pdo, $q, $kinds, $scope === 'issuer' ? (int) $issuer['issuer_id'] : null, $page);
log_screen_view($pdo, 'search');
if ($q !== '') {
    log_activity($pdo, 'search.keyword', null, null, ['issuer_id' => $scope === 'issuer' ? (int) $issuer['issuer_id'] : null,
        'after' => ['q' => mb_substr($q, 0, 120), 'kinds' => $kinds, 'scope' => $scope, 'count' => $result['total']]]);
}
if (wants_json()) {
    respond_screen(['q' => $q, 'kinds' => $kinds, 'scope' => $scope, 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
        'results' => array_map(static fn (array $r): array => ['entity_kind' => $r['entity_kind'], 'entity_id' => (int) $r['entity_id'], 'title' => $r['title'],
            'headline' => $r['headline'], 'status' => $r['status'], 'issuer_id' => $r['issuer_id'] === null ? null : (int) $r['issuer_id'], 'url' => search_result_url($r)], $result['rows'])]);
}
render_screen('Search', view('search/index.php', ['q' => $q, 'kinds' => $kinds, 'scope' => $scope, 'issuer' => $issuer, 'result' => $result]),
    ['activeNav' => '', 'screen' => 'search']);
