<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$db = get_db();
$admin = current_user();

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
if (is_array($flashData)) {
    if (isset($flashData['message']) && is_string($flashData['message'])) {
        $flashMessage = trim($flashData['message']);
    }
    if (isset($flashData['type']) && is_string($flashData['type'])) {
        $flashTone = strtolower($flashData['type']);
    }
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'open')));
$allowedFilters = ['open', 'resolved', 'closed', 'all'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'open';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = $_POST['csrf_token'] ?? '';
    $returnFilter = strtolower(trim((string) ($_POST['return_filter'] ?? $filter)));
    if (!in_array($returnFilter, $allowedFilters, true)) {
        $returnFilter = $filter;
    }

    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-complaints.php?filter=' . urlencode($returnFilter));
        exit;
    }

    $adminId = (int) ($admin['id'] ?? 0);

    try {
        switch ($action) {
            case 'create':
                $payload = [
                    'reference' => trim((string) ($_POST['reference'] ?? '')),
                    'title' => (string) ($_POST['title'] ?? ''),
                    'description' => (string) ($_POST['description'] ?? ''),
                    'priority' => (string) ($_POST['priority'] ?? 'medium'),
                    'origin' => (string) ($_POST['origin'] ?? 'admin'),
                    'customerName' => (string) ($_POST['customer_name'] ?? ''),
                    'customerContact' => (string) ($_POST['customer_contact'] ?? ''),
                    'assignedTo' => $_POST['assigned_to'] ?? null,
                    'slaDue' => (string) ($_POST['sla_due'] ?? ''),
                ];
                portal_save_complaint($db, $payload, $adminId);
                set_flash('success', 'Complaint saved successfully.');
                break;
            case 'assign':
                $reference = (string) ($_POST['reference'] ?? '');
                $assignee = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int) $_POST['assigned_to'] : null;
                $slaDue = (string) ($_POST['sla_due'] ?? '');
                portal_assign_complaint($db, $reference, $assignee, $slaDue !== '' ? $slaDue : null, $adminId);
                set_flash('success', 'Assignment updated.');
                break;
            case 'status':
                $reference = (string) ($_POST['reference'] ?? '');
                $status = (string) ($_POST['status'] ?? '');
                portal_admin_update_complaint_status($db, $reference, $status, $adminId);
                set_flash('success', 'Status updated.');
                break;
            case 'note':
                $reference = (string) ($_POST['reference'] ?? '');
                $note = (string) ($_POST['note_body'] ?? '');
                portal_add_complaint_note($db, $reference, $note, $adminId);
                set_flash('success', 'Note added.');
                break;
            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    header('Location: admin-complaints.php?filter=' . urlencode($returnFilter));
    exit;
}

function admin_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function admin_format_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function complaint_origin_label(string $origin): string
{
    return strtolower($origin) === 'customer' ? 'Customer submission' : 'Admin logged';
}

$complaints = portal_all_complaints($db);
$employees = admin_active_employees($db);

$openCount = count(array_filter($complaints, static fn (array $item): bool => ($item['status'] ?? '') !== 'closed'));
$closedCount = count($complaints) - $openCount;
$resolvedCount = count(array_filter($complaints, static fn (array $item): bool => ($item['status'] ?? '') === 'resolved'));

$filteredComplaints = array_filter($complaints, static function (array $complaint) use ($filter): bool {
    $status = strtolower((string) ($complaint['status'] ?? 'intake'));
    return match ($filter) {
        'closed' => $status === 'closed',
        'resolved' => $status === 'resolved',
        'all' => true,
        default => $status !== 'closed',
    };
});

usort($filteredComplaints, static function (array $a, array $b): int {
    $aTime = $a['updatedAt'] ?: ($a['createdAt'] ?? '');
    $bTime = $b['updatedAt'] ?: ($b['createdAt'] ?? '');
    return strcmp($bTime, $aTime);
});

$statusChoices = [
    'intake' => 'Intake',
    'triage' => 'Admin triage',
    'work' => 'In progress',
    'resolved' => 'Resolved (Pending Admin)',
    'closed' => 'Closed',
];
$priorityChoices = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'urgent' => 'Urgent',
];
$originChoices = [
    'admin' => 'Admin logged',
    'customer' => 'Customer submission',
];
$badgeToneMap = [
    'intake' => 'info',
    'triage' => 'warning',
    'work' => 'progress',
    'resolved' => 'pending',
    'closed' => 'success',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Complaints &amp; Service | Admin</title>
  <meta name="description" content="Create, assign, and close customer complaints with full service history." />
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
<body class="admin-complaints" data-theme="light">
  <main class="admin-complaints__shell">
    <header class="admin-complaints__header">
      <div>
        <p class="admin-complaints__subtitle">Admin workspace</p>
        <h1 class="admin-complaints__title">Complaints &amp; Service</h1>
        <p class="admin-complaints__meta">Signed in as <strong><?= htmlspecialchars($admin['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-complaints__actions">
        <a href="admin-dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to overview</a>
        <a href="logout.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
      </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <section class="admin-summary" aria-label="Complaint summary">
      <div class="admin-summary-card">
        <h2>Open complaints</h2>
        <p class="admin-summary-card__value"><?= number_format($openCount) ?></p>
        <p class="admin-summary-card__meta">Includes intake, triage, work, and pending admin review.</p>
      </div>
      <div class="admin-summary-card">
        <h2>Closed complaints</h2>
        <p class="admin-summary-card__value"><?= number_format(max(0, $closedCount)) ?></p>
        <p class="admin-summary-card__meta">Complaints marked closed by the admin desk.</p>
      </div>
      <div class="admin-summary-card">
        <h2>Pending admin review</h2>
        <p class="admin-summary-card__value"><?= number_format($resolvedCount) ?></p>
        <p class="admin-summary-card__meta">Awaiting confirmation after employee resolution.</p>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="complaint-create">
      <div class="admin-panel__header">
        <div>
          <h2 id="complaint-create">Log complaint</h2>
          <p>Capture new service issues from the support desk or customer submissions.</p>
        </div>
      </div>
      <form method="post" class="admin-form">
        <input type="hidden" name="action" value="create" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" />
        <div class="admin-form__grid">
          <label>
            Title
            <input type="text" name="title" required placeholder="Short summary" />
          </label>
          <label>
            Reference (optional)
            <input type="text" name="reference" placeholder="Auto-generated if empty" />
          </label>
          <label>
            Customer name
            <input type="text" name="customer_name" placeholder="Name or organisation" />
          </label>
          <label>
            Contact details
            <input type="text" name="customer_contact" placeholder="Phone, email, or notes" />
          </label>
          <label>
            Priority
            <select name="priority">
              <?php foreach ($priorityChoices as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Origin
            <select name="origin">
              <?php foreach ($originChoices as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Assign to
            <select name="assigned_to">
              <option value="">Unassigned</option>
              <?php foreach ($employees as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars($employee['name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            SLA due (optional)
            <input type="date" name="sla_due" />
          </label>
        </div>
        <label>
          Description
          <textarea name="description" rows="4" placeholder="Provide context, customer request, or troubleshooting notes."></textarea>
        </label>
        <button type="submit" class="btn btn-primary">Create complaint</button>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="complaint-list">
      <div class="admin-panel__header">
        <div>
          <h2 id="complaint-list">Complaint queue</h2>
          <p>Review open work, pending admin approvals, and recently closed complaints.</p>
        </div>
      </div>
      <nav class="complaint-filters" aria-label="Complaint filters">
        <?php
        $filterCounts = [
            'open' => $openCount,
            'resolved' => $resolvedCount,
            'closed' => $closedCount,
            'all' => count($complaints),
        ];
        foreach ($allowedFilters as $option):
            $isActive = $filter === $option;
            $count = $filterCounts[$option];
        ?>
        <a class="complaint-filter<?= $isActive ? ' is-active' : '' ?>" href="?filter=<?= htmlspecialchars($option, ENT_QUOTES) ?>">
          <?= ucfirst($option) ?> <span><?= number_format($count) ?></span>
        </a>
        <?php endforeach; ?>
      </nav>
      <?php if (empty($filteredComplaints)): ?>
      <p class="empty-state">No complaints match this view yet.</p>
      <?php else: ?>
      <div class="complaint-list">
        <?php foreach ($filteredComplaints as $complaint): ?>
        <?php
        $statusKey = strtolower((string) ($complaint['status'] ?? 'intake'));
        $badgeTone = $badgeToneMap[$statusKey] ?? 'info';
        $originLabel = complaint_origin_label((string) ($complaint['origin'] ?? 'admin'));
        $assignee = $complaint['assigneeName'] !== '' ? $complaint['assigneeName'] : 'Unassigned';
        $timelineEntries = $complaint['timeline'] ?? [];
        usort($timelineEntries, static function (array $a, array $b): int {
            return strcmp($b['time'] ?? '', $a['time'] ?? '');
        });
        $timelineEntries = array_slice($timelineEntries, 0, 6);
        ?>
        <article class="complaint-card" data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES) ?>">
          <header class="complaint-card__header">
            <div>
              <p class="complaint-card__reference">Reference · <?= htmlspecialchars($complaint['reference'], ENT_QUOTES) ?></p>
              <h3><?= htmlspecialchars($complaint['title'], ENT_QUOTES) ?></h3>
            </div>
            <span class="complaint-card__status complaint-card__status--<?= htmlspecialchars($badgeTone, ENT_QUOTES) ?>">
              <?= htmlspecialchars($complaint['statusLabel'] ?? complaint_status_label($statusKey), ENT_QUOTES) ?>
            </span>
          </header>
          <dl class="complaint-card__meta">
            <div>
              <dt>Customer</dt>
              <dd><?= htmlspecialchars($complaint['customerName'] !== '' ? $complaint['customerName'] : '—', ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Contact</dt>
              <dd><?= htmlspecialchars($complaint['customerContact'] !== '' ? $complaint['customerContact'] : 'Shared via Admin', ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Priority</dt>
              <dd><?= htmlspecialchars(ucfirst((string) ($complaint['priority'] ?? 'medium')), ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Origin</dt>
              <dd><?= htmlspecialchars($originLabel, ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Assigned to</dt>
              <dd><?= htmlspecialchars($assignee, ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>SLA due</dt>
              <dd><?= htmlspecialchars($complaint['slaDue'] !== '' ? admin_format_date($complaint['slaDue']) : 'Not set', ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Created</dt>
              <dd><?= htmlspecialchars(admin_format_datetime($complaint['createdAt'] ?? ''), ENT_QUOTES) ?></dd>
            </div>
            <div>
              <dt>Updated</dt>
              <dd><?= htmlspecialchars(admin_format_datetime($complaint['updatedAt'] ?? ''), ENT_QUOTES) ?></dd>
            </div>
          </dl>
          <div class="complaint-card__actions">
            <form method="post" class="complaint-card__form" aria-label="Update assignment">
              <input type="hidden" name="action" value="assign" />
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
              <input type="hidden" name="reference" value="<?= htmlspecialchars($complaint['reference'], ENT_QUOTES) ?>" />
              <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" />
              <label>
                Assign to
                <select name="assigned_to">
                  <option value="">Unassigned</option>
                  <?php foreach ($employees as $employee): ?>
                  <option value="<?= (int) $employee['id'] ?>"<?= $complaint['assignedTo'] === (int) $employee['id'] ? ' selected' : '' ?>><?= htmlspecialchars($employee['name'], ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                SLA due
                <input type="date" name="sla_due" value="<?= htmlspecialchars($complaint['slaDue'] ?? '', ENT_QUOTES) ?>" />
              </label>
              <button type="submit" class="btn btn-secondary btn-sm">Save assignment</button>
            </form>
            <form method="post" class="complaint-card__form" aria-label="Update status">
              <input type="hidden" name="action" value="status" />
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
              <input type="hidden" name="reference" value="<?= htmlspecialchars($complaint['reference'], ENT_QUOTES) ?>" />
              <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" />
              <label>
                Status
                <select name="status">
                  <?php foreach ($statusChoices as $value => $label): ?>
                  <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $statusKey === $value ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit" class="btn btn-primary btn-sm">Save status</button>
            </form>
            <form method="post" class="complaint-card__form complaint-card__form--note" aria-label="Add note">
              <input type="hidden" name="action" value="note" />
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
              <input type="hidden" name="reference" value="<?= htmlspecialchars($complaint['reference'], ENT_QUOTES) ?>" />
              <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" />
              <label>
                Internal note
                <textarea name="note_body" rows="2" placeholder="Add update for this complaint" required></textarea>
              </label>
              <button type="submit" class="btn btn-ghost btn-sm">Add note</button>
            </form>
          </div>
          <?php if (!empty($timelineEntries)): ?>
          <div class="complaint-card__timeline">
            <h4>Recent updates</h4>
            <ol class="complaint-card__timeline-list">
              <?php foreach ($timelineEntries as $entry): ?>
              <li class="complaint-card__timeline-item">
                <time datetime="<?= htmlspecialchars($entry['time'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars(admin_format_datetime($entry['time'] ?? ''), ENT_QUOTES) ?></time>
                <p class="complaint-card__timeline-summary">
                  <strong><?= htmlspecialchars($entry['summary'] ?? '', ENT_QUOTES) ?></strong>
                  <?php if (!empty($entry['details'])): ?>
                  <span><?= nl2br(htmlspecialchars((string) $entry['details'], ENT_QUOTES)) ?></span>
                  <?php endif; ?>
                </p>
                <p class="complaint-card__timeline-meta"><?= htmlspecialchars($entry['actor'] ?? 'System', ENT_QUOTES) ?></p>
              </li>
              <?php endforeach; ?>
            </ol>
          </div>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
  </main>
  <script src="admin-dashboard.js" defer></script>
</body>
</html>
