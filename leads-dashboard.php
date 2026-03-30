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

$homeUrl = '/admin-dashboard.php';
if ($loggedInEmployee && !$loggedInAdmin) {
    $homeUrl = '/employee-dashboard.php';
} elseif ($loggedInAdmin) {
    $homeUrl = '/admin-dashboard.php';
}

$employeeStore = new EmployeeFsStore();
$employees = $employeeStore->listEmployees();
$customerStore = new CustomerFsStore();
$quotationCreatePath = $loggedInEmployee && !$loggedInAdmin ? '/employee-quotations.php' : '/admin-quotations.php';

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

function leads_normalize_mobile(string $mobile): string
{
    return preg_replace('/\D+/', '', $mobile) ?? '';
}

function leads_parse_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function leads_parse_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function leads_value_or_dash(array $lead, string $key): string
{
    $value = trim((string) ($lead[$key] ?? ''));
    return $value === '' ? '—' : $value;
}

function leads_merge_lead_records(array $primary, array $secondary): array
{
    $merged = $primary;
    $booleanKeys = ['archived_flag', 'customer_created_flag', 'whatsapp_sent', 'email_sent', 'whatsapp_details_sent', 'email_details_sent'];

    foreach ($secondary as $key => $value) {
        if ($key === 'id') {
            continue;
        }
        if ($key === 'activity_log') {
            $mergedLogs = array_merge((array) ($merged['activity_log'] ?? []), (array) $value);
            $merged['activity_log'] = $mergedLogs;
            continue;
        }
        if (in_array($key, $booleanKeys, true)) {
            $merged[$key] = !empty($merged[$key]) || !empty($value);
            continue;
        }
        if (($merged[$key] ?? '') === '' && $value !== '') {
            $merged[$key] = $value;
        }
    }

    $primaryCreated = $primary['created_at'] ?? '';
    $secondaryCreated = $secondary['created_at'] ?? '';
    if ($secondaryCreated !== '') {
        if ($primaryCreated === '' || strtotime($secondaryCreated) < strtotime($primaryCreated)) {
            $merged['created_at'] = $secondaryCreated;
        }
    }

    $merged['updated_at'] = date('Y-m-d H:i:s');

    return $merged;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function leads_group_duplicate_mobiles(array $leads): array
{
    $groups = [];
    foreach ($leads as $lead) {
        $mobileKey = leads_normalize_mobile((string) ($lead['mobile'] ?? ''));
        if ($mobileKey === '') {
            continue;
        }
        if (!isset($groups[$mobileKey])) {
            $groups[$mobileKey] = [];
        }
        $groups[$mobileKey][] = $lead;
    }

    return array_filter($groups, static function (array $group): bool {
        return count($group) > 1;
    });
}

function leads_message_settings_path(): string
{
    return __DIR__ . '/data/leads/lead_message_settings.json';
}

function leads_message_settings_defaults(): array
{
    return [
        'default_whatsapp_message' => '',
        'default_email_subject' => '',
        'default_email_body' => '',
        'default_whatsapp_details_message' => '',
        'default_email_details_subject' => '',
        'default_email_details_body' => '',
        'details_page_url' => '/solar-details.php',
        'company_name' => 'Dakshayani Enterprises',
        'company_phone' => '',
        'updated_at' => '',
        'updated_by' => '',
    ];
}

function leads_load_message_settings(): array
{
    $defaults = leads_message_settings_defaults();
    $path = leads_message_settings_path();
    $legacyPath = __DIR__ . '/data/leads/lead_whatsapp_settings.json';
    if (!is_file($path) && is_file($legacyPath)) {
        $path = $legacyPath;
    }
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, [
        'default_whatsapp_message' => trim((string) ($decoded['default_whatsapp_message'] ?? '')),
        'default_email_subject' => trim((string) ($decoded['default_email_subject'] ?? '')),
        'default_email_body' => trim((string) ($decoded['default_email_body'] ?? '')),
        'default_whatsapp_details_message' => trim((string) ($decoded['default_whatsapp_details_message'] ?? '')),
        'default_email_details_subject' => trim((string) ($decoded['default_email_details_subject'] ?? '')),
        'default_email_details_body' => trim((string) ($decoded['default_email_details_body'] ?? '')),
        'details_page_url' => trim((string) ($decoded['details_page_url'] ?? '/solar-details.php')),
        'company_name' => trim((string) ($decoded['company_name'] ?? 'Dakshayani Enterprises')),
        'company_phone' => trim((string) ($decoded['company_phone'] ?? '')),
        'updated_at' => trim((string) ($decoded['updated_at'] ?? '')),
        'updated_by' => trim((string) ($decoded['updated_by'] ?? '')),
    ]);
}

function leads_save_message_settings(array $payload, string $updatedBy): bool
{
    $path = leads_message_settings_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $settingsPayload = [
        'default_whatsapp_message' => trim((string) ($payload['default_whatsapp_message'] ?? '')),
        'default_email_subject' => trim((string) ($payload['default_email_subject'] ?? '')),
        'default_email_body' => trim((string) ($payload['default_email_body'] ?? '')),
        'default_whatsapp_details_message' => trim((string) ($payload['default_whatsapp_details_message'] ?? '')),
        'default_email_details_subject' => trim((string) ($payload['default_email_details_subject'] ?? '')),
        'default_email_details_body' => trim((string) ($payload['default_email_details_body'] ?? '')),
        'details_page_url' => trim((string) ($payload['details_page_url'] ?? '/solar-details.php')),
        'company_name' => trim((string) ($payload['company_name'] ?? 'Dakshayani Enterprises')),
        'company_phone' => trim((string) ($payload['company_phone'] ?? '')),
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => trim($updatedBy) !== '' ? trim($updatedBy) : 'Admin',
    ];

    return file_put_contents($path, json_encode($settingsPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

function leads_explainer_content_path(): string
{
    return __DIR__ . '/data/leads/lead_explainer_content.json';
}

function leads_explainer_content_defaults(): array
{
    return [
        'page_title' => 'Solar Rooftop Details',
        'hero_intro' => 'Aasaan bhaasha mein samjhiye rooftop solar, PM Surya Ghar Yojana, on-grid vs hybrid aur pura installation process.',
        'what_is_solar_rooftop' => '',
        'pm_surya_ghar_text' => 'PM Surya Ghar: Muft Bijli Yojana ek residential-focused scheme hai jisme eligible gharon ko rooftop solar lagane par subsidy support mil sakta hai, policy aur eligibility ke hisaab se.',
        'who_is_eligible' => '',
        'on_grid_text' => 'On-grid system mein aapka solar system direct grid ke saath kaam karta hai. Din mein solar power use hoti hai, extra power grid mein jaa sakti hai, aur billing net-metering rules ke hisaab se hoti hai.',
        'hybrid_text' => 'Hybrid system mein solar ke saath battery backup hota hai. Isse light cut hone par bhi selected load chalaya ja sakta hai. Initial cost on-grid se thodi zyada hoti hai.',
        'which_one_is_suitable_for_whom' => '',
        'benefits' => '',
        'important_expectations' => '',
        'process_text' => "1) Site survey\n2) Load understanding & design\n3) Final proposal\n4) Installation\n5) Net-meter / testing\n6) Documentation & subsidy guidance (if applicable)",
        'faq_text' => "Q: Kitna bill kam ho sakta hai?\nA: Load, usage pattern, roof area aur system size par depend karta hai.\n\nQ: On-grid mein light chali gayi toh?\nA: Safety ke liye typical on-grid system blackout mein band hota hai.\n\nQ: Subsidy guaranteed hai?\nA: Nahi, subsidy policy, eligibility aur government process par depend karti hai.",
        'cta_text' => 'Apne ghar/business ke liye suitable solar option jaanne ke liye humse baat karein. Survey se quotation tak guided support milega.',
        'on_grid_image' => '',
        'hybrid_image' => '',
        'process_flow_image' => '',
        'benefits_image' => '',
        'updated_at' => '',
        'updated_by' => '',
    ];
}

function leads_load_explainer_content(): array
{
    $defaults = leads_explainer_content_defaults();
    $path = leads_explainer_content_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

function leads_save_explainer_content(array $payload, string $updatedBy): bool
{
    $defaults = leads_explainer_content_defaults();
    $path = leads_explainer_content_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $content = [];
    foreach ($defaults as $key => $value) {
        if (in_array($key, ['updated_at', 'updated_by'], true)) {
            continue;
        }
        $content[$key] = trim((string) ($payload[$key] ?? $value));
    }
    $content['updated_at'] = date('Y-m-d H:i:s');
    $content['updated_by'] = trim($updatedBy) !== '' ? trim($updatedBy) : 'Admin';

    return file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

function leads_build_sort_link(string $column, string $currentSortBy, string $currentSortDir): string
{
    $query = $_GET;
    $nextDir = 'asc';
    if ($currentSortBy === $column && $currentSortDir === 'asc') {
        $nextDir = 'desc';
    }
    $query['sort_by'] = $column;
    $query['sort_dir'] = $nextDir;
    return '/leads-dashboard.php?' . http_build_query($query);
}

function leads_sort_indicator(string $column, string $currentSortBy, string $currentSortDir): string
{
    if ($currentSortBy !== $column) {
        return '';
    }
    return $currentSortDir === 'desc' ? ' ↓' : ' ↑';
}

/**
 * @return array<int, string>
 */
function leads_standard_status_options(): array
{
    return [
        'Interested',
        'Site Visit Needed',
        'Quotation Sent',
        'Quotation required',
        'Converted',
        'Contacted',
        'Manual input',
    ];
}

function leads_resolve_status_submission(string $selectedStatus, string $customStatus): string
{
    if ($selectedStatus !== 'Manual input') {
        return $selectedStatus;
    }

    $customStatus = trim($customStatus);
    if ($customStatus !== '') {
        return $customStatus;
    }

    return 'Manual input';
}


function leads_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function leads_row_classes(array $lead, string $today): string
{
    $statusValue = strtolower(trim((string) ($lead['status'] ?? '')));
    $ratingValue = strtolower(trim((string) ($lead['rating'] ?? '')));
    $nextFollowupDate = trim((string) ($lead['next_followup_date'] ?? ''));
    $isConverted = $statusValue === 'converted' || strcasecmp((string) ($lead['converted_flag'] ?? ''), 'yes') === 0;
    $isNotInterested = $statusValue === 'not interested';
    if ($isConverted || $isNotInterested) {
        return '';
    }
    if ($nextFollowupDate !== '') {
        if ($nextFollowupDate < $today) {
            return 'lead-row-overdue';
        }
        if ($nextFollowupDate === $today) {
            return 'lead-row-today';
        }
        if ($ratingValue === 'hot') {
            return 'lead-row-hot';
        }
    } elseif ($statusValue === 'new') {
        return 'lead-row-new';
    }
    return '';
}

function leads_render_row(array $lead, int $index, string $today, string $quotationCreatePath): string
{
    $statusValue = strtolower(trim((string) ($lead['status'] ?? '')));
    $isConverted = $statusValue === 'converted' || strcasecmp((string) ($lead['converted_flag'] ?? ''), 'yes') === 0;
    $isNotInterested = $statusValue === 'not interested';
    $isArchived = !empty($lead['archived_flag']);
    $customerCreated = !empty($lead['customer_created_flag']);
    $hasMobile = trim((string) ($lead['mobile'] ?? '')) !== '' || trim((string) ($lead['alt_mobile'] ?? '')) !== '';
    $hasLeadName = trim((string) ($lead['name'] ?? '')) !== '';
    $leadMobileNormalized = normalize_customer_mobile((string) ($lead['mobile'] ?? ''));
    $leadMobileRaw = trim((string) ($lead['mobile'] ?? ''));
    if ($leadMobileRaw === '') {
        $leadMobileRaw = trim((string) ($lead['alt_mobile'] ?? ''));
    }
    if ($leadMobileNormalized === '') {
        $leadMobileNormalized = normalize_customer_mobile((string) ($lead['alt_mobile'] ?? ''));
    }
    $canCreateQuotation = !$isArchived && $hasLeadName && $leadMobileNormalized !== '';
    $callNotPickedCount = max(0, (int) ($lead['call_not_picked_count'] ?? 0));
    $whatsappSent = !empty($lead['whatsapp_sent']);
    $emailSent = !empty($lead['email_sent']);
    $whatsappDetailsSent = !empty($lead['whatsapp_details_sent']);
    $emailDetailsSent = !empty($lead['email_details_sent']);
    $rowClass = leads_row_classes($lead, $today);
    ob_start();
    ?>
    <tr id="lead-row-<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>"
        data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>"
        data-name="<?php echo leads_safe((string) ($lead['name'] ?? '')); ?>"
        data-mobile="<?php echo leads_safe($leadMobileRaw); ?>"
        data-email="<?php echo leads_safe((string) ($lead['email'] ?? '')); ?>"
        data-city="<?php echo leads_safe((string) ($lead['city'] ?? '')); ?>"
        data-assigned-to="<?php echo leads_safe((string) ($lead['assigned_to_name'] ?? '')); ?>"
        class="<?php echo leads_safe($rowClass); ?>">
      <td>
        <input type="checkbox" class="lead-select" name="lead_ids[]" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" form="bulk-actions-form" />
      </td>
      <td class="lead-index"><?php echo $index; ?></td>
      <td><?php echo leads_safe((string) ($lead['name'] ?? '')); ?></td>
      <td><a href="tel:<?php echo leads_safe($leadMobileRaw); ?>"><?php echo leads_safe($leadMobileRaw); ?></a></td>
      <td><?php echo leads_safe((string) ($lead['city'] ?? '')); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'monthly_bill')); ?></td>
      <td><span class="badge pill admin-chip--soft"><?php echo leads_safe((string) ($lead['status'] ?? '')); ?></span></td>
      <td><?php echo leads_safe(trim(((string) ($lead['next_followup_date'] ?? '')) . ' ' . ((string) ($lead['next_followup_time'] ?? '')))); ?></td>
      <td><?php echo leads_safe((string) ($lead['last_contacted_at'] ?? '')); ?></td>
      <td class="lead-message-status-cell">
        <?php if ($whatsappSent): ?>
          <span class="badge pill admin-chip--success">WA Intro</span>
        <?php endif; ?>
        <?php if ($emailSent): ?>
          <span class="badge pill admin-chip--soft">Email Intro</span>
        <?php endif; ?>
        <?php if ($whatsappDetailsSent): ?>
          <span class="badge pill admin-chip--soft">WA Details</span>
        <?php endif; ?>
        <?php if ($emailDetailsSent): ?>
          <span class="badge pill admin-chip--danger">Email Details</span>
        <?php endif; ?>
        <?php if (!$whatsappSent && !$emailSent && !$whatsappDetailsSent && !$emailDetailsSent): ?>
          <span style="color:#9ca3af;">&mdash;</span>
        <?php endif; ?>
      </td>
      <td><?php echo leads_safe((string) $callNotPickedCount); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'best_time_to_call')); ?></td>
      <td>
        <div class="table-actions">
          <a class="btn-secondary lead-action action-btn primary-action" data-action="whatsapp" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="#">WhatsApp</a>
          <button type="button" class="btn lead-action action-btn success-action" data-action="mark_contacted" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Mark Contacted</button>
          <button type="button" class="btn lead-action action-btn primary-action" data-action="mark_interested" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Interested</button>
          <button type="button" class="btn-secondary lead-action action-btn warning-action" data-action="mark_not_interested" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" <?php echo $isNotInterested ? 'disabled title="Already marked Not Interested"' : ''; ?>>Not Interested</button>
          <button type="button" class="btn-secondary lead-action action-btn danger-action" data-action="call_not_picked" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Call not Picked</button>
          <div class="action-more">
            <button type="button" class="btn-secondary action-btn more-toggle">More ▾</button>
            <div class="action-more-menu">
              <a class="lead-action action-more-item" data-action="view_edit" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="lead-detail.php?id=<?php echo urlencode((string) ($lead['id'] ?? '')); ?>">View / Edit</a>
              <a class="lead-action action-more-item" data-action="email" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="#">Email</a>
              <a class="lead-action action-more-item" data-action="whatsapp_details" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="#">WhatsApp Details</a>
              <a class="lead-action action-more-item" data-action="email_details" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="#">Email Details</a>
              <?php if ($canCreateQuotation): ?>
                <a class="lead-action action-more-item" data-action="create_quotation" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" href="<?php echo leads_safe($quotationCreatePath . '?action=create&from_lead_id=' . urlencode((string) ($lead['id'] ?? ''))); ?>">Create Quotation</a>
              <?php else: ?>
                <?php $quotationDisabledReason = $isArchived ? 'Archived lead' : 'Missing name/mobile'; ?>
                <button type="button" class="action-more-item" disabled title="<?php echo leads_safe($quotationDisabledReason); ?>">Create Quotation</button>
              <?php endif; ?>
              <?php if (!$isArchived): ?>
                <button type="button" class="lead-action action-more-item" data-action="archive_lead" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Archive</button>
              <?php else: ?>
                <button type="button" class="action-more-item" disabled title="Already archived">Archive</button>
              <?php endif; ?>
              <?php if (!$customerCreated && $hasMobile): ?>
                <button type="button" class="lead-action action-more-item" data-action="create_customer" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Create Customer</button>
              <?php else: ?>
                <button type="button" class="action-more-item" disabled title="<?php echo $customerCreated ? 'Customer already created' : 'Missing mobile number'; ?>">Create Customer</button>
              <?php endif; ?>
              <?php if (!$isConverted): ?>
                <button type="button" class="lead-action action-more-item" data-action="mark_converted" data-lead-id="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>">Converted</button>
              <?php else: ?>
                <button type="button" class="action-more-item" disabled title="Already converted">Converted</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'email')); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'finance_subsidy')); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'property_type')); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'roof_type')); ?></td>
      <td><?php echo leads_safe(leads_value_or_dash($lead, 'area_pincode')); ?></td>
      <td><?php echo leads_safe((string) ($lead['rating'] ?? '')); ?></td>
      <td><?php echo leads_safe((string) ($lead['assigned_to_name'] ?? '')); ?></td>
      <td><?php echo leads_safe((string) ($lead['created_at'] ?? '')); ?></td>
      <td><?php echo leads_safe((string) ($lead['updated_at'] ?? '')); ?></td>
      <td>
        <?php if (($lead['source_campaign_name'] ?? '') !== ''): ?>
          <?php echo leads_safe((string) ($lead['source_campaign_name'] ?? '')); ?>
          <?php if (($lead['source_campaign_id'] ?? '') !== ''): ?>
            <span class="badge" style="background:#e2e8f0;color:#0f172a;">#<?php echo leads_safe((string) ($lead['source_campaign_id'] ?? '')); ?></span>
          <?php endif; ?>
        <?php else: ?>&ndash;<?php endif; ?>
      </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

$messages = [];
if (isset($_GET['msg']) && trim((string) $_GET['msg']) !== '') {
    $messages[] = ['type' => 'success', 'text' => (string) $_GET['msg']];
}

$today = date('Y-m-d');

$isAjaxRequest = (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
if ($isAjaxRequest) {
    $ajaxAction = (string) ($_REQUEST['ajax_action'] ?? '');
    $leadId = (string) ($_REQUEST['lead_id'] ?? '');
    if ($ajaxAction === 'fetch_lead_form') {
        $lead = find_lead_by_id($leadId);
        if ($lead === null) {
            leads_json_response(['success' => false, 'message' => 'Lead not found.'], 404);
        }
        $savedStatus = trim((string) ($lead['status'] ?? ''));
        $statusOptions = leads_standard_status_options();
        $selectStatusValue = '';
        $customStatusValue = '';
        if ($savedStatus !== '') {
            if (in_array($savedStatus, $statusOptions, true)) {
                $selectStatusValue = $savedStatus;
            } else {
                $selectStatusValue = 'Manual input';
                $customStatusValue = $savedStatus;
            }
        }
        $showCustomStatusInput = $selectStatusValue === 'Manual input';
        ob_start();
        ?>
        <form id="lead-edit-form" class="grid" style="gap:0.75rem;">
          <input type="hidden" name="lead_id" value="<?php echo leads_safe((string) ($lead['id'] ?? '')); ?>" />
          <label>Name <input type="text" name="name" value="<?php echo leads_safe((string) ($lead['name'] ?? '')); ?>" required></label>
          <label>Mobile <input type="text" name="mobile" value="<?php echo leads_safe((string) ($lead['mobile'] ?? '')); ?>"></label>
          <label>Email <input type="email" name="email" value="<?php echo leads_safe((string) ($lead['email'] ?? '')); ?>"></label>
          <label>City <input type="text" name="city" value="<?php echo leads_safe((string) ($lead['city'] ?? '')); ?>"></label>
          <label>Monthly Bill <input type="text" name="monthly_bill" value="<?php echo leads_safe((string) ($lead['monthly_bill'] ?? '')); ?>"></label>
          <label>Finance &amp; Subsidy <input type="text" name="finance_subsidy" value="<?php echo leads_safe((string) ($lead['finance_subsidy'] ?? '')); ?>"></label>
          <label>Property Type <input type="text" name="property_type" value="<?php echo leads_safe((string) ($lead['property_type'] ?? '')); ?>"></label>
          <label>Roof Type <input type="text" name="roof_type" value="<?php echo leads_safe((string) ($lead['roof_type'] ?? '')); ?>"></label>
          <label>Best Time to Call <input type="text" name="best_time_to_call" value="<?php echo leads_safe((string) ($lead['best_time_to_call'] ?? '')); ?>"></label>
          <label>Area Pincode <input type="text" name="area_pincode" value="<?php echo leads_safe((string) ($lead['area_pincode'] ?? '')); ?>"></label>
          <label>Status
            <select name="status" id="lead-status-select">
              <option value="" <?php echo $selectStatusValue === '' ? 'selected' : ''; ?>>-- Select status --</option>
              <?php foreach ($statusOptions as $statusOption): ?>
                <option value="<?php echo leads_safe($statusOption); ?>" <?php echo $selectStatusValue === $statusOption ? 'selected' : ''; ?>><?php echo leads_safe($statusOption); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label id="lead-status-custom-wrapper" style="<?php echo $showCustomStatusInput ? '' : 'display:none;'; ?>">Custom status <input type="text" name="custom_status" id="lead-status-custom-input" value="<?php echo leads_safe($customStatusValue); ?>"></label>
          <label>Rating <input type="text" name="rating" value="<?php echo leads_safe((string) ($lead['rating'] ?? '')); ?>"></label>
          <label>Next Follow-up Date <input type="date" name="next_followup_date" value="<?php echo leads_safe((string) ($lead['next_followup_date'] ?? '')); ?>"></label>
          <label>Notes <input type="text" name="notes" value="<?php echo leads_safe((string) ($lead['notes'] ?? '')); ?>"></label>
          <div style="display:flex;justify-content:flex-end;"><button type="submit" class="btn">Save Changes</button></div>
        </form>
        <?php
        leads_json_response(['success' => true, 'html' => (string) ob_get_clean(), 'lead' => $lead]);
    }

    $lead = find_lead_by_id($leadId);
    if ($lead === null) {
        leads_json_response(['success' => false, 'message' => 'Lead not found.'], 404);
    }

    $updates = [];
    $message = 'Updated.';
    $removeRow = false;
    if ($ajaxAction === 'save_lead') {
        $updates = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'mobile' => trim((string) ($_POST['mobile'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'monthly_bill' => trim((string) ($_POST['monthly_bill'] ?? '')),
            'finance_subsidy' => trim((string) ($_POST['finance_subsidy'] ?? '')),
            'property_type' => trim((string) ($_POST['property_type'] ?? '')),
            'roof_type' => trim((string) ($_POST['roof_type'] ?? '')),
            'best_time_to_call' => trim((string) ($_POST['best_time_to_call'] ?? '')),
            'area_pincode' => trim((string) ($_POST['area_pincode'] ?? '')),
            'status' => leads_resolve_status_submission(
                trim((string) ($_POST['status'] ?? '')),
                trim((string) ($_POST['custom_status'] ?? ''))
            ),
            'rating' => trim((string) ($_POST['rating'] ?? '')),
            'next_followup_date' => trim((string) ($_POST['next_followup_date'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $message = 'Lead updated successfully.';
    } elseif ($ajaxAction === 'mark_contacted') {
        $updates = ['last_contacted_at' => date('Y-m-d H:i:s'), 'status' => 'Contacted'];
        if (trim((string) ($lead['next_followup_date'] ?? '')) === '') {
            $updates['next_followup_date'] = date('Y-m-d', strtotime('+3 days'));
        }
        $message = 'Lead marked as contacted.';
    } elseif ($ajaxAction === 'mark_interested') {
        $updates = ['status' => 'Interested'];
        $message = 'Lead marked as interested.';
    } elseif ($ajaxAction === 'archive_lead') {
        $updates = ['archived_flag' => true, 'archived_at' => (string) (($lead['archived_at'] ?? '') !== '' ? $lead['archived_at'] : date('Y-m-d H:i:s'))];
        $message = 'Lead archived.';
        $removeRow = true;
    } elseif ($ajaxAction === 'call_not_picked') {
        $actor = leads_actor_details();
        $updates = [
            'call_not_picked_count' => max(0, (int) ($lead['call_not_picked_count'] ?? 0)) + 1,
            'last_call_not_picked_at' => date('Y-m-d H:i:s'),
            'last_call_not_picked_by' => trim((string) ($actor['name'] ?? '')) !== '' ? (string) $actor['name'] : 'Unknown',
        ];
        $message = 'Call-not-picked count updated.';
    } elseif ($ajaxAction === 'create_customer_from_lead') {
        $customerResult = leads_create_customer_from_lead($customerStore, $lead);
        if (($customerResult['mobile'] ?? '') !== '') {
            $updates = ['customer_created_flag' => true, 'customer_mobile_link' => $customerResult['mobile']];
            if (isset($customerResult['customer']['serial_number'])) {
                $updates['customer_id_link'] = (string) $customerResult['customer']['serial_number'];
            }
            $message = ($customerResult['existing'] ?? false) ? 'Existing customer linked to lead.' : 'Customer created from lead.';
        }
    } elseif ($ajaxAction === 'mark_converted') {
        $customerResult = leads_create_customer_from_lead($customerStore, $lead);
        $updates = [
            'status' => 'Converted',
            'converted_flag' => 'Yes',
            'converted_date' => date('Y-m-d'),
            'archived_flag' => true,
            'archived_at' => date('Y-m-d H:i:s'),
        ];
        if (($customerResult['mobile'] ?? '') !== '') {
            $updates['customer_created_flag'] = true;
            $updates['customer_mobile_link'] = $customerResult['mobile'];
        }
        $message = 'Lead marked as converted.';
        $removeRow = true;
    } elseif ($ajaxAction === 'mark_not_interested') {
        $updates = ['status' => 'Not Interested', 'converted_flag' => 'No', 'not_interested_reason' => trim((string) ($_POST['reason'] ?? (string) ($lead['not_interested_reason'] ?? '')))];
        $message = 'Lead marked as not interested.';
    } elseif ($ajaxAction === 'mark_quotation_sent') {
        $updates = ['status' => 'Quotation Sent'];
        $message = 'Lead marked as quotation sent.';
    } elseif ($ajaxAction === 'mark_whatsapp_sent') {
        $actor = leads_actor_details();
        $updates = [
            'whatsapp_sent' => true,
            'whatsapp_sent_at' => date('Y-m-d H:i:s'),
            'whatsapp_sent_by' => trim((string) ($actor['name'] ?? '')) !== '' ? trim((string) ($actor['name'] ?? '')) : trim((string) ($actor['id'] ?? '')),
            'last_message_channel' => 'whatsapp',
            'last_message_type' => 'initial_whatsapp',
        ];
        $message = 'WhatsApp send attempt marked.';
    } elseif ($ajaxAction === 'mark_email_sent') {
        $actor = leads_actor_details();
        $updates = [
            'email_sent' => true,
            'email_sent_at' => date('Y-m-d H:i:s'),
            'email_sent_by' => trim((string) ($actor['name'] ?? '')) !== '' ? trim((string) ($actor['name'] ?? '')) : trim((string) ($actor['id'] ?? '')),
            'last_message_channel' => 'email',
            'last_message_type' => 'initial_email',
        ];
        $message = 'Email draft open marked.';
    } elseif ($ajaxAction === 'mark_whatsapp_details_sent') {
        $actor = leads_actor_details();
        $updates = [
            'whatsapp_details_sent' => true,
            'whatsapp_details_sent_at' => date('Y-m-d H:i:s'),
            'whatsapp_details_sent_by' => trim((string) ($actor['name'] ?? '')) !== '' ? trim((string) ($actor['name'] ?? '')) : trim((string) ($actor['id'] ?? '')),
            'last_message_channel' => 'whatsapp',
            'last_message_type' => 'details_whatsapp',
        ];
        $message = 'Detailed WhatsApp send attempt marked.';
    } elseif ($ajaxAction === 'mark_email_details_sent') {
        $actor = leads_actor_details();
        $updates = [
            'email_details_sent' => true,
            'email_details_sent_at' => date('Y-m-d H:i:s'),
            'email_details_sent_by' => trim((string) ($actor['name'] ?? '')) !== '' ? trim((string) ($actor['name'] ?? '')) : trim((string) ($actor['id'] ?? '')),
            'last_message_channel' => 'email',
            'last_message_type' => 'details_email',
        ];
        $message = 'Detailed email draft open marked.';
    }

    if ($updates === []) {
        leads_json_response(['success' => false, 'message' => 'No updates were applied.'], 422);
    }

    $result = update_lead($leadId, $updates);
    if ($result === null) {
        leads_json_response(['success' => false, 'message' => 'Unable to update lead.'], 500);
    }
    $updatedLead = $result['after'];
    $rowIndex = (int) ($_REQUEST['row_index'] ?? 1);
    leads_json_response([
        'success' => true,
        'message' => $message,
        'lead_id' => $leadId,
        'remove_row' => $removeRow,
        'row_html' => leads_render_row($updatedLead, max(1, $rowIndex), $today, $quotationCreatePath),
    ]);
}

$downloadSampleCsv = isset($_GET['download']) && $_GET['download'] === 'lead_sample_csv';
if ($downloadSampleCsv) {
    $headers = ['#', 'name', 'mobile', 'email', 'city', 'area_pincode', 'monthly_bill', 'finance_subsidy', 'property_type', 'roof_type', 'best_time_to_call', 'status', 'rating', 'next follow-up', 'assigned to', 'last contacted', 'campaign', 'actions'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-import-sample.csv"');
    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fputcsv($output, $headers);
        fclose($output);
    }
    exit;
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
                    header('Location: leads-dashboard.php?section=leads&msg=' . urlencode($msg));
                    exit;
                }

                $messages[] = ['type' => 'error', 'text' => 'Could not update lead.'];
            }
        }
    }

    $intent = isset($_POST['intent']) ? (string) $_POST['intent'] : '';

    if ($intent === 'save_message_settings' || $intent === 'save_whatsapp_settings') {
        if (!$loggedInAdmin) {
            $messages[] = ['type' => 'error', 'text' => 'Only admin can update messaging draft settings.'];
        } else {
            $actorDetails = leads_actor_details();
            $settingsPayload = [
                'default_whatsapp_message' => trim((string) ($_POST['default_whatsapp_message'] ?? '')),
                'default_email_subject' => trim((string) ($_POST['default_email_subject'] ?? '')),
                'default_email_body' => trim((string) ($_POST['default_email_body'] ?? '')),
                'default_whatsapp_details_message' => trim((string) ($_POST['default_whatsapp_details_message'] ?? '')),
                'default_email_details_subject' => trim((string) ($_POST['default_email_details_subject'] ?? '')),
                'default_email_details_body' => trim((string) ($_POST['default_email_details_body'] ?? '')),
                'details_page_url' => trim((string) ($_POST['details_page_url'] ?? '/solar-details.php')),
                'company_name' => trim((string) ($_POST['company_name'] ?? 'Dakshayani Enterprises')),
                'company_phone' => trim((string) ($_POST['company_phone'] ?? '')),
            ];
            if (leads_save_message_settings($settingsPayload, (string) ($actorDetails['name'] ?? 'Admin'))) {
                $messages[] = ['type' => 'success', 'text' => 'Messaging templates saved.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Unable to save messaging templates.'];
            }
        }
    } elseif ($intent === 'save_explainer_content') {
        if (!$loggedInAdmin) {
            $messages[] = ['type' => 'error', 'text' => 'Only admin can update explainer page content.'];
        } else {
            $actorDetails = leads_actor_details();
            $explainerPayload = [
                'page_title' => trim((string) ($_POST['page_title'] ?? '')),
                'hero_intro' => trim((string) ($_POST['hero_intro'] ?? '')),
                'what_is_solar_rooftop' => trim((string) ($_POST['what_is_solar_rooftop'] ?? '')),
                'pm_surya_ghar_text' => trim((string) ($_POST['pm_surya_ghar_text'] ?? '')),
                'who_is_eligible' => trim((string) ($_POST['who_is_eligible'] ?? '')),
                'on_grid_text' => trim((string) ($_POST['on_grid_text'] ?? '')),
                'hybrid_text' => trim((string) ($_POST['hybrid_text'] ?? '')),
                'which_one_is_suitable_for_whom' => trim((string) ($_POST['which_one_is_suitable_for_whom'] ?? '')),
                'benefits' => trim((string) ($_POST['benefits'] ?? '')),
                'important_expectations' => trim((string) ($_POST['important_expectations'] ?? '')),
                'process_text' => trim((string) ($_POST['process_text'] ?? '')),
                'faq_text' => trim((string) ($_POST['faq_text'] ?? '')),
                'cta_text' => trim((string) ($_POST['cta_text'] ?? '')),
                'on_grid_image' => trim((string) ($_POST['on_grid_image'] ?? '')),
                'hybrid_image' => trim((string) ($_POST['hybrid_image'] ?? '')),
                'process_flow_image' => trim((string) ($_POST['process_flow_image'] ?? '')),
                'benefits_image' => trim((string) ($_POST['benefits_image'] ?? '')),
            ];
            if (leads_save_explainer_content($explainerPayload, (string) ($actorDetails['name'] ?? 'Admin'))) {
                $messages[] = ['type' => 'success', 'text' => 'Explainer content saved.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Unable to save explainer content.'];
            }
        }
    } elseif ($intent === 'bulk_action') {
        $bulkAction = isset($_POST['bulk_action']) ? (string) $_POST['bulk_action'] : '';
        $selectedIds = $_POST['lead_ids'] ?? [];
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }
        $selectedIds = array_values(array_filter(array_map('strval', $selectedIds)));

        if ($bulkAction === '' || $selectedIds === []) {
            $messages[] = ['type' => 'error', 'text' => 'Select at least one lead and an action to apply.'];
        } else {
            $updatedCount = 0;
            foreach ($selectedIds as $leadId) {
                $existingLead = find_lead_by_id($leadId);
                if ($existingLead === null) {
                    continue;
                }
                $updates = [];
                if ($bulkAction === 'status_contacted') {
                    $updates = ['status' => 'Contacted'];
                } elseif ($bulkAction === 'status_quotation_sent') {
                    $updates = ['status' => 'Quotation Sent'];
                } elseif ($bulkAction === 'status_not_interested') {
                    $updates = [
                        'status' => 'Not Interested',
                        'converted_flag' => 'No',
                        'not_interested_reason' => (string) ($existingLead['not_interested_reason'] ?? ''),
                    ];
                } elseif ($bulkAction === 'archive') {
                    $updates = [
                        'archived_flag' => true,
                        'archived_at' => (string) (($existingLead['archived_at'] ?? '') !== '' ? $existingLead['archived_at'] : date('Y-m-d H:i:s')),
                    ];
                } elseif ($bulkAction === 'convert') {
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
                } elseif ($bulkAction === 'mark_contacted_now') {
                    $updates = [
                        'last_contacted_at' => date('Y-m-d H:i:s'),
                        'status' => 'Contacted',
                    ];
                    if (trim((string) ($existingLead['next_followup_date'] ?? '')) === '') {
                        $updates['next_followup_date'] = date('Y-m-d', strtotime('+3 days'));
                    }
                }

                if ($updates !== []) {
                    $result = update_lead($leadId, $updates);
                    if ($result !== null) {
                        $updatedCount++;
                    }
                }
            }

            if ($updatedCount > 0) {
                $messages[] = ['type' => 'success', 'text' => 'Updated ' . $updatedCount . ' lead(s).'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'No leads were updated.'];
            }
        }
    } elseif ($intent === 'quick_add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $monthlyBill = trim((string) ($_POST['monthly_bill'] ?? ''));
        $financeSubsidy = trim((string) ($_POST['finance_subsidy'] ?? ''));
        $propertyType = trim((string) ($_POST['property_type'] ?? ''));
        $roofType = trim((string) ($_POST['roof_type'] ?? ''));
        $bestTimeToCall = trim((string) ($_POST['best_time_to_call'] ?? ''));
        $areaPincode = trim((string) ($_POST['area_pincode'] ?? ''));
        $leadSource = trim((string) ($_POST['lead_source'] ?? 'Incoming Call'));
        $interestType = trim((string) ($_POST['interest_type'] ?? ''));

        if ($name === '' || $mobile === '') {
            $messages[] = ['type' => 'error', 'text' => 'Name and mobile are required to add a lead.'];
        } else {
            $actorDetails = leads_actor_details();
            $record = add_lead([
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'city' => $city,
                'monthly_bill' => $monthlyBill,
                'finance_subsidy' => $financeSubsidy,
                'property_type' => $propertyType,
                'roof_type' => $roofType,
                'best_time_to_call' => $bestTimeToCall,
                'area_pincode' => $areaPincode,
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
                    'status' => 'Contacted',
                ];
                if (trim((string) ($existing['next_followup_date'] ?? '')) === '') {
                    $updates['next_followup_date'] = date('Y-m-d', strtotime('+3 days'));
                }
                update_lead($leadId, $updates);
                $messages[] = ['type' => 'success', 'text' => 'Lead marked as contacted.'];
            }
        }
    } elseif ($intent === 'import_csv') {
        if (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            $messages[] = ['type' => 'error', 'text' => 'Please upload a CSV file.'];
        } elseif (($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $messages[] = ['type' => 'error', 'text' => 'CSV upload failed. Please try again.'];
        } else {
            $tmpName = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            $handle = fopen($tmpName, 'r');
            if ($handle === false) {
                $messages[] = ['type' => 'error', 'text' => 'Unable to read the uploaded CSV.'];
            } else {
                $actorDetails = leads_actor_details();
                $rowIndex = 0;
                $headers = [];
                $imported = 0;
                $skipped = 0;
                $defaultHeader = ['#', 'name', 'mobile', 'email', 'city', 'area_pincode', 'monthly_bill', 'finance_subsidy', 'property_type', 'roof_type', 'best_time_to_call', 'status', 'rating', 'next follow-up', 'assigned to', 'last contacted', 'campaign', 'actions'];
                while (($row = fgetcsv($handle)) !== false) {
                    $rowIndex++;
                    if ($rowIndex === 1) {
                        $normalized = array_map(static function (string $header): string {
                            return strtolower(trim($header));
                        }, $row);
                        if (in_array('name', $normalized, true) || in_array('mobile', $normalized, true)) {
                            $headers = $normalized;
                            continue;
                        }
                        $headers = $defaultHeader;
                    }

                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        $rowData[$header] = $row[$index] ?? '';
                    }
                    $lookup = static function (array $rowData, array $keys): string {
                        foreach ($keys as $key) {
                            if (array_key_exists($key, $rowData)) {
                                return trim((string) $rowData[$key]);
                            }
                        }
                        return '';
                    };

                    $mobile = $lookup($rowData, ['mobile']);
                    $name = $lookup($rowData, ['name']);
                    if ($mobile === '' && $name === '') {
                        $skipped++;
                        continue;
                    }

                    $status = $lookup($rowData, ['status']);
                    $rating = $lookup($rowData, ['rating']);
                    $assignedTo = $lookup($rowData, ['assigned to', 'assigned_to']);

                    $leadRecord = [
                        'name' => $name,
                        'mobile' => $mobile,
                        'email' => $lookup($rowData, ['email']),
                        'city' => $lookup($rowData, ['city']),
                        'area_pincode' => $lookup($rowData, ['area_pincode', 'area pincode']),
                        'monthly_bill' => $lookup($rowData, ['monthly_bill', 'monthly bill']),
                        'finance_subsidy' => $lookup($rowData, ['finance_subsidy', 'finance & subsidy', 'finance and subsidy']),
                        'property_type' => $lookup($rowData, ['property_type', 'property type']),
                        'roof_type' => $lookup($rowData, ['roof_type', 'roof type']),
                        'best_time_to_call' => $lookup($rowData, ['best_time_to_call', 'best time to call']),
                        'status' => $status !== '' ? $status : 'New',
                        'rating' => $rating !== '' ? $rating : 'Warm',
                        'next_followup_date' => leads_parse_date($lookup($rowData, ['next follow-up', 'next_followup', 'next_followup_date'])),
                        'assigned_to_name' => $assignedTo !== '' ? $assignedTo : $actorDetails['name'],
                        'assigned_to_type' => $actorDetails['type'],
                        'assigned_to_id' => $actorDetails['id'],
                        'last_contacted_at' => leads_parse_datetime($lookup($rowData, ['last contacted', 'last_contacted_at'])),
                        'source_campaign_name' => $lookup($rowData, ['campaign']),
                        'lead_source' => 'CSV Import',
                    ];

                    add_lead($leadRecord);
                    $imported++;
                }

                fclose($handle);
                $messages[] = ['type' => 'success', 'text' => 'Imported ' . $imported . ' lead(s). Skipped ' . $skipped . ' empty row(s).'];
            }
        }
    } elseif ($intent === 'merge_duplicates') {
        $mobileKey = leads_normalize_mobile((string) ($_POST['mobile_key'] ?? ''));
        if ($mobileKey !== '') {
            $leads = load_all_leads();
            $groups = leads_group_duplicate_mobiles($leads);
            if (!isset($groups[$mobileKey])) {
                $messages[] = ['type' => 'error', 'text' => 'No duplicate leads found for that mobile number.'];
            } else {
                $group = $groups[$mobileKey];
                usort($group, static function (array $a, array $b): int {
                    return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
                });

                $primary = $group[0];
                $mergedIds = [];
                foreach (array_slice($group, 1) as $duplicate) {
                    $primary = leads_merge_lead_records($primary, $duplicate);
                    $mergedIds[] = (string) ($duplicate['id'] ?? '');
                }

                $updatedLeads = [];
                foreach ($leads as $lead) {
                    $leadId = (string) ($lead['id'] ?? '');
                    if ($leadId === (string) ($primary['id'] ?? '')) {
                        $updatedLeads[] = $primary;
                        continue;
                    }
                    if (!in_array($leadId, $mergedIds, true)) {
                        $updatedLeads[] = $lead;
                    }
                }

                save_all_leads($updatedLeads);

                $actor = audit_current_actor();
                log_audit_event($actor['actor_type'], (string) $actor['actor_id'], 'lead', (string) ($primary['id'] ?? ''), 'lead_merge', [
                    'mobile' => $mobileKey,
                    'merged_ids' => $mergedIds,
                ]);

                header('Location: leads-dashboard.php?section=leads&msg=' . urlencode('Merged ' . count($mergedIds) . ' duplicate lead(s) for ' . $mobileKey . '.'));
                exit;
            }
        }
    }
}

$leads = load_all_leads();
$messageSettings = leads_load_message_settings();
$explainerContent = leads_load_explainer_content();

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
$sortBy = strtolower(trim((string) ($_GET['sort_by'] ?? 'created_at')));
$sortDir = strtolower(trim((string) ($_GET['sort_dir'] ?? 'desc')));
$allowedSort = ['sr_no', 'name', 'mobile', 'email', 'city', 'monthly_bill', 'finance_subsidy', 'property_type', 'roof_type', 'best_time_to_call', 'area_pincode', 'status', 'rating', 'next_followup', 'assigned_to', 'last_contacted_at', 'call_not_picked_count', 'created_at', 'updated_at'];
if (!in_array($sortBy, $allowedSort, true)) {
    $sortBy = 'created_at';
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}
$dashboardSections = ['leads', 'quick-add', 'import', 'settings'];
$activeSection = (string) ($_GET['section'] ?? ($_POST['current_section'] ?? 'leads'));
if (!in_array($activeSection, $dashboardSections, true)) {
    $activeSection = 'leads';
}
if ($activeSection === 'settings' && !$loggedInAdmin) {
    $activeSection = 'leads';
}

$today = date('Y-m-d');
$filteredLeads = array_values(array_filter($leads, function (array $lead) use ($searchTerm, $statusFilter, $ratingFilter, $assignedFilter, $followupToday, $followupOverdue, $today, $view): bool {
    $matchesSearch = true;
    if ($searchTerm !== '') {
        $haystacks = [
            strtolower((string) ($lead['name'] ?? '')),
            strtolower((string) ($lead['mobile'] ?? '')),
            strtolower((string) ($lead['email'] ?? '')),
            strtolower((string) ($lead['city'] ?? '')),
            strtolower((string) ($lead['monthly_bill'] ?? '')),
            strtolower((string) ($lead['finance_subsidy'] ?? '')),
            strtolower((string) ($lead['property_type'] ?? '')),
            strtolower((string) ($lead['roof_type'] ?? '')),
            strtolower((string) ($lead['best_time_to_call'] ?? '')),
            strtolower((string) ($lead['area_pincode'] ?? '')),
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

usort($filteredLeads, static function (array $a, array $b) use ($sortBy, $sortDir): int {
    $direction = $sortDir === 'desc' ? -1 : 1;
    $indexA = (int) ($a['id'] ?? 0);
    $indexB = (int) ($b['id'] ?? 0);
    $fallbackCompare = $indexA <=> $indexB;

    $isEmpty = static function (string $value): bool {
        return trim($value) === '' || trim($value) === '—';
    };

    $compareText = static function (string $left, string $right) use ($direction, $isEmpty, $fallbackCompare): int {
        $left = trim($left);
        $right = trim($right);
        $leftEmpty = $isEmpty($left);
        $rightEmpty = $isEmpty($right);
        if ($leftEmpty && $rightEmpty) {
            return $fallbackCompare;
        }
        if ($leftEmpty) {
            return 1;
        }
        if ($rightEmpty) {
            return -1;
        }
        $comparison = strnatcasecmp($left, $right);
        if ($comparison === 0) {
            return $fallbackCompare;
        }
        return $comparison * $direction;
    };

    $parseNumber = static function (string $value): ?float {
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
        if ($cleaned === '' || $cleaned === '-' || $cleaned === '.' || $cleaned === '-.') {
            return null;
        }
        if (!is_numeric($cleaned)) {
            return null;
        }
        return (float) $cleaned;
    };

    $compareNumber = static function (string $left, string $right) use ($direction, $fallbackCompare, $parseNumber): int {
        $leftNumber = $parseNumber($left);
        $rightNumber = $parseNumber($right);
        if ($leftNumber === null && $rightNumber === null) {
            return $fallbackCompare;
        }
        if ($leftNumber === null) {
            return 1;
        }
        if ($rightNumber === null) {
            return -1;
        }
        $comparison = $leftNumber <=> $rightNumber;
        if ($comparison === 0) {
            return $fallbackCompare;
        }
        return $comparison * $direction;
    };

    $parseTimestamp = static function (string $date, string $time = ''): ?int {
        $value = trim($date . ' ' . $time);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return $timestamp;
    };

    $compareTimestamp = static function (?int $left, ?int $right) use ($direction, $fallbackCompare): int {
        if ($left === null && $right === null) {
            return $fallbackCompare;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }
        $comparison = $left <=> $right;
        if ($comparison === 0) {
            return $fallbackCompare;
        }
        return $comparison * $direction;
    };

    if ($sortBy === 'sr_no') {
        return ($indexA <=> $indexB) * $direction;
    }

    if ($sortBy === 'name') {
        return $compareText((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    }
    if ($sortBy === 'mobile') {
        return $compareText((string) ($a['mobile'] ?? ''), (string) ($b['mobile'] ?? ''));
    }
    if ($sortBy === 'city') {
        return $compareText((string) ($a['city'] ?? ''), (string) ($b['city'] ?? ''));
    }
    if ($sortBy === 'email') {
        return $compareText((string) ($a['email'] ?? ''), (string) ($b['email'] ?? ''));
    }
    if ($sortBy === 'monthly_bill') {
        return $compareNumber((string) ($a['monthly_bill'] ?? ''), (string) ($b['monthly_bill'] ?? ''));
    }
    if ($sortBy === 'finance_subsidy') {
        return $compareNumber((string) ($a['finance_subsidy'] ?? ''), (string) ($b['finance_subsidy'] ?? ''));
    }
    if ($sortBy === 'property_type') {
        return $compareText((string) ($a['property_type'] ?? ''), (string) ($b['property_type'] ?? ''));
    }
    if ($sortBy === 'roof_type') {
        return $compareText((string) ($a['roof_type'] ?? ''), (string) ($b['roof_type'] ?? ''));
    }
    if ($sortBy === 'best_time_to_call') {
        return $compareText((string) ($a['best_time_to_call'] ?? ''), (string) ($b['best_time_to_call'] ?? ''));
    }
    if ($sortBy === 'area_pincode') {
        return $compareText((string) ($a['area_pincode'] ?? ''), (string) ($b['area_pincode'] ?? ''));
    }
    if ($sortBy === 'status') {
        return $compareText((string) ($a['status'] ?? ''), (string) ($b['status'] ?? ''));
    }
    if ($sortBy === 'rating') {
        return $compareText((string) ($a['rating'] ?? ''), (string) ($b['rating'] ?? ''));
    }
    if ($sortBy === 'assigned_to') {
        return $compareText((string) ($a['assigned_to_name'] ?? ''), (string) ($b['assigned_to_name'] ?? ''));
    }

    if ($sortBy === 'next_followup') {
        $aTs = $parseTimestamp((string) ($a['next_followup_date'] ?? ''), (string) ($a['next_followup_time'] ?? '00:00:00'));
        $bTs = $parseTimestamp((string) ($b['next_followup_date'] ?? ''), (string) ($b['next_followup_time'] ?? '00:00:00'));
        return $compareTimestamp($aTs, $bTs);
    }
    if ($sortBy === 'last_contacted_at') {
        $aTs = $parseTimestamp((string) ($a['last_contacted_at'] ?? ''));
        $bTs = $parseTimestamp((string) ($b['last_contacted_at'] ?? ''));
        return $compareTimestamp($aTs, $bTs);
    }
    if ($sortBy === 'call_not_picked_count') {
        return $compareNumber((string) ($a['call_not_picked_count'] ?? ''), (string) ($b['call_not_picked_count'] ?? ''));
    }
    if ($sortBy === 'updated_at') {
        $aTs = $parseTimestamp((string) ($a['updated_at'] ?? ''));
        $bTs = $parseTimestamp((string) ($b['updated_at'] ?? ''));
        return $compareTimestamp($aTs, $bTs);
    }

    $aTs = $parseTimestamp((string) ($a['created_at'] ?? ''));
    $bTs = $parseTimestamp((string) ($b['created_at'] ?? ''));
    return $compareTimestamp($aTs, $bTs);
});

$assignedNames = array_values(array_unique(array_filter(array_map(static function (array $lead): string {
    return trim((string) ($lead['assigned_to_name'] ?? ''));
}, $leads))));
sort($assignedNames);

$leadSources = ['Incoming Call', 'WhatsApp', 'Referral', 'Social Media', 'Website Contact Form', 'Other'];
$interestTypes = ['Residential Rooftop', 'Commercial', 'Industrial', 'Petrol Pump', 'Irrigation / Agriculture', 'Other'];
$statuses = ['New', 'Contacted', 'Site Visit Needed', 'Site Visit Done', 'Quotation Sent', 'Negotiation', 'Converted', 'Not Interested'];
$ratings = ['Hot', 'Warm', 'Cold'];
$duplicateGroups = leads_group_duplicate_mobiles($leads);
ksort($duplicateGroups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Leads Dashboard | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/admin-unified.css" />
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
    input[type=text], input[type=tel], input[type=date], input[type=email], select { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
    button { font: inherit; cursor: pointer; }
    .btn { background: #2563eb; color: #fff; border: none; padding: 0.7rem 1.1rem; border-radius: 10px; font-weight: 700; }
    .btn-secondary { background: #eef2ff; color: #1f2937; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.65rem 0.5rem; border-bottom: 1px solid #e5e7eb; }
    th { font-size: 0.9rem; color: #374151; }
    tr:hover { background: #f9fafb; }
    .badge { display: inline-block; padding: 0.25rem 0.55rem; border-radius: 999px; font-weight: 700; font-size: 0.85rem; }
    .pill { background: #eef2ff; color: #4338ca; }
    .table-actions {
      display: flex;
      flex-direction: row;
      gap: 0.25rem;
      flex-wrap: wrap;
      align-items: center;
      max-width: 100%;
    }
    .table-actions > * { flex: 0 0 auto; }
    .table-actions .action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.18rem 0.42rem;
      min-height: 1.45rem;
      border-radius: 7px;
      font-size: 0.7rem;
      line-height: 1.1;
      font-weight: 600;
      border: none;
      text-decoration: none;
      white-space: nowrap;
    }
    .table-actions .action-btn.btn-secondary { border: 1px solid #dbe2f3; }
    .table-actions .action-btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .action-more { position: relative; }
    .action-more-menu {
      position: absolute;
      right: 0;
      top: calc(100% + 0.2rem);
      min-width: 170px;
      background: #fff;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16);
      padding: 0.25rem;
      display: none;
      z-index: 25;
    }
    .action-more.open .action-more-menu { display: block; }
    .action-more-item {
      width: 100%;
      text-align: left;
      background: transparent;
      border: none;
      border-radius: 8px;
      color: #1f2937;
      padding: 0.38rem 0.5rem;
      font-size: 0.76rem;
      line-height: 1.2;
      text-decoration: none;
      display: block;
      cursor: pointer;
    }
    .action-more-item:hover { background: #f3f4f6; }
    .action-more-item:disabled { opacity: 0.5; cursor: not-allowed; }
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
    .section-tabs { display:flex; gap:0.6rem; flex-wrap:wrap; }
    .section-tab { padding:0.55rem 0.95rem; border-radius:999px; border:1px solid #d1d5db; text-decoration:none; color:#1f2937; background:#fff; font-weight:700; }
    .section-tab.active { background:#2563eb; color:#fff; border-color:#2563eb; }
    .subsection-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem; margin-top:1rem; }

    .ux-modal-backdrop, .ux-drawer-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 9998; display: none; }
    .ux-modal { position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(940px, 96vw); max-height: 88vh; overflow: auto; background: #fff; border-radius: 14px; padding: 1rem; z-index: 9999; display: none; }
    .ux-drawer { position: fixed; top: 0; right: 0; width: min(560px, 96vw); height: 100vh; background: #fff; box-shadow: -8px 0 30px rgba(0,0,0,0.2); z-index: 9999; transform: translateX(102%); transition: transform .2s ease; display: flex; flex-direction: column; }
    .ux-drawer.open { transform: translateX(0); }
    .ux-panel-header { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e5e7eb; padding-bottom:0.5rem; margin-bottom:0.75rem; }
    .ux-panel-body { overflow:auto; }
    .ux-toast-wrap { position: fixed; right: 1rem; bottom: 1rem; display: flex; flex-direction: column; gap: 0.5rem; z-index: 10000; }
    .ux-toast { background: #111827; color: #fff; padding: 0.65rem 0.85rem; border-radius: 10px; box-shadow: 0 10px 24px rgba(0,0,0,0.2); }
    .sort-link { color: inherit; text-decoration: none; white-space: nowrap; }
    .sort-link:hover { text-decoration: underline; }
    textarea { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; }
  </style>
</head>
<body class="admin-shell leads-page">
  <div class="fullwidth-wrapper admin-page">
    <div class="card admin-panel">
      <div class="header-row">
        <div>
          <h1>Leads Dashboard</h1>
          <p style="margin:0;color:#4b5563;">Quickly add new leads and manage follow-ups.</p>
        </div>
        <a class="back-button" href="<?php echo leads_safe($homeUrl); ?>" aria-label="Back to dashboard">
          <span aria-hidden="true">&larr;</span>
          Back to Dashboard
        </a>
      </div>
    </div>

    <div class="card admin-panel">
      <div class="section-tabs admin-section-tabs">
        <?php $sectionQuery = $_GET; $sectionQuery['section'] = 'leads'; ?>
        <a class="section-tab <?php echo $activeSection === 'leads' ? 'active' : ''; ?>" href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($sectionQuery)); ?>">Leads</a>
        <?php $sectionQuery['section'] = 'quick-add'; ?>
        <a class="section-tab <?php echo $activeSection === 'quick-add' ? 'active' : ''; ?>" href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($sectionQuery)); ?>">Quick Add Lead</a>
        <?php $sectionQuery['section'] = 'import'; ?>
        <a class="section-tab <?php echo $activeSection === 'import' ? 'active' : ''; ?>" href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($sectionQuery)); ?>">Import Lead</a>
        <?php if ($loggedInAdmin): ?>
          <?php $sectionQuery['section'] = 'settings'; ?>
          <a class="section-tab <?php echo $activeSection === 'settings' ? 'active' : ''; ?>" href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($sectionQuery)); ?>">Settings</a>
        <?php endif; ?>
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

    <?php if ($activeSection === 'quick-add'): ?>
    <div class="card admin-panel">
      <h2 style="margin-top:0;">Quick Add Lead</h2>
      <form method="post" class="grid grid-3">
        <input type="hidden" name="intent" value="quick_add" />
        <input type="hidden" name="current_section" value="quick-add" />
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
          <label for="email">Email</label>
          <input type="email" id="email" name="email" />
        </div>
        <div>
          <label for="area_pincode">Area Pincode</label>
          <input type="text" id="area_pincode" name="area_pincode" />
        </div>
        <div>
          <label for="monthly_bill">Monthly Bill</label>
          <input type="text" id="monthly_bill" name="monthly_bill" />
        </div>
        <div>
          <label for="finance_subsidy">Finance &amp; Subsidy</label>
          <input type="text" id="finance_subsidy" name="finance_subsidy" />
        </div>
        <div>
          <label for="property_type">Property Type</label>
          <input type="text" id="property_type" name="property_type" />
        </div>
        <div>
          <label for="roof_type">Roof Type</label>
          <input type="text" id="roof_type" name="roof_type" />
        </div>
        <div>
          <label for="best_time_to_call">Best Time to Call</label>
          <input type="text" id="best_time_to_call" name="best_time_to_call" />
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
    <?php endif; ?>

    <?php if ($activeSection === 'import'): ?>
    <div class="card admin-panel">
      <h2 style="margin-top:0;">Import Leads (CSV)</h2>
      <p style="margin-top:0;color:#4b5563;">Upload a CSV with columns: #, Name, Mobile, Email, City, Area Pincode, Monthly Bill, Finance &amp; Subsidy, Property Type, Roof Type, Best Time to Call, Status, Rating, Next Follow-Up, Assigned To, Last Contacted, Campaign, Actions. Older CSV formats still work.</p>
      <p style="margin:0.5rem 0 0.75rem;">
        <a class="btn-secondary" href="/leads-dashboard.php?download=lead_sample_csv">Download Sample CSV</a>
      </p>
      <form method="post" enctype="multipart/form-data" class="grid" style="grid-template-columns: 1fr auto; align-items:end;">
        <input type="hidden" name="intent" value="import_csv" />
        <input type="hidden" name="current_section" value="import" />
        <div>
          <label for="csv_file">CSV File</label>
          <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required />
        </div>
        <div>
          <button type="submit" class="btn">Import CSV</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($activeSection === 'leads'): ?>
    <div class="card admin-panel">
      <h2 style="margin-top:0;">Duplicate Mobiles</h2>
      <p style="margin-top:0;color:#4b5563;">Review leads that share the same mobile number and merge them into one record.</p>
      <?php if ($duplicateGroups === []): ?>
        <p style="margin:0;color:#6b7280;">No duplicate mobile numbers detected.</p>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Mobile</th>
                <th>Lead Names</th>
                <th>Count</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($duplicateGroups as $mobileKey => $group): ?>
                <tr>
                  <td><?php echo leads_safe((string) ($group[0]['mobile'] ?? $mobileKey)); ?></td>
                  <td>
                    <?php foreach ($group as $index => $lead): ?>
                      <?php echo leads_safe((string) ($lead['name'] ?? 'Lead')); ?>
                      <?php if ($index < count($group) - 1): ?>
                        <span style="color:#9ca3af;">&bull;</span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </td>
                  <td><?php echo count($group); ?></td>
                  <td>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="intent" value="merge_duplicates" />
                      <input type="hidden" name="current_section" value="leads" />
                      <input type="hidden" name="mobile_key" value="<?php echo leads_safe((string) $mobileKey); ?>" />
                      <button type="submit" class="btn-secondary" onclick="return confirm('Merge all leads with this mobile number?');">Merge</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($loggedInAdmin && $activeSection === 'settings'): ?>
      <div class="card admin-panel">
        <h2 style="margin-top:0;">Settings</h2>
        <p style="margin-top:0;color:#4b5563;">Admin-only controls for lead communication templates and /solar-details.php content.</p>

        <div class="subsection-card">
          <h3 style="margin-top:0;">Messaging Templates</h3>
          <p style="margin-top:0;color:#4b5563;">Use placeholders: <code>{{name}}</code>, <code>{{mobile}}</code>, <code>{{email}}</code>, <code>{{city}}</code>, <code>{{assigned_to}}</code>, <code>{{details_page_url}}</code>, <code>{{company_name}}</code>, <code>{{company_phone}}</code>.</p>
          <form method="post" class="grid" style="gap:0.5rem;">
            <input type="hidden" name="intent" value="save_message_settings" />
            <input type="hidden" name="current_section" value="settings" />
            <h4 style="margin:0.25rem 0;">Stage 1 — Attention Capture</h4>
            <label for="default_whatsapp_message">Default WhatsApp Intro Message</label>
            <textarea id="default_whatsapp_message" name="default_whatsapp_message" rows="4" placeholder="Hello {{name}}, thank you for your interest in solar..."><?php echo leads_safe((string) ($messageSettings['default_whatsapp_message'] ?? '')); ?></textarea>
            <label for="default_email_subject">Default Email Intro Subject</label>
            <input type="text" id="default_email_subject" name="default_email_subject" value="<?php echo leads_safe((string) ($messageSettings['default_email_subject'] ?? '')); ?>" placeholder="Solar Proposal for {{name}}" />
            <label for="default_email_body">Default Email Intro Body</label>
            <textarea id="default_email_body" name="default_email_body" rows="5" placeholder="Hello {{name}}, ..."><?php echo leads_safe((string) ($messageSettings['default_email_body'] ?? '')); ?></textarea>
            <h4 style="margin:0.5rem 0 0;">Stage 2 — Detailed Information</h4>
            <label for="default_whatsapp_details_message">Default WhatsApp Details Message</label>
            <textarea id="default_whatsapp_details_message" name="default_whatsapp_details_message" rows="4" placeholder="Hello {{name}}, here are complete details: {{details_page_url}}"><?php echo leads_safe((string) ($messageSettings['default_whatsapp_details_message'] ?? '')); ?></textarea>
            <label for="default_email_details_subject">Default Email Details Subject</label>
            <input type="text" id="default_email_details_subject" name="default_email_details_subject" value="<?php echo leads_safe((string) ($messageSettings['default_email_details_subject'] ?? '')); ?>" placeholder="Detailed Solar Information for {{name}}" />
            <label for="default_email_details_body">Default Email Details Body</label>
            <textarea id="default_email_details_body" name="default_email_details_body" rows="5" placeholder="Hello {{name}}, detailed information is available at {{details_page_url}}..."><?php echo leads_safe((string) ($messageSettings['default_email_details_body'] ?? '')); ?></textarea>
            <label for="details_page_url">Details Page URL</label>
            <input type="text" id="details_page_url" name="details_page_url" value="<?php echo leads_safe((string) ($messageSettings['details_page_url'] ?? '/solar-details.php')); ?>" placeholder="/solar-details.php" />
            <label for="company_name">Company Name Placeholder Value</label>
            <input type="text" id="company_name" name="company_name" value="<?php echo leads_safe((string) ($messageSettings['company_name'] ?? 'Dakshayani Enterprises')); ?>" />
            <label for="company_phone">Company Phone Placeholder Value</label>
            <input type="text" id="company_phone" name="company_phone" value="<?php echo leads_safe((string) ($messageSettings['company_phone'] ?? '')); ?>" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
              <small style="color:#6b7280;">
                Last updated:
                <?php echo leads_safe((string) ($messageSettings['updated_at'] ?? 'Never')); ?>
                <?php if (trim((string) ($messageSettings['updated_by'] ?? '')) !== ''): ?>
                  by <?php echo leads_safe((string) ($messageSettings['updated_by'] ?? '')); ?>
                <?php endif; ?>
              </small>
              <button type="submit" class="btn">Save Templates</button>
            </div>
          </form>
        </div>

        <div class="subsection-card">
          <h3 style="margin-top:0;">Lead Explainer Content</h3>
          <p style="margin-top:0;color:#4b5563;">This content appears on <a href="/solar-details.php" target="_blank" rel="noopener">/solar-details.php</a>. Use Media Library URLs for image slots if available.</p>
          <form method="post" class="grid" style="gap:0.5rem;">
          <input type="hidden" name="intent" value="save_explainer_content" />
          <input type="hidden" name="current_section" value="settings" />
          <label for="page_title">Page Title</label>
          <input type="text" id="page_title" name="page_title" value="<?php echo leads_safe((string) ($explainerContent['page_title'] ?? '')); ?>" />
          <label for="hero_intro">Hero Intro</label>
          <textarea id="hero_intro" name="hero_intro" rows="3"><?php echo leads_safe((string) ($explainerContent['hero_intro'] ?? '')); ?></textarea>
          <label for="what_is_solar_rooftop">What is Solar Rooftop</label>
          <textarea id="what_is_solar_rooftop" name="what_is_solar_rooftop" rows="4"><?php echo leads_safe((string) ($explainerContent['what_is_solar_rooftop'] ?? '')); ?></textarea>
          <label for="pm_surya_ghar_text">PM Surya Ghar Explanation</label>
          <textarea id="pm_surya_ghar_text" name="pm_surya_ghar_text" rows="4"><?php echo leads_safe((string) ($explainerContent['pm_surya_ghar_text'] ?? '')); ?></textarea>
          <label for="who_is_eligible">Who is Eligible</label>
          <textarea id="who_is_eligible" name="who_is_eligible" rows="4"><?php echo leads_safe((string) ($explainerContent['who_is_eligible'] ?? '')); ?></textarea>
          <label for="on_grid_text">On-grid Block</label>
          <textarea id="on_grid_text" name="on_grid_text" rows="4"><?php echo leads_safe((string) ($explainerContent['on_grid_text'] ?? '')); ?></textarea>
          <label for="hybrid_text">Hybrid Block</label>
          <textarea id="hybrid_text" name="hybrid_text" rows="4"><?php echo leads_safe((string) ($explainerContent['hybrid_text'] ?? '')); ?></textarea>
          <label for="which_one_is_suitable_for_whom">Which one is suitable for whom</label>
          <textarea id="which_one_is_suitable_for_whom" name="which_one_is_suitable_for_whom" rows="4"><?php echo leads_safe((string) ($explainerContent['which_one_is_suitable_for_whom'] ?? '')); ?></textarea>
          <label for="benefits">Benefits</label>
          <textarea id="benefits" name="benefits" rows="4"><?php echo leads_safe((string) ($explainerContent['benefits'] ?? '')); ?></textarea>
          <label for="important_expectations">Important Expectations</label>
          <textarea id="important_expectations" name="important_expectations" rows="4"><?php echo leads_safe((string) ($explainerContent['important_expectations'] ?? '')); ?></textarea>
          <label for="process_text">Process Section</label>
          <textarea id="process_text" name="process_text" rows="5"><?php echo leads_safe((string) ($explainerContent['process_text'] ?? '')); ?></textarea>
          <label for="faq_text">FAQ</label>
          <textarea id="faq_text" name="faq_text" rows="6"><?php echo leads_safe((string) ($explainerContent['faq_text'] ?? '')); ?></textarea>
          <label for="cta_text">CTA Text</label>
          <textarea id="cta_text" name="cta_text" rows="3"><?php echo leads_safe((string) ($explainerContent['cta_text'] ?? '')); ?></textarea>
          <label for="on_grid_image">On-grid Diagram Image URL</label>
          <input type="text" id="on_grid_image" name="on_grid_image" value="<?php echo leads_safe((string) ($explainerContent['on_grid_image'] ?? '')); ?>" placeholder="/uploads/.../on-grid.png" />
          <label for="hybrid_image">Hybrid Diagram Image URL</label>
          <input type="text" id="hybrid_image" name="hybrid_image" value="<?php echo leads_safe((string) ($explainerContent['hybrid_image'] ?? '')); ?>" />
          <label for="process_flow_image">Process Flow Image URL</label>
          <input type="text" id="process_flow_image" name="process_flow_image" value="<?php echo leads_safe((string) ($explainerContent['process_flow_image'] ?? '')); ?>" />
          <label for="benefits_image">Benefits Image / Icons URL</label>
          <input type="text" id="benefits_image" name="benefits_image" value="<?php echo leads_safe((string) ($explainerContent['benefits_image'] ?? '')); ?>" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
              <small style="color:#6b7280;">
                Last updated:
                <?php echo leads_safe((string) ($explainerContent['updated_at'] ?? 'Never')); ?>
                <?php if (trim((string) ($explainerContent['updated_by'] ?? '')) !== ''): ?>
                  by <?php echo leads_safe((string) ($explainerContent['updated_by'] ?? '')); ?>
                <?php endif; ?>
              </small>
              <button type="submit" class="btn">Save Explainer Content</button>
            </div>
          </form>
        </div>
      </div>
    <?php elseif (!$loggedInAdmin && $activeSection === 'settings'): ?>
      <div class="card admin-panel">
        <h2 style="margin-top:0;">Messaging Templates (View)</h2>
        <p style="margin-top:0;color:#4b5563;">Current details page link: <a href="<?php echo leads_safe((string) ($messageSettings['details_page_url'] ?? '/solar-details.php')); ?>" target="_blank" rel="noopener"><?php echo leads_safe((string) ($messageSettings['details_page_url'] ?? '/solar-details.php')); ?></a></p>
      </div>
    <?php endif; ?>

    <?php if ($activeSection === 'leads'): ?>
    <div class="card admin-panel">
      <h2 style="margin-top:0;">Leads</h2>
      <div class="lead-filters">
        <?php $baseQuery = $_GET; ?>
        <?php $activeQuery = $baseQuery; $activeQuery['view'] = 'active'; ?>
        <?php $archivedQuery = $baseQuery; $archivedQuery['view'] = 'archived'; ?>
        <?php $allQuery = $baseQuery; $allQuery['view'] = 'all'; ?>
        <a href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($activeQuery)); ?>" class="<?php echo $view === 'active' ? 'active' : ''; ?>">Active Leads</a>
        <a href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($archivedQuery)); ?>" class="<?php echo $view === 'archived' ? 'active' : ''; ?>">Archived Leads</a>
        <a href="/leads-dashboard.php?<?php echo leads_safe(http_build_query($allQuery)); ?>" class="<?php echo $view === 'all' ? 'active' : ''; ?>">All Leads</a>
      </div>
      <form method="get" class="filters admin-filter-bar">
        <input type="hidden" name="view" value="<?php echo leads_safe($view); ?>" />
        <input type="hidden" name="sort_by" value="<?php echo leads_safe($sortBy); ?>" />
        <input type="hidden" name="sort_dir" value="<?php echo leads_safe($sortDir); ?>" />
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

      <form method="post" id="bulk-actions-form" class="lead-filters" style="margin-top:0.75rem;">
        <input type="hidden" name="intent" value="bulk_action" />
        <input type="hidden" name="current_section" value="leads" />
        <label style="display:flex;align-items:center;gap:0.5rem;">
          <span style="font-weight:700;">Bulk Actions</span>
        </label>
        <select name="bulk_action" required>
          <option value="">Select action</option>
          <option value="status_contacted">Contacted</option>
          <option value="status_quotation_sent">Quotation Sent</option>
          <option value="status_not_interested">Not Interested</option>
          <option value="archive">Archive</option>
          <option value="convert">Converted</option>
          <option value="mark_contacted_now">Mark Contacted Now</option>
        </select>
        <button type="submit" class="btn-secondary" onclick="return confirm('Apply this action to selected leads?');">Apply</button>
      </form>

      <div class="admin-table-wrap">
        <table class="admin-table leads-main-table">
          <thead>
            <tr>
              <th>
                <label style="display:flex;align-items:center;gap:0.35rem;font-weight:700;">
                  <input type="checkbox" id="select-all-leads" />
                  All
                </label>
              </th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('sr_no', $sortBy, $sortDir)); ?>">#<?php echo leads_safe(leads_sort_indicator('sr_no', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('name', $sortBy, $sortDir)); ?>">Name<?php echo leads_safe(leads_sort_indicator('name', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('mobile', $sortBy, $sortDir)); ?>">Mobile<?php echo leads_safe(leads_sort_indicator('mobile', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('city', $sortBy, $sortDir)); ?>">City<?php echo leads_safe(leads_sort_indicator('city', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('monthly_bill', $sortBy, $sortDir)); ?>">Monthly Bill<?php echo leads_safe(leads_sort_indicator('monthly_bill', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('status', $sortBy, $sortDir)); ?>">Status<?php echo leads_safe(leads_sort_indicator('status', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('next_followup', $sortBy, $sortDir)); ?>">Next Follow-Up<?php echo leads_safe(leads_sort_indicator('next_followup', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('last_contacted_at', $sortBy, $sortDir)); ?>">Last Contacted<?php echo leads_safe(leads_sort_indicator('last_contacted_at', $sortBy, $sortDir)); ?></a></th>
              <th>Message Sent (Intro + Details)</th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('call_not_picked_count', $sortBy, $sortDir)); ?>">Call not Picked<?php echo leads_safe(leads_sort_indicator('call_not_picked_count', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('best_time_to_call', $sortBy, $sortDir)); ?>">Best Time to Call<?php echo leads_safe(leads_sort_indicator('best_time_to_call', $sortBy, $sortDir)); ?></a></th>
              <th>Actions</th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('email', $sortBy, $sortDir)); ?>">Email<?php echo leads_safe(leads_sort_indicator('email', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('finance_subsidy', $sortBy, $sortDir)); ?>">Finance &amp; Subsidy<?php echo leads_safe(leads_sort_indicator('finance_subsidy', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('property_type', $sortBy, $sortDir)); ?>">Property Type<?php echo leads_safe(leads_sort_indicator('property_type', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('roof_type', $sortBy, $sortDir)); ?>">Roof Type<?php echo leads_safe(leads_sort_indicator('roof_type', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('area_pincode', $sortBy, $sortDir)); ?>">Area Pincode<?php echo leads_safe(leads_sort_indicator('area_pincode', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('rating', $sortBy, $sortDir)); ?>">Rating<?php echo leads_safe(leads_sort_indicator('rating', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('assigned_to', $sortBy, $sortDir)); ?>">Assigned To<?php echo leads_safe(leads_sort_indicator('assigned_to', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('created_at', $sortBy, $sortDir)); ?>">Created At<?php echo leads_safe(leads_sort_indicator('created_at', $sortBy, $sortDir)); ?></a></th>
              <th><a class="sort-link" href="<?php echo leads_safe(leads_build_sort_link('updated_at', $sortBy, $sortDir)); ?>">Updated At<?php echo leads_safe(leads_sort_indicator('updated_at', $sortBy, $sortDir)); ?></a></th>
              <th>Campaign</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($filteredLeads === []): ?>
              <tr><td colspan="23">No leads match the selected filters.</td></tr>
            <?php else: ?>
              <?php foreach ($filteredLeads as $index => $lead): ?>
                <?php echo leads_render_row($lead, $index + 1, $today, $quotationCreatePath); ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div id="ux-modal-backdrop" class="ux-modal-backdrop"></div>
  <div id="ux-modal" class="ux-modal" role="dialog" aria-modal="true" aria-label="Lead modal">
    <div class="ux-panel-header"><h3 id="ux-modal-title" style="margin:0;">Modal</h3><button type="button" class="btn-secondary" id="ux-modal-close">Close</button></div>
    <div id="ux-modal-body" class="ux-panel-body"></div>
  </div>

  <div id="ux-drawer-backdrop" class="ux-drawer-backdrop"></div>
  <aside id="ux-drawer" class="ux-drawer" aria-label="Lead drawer">
    <div style="padding:1rem;">
      <div class="ux-panel-header"><h3 id="ux-drawer-title" style="margin:0;">Lead</h3><button type="button" class="btn-secondary" id="ux-drawer-close">Close</button></div>
      <div id="ux-drawer-body" class="ux-panel-body"></div>
    </div>
  </aside>
  <div id="ux-toast-wrap" class="ux-toast-wrap" aria-live="polite"></div>

  <script>
    const selectAll = document.getElementById('select-all-leads');
    const modal = document.getElementById('ux-modal');
    const modalBackdrop = document.getElementById('ux-modal-backdrop');
    const modalBody = document.getElementById('ux-modal-body');
    const modalTitle = document.getElementById('ux-modal-title');
    const drawer = document.getElementById('ux-drawer');
    const drawerBackdrop = document.getElementById('ux-drawer-backdrop');
    const drawerBody = document.getElementById('ux-drawer-body');
    const drawerTitle = document.getElementById('ux-drawer-title');
    const toastWrap = document.getElementById('ux-toast-wrap');
    const whatsappTemplate = <?php echo json_encode((string) ($messageSettings['default_whatsapp_message'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const emailSubjectTemplate = <?php echo json_encode((string) ($messageSettings['default_email_subject'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const emailBodyTemplate = <?php echo json_encode((string) ($messageSettings['default_email_body'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const whatsappDetailsTemplate = <?php echo json_encode((string) ($messageSettings['default_whatsapp_details_message'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const emailDetailsSubjectTemplate = <?php echo json_encode((string) ($messageSettings['default_email_details_subject'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const emailDetailsBodyTemplate = <?php echo json_encode((string) ($messageSettings['default_email_details_body'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
    const detailsPageUrl = <?php echo json_encode((string) ($messageSettings['details_page_url'] ?? '/solar-details.php'), JSON_UNESCAPED_UNICODE); ?>;
    const companyName = <?php echo json_encode((string) ($messageSettings['company_name'] ?? 'Dakshayani Enterprises'), JSON_UNESCAPED_UNICODE); ?>;
    const companyPhone = <?php echo json_encode((string) ($messageSettings['company_phone'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;

    function showToast(message) {
      const el = document.createElement('div');
      el.className = 'ux-toast';
      el.textContent = message;
      toastWrap.appendChild(el);
      setTimeout(() => el.remove(), 3000);
    }

    function openModal(title, html) {
      modalTitle.textContent = title;
      modalBody.innerHTML = html;
      modal.style.display = 'block';
      modalBackdrop.style.display = 'block';
    }

    function closeModal() {
      modal.style.display = 'none';
      modalBackdrop.style.display = 'none';
      modalBody.innerHTML = '';
    }

    function openDrawer(title, html) {
      drawerTitle.textContent = title;
      drawerBody.innerHTML = html;
      drawer.classList.add('open');
      drawerBackdrop.style.display = 'block';
    }

    function closeDrawer() {
      drawer.classList.remove('open');
      drawerBackdrop.style.display = 'none';
      drawerBody.innerHTML = '';
    }

    function closeAllMoreMenus() {
      document.querySelectorAll('.action-more.open').forEach((menuWrap) => {
        menuWrap.classList.remove('open');
      });
    }

    function normalizeWhatsappMobile(rawMobile) {
      const digits = (rawMobile || '').replace(/\D+/g, '');
      if (digits.length === 10) return `91${digits}`;
      if (digits.length === 12 && digits.startsWith('91')) return digits;
      return '';
    }

    function applyTemplate(template, row) {
      let normalizedDetailsPageUrl = (detailsPageUrl || '/solar-details.php').trim();
      if (normalizedDetailsPageUrl.startsWith('/')) {
        normalizedDetailsPageUrl = `${window.location.origin}${normalizedDetailsPageUrl}`;
      }
      const values = {
        name: row?.dataset?.name?.trim() || 'Customer',
        mobile: row?.dataset?.mobile?.trim() || '',
        email: row?.dataset?.email?.trim() || '',
        city: row?.dataset?.city?.trim() || '',
        assigned_to: row?.dataset?.assignedTo?.trim() || '',
        details_page_url: normalizedDetailsPageUrl,
        company_name: (companyName || 'Dakshayani Enterprises').trim(),
        company_phone: (companyPhone || '').trim(),
      };

      return (template || '').replace(/\{\{\s*(name|mobile|email|city|assigned_to|details_page_url|company_name|company_phone)\s*\}\}/gi, (_, key) => values[key.toLowerCase()] || '');
    }

    function isValidEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((email || '').trim());
    }

    async function ajaxAction(action, payload = {}) {
      const body = new URLSearchParams({ ajax: '1', ajax_action: action, ...payload });
      const response = await fetch('/leads-dashboard.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
      });
      return response.json();
    }

    function refreshRowFromResponse(data, row) {
      if (!row || !data) return;
      if (data.remove_row) {
        row.style.transition = 'opacity .2s ease';
        row.style.opacity = '0';
        setTimeout(() => row.remove(), 220);
        return;
      }
      if (data.row_html) {
        row.outerHTML = data.row_html;
      }
    }

    function initLeadEditStatusField(container) {
      if (!container) return;
      const statusSelect = container.querySelector('#lead-status-select');
      const customWrapper = container.querySelector('#lead-status-custom-wrapper');
      const customInput = container.querySelector('#lead-status-custom-input');
      if (!statusSelect || !customWrapper || !customInput) return;

      const syncCustomVisibility = () => {
        const isManualInput = statusSelect.value === 'Manual input';
        customWrapper.style.display = isManualInput ? '' : 'none';
        customInput.required = isManualInput;
      };

      statusSelect.addEventListener('change', syncCustomVisibility);
      syncCustomVisibility();
    }

    document.getElementById('ux-modal-close').addEventListener('click', closeModal);
    document.getElementById('ux-drawer-close').addEventListener('click', closeDrawer);
    modalBackdrop.addEventListener('click', closeModal);
    drawerBackdrop.addEventListener('click', closeDrawer);

    if (selectAll) {
      selectAll.addEventListener('change', () => {
        document.querySelectorAll('.lead-select').forEach((checkbox) => {
          checkbox.checked = selectAll.checked;
        });
      });
    }

    document.addEventListener('submit', async (event) => {
      if (event.target && event.target.id === 'lead-edit-form') {
        event.preventDefault();
        const form = event.target;
        const row = document.getElementById(`lead-row-${form.lead_id.value}`);
        const rowIndex = row ? (row.querySelector('.lead-index')?.textContent || '1') : '1';
        const formData = new FormData(form);
        formData.append('ajax', '1');
        formData.append('ajax_action', 'save_lead');
        formData.append('row_index', rowIndex);
        const response = await fetch('/leads-dashboard.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const data = await response.json();
        if (data.success) {
          refreshRowFromResponse(data, row);
          showToast(data.message || 'Saved');
          closeDrawer();
        } else {
          showToast(data.message || 'Unable to save');
        }
      }
    });

    document.addEventListener('click', async (event) => {
      const toggleButton = event.target.closest('.more-toggle');
      if (toggleButton) {
        event.preventDefault();
        event.stopPropagation();
        const wrapper = toggleButton.closest('.action-more');
        const shouldOpen = wrapper && !wrapper.classList.contains('open');
        closeAllMoreMenus();
        if (wrapper && shouldOpen) {
          wrapper.classList.add('open');
        }
        return;
      }

      if (!event.target.closest('.action-more')) {
        closeAllMoreMenus();
      }

      const actionEl = event.target.closest('.lead-action');
      if (!actionEl) return;
      event.preventDefault();
      closeAllMoreMenus();
      const action = actionEl.dataset.action;
      const leadId = actionEl.dataset.leadId;
      const row = document.getElementById(`lead-row-${leadId}`);
      const rowIndex = row ? (row.querySelector('.lead-index')?.textContent || '1') : '1';

      if (action === 'view_edit') {
        const data = await ajaxAction('fetch_lead_form', { lead_id: leadId });
        if (data.success) {
          openDrawer('View / Edit Lead', data.html || '');
          initLeadEditStatusField(document.getElementById('ux-drawer-body'));
        } else {
          showToast(data.message || 'Unable to load lead');
        }
        return;
      }

      if (action === 'whatsapp') {
        if (!whatsappTemplate || !whatsappTemplate.trim()) {
          showToast('WhatsApp draft message is not set.');
          return;
        }
        const normalizedMobile = normalizeWhatsappMobile(row?.dataset?.mobile || '');
        if (!normalizedMobile) {
          showToast('Invalid lead mobile number for WhatsApp.');
          return;
        }
        const finalMessage = applyTemplate(whatsappTemplate, row).trim();
        if (!finalMessage) {
          showToast('WhatsApp draft message resolved to empty text.');
          return;
        }
        const url = `https://wa.me/${normalizedMobile}?text=${encodeURIComponent(finalMessage)}`;
        window.open(url, '_blank', 'noopener');
        const data = await ajaxAction('mark_whatsapp_sent', { lead_id: leadId, row_index: rowIndex });
        if (data.success) {
          refreshRowFromResponse(data, row);
        }
        return;
      }

      if (action === 'email') {
        if (!emailSubjectTemplate.trim() && !emailBodyTemplate.trim()) {
          showToast('Email template is not set.');
          return;
        }
        const leadEmail = row?.dataset?.email?.trim() || '';
        if (!isValidEmail(leadEmail)) {
          showToast('Lead email not available or invalid.');
          return;
        }
        const subject = applyTemplate(emailSubjectTemplate, row).trim();
        const body = applyTemplate(emailBodyTemplate, row).trim();
        const mailtoUrl = `mailto:${encodeURIComponent(leadEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        window.open(mailtoUrl, '_blank');
        const data = await ajaxAction('mark_email_sent', { lead_id: leadId, row_index: rowIndex });
        if (data.success) {
          refreshRowFromResponse(data, row);
        }
        return;
      }

      if (action === 'whatsapp_details') {
        if (!whatsappDetailsTemplate || !whatsappDetailsTemplate.trim()) {
          showToast('Detailed WhatsApp template is not set.');
          return;
        }
        const normalizedMobile = normalizeWhatsappMobile(row?.dataset?.mobile || '');
        if (!normalizedMobile) {
          showToast('Lead mobile number is required for WhatsApp details.');
          return;
        }
        const finalMessage = applyTemplate(whatsappDetailsTemplate, row).trim();
        if (!finalMessage) {
          showToast('Detailed WhatsApp template resolved to empty text.');
          return;
        }
        const url = `https://wa.me/${normalizedMobile}?text=${encodeURIComponent(finalMessage)}`;
        window.open(url, '_blank', 'noopener');
        const data = await ajaxAction('mark_whatsapp_details_sent', { lead_id: leadId, row_index: rowIndex });
        if (data.success) {
          refreshRowFromResponse(data, row);
        }
        return;
      }

      if (action === 'email_details') {
        if (!emailDetailsSubjectTemplate.trim() && !emailDetailsBodyTemplate.trim()) {
          showToast('Detailed email template is not set.');
          return;
        }
        const leadEmail = row?.dataset?.email?.trim() || '';
        if (!isValidEmail(leadEmail)) {
          showToast('Lead email not available or invalid.');
          return;
        }
        const subject = applyTemplate(emailDetailsSubjectTemplate, row).trim();
        const body = applyTemplate(emailDetailsBodyTemplate, row).trim();
        const mailtoUrl = `mailto:${encodeURIComponent(leadEmail)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        window.open(mailtoUrl, '_blank');
        const data = await ajaxAction('mark_email_details_sent', { lead_id: leadId, row_index: rowIndex });
        if (data.success) {
          refreshRowFromResponse(data, row);
        }
        return;
      }

      if (action === 'create_quotation') {
        const href = actionEl.getAttribute('href') || '#';
        openModal('Create Quotation', `<iframe src="${href}" style="width:100%;height:70vh;border:1px solid #e5e7eb;border-radius:8px;"></iframe><div style="margin-top:.75rem;text-align:right;"><button type="button" class="btn" id="quotation-done">Done & Mark Quotation Sent</button></div>`);
        document.getElementById('quotation-done')?.addEventListener('click', async () => {
          const data = await ajaxAction('mark_quotation_sent', { lead_id: leadId, row_index: rowIndex });
          if (data.success) {
            refreshRowFromResponse(data, row);
            showToast(data.message || 'Updated');
            closeModal();
          }
        });
        return;
      }

      const confirmations = {
        archive_lead: 'Archive this lead?',
        create_customer: 'Create customer from this lead?',
        mark_converted: 'Mark this lead as Converted?',
        mark_not_interested: 'Mark this lead as Not Interested?',
      };
      if (confirmations[action] && !window.confirm(confirmations[action])) {
        return;
      }

      const endpointMap = {
        mark_contacted: 'mark_contacted',
        mark_interested: 'mark_interested',
        call_not_picked: 'call_not_picked',
        archive_lead: 'archive_lead',
        create_customer: 'create_customer_from_lead',
        mark_converted: 'mark_converted',
        mark_not_interested: 'mark_not_interested',
      };
      const ajaxName = endpointMap[action];
      if (!ajaxName) return;

      const data = await ajaxAction(ajaxName, { lead_id: leadId, row_index: rowIndex });
      if (data.success) {
        refreshRowFromResponse(data, row);
        showToast(data.message || 'Done');
      } else {
        showToast(data.message || 'Action failed');
      }
    });
  </script>
</body>
</html>
