<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/solar_finance_reports.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

function solar_finance_quote_public_urls(string $quoteId): array
{
    $quote = documents_get_quote($quoteId);
    if (!is_array($quote)) {
        return ['html' => '', 'pdf' => ''];
    }

    $quote['public_share_enabled'] = true;
    if (safe_text((string) ($quote['public_share_token'] ?? '')) === '') {
        $quote['public_share_token'] = documents_generate_quote_public_share_token();
        $quote['public_share_created_at'] = (string) ($quote['public_share_created_at'] ?? '') ?: date('c');
    }
    $quote['updated_at'] = date('c');
    documents_save_quote($quote);

    $scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $token = urlencode((string) ($quote['public_share_token'] ?? ''));

    return [
        'html' => $scheme . $host . '/quotation-public.php?t=' . $token,
        'pdf' => $scheme . $host . '/quotation-public-pdf.php?t=' . $token,
    ];
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload.');
    }

    $result = create_or_update_solar_finance_quote($payload);
    if (!($result['success'] ?? false)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Unable to auto-create quotation.'),
        ]);
        exit;
    }

    $urls = solar_finance_quote_public_urls((string) ($result['quote_id'] ?? ''));

    echo json_encode([
        'success' => true,
        'action' => (string) ($result['action'] ?? 'updated'),
        'quote_id' => (string) ($result['quote_id'] ?? ''),
        'quote_no' => (string) ($result['quote_no'] ?? ''),
        'scenario' => (string) ($result['scenario'] ?? ''),
        'message' => (string) ($result['message'] ?? ''),
        'quote_html_url' => (string) ($urls['html'] ?? ''),
        'quote_pdf_url' => (string) ($urls['pdf'] ?? ''),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to auto-create quotation.',
    ]);
}
