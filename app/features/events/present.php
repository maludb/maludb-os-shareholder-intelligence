<?php
declare(strict_types=1);

/**
 * The events' whitelist presenters (the JSON branch of every screen) and the chips the timeline, the calendar and the pages share.
 * An event's body is the person's Markdown: JSON carries it as written; a page renders it through markdown_html() (escaped first).
 */

/** The colour of a kind's chip (Bootstrap soft badges): the kinds an IR officer scans for stand out. */
const EVENT_KIND_CHIPS = ['filing' => 'bg-soft-primary text-primary', 'financing' => 'bg-soft-danger text-danger', 'dilution_instrument' => 'bg-soft-danger text-danger', 'record_date' => 'bg-soft-info text-info',
    'earnings' => 'bg-soft-success text-success', 'press' => 'bg-soft-secondary text-secondary', 'activist_short_report' => 'bg-soft-danger text-danger', 'lawsuit' => 'bg-soft-warning text-warning',
    'exchange_notice' => 'bg-soft-warning text-warning', 'insider_transaction' => 'bg-soft-primary text-primary', 'share_count' => 'bg-soft-info text-info', 'threshold' => 'bg-soft-warning text-warning',
    'note' => 'bg-soft-secondary text-secondary', 'other' => 'bg-soft-secondary text-secondary', 'dilution_expiry' => 'bg-soft-warning text-warning',
    'short_interest_publication' => 'bg-soft-secondary text-muted', 'thirteen_f_deadline' => 'bg-soft-secondary text-muted'];

/** The kind's words, for the stored kinds and the computed ones. */
function event_kind_label(string $kind): string
{
    return EVENT_KINDS[$kind] ?? (CALENDAR_COMPUTED_KINDS[$kind] ?? ($kind === 'dilution_expiry' ? 'Instrument expires' : ucfirst(str_replace('_', ' ', $kind))));
}

function event_kind_chip(string $kind, string $extra = ''): string
{
    return '<span class="badge ' . (EVENT_KIND_CHIPS[$kind] ?? 'bg-soft-secondary text-secondary') . '" data-kind="' . e($kind) . '"' . $extra . '>' . e(event_kind_label($kind)) . '</span>';
}

/** The severity as a dot with its word for assistive technology. */
function event_severity_dot(string $severity): string
{
    $c = ['high' => 'bg-danger', 'watch' => 'bg-warning', 'info' => 'bg-info'][$severity] ?? 'bg-secondary';
    return '<span class="d-inline-block rounded-circle ' . $c . '" style="width:10px;height:10px" title="' . e(ucfirst($severity)) . '" data-severity="' . e($severity) . '"></span><span class="visually-hidden">' . e($severity) . '</span>';
}

function present_event(array $e): array
{
    return ['event_id' => (int) $e['event_id'], 'issuer_id' => (int) $e['issuer_id'], 'security_id' => $e['security_id'] === null ? null : (int) $e['security_id'], 'on_date' => $e['on_date'],
            'kind' => $e['kind'], 'title' => $e['title'], 'body' => $e['body'] ?? null, 'url' => $e['url'] ?? null, 'filing_id' => $e['filing_id'] === null ? null : (int) $e['filing_id'],
            'filing' => ($e['filing_id'] ?? null) === null ? null : ['filing_id' => (int) $e['filing_id'], 'form' => $e['filing_form'] ?? null, 'filed_on' => $e['filing_filed_on'] ?? null],
            'severity' => $e['severity'], 'source' => $e['source'], 'tags' => is_array($e['tags'] ?? null) ? $e['tags'] : pg_text_array((string) ($e['tags'] ?? '')),
            'created_at' => json_ts($e['created_at'] ?? null), 'updated_at' => json_ts($e['updated_at'] ?? null)];
}

/** An upcoming or a calendar item: the stored events and the computed days in one shape. */
function present_upcoming(array $u): array
{
    return ['on_date' => $u['on_date'], 'kind' => $u['kind'], 'title' => $u['title'], 'source' => $u['source'], 'severity' => $u['severity'] ?? 'info',
            'event_id' => isset($u['event_id']) && $u['event_id'] !== null ? (int) $u['event_id'] : null, 'url' => $u['url'] ?? null, 'computed' => ($u['source'] ?? '') === 'computed'];
}

/** The URL of a calendar item (its event, its instrument), or null for a computed day. */
function upcoming_href(array $u): ?string
{
    if (($u['event_id'] ?? null) !== null) { return '/events/' . (int) $u['event_id']; }
    return $u['url'] ?? null;
}

/**
 * The person's Markdown as safe HTML: the text is escaped FIRST, then headings (#–###), bullet and numbered lists, **bold**, *italic*, `code`,
 * [text](https://…) links (http and https only, opened with rel noopener) and paragraphs. No HTML of the author's reaches the page.
 */
function markdown_html(?string $md): string
{
    $md = trim(str_replace(["\r\n", "\r"], "\n", (string) $md));
    if ($md === '') {
        return '';
    }
    $inline = static function (string $t): string {
        $t = e($t);
        $t = preg_replace_callback('~\[([^\]]+)\]\((https?://[^\s)]+)\)~', static fn (array $m): string => '<a href="' . $m[2] . '" rel="noopener noreferrer" target="_blank">' . $m[1] . '</a>', $t) ?? $t;
        $t = preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('~(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])~s', '<em>$1</em>', $t) ?? $t;
        return preg_replace('~`([^`]+)`~', '<code>$1</code>', $t) ?? $t;
    };
    $out = [];
    $para = [];
    $list = null;
    $flush = static function () use (&$out, &$para, &$list, $inline): void {
        if ($para !== []) { $out[] = '<p>' . implode('<br>', array_map($inline, $para)) . '</p>'; $para = []; }
        if ($list !== null) { $out[] = '<' . $list[0] . '>' . implode('', array_map(static fn (string $li): string => '<li>' . $inline($li) . '</li>', $list[1])) . '</' . $list[0] . '>'; $list = null; }
    };
    foreach (explode("\n", $md) as $line) {
        if (trim($line) === '') { $flush(); continue; }
        if (preg_match('~^(#{1,3})\s+(.+)$~', $line, $m)) { $flush(); $n = strlen($m[1]) + 2; $out[] = '<h' . $n . '>' . $inline($m[2]) . '</h' . $n . '>'; continue; }
        if (preg_match('~^\s*[-*]\s+(.+)$~', $line, $m)) {
            if ($list !== null && $list[0] !== 'ul') { $flush(); }
            if ($para !== []) { $flush(); }
            $list ??= ['ul', []]; $list[1][] = $m[1]; continue;
        }
        if (preg_match('~^\s*\d+[.)]\s+(.+)$~', $line, $m)) {
            if ($list !== null && $list[0] !== 'ol') { $flush(); }
            if ($para !== []) { $flush(); }
            $list ??= ['ol', []]; $list[1][] = $m[1]; continue;
        }
        if ($list !== null) { $flush(); }
        $para[] = $line;
    }
    $flush();
    return implode("\n", $out);
}
