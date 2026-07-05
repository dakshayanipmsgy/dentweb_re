<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') { $scriptDir = ''; }
$basePath = rtrim($scriptDir, '/');
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$appUrl = $scheme . '://' . $host . ($basePath === '' ? '/' : $basePath . '/');
$asset = static fn(string $path): string => ($basePath === '' ? '' : $basePath) . '/' . ltrim($path, '/');
$exists = static fn(string $path): bool => is_file(__DIR__ . '/' . ltrim($path, '/'));
$sw = @file_get_contents(__DIR__ . '/service-worker.js') ?: '';
$cacheVersion = 'Not detected';
if (preg_match("/CACHE_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]/", $sw, $m)) { $cacheVersion = $m[1]; }
$requiredIcons = ['assets/icons/app-icon.svg', 'assets/icons/app-icon-maskable.svg'];
$privateCacheRules = [
    'Authenticated PHP navigations use network/no-store' => str_contains($sw, "request.mode === 'navigate'") && str_contains($sw, 'noStoreFetch(request)'),
    'POST responses are not cached' => str_contains($sw, "request.method !== 'GET'"),
    'Dashboards and role pages are treated as private' => str_contains($sw, '/dashboard/i') && str_contains($sw, 'admin') && str_contains($sw, 'customer') && str_contains($sw, 'employee'),
    'Business documents are excluded from cache' => str_contains($sw, 'quotation|agreement|dispatch|challan|invoice|receipt'),
    'Generated documents/uploads/API routes are excluded' => str_contains($sw, 'download|storage') && str_contains($sw, 'uploads') && str_contains($sw, 'api\/'),
];
$checks = [
    'Manifest loads' => $exists('manifest.webmanifest'),
    'Service worker file exists' => $exists('service-worker.js'),
    'Offline fallback exists' => $exists('offline.html'),
    'Install help page exists' => $exists('app-install-help.php'),
    'Privacy policy exists' => $exists('privacy-policy.php'),
    'Terms page exists' => $exists('terms.php'),
    'HTTPS detected' => $scheme === 'https',
    'SVG app icons exist' => count(array_filter($requiredIcons, $exists)) === count($requiredIcons),
    'Current path/base path detected' => $appUrl !== '',
    'Cache version shown' => $cacheVersion !== 'Not detected',
    'Private pages excluded from service worker cache according to rules' => !in_array(false, $privateCacheRules, true),
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>PWA Diagnostics | Dakshayani Admin</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($asset('assets/css/admin-unified.css'), ENT_QUOTES) ?>">
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
  <style>.diag{max-width:1040px;margin:2rem auto;padding:1rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;box-shadow:0 12px 30px rgba(15,23,42,.06)}.ok{color:#047857;font-weight:800}.bad{color:#b91c1c;font-weight:800}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}.list{margin:.5rem 0 0;padding-left:1.2rem;line-height:1.7}.top{display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap}</style>
</head>
<body class="admin-shell"><main class="diag">
  <div class="top"><div><p class="admin-muted">Admin setup/testing only</p><h1>PWA Diagnostics</h1></div><div style="display:flex;gap:.5rem;flex-wrap:wrap"><a class="btn btn-ghost" href="<?= htmlspecialchars($asset('app-install-help.php'), ENT_QUOTES) ?>">Need help installing the app?</a><a class="btn btn-ghost" href="<?= htmlspecialchars($asset('admin-dashboard.php'), ENT_QUOTES) ?>">Back to dashboard</a></div></div>
  <section class="grid" aria-label="PWA diagnostics">
    <div class="card"><h2>Files</h2><p>Manifest: <span class="<?= $exists('manifest.webmanifest') ? 'ok' : 'bad' ?>"><?= $exists('manifest.webmanifest') ? 'Exists' : 'Missing' ?></span></p><p>Service worker: <span class="<?= $exists('service-worker.js') ? 'ok' : 'bad' ?>"><?= $exists('service-worker.js') ? 'Exists' : 'Missing' ?></span></p><p>Offline fallback: <span class="<?= $exists('offline.html') ? 'ok' : 'bad' ?>"><?= $exists('offline.html') ? 'Exists' : 'Missing' ?></span></p></div>
    <div class="card"><h2>Paths</h2><p>App URL/base path</p><p class="mono"><?= htmlspecialchars($appUrl, ENT_QUOTES) ?></p><p>Manifest path</p><p class="mono"><?= htmlspecialchars($asset('manifest.webmanifest'), ENT_QUOTES) ?></p><p>Service worker registration script path</p><p class="mono"><?= htmlspecialchars($asset('service-worker.js'), ENT_QUOTES) ?></p></div>
    <div class="card"><h2>Security & version</h2><p>HTTPS: <span class="<?= $scheme === 'https' ? 'ok' : 'bad' ?>"><?= $scheme === 'https' ? 'Detected' : 'Not detected' ?></span></p><p>Cache version name</p><p class="mono"><?= htmlspecialchars($cacheVersion, ENT_QUOTES) ?></p></div>
    <div class="card"><h2>Required SVG icons</h2><ul class="list"><?php foreach ($requiredIcons as $icon): ?><li><span class="<?= $exists($icon) ? 'ok' : 'bad' ?>"><?= $exists($icon) ? 'Exists' : 'Missing' ?></span> <span class="mono"><?= htmlspecialchars($icon, ENT_QUOTES) ?></span></li><?php endforeach; ?></ul></div>
  </section>
  <section class="card" style="margin-top:1rem"><h2>Admin PWA smoke test checklist</h2><ul class="list"><?php foreach ($checks as $label => $pass): ?><li><span class="<?= $pass ? 'ok' : 'bad' ?>"><?= $pass ? 'Pass' : 'Needs attention' ?></span> — <?= htmlspecialchars($label, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
  <section class="card" style="margin-top:1rem"><h2>Private-cache exclusion checks</h2><p class="admin-muted">These checks inspect <span class="mono">service-worker.js</span> for the release rules that keep private pages and documents out of Cache Storage.</p><ul class="list"><?php foreach ($privateCacheRules as $label => $pass): ?><li><span class="<?= $pass ? 'ok' : 'bad' ?>"><?= $pass ? 'Pass' : 'Needs attention' ?></span> — <?= htmlspecialchars($label, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
  <section class="card" style="margin-top:1rem"><h2>Manual release reminders</h2><ul class="list"><li>Use Chrome DevTools Application panel to verify only safe static assets are cached.</li><li>Share the public install help page with users who need install steps: <span class="mono"><?= htmlspecialchars($asset('app-install-help.php'), ENT_QUOTES) ?></span></li><li>Test from both domain root and a cPanel subdirectory before inviting users.</li><li>Follow <span class="mono">docs/pwa-release-test-plan.md</span> before rollout.</li></ul></section>
</main></body></html>
