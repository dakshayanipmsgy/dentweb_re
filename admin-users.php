<?php
declare(strict_types=1);

// Keep legacy customer bookmarks working while moving customer management to its own page.
if (strtolower((string) ($_GET['tab'] ?? '')) === 'customers') {
    $query = $_GET;
    unset($query['tab']);
    $location = 'admin-customers.php';
    if ($query !== []) {
        $location .= '?' . http_build_query($query);
    }
    header('Location: ' . $location, true, 302);
    exit;
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/employee_admin.php';

require_admin();

$employeeStore = new EmployeeFsStore();
$employeeErrors = [];
$employeeSuccess = '';
$employees = [];
$editingEmployee = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_valid_csrf();
        $action = (string) ($_POST['employee_action'] ?? '');
        $input = [
            'name' => $_POST['name'] ?? '',
            'login_id' => $_POST['login_id'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'designation' => $_POST['designation'] ?? '',
            'status' => $_POST['status'] ?? 'active',
        ];

        $existingEmployee = null;
        if ($action === 'update_employee') {
            $employeeId = (string) ($_POST['employee_id'] ?? '');
            $existingEmployee = $employeeStore->findById($employeeId);
        }

        admin_users_apply_employee_password($_POST, $input, $employeeErrors, $existingEmployee);

        if ($action === 'create_employee') {
            if ($employeeErrors === []) {
                $result = $employeeStore->addEmployee($input);
            } else {
                $result = ['success' => false, 'errors' => $employeeErrors, 'employee' => null];
            }
            if ($result['success']) {
                $employeeSuccess = 'Employee added successfully.';
            } else {
                $employeeErrors = $result['errors'];
            }
        } elseif ($action === 'update_employee') {
            $employeeId = (string) ($_POST['employee_id'] ?? '');
            if ($employeeErrors === []) {
                $result = $employeeStore->updateEmployee($employeeId, $input);
            } else {
                $result = ['success' => false, 'errors' => $employeeErrors, 'employee' => null];
            }
            if ($result['success']) {
                $employeeSuccess = 'Employee updated successfully.';
                $editingEmployee = $result['employee'];
            } else {
                $employeeErrors = $result['errors'];
            }
        }
    }

    $employees = $employeeStore->listEmployees();
    $viewEmployee = (string) ($_GET['view'] ?? '');
    if ($viewEmployee !== '') {
        $editingEmployee = $employeeStore->findById($viewEmployee);
        if ($editingEmployee === null) {
            $employeeErrors[] = 'Employee not found.';
        }
    }

function admin_users_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_users_apply_employee_password(array $source, array &$input, array &$errors, ?array $existingEmployee = null): void
{
    $password = isset($source['password']) && is_string($source['password']) ? (string) $source['password'] : '';
    $confirm = isset($source['confirm_password']) && is_string($source['confirm_password']) ? (string) $source['confirm_password'] : '';

    $hasPassword = ($password !== '') || ($confirm !== '');
    if (!$hasPassword) {
        return;
    }

    if ($password === '' || $confirm === '') {
        $errors[] = 'Both password fields are required when setting an employee password.';
        return;
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirm password must match.';
        return;
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        $errors[] = 'Unable to process the password. Please try again.';
        return;
    }

    $input['password_hash'] = $hash;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Employee Management | Dakshayani Enterprises</title>
  <meta name="description" content="Administer Dentweb employee accounts." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/admin-unified.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
  />
  <style>
    .users-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-bottom: 1.5rem;
    }

    .users-tab__link {
      padding: 0.65rem 1.1rem;
      border-radius: 999px;
      border: 1px solid #d9dde7;
      background: #f7f9fc;
      color: #1c2330;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
    }

    .users-tab__link:hover {
      border-color: #b6c2d9;
      background: #eef2f9;
    }

    .users-tab__link.is-active {
      background: linear-gradient(135deg, #1f4b99, #2d68d8);
      color: #ffffff;
      border-color: #2d68d8;
      box-shadow: 0 8px 20px rgba(45, 104, 216, 0.2);
    }

    .users-toolbar {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 1rem;
    }

    .users-toolbar__actions {
      display: flex;
      justify-content: flex-end;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .users-input,
    .users-select {
      width: 100%;
      padding: 0.65rem 0.75rem;
      border: 1px solid #d9dde7;
      border-radius: 10px;
      background: #fff;
      font: inherit;
    }

    .users-input:focus,
    .users-select:focus {
      outline: 2px solid #2d68d8;
      outline-offset: 2px;
    }

    .users-table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff;
      border: 1px solid #e6e9ef;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(17, 24, 39, 0.04);
    }

    .users-table th,
    .users-table td {
      padding: 0.9rem 1rem;
      text-align: left;
      border-bottom: 1px solid #eef2f7;
      font-size: 0.95rem;
    }

    .users-table th {
      background: #f7f9fc;
      font-weight: 700;
      color: #1c2330;
      letter-spacing: 0.01em;
    }

    .users-table tbody tr:last-child td {
      border-bottom: none;
    }

    .users-status {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.35rem 0.7rem;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 600;
      background: #eef2f9;
      color: #1f4b99;
    }

    .users-actions a {
      color: #2d68d8;
      font-weight: 600;
      text-decoration: none;
    }

    .users-actions a:hover {
      text-decoration: underline;
    }

    .users-section + .users-section {
      margin-top: 2rem;
    }

    .users-card {
      background: #ffffff;
      border: 1px solid #e6e9ef;
      border-radius: 14px;
      padding: 1.2rem 1.25rem;
      box-shadow: 0 8px 24px rgba(17, 24, 39, 0.05);
      margin-bottom: 1rem;
    }

    .users-card__header {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .users-form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.75rem 1rem;
      margin-top: 0.75rem;
    }

    .users-form-grid label {
      font-weight: 600;
      display: block;
      margin-bottom: 0.25rem;
      color: #1c2330;
    }

    .users-form-section {
      border: 1px solid #e6e9ef;
      border-radius: 12px;
      padding: 1rem;
      background: #f9fbff;
    }

    .users-form-section + .users-form-section {
      margin-top: 0.9rem;
    }

    .users-form-section__header {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 0.5rem;
      margin-bottom: 0.35rem;
      flex-wrap: wrap;
    }

    .users-form-section__title {
      margin: 0;
      font-size: 1rem;
      color: #111827;
    }

    .users-form-grid .users-input,
    .users-form-grid .users-select {
      border-radius: 8px;
    }

    .users-form-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 0.5rem;
    }

    .admin-alert {
      padding: 0.85rem 1rem;
      border-radius: 10px;
      margin-bottom: 1rem;
      border: 1px solid transparent;
    }

    .admin-alert--success {
      background: #edf7ed;
      border-color: #c8e6c9;
      color: #256029;
    }

    .admin-alert--error {
      background: #fff1f0;
      border-color: #f5c6cb;
      color: #b22222;
    }

    /* Customer Type badges */
    .badge-customer-type {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 500;
      color: #333;
    }

    /* PM Surya Ghar vs Non PM */
    .badge-pm-surya-ghar {
      background-color: #e0f7f7;
      border: 1px solid #b3e0e0;
    }

    .badge-non-pm-surya-ghar {
      background-color: #e5e9ff;
      border: 1px solid #c2c8ff;
    }

    /* Project status badges */
    .badge-status {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 500;
      color: #333;
    }

    .badge-status-new {
      background-color: #e3f2ff;
      border: 1px solid #bedcff;
    }

    .badge-status-survey-pending {
      background-color: #fff9e0;
      border: 1px solid #ffe9a3;
    }

    .badge-status-survey-done {
      background-color: #e6fff4;
      border: 1px solid #b8f0d2;
    }

    .badge-status-installation-pending {
      background-color: #fff3e0;
      border: 1px solid #ffd59b;
    }

    .badge-status-installation-in-progress {
      background-color: #ffe9e0;
      border: 1px solid #ffccba;
    }

    .badge-status-complete,
    .badge-status-completed {
      background-color: #e4ffe4;
      border: 1px solid #b4e6b4;
    }

    /* Generic YES/NO cell colours */
    .cell-yes {
      background-color: #e6ffed;
      border-radius: 4px;
    }

    .cell-no {
      background-color: #ffecec;
      border-radius: 4px;
    }

    /* Welcome sent colours */
    .cell-welcome-sent {
      background-color: #e4ffe4;
      border-radius: 4px;
    }

    .cell-welcome-not-sent {
      background-color: #fff0f0;
      border-radius: 4px;
    }

    .admin-alert ul {
      margin: 0.35rem 0 0 1rem;
    }

    .users-subtabs {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
    }

    .users-subtab {
      border: 1px solid #cfd8e6;
      background: #f8fafc;
      border-radius: 999px;
      padding: 0.45rem 0.8rem;
      font-weight: 600;
      cursor: pointer;
    }

    .users-subtab.is-active {
      background: #0f172a;
      color: #fff;
      border-color: #0f172a;
    }

    .users-status--ok {
      background: #e7f8ee;
      color: #0f766e;
    }

    .users-status--warn {
      background: #fff3da;
      color: #92400e;
    }

    .users-status--muted {
      background: #eef2f7;
      color: #475569;
    }

    .users-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 0.75rem;
      margin-bottom: 1rem;
    }

    .users-summary-card {
      background: #fff;
      border: 1px solid #e6e9ef;
      border-radius: 12px;
      padding: 0.85rem 1rem;
    }

    .users-summary-card strong {
      display: block;
      font-size: 1.3rem;
      color: #0f172a;
    }

    .users-pill {
      display: inline-flex;
      padding: 0.25rem 0.65rem;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
      background: #f1f5f9;
      color: #334155;
    }

    .users-pill--pm { background: #e0f7f7; color: #0f766e; }
    .users-pill--non-pm { background: #e5e9ff; color: #3730a3; }
    .users-pill--other { background: #f1f5f9; color: #334155; }

    .users-table td {
      vertical-align: middle;
    }

    .users-actions {
      display: flex;
      justify-content: flex-end;
      gap: 0.75rem;
      align-items: center;
    }

    details.users-collapsible summary {
      cursor: pointer;
      font-weight: 600;
    }
  </style>
</head>
<body class="admin-records admin-shell" data-theme="light">
  <main class="admin-records__shell admin-page">
    <header class="admin-records__header">
      <div>
        <h1>Employee Management</h1>
        <p class="admin-muted">Manage employees with a clean, ready-to-extend workspace.</p>
      </div>
      <div class="admin-records__meta">
        <a class="admin-link" href="admin-dashboard.php"><i class="fa-solid fa-gauge-high"></i> Back to overview</a>
      </div>
    </header>
    <section class="admin-section">
      <section class="users-section" aria-labelledby="employees-heading">
        <header class="admin-section__header">
          <div>
            <h2 id="employees-heading">Employees</h2>
            <p class="admin-muted">Manage employee records stored on disk. Add, review, and edit individual employees.</p>
          </div>
        </header>

        <div class="users-toolbar">
          <div>
            <label class="sr-only" for="employee-search">Search employees</label>
            <input id="employee-search" class="users-input" type="search" placeholder="Search employees" />
          </div>
          <div>
            <label class="sr-only" for="employee-role">Designation</label>
            <select id="employee-role" class="users-select">
              <option value="all">All designations</option>
            </select>
          </div>
          <div class="users-toolbar__actions">
            <a class="btn btn-secondary" href="#add-employee-form">Add Employee</a>
          </div>
        </div>

        <?php if ($employeeSuccess !== ''): ?>
        <div class="admin-alert admin-alert--success" role="status"><?= admin_users_safe($employeeSuccess) ?></div>
        <?php endif; ?>
        <?php if ($employeeErrors !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <div><strong>There was a problem:</strong></div>
          <ul>
            <?php foreach ($employeeErrors as $message): ?>
            <li><?= admin_users_safe($message) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div id="add-employee-form" class="users-card" aria-labelledby="add-employee-heading">
          <div class="users-card__header">
            <div>
              <h3 id="add-employee-heading">Add Employee</h3>
              <p class="admin-muted">Create a new employee profile for future login enablement.</p>
            </div>
          </div>
          <form method="post" class="users-form-grid">
        <?= csrf_field() ?>
            <input type="hidden" name="employee_action" value="create_employee" />
            <div>
              <label for="employee-name">Name *</label>
              <input id="employee-name" class="users-input" name="name" type="text" required />
            </div>
            <div>
              <label for="employee-login">Login ID *</label>
              <input id="employee-login" class="users-input" name="login_id" type="text" required />
            </div>
            <div>
              <label for="employee-phone">Phone</label>
              <input id="employee-phone" class="users-input" name="phone" type="text" />
            </div>
            <div>
              <label for="employee-designation">Designation</label>
              <input id="employee-designation" class="users-input" name="designation" type="text" />
            </div>
            <div>
              <label for="employee-password">Password (optional)</label>
              <input id="employee-password" class="users-input" name="password" type="password" minlength="6" />
              <p class="admin-muted" style="margin-top: 0.25rem;">Leave blank to keep the password unset.</p>
            </div>
            <div>
              <label for="employee-password-confirm">Confirm Password</label>
              <input id="employee-password-confirm" class="users-input" name="confirm_password" type="password" minlength="6" />
            </div>
            <div>
              <label for="employee-status">Status</label>
              <select id="employee-status" class="users-select" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Add employee</button>
            </div>
          </form>
        </div>

        <?php if ($editingEmployee !== null): ?>
        <div class="users-card" aria-labelledby="edit-employee-heading">
          <div class="users-card__header">
            <div>
              <h3 id="edit-employee-heading">Edit Employee</h3>
              <p class="admin-muted">Update details for <?= admin_users_safe($editingEmployee['name'] ?? $editingEmployee['login_id'] ?? '') ?>.</p>
            </div>
          </div>
          <form method="post" class="users-form-grid">
        <?= csrf_field() ?>
            <input type="hidden" name="employee_action" value="update_employee" />
            <input type="hidden" name="employee_id" value="<?= admin_users_safe($editingEmployee['id'] ?? '') ?>" />
            <div>
              <label for="edit-employee-name">Name *</label>
              <input id="edit-employee-name" class="users-input" name="name" type="text" value="<?= admin_users_safe($editingEmployee['name'] ?? '') ?>" required />
            </div>
            <div>
              <label for="edit-employee-login">Login ID *</label>
              <input id="edit-employee-login" class="users-input" name="login_id" type="text" value="<?= admin_users_safe($editingEmployee['login_id'] ?? '') ?>" required />
            </div>
            <div>
              <label for="edit-employee-phone">Phone</label>
              <input id="edit-employee-phone" class="users-input" name="phone" type="text" value="<?= admin_users_safe($editingEmployee['phone'] ?? '') ?>" />
            </div>
            <div>
              <label for="edit-employee-designation">Designation</label>
              <input id="edit-employee-designation" class="users-input" name="designation" type="text" value="<?= admin_users_safe($editingEmployee['designation'] ?? '') ?>" />
            </div>
            <div>
              <label for="edit-employee-password">Password (optional)</label>
              <input id="edit-employee-password" class="users-input" name="password" type="password" minlength="6" />
              <p class="admin-muted" style="margin-top: 0.25rem;">Leave blank to keep the existing password unchanged.</p>
            </div>
            <div>
              <label for="edit-employee-password-confirm">Confirm Password</label>
              <input id="edit-employee-password-confirm" class="users-input" name="confirm_password" type="password" minlength="6" />
            </div>
            <div>
              <label for="edit-employee-status">Status</label>
              <select id="edit-employee-status" class="users-select" name="status">
                <option value="active"<?= ($editingEmployee['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="inactive"<?= ($editingEmployee['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
              </select>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <div class="admin-table-wrapper">
          <table class="users-table" aria-label="Employee list">
            <thead>
              <tr>
                <th scope="col">Employee name</th>
                <th scope="col">Login ID</th>
                <th scope="col">Designation</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($employees === []): ?>
              <tr>
                <td colspan="5" class="text-center admin-muted">No employees found.</td>
              </tr>
              <?php else: ?>
              <?php foreach ($employees as $employee): ?>
              <tr
                data-employee-row="1"
                data-name="<?= admin_users_safe(strtolower((string) ($employee['name'] ?? ''))) ?>"
                data-login="<?= admin_users_safe(strtolower((string) ($employee['login_id'] ?? ''))) ?>"
                data-designation="<?= admin_users_safe(strtolower((string) ($employee['designation'] ?? ''))) ?>"
              >
                <td><?= admin_users_safe($employee['name'] ?? '') ?></td>
                <td><?= admin_users_safe($employee['login_id'] ?? '') ?></td>
                <td><?= admin_users_safe($employee['designation'] ?? '') ?></td>
                <td><span class="users-status"><?= ($employee['status'] ?? '') === 'inactive' ? 'Inactive' : 'Active' ?></span></td>
                <td class="users-actions text-right"><a href="admin-users.php?tab=employees&amp;view=<?= urlencode((string) ($employee['id'] ?? '')) ?>">View / Edit</a></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </section>
  </main>
  <script>
    (function () {
      const employeeSearch = document.getElementById('employee-search');
      const employeeRole = document.getElementById('employee-role');
      const employeeRows = Array.from(document.querySelectorAll('[data-employee-row="1"]'));
      const designations = Array.from(new Set(employeeRows.map((row) => row.dataset.designation || '').filter(Boolean)));
      designations.sort().forEach((designation) => {
        const option = document.createElement('option');
        option.value = designation;
        option.textContent = designation;
        employeeRole?.appendChild(option);
      });
      const applyEmployeeFilter = () => {
        const query = (employeeSearch?.value || '').trim().toLowerCase();
        const role = employeeRole?.value || 'all';
        employeeRows.forEach((row) => {
          const haystack = `${row.dataset.name || ''} ${row.dataset.login || ''} ${row.dataset.designation || ''}`;
          row.style.display = (query === '' || haystack.includes(query)) && (role === 'all' || row.dataset.designation === role) ? '' : 'none';
        });
      };
      employeeSearch?.addEventListener('input', applyEmployeeFilter);
      employeeRole?.addEventListener('change', applyEmployeeFilter);
    })();
  </script>
</body>
</html>
