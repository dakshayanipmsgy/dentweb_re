<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$db = get_db();
$user = current_user();
$adminCsrfToken = $_SESSION['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-reminders.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $actorId = (int) ($user['id'] ?? 0);
    $redirectQuery = trim((string) ($_POST['redirect_query'] ?? ''));
    $redirectBase = 'admin-reminders.php';
    if ($redirectQuery !== '') {
        $redirectBase .= '?' . ltrim($redirectQuery, '?');
    }

    $reminderIdForRedirect = (int) ($_POST['reminder_id'] ?? 0);
    $targetLocation = $redirectBase;

    try {
        switch ($action) {
            case 'create':
                $dueInput = (string) ($_POST['due_at'] ?? '');
                $reminder = admin_create_reminder($db, [
                    'title' => $_POST['title'] ?? '',
                    'module' => $_POST['module'] ?? '',
                    'linked_id' => $_POST['linked_id'] ?? '',
                    'due_at' => parse_reminder_due_input($dueInput),
                    'notes' => $_POST['notes'] ?? '',
                ], $actorId);
                $reminderIdForRedirect = $reminder['id'] ?? 0;
                set_flash('success', 'Reminder added and activated.');
                break;
            case 'approve':
                if ($reminderIdForRedirect <= 0) {
                    throw new RuntimeException('Reminder reference missing.');
                }
                admin_update_reminder_status($db, $reminderIdForRedirect, 'active', $actorId, null);
                set_flash('success', 'Reminder approved.');
                break;
            case 'reject':
                if ($reminderIdForRedirect <= 0) {
                    throw new RuntimeException('Reminder reference missing.');
                }
                $reason = (string) ($_POST['reason'] ?? '');
                admin_update_reminder_status($db, $reminderIdForRedirect, 'cancelled', $actorId, $reason);
                set_flash('success', 'Reminder rejected.');
                break;
            case 'complete':
                if ($reminderIdForRedirect <= 0) {
                    throw new RuntimeException('Reminder reference missing.');
                }
                admin_update_reminder_status($db, $reminderIdForRedirect, 'completed', $actorId, null);
                set_flash('success', 'Reminder marked completed.');
                break;
            case 'cancel':
                if ($reminderIdForRedirect <= 0) {
                    throw new RuntimeException('Reminder reference missing.');
                }
                $reason = (string) ($_POST['reason'] ?? '');
                admin_update_reminder_status($db, $reminderIdForRedirect, 'cancelled', $actorId, $reason);
                set_flash('success', 'Reminder cancelled.');
                break;
            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    if ($reminderIdForRedirect > 0 && strpos($targetLocation, 'id=') === false) {
        $separator = strpos($targetLocation, '?') === false ? '?' : '&';
        $targetLocation .= $separator . 'id=' . $reminderIdForRedirect;
    }

    header('Location: ' . $targetLocation);
    exit;
}

$moduleOptions = reminder_module_options();

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'active')));
$validStatuses = ['proposed', 'active', 'completed', 'cancelled', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'active';
}

$moduleFilter = strtolower(trim((string) ($_GET['module'] ?? 'all')));
if ($moduleFilter !== 'all' && !array_key_exists($moduleFilter, $moduleOptions)) {
    $moduleFilter = 'all';
}

$fromDate = trim((string) ($_GET['from'] ?? ''));
if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1) {
    $fromDate = '';
}

$toDate = trim((string) ($_GET['to'] ?? ''));
if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) !== 1) {
    $toDate = '';
}

$defaultPerPage = 15;
$pageParam = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($pageParam < 1) {
    $pageParam = 1;
}
$perPageParam = isset($_GET['per_page']) ? (int) $_GET['per_page'] : $defaultPerPage;
if ($perPageParam <= 0) {
    $perPageParam = $defaultPerPage;
}
$perPageParam = min($perPageParam, 50);

$listFilters = [
    'status' => $statusFilter,
    'module' => $moduleFilter,
    'from' => $fromDate,
    'to' => $toDate,
    'page' => $pageParam,
    'per_page' => $perPageParam,
];

$reminderResult = admin_list_reminders($db, $listFilters);
$reminders = $reminderResult['items'] ?? [];
$pagination = $reminderResult['pagination'] ?? [
    'page' => $pageParam,
    'perPage' => $perPageParam,
    'pages' => 1,
    'total' => count($reminders),
];
$pageParam = (int) ($pagination['page'] ?? $pageParam);
$perPageParam = (int) ($pagination['perPage'] ?? $perPageParam);
$pendingReminderRequests = admin_list_reminder_requests($db);

$queryParams = [];
if ($statusFilter !== 'active') {
    $queryParams['status'] = $statusFilter;
}
if ($moduleFilter !== 'all') {
    $queryParams['module'] = $moduleFilter;
}
if ($fromDate !== '') {
    $queryParams['from'] = $fromDate;
}
if ($toDate !== '') {
    $queryParams['to'] = $toDate;
}
if ($pageParam > 1) {
    $queryParams['page'] = (string) $pageParam;
}
if ($perPageParam !== $defaultPerPage) {
    $queryParams['per_page'] = (string) $perPageParam;
}

$currentQueryString = http_build_query($queryParams);
$currentQueryPrefixed = $currentQueryString !== '' ? '?' . $currentQueryString : '';

$selectedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$selectedReminder = null;
if ($selectedId > 0) {
    $selectedReminder = admin_find_reminder($db, $selectedId);
    if ($selectedReminder === null) {
        set_flash('error', 'Reminder not found.');
        $fallback = 'admin-reminders.php';
        if ($currentQueryString !== '') {
            $fallback .= '?' . $currentQueryString;
        }
        header('Location: ' . $fallback);
        exit;
    }
}

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

function parse_reminder_due_input(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException('Due date and time is required.');
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz);
    if (!$parsed) {
        throw new RuntimeException('Invalid due date and time.');
    }

    $startOfToday = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    if ($parsed < $startOfToday) {
        throw new RuntimeException('Due date must be today or later.');
    }

    return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function format_reminder_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Throwable $exception) {
        return $value;
    }

    return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
}

function reminder_status_class(string $status): string
{
    $map = [
        'proposed' => 'reminder-status--proposed',
        'active' => 'reminder-status--active',
        'completed' => 'reminder-status--completed',
        'cancelled' => 'reminder-status--cancelled',
    ];

    $key = strtolower(trim($status));

    return $map[$key] ?? 'reminder-status--proposed';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reminders &amp; Follow-Ups | Admin</title>
  <meta name="description" content="Manage Dentweb reminders and follow-up approvals from the admin portal." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
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
</head>
<body class="admin-reminders" data-theme="light">
  <main class="admin-reminders__shell">
    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <header class="admin-reminders__header">
      <div>
        <p class="admin-reminders__subtitle">Admin workspace</p>
        <h1 class="admin-reminders__title">Reminders &amp; Follow-Ups</h1>
        <p class="admin-reminders__meta">Signed in as <strong><?= htmlspecialchars($user['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-reminders__actions">
        <a href="admin-dashboard.php" class="btn btn-secondary">
          <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
          Back to overview
        </a>
        <a href="#reminder-create" class="btn btn-primary">
          <i class="fa-solid fa-plus" aria-hidden="true"></i>
          Add Reminder
        </a>
      </div>
    </header>

    <section id="requests-center" class="admin-reminders__requests" aria-labelledby="requests-center-title">
      <div class="admin-reminders__requests-header">
        <div>
          <h2 id="requests-center-title">Requests Center</h2>
          <p>Reminder proposals awaiting admin approval.</p>
        </div>
      </div>
      <?php if (empty($pendingReminderRequests)): ?>
      <p class="admin-reminders__requests-empty">No reminder proposals pending review.</p>
      <?php else: ?>
      <div class="admin-reminders__requests-table-wrapper">
        <table class="admin-reminders__requests-table">
          <thead>
            <tr>
              <th scope="col">Request</th>
              <th scope="col">Due</th>
              <th scope="col">Linked item</th>
              <th scope="col">Proposer</th>
              <th scope="col">Notes</th>
              <th scope="col" class="admin-reminders__requests-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingReminderRequests as $request): ?>
            <tr>
              <td>
                <p class="admin-reminders__requests-title"><?= htmlspecialchars($request['title'], ENT_QUOTES) ?></p>
                <span class="admin-reminders__requests-type">Reminder Proposal</span>
              </td>
              <td>
                <?php if ($request['dueIso'] !== ''): ?>
                <time datetime="<?= htmlspecialchars($request['dueIso'], ENT_QUOTES) ?>"><?= htmlspecialchars($request['dueDisplay'], ENT_QUOTES) ?></time>
                <?php else: ?>
                —
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($request['moduleLabel'], ENT_QUOTES) ?> · #<?= htmlspecialchars((string) $request['linkedId'], ENT_QUOTES) ?></td>
              <td><?= $request['proposerName'] !== '' ? htmlspecialchars($request['proposerName'], ENT_QUOTES) : '—' ?></td>
              <td><?= $request['notes'] !== '' ? nl2br(htmlspecialchars($request['notes'], ENT_QUOTES)) : '<span class="admin-muted">No notes provided</span>' ?></td>
              <td>
                <div class="admin-reminders__requests-actions-grid">
                  <form method="post">
                    <input type="hidden" name="action" value="approve" />
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
                    <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $request['id'], ENT_QUOTES) ?>" />
                    <input type="hidden" name="redirect_query" value="status=proposed&amp;focus=requests" />
                    <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                  </form>
                  <form method="post" class="admin-reminders__requests-reject">
                    <input type="hidden" name="action" value="reject" />
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
                    <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $request['id'], ENT_QUOTES) ?>" />
                    <input type="hidden" name="redirect_query" value="status=proposed&amp;focus=requests" />
                    <label class="sr-only" for="reject-note-<?= (int) $request['id'] ?>">Rejection reason</label>
                    <input
                      id="reject-note-<?= (int) $request['id'] ?>"
                      type="text"
                      name="reason"
                      required
                      maxlength="280"
                      placeholder="Reason"
                    />
                    <button type="submit" class="btn btn-secondary btn-sm">Reject</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <section id="reminder-create" class="admin-reminders__create">
      <div class="admin-reminders__create-header">
        <h2>Quick add</h2>
        <p>Create active reminders directly from the admin portal.</p>
      </div>
      <form method="post" class="admin-reminders__form">
        <input type="hidden" name="action" value="create" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
        <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQueryString, ENT_QUOTES) ?>" />
        <div class="reminder-form-grid">
          <label>
            Title
            <input type="text" name="title" required maxlength="160" />
          </label>
          <label>
            Linked item type
            <select name="module" required>
              <?php foreach ($moduleOptions as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $key === $moduleFilter ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Linked item ID
            <input type="number" name="linked_id" min="1" step="1" required />
          </label>
          <label>
            Due date &amp; time
            <input type="datetime-local" name="due_at" required />
          </label>
          <label class="reminder-form-grid__notes">
            Notes (optional)
            <textarea name="notes" rows="3" placeholder="Context or next steps"></textarea>
          </label>
        </div>
        <button type="submit" class="btn btn-primary">Create reminder</button>
      </form>
    </section>

    <section class="admin-reminders__filters">
      <form method="get" class="reminder-filter-form">
        <label>
          Status
          <select name="status">
            <?php foreach ($validStatuses as $statusOption): ?>
            <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>" <?= $statusOption === $statusFilter ? 'selected' : '' ?>><?= htmlspecialchars($statusOption === 'all' ? 'All' : reminder_status_label($statusOption), ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Linked type
          <select name="module">
            <option value="all" <?= $moduleFilter === 'all' ? 'selected' : '' ?>>All</option>
            <?php foreach ($moduleOptions as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $moduleFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Due from
          <input type="date" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES) ?>" />
        </label>
        <label>
          Due to
          <input type="date" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES) ?>" />
        </label>
        <button type="submit" class="btn btn-secondary">Apply filters</button>
      </form>
    </section>

    <section class="admin-reminders__layout">
      <div class="admin-reminders__list" id="reminder-list" aria-label="Reminder list">
        <?php if (empty($reminders)): ?>
        <p class="admin-reminders__empty">No reminders match the selected filters.</p>
        <?php else: ?>
        <table class="reminder-table">
          <thead>
            <tr>
              <th scope="col">Reminder</th>
              <th scope="col">Linked item</th>
              <th scope="col">Due</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reminders as $item): ?>
            <?php
            $rowParams = $queryParams;
            $rowParams['id'] = (string) $item['id'];
            $rowUrl = 'admin-reminders.php' . ($rowParams ? '?' . http_build_query($rowParams) : '');
            $isSelected = $selectedId === $item['id'];
            ?>
            <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
              <td>
                <a href="<?= htmlspecialchars($rowUrl, ENT_QUOTES) ?>" class="reminder-link">
                  <span class="reminder-list__title"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></span>
                  <span class="reminder-list__meta">
                    <span><i class="fa-solid fa-clock" aria-hidden="true"></i> <?= htmlspecialchars($item['dueDisplay'], ENT_QUOTES) ?></span>
                    <?php if ($item['notes'] !== ''): ?>
                    <span class="reminder-list__note"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i> <?= htmlspecialchars($item['notes'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                  </span>
                </a>
              </td>
              <td>
                <span class="reminder-list__module">
                  <?= htmlspecialchars($item['moduleLabel'], ENT_QUOTES) ?> · #<?= htmlspecialchars((string) $item['linkedId'], ENT_QUOTES) ?>
                </span>
              </td>
              <td>
                <?php if ($item['dueIso'] !== ''): ?>
                <time datetime="<?= htmlspecialchars($item['dueIso'], ENT_QUOTES) ?>"><?= htmlspecialchars($item['dueDisplay'], ENT_QUOTES) ?></time>
                <?php else: ?>
                —
                <?php endif; ?>
              </td>
              <td>
                <span class="reminder-status-chip <?= reminder_status_class($item['status']) ?>"><?= htmlspecialchars($item['statusLabel'], ENT_QUOTES) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php
        $totalItems = (int) ($pagination['total'] ?? count($reminders));
        $currentPage = (int) ($pagination['page'] ?? 1);
        $totalPages = (int) ($pagination['pages'] ?? 1);
        $perPageValue = (int) ($pagination['perPage'] ?? max(1, count($reminders)));
        $pageStart = $totalItems > 0 ? (($currentPage - 1) * $perPageValue) + 1 : 0;
        $pageEnd = $pageStart > 0 ? min($totalItems, $pageStart + count($reminders) - 1) : 0;
        ?>
        <?php if ($totalPages > 1): ?>
        <nav class="admin-reminders__pagination" aria-label="Reminder pagination">
          <span class="admin-reminders__pagination-summary">
            Showing <?= number_format($pageStart) ?>–<?= number_format($pageEnd) ?> of <?= number_format($totalItems) ?>
          </span>
          <div class="admin-reminders__pagination-controls">
            <?php if ($currentPage > 1): ?>
            <?php
            $prevParams = $queryParams;
            if ($currentPage - 1 <= 1) {
                unset($prevParams['page']);
            } else {
                $prevParams['page'] = (string) ($currentPage - 1);
            }
            $prevUrl = 'admin-reminders.php' . ($prevParams ? '?' . http_build_query($prevParams) : '');
            ?>
            <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($prevUrl, ENT_QUOTES) ?>">Previous</a>
            <?php else: ?>
            <span class="btn btn-secondary btn-sm" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <span class="admin-reminders__pagination-page">Page <?= $currentPage ?> of <?= $totalPages ?></span>

            <?php if ($currentPage < $totalPages): ?>
            <?php
            $nextParams = $queryParams;
            $nextParams['page'] = (string) ($currentPage + 1);
            $nextUrl = 'admin-reminders.php' . ($nextParams ? '?' . http_build_query($nextParams) : '');
            ?>
            <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES) ?>">Next</a>
            <?php else: ?>
            <span class="btn btn-secondary btn-sm" aria-disabled="true">Next</span>
            <?php endif; ?>
          </div>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <aside class="admin-reminders__detail" aria-label="Reminder detail">
        <?php if ($selectedReminder === null): ?>
        <p class="admin-reminders__placeholder">Select a reminder to review details and approvals.</p>
        <?php else: ?>
        <div class="admin-reminders__detail-header">
          <div>
            <h2 class="admin-reminders__detail-title"><?= htmlspecialchars($selectedReminder['title'], ENT_QUOTES) ?></h2>
            <p class="admin-reminders__detail-sub">Linked to <?= htmlspecialchars($selectedReminder['moduleLabel'], ENT_QUOTES) ?> · #<?= htmlspecialchars((string) $selectedReminder['linkedId'], ENT_QUOTES) ?></p>
          </div>
          <span class="reminder-status-chip <?= reminder_status_class($selectedReminder['status']) ?>"><?= htmlspecialchars($selectedReminder['statusLabel'], ENT_QUOTES) ?></span>
        </div>

        <dl class="admin-reminders__detail-grid">
          <div>
            <dt>Due</dt>
            <dd>
              <?php if ($selectedReminder['dueIso'] !== ''): ?>
              <time datetime="<?= htmlspecialchars($selectedReminder['dueIso'], ENT_QUOTES) ?>"><?= htmlspecialchars($selectedReminder['dueDisplay'], ENT_QUOTES) ?></time>
              <?php else: ?>
              —
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Proposed by</dt>
            <dd><?= htmlspecialchars($selectedReminder['proposerName'] !== '' ? $selectedReminder['proposerName'] : '—', ENT_QUOTES) ?></dd>
          </div>
          <div>
            <dt>Approved by</dt>
            <dd><?= htmlspecialchars($selectedReminder['approverName'] !== '' ? $selectedReminder['approverName'] : '—', ENT_QUOTES) ?></dd>
          </div>
          <div>
            <dt>Created</dt>
            <dd><?= htmlspecialchars(format_reminder_datetime($selectedReminder['createdAt']), ENT_QUOTES) ?></dd>
          </div>
          <div>
            <dt>Last updated</dt>
            <dd><?= htmlspecialchars(format_reminder_datetime($selectedReminder['updatedAt']), ENT_QUOTES) ?></dd>
          </div>
          <?php if ($selectedReminder['completedAt'] !== ''): ?>
          <div>
            <dt>Completed</dt>
            <dd><?= htmlspecialchars(format_reminder_datetime($selectedReminder['completedAt']), ENT_QUOTES) ?></dd>
          </div>
          <?php endif; ?>
        </dl>

        <?php if ($selectedReminder['notes'] !== ''): ?>
        <section class="admin-reminders__notes">
          <h3>Notes</h3>
          <p><?= nl2br(htmlspecialchars($selectedReminder['notes'], ENT_QUOTES)) ?></p>
        </section>
        <?php endif; ?>

        <?php if ($selectedReminder['decisionNote'] !== '' && $selectedReminder['status'] === 'cancelled'): ?>
        <section class="admin-reminders__notes admin-reminders__notes--muted">
          <h3>Rejection reason</h3>
          <p><?= nl2br(htmlspecialchars($selectedReminder['decisionNote'], ENT_QUOTES)) ?></p>
        </section>
        <?php endif; ?>

        <?php if ($selectedReminder['status'] === 'proposed'): ?>
        <div class="admin-reminders__actions">
          <form method="post">
            <input type="hidden" name="action" value="approve" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
            <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $selectedReminder['id'], ENT_QUOTES) ?>" />
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQueryString, ENT_QUOTES) ?>" />
            <button type="submit" class="btn btn-primary">Approve reminder</button>
          </form>
          <form method="post" class="admin-reminders__reject-form">
            <input type="hidden" name="action" value="reject" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
            <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $selectedReminder['id'], ENT_QUOTES) ?>" />
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQueryString, ENT_QUOTES) ?>" />
            <label>
              Rejection reason
              <textarea name="reason" rows="3" required placeholder="Share context for declining this reminder"></textarea>
            </label>
            <button type="submit" class="btn btn-secondary">Reject reminder</button>
          </form>
        </div>
        <?php elseif ($selectedReminder['status'] === 'active'): ?>
        <div class="admin-reminders__actions admin-reminders__actions--stacked">
          <form method="post">
            <input type="hidden" name="action" value="complete" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
            <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $selectedReminder['id'], ENT_QUOTES) ?>" />
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQueryString, ENT_QUOTES) ?>" />
            <button type="submit" class="btn btn-primary">Mark as completed</button>
          </form>
          <form method="post" class="admin-reminders__cancel-form">
            <input type="hidden" name="action" value="cancel" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES) ?>" />
            <input type="hidden" name="reminder_id" value="<?= htmlspecialchars((string) $selectedReminder['id'], ENT_QUOTES) ?>" />
            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($currentQueryString, ENT_QUOTES) ?>" />
            <label>
              Cancellation note (optional)
              <textarea name="reason" rows="3" placeholder="Share why this reminder is being cancelled"></textarea>
            </label>
            <button type="submit" class="btn btn-secondary">Cancel reminder</button>
          </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </aside>
    </section>
  </main>
</body>
</html>
