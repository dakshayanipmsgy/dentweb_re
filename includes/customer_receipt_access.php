<?php
declare(strict_types=1);

/** A receipt's immutable project link, not a copied phone number, grants access. */
function customer_receipt_can_view(array $receipt, ?array $quote, array $customer, array $ledger): bool
{
    $mobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));
    if ($mobile === '' || $quote === null || documents_receipt_quote_id($receipt) === '') {
        return false;
    }
    $owner = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? $quote['customer_snapshot']['mobile'] ?? $quote['party_snapshot']['customer_mobile'] ?? ''));
    if ($owner !== $mobile || (string) ($quote['id'] ?? '') !== documents_receipt_quote_id($receipt)) {
        return false;
    }
    $id = (string) ($receipt['id'] ?? '');
    return $id !== '' && in_array($id, array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $ledger), true);
}
