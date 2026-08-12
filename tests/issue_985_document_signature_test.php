<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/document_signature.php';
require_once dirname(__DIR__) . '/includes/material_document_renderer.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$id = 'issue985_' . bin2hex(random_bytes(4));
$relative = 'quotation/' . $id . '/signature.png';
$directory = document_signature_root() . '/quotation/' . $id;
mkdir($directory, 0750, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
file_put_contents($directory . '/signature.png', $png);
$doc = ['id' => $id, 'signature' => ['path'=>$relative, 'mime'=>'image/png', 'size'=>strlen($png), 'sha256'=>hash('sha256', $png)]];

$rendered = document_signature_render($doc);
$assert(str_contains($rendered, 'document-signature-image.php?'), 'a stored validated reference renders through the memory-safe image endpoint');
$assert(str_contains($rendered, 'Authorized Signatory'), 'signature renderer includes the signatory label');
$assert(document_signature_render(['id'=>$id]) === '', 'documents without signatures preserve the old rendering');
$assert(document_signature_file(['signature'=>['path'=>'../outside.png','mime'=>'image/png','sha256'=>'x']]) === [], 'path traversal references are rejected');
$assert(document_signature_file(['signature'=>['path'=>$relative,'mime'=>'image/svg+xml','sha256'=>hash('sha256',$png)]]) === [], 'unapproved MIME references are rejected');

ob_start();
render_material_document($doc, [], [], ['footer'=>'Authorised Signatory']);
$material = ob_get_clean();
$assert(str_contains($material, 'document-signature-image.php?'), 'shared Dispatch Advice/Challan material renderer includes signatures');

foreach (['admin-quotations.php','admin-dispatch-advices.php','admin-invoices.php','challan-view.php'] as $file) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $file);
    $assert(str_contains($source, 'document_signature_admin_controls'), $file . ' exposes shared admin controls');
}
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/admin-site-settings.php'), "'universal'"), 'site settings exposes the universal signature control');
$dashboardSource = file_get_contents(dirname(__DIR__) . '/admin-dashboard.php');
$assert(str_contains($dashboardSource, 'Universal Document Signature') && str_contains($dashboardSource, 'admin-site-settings.php#universal-document-signature'), 'admin dashboard links directly to universal signature settings');
$assert(str_contains(document_signature_admin_controls($doc, 'invoice', 'admin-invoices.php', true), 'Set or change the universal signature'), 'each per-document editor links to universal signature settings');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/document-signature-image.php'), 'readfile($file)'), 'signature images are streamed rather than expanded into memory-heavy data URIs');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/includes/quotation_view_renderer.php'), 'document_signature_render($quote)'), 'quotation admin/public/print renderer includes signatures');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/invoice-view.php'), 'document_signature_render($invoice)'), 'invoice view/print renderer includes signatures');
$challanPrint = file_get_contents(dirname(__DIR__) . '/challan-print.php');
$assert(str_contains($challanPrint, 'render_material_document($challan') && str_contains($challanPrint, 'document_signature_render($challan'), 'both Challan print rendering paths include shared signature support');

document_signature_delete($doc['signature']);
@rmdir($directory);
@rmdir(dirname($directory));
echo "Issue #985 document signature regression tests passed.\n";
