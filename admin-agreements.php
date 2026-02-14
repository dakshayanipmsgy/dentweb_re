<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$templates = documents_get_agreement_templates();
$activeTemplates = array_filter($templates, static fn($tpl): bool => is_array($tpl) && !($tpl['archived_flag'] ?? false));
$quotes = documents_list_quotes();
$rows = documents_list_agreements();

$linkQuoteId = safe_text($_GET['quote_id'] ?? '');
$linkedQuote = $linkQuoteId !== '' ? documents_get_quote($linkQuoteId) : null;

$lookupMobile = normalize_customer_mobile((string) ($_GET['lookup_mobile'] ?? ($linkedQuote['customer_mobile'] ?? '')));
$customerLookup = $lookupMobile !== '' ? documents_find_customer_by_mobile($lookupMobile) : null;

$prefill = [
    'template_key' => 'pm_suryaghar_residential',
    'execution_date' => '',
    'kwp' => is_array($linkedQuote) ? (string) ($linkedQuote['capacity_kwp'] ?? '') : '',
    'amount_total' => is_array($linkedQuote) ? (string) ($linkedQuote['calc']['grand_total'] ?? $linkedQuote['input_total_gst_inclusive'] ?? '') : '',
    'customer_mobile' => $lookupMobile,
    'customer_name' => (string) ($customerLookup['customer_name'] ?? ($linkedQuote['customer_name'] ?? '')),
    'consumer_account_no' => (string) ($customerLookup['consumer_account_no'] ?? ''),
    'consumer_address' => (string) ($customerLookup['billing_address'] ?? ($linkedQuote['billing_address'] ?? '')),
    'site_address' => (string) ($customerLookup['site_address'] ?? ($linkedQuote['site_address'] ?? '')),
    'district' => (string) ($customerLookup['district'] ?? ($linkedQuote['district'] ?? '')),
    'state' => (string) ($customerLookup['state'] ?? ($linkedQuote['state'] ?? 'Jharkhand')),
    'pin' => (string) ($customerLookup['pin'] ?? ($linkedQuote['pin'] ?? '')),
    'linked_quote_id' => is_array($linkedQuote) ? (string) ($linkedQuote['id'] ?? '') : '',
    'linked_quote_no' => is_array($linkedQuote) ? (string) ($linkedQuote['quote_no'] ?? '') : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        header('Location: admin-agreements.php?status=error&message=' . urlencode('Security validation failed.'));
        exit;
    }

    if (safe_text($_POST['action'] ?? '') === 'create_agreement') {
        $executionDate = safe_text($_POST['execution_date'] ?? '');
        $kwp = safe_text($_POST['kwp'] ?? '');
        $amount = safe_text($_POST['amount_total'] ?? '');
        $mobile = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $customerName = safe_text($_POST['customer_name'] ?? '');
        $templateKey = safe_text($_POST['template_key'] ?? 'pm_suryaghar_residential');

        if ($executionDate === '' || $kwp === '' || $amount === '' || $mobile === '' || $customerName === '') {
            header('Location: admin-agreements.php?status=error&message=' . urlencode('Execution date, kWp, amount, mobile and customer name are required.'));
            exit;
        }

        if (!isset($activeTemplates[$templateKey])) {
            $templateKey = 'pm_suryaghar_residential';
        }

        $number = documents_generate_document_number('agreement', 'RES');
        if (!$number['ok']) {
            header('Location: admin-agreements.php?status=error&message=' . urlencode((string) ($number['error'] ?? 'Missing numbering rule for agreement.')));
            exit;
        }

        $admin = current_user();
        $agreement = documents_agreement_defaults();
        $agreement['id'] = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $agreement['agreement_no'] = (string) ($number['number'] ?? '');
        $agreement['template_key'] = $templateKey;
        $agreement['execution_date'] = $executionDate;
        $agreement['kwp'] = $kwp;
        $agreement['amount_total'] = $amount;
        $agreement['customer_mobile'] = $mobile;
        $agreement['customer_name'] = $customerName;
        $agreement['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? '');
        $agreement['consumer_address'] = safe_text($_POST['consumer_address'] ?? '');
        $agreement['site_address'] = safe_text($_POST['site_address'] ?? '');
        $agreement['district'] = safe_text($_POST['district'] ?? '');
        $agreement['state'] = safe_text($_POST['state'] ?? 'Jharkhand');
        $agreement['pin'] = safe_text($_POST['pin'] ?? '');
        $agreement['linked_quote_id'] = safe_text($_POST['linked_quote_id'] ?? '');
        $agreement['linked_quote_no'] = safe_text($_POST['linked_quote_no'] ?? '');
        $agreement['created_by_type'] = 'admin';
        $agreement['created_by_id'] = (string) ($admin['id'] ?? '');
        $agreement['created_by_name'] = (string) ($admin['full_name'] ?? 'Admin');
        $agreement['created_at'] = date('c');
        $agreement['updated_at'] = date('c');
        $agreement['generated_html_snapshot'] = documents_render_agreement_html($agreement, $templates[$templateKey] ?? null);

        documents_save_agreement($agreement);

        if ($agreement['linked_quote_id'] !== '') {
            $quote = documents_get_quote($agreement['linked_quote_id']);
            if (is_array($quote)) {
                $quote['links']['agreement_id'] = $agreement['id'];
                $quote['updated_at'] = date('c');
                documents_save_quote($quote);
            }
        }

        header('Location: agreement-view.php?id=' . urlencode($agreement['id']) . '&ok=1');
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Agreements</title>
  <style>
    body{font-family:Arial;background:#f4f6fa;margin:0}.wrap{padding:16px;max-width:none}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}
    .btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}input,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
    table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}
  </style>
</head>
<body><main class="wrap">
<div class="card">
  <h1>Agreements</h1>
  <a class="btn secondary" href="admin-dashboard.php">Back to Admin Dashboard</a>
  <a class="btn secondary" href="admin-documents.php">Back to Documents Control Center</a>
</div>
<?php if (isset($_GET['status'], $_GET['message'])): ?><div class="card" style="background:<?= $_GET['status'] === 'error' ? '#fef2f2' : '#ecfdf5' ?>;color:<?= $_GET['status'] === 'error' ? '#991b1b' : '#065f46' ?>"><?= htmlspecialchars((string) $_GET['message'], ENT_QUOTES) ?></div><?php endif; ?>

<div class="card">
  <h2>Create Agreement</h2>
  <form method="get" style="margin-bottom:10px;display:flex;gap:8px;align-items:flex-end;max-width:560px">
    <div style="flex:1"><label>Lookup Customer by Mobile</label><input name="lookup_mobile" value="<?= htmlspecialchars($lookupMobile, ENT_QUOTES) ?>"></div>
    <?php if (is_array($linkedQuote)): ?><input type="hidden" name="quote_id" value="<?= htmlspecialchars((string) $linkedQuote['id'], ENT_QUOTES) ?>"><?php endif; ?>
    <button class="btn secondary" type="submit">Fetch customer</button>
  </form>
  <?php if ($customerLookup !== null): ?><div style="background:#f8fafc;border:1px solid #dbe1ea;border-radius:8px;padding:8px;margin-bottom:10px">Fetched: <strong><?= htmlspecialchars((string) $customerLookup['customer_name'], ENT_QUOTES) ?></strong>, Account: <?= htmlspecialchars((string) $customerLookup['consumer_account_no'], ENT_QUOTES) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="action" value="create_agreement">
    <div class="grid">
      <div><label>Template</label><select name="template_key"><?php foreach ($activeTemplates as $key => $template): ?><option value="<?= htmlspecialchars((string) $key, ENT_QUOTES) ?>" <?= $prefill['template_key'] === $key ? 'selected' : '' ?>><?= htmlspecialchars((string) ($template['name'] ?? $key), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
      <div><label>Execution date *</label><input type="date" name="execution_date" required value="<?= htmlspecialchars($prefill['execution_date'], ENT_QUOTES) ?>"></div>
      <div><label>kWp *</label><input name="kwp" required value="<?= htmlspecialchars($prefill['kwp'], ENT_QUOTES) ?>"></div>
      <div><label>Amount (₹) *</label><input type="number" step="0.01" min="0" name="amount_total" required value="<?= htmlspecialchars($prefill['amount_total'], ENT_QUOTES) ?>"></div>

      <div><label>Customer mobile *</label><input name="customer_mobile" required value="<?= htmlspecialchars($prefill['customer_mobile'], ENT_QUOTES) ?>"></div>
      <div><label>Consumer name *</label><input name="customer_name" required value="<?= htmlspecialchars($prefill['customer_name'], ENT_QUOTES) ?>"></div>
      <div><label>Consumer account no</label><input name="consumer_account_no" value="<?= htmlspecialchars($prefill['consumer_account_no'], ENT_QUOTES) ?>"></div>
      <div><label>District</label><input name="district" value="<?= htmlspecialchars($prefill['district'], ENT_QUOTES) ?>"></div>
      <div><label>State</label><input name="state" value="<?= htmlspecialchars($prefill['state'], ENT_QUOTES) ?>"></div>
      <div><label>PIN</label><input name="pin" value="<?= htmlspecialchars($prefill['pin'], ENT_QUOTES) ?>"></div>
      <div style="grid-column:1/-1"><label>Consumer address</label><input name="consumer_address" value="<?= htmlspecialchars($prefill['consumer_address'], ENT_QUOTES) ?>"></div>
      <div style="grid-column:1/-1"><label>Site address</label><input name="site_address" value="<?= htmlspecialchars($prefill['site_address'], ENT_QUOTES) ?>"></div>

      <div><label>Linked quotation</label><select name="linked_quote_id"><option value="">None</option><?php foreach ($quotes as $quote): if ((string)($quote['customer_mobile'] ?? '') !== $prefill['customer_mobile'] && $prefill['customer_mobile'] !== '') { continue; } ?><option value="<?= htmlspecialchars((string) ($quote['id'] ?? ''), ENT_QUOTES) ?>" data-quote-no="<?= htmlspecialchars((string) ($quote['quote_no'] ?? ''), ENT_QUOTES) ?>" <?= $prefill['linked_quote_id'] === (string) ($quote['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($quote['quote_no'] ?? ''), ENT_QUOTES) ?> - <?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
      <div><label>Linked quote no</label><input name="linked_quote_no" value="<?= htmlspecialchars($prefill['linked_quote_no'], ENT_QUOTES) ?>"></div>
    </div>
    <br><button class="btn" type="submit">Create Agreement</button>
  </form>
</div>

<div class="card">
  <h2>Agreements List</h2>
  <table><thead><tr><th>Agreement No</th><th>Customer</th><th>Execution Date</th><th>kWp</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars((string) $row['agreement_no'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars(documents_format_display_date((string) $row['execution_date']), ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars((string) $row['kwp'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars(documents_format_indian_currency((float) ($row['amount_total'] ?? 0)), ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars((string) $row['status'], ENT_QUOTES) ?></td>
      <td><a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) $row['id']) ?>">View</a> <a class="btn secondary" href="agreement-print.php?id=<?= urlencode((string) $row['id']) ?>" target="_blank">Print</a> <a class="btn secondary" href="agreement-pdf.php?id=<?= urlencode((string) $row['id']) ?>">PDF</a></td>
    </tr>
  <?php endforeach; if ($rows === []): ?><tr><td colspan="7">No agreements yet.</td></tr><?php endif; ?>
  </tbody></table>
</div>
</main></body></html>
