<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

logout_user();
$scriptDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
$prefix = $basePath === '' ? '' : $basePath;
$loginUrl = ($prefix === '' ? '' : $prefix) . '/login.php';
header('Location: ' . $loginUrl);
exit;
