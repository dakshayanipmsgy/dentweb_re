<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/tasks_helpers.php';

require_admin();
$currentUser = current_user();
$employeeStore = new EmployeeFsStore();
$employees = $employeeStore->listEmployees();

$messages = ['success' => '', 'error' => ''];
$today = tasks_today_date();

function tasks_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['task_action'] ?? '');
    $tasks = load_tasks();

    if ($action === 'admin_assign') {
        $assignedId = trim((string) ($_POST['assign_to_id'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priority = strtolower((string) ($_POST['priority'] ?? 'medium'));
        $frequency = strtolower((string) ($_POST['frequency_type'] ?? 'once'));
        $customDays = (int) ($_POST['custom_every_n_days'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? $today));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $nextDueDate = trim((string) ($_POST['next_due_date'] ?? ''));

        $allowedPriorities = ['low', 'medium', 'high'];
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'medium';
        }
        $allowedFrequencies = ['once', 'daily', 'weekly', 'monthly', 'custom'];
        if (!in_array($frequency, $allowedFrequencies, true)) {
            $frequency = 'once';
        }

        $employeeName = '';
        foreach ($employees as $emp) {
            if (($emp['id'] ?? '') === $assignedId) {
                $employeeName = (string) ($emp['name'] ?? '');
                break;
            }
        }

        $tz = new DateTimeZone(TASKS_TIMEZONE);
        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $tz) ?: new DateTimeImmutable('today', $tz);
        $startDate = $startDateObj->format('Y-m-d');

        if ($frequency === 'once') {
            $dueDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate, $tz);
            if ($dueDateObj === false) {
                $dueDateObj = $startDateObj->modify('+7 days');
            }
            $dueDate = $dueDateObj->format('Y-m-d');
            $nextDueDate = '';
        } else {
            $dueDate = '';
            if ($nextDueDate === '') {
                $nextDueDate = $startDate;
            }
        }

        if ($assignedId === '' || $employeeName === '') {
            $messages['error'] = 'Please select an employee.';
        } elseif ($title === '') {
            $messages['error'] = 'Title is required.';
        } else {
            $now = tasks_now_timestamp();
            $task = [
                'id' => generate_task_id(),
                'title' => $title,
                'description' => $description,
                'priority' => ucfirst($priority),
                'created_by_type' => 'admin',
                'created_by_id' => (string) ($currentUser['id'] ?? ''),
                'assigned_to_id' => $assignedId,
                'assigned_to_name' => $employeeName,
                'frequency_type' => $frequency,
                'custom_every_n_days' => $customDays,
                'start_date' => $startDate,
                'due_date' => $dueDate,
                'next_due_date' => $nextDueDate,
                'status' => 'Open',
                'archived_flag' => false,
                'last_completed_at' => '',
                'completion_log' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $tasks[] = $task;
            if (!save_tasks($tasks)) {
                $messages['error'] = 'Could not save task: ' . tasks_last_error();
            } else {
                header('Location: admin-tasks.php?msg=' . rawurlencode('Task created'));
                exit;
            }
        }
    } elseif ($action === 'admin_complete') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        $found = false;
        foreach ($tasks as &$task) {
            if (($task['id'] ?? '') !== $taskId) {
                continue;
            }
            if (!empty($task['archived_flag'])) {
                $messages['error'] = 'Task is archived.';
                break;
            }

            $found = true;
            $now = tasks_now_timestamp();
            $task['completion_log'][] = [
                'completed_at' => $now,
                'completed_by_type' => 'admin',
                'completed_by_id' => (string) ($currentUser['id'] ?? ''),
                'note' => $note,
            ];
            $task['last_completed_at'] = $now;

            $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
            $customDays = (int) ($task['custom_every_n_days'] ?? 0);
            if ($frequency === 'once') {
                $task['status'] = 'Completed';
            } else {
                $currentDue = (string) ($task['next_due_date'] ?? ($task['start_date'] ?? $today));
                $task['next_due_date'] = compute_next_due_date($frequency, $currentDue, $customDays);
                $task['status'] = 'Open';
            }
            $task['updated_at'] = $now;
            break;
        }
        unset($task);

        if (!$found && $messages['error'] === '') {
            $messages['error'] = 'Task not found.';
        }

        if ($messages['error'] === '' && !save_tasks($tasks)) {
            $messages['error'] = 'Could not update task: ' . tasks_last_error();
        } elseif ($messages['error'] === '') {
            header('Location: admin-tasks.php?msg=' . rawurlencode('Task updated'));
            exit;
        }
    } elseif (in_array($action, ['admin_archive', 'admin_unarchive'], true)) {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $found = false;
        foreach ($tasks as &$task) {
            if (($task['id'] ?? '') !== $taskId) {
                continue;
            }
            $found = true;
            $task['archived_flag'] = $action === 'admin_archive';
            $task['updated_at'] = tasks_now_timestamp();
            break;
        }
        unset($task);

        if (!$found) {
            $messages['error'] = 'Task not found.';
        } elseif (!save_tasks($tasks)) {
            $messages['error'] = 'Could not update task: ' . tasks_last_error();
        } else {
            $msg = $action === 'admin_archive' ? 'Task archived' : 'Task unarchived';
            header('Location: admin-tasks.php?msg=' . rawurlencode($msg));
            exit;
        }
    }
}

$tasks = load_tasks();
$flashMsg = isset($_GET['msg']) && is_string($_GET['msg']) ? trim((string) $_GET['msg']) : '';

[$weekStart, $weekEnd] = week_range_dates();

$overdueCount = 0;
$todayCount = 0;
$completedThisWeek = [];
$highPriorityOpen = 0;
foreach ($tasks as $task) {
    if (!empty($task['archived_flag'])) {
        continue;
    }
    $status = (string) ($task['status'] ?? 'Open');
    $due = get_effective_due_date($task);
    if ($status === 'Open') {
        if ($due !== '' && strcmp($due, $today) < 0) {
            $overdueCount++;
        } elseif ($due !== '' && strcmp($due, $today) === 0) {
            $todayCount++;
        }

        if (strtolower((string) ($task['priority'] ?? '')) === 'high') {
            $highPriorityOpen++;
        }
    }

    if (!empty($task['completion_log']) && is_array($task['completion_log'])) {
        foreach ($task['completion_log'] as $entry) {
            $completedAt = substr((string) ($entry['completed_at'] ?? ''), 0, 10);
            if ($completedAt === '') {
                continue;
            }
            if ($completedAt >= $weekStart && $completedAt <= $weekEnd) {
                $completedThisWeek[$task['id']] = true;
                break;
            }
        }
    }
}
$completedThisWeekCount = count($completedThisWeek);

$filterEmployee = trim((string) ($_GET['employee'] ?? 'all'));
$filterStatus = strtolower((string) ($_GET['status'] ?? 'all'));
$filterView = strtolower((string) ($_GET['view'] ?? 'active'));
$filterDue = strtolower((string) ($_GET['due'] ?? 'all'));

$filteredTasks = array_values(array_filter($tasks, static function (array $task) use ($filterEmployee, $filterStatus, $filterView, $filterDue, $today): bool {
    $archived = !empty($task['archived_flag']);
    if ($filterView === 'active' && $archived) {
        return false;
    }
    if ($filterView === 'archived' && !$archived) {
        return false;
    }

    if ($filterEmployee !== 'all' && $filterEmployee !== '' && (string) ($task['assigned_to_id'] ?? '') !== $filterEmployee) {
        return false;
    }

    $status = strtolower((string) ($task['status'] ?? 'open'));
    if ($filterStatus === 'open' && $status !== 'open') {
        return false;
    }
    if ($filterStatus === 'completed' && $status !== 'completed') {
        return false;
    }

    $due = get_effective_due_date($task);
    if ($filterDue === 'overdue' && ($due === '' || strcmp($due, $today) >= 0)) {
        return false;
    }
    if ($filterDue === 'today' && ($due === '' || strcmp($due, $today) !== 0)) {
        return false;
    }
    if ($filterDue === 'upcoming' && ($due === '' || strcmp($due, $today) <= 0)) {
        return false;
    }

    return true;
}));

usort($filteredTasks, static function (array $left, array $right): int {
    return strcmp(get_effective_due_date($left), get_effective_due_date($right));
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tasks | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/admin-unified.css" />
</head>
<body class="admin-records admin-shell" data-theme="light">
  <main class="admin-records__shell admin-page">
    <header class="admin-records__header">
      <div>
        <h1>Task Management</h1>
        <p class="admin-muted">Assign, track, and close recurring or one-time tasks with cleaner operational visibility.</p>
      </div>
      <div class="admin-records__meta">
        <a class="admin-link" href="admin-dashboard.php">&larr; Back to Dashboard</a>
      </div>
    </header>

    <?php if ($flashMsg !== ''): ?><div class="admin-alert admin-alert--success" role="status"><?= tasks_safe($flashMsg) ?></div><?php endif; ?>
    <?php if ($messages['success'] !== ''): ?><div class="admin-alert admin-alert--success" role="status"><?= tasks_safe($messages['success']) ?></div><?php endif; ?>
    <?php if ($messages['error'] !== ''): ?><div class="admin-alert admin-alert--error" role="alert"><?= tasks_safe($messages['error']) ?></div><?php endif; ?>

    <section class="admin-overview__cards admin-overview__cards--compact">
      <article class="overview-card"><div class="overview-card__label">Overdue</div><div class="overview-card__value"><?= (int) $overdueCount ?></div></article>
      <article class="overview-card"><div class="overview-card__label">Due today</div><div class="overview-card__value"><?= (int) $todayCount ?></div></article>
      <article class="overview-card"><div class="overview-card__label">High priority open</div><div class="overview-card__value"><?= (int) $highPriorityOpen ?></div></article>
      <article class="overview-card"><div class="overview-card__label">Completed this week</div><div class="overview-card__value"><?= (int) $completedThisWeekCount ?></div></article>
    </section>

    <section class="admin-workspace-grid admin-workspace-grid--tasks">
      <article class="admin-panel-card admin-panel-card--create">
        <h2>Assign Task</h2>
        <form method="post" class="users-form-grid">
          <input type="hidden" name="task_action" value="admin_assign" />
          <div>
            <label for="assign_to_id">Employee</label>
            <select id="assign_to_id" name="assign_to_id" class="users-select" required>
              <option value="">Select employee</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= tasks_safe((string) ($emp['id'] ?? '')) ?>"><?= tasks_safe((string) ($emp['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="title">Title *</label>
            <input class="users-input" type="text" id="title" name="title" required />
          </div>
          <div style="grid-column: 1 / -1;">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3" class="users-input"></textarea>
          </div>
          <div>
            <label for="priority">Priority</label>
            <select id="priority" name="priority" class="users-select">
              <option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option>
            </select>
          </div>
          <div>
            <label for="frequency_type">Frequency</label>
            <select id="frequency_type" name="frequency_type" class="users-select">
              <option value="once">Once</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="custom">Custom</option>
            </select>
          </div>
          <div>
            <label for="custom_every_n_days">Custom every N days</label>
            <input class="users-input" type="number" id="custom_every_n_days" name="custom_every_n_days" min="1" value="1" />
          </div>
          <div><label for="start_date">Start date</label><input class="users-input" type="date" id="start_date" name="start_date" value="<?= tasks_safe($today) ?>" /></div>
          <?php $defaultDue = (new DateTimeImmutable('today', new DateTimeZone(TASKS_TIMEZONE)))->modify('+7 days')->format('Y-m-d'); ?>
          <div><label for="due_date">Due date (once)</label><input class="users-input" type="date" id="due_date" name="due_date" value="<?= tasks_safe($defaultDue) ?>" /></div>
          <div><label for="next_due_date">Next due (recurring)</label><input class="users-input" type="date" id="next_due_date" name="next_due_date" value="<?= tasks_safe($today) ?>" /></div>
          <div class="users-form-actions" style="grid-column:1 / -1;"><button type="submit" class="btn btn-primary">Assign task</button></div>
        </form>
      </article>

      <article class="admin-panel-card admin-panel-card--filters">
        <h2>Filters</h2>
        <form method="get" class="users-form-grid">
          <div>
            <label for="employee">Employee</label>
            <select id="employee" name="employee" class="users-select">
              <option value="all">All</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= tasks_safe((string) ($emp['id'] ?? '')) ?>" <?= $filterEmployee === (string) ($emp['id'] ?? '') ? 'selected' : '' ?>><?= tasks_safe((string) ($emp['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label for="status">Status</label><select id="status" name="status" class="users-select"><option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option><option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>Open</option><option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option></select></div>
          <div><label for="view">View</label><select id="view" name="view" class="users-select"><option value="active" <?= $filterView === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $filterView === 'archived' ? 'selected' : '' ?>>Archived</option><option value="all" <?= $filterView === 'all' ? 'selected' : '' ?>>All</option></select></div>
          <div><label for="due">Due</label><select id="due" name="due" class="users-select"><option value="all" <?= $filterDue === 'all' ? 'selected' : '' ?>>All</option><option value="overdue" <?= $filterDue === 'overdue' ? 'selected' : '' ?>>Overdue</option><option value="today" <?= $filterDue === 'today' ? 'selected' : '' ?>>Today</option><option value="upcoming" <?= $filterDue === 'upcoming' ? 'selected' : '' ?>>Upcoming</option></select></div>
          <div class="users-form-actions" style="grid-column:1 / -1;"><button type="submit" class="btn btn-secondary">Apply filters</button></div>
        </form>
      </article>
    </section>

    <section class="admin-table-wrapper">
      <table class="admin-table" aria-label="All tasks">
        <thead>
          <tr><th>Due</th><th>Task</th><th>Assignee</th><th>Schedule</th><th>Priority</th><th>Status</th><th>Last Completed</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if ($filteredTasks === []): ?>
            <tr><td colspan="8" class="admin-records__empty">No tasks match the current filters.</td></tr>
          <?php endif; ?>
          <?php foreach ($filteredTasks as $task): ?>
            <?php
              $dueDate = get_effective_due_date($task);
              $isOverdue = $dueDate !== '' && strcmp($dueDate, $today) < 0 && strtolower((string) ($task['status'] ?? '')) === 'open';
              $isToday = $dueDate !== '' && strcmp($dueDate, $today) === 0 && strtolower((string) ($task['status'] ?? '')) === 'open';
              $priorityText = strtolower((string) ($task['priority'] ?? 'medium'));
            ?>
            <tr>
              <td>
                <span class="status-pill <?= $isOverdue ? 'status-pill--danger' : ($isToday ? 'status-pill--warning' : '') ?>"><?= tasks_safe($dueDate !== '' ? $dueDate : '-') ?></span>
              </td>
              <td>
                <strong><?= tasks_safe((string) ($task['title'] ?? '')) ?></strong>
                <?php if (trim((string) ($task['description'] ?? '')) !== ''): ?><p class="admin-muted text-sm"><?= nl2br(tasks_safe((string) ($task['description'] ?? ''))) ?></p><?php endif; ?>
              </td>
              <td><?= tasks_safe((string) ($task['assigned_to_name'] ?? '')) ?></td>
              <td><?= tasks_safe(ucfirst((string) ($task['frequency_type'] ?? 'once'))) ?></td>
              <td><span class="status-pill <?= $priorityText === 'high' ? 'status-pill--danger' : '' ?>"><?= tasks_safe((string) ($task['priority'] ?? 'Medium')) ?></span></td>
              <td><?= tasks_safe((string) ($task['status'] ?? '')) ?></td>
              <td><?= tasks_safe((string) ($task['last_completed_at'] ?? '')) ?></td>
              <td>
                <div class="admin-row-actions">
                  <?php if (($task['status'] ?? 'Open') === 'Open'): ?>
                    <button type="button" class="btn btn-primary btn-xs js-open-complete" data-task-id="<?= tasks_safe((string) ($task['id'] ?? '')) ?>">Complete</button>
                  <?php endif; ?>
                  <form method="post" class="admin-inline-form">
                    <input type="hidden" name="task_action" value="<?= empty($task['archived_flag']) ? 'admin_archive' : 'admin_unarchive' ?>" />
                    <input type="hidden" name="task_id" value="<?= tasks_safe((string) ($task['id'] ?? '')) ?>" />
                    <button type="submit" class="btn btn-ghost btn-xs"><?= empty($task['archived_flag']) ? 'Archive' : 'Unarchive' ?></button>
                  </form>
                </div>
                <?php if (!empty($task['completion_log']) && is_array($task['completion_log'])): ?>
                  <details class="admin-payload"><summary>View completion log</summary><ul>
                    <?php foreach (array_reverse($task['completion_log']) as $entry): ?>
                      <li><?= tasks_safe((string) ($entry['completed_at'] ?? '')) ?><?php if (trim((string) ($entry['note'] ?? '')) !== ''): ?> — <?= tasks_safe((string) $entry['note']) ?><?php endif; ?></li>
                    <?php endforeach; ?>
                  </ul></details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <dialog id="complete-dialog" class="admin-dialog">
      <form method="dialog" class="admin-dialog__frame"><header class="admin-dialog__header"><h2>Mark task complete</h2><button class="btn btn-secondary btn-xs" value="cancel">Close</button></header></form>
      <form method="post" class="admin-dialog__body">
        <input type="hidden" name="task_action" value="admin_complete" />
        <input type="hidden" name="task_id" id="complete-task-id" value="" />
        <label for="complete-note">Completion note (optional)</label>
        <textarea id="complete-note" name="note" rows="4" class="admin-textarea" placeholder="Add what was completed"></textarea>
        <div class="admin-dialog__actions"><button type="submit" class="btn btn-primary">Save completion</button></div>
      </form>
    </dialog>
  </main>
  <script>
    (function () {
      const dialog = document.getElementById('complete-dialog');
      const input = document.getElementById('complete-task-id');
      if (!dialog || !input) return;
      document.querySelectorAll('.js-open-complete').forEach((button) => {
        button.addEventListener('click', function () {
          input.value = this.getAttribute('data-task-id') || '';
          if (typeof dialog.showModal === 'function') dialog.showModal();
        });
      });
    })();
  </script>
</body>
</html>
