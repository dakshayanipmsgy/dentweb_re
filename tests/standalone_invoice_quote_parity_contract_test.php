<?php
declare(strict_types=1);

function standalone_parity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$parityPath = $root . '/includes/standalone_invoice_quote_parity.php';
$lifecyclePath = $root . '/includes/commercial_lifecycle.php';
$invoicePath = $root . '/admin-invoices.php';
$helpersPath = $root . '/admin/includes/documents_helpers.php';
$dashboardPath = $root . '/customer-dashboard.php';
$documentViewPath = $root . '/customer-document-view.php';
$invoiceViewPath = $root . '/invoice-view.php';

$parity = file_get_contents($parityPath);
$lifecycle = file_get_contents($lifecyclePath);
$invoices = file_get_contents($invoicePath);
$helpers = file_get_contents($helpersPath);
$dashboard = file_get_contents($dashboardPath);
$documentView = file_get_contents($documentViewPath);
$invoiceView = file_get_contents($invoiceViewPath);

foreach ([$parity, $lifecycle, $invoices, $helpers, $dashboard, $documentView, $invoiceView] as $source) {
    standalone_parity_assert(is_string($source), 'required source file is readable');
}

// The extension must be scoped to admin-invoices.php and standalone invoice records only.
standalone_parity_assert(str_contains($lifecycle, "basename((string) (\$_SERVER['SCRIPT_NAME']"), 'commercial lifecycle gates the additive include by current script');
standalone_parity_assert(str_contains($lifecycle, "=== 'admin-invoices.php'"), 'quotation-parity extension is only loaded by admin-invoices.php');
standalone_parity_assert(str_contains($parity, 'documents_invoice_is_standalone($invoice)'), 'POST/UI extension is explicitly source-gated to standalone invoices');
standalone_parity_assert(str_contains($parity, "['save_invoice_draft', 'finalize_invoice']"), 'only standalone draft save/finalize are intercepted');

// Existing invoice constructors remain present and distinct.
standalone_parity_assert(str_contains($helpers, 'function documents_create_invoice_from_quote('), 'existing quotation invoice constructor remains present');
standalone_parity_assert(str_contains($helpers, 'function documents_create_invoice_from_legacy_project('), 'existing legacy invoice constructor remains present');
standalone_parity_assert(str_contains($helpers, 'function documents_create_standalone_invoice('), 'existing standalone invoice constructor remains present');
standalone_parity_assert(!str_contains($parity, 'documents_create_invoice_from_quote('), 'standalone parity path never fabricates/creates an invoice through quotation constructor');
standalone_parity_assert(!str_contains($parity, 'documents_save_quote('), 'standalone parity path never writes quotation files');

// Same quotation Items Master sources and structured identifiers must be used.
foreach ([
    'documents_inventory_components(false)',
    'documents_inventory_kits(false)',
    'documents_inventory_component_variants(false)',
    'documents_inventory_tax_profiles(false)',
    'documents_inventory_get_component(',
    'documents_inventory_get_component_variant(',
    'documents_inventory_get_kit(',
] as $masterCall) {
    standalone_parity_assert(str_contains($parity, $masterCall), "standalone invoice reuses quotation Items Master source {$masterCall}");
}
foreach ([
    "'type' => (string)\$master['type']",
    "'kit_id' => (string)\$master['kit_id']",
    "'component_id' => (string)\$master['component_id']",
    "'variant_id' => (string)\$master['variant_id']",
    "'name_snapshot'",
    "'master_description_snapshot'",
    "'hsn_snapshot'",
    "'tax_profile_id'",
] as $structuredKey) {
    standalone_parity_assert(str_contains($parity, $structuredKey), "structured invoice items preserve quotation-compatible key {$structuredKey}");
}

// DCR / Non-DCR and rate chart concepts must match quotation terminology.
standalone_parity_assert(str_contains($parity, "'main_solar_kwp'"), 'standalone invoice stores Main Solar / DCR capacity');
standalone_parity_assert(str_contains($parity, "'complimentary_non_dcr_kwp'"), 'standalone invoice stores complimentary Non-DCR capacity');
standalone_parity_assert(str_contains($parity, "['rate_chart']"), 'standalone invoice reads the quotation rate chart');
standalone_parity_assert(str_contains($parity, "variant(r)==='DN'?Math.min(3,t):t"), 'DN model selection uses the same 3 kWp DCR split concept as quotation creation');
standalone_parity_assert(str_contains($parity, 'Ongrid Solar Power Generation System'), 'on-grid model can select the same quotation kit');
standalone_parity_assert(str_contains($parity, 'Hybrid Solar Power Generation System TBased'), 'hybrid TB model can select the same quotation kit');
standalone_parity_assert(str_contains($parity, 'Hybrid Solar Power Generation System TLess'), 'hybrid TL model can select the same quotation kit');

// Customer lookup must search existing Customer Users by name/mobile and fill snapshot fields.
standalone_parity_assert(str_contains($parity, 'listActiveCustomers()'), 'customer dropdown is sourced from existing Customer Users');
standalone_parity_assert(str_contains($parity, 'Find existing customer by name or mobile'), 'standalone UI exposes customer name/mobile lookup');
standalone_parity_assert(str_contains($parity, "norm(c.name).includes(term)"), 'customer dropdown searches name');
standalone_parity_assert(str_contains($parity, "String(c.mobile||'').replace"), 'customer dropdown searches mobile');
standalone_parity_assert(str_contains($parity, 'documents_standalone_match_customer('), 'selected/entered customer is linked through the existing standalone customer matcher');

// A quotation-like renderer snapshot is allowed, but real quotation identity must never be fabricated.
standalone_parity_assert(str_contains($parity, "'source_type' => 'standalone_invoice_quote_parity'"), 'standalone invoice snapshot is explicitly source-labelled');
standalone_parity_assert(str_contains($parity, "'quote_id' => ''"), 'standalone renderer snapshot has no real quotation id');
standalone_parity_assert(str_contains($helpers, "\$doc['linked_quote_id']='';\$doc['quotation_id']='';"), 'standalone constructor keeps real quotation linkage empty');
standalone_parity_assert(str_contains($helpers, "['legacy_project','standalone_invoice']") && str_contains($helpers, 'quotation_reference_known'), 'standalone invoice is never treated as having a real quotation pricing reference');

// Existing customer-facing source-aware support must remain present rather than being replaced.
standalone_parity_assert(str_contains($dashboard, 'standalone'), 'customer dashboard retains standalone invoice visibility support');
standalone_parity_assert(str_contains($documentView, 'standalone'), 'customer document view retains standalone ownership support');
standalone_parity_assert(str_contains($invoiceView, 'standalone'), 'invoice view retains standalone customer/source support');

// The extension must not alter customer-facing files or the main admin invoice file directly.
standalone_parity_assert(!str_contains($dashboard, 'standalone_invoice_quote_parity.php'), 'customer dashboard is not rerouted through the new editor');
standalone_parity_assert(!str_contains($documentView, 'standalone_invoice_quote_parity.php'), 'customer document view is not rerouted through the new editor');
standalone_parity_assert(!str_contains($invoiceView, 'standalone_invoice_quote_parity.php'), 'invoice renderer is not rerouted through the new editor');

// Project technology constraints.
standalone_parity_assert(!preg_match('/\b(node|npm|yarn|pnpm)\b/i', $parity), 'no Node.js tooling introduced by parity extension');
standalone_parity_assert(!preg_match('/\b(PDO|mysqli|mysql_query|sqlite)\b/i', $parity), 'no SQL/database dependency introduced by parity extension');

echo "standalone invoice quotation parity contract tests passed\n";
