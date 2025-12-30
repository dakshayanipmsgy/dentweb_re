<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/customer_admin.php';

function customer_portal_session(): void
{
    start_session();
}

function customer_portal_logout(): void
{
    customer_portal_session();
    $_SESSION['customer_logged_in'] = false;
    unset($_SESSION['customer_mobile']);
    unset($_SESSION['customer_name']);
    session_regenerate_id(true);
}

function customer_portal_require_login(): void
{
    customer_portal_session();
    if (empty($_SESSION['customer_logged_in']) || !isset($_SESSION['customer_mobile'])) {
        header('Location: login.php?login_type=customer');
        exit;
    }
}

function customer_portal_attempt_login(CustomerFsStore $store, string $mobile, string $password): array
{
    $mobileInput = trim($mobile);
    $passwordInput = (string) $password;

    if ($mobileInput === '' || $passwordInput === '') {
        return ['success' => false, 'message' => 'Invalid mobile or password.'];
    }

    $customer = $store->findByMobile($mobileInput);
    if ($customer === null) {
        return ['success' => false, 'message' => 'Invalid mobile or password.'];
    }

    $hash = $customer['password_hash'] ?? '';
    if (!is_string($hash) || trim($hash) === '') {
        return ['success' => false, 'message' => 'Password not set. Please contact support.'];
    }

    if (!password_verify($passwordInput, $hash)) {
        return ['success' => false, 'message' => 'Invalid mobile or password.'];
    }

    customer_portal_session();
    $_SESSION['customer_logged_in'] = true;
    $_SESSION['customer_mobile'] = $customer['mobile'] ?? $mobileInput;
    $_SESSION['customer_name'] = $customer['name'] ?? '';
    session_regenerate_id(true);

    return ['success' => true, 'customer' => $customer];
}

function customer_portal_fetch_customer(CustomerFsStore $store): ?array
{
    customer_portal_session();
    $mobile = isset($_SESSION['customer_mobile']) ? (string) $_SESSION['customer_mobile'] : '';
    if ($mobile === '') {
        return null;
    }

    return $store->findByMobile($mobile);
}

function customer_portal_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
