<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';

$employeeStore = new EmployeeFsStore();
employee_portal_require_login();
$employee = employee_portal_current_employee($employeeStore);
if ($employee === null) {
    header('Location: login.php?login_type=employee');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Quotations &amp; Challans</title>
  <style>
    body { margin:0; font-family: Arial, sans-serif; background:#f5f7fb; }
    .wrap { width:100%; max-width:none; box-sizing:border-box; padding:1.5rem; }
    .card { background:#fff; border:1px solid #dbe1ea; border-radius:12px; padding:1.25rem; }
    .muted { color:#64748b; }
    .disabled-list { margin-top:1rem; padding-left:1.2rem; }
    .disabled-list li { color:#475569; margin-bottom:0.4rem; }
    .btn { display:inline-block; margin-top:1rem; margin-right:0.5rem; text-decoration:none; background:#1d4ed8; color:#fff; padding:0.55rem 0.85rem; border-radius:8px; }
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <h1>Quotations &amp; Challans</h1>
      <p class="muted">Hello <?= htmlspecialchars((string) ($employee['name'] ?? 'Employee'), ENT_QUOTES) ?>.</p>
      <p>Quotation module is now enabled. Challan will be enabled in a later phase.</p>
      <ul class="disabled-list" aria-label="Accessible modules">
        <li>Quotation Builder (enabled)</li>
        <li>Delivery Challan Builder (coming in later phase)</li>
        <li>Document History (coming in later phase)</li>
      </ul>
      <a class="btn" href="employee-quotations.php">Quotations</a>
      <a class="btn" href="employee-dashboard.php">Back to Employee Dashboard</a>
    </section>
  </main>
</body>
</html>
