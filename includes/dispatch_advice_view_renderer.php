<?php
declare(strict_types=1);
require_once __DIR__ . '/material_document_renderer.php';
function dispatch_advice_safe(string $value): string { return material_document_safe($value); }
function render_dispatch_advice(array $d, array $company = [], bool $public = false): void
{
    render_material_document($d, $company, documents_normalize_dispatch_advice_items((array)($d['items'] ?? [])), [
        'title' => 'Material Dispatch Advice',
        'number' => (string)($d['dispatch_advice_no'] ?? ''),
        'version' => (string)($d['revision_no'] ?? 1),
        'date' => (string)($d['planned_dispatch_date'] ?? ''),
        'status' => ucfirst((string)($d['status'] ?? '')),
        'quotation_no' => (string)($d['quotation_no'] ?? ''),
        'agreement_no' => (string)($d['agreement_no'] ?? ''),
        'customer_name' => (string)($d['customer_name'] ?? ''),
        'customer_mobile' => $public ? customer_acceptance_mask_mobile((string)($d['customer_mobile'] ?? '')) : (string)($d['customer_mobile'] ?? ''),
        'delivery_address' => (string)($d['delivery_address'] ?? ''),
        'note_title' => 'Customer note',
        'note' => (string)($d['customer_note'] ?? ''),
        'disclaimer' => (string)($d['disclaimer'] ?? ''),
        'footer' => 'For Dakshayani Enterprises — Authorised Signatory',
    ]);
}
