<?php
declare(strict_types=1);
/**
 * GET /admin/sources/{id}/credential — set or rotate a source's credential (screen `admin-source-credential`): the source, the credential's kind, label and
 * last four when one is set (never the secret), the form. POST — action `credential_set` (log `credential.set|rotate`; credentials.manage; people; an agent's
 * pauses — other): **source**, **kind** (api_key, bearer, basic or oauth_client), **label**, **secret**. A source paused for want of a key resumes.
 * Built ahead of slice 10 (2026-10-11: Alpha Vantage, the default price source, takes its key here).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/write.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    si_handler_begin();
    require_right('credentials.manage');
    require_human();
    $pdo = db();
    $src = find_source($pdo, (int) (request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
    $errors = [];
    $kind = (string) (req_val('kind') ?? 'api_key') ?: 'api_key';
    if (!isset(CREDENTIAL_KINDS[$kind])) {
        $errors['kind'] = 'The kind is an API key, a bearer token, a user and password, or an OAuth client.';
    }
    $label = (string) (req_val('label') ?? '');
    if ($label === '' || mb_strlen($label) > 80) {
        $errors['label'] = $label === '' ? 'Name the credential (such as "Alpha Vantage free key"), so the admin knows it by its name.' : 'The label is up to 80 characters.';
    }
    $secret = trim((string) ($_POST['secret'] ?? ''));
    if (mb_strlen($secret) < 4 || mb_strlen($secret) > 2000 || preg_match('~\s~', $secret)) {
        $errors['secret'] = 'The secret is the key itself: 4 to 2,000 characters, no spaces.';
    }
    if ($errors !== []) {
        si_refuse_fields($errors);
    }
    $r = si_guard($pdo, static function () use ($pdo, $src, $kind, $label, $secret): array {
        $pdo->beginTransaction();
        $r = credential_set($pdo, (int) $src['source_id'], ['kind' => $kind, 'label' => $label, 'secret' => $secret], (int) current_member_id());
        $pdo->commit();
        return $r;
    });
    $did = ($r['rotated'] ? 'Rotated the credential of ' : 'Set the credential of ') . $src['name'] . ($r['resumed'] ? '; the source resumed and pulls on its schedule' : '');
    si_done($did, (int) $src['source_id'], si_land('/admin/sources/' . (int) $src['source_id'] . '/credential', $r['resumed'] ? 'resumed' : ($r['rotated'] ? 'rotated' : 'set')), 'sourceChanged', ['resumed' => $r['resumed']]);
}

require_right('credentials.manage');
$pdo = db();
$src = find_source($pdo, (int) (request_integer('id') ?? 0)) ?? refuse(404, 'Source not found.');
log_screen_view($pdo, 'admin-source-credential');
if (wants_json()) {
    respond_screen(['source' => present_source_credential($src), 'kinds' => array_keys(CREDENTIAL_KINDS), 'fields' => ['kind', 'label', 'secret']]);
}
$notices = ['set' => ['success', 'The credential is set. The secret is not shown again.'], 'rotated' => ['success', 'The credential is rotated. The secret is not shown again.'],
            'resumed' => ['success', 'The credential is set and the source resumed: it pulls at its next scheduled slot.']];
render_screen('Credential — ' . $src['name'], view('admin/credential.php', ['s' => $src, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => back_link()]),
    ['activeNav' => 'admin', 'screen' => 'admin-source-credential', 'entity' => 'source', 'recordId' => (string) $src['source_id']]);
