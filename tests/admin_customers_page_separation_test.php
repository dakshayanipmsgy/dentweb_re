<?php
declare(strict_types=1);

function separation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$customers = file_get_contents($root . '/admin-customers.php');
$employees = file_get_contents($root . '/admin-users.php');
$dashboard = file_get_contents($root . '/admin-dashboard.php');
$documents = file_get_contents($root . '/admin-documents.php');
$employeeDashboard = file_get_contents($root . '/employee-dashboard.php');
$handover = file_get_contents($root . '/generate-handover.php');

separation_assert(is_string($customers), 'customer management page exists');
separation_assert(str_contains($customers, 'new CustomerFsStore()'), 'customer page uses the existing filesystem store');
foreach ([
    'listActiveCustomers', 'listArchivedCustomers', 'create_customer', 'update_customer',
    'archive_customer', 'restore_customer', 'bulk_archive_customers', 'bulk_restore_customers',
    'customer_bulk_import', 'create_complaint', 'send_welcome_whatsapp',
    'send_welcome_email', 'generate_handover', 'require_valid_csrf', 'log_audit_event',
] as $feature) {
    separation_assert(str_contains($customers, $feature), "customer page preserves {$feature}");
}

separation_assert(str_contains($employees, "unset(\$query['tab'])"), 'legacy redirect removes the customer tab parameter');
separation_assert(str_contains($employees, "http_build_query(\$query)"), 'legacy redirect preserves applicable query parameters');
separation_assert(str_contains($employees, "'admin-customers.php'"), 'legacy customer URLs redirect to customer page');
separation_assert(!str_contains($employees, 'new CustomerFsStore()'), 'employee page does not manage customers');
separation_assert(str_contains($employees, 'new EmployeeFsStore()'), 'employee page retains employee filesystem management');
separation_assert(str_contains($employees, 'create_employee') && str_contains($employees, 'update_employee'), 'employee create and update remain available');

separation_assert(str_contains($dashboard, "\$pathFor('admin-customers.php')") && str_contains($dashboard, '>Customers</a>'), 'dashboard has a Customers link');
separation_assert(str_contains($dashboard, "\$pathFor('admin-users.php')") && str_contains($dashboard, '>Employees</a>'), 'dashboard has an Employees link');
foreach ([$documents, $employeeDashboard, $handover] as $linkingPage) {
    separation_assert(str_contains($linkingPage, 'admin-customers.php'), 'internal customer linkage uses the customer page');
}

separation_assert(!str_contains($documents, "['tab' => 'customers'"), 'Accepted/Completed customer linkage no longer uses the users tab');

echo "admin customers page separation tests passed\n";
