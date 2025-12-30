<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/employee_admin.php';

start_session();
$loggedInAdmin = !empty($_SESSION['admin_logged_in']) || (($_SESSION['user']['role_name'] ?? '') === 'admin');
$loggedInEmployee = !empty($_SESSION['employee_logged_in']) || (($_SESSION['user']['role_name'] ?? '') === 'employee');
if (!$loggedInAdmin && !$loggedInEmployee) {
    header('Location: login.php');
    exit;
}

$employeeStore = new EmployeeFsStore();
$employees = $employeeStore->listEmployees();

function lead_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$leadId = isset($_GET['id']) ? (string) $_GET['id'] : '';
$lead = $leadId !== '' ? find_lead_by_id($leadId) : null;
if ($lead === null) {
    http_response_code(404);
    echo 'Lead not found.';
    exit;
}

$statuses = ['New', 'Contacted', 'Site Visit Needed', 'Site Visit Done', 'Quotation Sent', 'Negotiation', 'Converted', 'Not Interested'];
$ratings = ['Hot', 'Warm', 'Cold'];
$leadSources = ['Incoming Call', 'WhatsApp', 'Referral', 'Social Media', 'Website Contact Form', 'Other'];
$interestTypes = ['Residential Rooftop', 'Commercial', 'Industrial', 'Petrol Pump', 'Irrigation / Agriculture', 'Other'];

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = isset($_POST['intent']) ? (string) $_POST['intent'] : 'save';
    $payload = [
        'name' => trim((string) ($_POST['name'] ?? $lead['name'])),
        'mobile' => trim((string) ($_POST['mobile'] ?? $lead['mobile'])),
        'alt_mobile' => trim((string) ($_POST['alt_mobile'] ?? $lead['alt_mobile'])),
        'city' => trim((string) ($_POST['city'] ?? $lead['city'])),
        'area_or_locality' => trim((string) ($_POST['area_or_locality'] ?? $lead['area_or_locality'])),
        'state' => trim((string) ($_POST['state'] ?? $lead['state'])),
        'lead_source' => trim((string) ($_POST['lead_source'] ?? $lead['lead_source'])),
        'interest_type' => trim((string) ($_POST['interest_type'] ?? $lead['interest_type'])),
        'status' => trim((string) ($_POST['status'] ?? $lead['status'])),
        'rating' => trim((string) ($_POST['rating'] ?? $lead['rating'])),
        'next_followup_date' => trim((string) ($_POST['next_followup_date'] ?? $lead['next_followup_date'])),
        'next_followup_time' => trim((string) ($_POST['next_followup_time'] ?? $lead['next_followup_time'])),
        'notes' => trim((string) ($_POST['notes'] ?? $lead['notes'])),
        'tags' => trim((string) ($_POST['tags'] ?? ($lead['tags'] ?? ''))),
        'converted_flag' => (string) ($lead['converted_flag'] ?? ''),
        'converted_date' => (string) ($lead['converted_date'] ?? ''),
        'not_interested_reason' => trim((string) ($_POST['not_interested_reason'] ?? ($lead['not_interested_reason'] ?? ''))),
    ];

    $assignedOption = (string) ($_POST['assigned_option'] ?? 'unassigned');
    $customName = trim((string) ($_POST['assigned_custom_name'] ?? ''));
    $assignedName = '';
    $assignedType = '';
    $assignedId = '';

    if ($assignedOption === 'unassigned') {
        // leave empty
    } elseif ($assignedOption === 'admin') {
        $assignedType = 'admin';
        $assignedId = (string) ($_SESSION['user']['id'] ?? 'admin');
        $assignedName = trim((string) ($_SESSION['user']['full_name'] ?? ($_SESSION['user']['username'] ?? 'Admin')));
    } elseif (str_starts_with($assignedOption, 'employee:')) {
        $assignedType = 'employee';
        $assignedId = substr($assignedOption, strlen('employee:'));
        foreach ($employees as $employee) {
            if (($employee['id'] ?? '') === $assignedId) {
                $assignedName = trim((string) ($employee['name'] ?? ''));
                break;
            }
        }
    } elseif ($assignedOption === 'custom' && $customName !== '') {
        $assignedType = 'external';
        $assignedName = $customName;
    }

    $payload['assigned_to_type'] = $assignedType;
    $payload['assigned_to_id'] = $assignedId;
    $payload['assigned_to_name'] = $assignedName;

    if ($intent === 'convert') {
        $payload['status'] = 'Converted';
        $payload['converted_flag'] = 'Yes';
        $payload['converted_date'] = date('Y-m-d');
    } elseif ($intent === 'not_interested') {
        $payload['status'] = 'Not Interested';
        $payload['converted_flag'] = 'No';
        $payload['not_interested_reason'] = trim((string) ($_POST['not_interested_reason'] ?? $lead['not_interested_reason']));
    }

    $activityNote = trim((string) ($_POST['activity_note'] ?? ''));
    if ($activityNote !== '') {
        $actor = audit_current_actor();
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'actor' => $actor['actor_id'],
            'note' => $activityNote,
        ];
        $existingLog = is_array($lead['activity_log'] ?? []) ? $lead['activity_log'] : [];
        $payload['activity_log'] = array_values($existingLog);
        $payload['activity_log'][] = $entry;
    }

    $result = update_lead($leadId, $payload);
    if ($result !== null) {
        $lead = $result['after'];
        $messages[] = ['type' => 'success', 'text' => 'Lead updated successfully.'];

        if ($intent === 'convert') {
            $actor = audit_current_actor();
            log_audit_event($actor['actor_type'], (string) $actor['actor_id'], 'lead', (string) $leadId, 'lead_convert', ['status' => 'Converted']);
        } elseif ($intent === 'not_interested') {
            $actor = audit_current_actor();
            log_audit_event($actor['actor_type'], (string) $actor['actor_id'], 'lead', (string) $leadId, 'lead_not_interested', ['reason' => $payload['not_interested_reason'] ?? '']);
        }
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Could not update lead.'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lead Detail | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    body { background: #f7f8fb; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .page-shell { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.5rem; box-shadow: 0 12px 40px rgba(0,0,0,0.06); margin-bottom: 1rem; }
    h1 { margin-top: 0; }
    label { font-weight: 700; color: #374151; display: block; margin-bottom: 0.25rem; }
    input[type=text], input[type=tel], input[type=date], input[type=time], select, textarea { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
    textarea { min-height: 120px; }
    .grid { display: grid; gap: 0.75rem; }
    .grid-3 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .btn { background: #2563eb; color: #fff; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .btn-secondary { background: #eef2ff; color: #1f2937; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .btn-success { background: #10b981; color: #fff; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .btn-danger { background: #ef4444; color: #fff; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .section-title { margin: 0 0 0.5rem; color: #111827; }
    .messages { margin-bottom: 1rem; }
    .alert { padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 0.5rem; }
    .alert-success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecdd3; }
    ul.activity { list-style: disc; margin-left: 1.5rem; color: #374151; }
  </style>
</head>
<body>
  <div class="page-shell">
    <?php
      $backUrl = 'leads-dashboard.php';
      if (!empty($_GET['return_to']) && !preg_match('/^https?:\/\//i', (string) $_GET['return_to'])) {
          $backUrl = (string) $_GET['return_to'];
      }
    ?>
    <p><a href="<?php echo lead_safe($backUrl); ?>" class="btn-secondary">&larr; Back to Leads</a></p>
    <div class="card">
      <h1>Lead Detail</h1>
      <p style="margin:0;color:#4b5563;">Lead ID: <?php echo lead_safe((string) $lead['id']); ?></p>
    </div>

    <?php if ($messages !== []): ?>
      <div class="messages">
        <?php foreach ($messages as $message): ?>
          <div class="alert alert-<?php echo $message['type'] === 'success' ? 'success' : 'error'; ?>"><?php echo lead_safe($message['text']); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="intent" value="save" />
      <div class="card">
        <h2 class="section-title">Basic Info</h2>
        <div class="grid grid-3">
          <div>
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" value="<?php echo lead_safe((string) $lead['name']); ?>" required />
          </div>
          <div>
            <label for="mobile">Mobile *</label>
            <input type="tel" id="mobile" name="mobile" value="<?php echo lead_safe((string) $lead['mobile']); ?>" required />
          </div>
          <div>
            <label for="alt_mobile">Alt Mobile</label>
            <input type="tel" id="alt_mobile" name="alt_mobile" value="<?php echo lead_safe((string) $lead['alt_mobile']); ?>" />
          </div>
          <div>
            <label for="city">City</label>
            <input type="text" id="city" name="city" value="<?php echo lead_safe((string) $lead['city']); ?>" />
          </div>
          <div>
            <label for="area_or_locality">Area / Locality</label>
            <input type="text" id="area_or_locality" name="area_or_locality" value="<?php echo lead_safe((string) $lead['area_or_locality']); ?>" />
          </div>
          <div>
            <label for="state">State</label>
            <input type="text" id="state" name="state" value="<?php echo lead_safe((string) $lead['state']); ?>" />
          </div>
          <div>
            <label for="lead_source">Lead Source</label>
            <select id="lead_source" name="lead_source">
              <?php foreach ($leadSources as $source): ?>
                <option value="<?php echo lead_safe($source); ?>" <?php echo strcasecmp((string) $lead['lead_source'], $source) === 0 ? 'selected' : ''; ?>><?php echo lead_safe($source); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="interest_type">Interest Type</label>
            <select id="interest_type" name="interest_type">
              <option value="">Select</option>
              <?php foreach ($interestTypes as $type): ?>
                <option value="<?php echo lead_safe($type); ?>" <?php echo strcasecmp((string) $lead['interest_type'], $type) === 0 ? 'selected' : ''; ?>><?php echo lead_safe($type); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="section-title">Status &amp; Follow-Up</h2>
        <div class="grid grid-3">
          <div>
            <label for="status">Status</label>
            <select id="status" name="status">
              <?php foreach ($statuses as $statusOption): ?>
                <option value="<?php echo lead_safe($statusOption); ?>" <?php echo strcasecmp((string) $lead['status'], $statusOption) === 0 ? 'selected' : ''; ?>><?php echo lead_safe($statusOption); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="rating">Rating</label>
            <select id="rating" name="rating">
              <?php foreach ($ratings as $ratingOption): ?>
                <option value="<?php echo lead_safe($ratingOption); ?>" <?php echo strcasecmp((string) $lead['rating'], $ratingOption) === 0 ? 'selected' : ''; ?>><?php echo lead_safe($ratingOption); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="assigned_option">Assigned To</label>
            <select id="assigned_option" name="assigned_option">
              <option value="unassigned">Unassigned</option>
              <option value="admin" <?php echo ($lead['assigned_to_type'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
              <?php foreach ($employees as $employee): ?>
                <?php $value = 'employee:' . ($employee['id'] ?? ''); ?>
                <option value="<?php echo lead_safe($value); ?>" <?php echo ($lead['assigned_to_type'] === 'employee' && ($lead['assigned_to_id'] ?? '') === ($employee['id'] ?? '')) ? 'selected' : ''; ?>><?php echo lead_safe((string) ($employee['name'] ?? 'Employee')); ?></option>
              <?php endforeach; ?>
              <option value="custom" <?php echo ($lead['assigned_to_type'] === 'external') ? 'selected' : ''; ?>>Other (custom)</option>
            </select>
            <input type="text" name="assigned_custom_name" placeholder="Custom name" value="<?php echo lead_safe(((string) ($lead['assigned_to_type'] ?? '')) === 'external' ? (string) $lead['assigned_to_name'] : ''); ?>" style="margin-top:0.4rem;" />
          </div>
          <div>
            <label for="next_followup_date">Next Follow-Up Date</label>
            <input type="date" id="next_followup_date" name="next_followup_date" value="<?php echo lead_safe((string) $lead['next_followup_date']); ?>" />
          </div>
          <div>
            <label for="next_followup_time">Next Follow-Up Time</label>
            <input type="time" id="next_followup_time" name="next_followup_time" value="<?php echo lead_safe((string) $lead['next_followup_time']); ?>" />
          </div>
          <div>
            <label>Last Contacted At</label>
            <input type="text" value="<?php echo lead_safe((string) $lead['last_contacted_at']); ?>" disabled />
          </div>
          <div>
            <label for="tags">Tags</label>
            <input type="text" id="tags" name="tags" placeholder="Comma separated" value="<?php echo lead_safe((string) ($lead['tags'] ?? '')); ?>" />
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="section-title">Notes &amp; Activity</h2>
        <div class="grid">
          <div>
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes"><?php echo lead_safe((string) $lead['notes']); ?></textarea>
          </div>
          <div>
            <label for="activity_note">Add activity note</label>
            <input type="text" id="activity_note" name="activity_note" placeholder="Add a quick activity note" />
          </div>
          <?php if (!empty($lead['activity_log']) && is_array($lead['activity_log'])): ?>
            <div>
              <label>Activity Log</label>
              <ul class="activity">
                <?php foreach (array_reverse($lead['activity_log']) as $log): ?>
                  <li><?php echo lead_safe((string) ($log['timestamp'] ?? '')); ?> — <?php echo lead_safe((string) ($log['actor'] ?? '')); ?>: <?php echo lead_safe((string) ($log['note'] ?? ($log['description'] ?? ''))); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <h2 class="section-title">Quick Actions</h2>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
          <a class="btn-secondary" href="tel:<?php echo lead_safe((string) $lead['mobile']); ?>">Call</a>
          <a class="btn-secondary" href="https://wa.me/91<?php echo lead_safe(preg_replace('/[^0-9]/', '', (string) $lead['mobile'])); ?>?text=<?php echo urlencode('Hello ' . ($lead['name'] ?? '') . ', this is Dakshayani Enterprises regarding your solar enquiry.'); ?>" target="_blank">WhatsApp</a>
          <button type="submit" name="intent" value="convert" class="btn-success">Mark Converted</button>
          <button type="submit" name="intent" value="not_interested" class="btn-danger">Mark Not Interested</button>
        </div>
        <div style="margin-top:0.5rem; max-width:480px;">
          <label for="not_interested_reason">Not Interested Reason</label>
          <input type="text" id="not_interested_reason" name="not_interested_reason" value="<?php echo lead_safe((string) $lead['not_interested_reason']); ?>" />
        </div>
      </div>

      <div style="display:flex; gap:0.75rem;">
        <button type="submit" class="btn">Save Changes</button>
        <a href="leads-dashboard.php" class="btn-secondary">Back to Dashboard</a>
      </div>
    </form>
  </div>
</body>
</html>
