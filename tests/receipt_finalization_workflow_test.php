<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../admin-documents.php');
$assertions = 0;

function receipt_finalize_ok(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

receipt_finalize_ok(
    str_contains($source, '<form method="post" data-receipt-editor="1">'),
    'receipt editor uses its normal PHP form submission path'
);
receipt_finalize_ok(
    !preg_match('/<form[^>]*data-receipt-editor="1"[^>]*data-accepted-ajax-form/s', $source),
    'receipt editor is excluded from the generic accepted-customer AJAX handler'
);
receipt_finalize_ok(
    str_contains($source, 'name="action" value="save_receipt_draft"')
        && str_contains($source, 'name="action" value="finalize_receipt"'),
    'draft and finalise remain distinct submit-button actions'
);
receipt_finalize_ok(
    str_contains($source, '$projectInvoices = documents_invoices_for_quote')
        && str_contains($source, 'documents_receipt_allocations_normalize($receipt, $projectInvoices)'),
    'finalisation validates allocations against the complete project invoice history'
);

echo "receipt_finalization_workflow_test passed ({$assertions} assertions)\n";
