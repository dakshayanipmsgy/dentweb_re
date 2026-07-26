<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$quotes = [
    ['id' => 'draft', 'quote_no' => 'Q-001', 'customer_name' => 'Alpha', 'status' => 'draft'],
    ['id' => 'pending', 'quote_no' => 'Q-002', 'customer_name' => 'Beta', 'status' => 'pending admin approval'],
    ['id' => 'approved', 'quote_no' => 'Q-003', 'customer_name' => 'Gamma', 'status' => 'Approved'],
    ['id' => 'accepted', 'quote_no' => 'Q-004', 'customer_name' => 'Delta', 'status' => 'accepted'],
    ['id' => 'updated', 'quote_no' => 'Q-005', 'customer_name' => 'Epsilon', 'status' => 'update_requested'],
    ['id' => 'archived', 'quote_no' => 'Q-006', 'customer_name' => 'Zeta', 'status' => 'draft', 'archived_flag' => true],
];

$options = quotation_bulk_status_options();
$assert(array_keys($options) === ['draft', 'pending_admin_approval', 'approved', 'accepted', 'update_requested', 'archived'], 'all canonical quotation statuses are offered');
$assert($options['approved'] === documents_status_label(['status' => 'approved'], 'admin'), 'status filter reuses canonical quotation labels');
$assert(quotation_bulk_normalize_status_filter('not-real') === '', 'unknown status safely falls back to all statuses');
$assert(count(quotation_bulk_filter_quotes($quotes, 'not-real')) === count($quotes), 'unknown status does not hide quotations');
$assert(array_column(quotation_bulk_filter_quotes($quotes, 'approved'), 'id') === ['approved'], 'status selection returns only matching rows');
$assert(array_column(quotation_bulk_filter_quotes($quotes, 'archived'), 'id') === ['archived'], 'archived flag is matched as canonical archived status');
$assert(array_column(quotation_bulk_filter_quotes($quotes, 'draft', 'alpha'), 'id') === ['draft'], 'status and search filters work together');
$assert(quotation_bulk_filter_quotes($quotes, 'approved', 'Alpha') === [], 'combined filters require both conditions');

$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');
$assert(str_contains($admin, 'name="status_filter"') && str_contains($admin, 'All statuses'), 'bulk toolbar persists status_filter in the URL');
$assert(str_contains($admin, "'status_filter' => quotation_bulk_normalize_status_filter(\$_GET['status_filter'] ?? '')"), 'bulk action redirects preserve the validated status filter');
$assert(str_contains($admin, 'visibleChecks') && str_contains($admin, 'check.checked=false;check.disabled=true'), 'Select All and submission are restricted to visible eligible rows');
$assert(str_contains($admin, 'bulk_download_quotation_pdfs') && str_contains($admin, 'bulk_print_quotations'), 'existing bulk download and print actions remain available');
