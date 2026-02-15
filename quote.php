<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$token = safe_text($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(404);
    echo 'Invalid link.';
    exit;
}
$quote = null;
foreach (documents_list_quotes() as $row) {
    $share = is_array($row['share'] ?? null) ? $row['share'] : [];
    if ((string) ($share['token'] ?? '') === $token) {
        $quote = $row;
        break;
    }
}
if ($quote === null) {
    http_response_code(404);
    echo 'Invalid link.';
    exit;
}
$share = is_array($quote['share'] ?? null) ? $quote['share'] : [];
if (empty($share['enabled'])) {
    echo '<h1>Link disabled</h1><p>This shared quotation link is currently disabled.</p>';
    exit;
}
define('QUOTE_PUBLIC_MODE', true);
$_GET['id'] = (string) ($quote['id'] ?? '');
require __DIR__ . '/quotation-view.php';
