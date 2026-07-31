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

function employee_app_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install Dakshayani Work</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="assets/css/admin-unified.css">
  <style>
    body{background:#f4f7fb;color:#172033}.app-shell{max-width:900px;margin:0 auto;padding:24px 14px 90px}.hero,.card{background:#fff;border:1px solid #dfe7f1;border-radius:18px;box-shadow:0 14px 34px rgba(15,23,42,.06)}.hero{padding:28px;background:linear-gradient(135deg,#ecfeff,#eff6ff)}.hero h1{margin:0 0 8px;font-size:2rem}.hero p{color:#475569;max-width:680px;line-height:1.6}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{border:0;border-radius:10px;padding:11px 16px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-primary{background:#0f766e;color:#fff}.btn-secondary{background:#e2e8f0;color:#172033}.btn[disabled]{opacity:.65;cursor:not-allowed}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:16px 0}.card{padding:18px}.card h2,.card h3{margin-top:0}.card p,.card li{color:#475569;line-height:1.55}.steps{counter-reset:steps;display:grid;gap:10px;padding:0;list-style:none}.steps li{counter-increment:steps;padding:12px 12px 12px 46px;background:#f8fafc;border-radius:11px;position:relative}.steps li:before{content:counter(steps);position:absolute;left:12px;top:10px;width:25px;height:25px;border-radius:50%;display:grid;place-items:center;background:#0f766e;color:#fff;font-weight:800}.status{margin-top:12px;padding:10px 12px;background:#f8fafc;border-radius:10px;color:#475569}.security{border-left:4px solid #0f766e}.muted{color:#64748b}@media(max-width:750px){.grid{grid-template-columns:1fr}.hero{padding:21px}.hero h1{font-size:1.55rem}}
  </style>
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
<main class="app-shell">
  <section class="hero">
    <p style="margin:0 0 6px;font-weight:800;color:#0f766e">EMPLOYEE APP</p>
    <h1>Install Dakshayani Work</h1>
    <p>Hello <?= employee_app_safe((string)($employee['name'] ?? 'Employee')) ?>. Install the secure employee workspace directly from this website. It opens like an app and uses the same employee login.</p>
    <div class="actions">
      <button class="btn btn-primary" type="button" data-pwa-install data-pwa-install-fallback="#manual-install">Install app</button>
      <a class="btn btn-secondary" href="employee-tasks.php">Open my tasks</a>
      <a class="btn btn-secondary" href="employee-dashboard.php">Open dashboard</a>
    </div>
    <div class="status" id="install-status" role="status">Checking whether this device can show the direct install prompt…</div>
  </section>

  <section class="grid" aria-label="App benefits">
    <article class="card"><h3>Tasks</h3><p>See new assignments, deadlines, priority and expected output in one place.</p></article>
    <article class="card"><h3>Replies</h3><p>Acknowledge work, update admin, report blockers and receive corrections inside each task.</p></article>
    <article class="card"><h3>Approval</h3><p>Submit completed work for admin review instead of closing work without verification.</p></article>
  </section>

  <section class="card" id="manual-install">
    <h2>Manual installation steps</h2>
    <div class="grid" style="margin-bottom:0">
      <div>
        <h3>Android — Chrome</h3>
        <ol class="steps">
          <li>Open this page in Chrome.</li>
          <li>Tap the three-dot menu.</li>
          <li>Choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li>
          <li>Confirm installation.</li>
        </ol>
      </div>
      <div>
        <h3>iPhone / iPad — Safari</h3>
        <ol class="steps">
          <li>Open this page in Safari.</li>
          <li>Tap the Share button.</li>
          <li>Choose <strong>Add to Home Screen</strong>.</li>
          <li>Tap <strong>Add</strong>.</li>
        </ol>
      </div>
      <div>
        <h3>Computer — Chrome / Edge</h3>
        <ol class="steps">
          <li>Open this page in Chrome or Edge.</li>
          <li>Click the install icon in the address bar.</li>
          <li>Confirm <strong>Install</strong>.</li>
          <li>Pin it to the taskbar if useful.</li>
        </ol>
      </div>
    </div>
  </section>

  <section class="card security">
    <h2>Security and connectivity</h2>
    <p>The installed app is the same secure website in an app window. Employee authentication and permissions remain server-side. Private task pages are not stored for offline viewing, so current data requires an internet connection.</p>
    <p class="muted">This is a Progressive Web App, so no separate APK file is required for the first release.</p>
  </section>
</main>
<script>
  (() => {
    const status = document.getElementById('install-status');
    const update = () => {
      if (!status || !window.dakshayaniPwa) return;
      const state = window.dakshayaniPwa.getInstallState();
      if (state.standalone) status.textContent = 'Dakshayani Work is already installed and running as an app.';
      else if (state.canPrompt) status.textContent = 'Direct installation is available on this device. Tap “Install app”.';
      else if (state.isIos) status.textContent = 'On iPhone or iPad, use Safari → Share → Add to Home Screen.';
      else status.textContent = 'Use your browser menu and choose Install app or Add to Home screen.';
    };
    document.addEventListener('dakshayani:pwa-state', update);
    window.addEventListener('load', update, {once:true});
    setTimeout(update, 500);
  })();
</script>
</body>
</html>
