<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();

http_response_code(410);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PDF Disabled</title></head>
<body style="font-family:Arial,sans-serif;padding:24px">
<h1>PDF generation disabled</h1>
<p>PDF generation disabled. Use Print HTML.</p>
<p><a href="javascript:history.back()">Go back</a></p>
</body></html>
