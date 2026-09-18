<?php
declare(strict_types=1);

$css = file_get_contents(__DIR__ . '/../assets/css/quote-panel-layout-designer.css');
if (!is_string($css)) {
    fwrite(STDERR, "FAIL: unable to read panel layout designer CSS\n");
    exit(1);
}

function rule_has(string $css, string $selector, string $declaration): bool
{
    $selectorPattern = preg_quote($selector, '/');
    $declarationPattern = preg_quote($declaration, '/');
    return preg_match('/' . $selectorPattern . '\s*\{[^}]*' . $declarationPattern . '/s', $css) === 1;
}

$checks = [
    'outer layout section does not clip designer' => rule_has($css, '.panel-layout-section', 'overflow:visible'),
    'outer layout section can shrink in its parent grid' => rule_has($css, '.panel-layout-section', 'min-width:0'),
    'designer uses shrink-safe grid columns' => rule_has($css, '.panel-layout-main', 'grid-template-columns:minmax(0,1fr) 238px'),
    'canvas scroller can shrink inside parent' => rule_has($css, '.panel-layout-scroll', 'min-width:0'),
    'canvas scroller owns horizontal overflow' => rule_has($css, '.panel-layout-scroll', 'overflow:auto'),
    'palette responds to actual available width' => rule_has($css, '.panel-layout-designer', 'container-type:inline-size')
        && str_contains($css, '@container(max-width:940px)')
        && rule_has($css, '.panel-layout-main', 'grid-template-columns:1fr'),
    'medium viewport stacks the palette as a fallback' => str_contains($css, '@media(max-width:1100px)'),
    'mobile palette uses one column' => str_contains($css, '@media(max-width:760px)')
        && rule_has($css, '.panel-layout-palette', 'grid-template-columns:1fr'),
    'small mobile controls use one column' => str_contains($css, '@media(max-width:520px)')
        && rule_has($css, '.panel-layout-meta-grid,.panel-layout-controls', 'grid-template-columns:1fr'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}
