<?php
declare(strict_types=1);

function assert_ui(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');
$renderer = file_get_contents(__DIR__ . '/../includes/quotation_view_renderer.php');
$css = file_get_contents(__DIR__ . '/../assets/css/quote-panel-layout-designer.css');

assert_ui(is_string($admin) && str_contains($admin, 'Solar Panel Layout Designer'), 'visual panel layout designer remains available');
assert_ui(str_contains($admin, 'data-add-layout-item="obstruction"'), 'designer retains visual keep-out tool');
assert_ui(!str_contains($admin, '<h4>Editable layout groups</h4>'), 'legacy editable layout groups are removed from admin UI');
assert_ui(!str_contains($admin, '<h4>Obstructions / keep-out areas</h4>'), 'legacy obstruction table is removed from admin UI');
assert_ui(!str_contains($admin, '<label>Shade / obstruction / site note</label>'), 'legacy shade/site note field is removed from admin UI');
assert_ui(!str_contains($admin, '<label>Customer-facing orientation note</label>'), 'legacy customer orientation note field is removed from admin UI');

assert_ui(is_string($renderer) && str_contains($renderer, 'panel-orientation-diagram-shell'), 'customer quotation keeps a dedicated modern diagram shell');
assert_ui(!str_contains($renderer, 'orientation-group-table'), 'customer quotation no longer renders legacy layout group table');
assert_ui(!str_contains($renderer, '<strong>Shade / site note:</strong>'), 'customer quotation no longer renders legacy shade note');
assert_ui(!str_contains($renderer, "\$panelOrientation['customer_note']"), 'customer quotation no longer renders legacy customer orientation note');
assert_ui(str_contains($renderer, 'The Solar Panel Layout Designer drawing above is the customer-facing reference'), 'customer quotation explains the visual designer as the layout reference');

assert_ui(is_string($css) && str_contains($css, '.panel-layout-add-btn'), 'designer includes modern palette button styling');
assert_ui(str_contains($css, '.panel-layout-section-head'), 'designer includes modern section hierarchy styling');
