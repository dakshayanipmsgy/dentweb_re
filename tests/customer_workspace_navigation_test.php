<?php
declare(strict_types=1);
function current_user(): ?array { return null; }
$_SESSION = ['customer_logged_in' => true, 'customer_mobile' => '9000000000'];
$_SERVER['REQUEST_URI'] = '/customer-dashboard.php';
ob_start(); require __DIR__ . '/../includes/mobile_app_nav.php'; $html = ob_get_clean();
if (substr_count($html, 'aria-current="page"') !== 1 || !str_contains($html, 'customer-app-nav') || str_contains($html, 'admin-dashboard')) throw new RuntimeException('Customer navigation must have exactly one active page and no staff links');
$_SESSION = [];
ob_start(); require __DIR__ . '/../includes/mobile_app_nav.php'; $html = ob_get_clean();
if ($html !== '') throw new RuntimeException('Signed-out session must not gain navigation');
echo "customer_workspace_navigation_test passed\n";
