<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$store = customer_record_store();
$state = isset($_GET['state']) ? (string) $_GET['state'] : 'all';

$csv = $store->exportCsv($state);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="customer-export.csv"');
header('Content-Length: ' . strlen($csv));

echo $csv;
