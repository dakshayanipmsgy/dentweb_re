<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/customer_admin.php';
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
$customerStore = new CustomerFsStore();

function leads_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function leads_actor_details(): array
{
    $actor = audit_current_actor();
    $name = '';
    if ($actor['actor_type'] === 'admin') {
        $user = $_SESSION['user'] ?? [];
        $name = trim((string) ($user['full_name'] ?? ($user['username'] ?? 'Admin')));
    } elseif ($actor['actor_type'] === 'employee') {
        $name = trim((string) ($_SESSION['employee_name'] ?? ''));
    }

    return ['type' => $actor['actor_type'], 'id' => (string) $actor['actor_id'], 'name' => $name];
}

/**
 * @return array{created: bool, existing: bool, mobile: string, customer: array<string, mixed>|null}
 */
function leads_create_customer_from_lead(CustomerFsStore $customerStore, array $lead): array
{
    $leadName = trim((string) ($lead['name'] ?? ''));
    $leadMobile = trim((string) ($lead['mobile'] ?? ''));
    if ($leadMobile === '') {
        $leadMobile = trim((string) ($lead['alt_mobile'] ?? ''));
    }

    if ($leadMobile === '') {
        return ['created' => false, 'existing' => false, 'mobile' => '', 'customer' => null];
    }

    $existingCustomer = $customerStore->findByMobile($leadMobile);
    if ($existingCustomer !== null) {
        return ['created' => false, 'existing' => true, 'mobile' => $leadMobile, 'customer' => $existingCustomer];
    }

    $customerPayload = [
        'name' => $leadName !== '' ? $leadName : 'New Customer',
        'mobile' => $leadMobile,
        'password_hash' => password_hash('abcd1234', PASSWORD_DEFAULT),
        'city' => (string) ($lead['city'] ?? ''),
        'state' => (string) ($lead['state'] ?? ''),
        'address' => (string) ($lead['area_or_locality'] ?? ''),
        'customer_type' => (string) ($lead['customer_type'] ?? ''),
        'status' => 'New',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $result = $customerStore->addCustomer($customerPayload);
    if (($result['success'] ?? false) === true) {
        return ['created' => true, 'existing' => false, 'mobile' => $leadMobile, 'customer' => $result['customer'] ?? null];
    }

    return ['created' => false, 'existing' => false, 'mobile' => $leadMobile, 'customer' => null];
}

$messages = [];
if (isset($_GET['msg']) && trim((string) $_GET['msg']) !== '') {
    $messages[] = ['type' => 'success', 'text' => (string) $_GET['msg']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leadAction = isset($_POST['lead_action']) ? (string) $_POST['lead_action'] : '';
    $leadId = isset($_POST['lead_id']) ? (string) $_POST['lead_id'] : '';

    if ($leadAction !== '' && $leadId !== '') {
        $existingLead = find_lead_by_id($leadId);
        if ($existingLead === null) {
            $messages[] = ['type' => 'error', 'text' => 'Lead not found.'];
        } else {
            $updates = [];
            $customerResult = null;
            if ($leadAction === 'mark_converted') {
                $customerResult = leads_create_customer_from_lead($customerStore, $existingLead);
                $updates = [
                    'status' => 'Converted',
                    'converted_flag' => 'Yes',
                    'converted_date' => date('Y-m-d'),
                    'not_interested_reason' => (string) ($existingLead['not_interested_reason'] ?? ''),
                    'archived_flag' => true,
                    'archived_at' => date('Y-m-d H:i:s'),
                ];
                if (($customerResult['mobile'] ?? '') !== '') {
                    $updates['customer_created_flag'] = true;
                    $updates['customer_mobile_link'] = $customerResult['mobile'];
                    if (isset($customerResult['customer']['serial_number'])) {
                        $updates['customer_id_link'] = (string) $customerResult['customer']['serial_number'];
                    }
                }
            } elseif ($leadAction === 'mark_not_interested') {
                $updates = [
                    'status' => 'Not Interested',
                    'converted_flag' => 'No',
                    'not_interested_reason' => (string) ($existingLead['not_interested_reason'] ?? ''),
                ];
            } elseif ($leadAction === 'archive_lead') {
                $updates = [
                    'archived_flag' => true,
                    'archived_at' => (string) (($existingLead['archived_at'] ?? '') !== '' ? $existingLead['archived_at'] : date('Y-m-d H:i:s')),
                ];
            } elseif ($leadAction === 'create_customer_from_lead') {
                $customerResult = leads_create_customer_from_lead($customerStore, $existingLead);
                if (($customerResult['mobile'] ?? '') !== '') {
                    $updates = [
                        'customer_created_flag' => true,
                        'customer_mobile_link' => $customerResult['mobile'],
                    ];
                    if (isset($customerResult['customer']['serial_number'])) {
                        $updates['customer_id_link'] = (string) $customerResult['customer']['serial_number'];
                    }
                }
            }

            if ($updates !== []) {
                $result = update_lead($leadId, $updates);
                if ($result !== null) {
                    if ($leadAction === 'mark_converted') {
                        $msg = 'Lead marked as Converted.';
                    } elseif ($leadAction === 'mark_not_interested') {
                        $msg = 'Lead marked as Not Interested.';
                    } elseif ($leadAction === 'archive_lead') {
                        $msg = 'Lead archived.';
                    } else {
                        $msg = 'Customer created from lead.';
                    }
                    header('Location: leads-dashboard.php?msg=' . urlencode($msg));
                    exit;
                }

                $messages[] = ['type' => 'error', 'text' => 'Could not update lead.'];
            }
        }
    }

    $intent = isset($_POST['intent']) ? (string) $_POST['intent'] : '';

    if ($intent === 'quick_add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $leadSource = trim((string) ($_POST['lead_source'] ?? 'Incoming Call'));
        $interestType = trim((string) ($_POST['interest_type'] ?? ''));

        if ($name === '' || $mobile === '') {
            $messages[] = ['type' => 'error', 'text' => 'Name and mobile are required to add a lead.'];
        } else {
            $actorDetails = leads_actor_details();
            $record = add_lead([
                'name' => $name,
                'mobile' => $mobile,
                'city' => $city,
                'lead_source' => $leadSource !== '' ? $leadSource : 'Incoming Call',
                'interest_type' => $interestType,
                'status' => 'New',
                'rating' => 'Warm',
                'assigned_to_type' => $actorDetails['type'],
                'assigned_to_id' => $actorDetails['id'],
                'assigned_to_name' => $actorDetails['name'],
            ]);
            $messages[] = ['type' => 'success', 'text' => 'Lead added successfully (#' . leads_safe($record['id']) . ').'];
        }
    } elseif ($intent === 'mark_contacted') {
        $leadId = (string) ($_POST['lead_id'] ?? '');
        if ($leadId !== '') {
            $existing = find_lead_by_id($leadId);
            if ($existing !== null) {
                $updates = [
                    'last_contacted_at' => date('Y-m-d H:i:s'),
                ];
                if (trim((string) ($existing['next_followup_date'] ?? '')) === '') {
                    $updates['next_followup_date'] = date('Y-m-d', strtotime('+3 days'));
                }
                update_lead($leadId, $updates);
                $messages[] = ['type' => 'success', 'text' => 'Lead marked as contacted.'];
            }
        }
    }
}

$leads = load_all_leads();

$view = $_GET['view'] ?? 'active';
if (!in_array($view, ['active', 'archived', 'all'], true)) {
    $view = 'active';
}

$searchTerm = strtolower(trim((string) ($_GET['search'] ?? '')));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$ratingFilter = (string) ($_GET['rating'] ?? 'all');
$assignedFilter = (string) ($_GET['assigned_to'] ?? 'all');
$followupToday = isset($_GET['followup_today']) && $_GET['followup_today'] === '1';
$followupOverdue = isset($_GET['followup_overdue']) && $_GET['followup_overdue'] === '1';

$today = date('Y-m-d');
$filteredLeads = array_values(array_filter($leads, function (array $lead) use ($searchTerm, $statusFilter, $ratingFilter, $assignedFilter, $followupToday, $followupOverdue, $today, $view): bool {
    $matchesSearch = true;
    if ($searchTerm !== '') {
        $haystacks = [
            strtolower((string) ($lead['name'] ?? '')),
            strtolower((string) ($lead['mobile'] ?? '')),
            strtolower((string) ($lead['city'] ?? '')),
        ];
        $matchesSearch = false;
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, $searchTerm)) {
                $matchesSearch = true;
                break;
            }
        }
    }

    $matchesStatus = $statusFilter === 'all' || strcasecmp((string) ($lead['status'] ?? ''), $statusFilter) === 0;
    $matchesRating = $ratingFilter === 'all' || strcasecmp((string) ($lead['rating'] ?? ''), $ratingFilter) === 0;

    $matchesAssigned = true;
    if ($assignedFilter === 'me') {
        $currentName = leads_actor_details()['name'];
        $matchesAssigned = $currentName !== '' && strcasecmp((string) ($lead['assigned_to_name'] ?? ''), $currentName) === 0;
    } elseif ($assignedFilter !== 'all') {
        $matchesAssigned = strcasecmp((string) ($lead['assigned_to_name'] ?? ''), $assignedFilter) === 0;
    }

    $matchesFollowup = true;
    $isArchived = !empty($lead['archived_flag']);
    if ($view === 'active' && $isArchived) {
        return false;
    }
    if ($view === 'archived' && !$isArchived) {
        return false;
    }

    $status = strtolower((string) ($lead['status'] ?? ''));
    if ($followupToday || $followupOverdue) {
        $date = (string) ($lead['next_followup_date'] ?? '');
        if ($date === '') {
            $matchesFollowup = false;
        } else {
            if ($followupToday) {
                $matchesFollowup = $matchesFollowup && ($date === $today);
            }
            if ($followupOverdue) {
                $matchesFollowup = $matchesFollowup && ($date < $today) && !in_array($status, ['converted', 'not interested'], true);
            }
        }
    }

    return $matchesSearch && $matchesStatus && $matchesRating && $matchesAssigned && $matchesFollowup;
}));

$assignedNames = array_values(array_unique(array_filter(array_map(static function (array $lead): string {
    return trim((string) ($lead['assigned_to_name'] ?? ''));
}, $leads))));
sort($assignedNames);

$leadSources = ['Incoming Call', 'WhatsApp', 'Referral', 'Social Media', 'Website Contact Form', 'Other'];
$interestTypes = ['Residential Rooftop', 'Commercial', 'Industrial', 'Petrol Pump', 'Irrigation / Agriculture', 'Other'];
$statuses = ['New', 'Contacted', 'Site Visit Needed', 'Site Visit Done', 'Quotation Sent', 'Negotiation', 'Converted', 'Not Interested'];
$ratings = ['Hot', 'Warm', 'Cold'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Leads Dashboard | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    body { background: #f7f8fb; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .fullwidth-wrapper {
      width: 100% !important;
      max-width: 100% !important;
      padding: 1.5rem 20px;
      box-sizing: border-box;
      margin-left: 0;
      margin-right: 0;
    }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.5rem; box-shadow: 0 12px 40px rgba(0,0,0,0.06); margin-bottom: 1rem; }
    h1 { margin: 0 0 0.5rem; }
    .grid { display: grid; gap: 0.75rem; }
    .grid-3 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    label { font-weight: 700; color: #374151; display: block; margin-bottom: 0.25rem; }
    input[type=text], input[type=tel], input[type=date], select { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
    button { font: inherit; cursor: pointer; }
    .btn { background: #2563eb; color: #fff; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; }
    .btn-secondary { background: #eef2ff; color: #1f2937; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.65rem 0.5rem; border-bottom: 1px solid #e5e7eb; }
    th { font-size: 0.9rem; color: #374151; }
    tr:hover { background: #f9fafb; }
    .badge { display: inline-block; padding: 0.25rem 0.55rem; border-radius: 999px; font-weight: 700; font-size: 0.85rem; }
    .pill { background: #eef2ff; color: #4338ca; }
    .table-actions { display: flex; gap: 0.35rem; flex-wrap: wrap; }
    .messages { margin-bottom: 1rem; }
    .alert { padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 0.5rem; }
    .alert-success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecdd3; }
    .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; margin-bottom: 0.5rem; }
    .header-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .back-button { background: #eef2ff; color: #1f2937; border: 1px solid #d1d5db; padding: 0.65rem 0.9rem; border-radius: 10px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 0.45rem; }
    .back-button:hover { background: #e0e7ff; }

    /* Lead row colours */
    .lead-row-overdue { background-color: #ffe5e5; }
    .lead-row-today { background-color: #fff9e0; }
    .lead-row-hot { background-color: #fff4e6; }
    .lead-row-new { background-color: #e9f3ff; }
    .lead-filters { margin-bottom: 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
    .lead-filters a { padding: 0.4rem 0.75rem; border-radius: 999px; border: 1px solid #d1d5db; text-decoration: none; color: #1f2937; background: #fff; }
    .lead-filters a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
  </style>
</head>
<body>
  <div class="fullwidth-wrapper">
    <div class="card">
      <div class="header-row">
        <div>
          <h1>Leads Dashboard</h1>
          <p style="margin:0;color:#4b5563;">Quickly add new leads and manage follow-ups.</p>
        </div>
        <button type="button" class="back-button" onclick="history.back();" aria-label="Go back">
          <span aria-hidden="true">&larr;</span>
          Back
        </button>
      </div>
    </div>

    <?php if ($messages !== []): ?>
      <div class="messages">
        <?php foreach ($messages as $message): ?>
          <div class="alert alert-<?php echo $message['type'] === 'success' ? 'success' : 'error'; ?>">
            <?php echo leads_safe($message['text']); ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 style="margin-top:0;">Quick Add Lead</h2>
      <form method="post" class="grid grid-3">
        <input type="hidden" name="intent" value="quick_add" />
        <div>
          <label for="name">Name *</label>
          <input type="text" id="name" name="name" required />
        </div>
        <div>
          <label for="mobile">Mobile *</label>
          <input type="tel" id="mobile" name="mobile" required />
        </div>
        <div>
          <label for="city">City</label>
          <input type="text" id="city" name="city" />
        </div>
        <div>
          <label for="lead_source">Lead Source</label>
          <select id="lead_source" name="lead_source">
            <?php foreach ($leadSources as $source): ?>
              <option value="<?php echo leads_safe($source); ?>" <?php echo $source === 'Incoming Call' ? 'selected' : ''; ?>><?php echo leads_safe($source); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="interest_type">Interest Type</label>
          <select id="interest_type" name="interest_type">
            <option value="">Select</option>
            <?php foreach ($interestTypes as $type): ?>
              <option value="<?php echo leads_safe($type); ?>"><?php echo leads_safe($type); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="align-self:end;">
          <button type="submit" class="btn">Add Lead</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Leads</h2>
      <div class="lead-filters">
        <a href="/leads-dashboard.php?view=active" class="<?php echo $view === 'active' ? 'active' : ''; ?>">Active Leads</a>
        <a href="/leads-dashboard.php?view=archived" class="<?php echo $view === 'archived' ? 'active' : ''; ?>">Archived Leads</a>
        <a href="/leads-dashboard.php?view=all" class="<?php echo $view === 'all' ? 'active' : ''; ?>">All Leads</a>
      </div>
      <form method="get" class="filters">
        <input type="hidden" name="view" value="<?php echo leads_safe($view); ?>" />
        <input type="text" name="search" placeholder="Search name, mobile, city" value="<?php echo leads_safe((string) ($_GET['search'] ?? '')); ?>" />
        <select name="status">
          <option value="all">All Statuses</option>
          <?php foreach ($statuses as $statusOption): ?>
            <option value="<?php echo leads_safe($statusOption); ?>" <?php echo strcasecmp((string) ($_GET['status'] ?? ''), $statusOption) === 0 ? 'selected' : ''; ?>><?php echo leads_safe($statusOption); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="rating">
          <option value="all">All Ratings</option>
          <?php foreach ($ratings as $ratingOption): ?>
            <option value="<?php echo leads_safe($ratingOption); ?>" <?php echo strcasecmp((string) ($_GET['rating'] ?? ''), $ratingOption) === 0 ? 'selected' : ''; ?>><?php echo leads_safe($ratingOption); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="assigned_to">
          <option value="all">All Owners</option>
          <option value="me" <?php echo ((string) ($_GET['assigned_to'] ?? '') === 'me') ? 'selected' : ''; ?>>Me</option>
          <?php foreach ($assignedNames as $assignedName): ?>
            <option value="<?php echo leads_safe($assignedName); ?>" <?php echo strcasecmp((string) ($_GET['assigned_to'] ?? ''), $assignedName) === 0 ? 'selected' : ''; ?>><?php echo leads_safe($assignedName); ?></option>
          <?php endforeach; ?>
        </select>
        <label style="display:flex;align-items:center;gap:0.35rem;">
          <input type="checkbox" name="followup_today" value="1" <?php echo $followupToday ? 'checked' : ''; ?> /> Today's Follow-ups
        </label>
        <label style="display:flex;align-items:center;gap:0.35rem;">
          <input type="checkbox" name="followup_overdue" value="1" <?php echo $followupOverdue ? 'checked' : ''; ?> /> Overdue Follow-ups
        </label>
        <button type="submit" class="btn-secondary">Apply Filters</button>
      </form>

      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Mobile</th>
              <th>City</th>
              <th>Status</th>
              <th>Rating</th>
              <th>Next Follow-Up</th>
              <th>Assigned To</th>
              <th>Last Contacted</th>
              <th>Campaign</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($filteredLeads === []): ?>
              <tr><td colspan="11">No leads match the selected filters.</td></tr>
            <?php else: ?>
              <?php foreach ($filteredLeads as $index => $lead): ?>
                <?php
                  $statusValue = strtolower(trim((string) ($lead['status'] ?? '')));
                  $ratingValue = strtolower(trim((string) ($lead['rating'] ?? '')));
                  $nextFollowupDate = trim((string) ($lead['next_followup_date'] ?? ''));
                  $isConverted = $statusValue === 'converted' || strcasecmp((string) ($lead['converted_flag'] ?? ''), 'yes') === 0;
                  $isNotInterested = $statusValue === 'not interested';
                  $isArchived = !empty($lead['archived_flag']);
                  $customerCreated = !empty($lead['customer_created_flag']);
                  $hasMobile = trim((string) ($lead['mobile'] ?? '')) !== '' || trim((string) ($lead['alt_mobile'] ?? '')) !== '';
                  $rowClass = '';

                  if (!$isConverted && !$isNotInterested) {
                      if ($nextFollowupDate !== '') {
                          if ($nextFollowupDate < $today) {
                              $rowClass = 'lead-row-overdue';
                          } elseif ($nextFollowupDate === $today) {
                              $rowClass = 'lead-row-today';
                          } elseif ($ratingValue === 'hot') {
                              $rowClass = 'lead-row-hot';
                          }
                      } elseif ($statusValue === 'new') {
                          $rowClass = 'lead-row-new';
                      }
                  }
                ?>
                <tr class="<?= leads_safe($rowClass) ?>">
                  <td><?php echo $index + 1; ?></td>
                  <td><?php echo leads_safe((string) ($lead['name'] ?? '')); ?></td>
                  <td><a href="tel:<?php echo leads_safe((string) ($lead['mobile'] ?? '')); ?>"><?php echo leads_safe((string) ($lead['mobile'] ?? '')); ?></a></td>
                  <td><?php echo leads_safe((string) ($lead['city'] ?? '')); ?></td>
                  <td><span class="badge pill"><?php echo leads_safe((string) ($lead['status'] ?? '')); ?></span></td>
                  <td><?php echo leads_safe((string) ($lead['rating'] ?? '')); ?></td>
                  <td><?php echo leads_safe(trim(((string) ($lead['next_followup_date'] ?? '')) . ' ' . ((string) ($lead['next_followup_time'] ?? '')))); ?></td>
                  <td><?php echo leads_safe((string) ($lead['assigned_to_name'] ?? '')); ?></td>
                  <td><?php echo leads_safe((string) ($lead['last_contacted_at'] ?? '')); ?></td>
                  <td>
                    <?php if (($lead['source_campaign_name'] ?? '') !== ''): ?>
                      <?php echo leads_safe((string) ($lead['source_campaign_name'] ?? '')); ?>
                      <?php if (($lead['source_campaign_id'] ?? '') !== ''): ?>
                        <span class="badge" style="background:#e2e8f0;color:#0f172a;">#<?php echo leads_safe((string) ($lead['source_campaign_id'] ?? '')); ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      &ndash;
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="table-actions">
                      <a class="btn-secondary" style="padding:0.35rem 0.6rem;" href="lead-detail.php?id=<?php echo urlencode((string) ($lead['id'] ?? '')); ?>">View / Edit</a>
                      <a class="btn-secondary" style="padding:0.35rem 0.6rem;" href="https://wa.me/91<?php echo leads_safe(preg_replace('/[^0-9]/', '', (string) ($lead['mobile'] ?? ''))); ?>?text=<?php echo urlencode('Hello ' . ($lead['name'] ?? '') . ', this is Dakshayani Enterprises regarding your solar enquiry.'); ?>" target="_blank">WhatsApp</a>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="intent" value="mark_contacted" />
                        <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
                        <button type="submit" class="btn" style="padding:0.35rem 0.6rem; background:#10b981;">Mark Contacted Now</button>
                      </form>
                      <?php if (!$isArchived): ?>
                        <form method="post" action="/leads-dashboard.php" style="display:inline-block; margin:0;">
                          <input type="hidden" name="lead_action" value="archive_lead" />
                          <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
                          <button type="submit" class="btn-secondary" style="padding:0.35rem 0.6rem;" onclick="return confirm('Archive this lead?');">Archive</button>
                        </form>
                      <?php endif; ?>
                      <?php if (!$customerCreated && $hasMobile): ?>
                        <form method="post" action="/leads-dashboard.php" style="display:inline-block; margin:0;">
                          <input type="hidden" name="lead_action" value="create_customer_from_lead" />
                          <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
                          <button type="submit" class="btn-secondary" style="padding:0.35rem 0.6rem; background:#e0f2fe; color:#0f172a;" onclick="return confirm('Create customer from this lead?');">Create Customer</button>
                        </form>
                      <?php endif; ?>
                      <?php if (!$isConverted): ?>
                        <form method="post" style="margin:0;">
                          <input type="hidden" name="lead_action" value="mark_converted" />
                          <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
                          <button type="submit" class="btn" style="padding:0.35rem 0.6rem; background:#16a34a;" onclick="return confirm('Mark this lead as Converted?');">Converted</button>
                        </form>
                      <?php endif; ?>
                      <?php if (!$isNotInterested): ?>
                        <form method="post" style="margin:0;">
                          <input type="hidden" name="lead_action" value="mark_not_interested" />
                          <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
                          <button type="submit" class="btn-secondary" style="padding:0.35rem 0.6rem; background:#fbbf24; color:#1f2937;" onclick="return confirm('Mark this lead as Not Interested?');">Not Interested</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
