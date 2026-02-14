<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/tasks_helpers.php';
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
$employeeIndex = [];
foreach ($employees as $employeeRow) {
    $employeeIndex[(string) ($employeeRow['id'] ?? '')] = $employeeRow;
}

$today = tasks_today_date();
$tz = new DateTimeZone(TASKS_TIMEZONE);
$upcomingAdminLimit = (new DateTimeImmutable('today', $tz))->modify('+3 days')->format('Y-m-d');

$allTasks = load_tasks();
$pendingTasks = array_values(array_filter($allTasks, static function (array $task): bool {
    if (!empty($task['archived_flag'])) {
        return false;
    }
    return strcasecmp((string) ($task['status'] ?? ''), 'open') === 0;
}));

$pendingOverdue = 0;
$pendingToday = 0;
foreach ($pendingTasks as $task) {
    $due = get_effective_due_date($task);
    if ($due === '') {
        continue;
    }
    if (is_overdue($task, $today)) {
        $pendingOverdue++;
        continue;
    }
    if (is_due_today($task, $today)) {
        $pendingToday++;
    }
}
$pendingTotal = count($pendingTasks);

$atRiskTasks = array_values(array_filter($pendingTasks, static function (array $task) use ($today, $upcomingAdminLimit): bool {
    $due = get_effective_due_date($task);
    if ($due === '') {
        return false;
    }
    if (is_overdue($task, $today) || is_due_today($task, $today)) {
        return true;
    }

    return strcmp($due, $today) > 0 && strcmp($due, $upcomingAdminLimit) <= 0;
}));

usort($atRiskTasks, static function (array $left, array $right) use ($today): int {
    $rank = static function (array $task) use ($today): int {
        if (is_overdue($task, $today)) {
            return 0;
        }
        if (is_due_today($task, $today)) {
            return 1;
        }
        return 2;
    };

    $rankLeft = $rank($left);
    $rankRight = $rank($right);
    if ($rankLeft !== $rankRight) {
        return $rankLeft <=> $rankRight;
    }

    return strcmp(get_effective_due_date($left), get_effective_due_date($right));
});
$atRiskTasks = array_slice($atRiskTasks, 0, 8);

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

$buildReminderMessage = static function (array $task, string $assigneeName) use ($today, $tz, $formatTaskDate): string {
    $title = trim((string) ($task['title'] ?? 'Task'));
    $priority = trim((string) ($task['priority'] ?? 'Medium'));
    $dueRaw = get_effective_due_date($task);
    $dueDisplay = $formatTaskDate($dueRaw);

    $lines = [
        'Reminder: Task pending',
        'Task: ' . $title,
        'Due: ' . $dueDisplay,
    ];

    $todayDate = DateTimeImmutable::createFromFormat('Y-m-d', $today, $tz) ?: new DateTimeImmutable('today', $tz);
    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueRaw, $tz);
    if (is_overdue($task, $today) && $dueDate !== false) {
        $diff = $todayDate->diff($dueDate);
        $daysOverdue = max(1, (int) $diff->days);
        $lines[] = 'Overdue by ' . $daysOverdue . ' day(s).';
    }

    $lines[] = 'Priority: ' . $priority;
    $lines[] = 'Assigned to: ' . ($assigneeName !== '' ? $assigneeName : 'Employee');
    $lines[] = '';
    $lines[] = 'Please update status once completed.';
    $lines[] = '- Dakshayani Admin';

    return implode("\n", $lines);
};

$buildWhatsappDraft = static function (array $task) use ($employeeIndex, $buildReminderMessage): array {
    $assigneeId = (string) ($task['assigned_to_id'] ?? '');
    $employeeRow = $employeeIndex[$assigneeId] ?? [];
    $assigneeName = (string) ($employeeRow['name'] ?? '');

    $phoneDigits = preg_replace('/\D+/', '', (string) ($employeeRow['phone'] ?? ''));
    if (strlen($phoneDigits) > 10 && substr($phoneDigits, 0, 2) === '91') {
        $phoneDigits = substr($phoneDigits, 2);
    }
    $waNumber = $phoneDigits !== '' ? '91' . $phoneDigits : '';

    $message = $buildReminderMessage($task, $assigneeName);
    $baseUrl = $waNumber !== '' ? 'https://wa.me/' . rawurlencode($waNumber) : 'https://wa.me/';
    $note = $waNumber === '' ? 'Employee number not found; choose contact manually.' : '';

    return [
        'url' => $baseUrl . '?text=' . rawurlencode($message),
        'note' => $note,
        'message' => $message,
        'assignee' => $assigneeName,
        'phone' => $waNumber,
    ];
};

$buildMailDraft = static function (array $task) use ($employeeIndex, $buildReminderMessage): ?string {
    $assigneeId = (string) ($task['assigned_to_id'] ?? '');
    $employeeRow = $employeeIndex[$assigneeId] ?? [];
    $email = trim((string) ($employeeRow['email'] ?? $employeeRow['mail'] ?? $employeeRow['email_id'] ?? ''));
    if ($email === '') {
        return null;
    }

    $message = $buildReminderMessage($task, (string) ($employeeRow['name'] ?? ''));
    $subject = 'Reminder: Pending task';

    return 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message);
};

$overdueOnly = array_values(array_filter($pendingTasks, static function (array $task) use ($today): bool {
    return is_overdue($task, $today);
}));
$dueTodayOnly = array_values(array_filter($pendingTasks, static function (array $task) use ($today): bool {
    return is_due_today($task, $today);
}));

$buildBulkText = static function (array $tasksList, string $heading) use ($employeeIndex, $formatTaskDate): ?string {
    if ($tasksList === []) {
        return null;
    }

    $lines = [$heading];
    foreach ($tasksList as $task) {
        $assignee = (string) ($employeeIndex[(string) ($task['assigned_to_id'] ?? '')]['name'] ?? 'Employee');
        $due = $formatTaskDate(get_effective_due_date($task));
        $priority = (string) ($task['priority'] ?? 'Medium');
        $title = trim((string) ($task['title'] ?? 'Task'));
        $lines[] = sprintf('- %s: %s (Due %s, %s)', $assignee, $title, $due, $priority);
    }

    $lines[] = '';
    $lines[] = 'Please update status once completed.';

    return implode("\n", $lines);
};

$bulkMessages = [
    'overdue' => $buildBulkText($overdueOnly, 'Overdue tasks to follow up'),
    'today' => $buildBulkText($dueTodayOnly, 'Tasks due today'),
    'all' => $buildBulkText($pendingTasks, 'Pending tasks summary'),
];

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
    .admin-task-widget {
      margin: 1rem 0 1.5rem;
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 1rem 1.25rem;
      box-shadow: 0 14px 30px rgba(0,0,0,0.06);
    }
    .admin-task-widget__header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-bottom: 0.75rem;
    }
    .admin-task-widget__counts {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.5rem;
      width: 100%;
      margin-bottom: 0.5rem;
    }
    .admin-task-widget__count {
      padding: 0.65rem 0.75rem;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      background: #f8fafc;
    }
    .admin-task-widget__count-title {
      margin: 0;
      font-size: 0.9rem;
      color: #475569;
      font-weight: 700;
    }
    .admin-task-widget__count-value {
      margin: 0.25rem 0 0;
      font-size: 1.5rem;
      font-weight: 700;
      color: #0f172a;
    }
    .admin-task-list {
      list-style: none;
      padding: 0;
      margin: 0.25rem 0 0;
      display: grid;
      gap: 0.6rem;
    }
    .admin-task-list__item {
      padding: 0.65rem 0.75rem;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      background: #f9fafb;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 0.35rem 0.75rem;
      align-items: center;
    }
    .admin-task-list__title {
      margin: 0;
      font-weight: 700;
      color: #0f172a;
    }
    .admin-task-list__meta {
      margin: 0.15rem 0 0;
      color: #475569;
      font-size: 0.95rem;
    }
    .admin-task-list__badges {
      display: inline-flex;
      gap: 0.35rem;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 0.35rem;
    }
    .admin-task-list__badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.25rem 0.55rem;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #fff;
      font-weight: 700;
      font-size: 0.82rem;
      color: #0f172a;
    }
    .admin-task-list__actions {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 0.35rem;
      min-width: 200px;
    }
    .admin-task-list__action-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.65rem;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      text-decoration: none;
      color: #0f172a;
      font-weight: 700;
      background: #eef2ff;
    }
    .admin-task-list__note {
      margin: 0;
      font-size: 0.85rem;
      color: #475569;
      text-align: right;
    }
    .admin-task-quick {
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px dashed #e2e8f0;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0.5rem;
    }
    .admin-task-quick__link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.5rem 0.65rem;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      background: #f8fafc;
      color: #0f172a;
      font-weight: 700;
      text-decoration: none;
      justify-content: center;
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
        <a href="<?= htmlspecialchars($pathFor('admin-tasks.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-list-check" aria-hidden="true"></i>
          Tasks
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
        <a href="<?= htmlspecialchars($pathFor('admin-docs.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
          Documents
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

    <section class="admin-task-widget" aria-label="Pending tasks across employees">
      <div class="admin-task-widget__header">
        <div>
          <h2 style="margin:0;">Pending Tasks (All Employees)</h2>
          <p style="margin:0;color:#475569;">Lightweight view of overdue, today, and near-term tasks.</p>
        </div>
        <a href="<?= htmlspecialchars($pathFor('admin-tasks.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-list-check" aria-hidden="true"></i>
          Manage tasks
        </a>
      </div>

      <div class="admin-task-widget__counts">
        <div class="admin-task-widget__count">
          <p class="admin-task-widget__count-title">Total pending</p>
          <p class="admin-task-widget__count-value"><?= number_format((int) $pendingTotal) ?></p>
        </div>
        <div class="admin-task-widget__count">
          <p class="admin-task-widget__count-title">Overdue</p>
          <p class="admin-task-widget__count-value" style="color:#b91c1c;"><?= number_format((int) $pendingOverdue) ?></p>
        </div>
        <div class="admin-task-widget__count">
          <p class="admin-task-widget__count-title">Due today</p>
          <p class="admin-task-widget__count-value" style="color:#4338ca;"><?= number_format((int) $pendingToday) ?></p>
        </div>
      </div>

      <?php if ($atRiskTasks === []): ?>
        <p class="admin-overview__empty" style="margin:0.25rem 0 0;">No pending tasks 🎉</p>
      <?php else: ?>
        <ul class="admin-task-list">
          <?php foreach ($atRiskTasks as $task): ?>
            <?php
              $assigneeId = (string) ($task['assigned_to_id'] ?? '');
              $assigneeName = (string) ($employeeIndex[$assigneeId]['name'] ?? 'Employee');
              $dueDateRaw = get_effective_due_date($task);
              $dueDate = $formatTaskDate($dueDateRaw);
              $priority = (string) ($task['priority'] ?? 'Medium');
              $whatsAppDraft = $buildWhatsappDraft($task);
              $mailDraft = $buildMailDraft($task);

              if (is_overdue($task, $today)) {
                  $statusLabel = 'Overdue';
                  $statusColor = '#b91c1c';
                  $statusBg = '#fef2f2';
                  $statusBorder = '#fecdd3';
              } elseif (is_due_today($task, $today)) {
                  $statusLabel = 'Due today';
                  $statusColor = '#4338ca';
                  $statusBg = '#eef2ff';
                  $statusBorder = '#c7d2fe';
              } else {
                  $statusLabel = 'Upcoming';
                  $statusColor = '#0f172a';
                  $statusBg = '#ecfeff';
                  $statusBorder = '#bae6fd';
              }
            ?>
            <li class="admin-task-list__item">
              <div>
                <p class="admin-task-list__title"><?= htmlspecialchars((string) ($task['title'] ?? 'Task'), ENT_QUOTES) ?></p>
                <p class="admin-task-list__meta">
                  <?= htmlspecialchars($assigneeName, ENT_QUOTES) ?> · Due <?= htmlspecialchars($dueDate, ENT_QUOTES) ?>
                </p>
                <div class="admin-task-list__badges">
                  <span class="admin-task-list__badge"><?= htmlspecialchars($priority, ENT_QUOTES) ?></span>
                  <span class="admin-task-list__badge" style="background:<?= htmlspecialchars($statusBg, ENT_QUOTES) ?>;color:<?= htmlspecialchars($statusColor, ENT_QUOTES) ?>;border-color:<?= htmlspecialchars($statusBorder, ENT_QUOTES) ?>;">
                    <?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>
                  </span>
                  <span class="admin-task-list__badge">Open</span>
                </div>
              </div>
              <div class="admin-task-list__actions">
                <a class="admin-task-list__action-link" target="_blank" rel="noreferrer noopener" href="<?= htmlspecialchars($whatsAppDraft['url'], ENT_QUOTES) ?>">
                  <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                  Draft WhatsApp Reminder
                </a>
                <?php if ($mailDraft !== null): ?>
                  <a class="admin-task-list__action-link" target="_blank" rel="noreferrer noopener" href="<?= htmlspecialchars($mailDraft, ENT_QUOTES) ?>">
                    <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                    Draft Email
                  </a>
                <?php endif; ?>
                <?php if ($whatsAppDraft['note'] !== ''): ?>
                  <p class="admin-task-list__note"><?= htmlspecialchars((string) $whatsAppDraft['note'], ENT_QUOTES) ?></p>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div class="admin-task-quick">
        <?php
          $quickLinks = [
              'overdue' => 'Overdue only',
              'today' => 'Due today',
              'all' => 'All pending',
          ];
        ?>
        <?php foreach ($quickLinks as $key => $label): ?>
          <?php $bulkText = $bulkMessages[$key] ?? null; ?>
          <?php if ($bulkText !== null): ?>
            <a class="admin-task-quick__link" target="_blank" rel="noreferrer noopener" href="https://wa.me/?text=<?= rawurlencode($bulkText) ?>">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
              <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </a>
          <?php else: ?>
            <span class="admin-task-quick__link" style="opacity:0.6;pointer-events:none;">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
              <?= htmlspecialchars($label, ENT_QUOTES) ?>
            </span>
          <?php endif; ?>
        <?php endforeach; ?>
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
  <script src="<?= htmlspecialchars($pathFor('admin-dashboard.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
