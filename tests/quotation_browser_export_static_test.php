<?php
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/admin-quotations.php');
$bulk = file_get_contents($root . '/includes/quotation_bulk_actions.php');
$js = file_get_contents($root . '/assets/js/quotation-browser-export.js');
$manifest = require $root . '/includes/quotation_browser_manifest.php';
$doc = file_get_contents($root . '/docs/quotation-browser-pdf-export.md');
$ok = true;
$assert = function (bool $cond, string $msg) use (&$ok): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $ok = false; } };
$assert(str_contains($admin, "role_name'] ?? '') !== 'admin'"), 'admin-only export endpoint');
$assert(str_contains($admin, "quotation_browser_export_session") && str_contains($admin, 'verify_csrf_token'), 'CSRF protected export session creation');
$assert(str_contains($bulk, 'QUOTATION_BROWSER_EXPORT_TOKEN_TTL') && str_contains($bulk, 'quotation_browser_export_token_ids') && str_contains($bulk, 'hash_equals'), 'session-bound expiring token validation');
$assert(str_contains($bulk, 'quotation_bulk_normalize_selected_ids'), 'ID normalization/deduplication is reused');
$assert(str_contains($bulk, 'quotation_bulk_pdf_filename'), 'safe suggested filenames');
$assert(str_contains($admin, 'quotation_browser_export_render') && str_contains($admin, 'documents_get_quote($id)'), 'render endpoint resolves quotations through documents_get_quote');
$assert(str_contains($bulk, 'quotation_render_to_html($quote') && str_contains($bulk, "'browser-client-export'"), 'shared renderer browser mode');
$assert(!str_contains($bulk, 'SimplePdfDocument'), 'client export path does not use SimplePdfDocument');
$assert(str_contains($js, 'for(let i=0;i<session.items.length;i++)'), 'sequential processing');
$assert(str_contains($js, 'zipSync(files') && str_contains($js, 'downloadBlob(new Blob([files'), 'one PDF and multi ZIP handling');
$assert(str_contains($js, 'frame.remove()') && str_contains($js, 'URL.revokeObjectURL') && str_contains($js, 'canvas.width=canvas.height=0'), 'iframe/canvas/blob cleanup');
$assert(str_contains($js, 'READY_TIMEOUT') && str_contains($js, 'd.fonts') && str_contains($js, "$$('img'"), 'readiness timeout and font/image readiness');
$assert(str_contains($js, 'Unsupported browser or missing local export library'), 'unsupported browser handling');
$assert(str_contains($admin, 'Download using browser') && str_contains($admin, 'Using browser PDF/ZIP export'), 'forced browser-export UI and status');
$assert(str_contains($admin, 'Managed Install/Repair PDF engine is disabled') && str_contains($bulk, 'quotation_browser_managed_install_available'), 'managed install disabled for invalid checksum');
foreach (($manifest['packages'] ?? []) as $pkg) { $assert((string)($pkg['sha256'] ?? '') === str_repeat('0', 64), 'test fixture confirms all-zero checksum defect'); }
$assert(!preg_match('/https?:\/\/(cdn|unpkg|jsdelivr)/i', $admin . $js), 'no runtime CDN dependency in export path');
$assert(str_contains($doc, '10 quotations') && str_contains($doc, 'privacy') || str_contains(strtolower($doc), 'privacy'), 'documentation includes batch/privacy behavior');
if (!$ok) { exit(1); }
echo "PASS: quotation browser export static coverage\n";
