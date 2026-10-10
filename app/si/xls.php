<?php
declare(strict_types=1);

/**
 * An old Excel workbook (.xls — BIFF8 in a Compound File) read without a library: the container's FAT and directory, the `Workbook` stream,
 * and of its records only what a value needs — the sheet list (BOUNDSHEET), the shared strings (SST with its CONTINUE records), and the cells
 * (LABELSST, LABEL, NUMBER, RK, MULRK, FORMULA with its cached result, STRING, BOOLERR). No formula is evaluated; no macro is read. A
 * password-protected workbook (FILEPASS) is refused in words. Broadridge's Share Range Analysis is the reader's purpose (slice 3).
 *   si_xls_sheets(string $bytes, int $maxRows = 200000): array{name => rows[][]}  — the shape of si_xlsx_sheets()
 */

final class SiXlsError extends RuntimeException
{
}

function si_xls_u16(string $b, int $o): int
{
    return ord($b[$o]) | (ord($b[$o + 1]) << 8);
}

function si_xls_u32(string $b, int $o): int
{
    return unpack('V', substr($b, $o, 4))[1];
}

/** The Compound File's stream by name (the first of $names found). */
function si_xls_stream(string $bytes, array $names): string
{
    if (strlen($bytes) < 512 || !str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        throw new SiXlsError('not an old Excel workbook');
    }
    $secSize = 1 << si_xls_u16($bytes, 0x1E);
    $miniSize = 1 << si_xls_u16($bytes, 0x20);
    $nFat = si_xls_u32($bytes, 0x2C);
    $dirStart = si_xls_u32($bytes, 0x30);
    $cutoff = si_xls_u32($bytes, 0x38);
    $miniFatStart = si_xls_u32($bytes, 0x3C);
    $difatStart = si_xls_u32($bytes, 0x44);
    $nDifat = si_xls_u32($bytes, 0x48);
    $sector = static fn (int $n): string => substr($bytes, 512 + $n * $secSize, $secSize);
    $fatSectors = [];
    for ($i = 0; $i < 109 && count($fatSectors) < $nFat; $i++) {
        $s = si_xls_u32($bytes, 0x4C + 4 * $i);
        if ($s >= 0xFFFFFFFA) { break; }
        $fatSectors[] = $s;
    }
    for ($d = $difatStart, $k = 0; $k < $nDifat && $d < 0xFFFFFFFA; $k++) {
        $sec = $sector($d);
        $per = intdiv($secSize, 4) - 1;
        for ($i = 0; $i < $per && count($fatSectors) < $nFat; $i++) {
            $s = si_xls_u32($sec, 4 * $i);
            if ($s < 0xFFFFFFFA) { $fatSectors[] = $s; }
        }
        $d = si_xls_u32($sec, 4 * $per);
    }
    $fat = [];
    foreach ($fatSectors as $s) {
        $fat = array_merge($fat, array_values(unpack('V*', $sector($s))));
    }
    $chain = static function (int $start, array $table, int $limit) {
        $out = [];
        for ($s = $start, $n = 0; $s < 0xFFFFFFFA && $n < $limit; $n++) {
            $out[] = $s;
            $s = $table[$s] ?? 0xFFFFFFFE;
        }
        return $out;
    };
    $read = static fn (int $start): string => implode('', array_map($sector, $chain($start, $fat, 1 << 20)));
    $dir = $read($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $len = si_xls_u16($dir, $o + 0x40);
        $name = $len >= 2 ? (string) mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = ['name' => $name, 'type' => ord($dir[$o + 0x42]), 'start' => si_xls_u32($dir, $o + 0x74), 'size' => si_xls_u32($dir, $o + 0x78)];
    }
    $root = $entries[0] ?? null;
    foreach ($names as $want) {
        foreach ($entries as $e) {
            if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) {
                continue;
            }
            if ($e['size'] >= $cutoff || $root === null) {
                return substr($read($e['start']), 0, $e['size']);
            }
            $miniStream = $read($root['start']);
            $miniFat = $miniFatStart < 0xFFFFFFFA ? array_values(unpack('V*', $read($miniFatStart))) : [];
            $out = '';
            foreach ($chain($e['start'], $miniFat, 1 << 20) as $m) {
                $out .= substr($miniStream, $m * $miniSize, $miniSize);
            }
            return substr($out, 0, $e['size']);
        }
    }
    throw new SiXlsError('the workbook has no Workbook stream');
}

/** An RK number → its value. */
function si_xls_rk(int $rk): float|int
{
    if ($rk & 0x02) {
        $v = $rk >> 2;
        if ($v & 0x20000000) { $v -= 0x40000000; }               // a signed 30-bit integer
    } else {
        $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];      // the high word of a little-endian double
    }
    return ($rk & 0x01) ? $v / 100 : $v;
}

/** A number as written in a cell: a whole number without its ".0". */
function si_xls_num(float|int $v): string
{
    if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
        return (string) (int) $v;
    }
    return (string) $v;
}

/**
 * The record stream's strings: a reader over the segments of one record and its CONTINUE records (a string's characters may cross into the
 * next segment, which then begins with a fresh flags byte).
 */
final class SiXlsStrings
{
    private int $seg = 0;
    private int $pos = 0;

    /** @param string[] $segments */
    public function __construct(private array $segments)
    {
    }

    private function need(): void
    {
        while ($this->seg < count($this->segments) && $this->pos >= strlen($this->segments[$this->seg])) {
            $this->seg++;
            $this->pos = 0;
        }
        if ($this->seg >= count($this->segments)) {
            throw new SiXlsError('the shared strings end early');
        }
    }

    public function bytes(int $n): string
    {
        $out = '';
        while ($n > 0) {
            $this->need();
            $take = min($n, strlen($this->segments[$this->seg]) - $this->pos);
            $out .= substr($this->segments[$this->seg], $this->pos, $take);
            $this->pos += $take;
            $n -= $take;
        }
        return $out;
    }

    public function u8(): int { return ord($this->bytes(1)); }
    public function u16(): int { return unpack('v', $this->bytes(2))[1]; }
    public function u32(): int { return unpack('V', $this->bytes(4))[1]; }

    /** XLUnicodeRichExtendedString: cch, flags, [runs], [ext], the characters (8- or 16-bit, switching at a segment's start), the runs, the ext. */
    public function richString(): string
    {
        $cch = $this->u16();
        $flags = $this->u8();
        $runs = ($flags & 0x08) ? $this->u16() : 0;
        $ext = ($flags & 0x04) ? $this->u32() : 0;
        $wide = (bool) ($flags & 0x01);
        $out = '';
        while ($cch > 0) {
            $this->need();
            if ($this->pos === 0 && $out !== '') {                     // a continued string: the new segment's flags byte
                $wide = (bool) (ord($this->segments[$this->seg][0]) & 0x01);
                $this->pos = 1;
            }
            $avail = strlen($this->segments[$this->seg]) - $this->pos;
            $take = min($cch, $wide ? intdiv($avail, 2) : $avail);
            if ($take <= 0) {
                $this->seg++;
                $this->pos = 0;
                continue;
            }
            $raw = $this->bytes($wide ? $take * 2 : $take);
            $out .= $wide ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $cch -= $take;
            if ($cch > 0 && $this->pos >= strlen($this->segments[$this->seg])) {
                $this->seg++;
                $this->pos = 0;
                $this->need();
                $wide = (bool) (ord($this->segments[$this->seg][0]) & 0x01);
                $this->pos = 1;
            }
        }
        if ($runs > 0) { $this->bytes(4 * $runs); }
        if ($ext > 0) { $this->bytes($ext); }
        return $out;
    }
}

/** A short or a long XLUnicodeString inside one record ($o advances). */
function si_xls_string(string $d, int &$o, bool $short): string
{
    $cch = $short ? ord($d[$o]) : si_xls_u16($d, $o);
    $o += $short ? 1 : 2;
    $wide = (bool) (ord($d[$o]) & 0x01);
    $o += 1;
    $raw = substr($d, $o, $wide ? $cch * 2 : $cch);
    $o += strlen($raw);
    return $wide ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
}

function si_xls_sheets(string $bytes, int $maxRows = 200000): array
{
    $wb = si_xls_stream($bytes, ['Workbook', 'Book']);
    $len = strlen($wb);
    // the records: [type, data, offset]
    $records = [];
    for ($o = 0; $o + 4 <= $len;) {
        $type = si_xls_u16($wb, $o);
        $size = si_xls_u16($wb, $o + 2);
        $records[] = [$type, substr($wb, $o + 4, $size), $o];
        $o += 4 + $size;
    }
    $sheets = [];
    $sst = [];
    $n = count($records);
    for ($i = 0; $i < $n; $i++) {
        [$type, $d] = $records[$i];
        if ($type === 0x002F) {
            throw new SiXlsError('The workbook is password-protected: open it in Excel, remove the password and upload it again.');
        }
        if ($type === 0x0085) {
            $o = 6;
            $sheets[] = ['offset' => si_xls_u32($d, 0), 'type' => ord($d[5]), 'name' => si_xls_string($d, $o, true)];
        } elseif ($type === 0x00FC) {
            $segments = [substr($d, 8)];
            while ($i + 1 < $n && $records[$i + 1][0] === 0x003C) {
                $segments[] = $records[++$i][1];
            }
            $r = new SiXlsStrings($segments);
            $count = si_xls_u32($d, 4);
            for ($k = 0; $k < $count; $k++) {
                $sst[] = $r->richString();
            }
        }
    }
    $byOffset = [];
    foreach ($records as $idx => [, , $off]) {
        $byOffset[$off] = $idx;
    }
    $out = [];
    foreach ($sheets as $s) {
        if ($s['type'] !== 0 || !isset($byOffset[$s['offset']])) {
            continue;                                                   // a chart or a macro sheet
        }
        $cells = [];
        $pending = null;
        for ($i = $byOffset[$s['offset']] + 1; $i < $n; $i++) {
            [$type, $d] = $records[$i];
            if ($type === 0x000A) {
                break;
            }
            switch ($type) {
                case 0x00FD:
                    $cells[si_xls_u16($d, 0)][si_xls_u16($d, 2)] = $sst[si_xls_u32($d, 6)] ?? '';
                    break;
                case 0x0204:
                    $o = 6;
                    $cells[si_xls_u16($d, 0)][si_xls_u16($d, 2)] = si_xls_string($d, $o, false);
                    break;
                case 0x0203:
                    $cells[si_xls_u16($d, 0)][si_xls_u16($d, 2)] = si_xls_num(unpack('e', substr($d, 6, 8))[1]);
                    break;
                case 0x027E:
                    $cells[si_xls_u16($d, 0)][si_xls_u16($d, 2)] = si_xls_num(si_xls_rk(si_xls_u32($d, 6)));
                    break;
                case 0x00BD:
                    $row = si_xls_u16($d, 0);
                    $first = si_xls_u16($d, 2);
                    $k = intdiv(strlen($d) - 6, 6);
                    for ($j = 0; $j < $k; $j++) {
                        $cells[$row][$first + $j] = si_xls_num(si_xls_rk(si_xls_u32($d, 4 + 6 * $j + 2)));
                    }
                    break;
                case 0x0205:
                    $cells[si_xls_u16($d, 0)][si_xls_u16($d, 2)] = ord($d[7]) === 0 ? (ord($d[6]) ? 'TRUE' : 'FALSE') : '#ERR';
                    break;
                case 0x0006:
                    $row = si_xls_u16($d, 0);
                    $col = si_xls_u16($d, 2);
                    if (si_xls_u16($d, 12) === 0xFFFF) {
                        $kind = ord($d[6]);
                        if ($kind === 0) { $pending = [$row, $col]; }   // a string result: the STRING record follows
                        elseif ($kind === 1) { $cells[$row][$col] = ord($d[8]) ? 'TRUE' : 'FALSE'; }
                        elseif ($kind === 3) { $cells[$row][$col] = ''; }
                        else { $cells[$row][$col] = '#ERR'; }
                    } else {
                        $cells[$row][$col] = si_xls_num(unpack('e', substr($d, 6, 8))[1]);
                    }
                    break;
                case 0x0207:
                    if ($pending !== null) {
                        $o = 0;
                        $cells[$pending[0]][$pending[1]] = si_xls_string($d, $o, false);
                        $pending = null;
                    }
                    break;
            }
        }
        $rows = [];
        if ($cells !== []) {
            ksort($cells);
            $last = min(max(array_keys($cells)), $maxRows - 1);
            for ($r = 0; $r <= $last; $r++) {
                $line = [];
                if (isset($cells[$r])) {
                    for ($c = 0, $max = max(array_keys($cells[$r])); $c <= $max; $c++) { $line[] = trim((string) ($cells[$r][$c] ?? '')); }
                }
                $rows[] = $line;
            }
        }
        $out[$s['name']] = $rows;
    }
    return $out;
}
