<?php
declare(strict_types=1);

// Dedicated route that reuses the proven accepted-customer/document-pack workflow.
define('ADMIN_RECEIPTS_WORKSPACE', true);
$_GET['tab'] = 'accepted_customers';
require __DIR__ . '/admin-documents.php';
