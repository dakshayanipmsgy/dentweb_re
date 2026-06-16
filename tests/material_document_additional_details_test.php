<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/material_document_renderer.php';
function md_ok(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$company = ['brand_name' => 'Dakshayani Enterprises'];
$options = [
    'title' => 'Delivery Challan',
    'additional_details_title' => 'Dispatch Details',
    'additional_details' => [
        ['label' => 'Dispatch date', 'value' => '  '],
        ['label' => 'Driver mobile', 'value' => '9876543210'],
        ['label' => 'E-way bill / reference', 'value' => '<EWB-612>'],
        ['label' => 'Delivery notes', 'value' => "Line one\nLine two", 'multiline' => true],
    ],
    'disclaimer' => 'Safe disclaimer',
];
ob_start();
render_material_document([], $company, [], $options);
$html = ob_get_clean();
md_ok(str_contains($html, 'Dispatch Details'), 'renders dispatch details title');
md_ok(str_contains($html, 'Driver mobile') && str_contains($html, '9876543210'), 'renders driver mobile');
md_ok(str_contains($html, 'E-way bill / reference') && str_contains($html, '&lt;EWB-612&gt;'), 'renders escaped e-way bill reference');
md_ok(str_contains($html, 'Delivery notes') && str_contains($html, "Line one<br />\nLine two"), 'renders multiline notes with line breaks');
md_ok(!str_contains($html, 'Dispatch date'), 'omits empty detail rows');
$options['additional_details'] = [['label' => 'Driver mobile', 'value' => '']];
ob_start();
render_material_document([], $company, [], $options);
$html = ob_get_clean();
md_ok(!str_contains($html, 'Dispatch Details'), 'omits empty details section');
echo "material document additional details tests passed\n";
