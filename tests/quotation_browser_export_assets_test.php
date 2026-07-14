<?php
$root = dirname(__DIR__);
require_once $root . '/includes/quotation_bulk_actions.php';
$manifestPath = $root . '/assets/vendor/browser-export/integrity-manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true);
$ok = true;
$assert = function (bool $cond, string $msg) use (&$ok): void { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $ok = false; } };
$assert(is_array($manifest['assets'] ?? null), 'integrity manifest exists');
$expected = ['paged.polyfill.min.js','html2canvas.min.js','jspdf.umd.min.js','fflate.min.js'];
foreach ($expected as $file) {
    $asset = null;
    foreach ($manifest['assets'] as $row) { if (($row['file'] ?? '') === $file) { $asset = $row; break; } }
    $path = $root . '/assets/vendor/browser-export/' . $file;
    $assert(is_array($asset), "$file listed in manifest");
    $assert(is_file($path) && is_readable($path), "$file exists and readable");
    $assert(filesize($path) > 10000, "$file is not placeholder-only");
    $assert(filesize($path) === (int)($asset['bytes'] ?? -1), "$file byte size matches manifest");
    $assert(hash_file('sha256', $path) === ($asset['sha256'] ?? ''), "$file sha256 matches manifest");
}
$status = quotation_browser_export_asset_status($root . '/assets/vendor/browser-export');
$assert(($status['ok'] ?? false) === true && ($status['message'] ?? '') === 'Browser PDF/ZIP exporter ready', 'server asset readiness helper reports ready');
$admin = file_get_contents($root . '/admin-quotations.php');
$assert(str_contains($admin, 'assets/vendor/browser-export/paged.polyfill.min.js') && str_contains($admin, 'assets/vendor/browser-export/fflate.min.js'), 'Bulk Tools page loads local libraries');
$assert(!preg_match('/https?:\/\/(cdn|unpkg|jsdelivr)/i', $admin), 'Bulk Tools page has no CDN runtime dependency');
if (!$ok) { exit(1); }
echo "PASS: quotation browser export asset coverage\n";
