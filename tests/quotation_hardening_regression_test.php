<?php

declare(strict_types=1);

$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');
$view = file_get_contents(__DIR__ . '/../quotation-view.php');
$partial = file_get_contents(__DIR__ . '/../admin/partials/quotation-list.php');
$public = file_get_contents(__DIR__ . '/../quotation-public.php');
$renderer = file_get_contents(__DIR__ . '/../includes/quotation_view_renderer.php');
$reports = file_get_contents(__DIR__ . '/../includes/solar_finance_reports.php');

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$assert(str_contains($admin, "json_last_error() !== JSON_ERROR_NONE") && str_contains($admin, 'Existing rate-chart settings were preserved.'), 'invalid rate-chart JSON is rejected with a preservation message');
$assert(strpos($admin, '$decodedRateChartOnGrid = $decodeRateChart') < strpos($admin, "foreach (['primary','accent'"), 'rate-chart JSON is validated before settings are mutated');
$assert(str_contains($admin, "'dcr_size_kwp'") && str_contains($admin, "'non_dcr_size_kwp'") && str_contains($admin, "'total_system_size_kwp'"), 'admin snapshot stores DCR, Non-DCR, and total model sizes');
$assert(str_contains($reports, "'dcr_size_kwp'") && str_contains($reports, "'non_dcr_size_kwp'") && str_contains($reports, "'total_system_size_kwp'"), 'solar-finance snapshot stores all size semantics');
$assert(str_contains($admin, 'array_key_exists($key, $postData) ? $postData[$key] : $fallback'), 'missing or disabled finance fields preserve saved fallback values');
foreach (['loan_upto_2_lacs_tenure_years', 'loan_above_2_lacs_tenure_years', '_interest_pct', '_margin_ratio_pct', '_loan_ratio_pct', 'scenario_price_self_funded'] as $needle) {
    $assert(str_contains($admin, $needle), "finance persistence path includes {$needle}");
}
$assert(substr_count($admin, "require __DIR__ . '/admin/partials/quotation-list.php';") === 2, 'full and partial list renders use the same list partial');
$assert(str_contains($partial, 'value="unarchive_quote"'), 'archived row actions expose shared unarchive action');
$assert(str_contains($view, 'documents_quote_apply_admin_status_transition') && !str_contains($view, 'documents_quote_has_valid_acceptance_data'), 'quotation view uses the shared transition without legacy acceptance validation');
$helpers = file_get_contents(__DIR__ . '/../admin/includes/documents_helpers.php');
$assert(str_contains($public, 'documents_get_quote_by_public_share_token') && str_contains($public, "public_share_enabled") && str_contains($helpers, 'hash_equals'), 'public quotation uses constant-time token lookup and validity checks');
$assert(str_contains($renderer, 'upi://pay?') && str_contains($renderer, 'htmlspecialchars($upiAdvanceUrl, ENT_QUOTES)'), 'UPI payment links remain escaped');
