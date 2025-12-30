<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/employee_admin.php';

function employee_portal_session(): void
{
    start_session();
}

function employee_portal_logout(): void
{
    employee_portal_session();
    $_SESSION['employee_logged_in'] = false;
    unset($_SESSION['employee_id'], $_SESSION['employee_login_id'], $_SESSION['employee_name']);
    session_regenerate_id(true);
}

function employee_portal_require_login(): void
{
    employee_portal_session();
    if (empty($_SESSION['employee_logged_in']) || !isset($_SESSION['employee_id'])) {
        header('Location: login.php?login_type=employee');
        exit;
    }
}

function employee_portal_attempt_login(EmployeeFsStore $store, string $loginId, string $password): array
{
    $loginInput = trim($loginId);
    $passwordInput = (string) $password;

    if ($loginInput === '' || $passwordInput === '') {
        return ['success' => false, 'message' => 'Invalid login or password.'];
    }

    $employee = $store->findByLoginId($loginInput);
    if ($employee === null || ($employee['status'] ?? 'active') !== 'active') {
        return ['success' => false, 'message' => 'Invalid login or password.'];
    }

    $hash = $employee['password_hash'] ?? '';
    if (!is_string($hash) || trim($hash) === '') {
        return ['success' => false, 'message' => 'Password not set. Please contact admin.'];
    }

    if (!password_verify($passwordInput, $hash)) {
        return ['success' => false, 'message' => 'Invalid login or password.'];
    }

    employee_portal_session();
    $_SESSION['employee_logged_in'] = true;
    $_SESSION['employee_id'] = $employee['id'] ?? '';
    $_SESSION['employee_login_id'] = $employee['login_id'] ?? $loginInput;
    $_SESSION['employee_name'] = $employee['name'] ?? '';
    session_regenerate_id(true);

    return ['success' => true, 'employee' => $employee];
}

function employee_portal_current_employee(EmployeeFsStore $store): ?array
{
    employee_portal_session();
    $employeeId = isset($_SESSION['employee_id']) ? (string) $_SESSION['employee_id'] : '';
    if ($employeeId === '') {
        return null;
    }

    $employee = $store->findById($employeeId);
    if ($employee === null || ($employee['status'] ?? 'active') !== 'active') {
        employee_portal_logout();
        return null;
    }

    return $employee;
}
