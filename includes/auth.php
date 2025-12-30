<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function start_session(): void
{
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure' => $secure,
        ]);
    }

    $now = time();
    $timeoutMinutes = 45;
    try {
        $timeoutMinutes = get_session_timeout_minutes(get_db());
    } catch (Throwable $exception) {
        $fallback = (int) ($_SESSION['session_policy_timeout'] ?? 45);
        if ($fallback >= 15 && $fallback <= 720) {
            $timeoutMinutes = $fallback;
        }
    }

    $timeoutSeconds = $timeoutMinutes * 60;
    $lastActivity = isset($_SESSION['session_last_activity']) ? (int) $_SESSION['session_last_activity'] : $now;

    if (!empty($_SESSION['user']) && $timeoutSeconds > 0 && ($now - $lastActivity) >= $timeoutSeconds) {
        $_SESSION = [];
        session_unset();
        session_destroy();
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure' => $secure,
        ]);
        $lastActivity = $now;
    }

    $_SESSION['session_policy_timeout'] = $timeoutMinutes;
    $_SESSION['session_last_activity'] = $now;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function is_database_accessible(): bool
{
    try {
        get_db();
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function enforce_offline_session_validity(): void
{
    start_session();

    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['offline_mode'])) {
        return;
    }

    if (!is_database_accessible()) {
        return;
    }

    unset($_SESSION['user']);
    $_SESSION['offline_session_invalidated'] = true;
    session_regenerate_id(true);

    header('Location: login.php');
    exit;
}

function require_login(): void
{
    start_session();
    enforce_offline_session_validity();
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();
    $user = $_SESSION['user'] ?? null;
    if (!$user || ($user['role_name'] ?? null) !== $role) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function require_admin(): void
{
    start_session();
    enforce_offline_session_validity();

    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        header('Location: login.php');
        exit;
    }

    if (($user['role_name'] ?? '') === 'admin') {
        return;
    }

    $redirect = 'login.php';
    if (($user['role_name'] ?? '') === 'employee') {
        $redirect = 'employee-dashboard.php';
    }

    set_flash('warning', 'Administrator permissions are required to access that workspace.');
    header('Location: ' . $redirect);
    exit;
}

function current_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

function set_flash(string $type, string $message): void
{
    start_session();

    $allowed = ['success', 'info', 'warning', 'error'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => trim($message),
    ];
}

function consume_flash(): ?array
{
    start_session();
    $flash = $_SESSION['flash'] ?? null;
    if (!is_array($flash)) {
        return null;
    }

    unset($_SESSION['flash']);

    $type = is_string($flash['type'] ?? null) ? $flash['type'] : 'info';
    $message = is_string($flash['message'] ?? null) ? $flash['message'] : '';

    return [
        'type' => $type,
        'message' => $message,
    ];
}

function client_ip_address(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        if (str_contains($candidate, ',')) {
            $parts = explode(',', $candidate);
            $candidate = $parts[0] ?? $candidate;
        }

        $candidate = trim($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '0.0.0.0';
}

function verify_csrf_token(?string $token): bool
{
    start_session();
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function normalize_customer_mobile(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) {
        return '';
    }

    $digits = trim($digits);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

function ensure_api_access(string $requiredRole = 'admin'): void
{
    start_session();
    enforce_offline_session_validity();
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    if (($requiredRole !== '') && (($_SESSION['user']['role_name'] ?? '') !== $requiredRole)) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }
}

function authenticate_user(string $identifier, string $password, string $roleName): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return null;
    }

    try {
        $store = user_store();
    } catch (Throwable $storeError) {
        return authenticate_user_fallback($identifier, $password, $roleName, $storeError);
    }

    try {
        $user = $store->findByLoginIdentifier($identifier, $roleName);
    } catch (Throwable $storeError) {
        return authenticate_user_fallback($identifier, $password, $roleName, $storeError);
    }

    if (!$user) {
        return null;
    }

    if (($user['status'] ?? 'inactive') !== 'active') {
        return null;
    }

    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }

    try {
        $updated = $store->recordLogin((int) $user['id']);
        if (is_array($updated)) {
            $user = $updated;
        }
    } catch (Throwable $recordError) {
        $store->appendAudit([
            'event' => 'login_metadata_failed',
            'user_id' => (int) ($user['id'] ?? 0),
            'role' => $user['role'] ?? $roleName,
            'message' => 'Unable to record last login timestamp.',
            'error' => $recordError->getMessage(),
        ]);
    }

    $result = [
        'id' => (int) ($user['id'] ?? 0),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'username' => (string) ($user['username'] ?? ($user['email'] ?? '')),
        'role_name' => (string) ($user['role'] ?? $roleName),
        'status' => (string) ($user['status'] ?? 'inactive'),
        'permissions_note' => (string) ($user['permissions_note'] ?? ''),
        'password_hash' => $hash,
        'last_login_at' => $user['last_login_at'] ?? null,
        'password_last_set_at' => $user['password_last_set_at'] ?? null,
        'offline_mode' => false,
    ];

    try {
        $db = get_db();
        try {
            portal_log_action(
                $db,
                (int) $result['id'],
                'login',
                'user',
                (int) $result['id'],
                sprintf(
                    'User %s logged in to the %s portal',
                    $result['email'] !== '' ? $result['email'] : $result['username'],
                    $roleName
                )
            );
        } catch (Throwable $logError) {
            $store->appendAudit([
                'event' => 'login_audit_fallback',
                'user_id' => (int) $result['id'],
                'role' => $result['role_name'],
                'message' => 'Database audit log unavailable for login event.',
                'error' => $logError->getMessage(),
            ]);
        }
    } catch (Throwable $dbError) {
        $store->appendAudit([
            'event' => 'login_audit_skipped',
            'user_id' => (int) $result['id'],
            'role' => $result['role_name'],
            'message' => 'Login recorded without database access.',
            'error' => $dbError->getMessage(),
        ]);
    }

    return $result;
}

function find_account_profile(string $identifier): ?array
{
    $normalized = trim($identifier);
    if ($normalized === '') {
        return null;
    }

    $storeFailure = null;
    try {
        $store = user_store();
        $record = $store->findByIdentifier($normalized);
        if (is_array($record)) {
            return [
                'id' => (int) ($record['id'] ?? 0),
                'full_name' => (string) ($record['full_name'] ?? ''),
                'email' => (string) ($record['email'] ?? ''),
                'username' => (string) ($record['username'] ?? ($record['email'] ?? '')),
                'status' => (string) ($record['status'] ?? 'inactive'),
                'role_name' => (string) ($record['role'] ?? ''),
                'offline_mode' => false,
            ];
        }
    } catch (Throwable $storeError) {
        $storeFailure = $storeError;
    }

    if ($storeFailure === null) {
        return null;
    }

    $offlineAccount = resolve_offline_account($storeFailure);
    if ($offlineAccount === null) {
        return null;
    }

    $matchesEmail = strcasecmp($offlineAccount['email'], $normalized) === 0;
    $username = $offlineAccount['username'] ?? null;
    $matchesUsername = is_string($username) && strcasecmp($username, $normalized) === 0;
    if (!$matchesEmail && !$matchesUsername) {
        return null;
    }

    return [
        'id' => (int) ($offlineAccount['id'] ?? 0),
        'full_name' => $offlineAccount['name'] ?? 'Primary Administrator',
        'email' => $offlineAccount['email'],
        'username' => $offlineAccount['username'] ?? $offlineAccount['email'],
        'status' => 'active',
        'role_name' => $offlineAccount['role'],
        'offline_mode' => true,
    ];
}

function authenticate_user_fallback(string $identifier, string $password, string $roleName, Throwable $reason): ?array
{
    $account = resolve_offline_account($reason);
    if ($account === null) {
        return null;
    }

    $normalized = trim($identifier);
    $emailMatches = strcasecmp($account['email'], $normalized) === 0;
    $username = $account['username'] ?? null;
    $usernameMatches = is_string($username) && strcasecmp($username, $normalized) === 0;
    $mobileMatches = false;
    if ($roleName === 'customer') {
        $inputMobile = normalize_customer_mobile($normalized);
        $storedMobile = is_string($username) ? normalize_customer_mobile($username) : '';
        if ($inputMobile !== '' && $storedMobile !== '' && hash_equals($storedMobile, $inputMobile)) {
            $mobileMatches = true;
        }
    }

    if (!$emailMatches && !$usernameMatches && !$mobileMatches) {
        return null;
    }

    if ($roleName !== $account['role']) {
        return null;
    }

    if (!verify_offline_password($password, $account)) {
        return null;
    }

    return [
        'id' => $account['id'],
        'full_name' => $account['name'],
        'email' => $account['email'],
        'username' => $account['username'] ?? $account['email'],
        'role_name' => $account['role'],
        'status' => 'active',
        'permissions_note' => 'Offline administrator access granted while the database is unavailable.',
        'offline_mode' => true,
    ];
}

function resolve_offline_account(?Throwable $reason = null): ?array
{
    static $account = null;
    if ($account !== null) {
        return $account;
    }

    if (is_offline_access_disabled()) {
        if ($reason !== null) {
            error_log('Offline authentication is disabled. Database error: ' . $reason->getMessage());
        } else {
            error_log('Offline authentication is disabled.');
        }
        $account = null;
        return $account;
    }

    static $hasLogged = false;
    if (!$hasLogged) {
        if ($reason !== null) {
            error_log('Database unavailable for authentication: ' . $reason->getMessage() . ' — enabling offline administrator access.');
        } else {
            error_log('Database unavailable for authentication — enabling offline administrator access.');
        }
        $hasLogged = true;
    }

    $default = [
        'id' => 0,
        'name' => 'Primary Administrator',
        'email' => 'admin@dakshayani.in',
        'username' => 'admin',
        'role' => 'admin',
        'password_hash' => '$2y$12$TvquhYdWBtKSPQ56kB4S1OTeNntaEv8QE9Woq2SPKkuuFVr4dMy/q',
        'password_plain' => null,
    ];

    $overrides = [
        'email' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_EMAIL'] ?? null,
            $_SERVER['FALLBACK_ADMIN_EMAIL'] ?? null,
            getenv('FALLBACK_ADMIN_EMAIL') ?: null,
        ]),
        'name' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_NAME'] ?? null,
            $_SERVER['FALLBACK_ADMIN_NAME'] ?? null,
            getenv('FALLBACK_ADMIN_NAME') ?: null,
        ]),
        'role' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_ROLE'] ?? null,
            $_SERVER['FALLBACK_ADMIN_ROLE'] ?? null,
            getenv('FALLBACK_ADMIN_ROLE') ?: null,
        ]),
        'username' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_USERNAME'] ?? null,
            $_SERVER['FALLBACK_ADMIN_USERNAME'] ?? null,
            getenv('FALLBACK_ADMIN_USERNAME') ?: null,
        ]),
        'password_hash' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_PASSWORD_HASH'] ?? null,
            $_SERVER['FALLBACK_ADMIN_PASSWORD_HASH'] ?? null,
            getenv('FALLBACK_ADMIN_PASSWORD_HASH') ?: null,
        ]),
        'password_plain' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_PASSWORD'] ?? null,
            $_SERVER['FALLBACK_ADMIN_PASSWORD'] ?? null,
            getenv('FALLBACK_ADMIN_PASSWORD') ?: null,
        ]),
        'id' => collect_first_non_empty([
            $_ENV['FALLBACK_ADMIN_ID'] ?? null,
            $_SERVER['FALLBACK_ADMIN_ID'] ?? null,
            getenv('FALLBACK_ADMIN_ID') ?: null,
        ]),
    ];

    if (is_string($overrides['email']) && filter_var($overrides['email'], FILTER_VALIDATE_EMAIL)) {
        $default['email'] = $overrides['email'];
    }

    if (is_string($overrides['name']) && trim($overrides['name']) !== '') {
        $default['name'] = trim($overrides['name']);
    }

    if (is_string($overrides['role']) && trim($overrides['role']) !== '') {
        $default['role'] = trim($overrides['role']);
    }

    if (is_string($overrides['username']) && trim($overrides['username']) !== '') {
        $default['username'] = trim($overrides['username']);
    }

    if (is_string($overrides['password_hash']) && trim($overrides['password_hash']) !== '') {
        $default['password_hash'] = trim($overrides['password_hash']);
    }

    if (is_string($overrides['password_plain']) && trim($overrides['password_plain']) !== '') {
        $default['password_plain'] = trim($overrides['password_plain']);
    }

    if (is_string($overrides['id']) && trim($overrides['id']) !== '' && is_numeric($overrides['id'])) {
        $default['id'] = (int) $overrides['id'];
    }

    $account = $default;

    return $account;
}

function collect_first_non_empty(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $candidate = trim($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function verify_offline_password(string $password, array $account): bool
{
    if (isset($account['password_hash']) && is_string($account['password_hash']) && $account['password_hash'] !== '') {
        if (@password_verify($password, $account['password_hash'])) {
            return true;
        }
    }

    if (isset($account['password_plain']) && is_string($account['password_plain']) && $account['password_plain'] !== '') {
        return hash_equals($account['password_plain'], $password);
    }

    return false;
}

function is_offline_access_disabled(): bool
{
    $candidates = [
        $_ENV['FALLBACK_ADMIN_DISABLE'] ?? null,
        $_SERVER['FALLBACK_ADMIN_DISABLE'] ?? null,
        getenv('FALLBACK_ADMIN_DISABLE') ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $normalized = strtolower(trim($candidate));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
    }

    return false;
}

function logout_user(): void
{
    start_session();
    unset(
        $_SESSION['customer_logged_in'],
        $_SESSION['customer_mobile'],
        $_SESSION['customer_name'],
        $_SESSION['employee_logged_in'],
        $_SESSION['employee_id'],
        $_SESSION['employee_login_id'],
        $_SESSION['employee_name'],
        $_SESSION['admin_logged_in']
    );
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
    ]);
    session_regenerate_id(true);
    $_SESSION['session_policy_timeout'] = 45;
    $_SESSION['session_last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => 'You have been signed out securely.',
    ];
}
