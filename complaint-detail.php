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

$complaintId = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));
$complaint = $complaintId !== '' ? find_complaint_by_id($complaintId) : null;
$customer = null;

if ($complaint !== null && !empty($complaint['customer_mobile'])) {
    $customer = $customerStore->findByMobile((string) $complaint['customer_mobile']);
}

$successMessage = '';
$errorMessage = '';

function complaint_detail_format_message(array $complaint, array $customer): string
{
    $customerName = trim((string) ($customer['name'] ?? ''));
    $customerMobile = trim((string) ($customer['mobile'] ?? ($complaint['customer_mobile'] ?? '')));
    $division = trim((string) ($customer['division_name'] ?? ''));
    $subdivision = trim((string) ($customer['sub_division_name'] ?? ''));
    $jbvnlAccount = trim((string) ($customer['jbvnl_account_number'] ?? ''));
    $applicationId = trim((string) ($customer['application_id'] ?? ''));

    $lines = [
        'Customer Details',
        'Customer Name: ' . $customerName,
        'Mobile: ' . $customerMobile,
        'Division: ' . $division,
        'Subdivision: ' . $subdivision,
        'JBVNL Account Number: ' . $jbvnlAccount,
        'Application ID: ' . $applicationId,
        '',
        'Complaint Details',
        'Complaint ID: ' . (string) ($complaint['id'] ?? ''),
        'Problem Category: ' . trim((string) ($complaint['problem_category'] ?? '')),
        'Complaint Title: ' . trim((string) ($complaint['title'] ?? '')),
        'Description: ' . trim((string) ($complaint['description'] ?? '')),
        'Assignee: ' . trim((string) ($complaint['assignee'] ?? '')),
        'Status: ' . trim((string) ($complaint['status'] ?? '')),
        'Created At: ' . trim((string) ($complaint['created_at'] ?? '')),
        'Updated At: ' . trim((string) ($complaint['updated_at'] ?? '')),
    ];

    return implode("\n", $lines);
}

function complaint_detail_email_subject(array $complaint, array $customer): string
{
    $customerName = trim((string) ($customer['name'] ?? ''));
    $id = trim((string) ($complaint['id'] ?? ''));

    return 'Complaint Forwarded - Complaint ID ' . $id . ' - ' . $customerName;
}

$action = (string) ($_GET['action'] ?? '');
if ($action === 'whatsapp' || $action === 'email') {
    if ($complaint === null) {
        $errorMessage = 'Complaint not found.';
    } else {
        $customerData = $customer ?? [];
        $message = complaint_detail_format_message($complaint, $customerData);

        $currentForwarded = complaint_normalize_forwarded_via($complaint['forwarded_via'] ?? 'none');
        $nextForwarded = $currentForwarded;
        if ($action === 'whatsapp') {
            if ($currentForwarded === 'none') {
                $nextForwarded = 'whatsapp';
            } elseif ($currentForwarded === 'email') {
                $nextForwarded = 'both';
            }
        } else {
            if ($currentForwarded === 'none') {
                $nextForwarded = 'email';
            } elseif ($currentForwarded === 'whatsapp') {
                $nextForwarded = 'both';
            }
        }

        $updated = update_complaint([
            'id' => (string) ($complaint['id'] ?? ''),
            'forwarded_via' => $nextForwarded,
        ]);

        if ($updated !== null) {
            $complaint = $updated;
        }

        if ($action === 'whatsapp') {
            header('Location: https://wa.me/?text=' . rawurlencode($message));
            exit;
        }

        $subject = complaint_detail_email_subject($complaint, $customerData);
        header('Location: mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updates = [
            'id' => $complaintId,
            'problem_category' => (string) ($_POST['problem_category'] ?? ''),
            'assignee' => (string) ($_POST['assignee'] ?? ''),
            'status' => (string) ($_POST['status'] ?? ''),
        ];

        $updated = update_complaint($updates);
        if ($updated === null) {
            throw new RuntimeException('Complaint not found.');
        }

        if (!empty($updated['customer_mobile'])) {
            complaint_sync_customer_flag($customerStore, (string) $updated['customer_mobile']);
        }

        $complaint = $updated;
        $successMessage = 'Complaint updated successfully.';
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}

$problemCategories = complaint_problem_categories();
$assigneeOptions = complaint_assignee_options();
$statusOptions = [
    'open' => 'Open',
    'intake' => 'Intake',
    'triage' => 'Admin triage',
    'work' => 'In progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];

function complaint_detail_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Complaint Detail | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .detail-shell {
      width: 100%;
      max-width: none;
      margin: 1.5rem 0;
      padding: 0 1.25rem;
      box-sizing: border-box;
    }
    .detail-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
    }
    .detail-title { margin: 0; font-size: 2rem; color: #111827; }
    .detail-subtitle { margin: 0.35rem 0 0; color: #4b5563; }
    .detail-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 1.25rem;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.04);
      margin-bottom: 1rem;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 0.75rem 1.25rem;
      margin: 0.5rem 0 0;
    }
    .detail-grid p { margin: 0; color: #111827; }
    .detail-grid small { color: #6b7280; }
    .detail-form label { display: block; margin: 0.6rem 0 0.35rem; font-weight: 600; color: #1f2937; }
    .detail-form select { width: 100%; padding: 0.65rem 0.7rem; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
    .detail-actions { margin-top: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .detail-actions button, .detail-actions a { padding: 0.65rem 1.1rem; border-radius: 10px; text-decoration: none; font-weight: 700; border: 1px solid transparent; }
    .detail-actions button { background: #1f4b99; color: #ffffff; cursor: pointer; border-color: #1f4b99; }
    .detail-actions a { background: #f3f4f6; color: #111827; border-color: #e5e7eb; }
    .admin-alert { margin: 0.5rem 0; }
  </style>
</head>
<body>
  <div class="detail-shell">
    <div class="detail-header">
      <div>
        <h1 class="detail-title">Complaint detail</h1>
        <p class="detail-subtitle">Signed in as <?= complaint_detail_safe($viewerName) ?> (<?= complaint_detail_safe(ucfirst($viewerType)) ?>)</p>
      </div>
      <div>
        <a href="complaints-overview.php" class="btn btn-ghost">Back to complaints</a>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="admin-alert admin-alert--success" role="status"><?= complaint_detail_safe($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
      <div class="admin-alert admin-alert--error" role="alert"><?= complaint_detail_safe($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($complaint === null): ?>
      <div class="detail-card">
        <p>Complaint not found.</p>
        <div class="detail-actions">
          <a href="complaints-overview.php">Back to list</a>
        </div>
      </div>
    <?php else: ?>
      <div class="detail-card">
        <h2 style="margin-top:0;">Complaint #<?= complaint_detail_safe((string) ($complaint['id'] ?? '—')) ?></h2>
        <div class="detail-actions">
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=whatsapp">Create WhatsApp Message</a>
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=email">Create Email Message</a>
        </div>
        <div class="detail-grid">
          <div>
            <small>Customer mobile</small>
            <p><?= complaint_detail_safe((string) ($complaint['customer_mobile'] ?? 'Unknown')) ?></p>
          </div>
          <div>
            <small>Title</small>
            <p><?= complaint_detail_safe((string) ($complaint['title'] ?? 'Complaint')) ?></p>
          </div>
          <div>
            <small>Problem category</small>
            <p><?= complaint_detail_safe((string) ($complaint['problem_category'] ?? '')) ?></p>
          </div>
          <div>
            <small>Assignee</small>
            <p><?= complaint_detail_safe(complaint_display_assignee($complaint['assignee'] ?? '')) ?></p>
          </div>
          <div>
            <small>Status</small>
            <p><?= complaint_detail_safe(ucfirst((string) ($complaint['status'] ?? 'open'))) ?></p>
          </div>
          <div>
            <small>Forwarding Status</small>
            <p><?= complaint_detail_safe(complaint_forwarded_label($complaint['forwarded_via'] ?? 'none')) ?></p>
          </div>
          <div>
            <small>Created at</small>
            <p><?= complaint_detail_safe((string) ($complaint['created_at'] ?? '')) ?></p>
          </div>
          <div>
            <small>Updated at</small>
            <p><?= complaint_detail_safe((string) ($complaint['updated_at'] ?? '')) ?></p>
          </div>
          <div>
            <small>Description</small>
            <p><?= nl2br(complaint_detail_safe((string) ($complaint['description'] ?? 'No description'))) ?></p>
          </div>
        </div>
      </div>

      <div class="detail-card">
        <h3 style="margin-top:0;">Update complaint</h3>
        <form method="post" class="detail-form">
          <input type="hidden" name="id" value="<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>" />
          <label for="problem_category">Problem category</label>
          <select id="problem_category" name="problem_category" required>
            <?php foreach ($problemCategories as $category): ?>
              <option value="<?= complaint_detail_safe($category) ?>" <?= strcasecmp((string) ($complaint['problem_category'] ?? ''), $category) === 0 ? 'selected' : '' ?>><?= complaint_detail_safe($category) ?></option>
            <?php endforeach; ?>
          </select>

          <label for="assignee">Assignee</label>
          <select id="assignee" name="assignee">
            <option value="">Unassigned</option>
            <?php foreach ($assigneeOptions as $assignee): ?>
              <option value="<?= complaint_detail_safe($assignee) ?>" <?= strcasecmp((string) ($complaint['assignee'] ?? ''), $assignee) === 0 ? 'selected' : '' ?>><?= complaint_detail_safe($assignee) ?></option>
            <?php endforeach; ?>
          </select>

          <label for="status">Status</label>
          <select id="status" name="status" required>
            <?php foreach ($statusOptions as $value => $label): ?>
              <option value="<?= complaint_detail_safe($value) ?>" <?= strtolower((string) ($complaint['status'] ?? '')) === $value ? 'selected' : '' ?>><?= complaint_detail_safe($label) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="detail-actions">
            <button type="submit">Save changes</button>
            <a href="complaints-overview.php">Cancel</a>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
