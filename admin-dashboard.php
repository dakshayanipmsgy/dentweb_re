<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/task_storage.php';
require_once __DIR__ . '/includes/employee_admin.php';

require_admin();
$user = current_user();
$db = get_db();

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
$flashIcons = [
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'error' => 'fa-circle-exclamation',
    'info' => 'fa-circle-info',
];
$flashIcon = $flashIcons[$flashTone];

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

$counts = admin_overview_counts($db);

$complaints = load_all_complaints();
$complaintCounts = complaint_summary_counts($complaints);
$openComplaints = array_filter($complaints, static function (array $item): bool {
    $status = strtolower((string) ($item['status'] ?? 'open'));
    return $status !== 'closed';
});

usort($openComplaints, static function (array $left, array $right): int {
    $leftTime = (string) ($left['updated_at'] ?? $left['created_at'] ?? '');
    $rightTime = (string) ($right['updated_at'] ?? $right['created_at'] ?? '');

    return strcmp($rightTime, $leftTime);
});

$highlightComplaints = array_slice($openComplaints, 0, 3);
$openComplaintCount = count($openComplaints);

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
$prefix = $basePath === '' ? '' : $basePath;
$pathFor = static function (string $path) use ($prefix): string {
    $clean = ltrim($path, '/');
    return ($prefix === '' ? '' : $prefix) . '/' . $clean;
};

$portalClock = portal_current_time();
$portalTimeIso = (string) ($portalClock['iso'] ?? '');
$portalTimeDisplay = (string) ($portalClock['display'] ?? '');
$portalTimeLabel = (string) ($portalClock['label'] ?? 'IST');

$complaintStatusLabels = [
    'open' => 'Open',
    'intake' => 'Intake',
    'triage' => 'Admin triage',
    'work' => 'In progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];

$leadStats = get_lead_stats_for_dashboard();

$employeeStore = new EmployeeFsStore();
$employees = $employeeStore->listEmployees();

$taskErrors = [];
$taskSuccess = '';
$tasks = tasks_load_all();
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskAction = (string) ($_POST['task_action'] ?? '');
    if (in_array($taskAction, ['admin_assign_task', 'admin_create_task'], true)) {
        $title = trim((string) ($_POST['task_title'] ?? ''));
        $description = trim((string) ($_POST['task_description'] ?? ''));
        $priorityInput = strtolower((string) ($_POST['task_priority'] ?? ''));
        $priority = in_array($priorityInput, ['low', 'medium', 'high'], true) ? ucfirst($priorityInput) : 'Low';
        $frequency = (string) ($_POST['task_frequency'] ?? 'once');
        $customDays = (int) ($_POST['task_custom_days'] ?? 0);
        $startDateInput = trim((string) ($_POST['task_start_date'] ?? ''));
        $dueDateInput = trim((string) ($_POST['task_due_date'] ?? ''));
        $assignedEmployeeId = trim((string) ($_POST['assigned_to_employee'] ?? ''));

        if ($title === '') {
            $taskErrors[] = 'Title is required.';
        }
        if ($assignedEmployeeId === '') {
            $taskErrors[] = 'Please select an employee.';
        }

        $allowedFrequencies = ['once', 'daily', 'weekly', 'monthly', 'custom'];
        if (!in_array($frequency, $allowedFrequencies, true)) {
            $frequency = 'once';
        }
        if ($frequency === 'custom' && $customDays <= 0) {
            $taskErrors[] = 'Please provide the number of days for the custom frequency.';
        }

        $startDate = tasks_parse_date($startDateInput, new DateTimeImmutable('today'));
        $chosenDueDate = $dueDateInput !== '' ? tasks_parse_date($dueDateInput, $startDate) : $startDate;
        $nextDueDate = $frequency === 'once'
            ? $chosenDueDate
            : tasks_parse_date($dueDateInput !== '' ? $dueDateInput : $startDate->format('Y-m-d'), $startDate);

        $assignedEmployee = null;
        foreach ($employees as $employeeRow) {
            if (($employeeRow['id'] ?? '') === $assignedEmployeeId) {
                $assignedEmployee = $employeeRow;
                break;
            }
        }
        if ($assignedEmployee === null) {
            $taskErrors[] = 'Selected employee could not be found.';
        }

        if ($taskErrors === []) {
            $existingIds = array_column($tasks, 'id');
            $taskId = tasks_generate_id();
            while (in_array($taskId, $existingIds, true)) {
                $taskId = tasks_generate_id();
            }

            $baseTimestamps = date('Y-m-d H:i:s');

            $tasks[] = [
                'id' => $taskId,
                'title' => $title,
                'description' => $description,
                'created_by_type' => 'admin',
                'created_by_id' => (string) ($user['id'] ?? ''),
                'created_by_name' => (string) ($user['full_name'] ?? 'Administrator'),
                'assigned_to_type' => 'employee',
                'assigned_to_id' => $assignedEmployeeId,
                'assigned_to_name' => (string) ($assignedEmployee['name'] ?? $assignedEmployee['login_id'] ?? ''),
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

            if (!tasks_save_all($tasks)) {
                $taskErrors[] = 'Could not save the new task. Please try again.';
            } else {
                set_flash('success', 'Task assigned successfully.');
                header('Location: admin-dashboard.php#tasks');
                exit;
            }
        }
    } elseif ($taskAction === 'admin_complete_task') {
        $taskId = (string) ($_POST['task_id'] ?? '');
        $completionNote = trim((string) ($_POST['completion_note'] ?? ''));
        foreach ($tasks as $index => $task) {
            if (($task['id'] ?? '') !== $taskId || (bool) ($task['archived_flag'] ?? false)) {
                continue;
            }

            $tasks[$index]['completion_log'] = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
            $now = date('Y-m-d H:i:s');
            $tasks[$index]['completion_log'][] = [
                'completed_at' => $now,
                'completed_by_type' => 'admin',
                'completed_by_id' => (string) ($user['id'] ?? ''),
                'completed_by_name' => (string) ($user['full_name'] ?? 'Administrator'),
                'note' => $completionNote,
            ];

            $tasks[$index]['last_completed_at'] = $now;
            $tasks[$index]['updated_at'] = $now;

            $frequency = (string) ($task['frequency_type'] ?? 'once');
            $customDays = (int) ($task['frequency_custom_days'] ?? 0);
            $nextDueDateStr = (string) ($task['next_due_date'] ?? '');
            $dueDateFallback = (string) ($task['due_date'] ?? '');
            $currentDueDate = $nextDueDateStr !== ''
                ? tasks_parse_date($nextDueDateStr, $today)
                : tasks_parse_date($dueDateFallback !== '' ? $dueDateFallback : $today->format('Y-m-d'), $today);

            if ($frequency === 'once') {
                $tasks[$index]['status'] = 'Completed';
            } else {
                $newDueDate = tasks_next_due_date($frequency, $currentDueDate, $customDays);
                $tasks[$index]['next_due_date'] = $newDueDate->format('Y-m-d');
                $tasks[$index]['due_date'] = $newDueDate->format('Y-m-d');
                $tasks[$index]['status'] = 'Open';
            }

            if (!tasks_save_all($tasks)) {
                $taskErrors[] = 'Could not update the task. Please try again.';
            } else {
                $taskSuccess = 'Task marked as completed.';
            }
            break;
        }
    } elseif ($taskAction === 'admin_archive_task') {
        $taskId = (string) ($_POST['task_id'] ?? '');
        foreach ($tasks as $index => $task) {
            if (($task['id'] ?? '') !== $taskId) {
                continue;
            }
            $tasks[$index]['archived_flag'] = true;
            $tasks[$index]['updated_at'] = date('Y-m-d H:i:s');
            if (!tasks_save_all($tasks)) {
                $taskErrors[] = 'Could not archive the task. Please try again.';
            } else {
                $taskSuccess = 'Task archived.';
            }
            break;
        }
    }
}

$taskArchiveFilter = (string) ($_GET['task_archive'] ?? 'active');
$taskStatusFilter = (string) ($_GET['task_status'] ?? 'all');
$taskEmployeeFilter = (string) ($_GET['task_employee'] ?? 'all');
$taskDueFilter = (string) ($_GET['task_due'] ?? 'all');

$filteredTasks = array_values(array_filter($tasks, static function (array $task) use ($taskArchiveFilter, $taskStatusFilter, $taskEmployeeFilter, $taskDueFilter, $today): bool {
    $isArchived = (bool) ($task['archived_flag'] ?? false);
    if ($taskArchiveFilter === 'active' && $isArchived) {
        return false;
    }
    if ($taskArchiveFilter === 'archived' && !$isArchived) {
        return false;
    }

    $status = strtolower((string) ($task['status'] ?? 'open'));
    if ($taskStatusFilter === 'open' && $status === 'completed') {
        return false;
    }
    if ($taskStatusFilter === 'completed' && $status !== 'completed') {
        return false;
    }

    if ($taskEmployeeFilter !== 'all' && (string) ($task['assigned_to_id'] ?? '') !== $taskEmployeeFilter) {
        return false;
    }

    $nextDueDateString = (string) ($task['next_due_date'] ?? ($task['due_date'] ?? ''));
    $nextDueDate = $nextDueDateString !== '' ? tasks_parse_date($nextDueDateString, $today) : $today;
    $isOverdue = $status !== 'completed' && $nextDueDate < $today;
    $isToday = $status !== 'completed' && $nextDueDate->format('Y-m-d') === $today->format('Y-m-d');
    $isUpcoming = $status !== 'completed' && $nextDueDate > $today;

    if ($taskDueFilter === 'overdue' && !$isOverdue) {
        return false;
    }
    if ($taskDueFilter === 'today' && !$isToday) {
        return false;
    }
    if ($taskDueFilter === 'upcoming' && !$isUpcoming) {
        return false;
    }

    return true;
}));

usort($filteredTasks, static function (array $left, array $right) use ($today): int {
    $leftDue = tasks_parse_date((string) ($left['next_due_date'] ?? $left['due_date'] ?? $today->format('Y-m-d')), $today);
    $rightDue = tasks_parse_date((string) ($right['next_due_date'] ?? $right['due_date'] ?? $today->format('Y-m-d')), $today);
    return strcmp($leftDue->format('Y-m-d'), $rightDue->format('Y-m-d'));
});

$activeTasks = array_filter($tasks, static fn(array $task): bool => !(bool) ($task['archived_flag'] ?? false));
$overdueTaskCount = count(array_filter($activeTasks, static function (array $task) use ($today): bool {
    $status = strtolower((string) ($task['status'] ?? 'open'));
    $nextDueDateString = (string) ($task['next_due_date'] ?? ($task['due_date'] ?? ''));
    $nextDueDate = $nextDueDateString !== '' ? tasks_parse_date($nextDueDateString, $today) : $today;
    return $status !== 'completed' && $nextDueDate < $today;
}));

$completedThisWeekCount = count(array_filter($activeTasks, static function (array $task) use ($today): bool {
    $lastCompleted = (string) ($task['last_completed_at'] ?? '');
    if ($lastCompleted === '') {
        return false;
    }
    try {
        $completedAt = new DateTimeImmutable($lastCompleted);
    } catch (Throwable $exception) {
        return false;
    }
    $weekStart = $today->modify('monday this week');
    return $completedAt >= $weekStart && $completedAt <= $today->modify('+1 day');
}));

$pendingTodayCount = count(array_filter($activeTasks, static function (array $task) use ($today): bool {
    $status = strtolower((string) ($task['status'] ?? 'open'));
    $nextDueDateString = (string) ($task['next_due_date'] ?? ($task['due_date'] ?? ''));
    $nextDueDate = $nextDueDateString !== '' ? tasks_parse_date($nextDueDateString, $today) : $today;
    return $status !== 'completed' && $nextDueDate->format('Y-m-d') === $today->format('Y-m-d');
}));

$employeeCompletionCounts = [];
foreach ($tasks as $task) {
    $logEntries = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
    foreach ($logEntries as $entry) {
        if (strtolower((string) ($entry['completed_by_type'] ?? '')) !== 'employee') {
            continue;
        }
        $employeeId = (string) ($entry['completed_by_id'] ?? '');
        if ($employeeId === '') {
            continue;
        }
        if (!isset($employeeCompletionCounts[$employeeId])) {
            $employeeCompletionCounts[$employeeId] = 0;
        }
        $employeeCompletionCounts[$employeeId]++;
    }
}

$cardConfigs = [];

$cardConfigs[] = [
    'key' => 'customer_complaints',
    'link' => $pathFor('complaints-overview.php'),
    'icon' => 'fa-headset',
    'label' => 'Customer Complaints',
    'value' => $complaintCounts['open'],
    'description' => sprintf('Total: %s · Unassigned: %s', number_format((int) $complaintCounts['total']), number_format((int) $complaintCounts['unassigned'])),
];

$cardConfigs[] = [
    'key' => 'leads',
    'link' => $pathFor('leads-dashboard.php'),
    'icon' => 'fa-address-card',
    'label' => 'Leads',
    'value' => $leadStats['total_leads'],
    'description' => sprintf(
        'New: %s · Site visit: %s · Quotes: %s · Today: %s · Overdue: %s',
        number_format((int) $leadStats['new_leads']),
        number_format((int) $leadStats['site_visit_needed']),
        number_format((int) $leadStats['quotation_sent']),
        number_format((int) $leadStats['today_followups']),
        number_format((int) $leadStats['overdue_followups'])
    ),
];

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Overview | Dakshayani Enterprises</title>
  <meta name="description" content="At-a-glance admin overview with live counts and recent activity across Dentweb operations." />
  <link rel="icon" href="<?= htmlspecialchars($pathFor('images/favicon.ico'), ENT_QUOTES) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars($pathFor('style.css'), ENT_QUOTES) ?>" />
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
    .admin-task-widgets {
      margin: 1.5rem 0 0;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.75rem;
    }
    .admin-task-widget {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 0.9rem 1rem;
      background: #ffffff;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.04);
      display: grid;
      gap: 0.25rem;
    }
    .admin-task-widget h3 {
      margin: 0;
      font-size: 1rem;
      color: #111827;
    }
    .admin-task-widget .value {
      font-size: 1.6rem;
      font-weight: 800;
      color: #1f4b99;
      line-height: 1.2;
    }
    .admin-task-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 1rem;
      margin-top: 1rem;
    }
    @media (max-width: 960px) {
      .admin-task-grid {
        grid-template-columns: 1fr;
      }
    }
    .admin-task-card {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #ffffff;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.04);
      padding: 1rem 1.25rem;
    }
    .admin-task-card h2 {
      margin: 0 0 0.5rem;
      font-size: 1.25rem;
      color: #111827;
    }
    .admin-task-filter {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 0.75rem;
      margin: 0.5rem 0 1rem;
    }
    .admin-task-filter select {
      width: 100%;
      padding: 0.6rem 0.7rem;
      border-radius: 10px;
      border: 1px solid #d1d5db;
      font: inherit;
    }
    .admin-task-table {
      width: 100%;
      border-collapse: collapse;
    }
    .admin-task-table th,
    .admin-task-table td {
      border: 1px solid #e5e7eb;
      padding: 0.6rem 0.7rem;
      text-align: left;
      font-size: 0.95rem;
      vertical-align: top;
    }
    .admin-task-table th {
      background: #f9fafb;
      font-weight: 700;
      color: #111827;
    }
    .admin-task-table .badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.2rem 0.55rem;
      font-weight: 700;
      font-size: 0.85rem;
    }
    .badge--open { background: #eef2ff; color: #4338ca; }
    .badge--completed { background: #dcfce7; color: #166534; }
    .badge--priority-low { background: #e0f2fe; color: #0b66c2; }
    .badge--priority-medium { background: #fef3c7; color: #b45309; }
    .badge--priority-high { background: #fee2e2; color: #b91c1c; }
    .admin-task-actions form { display: inline; }
    .admin-task-actions button {
      background: #1f4b99;
      border: 1px solid #1f4b99;
      color: #ffffff;
      padding: 0.45rem 0.7rem;
      border-radius: 8px;
      font-weight: 700;
      cursor: pointer;
      margin-right: 0.35rem;
      margin-top: 0.35rem;
    }
    .admin-task-actions button.btn-secondary {
      background: #e5e7eb;
      color: #111827;
      border-color: #d1d5db;
    }
    .admin-task-actions button.btn-ghost {
      background: #ffffff;
      color: #b91c1c;
      border-color: #fca5a5;
    }
    .admin-task-form label {
      display: block;
      margin-bottom: 0.25rem;
      font-weight: 700;
      color: #111827;
    }
    .admin-task-form input,
    .admin-task-form select,
    .admin-task-form textarea {
      width: 100%;
      padding: 0.6rem 0.7rem;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      font: inherit;
      margin-bottom: 0.75rem;
    }
    .admin-task-form textarea {
      min-height: 100px;
      resize: vertical;
    }
    .admin-task-form button {
      background: #1f4b99;
      color: #ffffff;
      border: 1px solid #1f4b99;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-weight: 700;
      cursor: pointer;
    }
    .admin-task-log {
      margin: 0.35rem 0 0;
      padding: 0.5rem 0.75rem;
      background: #f8fafc;
      border-radius: 10px;
      border: 1px dashed #cbd5e1;
      color: #475569;
      font-size: 0.9rem;
    }
  </style>
</head>
<body class="admin-overview" data-theme="light">
  <main class="admin-overview__shell">
    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <header class="admin-overview__header">
      <div class="admin-overview__identity">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <div>
          <p class="admin-overview__subtitle">Welcome back</p>
          <h1 class="admin-overview__title">Admin Overview</h1>
          <p class="admin-overview__user">Signed in as <strong><?= htmlspecialchars($user['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
        </div>
      </div>
      <div class="admin-overview__actions">
        <div class="dashboard-auth-time admin-overview__clock" role="status" aria-live="polite">
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          <div>
            <small>Current time (Kolkata)</small>
            <time datetime="<?= htmlspecialchars($portalTimeIso, ENT_QUOTES) ?>">
              <?= htmlspecialchars($portalTimeDisplay, ENT_QUOTES) ?> <?= htmlspecialchars($portalTimeLabel, ENT_QUOTES) ?>
            </time>
          </div>
        </div>
        <a href="<?= htmlspecialchars($pathFor('admin-users.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-users-gear" aria-hidden="true"></i>
          Users
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-requests.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-inbox" aria-hidden="true"></i>
          Requests
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-ai-studio.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
          AI Studio
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-smart-marketing.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
          Smart Marketing
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-handover-templates.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
          Handover Templates
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin/website-settings/'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-palette" aria-hidden="true"></i>
          Website Content &amp; Theme
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-blog-manager.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-newspaper" aria-hidden="true"></i>
          Blog Manager
        </a>
        <button type="button" class="btn btn-ghost" data-theme-toggle>
          <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
          Theme
        </button>
        <a href="<?= htmlspecialchars($pathFor('logout.php'), ENT_QUOTES) ?>" class="btn btn-primary">
          <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
          Log out
        </a>
      </div>
    </header>

    <section class="admin-overview__cards" aria-label="Operational summaries">
      <?php foreach ($cardConfigs as $card): ?>
      <?php
        $cardKey = isset($card['key']) ? (string) $card['key'] : '';
        $cardStateKey = isset($card['state_key']) ? (string) $card['state_key'] : '';
      ?>
      <a
        class="overview-card"
        href="<?= htmlspecialchars($card['link'], ENT_QUOTES) ?>"
        <?php if ($cardKey !== ''): ?> data-dashboard-card="<?= htmlspecialchars($cardKey, ENT_QUOTES) ?>"<?php endif; ?>
      >
        <div class="overview-card__icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($card['icon'], ENT_QUOTES) ?>"></i></div>
        <div class="overview-card__body">
          <p class="overview-card__label"><?= htmlspecialchars($card['label'], ENT_QUOTES) ?></p>
          <p
            class="overview-card__value"
            <?php if ($cardKey !== ''): ?> data-dashboard-count="<?= htmlspecialchars($cardKey, ENT_QUOTES) ?>"<?php endif; ?>
            <?php if ($cardStateKey !== ''): ?> data-customer-state-count="<?= htmlspecialchars($cardStateKey, ENT_QUOTES) ?>"<?php endif; ?>
          >
            <?= number_format((int) $card['value']) ?>
          </p>
          <p class="overview-card__meta"><?= htmlspecialchars($card['description'], ENT_QUOTES) ?></p>
        </div>
        <span class="overview-card__cta" aria-hidden="true">View list <i class="fa-solid fa-arrow-right"></i></span>
      </a>
      <?php endforeach; ?>
    </section>

    <section class="admin-task-widgets" aria-label="Task visibility">
      <div class="admin-task-widget">
        <h3>Tasks overdue</h3>
        <div class="value"><?= htmlspecialchars((string) $overdueTaskCount, ENT_QUOTES) ?></div>
      </div>
      <div class="admin-task-widget">
        <h3>Completed this week</h3>
        <div class="value"><?= htmlspecialchars((string) $completedThisWeekCount, ENT_QUOTES) ?></div>
      </div>
      <div class="admin-task-widget">
        <h3>Pending today</h3>
        <div class="value"><?= htmlspecialchars((string) $pendingTodayCount, ENT_QUOTES) ?></div>
      </div>
      <?php if ($employeeCompletionCounts !== []): ?>
      <div class="admin-task-widget">
        <h3>Employee completions</h3>
        <div class="value" style="font-size:1rem;">
          <?php foreach ($employeeCompletionCounts as $empId => $count): ?>
            <?php
              $empName = '';
              foreach ($employees as $empRow) {
                  if (($empRow['id'] ?? '') === $empId) {
                      $empName = (string) ($empRow['name'] ?? $empRow['login_id'] ?? $empId);
                      break;
                  }
              }
              if ($empName === '') {
                  $empName = $empId;
              }
            ?>
            <div><?= htmlspecialchars($empName, ENT_QUOTES) ?>: <strong><?= htmlspecialchars((string) $count, ENT_QUOTES) ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <section id="tasks" class="admin-task-grid" aria-label="Admin task management">
      <div class="admin-task-card">
        <h2>Tasks</h2>

        <?php if ($taskErrors !== []): ?>
          <div class="admin-alert admin-alert--error" role="alert" style="margin:0 0 0.75rem;">
            <?= htmlspecialchars(implode(' ', $taskErrors), ENT_QUOTES) ?>
          </div>
        <?php endif; ?>

        <?php if ($taskSuccess !== ''): ?>
          <div class="admin-alert admin-alert--success" role="status" style="margin:0 0 0.75rem;">
            <?= htmlspecialchars($taskSuccess, ENT_QUOTES) ?>
          </div>
        <?php endif; ?>

        <form class="admin-task-filter" method="get">
          <div>
            <label class="sr-only" for="task_employee">Employee</label>
            <select id="task_employee" name="task_employee">
              <option value="all">All employees</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= htmlspecialchars((string) ($emp['id'] ?? ''), ENT_QUOTES) ?>"<?= $taskEmployeeFilter === (string) ($emp['id'] ?? '') ? ' selected' : '' ?>>
                  <?= htmlspecialchars((string) ($emp['name'] ?? $emp['login_id'] ?? ''), ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="sr-only" for="task_status">Status</label>
            <select id="task_status" name="task_status">
              <option value="all"<?= $taskStatusFilter === 'all' ? ' selected' : '' ?>>All statuses</option>
              <option value="open"<?= $taskStatusFilter === 'open' ? ' selected' : '' ?>>Open</option>
              <option value="completed"<?= $taskStatusFilter === 'completed' ? ' selected' : '' ?>>Completed</option>
            </select>
          </div>
          <div>
            <label class="sr-only" for="task_due">Due</label>
            <select id="task_due" name="task_due">
              <option value="all"<?= $taskDueFilter === 'all' ? ' selected' : '' ?>>All</option>
              <option value="overdue"<?= $taskDueFilter === 'overdue' ? ' selected' : '' ?>>Overdue</option>
              <option value="today"<?= $taskDueFilter === 'today' ? ' selected' : '' ?>>Today</option>
              <option value="upcoming"<?= $taskDueFilter === 'upcoming' ? ' selected' : '' ?>>Upcoming</option>
            </select>
          </div>
          <div>
            <label class="sr-only" for="task_archive">Archive</label>
            <select id="task_archive" name="task_archive">
              <option value="active"<?= $taskArchiveFilter === 'active' ? ' selected' : '' ?>>Active</option>
              <option value="archived"<?= $taskArchiveFilter === 'archived' ? ' selected' : '' ?>>Archived</option>
              <option value="all"<?= $taskArchiveFilter === 'all' ? ' selected' : '' ?>>All</option>
            </select>
          </div>
        </form>

        <?php if ($filteredTasks === []): ?>
          <p class="admin-overview__empty">No tasks match your filters.</p>
        <?php else: ?>
          <div class="admin-table-wrapper">
            <table class="admin-task-table" aria-label="Admin tasks">
              <thead>
                <tr>
                  <th scope="col">Due / Next Due</th>
                  <th scope="col">Title</th>
                  <th scope="col">Assigned To</th>
                  <th scope="col">Frequency</th>
                  <th scope="col">Priority</th>
                  <th scope="col">Status</th>
                  <th scope="col">Last Completed</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($filteredTasks as $task): ?>
                  <?php
                    $statusLabel = (string) ($task['status'] ?? 'Open');
                    $priorityLabel = ucfirst(strtolower((string) ($task['priority'] ?? 'Low')));
                    $nextDueDisplay = (string) ($task['next_due_date'] ?? $task['due_date'] ?? '');
                    $logEntries = is_array($task['completion_log'] ?? null) ? $task['completion_log'] : [];
                    $isArchived = (bool) ($task['archived_flag'] ?? false);
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($nextDueDisplay, ENT_QUOTES) ?></td>
                    <td>
                      <div style="font-weight:700;color:#111827;"><?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES) ?></div>
                      <?php if (!empty($task['description'])): ?>
                        <div style="color:#4b5563;font-size:0.9rem;margin-top:0.25rem;"><?= htmlspecialchars((string) $task['description'], ENT_QUOTES) ?></div>
                      <?php endif; ?>
                      <?php if ($logEntries !== []): ?>
                        <div class="admin-task-log">
                          <strong>Completion log:</strong>
                          <ul style="margin:0.35rem 0 0.2rem 1rem; padding:0;">
                            <?php foreach ($logEntries as $entry): ?>
                              <li>
                                <?= htmlspecialchars((string) ($entry['completed_at'] ?? ''), ENT_QUOTES) ?> —
                                <?= htmlspecialchars((string) ($entry['completed_by_type'] ?? ''), ENT_QUOTES) ?>
                                <?php if (!empty($entry['completed_by_name'])): ?>
                                  (<?= htmlspecialchars((string) $entry['completed_by_name'], ENT_QUOTES) ?>)
                                <?php endif; ?>
                                <?php if (!empty($entry['note'])): ?>
                                  — <?= htmlspecialchars((string) $entry['note'], ENT_QUOTES) ?>
                                <?php endif; ?>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($task['assigned_to_name'] ?? $task['assigned_to_id'] ?? ''), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(tasks_frequency_label($task), ENT_QUOTES) ?></td>
                    <td>
                      <?php
                        $priorityClass = 'badge--priority-low';
                        if (strcasecmp($priorityLabel, 'High') === 0) {
                            $priorityClass = 'badge--priority-high';
                        } elseif (strcasecmp($priorityLabel, 'Medium') === 0) {
                            $priorityClass = 'badge--priority-medium';
                        }
                      ?>
                      <span class="badge <?= htmlspecialchars($priorityClass, ENT_QUOTES) ?>"><?= htmlspecialchars($priorityLabel, ENT_QUOTES) ?></span>
                    </td>
                    <td>
                      <?php
                        $statusClass = strcasecmp($statusLabel, 'Completed') === 0 ? 'badge--completed' : 'badge--open';
                      ?>
                      <span class="badge <?= htmlspecialchars($statusClass, ENT_QUOTES) ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span>
                      <?php if ($isArchived): ?>
                        <div class="badge badge--priority-medium" style="margin-top:0.25rem;">Archived</div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($task['last_completed_at'] ?? '—'), ENT_QUOTES) ?></td>
                    <td class="admin-task-actions">
                      <?php if (!$isArchived && strcasecmp($statusLabel, 'Completed') !== 0): ?>
                        <form method="post">
                          <input type="hidden" name="task_action" value="admin_complete_task" />
                          <input type="hidden" name="task_id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES) ?>" />
                          <label for="admin-note-<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES) ?>" class="sr-only">Completion note</label>
                          <input id="admin-note-<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES) ?>" type="text" name="completion_note" placeholder="Optional note" style="width:100%;margin:0 0 0.35rem;padding:0.4rem 0.5rem;border:1px solid #d1d5db;border-radius:8px;font:inherit;" />
                          <button type="submit">Mark Completed</button>
                        </form>
                      <?php endif; ?>
                      <?php if (!$isArchived): ?>
                        <form method="post">
                          <input type="hidden" name="task_action" value="admin_archive_task" />
                          <input type="hidden" name="task_id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES) ?>" />
                          <button type="submit" class="btn-ghost">Archive</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="admin-task-card">
        <h2>Assign Task</h2>
        <form class="admin-task-form" method="post">
          <input type="hidden" name="task_action" value="admin_assign_task" />

          <label for="admin_assigned_to">Assign to Employee *</label>
          <select id="admin_assigned_to" name="assigned_to_employee" required>
            <option value="">Select employee</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?= htmlspecialchars((string) ($emp['id'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars((string) ($emp['name'] ?? $emp['login_id'] ?? ''), ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="admin_task_title">Title *</label>
          <input id="admin_task_title" type="text" name="task_title" required />

          <label for="admin_task_description">Description</label>
          <textarea id="admin_task_description" name="task_description"></textarea>

          <label for="admin_task_priority">Priority</label>
          <select id="admin_task_priority" name="task_priority">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>

          <label for="admin_task_frequency">Frequency</label>
          <select id="admin_task_frequency" name="task_frequency">
            <option value="once">Once</option>
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
            <option value="custom">Custom (every N days)</option>
          </select>

          <div id="admin_custom_days_wrapper" style="display:none;">
            <label for="admin_task_custom_days">Custom days (for custom frequency)</label>
            <input id="admin_task_custom_days" type="number" name="task_custom_days" min="1" value="0" />
          </div>

          <label for="admin_task_start_date">Start date</label>
          <input id="admin_task_start_date" type="date" name="task_start_date" value="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES) ?>" />

          <label for="admin_task_due_date">Due date / Next due date</label>
          <input id="admin_task_due_date" type="date" name="task_due_date" value="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES) ?>" />

          <button type="submit">Assign Task</button>
        </form>
      </div>
    </section>

    <section class="admin-overview__highlights" aria-label="Customer complaints requiring attention">
      <div class="admin-overview__highlights-header">
        <h2>Customer complaints</h2>
        <p class="admin-overview__highlights-sub">
          Showing the latest <?= htmlspecialchars((string) count($highlightComplaints), ENT_QUOTES) ?> of <?= htmlspecialchars((string) $openComplaintCount, ENT_QUOTES) ?> active complaints.
          <a href="<?= htmlspecialchars($pathFor('complaints-overview.php'), ENT_QUOTES) ?>">Open service desk</a>
        </p>
      </div>

      <?php if (count($highlightComplaints) === 0): ?>
      <p class="admin-overview__empty">No customer complaints are waiting right now.</p>
      <?php else: ?>
      <ul class="highlight-list">
        <?php foreach ($highlightComplaints as $complaint): ?>
        <?php
          $status = strtolower((string) ($complaint['status'] ?? 'open'));
          $statusLabel = $complaintStatusLabels[$status] ?? ucfirst($status ?: 'Open');
          $title = trim((string) ($complaint['title'] ?? 'Complaint'));
          $summary = trim((string) ($complaint['description'] ?? ''));
          $updatedAt = (string) ($complaint['updated_at'] ?? $complaint['created_at'] ?? '');
          $isoTime = '';
          $displayTime = $updatedAt !== '' ? $updatedAt : '—';

          try {
              $dt = new DateTimeImmutable($updatedAt);
              $dt = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
              $isoTime = $dt->format(DateTimeInterface::ATOM);
              $displayTime = $dt->format('d M Y · h:i A');
          } catch (Throwable $exception) {
          }
        ?>
        <li class="highlight-list__item">
          <div class="highlight-list__icon" aria-hidden="true"><i class="fa-solid fa-headset"></i></div>
          <div>
            <p class="highlight-list__module">Complaint #<?= htmlspecialchars((string) ($complaint['id'] ?? $complaint['reference'] ?? '—'), ENT_QUOTES) ?></p>
            <p class="highlight-list__summary">
              <?= htmlspecialchars($title, ENT_QUOTES) ?> —
              <?= htmlspecialchars($summary !== '' ? $summary : 'No description provided.', ENT_QUOTES) ?>
            </p>
            <p class="highlight-list__summary">
              Customer: <?= htmlspecialchars((string) ($complaint['customer_mobile'] ?? 'Unknown'), ENT_QUOTES) ?> ·
              Status: <?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>
            </p>
          </div>
          <div class="highlight-list__time">
            <?php if ($isoTime !== ''): ?>
            <time datetime="<?= htmlspecialchars($isoTime, ENT_QUOTES) ?>"><?= htmlspecialchars($displayTime, ENT_QUOTES) ?></time>
            <?php else: ?>
            <span><?= htmlspecialchars($displayTime, ENT_QUOTES) ?></span>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

  </main>

  <script>
    (function() {
      var frequencySelect = document.getElementById('admin_task_frequency');
      var customWrapper = document.getElementById('admin_custom_days_wrapper');
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
  <script src="<?= htmlspecialchars($pathFor('admin-dashboard.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
