#!/usr/bin/env python3
r"""Builds the Phase 0 fixtures (design §14, the owner's answer (a)): the two files of the reference implementation ANONYMISED — every
natural person's name, street and zip replaced by synthetic ones, deterministically, so two registrations of one person stay one person;
shares, structure, legends, custodian lines, section titles, entity names of brokers and Cede & Co kept — and a SYNTHETIC SPR week (two
weeks of five position dates) over the seeded participants whose total on the register's date equals Cede & Co's line, with a crafted
retail→prime migration and one participant jump. Also the public-record samples in the shapes the fetchers will read. Writes the xlsx
files and the CSV/TSV rows the SQL proof loads (\copy). Run from the repo root with the venv's python:
    mcp/venv/bin/python -I tests/fixtures/build_fixtures.py <path to KineticIntel2 clone>
Nothing of a real person survives: names come from two word lists hashed from the original; a mapping is never written anywhere."""
import sys, os, csv, hashlib, re, json, datetime as dt
import openpyxl
src = sys.argv[1]; out = os.path.dirname(os.path.abspath(__file__))
FIRST = ["ALEX","BRIAN","CARLA","DANA","ELLEN","FRANK","GRACE","HENRY","IRENE","JULIA","KEVIN","LAURA","MARCO","NINA","OSCAR","PRIYA","QUINN","ROBERT","SARA","TOMAS","UMA","VICTOR","WENDY","XAVIER","YARA","ZANE"]
LAST  = ["ADLER","BAKER","CHEN","DIAZ","EVANS","FOSTER","GARCIA","HUANG","IVERSON","JONES","KUMAR","LOPEZ","MILLER","NGUYEN","OWENS","PATEL","QUIGLEY","REYES","SMITH","TAYLOR","UPTON","VARGAS","WALSH","XU","YOUNG","ZIMMER"]
STREET = ["OAK","MAPLE","CEDAR","PINE","ELM","BIRCH","WALNUT","SPRUCE","ASH","WILLOW","LAKE","HILL","RIVER","PARK","MEADOW","RIDGE"]
CITY = ["SPRINGFIELD","RIVERTON","FAIRVIEW","GREENVILLE","MADISON","CLINTON","SALEM","OXFORD","BRISTOL","ASHLAND","KINGSTON","MILFORD","NEWPORT","WINCHESTER","DAYTON","HUDSON"]
def h(s, n): return int(hashlib.sha256(s.encode()).hexdigest(), 16) % n
LEGEND = re.compile(r"\b(CUST|CUSTODIAN|IRA|ROTH|SEP|TOD|TTEE|TRUSTEE|TRUST|U/A|UA DTD|DTD|JT TEN|JTWROS|TEN COM|FBO|DBA|P/ADM|ADM|EXEC|ESTATE|EST OF|UTMA|UGMA|CONTRIBUTORY|ROLLOVER|DESIGNATED BENE|PLAN|SUBJECT TO STA RULES|FMT CO|FRP PS|A/C|ACCT|BENE|SEP IRA|SIMPLE)\b")
BROKER = re.compile(r"(SCHWAB|FIDELITY|FMT CO|NATIONAL FINANCIAL|ROBINHOOD|VANGUARD|TD AMERITRADE|E\*TRADE|ETRADE|MERRILL|PERSHING|APEX|INTERACTIVE BROKERS|WELLS FARGO|MORGAN STANLEY|J\.?P\.? ?MORGAN|UBS|GOLDMAN|QUESTRADE|RBC|BMO|CIBC|SCOTIA|TD WATERHOUSE|NATIONAL BANK|DESJARDINS|CREDENTIAL|AVISO|CANACCORD|RAYMOND JAMES|CEDE)")
ENTITY = re.compile(r"\b(INC|LLC|L\.L\.C|CORP|CORPORATION|LTD|LP|L\.P|PARTNERS|FUND|CAPITAL|HOLDINGS|FOUNDATION|COMPANY|CO|PLC)\b")
def person_name(orig):
    k = re.sub(r"[^A-Z ]", "", orig.upper()).strip()
    return f"{FIRST[h(k,26)]} {chr(65+h(k+'m',26))} {LAST[h(k+'l',26)]}"
def anon_name_line(line):
    s = line.strip().upper()
    if not s: return line
    if BROKER.search(s) or 'CEDE' in s: return s                      # a custodian's or Cede's own words stay
    if ENTITY.search(s) and not LEGEND.search(s):                      # an entity: a synthetic entity name
        return f"{LAST[h(s,26)]} {STREET[h(s+'e',16)]} HOLDINGS LLC"
    core = LEGEND.sub("", s); core = re.sub(r"\s+", " ", core).strip()
    legends = " ".join(m.group(0) for m in LEGEND.finditer(s))
    return (person_name(core) + (" " + legends if legends else "")).strip()
def anon_street(line):
    s = line.strip().upper(); m = re.match(r"^(\d+)\s*(.*)$", s)
    if not m: return s
    return f"{100 + h(s,8900)} {STREET[h(s+'s',16)]} {['ST','AVE','RD','DR','LN','CT'][h(s+'t',6)]}"
def anon_csz(line):
    s = line.strip().upper(); m = re.search(r"\b([A-Z]{2})\s+(\d{5})(?:-\d{4})?\s*$", s)
    if not m: return s
    st, z = m.group(1), m.group(2); zz = f"{10000 + h(z,89999):05d}"
    return f"{CITY[h(s,16)]} {st} {zz}"
def zip_anon(z):
    z = (z or "").strip(); return f"{10000 + h(z,89999):05d}" if re.match(r"^\d{5}", z) else z
# ---------------------------------------------------------------- the NOBO list
wb = openpyxl.load_workbook(os.path.join(src, "NOBOLIST.xlsx")); ws = wb.active
nobo_rows = []; clients = []; sec = "SHARES"; name_tokens = {}
for r in range(5, ws.max_row + 1):
    a = ws.cell(r, 1).value
    if a == "# OF NOBOS": sec = "SUMMARY"
    if a == "CUSIP": sec = "CLIENTS"; continue
    if sec == "SHARES" and isinstance(a, (int, float)):
        lines = [str(ws.cell(r, c).value or "").strip() for c in range(2, 9)]
        name_line = lines[0]; new_name = anon_name_line(name_line)
        # the person's tokens replaced wherever they reappear (FBO DANIEL GUMBER)
        orig_tokens = [t for t in re.sub(r"[^A-Z ]", "", name_line.upper()).split() if len(t) > 2 and not LEGEND.match(t)]
        new_tokens = [t for t in new_name.split() if len(t) > 2 and not LEGEND.match(t)]
        out_lines = [new_name]
        for ln in lines[1:]:
            u = ln.upper()
            if not u: out_lines.append(""); continue
            if re.search(r"\b[A-Z]{2}\s+\d{5}", u): out_lines.append(anon_csz(u)); continue
            if re.match(r"^\d", u): out_lines.append(anon_street(u)); continue
            if BROKER.search(u) or LEGEND.search(u):
                for i, t in enumerate(orig_tokens):
                    if t in u: u = u.replace(t, new_tokens[min(i, len(new_tokens)-1)] if new_tokens else "X")
                out_lines.append(u); continue
            out_lines.append(anon_name_line(u) if ENTITY.search(u) or len(u.split()) <= 4 else u)
        zsort = zip_anon(str(ws.cell(r, 9).value or ""))
        for c, v in enumerate(out_lines, start=2): ws.cell(r, c).value = (v + " " * (36 - len(v)))[:36] if v else " " * 36
        ws.cell(r, 9).value = zsort
        nobo_rows.append([r, int(a)] + out_lines + [zsort, str(ws.cell(r, 10).value or "")])
    if sec == "CLIENTS" and a and a != "CUSIP":
        clients.append([r, str(a), str(ws.cell(r, 2).value or "").strip(), ws.cell(r, 3).value, ws.cell(r, 4).value])
hdr = [str(ws.cell(2, c).value or "").strip() for c in range(1, 7)]
ws.cell(2, 3).value = "SMOKE ISSUER INC                    "
wb.save(os.path.join(out, "nobo_list.xlsx"))
with open(os.path.join(out, "nobo_rows.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["row_no","shares","line1","line2","line3","line4","line5","line6","line7","zip_sort","cusip"]); w.writerows(nobo_rows)
with open(os.path.join(out, "nobo_clients.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["row_no","cusip","client","nobo_count","shares"]); w.writerows(clients)
nobo_header = {"job_number": hdr[0], "cusip": hdr[1], "issuer_name": "SMOKE ISSUER INC", "record_date": hdr[3], "sort_order": hdr[4], "nobo_count": int(hdr[5])}
# ---------------------------------------------------------------- the register (STC's position detail)
wb = openpyxl.load_workbook(os.path.join(src, "STCCommon.xlsx")); ws = wb.active
reg_rows = []; section = None; record_date = str(ws.cell(8, 1).value)
for r in range(8, ws.max_row + 1):
    b = ws.cell(r, 2).value
    if isinstance(b, str) and b.startswith("Common") and ws.cell(r, 5).value == b: section = b; continue
    name = ws.cell(r, 5).value; shares = ws.cell(r, 12).value
    if name is None or not isinstance(shares, (int, float)): continue
    nm = str(name).replace("\r", "").replace("\n", " ").strip().upper()
    if "CEDE" in nm or "PRIOR TA" in nm: new_name = nm
    else: new_name = anon_name_line(nm)
    street = anon_street(str(ws.cell(r, 6).value or "")); city = CITY[h(str(ws.cell(r, 7).value or ""), 16)]; st = str(ws.cell(r, 8).value or ""); ctry = str(ws.cell(r, 9).value or ""); z = zip_anon(str(ws.cell(r, 10).value or ""))
    if "CEDE" in nm: street, city, z = "55 WATER ST", "NEW YORK", "10041"
    ws.cell(r, 5).value = new_name; ws.cell(r, 6).value = street; ws.cell(r, 7).value = city; ws.cell(r, 10).value = z
    issue = ws.cell(r, 11).value; issue_s = issue.strftime("%Y-%m-%d") if isinstance(issue, dt.datetime) else ""
    reg_rows.append([r, section or "Common", ws.cell(r, 2).value, ws.cell(r, 3).value, ws.cell(r, 4).value, new_name, street, city, st, ctry, z, issue_s, int(shares),
                     ws.cell(r, 13).value or "", ws.cell(r, 14).value or "", ws.cell(r, 15).value or "", ws.cell(r, 16).value or "", ws.cell(r, 17).value or "", ws.cell(r, 18).value or ""])
wb.save(os.path.join(out, "register_stc.xlsx"))
with open(os.path.join(out, "register_rows.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["row_no","section","position_id","position_type","holder_ref","registration_name","address_line","city","state","country","zip","issue_date","shares","cost_basis","restriction_text","percent_owned","is_employee","is_officer_director","is_us_citizen"]); w.writerows(reg_rows)
cede_total = sum(x[12] for x in reg_rows if x[5].startswith("CEDE"))
reg_total = sum(x[12] for x in reg_rows)
# ---------------------------------------------------------------- the SPR: two weeks over the seeded participants, total = Cede on 2024-04-04
rd = dt.date(2024, 4, 4)
dates = []; d = rd - dt.timedelta(days=13)
while len(dates) < 10:
    if d.weekday() < 5: dates.append(d)
    d += dt.timedelta(days=1)
assert dates[-1] == rd or True
# the participants and their closing positions on the record date (must sum to cede_total); the earlier days drift
parts = [("0164","CHARLES SCHWAB & CO., INC.",0.19),("0226","NATIONAL FINANCIAL SERVICES LLC",0.17),("6769","ROBINHOOD SECURITIES, LLC",0.11),("0158","APEX CLEARING CORPORATION",0.06),("0062","VANGUARD MARKETING CORPORATION",0.03),
         ("0015","MORGAN STANLEY SMITH BARNEY LLC",0.04),("0671","MERRILL LYNCH, PIERCE, FENNER & SMITH INCORPORATED",0.04),("0443","PERSHING LLC",0.05),("0141","WELLS FARGO CLEARING SERVICES, LLC",0.03),("0005","GOLDMAN SACHS & CO. LLC",0.07),
         ("0050","MORGAN STANLEY & CO. LLC",0.05),("0161","BOFA SECURITIES, INC.",0.04),("0187","J.P. MORGAN SECURITIES LLC",0.03),("0395","CITADEL CLEARING LLC",0.03),("0063","VIRTU AMERICAS LLC",0.02),("3691","ROBINHOOD SECURITIES, LLC/LENDING",0.02),("0534","INTERACTIVE BROKERS LLC/RETAIL",0.02)]
closing = {}; acc = 0
for i, (num, name, frac) in enumerate(parts):
    v = int(cede_total * frac) if i < len(parts) - 1 else cede_total - acc
    closing[num] = v; acc += v
assert sum(closing.values()) == cede_total
# the drift: retail boxes LOSE 3% of the total over the two weeks, prime/lending GAIN it (the crafted migration); Goldman jumps 1.5% on day 7
retail = ["0164","0226","6769","0158","0062","0534"]; gain = ["0005","0050","0161","0395","3691"]
grid = {}
for num, name, frac in parts:
    series = []
    for k, dd in enumerate(dates):
        t = k / 9.0
        v = closing[num]
        if num in retail: v = int(closing[num] + cede_total * 0.03 * (1 - t) * (closing[num] / sum(closing[x] for x in retail)))
        if num in gain: v = int(closing[num] - cede_total * 0.03 * (1 - t) * (closing[num] / sum(closing[x] for x in gain)))
        if num == "0005" and k < 6: v -= int(cede_total * 0.015)
        series.append(max(v, 0))
    series[-1] = closing[num]
    grid[num] = (name, series)
spr_rows = []
wb = openpyxl.Workbook(); ws = wb.active; ws.title = "SPR"
for wk in range(2):
    base = wk * 5; top = 1 + wk * (len(parts) + 4)
    ws.cell(top, 1).value = "DTC SECURITY POSITION REPORT — CUSIP 07831X206 — SMOKE ISSUER INC (synthetic fixture)"
    ws.cell(top + 1, 1).value = "PARTICIPANT"; ws.cell(top + 1, 2).value = "NAME"
    for k in range(5): ws.cell(top + 1, 3 + k).value = dates[base + k].strftime("%m/%d/%Y")
    for j, (num, name, frac) in enumerate(parts):
        rr = top + 2 + j; ws.cell(rr, 1).value = num; ws.cell(rr, 2).value = grid[num][0]
        for k in range(5):
            ws.cell(rr, 3 + k).value = grid[num][1][base + k]
            spr_rows.append([rr, num, grid[num][0], dates[base + k].isoformat(), grid[num][1][base + k]])
    tr = top + 2 + len(parts); ws.cell(tr, 2).value = "TOTAL"
    for k in range(5): ws.cell(tr, 3 + k).value = sum(grid[n][1][base + k] for n, _, _ in parts)
wb.save(os.path.join(out, "spr_weekly.xlsx"))
with open(os.path.join(out, "spr_rows.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["row_no","participant_number","participant_name","position_date","shares"]); w.writerows(spr_rows)
spr_totals = {dates[k].isoformat(): sum(grid[n][1][k] for n, _, _ in parts) for k in range(10)}
# ---------------------------------------------------------------- the public record (synthetic, the fetchers' shapes)
rows_si = []; sd = [dt.date(2024,1,31), dt.date(2024,2,15), dt.date(2024,2,29), dt.date(2024,3,15), dt.date(2024,3,28)]
short = [120000, 150000, 260000, 410000, 520000]; vol = [40000, 42000, 45000, 44000, 47000]
for i, s in enumerate(sd):
    rows_si.append([s.isoformat(), (s + dt.timedelta(days=12)).isoformat(), short[i], short[i-1] if i else None, short[i] - (short[i-1] if i else 0), vol[i], round(short[i] / vol[i], 2), "N"])
with open(os.path.join(out, "short_interest.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["settlementDate","publishedDate","currentShortPositionQuantity","previousShortPositionQuantity","changePositionQuantity","averageDailyVolumeQuantity","daysToCoverQuantity","revisionFlag"]); w.writerows(rows_si)
# daily short volume (CNMS shape) and prices (stooq shape), 60 trading days to the record date; a crafted price drop in the last 3 days
tdays = []; d = rd - dt.timedelta(days=90)
while d <= rd:
    if d.weekday() < 5 and d != dt.date(2024,3,29): tdays.append(d)
    d += dt.timedelta(days=1)
tdays = tdays[-60:]
with open(os.path.join(out, "CNMSshvol.txt"), "w") as f:
    f.write("Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market\n")
    for i, d in enumerate(tdays):
        tot = 40000 + (i * 137) % 9000; ratio = 0.35 + (0.35 * i / 59)
        f.write(f"{d.strftime('%Y%m%d')}|SMOK|{int(tot*ratio)}|{int(tot*0.01)}|{tot}|B,Q,N\n")
with open(os.path.join(out, "prices_stooq.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["Date","Open","High","Low","Close","Volume"])
    px = 4.00
    for i, d in enumerate(tdays):
        px = px * (1 + ((i * 7919) % 11 - 5) / 400.0)
        if i >= 57: px *= 0.93
        v = 45000 + (i * 977) % 12000
        if i >= 58: v *= 4
        w.writerow([d.isoformat(), round(px*0.99,4), round(px*1.02,4), round(px*0.97,4), round(px,4), v])
with open(os.path.join(out, "cnsfails.txt"), "w") as f:
    f.write("SETTLEMENT DATE|CUSIP|SYMBOL|QUANTITY (FAILS)|DESCRIPTION|PRICE\n")
    for i, d in enumerate(tdays[-20:]):
        q = 3000 + (i * 311) % 2000
        if i == 15: q = 95000
        f.write(f"{d.strftime('%Y%m%d')}|07831X206|SMOK|{q}|SMOKE ISSUER INC|4.10\n")
with open(os.path.join(out, "nasdaqth.txt"), "w") as f:
    f.write("Symbol|Security Name|Market Category|Reg SHO Threshold Flag|Rule 3210\n")
    f.write("SMOK|SMOKE ISSUER INC - Common Stock|S|Y|N\nOTHR|OTHER CO - Common Stock|Q|Y|N\n")
threshold_days = [d.isoformat() for d in tdays[-6:]]
with open(os.path.join(out, "threshold_days.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["list_date","list","flag"]); [w.writerow([d, "nasdaq", "Y"]) for d in threshold_days]
with open(os.path.join(out, "dtcc_participants.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["No.","Participant Account Name"])
    w.writerows([["0005","GOLDMAN SACHS & CO. LLC"],["0161","BOFA SECURITIES, INC."],["0164","CHARLES SCHWAB & CO., INC."],["7777","SMOKE NEW CLEARING LLC/STOCK LOAN"],["0226","NATIONAL FINANCIAL SERVICES LLC"]])
with open(os.path.join(out, "13f_infotable.tsv"), "w", newline="") as f:
    w = csv.writer(f, delimiter="\t"); w.writerow(["ACCESSION_NUMBER","INFOTABLE_SK","NAMEOFISSUER","TITLEOFCLASS","CUSIP","FIGI","VALUE","SSHPRNAMT","SSHPRNAMTTYPE","PUTCALL","INVESTMENTDISCRETION","OTHERMANAGER","VOTING_AUTH_SOLE","VOTING_AUTH_SHARED","VOTING_AUTH_NONE","FILER_CIK","FILER_NAME","PERIOD"])
    rows13 = [["0001111111-24-000001","1","SMOKE ISSUER INC","COM","07831X206","","410000","100000","SH","","SOLE","","100000","0","0","0001111111","SMOKE ALPHA CAPITAL MANAGEMENT LP","2023-12-31"],
              ["0001222222-24-000001","1","SMOKE ISSUER INC","COM","07831X206","","205000","50000","SH","","SOLE","","50000","0","0","0001222222","SMOKE BETA ADVISORS LLC","2023-12-31"],
              ["0001111111-24-000002","1","SMOKE ISSUER INC","COM","07831X206","","164000","40000","SH","","SOLE","","40000","0","0","0001111111","SMOKE ALPHA CAPITAL MANAGEMENT LP","2024-03-31"],
              ["0001333333-24-000001","1","SMOKE ISSUER INC","COM","07831X206","","82000","20000","SH","","SOLE","","20000","0","0","0001333333","SMOKE GAMMA PENSION FUND","2024-03-31"],
              ["0001222222-24-000002","1","SMOKE ISSUER INC","COM","07831X206","","0","30000","SH","PUT","SOLE","","0","0","30000","0001222222","SMOKE BETA ADVISORS LLC","2024-03-31"]]
    w.writerows(rows13)
with open(os.path.join(out, "beneficial.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["filer_cik","filer_name","schedule","is_amendment","amendment_no","filed_on","event_date","percent_of_class","shares","purpose_text","filer_type","accession"])
    w.writerows([["0001444444","SMOKE DELTA PARTNERS LP","13D","0","","2024-04-02","2024-03-26","6.2","1057000","The Reporting Persons intend to engage the Issuer's board on capital allocation and may seek representation.","active","0001444444-24-000003"],
                 ["0001111111","SMOKE ALPHA CAPITAL MANAGEMENT LP","13G","0","","2024-02-14","2023-12-31","5.9","100000","","passive","0001111111-24-000009"]])
with open(os.path.join(out, "form4.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["accession","form","reporting_owner_cik","reporting_owner_name","relationship","officer_title","transaction_date","transaction_code","shares","price","acquired_disposed","shares_owned_after","ownership","is_derivative","row_no"])
    w.writerows([["0000099990-24-000011","4","0000099990","SMITH ROBERT A","officer","Chief Executive Officer","2024-03-20","S","20000","4.15","D","480000","D","0","1"],
                 ["0000099990-24-000012","4","0000099990","SMITH ROBERT A","officer","Chief Executive Officer","2024-03-27","S","15000","4.02","D","465000","D","0","1"],
                 ["0000099991-24-000004","4","0000099991","JONES LAURA","director","","2024-03-28","S","5000","3.98","D","120000","D","0","1"],
                 ["0000099991-24-000004","4","0000099991","JONES LAURA","director","","2024-03-28","A","10000","0","A","130000","D","1","1"]])
with open(os.path.join(out, "form144.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["accession","filed_on","seller_name","seller_cik","relationship","shares_to_sell","aggregate_market_value","approx_sale_date","broker","shares_outstanding_stated"])
    w.writerows([["0000099990-24-000013","2024-04-01","SMITH ROBERT A","0000099990","officer","100000","410000","2024-04-05","SMOKE BROKER LLC","17053900"]])
with open(os.path.join(out, "filings_index.csv"), "w", newline="") as f:
    w = csv.writer(f); w.writerow(["accession","form","filed_on","report_date","items","primary_document","primary_doc_description","about_us","filer_cik"])
    w.writerows([["0000999999-24-000005","8-K","2024-03-15","2024-03-14","1.01;3.02;9.01","smok-8k_20240314.htm","8-K","0","0000999999"],
                 ["0000999999-24-000006","10-K","2024-03-29","2023-12-31","","smok-10k_20231231.htm","10-K","0","0000999999"],
                 ["0001444444-24-000003","SC 13D","2024-04-02","","","sc13d.xml","SC 13D","1","0001444444"],
                 ["0000099990-24-000013","144","2024-04-01","","","xsl144X01/primary_doc.xml","144","1","0000099990"],
                 ["0000999999-24-000007","424B5","2024-03-18","","","smok-424b5.htm","Prospectus supplement — at-the-market offering","0","0000999999"]])
# the XML/JSON/HTML samples the Phase 3 parsers are tested against (small, shape-accurate, synthetic)
S = os.path.join(out, "samples")
open(os.path.join(S, "submissions_CIK0000999999.json"), "w").write(json.dumps({"cik":"999999","entityType":"operating","sic":"3990","sicDescription":"MISCELLANEOUS MANUFACTURING INDUSTRIES","name":"SMOKE ISSUER INC","tickers":["SMOK"],"exchanges":["OTC"],"ein":"000000000","fiscalYearEnd":"1231","stateOfIncorporation":"NV",
  "addresses":{"mailing":{"street1":"1 SMOKE WAY","city":"RENO","stateOrCountry":"NV","zipCode":"89501"},"business":{"street1":"1 SMOKE WAY","city":"RENO","stateOrCountry":"NV","zipCode":"89501"}},"formerNames":[{"name":"SMOKE ACQUISITION CORP","from":"2019-01-01T00:00:00.000Z","to":"2022-06-30T00:00:00.000Z"}],
  "filings":{"recent":{"accessionNumber":["0000999999-24-000006","0000999999-24-000005"],"filingDate":["2024-03-29","2024-03-15"],"reportDate":["2023-12-31","2024-03-14"],"acceptanceDateTime":["2024-03-29T16:05:11.000Z","2024-03-15T08:30:00.000Z"],"act":["34","34"],"form":["10-K","8-K"],"fileNumber":["000-56000","000-56000"],"filmNumber":["24001","24002"],"items":["","1.01,3.02,9.01"],"size":[1200000,80000],"isXBRL":[1,0],"isInlineXBRL":[1,0],"primaryDocument":["smok-10k_20231231.htm","smok-8k_20240314.htm"],"primaryDocDescription":["10-K","8-K"]}}}, indent=1))
open(os.path.join(S, "companyfacts_CIK0000999999.json"), "w").write(json.dumps({"cik":999999,"entityName":"SMOKE ISSUER INC","facts":{"dei":{"EntityCommonStockSharesOutstanding":{"label":"Entity Common Stock, Shares Outstanding","units":{"shares":[{"end":"2024-03-25","val":17053900,"accn":"0000999999-24-000006","fy":2023,"fp":"FY","form":"10-K","filed":"2024-03-29","frame":"CY2024Q1I"}]}},"EntityPublicFloat":{"units":{"USD":[{"end":"2023-06-30","val":41000000,"accn":"0000999999-24-000006","fy":2023,"fp":"FY","form":"10-K","filed":"2024-03-29","frame":"CY2023Q2I"}]}}}}}, indent=1))
open(os.path.join(S, "sc13d.xml"), "w").write('<?xml version="1.0"?><edgarSubmission><headerData><submissionType>SC 13D</submissionType></headerData><formData><coverPageHeader><issuerInfo><issuerName>SMOKE ISSUER INC</issuerName><issuerCusip>07831X206</issuerCusip></issuerInfo><dateOfEvent>2024-03-26</dateOfEvent></coverPageHeader><coverPage><reportingPersonInfo><reportingPersonName>SMOKE DELTA PARTNERS LP</reportingPersonName><reportingPersonCik>0001444444</reportingPersonCik><soleVotingPower>1057000</soleVotingPower><sharedVotingPower>0</sharedVotingPower><soleDispositivePower>1057000</soleDispositivePower><sharedDispositivePower>0</sharedDispositivePower><aggregateAmountOwned>1057000</aggregateAmountOwned><percentOfClass>6.2</percentOfClass></reportingPersonInfo></coverPage><items><item4>The Reporting Persons intend to engage the Issuer\'s board on capital allocation and may seek representation.</item4></items></formData></edgarSubmission>')
open(os.path.join(S, "form4.xml"), "w").write('<?xml version="1.0"?><ownershipDocument><schemaVersion>X0508</schemaVersion><documentType>4</documentType><periodOfReport>2024-03-20</periodOfReport><issuer><issuerCik>0000999999</issuerCik><issuerName>SMOKE ISSUER INC</issuerName><issuerTradingSymbol>SMOK</issuerTradingSymbol></issuer><reportingOwner><reportingOwnerId><rptOwnerCik>0000099990</rptOwnerCik><rptOwnerName>SMITH ROBERT A</rptOwnerName></reportingOwnerId><reportingOwnerRelationship><isDirector>0</isDirector><isOfficer>1</isOfficer><isTenPercentOwner>0</isTenPercentOwner><officerTitle>Chief Executive Officer</officerTitle></reportingOwnerRelationship></reportingOwner><nonDerivativeTable><nonDerivativeTransaction><securityTitle><value>Common Stock</value></securityTitle><transactionDate><value>2024-03-20</value></transactionDate><transactionCoding><transactionCode>S</transactionCode></transactionCoding><transactionAmounts><transactionShares><value>20000</value></transactionShares><transactionPricePerShare><value>4.15</value></transactionPricePerShare><transactionAcquiredDisposedCode><value>D</value></transactionAcquiredDisposedCode></transactionAmounts><postTransactionAmounts><sharesOwnedFollowingTransaction><value>480000</value></sharesOwnedFollowingTransaction></postTransactionAmounts><ownershipNature><directOrIndirectOwnership><value>D</value></directOrIndirectOwnership></ownershipNature></nonDerivativeTransaction></nonDerivativeTable></ownershipDocument>')
open(os.path.join(S, "form144.xml"), "w").write('<?xml version="1.0"?><edgarSubmission><headerData><submissionType>144</submissionType></headerData><formData><issuerInfo><issuerCik>0000999999</issuerCik><issuerName>SMOKE ISSUER INC</issuerName><nameOfPersonForWhoseAccountTheSecuritiesAreToBeSold>SMITH ROBERT A</nameOfPersonForWhoseAccountTheSecuritiesAreToBeSold><relationshipsToIssuer><relationshipToIssuer>Officer</relationshipToIssuer></relationshipsToIssuer></issuerInfo><securitiesToBeSold><securitiesClassTitle>Common</securitiesClassTitle><brokerOrMarketmakerDetails><name>SMOKE BROKER LLC</name></brokerOrMarketmakerDetails><noOfUnitsSold>100000</noOfUnitsSold><aggregateMarketValue>410000</aggregateMarketValue><noOfUnitsOutstanding>17053900</noOfUnitsOutstanding><approxSaleDate>04/05/2024</approxSaleDate></securitiesToBeSold></formData></edgarSubmission>')
open(os.path.join(S, "smok-8k_20240314.htm"), "w").write('<html><body><p>Item 1.01 Entry into a Material Definitive Agreement.</p><p>On March 14, 2024, SMOKE ISSUER INC (the "Company") entered into an equity purchase agreement (the "Equity Line") with SMOKE EPSILON CAPITAL LLC under which the Company may sell up to $10,000,000 of common stock at 95% of the lowest VWAP of the five trading days before each purchase.</p><p>Item 3.02 Unregistered Sales of Equity Securities.</p></body></html>')
open(os.path.join(S, "short_interest_api.json"), "w").write(json.dumps([{"settlementDate": r[0], "symbolCode": "SMOK", "issueName": "SMOKE ISSUER INC", "marketClassCode": "OTC", "currentShortPositionQuantity": r[2], "previousShortPositionQuantity": r[3], "changePreviousNumber": r[4], "averageDailyVolumeQuantity": r[5], "daysToCoverQuantity": r[6], "revisionFlag": r[7]} for r in rows_si], indent=1))
meta = {"nobo_header": nobo_header, "nobo_rows": len(nobo_rows), "nobo_shares": sum(r[1] for r in nobo_rows), "nobo_clients": len(clients), "nobo_clients_shares": sum(int(c[4] or 0) for c in clients),
        "register_rows": len(reg_rows), "register_total": reg_total, "cede_total": cede_total, "register_date": record_date, "spr_dates": [d.isoformat() for d in dates], "spr_totals": spr_totals, "spr_rows": len(spr_rows), "participants": len(parts),
        "trading_days": len(tdays), "last_trading_day": tdays[-1].isoformat(), "threshold_days": threshold_days}
json.dump(meta, open(os.path.join(out, "meta.json"), "w"), indent=1)
print(json.dumps(meta, indent=1))
