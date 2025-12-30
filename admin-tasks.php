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
  <style>
    body { background: #f6f8fb; font-family: Arial, sans-serif; }
    .shell { max-width: 1100px; margin: 20px auto 40px; padding: 0 16px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; box-shadow: 0 8px 24px rgba(0,0,0,0.05); }
    .message { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
    .message.success { background: #ecfdf3; border: 1px solid #bbf7d0; color: #166534; }
    .message.error { background: #fef2f2; border: 1px solid #fecdd3; color: #991b1b; }
    .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; border: 1px solid #d1d5db; background: #fff; margin-right: 4px; margin-top: 4px; }
    .btn { display: inline-block; padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; background: #111827; color: #fff; text-decoration: none; font-weight: 600; }
    .btn-secondary { background: #fff; color: #111; }
    .table { width: 100%; border-collapse: collapse; }
    .table th, .table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .table th { background: #f9fafb; }
    .input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
  </style>
</head>
<body>
  <div class="shell">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <div>
        <a href="admin-dashboard.php" class="btn-secondary btn">&larr; Back to Dashboard</a>
        <h2 style="margin:8px 0 0;">Tasks</h2>
      </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
      <div class="message success"><?= tasks_safe($flashMsg) ?></div>
    <?php endif; ?>
    <?php if ($messages['success'] !== ''): ?>
      <div class="message success"><?= tasks_safe($messages['success']) ?></div>
    <?php endif; ?>
    <?php if ($messages['error'] !== ''): ?>
      <div class="message error"><?= tasks_safe($messages['error']) ?></div>
    <?php endif; ?>

    <div class="grid" style="align-items:start;">
      <div class="card">
        <h3 style="margin-top:0;">Assign Task</h3>
        <form method="post">
          <input type="hidden" name="task_action" value="admin_assign" />
          <label for="assign_to_id">Employee</label>
          <select id="assign_to_id" name="assign_to_id" required>
            <option value="">Select employee</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?= tasks_safe((string) ($emp['id'] ?? '')) ?>"><?= tasks_safe((string) ($emp['name'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
          <label for="title">Title *</label>
          <input class="input" type="text" id="title" name="title" required />
          <label for="description">Description</label>
          <textarea id="description" name="description" rows="3" class="input"></textarea>
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div>
              <label for="priority">Priority</label>
              <select id="priority" name="priority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </div>
            <div>
              <label for="frequency_type">Frequency</label>
              <select id="frequency_type" name="frequency_type">
                <option value="once">Once</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <div>
              <label for="custom_every_n_days">Custom every N days</label>
              <input class="input" type="number" id="custom_every_n_days" name="custom_every_n_days" min="1" value="1" />
            </div>
            <div>
              <label for="start_date">Start date</label>
              <input class="input" type="date" id="start_date" name="start_date" value="<?= tasks_safe($today) ?>" />
            </div>
            <div>
              <?php
                $tz = new DateTimeZone(TASKS_TIMEZONE);
                $defaultDue = (new DateTimeImmutable('today', $tz))->modify('+7 days')->format('Y-m-d');
              ?>
              <label for="due_date">Due date (once)</label>
              <input class="input" type="date" id="due_date" name="due_date" value="<?= tasks_safe($defaultDue) ?>" />
            </div>
            <div>
              <label for="next_due_date">Next due date (recurring)</label>
              <input class="input" type="date" id="next_due_date" name="next_due_date" value="<?= tasks_safe($today) ?>" />
            </div>
          </div>
          <div style="margin-top:12px;">
            <button type="submit" class="btn">Assign Task</button>
          </div>
        </form>
      </div>

      <div class="card">
        <h3 style="margin-top:0;">Counters</h3>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
          <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;">
            <div style="font-weight:700;">Overdue</div>
            <div style="font-size:20px;"><?= (int) $overdueCount ?></div>
          </div>
          <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;">
            <div style="font-weight:700;">Pending today</div>
            <div style="font-size:20px;"><?= (int) $todayCount ?></div>
          </div>
          <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;">
            <div style="font-weight:700;">Completed this week</div>
            <div style="font-size:20px;"><?= (int) $completedThisWeekCount ?></div>
          </div>
        </div>
        <form method="get" style="margin-top:16px;">
          <h4 style="margin:0 0 8px;">Filters</h4>
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
            <div>
              <label for="employee">Employee</label>
              <select id="employee" name="employee">
                <option value="all">All</option>
                <?php foreach ($employees as $emp): ?>
                  <option value="<?= tasks_safe((string) ($emp['id'] ?? '')) ?>" <?= $filterEmployee === (string) ($emp['id'] ?? '') ? 'selected' : '' ?>>
                    <?= tasks_safe((string) ($emp['name'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="status">Status</label>
              <select id="status" name="status">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
                <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
              </select>
            </div>
            <div>
              <label for="view">View</label>
              <select id="view" name="view">
                <option value="active" <?= $filterView === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="archived" <?= $filterView === 'archived' ? 'selected' : '' ?>>Archived</option>
                <option value="all" <?= $filterView === 'all' ? 'selected' : '' ?>>All</option>
              </select>
            </div>
            <div>
              <label for="due">Due</label>
              <select id="due" name="due">
                <option value="all" <?= $filterDue === 'all' ? 'selected' : '' ?>>All</option>
                <option value="overdue" <?= $filterDue === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="today" <?= $filterDue === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="upcoming" <?= $filterDue === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
              </select>
            </div>
          </div>
          <button type="submit" class="btn" style="margin-top:10px;">Apply</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h3 style="margin-top:0;">All Tasks</h3>
      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Due / Next Due</th>
              <th>Title</th>
              <th>Employee</th>
              <th>Frequency</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Last Completed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($filteredTasks === []): ?>
              <tr><td colspan="8" style="text-align:center;color:#6b7280;">No tasks.</td></tr>
            <?php endif; ?>
            <?php foreach ($filteredTasks as $task): ?>
              <tr>
                <td><?= tasks_safe(get_effective_due_date($task) ?: '-') ?></td>
                <td>
                  <strong><?= tasks_safe((string) ($task['title'] ?? '')) ?></strong><br/>
                  <small><?= nl2br(tasks_safe((string) ($task['description'] ?? ''))) ?></small>
                </td>
                <td><?= tasks_safe((string) ($task['assigned_to_name'] ?? '')) ?></td>
                <td><?= tasks_safe((string) ($task['frequency_type'] ?? '')) ?></td>
                <td><?= tasks_safe((string) ($task['priority'] ?? '')) ?></td>
                <td><?= tasks_safe((string) ($task['status'] ?? '')) ?></td>
                <td><?= tasks_safe((string) ($task['last_completed_at'] ?? '')) ?></td>
                <td>
                  <?php if (($task['status'] ?? 'Open') === 'Open'): ?>
                    <form method="post" style="margin-bottom:6px;">
                      <input type="hidden" name="task_action" value="admin_complete" />
                      <input type="hidden" name="task_id" value="<?= tasks_safe((string) ($task['id'] ?? '')) ?>" />
                      <textarea name="note" rows="2" class="input" placeholder="Note (optional)"></textarea>
                      <button type="submit" class="btn" style="margin-top:4px;">Mark Completed</button>
                    </form>
                  <?php endif; ?>
                  <?php if (empty($task['archived_flag'])): ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="task_action" value="admin_archive" />
                      <input type="hidden" name="task_id" value="<?= tasks_safe((string) ($task['id'] ?? '')) ?>" />
                      <button type="submit" class="btn-secondary btn" style="margin-top:4px;">Archive</button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="task_action" value="admin_unarchive" />
                      <input type="hidden" name="task_id" value="<?= tasks_safe((string) ($task['id'] ?? '')) ?>" />
                      <button type="submit" class="btn-secondary btn" style="margin-top:4px;">Unarchive</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!empty($task['completion_log'])): ?>
                    <details style="margin-top:6px;">
                      <summary>View Log</summary>
                      <ul style="padding-left:16px; margin:6px 0 0;">
                        <?php foreach (array_reverse($task['completion_log']) as $entry): ?>
                          <li>
                            <?= tasks_safe((string) ($entry['completed_at'] ?? '')) ?>
                            by <?= tasks_safe((string) ($entry['completed_by_type'] ?? '')) ?>
                            <?php if (trim((string) ($entry['note'] ?? '')) !== ''): ?>
                              — <?= tasks_safe((string) $entry['note']) ?>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
