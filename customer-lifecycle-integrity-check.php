<?php
declare(strict_types=1);
require_once __DIR__.'/includes/leads.php';
require_once __DIR__.'/includes/customer_admin.php';
require_once __DIR__.'/admin/includes/documents_helpers.php';
require_once __DIR__.'/includes/customer_lifecycle_integrity.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$report=customer_lifecycle_integrity_check(load_all_leads(),documents_list_quotes(),(new CustomerFsStore())->listCustomers());
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['findings'] === [] ? 0 : 2);
