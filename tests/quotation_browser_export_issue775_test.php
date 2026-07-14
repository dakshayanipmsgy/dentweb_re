<?php
$root = dirname(__DIR__);
require_once $root . '/includes/quotation_bulk_actions.php';
$bulk = file_get_contents($root . '/includes/quotation_bulk_actions.php');
$js = file_get_contents($root . '/assets/js/quotation-browser-export.js');
$admin = file_get_contents($root . '/admin-quotations.php');
$paginator = file_get_contents($root . '/assets/vendor/browser-export/paged.polyfill.min.js');
$ok = true;
$assert = function (bool $cond, string $msg) use (&$ok): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $ok = false; } };
$client = quotation_prepare_client_browser_export_html('<!doctype html><html><head></head><body><p>Quote</p></body></html>', 75);
$server = quotation_prepare_server_browser_pdf_html('<!doctype html><html><head></head><body><p>Quote</p></body></html>');
$assert(!str_contains($client, 'file://'), 'browser-client HTML contains no file base URL');
$assert(str_contains($server, 'file://'), 'server Chromium HTML retains file base URL behavior');
$assert(str_contains(str_replace('\\/', '/', $client), 'assets/vendor/browser-export/paged.polyfill.min.js'), 'client HTML contains same-origin paginator path');
$assert(str_contains($bulk, 'new URL(String(cfg.paginatorScript') && str_contains($bulk, 'window.location.href'), 'paginator URL resolves at web root and subdirectory deployments');
$assert(str_contains($bulk, 'script.onload=') && str_contains($bulk, 'script.onerror=') && strpos($bulk, 'script.onload=') < strpos($bulk, 'script.src='), 'script handlers are set before src');
$assert(str_contains($bulk, 'paginator_load_timeout') && str_contains($bulk, 'paginator_asset_load_failed'), 'script timeout and load failure are structured');
$assert(str_contains($bulk, 'paginator_api_mismatch'), 'API mismatch has dedicated code');
$assert(!str_contains($bulk, "__quotationPdfError='pagination_failed: "), 'iframe does not prefix string errors');
$assert(substr_count($js, "replace(/^pagination_failed") === 1, 'parent strips legacy duplicate prefix once');
foreach ([50,60,70,80,90,100] as $pct) { $assert(quotation_browser_export_normalize_scale($pct) === $pct, "$pct percent accepted"); }
$assert(quotation_browser_export_normalize_scale(49) === 50, 'below range normalized');
$assert(quotation_browser_export_normalize_scale(101) === 100, 'above range normalized');
$assert(quotation_browser_export_normalize_scale('abc') === 100, 'non numeric scale defaults safely');
$assert(str_contains($admin, 'Export content size') && str_contains($admin, 'quotationBrowserExportScale'), 'bulk UI exposes scale control');
$assert(str_contains($js, 'localStorage') && str_contains($js, 'export_scale_percent'), 'scale is remembered locally and posted to session');
$assert(str_contains($bulk, "'scale_percent'=>") && str_contains($bulk, 'quotation_browser_export_token_scale'), 'scale is bound to export token');
$assert(str_contains($bulk, 'quotation-export-scale-root') && str_contains($bulk, 'fontSize=scalePercent'), 'scale changes layout before pagination');
$assert(str_contains($paginator, 'DentwebPaginator') && str_contains($paginator, 'breakBefore') && str_contains($paginator, 'breakAfter') && str_contains($paginator, 'breakInside'), 'application paginator supports page break rules');
$assert(str_contains($paginator, 'W=794,H=1123') && str_contains($paginator, "height:'+H+'px"), 'A4 page dimensions are fixed');
$assert(!preg_match('/https?:\/\/(cdn|unpkg|jsdelivr)/i', $admin . $js . $bulk), 'no runtime CDN dependency introduced');
$assert(!str_contains($js . $bulk, 'new SimplePdfDocument'), 'client export path does not use SimplePdfDocument');
if (!$ok) { exit(1); }
echo "PASS: quotation browser export issue 775 coverage\n";
