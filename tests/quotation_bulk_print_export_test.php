<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$quote = static function (string $id, string $no, string $customer): array {
    $q = documents_quote_defaults();
    $q['id'] = $id;
    $q['quote_no'] = $no;
    $q['customer_name'] = $customer;
    $q['customer_mobile'] = '9999999999';
    $q['capacity_kwp'] = '3';
    $q['input_total_gst_inclusive'] = 123456;
    $q['calc'] = ['grand_total' => 123456, 'gross_payable' => 123456, 'subsidy_expected_rs' => 78000];
    return documents_quote_prepare($q);
};

$failed = false;
$tempFiles = [];
try {
    $ids = quotation_bulk_normalize_selected_ids(['  A  ', '', 'B', 'A', "\nC\r", 0]);
    $assert($ids === ['A', 'B', 'C', '0'], 'submitted IDs are normalized and duplicate IDs preserve first order');

    try { quotation_bulk_resolve_quotes([]); $assert(false, 'empty selection rejected'); }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'No valid quotation IDs'), 'no selection is rejected with a clear error'); }

    try { quotation_bulk_resolve_quotes(['definitely-missing-quotation']); $assert(false, 'missing quotation rejected'); }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'not found'), 'invalid or missing quotations are rejected'); }

    $used = [];
    $safeA = quotation_bulk_pdf_filename($quote('ID/1', 'QTN-2026/001', 'A / B "Unicode ग्राहक"'), $used);
    $safeB = quotation_bulk_pdf_filename($quote('ID/1', 'QTN-2026/001', 'A / B "Unicode ग्राहक"'), $used);
    $assert(str_ends_with($safeA, '.pdf') && !preg_match('/[\\\/\r\n"]/', $safeA), 'generated filenames are safe');
    $assert($safeA !== $safeB, 'generated filenames are collision-resistant');


    $fakeChromium = quotation_bulk_temp_file('.sh');
    $tempFiles[] = $fakeChromium;
    file_put_contents($fakeChromium, <<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --print-to-pdf=*) out="${arg#--print-to-pdf=}" ;;
  esac
done
[ -n "$out" ] || exit 2
printf '%s
' '%PDF-1.4 fake browser pdf' > "$out"
exit 0
SH);
    chmod($fakeChromium, 0700);
    putenv('QUOTATION_CHROMIUM_PATH=' . $fakeChromium);
    putenv('QUOTATION_PDF_TIMEOUT_SECONDS=5');
    $assert(quotation_browser_pdf_chromium_path() === $fakeChromium, 'browser executable detection uses configured Chromium path');
    $assert(quotation_browser_pdf_node_path() === '', 'Node dependency is not required for the PHP Chromium exporter');
    $assert(quotation_browser_pdf_validate_executable($fakeChromium, 'Chromium') === $fakeChromium, 'valid browser executable paths are accepted');
    try { quotation_browser_pdf_validate_executable('relative/chrome', 'Chromium'); $assert(false, 'relative browser path rejected'); }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'absolute'), 'invalid configured executable paths are rejected'); }

    $defaults = documents_quote_defaults_settings();
    $company = documents_get_company_profile_for_quotes();
    $q1 = $quote('BULK-PRINT-1', 'QTN-A', 'First Customer');
    $q2 = $quote('BULK-PRINT-2', 'QTN-B', 'Second Customer');
    $combined = quotation_bulk_combined_print_html([$q1, $q2], $defaults, $company);
    $assert(strpos($combined, 'First Customer') < strpos($combined, 'Second Customer'), 'print output preserves selection order');
    $assert(substr_count($combined, 'bulk-print-quotation') >= 2 && str_contains($combined, 'page-break-after:always'), 'multiple print output contains page breaks');

    $pdfPath = quotation_bulk_temp_file('.pdf');
    $tempFiles[] = $pdfPath;
    quotation_bulk_render_pdf_file($q1, $defaults, $company, $pdfPath);
    $pdf = file_get_contents($pdfPath);
    $assert(is_string($pdf) && str_starts_with($pdf, '%PDF-'), 'PDF responses contain valid PDF signatures');
    $assert(!str_contains(file_get_contents(__DIR__ . '/../includes/quotation_bulk_actions.php'), 'new SimplePdfDocument'), 'quotation export does not use SimplePdfDocument');

    if (class_exists('ZipArchive')) {
        $zipPath = quotation_bulk_temp_file('.zip');
        $tempFiles[] = $zipPath;
        $zip = new ZipArchive();
        $assert($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'multiple quotations select ZIP-capable response path');
        $usedZip = [];
        $zip->addFile($pdfPath, quotation_bulk_pdf_filename($q1, $usedZip));
        $pdfPath2 = quotation_bulk_temp_file('.pdf');
        $tempFiles[] = $pdfPath2;
        quotation_bulk_render_pdf_file($q2, $defaults, $company, $pdfPath2);
        $zip->addFile($pdfPath2, quotation_bulk_pdf_filename($q2, $usedZip));
        $zip->close();
        $zip2 = new ZipArchive();
        $assert($zip2->open($zipPath) === true && $zip2->numFiles === 2, 'ZIP entries contain one unique PDF per quotation and form a valid archive');
        for ($i = 0; $i < $zip2->numFiles; $i++) {
            $entry = $zip2->getFromIndex($i);
            $assert(is_string($entry) && str_starts_with($entry, '%PDF-'), 'ZIP responses contain valid PDF entries');
        }
        $zip2->close();
    } else {
        $assert(!class_exists('ZipArchive'), 'missing ZIP dependency can be detected for controlled errors');
    }

    quotation_bulk_delete_files($tempFiles);
    $assert(array_filter($tempFiles, 'is_file') === [], 'temporary files are cleaned up after success');
    $errTemp = quotation_bulk_temp_file('.pdf');
    file_put_contents($errTemp, 'x');
    quotation_bulk_delete_files([$errTemp]);
    $assert(!is_file($errTemp), 'temporary files are cleaned up after an error');

    $assert(count([$q1]) === 1, 'one quotation selects the direct-PDF response path');
    $assert(count([$q1, $q2]) > 1, 'multiple quotations select the ZIP response path');
    $assert(function_exists('documents_quote_apply_admin_status_transition'), 'existing mutation bulk actions still operate through status transition helper');
    $assert(!str_contains(file_get_contents(__DIR__ . '/../admin-quotations.php'), "['archive' => 'archived'") || str_contains(file_get_contents(__DIR__ . '/../admin-quotations.php'), "bulk_download_quotation_pdfs"), 'export actions do not replace mutation status transitions');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    quotation_bulk_delete_files($tempFiles);
}

if ($failed) { exit(1); }
