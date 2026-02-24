<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_admin.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/customer_bulk_import.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/handover.php';

employee_portal_session();
$isEmployeePortal = !empty($_SESSION['employee_logged_in']);
$employeeUser = null;
if ($isEmployeePortal) {
    $employeeStore = new EmployeeFsStore();
    $employeeUser = employee_portal_current_employee($employeeStore);
    if ($employeeUser === null) {
        header('Location: login.php?login_type=employee');
        exit;
    }
} else {
    require_admin();
}

$activeTab = strtolower((string) ($_GET['tab'] ?? 'customers'));
if (!in_array($activeTab, ['customers', 'employees'], true)) {
    $activeTab = 'customers';
}
if ($isEmployeePortal) {
    $activeTab = 'customers';
}

$customerStore = new CustomerFsStore();
$customerStatuses = $customerStore->customerStatuses();
$problemCategories = complaint_problem_categories();
$assigneeOptions = complaint_assignee_options();
$customerErrors = [];
$customerSuccess = '';
$customers = [];
$editingCustomer = null;
$customerComplaints = [];
$customerImportSummary = null;
$customerImportError = '';
$customerPlainPassword = null;
$handoverTemplates = handover_template_defaults();
    $employeeStore = $employeeStore ?? new EmployeeFsStore();
    $employeeErrors = [];
    $employeeSuccess = '';
    $employees = [];
    $editingEmployee = null;

$flash = consume_flash();
if (is_array($flash)) {
    $flashMessage = trim((string) ($flash['message'] ?? ''));
    if ($flashMessage !== '') {
        if (($flash['type'] ?? '') === 'error') {
            $customerErrors[] = $flashMessage;
        } else {
            $customerSuccess = $flashMessage;
        }
    }
}

if ($activeTab === 'customers' && isset($_GET['download']) && $_GET['download'] === 'customer_csv_template') {
    customer_bulk_send_sample_csv();
    exit;
}

if ($activeTab === 'customers') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['customer_action'] ?? '');
        if ($action === 'create_complaint') {
            $viewMobile = (string) ($_POST['view_mobile'] ?? '');
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $problemCategory = trim((string) ($_POST['problem_category'] ?? ''));
            $assignee = trim((string) ($_POST['assignee'] ?? ''));

            if ($viewMobile === '') {
                $customerErrors[] = 'Customer not found for complaint creation.';
            }
            if ($title === '' || $description === '' || $problemCategory === '' || $assignee === '') {
                $customerErrors[] = 'Title, description, problem category, and assignee are required.';
            }

            if ($customerErrors === []) {
                try {
                    add_complaint([
                        'customer_mobile' => $viewMobile,
                        'title' => $title,
                        'description' => $description,
                        'status' => 'open',
                        'problem_category' => $problemCategory,
                        'assignee' => $assignee,
                    ], true);
                    complaint_sync_customer_flag($customerStore, $viewMobile);
                    $customerSuccess = 'Complaint created successfully.';
                } catch (Throwable $exception) {
                    $customerErrors[] = $exception->getMessage();
                }
            }

            if ($viewMobile !== '') {
                $editingCustomer = $customerStore->findByMobile($viewMobile);
            }
        } elseif ($action === 'update_complaint' || $action === 'update_complaint_status') {
            $complaintId = (string) ($_POST['complaint_id'] ?? '');
            $newStatus = (string) ($_POST['status'] ?? 'open');
            $hasCategory = array_key_exists('problem_category', $_POST);
            $problemCategory = $hasCategory ? trim((string) $_POST['problem_category']) : null;
            $hasAssignee = array_key_exists('assignee', $_POST);
            $assignee = $hasAssignee ? trim((string) $_POST['assignee']) : null;
            $viewMobile = (string) ($_POST['view_mobile'] ?? '');

            if ($complaintId === '') {
                $customerErrors[] = 'Complaint not found.';
            } else {
                try {
                    $updatePayload = [
                        'id' => $complaintId,
                        'status' => $newStatus,
                    ];

                    if ($hasCategory) {
                        $updatePayload['problem_category'] = (string) $problemCategory;
                    }

                    if ($hasAssignee) {
                        $updatePayload['assignee'] = (string) $assignee;
                    }

                    $updated = update_complaint($updatePayload);
                    if ($updated === null) {
                        $customerErrors[] = 'Complaint not found.';
                    } else {
                        complaint_sync_customer_flag($customerStore, (string) ($updated['customer_mobile'] ?? $viewMobile));
                        $customerSuccess = 'Complaint updated successfully.';
                    }
                } catch (Throwable $exception) {
                    $customerErrors[] = $exception->getMessage();
                }
            }

            if ($viewMobile !== '') {
                $editingCustomer = $customerStore->findByMobile($viewMobile);
            }
        } else {
            $input = [
                'mobile' => $_POST['mobile'] ?? '',
                'name' => $_POST['name'] ?? '',
                'serial_number' => $_POST['serial_number'] ?? '',
                'customer_type' => $_POST['customer_type'] ?? '',
                'address' => $_POST['address'] ?? '',
                'city' => $_POST['city'] ?? '',
                'district' => $_POST['district'] ?? '',
                'pin_code' => $_POST['pin_code'] ?? '',
                'state' => $_POST['state'] ?? '',
                'meter_number' => $_POST['meter_number'] ?? '',
                'meter_serial_number' => $_POST['meter_serial_number'] ?? '',
                'jbvnl_account_number' => $_POST['jbvnl_account_number'] ?? '',
                'application_id' => $_POST['application_id'] ?? '',
                'application_submitted_date' => $_POST['application_submitted_date'] ?? '',
                'sanction_load_kwp' => $_POST['sanction_load_kwp'] ?? '',
                'installed_pv_module_capacity_kwp' => $_POST['installed_pv_module_capacity_kwp'] ?? '',
                'circle_name' => $_POST['circle_name'] ?? '',
                'division_name' => $_POST['division_name'] ?? '',
                'sub_division_name' => $_POST['sub_division_name'] ?? '',
                'loan_taken' => $_POST['loan_taken'] ?? '',
                'loan_application_date' => $_POST['loan_application_date'] ?? '',
                'solar_plant_installation_date' => $_POST['solar_plant_installation_date'] ?? '',
                'subsidy_amount_rs' => $_POST['subsidy_amount_rs'] ?? '',
                'subsidy_disbursed_date' => $_POST['subsidy_disbursed_date'] ?? '',
                'complaints_raised' => (isset($_POST['complaints_raised']) && strtolower((string) $_POST['complaints_raised']) === 'yes') ? 'Yes' : 'No',
                'status' => $_POST['status'] ?? '',
                'welcome_sent_via' => $_POST['welcome_sent_via'] ?? '',
                'handover_overrides' => admin_users_collect_handover_overrides($_POST),
            ];

            if (in_array($action, ['create_customer', 'update_customer', 'send_welcome_whatsapp', 'send_welcome_email'], true)) {
                admin_users_apply_customer_password($_POST, $input, $customerErrors, $action === 'create_customer', $customerPlainPassword);
            }

            if ($action === 'create_customer') {
                if ($customerErrors === []) {
                    $result = $customerStore->addCustomer($input);
                    if ($result['success']) {
                        $customerSuccess = 'Customer added successfully.';
                        $actor = audit_current_actor();
                        log_audit_event(
                            $actor['actor_type'],
                            $actor['actor_id'],
                            'customer',
                            (string) ($result['customer']['mobile'] ?? $input['mobile']),
                            'customer_create',
                            [
                                'name' => $result['customer']['name'] ?? '',
                                'mobile' => $result['customer']['mobile'] ?? '',
                                'customer_type' => $result['customer']['customer_type'] ?? '',
                                'serial_number' => $result['customer']['serial_number'] ?? '',
                                'status' => $result['customer']['status'] ?? '',
                            ]
                        );
                    } else {
                        $customerErrors = $result['errors'];
                    }
                }
            } elseif ($action === 'update_customer') {
                if ($customerErrors === []) {
                    $originalMobile = (string) ($_POST['original_mobile'] ?? '');
                    $existingCustomer = $customerStore->findByMobile($originalMobile);
                    if ($existingCustomer === null) {
                        $customerErrors[] = 'Customer not found.';
                    }

                    if ($customerErrors === []) {
                        $result = $customerStore->updateCustomer($originalMobile, $input);
                        if ($result['success']) {
                            $customerSuccess = 'Customer updated successfully.';
                            $editingCustomer = $result['customer'];
                            $actor = audit_current_actor();
                            $changes = audit_changed_fields(
                                $existingCustomer ?? [],
                                $result['customer'] ?? [],
                                ['password_hash', 'updated_at', 'created_at', 'mobile_key']
                            );
                            if ($changes !== []) {
                                log_audit_event(
                                    $actor['actor_type'],
                                    $actor['actor_id'],
                                    'customer',
                                    (string) ($result['customer']['mobile'] ?? $originalMobile),
                                    'customer_update',
                                    ['changed_fields' => $changes]
                                );
                            }
                        } else {
                            $customerErrors = $result['errors'];
                        }
                    }
                }
            } elseif (in_array($action, ['send_welcome_whatsapp', 'send_welcome_email'], true)) {
                $originalMobile = (string) ($_POST['original_mobile'] ?? '');
                $existingCustomer = $customerStore->findByMobile($originalMobile);
                if ($existingCustomer === null) {
                    $customerErrors[] = 'Customer not found.';
                }

                if ($customerErrors === []) {
                    $channel = $action === 'send_welcome_whatsapp' ? 'whatsapp' : 'email';
                    $input['welcome_sent_via'] = admin_users_next_welcome_status((string) ($existingCustomer['welcome_sent_via'] ?? 'none'), $channel);
                    $result = $customerStore->updateCustomer($originalMobile, $input);
                    if ($result['success']) {
                        $editingCustomer = $result['customer'];
                        $message = admin_users_build_welcome_message($editingCustomer, $customerPlainPassword);
                        $actor = audit_current_actor();
                        log_audit_event(
                            $actor['actor_type'],
                            $actor['actor_id'],
                            'customer',
                            (string) ($editingCustomer['mobile'] ?? $originalMobile),
                            $channel === 'whatsapp' ? 'customer_send_welcome_whatsapp' : 'customer_send_welcome_email',
                            [
                                'welcome_sent_via_before' => $existingCustomer['welcome_sent_via'] ?? 'none',
                                'welcome_sent_via_after' => $editingCustomer['welcome_sent_via'] ?? $input['welcome_sent_via'],
                            ]
                        );
                        if ($channel === 'whatsapp') {
                            $mobile = trim((string) ($editingCustomer['mobile'] ?? ''));
                            $normalizedMobile = preg_replace('/\D+/', '', $mobile);

                            if (strlen($normalizedMobile) !== 10) {
                                $customerErrors[] = 'Customer mobile number is invalid. Cannot open WhatsApp chat.';
                            } else {
                                $mobileForWhatsApp = '91' . $normalizedMobile;
                                $url = 'https://wa.me/' . $mobileForWhatsApp . '?text=' . rawurlencode($message);

                                $customerSuccess = 'WhatsApp welcome message prepared.';
                                header('Location: ' . $url);
                                exit;
                            }
                        }

                        $subject = admin_users_build_welcome_subject($editingCustomer);
                        $customerSuccess = 'Email welcome message prepared.';
                        header('Location: mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message));
                        exit;
                    }

                    $customerErrors = $result['errors'];
                }
            } elseif ($action === 'generate_handover') {
                $viewMobile = (string) ($_POST['view_mobile'] ?? ($_POST['original_mobile'] ?? ''));
                if ($viewMobile === '') {
                    $customerErrors[] = 'Customer not found.';
                }

                if ($customerErrors === []) {
                    $existingCustomer = $customerStore->findByMobile($viewMobile);
                    if ($existingCustomer === null) {
                        $customerErrors[] = 'Customer not found.';
                    }
                }

                if ($customerErrors === []) {
                    $saveResult = $customerStore->updateCustomer($viewMobile, $input);
                    if (!$saveResult['success']) {
                        $customerErrors = $saveResult['errors'];
                    } else {
                        header('Location: generate-handover.php?mobile=' . urlencode($viewMobile));
                        exit;
                    }
                }
            } elseif ($action === 'send_handover_whatsapp') {
                $viewMobile = (string) ($_POST['view_mobile'] ?? ($_POST['original_mobile'] ?? ''));
                $existingCustomer = $viewMobile !== '' ? $customerStore->findByMobile($viewMobile) : null;
                if ($existingCustomer === null) {
                    $customerErrors[] = 'Customer not found.';
                } else {
                    $editingCustomer = $existingCustomer;
                    $handoverHtmlPath = trim((string) ($existingCustomer['handover_html_path'] ?? ($existingCustomer['handover_document_path'] ?? '')));

                    if ($handoverHtmlPath === '') {
                        $customerErrors[] = 'Please generate the handover document first.';
                    } else {
                        $handoverUrl = rtrim(admin_users_base_url(), '/') . '/' . ltrim($handoverHtmlPath, '/');
                        $normalizedMobile = handover_normalize_mobile((string) ($existingCustomer['mobile'] ?? ''));
                        if ($normalizedMobile === '') {
                            $customerErrors[] = 'Customer mobile number is invalid for WhatsApp.';
                        } else {
                            $message = admin_users_build_handover_message($existingCustomer, $handoverUrl);
                            $waUrl = 'https://wa.me/91' . $normalizedMobile . '?text=' . urlencode($message);
                            header('Location: ' . $waUrl);
                            exit;
                        }
                    }
                }
            } elseif ($action === 'import_customers') {
                $upload = $_FILES['csv_file'] ?? null;
                $importResult = customer_bulk_import($customerStore, $upload);
                if ($importResult['success']) {
                    $customerImportSummary = $importResult['summary'];
                } else {
                    $customerImportError = $importResult['message'];
                }
            }
        }
    }

    $customers = $customerStore->listCustomers();
    $viewMobile = (string) ($_GET['view'] ?? '');
    if ($viewMobile !== '') {
        $editingCustomer = $customerStore->findByMobile($viewMobile);
        if ($editingCustomer === null) {
            $customerErrors[] = 'Customer not found.';
        }
    }

    if ($editingCustomer !== null) {
        $handoverOverrides = $editingCustomer['handover_overrides'] ?? null;
        if (!is_array($handoverOverrides)) {
            $handoverOverrides = handover_default_overrides();
        } else {
            $handoverOverrides = array_merge(handover_default_overrides(), $handoverOverrides);
        }
        $editingCustomer['handover_overrides'] = $handoverOverrides;
        $customerComplaints = get_complaints_by_customer((string) ($editingCustomer['mobile'] ?? ''));
    }

    $handoverTemplates = load_handover_templates();
}

if ($activeTab === 'employees') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['employee_action'] ?? '');
        $input = [
            'name' => $_POST['name'] ?? '',
            'login_id' => $_POST['login_id'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'designation' => $_POST['designation'] ?? '',
            'status' => $_POST['status'] ?? 'active',
            'can_access_admin_created_dcs' => isset($_POST['can_access_admin_created_dcs']),
        ];

        $existingEmployee = null;
        if ($action === 'update_employee') {
            $employeeId = (string) ($_POST['employee_id'] ?? '');
            $existingEmployee = $employeeStore->findById($employeeId);
        }

        admin_users_apply_employee_password($_POST, $input, $employeeErrors, $existingEmployee);

        if ($action === 'create_employee') {
            if ($employeeErrors === []) {
                $result = $employeeStore->addEmployee($input);
            } else {
                $result = ['success' => false, 'errors' => $employeeErrors, 'employee' => null];
            }
            if ($result['success']) {
                $employeeSuccess = 'Employee added successfully.';
            } else {
                $employeeErrors = $result['errors'];
            }
        } elseif ($action === 'update_employee') {
            $employeeId = (string) ($_POST['employee_id'] ?? '');
            if ($employeeErrors === []) {
                $result = $employeeStore->updateEmployee($employeeId, $input);
            } else {
                $result = ['success' => false, 'errors' => $employeeErrors, 'employee' => null];
            }
            if ($result['success']) {
                $employeeSuccess = 'Employee updated successfully.';
                $editingEmployee = $result['employee'];
            } else {
                $employeeErrors = $result['errors'];
            }
        }
    }

    $employees = $employeeStore->listEmployees();
    $viewEmployee = (string) ($_GET['view'] ?? '');
    if ($viewEmployee !== '') {
        $editingEmployee = $employeeStore->findById($viewEmployee);
        if ($editingEmployee === null) {
            $employeeErrors[] = 'Employee not found.';
        }
    }
}

function admin_users_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_users_apply_customer_password(
    array $source,
    array &$input,
    array &$errors,
    bool $requirePassword = false,
    ?string &$plainPassword = null
): void
{
    $password = isset($source['password']) && is_string($source['password']) ? (string) $source['password'] : '';
    $confirm = isset($source['confirm_password']) && is_string($source['confirm_password']) ? (string) $source['confirm_password'] : '';

    $hasPassword = ($password !== '') || ($confirm !== '');
    if (!$hasPassword) {
        if ($requirePassword) {
            $errors[] = 'Password is required for new customers.';
        }
        return;
    }

    if ($password === '' || $confirm === '') {
        $errors[] = 'Both password fields are required when setting a password.';
        return;
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirm password must match.';
        return;
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        $errors[] = 'Unable to process the password. Please try again.';
        return;
    }

    $input['password_hash'] = $hash;
    if ($plainPassword !== null) {
        $plainPassword = $password;
    }
}

function admin_users_collect_handover_overrides(array $source): array
{
    $incoming = $source['handover_overrides'] ?? [];
    $defaults = handover_default_overrides();

    if (!is_array($incoming)) {
        return $defaults;
    }

    foreach ($defaults as $key => $value) {
        $defaults[$key] = trim((string) ($incoming[$key] ?? $value));
    }

    return $defaults;
}

function admin_users_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $isSecure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $isSecure ? 'https' : 'http';

    if ($host === '') {
        return 'https://dakshayani.co.in';
    }

    return $scheme . '://' . $host;
}

function admin_users_build_handover_message(array $customer, string $handoverUrl): string
{
    $template = <<<TEXT
Dear {{consumer_name}},

Your solar project with Dakshayani Enterprises has been successfully completed and handed over.

You can view or print your handover documents here:
[HANDOVER_URL]

Thank you for choosing Dakshayani Enterprises.
www.dakshayani.co.in
TEXT;

    $customerName = trim((string) ($customer['name'] ?? $customer['full_name'] ?? 'Customer'));
    $placeholders = [
        '{{consumer_name}}' => $customerName === '' ? 'Customer' : $customerName,
        '[HANDOVER_URL]' => $handoverUrl,
    ];

    return strtr($template, $placeholders);
}

function admin_users_apply_employee_password(array $source, array &$input, array &$errors, ?array $existingEmployee = null): void
{
    $password = isset($source['password']) && is_string($source['password']) ? (string) $source['password'] : '';
    $confirm = isset($source['confirm_password']) && is_string($source['confirm_password']) ? (string) $source['confirm_password'] : '';

    $hasPassword = ($password !== '') || ($confirm !== '');
    if (!$hasPassword) {
        return;
    }

    if ($password === '' || $confirm === '') {
        $errors[] = 'Both password fields are required when setting an employee password.';
        return;
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirm password must match.';
        return;
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        $errors[] = 'Unable to process the password. Please try again.';
        return;
    }

    $input['password_hash'] = $hash;
}

function admin_users_normalise_welcome_channel(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '' || $value === 'none') {
        return 'none';
    }

    if (in_array($value, ['whatsapp', 'email', 'both'], true)) {
        return $value;
    }

    return 'none';
}

function admin_users_next_welcome_status(string $current, string $channel): string
{
    $current = admin_users_normalise_welcome_channel($current);
    $channel = admin_users_normalise_welcome_channel($channel);

    if ($channel === 'none') {
        return $current;
    }

    if ($current === 'none') {
        return $channel;
    }

    if ($current === $channel) {
        return $channel;
    }

    return 'both';
}

function admin_users_display_welcome_status(string $value): string
{
    $value = admin_users_normalise_welcome_channel($value);
    if ($value === 'whatsapp') {
        return 'WhatsApp';
    }
    if ($value === 'email') {
        return 'Email';
    }
    if ($value === 'both') {
        return 'WhatsApp & Email';
    }

    return 'Not Sent';
}

function admin_users_build_welcome_message(array $customer, ?string $tempPassword): string
{
    $loginUrl = 'https://dakshayani.co.in/login.php';
    $websiteUrl = 'https://dakshayani.co.in';

    $valueOrDash = static fn (string $key): string => ($value = trim((string) ($customer[$key] ?? ''))) !== '' ? $value : '-';
    $password = $tempPassword ?? '';

    $lines = [];
    $lines[] = 'Dear ' . ($customer['name'] ?? 'Customer') . ',';
    $lines[] = '';
    $lines[] = 'Welcome to Dakshayani Enterprises. Your solar system has been successfully registered with us.';
    $lines[] = '';
    $lines[] = 'Customer Serial Number: ' . $valueOrDash('serial_number');
    $lines[] = 'Registered Mobile: ' . $valueOrDash('mobile');
    $lines[] = 'Customer Type: ' . $valueOrDash('customer_type');
    $lines[] = '';
    $lines[] = '--- Customer Details ---';
    $lines[] = 'Address: ' . implode(', ', [
        $valueOrDash('address'),
        $valueOrDash('city'),
        $valueOrDash('district'),
        $valueOrDash('state'),
    ]) . ' - ' . $valueOrDash('pin_code');
    $lines[] = 'JBVNL Account Number: ' . $valueOrDash('jbvnl_account_number');
    $lines[] = 'Circle: ' . $valueOrDash('circle_name');
    $lines[] = 'Division: ' . $valueOrDash('division_name');
    $lines[] = 'Sub Division: ' . $valueOrDash('sub_division_name');
    $lines[] = '';
    $lines[] = '--- Portal Login Details ---';
    $lines[] = 'Login URL: ' . $loginUrl;
    $lines[] = 'Username: Your registered mobile number';
    $lines[] = 'Password: abcd1234';
    $lines[] = '(Please change this password after your first login.)';
    $lines[] = '';
    $lines[] = '--- Complaint Registration ---';
    $lines[] = 'You can register complaints anytime using your registered mobile number at:';
    $lines[] = 'https://dakshayani.co.in/contact.php';
    $lines[] = 'Under the section: "Register a Complaint (registered customers)".';
    $lines[] = '';
    $lines[] = '--- Additional Information ---';
    $lines[] = 'Sanction Load: ' . $valueOrDash('sanction_load_kwp') . ' kWp';
    $lines[] = 'Installed Capacity: ' . $valueOrDash('installed_pv_module_capacity_kwp') . ' kWp';
    $lines[] = 'Application ID: ' . $valueOrDash('application_id');
    $lines[] = 'Application Submitted Date: ' . $valueOrDash('application_submitted_date');
    $lines[] = 'Solar Plant Installation Date: ' . $valueOrDash('solar_plant_installation_date');
    $lines[] = 'Loan Taken: ' . $valueOrDash('loan_taken');
    $lines[] = 'Subsidy Amount: ' . $valueOrDash('subsidy_amount_rs');
    $lines[] = 'Subsidy Disbursed Date: ' . $valueOrDash('subsidy_disbursed_date');
    $lines[] = '';
    $lines[] = 'Regards,';
    $lines[] = 'Dakshayani Enterprises';
    $lines[] = $websiteUrl;

    return implode("\n", $lines);
}

function admin_users_build_welcome_subject(array $customer): string
{
    $serial = trim((string) ($customer['serial_number'] ?? ''));
    $serialPart = $serial !== '' ? ' - ' . $serial : '';
    return 'Welcome to Dakshayani Enterprises - ' . ($customer['name'] ?? 'Customer') . $serialPart;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>User Management | Dakshayani Enterprises</title>
  <meta name="description" content="Administer Dentweb user accounts." />
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
  <style>
    .users-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-bottom: 1.5rem;
    }

    .users-tab__link {
      padding: 0.65rem 1.1rem;
      border-radius: 999px;
      border: 1px solid #d9dde7;
      background: #f7f9fc;
      color: #1c2330;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
    }

    .users-tab__link:hover {
      border-color: #b6c2d9;
      background: #eef2f9;
    }

    .users-tab__link.is-active {
      background: linear-gradient(135deg, #1f4b99, #2d68d8);
      color: #ffffff;
      border-color: #2d68d8;
      box-shadow: 0 8px 20px rgba(45, 104, 216, 0.2);
    }

    .users-toolbar {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 1rem;
    }

    .users-toolbar__actions {
      display: flex;
      justify-content: flex-end;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .users-input,
    .users-select {
      width: 100%;
      padding: 0.65rem 0.75rem;
      border: 1px solid #d9dde7;
      border-radius: 10px;
      background: #fff;
      font: inherit;
    }

    .users-input:focus,
    .users-select:focus {
      outline: 2px solid #2d68d8;
      outline-offset: 2px;
    }

    .users-table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff;
      border: 1px solid #e6e9ef;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(17, 24, 39, 0.04);
    }

    .users-table th,
    .users-table td {
      padding: 0.9rem 1rem;
      text-align: left;
      border-bottom: 1px solid #eef2f7;
      font-size: 0.95rem;
    }

    .users-table th {
      background: #f7f9fc;
      font-weight: 700;
      color: #1c2330;
      letter-spacing: 0.01em;
    }

    .users-table tbody tr:last-child td {
      border-bottom: none;
    }

    .users-status {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.35rem 0.7rem;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 600;
      background: #eef2f9;
      color: #1f4b99;
    }

    .users-actions a {
      color: #2d68d8;
      font-weight: 600;
      text-decoration: none;
    }

    .users-actions a:hover {
      text-decoration: underline;
    }

    .users-section + .users-section {
      margin-top: 2rem;
    }

    .users-card {
      background: #ffffff;
      border: 1px solid #e6e9ef;
      border-radius: 14px;
      padding: 1.2rem 1.25rem;
      box-shadow: 0 8px 24px rgba(17, 24, 39, 0.05);
      margin-bottom: 1rem;
    }

    .users-card__header {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .users-form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.75rem 1rem;
      margin-top: 0.75rem;
    }

    .users-form-grid label {
      font-weight: 600;
      display: block;
      margin-bottom: 0.25rem;
      color: #1c2330;
    }

    .users-form-section {
      border: 1px solid #e6e9ef;
      border-radius: 12px;
      padding: 1rem;
      background: #f9fbff;
    }

    .users-form-section + .users-form-section {
      margin-top: 0.9rem;
    }

    .users-form-section__header {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 0.5rem;
      margin-bottom: 0.35rem;
      flex-wrap: wrap;
    }

    .users-form-section__title {
      margin: 0;
      font-size: 1rem;
      color: #111827;
    }

    .users-form-grid .users-input,
    .users-form-grid .users-select {
      border-radius: 8px;
    }

    .users-form-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 0.5rem;
    }

    .admin-alert {
      padding: 0.85rem 1rem;
      border-radius: 10px;
      margin-bottom: 1rem;
      border: 1px solid transparent;
    }

    .admin-alert--success {
      background: #edf7ed;
      border-color: #c8e6c9;
      color: #256029;
    }

    .admin-alert--error {
      background: #fff1f0;
      border-color: #f5c6cb;
      color: #b22222;
    }

    /* Customer Type badges */
    .badge-customer-type {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 500;
      color: #333;
    }

    /* PM Surya Ghar vs Non PM */
    .badge-pm-surya-ghar {
      background-color: #e0f7f7;
      border: 1px solid #b3e0e0;
    }

    .badge-non-pm-surya-ghar {
      background-color: #e5e9ff;
      border: 1px solid #c2c8ff;
    }

    /* Project status badges */
    .badge-status {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 500;
      color: #333;
    }

    .badge-status-new {
      background-color: #e3f2ff;
      border: 1px solid #bedcff;
    }

    .badge-status-survey-pending {
      background-color: #fff9e0;
      border: 1px solid #ffe9a3;
    }

    .badge-status-survey-done {
      background-color: #e6fff4;
      border: 1px solid #b8f0d2;
    }

    .badge-status-installation-pending {
      background-color: #fff3e0;
      border: 1px solid #ffd59b;
    }

    .badge-status-installation-in-progress {
      background-color: #ffe9e0;
      border: 1px solid #ffccba;
    }

    .badge-status-complete,
    .badge-status-completed {
      background-color: #e4ffe4;
      border: 1px solid #b4e6b4;
    }

    /* Generic YES/NO cell colours */
    .cell-yes {
      background-color: #e6ffed;
      border-radius: 4px;
    }

    .cell-no {
      background-color: #ffecec;
      border-radius: 4px;
    }

    /* Welcome sent colours */
    .cell-welcome-sent {
      background-color: #e4ffe4;
      border-radius: 4px;
    }

    .cell-welcome-not-sent {
      background-color: #fff0f0;
      border-radius: 4px;
    }

    .admin-alert ul {
      margin: 0.35rem 0 0 1rem;
    }
  </style>
</head>
<body class="admin-records" data-theme="light">
  <main class="admin-records__shell">
    <header class="admin-records__header">
      <div>
        <h1><?= $isEmployeePortal ? 'Customer Workspace' : 'User Management' ?></h1>
        <p class="admin-muted">
          <?= $isEmployeePortal
            ? 'Add and edit customers directly from the employee portal. Use the same trusted form the admin team uses.'
            : 'Manage customers and employees with a clean, ready-to-extend workspace.' ?>
        </p>
      </div>
      <div class="admin-records__meta">
        <a class="admin-link" href="<?= $isEmployeePortal ? 'employee-dashboard.php' : 'admin-dashboard.php' ?>">
          <i class="fa-solid fa-gauge-high"></i> Back to overview
        </a>
      </div>
    </header>

    <section class="admin-section">
      <div class="users-tabs" role="tablist" aria-label="User categories">
        <a
          class="users-tab__link<?= $activeTab === 'customers' ? ' is-active' : '' ?>"
          role="tab"
          aria-selected="<?= $activeTab === 'customers' ? 'true' : 'false' ?>"
          href="admin-users.php?tab=customers"
        >
          Customers
        </a>
        <?php if (!$isEmployeePortal): ?>
        <a
          class="users-tab__link<?= $activeTab === 'employees' ? ' is-active' : '' ?>"
          role="tab"
          aria-selected="<?= $activeTab === 'employees' ? 'true' : 'false' ?>"
          href="admin-users.php?tab=employees"
        >
          Employees
        </a>
        <?php endif; ?>
      </div>

      <?php if ($activeTab === 'customers'): ?>
      <section class="users-section" aria-labelledby="customers-heading">
        <header class="admin-section__header">
          <div>
            <h2 id="customers-heading">Customers</h2>
            <p class="admin-muted">Manage customers stored on disk. Add, review, and edit individual records.</p>
          </div>
        </header>

        <div class="users-toolbar">
          <div>
            <label class="sr-only" for="customer-search">Search customers</label>
            <input id="customer-search" class="users-input" type="search" placeholder="Search customers" />
          </div>
          <div>
            <label class="sr-only" for="customer-type">Customer type</label>
            <select id="customer-type" class="users-select">
              <option>Customer Type (PM Surya Ghar / Non-PM)</option>
            </select>
          </div>
          <div class="users-toolbar__actions">
            <a class="btn btn-secondary" href="#add-customer-form">Add Customer</a>
            <a class="btn btn-primary" href="#customer-bulk-upload">Bulk Upload (CSV)</a>
          </div>
        </div>

        <div id="customer-bulk-upload" class="users-card" aria-labelledby="bulk-upload-heading">
          <div class="users-card__header">
            <div>
              <h3 id="bulk-upload-heading">Bulk Upload (CSV)</h3>
              <p class="admin-muted">
                Upload a CSV file that matches the required header row. Mobile numbers must be unique; existing mobiles will be updated.
              </p>
            </div>
            <a class="btn btn-link" href="admin-users.php?tab=customers&download=customer_csv_template">Download sample CSV</a>
          </div>
          <?php if ($customerImportError !== ''): ?>
          <div class="admin-alert admin-alert--error" role="alert"><?php echo admin_users_safe($customerImportError); ?></div>
          <?php endif; ?>
          <?php if ($customerImportSummary !== null): ?>
          <div class="admin-alert admin-alert--success" role="status">
            <strong>Import completed.</strong>
            <div>New customers created: <?php echo (int) $customerImportSummary['created']; ?></div>
            <div>Existing customers updated: <?php echo (int) $customerImportSummary['updated']; ?></div>
            <div>Rows skipped due to errors: <?php echo (int) $customerImportSummary['skipped']; ?></div>
          </div>
          <?php if (!empty($customerImportSummary['errors'])): ?>
          <div class="admin-alert admin-alert--warning" role="status">
            <strong>Issues found:</strong>
            <ul>
              <?php foreach ($customerImportSummary['errors'] as $importError): ?>
              <li>Row <?php echo (int) $importError['line']; ?>: <?php echo admin_users_safe($importError['message']); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="users-form-grid">
            <input type="hidden" name="customer_action" value="import_customers" />
            <div>
              <label for="customer_csv">Upload CSV file</label>
              <input id="customer_csv" class="users-input" type="file" name="csv_file" accept=".csv,text/csv" required />
              <p class="admin-muted" style="margin-top: 0.35rem;">Required headers: mobile, name, customer_type, address, city, district, pin_code, state, meter_number, meter_serial_number, jbvnl_account_number, application_id, complaints_raised, status, application_submitted_date, sanction_load_kwp, installed_pv_module_capacity_kwp, circle_name, division_name, sub_division_name, loan_taken, loan_application_date, solar_plant_installation_date, subsidy_amount_rs, subsidy_disbursed_date, password. Optional headers: serial_number, welcome_sent_via.</p>
            </div>

            <div style="grid-column:1/-1">
              <label><input type="checkbox" name="can_access_admin_created_dcs" value="1" /> Allow access to delivery challans created by admin</label>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Upload and import</button>
            </div>
          </form>
        </div>

        <?php if ($customerSuccess !== ''): ?>
        <div class="admin-alert admin-alert--success" role="status"><?= admin_users_safe($customerSuccess) ?></div>
        <?php endif; ?>
        <?php if ($customerErrors !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <div><strong>There was a problem:</strong></div>
          <ul>
            <?php foreach ($customerErrors as $message): ?>
            <li><?= admin_users_safe($message) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div id="add-customer-form" class="users-card" aria-labelledby="add-customer-heading">
          <div class="users-card__header">
            <div>
              <h3 id="add-customer-heading">Add Customer</h3>
              <p class="admin-muted">Create a single customer entry with required details.</p>
            </div>
          </div>
          <form method="post" class="users-form">
            <input type="hidden" name="customer_action" value="create_customer" />
            <input type="hidden" name="welcome_sent_via" value="none" />

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">A. Basic Information</h4>
                <p class="admin-muted" style="margin: 0;">Only mobile, name, and password are required to get started.</p>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="mobile">Mobile number *</label>
                  <input id="mobile" class="users-input" name="mobile" type="text" required />
                </div>
                <div>
                  <label for="name">Name *</label>
                  <input id="name" class="users-input" name="name" type="text" required />
                </div>
                <div>
                  <label for="serial_number">Customer Serial / Installation No.</label>
                  <input id="serial_number" class="users-input" name="serial_number" type="text" value="" placeholder="Will be assigned automatically" readonly />
                </div>
                <div>
                  <label for="customer_type">Customer type</label>
                  <select id="customer_type" class="users-select" name="customer_type">
                    <option value="">Select type</option>
                    <option value="PM Surya Ghar">PM Surya Ghar</option>
                    <option value="Non PM Surya Ghar">Non PM Surya Ghar</option>
                  </select>
                </div>
                <div>
                  <label for="status">Status</label>
                  <select id="status" class="users-select" name="status">
                    <?php foreach ($customerStatuses as $statusOption): ?>
                      <option value="<?= admin_users_safe($statusOption) ?>"<?= $statusOption === 'New' ? ' selected' : '' ?>><?= admin_users_safe($statusOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="password">Password *</label>
                  <input id="password" class="users-input" name="password" type="password" minlength="6" required />
                  <p class="admin-muted" style="margin-top: 0.25rem;">Set a temporary password for the customer.</p>
                </div>
                <div>
                  <label for="confirm_password">Confirm password *</label>
                  <input id="confirm_password" class="users-input" name="confirm_password" type="password" minlength="6" required />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">B. Location &amp; Meter Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="address">Address</label>
                  <input id="address" class="users-input" name="address" type="text" />
                </div>
                <div>
                  <label for="city">City</label>
                  <input id="city" class="users-input" name="city" type="text" />
                </div>
                <div>
                  <label for="district">District</label>
                  <input id="district" class="users-input" name="district" type="text" />
                </div>
                <div>
                  <label for="pin_code">PIN code</label>
                  <input id="pin_code" class="users-input" name="pin_code" type="text" />
                </div>
                <div>
                  <label for="state">State</label>
                  <input id="state" class="users-input" name="state" type="text" />
                </div>
                <div>
                  <label for="meter_number">Meter number</label>
                  <input id="meter_number" class="users-input" name="meter_number" type="text" />
                </div>
                <div>
                  <label for="meter_serial_number">Meter serial number</label>
                  <input id="meter_serial_number" class="users-input" name="meter_serial_number" type="text" />
                </div>
                <div>
                  <label for="jbvnl_account_number">JBVNL account number</label>
                  <input id="jbvnl_account_number" class="users-input" name="jbvnl_account_number" type="text" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">C. PM Surya Ghar / Application Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="application_id">Application ID</label>
                  <input id="application_id" class="users-input" name="application_id" type="text" />
                </div>
                <div>
                  <label for="application_submitted_date">Application Submitted Date</label>
                  <input id="application_submitted_date" class="users-input" name="application_submitted_date" type="date" />
                </div>
                <div>
                  <label for="sanction_load_kwp">Sanction Load (kWp)</label>
                  <input id="sanction_load_kwp" class="users-input" name="sanction_load_kwp" type="text" />
                </div>
                <div>
                  <label for="installed_pv_module_capacity_kwp">Installed PV Module Capacity (kWp)</label>
                  <input id="installed_pv_module_capacity_kwp" class="users-input" name="installed_pv_module_capacity_kwp" type="text" />
                </div>
                <div>
                  <label for="circle_name">Circle Name</label>
                  <input id="circle_name" class="users-input" name="circle_name" type="text" />
                </div>
                <div>
                  <label for="division_name">Division Name</label>
                  <input id="division_name" class="users-input" name="division_name" type="text" />
                </div>
                <div>
                  <label for="sub_division_name">Sub Division Name</label>
                  <input id="sub_division_name" class="users-input" name="sub_division_name" type="text" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">D. Loan &amp; Financial Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="loan_taken">Loan Taken (Yes/No)</label>
                  <select id="loan_taken" class="users-select" name="loan_taken">
                    <option value="">Select an option</option>
                    <option value="Yes">Yes</option>
                    <option value="No">No</option>
                  </select>
                </div>
                <div>
                  <label for="loan_application_date">Loan Application Date</label>
                  <input id="loan_application_date" class="users-input" name="loan_application_date" type="date" />
                </div>
                <div>
                  <label for="solar_plant_installation_date">Solar Plant Installation Date</label>
                  <input id="solar_plant_installation_date" class="users-input" name="solar_plant_installation_date" type="date" />
                </div>
                <div>
                  <label for="subsidy_amount_rs">Subsidy Amount (Rs.)</label>
                  <input id="subsidy_amount_rs" class="users-input" name="subsidy_amount_rs" type="text" />
                </div>
                <div>
                  <label for="subsidy_disbursed_date">Subsidy Disbursed Date</label>
                  <input id="subsidy_disbursed_date" class="users-input" name="subsidy_disbursed_date" type="date" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">E. Complaint summary</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="complaints_raised">Complaints raised</label>
                  <select id="complaints_raised" class="users-select" name="complaints_raised">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                  </select>
                </div>
              </div>
            </div>


            <div style="grid-column:1/-1">
              <label><input type="checkbox" name="can_access_admin_created_dcs" value="1" <?= !empty($editingEmployee['can_access_admin_created_dcs']) ? 'checked' : '' ?> /> Allow access to delivery challans created by admin</label>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Add customer</button>
            </div>
          </form>
        </div>

        <?php if ($editingCustomer !== null): ?>
        <div class="users-card" aria-labelledby="edit-customer-heading">
          <div class="users-card__header">
            <div>
              <h3 id="edit-customer-heading">Edit Customer</h3>
              <p class="admin-muted">Update details for <?= admin_users_safe($editingCustomer['name'] ?? $editingCustomer['mobile']) ?>.</p>
            </div>
          </div>
          <form method="post" class="users-form">
            <input type="hidden" name="welcome_sent_via" value="<?= admin_users_safe($editingCustomer['welcome_sent_via'] ?? 'none') ?>" />
            <input type="hidden" name="original_mobile" value="<?= admin_users_safe($editingCustomer['mobile'] ?? '') ?>" />
            <input type="hidden" name="view_mobile" value="<?= admin_users_safe($editingCustomer['mobile'] ?? '') ?>" />

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">A. Basic Information</h4>
                <p class="admin-muted" style="margin: 0;">Leave password blank to keep the current one.</p>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="edit-mobile">Mobile number *</label>
                  <input id="edit-mobile" class="users-input" name="mobile" type="text" value="<?= admin_users_safe($editingCustomer['mobile'] ?? '') ?>" required readonly />
                </div>
                <div>
                  <label for="edit-name">Name *</label>
                  <input id="edit-name" class="users-input" name="name" type="text" value="<?= admin_users_safe($editingCustomer['name'] ?? '') ?>" required />
                </div>
                <div>
                  <label for="edit-serial_number">Customer Serial / Installation No.</label>
                  <input id="edit-serial_number" class="users-input" name="serial_number" type="text" value="<?= admin_users_safe($editingCustomer['serial_number'] ?? '') ?>" readonly />
                </div>
                <div>
                  <label for="edit-customer_type">Customer type</label>
                  <select id="edit-customer_type" class="users-select" name="customer_type">
                    <option value="">Select type</option>
                    <option value="PM Surya Ghar"<?= ($editingCustomer['customer_type'] ?? '') === 'PM Surya Ghar' ? ' selected' : '' ?>>PM Surya Ghar</option>
                    <option value="Non PM Surya Ghar"<?= ($editingCustomer['customer_type'] ?? '') === 'Non PM Surya Ghar' ? ' selected' : '' ?>>Non PM Surya Ghar</option>
                  </select>
                </div>
                <div>
                  <label for="edit-status">Status</label>
                  <select id="edit-status" class="users-select" name="status">
                    <?php foreach ($customerStatuses as $statusOption): ?>
                      <option value="<?= admin_users_safe($statusOption) ?>"<?= ($editingCustomer['status'] ?? 'New') === $statusOption ? ' selected' : '' ?>><?= admin_users_safe($statusOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="edit-password">New password (optional)</label>
                  <input id="edit-password" class="users-input" name="password" type="password" minlength="6" />
                  <p class="admin-muted" style="margin-top: 0.25rem;">To reset, enter and confirm a new password.</p>
                </div>
                <div>
                  <label for="edit-confirm_password">Confirm new password</label>
                  <input id="edit-confirm_password" class="users-input" name="confirm_password" type="password" minlength="6" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">B. Location &amp; Meter Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="edit-address">Address</label>
                  <input id="edit-address" class="users-input" name="address" type="text" value="<?= admin_users_safe($editingCustomer['address'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-city">City</label>
                  <input id="edit-city" class="users-input" name="city" type="text" value="<?= admin_users_safe($editingCustomer['city'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-district">District</label>
                  <input id="edit-district" class="users-input" name="district" type="text" value="<?= admin_users_safe($editingCustomer['district'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-pin_code">PIN code</label>
                  <input id="edit-pin_code" class="users-input" name="pin_code" type="text" value="<?= admin_users_safe($editingCustomer['pin_code'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-state">State</label>
                  <input id="edit-state" class="users-input" name="state" type="text" value="<?= admin_users_safe($editingCustomer['state'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-meter_number">Meter number</label>
                  <input id="edit-meter_number" class="users-input" name="meter_number" type="text" value="<?= admin_users_safe($editingCustomer['meter_number'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-meter_serial_number">Meter serial number</label>
                  <input id="edit-meter_serial_number" class="users-input" name="meter_serial_number" type="text" value="<?= admin_users_safe($editingCustomer['meter_serial_number'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-jbvnl_account_number">JBVNL account number</label>
                  <input id="edit-jbvnl_account_number" class="users-input" name="jbvnl_account_number" type="text" value="<?= admin_users_safe($editingCustomer['jbvnl_account_number'] ?? '') ?>" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">C. PM Surya Ghar / Application Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="edit-application_id">Application ID</label>
                  <input id="edit-application_id" class="users-input" name="application_id" type="text" value="<?= admin_users_safe($editingCustomer['application_id'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-application_submitted_date">Application Submitted Date</label>
                  <input id="edit-application_submitted_date" class="users-input" name="application_submitted_date" type="date" value="<?= admin_users_safe($editingCustomer['application_submitted_date'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-sanction_load_kwp">Sanction Load (kWp)</label>
                  <input id="edit-sanction_load_kwp" class="users-input" name="sanction_load_kwp" type="text" value="<?= admin_users_safe($editingCustomer['sanction_load_kwp'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-installed_pv_module_capacity_kwp">Installed PV Module Capacity (kWp)</label>
                  <input id="edit-installed_pv_module_capacity_kwp" class="users-input" name="installed_pv_module_capacity_kwp" type="text" value="<?= admin_users_safe($editingCustomer['installed_pv_module_capacity_kwp'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-circle_name">Circle Name</label>
                  <input id="edit-circle_name" class="users-input" name="circle_name" type="text" value="<?= admin_users_safe($editingCustomer['circle_name'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-division_name">Division Name</label>
                  <input id="edit-division_name" class="users-input" name="division_name" type="text" value="<?= admin_users_safe($editingCustomer['division_name'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-sub_division_name">Sub Division Name</label>
                  <input id="edit-sub_division_name" class="users-input" name="sub_division_name" type="text" value="<?= admin_users_safe($editingCustomer['sub_division_name'] ?? '') ?>" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">D. Loan &amp; Financial Details</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="edit-loan_taken">Loan Taken (Yes/No)</label>
                  <select id="edit-loan_taken" class="users-select" name="loan_taken">
                    <option value="">Select an option</option>
                    <option value="Yes"<?= ($editingCustomer['loan_taken'] ?? '') === 'Yes' ? ' selected' : '' ?>>Yes</option>
                    <option value="No"<?= ($editingCustomer['loan_taken'] ?? '') === 'No' ? ' selected' : '' ?>>No</option>
                  </select>
                </div>
                <div>
                  <label for="edit-loan_application_date">Loan Application Date</label>
                  <input id="edit-loan_application_date" class="users-input" name="loan_application_date" type="date" value="<?= admin_users_safe($editingCustomer['loan_application_date'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-solar_plant_installation_date">Solar Plant Installation Date</label>
                  <input id="edit-solar_plant_installation_date" class="users-input" name="solar_plant_installation_date" type="date" value="<?= admin_users_safe($editingCustomer['solar_plant_installation_date'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-subsidy_amount_rs">Subsidy Amount (Rs.)</label>
                  <input id="edit-subsidy_amount_rs" class="users-input" name="subsidy_amount_rs" type="text" value="<?= admin_users_safe($editingCustomer['subsidy_amount_rs'] ?? '') ?>" />
                </div>
                <div>
                  <label for="edit-subsidy_disbursed_date">Subsidy Disbursed Date</label>
                  <input id="edit-subsidy_disbursed_date" class="users-input" name="subsidy_disbursed_date" type="date" value="<?= admin_users_safe($editingCustomer['subsidy_disbursed_date'] ?? '') ?>" />
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">E. Complaint summary</h4>
              </div>
              <div class="users-form-grid">
                <div>
                  <label for="edit-complaints_raised">Complaints raised</label>
                  <select id="edit-complaints_raised" class="users-select" name="complaints_raised">
                    <option value="No"<?= ($editingCustomer['complaints_raised'] ?? '') === 'No' ? ' selected' : '' ?>>No</option>
                    <option value="Yes"<?= ($editingCustomer['complaints_raised'] ?? '') === 'Yes' ? ' selected' : '' ?>>Yes</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">F. Welcome / Communication</h4>
                <p class="admin-muted" style="margin: 0;">Welcome message includes the password only if you set it just before sending.</p>
              </div>
              <div class="users-form-grid">
                <div>
                  <label>Customer Serial / Installation No.</label>
                  <input class="users-input" type="text" value="<?= admin_users_safe($editingCustomer['serial_number'] ?? '') ?>" readonly />
                </div>
                <div>
                  <label>Welcome Sent</label>
                  <input class="users-input" type="text" value="<?= admin_users_safe(admin_users_display_welcome_status($editingCustomer['welcome_sent_via'] ?? '')) ?>" readonly />
                </div>
              </div>
              <div class="users-form-actions" style="gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn btn-secondary" type="submit" name="customer_action" value="send_welcome_whatsapp">Create WhatsApp Welcome Message</button>
                <button class="btn btn-secondary" type="submit" name="customer_action" value="send_welcome_email">Create Email Welcome Message</button>
              </div>
            </div>

            <?php
              $handoverOverrides = $editingCustomer['handover_overrides'] ?? handover_default_overrides();
              $handoverTemplatesMap = [
                'welcome_note' => 'welcome_note_template',
                'user_manual' => 'user_manual_template',
                'system_details' => 'system_details_template',
                'operation_maintenance' => 'operation_maintenance_template',
                'warranty_details' => 'warranty_details_template',
                'consumer_engagement' => 'consumer_engagement_template',
                'education_best_practices' => 'education_best_practices_template',
                'final_notes' => 'final_notes_template',
                'handover_acknowledgment' => 'handover_acknowledgment_template',
              ];

              $handoverDisplayValues = [];
              foreach ($handoverTemplatesMap as $overrideKey => $templateKey) {
                  $overrideValue = trim((string) ($handoverOverrides[$overrideKey] ?? ''));
                  $globalValue = (string) ($handoverTemplates[$templateKey] ?? '');
                  $handoverDisplayValues[$overrideKey] = $overrideValue !== '' ? $overrideValue : $globalValue;
              }

              $handoverPlaceholders = [
                '{{consumer_name}}',
                '{{address}}',
                '{{consumer_no}}',
                '{{mobile}}',
                '{{invoice_no}}',
                '{{premises_type}}',
                '{{scheme_type}}',
                '{{system_type}}',
                '{{system_capacity_kwp}}',
                '{{installation_date}}',
                '{{jbvnl_account_number}}',
                '{{application_id}}',
                '{{city}}',
                '{{district}}',
                '{{pin_code}}',
                '{{state}}',
                '{{circle_name}}',
                '{{division_name}}',
                '{{sub_division_name}}',
                '{{solar_plant_installation_date}}',
              ];
            ?>
            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">G. Handover Section Overrides (optional)</h4>
                <p class="admin-muted" style="margin: 0;">Leave any field blank to use the global template. Available placeholders: <?= admin_users_safe(implode(', ', $handoverPlaceholders)) ?>.</p>
              </div>
              <div class="users-form-grid" style="grid-template-columns: 1fr;">
                <div>
                  <label for="handover-welcome-note">Welcome Note</label>
                  <textarea id="handover-welcome-note" class="users-input" name="handover_overrides[welcome_note]" rows="6"><?= admin_users_safe($handoverDisplayValues['welcome_note'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-user-manual">User Manual</label>
                  <textarea id="handover-user-manual" class="users-input" name="handover_overrides[user_manual]" rows="6"><?= admin_users_safe($handoverDisplayValues['user_manual'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-system-details">System Details</label>
                  <textarea id="handover-system-details" class="users-input" name="handover_overrides[system_details]" rows="6"><?= admin_users_safe($handoverDisplayValues['system_details'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-operations">Operation &amp; Maintenance</label>
                  <textarea id="handover-operations" class="users-input" name="handover_overrides[operation_maintenance]" rows="6"><?= admin_users_safe($handoverDisplayValues['operation_maintenance'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-warranty">Warranty Details</label>
                  <textarea id="handover-warranty" class="users-input" name="handover_overrides[warranty_details]" rows="6"><?= admin_users_safe($handoverDisplayValues['warranty_details'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-engagement">Consumer Engagement / Benefits</label>
                  <textarea id="handover-engagement" class="users-input" name="handover_overrides[consumer_engagement]" rows="6"><?= admin_users_safe($handoverDisplayValues['consumer_engagement'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-education">Consumer Education &amp; Best Practices</label>
                  <textarea id="handover-education" class="users-input" name="handover_overrides[education_best_practices]" rows="6"><?= admin_users_safe($handoverDisplayValues['education_best_practices'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-final-notes">Final Notes &amp; Commitments</label>
                  <textarea id="handover-final-notes" class="users-input" name="handover_overrides[final_notes]" rows="6"><?= admin_users_safe($handoverDisplayValues['final_notes'] ?? '') ?></textarea>
                </div>
                <div>
                  <label for="handover-acknowledgment">Handover Acknowledgment</label>
                  <textarea id="handover-acknowledgment" class="users-input" name="handover_overrides[handover_acknowledgment]" rows="6"><?= admin_users_safe($handoverDisplayValues['handover_acknowledgment'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <?php
              $handoverHtmlPath = trim((string) ($editingCustomer['handover_html_path'] ?? ($editingCustomer['handover_document_path'] ?? '')));
            ?>
            <div class="users-form-section">
              <div class="users-form-section__header">
                <h4 class="users-form-section__title">Handover Document</h4>
                <p class="admin-muted" style="margin: 0;">Generate and share the customer handover pack.</p>
              </div>
              <div class="users-form-actions" style="gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn btn-primary" type="submit" name="customer_action" value="generate_handover">Generate Handover Document</button>
                <?php if ($handoverHtmlPath !== ''): ?>
                  <a class="btn btn-secondary" target="_blank" rel="noreferrer" href="<?= admin_users_safe('/' . ltrim($handoverHtmlPath, '/')) ?>">View Handover (HTML)</a>
                  <a class="btn btn-secondary" target="_blank" rel="noreferrer" href="<?= admin_users_safe('/' . ltrim($handoverHtmlPath, '/')) ?>">Print Handover Pack for <?= admin_users_safe($editingCustomer['name'] ?? 'Customer') ?></a>
                  <button class="btn btn-secondary" type="submit" name="customer_action" value="send_handover_whatsapp">WhatsApp Handover Link</button>
                <?php else: ?>
                  <button class="btn btn-secondary" type="submit" name="customer_action" value="send_handover_whatsapp" disabled>WhatsApp Handover Link</button>
                <?php endif; ?>
              </div>
              <?php if (($editingCustomer['handover_generated_at'] ?? '') !== ''): ?>
                <p class="admin-muted" style="margin: 0.35rem 0 0;">Last generated: <?= admin_users_safe((string) $editingCustomer['handover_generated_at']) ?></p>
              <?php endif; ?>
            </div>

            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit" name="customer_action" value="update_customer">Save changes</button>
            </div>
          </form>
        </div>

        <div class="users-card" aria-labelledby="customer-complaints-heading">
          <div class="users-card__header">
            <div>
              <h3 id="customer-complaints-heading">Complaints</h3>
              <p class="admin-muted">View and update complaints raised by this customer.</p>
            </div>
          </div>
          <form method="post" class="users-form-grid" style="margin-bottom: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <input type="hidden" name="customer_action" value="create_complaint" />
            <input type="hidden" name="view_mobile" value="<?= admin_users_safe($editingCustomer['mobile'] ?? '') ?>" />
            <div>
              <label for="complaint-title">Title *</label>
              <input id="complaint-title" class="users-input" name="title" type="text" required />
            </div>
            <div>
              <label for="complaint-category">Problem category *</label>
              <select id="complaint-category" class="users-select" name="problem_category" required>
                <option value="">Select a category</option>
                <?php foreach ($problemCategories as $category): ?>
                  <option value="<?= admin_users_safe($category) ?>"><?= admin_users_safe($category) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="complaint-assignee">Assignee *</label>
              <select id="complaint-assignee" class="users-select" name="assignee" required>
                <option value="">Select an assignee</option>
                <?php foreach ($assigneeOptions as $assignee): ?>
                  <option value="<?= admin_users_safe($assignee) ?>"><?= admin_users_safe($assignee) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="grid-column: 1 / -1;">
              <label for="complaint-description">Description *</label>
              <textarea id="complaint-description" class="users-input" name="description" rows="3" required></textarea>
            </div>
            <div class="users-form-actions" style="grid-column: 1 / -1;">
              <button class="btn btn-secondary" type="submit">Add Complaint</button>
            </div>
          </form>

          <?php if ($customerComplaints === []): ?>
            <p class="admin-muted" style="margin: 0;">No complaints recorded.</p>
          <?php else: ?>
            <table class="admin-table" aria-label="Customer complaints">
              <thead>
                <tr>
                  <th scope="col">ID</th>
                  <th scope="col">Title</th>
                  <th scope="col">Status</th>
                <th scope="col">Admin DC access</th>
                  <th scope="col">Problem Category</th>
                  <th scope="col">Assignee</th>
                  <th scope="col">Created</th>
                  <th scope="col">Updated</th>
                  <th scope="col" class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($customerComplaints as $complaint): ?>
                  <?php $assigneeLabel = complaint_display_assignee($complaint['assignee'] ?? ''); ?>
                  <tr>
                    <td><?= admin_users_safe((string) ($complaint['id'] ?? '')) ?></td>
                    <td><?= admin_users_safe($complaint['title'] ?? '') ?></td>
                    <td><?= admin_users_safe(ucfirst((string) ($complaint['status'] ?? ''))) ?></td>
                    <td><?= admin_users_safe($complaint['problem_category'] ?? complaint_default_category()) ?></td>
                    <td>
                      <span style="font-weight: 700; color: <?= $assigneeLabel === 'Unassigned' ? '#d97706' : '#111827' ?>;">
                        <?= admin_users_safe($assigneeLabel) ?>
                        <?php if ($assigneeLabel === 'Unassigned'): ?>
                          <span class="admin-tag" style="margin-left: 0.4rem; background: #fef3c7; color: #92400e;">Needs assignment</span>
                        <?php endif; ?>
                      </span>
                    </td>
                    <td><?= admin_users_safe($complaint['created_at'] ?? '') ?></td>
                    <td><?= admin_users_safe($complaint['updated_at'] ?? '') ?></td>
                    <td class="text-right">
                      <form method="post" class="admin-inline-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.35rem; align-items: center;">
                        <input type="hidden" name="customer_action" value="update_complaint" />
                        <input type="hidden" name="view_mobile" value="<?= admin_users_safe($editingCustomer['mobile'] ?? '') ?>" />
                        <input type="hidden" name="complaint_id" value="<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>" />
                        <label class="sr-only" for="status-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>">Status</label>
                        <select id="status-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>" class="users-select" name="status">
                          <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'closed' => 'Closed'] as $statusValue => $statusLabel): ?>
                            <option value="<?= $statusValue ?>"<?= strtolower((string) ($complaint['status'] ?? '')) === $statusValue ? ' selected' : '' ?>><?= $statusLabel ?></option>
                          <?php endforeach; ?>
                        </select>
                        <label class="sr-only" for="category-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>">Problem category</label>
                        <select id="category-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>" class="users-select" name="problem_category">
                          <?php foreach ($problemCategories as $category): ?>
                            <option value="<?= admin_users_safe($category) ?>"<?= ($complaint['problem_category'] ?? complaint_default_category()) === $category ? ' selected' : '' ?>><?= admin_users_safe($category) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <label class="sr-only" for="assignee-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>">Assignee</label>
                        <select id="assignee-<?= admin_users_safe((string) ($complaint['id'] ?? '')) ?>" class="users-select" name="assignee">
                          <option value="">Unassigned</option>
                          <?php foreach ($assigneeOptions as $assignee): ?>
                            <option value="<?= admin_users_safe($assignee) ?>"<?= ($complaint['assignee'] ?? '') === $assignee ? ' selected' : '' ?>><?= admin_users_safe($assignee) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-secondary" type="submit" style="justify-self: end;">Update</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="admin-table-wrapper">
              <table class="users-table" aria-label="Customer list">
            <thead>
              <tr>
                <th scope="col">Serial</th>
                <th scope="col">Mobile number</th>
                <th scope="col">Name</th>
                <th scope="col">Customer type</th>
                <th scope="col">City</th>
                <th scope="col">Status</th>
                <th scope="col">Welcome Sent</th>
                <th scope="col">Complaint flag</th>
                <th scope="col" class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($customers === []): ?>
              <tr>
                <td colspan="9" class="text-center admin-muted">No customers found.</td>
              </tr>
              <?php else: ?>
              <?php foreach ($customers as $customer): ?>
              <?php
                $customerType = trim((string) ($customer['customer_type'] ?? ''));
                $typeKey = strtolower($customerType);
                $typeClass = 'badge-customer-type ' . ($typeKey === 'pm surya ghar' ? 'badge-pm-surya-ghar' : 'badge-non-pm-surya-ghar');

                $statusRaw = trim((string) ($customer['status'] ?? ''));
                $statusKey = strtolower(str_replace(' ', '-', $statusRaw));
                $statusClass = 'badge-status';
                switch ($statusKey) {
                  case 'new':
                    $statusClass .= ' badge-status-new';
                    break;
                  case 'survey-pending':
                    $statusClass .= ' badge-status-survey-pending';
                    break;
                  case 'survey-done':
                    $statusClass .= ' badge-status-survey-done';
                    break;
                  case 'installation-pending':
                    $statusClass .= ' badge-status-installation-pending';
                    break;
                  case 'installation-in-progress':
                    $statusClass .= ' badge-status-installation-in-progress';
                    break;
                  case 'complete':
                  case 'completed':
                    $statusClass .= ' badge-status-complete';
                    break;
                  default:
                    break;
                }

                $welcomeSentVia = strtolower(trim((string) ($customer['welcome_sent_via'] ?? '')));
                $welcomeSent = $welcomeSentVia !== '' && $welcomeSentVia !== 'none';
                $welcomeClass = $welcomeSent ? 'cell-welcome-sent' : 'cell-welcome-not-sent';

                $complaintValue = strtolower(trim((string) ($customer['complaints_raised'] ?? 'no')));
                $hasComplaint = in_array($complaintValue, ['yes', 'y', '1'], true);
                $complaintClass = $hasComplaint ? 'cell-yes' : 'cell-no';
              ?>
              <tr>
                <td><?= admin_users_safe((string) ($customer['serial_number'] ?? '')) ?></td>
                <td><?= admin_users_safe($customer['mobile'] ?? '') ?></td>
                <td><?= admin_users_safe($customer['name'] ?? '') ?></td>
                <td><span class="<?= admin_users_safe($typeClass) ?>"><?= admin_users_safe($customerType) ?></span></td>
                <td><?= admin_users_safe($customer['city'] ?? '') ?></td>
                <td><span class="<?= admin_users_safe($statusClass) ?>"><?= admin_users_safe($statusRaw) ?></span></td>
                <td class="<?= admin_users_safe($welcomeClass) ?>"><?= admin_users_safe(admin_users_display_welcome_status($customer['welcome_sent_via'] ?? '')) ?></td>
                <td class="<?= admin_users_safe($complaintClass) ?>"><span class="users-status"><?= $hasComplaint ? 'Yes' : 'No' ?></span></td>
                <td class="users-actions text-right"><a href="admin-users.php?tab=customers&amp;view=<?= urlencode((string) ($customer['mobile'] ?? '')) ?>">View / Edit</a></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php else: ?>
      <section class="users-section" aria-labelledby="employees-heading">
        <header class="admin-section__header">
          <div>
            <h2 id="employees-heading">Employees</h2>
            <p class="admin-muted">Manage employee records stored on disk. Add, review, and edit individual employees.</p>
          </div>
        </header>

        <div class="users-toolbar">
          <div>
            <label class="sr-only" for="employee-search">Search employees</label>
            <input id="employee-search" class="users-input" type="search" placeholder="Search employees" />
          </div>
          <div>
            <label class="sr-only" for="employee-role">Designation</label>
            <select id="employee-role" class="users-select">
              <option>Filter by designation</option>
            </select>
          </div>
          <div class="users-toolbar__actions">
            <a class="btn btn-secondary" href="#add-employee-form">Add Employee</a>
          </div>
        </div>

        <?php if ($employeeSuccess !== ''): ?>
        <div class="admin-alert admin-alert--success" role="status"><?= admin_users_safe($employeeSuccess) ?></div>
        <?php endif; ?>
        <?php if ($employeeErrors !== []): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          <div><strong>There was a problem:</strong></div>
          <ul>
            <?php foreach ($employeeErrors as $message): ?>
            <li><?= admin_users_safe($message) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div id="add-employee-form" class="users-card" aria-labelledby="add-employee-heading">
          <div class="users-card__header">
            <div>
              <h3 id="add-employee-heading">Add Employee</h3>
              <p class="admin-muted">Create a new employee profile for future login enablement.</p>
            </div>
          </div>
          <form method="post" class="users-form-grid">
            <input type="hidden" name="employee_action" value="create_employee" />
            <div>
              <label for="employee-name">Name *</label>
              <input id="employee-name" class="users-input" name="name" type="text" required />
            </div>
            <div>
              <label for="employee-login">Login ID *</label>
              <input id="employee-login" class="users-input" name="login_id" type="text" required />
            </div>
            <div>
              <label for="employee-phone">Phone</label>
              <input id="employee-phone" class="users-input" name="phone" type="text" />
            </div>
            <div>
              <label for="employee-designation">Designation</label>
              <input id="employee-designation" class="users-input" name="designation" type="text" />
            </div>
            <div>
              <label for="employee-password">Password (optional)</label>
              <input id="employee-password" class="users-input" name="password" type="password" minlength="6" />
              <p class="admin-muted" style="margin-top: 0.25rem;">Leave blank to keep the password unset.</p>
            </div>
            <div>
              <label for="employee-password-confirm">Confirm Password</label>
              <input id="employee-password-confirm" class="users-input" name="confirm_password" type="password" minlength="6" />
            </div>
            <div>
              <label for="employee-status">Status</label>
              <select id="employee-status" class="users-select" name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div style="grid-column:1/-1">
              <label><input type="checkbox" name="can_access_admin_created_dcs" value="1" /> Allow access to delivery challans created by admin</label>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Add employee</button>
            </div>
          </form>
        </div>

        <?php if ($editingEmployee !== null): ?>
        <div class="users-card" aria-labelledby="edit-employee-heading">
          <div class="users-card__header">
            <div>
              <h3 id="edit-employee-heading">Edit Employee</h3>
              <p class="admin-muted">Update details for <?= admin_users_safe($editingEmployee['name'] ?? $editingEmployee['login_id'] ?? '') ?>.</p>
            </div>
          </div>
          <form method="post" class="users-form-grid">
            <input type="hidden" name="employee_action" value="update_employee" />
            <input type="hidden" name="employee_id" value="<?= admin_users_safe($editingEmployee['id'] ?? '') ?>" />
            <div>
              <label for="edit-employee-name">Name *</label>
              <input id="edit-employee-name" class="users-input" name="name" type="text" value="<?= admin_users_safe($editingEmployee['name'] ?? '') ?>" required />
            </div>
            <div>
              <label for="edit-employee-login">Login ID *</label>
              <input id="edit-employee-login" class="users-input" name="login_id" type="text" value="<?= admin_users_safe($editingEmployee['login_id'] ?? '') ?>" required />
            </div>
            <div>
              <label for="edit-employee-phone">Phone</label>
              <input id="edit-employee-phone" class="users-input" name="phone" type="text" value="<?= admin_users_safe($editingEmployee['phone'] ?? '') ?>" />
            </div>
            <div>
              <label for="edit-employee-designation">Designation</label>
              <input id="edit-employee-designation" class="users-input" name="designation" type="text" value="<?= admin_users_safe($editingEmployee['designation'] ?? '') ?>" />
            </div>
            <div>
              <label for="edit-employee-password">Password (optional)</label>
              <input id="edit-employee-password" class="users-input" name="password" type="password" minlength="6" />
              <p class="admin-muted" style="margin-top: 0.25rem;">Leave blank to keep the existing password unchanged.</p>
            </div>
            <div>
              <label for="edit-employee-password-confirm">Confirm Password</label>
              <input id="edit-employee-password-confirm" class="users-input" name="confirm_password" type="password" minlength="6" />
            </div>
            <div>
              <label for="edit-employee-status">Status</label>
              <select id="edit-employee-status" class="users-select" name="status">
                <option value="active"<?= ($editingEmployee['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="inactive"<?= ($editingEmployee['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
              </select>
            </div>
            <div style="grid-column:1/-1">
              <label><input type="checkbox" name="can_access_admin_created_dcs" value="1" <?= !empty($editingEmployee['can_access_admin_created_dcs']) ? 'checked' : '' ?> /> Allow access to delivery challans created by admin</label>
            </div>
            <div class="users-form-actions">
              <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <div class="admin-table-wrapper">
          <table class="users-table" aria-label="Employee list">
            <thead>
              <tr>
                <th scope="col">Employee name</th>
                <th scope="col">Login ID</th>
                <th scope="col">Designation</th>
                <th scope="col">Status</th>
                <th scope="col">Admin DC access</th>
                <th scope="col" class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($employees === []): ?>
              <tr>
                <td colspan="6" class="text-center admin-muted">No employees found.</td>
              </tr>
              <?php else: ?>
              <?php foreach ($employees as $employee): ?>
              <tr>
                <td><?= admin_users_safe($employee['name'] ?? '') ?></td>
                <td><?= admin_users_safe($employee['login_id'] ?? '') ?></td>
                <td><?= admin_users_safe($employee['designation'] ?? '') ?></td>
                <td><span class="users-status"><?= ($employee['status'] ?? '') === 'inactive' ? 'Inactive' : 'Active' ?></span></td>
                <td><?= !empty($employee['can_access_admin_created_dcs']) ? 'Allowed' : 'No' ?></td>
                <td class="users-actions text-right"><a href="admin-users.php?tab=employees&amp;view=<?= urlencode((string) ($employee['id'] ?? '')) ?>">View / Edit</a></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
