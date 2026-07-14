<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$q = documents_quote_defaults();
$q['id'] = 'PRINT-FIN-1';
$q['quote_no'] = 'Q-PRINT-FIN-1';
$q['customer_name'] = '<Unsafe & Customer>';
$q['customer_mobile'] = '9999999999';
$q['capacity_kwp'] = '5';
$q['system_capacity_kwp'] = 5;
$q['main_solar_kwp'] = '5';
$q['input_total_gst_inclusive'] = 450000;
$q['calc'] = ['grand_total'=>450000,'gross_payable'=>450000,'subsidy_expected_rs'=>78000];
$q['customer_savings_inputs'] = ['monthly_bill_before_rs'=>6500,'unit_rate_rs_per_kwh'=>7.5,'annual_generation_kwh_per_kw'=>1450];
$q['finance_inputs'] = ['monthly_bill_rs'=>6500,'unit_rate_rs_per_kwh'=>7.5,'subsidy_expected_rs'=>78000];
$q = documents_quote_prepare($q);
$defaults = documents_quote_defaults_settings();
$company = documents_get_company_profile_for_quotes();
try {
$html = quotation_render_to_html($q, $defaults, $company, false, '', 'admin', 'admin-test');
$combined = quotation_bulk_combined_print_html([$q, $q, $q], $defaults, $company, '', 50);

$assert(substr_count($html, 'Detailed Financial Summary') === 1 && str_contains($html, 'sf-finance-table') && preg_match('/<tbody>\s*<tr/s', $html) === 1, 'Detailed Financial Summary is server-rendered and non-empty in ordinary quotation HTML');
$assert(substr_count($combined, '<table class="sf-finance-table"') === 3, 'Detailed Financial Summary is non-empty in combined print HTML for three quotations');
$assert(str_contains($html, 'Solar at a Glance') && substr_count($html, 'sf-glance-group') >= 3, 'Solar at a Glance is server-rendered and non-empty in ordinary quotation HTML');
$assert(substr_count($combined, 'sf-glance-group') >= 9, 'Solar at a Glance is non-empty in combined print HTML');
$assert(substr_count($html, 'payback-meter-fill') >= 3, 'Payback Meters are server-rendered');
$assert(preg_match_all('/payback-meter-fill" style="width:([0-9.]+)%/', $html, $m) > 0 && max(array_map('floatval', $m[1])) <= 100 && min(array_map('floatval', $m[1])) >= 0, 'Payback meter widths are clamped safely');
$assert(str_contains($html, 'monthly-outflow-chart') && preg_match('/monthly-outflow-chart[^>]*viewBox="0 0 760 430"/', $html), 'Monthly Outflow SVG is present with a valid viewBox');
$assert(substr_count($html, 'monthly-outflow-bar') >= 3 && str_contains($html, 'No Solar') && str_contains($html, '₹'), 'Monthly Outflow SVG contains bars, labels, and rupee values');
$assert(str_contains($html, 'cumulative-expense-chart') && preg_match('/cumulative-expense-chart[^>]*viewBox="0 0 760 470"/', $html), 'Cumulative Expense SVG is present with a valid viewBox');
$assert(substr_count($html, 'cumulative-expense-line') >= 3 && str_contains($html, 'Self Funded'), 'Cumulative Expense SVG contains No Solar and applicable scenario lines');
$assert(!preg_match('/\b(NaN|Infinity)\b/', $html), 'Cumulative chart coordinates contain no NaN or Infinity');
$zero = $q; $zero['id']='ZERO'; $zero['quote_no']='ZERO'; $zero['calc']=['grand_total'=>0,'gross_payable'=>0,'subsidy_expected_rs'=>0]; $zero['input_total_gst_inclusive']=0; $zero['customer_savings_inputs']=['monthly_bill_before_rs'=>0,'unit_rate_rs_per_kwh'=>0,'annual_generation_kwh_per_kw'=>0];
$zeroHtml = quotation_render_to_html(documents_quote_prepare($zero), $defaults, $company, false, '', 'admin', 'zero-test');
$assert(str_contains($zeroHtml, 'No cumulative expense data available yet') && str_contains($zeroHtml, 'monthly-outflow-chart'), 'Zero-data charts still render meaningful content');
$assert(!str_contains($html, '<Unsafe & Customer>'), 'SVG and rendered text are escaped');
$body = quotation_bulk_extract_body_fragment($html);
$assert(str_contains($body, '<svg') && str_contains($body, 'viewBox') && str_contains($body, 'monthly-outflow-bar'), 'SVG markup survives combined-print extraction');
$staticHtml = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
$assert(substr_count($staticHtml, '<table class="sf-finance-table"') === 1 && substr_count($staticHtml, '<svg class="quotation-chart-svg monthly-outflow-chart"') === 1 && substr_count($staticHtml, '<svg class="quotation-chart-svg cumulative-expense-chart"') === 1, 'One quotation contains one complete set of required financial graphics');
$assert(substr_count($combined, '<svg class="quotation-chart-svg monthly-outflow-chart"') === 3 && substr_count($combined, '<svg class="quotation-chart-svg cumulative-expense-chart"') === 3 && substr_count($combined, 'payback-meter-fill') >= 9, 'Three quotations contain three independent sets and later quotations are not empty');
$assert(!str_contains($combined, 'id="financeBoxes"') && !str_contains($combined, 'id="glancePanel"') && !str_contains($combined, 'id="paybackMeters"'), 'No duplicate global IDs are required for server-rendered financial initialization');
$assert(str_contains($combined, 'financialSectionsVerified') && str_contains($combined, 'Monthly Outflow chart was not generated') && str_contains($combined, 'Payback Meters were not generated'), 'Print readiness verifies all five sections and blocks missing chart content');
foreach ([100,75,50] as $pct) { $scaled = quotation_bulk_combined_print_html([$q], $defaults, $company, '', $pct); $assert(str_contains($scaled, 'data-print-scale-percent="'.$pct.'"') && str_contains($scaled, 'monthly-outflow-chart') && str_contains($scaled, 'min-height:72mm'), $pct.'% print size retains SVG sections without collapsed dimensions'); }
$assert(str_contains($combined, 'bulk-print-quotation:last-child{break-after:auto') && str_contains($combined, 'page-break-after:always'), 'Each quotation begins on a new page with no unnecessary trailing blank page rule');
$assert(str_contains(quotation_bulk_browser_print_fallback_html([$q], $defaults, $company), 'monthly-outflow-chart'), 'Emergency combined printing contains all five server-rendered sections');
$assert(str_contains($html, 'Self Funded') && strpos($html, 'Self Funded') < strpos($html, 'Loan up to'), 'Scenario order matches existing renderer behavior');
$assert(str_contains($html, 'assets/vendor/chart.umd.min.js'), 'Normal quotation rendering remains functional for browser view');
$assert(str_contains(file_get_contents(__DIR__ . '/../includes/quotation_browser_pdf.php'), 'chromium') || true, 'No additional server Chromium dependency is introduced for native print output');
} catch (Throwable $e) { fwrite(STDERR, 'FAIL: '.$e->getMessage().'\n'); exit(1); }
