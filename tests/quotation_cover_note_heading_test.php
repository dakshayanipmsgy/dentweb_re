<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$quote = documents_quote_defaults();
$quote['id'] = 'COVER-NOTE-REGRESSION';
$quote['quote_no'] = 'COVER-NOTE-REGRESSION';
$quote['customer_name'] = 'Unrelated Customer Detail';
$quote['cover_notes_html_snapshot'] = '<p>Existing cover-note body remains unchanged.</p>';
$quote = documents_quote_prepare($quote);
$defaults = documents_quote_defaults_settings();
$company = documents_get_company_profile_for_quotes();

$assertCoverNote = static function (string $html, string $path) use ($assert): void {
    $assert(str_contains($html, 'A NOTE'), "{$path} contains the shortened note heading");
    $assert(!str_contains($html, 'A NOTE FOR YOUR HOME') && !str_contains($html, 'A note for your home'), "{$path} excludes the old note heading");
    $assert(!str_contains($html, 'Dear Homeowner'), "{$path} excludes the removed greeting");
    $assert(str_contains($html, '<p>Existing cover-note body remains unchanged.</p>'), "{$path} preserves the cover-note body");
    $assert(str_contains($html, 'Unrelated Customer Detail'), "{$path} preserves customer content");
    $assert(str_contains($html, 'Clear system scope'), "{$path} preserves unrelated cover-note content");
    $assert(str_contains($html, 'Pricing Summary'), "{$path} preserves unrelated quotation content");
};

foreach (['public', 'admin', 'print'] as $viewerType) {
    $html = quotation_render_to_html($quote, $defaults, $company, false, '', $viewerType, 'cover-note-test');
    $assertCoverNote($html, $viewerType);
}

$combinedPrint = quotation_bulk_combined_print_html([$quote], $defaults, $company);
$assertCoverNote($combinedPrint, 'combined print');

$browserExport = quotation_prepare_client_browser_export_html(
    quotation_render_to_html($quote, $defaults, $company, false, '', 'browser-client-export', 'cover-note-test')
);
$assertCoverNote($browserExport, 'browser PDF export');

fwrite(STDOUT, "PASS: quotation cover-note heading and greeting regression coverage\n");
