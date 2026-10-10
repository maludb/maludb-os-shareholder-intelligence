"""Slice 4's EDGAR samples, made deterministically (run with python3 -I; nothing real in them — the SMOKE issuer, CIK 0000999999):
  submissions_CIK0000999999.json               the entity (slice 1's, unchanged) and a richer `filings.recent` + `files[]` naming an older page
  CIK0000999999-submissions-001.json           the older page (2023)
  companyfacts_CIK0000999999.json              the cover facts: three outstanding counts (one restated by an amendment) and the public float
  efts/<slug>.json                             the full-text search's answers by term (EDGAR's shape: hits.hits[]._id "<adsh>:<file>", _source)
  archives/<file>                              the documents the filings name (the 8-K, the 10-K with an inline-XBRL header and a table, the S-3, the 424B5,
                                               the Form 4's XML, another filer's 13D naming SMOKE, the 8-K's exhibit with the equity purchase agreement)
"""
import json, os
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'fixtures', 'samples')
os.makedirs(os.path.join(root, 'efts'), exist_ok=True)
os.makedirs(os.path.join(root, 'archives'), exist_ok=True)
sub = json.load(open(os.path.join(root, 'submissions_CIK0000999999.json')))
recent = [  # accession, filed, report, accepted, form, items, size, xbrl, ixbrl, doc, descr
    ('0000999999-24-000009', '2024-05-15', '2024-03-31', '2024-05-15T16:01:00.000Z', '10-Q', '', 600000, 1, 1, 'smok-10q_20240331.htm', '10-Q'),
    ('0000999999-24-000007', '2024-04-05', '', '2024-04-05T08:00:00.000Z', '424B5', '', 90000, 0, 0, 'smok-424b5.htm', '424B5'),
    ('0001555555-24-000001', '2024-04-03', '2024-04-01', '2024-04-03T18:00:00.000Z', '4', '', 5000, 0, 0, 'form4.xml', 'FORM 4'),
    ('0000999999-24-000006', '2024-03-29', '2023-12-31', '2024-03-29T16:05:11.000Z', '10-K', '', 1200000, 1, 1, 'smok-10k_20231231.htm', '10-K'),
    ('0000999999-24-000005', '2024-03-15', '2024-03-14', '2024-03-15T08:30:00.000Z', '8-K', '1.01,3.02,9.01', 80000, 0, 0, 'smok-8k_20240314.htm', '8-K'),
    ('0000999999-24-000004', '2024-02-20', '', '2024-02-20T17:00:00.000Z', 'S-3', '', 150000, 0, 0, 'smok-s3.htm', 'S-3'),
    ('0000999999-24-000003', '2024-02-01', '', '2024-02-01T09:00:00.000Z', 'CORRESP', '', 3000, 0, 0, 'filename1.htm', 'CORRESP'),
]
keys = ['accessionNumber', 'filingDate', 'reportDate', 'acceptanceDateTime', 'form', 'items', 'size', 'isXBRL', 'isInlineXBRL', 'primaryDocument', 'primaryDocDescription']
def cols(rows):
    d = {k: [] for k in keys + ['act', 'fileNumber', 'filmNumber']}
    for r in rows:
        for k, v in zip(keys, r): d[k].append(v)
        d['act'].append('34'); d['fileNumber'].append('000-56000'); d['filmNumber'].append('24' + r[0][-3:])
    return d
sub['filings'] = {'recent': cols(recent), 'files': [{'name': 'CIK0000999999-submissions-001.json', 'filingCount': 2, 'filingFrom': '2023-06-01', 'filingTo': '2023-11-14'}]}
json.dump(sub, open(os.path.join(root, 'submissions_CIK0000999999.json'), 'w'), indent=1)
older = cols([('0000999999-23-000012', '2023-11-14', '2023-09-30', '2023-11-14T16:00:00.000Z', '10-Q', '', 500000, 1, 1, 'smok-10q_20230930.htm', '10-Q'),
              ('0000999999-23-000008', '2023-06-01', '2023-05-30', '2023-06-01T16:00:00.000Z', '8-K', '5.02', 20000, 0, 0, 'smok-8k_20230530.htm', '8-K')])
json.dump(older, open(os.path.join(root, 'CIK0000999999-submissions-001.json'), 'w'), indent=1)
facts = {'cik': 999999, 'entityName': 'SMOKE ISSUER INC', 'facts': {'dei': {
    'EntityCommonStockSharesOutstanding': {'label': 'Entity Common Stock, Shares Outstanding', 'units': {'shares': [
        {'end': '2023-11-10', 'val': 16500000, 'accn': '0000999999-23-000012', 'fy': 2023, 'fp': 'Q3', 'form': '10-Q', 'filed': '2023-11-14', 'frame': 'CY2023Q4I'},
        {'end': '2024-03-25', 'val': 17000000, 'accn': '0000999999-24-000006', 'fy': 2023, 'fp': 'FY', 'form': '10-K', 'filed': '2024-03-29'},
        {'end': '2024-03-25', 'val': 17053900, 'accn': '0000999999-24-000010', 'fy': 2023, 'fp': 'FY', 'form': '10-K/A', 'filed': '2024-04-10', 'frame': 'CY2024Q1I'},
        {'end': '2024-05-10', 'val': 17600000, 'accn': '0000999999-24-000009', 'fy': 2024, 'fp': 'Q1', 'form': '10-Q', 'filed': '2024-05-15', 'frame': 'CY2024Q2I'}]}},
    'EntityPublicFloat': {'label': 'Entity Public Float', 'units': {'USD': [
        {'end': '2023-06-30', 'val': 41000000, 'accn': '0000999999-24-000006', 'fy': 2023, 'fp': 'FY', 'form': '10-K', 'filed': '2024-03-29', 'frame': 'CY2023Q2I'}]}}}}}
json.dump(facts, open(os.path.join(root, 'companyfacts_CIK0000999999.json'), 'w'), indent=1)
def hit(adsh, file, form, date, ciks, names, items=()):
    return {'_index': 'edgar_file', '_id': adsh + ':' + file, '_score': 3.1, '_source': {'ciks': ciks, 'display_names': names, 'root_forms': [form], 'form': form, 'file_date': date,
            'adsh': adsh, 'file_type': form, 'file_description': form, 'items': list(items), 'period_ending': date}}
def answer(hits):
    return {'took': 3, 'timed_out': False, 'hits': {'total': {'value': len(hits), 'relation': 'eq'}, 'hits': hits}}
smoke = ['SMOKE ISSUER INC  (SMOK)  (CIK 0000999999)']
json.dump(answer([hit('0000999999-24-000005', 'smok-ex10_1.htm', '8-K', '2024-03-15', ['0000999999'], smoke, ['1.01', '3.02'])]), open(os.path.join(root, 'efts', 'equity-purchase-agreement.json'), 'w'))
json.dump(answer([hit('0000999999-24-000004', 'smok-s3.htm', 'S-3', '2024-02-20', ['0000999999'], smoke), hit('0000999999-24-000007', 'smok-424b5.htm', '424B5', '2024-04-05', ['0000999999'], smoke)]),
          open(os.path.join(root, 'efts', 'at-the-market.json'), 'w'))
json.dump(answer([hit('0000999999-24-000005', 'smok-ex10_1.htm', '8-K', '2024-03-15', ['0000999999'], smoke, ['1.01', '3.02'])]), open(os.path.join(root, 'efts', 'equity-line.json'), 'w'))
json.dump(answer([hit('0001444444-24-000003', 'sc13d.htm', 'SC 13D', '2024-04-02', ['0001444444', '0000999999'], ['SMOKE ACTIVIST FUND LP  (CIK 0001444444)', 'SMOKE ISSUER INC  (SMOK)  (CIK 0000999999)']),
                  hit('0001777777-24-000001', 'complaint.htm', '8-K', '2024-04-20', ['0001777777'], ['SMOKE PLAINTIFF CORP  (CIK 0001777777)'])]),
          open(os.path.join(root, 'efts', 'about-smoke-issuer.json'), 'w'))
doc = lambda body: '<html><head><title>t</title><style>p{color:red}</style><script>var x=1;</script></head><body>' + body + '</body></html>'
A = os.path.join(root, 'archives')
open(os.path.join(A, 'smok-10k_20231231.htm'), 'w').write(doc('<div style="display:none"><ix:header><ix:hidden>dei:EntityCommonStockSharesOutstanding 17000000</ix:hidden></ix:header></div>'
    '<p>UNITED STATES SECURITIES AND EXCHANGE COMMISSION</p><p>FORM 10-K</p><p>SMOKE ISSUER INC</p><p>As of March 25, 2024, the registrant had 17,000,000 shares of common stock outstanding.</p>'
    '<table><tr><td>Revenue</td><td>$1,200</td></tr><tr><td>Net loss</td><td>$(3,400)</td></tr></table>'
    '<p>Item 1A. Risk Factors. Sales of our common stock under the Equity Line may cause substantial dilution.</p>'))
open(os.path.join(A, 'smok-s3.htm'), 'w').write(doc('<p>FORM S-3 REGISTRATION STATEMENT</p><p>SMOKE ISSUER INC may offer and sell up to $25,000,000 of common stock from time to time in "at-the-market" offerings through SMOKE BROKERAGE LLC as sales agent.</p>'))
open(os.path.join(A, 'smok-424b5.htm'), 'w').write(doc('<p>PROSPECTUS SUPPLEMENT</p><p>We have entered into an at-the-market sales agreement with SMOKE BROKERAGE LLC for up to $25,000,000.</p>'))
open(os.path.join(A, 'smok-ex10_1.htm'), 'w').write(doc('<p>EXHIBIT 10.1 — EQUITY PURCHASE AGREEMENT</p><p>This equity purchase agreement is made between SMOKE ISSUER INC and SMOKE EPSILON CAPITAL LLC. The purchase price shall be 95% of the lowest daily VWAP during the five trading days before each put.</p>'))
open(os.path.join(A, 'smok-10q_20240331.htm'), 'w').write(doc('<p>FORM 10-Q</p><p>As of May 10, 2024 there were 17,600,000 shares outstanding.</p>'))
open(os.path.join(A, 'sc13d.htm'), 'w').write(doc('<p>SCHEDULE 13D</p><p>SMOKE ACTIVIST FUND LP beneficially owns 1,800,000 shares (10.6%) of SMOKE ISSUER INC and intends to seek board representation.</p>'))
open(os.path.join(A, 'complaint.htm'), 'w').write(doc('<p>SMOKE PLAINTIFF CORP v. SMOKE ISSUER INC — a complaint filed in the District of Nevada.</p>'))
import shutil
shutil.copy(os.path.join(root, 'smok-8k_20240314.htm'), os.path.join(A, 'smok-8k_20240314.htm'))
shutil.copy(os.path.join(root, 'form4.xml'), os.path.join(A, 'form4.xml'))
open(os.path.join(A, 'evil.xml'), 'w').write('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>')
print('wrote the EDGAR samples')
