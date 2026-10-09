#!/usr/bin/env python3
r"""Reads DTC's Security Position Report as the owner receives it (tests/fixtures/real/*SPR*.csv) in its two layouts and writes the
unpivoted rows the proof loads plus the control totals the file carries itself. No person's data: an SPR names brokers.
  WEEKLY  "Weekly Subscription Security Position Report / Daily Closing Balances / For Week Ending MM/DD/YYYY": a two-row header
          (Participant / Number, Name, Opening <prev Friday>, Monday..Friday <dates>, Share Change, Percent Change), rows, a Total row.
          DTC writes -9999999999999 (the Total row) or the word "Holiday" (a participant row) for a day with no balance: the day is ABSENT —
          no row, no control total.
  ONE-TIME "One Time Security Position Report / Position as of: MM/DD/YYYY": Participant Number (no leading zeros), Name, Quantity.
    mcp/venv/bin/python -I tests/fixtures/load_real_spr.py <spr csv> <out rows csv> <out control json>
"""
import sys, csv, json, re
src, out_rows, out_ctrl = sys.argv[1:4]
lines = [l.rstrip('\r') for l in open(src, encoding='utf-8', errors='replace').read().splitlines()]
SENT = '-9999999999999'
def iso(d): m, dd, y = d.strip().split('/'); return f"{y}-{m}-{dd}"
rows = []; ctrl = {}
cusip = next((lines[k + 1].split(',')[3 if lines[k].startswith('Security Description,,,') else 1].strip() for k, l in enumerate(lines) if l.startswith('Security Description')), None)
if any(l.startswith('Participant,Participant') for l in lines):
    week_ending = next(l for l in lines if 'Week Ending' in l).split('Ending')[-1].strip()
    i = next(k for k, l in enumerate(lines) if l.startswith('Participant,Participant'))
    hdr1 = lines[i].split(','); hdr2 = lines[i + 1].split(',')
    dates = [hdr2[c] for c in range(2, 8)]; labels = [hdr1[c] for c in range(2, 8)]
    total = None
    for n, l in enumerate(lines[i + 2:], start=i + 3):
        cells = l.split(',')
        if not l.strip(): continue
        if cells[0] == 'Total': total = cells; continue
        if not re.match(r'^\d{4}$', cells[0]): continue
        for c in range(6):
            v = cells[2 + c].strip()
            if v == '' or v == SENT or not re.match(r'^-?\d+$', v): continue       # '', the sentinel, or the word 'Holiday': absent
            rows.append([n, cells[0], cells[1].strip(), iso(dates[c]), v, labels[c]])
    ctrl = {'layout': 'weekly', 'cusip': cusip, 'week_ending': iso(week_ending),
            'shares_by_date': {iso(dates[c]): int(total[2 + c]) for c in range(6) if total and total[2 + c].strip() not in ('', SENT)},
            'absent_dates': [iso(dates[c]) for c in range(6) if total and total[2 + c].strip() == SENT],
            'participants': len({r[1] for r in rows}), 'share_change_total': int(total[8]) if total and total[8].strip() else None}
else:
    asof = next(l for l in lines if 'Position as of' in l).split('as of:')[-1].strip().strip(',')
    i = next(k for k, l in enumerate(lines) if l.startswith('Number,'))
    for n, l in enumerate(lines[i + 1:], start=i + 2):
        cells = l.split(',')
        if not l.strip() or not re.match(r'^\d{1,4}$', cells[0].strip()): continue
        rows.append([n, cells[0].strip().zfill(4), cells[2].strip(), iso(asof), cells[4].strip(), 'Quantity'])
    ctrl = {'layout': 'one_time', 'cusip': cusip, 'as_of': iso(asof), 'shares_by_date': {iso(asof): sum(int(r[4]) for r in rows)},
            'participants': len(rows), 'note': 'a one-time report carries no Total row: the control is the sum of the rows'}
with open(out_rows, 'w', newline='') as f:
    w = csv.writer(f); w.writerow(['row_no', 'participant_number', 'participant_name', 'position_date', 'shares', 'column']); w.writerows(rows)
json.dump(ctrl, open(out_ctrl, 'w'), indent=1); print(json.dumps(ctrl))
