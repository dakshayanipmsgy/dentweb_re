<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/tasks_helpers.php';

$employeeStore = new EmployeeFsStore();
employee_portal_require_login();
$employee = employee_portal_current_employee($employeeStore);
if ($employee === null) {
    header('Location: login.php?login_type=employee');
    exit;
}

$messages = ['success' => '', 'error' => ''];
$today = tasks_today_date();
$showCompleted = (isset($_GET['show_completed']) && $_GET['show_completed'] === '1');

function task_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function employee_task_status_message(): ?string
{
    if (isset($_GET['msg']) && is_string($_GET['msg'])) {
        return trim((string) $_GET['msg']);
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['task_action'] ?? '');

    if ($action === 'employee_create') {
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

        if ($title === '') {
            $messages['error'] = 'Title is required.';
        } else {
            $tasks = load_tasks();
            $now = tasks_now_timestamp();
            $task = [
                'id' => generate_task_id(),
                'title' => $title,
                'description' => $description,
                'priority' => ucfirst($priority),
                'created_by_type' => 'employee',
                'created_by_id' => (string) ($employee['id'] ?? ''),
                'assigned_to_id' => (string) ($employee['id'] ?? ''),
                'assigned_to_name' => (string) ($employee['name'] ?? ''),
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
                header('Location: employee-tasks.php?msg=' . rawurlencode('Task created'));
                exit;
            }
        }
    } elseif ($action === 'employee_complete') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        $tasks = load_tasks();
        $found = false;
        foreach ($tasks as &$task) {
            if (($task['id'] ?? '') !== $taskId) {
                continue;
            }
            if (($task['assigned_to_id'] ?? '') !== (string) ($employee['id'] ?? '')) {
                $messages['error'] = 'You can only complete your own tasks.';
                break;
            }
            if (!empty($task['archived_flag'])) {
                $messages['error'] = 'Task is archived.';
                break;
            }

            $found = true;
            $now = tasks_now_timestamp();
            $completedAt = $now;

            $task['completion_log'][] = [
                'completed_at' => $completedAt,
                'completed_by_type' => 'employee',
                'completed_by_id' => (string) ($employee['id'] ?? ''),
                'note' => $note,
            ];
            $task['last_completed_at'] = $completedAt;

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

        if ($messages['error'] === '') {
            if (!save_tasks($tasks)) {
                $messages['error'] = 'Could not save completion: ' . tasks_last_error();
            } else {
                header('Location: employee-tasks.php?msg=' . rawurlencode('Task updated'));
                exit;
            }
        }
    }
}

$tasks = load_tasks();
$employeeId = (string) ($employee['id'] ?? '');
$assignedTasks = array_values(array_filter($tasks, static function (array $task) use ($employeeId, $showCompleted): bool {
    if (($task['assigned_to_id'] ?? '') !== $employeeId) {
        return false;
    }
    if (!empty($task['archived_flag'])) {
        return false;
    }
    if ($showCompleted) {
        return true;
    }
    return (string) ($task['status'] ?? 'Open') !== 'Completed';
}));

$overdueTasks = [];
$todayTasks = [];
$upcomingTasks = [];
$completedTasks = [];
foreach ($assignedTasks as $task) {
    $status = (string) ($task['status'] ?? 'Open');
    if ($status === 'Completed') {
        $completedTasks[] = $task;
        continue;
    }

    if (is_overdue($task, $today)) {
        $overdueTasks[] = $task;
    } elseif (is_due_today($task, $today)) {
        $todayTasks[] = $task;
    } else {
        $upcomingTasks[] = $task;
    }
}

$sortByDue = static function (array $left, array $right): int {
    $leftDue = get_effective_due_date($left);
    $rightDue = get_effective_due_date($right);
    return strcmp($leftDue, $rightDue);
};
usort($overdueTasks, $sortByDue);
usort($todayTasks, $sortByDue);
usort($upcomingTasks, $sortByDue);
usort($completedTasks, $sortByDue);

$flashMsg = employee_task_status_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Tasks | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    body { background: #f6f8fb; font-family: Arial, sans-serif; }
    .shell { max-width: 960px; margin: 20px auto 40px; padding: 0 16px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.05); }
    .title-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .btn { display: inline-block; padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; background: #111827; color: #fff; text-decoration: none; font-weight: 600; }
    .btn-secondary { background: #fff; color: #111; }
    .input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
    label { font-weight: 600; font-size: 14px; margin-top: 8px; display: block; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .task-row { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; background: #f9fafb; }
    .task-row h4 { margin: 0 0 6px; }
    .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; border: 1px solid #d1d5db; background: #fff; }
    .log { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; margin-top: 8px; }
    .message { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
    .message.success { background: #ecfdf3; border: 1px solid #bbf7d0; color: #166534; }
    .message.error { background: #fef2f2; border: 1px solid #fecdd3; color: #991b1b; }
  </style>
</head>
<body>
  <div class="shell">
    <div class="title-row">
      <div>
        <a href="employee-dashboard.php" class="btn-secondary btn">&larr; Back to Dashboard</a>
        <h2 style="margin:8px 0 0;">My Tasks</h2>
      </div>
    </div>

    <?php if ($flashMsg): ?>
      <div class="message success"><?= task_safe($flashMsg) ?></div>
    <?php endif; ?>
    <?php if ($messages['success'] !== ''): ?>
      <div class="message success"><?= task_safe($messages['success']) ?></div>
    <?php endif; ?>
    <?php if ($messages['error'] !== ''): ?>
      <div class="message error"><?= task_safe($messages['error']) ?></div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0;">Create My Task</h3>
      <form method="post">
        <input type="hidden" name="task_action" value="employee_create" />
        <div class="grid">
          <div>
            <label for="title">Title *</label>
            <input class="input" type="text" id="title" name="title" required />
          </div>
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
            <input class="input" type="date" id="start_date" name="start_date" value="<?= task_safe($today) ?>" />
          </div>
          <div>
            <label for="due_date">Due date (for once)</label>
            <?php
              $tz = new DateTimeZone(TASKS_TIMEZONE);
              $defaultDue = (new DateTimeImmutable('today', $tz))->modify('+7 days')->format('Y-m-d');
            ?>
            <input class="input" type="date" id="due_date" name="due_date" value="<?= task_safe($defaultDue) ?>" />
          </div>
          <div>
            <label for="next_due_date">Next due date (recurring)</label>
            <input class="input" type="date" id="next_due_date" name="next_due_date" value="<?= task_safe($today) ?>" />
          </div>
        </div>
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3" class="input" placeholder="Optional details"></textarea>
        <div style="margin-top:12px;">
          <button type="submit" class="btn">Save Task</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="title-row">
        <h3 style="margin:0;">My Tasks</h3>
        <a class="btn-secondary btn" href="?show_completed=<?= $showCompleted ? '0' : '1' ?>">
          <?= $showCompleted ? 'Hide Completed' : 'Show Completed' ?>
        </a>
      </div>

      <?php
      $renderList = function (string $heading, array $items) {
          if ($items === []) {
              echo '<p style="color:#6b7280;">No tasks.</p>';
              return;
          }
          echo '<h4 style="margin:12px 0 6px;">' . task_safe($heading) . '</h4>';
          foreach ($items as $task) {
              $due = get_effective_due_date($task);
              ?>
              <div class="task-row">
                <div class="title-row" style="align-items:flex-start;">
                  <div>
                    <h4><?= task_safe((string) ($task['title'] ?? '')) ?></h4>
                    <div class="badge">Due: <?= task_safe($due ?: '-') ?></div>
                    <div class="badge">Priority: <?= task_safe((string) ($task['priority'] ?? '')) ?></div>
                    <div class="badge">Frequency: <?= task_safe((string) ($task['frequency_type'] ?? '')) ?></div>
                    <div class="badge">Status: <?= task_safe((string) ($task['status'] ?? 'Open')) ?></div>
                  </div>
                  <?php if (($task['status'] ?? 'Open') === 'Open'): ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="task_action" value="employee_complete" />
                      <input type="hidden" name="task_id" value="<?= task_safe((string) ($task['id'] ?? '')) ?>" />
                      <label style="font-size:12px;margin-bottom:4px;">Note</label>
                      <textarea name="note" rows="2" class="input" placeholder="Optional note"></textarea>
                      <button type="submit" class="btn" style="margin-top:6px;">Mark Completed</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if (trim((string) ($task['description'] ?? '')) !== ''): ?>
                  <p style="margin:8px 0 4px; color:#374151;"><?= nl2br(task_safe((string) $task['description'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($task['completion_log'])): ?>
                  <details class="log">
                    <summary>View Log</summary>
                    <ul style="padding-left:16px; margin:8px 0 0;">
                      <?php foreach (array_reverse($task['completion_log']) as $entry): ?>
                        <li>
                          <?= task_safe((string) ($entry['completed_at'] ?? '')) ?>
                          by <?= task_safe((string) ($entry['completed_by_type'] ?? '')) ?>
                          <?php if (trim((string) ($entry['note'] ?? '')) !== ''): ?>
                            — <?= task_safe((string) $entry['note']) ?>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              </div>
              <?php
          }
      };

      $renderList('Overdue', $overdueTasks);
      $renderList('Due Today', $todayTasks);
      $renderList('Upcoming', $upcomingTasks);
      if ($showCompleted) {
          $renderList('Completed', $completedTasks);
      }
      ?>
    </div>
  </div>
</body>
</html>
