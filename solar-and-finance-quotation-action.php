<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/solar_finance_reports.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload.');
    }

    $linkedQuoteId = safe_text((string) ($payload['linked_quote_id'] ?? ''));

    $syncResult = create_or_update_solar_finance_quote($payload);
    if (!($syncResult['success'] ?? false)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => (string) ($syncResult['message'] ?? 'Unable to sync quotation.'),
        ]);
        exit;
    }

    $quoteId = safe_text((string) ($syncResult['quote_id'] ?? $linkedQuoteId));
    if ($quoteId === '') {
        throw new RuntimeException('Quotation id is missing after sync.');
    }

    $quote = documents_get_quote($quoteId);
    if (!is_array($quote)) {
        throw new RuntimeException('Quotation not found.');
    }

    $changed = false;
    if (safe_text((string) ($quote['public_share_token'] ?? '')) === '') {
        $quote['public_share_token'] = documents_generate_quote_public_share_token();
        $quote['public_share_created_at'] = date('c');
        $changed = true;
    }
    if (empty($quote['public_share_enabled'])) {
        $quote['public_share_enabled'] = true;
        $quote['public_share_revoked_at'] = null;
        $changed = true;
    }

    if ($changed) {
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!($saved['ok'] ?? false)) {
            throw new RuntimeException('Unable to update share settings for quotation.');
        }
    }

    $token = safe_text((string) ($quote['public_share_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Unable to prepare quotation view link.');
    }

    echo json_encode([
        'success' => true,
        'quote_id' => (string) ($quote['id'] ?? ''),
        'quote_no' => (string) ($quote['quote_no'] ?? ''),
        'html_url' => '/quotation-public.php?t=' . urlencode($token),
        'pdf_url' => '/quotation-public-pdf.php?t=' . urlencode($token) . '&download=1',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare quotation action.',
    ]);
}
