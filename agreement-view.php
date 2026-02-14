<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$row = documents_get_agreement($id);
if ($row === null) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

$templates = documents_get_agreement_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        header('Location: agreement-view.php?id=' . urlencode($id) . '&err=csrf');
        exit;
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'archive') {
        $row['status'] = 'Archived';
        $row['updated_at'] = date('c');
        documents_save_agreement($row);
        header('Location: agreement-view.php?id=' . urlencode($id) . '&ok=1');
        exit;
    }

    if ($action === 'save') {
        $row['status'] = in_array($_POST['status'] ?? '', ['Draft', 'Final', 'Archived'], true) ? (string) $_POST['status'] : 'Draft';
        $row['template_key'] = safe_text($_POST['template_key'] ?? $row['template_key']);
        if (!isset($templates[$row['template_key']])) {
            $row['template_key'] = 'pm_suryaghar_residential';
        }

        foreach (['execution_date','kwp','amount_total','customer_mobile','customer_name','consumer_account_no','consumer_address','site_address','district','state','pin'] as $field) {
            $row[$field] = safe_text($_POST[$field] ?? $row[$field]);
        }

        $templateHtml = (string) ($_POST['template_html'] ?? '');
        if ($templateHtml !== '' && isset($templates[$row['template_key']]) && is_array($templates[$row['template_key']])) {
            $templates[$row['template_key']]['template_html'] = $templateHtml;
            $templates[$row['template_key']]['updated_at'] = date('c');
            documents_save_agreement_templates($templates);
        }

        $row['generated_html_snapshot'] = documents_render_agreement_html($row, $templates[$row['template_key']] ?? null);
        $row['updated_at'] = date('c');
        documents_save_agreement($row);
        header('Location: agreement-view.php?id=' . urlencode($id) . '&ok=1');
        exit;
    }
}

$template = is_array($templates[$row['template_key']] ?? null) ? $templates[$row['template_key']] : ($templates['pm_suryaghar_residential'] ?? []);
if ((string) ($row['generated_html_snapshot'] ?? '') === '') {
    $row['generated_html_snapshot'] = documents_render_agreement_html($row, $template);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agreement <?= htmlspecialchars((string) $row['agreement_no'], ENT_QUOTES) ?></title>
  <style>
    body{font-family:Arial;background:#f4f6fa;margin:0}.wrap{padding:16px;max-width:none}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}
    .btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:140px}
    .preview{border:1px solid #e2e8f0;border-radius:8px;padding:20px;background:#fff}
  </style>
</head>
<body><main class="wrap">
<?php if (isset($_GET['ok'])): ?><div class="card" style="background:#ecfdf5;color:#065f46">Agreement saved.</div><?php endif; ?>
<?php if (isset($_GET['err']) && $_GET['err'] === 'csrf'): ?><div class="card" style="background:#fef2f2;color:#991b1b">Security validation failed.</div><?php endif; ?>
<?php if (isset($_GET['message'])): ?><div class="card" style="background:#eff6ff;color:#1e3a8a"><?= htmlspecialchars((string) $_GET['message'], ENT_QUOTES) ?></div><?php endif; ?>

<div class="card">
  <h1>Vendor–Consumer Agreement</h1>
  <a class="btn secondary" href="admin-agreements.php">Back</a>
  <a class="btn" href="agreement-print.php?id=<?= urlencode((string) $row['id']) ?>" target="_blank">Print</a>
  <a class="btn" href="agreement-pdf.php?id=<?= urlencode((string) $row['id']) ?>">Download PDF</a>
</div>

<div class="card"><strong><?= htmlspecialchars((string) $row['agreement_no'], ENT_QUOTES) ?></strong><?php if ((string)($row['linked_quote_no'] ?? '') !== ''): ?> | Linked Quote: <?= htmlspecialchars((string) $row['linked_quote_no'], ENT_QUOTES) ?><?php endif; ?></div>

<div class="card">
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
  <input type="hidden" name="action" value="save">
  <div class="grid">
    <div><label>Status</label><select name="status"><?php foreach (['Draft','Final','Archived'] as $s): ?><option value="<?= $s ?>" <?= (string) $row['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div><label>Template</label><select name="template_key"><?php foreach ($templates as $key => $tpl): if (($tpl['archived_flag'] ?? false) && $key !== $row['template_key']) { continue; } ?><option value="<?= htmlspecialchars((string) $key, ENT_QUOTES) ?>" <?= (string) $row['template_key'] === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars((string) ($tpl['name'] ?? $key), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
    <div><label>Execution Date *</label><input type="date" name="execution_date" required value="<?= htmlspecialchars((string) $row['execution_date'], ENT_QUOTES) ?>"></div>
    <div><label>kWp *</label><input name="kwp" required value="<?= htmlspecialchars((string) $row['kwp'], ENT_QUOTES) ?>"></div>
    <div><label>Amount (₹) *</label><input type="number" step="0.01" name="amount_total" required value="<?= htmlspecialchars((string) $row['amount_total'], ENT_QUOTES) ?>"></div>
    <div><label>Customer Mobile</label><input name="customer_mobile" value="<?= htmlspecialchars((string) $row['customer_mobile'], ENT_QUOTES) ?>"></div>
    <div><label>Customer Name</label><input name="customer_name" value="<?= htmlspecialchars((string) $row['customer_name'], ENT_QUOTES) ?>"></div>
    <div><label>Consumer Account No</label><input name="consumer_account_no" value="<?= htmlspecialchars((string) $row['consumer_account_no'], ENT_QUOTES) ?>"></div>
    <div><label>District</label><input name="district" value="<?= htmlspecialchars((string) $row['district'], ENT_QUOTES) ?>"></div>
    <div><label>State</label><input name="state" value="<?= htmlspecialchars((string) $row['state'], ENT_QUOTES) ?>"></div>
    <div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string) $row['pin'], ENT_QUOTES) ?>"></div>
    <div style="grid-column:1/-1"><label>Consumer Address</label><textarea name="consumer_address"><?= htmlspecialchars((string) $row['consumer_address'], ENT_QUOTES) ?></textarea></div>
    <div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"><?= htmlspecialchars((string) $row['site_address'], ENT_QUOTES) ?></textarea></div>
    <div style="grid-column:1/-1"><label>Template HTML (editable default text)</label><textarea name="template_html"><?= htmlspecialchars((string) ($template['template_html'] ?? ''), ENT_QUOTES) ?></textarea></div>
  </div>
  <br><button class="btn" type="submit">Save</button>
</form>
<form method="post" style="margin-top:10px">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="archive">
  <button class="btn secondary" type="submit">Archive</button>
</form>
</div>

<div class="card">
  <h3>Rendered Agreement Preview</h3>
  <div class="preview"><?= $row['generated_html_snapshot'] ?></div>
</div>
</main></body></html>
