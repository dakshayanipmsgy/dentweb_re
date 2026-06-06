<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();
http_response_code(410);
echo 'PDF generation has been removed. Please use the HTML view and browser print if needed.';
