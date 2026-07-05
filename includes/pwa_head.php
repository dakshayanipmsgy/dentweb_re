<?php
declare(strict_types=1);

if (!function_exists('pwa_asset')) {
    function pwa_asset(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir === '/' || $scriptDir === '.') {
            $scriptDir = '';
        }
        // Build relative-to-current-script URLs so hosted subdirectories on cPanel
        // load the manifest, service worker helper, and icons from the same app root.
        return ($scriptDir === '' ? '' : rtrim($scriptDir, '/') . '/') . ltrim($path, '/');
    }
}

if (!defined('DAKSHAYANI_PWA_HEAD_PRINTED')):
    define('DAKSHAYANI_PWA_HEAD_PRINTED', true);
?>
<link rel="manifest" href="<?= htmlspecialchars(pwa_asset('manifest.webmanifest'), ENT_QUOTES) ?>">
<meta name="theme-color" content="#0f766e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Dakshayani">
<meta name="application-name" content="Dakshayani Enterprises">
<meta name="format-detection" content="telephone=no">
<link rel="icon" href="<?= htmlspecialchars(pwa_asset('images/favicon.ico'), ENT_QUOTES) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars(pwa_asset('assets/icons/app-icon.svg'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(pwa_asset('assets/css/pwa-shell.css'), ENT_QUOTES) ?>">
<script defer src="<?= htmlspecialchars(pwa_asset('assets/js/pwa.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
