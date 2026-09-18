<?php
declare(strict_types=1);

$css = file_get_contents(__DIR__ . '/../assets/css/quote-panel-layout-designer.css');
if (!is_string($css)) {
    fwrite(STDERR, "FAIL: unable to read panel layout designer CSS\n");
    exit(1);
}

$checks = [
    'outer layout section does not clip designer' => str_contains($css, '.panel-layout-section{overflow:visible;'),
    'designer uses shrink-safe grid columns' => str_contains($css, '.panel-layout-main{display:grid;grid-template-columns:minmax(0,1fr) 238px;'),
    'canvas scroller can shrink inside parent' => str_contains($css, '.panel-layout-scroll{position:relative;width:100%;min-width:0;'),
    'canvas owns overflow scrolling' => str_contains($css, 'overflow:auto;overscroll-behavior:contain;'),
    'palette stacks before narrow clipping occurs' => str_contains($css, '@media(max-width:1100px)') && str_contains($css, '.panel-layout-main{grid-template-columns:1fr}'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}
