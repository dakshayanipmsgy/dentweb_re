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

function complaint_detail_customer_notification_link(array $complaint, array $customer, string $channel): string
{
    $message = complaint_customer_resolution_message($complaint, $customer);
    $mobile = complaint_mobile_digits($complaint, $customer);

    if ($channel === 'whatsapp') {
        return complaint_whatsapp_urls($message, $mobile)['fallback_url'];
    }

    return complaint_sms_url($message, $mobile);
}

function complaint_detail_email_subject(array $complaint, array $customer): string
{
    return complaint_forward_email_subject($complaint, $customer);
}

function complaint_detail_external_action_response(array $complaint, array $customer, string $action): array
{
    if ($action === 'notify_whatsapp' || $action === 'notify_sms') {
        $channel = $action === 'notify_whatsapp' ? 'whatsapp' : 'sms';
        $mobile = complaint_mobile_digits($complaint, $customer);
        if (strlen($mobile) < 10) {
            return ['ok' => false, 'error' => 'Customer mobile number is missing or invalid. Update the complaint/customer mobile before notifying.'];
        }
        $nextNotified = complaint_add_customer_notification_channel($complaint['customer_notified_via'] ?? 'none', $channel);
        $notificationCount = max(0, (int) ($complaint['customer_notification_count'] ?? 0)) + 1;
        update_complaint([
            'id' => (string) ($complaint['id'] ?? ''),
            'customer_notified_via' => $nextNotified,
            'customer_notified_at' => complaint_now(),
            'customer_notification_count' => $notificationCount,
        ]);
        $message = complaint_customer_resolution_message($complaint, $customer);
        if ($channel === 'whatsapp') {
            return ['ok' => true, 'message' => 'Customer notification logged. Opening WhatsApp composer.'] + complaint_whatsapp_urls($message, $mobile);
        }
        return ['ok' => true, 'message' => 'Customer notification logged. Opening SMS composer.', 'open_url' => complaint_sms_url($message, $mobile)];
    }

    $channel = $action === 'email' ? 'email' : 'whatsapp';
    update_complaint([
        'id' => (string) ($complaint['id'] ?? ''),
        'forwarded_via' => complaint_add_forwarded_channel($complaint['forwarded_via'] ?? 'none', $channel),
    ]);
    $message = complaint_forward_message($complaint, $customer);
    if ($channel === 'whatsapp') {
        return ['ok' => true, 'message' => 'Complaint forwarding logged. Opening WhatsApp composer.'] + complaint_whatsapp_urls($message);
    }
    $subject = complaint_forward_email_subject($complaint, $customer);
    return ['ok' => true, 'message' => 'Complaint forwarding logged. Opening email composer.', 'open_url' => 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'external_action') {
    require_valid_csrf();
    header('Content-Type: application/json; charset=UTF-8');
    $externalAction = strtolower(trim((string) ($_POST['external_action'] ?? '')));
    if ($complaint === null) {
        echo json_encode(['ok' => false, 'error' => 'Complaint not found.']);
        exit;
    }
    if (!in_array($externalAction, ['whatsapp', 'email', 'notify_whatsapp', 'notify_sms'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
        exit;
    }
    echo json_encode(complaint_detail_external_action_response($complaint, $customer ?? [], $externalAction), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
if (in_array($action, ['whatsapp', 'email', 'notify_whatsapp', 'notify_sms', 'close'], true)) {
    if ($complaint === null) {
        $errorMessage = 'Complaint not found.';
    } else {
        $customerData = $customer ?? [];
        if ($action === 'close') {
            $updated = update_complaint([
                'id' => (string) ($complaint['id'] ?? ''),
                'status' => 'closed',
            ]);
            if ($updated !== null) {
                $complaint = $updated;
                if (!empty($updated['customer_mobile'])) {
                    complaint_sync_customer_flag($customerStore, (string) $updated['customer_mobile']);
                }
                $successMessage = 'Complaint marked closed.';
            }
        } elseif ($action === 'notify_whatsapp' || $action === 'notify_sms') {
            $channel = $action === 'notify_whatsapp' ? 'whatsapp' : 'sms';
            $nextNotified = complaint_add_customer_notification_channel($complaint['customer_notified_via'] ?? 'none', $channel);
            $notificationCount = max(0, (int) ($complaint['customer_notification_count'] ?? 0)) + 1;

            $updated = update_complaint([
                'id' => (string) ($complaint['id'] ?? ''),
                'customer_notified_via' => $nextNotified,
                'customer_notified_at' => complaint_now(),
                'customer_notification_count' => $notificationCount,
            ]);

            if ($updated !== null) {
                $complaint = $updated;
            }

            header('Location: ' . complaint_detail_customer_notification_link($complaint, $customerData, $channel));
            exit;
        } else {
            $message = complaint_forward_message($complaint, $customerData);

            $nextForwarded = complaint_add_forwarded_channel($complaint['forwarded_via'] ?? 'none', $action === 'whatsapp' ? 'whatsapp' : 'email');

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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
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
    .detail-action-note { margin: 0.65rem 0 0; color: #4b5563; font-size: 0.92rem; line-height: 1.45; }
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
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=notify_whatsapp" class="js-external-complaint-action" data-action="notify_whatsapp">Send Update to Customer on WhatsApp</a>
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=notify_sms" class="js-external-complaint-action" data-action="notify_sms">Send Update to Customer by SMS</a>
          <?php if (strtolower((string) ($complaint['status'] ?? 'open')) !== 'closed'): ?>
            <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=close" onclick="return confirm('Mark this complaint as closed?');">Mark Closed</a>
          <?php endif; ?>
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=whatsapp" class="js-external-complaint-action" data-action="whatsapp">Forward Complaint on WhatsApp</a>
          <a href="complaint-detail.php?id=<?= complaint_detail_safe((string) ($complaint['id'] ?? '')) ?>&action=email" class="js-external-complaint-action" data-action="email">Forward Complaint by Email</a>
        </div>
        <p class="detail-action-note">Customer update actions send a resolution/update message to the registered customer number. Forward complaint actions create a complaint summary that you can send to any authority or recipient you choose.</p>
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
            <small>Customer Notified</small>
            <p><?= complaint_detail_safe(complaint_customer_notified_label($complaint['customer_notified_via'] ?? 'none')) ?></p>
          </div>
          <div>
            <small>Customer notification time</small>
            <p><?= complaint_detail_safe((string) ($complaint['customer_notified_at'] ?? '—')) ?></p>
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
        <?= csrf_field() ?>
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
  <script>
    (() => {
      const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      const complaintId = <?= json_encode((string) ($complaint['id'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
      const openExternal = (url, fallbackUrl) => {
        if (!url) return;
        window.open(url, '_blank', 'noopener');
        if (fallbackUrl && fallbackUrl !== url) {
          window.setTimeout(() => window.open(fallbackUrl, '_blank', 'noopener'), 900);
        }
      };

      document.addEventListener('click', async (event) => {
        const link = event.target.closest('.js-external-complaint-action');
        if (!link) return;
        event.preventDefault();

        const payload = new URLSearchParams();
        payload.set('action', 'external_action');
        payload.set('external_action', link.getAttribute('data-action') || '');
        payload.set('id', complaintId);
        payload.set('csrf_token', csrfToken);

        try {
          const response = await fetch('complaint-detail.php?id=' + encodeURIComponent(complaintId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: payload.toString()
          });
          const result = await response.json();
          if (!result || !result.ok) {
            throw new Error((result && result.error) || 'Communication action failed.');
          }
          openExternal(result.open_url, result.fallback_url || '');
          window.setTimeout(() => window.location.reload(), 1200);
        } catch (err) {
          alert(err && err.message ? err.message : 'Communication action failed.');
        }
      });
    })();
  </script>
</body>
</html>
