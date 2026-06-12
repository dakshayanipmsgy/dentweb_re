<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../admin-quotations.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read admin-quotations.php\n");
    exit(1);
}

$assertions = [
    'row actions keep the JavaScript-enhanced form class' => 'class="js-quote-action"',
    'row action forms keep CSRF fields' => 'name="csrf_token"',
    'Approve action remains available to the backend' => "if (\$action === 'approve_quote')",
    'Accept action remains available to the backend' => "if (\$action === 'accept_quote')",
    'Archive action remains available to the backend' => "if (\$action === 'archive_quote')",
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
