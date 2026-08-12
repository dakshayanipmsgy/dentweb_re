<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/document_signature.php';

$path = safe_text((string)($_GET['path'] ?? ''));
$hash = strtolower(safe_text((string)($_GET['hash'] ?? '')));
if ($path === '' || str_contains($path, '..') || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
    http_response_code(404); exit;
}
$root = realpath(document_signature_root());
$file = realpath(document_signature_root() . '/' . ltrim($path, '/'));
if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)
    || filesize($file) > 5 * 1024 * 1024 || !hash_equals($hash, hash_file('sha256', $file))) {
    http_response_code(404); exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/png', 'image/jpeg'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file);
