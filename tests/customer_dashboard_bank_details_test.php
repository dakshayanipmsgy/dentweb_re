<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assertions = 0;
function bank_details_ok(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$profilePath = documents_company_profile_path();
$profileBefore = file_get_contents($profilePath);
$requestPath = documents_payment_requests_path();
$requestsBefore = is_file($requestPath) ? file_get_contents($requestPath) : null;
$receiptPath = documents_sales_receipts_store_path();
$receiptsBefore = is_file($receiptPath) ? file_get_contents($receiptPath) : null;

$configured = documents_company_bank_transfer_details([
    'bank_account_name' => 'Dakshayani & Sons',
    'bank_name' => 'Example <Bank>',
    'bank_account_no' => '0012 3456 7890',
    'bank_ifsc' => 'EXAM0000123',
    'bank_branch' => 'Main "Branch"',
]);
bank_details_ok($configured['has_details'] && count($configured['fields']) === 5, 'all configured bank fields are returned');
bank_details_ok($configured['bank_account_no'] === '0012 3456 7890' && $configured['bank_ifsc'] === 'EXAM0000123', 'canonical Copy values remain exact');
bank_details_ok(array_column($configured['fields'], 'key') === ['bank_account_name', 'bank_name', 'bank_account_no', 'bank_ifsc', 'bank_branch'], 'shared field precedence is stable');
bank_details_ok(array_values(array_filter($configured['fields'], static fn(array $field): bool => $field['copyable'])) === [$configured['fields'][2], $configured['fields'][3]], 'only account number and IFSC are copyable');

$partial = documents_company_bank_transfer_details(['bank_name' => '  Bank One  ', 'bank_ifsc' => " \t "]);
bank_details_ok($partial['has_details'] && count($partial['fields']) === 1 && $partial['bank_name'] === 'Bank One', 'blank fields and labels are omitted cleanly');
$empty = documents_company_bank_transfer_details([]);
bank_details_ok(!$empty['has_details'] && $empty['fields'] === [], 'no-details fallback is exposed');

$dashboard = file_get_contents(__DIR__ . '/../customer-dashboard.php');
$admin = file_get_contents(__DIR__ . '/../admin-documents.php');
bank_details_ok(substr_count($dashboard, 'class="bank-transfer-panel"') === 1 && str_contains($dashboard, "if (\$project['payment_requests'] !== [])"), 'one panel template is rendered per project with active requests');
bank_details_ok(str_contains($dashboard, "['cancelled', 'paid']") && str_contains($dashboard, "empty(\$request['archived_flag'])") && str_contains($dashboard, "!empty(\$request['visibility_to_customer'])"), 'internal-only, cancelled, paid, and archived requests are excluded');
bank_details_ok(str_contains($dashboard, "(string) (\$request['quotation_id'] ?? '') === \$quoteId") && str_contains($dashboard, "normalize_customer_mobile((string) (\$request['customer_mobile'] ?? '')) === \$customerMobile"), 'quotation and logged-in customer ownership are exact');
bank_details_ok(str_contains($dashboard, 'data-copy-value="<?= customer_portal_safe($bankField[\'value\']) ?>"') && str_contains($dashboard, 'aria-label="Copy '), 'accessible Copy actions carry escaped canonical values');
bank_details_ok(str_contains($dashboard, '.bank-transfer-grid{grid-template-columns:1fr}') && str_contains($dashboard, 'repeat(2,minmax(0,1fr))'), 'mobile and iPad-responsive bank markup is present');
bank_details_ok(str_contains($dashboard, 'Bank transfer details are not configured.'), 'no-details customer fallback is rendered');
bank_details_ok(str_contains($admin, 'documents_company_bank_transfer_details($company)') && !str_contains($admin, "['bank_name'=>'Bank','bank_account_name'"), 'printable Payment Request reuses shared bank parsing');

$readOnlyBefore = file_get_contents($profilePath);
for ($i = 0; $i < 5; $i++) {
    documents_company_bank_transfer_details();
}
bank_details_ok(file_get_contents($profilePath) === $readOnlyBefore && $readOnlyBefore === $profileBefore, 'render helper leaves company_profile.json byte-for-byte unchanged');
bank_details_ok((is_file($requestPath) ? file_get_contents($requestPath) : null) === $requestsBefore, 'bank rendering leaves payment requests and UPI launch counts unchanged');
bank_details_ok((is_file($receiptPath) ? file_get_contents($receiptPath) : null) === $receiptsBefore, 'bank rendering leaves receipt and accounting state unchanged');
bank_details_ok(!str_contains($dashboard, 'documents_payment_request_generate_upi_link') && str_contains($dashboard, 'documents_customer_payment_request_link_state'), 'dashboard creates or refreshes no UPI link');

echo "customer_dashboard_bank_details_test passed ($assertions assertions)\n";
