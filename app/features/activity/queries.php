<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log, db/016 + db/019: rows about the issuers the wall lets them see; their own by default). */
const ACTIVITY_PAGE = 50;

/** The audit keys a record's history is read by (db/002): the filter's name => the column. */
const ACTIVITY_KEYS = ['issuer' => 'issuer_id', 'security' => 'security_id', 'upload' => 'import_id', 'signal' => 'signal_id'];

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days),
 * issuer / security / upload / signal (int: that record's history, by the audit key), entity_type + entity_id (one record's history). mcp_activity_log decides what is seen.
 */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (ACTIVITY_KEYS as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = $col . ' = :' . $k;
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['entity_type'] ?? null) !== null) {
        $where[] = 'entity_type = :etype AND entity_id = :eid';
        $args['etype'] = (string) $filters['entity_type'];
        $args['eid'] = (int) $filters['entity_id'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, entity_type, entity_id, after, issuer_id, security_id, import_id, signal_id, token_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by the audit key for an issuer, a security, an upload or a signal, else by entity. */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 30): array
{
    $f = isset(ACTIVITY_KEYS[$type]) ? [$type => $id] : ['entity_type' => $type, 'entity_id' => $id];
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'issuer_id' => $r['issuer_id'] === null ? null : (int) $r['issuer_id'], 'security_id' => $r['security_id'] === null ? null : (int) $r['security_id'],
            'import_id' => $r['import_id'] === null ? null : (int) $r['import_id'], 'signal_id' => $r['signal_id'] === null ? null : (int) $r['signal_id'], 'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id']];
}

/** The record a row concerns, as a link: [url, label] or null. Phase 3's slices add their screens. */
function activity_record_link(array $r): ?array
{
    if (($r['signal_id'] ?? null) !== null) { return ['/signals/' . (int) $r['signal_id'], 'the signal']; }
    if (($r['import_id'] ?? null) !== null) { return ['/uploads/' . (int) $r['import_id'], 'the upload']; }
    $records = ['holder' => ['/holders/', 'the holder'], 'filing' => ['/filings/', 'the filing'], 'event' => ['/events/', 'the event'], 'report' => ['/reports/', 'the report'],
                'institution' => ['/institutions/', 'the institution'], 'participant' => ['/participants/', 'the participant']];
    if (($r['entity_id'] ?? null) !== null && isset($records[$r['entity_type'] ?? ''])) { return [$records[$r['entity_type']][0] . (int) $r['entity_id'], $records[$r['entity_type']][1]]; }
    if (($r['issuer_id'] ?? null) !== null) { return ['/issuers/' . (int) $r['issuer_id'], 'the issuer']; }
    return null;
}

/** One line for a row, in words: "Dana uploaded a file". Falls back to the event name. Later slices add their events (the design's §6.8 vocabulary). */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'Shareholder Intelligence';
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $title = isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : '');
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.session_ended' => 'was signed out', 'member.sign_on.refused' => 'was refused at sign-on', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'settings.prefs' => 'changed how they are told', 'notification.read' => 'read a notification',
        'notification.read_all' => 'read their notifications', 'issuer.switch' => 'switched issuer', 'search.keyword' => 'searched', 'assistant.ask' => 'asked the command bar',
        'issuer.save' => 'saved an issuer', 'issuer.delete' => 'deleted an issuer', 'issuer.restore' => 'restored an issuer', 'issuer.edgar_sync' => 'read an issuer from EDGAR',
        'security.save' => 'saved a security', 'share_count.save' => 'recorded a share count', 'restriction.save' => 'changed who may see an issuer',
        'upload.create' => 'uploaded a file', 'upload.parse' => 'parsed an upload', 'upload.tie' => 'tied an upload', 'upload.untie' => 'found an upload untied', 'upload.override' => 'overrode an untied upload',
        'upload.delete' => 'deleted an upload', 'upload.restore' => 'restored an upload', 'reconciliation.run' => 'ran the reconciliation',
        'match.auto' => 'matched a holder', 'match.accept' => 'accepted a holder match', 'match.reject' => 'rejected a holder match', 'match.new_holder' => 'made a holder', 'holder.merge' => 'merged holders',
        'signal.raise' => 'raised a signal', 'signal.acknowledge' => 'acknowledged a signal', 'signal.dismiss' => 'dismissed a signal', 'signal.escalate' => 'escalated a signal', 'signal.propose' => 'proposed a disposition',
        'event.create' => 'recorded an event', 'report.draft' => 'drafted a pack', 'report.approve' => 'approved a pack', 'report.send' => 'sent a pack', 'export.create' => 'exported',
        'source.pull_done' => 'pulled a source', 'source.pull_fail' => 'failed to pull a source', 'source.pull_blocked' => 'was refused by a source',
        'key.mint' => 'minted a key', 'key.rotate' => 'rotated a key', 'key.revoke' => 'revoked a key', 'share.read' => 'read a share', 'api.call' => 'called the API',
        'settings.save' => 'changed the settings', 'worker.pass' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
