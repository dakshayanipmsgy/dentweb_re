<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/tasks_helpers.php';

require_admin();
$currentUser = current_user() ?? [];
$adminId = (string) ($currentUser['id'] ?? 'admin');
$adminName = trim((string) ($currentUser['full_name'] ?? $currentUser['username'] ?? 'Admin')) ?: 'Admin';
$employeeStore = new EmployeeFsStore();
$employees = $employeeStore->listEmployees();
$today = tasks_today_date();
$error = '';
$flash = isset($_GET['msg']) && is_string($_GET['msg']) ? trim($_GET['msg']) : '';

function admin_task_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_task_employee_name(array $employees, string $employeeId): string
{
    foreach ($employees as $employee) {
        if ((string) ($employee['id'] ?? '') === $employeeId && (string) ($employee['status'] ?? 'active') === 'active') {
            return trim((string) ($employee['name'] ?? ''));
        }
    }
    return '';
}

function admin_task_find_index(array $tasks, string $taskId): ?int
{
    foreach ($tasks as $index => $task) {
        if ((string) ($task['id'] ?? '') === $taskId) {
            return $index;
        }
    }
    return null;
}

function admin_task_redirect(string $message): never
{
    header('Location: admin-tasks.php?msg=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = trim((string) ($_POST['task_action'] ?? ''));
    $operationError = '';

    if ($action === 'create') {
        $assignedId = trim((string) ($_POST['assigned_to_id'] ?? ''));
        $assignedName = admin_task_employee_name($employees, $assignedId);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $expectedOutcome = trim((string) ($_POST['expected_outcome'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'General')) ?: 'General';
        $projectReference = trim((string) ($_POST['project_reference'] ?? ''));
        $priority = strtolower(trim((string) ($_POST['priority'] ?? 'medium')));
        $frequency = strtolower(trim((string) ($_POST['frequency_type'] ?? 'once')));
        $customDays = max(1, (int) ($_POST['custom_every_n_days'] ?? 1));
        $startDate = tasks_valid_date((string) ($_POST['start_date'] ?? ''), $today);
        $dueDate = tasks_valid_date((string) ($_POST['due_date'] ?? ''), $startDate);
        $nextDueDate = tasks_valid_date((string) ($_POST['next_due_date'] ?? ''), $startDate);

        if ($assignedName === '') {
            $operationError = 'Please select an active employee.';
        } elseif ($title === '') {
            $operationError = 'Task title is required.';
        } elseif (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $operationError = 'Invalid priority.';
        } elseif (!in_array($frequency, ['once', 'daily', 'weekly', 'monthly', 'custom'], true)) {
            $operationError = 'Invalid frequency.';
        }

        if ($operationError === '') {
            $saved = tasks_mutate(function (array &$tasks) use ($assignedId, $assignedName, $title, $description, $expectedOutcome, $category, $projectReference, $priority, $frequency, $customDays, $startDate, $dueDate, $nextDueDate, $adminId, $adminName): void {
                $now = tasks_now_timestamp();
                $task = task_normalize([
                    'id' => generate_task_id(),
                    'title' => $title,
                    'description' => $description,
                    'expected_outcome' => $expectedOutcome,
                    'category' => $category,
                    'project_reference' => $projectReference,
                    'priority' => ucfirst($priority),
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                    'created_by_name' => $adminName,
                    'assigned_by_name' => $adminName,
                    'assigned_to_id' => $assignedId,
                    'assigned_to_name' => $assignedName,
                    'frequency_type' => $frequency,
                    'custom_every_n_days' => $frequency === 'custom' ? $customDays : 0,
                    'start_date' => $startDate,
                    'due_date' => $frequency === 'once' ? $dueDate : '',
                    'next_due_date' => $frequency === 'once' ? '' : $nextDueDate,
                    'workflow_status' => 'assigned',
                    'status' => 'Open',
                    'attention_owner' => 'employee',
                    'archived_flag' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_activity_at' => $now,
                ]);
                task_add_activity($task, 'admin', $adminId, $adminName, 'Task assigned', 'Assigned to ' . $assignedName . '.');
                $tasks[] = $task;
            });
            if ($saved) {
                admin_task_redirect('Task assigned to ' . $assignedName);
            }
            $operationError = tasks_last_error() ?: 'Could not create task.';
        }
    } elseif (in_array($action, ['reply', 'approve', 'reopen', 'update', 'archive', 'unarchive', 'cancel'], true)) {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($taskId === '') {
            $operationError = 'Task was not selected.';
        }

        if ($operationError === '') {
            $saved = tasks_mutate(function (array &$tasks) use ($action, $taskId, $message, $employees, $adminId, $adminName, &$operationError): void {
                $index = admin_task_find_index($tasks, $taskId);
                if ($index === null) {
                    $operationError = 'Task not found.';
                    return;
                }

                $task = task_normalize($tasks[$index]);
                $workflow = task_workflow_status($task);

                if ($action === 'reply') {
                    if ($message === '') {
                        $operationError = 'Reply cannot be empty.';
                        return;
                    }
                    task_add_message($task, 'admin', $adminId, $adminName, $message);
                    if (!in_array($workflow, ['completed', 'cancelled'], true)) {
                        $task['attention_owner'] = 'employee';
                    }
                    task_add_activity($task, 'admin', $adminId, $adminName, 'Admin replied');
                } elseif ($action === 'approve') {
                    if ($workflow !== 'submitted') {
                        $operationError = 'Only submitted work can be approved.';
                        return;
                    }
                    $now = tasks_now_timestamp();
                    if ($message !== '') {
                        task_add_message($task, 'admin', $adminId, $adminName, $message, 'approval');
                    }
                    $task['completion_log'][] = [
                        'completed_at' => $now,
                        'completed_by_type' => 'admin',
                        'completed_by_id' => $adminId,
                        'completed_by_name' => $adminName,
                        'note' => $message,
                        'employee_submission_at' => (string) ($task['submitted_at'] ?? ''),
                    ];
                    $task['last_completed_at'] = $now;
                    $task['approved_at'] = $now;
                    $frequency = strtolower((string) ($task['frequency_type'] ?? 'once'));
                    if ($frequency === 'once') {
                        task_set_workflow($task, 'completed', 'none');
                        task_add_activity($task, 'admin', $adminId, $adminName, 'Work approved and task completed');
                    } else {
                        $task['next_due_date'] = compute_next_due_after_completion($frequency, get_effective_due_date($task), (int) ($task['custom_every_n_days'] ?? 0));
                        $task['occurrence_number'] = max(1, (int) ($task['occurrence_number'] ?? 1)) + 1;
                        $task['acknowledged_at'] = '';
                        $task['started_at'] = '';
                        $task['submitted_at'] = '';
                        $task['approved_at'] = '';
                        task_set_workflow($task, 'assigned', 'employee');
                        task_add_activity($task, 'admin', $adminId, $adminName, 'Occurrence approved', 'Next occurrence due ' . $task['next_due_date'] . '.');
                    }
                } elseif ($action === 'reopen') {
                    if ($message === '') {
                        $operationError = 'Give the employee a reason or correction note.';
                        return;
                    }
                    task_add_message($task, 'admin', $adminId, $adminName, $message, 'reopen');
                    task_set_workflow($task, 'in_progress', 'employee');
                    $task['submitted_at'] = '';
                    $task['approved_at'] = '';
                    task_add_activity($task, 'admin', $adminId, $adminName, 'Task reopened', $message);
                } elseif ($action === 'update') {
                    $newEmployeeId = trim((string) ($_POST['assigned_to_id'] ?? ''));
                    $newEmployeeName = admin_task_employee_name($employees, $newEmployeeId);
                    $newPriority = strtolower(trim((string) ($_POST['priority'] ?? 'medium')));
                    $newDueDate = tasks_valid_date((string) ($_POST['due_date'] ?? ''), get_effective_due_date($task));
                    $newCategory = trim((string) ($_POST['category'] ?? $task['category'])) ?: 'General';
                    $newProject = trim((string) ($_POST['project_reference'] ?? $task['project_reference']));

                    if ($newEmployeeName === '') {
                        $operationError = 'Select an active employee.';
                        return;
                    }
                    if (!in_array($newPriority, ['low', 'medium', 'high', 'urgent'], true)) {
                        $operationError = 'Invalid priority.';
                        return;
                    }

                    $reassigned = $newEmployeeId !== (string) ($task['assigned_to_id'] ?? '');
                    $task['assigned_to_id'] = $newEmployeeId;
                    $task['assigned_to_name'] = $newEmployeeName;
                    $task['priority'] = ucfirst($newPriority);
                    $task['category'] = $newCategory;
                    $task['project_reference'] = $newProject;
                    if (strtolower((string) ($task['frequency_type'] ?? 'once')) === 'once') {
                        $task['due_date'] = $newDueDate;
                    } else {
                        $task['next_due_date'] = $newDueDate;
                    }
                    if ($reassigned) {
                        $task['acknowledged_at'] = '';
                        $task['started_at'] = '';
                        $task['submitted_at'] = '';
                        task_set_workflow($task, 'assigned', 'employee');
                    } else {
                        $task['attention_owner'] = 'employee';
                    }
                    task_add_activity($task, 'admin', $adminId, $adminName, $reassigned ? 'Task reassigned' : 'Task details updated', 'Assigned to ' . $newEmployeeName . '; due ' . $newDueDate . '.');
                } elseif ($action === 'archive' || $action === 'unarchive') {
                    $task['archived_flag'] = $action === 'archive';
                    $task['updated_at'] = tasks_now_timestamp();
                    task_add_activity($task, 'admin', $adminId, $adminName, $action === 'archive' ? 'Task archived' : 'Task restored');
                } elseif ($action === 'cancel') {
                    if ($message === '') {
                        $operationError = 'Add a cancellation reason.';
                        return;
                    }
                    task_add_message($task, 'admin', $adminId, $adminName, $message, 'cancellation');
                    task_set_workflow($task, 'cancelled', 'none');
                    task_add_activity($task, 'admin', $adminId, $adminName, 'Task cancelled', $message);
                }

                $tasks[$index] = task_normalize($task);
            });

            if ($operationError !== '') {
                $error = $operationError;
            } elseif ($saved) {
                $labels = [
                    'reply' => 'Reply sent', 'approve' => 'Work approved', 'reopen' => 'Task reopened',
                    'update' => 'Task updated', 'archive' => 'Task archived', 'unarchive' => 'Task restored', 'cancel' => 'Task cancelled',
                ];
                admin_task_redirect($labels[$action] ?? 'Task updated');
            } else {
                $error = tasks_last_error() ?: 'Could not update task.';
            }
        }
    }

    if ($operationError !== '' && $error === '') {
        $error = $operationError;
    }
}

$tasks = load_tasks();
$filterEmployee = trim((string) ($_GET['employee'] ?? 'all'));
$filterWorkflow = strtolower(trim((string) ($_GET['workflow'] ?? 'active')));
$filterAttention = strtolower(trim((string) ($_GET['attention'] ?? 'all')));
$filterDue = strtolower(trim((string) ($_GET['due'] ?? 'all')));
$search = trim((string) ($_GET['q'] ?? ''));

$counts = ['active' => 0, 'overdue' => 0, 'review' => 0, 'blocked' => 0, 'employee_action' => 0];
foreach ($tasks as $task) {
    if (!task_is_active($task)) {
        continue;
    }
    $counts['active']++;
    if (is_overdue($task, $today)) {
        $counts['overdue']++;
    }
    if (task_workflow_status($task) === 'submitted') {
        $counts['review']++;
    }
    if (task_workflow_status($task) === 'blocked') {
        $counts['blocked']++;
    }
    if ((string) ($task['attention_owner'] ?? '') === 'employee') {
        $counts['employee_action']++;
    }
}

$filteredTasks = array_values(array_filter($tasks, static function (array $task) use ($filterEmployee, $filterWorkflow, $filterAttention, $filterDue, $search, $today): bool {
    if ($filterEmployee !== 'all' && (string) ($task['assigned_to_id'] ?? '') !== $filterEmployee) {
        return false;
    }
    $workflow = task_workflow_status($task);
    if ($filterWorkflow === 'active' && !task_is_active($task)) {
        return false;
    }
    if (!in_array($filterWorkflow, ['all', 'active'], true) && $workflow !== $filterWorkflow) {
        return false;
    }
    if ($filterAttention !== 'all' && (string) ($task['attention_owner'] ?? 'none') !== $filterAttention) {
        return false;
    }
    if ($filterDue === 'overdue' && !is_overdue($task, $today)) {
        return false;
    }
    if ($filterDue === 'today' && !is_due_today($task, $today)) {
        return false;
    }
    if ($filterDue === 'upcoming' && (get_effective_due_date($task) === '' || strcmp(get_effective_due_date($task), $today) <= 0)) {
        return false;
    }
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($task['title'] ?? ''), (string) ($task['description'] ?? ''),
            (string) ($task['expected_outcome'] ?? ''), (string) ($task['project_reference'] ?? ''),
            (string) ($task['assigned_to_name'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($search))) {
            return false;
        }
    }
    return true;
}));

$priorityRank = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($filteredTasks, static function (array $left, array $right) use ($priorityRank): int {
    $attentionLeft = (string) ($left['attention_owner'] ?? '') === 'admin' ? 0 : 1;
    $attentionRight = (string) ($right['attention_owner'] ?? '') === 'admin' ? 0 : 1;
    if ($attentionLeft !== $attentionRight) {
        return $attentionLeft <=> $attentionRight;
    }
    $leftDue = get_effective_due_date($left) ?: '9999-12-31';
    $rightDue = get_effective_due_date($right) ?: '9999-12-31';
    if ($leftDue !== $rightDue) {
        return strcmp($leftDue, $rightDue);
    }
    return ($priorityRank[strtolower((string) ($left['priority'] ?? 'medium'))] ?? 2)
        <=> ($priorityRank[strtolower((string) ($right['priority'] ?? 'medium'))] ?? 2);
});

$defaultDue = (new DateTimeImmutable('today', new DateTimeZone(TASKS_TIMEZONE)))->modify('+3 days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Task Control Centre | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="assets/css/admin-unified.css">
  <style>
    body{background:#f4f7fb;color:#172033}.task-shell{max-width:1500px;margin:0 auto;padding:24px}.task-header,.task-row-head,.task-actions,.task-meta,.task-stats{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.task-header{justify-content:space-between;margin-bottom:18px}.task-header h1{margin:0}.task-header p{margin:5px 0 0;color:#64748b}.task-stats{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));margin:16px 0}.task-stat,.task-panel,.task-card{background:#fff;border:1px solid #dfe7f1;border-radius:15px;box-shadow:0 10px 28px rgba(15,23,42,.05)}.task-stat{padding:16px}.task-stat b{display:block;font-size:1.65rem}.task-stat span{color:#64748b}.task-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:18px;align-items:start}.task-panel{padding:18px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid .wide{grid-column:1/-1}.task-panel label,.task-card label{font-size:.84rem;font-weight:700;display:block;margin-bottom:5px}.task-input{width:100%;padding:10px 11px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.btn{border:0;border-radius:9px;padding:9px 13px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-primary{background:#0f766e;color:#fff}.btn-secondary{background:#e2e8f0;color:#172033}.btn-danger{background:#fee2e2;color:#991b1b}.btn-warning{background:#fff7ed;color:#9a3412}.btn-small{padding:7px 10px;font-size:.82rem}.alert{padding:12px 14px;border-radius:10px;margin-bottom:14px}.alert-success{background:#ecfdf5;color:#166534}.alert-error{background:#fef2f2;color:#991b1b}.filters{display:grid;grid-template-columns:2fr repeat(4,1fr) auto;gap:9px;margin-bottom:14px}.task-list{display:grid;gap:13px}.task-card{padding:16px}.task-row-head{justify-content:space-between;align-items:flex-start}.task-title{margin:0;font-size:1.05rem}.task-meta{font-size:.84rem;color:#64748b;margin-top:7px}.pill{padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.77rem;font-weight:800}.pill-red{background:#fef2f2;color:#b91c1c}.pill-amber{background:#fff7ed;color:#9a3412}.pill-green{background:#ecfdf5;color:#166534}.pill-blue{background:#eff6ff;color:#1d4ed8}.task-body{margin-top:12px;padding-top:12px;border-top:1px solid #edf2f7}.task-copy{display:grid;grid-template-columns:1fr 1fr;gap:12px}.task-copy p{margin:4px 0;color:#475569;white-space:pre-wrap}.thread{display:grid;gap:8px;margin:12px 0}.message{padding:10px 12px;border-radius:10px;background:#f8fafc;border-left:3px solid #94a3b8}.message.admin{border-left-color:#0f766e}.message.employee{border-left-color:#2563eb}.message small{color:#64748b}.inline-form{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:9px}.admin-tools{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px;padding-top:12px;border-top:1px dashed #cbd5e1}.admin-tools form{padding:10px;background:#f8fafc;border-radius:10px}.empty{padding:30px;text-align:center;color:#64748b;background:#fff;border-radius:14px}.muted{color:#64748b}.attention{border-color:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.12)}@media(max-width:1050px){.task-grid{grid-template-columns:1fr}.filters{grid-template-columns:repeat(2,1fr)}.task-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.task-shell{padding:14px 10px 80px}.task-stats,.form-grid,.filters,.task-copy,.admin-tools{grid-template-columns:1fr}.inline-form{grid-template-columns:1fr}.task-header{align-items:flex-start}.task-panel{padding:14px}}
  </style>
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
<main class="task-shell">
  <header class="task-header">
    <div><h1>Task Control Centre</h1><p>Assign clear work, receive staff updates, review submissions, and keep responsibility visible.</p></div>
    <a class="btn btn-secondary" href="admin-dashboard.php">Back to dashboard</a>
  </header>

  <?php if ($flash !== ''): ?><div class="alert alert-success" role="status"><?= admin_task_safe($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= admin_task_safe($error) ?></div><?php endif; ?>

  <section class="task-stats" aria-label="Task summary">
    <article class="task-stat"><b><?= $counts['active'] ?></b><span>Active tasks</span></article>
    <article class="task-stat"><b><?= $counts['review'] ?></b><span>Awaiting admin review</span></article>
    <article class="task-stat"><b><?= $counts['blocked'] ?></b><span>Blocked by staff</span></article>
    <article class="task-stat"><b><?= $counts['overdue'] ?></b><span>Overdue</span></article>
    <article class="task-stat"><b><?= $counts['employee_action'] ?></b><span>Waiting on employee</span></article>
  </section>

  <section class="task-grid">
    <aside class="task-panel">
      <h2 style="margin-top:0">Assign a task</h2>
      <p class="muted">Define the result, owner and deadline. The employee acknowledges, updates and submits it for your approval.</p>
      <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="task_action" value="create">
        <div class="wide"><label for="assigned_to_id">Responsible employee</label><select class="task-input" id="assigned_to_id" name="assigned_to_id" required><option value="">Select employee</option><?php foreach ($employees as $employee): if ((string)($employee['status'] ?? 'active') !== 'active') continue; ?><option value="<?= admin_task_safe((string)($employee['id'] ?? '')) ?>"><?= admin_task_safe((string)($employee['name'] ?? 'Employee')) ?></option><?php endforeach; ?></select></div>
        <div class="wide"><label for="title">Task title</label><input class="task-input" id="title" name="title" required placeholder="Example: Complete net-metering documents for Sharma site"></div>
        <div class="wide"><label for="expected_outcome">Expected result / proof</label><textarea class="task-input" id="expected_outcome" name="expected_outcome" rows="2" placeholder="What should be delivered, uploaded or confirmed?"></textarea></div>
        <div class="wide"><label for="description">Instructions</label><textarea class="task-input" id="description" name="description" rows="4" placeholder="Steps, contact person, dependencies, or special instructions"></textarea></div>
        <div><label for="category">Category</label><select class="task-input" id="category" name="category"><option>Operations</option><option>Customer follow-up</option><option>Procurement</option><option>Installation</option><option>Documentation</option><option>Finance</option><option>Service</option><option>General</option></select></div>
        <div><label for="project_reference">Customer / project reference</label><input class="task-input" id="project_reference" name="project_reference" placeholder="Name, mobile, site or work order"></div>
        <div><label for="priority">Priority</label><select class="task-input" id="priority" name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
        <div><label for="frequency_type">Schedule</label><select class="task-input" id="frequency_type" name="frequency_type"><option value="once">One time</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="custom">Every N days</option></select></div>
        <div><label for="custom_every_n_days">N days</label><input class="task-input" type="number" id="custom_every_n_days" name="custom_every_n_days" min="1" value="7"></div>
        <div><label for="start_date">Start date</label><input class="task-input" type="date" id="start_date" name="start_date" value="<?= admin_task_safe($today) ?>"></div>
        <div><label for="due_date">Due date (one time)</label><input class="task-input" type="date" id="due_date" name="due_date" value="<?= admin_task_safe($defaultDue) ?>"></div>
        <div><label for="next_due_date">First due date (recurring)</label><input class="task-input" type="date" id="next_due_date" name="next_due_date" value="<?= admin_task_safe($today) ?>"></div>
        <div class="wide"><button class="btn btn-primary" type="submit">Assign task</button></div>
      </form>
    </aside>

    <section>
      <form method="get" class="filters" aria-label="Task filters">
        <input class="task-input" type="search" name="q" placeholder="Search task, employee, project…" value="<?= admin_task_safe($search) ?>">
        <select class="task-input" name="employee"><option value="all">All employees</option><?php foreach ($employees as $employee): ?><option value="<?= admin_task_safe((string)($employee['id'] ?? '')) ?>" <?= $filterEmployee === (string)($employee['id'] ?? '') ? 'selected' : '' ?>><?= admin_task_safe((string)($employee['name'] ?? 'Employee')) ?></option><?php endforeach; ?></select>
        <select class="task-input" name="workflow"><option value="active" <?= $filterWorkflow === 'active' ? 'selected' : '' ?>>All active</option><option value="all" <?= $filterWorkflow === 'all' ? 'selected' : '' ?>>All including closed</option><?php foreach (TASK_WORKFLOW_STATUSES as $status): ?><option value="<?= $status ?>" <?= $filterWorkflow === $status ? 'selected' : '' ?>><?= admin_task_safe(task_workflow_label($status)) ?></option><?php endforeach; ?></select>
        <select class="task-input" name="attention"><option value="all">Any attention</option><option value="admin" <?= $filterAttention === 'admin' ? 'selected' : '' ?>>Needs admin</option><option value="employee" <?= $filterAttention === 'employee' ? 'selected' : '' ?>>Needs employee</option><option value="none" <?= $filterAttention === 'none' ? 'selected' : '' ?>>No pending response</option></select>
        <select class="task-input" name="due"><option value="all">Any due date</option><option value="overdue" <?= $filterDue === 'overdue' ? 'selected' : '' ?>>Overdue</option><option value="today" <?= $filterDue === 'today' ? 'selected' : '' ?>>Due today</option><option value="upcoming" <?= $filterDue === 'upcoming' ? 'selected' : '' ?>>Upcoming</option></select>
        <button class="btn btn-secondary" type="submit">Filter</button>
      </form>

      <div class="task-list">
      <?php if ($filteredTasks === []): ?><div class="empty">No tasks match these filters.</div><?php endif; ?>
      <?php foreach ($filteredTasks as $task):
          $workflow = task_workflow_status($task);
          $due = get_effective_due_date($task);
          $attention = (string) ($task['attention_owner'] ?? 'none');
          $isAttention = $attention === 'admin';
      ?>
        <article class="task-card <?= $isAttention ? 'attention' : '' ?>">
          <div class="task-row-head">
            <div>
              <h3 class="task-title"><?= admin_task_safe((string)$task['title']) ?></h3>
              <div class="task-meta">
                <span><?= admin_task_safe((string)$task['assigned_to_name']) ?></span>
                <span><?= admin_task_safe((string)$task['category']) ?></span>
                <?php if ((string)$task['project_reference'] !== ''): ?><span><?= admin_task_safe((string)$task['project_reference']) ?></span><?php endif; ?>
                <span>Updated <?= admin_task_safe((string)$task['last_activity_at']) ?></span>
              </div>
            </div>
            <div class="task-actions">
              <span class="pill <?= $workflow === 'blocked' ? 'pill-red' : ($workflow === 'submitted' ? 'pill-amber' : ($workflow === 'completed' ? 'pill-green' : 'pill-blue')) ?>"><?= admin_task_safe(task_workflow_label($workflow)) ?></span>
              <span class="pill"><?= admin_task_safe((string)$task['priority']) ?></span>
              <span class="pill <?= is_overdue($task, $today) ? 'pill-red' : '' ?>"><?= admin_task_safe($due ?: 'No due date') ?></span>
              <?php if ($attention !== 'none'): ?><span class="pill pill-amber"><?= $attention === 'admin' ? 'Admin action' : 'Employee action' ?></span><?php endif; ?>
            </div>
          </div>

          <details class="task-body" <?= $isAttention ? 'open' : '' ?>>
            <summary><strong>Open task details and conversation</strong></summary>
            <div class="task-copy">
              <div><strong>Expected result</strong><p><?= admin_task_safe((string)($task['expected_outcome'] ?: 'Not specified')) ?></p></div>
              <div><strong>Instructions</strong><p><?= admin_task_safe((string)($task['description'] ?: 'No additional instructions')) ?></p></div>
            </div>
            <p class="muted"><strong>Schedule:</strong> <?= admin_task_safe(task_frequency_label($task)) ?> · <strong>Occurrence:</strong> <?= (int)$task['occurrence_number'] ?> · <strong>Assigned by:</strong> <?= admin_task_safe((string)($task['assigned_by_name'] ?: 'Admin')) ?></p>

            <div class="thread" aria-label="Task conversation">
              <?php if ($task['thread'] === []): ?><p class="muted">No replies yet.</p><?php endif; ?>
              <?php foreach ($task['thread'] as $entry): ?>
                <div class="message <?= admin_task_safe((string)($entry['actor_type'] ?? '')) ?>">
                  <strong><?= admin_task_safe((string)($entry['actor_name'] ?? ucfirst((string)($entry['actor_type'] ?? 'User')))) ?></strong>
                  <small> · <?= admin_task_safe((string)($entry['created_at'] ?? '')) ?></small>
                  <div><?= nl2br(admin_task_safe((string)($entry['message'] ?? ''))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if (!in_array($workflow, ['completed','cancelled'], true)): ?>
            <form method="post" class="inline-form">
              <?= csrf_field() ?><input type="hidden" name="task_action" value="reply"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>">
              <input class="task-input" name="message" required placeholder="Reply, clarify, or ask for an update…"><button class="btn btn-primary" type="submit">Send reply</button>
            </form>
            <?php endif; ?>

            <div class="admin-tools">
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="task_action" value="update"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>">
                <strong>Edit responsibility</strong>
                <label>Employee</label><select class="task-input" name="assigned_to_id"><?php foreach ($employees as $employee): if ((string)($employee['status'] ?? 'active') !== 'active') continue; ?><option value="<?= admin_task_safe((string)($employee['id'] ?? '')) ?>" <?= (string)$task['assigned_to_id'] === (string)($employee['id'] ?? '') ? 'selected' : '' ?>><?= admin_task_safe((string)($employee['name'] ?? 'Employee')) ?></option><?php endforeach; ?></select>
                <label>Due date</label><input class="task-input" type="date" name="due_date" value="<?= admin_task_safe($due) ?>">
                <label>Priority</label><select class="task-input" name="priority"><?php foreach (['low','medium','high','urgent'] as $priority): ?><option value="<?= $priority ?>" <?= strtolower((string)$task['priority']) === $priority ? 'selected' : '' ?>><?= ucfirst($priority) ?></option><?php endforeach; ?></select>
                <label>Category</label><input class="task-input" name="category" value="<?= admin_task_safe((string)$task['category']) ?>">
                <label>Project reference</label><input class="task-input" name="project_reference" value="<?= admin_task_safe((string)$task['project_reference']) ?>">
                <button class="btn btn-secondary btn-small" style="margin-top:8px" type="submit">Save changes</button>
              </form>

              <div>
                <strong>Decision</strong>
                <?php if ($workflow === 'submitted'): ?>
                <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="task_action" value="approve"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>"><textarea class="task-input" name="message" rows="2" placeholder="Approval note (optional)"></textarea><button class="btn btn-primary btn-small" style="margin-top:7px" type="submit">Approve work</button></form>
                <?php endif; ?>
                <?php if (in_array($workflow, ['submitted','blocked','completed'], true)): ?>
                <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="task_action" value="reopen"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>"><textarea class="task-input" name="message" rows="2" required placeholder="Correction or next action required"></textarea><button class="btn btn-warning btn-small" style="margin-top:7px" type="submit">Reopen / send back</button></form>
                <?php endif; ?>
                <?php if (!in_array($workflow, ['completed','cancelled'], true)): ?>
                <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="task_action" value="cancel"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>"><input class="task-input" name="message" required placeholder="Cancellation reason"><button class="btn btn-danger btn-small" style="margin-top:7px" type="submit">Cancel task</button></form>
                <?php endif; ?>
                <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="task_action" value="<?= empty($task['archived_flag']) ? 'archive' : 'unarchive' ?>"><input type="hidden" name="task_id" value="<?= admin_task_safe((string)$task['id']) ?>"><button class="btn btn-secondary btn-small" type="submit"><?= empty($task['archived_flag']) ? 'Archive' : 'Restore' ?></button></form>
              </div>
            </div>

            <?php if ($task['activity_log'] !== []): ?><details style="margin-top:12px"><summary>Activity history</summary><ul><?php foreach (array_reverse($task['activity_log']) as $activity): ?><li><strong><?= admin_task_safe((string)($activity['action'] ?? 'Updated')) ?></strong> · <?= admin_task_safe((string)($activity['actor_name'] ?? 'System')) ?> · <?= admin_task_safe((string)($activity['created_at'] ?? '')) ?><?php if ((string)($activity['details'] ?? '') !== ''): ?> — <?= admin_task_safe((string)$activity['details']) ?><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
          </details>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
  </section>
</main>
</body>
</html>
