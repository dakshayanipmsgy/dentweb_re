<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();
// Legacy endpoint retained for compatibility. Quotation is now HTML-only modern proposal.
$id = urlencode((string)($_GET['id'] ?? ''));
header('Location: quotation-view.php?id=' . $id);
exit;
