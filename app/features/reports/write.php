<?php
declare(strict_types=1);

/**
 * The packs' writes (events-reports.md "Query functions", signatures fixed). A pack is DRAFTED (by the Analyst's token, an agent, or a person), edited while a
 * draft, RENDERED (HTML always; a PDF only when SI_WKHTMLTOPDF is set), APPROVED by an IR Manager (the narrative frozen — db/014's trigger — and the rendering
 * kept as approved), and SENT to members: one MaluMail message each, a link to the pack inside the application (MaluMail's send API takes no attachment, so the
 * PDF is fetched after sign-in, never mailed). Nothing leaves the business: a recipient must be an active member who may see the issuer. The log carries ids,
 * counts and kinds — never the narrative's words, a recipient's address or the message.
 */
require_once dirname(__DIR__) . '/events/present.php';
require_once dirname(__DIR__, 2) . '/mail.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/render.php';

/** The fields of a pack from a request: a field left out stays; the period is required to draft; refused with field errors. */
function report_fields(PDO $pdo, ?array $before): array
{
    $errors = [];
    $f = [];
    if (req_has('kind')) {
        $k = (string) req_val('kind');
        isset(REPORT_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(REPORT_KINDS)) . '.';
    }
    if (req_has('title')) {
        $t = (string) req_val('title');
        mb_strlen($t) > 200 ? $errors['title'] = 'A title is up to 200 characters.' : $f['title'] = $t;
    }
    foreach (['period_from' => 'The first day of the period', 'period_to' => 'The last day of the period'] as $k => $label) {
        if (req_has($k) || $before === null) {
            $d = request_date($k);
            $d === null || $d === false ? $errors[$k] = $label . ' is required, such as 2026-09-30.' : $f[$k] = $d;
        }
    }
    $from = $f['period_from'] ?? ($before['period_from'] ?? null);
    $to = $f['period_to'] ?? ($before['period_to'] ?? null);
    if (!isset($errors['period_from']) && !isset($errors['period_to']) && $from !== null && $to !== null && $to < $from) {
        $errors['period_to'] = 'The period ends before it begins.';
    }
    if (req_has('narrative')) {
        $n = (string) req_val('narrative');
        mb_strlen($n) > 20000 ? $errors['narrative'] = 'The narrative is up to 20,000 characters.' : $f['narrative'] = $n === '' ? null : $n;
    }
    $secs = request_list('sections');
    if ($secs !== null) {
        $bad = array_diff($secs, array_keys(REPORT_SECTIONS));
        if ($bad !== [] || $secs === []) {
            $errors['sections'] = $secs === [] ? 'Choose at least one section.' : 'Unknown section: ' . implode(', ', array_slice($bad, 0, 3)) . '.';
        } else {
            $f['sections'] = array_values(array_filter(array_keys(REPORT_SECTIONS), static fn (string $k): bool => in_array($k, $secs, true)));
        }
    }
    if ($errors !== []) {
        si_refuse_fields($errors);
    }
    return $f;
}

/**
 * Draft a pack ($id null; $f needs issuer, period_from and period_to) or change a draft. An approved pack is frozen: a new draft is the way to correct it.
 * Logs report.draft / report.update; a pack an AGENT drafts tells the IR Managers (kind pack_drafted). Answers the id.
 */
function report_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $vals = [];
    foreach (['kind', 'title', 'period_from', 'period_to', 'narrative'] as $c) {
        if (array_key_exists($c, $f)) { $vals[$c] = $f[$c]; }
    }
    if (array_key_exists('sections', $f)) { $vals['sections'] = json_encode($f['sections']); }
    if ($id === null) {
        $kind = $vals['kind'] ?? 'weekly_pack';
        $vals['kind'] = $kind;
        if (($vals['title'] ?? '') === '') {
            $vals['title'] = (REPORT_KINDS[$kind] ?? 'Pack') . ' — ' . date('M j', strtotime((string) $vals['period_from'])) . ' to ' . date('M j, Y', strtotime((string) $vals['period_to']));
        }
        $vals += ['issuer_id' => (int) $f['issuer'], 'drafted_by' => $by];
        $cols = array_keys($vals);
        $ph = array_map(static fn (string $c): string => $c === 'sections' ? 'CAST(:sections AS jsonb)' : ':' . $c, $cols);
        $st = $pdo->prepare('INSERT INTO reports (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ') RETURNING id');
        $st->execute($vals);
        $id = (int) $st->fetchColumn();
        $row = $pdo->query('SELECT kind, period_from, period_to, jsonb_array_length(sections) AS n FROM reports WHERE id = ' . $id)->fetch();
        log_activity($pdo, 'report.draft', 'report', $id, ['issuer_id' => (int) $f['issuer'], 'after' => ['report_id' => $id, 'kind' => $row['kind'], 'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'sections' => (int) $row['n']]]);
        if ((current_member()['member_kind'] ?? 'human') === 'agent') { report_tell_managers($pdo, $id, (int) $f['issuer'], (string) $vals['title'], $by); }
        return $id;
    }
    $was = report_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($was['status'] !== 'draft') {
        throw new DomainException('The pack is ' . $was['status'] . ' and frozen; draft a new one to correct it.');
    }
    if ($vals !== []) {
        $set = array_map(static fn (string $c): string => $c === 'sections' ? 'sections = CAST(:sections AS jsonb)' : "$c = :$c", array_keys($vals));
        $pdo->prepare('UPDATE reports SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($vals + ['id' => $id]);
    }
    log_activity($pdo, 'report.update', 'report', $id, ['issuer_id' => (int) $was['issuer_id'], 'after' => ['report_id' => $id, 'fields_changed' => array_keys($vals), 'narrative_length' => array_key_exists('narrative', $vals) ? mb_strlen((string) $vals['narrative']) : null]]);
    return $id;
}

/** The IR Managers told a draft waits (a pack an agent drafted): the bell and the person's chosen channels (si_notify keeps the issuer wall). */
function report_tell_managers(PDO $pdo, int $id, int $issuer, string $title, int $by): void
{
    $tell = $pdo->prepare("SELECT si_notify(:m, 'pack_drafted', :t, :b, 'report', :r, NULL, :i, :by, :d)");
    foreach ($pdo->query("SELECT m.id FROM members m WHERE m.status = 'active' AND m.member_kind = 'human' AND 'manager' = ANY (si_member_roles(m.id))")->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $tell->execute(['m' => (int) $m, 't' => 'A pack waits for your approval: ' . $title, 'b' => 'Read it, edit the narrative if it needs it, then approve it.', 'r' => $id, 'i' => $issuer, 'by' => $by, 'd' => 'pack_drafted:' . $id . ':' . $m]);
    }
}

/**
 * Render the pack and keep it: the HTML under the store, the PDF beside it when the renderer is present; the previous rendering of a DRAFT is replaced. An
 * approved pack keeps the rendering made at its approval (the numbers it was approved on) — rendering it again is refused unless it has none. Logs report.render.
 * Answers ['pdf' => bool, 'bytes' => int].
 */
function report_render(PDO $pdo, int $id, bool $atApproval = false): array
{
    $row = report_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($row['status'] !== 'draft' && $row['rendered_path'] !== null && !$atApproval) {
        throw new DomainException('The pack is ' . $row['status'] . ': the rendering made when it was approved is kept as approved.');
    }
    $r = render_pack($pdo, $id);
    $html = si_store_named('reports', $id, 'html', $r['html']);
    $pdo->prepare('UPDATE reports SET rendered_path = :h, pdf_path = :p WHERE id = :id')->execute(['h' => $html, 'p' => $r['pdf_path'], 'id' => $id]);
    foreach ([$row['rendered_path'], $row['pdf_path']] as $old) { if ($old !== null) { si_unlink_named((string) $old); } }
    log_activity($pdo, 'report.render', 'report', $id, ['issuer_id' => (int) $row['issuer_id'], 'after' => ['report_id' => $id, 'html' => true, 'pdf' => $r['pdf_path'] !== null, 'bytes' => strlen($r['html'])]]);
    return ['pdf' => $r['pdf_path'] !== null, 'bytes' => strlen($r['html'])];
}

/**
 * The PDF of a pack approved before a renderer was installed (the owner, 2026-10-11): made from the pack's STORED HTML — the approved content unchanged —
 * when the pack has a rendering and no PDF. Answers whether a PDF was made; logs `report.render` {pdf: true, late: true}.
 */
function report_pdf_late(PDO $pdo, int $id): bool
{
    $row = report_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($row['rendered_path'] === null || $row['pdf_path'] !== null) {
        return false;
    }
    $pdf = pack_pdf_from_html($id, si_stored_named((string) $row['rendered_path']));
    if ($pdf === null) {
        return false;
    }
    $pdo->prepare('UPDATE reports SET pdf_path = :p WHERE id = :id AND pdf_path IS NULL')->execute(['p' => $pdf, 'id' => $id]);
    log_activity($pdo, 'report.render', 'report', $id, ['issuer_id' => (int) $row['issuer_id'], 'after' => ['report_id' => $id, 'html' => false, 'pdf' => true, 'late' => true]]);
    return true;
}

/** A manager approves a draft (the database checks the right and that it is a draft); the narrative is frozen, the pack is rendered as approved, its drafter told. */
function report_approve(PDO $pdo, int $id): void
{
    $row = report_row($pdo, $id) ?? throw new DomainException('Not found.');
    if ($row['status'] !== 'draft') { throw new DomainException('The pack is ' . $row['status'] . ' already.'); }
    $pdo->prepare('SELECT si_report_approve(:id)')->execute(['id' => $id]);
    $rendered = report_render($pdo, $id, true);
    log_activity($pdo, 'report.approve', 'report', $id, ['issuer_id' => (int) $row['issuer_id'], 'before' => ['status' => 'draft'], 'after' => ['report_id' => $id, 'status' => 'approved', 'pdf' => $rendered['pdf']]]);
    $by = current_member_id();
    if ($row['drafted_by'] !== null && (int) $row['drafted_by'] !== $by) {
        $name = (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]) ?: 'An IR Manager');
        $pdo->prepare("SELECT si_notify(:m, 'pack_approved', :t, NULL, 'report', :r, NULL, :i, :by, :d)")
            ->execute(['m' => (int) $row['drafted_by'], 't' => $name . ' approved the pack: ' . $row['title'], 'r' => $id, 'i' => (int) $row['issuer_id'], 'by' => $by, 'd' => 'pack_approved:' . $id]);
    }
}

/** Why a member cannot be sent this issuer's pack, in words — or null when they can: an active human member admitted here who may see the issuer (an external one only when its list names them). */
function report_recipient_problem(PDO $pdo, int $issuer, int $member): ?string
{
    $st = $pdo->prepare("SELECT m.display_name, m.email, m.member_kind, m.status, m.capability, m.is_external, si_member_roles(m.id) AS roles,
                                EXISTS (SELECT 1 FROM issuer_members x WHERE x.issuer_id = :i AND x.member_id = m.id) AS listed,
                                EXISTS (SELECT 1 FROM issuer_members x WHERE x.issuer_id = :i2) AS restricted
                           FROM members m WHERE m.id = :m");
    $st->execute(['i' => $issuer, 'i2' => $issuer, 'm' => $member]);
    $m = $st->fetch();
    if ($m === false || $m['status'] !== 'active' || $m['capability'] === null) { return 'Recipient ' . $member . ' is not a member of this application.'; }
    if ($m['member_kind'] !== 'human') { return $m['display_name'] . ' is an agent: a pack goes to people.'; }
    if ($m['email'] === null || $m['email'] === '') { return $m['display_name'] . ' has no email address on record.'; }
    $roles = pg_text_array((string) $m['roles']);
    $privileged = in_array('manager', $roles, true) || in_array('admin', $roles, true);
    if ($m['is_external'] && !$m['listed']) { return $m['display_name'] . ' is outside the business: a pack goes to them only when the issuer\'s list names them.'; }
    if ($m['restricted'] && !$m['listed'] && !$privileged) { return $m['display_name'] . ' may not see this issuer.'; }
    return null;
}

/**
 * Send an approved pack to members: one MaluMail message each (the narrative as written and a link to the pack inside the application). A recipient who is not
 * a member who may see the issuer refuses the whole send, before any mail. The pack becomes `published` once a message is accepted. Answers how many were accepted;
 * $failed gets how many were not (suppressed, rejected or not delivered). Logs report.send {recipients, failed, pdf} — never an address.
 */
function report_send(PDO $pdo, int $id, array $recipients, ?string $message, int $by, ?int &$failed = null): int
{
    $r = find_report($pdo, $id) ?? throw new DomainException('Not found.');
    if (!in_array($r['status'], ['approved', 'published'], true)) { throw new DomainException('Approve the pack before it is sent: a draft goes to no one.'); }
    $ids = array_values(array_unique(array_map('intval', $recipients)));
    if ($ids === [] || count($ids) > 25) { throw new DomainException($ids === [] ? 'Choose who the pack goes to.' : 'A pack goes to at most 25 people at once.'); }
    foreach ($ids as $m) {
        if (($why = report_recipient_problem($pdo, (int) $r['issuer_id'], $m)) !== null) { throw new DomainException($why); }
    }
    if (trim((string) env('MALUMAIL_API_KEY', '')) === '') { throw new DomainException('Mail is not configured here: ask the admin to set the MaluMail key.'); }
    $sender = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]);
    $row = report_row($pdo, $id);
    $url = app_url('/reports/' . $id);
    $accepted = 0;
    $failed = 0;
    foreach ($ids as $m) {
        $to = $pdo->prepare('SELECT display_name, email FROM members WHERE id = :m');
        $to->execute(['m' => $m]);
        $p = $to->fetch();
        $data = ['r' => $r, 'url' => $url, 'sender' => $sender, 'message' => $message, 'to' => $p['display_name'], 'narrative' => markdown_html((string) ($r['narrative'] ?? ''))];
        $mail = ['from' => (string) env('MAIL_FROM', 'shareholders@localhost'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => (string) $p['email'],
                 'subject' => (string) $r['issuer_name'] . ' — ' . $r['title'], 'html' => view('emails/pack.php', $data), 'text' => trim(($message !== null && $message !== '' ? $message . "\n\n" : '') . $r['title'] . "\n" . $r['period_from'] . ' to ' . $r['period_to'] . ($r['narrative'] ? "\n\n" . $r['narrative'] : '') . "\n\nOpen the pack: " . $url . "\n\n" . $sender . ' sent this from ' . app_name() . '. Sign in to read the pack with its tables.')];
        try {
            $res = malumail_send($mail);
            $ok = $res['status'] === 200 && in_array(strtolower((string) $p['email']), array_map('strtolower', array_map('strval', (array) ($res['body']['accepted'] ?? []))), true);
        } catch (Throwable $e) {
            error_log('report_send: ' . $e->getMessage());
            $ok = false;
        }
        $ok ? $accepted++ : $failed++;
    }
    if ($accepted === 0) { throw new DomainException('MaluMail accepted no recipient (an address may be suppressed or invalid); nothing was sent.'); }
    $pdo->prepare("UPDATE reports SET status = 'published' WHERE id = :id AND status IN ('approved', 'published')")->execute(['id' => $id]);
    log_activity($pdo, 'report.send', 'report', $id, ['issuer_id' => (int) $r['issuer_id'], 'before' => ['status' => $r['status']], 'after' => ['report_id' => $id, 'recipients' => $accepted, 'failed' => $failed, 'pdf' => $row !== null && $row['pdf_path'] !== null]]);
    return $accepted;
}

/** A pack to the tombstone (a draft or an approved one); the purge window and the restore are the admin's (slice 10). */
function report_delete(PDO $pdo, int $id): void
{
    $row = report_row($pdo, $id) ?? throw new DomainException('Not found.');
    $pdo->prepare('UPDATE reports SET deleted_at = now() WHERE id = :id')->execute(['id' => $id]);
    log_activity($pdo, 'report.delete', 'report', $id, ['issuer_id' => (int) $row['issuer_id'], 'before' => ['status' => $row['status']], 'after' => ['report_id' => $id, 'kind' => $row['kind']]]);
}
