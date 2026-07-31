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

$employeeId = (string) ($employee['id'] ?? '');
$employeeName = trim((string) ($employee['name'] ?? $employee['login_id'] ?? 'Employee')) ?: 'Employee';
$today = tasks_today_date();
$error = '';
$flash = isset($_GET['msg']) && is_string($_GET['msg']) ? trim($_GET['msg']) : '';

function employee_task_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function employee_task_find_index(array $tasks, string $taskId): ?int
{
    foreach ($tasks as $index => $task) {
        if ((string) ($task['id'] ?? '') === $taskId) {
            return $index;
        }
    }
    return null;
}

function employee_task_redirect(string $message): never
{
    header('Location: employee-tasks.php?msg=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = trim((string) ($_POST['task_action'] ?? ''));
    $operationError = '';

    if ($action === 'create_personal') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priority = strtolower(trim((string) ($_POST['priority'] ?? 'medium')));
        $dueDate = tasks_valid_date((string) ($_POST['due_date'] ?? ''), $today);
        if ($title === '') {
            $operationError = 'Task title is required.';
        } elseif (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $operationError = 'Invalid priority.';
        }

        if ($operationError === '') {
            $saved = tasks_mutate(function (array &$tasks) use ($title, $description, $priority, $dueDate, $employeeId, $employeeName): void {
                $now = tasks_now_timestamp();
                $task = task_normalize([
                    'id' => generate_task_id(),
                    'title' => $title,
                    'description' => $description,
                    'expected_outcome' => '',
                    'category' => 'Personal follow-up',
                    'project_reference' => '',
                    'priority' => ucfirst($priority),
                    'created_by_type' => 'employee',
                    'created_by_id' => $employeeId,
                    'created_by_name' => $employeeName,
                    'assigned_by_name' => $employeeName,
                    'assigned_to_id' => $employeeId,
                    'assigned_to_name' => $employeeName,
                    'frequency_type' => 'once',
                    'start_date' => tasks_today_date(),
                    'due_date' => $dueDate,
                    'workflow_status' => 'in_progress',
                    'status' => 'Open',
                    'attention_owner' => 'none',
                    'started_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_activity_at' => $now,
                ]);
                task_add_activity($task, 'employee', $employeeId, $employeeName, 'Personal task created');
                $tasks[] = $task;
            });
            if ($saved) {
                employee_task_redirect('Personal task created');
            }
            $operationError = tasks_last_error() ?: 'Could not create task.';
        }
    } elseif (in_array($action, ['acknowledge', 'start', 'reply', 'block', 'submit', 'resume'], true)) {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($taskId === '') {
            $operationError = 'Task was not selected.';
        }

        if ($operationError === '') {
            $saved = tasks_mutate(function (array &$tasks) use ($action, $taskId, $message, $employeeId, $employeeName, &$operationError): void {
                $index = employee_task_find_index($tasks, $taskId);
                if ($index === null) {
                    $operationError = 'Task not found.';
                    return;
                }

                $task = task_normalize($tasks[$index]);
                if ((string) ($task['assigned_to_id'] ?? '') !== $employeeId) {
                    $operationError = 'You can only update tasks assigned to you.';
                    return;
                }
                if (!empty($task['archived_flag'])) {
                    $operationError = 'This task is archived.';
                    return;
                }

                $workflow = task_workflow_status($task);
                if (in_array($workflow, ['completed', 'cancelled'], true)) {
                    $operationError = 'This task is already closed.';
                    return;
                }

                if ($action === 'acknowledge') {
                    if ($workflow !== 'assigned') {
                        $operationError = 'This task has already been acknowledged.';
                        return;
                    }
                    $task['acknowledged_at'] = tasks_now_timestamp();
                    task_set_workflow($task, 'acknowledged', 'none');
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Task acknowledged');
                } elseif ($action === 'start') {
                    if (!in_array($workflow, ['assigned', 'acknowledged'], true)) {
                        $operationError = 'This task cannot be started from its current status.';
                        return;
                    }
                    if ((string) ($task['acknowledged_at'] ?? '') === '') {
                        $task['acknowledged_at'] = tasks_now_timestamp();
                    }
                    $task['started_at'] = tasks_now_timestamp();
                    task_set_workflow($task, 'in_progress', 'none');
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Work started');
                } elseif ($action === 'reply') {
                    if ($message === '') {
                        $operationError = 'Update cannot be empty.';
                        return;
                    }
                    task_add_message($task, 'employee', $employeeId, $employeeName, $message);
                    $task['attention_owner'] = 'admin';
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Employee posted an update');
                } elseif ($action === 'block') {
                    if ($message === '') {
                        $operationError = 'Explain what is blocking the task and what support is needed.';
                        return;
                    }
                    task_add_message($task, 'employee', $employeeId, $employeeName, $message, 'blocker');
                    task_set_workflow($task, 'blocked', 'admin');
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Task blocked', $message);
                } elseif ($action === 'submit') {
                    if ($message === '') {
                        $operationError = 'Add a completion summary or proof before submitting.';
                        return;
                    }
                    task_add_message($task, 'employee', $employeeId, $employeeName, $message, 'submission');
                    $task['submitted_at'] = tasks_now_timestamp();
                    task_set_workflow($task, 'submitted', 'admin');
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Work submitted for review');
                } elseif ($action === 'resume') {
                    if ($workflow !== 'blocked') {
                        $operationError = 'Only a blocked task can be resumed.';
                        return;
                    }
                    if ($message !== '') {
                        task_add_message($task, 'employee', $employeeId, $employeeName, $message, 'resume');
                    }
                    task_set_workflow($task, 'in_progress', 'none');
                    task_add_activity($task, 'employee', $employeeId, $employeeName, 'Work resumed', $message);
                }

                $tasks[$index] = task_normalize($task);
            });

            if ($operationError !== '') {
                $error = $operationError;
            } elseif ($saved) {
                $labels = [
                    'acknowledge' => 'Task acknowledged', 'start' => 'Work started', 'reply' => 'Update sent to admin',
                    'block' => 'Blocker reported to admin', 'submit' => 'Work submitted for admin review', 'resume' => 'Work resumed',
                ];
                employee_task_redirect($labels[$action] ?? 'Task updated');
            } else {
                $error = tasks_last_error() ?: 'Could not update task.';
            }
        }
    }

    if ($operationError !== '' && $error === '') {
        $error = $operationError;
    }
}

$allTasks = load_tasks();
$employeeTasks = array_values(array_filter($allTasks, static fn(array $task): bool =>
    (string) ($task['assigned_to_id'] ?? '') === $employeeId && empty($task['archived_flag'])
));

$filter = strtolower(trim((string) ($_GET['view'] ?? 'active')));
$search = trim((string) ($_GET['q'] ?? ''));
$filteredTasks = array_values(array_filter($employeeTasks, static function (array $task) use ($filter, $search): bool {
    $workflow = task_workflow_status($task);
    if ($filter === 'active' && in_array($workflow, ['completed', 'cancelled'], true)) {
        return false;
    }
    if ($filter === 'needs_me' && (string) ($task['attention_owner'] ?? '') !== 'employee' && $workflow !== 'assigned') {
        return false;
    }
    if ($filter === 'waiting_admin' && (string) ($task['attention_owner'] ?? '') !== 'admin') {
        return false;
    }
    if (!in_array($filter, ['all', 'active', 'needs_me', 'waiting_admin'], true) && $workflow !== $filter) {
        return false;
    }
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($task['title'] ?? ''), (string) ($task['description'] ?? ''),
            (string) ($task['expected_outcome'] ?? ''), (string) ($task['project_reference'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($search))) {
            return false;
        }
    }
    return true;
}));

$counts = ['active' => 0, 'needs_me' => 0, 'overdue' => 0, 'waiting_admin' => 0, 'completed' => 0];
foreach ($employeeTasks as $task) {
    $workflow = task_workflow_status($task);
    if (!in_array($workflow, ['completed', 'cancelled'], true)) {
        $counts['active']++;
    }
    if ((string) ($task['attention_owner'] ?? '') === 'employee' || $workflow === 'assigned') {
        $counts['needs_me']++;
    }
    if (is_overdue($task, $today)) {
        $counts['overdue']++;
    }
    if ((string) ($task['attention_owner'] ?? '') === 'admin') {
        $counts['waiting_admin']++;
    }
    if ($workflow === 'completed') {
        $counts['completed']++;
    }
}

usort($filteredTasks, static function (array $left, array $right): int {
    $leftNeed = ((string) ($left['attention_owner'] ?? '') === 'employee' || task_workflow_status($left) === 'assigned') ? 0 : 1;
    $rightNeed = ((string) ($right['attention_owner'] ?? '') === 'employee' || task_workflow_status($right) === 'assigned') ? 0 : 1;
    if ($leftNeed !== $rightNeed) {
        return $leftNeed <=> $rightNeed;
    }
    return strcmp(get_effective_due_date($left) ?: '9999-12-31', get_effective_due_date($right) ?: '9999-12-31');
});

$defaultDue = (new DateTimeImmutable('today', new DateTimeZone(TASKS_TIMEZONE)))->modify('+2 days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Work | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="assets/css/admin-unified.css">
  <style>
    body{background:#f4f7fb;color:#172033}.work-shell{max-width:1050px;margin:0 auto;padding:20px 14px 85px}.work-header,.work-meta,.work-actions,.work-stats{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.work-header{justify-content:space-between;margin-bottom:15px}.work-header h1{margin:0}.work-header p{margin:5px 0 0;color:#64748b}.work-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));margin:14px 0}.stat,.panel,.task-card,.app-card{background:#fff;border:1px solid #dfe7f1;border-radius:15px;box-shadow:0 10px 28px rgba(15,23,42,.05)}.stat{padding:14px}.stat b{display:block;font-size:1.5rem}.stat span{font-size:.83rem;color:#64748b}.app-card{padding:15px;display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px;background:linear-gradient(135deg,#ecfeff,#eff6ff)}.app-card p{margin:4px 0;color:#475569}.panel{padding:15px;margin-bottom:14px}.filters{display:grid;grid-template-columns:2fr 1fr auto;gap:9px}.task-input{width:100%;padding:10px 11px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.btn{border:0;border-radius:9px;padding:9px 13px;font-weight:750;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-primary{background:#0f766e;color:#fff}.btn-secondary{background:#e2e8f0;color:#172033}.btn-danger{background:#fee2e2;color:#991b1b}.btn-warning{background:#fff7ed;color:#9a3412}.btn-small{padding:7px 10px;font-size:.82rem}.task-list{display:grid;gap:13px}.task-card{padding:15px}.task-card.needs-action{border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.12)}.task-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.task-top h2{font-size:1.05rem;margin:0}.work-meta{font-size:.83rem;color:#64748b;margin-top:7px}.pill{padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.76rem;font-weight:800}.pill-red{background:#fef2f2;color:#b91c1c}.pill-amber{background:#fff7ed;color:#9a3412}.pill-green{background:#ecfdf5;color:#166534}.pill-blue{background:#eff6ff;color:#1d4ed8}.task-content{margin-top:12px;padding-top:12px;border-top:1px solid #edf2f7}.task-copy{display:grid;grid-template-columns:1fr 1fr;gap:12px}.task-copy p{margin:4px 0;color:#475569;white-space:pre-wrap}.thread{display:grid;gap:8px;margin:12px 0}.message{padding:10px 12px;border-radius:10px;background:#f8fafc;border-left:3px solid #94a3b8}.message.admin{border-left-color:#0f766e}.message.employee{border-left-color:#2563eb}.message small{color:#64748b}.reply-form{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:9px}.action-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}.action-forms form{padding:10px;background:#f8fafc;border-radius:10px}.action-forms label,.personal-form label{font-size:.83rem;font-weight:700;display:block;margin-bottom:5px}.alert{padding:12px 14px;border-radius:10px;margin-bottom:14px}.alert-success{background:#ecfdf5;color:#166534}.alert-error{background:#fef2f2;color:#991b1b}.empty{padding:30px;text-align:center;color:#64748b;background:#fff;border-radius:14px}.muted{color:#64748b}.personal-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:9px;align-items:end}@media(max-width:800px){.work-stats{grid-template-columns:repeat(2,1fr)}.task-copy,.action-forms,.personal-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.reply-form{grid-template-columns:1fr}.app-card{align-items:flex-start;flex-direction:column}}@media(max-width:480px){.work-shell{padding:12px 9px 85px}.work-stats{grid-template-columns:1fr 1fr}.task-card,.panel{padding:13px}}
  </style>
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
<main class="work-shell">
  <header class="work-header">
    <div><h1>My Work</h1><p>Hello <?= employee_task_safe($employeeName) ?>. Acknowledge assignments, share progress, and submit finished work for admin approval.</p></div>
    <a class="btn btn-secondary" href="employee-dashboard.php">Dashboard</a>
  </header>

  <?php if ($flash !== ''): ?><div class="alert alert-success" role="status"><?= employee_task_safe($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= employee_task_safe($error) ?></div><?php endif; ?>

  <section class="app-card">
    <div><strong>Use Dakshayani Work like an app</strong><p>Install it on your phone for one-tap access to tasks, customers and work tools.</p></div>
    <a class="btn btn-primary" href="employee-app.php">Install / app help</a>
  </section>

  <section class="work-stats" aria-label="My task summary">
    <article class="stat"><b><?= $counts['active'] ?></b><span>Active</span></article>
    <article class="stat"><b><?= $counts['needs_me'] ?></b><span>Needs my action</span></article>
    <article class="stat"><b><?= $counts['overdue'] ?></b><span>Overdue</span></article>
    <article class="stat"><b><?= $counts['waiting_admin'] ?></b><span>Waiting for admin</span></article>
    <article class="stat"><b><?= $counts['completed'] ?></b><span>Approved complete</span></article>
  </section>

  <section class="panel">
    <form class="filters" method="get">
      <input class="task-input" type="search" name="q" placeholder="Search tasks or project…" value="<?= employee_task_safe($search) ?>">
      <select class="task-input" name="view">
        <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active tasks</option>
        <option value="needs_me" <?= $filter === 'needs_me' ? 'selected' : '' ?>>Needs my action</option>
        <option value="waiting_admin" <?= $filter === 'waiting_admin' ? 'selected' : '' ?>>Waiting for admin</option>
        <option value="submitted" <?= $filter === 'submitted' ? 'selected' : '' ?>>Awaiting review</option>
        <option value="blocked" <?= $filter === 'blocked' ? 'selected' : '' ?>>Blocked</option>
        <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
      </select>
      <button class="btn btn-secondary" type="submit">Filter</button>
    </form>
  </section>

  <section class="task-list">
    <?php if ($filteredTasks === []): ?><div class="empty">No tasks match this view.</div><?php endif; ?>
    <?php foreach ($filteredTasks as $task):
        $workflow = task_workflow_status($task);
        $due = get_effective_due_date($task);
        $needsAction = (string) ($task['attention_owner'] ?? '') === 'employee' || $workflow === 'assigned';
    ?>
      <article class="task-card <?= $needsAction ? 'needs-action' : '' ?>">
        <div class="task-top">
          <div>
            <h2><?= employee_task_safe((string)$task['title']) ?></h2>
            <div class="work-meta">
              <span><?= employee_task_safe((string)$task['category']) ?></span>
              <?php if ((string)$task['project_reference'] !== ''): ?><span><?= employee_task_safe((string)$task['project_reference']) ?></span><?php endif; ?>
              <span>Assigned by <?= employee_task_safe((string)($task['assigned_by_name'] ?: 'Admin')) ?></span>
            </div>
          </div>
          <div class="work-actions">
            <span class="pill <?= $workflow === 'blocked' ? 'pill-red' : ($workflow === 'submitted' ? 'pill-amber' : ($workflow === 'completed' ? 'pill-green' : 'pill-blue')) ?>"><?= employee_task_safe(task_workflow_label($workflow)) ?></span>
            <span class="pill"><?= employee_task_safe((string)$task['priority']) ?></span>
            <span class="pill <?= is_overdue($task, $today) ? 'pill-red' : '' ?>"><?= employee_task_safe($due ?: 'No due date') ?></span>
            <?php if ($needsAction): ?><span class="pill pill-amber">Your action</span><?php endif; ?>
          </div>
        </div>

        <details class="task-content" <?= $needsAction ? 'open' : '' ?>>
          <summary><strong>Open task and conversation</strong></summary>
          <div class="task-copy">
            <div><strong>Expected result</strong><p><?= employee_task_safe((string)($task['expected_outcome'] ?: 'Not specified')) ?></p></div>
            <div><strong>Instructions</strong><p><?= employee_task_safe((string)($task['description'] ?: 'No additional instructions')) ?></p></div>
          </div>
          <p class="muted"><strong>Schedule:</strong> <?= employee_task_safe(task_frequency_label($task)) ?> · <strong>Occurrence:</strong> <?= (int)$task['occurrence_number'] ?></p>

          <div class="thread">
            <?php if ($task['thread'] === []): ?><p class="muted">No messages yet.</p><?php endif; ?>
            <?php foreach ($task['thread'] as $entry): ?>
              <div class="message <?= employee_task_safe((string)($entry['actor_type'] ?? '')) ?>">
                <strong><?= employee_task_safe((string)($entry['actor_name'] ?? ucfirst((string)($entry['actor_type'] ?? 'User')))) ?></strong>
                <small> · <?= employee_task_safe((string)($entry['created_at'] ?? '')) ?></small>
                <div><?= nl2br(employee_task_safe((string)($entry['message'] ?? ''))) ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (!in_array($workflow, ['completed','cancelled'], true)): ?>
          <form method="post" class="reply-form">
            <?= csrf_field() ?><input type="hidden" name="task_action" value="reply"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>">
            <input class="task-input" name="message" required placeholder="Reply to admin or share a progress update…"><button class="btn btn-primary" type="submit">Send update</button>
          </form>
          <?php endif; ?>

          <div class="action-forms">
            <div>
              <strong>Progress</strong>
              <div class="work-actions" style="margin-top:8px">
                <?php if ($workflow === 'assigned'): ?>
                <form method="post" style="padding:0;background:transparent"><?= csrf_field() ?><input type="hidden" name="task_action" value="acknowledge"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>"><button class="btn btn-secondary btn-small" type="submit">Acknowledge</button></form>
                <form method="post" style="padding:0;background:transparent"><?= csrf_field() ?><input type="hidden" name="task_action" value="start"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>"><button class="btn btn-primary btn-small" type="submit">Start work</button></form>
                <?php elseif ($workflow === 'acknowledged'): ?>
                <form method="post" style="padding:0;background:transparent"><?= csrf_field() ?><input type="hidden" name="task_action" value="start"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>"><button class="btn btn-primary btn-small" type="submit">Start work</button></form>
                <?php elseif ($workflow === 'blocked'): ?>
                <form method="post" style="padding:0;background:transparent"><?= csrf_field() ?><input type="hidden" name="task_action" value="resume"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>"><button class="btn btn-primary btn-small" type="submit">Blocker resolved — resume</button></form>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!in_array($workflow, ['submitted','completed','cancelled'], true)): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="task_action" value="block"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>">
              <label>Report a blocker</label><textarea class="task-input" name="message" rows="2" required placeholder="What is blocked? What decision, material or support is needed?"></textarea><button class="btn btn-warning btn-small" style="margin-top:7px" type="submit">Send blocker to admin</button>
            </form>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="task_action" value="submit"><input type="hidden" name="task_id" value="<?= employee_task_safe((string)$task['id']) ?>">
              <label>Submit completed work</label><textarea class="task-input" name="message" rows="2" required placeholder="Summarise what was completed and mention proof, file, customer confirmation or result"></textarea><button class="btn btn-primary btn-small" style="margin-top:7px" type="submit">Submit for admin approval</button>
            </form>
            <?php elseif ($workflow === 'submitted'): ?>
            <div><strong>Submitted for review</strong><p class="muted">Admin will approve it or return it with corrections. You can still send an update above.</p></div>
            <?php elseif ($workflow === 'completed'): ?>
            <div><strong>Approved complete</strong><p class="muted">Approved on <?= employee_task_safe((string)($task['last_completed_at'] ?: $task['approved_at'])) ?>.</p></div>
            <?php endif; ?>
          </div>
        </details>
      </article>
    <?php endforeach; ?>
  </section>

  <details class="panel" style="margin-top:15px">
    <summary><strong>Add a personal follow-up</strong></summary>
    <p class="muted">Use this only for your own reminders. Admin-assigned work will appear automatically above.</p>
    <form method="post" class="personal-grid personal-form">
      <?= csrf_field() ?><input type="hidden" name="task_action" value="create_personal">
      <div><label>Title</label><input class="task-input" name="title" required></div>
      <div><label>Due date</label><input class="task-input" type="date" name="due_date" value="<?= employee_task_safe($defaultDue) ?>"></div>
      <div><label>Priority</label><select class="task-input" name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
      <button class="btn btn-secondary" type="submit">Add</button>
      <div style="grid-column:1/-1"><label>Notes</label><textarea class="task-input" name="description" rows="2"></textarea></div>
    </form>
  </details>
</main>
</body>
</html>
