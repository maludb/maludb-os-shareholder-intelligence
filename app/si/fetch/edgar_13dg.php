<?php
declare(strict_types=1);

/**
 * Connector `edgar_13dg` (edgar-and-finra-fetchers.md §2): Schedules 13D and 13G about the issuer from its index (EDGAR lists a schedule under its
 * subject company). The XML of a schedule filed since 2024-12-18 → beneficial_ownership: the first reporting person the filer (an institution,
 * si_institution_upsert — its CIK, else the accession's filer prefix), the others its group, the cover's powers, the shares, the percent, the event
 * date, item 4's purpose, the amendment; an older schedule's HTML read best-effort from its cover rows (the name, row 9's amount, row 11's percent;
 * filer_type NULL). ownership.load per filing.
 */

require_once __DIR__ . '/ownership_common.php';

const SI_13DG_FORMS = ['SC 13D', 'SC 13D/A', 'SC 13G', 'SC 13G/A', 'SCHEDULE 13D', 'SCHEDULE 13D/A', 'SCHEDULE 13G', 'SCHEDULE 13G/A'];

function si_fetch_edgar_13dg(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $seen = 0; $new = 0; $failed = 0;
    foreach (si_ownership_due($pdo, $source, $ctx, SI_13DG_FORMS) as $f) {
        $d = si_ownership_document($pdo, $client, $f);
        if ($d['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => 0, 'error' => $d['error']];
        }
        $seen++;
        if (!$d['ok']) { si_ownership_mark($pdo, (int) $f['id'], $d['error']); $failed++; continue; }
        try {
            if (str_ends_with(strtolower((string) $d['name']), '.xml') && !si_ownership_about(si_xml_load($d['body']), (string) $f['issuer_cik'])) { si_ownership_mark($pdo, (int) $f['id'], SI_NOT_ABOUT_US); continue; }
            $b = str_ends_with(strtolower((string) $d['name']), '.xml') ? si_parse_13dg_xml($d['body']) : si_parse_13dg_html($d['body']);
        } catch (Throwable $e) {
            si_ownership_mark($pdo, (int) $f['id'], 'Not read as a schedule 13D/G: ' . $e->getMessage());
            $failed++;
            continue;
        }
        $schedule = str_contains((string) $f['form'], '13D') ? '13D' : '13G';
        $cik = $b['cik'] ?? substr((string) $f['accession_number'], 0, 10);
        $sec = ($b['cusip'] ? one_value($pdo, 'SELECT id FROM securities WHERE issuer_id = :i AND upper(cusip) = :c', ['i' => (int) $f['issuer_id'], 'c' => strtoupper((string) $b['cusip'])]) : null)
            ?: one_value($pdo, 'SELECT id FROM securities WHERE issuer_id = :i AND is_primary LIMIT 1', ['i' => (int) $f['issuer_id']]);
        if (!$sec) { si_ownership_mark($pdo, (int) $f['id'], 'The issuer has no security to hold the schedule.'); $failed++; continue; }
        $pdo->beginTransaction();
        $known = one_value($pdo, 'SELECT id FROM institutions WHERE cik = :c', ['c' => $cik]);
        $inst = (int) one_value($pdo, 'SELECT si_institution_upsert(:c, :n)', ['c' => $cik, 'n' => $b['name']]);
        if ($known === null || $known === false) {
            log_activity($pdo, 'institution.create', 'institution', $inst, ['after' => ['institution_id' => $inst, 'cik' => $cik, 'from' => 'schedule']]);
        }
        $amend = str_ends_with((string) $f['form'], '/A');
        $st = $pdo->prepare('INSERT INTO beneficial_ownership (institution_id, security_id, filing_id, schedule, is_amendment, amendment_no, filed_on, event_date, percent_of_class, shares_beneficially_owned,
                                  sole_voting, shared_voting, sole_dispositive, shared_dispositive, purpose_text, group_members, filer_type, raw_xml_sha)
                             VALUES (:i, :s, :f, :sch, :am, :amno, :filed, :ev, :pct, :sh, :sv, :shv, :sd, :shd, :pur, CAST(:grp AS text[]), :ft, :sha)
                             ON CONFLICT (institution_id, security_id, filed_on, schedule, COALESCE(amendment_no, 0)) DO NOTHING RETURNING id');
        $st->execute(['i' => $inst, 's' => (int) $sec, 'f' => (int) $f['id'], 'sch' => $schedule, 'am' => $amend ? 'true' : 'false', 'amno' => $b['amendment_no'] ?? ($amend ? null : null), 'filed' => $f['filed_on'],
            'ev' => $b['event_date'], 'pct' => $b['percent'], 'sh' => $b['shares'], 'sv' => $b['sole_voting'] ?? null, 'shv' => $b['shared_voting'] ?? null, 'sd' => $b['sole_dispositive'] ?? null, 'shd' => $b['shared_dispositive'] ?? null,
            'pur' => $b['purpose'] ?? null, 'grp' => '{' . implode(',', array_map(static fn ($t) => '"' . str_replace('"', '', $t) . '"', $b['group'] ?? [])) . '}',
            'ft' => $schedule === '13D' && $b['xml'] ? 'active' : ($b['filer_type'] ?? null), 'sha' => hash('sha256', $d['body'])]);
        $made = $st->fetchColumn() !== false;
        si_ownership_mark($pdo, (int) $f['id'], null);
        log_activity($pdo, 'ownership.load', 'filing', (int) $f['id'], ['issuer_id' => (int) $f['issuer_id'], 'security_id' => (int) $sec, 'after' => ['filing_id' => (int) $f['id'], 'schedule' => $schedule, 'percent' => $b['percent'] === null ? null : (float) $b['percent'], 'institution_id' => $inst]]);
        $pdo->commit();
        $new += $made ? 1 : 0;
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => 0, 'status' => 'ok', 'error' => $failed > 0 ? $failed . ' schedule(s) could not be read (their error is on the filing).' : null];
}

function si_parse_13dg_xml(string $xml): array
{
    $x = si_xml_load($xml);
    // the 13D's reportingPersonInfo; the 13G's coverPageHeaderReportingPersonDetails (no CIK on the person: the filer's, from the header)
    $people = ($x->xpath('.//reportingPersonInfo') ?: []) ?: ($x->xpath('.//coverPageHeaderReportingPersonDetails') ?: []);
    if ($people === []) {
        throw new SiXmlError('no reporting person');
    }
    $p = $people[0];
    $cik = si_x($x, 'reportingPersonCIK', $p) ?? si_x($x, 'reportingPersonCik', $p) ?? (($h = $x->xpath('.//headerData//filer//cik')) ? trim((string) $h[0]) : null);
    $designation = strtolower((string) (si_x($x, 'designateRulePursuantThisScheduleFiled') ?? si_x($x, 'rule13d1') ?? ''));
    $purpose = $x->xpath('.//coverPageHeaderReportingPersonDetails') ? null : (($i4 = $x->xpath('.//item4')) ? trim(preg_replace('~\s+~', ' ', strip_tags($i4[0]->asXML() ?: '')) ?? '') : null);   // a 13G's item 4 is the amount, not a purpose
    return ['xml' => true, 'cik' => $cik !== null && preg_match('~^\d{1,10}$~', $cik) ? str_pad($cik, 10, '0', STR_PAD_LEFT) : null, 'name' => (string) (si_x($x, 'reportingPersonName', $p) ?? 'UNKNOWN FILER'),
            'cusip' => si_x($x, 'issuerCUSIP') ?? si_x($x, 'issuerCusip'), 'event_date' => si_date_any(si_x($x, 'dateOfEvent') ?? si_x($x, 'eventDateRequiresFilingThisStatement')),
            'percent' => si_num(si_x($x, 'percentOfClass', $p) ?? si_x($x, 'classPercent', $p)), 'shares' => si_num(si_x($x, 'aggregateAmountOwned', $p) ?? si_x($x, 'reportingPersonBeneficiallyOwnedAggregateNumberOfShares', $p)), 'sole_voting' => si_num(si_x($x, 'soleVotingPower', $p)),
            'shared_voting' => si_num(si_x($x, 'sharedVotingPower', $p)), 'sole_dispositive' => si_num(si_x($x, 'soleDispositivePower', $p)), 'shared_dispositive' => si_num(si_x($x, 'sharedDispositivePower', $p)),
            'purpose' => $purpose === '' ? null : $purpose, 'group' => array_values(array_filter(array_map(static fn ($q) => si_x($q, 'reportingPersonName', $q), array_slice($people, 1)))),
            'amendment_no' => ($a = si_num(si_x($x, 'amendmentNo'))) === null ? null : (int) $a,
            'filer_type' => str_contains($designation, '1(b)') ? 'qii' : (str_contains($designation, '1(c)') ? 'passive' : (str_contains($designation, '1(d)') ? 'exempt' : null))];
}

/** An older schedule's HTML, best-effort from the cover rows. */
function si_parse_13dg_html(string $html): array
{
    $t = si_html_to_text($html);
    $sep = '[\s|]+';                                                     // a cell's bar, or the row's own lines (an older filer's cover)
    $names = [];
    if (preg_match_all('~NAMES? OF REPORTING PERSONS?[^\n|]*' . $sep . '((?:I\.?R\.?S\.?[^\n|]*' . $sep . ')?)([^\n|]+)~i', $t, $m)) {
        foreach ($m[2] as $n) {
            $n = trim($n);
            if ($n !== '' && !preg_match('~^(\d+|CHECK THE)~i', $n)) { $names[] = $n; }
        }
    }
    $names = array_values(array_unique($names));
    $amount = preg_match('~AGGREGATE AMOUNT BENEFICIALLY OWNED[^\n|]*' . $sep . '([\d,]+)~i', $t, $m) ? si_num($m[1]) : null;
    $pct = preg_match('~PERCENT OF CLASS[^\n|]*' . $sep . '(?:[^\n|%]*?)([\d.]+)\s*%~i', $t, $m) ? $m[1] : null;
    $power = static fn (string $label): ?string => preg_match('~' . $label . '[^\n|]*' . $sep . '([\d,]+)~i', $t, $m) ? si_num($m[1]) : null;
    if ($names === [] && $amount === null && $pct === null) {
        throw new RuntimeException('the cover\'s rows were not found');
    }
    return ['xml' => false, 'cik' => null, 'name' => $names[0] ?? 'UNKNOWN FILER', 'cusip' => preg_match('~([0-9A-Z]{9})\s*\(CUSIP~i', $t, $m) ? $m[1] : null,
            'event_date' => preg_match('~([A-Z][a-z]+\.? \d{1,2}, \d{4})\s*\(Date of Event~', $t, $m) ? date('Y-m-d', (int) strtotime($m[1])) : null,
            'percent' => $pct, 'shares' => $amount, 'sole_voting' => $power('SOLE VOTING POWER'), 'shared_voting' => $power('SHARED VOTING POWER'),
            'sole_dispositive' => $power('SOLE DISPOSITIVE POWER'), 'shared_dispositive' => $power('SHARED DISPOSITIVE POWER'),
            'purpose' => null, 'group' => array_slice($names, 1), 'amendment_no' => preg_match('~Amendment No\.?\s*(\d+)~i', $t, $m) ? (int) $m[1] : null, 'filer_type' => null];
}
