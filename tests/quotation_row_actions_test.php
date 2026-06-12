<?php

declare(strict_types=1);

$mainSource = file_get_contents(__DIR__ . '/../admin-quotations.php');
$listSource = file_get_contents(__DIR__ . '/../admin/partials/quotation-list.php');
$source = is_string($mainSource) && is_string($listSource) ? $mainSource . "\n" . $listSource : false;
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read admin-quotations.php\n");
    exit(1);
}

$assertions = [
    'row actions keep the JavaScript-enhanced form class' => 'class="js-quote-action"',
    'row action forms keep CSRF fields' => 'name="csrf_token"',
    'Approve and Accept actions remain available to the backend' => "if (\$action === 'approve_quote' || \$action === 'accept_quote')",
    'Archive and unarchive actions remain available to the backend' => "if (\$action === 'archive_quote' || \$action === 'unarchive_quote')",
    'AJAX requests still include the CSRF-bearing FormData' => 'body:formData',
    'form action uses the action attribute instead of the shadowable form.action property' => "form.getAttribute('action')||window.location.href",
    'form action is resolved against the current page' => 'new URL(rawAction,window.location.href).href',
    'invalid action URLs fall back to the current page' => 'return window.location.href;',
    'action responses are read as text before parsing' => 'const text=await response.text();',
    'action responses require a JSON content type' => "!contentType.includes('json')",
    'action responses use guarded JSON parsing' => 'return JSON.parse(text);',
    'redirected or login responses get a session-expiry message' => 'Your session may have expired.',
    'malformed JSON gets a clear message' => 'the server returned malformed JSON.',
    'list refresh remains AJAX-driven' => "'X-Requested-With':'quotation-list'",
    'list refresh requests the quotation list partial' => "url.searchParams.set('partial','quotation_list')",
    'partial request exits before editor rendering' => "require __DIR__ . '/admin/partials/quotation-list.php';\n    exit;",
    'list partial safely normalizes calc' => "is_array(\$q['calc'] ?? null) ? \$q['calc'] : []",
    'list amount falls back to the stored inclusive total' => "\$calc['grand_total'] ?? \$q['input_total_gst_inclusive'] ?? 0",
    'row approve and accept use the shared status transition' => "documents_quote_apply_admin_status_transition(\$quote, \$targetStatus",
    'Bulk Tools approve and accept use the shared status transition' => 'documents_quote_apply_admin_status_transition(',
    'row Accept is offered only from the shared approved starting state' => "\$quoteStatusNorm === 'approved'",
    'row Accept and Approve share one backend handler' => "if (\$action === 'approve_quote' || \$action === 'accept_quote')",
    'list refresh uses a clean quotations URL' => "new URL('admin-quotations.php',window.location.href)",
    'list refresh uses the clean quotations URL' => 'fetch(quotationListUrl()',
    'clean refresh preserves active list filters' => "['tab','status_filter']",
];

foreach ($assertions as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

if (str_contains($source, 'fetch(form.action||window.location.href')) {
    fwrite(STDERR, "FAIL: unsafe shadowable form.action fetch remains\n");
    exit(1);
}
fwrite(STDOUT, "PASS: unsafe shadowable form.action fetch is absent\n");

$sharedHandlerStart = strpos($source, "if (\$action === 'approve_quote' || \$action === 'accept_quote')");
$archiveHandlerStart = strpos($source, "if (\$action === 'archive_quote' || \$action === 'unarchive_quote')", $sharedHandlerStart ?: 0);
$sharedHandler = ($sharedHandlerStart !== false && $archiveHandlerStart !== false)
    ? substr($source, $sharedHandlerStart, $archiveHandlerStart - $sharedHandlerStart)
    : '';
if (str_contains($sharedHandler, 'documents_quote_has_valid_acceptance_data')) {
    fwrite(STDERR, "FAIL: row Accept still has stricter acceptance-data validation\n");
    exit(1);
}
fwrite(STDOUT, "PASS: row Accept has no stricter acceptance-data validation\n");
