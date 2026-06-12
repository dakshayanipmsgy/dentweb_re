<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_view_renderer.php';

$quote = documents_quote_defaults();
$quote['id'] = 'SMOKE-QUOTE';
$quote['quote_no'] = 'SMOKE-QUOTE';
$quote['customer_name'] = 'Quotation Smoke Test';
$quote['customer_mobile'] = '9876543210';
$quote['main_solar_kwp'] = '3';
$quote['complimentary_non_dcr_kwp'] = '2';
$quote['capacity_kwp'] = '5';
$quote['system_capacity_kwp'] = 5;
$quote['input_total_gst_inclusive'] = 500000;
$quote['calc'] = ['grand_total' => 500000, 'gross_payable' => 500000, 'subsidy_expected_rs' => 78000];
$quote = documents_quote_prepare($quote);
$defaults = documents_quote_defaults_settings();
$company = documents_get_company_profile_for_quotes();

$previous = set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    ob_start();
    quotation_render($quote, $defaults, $company, false, 'https://example.test/quotation-public.php?t=test', 'public', '');
    $publicHtml = (string) ob_get_clean();
    ob_start();
    quotation_render($quote, $defaults, $company, false, '', 'admin', 'admin-smoke');
    $adminHtml = (string) ob_get_clean();
} finally {
    restore_error_handler();
}

if (!str_contains($publicHtml, 'Quotation Smoke Test') || !str_contains($adminHtml, 'Quotation Smoke Test')) {
    fwrite(STDERR, "FAIL: quotation renderer smoke output is incomplete\n");
    exit(1);
}
fwrite(STDOUT, "PASS: quotation view/public renderer emits complete output without warnings, notices, or fatals\n");
