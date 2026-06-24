<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_view_renderer.php';

$quote = documents_quote_defaults();
$quote['id'] = 'test_revision_fresh_calc';
$quote['quote_no'] = 'TEST-REV-1';
$quote['status'] = 'draft';
$quote['input_total_gst_inclusive'] = 100000;
$quote['scenario_prices']['self_funded']['price'] = 100000;
$quote['finance_scenarios']['self_funded']['price'] = 100000;
$quote['finance_scenarios']['self_funded']['gross_payable'] = 100000;
$quote['finance_inputs']['transportation_rs'] = '0';
$quote['finance_inputs']['subsidy_expected_rs'] = '0';
$quote['special_requests_text'] = "new line one\nnew line two";
$quote['special_requests_inclusive'] = 'old stale text';
$quote['calc'] = documents_calc_quote_pricing_with_tax_profile($quote, 0, 0, 77777, documents_get_quote_defaults_settings());
$quote['calc_signature'] = 'stale-signature';

$prepared = documents_quote_prepare($quote);
if ($prepared['special_requests_text'] !== "new line one\nnew line two") {
    fwrite(STDERR, "canonical special_requests_text was not preserved\n");
    exit(1);
}
if ($prepared['special_requests_inclusive'] !== $prepared['special_requests_text']) {
    fwrite(STDERR, "compatibility special_requests_inclusive was not mirrored\n");
    exit(1);
}
if (documents_quote_calc_is_fresh($prepared)) {
    fwrite(STDERR, "stale calculation was incorrectly considered fresh\n");
    exit(1);
}
$refreshed = documents_quote_refresh_calc($prepared, documents_get_quote_defaults_settings());
if (abs((float)$refreshed['calc']['final_price_incl_gst'] - 100000) > 0.5) {
    fwrite(STDERR, "refreshed calc did not use current primary scenario price\n");
    exit(1);
}
if (!documents_quote_calc_is_fresh($refreshed)) {
    fwrite(STDERR, "refreshed calculation was not marked fresh\n");
    exit(1);
}

ob_start();
quotation_render($prepared, documents_get_quote_defaults_settings(), ['brand_name' => 'Test Co'], false);
$html = ob_get_clean();
if (strpos($html, '₹1,00,000') === false || strpos($html, 'new line one') === false || strpos($html, 'new line two') === false) {
    fwrite(STDERR, "renderer did not show refreshed price and canonical special requests\n");
    exit(1);
}

echo "quotation revision fresh calc regression passed\n";
