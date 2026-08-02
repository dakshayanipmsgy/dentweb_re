<?php
declare(strict_types=1);
$root = dirname(__DIR__); $failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$manifest = json_decode((string)file_get_contents($root . '/employee-manifest.webmanifest'), true, 32, JSON_THROW_ON_ERROR);
$assert($manifest['name'] === 'Dakshayani Work', 'employee name');
$assert($manifest['id'] === './employee-app.php?source=installed-work', 'stable employee id');
$assert(str_starts_with($manifest['start_url'], './employee-tasks.php'), 'employee start URL');
$assert($manifest['scope'] === './' && count($manifest['shortcuts']) === 4, 'scope and shortcuts');
$expected = ['icon-192.png' => 192, 'icon-512.png' => 512, 'icon-maskable-192.png' => 192, 'icon-maskable-512.png' => 512];
foreach ($manifest['icons'] as $icon) {
    $name = basename($icon['src']);
    $assert(isset($expected[$name]), 'approved manifest icon path');
    $assert($icon['type'] === 'image/png', 'PNG manifest declaration');
    $assert($icon['sizes'] === $expected[$name] . 'x' . $expected[$name], 'manifest icon size');
}
foreach (['icon-source.svg', 'icon-maskable-source.svg', 'apple-touch-icon-source.svg'] as $source) {
    $text = (string)file_get_contents($root . '/assets/icons/employee/' . $source);
    $assert(str_contains($text, '<svg') && !str_contains($text, 'base64') && !preg_match('/<image\b/i', $text), 'text-only safe SVG source');
}
$temporary = sys_get_temp_dir() . '/dakshayani-icon-test-' . bin2hex(random_bytes(6));
try {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/generate-employee-pwa-icons.php') . ' --output-dir=' . escapeshellarg($temporary);
    exec($command, $output, $status); $assert($status === 0, 'temporary icon generation');
    $firstHashes = [];
    foreach ($expected + ['apple-touch-icon.png' => 180] as $name => $size) {
        $file = $temporary . '/' . $name; $info = @getimagesize($file);
        $assert(is_array($info) && $info[0] === $size && $info[1] === $size && ($info['mime'] ?? '') === 'image/png', 'generated PNG MIME/dimensions');
        $assert(@file_get_contents($file, false, null, 0, 8) === "\x89PNG\r\n\x1a\n", 'generated PNG signature');
        $firstHashes[$name] = hash_file('sha256', $file);
    }
    exec($command . ' --force', $regenerated, $regenerateStatus); $assert($regenerateStatus === 0, 'forced regeneration');
    foreach ($firstHashes as $name => $hash) $assert(hash_equals($hash, hash_file('sha256', $temporary . '/' . $name)), 'deterministic regeneration');
    exec($command . ' --check', $checked, $checkStatus); $assert($checkStatus === 0, 'generated deployment check');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) @unlink($file); @rmdir($temporary);
}
$general = json_decode((string)file_get_contents($root . '/manifest.webmanifest'), true);
$assert($general['name'] === 'Dakshayani Enterprises', 'general identity preserved');
$worker = (string)file_get_contents($root . '/service-worker.js');
foreach (["SAFE_URLS.has(url.href)", "cache:'no-store'", "request.mode==='navigate'", 'notificationclick', 'notification-open.php?id=', "addEventListener('push'"] as $needle) $assert(str_contains($worker, $needle), 'service worker: ' . $needle);
$assert(substr_count((string)file_get_contents($root . '/assets/js/pwa.js'), 'serviceWorker.register') === 1, 'single registration source');
$employeeJs = (string)file_get_contents($root . '/assets/js/employee-pwa.js');
$assert(strpos($employeeJs, 'Notification.requestPermission') > strpos($employeeJs, '[data-enable-push]'), 'permission only after click handler');
$bell = (string)file_get_contents($root . '/includes/notification_bell.php');
$assert(str_contains($bell, 'setAppBadge') && str_contains($bell, 'clearAppBadge'), 'badge integration');
$css = (string)file_get_contents($root . '/assets/css/pwa-shell.css');
foreach (['safe-area-inset-bottom', 'min-height:44px', 'overflow-x:hidden', '.employee-app-shell'] as $needle) $assert(str_contains($css, $needle), 'mobile CSS: ' . $needle);
if ($failures) { fwrite(STDERR, 'FAIL: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo "Employee PWA manifest, temporary icons, cache, push, badge and mobile checks passed.\n";
