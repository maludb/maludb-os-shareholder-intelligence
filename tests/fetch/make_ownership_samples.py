"""Slice 5's ownership samples, made deterministically (python3 -I; nothing real — SMOKE names, CIK 0000999999's CUSIP 07831X206):
  archives/<accession, 18 digits>/<file>     the raw XML of the ownership forms, by filing: the Form 4 (two sales, a 10b5-1 footnote, an option exercise),
                                              the 13D (two reporting persons, item 4), the Form 144, a 2023 13G in HTML (best-effort), an XML declaring an entity
  form13f/<window>_form13f.zip                the SEC's quarterly 13F data sets in their shape (SUBMISSION.tsv, COVERPAGE.tsv, INFOTABLE.tsv), two windows:
                                              2023-12-31 (Alpha 100,000; Beta 50,000) and 2024-03-31 (Alpha 40,000; Gamma 20,000; Beta's PUT 30,000), with other
                                              CUSIPs' rows that must be skipped and an amendment that restates
  form13f/listing.html                        the SEC's data-set page linking the zips
"""
import os, zipfile, io
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'fixtures', 'samples')
A = os.path.join(root, 'archives')
def put(acc, name, body):
    d = os.path.join(A, acc.replace('-', ''))
    os.makedirs(d, exist_ok=True)
    open(os.path.join(d, name), 'w').write(body)
v = lambda x: '<value>%s</value>' % x
def nd(date, code, shares, price, ad, after, foot=''):
    return ('<nonDerivativeTransaction><securityTitle>' + v('Common Stock') + '</securityTitle><transactionDate>' + v(date) + '</transactionDate>'
            '<transactionCoding><transactionFormType>4</transactionFormType><transactionCode>' + code + '</transactionCode><equitySwapInvolved>0</equitySwapInvolved>' + foot + '</transactionCoding>'
            '<transactionAmounts><transactionShares>' + v(shares) + '</transactionShares><transactionPricePerShare>' + v(price) + '</transactionPricePerShare>'
            '<transactionAcquiredDisposedCode>' + v(ad) + '</transactionAcquiredDisposedCode></transactionAmounts><postTransactionAmounts><sharesOwnedFollowingTransaction>' + v(after) + '</sharesOwnedFollowingTransaction></postTransactionAmounts>'
            '<ownershipNature><directOrIndirectOwnership>' + v('D') + '</directOrIndirectOwnership></ownershipNature></nonDerivativeTransaction>')
form4 = ('<?xml version="1.0"?><ownershipDocument xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><schemaVersion>X0508</schemaVersion><documentType>4</documentType><periodOfReport>2024-03-20</periodOfReport>'
         '<aff10b5One>1</aff10b5One><issuer><issuerCik>0000999999</issuerCik><issuerName>SMOKE ISSUER INC</issuerName><issuerTradingSymbol>SMOK</issuerTradingSymbol></issuer>'
         '<reportingOwner><reportingOwnerId><rptOwnerCik>0000099990</rptOwnerCik><rptOwnerName>SMITH ROBERT A</rptOwnerName></reportingOwnerId>'
         '<reportingOwnerRelationship><isDirector>1</isDirector><isOfficer>1</isOfficer><isTenPercentOwner>0</isTenPercentOwner><officerTitle>Chief Executive Officer</officerTitle></reportingOwnerRelationship></reportingOwner>'
         '<nonDerivativeTable>' + nd('2024-03-20', 'S', 20000, '4.15', 'D', 480000, '<footnoteId id="F1"/>') + nd('2024-03-21', 'S', 15000, '4.05', 'D', 465000, '<footnoteId id="F1"/>')
         + nd('2024-03-22', 'M', 10000, '1.00', 'A', 475000) + '</nonDerivativeTable>'
         '<derivativeTable><derivativeTransaction><securityTitle>' + v('Stock Option (right to buy)') + '</securityTitle><conversionOrExercisePrice>' + v('1.00') + '</conversionOrExercisePrice>'
         '<transactionDate>' + v('2024-03-22') + '</transactionDate><transactionCoding><transactionFormType>4</transactionFormType><transactionCode>M</transactionCode></transactionCoding>'
         '<transactionAmounts><transactionShares>' + v(10000) + '</transactionShares><transactionPricePerShare>' + v(0) + '</transactionPricePerShare><transactionAcquiredDisposedCode>' + v('D') + '</transactionAcquiredDisposedCode></transactionAmounts>'
         '<expirationDate>' + v('2029-01-01') + '</expirationDate><underlyingSecurity><underlyingSecurityTitle>' + v('Common Stock') + '</underlyingSecurityTitle><underlyingSecurityShares>' + v(10000) + '</underlyingSecurityShares></underlyingSecurity>'
         '<postTransactionAmounts><sharesOwnedFollowingTransaction>' + v(90000) + '</sharesOwnedFollowingTransaction></postTransactionAmounts><ownershipNature><directOrIndirectOwnership>' + v('D') + '</directOrIndirectOwnership></ownershipNature></derivativeTransaction></derivativeTable>'
         '<footnotes><footnote id="F1">The sales were effected under a Rule 10b5-1 trading plan adopted on December 1, 2023.</footnote></footnotes></ownershipDocument>')
put('0001555555-24-000001', 'form4.xml', form4)
sc13d = ('<?xml version="1.0"?><edgarSubmission xmlns="http://www.sec.gov/edgar/schedule13D"><headerData><submissionType>SCHEDULE 13D</submissionType></headerData><formData><coverPageHeader><securitiesClassTitle>Common Stock</securitiesClassTitle>'
         '<dateOfEvent>03/26/2024</dateOfEvent><issuerInfo><issuerCIK>0000999999</issuerCIK><issuerCUSIP>07831X206</issuerCUSIP><issuerName>SMOKE ISSUER INC</issuerName></issuerInfo></coverPageHeader>'
         '<reportingPersons><reportingPersonInfo><reportingPersonCIK>0001444444</reportingPersonCIK><reportingPersonName>SMOKE DELTA PARTNERS LP</reportingPersonName><soleVotingPower>1057000</soleVotingPower><sharedVotingPower>0</sharedVotingPower>'
         '<soleDispositivePower>1057000</soleDispositivePower><sharedDispositivePower>0</sharedDispositivePower><aggregateAmountOwned>1057000</aggregateAmountOwned><percentOfClass>6.2</percentOfClass><typeOfReportingPerson>PN</typeOfReportingPerson></reportingPersonInfo>'
         '<reportingPersonInfo><reportingPersonCIK>0001444445</reportingPersonCIK><reportingPersonName>SMOKE DELTA GP LLC</reportingPersonName><aggregateAmountOwned>1057000</aggregateAmountOwned><percentOfClass>6.2</percentOfClass></reportingPersonInfo></reportingPersons>'
         '<items1To7><item4><transactionPurpose>The Reporting Persons intend to engage the Issuer\'s board on capital allocation and may seek representation.</transactionPurpose></item4></items1To7></formData></edgarSubmission>')
put('0001444444-24-000003', 'primary_doc.xml', sc13d)
f144 = ('<?xml version="1.0"?><edgarSubmission><headerData><submissionType>144</submissionType><filerInfo><filer><filerCredentials><cik>0000099990</cik></filerCredentials></filer></filerInfo></headerData><formData><issuerInfo><issuerCik>0000999999</issuerCik><issuerName>SMOKE ISSUER INC</issuerName>'
        '<nameOfPersonForWhoseAccountTheSecuritiesAreToBeSold>SMITH ROBERT A</nameOfPersonForWhoseAccountTheSecuritiesAreToBeSold><relationshipsToIssuer><relationshipToIssuer>Officer</relationshipToIssuer></relationshipsToIssuer></issuerInfo>'
        '<securitiesInformation><securitiesClassTitle>Common</securitiesClassTitle><brokerOrMarketmakerDetails><name>SMOKE BROKER LLC</name></brokerOrMarketmakerDetails><noOfUnitsSold>100000</noOfUnitsSold>'
        '<aggregateMarketValue>410000</aggregateMarketValue><noOfUnitsOutstanding>17053900</noOfUnitsOutstanding><approxSaleDate>04/05/2024</approxSaleDate></securitiesInformation></formData></edgarSubmission>')
put('0000099990-24-000013', 'primary_doc.xml', f144)
put('0001888888-23-000004', 'sc13g.htm', '<html><body><p>SCHEDULE 13G</p><p>(Amendment No. 1)</p><p>SMOKE ISSUER INC (Name of Issuer)</p><p>Common Stock (Title of Class of Securities)</p><p>07831X206 (CUSIP Number)</p>'
    '<p>December 31, 2022 (Date of Event Which Requires Filing of this Statement)</p><table><tr><td>1</td><td>NAMES OF REPORTING PERSONS</td><td>SMOKE EPSILON INDEX FUNDS TRUST</td></tr>'
    '<tr><td>9</td><td>AGGREGATE AMOUNT BENEFICIALLY OWNED BY EACH REPORTING PERSON</td><td>950,000</td></tr><tr><td>11</td><td>PERCENT OF CLASS REPRESENTED BY AMOUNT IN ROW (9)</td><td>5.8%</td></tr></table></body></html>')
put('0001444444-24-000099', 'primary_doc.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><edgarSubmission><headerData><submissionType>SCHEDULE 13D/A</submissionType></headerData><formData><reportingPersonName>&e;</reportingPersonName></formData></edgarSubmission>')
# the 13F data sets
F = os.path.join(root, 'form13f')
os.makedirs(F, exist_ok=True)
def tsv(rows):
    return '\n'.join('\t'.join(str(c) for c in r) for r in rows) + '\n'
def zipset(name, subs, covers, infos):
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('SUBMISSION.tsv', tsv([['ACCESSION_NUMBER', 'FILING_DATE', 'SUBMISSIONTYPE', 'CIK', 'PERIODOFREPORT']] + subs))
        z.writestr('COVERPAGE.tsv', tsv([['ACCESSION_NUMBER', 'REPORTCALENDARORQUARTER', 'ISAMENDMENT', 'AMENDMENTNO', 'AMENDMENTTYPE', 'CONFDENIEDEXPIRED', 'DATEDENIEDEXPIRED', 'DATEREPORTED', 'REASONFORNONCONFIDENTIALITY', 'FILINGMANAGER_NAME']] + covers))
        z.writestr('INFOTABLE.tsv', tsv([['ACCESSION_NUMBER', 'INFOTABLE_SK', 'NAMEOFISSUER', 'TITLEOFCLASS', 'CUSIP', 'FIGI', 'VALUE', 'SSHPRNAMT', 'SSHPRNAMTTYPE', 'PUTCALL', 'INVESTMENTDISCRETION', 'OTHERMANAGER', 'VOTING_AUTH_SOLE', 'VOTING_AUTH_SHARED', 'VOTING_AUTH_NONE']] + infos))
        z.writestr('readme.htm', '<html>the SEC readme</html>')
    open(os.path.join(F, name), 'wb').write(b.getvalue())
info = lambda acc, sk, cusip, val, sh, pc='', disc='SOLE', vs=None: [acc, sk, 'SMOKE ISSUER INC' if cusip == '07831X206' else 'OTHER CORP', 'COM', cusip, '', val, sh, 'SH', pc, disc, '', sh if vs is None else vs, 0, 0]
zipset('01dec2023-29feb2024_form13f.zip',
       [['0001111111-24-000001', '14-FEB-2024', '13F-HR', '0001111111', '31-DEC-2023'], ['0001222222-24-000001', '13-FEB-2024', '13F-HR', '0001222222', '31-DEC-2023'], ['0009999999-24-000001', '12-FEB-2024', '13F-HR', '0009999999', '31-DEC-2023']],
       [['0001111111-24-000001', '31-DEC-2023', 'N', '', '', '', '', '14-FEB-2024', '', 'SMOKE ALPHA CAPITAL MANAGEMENT LP'], ['0001222222-24-000001', '31-DEC-2023', 'N', '', '', '', '', '13-FEB-2024', '', 'SMOKE BETA ADVISORS LLC'],
        ['0009999999-24-000001', '31-DEC-2023', 'N', '', '', '', '', '12-FEB-2024', '', 'SMOKE UNRELATED BANK']],
       [info('0001111111-24-000001', 1, '07831X206', 300000, 60000), info('0001111111-24-000001', 2, '07831X206', 200000, 40000, '', 'DEFINED', 0), info('0001111111-24-000001', 3, '999999999', 1000, 50),
        info('0001222222-24-000001', 1, '07831X206', 205000, 50000), info('0009999999-24-000001', 1, '111111111', 9000, 900)])
zipset('01mar2024-31may2024_form13f.zip',
       [['0001111111-24-000002', '14-MAY-2024', '13F-HR', '0001111111', '31-MAR-2024'], ['0001333333-24-000001', '10-MAY-2024', '13F-HR', '0001333333', '31-MAR-2024'], ['0001222222-24-000002', '15-MAY-2024', '13F-HR', '0001222222', '31-MAR-2024'],
        ['0001333333-24-000002', '20-MAY-2024', '13F-HR/A', '0001333333', '31-MAR-2024']],
       [['0001111111-24-000002', '31-MAR-2024', 'N', '', '', '', '', '14-MAY-2024', '', 'SMOKE ALPHA CAPITAL MANAGEMENT LP'], ['0001333333-24-000001', '31-MAR-2024', 'N', '', '', '', '', '10-MAY-2024', '', 'SMOKE GAMMA PENSION FUND'],
        ['0001222222-24-000002', '31-MAR-2024', 'N', '', '', '', '', '15-MAY-2024', '', 'SMOKE BETA ADVISORS LLC'], ['0001333333-24-000002', '31-MAR-2024', 'Y', '1', 'RESTATEMENT', '', '', '20-MAY-2024', '', 'SMOKE GAMMA PENSION FUND']],
       [info('0001111111-24-000002', 1, '07831X206', 164000, 40000), info('0001333333-24-000001', 1, '07831X206', 70000, 17000), info('0001333333-24-000002', 1, '07831X206', 82000, 20000),
        info('0001222222-24-000002', 1, '07831X206', 0, 30000, 'PUT'), info('0001222222-24-000002', 2, '222222222', 5000, 500)])
open(os.path.join(F, 'listing.html'), 'w').write('<html><body><h1>Form 13F Data Sets</h1><ul>'
    '<li><a href="/files/structureddata/data/form-13f-data-sets/01mar2024-31may2024_form13f.zip">March 2024 – May 2024</a></li>'
    '<li><a href="/files/structureddata/data/form-13f-data-sets/01dec2023-29feb2024_form13f.zip">December 2023 – February 2024</a></li>'
    '<li><a href="/files/structureddata/data/form-13f-data-sets/readme.pdf">The readme</a></li></ul></body></html>')

# A Schedule 13G in the 2024 XML layout (coverPageHeaderReportingPersonDetails; the person carries no CIK — the filer's is in the header)
put('000177777724000005', 'primary_doc.xml', """<?xml version="1.0" encoding="UTF-8"?>
<edgarSubmission xmlns="http://www.sec.gov/edgar/schedule13g" xmlns:com="http://www.sec.gov/edgar/common">
  <headerData><submissionType>SCHEDULE 13G</submissionType><filerInfo><filer><filerCredentials><cik>0001777777</cik><ccc>XXXXXXXX</ccc></filerCredentials></filer></filerInfo></headerData>
  <formData>
    <coverPageHeader><securitiesClassTitle>Common Stock</securitiesClassTitle><eventDateRequiresFilingThisStatement>04/30/2024</eventDateRequiresFilingThisStatement>
      <issuerInfo><issuerCik>0000999999</issuerCik><issuerName>SMOKE ISSUER INC</issuerName><issuerCusip>07831X206</issuerCusip></issuerInfo>
      <designateRulesPursuantThisScheduleFiled><designateRulePursuantThisScheduleFiled>Rule 13d-1(c)</designateRulePursuantThisScheduleFiled></designateRulesPursuantThisScheduleFiled></coverPageHeader>
    <coverPageHeaderReportingPersonDetails><reportingPersonName>SMOKE ZETA GROWTH FUND LP</reportingPersonName><citizenshipOrOrganization>DE</citizenshipOrOrganization>
      <reportingPersonBeneficiallyOwnedNumberOfShares><soleVotingPower>0.00</soleVotingPower><sharedVotingPower>870000.00</sharedVotingPower><soleDispositivePower>0.00</soleDispositivePower><sharedDispositivePower>870000.00</sharedDispositivePower></reportingPersonBeneficiallyOwnedNumberOfShares>
      <reportingPersonBeneficiallyOwnedAggregateNumberOfShares>870000.00</reportingPersonBeneficiallyOwnedAggregateNumberOfShares><classPercent>5.1</classPercent><typeOfReportingPerson>PN</typeOfReportingPerson></coverPageHeaderReportingPersonDetails>
    <coverPageHeaderReportingPersonDetails><reportingPersonName>SMOKE ZETA GP LLC</reportingPersonName><classPercent>5.1</classPercent></coverPageHeaderReportingPersonDetails>
    <items><item4><amountBeneficiallyOwned>870000</amountBeneficiallyOwned></item4></items>
  </formData>
</edgarSubmission>
""")
# A Form 4 the ISSUER filed as a ten-percent owner of ANOTHER company (EDGAR lists it in the issuer's index too): set aside, nothing kept
put('000099999924000020', 'form4.xml', """<?xml version="1.0"?>
<ownershipDocument><schemaVersion>X0508</schemaVersion><documentType>4</documentType><periodOfReport>2024-04-10</periodOfReport>
  <issuer><issuerCik>0000123456</issuerCik><issuerName>SMOKE OTHER HOLDINGS INC</issuerName><issuerTradingSymbol>SOTH</issuerTradingSymbol></issuer>
  <reportingOwner><reportingOwnerId><rptOwnerCik>0000999999</rptOwnerCik><rptOwnerName>SMOKE ISSUER INC</rptOwnerName></reportingOwnerId><reportingOwnerRelationship><isTenPercentOwner>1</isTenPercentOwner></reportingOwnerRelationship></reportingOwner>
  <nonDerivativeTable><nonDerivativeTransaction><securityTitle><value>Common Stock</value></securityTitle><transactionDate><value>2024-04-10</value></transactionDate>
    <transactionCoding><transactionFormType>4</transactionFormType><transactionCode>P</transactionCode></transactionCoding>
    <transactionAmounts><transactionShares><value>500000</value></transactionShares><transactionPricePerShare><value>2.00</value></transactionPricePerShare><transactionAcquiredDisposedCode><value>A</value></transactionAcquiredDisposedCode></transactionAmounts>
    <postTransactionAmounts><sharesOwnedFollowingTransaction><value>5000000</value></sharesOwnedFollowingTransaction></postTransactionAmounts><ownershipNature><directOrIndirectOwnership><value>D</value></directOrIndirectOwnership></ownershipNature></nonDerivativeTransaction></nonDerivativeTable>
</ownershipDocument>
""")
print('wrote the ownership samples')
