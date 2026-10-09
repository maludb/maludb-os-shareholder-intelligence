"""The fetchers' samples that are files, made deterministically (run with mcp/venv/bin/python -I; nothing real in them):
  tests/fixtures/samples/dtc_alpha.xlsx                  — DTCC's participant listing in the layout of 2026-09-30 (title rows, the header, name + number)
  tests/fixtures/samples/company_tickers_exchange.json   — the SEC's ticker file in its shape (fields + data), SMOKE companies only
"""
import json, os, sys
import openpyxl

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'fixtures', 'samples')
wb = openpyxl.Workbook()
ws = wb.active
ws.title = 'DTC Participant in Alphabetical'
ws.append(['Run Time:9/30/2026 2:36:05 PM', None])
ws.append(['DTC Participant Report (Alphabetical Sort)', None])
ws.append([None, None])
ws.append(['Participant Account Name', 'No'])
for name, number in [('CHARLES SCHWAB & CO., INC. (A SMOKE RENAME)', '0164'), ('GOLDMAN SACHS & CO. LLC', '0005'), ('NATIONAL FINANCIAL SERVICES LLC', '0226'),
                     ('SMOKE NEW CLEARING LLC/STOCK LOAN', '7777'), ('SMOKE PRIME SECURITIES LLC', '7778')]:
    ws.append([name.ljust(70), number])
ws.append([None, None])
wb.save(os.path.join(root, 'dtc_alpha.xlsx'))

tickers = {'fields': ['cik', 'name', 'ticker', 'exchange'], 'data': [
    [999999, 'SMOKE ISSUER INC', 'SMOK', 'Nasdaq'],
    [999998, 'SMOKE OTC HOLDINGS CORP', 'SMOT', 'OTC'],
    [999997, 'SMOKE TWO CLASS INC', 'SMTA', 'NYSE'],
    [999997, 'SMOKE TWO CLASS INC', 'SMTB', 'NYSE'],
]}
with open(os.path.join(root, 'company_tickers_exchange.json'), 'w') as f:
    json.dump(tickers, f)
print('wrote dtc_alpha.xlsx and company_tickers_exchange.json')
