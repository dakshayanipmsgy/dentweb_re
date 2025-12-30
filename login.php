<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/employee_portal.php';

if (!isset($bootstrapError) || !is_string($bootstrapError)) {
    $bootstrapError = '';
}

$supportEmail = resolve_admin_email();

start_session();

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
$flashIcons = [
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'error' => 'fa-circle-exclamation',
    'info' => 'fa-circle-info',
];
$flashIcon = $flashIcons[$flashTone] ?? 'fa-circle-info';
if (is_array($flashData)) {
    if (isset($flashData['message']) && is_string($flashData['message'])) {
        $flashMessage = trim($flashData['message']);
    }
    if (isset($flashData['type']) && is_string($flashData['type'])) {
        $candidateTone = strtolower($flashData['type']);
        if (isset($flashIcons[$candidateTone])) {
            $flashTone = $candidateTone;
            $flashIcon = $flashIcons[$candidateTone];
        }
    }
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
$prefix = $basePath === '' ? '' : $basePath;
$routeFor = static function (string $path) use ($prefix): string {
    $clean = ltrim($path, '/');
    return ($prefix === '' ? '' : $prefix) . '/' . $clean;
};

$allowedRoles = ['admin', 'customer', 'employee'];
$roleRoutes = [
    'admin' => $routeFor('admin-dashboard.php'),
    'customer' => $routeFor('customer-dashboard.php'),
    'employee' => $routeFor('employee-dashboard.php'),
];
$roleLabel = static function (string $role): string {
    $clean = trim(str_replace(['_', '-'], ' ', strtolower($role)));
    return $clean === '' ? 'Selected' : ucwords($clean);
};

$error = $bootstrapError;
$success = '';
$recoveryError = '';
$recoverySuccess = '';
$rateLimitMessage = '';

$selectedRole = 'admin';
$submittedRole = '';
if (isset($_POST['login_type']) && is_string($_POST['login_type'])) {
    $submittedRole = strtolower(trim($_POST['login_type']));
} elseif (isset($_POST['role']) && is_string($_POST['role'])) {
    $submittedRole = strtolower(trim($_POST['role']));
} elseif (isset($_GET['login_type']) && is_string($_GET['login_type'])) {
    $submittedRole = strtolower(trim($_GET['login_type']));
} elseif (isset($_GET['role']) && is_string($_GET['role'])) {
    $submittedRole = strtolower(trim($_GET['role']));
}

$isRoleValid = $submittedRole === '' || in_array($submittedRole, $allowedRoles, true);
if ($isRoleValid && $submittedRole !== '') {
    $selectedRole = $submittedRole;
}

$identifierValue = '';
if (isset($_POST['email']) && is_string($_POST['email'])) {
    $identifierValue = trim($_POST['email']);
}

$passwordValue = '';
if (isset($_POST['password']) && is_string($_POST['password'])) {
    $passwordValue = (string) $_POST['password'];
}

$identifierLabelText = 'Email ID';
$identifierPlaceholder = 'you@example.com';
$identifierType = 'email';
$identifierInputMode = 'email';
$identifierAutocomplete = 'email';
$roleHint = 'Administrators must use the credentials issued by Dakshayani Enterprises.';

if ($selectedRole === 'customer') {
    $identifierLabelText = 'Mobile number';
    $identifierPlaceholder = 'Enter 10-digit mobile';
    $identifierType = 'tel';
    $identifierInputMode = 'numeric';
    $identifierAutocomplete = 'tel';
    $roleHint = 'Customers should sign in with their registered mobile number and password.';
} elseif ($selectedRole === 'employee') {
    $identifierLabelText = 'Login ID';
    $identifierPlaceholder = 'Enter login ID';
    $identifierType = 'text';
    $identifierInputMode = 'text';
    $identifierAutocomplete = 'username';
    $roleHint = 'Employees should use their assigned login ID and password.';
}

$intent = 'login';
if (isset($_POST['intent']) && is_string($_POST['intent'])) {
    $candidateIntent = strtolower(trim($_POST['intent']));
    if (in_array($candidateIntent, ['login', 'recover'], true)) {
        $intent = $candidateIntent;
    }
}

$validateRecoveryPassword = static function (string $password): ?string {
    if (strlen($password) < 12) {
        return 'Choose a password with at least 12 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Include at least one uppercase letter in the new password.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Include at least one lowercase letter in the new password.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Include at least one number in the new password.';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'Include at least one special character in the new password.';
    }

    return null;
};

if (!empty($_SESSION['offline_session_invalidated'])) {
    $error = 'Your emergency administrator session ended because the secure database is available again. Please sign in using your standard credentials.';
    unset($_SESSION['offline_session_invalidated']);
}

$ipAddress = client_ip_address();

$db = null;
$loginPolicy = [
    'retry_limit' => 5,
    'lockout_minutes' => 30,
    'session_timeout' => 45,
];

try {
    $db = get_db();
    $loginPolicy = get_login_policy($db);
} catch (Throwable $dbInitialisationError) {
    $db = null;
}

$recoverySecretConfigured = admin_recovery_secret() !== '';
$recoveryAvailable = $db instanceof PDO ? is_admin_recovery_available($db) : false;

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($requestMethod === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        if ($intent === 'recover') {
            $recoveryError = 'Your session expired. Please refresh and try again.';
        } else {
            $error = 'Your session expired. Please refresh and try again.';
        }
    } elseif ($bootstrapError !== '') {
        if ($intent === 'recover') {
            $recoveryError = $bootstrapError;
        } else {
            $error = $bootstrapError;
        }
    } elseif ($intent === 'recover') {
        if (!$recoverySecretConfigured) {
            $recoveryError = 'Emergency recovery is disabled on this server.';
        } elseif (!$db instanceof PDO) {
            $recoveryError = 'Emergency recovery requires the secure database to be online. Please try again once the database connection is restored.';
        } elseif (!$recoveryAvailable) {
            $recoveryError = 'Emergency recovery is unavailable because at least one administrator account remains active.';
        } else {
            $secretInput = isset($_POST['recovery_secret']) && is_string($_POST['recovery_secret']) ? trim($_POST['recovery_secret']) : '';
            $newPassword = isset($_POST['recovery_password']) && is_string($_POST['recovery_password']) ? (string) $_POST['recovery_password'] : '';
            $confirmPassword = isset($_POST['recovery_password_confirm']) && is_string($_POST['recovery_password_confirm']) ? (string) $_POST['recovery_password_confirm'] : '';

            if ($secretInput === '') {
                $recoveryError = 'Enter the recovery secret provided in the environment configuration.';
            } elseif (!hash_equals(admin_recovery_secret(), $secretInput)) {
                $recoveryError = 'The recovery secret was incorrect.';
                record_system_audit(
                    $db,
                    'admin_recovery_failed',
                    'security',
                    0,
                    sprintf('Invalid recovery secret attempt from %s', mask_ip_for_log($ipAddress))
                );
            } elseif ($newPassword !== $confirmPassword) {
                $recoveryError = 'The confirmation password did not match.';
            } else {
                $passwordIssue = $validateRecoveryPassword($newPassword);
                if ($passwordIssue !== null) {
                    $recoveryError = $passwordIssue;
                } else {
                    $recoveryEmailCandidates = [
                        $_ENV['ADMIN_RECOVERY_EMAIL'] ?? null,
                        $_SERVER['ADMIN_RECOVERY_EMAIL'] ?? null,
                        getenv('ADMIN_RECOVERY_EMAIL') ?: null,
                    ];
                    $recoveryEmail = 'd.entranchi@gmail.com';
                    foreach ($recoveryEmailCandidates as $candidateEmail) {
                        if (is_string($candidateEmail)) {
                            $candidateEmail = trim($candidateEmail);
                            if ($candidateEmail !== '' && filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                                $recoveryEmail = $candidateEmail;
                                break;
                            }
                        }
                    }

                    $recoveryNameCandidates = [
                        $_ENV['ADMIN_RECOVERY_NAME'] ?? null,
                        $_SERVER['ADMIN_RECOVERY_NAME'] ?? null,
                        getenv('ADMIN_RECOVERY_NAME') ?: null,
                    ];
                    $recoveryName = 'Primary Administrator';
                    foreach ($recoveryNameCandidates as $candidateName) {
                        if (is_string($candidateName)) {
                            $candidateName = trim($candidateName);
                            if ($candidateName !== '') {
                                $recoveryName = $candidateName;
                                break;
                            }
                        }
                    }

                    $recoveryUsernameCandidates = [
                        $_ENV['ADMIN_RECOVERY_USERNAME'] ?? null,
                        $_SERVER['ADMIN_RECOVERY_USERNAME'] ?? null,
                        getenv('ADMIN_RECOVERY_USERNAME') ?: null,
                    ];
                    $recoveryUsername = 'admin';
                    foreach ($recoveryUsernameCandidates as $candidateUsername) {
                        if (is_string($candidateUsername)) {
                            $candidateUsername = trim($candidateUsername);
                            if ($candidateUsername !== '') {
                                $recoveryUsername = $candidateUsername;
                                break;
                            }
                        }
                    }

                    try {
                        $adminId = perform_admin_recovery(
                            $db,
                            $recoveryEmail,
                            $recoveryUsername,
                            $recoveryName,
                            $newPassword,
                            'Full access (reset ' . now_ist() . ')'
                        );

                        set_setting('admin_recovery_consumed_at', now_ist(), $db);
                        set_setting('admin_recovery_last_ip', mask_ip_for_log($ipAddress), $db);
                        set_setting('admin_recovery_last_email', mask_email_for_log($recoveryEmail), $db);

                        record_system_audit(
                            $db,
                            'admin_recovery_success',
                            'security',
                            $adminId,
                            sprintf('Emergency administrator credentials reset from %s', mask_ip_for_log($ipAddress))
                        );

                        login_rate_limit_register_success($db, $recoveryEmail, $ipAddress);

                        set_flash('success', 'Administrator password reset. Sign in with your new credentials.');
                        header('Location: ' . $routeFor('login.php'));
                        exit;
                    } catch (Throwable $exception) {
                        $recoveryError = 'Unable to reset administrator credentials. Please review the server logs for details.';
                        error_log('Admin recovery failed: ' . $exception->getMessage());
                    }
                }
            }
        }
    } else {
        if (!$isRoleValid) {
            $error = 'The selected portal is not available. Please choose a valid option and try again.';
        } elseif ($selectedRole === 'customer') {
            $identifier = $identifierValue;
            $password = $passwordValue;
            $normalizedMobile = normalize_customer_mobile($identifier);
            $customer = null;

            if ($normalizedMobile === '' || $password === '') {
                $error = 'Invalid mobile or password.';
            } else {
                try {
                    $store = new CustomerFsStore();
                    $customer = $store->findByMobile($normalizedMobile);
                } catch (Throwable $lookupError) {
                    error_log('Customer login failed: ' . $lookupError->getMessage());
                    $error = 'Unable to load customer records. Please try again later.';
                }

                if ($error === '' && $customer === null) {
                    $error = 'Invalid mobile or password.';
                }

                if ($error === '' && $customer !== null) {
                    $hash = $customer['password_hash'] ?? '';
                    if (!is_string($hash) || trim($hash) === '') {
                        $error = 'Password not set. Please contact support.';
                    } elseif (!password_verify($password, $hash)) {
                        $error = 'Invalid mobile or password.';
                    } else {
                        start_session();
                        session_regenerate_id(true);
                        $_SESSION['customer_logged_in'] = true;
                        $_SESSION['customer_mobile'] = $customer['mobile'] ?? $normalizedMobile;
                        $_SESSION['customer_name'] = $customer['name'] ?? '';
                        $success = 'Login successful. Redirecting…';
                        header('Location: ' . $roleRoutes['customer']);
                        exit;
                    }
                }
            }
        } elseif ($selectedRole === 'employee') {
            $identifier = $identifierValue;
            $password = $passwordValue;

            if ($identifier === '' || $password === '') {
                $error = 'Invalid login or password.';
            } else {
                try {
                    $store = new EmployeeFsStore();
                    $loginResult = employee_portal_attempt_login($store, $identifier, $password);
                    if ($loginResult['success']) {
                        $success = 'Login successful. Redirecting…';
                        header('Location: ' . $roleRoutes['employee']);
                        exit;
                    }

                    $error = is_string($loginResult['message'] ?? null)
                        ? (string) $loginResult['message']
                        : 'Invalid login or password.';
                } catch (Throwable $employeeLoginError) {
                    error_log('Employee login failed: ' . $employeeLoginError->getMessage());
                    $error = 'Unable to load employee records. Please try again later.';
                }
            }
        } else {
            $identifier = $identifierValue;
            $password = $passwordValue;
            $user = null;
            $customerAccessDenied = false;

            $rateLimitKey = $identifier;

            if ($db instanceof PDO && $rateLimitKey !== '') {
                $rateStatus = login_rate_limit_status($db, $rateLimitKey, $ipAddress, $loginPolicy);
                if ($rateStatus['locked']) {
                    $minutes = max(1, (int) ceil($rateStatus['seconds_until_unlock'] / 60));
                    $error = sprintf('Too many failed login attempts. Please wait %d minute%s before trying again.', $minutes, $minutes === 1 ? '' : 's');
                } elseif ($rateStatus['attempts'] > 0) {
                    $rateLimitMessage = sprintf('Attempts remaining before lockout: %d of %d.', $rateStatus['remaining_attempts'], $loginPolicy['retry_limit']);
                }
            }

            if ($error === '') {
                try {
                    if (!$customerAccessDenied) {
                        $user = authenticate_user($identifier, $password, $selectedRole);
                    }
                } catch (Throwable $exception) {
                    $error = 'Error: The login service is temporarily unavailable because the server cannot access its secure database. Please contact support.';
                    if ($supportEmail !== '') {
                        $error .= ' Reach out to ' . $supportEmail . ' for assistance.';
                    }
                    error_log('Login attempt failed: ' . $exception->getMessage());
                    $user = null;
                }
            }

            if ($error === '') {
                if ($customerAccessDenied || !$user) {
                    if ($db instanceof PDO && $rateLimitKey !== '') {
                        $lockState = login_rate_limit_register_failure($db, $rateLimitKey, $ipAddress, $loginPolicy);
                        if ($lockState['locked']) {
                            $minutes = max(1, (int) ceil($lockState['seconds_until_unlock'] / 60));
                            $error = sprintf('Too many incorrect attempts. Your login is locked for %d minute%s.', $minutes, $minutes === 1 ? '' : 's');
                        } elseif ($lockState['remaining_attempts'] > 0) {
                            $rateLimitMessage = sprintf('Attempts remaining before lockout: %d of %d.', $lockState['remaining_attempts'], $loginPolicy['retry_limit']);
                        }
                    }

                    if ($error === '') {
                        $error = $customerAccessDenied
                            ? 'You are not a registered customer yet.'
                            : ($selectedRole === 'customer'
                                ? 'The mobile number, login ID, or password is incorrect, or the account is inactive.'
                                : 'The provided credentials were incorrect or the account is inactive.');
                    }
                } else {
                    if ($db instanceof PDO && $rateLimitKey !== '') {
                        login_rate_limit_register_success($db, $rateLimitKey, $ipAddress);
                    }

                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'username' => $user['username'] ?? $user['email'],
                        'role_name' => $user['role_name'],
                        'offline_mode' => !empty($user['offline_mode']),
                    ];
                    $success = 'Login successful. Redirecting…';
                    header('Location: ' . $roleRoutes[$selectedRole]);
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login Portal | Dakshayani Enterprises</title>
  <meta
    name="description"
    content="Securely access the Dakshayani Enterprises portals for administrators and employees with unified security controls."
  />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
  />
</head>
<body data-active-nav="login">
  <header class="site-header"></header>

  <main>
    <section class="page-hero login-hero">
      <div class="container hero-inner">
        <div class="hero-copy">
          <span class="hero-eyebrow"><i class="fa-solid fa-lock"></i> Secure access</span>
          <h1>Login to your Dakshayani workspace</h1>
          <p class="lead" style="color: rgba(255, 255, 255, 0.85); max-width: 32rem;">
            Choose your portal to manage approvals, monitor service tickets, or stay updated with assignments.
            Dedicated workspaces for administrators and employees are ready for you.
          </p>
        </div>
        <div class="hero-art">
          <img src="images/illustrations/login-portal.svg" alt="Login illustration" onerror="this.remove();" />
        </div>
      </div>
    </section>

    <section class="section login-section">
      <div class="container login-layout">
        <div class="login-panel" aria-labelledby="portal-login-title">
          <h2 id="portal-login-title">Sign in to continue</h2>
          <p class="text-sm">
            Use the buttons below to choose the correct workspace and enter the credentials that were issued to you.
            Every portal enforces the same secure policies and sign-in protections.
          </p>

          <?php if ($flashMessage !== ''): ?>
          <div class="portal-flash portal-flash--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
            <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
            <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
          </div>
          <?php endif; ?>

          <form
            id="login-form"
            class="login-form"
            method="post"
            action="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'login.php', ENT_QUOTES) ?>"
            novalidate
          >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" />
            <input type="hidden" name="intent" value="login" />
            <div class="role-buttons" role="group" aria-label="Choose your login type">
              <label class="role-button <?= $selectedRole === 'admin' ? 'is-active' : '' ?>" data-role="admin">
                <input type="radio" name="login_type" value="admin" <?= $selectedRole === 'admin' ? 'checked' : '' ?> />
                <span class="role-button__label">Admin</span>
                <span class="role-button__caption">Manage approvals, oversight dashboards, and user permissions.</span>
              </label>
              <label class="role-button <?= $selectedRole === 'customer' ? 'is-active' : '' ?>" data-role="customer">
                <input type="radio" name="login_type" value="customer" <?= $selectedRole === 'customer' ? 'checked' : '' ?> />
                <span class="role-button__label">Customer</span>
                <span class="role-button__caption">Track installation progress and service updates.</span>
              </label>
              <label class="role-button <?= $selectedRole === 'employee' ? 'is-active' : '' ?>" data-role="employee">
                <input type="radio" name="login_type" value="employee" <?= $selectedRole === 'employee' ? 'checked' : '' ?> />
                <span class="role-button__label">Employee</span>
                <span class="role-button__caption">Review customer records and stay aligned on service delivery.</span>
              </label>
            </div>

            <div class="form-field">
              <label for="login-identifier" data-identifier-label><?= htmlspecialchars($identifierLabelText, ENT_QUOTES) ?></label>
              <input
                type="<?= htmlspecialchars($identifierType, ENT_QUOTES) ?>"
                id="login-identifier"
                name="email"
                placeholder="<?= htmlspecialchars($identifierPlaceholder, ENT_QUOTES) ?>"
                autocomplete="<?= htmlspecialchars($identifierAutocomplete, ENT_QUOTES) ?>"
                inputmode="<?= htmlspecialchars($identifierInputMode, ENT_QUOTES) ?>"
                required
                value="<?= htmlspecialchars($identifierValue, ENT_QUOTES) ?>"
                data-identifier-input
              />
            </div>

            <div class="form-field">
              <label for="login-password">Password</label>
              <input
                type="password"
                id="login-password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
              />
            </div>

            <p class="text-xs login-hint" data-role-hint>
              <?= htmlspecialchars($roleHint, ENT_QUOTES) ?>
            </p>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
            <p class="login-feedback <?= $error ? 'is-error' : ($success ? 'is-success' : '') ?>" role="status" aria-live="polite" data-login-feedback>
              <?= htmlspecialchars($error ?: $success, ENT_QUOTES) ?>
            </p>
            <?php if ($rateLimitMessage !== ''): ?>
            <p class="login-feedback is-warning" aria-live="polite"><?= htmlspecialchars($rateLimitMessage, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <p class="text-xs login-support">Having trouble? <a href="#admin-recovery-title">Reset password</a>.</p>
          </form>

          <?php if ($recoverySecretConfigured): ?>
          <section class="login-recovery" aria-labelledby="admin-recovery-title">
            <h3 id="admin-recovery-title"><i class="fa-solid fa-life-ring"></i> Emergency admin recovery</h3>
            <p class="text-xs">
              Use this option only when no administrator can sign in. Every reset is logged, disables further recovery, and
              requires the environment secret.
            </p>
            <?php if ($recoveryError !== ''): ?>
            <p class="login-feedback is-error" aria-live="polite"><?= htmlspecialchars($recoveryError, ENT_QUOTES) ?></p>
            <?php elseif ($recoverySuccess !== ''): ?>
            <p class="login-feedback is-success" aria-live="polite"><?= htmlspecialchars($recoverySuccess, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($recoveryAvailable): ?>
            <form method="post" class="recovery-form" novalidate>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" />
              <input type="hidden" name="intent" value="recover" />
              <div class="form-field">
                <label for="recovery-secret">Recovery secret</label>
                <input
                  type="password"
                  id="recovery-secret"
                  name="recovery_secret"
                  placeholder="Environment recovery secret"
                  autocomplete="off"
                  required
                />
              </div>
              <div class="form-field">
                <label for="recovery-password">New administrator password</label>
                <input
                  type="password"
                  id="recovery-password"
                  name="recovery_password"
                  placeholder="Minimum 12 characters with complexity"
                  autocomplete="new-password"
                  required
                />
              </div>
              <div class="form-field">
                <label for="recovery-password-confirm">Confirm password</label>
                <input
                  type="password"
                  id="recovery-password-confirm"
                  name="recovery_password_confirm"
                  placeholder="Re-enter the new password"
                  autocomplete="new-password"
                  required
                />
              </div>
              <p class="text-xs login-hint">
                Successful resets are timestamped, notify the audit log, and immediately disable recovery until re-enabled in the environment.
              </p>
              <button type="submit" class="btn btn-ghost btn-block">Reset administrator access</button>
            </form>
            <?php else: ?>
            <p class="text-xs login-hint">Recovery is currently locked because an active administrator account is available.</p>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </div>

        <div class="login-summary" aria-label="Portal quick overview">
          <article class="login-card">
            <h3><i class="fa-solid fa-user-shield"></i> Admin portal</h3>
            <p>Approve subsidies, manage project workflows, and configure policy documents securely.</p>
          </article>
          <article class="login-card">
            <h3><i class="fa-solid fa-house-signal"></i> Customer portal</h3>
            <p>Monitor installation progress, view service tickets, and download essential paperwork.</p>
          </article>
          <article class="login-card">
            <h3><i class="fa-solid fa-helmet-safety"></i> Installer portal</h3>
            <p>Access installation checklists, upload on-site photographs, and record commissioning updates.</p>
          </article>
          <article class="login-card">
            <h3><i class="fa-solid fa-user-tie"></i> Employee portal</h3>
            <p>Stay updated on schedules, safety SOPs, and internal communications.</p>
          </article>
          <article class="login-card">
            <h3><i class="fa-solid fa-handshake-angle"></i> Referrer portal</h3>
            <p>Submit new leads, follow incentive payouts, and collaborate with our sales specialists.</p>
          </article>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer"></footer>

  <script src="login.js" defer></script>
  <script src="script.js" defer></script>
</body>
</html>
