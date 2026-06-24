<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$raw = [
    'enabled' => true,
    'layout_mode' => 'grid_editor',
    'grid' => ['columns' => 60, 'rows' => 40, 'cell_unit' => 'pixel', 'editor_cell_px' => 16, 'major_line_every' => 5],
    'objects' => [
        ['id' => 'panel_1', 'type' => 'panel', 'x' => 1, 'y' => 1, 'w' => 2, 'h' => 4, 'orientation' => 'portrait', 'label' => '1'],
        ['id' => 'panel_2', 'type' => 'panel', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 2, 'orientation' => 'landscape', 'label' => '2'],
        ['id' => 'text_1', 'type' => 'text', 'x' => 8, 'y' => 1, 'w' => 6, 'h' => 2, 'text' => '<script>alert(1)</script>Main roof'],
        ['id' => 'panel_3', 'type' => 'panel', 'x' => 58, 'y' => 38, 'w' => 4, 'h' => 4, 'orientation' => 'landscape', 'label' => '3'],
    ],
];

$normalized = documents_quote_normalize_panel_orientation($raw);
$html = documents_quote_render_panel_orientation_diagram($normalized);
$defaults = documents_quote_normalize_panel_orientation(['enabled' => true]);
$clamped = documents_quote_normalize_panel_orientation([
    'enabled' => true,
    'layout_mode' => 'grid_editor',
    'grid' => ['columns' => 999, 'rows' => 2, 'editor_cell_px' => 200, 'major_line_every' => 99],
    'objects' => [['id' => 'panel_x', 'type' => 'panel', 'x' => 95, 'y' => 5, 'w' => 2, 'h' => 4, 'orientation' => 'portrait', 'label' => 'X']],
]);

$assertions = [
    'new default grid is smaller-cell 36 by 24' => ($defaults['grid']['columns'] ?? 0) === 36 && ($defaults['grid']['rows'] ?? 0) === 24 && ($defaults['grid']['editor_cell_px'] ?? 0) === 18,
    'grid layout mode is preserved when objects exist' => ($normalized['layout_mode'] ?? '') === 'grid_editor',
    'grid dimensions are saved as grid units' => ($normalized['grid']['columns'] ?? 0) === 60 && ($normalized['grid']['rows'] ?? 0) === 40 && ($normalized['grid']['cell_unit'] ?? '') === 'grid',
    'editor display options are normalized' => ($normalized['grid']['editor_cell_px'] ?? 0) === 16 && ($normalized['grid']['major_line_every'] ?? 0) === 5 && ($normalized['grid']['customer_grid_visible'] ?? true) === false,
    'overlapping panel is rejected server-side' => count($normalized['objects'] ?? []) === 3,
    'out-of-bounds panel is constrained inside resized grid' => (($normalized['objects'][2]['x'] ?? 99) + ($normalized['objects'][2]['w'] ?? 99)) <= 60 && (($normalized['objects'][2]['y'] ?? 99) + ($normalized['objects'][2]['h'] ?? 99)) <= 40,
    'invalid grid dimensions are clamped safely' => ($clamped['grid']['columns'] ?? 0) === 100 && ($clamped['grid']['rows'] ?? 0) === 8 && ($clamped['grid']['editor_cell_px'] ?? 0) === 28 && ($clamped['grid']['major_line_every'] ?? 0) === 10,
    'text label is sanitized' => !str_contains((string)($normalized['objects'][1]['text'] ?? ''), '<script>'),
    'public renderer uses clean cropped grid SVG without editor grid by default' => str_contains($html, 'Solar panel grid layout diagram') && str_contains($html, 'Main roof') && !str_contains($html, '<script>') && !str_contains($html, 'fill="url(#grid)"'),
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}
