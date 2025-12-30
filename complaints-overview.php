<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/customer_admin.php';

$employeeStore = new EmployeeFsStore();
$customerStore = new CustomerFsStore();

$viewerType = 'employee';
$viewerName = 'Employee';

$admin = current_user();
if (($admin['role_name'] ?? '') === 'admin') {
    require_admin();
    $viewerType = 'admin';
    $viewerName = trim((string) ($admin['full_name'] ?? 'Administrator'));
} else {
    employee_portal_require_login();
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee === null) {
        header('Location: login.php?login_type=employee');
        exit;
    }
    $viewerName = trim((string) ($employee['name'] ?? ($employee['login_id'] ?? 'Employee')));
}

$statusFilterRaw = $_GET['status'] ?? null;
$statusFilter = strtolower(trim((string) ($statusFilterRaw ?? 'all')));
$assigneeFilter = trim((string) ($_GET['assignee'] ?? 'all'));
$categoryFilter = trim((string) ($_GET['category'] ?? 'all'));

$complaints = load_all_complaints();
$customers = $customerStore->listCustomers();

$customerByMobile = [];
foreach ($customers as $cust) {
    $mobile = $cust['mobile'] ?? '';
    if ($mobile !== '') {
        $customerByMobile[$mobile] = $cust;
    }
}

usort($complaints, static function (array $left, array $right): int {
    $leftTime = (string) ($left['created_at'] ?? '');
    $rightTime = (string) ($right['created_at'] ?? '');
    return strcmp($rightTime, $leftTime);
});

$assigneeOptions = array_unique(array_merge(['Unassigned'], array_map(static fn ($item) => complaint_display_assignee($item['assignee'] ?? ''), $complaints), complaint_assignee_options()));
$categoryOptions = array_unique(array_merge(['All'], complaint_problem_categories()));

$noStatusFilterApplied = $statusFilterRaw === null || $statusFilterRaw === '';

$filtered = array_filter($complaints, static function (array $complaint) use ($statusFilter, $assigneeFilter, $categoryFilter, $noStatusFilterApplied): bool {
    $statusRaw = (string) ($complaint['status'] ?? 'open');
    $status = strtolower($statusRaw);
    $assignee = complaint_display_assignee($complaint['assignee'] ?? '');
    $category = (string) ($complaint['problem_category'] ?? '');
    $isClosed = strtolower(trim($statusRaw)) === 'closed';

    if ($noStatusFilterApplied && $isClosed) {
        return false;
    }

    $statusMatches = $statusFilter === 'all' || $status === $statusFilter;
    $assigneeMatches = $assigneeFilter === 'all' || strcasecmp($assignee, $assigneeFilter) === 0;
    $categoryMatches = $categoryFilter === 'all' || strcasecmp($category, $categoryFilter) === 0;

    return $statusMatches && $assigneeMatches && $categoryMatches;
});

$counts = complaint_summary_counts($complaints);

function complaints_overview_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Customer Complaints | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .complaints-shell {
      width: 100%;
      max-width: none;
      margin: 1.5rem 0;
      padding: 0 1.25rem;
      box-sizing: border-box;
    }
    .complaints-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
    }
    .complaints-title {
      margin: 0;
      font-size: 2rem;
      color: #111827;
    }
    .complaints-subtitle {
      margin: 0.35rem 0 0;
      color: #4b5563;
    }
    .complaints-filters {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.75rem;
      margin: 1rem 0;
    }
    .complaints-filters select,
    .complaints-filters button {
      width: 100%;
      padding: 0.6rem 0.7rem;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      font: inherit;
    }
    .complaints-filters button {
      background: #1f4b99;
      color: #ffffff;
      font-weight: 700;
      cursor: pointer;
      border-color: #1f4b99;
    }
    .complaints-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.75rem;
      margin: 1rem 0;
    }
    .summary-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 1rem 1.25rem;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.04);
    }
    .summary-card h3 {
      margin: 0;
      font-size: 0.95rem;
      color: #4b5563;
      font-weight: 600;
    }
    .summary-card p {
      margin: 0.35rem 0 0;
      font-size: 1.4rem;
      font-weight: 700;
      color: #111827;
    }
    .complaints-table {
      width: 100%;
      table-layout: auto;
      border-collapse: collapse;
      background: #ffffff;
    }
    .complaints-table th,
    .complaints-table td {
      border: 1px solid #e5e7eb;
      padding: 0.75rem 0.8rem;
      text-align: left;
      font-size: 0.95rem;
    }
    .complaints-table th {
      background: #f9fafb;
      font-weight: 700;
      color: #111827;
    }
    .complaints-table td a {
      color: #1f4b99;
      text-decoration: none;
      font-weight: 700;
    }
    .empty-state {
      margin: 1rem 0 0;
      padding: 1rem 1.25rem;
      border-radius: 12px;
      border: 1px dashed #cbd5e1;
      background: #f8fafc;
      color: #475569;
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

    /* Complaints row colours by age (open only) */
    .complaint-age-0-1 {
      background-color: #f0fff4;
    }

    .complaint-age-2-3 {
      background-color: #fffbea;
    }

    .complaint-age-4-7 {
      background-color: #fff4e6;
    }

    .complaint-age-8-14 {
      background-color: #ffe5e5;
    }

    .complaint-age-15plus {
      background-color: #ffd6d6;
    }
  </style>
</head>
<body>
  <div class="complaints-shell">
    <div class="complaints-header">
      <div>
        <h1 class="complaints-title">Customer Complaints</h1>
        <p class="complaints-subtitle">Signed in as <?= complaints_overview_safe($viewerName) ?> (<?= complaints_overview_safe(ucfirst($viewerType)) ?>)</p>
      </div>
      <div>
        <a href="<?= complaints_overview_safe($viewerType === 'admin' ? 'admin-dashboard.php' : 'employee-dashboard.php') ?>" class="btn btn-ghost">Back to dashboard</a>
      </div>
    </div>

    <div class="complaints-summary">
      <div class="summary-card">
        <h3>Total complaints</h3>
        <p><?= number_format((int) $counts['total']) ?></p>
      </div>
      <div class="summary-card">
        <h3>Open complaints</h3>
        <p><?= number_format((int) $counts['open']) ?></p>
      </div>
      <div class="summary-card">
        <h3>Unassigned complaints</h3>
        <p><?= number_format((int) $counts['unassigned']) ?></p>
      </div>
    </div>

    <form method="get" class="complaints-filters">
      <div>
        <label class="sr-only" for="status">Status</label>
        <select id="status" name="status">
          <?php $statusOptions = ['all' => 'All statuses', 'open' => 'Open', 'intake' => 'Intake', 'triage' => 'Admin triage', 'work' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed']; ?>
          <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= complaints_overview_safe($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= complaints_overview_safe($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="sr-only" for="assignee">Assignee</label>
        <select id="assignee" name="assignee">
          <option value="all" <?= $assigneeFilter === 'all' ? 'selected' : '' ?>>All assignees</option>
          <?php foreach ($assigneeOptions as $assignee): ?>
            <?php $value = complaints_overview_safe($assignee); ?>
            <option value="<?= $value ?>" <?= strcasecmp($assigneeFilter, $assignee) === 0 ? 'selected' : '' ?>><?= $value ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="sr-only" for="category">Category</label>
        <select id="category" name="category">
          <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All categories</option>
          <?php foreach (complaint_problem_categories() as $category): ?>
            <option value="<?= complaints_overview_safe($category) ?>" <?= strcasecmp($categoryFilter, $category) === 0 ? 'selected' : '' ?>><?= complaints_overview_safe($category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit">Apply filters</button>
      </div>
    </form>

    <?php if (count($filtered) === 0): ?>
      <div class="empty-state">No complaints match your filters.</div>
    <?php else: ?>
      <table class="complaints-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Customer Name</th>
            <th>Customer mobile</th>
            <th>Title</th>
            <th>Category</th>
            <th>Assignee</th>
            <th>Status</th>
            <th>Forwarded</th>
            <th>Created</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filtered as $complaint): ?>
          <?php
            $created = complaints_overview_safe((string) ($complaint['created_at'] ?? ''));
            $title = trim((string) ($complaint['title'] ?? 'Complaint'));
            $mobile = (string) ($complaint['customer_mobile'] ?? '');
            $customerName = isset($customerByMobile[$mobile]) ? (string) ($customerByMobile[$mobile]['name'] ?? '') : '';
            $forwardedVia = complaint_normalize_forwarded_via($complaint['forwarded_via'] ?? 'none');
            $forwardedLabel = match ($forwardedVia) {
                'whatsapp' => 'WhatsApp',
                'email' => 'Email',
                'both' => 'Both',
                default => 'No',
            };

            $statusRaw = (string) ($complaint['status'] ?? 'open');
            $status = strtolower(trim($statusRaw));
            $rowClass = '';
            $isOpen = !in_array($status, ['closed', 'resolved'], true);

            if ($isOpen) {
                $createdAt = $complaint['created_at'] ?? null;
                $days = 0;
                if ($createdAt) {
                    $createdTs = strtotime((string) $createdAt);
                    if ($createdTs !== false) {
                        $days = (int) floor((time() - $createdTs) / 86400);
                    }
                }

                if ($days <= 1) {
                    $rowClass = 'complaint-age-0-1';
                } elseif ($days <= 3) {
                    $rowClass = 'complaint-age-2-3';
                } elseif ($days <= 7) {
                    $rowClass = 'complaint-age-4-7';
                } elseif ($days <= 14) {
                    $rowClass = 'complaint-age-8-14';
                } else {
                    $rowClass = 'complaint-age-15plus';
                }
            }
          ?>
          <tr class="<?= complaints_overview_safe($rowClass) ?>">
            <td><?= complaints_overview_safe((string) ($complaint['id'] ?? '—')) ?></td>
            <td><?= complaints_overview_safe($customerName) ?></td>
            <td><?= complaints_overview_safe((string) ($complaint['customer_mobile'] ?? 'Unknown')) ?></td>
            <td><?= complaints_overview_safe($title !== '' ? $title : 'Complaint') ?></td>
            <td><?= complaints_overview_safe((string) ($complaint['problem_category'] ?? '')) ?></td>
            <td><?= complaints_overview_safe(complaint_display_assignee($complaint['assignee'] ?? '')) ?></td>
            <td><?= complaints_overview_safe(ucfirst((string) ($complaint['status'] ?? 'open'))) ?></td>
            <td><?= complaints_overview_safe($forwardedLabel) ?></td>
            <td><?= $created ?></td>
            <td><a href="complaint-detail.php?id=<?= complaints_overview_safe((string) ($complaint['id'] ?? '')) ?>">View / Edit</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
