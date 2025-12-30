<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/customer_admin.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/task_storage.php';

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

function employee_tasks_storage_dir(): string
{
    return __DIR__ . '/data/tasks';
}

function employee_tasks_file_path(): string
{
    return employee_tasks_storage_dir() . '/tasks.json';
}

function employee_tasks_ensure_directory(): void
{
    $dir = employee_tasks_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function load_tasks(): array
{
    return tasks_load_all();
}

function save_tasks(array $tasks): void
{
    tasks_save_all($tasks);
}

function generate_task_id(): string
{
    return tasks_generate_id();
}

function employee_dashboard_parse_date(string $value, ?DateTimeImmutable $fallback = null): DateTimeImmutable
{
    return tasks_parse_date($value, $fallback);
}

function employee_dashboard_next_due_date(string $frequency, DateTimeImmutable $currentDueDate, int $customDays): DateTimeImmutable
{
    return tasks_next_due_date($frequency, $currentDueDate, $customDays);
}

function employee_dashboard_frequency_label(array $task): string
{
    return tasks_frequency_label($task);
}

$taskErrors = [];
$taskSuccess = '';
$tasks = load_tasks();
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['task_action'] ?? '');

    if ($action === 'create_task') {
        $title = trim((string) ($_POST['task_title'] ?? ''));
        $description = trim((string) ($_POST['task_description'] ?? ''));
        $priorityInput = strtolower((string) ($_POST['task_priority'] ?? ''));
        $priority = in_array($priorityInput, ['low', 'medium', 'high'], true) ? ucfirst($priorityInput) : 'Low';
        $frequency = (string) ($_POST['task_frequency'] ?? 'once');
        $customDays = (int) ($_POST['task_custom_days'] ?? 0);
        $startDateInput = trim((string) ($_POST['task_start_date'] ?? ''));
        $startDate = employee_dashboard_parse_date($startDateInput, new DateTimeImmutable('today'));
        $dueDateInput = trim((string) ($_POST['task_due_date'] ?? ''));

        if ($title === '') {
            $taskErrors[] = 'Title is required.';
        }

        $allowedFrequencies = ['once', 'daily', 'weekly', 'monthly', 'custom'];
        if (!in_array($frequency, $allowedFrequencies, true)) {
            $frequency = 'once';
        }

        if ($frequency === 'custom' && $customDays <= 0) {
            $taskErrors[] = 'Please provide the number of days for the custom frequency.';
        }

        $chosenDueDate = $dueDateInput !== '' ? employee_dashboard_parse_date($dueDateInput, $startDate) : $startDate;

        if ($taskErrors === []) {
            $existingIds = array_column($tasks, 'id');
            $taskId = generate_task_id();
            while (in_array($taskId, $existingIds, true)) {
                $taskId = generate_task_id();
            }

            $baseTimestamps = date('Y-m-d H:i:s');
            $nextDueDate = $frequency === 'once'
                ? $chosenDueDate
                : employee_dashboard_parse_date($dueDateInput !== '' ? $dueDateInput : $startDate->format('Y-m-d'), $startDate);

            $tasks[] = [
                'id' => $taskId,
                'title' => $title,
                'description' => $description,
                'created_by_type' => 'employee',
                'created_by_id' => (string) ($employee['id'] ?? ''),
                'assigned_to_type' => 'employee',
                'assigned_to_id' => (string) ($employee['id'] ?? ''),
                'assigned_to_name' => (string) ($employee['name'] ?? $employee['login_id'] ?? ''),
                'priority' => $priority,
                'frequency_type' => $frequency,
                'frequency_custom_days' => $frequency === 'custom' ? $customDays : 0,
                'start_date' => $startDate->format('Y-m-d'),
                'due_date' => $chosenDueDate->format('Y-m-d'),
                'next_due_date' => $nextDueDate->format('Y-m-d'),
                'last_completed_at' => '',
                'status' => 'Open',
                'completion_log' => [],
                'created_at' => $baseTimestamps,
                'updated_at' => $baseTimestamps,
                'archived_flag' => false,
            ];

            save_tasks($tasks);
            $taskSuccess = 'Task created successfully.';
        }
    } elseif ($action === 'complete_task') {
        $taskId = (string) ($_POST['task_id'] ?? '');
        $completionNote = trim((string) ($_POST['completion_note'] ?? ''));
        foreach ($tasks as $index => $task) {
            $assignedTo = (string) ($task['assigned_to_id'] ?? '');
            $isArchived = (bool) ($task['archived_flag'] ?? false);
            if (($task['id'] ?? '') !== $taskId || $assignedTo !== (string) ($employee['id'] ?? '') || $isArchived) {
                continue;
            }

            $tasks[$index]['completion_log'] = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
            $now = date('Y-m-d H:i:s');
            $tasks[$index]['completion_log'][] = [
                'completed_at' => $now,
                'completed_by_type' => 'employee',
                'completed_by_id' => (string) ($employee['id'] ?? ''),
                'note' => $completionNote,
            ];

            $tasks[$index]['last_completed_at'] = $now;
            $tasks[$index]['updated_at'] = $now;

            $frequency = (string) ($task['frequency_type'] ?? 'once');
            $customDays = (int) ($task['frequency_custom_days'] ?? 0);
            $status = (string) ($task['status'] ?? 'Open');
            $nextDueDateStr = (string) ($task['next_due_date'] ?? '');
            $dueDateFallback = (string) ($task['due_date'] ?? '');
            $currentDueDate = $nextDueDateStr !== ''
                ? employee_dashboard_parse_date($nextDueDateStr, $today)
                : employee_dashboard_parse_date($dueDateFallback !== '' ? $dueDateFallback : $today->format('Y-m-d'), $today);

            if ($frequency === 'once') {
                $tasks[$index]['status'] = 'Completed';
            } else {
                $tasks[$index]['status'] = $status === 'Completed' ? 'Completed' : 'Open';
                $newDueDate = employee_dashboard_next_due_date($frequency, $currentDueDate, $customDays);
                $tasks[$index]['next_due_date'] = $newDueDate->format('Y-m-d');
                $tasks[$index]['due_date'] = $newDueDate->format('Y-m-d');
            }

            save_tasks($tasks);
            $taskSuccess = 'Task marked as completed.';
            break;
        }
    }
}

$myTasks = array_values(array_filter($tasks, function ($task) use ($employee): bool {
    $assignedTo = (string) ($task['assigned_to_id'] ?? '');
    $isArchived = (bool) ($task['archived_flag'] ?? false);
    return $assignedTo === (string) ($employee['id'] ?? '') && !$isArchived;
}));

$groupedTasks = [
    'overdue' => [],
    'today' => [],
    'upcoming' => [],
];

foreach ($myTasks as $task) {
    $status = (string) ($task['status'] ?? 'Open');
    $nextDueDateString = (string) ($task['next_due_date'] ?? ($task['due_date'] ?? ''));
    $nextDueDate = $nextDueDateString !== '' ? employee_dashboard_parse_date($nextDueDateString, $today) : $today;
    $key = 'upcoming';

    if (strcasecmp($status, 'Completed') === 0) {
        $key = 'upcoming';
    } elseif ($nextDueDate < $today) {
        $key = 'overdue';
    } elseif ($nextDueDate->format('Y-m-d') === $today->format('Y-m-d')) {
        $key = 'today';
    }

    $groupedTasks[$key][] = $task + ['_computed_next_due' => $nextDueDate->format('Y-m-d')];
}

foreach ($groupedTasks as $groupKey => $tasksList) {
    usort($tasksList, function ($a, $b): int {
        return strcmp((string) ($a['_computed_next_due'] ?? ''), (string) ($b['_computed_next_due'] ?? ''));
    });
    $groupedTasks[$groupKey] = $tasksList;
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
    .tasks-section {
      margin-top: 1rem;
    }
    .tasks-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 1rem;
    }
    @media (max-width: 960px) {
      .tasks-grid {
        grid-template-columns: 1fr;
      }
    }
    .tasks-card {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #ffffff;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.04);
      padding: 1rem 1.25rem;
    }
    .tasks-card h2 {
      margin: 0 0 0.75rem;
      font-size: 1.2rem;
      color: #111827;
    }
    .tasks-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 0.5rem;
    }
    .tasks-table th,
    .tasks-table td {
      border: 1px solid #e5e7eb;
      padding: 0.65rem 0.7rem;
      text-align: left;
      font-size: 0.95rem;
    }
    .tasks-table th {
      background: #f9fafb;
      font-weight: 700;
      color: #111827;
    }
    .task-row--overdue {
      background: #fff1f1;
    }
    .task-row--today {
      background: #fff9e6;
    }
    .task-row--completed {
      background: #ecfdf3;
    }
    .task-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.15rem 0.5rem;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 700;
    }
    .task-badge--priority-low {
      background: #e0f2fe;
      color: #0b66c2;
    }
    .task-badge--priority-medium {
      background: #fef3c7;
      color: #b45309;
    }
    .task-badge--priority-high {
      background: #fee2e2;
      color: #b91c1c;
    }
    .task-badge--status-open {
      background: #eef2ff;
      color: #4338ca;
    }
    .task-badge--status-completed {
      background: #dcfce7;
      color: #15803d;
    }
    .task-actions form {
      margin: 0;
    }
    .task-actions button {
      background: #15803d;
      border: 1px solid #15803d;
      color: #ffffff;
      padding: 0.45rem 0.7rem;
      border-radius: 8px;
      font-weight: 700;
      cursor: pointer;
    }
    .task-actions button:hover {
      background: #0f6a32;
    }
    .task-note-input {
      width: 100%;
      padding: 0.4rem 0.5rem;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font: inherit;
      margin-top: 0.35rem;
    }
    .task-group-title {
      margin: 1rem 0 0.35rem;
      font-size: 1.05rem;
      color: #111827;
    }
    .task-empty {
      padding: 0.8rem 1rem;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      color: #475569;
    }
    .task-form label {
      display: block;
      margin-bottom: 0.25rem;
      font-weight: 700;
      color: #111827;
    }
    .task-form input,
    .task-form select,
    .task-form textarea {
      width: 100%;
      padding: 0.6rem 0.7rem;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      font: inherit;
      margin-bottom: 0.75rem;
    }
    .task-form textarea {
      min-height: 100px;
      resize: vertical;
    }
    .task-form button {
      background: #1f4b99;
      color: #ffffff;
      border: 1px solid #1f4b99;
      border-radius: 10px;
      padding: 0.7rem 1rem;
      font-weight: 700;
      cursor: pointer;
    }
    .task-form button:hover {
      background: #173b77;
    }
    .alert {
      padding: 0.75rem 1rem;
      border-radius: 10px;
      margin: 0.5rem 0 1rem;
      font-weight: 600;
    }
    .alert--error {
      background: #fef2f2;
      border: 1px solid #fecdd3;
      color: #b91c1c;
    }
    .alert--success {
      background: #ecfdf3;
      border: 1px solid #bbf7d0;
      color: #166534;
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
          <a class="primary-button" href="admin-users.php?tab=customers">+ Add Customer</a>
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

      <div class="tasks-section">
        <div class="tasks-grid">
          <div class="tasks-card">
            <h2>My Tasks</h2>

            <?php if ($taskErrors !== []): ?>
              <div class="alert alert--error">
                <?= employee_dashboard_safe(implode(' ', $taskErrors)) ?>
              </div>
            <?php endif; ?>

            <?php if ($taskSuccess !== ''): ?>
              <div class="alert alert--success">
                <?= employee_dashboard_safe($taskSuccess) ?>
              </div>
            <?php endif; ?>

            <?php
            $groupLabels = [
              'overdue' => 'Overdue',
              'today' => 'Due Today',
              'upcoming' => 'Upcoming',
            ];
            ?>

            <?php foreach ($groupLabels as $groupKey => $label): ?>
              <h3 class="task-group-title"><?= employee_dashboard_safe($label) ?></h3>
              <?php if (empty($groupedTasks[$groupKey])): ?>
                <div class="task-empty">No tasks in this section.</div>
              <?php else: ?>
                <div class="admin-table-wrapper">
                  <table class="tasks-table" aria-label="<?= employee_dashboard_safe($label) ?>">
                    <thead>
                      <tr>
                        <th scope="col">Due / Next Due</th>
                        <th scope="col">Title</th>
                        <th scope="col">Frequency</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($groupedTasks[$groupKey] as $task): ?>
                        <?php
                          $statusLabel = (string) ($task['status'] ?? 'Open');
                          $priorityLabel = ucfirst(strtolower((string) ($task['priority'] ?? 'Low')));
                          $nextDueDisplay = (string) ($task['_computed_next_due'] ?? ($task['next_due_date'] ?? $task['due_date'] ?? ''));
                          $rowClass = '';
                          if (strcasecmp($statusLabel, 'Completed') === 0) {
                              $rowClass = 'task-row--completed';
                          } elseif ($groupKey === 'overdue') {
                              $rowClass = 'task-row--overdue';
                          } elseif ($groupKey === 'today') {
                              $rowClass = 'task-row--today';
                          }
                        ?>
                        <tr class="<?= employee_dashboard_safe($rowClass) ?>">
                          <td><?= employee_dashboard_safe($nextDueDisplay) ?></td>
                          <td>
                            <div style="font-weight: 700; color: #111827;"><?= employee_dashboard_safe((string) ($task['title'] ?? '')) ?></div>
                            <?php if (!empty($task['description'])): ?>
                              <div style="color: #4b5563; font-size: 0.9rem; margin-top: 0.25rem;"><?= employee_dashboard_safe((string) $task['description']) ?></div>
                            <?php endif; ?>
                          </td>
                          <td><?= employee_dashboard_safe(employee_dashboard_frequency_label($task)) ?></td>
                          <td>
                            <?php
                              $priorityClass = 'task-badge--priority-low';
                              if (strcasecmp($priorityLabel, 'High') === 0) {
                                  $priorityClass = 'task-badge--priority-high';
                              } elseif (strcasecmp($priorityLabel, 'Medium') === 0) {
                                  $priorityClass = 'task-badge--priority-medium';
                              }
                            ?>
                            <span class="task-badge <?= employee_dashboard_safe($priorityClass) ?>"><?= employee_dashboard_safe($priorityLabel) ?></span>
                          </td>
                          <td>
                            <?php
                              $statusClass = strcasecmp($statusLabel, 'Completed') === 0 ? 'task-badge--status-completed' : 'task-badge--status-open';
                            ?>
                            <span class="task-badge <?= employee_dashboard_safe($statusClass) ?>"><?= employee_dashboard_safe($statusLabel) ?></span>
                          </td>
                          <td class="task-actions">
                            <?php if (strcasecmp($statusLabel, 'Open') === 0): ?>
                              <form method="post">
                                <input type="hidden" name="task_action" value="complete_task" />
                                <input type="hidden" name="task_id" value="<?= employee_dashboard_safe((string) ($task['id'] ?? '')) ?>" />
                                <label for="note-<?= employee_dashboard_safe((string) ($task['id'] ?? '')) ?>" class="sr-only">Completion note</label>
                                <input id="note-<?= employee_dashboard_safe((string) ($task['id'] ?? '')) ?>" class="task-note-input" type="text" name="completion_note" placeholder="Optional note" />
                                <button type="submit">Mark Completed</button>
                              </form>
                            <?php else: ?>
                              <span style="color:#4b5563;">Completed on <?= employee_dashboard_safe((string) ($task['last_completed_at'] ?? '')) ?></span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <div class="tasks-card">
            <h2>Create My Task</h2>
            <form class="task-form" method="post">
              <input type="hidden" name="task_action" value="create_task" />
              <label for="task_title">Title *</label>
              <input id="task_title" type="text" name="task_title" required />

              <label for="task_description">Description</label>
              <textarea id="task_description" name="task_description"></textarea>

              <label for="task_priority">Priority</label>
              <select id="task_priority" name="task_priority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>

              <label for="task_frequency">Frequency</label>
              <select id="task_frequency" name="task_frequency">
                <option value="once">Once</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom (every N days)</option>
              </select>

              <div id="custom_days_wrapper" style="display:none;">
                <label for="task_custom_days">Custom days (for custom frequency)</label>
                <input id="task_custom_days" type="number" name="task_custom_days" min="1" value="0" />
              </div>

              <label for="task_start_date">Start date</label>
              <input id="task_start_date" type="date" name="task_start_date" value="<?= employee_dashboard_safe($today->format('Y-m-d')) ?>" />

              <label for="task_due_date">Due date / Next due date</label>
              <input id="task_due_date" type="date" name="task_due_date" value="<?= employee_dashboard_safe($today->format('Y-m-d')) ?>" />

              <button type="submit">Create Task</button>
            </form>
          </div>
        </div>
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
  <script>
    (function() {
      var frequencySelect = document.getElementById('task_frequency');
      var customWrapper = document.getElementById('custom_days_wrapper');
      if (!frequencySelect || !customWrapper) {
        return;
      }
      var toggleCustom = function() {
        customWrapper.style.display = frequencySelect.value === 'custom' ? 'block' : 'none';
      };
      frequencySelect.addEventListener('change', toggleCustom);
      toggleCustom();
    })();
  </script>
</body>
</html>
