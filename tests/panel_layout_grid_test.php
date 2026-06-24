<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$raw = [
    'enabled' => true,
    'layout_mode' => 'grid_editor',
    'grid' => ['columns' => 24, 'rows' => 16, 'cell_unit' => 'pixel'],
    'objects' => [
        ['id' => 'panel_1', 'type' => 'panel', 'x' => 1, 'y' => 1, 'w' => 2, 'h' => 4, 'orientation' => 'portrait', 'label' => '1'],
        ['id' => 'panel_2', 'type' => 'panel', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 2, 'orientation' => 'landscape', 'label' => '2'],
        ['id' => 'text_1', 'type' => 'text', 'x' => 8, 'y' => 1, 'w' => 6, 'h' => 2, 'text' => '<script>alert(1)</script>Main roof'],
        ['id' => 'panel_3', 'type' => 'panel', 'x' => 22, 'y' => 14, 'w' => 4, 'h' => 4, 'orientation' => 'landscape', 'label' => '3'],
    ],
];

$normalized = documents_quote_normalize_panel_orientation($raw);
$html = documents_quote_render_panel_orientation_diagram($normalized);

$assertions = [
    'grid layout mode is preserved when objects exist' => ($normalized['layout_mode'] ?? '') === 'grid_editor',
    'grid dimensions are saved as grid units' => ($normalized['grid']['columns'] ?? 0) === 24 && ($normalized['grid']['rows'] ?? 0) === 16 && ($normalized['grid']['cell_unit'] ?? '') === 'grid',
    'overlapping panel is rejected server-side' => count($normalized['objects'] ?? []) === 3,
    'out-of-bounds panel is constrained inside grid' => (($normalized['objects'][2]['x'] ?? 99) + ($normalized['objects'][2]['w'] ?? 99)) <= 24,
    'text label is sanitized' => !str_contains((string)($normalized['objects'][1]['text'] ?? ''), '<script>'),
    'public renderer uses clean grid SVG' => str_contains($html, 'Solar panel grid layout diagram') && str_contains($html, 'Main roof') && !str_contains($html, '<script>'),
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}
