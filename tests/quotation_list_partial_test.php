<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

if (!function_exists('current_user')) {
    function current_user(): array
    {
        return ['id' => 'test-admin', 'full_name' => 'Test Admin', 'role_name' => 'admin'];
    }
}

$_SESSION['csrf_token'] = 'test-csrf';
$tab = 'quotations';
$statusFilter = '';
$allQuotes = [[
    'id' => 'Q-WARNING-FREE',
    'status' => 'draft',
    'calc' => 'legacy-invalid-calc',
    'input_total_gst_inclusive' => 12345.67,
    'source' => 'legacy-invalid-source',
    'workflow' => 'legacy-invalid-workflow',
    'public_share_enabled' => true,
]];
$quotationPublicShareUrl = static fn(array $quote): string => '';
$quotationExtractMobile = static fn(array $quote): string => '';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $preparedMalformedQuote = documents_quote_prepare(['calc' => 'legacy-invalid-calc']);
    ob_start();
    require __DIR__ . '/../admin/partials/quotation-list.php';
    $html = (string) ob_get_clean();
} catch (Throwable $exception) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'FAIL: partial emitted a PHP warning/error: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    restore_error_handler();
}

$assertions = [
    'quote preparation normalizes malformed calc data' => is_array($preparedMalformedQuote['calc'] ?? null),
    'partial renders only the quotation list root' => str_starts_with(ltrim($html), '<div id="quotationList"'),
    'partial uses the legacy total fallback when calc is invalid' => str_contains($html, '₹12,345.67'),
    'partial safely falls back to quote id for a missing quote number' => str_contains($html, 'Q-WARNING-FREE'),
    'partial does not render editor or Bulk Tools panels' => !str_contains($html, 'Save Quotation') && !str_contains($html, 'Bulk Tools'),
    'partial output contains no PHP warning text' => !preg_match('/(?:PHP )?(?:Warning|Notice|Fatal error):/i', $html),
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}
