<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

ini_set('display_errors', '0');
documents_ensure_structure();

$employeeStore = new EmployeeFsStore();
$user = current_user();
$viewerType = '';
$viewerId = '';
$viewerName = '';

if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
    $viewerName = (string) ($user['full_name'] ?? 'Admin');
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
        $viewerName = (string) ($employee['name'] ?? 'Employee');
    }
}

if ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$mode = safe_text($_GET['mode'] ?? 'html');
$agreement = documents_get_agreement($id);
if ($agreement === null) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

$linkedQuoteId = safe_text((string) ($agreement['linked_quote_id'] ?? ''));
$linkedQuote = $linkedQuoteId !== '' ? documents_get_quote($linkedQuoteId) : null;

if ($viewerType === 'employee') {
    $canViewByAgreementOwner = ((string) ($agreement['created_by_type'] ?? '') === 'employee') && ((string) ($agreement['created_by_id'] ?? '') === $viewerId);
    $canViewByQuoteOwner = is_array($linkedQuote)
        && ((string) ($linkedQuote['created_by_type'] ?? '') === 'employee')
        && ((string) ($linkedQuote['created_by_id'] ?? '') === $viewerId);

    if (!$canViewByAgreementOwner && !$canViewByQuoteOwner) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

$redirectWith = static function (string $status, string $message) use ($id): void {
    header('Location: agreement-view.php?id=' . urlencode($id) . '&mode=edit&status=' . urlencode($status) . '&message=' . urlencode($message));
    exit;
};

if ($mode === 'edit') {
    if ($viewerType !== 'admin') {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            $redirectWith('error', 'Security validation failed.');
        }

        $action = safe_text($_POST['action'] ?? '');
        if (in_array($action, ['save', 'mark_final', 'archive'], true)) {
            $agreement['execution_date'] = safe_text($_POST['execution_date'] ?? $agreement['execution_date']);
            $agreement['system_capacity_kwp'] = safe_text($_POST['system_capacity_kwp'] ?? $agreement['system_capacity_kwp']);
            $agreement['total_cost'] = safe_text($_POST['total_cost'] ?? $agreement['total_cost']);
            $agreement['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? $agreement['consumer_account_no']);
            $agreement['consumer_address'] = safe_text($_POST['consumer_address'] ?? $agreement['consumer_address']);
            $agreement['site_address'] = safe_text($_POST['site_address'] ?? $agreement['site_address']);
            $agreement['party_snapshot']['customer_mobile'] = (string) $agreement['customer_mobile'];
            $agreement['party_snapshot']['customer_name'] = (string) $agreement['customer_name'];
            $agreement['party_snapshot']['consumer_account_no'] = (string) $agreement['consumer_account_no'];
            $agreement['party_snapshot']['consumer_address'] = (string) $agreement['consumer_address'];
            $agreement['party_snapshot']['site_address'] = (string) $agreement['site_address'];
            $agreement['party_snapshot']['system_capacity_kwp'] = (string) $agreement['system_capacity_kwp'];
            $agreement['party_snapshot']['total_cost'] = (string) $agreement['total_cost'];

            $agreement['overrides']['fields_override']['execution_date'] = safe_text($_POST['override_execution_date'] ?? '');
            $agreement['overrides']['fields_override']['system_capacity_kwp'] = safe_text($_POST['override_system_capacity_kwp'] ?? '');
            $agreement['overrides']['fields_override']['total_cost'] = safe_text($_POST['override_total_cost'] ?? '');
            $agreement['overrides']['fields_override']['consumer_account_no'] = safe_text($_POST['override_consumer_account_no'] ?? '');
            $agreement['overrides']['fields_override']['consumer_address'] = safe_text($_POST['override_consumer_address'] ?? '');
            $agreement['overrides']['fields_override']['site_address'] = safe_text($_POST['override_site_address'] ?? '');

            $agreement['overrides']['html_override'] = trim((string) ($_POST['html_override'] ?? ''));
            $agreement['rendering']['background_image'] = safe_text($_POST['background_image'] ?? '');
            $agreement['rendering']['background_opacity'] = max(0.1, min(1.0, (float) ($_POST['background_opacity'] ?? 1)));

            if ($action === 'mark_final') {
                $agreement['status'] = 'Final';
            } elseif ($action === 'archive') {
                $agreement['status'] = 'Archived';
            }

            $agreement['updated_at'] = date('c');
            $saved = documents_save_agreement($agreement);
            if (!$saved['ok']) {
                $redirectWith('error', 'Unable to save agreement changes.');
            }

            $msg = 'Agreement saved.';
            if ($action === 'mark_final') {
                $msg = 'Agreement marked as Final.';
            } elseif ($action === 'archive') {
                $msg = 'Agreement archived.';
            }
            $redirectWith('success', $msg);
        }
    }

    $company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
    $previewHtml = quotation_sanitize_html(documents_render_agreement_body_html($agreement, $company));
    $status = safe_text($_GET['status'] ?? '');
    $message = safe_text($_GET['message'] ?? '');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agreement <?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?></title>
  <style>
    body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{max-width:1180px;margin:0 auto;padding:16px}
    .card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}
    .btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}.btn.warn{background:#b91c1c}
    input,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box} textarea{min-height:90px}
    .banner{padding:9px;border-radius:8px;margin-bottom:10px}.success{background:#ecfdf5;color:#065f46}.error{background:#fef2f2;color:#991b1b}
    .preview{border:1px solid #dbe1ea;border-radius:8px;padding:12px;background:#fff}
    .muted{color:#64748b;font-size:12px}
  </style>
</head>
<body>
<main class="wrap">
  <?php if ($message !== '' && ($status === 'success' || $status === 'error')): ?><div class="banner <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

  <div class="card">
    <h1 style="margin:0 0 10px 0">Agreement View</h1>
    <p><strong><?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?></strong> · Status: <?= htmlspecialchars((string) $agreement['status'], ENT_QUOTES) ?></p>
    <a class="btn secondary" href="admin-agreements.php">Back to Agreements</a>
    <a class="btn secondary" href="agreement-view.php?id=<?= urlencode($id) ?>" target="_blank" rel="noopener">View as HTML</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
    <div class="card">
      <h2 style="margin-top:0">Core Details</h2>
      <div class="grid">
        <div><label>Customer Name</label><input disabled value="<?= htmlspecialchars((string) $agreement['customer_name'], ENT_QUOTES) ?>"></div>
        <div><label>Customer Mobile</label><input disabled value="<?= htmlspecialchars((string) $agreement['customer_mobile'], ENT_QUOTES) ?>"></div>
        <div><label>Linked Quote</label><input disabled value="<?= htmlspecialchars((string) ($agreement['linked_quote_no'] ?: '-'), ENT_QUOTES) ?>"></div>
        <div><label>Execution Date</label><input type="date" name="execution_date" value="<?= htmlspecialchars((string) $agreement['execution_date'], ENT_QUOTES) ?>"></div>
        <div><label>System Capacity (kWp)</label><input name="system_capacity_kwp" value="<?= htmlspecialchars((string) $agreement['system_capacity_kwp'], ENT_QUOTES) ?>"></div>
        <div><label>Total Cost</label><input name="total_cost" value="<?= htmlspecialchars((string) $agreement['total_cost'], ENT_QUOTES) ?>"></div>
        <div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string) $agreement['consumer_account_no'], ENT_QUOTES) ?>"></div>
        <div style="grid-column:1/-1"><label>Consumer Address</label><textarea name="consumer_address"><?= htmlspecialchars((string) $agreement['consumer_address'], ENT_QUOTES) ?></textarea></div>
        <div style="grid-column:1/-1"><label>Consumer Site Address</label><textarea name="site_address"><?= htmlspecialchars((string) $agreement['site_address'], ENT_QUOTES) ?></textarea></div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin-top:0">Field Overrides (optional)</h2>
      <p class="muted">If override is non-empty, it is used in HTML placeholders.</p>
      <div class="grid">
        <div><label>Override Execution Date</label><input name="override_execution_date" value="<?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['execution_date'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Override kWp</label><input name="override_system_capacity_kwp" value="<?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['system_capacity_kwp'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Override Total Cost</label><input name="override_total_cost" value="<?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['total_cost'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Override Account No</label><input name="override_consumer_account_no" value="<?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['consumer_account_no'] ?? ''), ENT_QUOTES) ?>"></div>
        <div style="grid-column:1/-1"><label>Override Consumer Address</label><textarea name="override_consumer_address"><?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['consumer_address'] ?? ''), ENT_QUOTES) ?></textarea></div>
        <div style="grid-column:1/-1"><label>Override Site Address</label><textarea name="override_site_address"><?= htmlspecialchars((string) ($agreement['overrides']['fields_override']['site_address'] ?? ''), ENT_QUOTES) ?></textarea></div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin-top:0">Agreement Text Override (optional)</h2>
      <textarea name="html_override" style="min-height:220px"><?= htmlspecialchars((string) ($agreement['overrides']['html_override'] ?? ''), ENT_QUOTES) ?></textarea>
      <p class="muted">Leave empty to use default template. Placeholders remain supported.</p>
      <div class="grid">
        <div><label>Background Image</label><input name="background_image" value="<?= htmlspecialchars((string) ($agreement['rendering']['background_image'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Background Opacity</label><input name="background_opacity" type="number" min="0.1" max="1" step="0.05" value="<?= htmlspecialchars((string) ($agreement['rendering']['background_opacity'] ?? 1), ENT_QUOTES) ?>"></div>
      </div>
    </div>

    <div class="card">
      <button class="btn" name="action" value="save" type="submit">Save Changes</button>
      <button class="btn secondary" name="action" value="mark_final" type="submit">Mark Final</button>
      <button class="btn warn" name="action" value="archive" type="submit">Archive</button>
    </div>
  </form>

  <div class="card">
    <h2 style="margin-top:0">Rendered Preview</h2>
    <div class="preview"><?= $previewHtml ?></div>
  </div>
</main>
</body>
</html>
    <?php
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$customer = null;
if ((string) ($agreement['customer_mobile'] ?? '') !== '') {
    $customerStore = new CustomerFsStore();
    $customer = $customerStore->findByMobile((string) ($agreement['customer_mobile'] ?? ''));
}

if (is_array($linkedQuote)) {
    $agreement['customer_name'] = safe_text((string) ($agreement['customer_name'] ?: ($linkedQuote['customer_name'] ?? '')));
    $agreement['execution_date'] = safe_text((string) ($agreement['execution_date'] ?: ($linkedQuote['accepted_at'] ?? '')));
    $agreement['system_capacity_kwp'] = safe_text((string) ($agreement['system_capacity_kwp'] ?: ($linkedQuote['capacity_kwp'] ?? '')));
    $agreement['total_cost'] = safe_text((string) ($agreement['total_cost'] ?: ($linkedQuote['input_total_gst_inclusive'] ?? '')));
}
if (is_array($customer)) {
    $agreement['consumer_account_no'] = safe_text((string) ($agreement['consumer_account_no'] ?: ($customer['jbvnl_account_number'] ?? '')));
    $agreement['consumer_address'] = safe_text((string) ($agreement['consumer_address'] ?: ($customer['address'] ?? '')));
    $agreement['site_address'] = safe_text((string) ($agreement['site_address'] ?: ($customer['address'] ?? '')));
}

$previewHtml = quotation_sanitize_html(documents_render_agreement_body_html($agreement, $company));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agreement <?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?></title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #e5e7eb; }
    .a4-page { width: 210mm; min-height: 297mm; margin: 8mm auto; padding: 15mm; box-sizing: border-box; background: #fff; color: #111827; }
    .a4-page p { line-height: 1.45; margin: 0 0 8px; }
    .a4-page table { width: 100%; border-collapse: collapse; }
    .a4-page td, .a4-page th { border: 1px solid #111827; padding: 6px; vertical-align: top; }
    @media print {
      @page { size: A4; margin: 0; }
      body { background: #fff; }
      .a4-page { margin: 0; width: auto; min-height: auto; padding: 10mm; box-shadow: none; }
    }
  </style>
</head>
<body>
<div class="a4-page"><?= $previewHtml ?></div>
</body>
</html>
