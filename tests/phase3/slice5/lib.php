<?php
/**
 * Helpers for slice 5's proofs (docs/build-specs/ownership-forms.md "Proof"): slice 4's library (the SMOKE issuer, a pull by the worker), and the
 * ownership filings the SMOKE index would list — EDGAR lists a schedule 13D/G, a Form 144 under the subject company — added to the index the
 * way slice 4's pull adds a filing, so slice 4's samples stay as its proofs know them.
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** The SMOKE issuer, its index pulled, the ownership filings added; answers smoke_issuer(). */
function seed_ownership(): array
{
    $sm = smoke_issuer();
    edgar_contact(true);
    pull('edgar_submissions');
    $add = static function (string $acc, string $form, string $filed, string $doc, string $cik = '0000999999') use ($sm): void {
        q('INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, primary_document, primary_doc_description) VALUES (:i, :c, :a, :f, :d, :doc, :f) ON CONFLICT DO NOTHING',
          ['i' => $sm['issuer'], 'c' => $cik, 'a' => $acc, 'f' => $form, 'd' => $filed, 'doc' => $doc]);
    };
    $add('0001444444-24-000003', 'SCHEDULE 13D', '2024-04-02', 'xslSCHEDULE_13D_X01/primary_doc.xml');
    $add('0001888888-23-000004', 'SC 13G/A', '2023-02-10', 'sc13g.htm');
    $add('0000099990-24-000013', '144', '2024-04-01', 'xsl144X01/primary_doc.xml');
    $add('0001444444-24-000099', 'SCHEDULE 13D/A', '2024-04-15', 'xslSCHEDULE_13D_X01/primary_doc.xml');
    $add('0001777777-24-000005', 'SCHEDULE 13G', '2024-05-06', 'xslSCHEDULE_13G_X01/primary_doc.xml');      // the 2024 13G layout
    $add('0000999999-24-000020', '4', '2024-04-11', 'xslF345X05/form4.xml');                                  // the issuer's own Form 4 about another company
    return $sm;
}
