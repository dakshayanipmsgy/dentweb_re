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
if [ "$1" = "--version" ]; then echo "Chromium 120.0.0 test"; exit 0; fi
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
    $disc = quotation_browser_pdf_discover(null, true);
    $assert(!empty($disc['available']) && $disc['configured'] === true, 'explicit QUOTATION_CHROMIUM_PATH takes precedence');
    putenv('QUOTATION_CHROMIUM_PATH=/definitely/not/a/browser');
    $disc2 = quotation_browser_pdf_discover([['path' => '/definitely/not/a/browser', 'source' => 'configured', 'label' => 'QUOTATION_CHROMIUM_PATH', 'configured' => true], ['path' => $fakeChromium, 'source' => 'path', 'label' => 'PATH', 'configured' => false]], true);
    $assert(!empty($disc2['available']) && $disc2['path'] === $fakeChromium && $disc2['configured'] === false && str_contains($disc2['warning'], 'QUOTATION_CHROMIUM_PATH'), 'invalid configured path falls through to automatic browser');
    putenv('QUOTATION_CHROMIUM_PATH=' . $fakeChromium);

    $pathDir = dirname($fakeChromium) . DIRECTORY_SEPARATOR . 'browser-path-' . bin2hex(random_bytes(4));
    mkdir($pathDir, 0700);
    $tempFiles[] = $pathDir . DIRECTORY_SEPARATOR . 'google-chrome';
    copy($fakeChromium, $tempFiles[count($tempFiles)-1]); chmod($tempFiles[count($tempFiles)-1], 0700);
    $oldPath = getenv('PATH') ?: '';
    putenv('QUOTATION_CHROMIUM_PATH'); putenv('CHROME_PATH'); putenv('CHROMIUM_PATH'); putenv('PATH=' . $pathDir);
    $pathDisc = quotation_browser_pdf_discover(null, true);
    $assert(!empty($pathDisc['available']) && $pathDisc['source'] === 'path', 'browser detection scans executable names from PATH directories');
    putenv('PATH=' . $oldPath);
    $commonDisc = quotation_browser_pdf_discover([['path' => $fakeChromium, 'source' => 'common', 'label' => 'common location', 'configured' => false]], true);
    $assert(!empty($commonDisc['available']) && $commonDisc['source'] === 'common', 'browser detection supports injectable common absolute locations');
    $managedDisc = quotation_browser_pdf_discover([['path' => $fakeChromium, 'source' => 'repository-managed', 'label' => 'managed browser', 'configured' => false]], true);
    $assert(!empty($managedDisc['available']) && $managedDisc['source'] === 'repository-managed', 'repository-managed browser detection is supported');
    putenv('CHROME_PATH=' . $fakeChromium); $chromeDisc = quotation_browser_pdf_discover(null, true); $assert(!empty($chromeDisc['available']) && $chromeDisc['configured'] === true, 'CHROME_PATH remains supported'); putenv('CHROME_PATH');
    putenv('CHROMIUM_PATH=' . $fakeChromium); $chromiumDisc = quotation_browser_pdf_discover(null, true); $assert(!empty($chromiumDisc['available']) && $chromiumDisc['configured'] === true, 'CHROMIUM_PATH remains supported'); putenv('CHROMIUM_PATH');
    putenv('QUOTATION_CHROMIUM_PATH=' . $fakeChromium);
    $nonFile = quotation_browser_pdf_discover([['path' => sys_get_temp_dir(), 'source' => 'configured', 'label' => 'directory', 'configured' => true]], true);
    $assert(empty($nonFile['available']), 'non-files are rejected during browser discovery');
    $nonExec = quotation_bulk_temp_file('.txt'); file_put_contents($nonExec, 'not executable'); $tempFiles[] = $nonExec;
    $assert(empty(quotation_browser_pdf_discover([['path' => $nonExec, 'source' => 'configured', 'label' => 'non-exec', 'configured' => true]], true)['available']), 'non-executable files are rejected');
    $badBrowser = quotation_bulk_temp_file('.sh'); file_put_contents($badBrowser, "#!/bin/sh
echo not-a-browser
exit 0
"); chmod($badBrowser, 0700); $tempFiles[] = $badBrowser;
    $assert(empty(quotation_browser_pdf_discover([['path' => $badBrowser, 'source' => 'configured', 'label' => 'bad', 'configured' => true]], true)['available']), 'executables failing Chrome version validation are rejected');
    $none = quotation_browser_pdf_discover([], true);
    $assert(empty($none['available']) && $none['status'] === 'not_found', 'no browser returns an unavailable capability result without an exception');

    $assert(quotation_browser_pdf_node_path() === '', 'Node dependency is not required for the PHP Chromium exporter');
    $assert(quotation_browser_pdf_validate_executable($fakeChromium, 'Chromium') === $fakeChromium, 'valid browser executable paths are accepted');
    $assert(empty(quotation_browser_pdf_discover([['path' => 'relative/chrome', 'source' => 'configured', 'label' => 'relative', 'configured' => true]], true)['available']), 'relative browser paths are unavailable without throwing');
    try { quotation_browser_pdf_validate_executable('relative/chrome', 'Chromium'); $assert(false, 'relative browser path rejected'); }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'absolute'), 'invalid configured executable paths are rejected'); }

    foreach ([50,60,70,75,80,90,100] as $pct) { $assert(quotation_output_scale_percent($pct) === $pct, "print scale $pct percent accepted"); }
    $assert(quotation_output_scale_percent(null) === 100, 'print scale defaults to 100 percent');
    $assert(quotation_output_scale_percent(49) === 50, 'print scale below 50 is normalized safely');
    $assert(quotation_output_scale_percent(101) === 100, 'print scale above 100 is normalized safely');
    $assert(quotation_output_scale_percent('75%;color:red') === 100, 'print scale rejects CSS injection strings');
    $assert(quotation_output_scale_percent('abc') === 100, 'print scale rejects non-numeric input');

    $defaults = documents_quote_defaults_settings();
    $company = documents_get_company_profile_for_quotes();
    $q1 = $quote('BULK-PRINT-1', 'QTN-A', 'First Customer');
    $q2 = $quote('BULK-PRINT-2', 'QTN-B', 'Second Customer');
    $combined = quotation_bulk_combined_print_html([$q1, $q2], $defaults, $company, '', 75);
    $fallback = quotation_bulk_browser_print_fallback_html([$q1, $q2], $defaults, $company);
    $assert(str_contains($fallback, 'Save as PDF') && str_contains($fallback, 'one combined PDF'), 'missing server browser returns combined print Save as PDF fallback HTML');
    $assert(strpos($combined, 'First Customer') < strpos($combined, 'Second Customer'), 'print output preserves selection order');
    $assert(substr_count($combined, 'bulk-print-quotation') >= 2 && str_contains($combined, 'page-break-after:always'), 'multiple print output contains page breaks');
    $assert(str_contains($combined, 'data-print-scale-percent="75"') && str_contains($combined, 'quotation-print-scale') && str_contains($combined, 'quotationPrintScaleSelect'), 'combined print HTML receives and exposes validated print percentage');
    $assert(str_contains($combined, '@page{size:A4') && str_contains($combined, 'zoom:var(--quotation-print-scale)') && !str_contains($combined, '75%;color'), 'A4 page and safe flow scaling CSS are emitted');
    $assert(str_contains($combined, 'document.fonts.ready') && str_contains($combined, 'requestAnimationFrame(()=>requestAnimationFrame') && str_contains($combined, 'window.print()'), 'print readiness waits for fonts, images, and two animation frames before printing');
    $assert(str_contains($combined, '@media print{.bulk-print-toolbar,.bulk-print-fallback-banner{display:none!important}'), 'print toolbar is excluded from printed output');

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
