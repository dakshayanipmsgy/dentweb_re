<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/customer_admin.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/tasks_helpers.php';

$employeeStore = new EmployeeFsStore();
$customerStore = new CustomerFsStore();

$statusOptions = $customerStore->customerStatuses();

$searchTerm = trim((string) ($_GET['search'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$typeFilter = (string) ($_GET['customer_type'] ?? 'all');

employee_portal_require_login();
$employee = employee_portal_current_employee($employeeStore);
if ($employee === null) {
    header('Location: login.php?login_type=employee');
    exit;
}

$today = tasks_today_date();
$tasks = load_tasks();
$tz = new DateTimeZone(TASKS_TIMEZONE);
$upcomingWindowEnd = (new DateTimeImmutable('today', $tz))->modify('+7 days')->format('Y-m-d');

$pendingTasksForEmployee = array_values(array_filter($tasks, static function (array $task) use ($employee): bool {
    if (!empty($task['archived_flag'])) {
        return false;
    }
    if (strcasecmp((string) ($task['status'] ?? ''), 'open') !== 0) {
        return false;
    }

    return (string) ($task['assigned_to_id'] ?? '') === (string) ($employee['id'] ?? '');
}));

usort($pendingTasksForEmployee, static function (array $left, array $right): int {
    return strcmp(get_effective_due_date($left), get_effective_due_date($right));
});

$overdueTasks = [];
$todayTasks = [];
$upcomingTasks = [];

foreach ($pendingTasksForEmployee as $task) {
    $due = get_effective_due_date($task);
    if ($due === '') {
        continue;
    }
    if (is_overdue($task, $today)) {
        $overdueTasks[] = $task;
        continue;
    }
    if (is_due_today($task, $today)) {
        $todayTasks[] = $task;
        continue;
    }
    if (strcmp($due, $today) > 0 && strcmp($due, $upcomingWindowEnd) <= 0) {
        $upcomingTasks[] = $task;
    }
}

$overdueTasks = array_slice($overdueTasks, 0, 3);
$remainingSlots = 6 - count($overdueTasks);
$todayTasks = array_slice($todayTasks, 0, max(0, min(3, $remainingSlots)));
$remainingSlots = max(0, 6 - count($overdueTasks) - count($todayTasks));
$upcomingTasks = array_slice($upcomingTasks, 0, max(0, min(3, $remainingSlots)));

$hasPendingTasks = ($overdueTasks !== [] || $todayTasks !== [] || $upcomingTasks !== []);

$formatTaskDate = static function (string $dateValue) use ($tz): string {
    if ($dateValue === '') {
        return 'No due date';
    }

    try {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateValue, $tz);
        if ($dt === false) {
            return $dateValue;
        }
        return $dt->format('d M Y');
    } catch (Throwable $exception) {
        return $dateValue;
    }
};

$customers = $customerStore->listCustomers();
$customers = array_values(array_filter($customers, function (array $customer) use ($searchTerm, $statusFilter, $typeFilter): bool {
    $matchesSearch = true;
    if ($searchTerm !== '') {
        $needle = strtolower($searchTerm);
        $name = strtolower((string) ($customer['name'] ?? ''));
        $mobile = strtolower((string) ($customer['mobile'] ?? ''));
        $matchesSearch = (strpos($name, $needle) !== false) || (strpos($mobile, $needle) !== false);
    }

    $matchesStatus = true;
    if ($statusFilter !== 'all') {
        $matchesStatus = strcasecmp((string) ($customer['status'] ?? ''), $statusFilter) === 0;
    }

    $matchesType = true;
    if ($typeFilter !== 'all') {
        $matchesType = strcasecmp((string) ($customer['customer_type'] ?? ''), $typeFilter) === 0;
    }

    return $matchesSearch && $matchesStatus && $matchesType;
}));

$complaintCounts = complaint_summary_counts();
$leadStats = get_lead_stats_for_dashboard();

function employee_dashboard_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Employee Dashboard | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .employee-shell {
      min-height: 100vh;
      background: #f5f7fb;
      padding: 2rem 1rem 3rem;
    }
    .employee-card {
      background: #ffffff;
      width: 100%;
      max-width: none;
      margin: 0;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.06);
      padding: 1.5rem 1.75rem;
    }
    .employee-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
    }
    .employee-title {
      margin: 0;
      font-size: 1.5rem;
      color: #111827;
    }
    .employee-subtitle {
      margin: 0.35rem 0 0;
      color: #4b5563;
    }
    .complaints-card {
      margin: 0.5rem 0 1rem;
      padding: 1rem 1.25rem;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: linear-gradient(135deg, #eef2ff, #e0f2fe);
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 0.5rem 1rem;
      align-items: center;
      text-decoration: none;
      color: #111827;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.06);
    }
    .complaints-card__title {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 700;
      color: #111827;
    }
    .complaints-card__meta {
      margin: 0;
      color: #374151;
      font-weight: 600;
    }
    .complaints-card__counts {
      margin: 0;
      font-weight: 700;
      color: #1f2937;
    }
    .complaints-card__cta {
      margin: 0;
      font-weight: 700;
      color: #1f4b99;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }
    .leads-card {
      margin: 0.5rem 0 1.25rem;
      padding: 1rem 1.25rem;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #fff9f1;
      display: grid;
      gap: 0.35rem;
      text-decoration: none;
      color: #111827;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.06);
      cursor: pointer;
    }
    .leads-card h2 {
      margin: 0;
      font-size: 1.1rem;
      font-weight: 700;
      color: #1f2937;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .leads-card ul {
      margin: 0;
      padding-left: 1.1rem;
      color: #374151;
      line-height: 1.5;
    }
    .employee-meta {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .employee-meta__pill {
      background: #eef2ff;
      color: #4338ca;
      padding: 0.4rem 0.75rem;
      border-radius: 999px;
      font-weight: 700;
      font-size: 0.95rem;
    }
    .filters {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.75rem;
      margin: 1rem 0 0.5rem;
    }
    .filters input,
    .filters select,
    .filters button {
      width: 100%;
      padding: 0.6rem 0.7rem;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      font: inherit;
    }
    .filters button {
      background: #1f4b99;
      color: #ffffff;
      font-weight: 700;
      cursor: pointer;
      border-color: #1f4b99;
    }
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    .logout-link {
      color: #d92b2b;
      font-weight: 700;
      text-decoration: none;
      border: 1px solid #f3c0c0;
      padding: 0.5rem 0.85rem;
      border-radius: 10px;
      background: #fff5f5;
      transition: background 0.2s ease, transform 0.1s ease;
    }
    .logout-link:hover {
      background: #ffe8e8;
      transform: translateY(-1px);
    }
    .primary-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      background: #1f4b99;
      color: #ffffff;
      text-decoration: none;
      font-weight: 700;
      padding: 0.65rem 1rem;
      border-radius: 10px;
      border: 1px solid #1f4b99;
      transition: background 0.2s ease, transform 0.1s ease;
    }
    .primary-button:hover {
      background: #173b77;
      transform: translateY(-1px);
    }
    .customers-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
      background: #ffffff;
    }
    .customers-table th,
    .customers-table td {
      border: 1px solid #e5e7eb;
      padding: 0.75rem 0.8rem;
      text-align: left;
      font-size: 0.95rem;
    }
    .customers-table th {
      background: #f9fafb;
      font-weight: 700;
      color: #111827;
    }
    .empty-state {
      margin: 1rem 0 0;
      padding: 1rem 1.25rem;
      border-radius: 12px;
      border: 1px dashed #cbd5e1;
      background: #f8fafc;
      color: #475569;
    }
    .table-link {
      color: #1f4b99;
      font-weight: 700;
      text-decoration: none;
    }
    .tasks-widget {
      margin: 0.75rem 0 1.25rem;
      padding: 1rem 1.25rem;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #ffffff;
      box-shadow: 0 12px 26px rgba(0, 0, 0, 0.05);
    }
    .tasks-widget__header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-bottom: 0.5rem;
    }
    .tasks-widget__title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: #111827;
    }
    .tasks-widget__list {
      list-style: none;
      padding: 0;
      margin: 0.5rem 0 0;
      display: grid;
      gap: 0.45rem;
    }
    .tasks-widget__item {
      padding: 0.6rem 0.65rem;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      background: #f8fafc;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 0.35rem 0.75rem;
      align-items: center;
    }
    .tasks-widget__item-title {
      margin: 0;
      font-weight: 700;
      color: #0f172a;
    }
    .tasks-widget__meta {
      margin: 0;
      font-size: 0.9rem;
      color: #475569;
    }
    .tasks-widget__badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.25rem 0.55rem;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 700;
      border: 1px solid #cbd5e1;
      color: #0f172a;
      background: #fff;
    }
    .tasks-widget__badges {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      flex-wrap: wrap;
    }
    .tasks-widget__section-title {
      margin: 0.75rem 0 0.25rem;
      font-size: 0.95rem;
      color: #334155;
      font-weight: 700;
    }
    .tasks-widget__empty {
      margin: 0.25rem 0 0;
      color: #475569;
    }
    .tasks-widget__view-all {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.5rem 0.7rem;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      text-decoration: none;
      font-weight: 700;
      color: #1f4b99;
      background: #f8fafc;
    }
  </style>
</head>
<body>
  <div class="employee-shell">
    <div class="employee-card">
      <div class="employee-header">
        <div>
          <h1 class="employee-title">Employee Dashboard</h1>
          <p class="employee-subtitle">View and manage all customers registered with Dakshayani Enterprises.</p>
        </div>
        <div class="employee-meta">
          <span class="employee-meta__pill">Logged in as <?= employee_dashboard_safe($employee['name'] ?? $employee['login_id'] ?? 'Employee') ?></span>
          <a class="primary-button" href="employee-tasks.php">My Tasks</a>
          <a class="primary-button" href="admin-users.php?tab=customers">+ Add Customer</a>
          <a class="primary-button" href="employee-documents.php">Quotations &amp; Challans</a>
          <a class="primary-button" href="admin-documents.php?tab=items&sub=inventory">Inventory</a>
          <a class="logout-link" href="logout.php">Log out</a>
        </div>
      </div>

      <a class="complaints-card" href="complaints-overview.php">
        <div>
          <p class="complaints-card__title">Customer Complaints</p>
          <p class="complaints-card__meta">Stay on top of customer issues.</p>
          <p class="complaints-card__counts">Open: <?= number_format((int) $complaintCounts['open']) ?> · Unassigned: <?= number_format((int) $complaintCounts['unassigned']) ?></p>
        </div>
        <div>
          <p class="complaints-card__counts" style="text-align:right;">Total <?= number_format((int) $complaintCounts['total']) ?></p>
          <p class="complaints-card__cta">View all <span aria-hidden="true">→</span></p>
        </div>
      </a>

      <a class="leads-card" href="leads-dashboard.php">
        <h2><span aria-hidden="true">📇</span> Leads</h2>
        <ul>
          <li>Total: <?= number_format((int) $leadStats['total_leads']) ?></li>
          <li>New: <?= number_format((int) $leadStats['new_leads']) ?></li>
          <li>Site Visit Needed: <?= number_format((int) $leadStats['site_visit_needed']) ?></li>
          <li>Quotation Sent: <?= number_format((int) $leadStats['quotation_sent']) ?></li>
          <li>Today's Follow-ups: <?= number_format((int) $leadStats['today_followups']) ?></li>
          <li>Overdue: <?= number_format((int) $leadStats['overdue_followups']) ?></li>
        </ul>
      </a>

      <div class="tasks-widget" aria-labelledby="pending-tasks-title">
        <div class="tasks-widget__header">
          <div>
            <p id="pending-tasks-title" class="tasks-widget__title">My Pending Tasks</p>
            <p class="tasks-widget__meta" style="margin:0;color:#475569;">Overdue, due today, and upcoming</p>
          </div>
          <a class="tasks-widget__view-all" href="employee-tasks.php">View all tasks</a>
        </div>

        <?php if (!$hasPendingTasks): ?>
          <p class="tasks-widget__empty">No pending tasks 🎉</p>
        <?php else: ?>
          <?php if ($overdueTasks !== []): ?>
            <p class="tasks-widget__section-title">Overdue</p>
            <ul class="tasks-widget__list">
              <?php foreach ($overdueTasks as $task): ?>
                <?php
                  $dueDate = $formatTaskDate(get_effective_due_date($task));
                  $priority = (string) ($task['priority'] ?? 'Medium');
                ?>
                <li class="tasks-widget__item">
                  <div>
                    <p class="tasks-widget__item-title"><?= employee_dashboard_safe((string) ($task['title'] ?? 'Task')) ?></p>
                    <p class="tasks-widget__meta">Due <?= employee_dashboard_safe($dueDate) ?></p>
                    <div class="tasks-widget__badges">
                      <span class="tasks-widget__badge"><?= employee_dashboard_safe($priority) ?></span>
                      <span class="tasks-widget__badge" style="background:#fef2f2;color:#b91c1c;border-color:#fecdd3;">Overdue</span>
                      <span class="tasks-widget__badge">Open</span>
                    </div>
                  </div>
                  <div aria-hidden="true" style="font-size:1.25rem;color:#b91c1c;">!</div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($todayTasks !== []): ?>
            <p class="tasks-widget__section-title">Due today</p>
            <ul class="tasks-widget__list">
              <?php foreach ($todayTasks as $task): ?>
                <?php
                  $dueDate = $formatTaskDate(get_effective_due_date($task));
                  $priority = (string) ($task['priority'] ?? 'Medium');
                ?>
                <li class="tasks-widget__item">
                  <div>
                    <p class="tasks-widget__item-title"><?= employee_dashboard_safe((string) ($task['title'] ?? 'Task')) ?></p>
                    <p class="tasks-widget__meta">Due <?= employee_dashboard_safe($dueDate) ?></p>
                    <div class="tasks-widget__badges">
                      <span class="tasks-widget__badge"><?= employee_dashboard_safe($priority) ?></span>
                      <span class="tasks-widget__badge" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe;">Today</span>
                      <span class="tasks-widget__badge">Open</span>
                    </div>
                  </div>
                  <div aria-hidden="true" style="font-size:1.2rem;color:#4338ca;">•</div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($upcomingTasks !== []): ?>
            <p class="tasks-widget__section-title">Upcoming (next 7 days)</p>
            <ul class="tasks-widget__list">
              <?php foreach ($upcomingTasks as $task): ?>
                <?php
                  $dueDate = $formatTaskDate(get_effective_due_date($task));
                  $priority = (string) ($task['priority'] ?? 'Medium');
                ?>
                <li class="tasks-widget__item">
                  <div>
                    <p class="tasks-widget__item-title"><?= employee_dashboard_safe((string) ($task['title'] ?? 'Task')) ?></p>
                    <p class="tasks-widget__meta">Due <?= employee_dashboard_safe($dueDate) ?></p>
                    <div class="tasks-widget__badges">
                      <span class="tasks-widget__badge"><?= employee_dashboard_safe($priority) ?></span>
                      <span class="tasks-widget__badge" style="background:#ecfeff;color:#0f172a;border-color:#bae6fd;">Upcoming</span>
                      <span class="tasks-widget__badge">Open</span>
                    </div>
                  </div>
                  <div aria-hidden="true" style="font-size:1.2rem;color:#0f172a;">→</div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <form class="filters" method="get">
        <div>
          <label for="search" class="sr-only">Search</label>
          <input id="search" type="search" name="search" placeholder="Search by name or mobile" value="<?= employee_dashboard_safe($searchTerm) ?>" />
        </div>
        <div>
          <label for="status" class="sr-only">Status</label>
          <select id="status" name="status">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option>
            <?php foreach ($statusOptions as $statusOption): ?>
              <option value="<?= employee_dashboard_safe($statusOption) ?>"<?= strcasecmp($statusFilter, $statusOption) === 0 ? ' selected' : '' ?>><?= employee_dashboard_safe($statusOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="customer_type" class="sr-only">Customer type</label>
          <select id="customer_type" name="customer_type">
            <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>>All types</option>
            <option value="PM Surya Ghar"<?= $typeFilter === 'PM Surya Ghar' ? ' selected' : '' ?>>PM Surya Ghar</option>
            <option value="Non PM Surya Ghar"<?= $typeFilter === 'Non PM Surya Ghar' ? ' selected' : '' ?>>Non PM Surya Ghar</option>
          </select>
        </div>
        <div>
          <button type="submit">Apply filters</button>
        </div>
      </form>

      <?php if ($customers === []): ?>
        <div class="empty-state">No customers available.</div>
      <?php else: ?>
        <div class="admin-table-wrapper">
          <table class="customers-table" aria-label="Customer list">
            <thead>
              <tr>
                <th scope="col">Mobile number</th>
                <th scope="col">Name</th>
                <th scope="col">Customer type</th>
                <th scope="col">City</th>
                <th scope="col">District</th>
                <th scope="col">Status</th>
                <th scope="col">Complaints raised</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($customers as $customer): ?>
              <tr>
                <td><?= employee_dashboard_safe($customer['mobile'] ?? '') ?></td>
                <td><?= employee_dashboard_safe($customer['name'] ?? '') ?></td>
                <td><?= employee_dashboard_safe($customer['customer_type'] ?? '') ?></td>
                <td><?= employee_dashboard_safe($customer['city'] ?? '') ?></td>
                <td><?= employee_dashboard_safe($customer['district'] ?? '') ?></td>
                <td><?= employee_dashboard_safe($customer['status'] ?? '') ?></td>
                <td><?= ($customer['complaints_raised'] ?? '') === 'Yes' ? 'Yes' : 'No' ?></td>
                <td><a class="table-link" href="admin-users.php?tab=customers&amp;view=<?= urlencode((string) ($customer['mobile'] ?? '')) ?>">View / Edit</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
