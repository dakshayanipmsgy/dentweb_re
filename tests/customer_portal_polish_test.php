<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_workspace_view.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
function polish_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$projects = [['quote' => ['quote_no' => 'Q<001>', 'system_type' => 'ongrid'], 'invoices' => [
    ['id' => 'a', 'invoice_no' => 'INV/001', 'status' => 'finalized', 'main_solar_kwp' => 3, 'complimentary_non_dcr_kwp' => .5],
    ['id' => 'draft', 'invoice_no' => 'DRAFT SECRET', 'status' => 'draft', 'capacity_kwp' => 99],
]]];
$before = $projects;
$groups = customer_workspace_project_references($projects);
$html = customer_workspace_references_html($groups);
polish_check($projects === $before, 'View must not mutate source records');
polish_check(str_contains($html, 'INV/001') && str_contains($html, '3.50 kWp'), 'Canonical invoice references missing');
polish_check(!str_contains($html, 'DRAFT SECRET') && !str_contains($html, '99.00'), 'Draft must not be treated as finalized');
polish_check(str_contains($html, 'Q&lt;001&gt;') && !str_contains($html, 'Q<001>'), 'Reference HTML escaping');
polish_check(str_contains($html, 'does not confirm commissioning'), 'Must distinguish invoiced from installed');
polish_check(customer_workspace_references_html([]) === '', 'No fabricated project references');
polish_check(customer_workspace_first_text(['', '  ', '000123']) === '000123', 'Leading zeros lost');
polish_check(customer_workspace_first_text(['', '0', 'fallback']) === '0', 'Zero must not be missing');
$body = '<section><table><tr><td>Invoice No.</td><td></td></tr><tr><td>Capacity</td><td> kWp</td></tr><tr><td>Recorded capacity</td><td>3 kWp</td></tr><tr><td>Proof</td><td><img src="proof.png" alt=""></td></tr></table><p>Warranty Period: _______ years.</p><p>Performance Warranty: _______ years.</p><p>Warranty Period: 25 years.</p><p>Customer Signature: __________________</p><p>Custom terms &amp; serial ABC-001.</p></section>';
$out = customer_workspace_handover_missing_details($body);
polish_check(substr_count($out, 'Not recorded in saved handover') === 4, 'Blank table fields and warranty placeholders need explicit labels');
polish_check(str_contains($out, '3 kWp') && str_contains($out, '25 years.') && str_contains($out, 'Custom terms &amp; serial ABC-001.'), 'Recorded data or terms changed');
polish_check(str_contains($out, 'Customer Signature: __________________') && str_contains($out, 'proof.png'), 'Signature placeholder or image changed');
polish_check(customer_workspace_handover_missing_details($out) === $out, 'Normalization must be idempotent');
$invoiceSource = file_get_contents(__DIR__ . '/../invoice-view.php');
polish_check(str_contains($invoiceSource, "?: 'Authorized Signatory'"), 'Signature caption needs fallback only');
polish_check(str_contains($invoiceSource, 'Invoice total before payments'), 'Gross total must not be presented as unpaid balance');
echo "customer_portal_polish_test passed\n";
