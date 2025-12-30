<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$type = isset($_GET['type']) && is_string($_GET['type']) ? strtolower(trim($_GET['type'])) : 'leads';
if (!in_array($type, ['leads', 'customers', 'customer'], true)) {
    $type = 'leads';
}
if ($type === 'customer') {
    $type = 'customers';
}

$columns = customer_records_template_columns($type);
if (empty($columns)) {
    http_response_code(404);
    exit('Template not found.');
}

$filename = $type === 'customers' ? 'customers-template.csv' : 'leads-template.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$handle = fopen('php://output', 'wb');
if ($handle === false) {
    exit('Unable to stream template.');
}

fputcsv($handle, $columns);

fclose($handle);
exit;
