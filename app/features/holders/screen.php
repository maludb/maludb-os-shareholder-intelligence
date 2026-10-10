<?php
declare(strict_types=1);

/** What every holder screen and handler starts with (slice 3): the bootstrap, the features, and the security a screen reads (as the positions screens do). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/positions/screen.php';
require_once dirname(__DIR__) . '/notes/write.php';

/** A record date from the request among those loaded (else the latest); null when none is loaded. */
function holders_pick_date(array $dates): ?string
{
    $want = request_date('record_date');
    if (is_string($want) && in_array($want, $dates, true)) {
        return $want;
    }
    return $dates[0] ?? null;
}

/** The channel from the request (nobo by default). */
function holders_channel(): string
{
    return isset(HOLDER_CHANNELS[request_string('channel')]) ? request_string('channel') : 'nobo';
}

/** A holder the request names, or a 404 — with the "merged into" link when it was merged (holder-identity.md §3). */
function holder_from_request(PDO $pdo, string $field = 'holder'): array
{
    $id = request_integer($field) ?? request_integer('id');
    $h = $id === null ? null : find_holder($pdo, $id);
    if ($h === null) {
        $t = $id === null ? null : holder_tombstone($pdo, $id);
        if ($t !== null && $t['merged_into_id'] !== null) {
            if (wants_json()) {
                json_error('not_found', 'This holder was merged into holder ' . (int) $t['merged_into_id'] . '.', 404, ['merged_into' => (int) $t['merged_into_id']]);
            }
            http_response_code(404);
            render_screen('Merged', view('holders/partials/merged.php', ['into' => (int) $t['merged_into_id']]), ['activeNav' => 'holders', 'screen' => 'holder-view']);
            exit;
        }
        refuse(404, 'Not found.');
    }
    return $h;
}
