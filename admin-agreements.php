<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$templates = documents_get_agreement_templates();
$activeTemplates = array_filter($templates, static fn($row): bool => is_array($row) && !($row['archived_flag'] ?? false));
if ($activeTemplates === []) {
    $defaults = documents_agreement_template_defaults();
    $activeTemplates = $defaults;
    documents_save_agreement_templates($defaults);
}

$redirectWith = static function (string $type, string $message): void {
    header('Location: admin-agreements.php?' . http_build_query(['status' => $type, 'message' => $message]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'create_agreement') {
        $customerMobile = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $customerName = safe_text($_POST['customer_name'] ?? '');
        $consumerAccountNo = safe_text($_POST['consumer_account_no'] ?? '');
        $consumerAddress = safe_text($_POST['consumer_address'] ?? '');
        $siteAddress = safe_text($_POST['site_address'] ?? '');
        $executionDate = safe_text($_POST['execution_date'] ?? '');
        $capacity = safe_text($_POST['system_capacity_kwp'] ?? '');
        $totalCost = safe_text($_POST['total_cost'] ?? '');
        $templateId = safe_text($_POST['template_id'] ?? 'default_pm_surya_ghar_agreement');
        $linkedQuoteId = safe_text($_POST['linked_quote_id'] ?? '');
        $backgroundImage = safe_text($_POST['background_image'] ?? '');
        $backgroundOpacity = (float) ($_POST['background_opacity'] ?? 1);
        $backgroundOpacity = max(0.1, min(1.0, $backgroundOpacity));

        if ($customerMobile === '' || $customerName === '') {
            $redirectWith('error', 'Customer mobile and customer name are required.');
        }
        if ($executionDate === '' || $capacity === '' || $totalCost === '') {
            $redirectWith('error', 'Execution date, kWp, and total cost are required.');
        }
        if (!isset($templates[$templateId])) {
            $redirectWith('error', 'Selected agreement template not found.');
        }

        $quote = null;
        if ($linkedQuoteId !== '') {
            $quote = documents_get_quote($linkedQuoteId);
            if ($quote !== null) {
                $linkedQuoteId = (string) ($quote['id'] ?? '');
            } else {
                $linkedQuoteId = '';
            }
        }

        $quoteSnapshot = $quote !== null ? documents_quote_resolve_snapshot($quote) : null;
        if ($quoteSnapshot !== null) {
            $customerMobile = $customerMobile !== '' ? $customerMobile : (string) ($quoteSnapshot['mobile'] ?? '');
            $customerName = $customerName !== '' ? $customerName : (string) ($quoteSnapshot['name'] ?? '');
            $consumerAccountNo = $consumerAccountNo !== '' ? $consumerAccountNo : (string) ($quote['consumer_account_no'] ?? ($quoteSnapshot['consumer_account_no'] ?? ''));
            $siteAddress = $siteAddress !== '' ? $siteAddress : (string) ($quote['site_address'] ?? ($quoteSnapshot['address'] ?? ''));
            $consumerAddress = $consumerAddress !== '' ? $consumerAddress : $siteAddress;
            $capacity = $capacity !== '' ? $capacity : safe_text((string) ($quote['capacity_kwp'] ?? ''));
            $totalCost = $totalCost !== '' ? $totalCost : documents_format_money_indian((float) ($quote['calc']['grand_total'] ?? 0));
        } else {
            $lookupCustomer = $customerMobile !== '' ? documents_find_customer_by_mobile($customerMobile) : null;
            if ($lookupCustomer !== null) {
                $customerName = $customerName !== '' ? $customerName : (string) ($lookupCustomer['name'] ?? '');
                $consumerAccountNo = $consumerAccountNo !== '' ? $consumerAccountNo : (string) ($lookupCustomer['consumer_account_no'] ?? '');
                $siteAddress = $siteAddress !== '' ? $siteAddress : (string) ($lookupCustomer['address'] ?? '');
                $consumerAddress = $consumerAddress !== '' ? $consumerAddress : $siteAddress;
            }
        }

        $number = documents_generate_agreement_number('RES');
        if (!$number['ok']) {
            $redirectWith('error', (string) ($number['error'] ?: 'Unable to generate agreement number.'));
        }

        $id = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $agreement = documents_agreement_defaults();
        $agreement['id'] = $id;
        $agreement['agreement_no'] = (string) $number['agreement_no'];
        $agreement['status'] = 'Draft';
        $agreement['template_id'] = $templateId;
        $agreement['customer_mobile'] = $customerMobile;
        $agreement['customer_name'] = $customerName;
        $agreement['consumer_account_no'] = $consumerAccountNo;
        $agreement['consumer_address'] = $consumerAddress;
        $agreement['site_address'] = $siteAddress;
        $agreement['execution_date'] = $executionDate;
        $agreement['system_capacity_kwp'] = $capacity;
        $agreement['total_cost'] = $totalCost;
        $agreement['linked_quote_id'] = $linkedQuoteId;
        $agreement['linked_quote_no'] = (string) ($quote['quote_no'] ?? '');
        $agreement['district'] = (string) (($quoteSnapshot['district'] ?? '') ?: ($lookupCustomer['district'] ?? ''));
        $agreement['city'] = (string) (($quoteSnapshot['city'] ?? '') ?: ($lookupCustomer['city'] ?? ''));
        $agreement['state'] = (string) (($quoteSnapshot['state'] ?? '') ?: ($lookupCustomer['state'] ?? ''));
        $agreement['pin_code'] = (string) (($quoteSnapshot['pin_code'] ?? '') ?: ($lookupCustomer['pin_code'] ?? ''));
        $agreement['party_snapshot'] = [
            'customer_mobile' => $customerMobile,
            'customer_name' => $customerName,
            'consumer_account_no' => $consumerAccountNo,
            'consumer_address' => $consumerAddress,
            'site_address' => $siteAddress,
            'district' => (string) (($quoteSnapshot['district'] ?? '') ?: ($lookupCustomer['district'] ?? '')),
            'city' => (string) (($quoteSnapshot['city'] ?? '') ?: ($lookupCustomer['city'] ?? '')),
            'state' => (string) (($quoteSnapshot['state'] ?? '') ?: ($lookupCustomer['state'] ?? '')),
            'pin_code' => (string) (($quoteSnapshot['pin_code'] ?? '') ?: ($lookupCustomer['pin_code'] ?? '')),
            'system_capacity_kwp' => $capacity,
            'total_cost' => $totalCost,
        ];
        $agreement['rendering']['background_image'] = $backgroundImage;
        $agreement['rendering']['background_opacity'] = $backgroundOpacity;
        $user = current_user();
        $agreement['created_by_type'] = 'admin';
        $agreement['created_by_id'] = (string) ($user['id'] ?? '');
        $agreement['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $agreement['created_at'] = date('c');
        $agreement['updated_at'] = date('c');

        $saved = documents_save_agreement($agreement);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to save agreement record.');
        }

        header('Location: agreement-view.php?id=' . urlencode($id) . '&status=success&message=' . urlencode('Agreement created successfully.'));
        exit;
    }


    if ($action === 'save_template') {
        $templateId = safe_text($_POST['template_id'] ?? 'default_pm_surya_ghar_agreement');
        $rows = documents_get_agreement_templates();
        if (!isset($rows[$templateId]) || !is_array($rows[$templateId])) {
            $redirectWith('error', 'Template not found.');
        }
        $rows[$templateId]['name'] = safe_text($_POST['template_name'] ?? (string) ($rows[$templateId]['name'] ?? ''));
        $rows[$templateId]['html_template'] = trim((string) ($_POST['html_template'] ?? ''));
        $rows[$templateId]['updated_at'] = date('c');
        $saved = documents_save_agreement_templates($rows);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to save agreement template.');
        }
        $redirectWith('success', 'Agreement template saved.');
    }

    if ($action === 'archive_agreement') {
        $id = safe_text($_POST['agreement_id'] ?? '');
        $agreement = documents_get_agreement($id);
        if ($agreement === null) {
            $redirectWith('error', 'Agreement not found.');
        }
        $agreement['status'] = 'Archived';
        $agreement['updated_at'] = date('c');
        $saved = documents_save_agreement($agreement);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to archive agreement.');
        }
        $redirectWith('success', 'Agreement archived successfully.');
    }
}

$lookupMobile = normalize_customer_mobile((string) ($_GET['lookup_mobile'] ?? ''));
$lookupCustomer = $lookupMobile !== '' ? documents_find_customer_by_mobile($lookupMobile) : null;
$selectedQuoteId = safe_text($_GET['quote_id'] ?? '');
$selectedQuote = $selectedQuoteId !== '' ? documents_get_quote($selectedQuoteId) : null;
$selectedQuoteSnapshot = $selectedQuote !== null ? documents_quote_resolve_snapshot($selectedQuote) : documents_customer_snapshot_defaults();

$quoteCandidates = [];
if ($lookupMobile !== '') {
    foreach (documents_list_quotes() as $q) {
        if ((string) ($q['customer_mobile'] ?? '') === $lookupMobile) {
            $quoteCandidates[] = $q;
        }
    }
}

$seedTemplate = reset($activeTemplates);
$seedTemplateId = is_array($seedTemplate) ? (string) ($seedTemplate['id'] ?? 'default_pm_surya_ghar_agreement') : 'default_pm_surya_ghar_agreement';
$seedBackground = '';
$templateSets = json_load(documents_templates_dir() . '/template_sets.json', []);
if (is_array($templateSets)) {
    foreach ($templateSets as $set) {
        if (!is_array($set)) {
            continue;
        }
        if ((string) ($set['segment'] ?? '') !== 'RES') {
            continue;
        }
        $seedBackground = safe_text($set['default_doc_theme']['page_background_image'] ?? '');
        if ($seedBackground !== '') {
            break;
        }
    }
}

$all = documents_list_agreements();
$search = strtolower(safe_text($_GET['q'] ?? ''));
$statusFilter = safe_text($_GET['status_filter'] ?? '');
$rows = array_values(array_filter($all, static function (array $row) use ($search, $statusFilter): bool {
    if ($statusFilter !== '' && (string) ($row['status'] ?? '') !== $statusFilter) {
        return false;
    }
    if ($search === '') {
        return true;
    }
    $hay = strtolower((string) ($row['customer_name'] ?? '') . ' ' . (string) ($row['customer_mobile'] ?? '') . ' ' . (string) ($row['agreement_no'] ?? ''));
    return str_contains($hay, $search);
}));

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agreements</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}
    .wrap{max-width:1200px;margin:0 auto;padding:16px}
    .card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}
    .btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}
    .btn.warn{background:#b91c1c}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px;vertical-align:top}
    input,select,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
    .banner{padding:9px;border-radius:8px;margin-bottom:10px}.success{background:#ecfdf5;color:#065f46}.error{background:#fef2f2;color:#991b1b}
    .muted{color:#64748b;font-size:12px}
  </style>
</head>
<body>
<main class="wrap">
  <div class="card">
    <h1 style="margin:0 0 10px 0">Vendor–Consumer Agreements</h1>
    <a class="btn secondary" href="admin-documents.php">Back to Documents</a>
  </div>

  <?php if ($message !== '' && ($status === 'success' || $status === 'error')): ?>
    <div class="banner <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Create New Agreement</h2>
    <form method="get" class="grid" style="margin-bottom:10px">
      <div>
        <label>Lookup Customer by Mobile</label>
        <input name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>" placeholder="10-digit mobile" />
      </div>
      <div style="align-self:end"><button class="btn secondary" type="submit">Search</button></div>
    </form>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="create_agreement">
      <div class="grid">
        <div><label>Customer Mobile</label><input name="customer_mobile" required value="<?= htmlspecialchars((string) (($selectedQuoteSnapshot['mobile'] ?? '') ?: ($lookupMobile !== '' ? $lookupMobile : '')), ENT_QUOTES) ?>"></div>
        <div><label>Customer Name</label><input name="customer_name" required value="<?= htmlspecialchars((string) (($selectedQuoteSnapshot['name'] ?? '') ?: ($lookupCustomer['name'] ?? '')), ENT_QUOTES) ?>"></div>
        <div><label>Consumer Account No. (JBVNL)</label><input name="consumer_account_no" value="<?= htmlspecialchars((string) (($selectedQuote['consumer_account_no'] ?? '') ?: ($selectedQuoteSnapshot['consumer_account_no'] ?? ($lookupCustomer['consumer_account_no'] ?? ''))), ENT_QUOTES) ?>"></div>
        <div><label>Execution Date</label><input type="date" name="execution_date" required value="<?= htmlspecialchars((string) date('Y-m-d'), ENT_QUOTES) ?>"></div>
        <div><label>System Capacity (kWp)</label><input name="system_capacity_kwp" required value="<?= htmlspecialchars((string) ($selectedQuote['capacity_kwp'] ?? ''), ENT_QUOTES) ?>"></div>
        <div><label>Total RTS Cost</label><input name="total_cost" required value="<?= htmlspecialchars((string) (($selectedQuote['calc']['grand_total'] ?? '') !== '' ? documents_format_money_indian((float) ($selectedQuote['calc']['grand_total'] ?? 0)) : ''), ENT_QUOTES) ?>"></div>
        <div style="grid-column:1/-1"><label>Consumer Address</label><textarea name="consumer_address"><?= htmlspecialchars((string) (($selectedQuote['site_address'] ?? '') ?: ($selectedQuoteSnapshot['address'] ?? ($lookupCustomer['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>
        <div style="grid-column:1/-1"><label>Consumer Site Address</label><textarea name="site_address"><?= htmlspecialchars((string) (($selectedQuote['site_address'] ?? '') ?: ($selectedQuoteSnapshot['address'] ?? ($lookupCustomer['address'] ?? ''))), ENT_QUOTES) ?></textarea></div>

        <div>
          <label>Link Quotation (Optional)</label>
          <select name="linked_quote_id" onchange="if(this.value){window.location='admin-agreements.php?lookup_mobile=<?= urlencode($lookupMobile) ?>&quote_id='+encodeURIComponent(this.value)}">
            <option value="">-- none --</option>
            <?php foreach ($quoteCandidates as $q): ?>
              <option value="<?= htmlspecialchars((string) $q['id'], ENT_QUOTES) ?>" <?= ((string) ($selectedQuote['id'] ?? '') === (string) ($q['id'] ?? '')) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($q['quote_no'] ?? ''), ENT_QUOTES) ?> | ₹<?= number_format((float) ($q['calc']['grand_total'] ?? 0), 2) ?> | <?= htmlspecialchars((string) ($q['capacity_kwp'] ?? ''), ENT_QUOTES) ?> kWp
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Template</label>
          <select name="template_id" required>
            <?php foreach ($activeTemplates as $template): if (!is_array($template)) { continue; } ?>
              <option value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($template['id'] ?? '') === $seedTemplateId) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div><label>Background Image (optional path/url)</label><input name="background_image" value="<?= htmlspecialchars($seedBackground, ENT_QUOTES) ?>"></div>
        <div><label>Background Opacity</label><input name="background_opacity" type="number" min="0.1" max="1" step="0.05" value="1"></div>
      </div>
      <p class="muted">Vendor details are auto-fetched from company profile: <?= htmlspecialchars(documents_company_vendor_name($company), ENT_QUOTES) ?></p>
      <button class="btn" type="submit">Create Agreement</button>
    </form>
  </div>


  <div class="card">
    <h2 style="margin-top:0">Default Agreement Template (Admin Editable)</h2>
    <?php $defaultTemplate = $templates['default_pm_surya_ghar_agreement'] ?? null; if (is_array($defaultTemplate)): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="template_id" value="default_pm_surya_ghar_agreement">
      <div class="grid">
        <div style="grid-column:1/-1"><label>Template Name</label><input name="template_name" value="<?= htmlspecialchars((string) ($defaultTemplate['name'] ?? ''), ENT_QUOTES) ?>"></div>
        <div style="grid-column:1/-1"><label>HTML Template</label><textarea name="html_template" style="min-height:240px"><?= htmlspecialchars((string) ($defaultTemplate['html_template'] ?? ''), ENT_QUOTES) ?></textarea></div>
      </div>
      <button class="btn secondary" type="submit">Save Template</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Agreements List</h2>
    <form method="get" class="grid" style="margin-bottom:10px">
      <div><label>Search (mobile/name/agreement no)</label><input name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></div>
      <div><label>Status</label><select name="status_filter"><option value="">All</option><?php foreach (['Draft','Final','Archived'] as $st): ?><option value="<?= $st ?>" <?= $statusFilter===$st?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select></div>
      <div style="align-self:end"><button class="btn secondary" type="submit">Filter</button></div>
    </form>

    <table>
      <thead><tr><th>Agreement No</th><th>Customer</th><th>Execution Date</th><th>kWp</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= htmlspecialchars((string) $row['agreement_no'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES) ?><br><span class="muted"><?= htmlspecialchars((string) $row['customer_mobile'], ENT_QUOTES) ?></span></td>
          <td><?= htmlspecialchars((string) $row['execution_date'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars((string) $row['system_capacity_kwp'], ENT_QUOTES) ?></td>
          <td>₹<?= htmlspecialchars((string) $row['total_cost'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars((string) $row['status'], ENT_QUOTES) ?></td>
          <td>
            <a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) $row['id']) ?>">View/Edit</a>
            <a class="btn secondary" target="_blank" href="agreement-print.php?id=<?= urlencode((string) $row['id']) ?>">Open Document</a>
            <?php if ((string) $row['status'] !== 'Archived'): ?>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
              <input type="hidden" name="action" value="archive_agreement">
              <input type="hidden" name="agreement_id" value="<?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>">
              <button class="btn warn" type="submit">Archive</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if ($rows === []): ?>
        <tr><td colspan="7">No agreements found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
