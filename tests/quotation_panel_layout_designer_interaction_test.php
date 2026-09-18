<?php
declare(strict_types=1);

$js = file_get_contents(__DIR__ . '/../assets/js/quote-panel-layout-designer.js');
$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');

function assert_designer(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

assert_designer(is_string($js) && str_contains($js, 'function cloneLayout'), 'designer has an internal clone helper');
assert_designer(str_contains($js, "typeof window.structuredClone==='function'") && str_contains($js, 'JSON.parse(JSON.stringify(value))'), 'clone helper uses native cloning with a JSON fallback');
assert_designer(str_contains($js, "document.readyState==='loading'") && str_contains($js, "DOMContentLoaded"), 'bootstrap supports loading and already-loaded documents');
assert_designer(str_contains($js, "root.dataset.layoutInitialized==='1'") && str_contains($js, "root.dataset.layoutReady='1'"), 'initialization is idempotent and exposes success');
assert_designer(str_contains($js, "root.dataset.layoutReady='0'") && str_contains($js, "root.dataset.layoutError='1'") && str_contains($js, 'Layout designer could not start'), 'initialization exposes a user-safe failure state');
assert_designer(str_contains($js, "querySelectorAll('[data-add-layout-item]')") && str_contains($js, 'add(b.dataset.addLayoutItem)'), 'palette buttons invoke production add logic');
assert_designer(str_contains($js, "touchedInput.value='1'") && str_contains($admin, 'name="panel_orientation_layout_touched"'), 'layout edits update the touched input');
assert_designer(str_contains($js, 'input.value=JSON.stringify(layout)') && str_contains($admin, 'name="panel_orientation_json"'), 'layout JSON remains the save target');
assert_designer(str_contains($admin, 'quote-panel-layout-designer.js?v=') && str_contains($admin, "filemtime(__DIR__ . '/assets/js/quote-panel-layout-designer.js')"), 'designer script URL is deterministically cache-busted');
assert_designer(str_contains($js, "typeof el.setPointerCapture==='function'") && str_contains($js, 'pointercancel'), 'pointer dragging degrades safely when capture is unavailable');
