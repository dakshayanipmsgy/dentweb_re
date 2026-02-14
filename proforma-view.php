<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$row = documents_get_proforma($id);
if ($row === null) { http_response_code(404); echo 'Proforma not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { header('Location: proforma-view.php?id=' . urlencode($id) . '&err=csrf'); exit; }
    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'save') {
        $row['status'] = in_array($_POST['status'] ?? '', ['Draft','Final','Archived'], true) ? (string)$_POST['status'] : 'Draft';
        $row['notes_top'] = safe_text($_POST['notes_top'] ?? '');
        $row['notes_bottom'] = safe_text($_POST['notes_bottom'] ?? '');
        $row['customer_name'] = safe_text($_POST['customer_name'] ?? '');
        $row['billing_address'] = safe_text($_POST['billing_address'] ?? '');
        $row['site_address'] = safe_text($_POST['site_address'] ?? '');
        $row['district'] = safe_text($_POST['district'] ?? '');
        $row['city'] = safe_text($_POST['city'] ?? '');
        $row['state'] = safe_text($_POST['state'] ?? '');
        $row['pin'] = safe_text($_POST['pin'] ?? '');
        $row['updated_at'] = date('c');
        documents_save_proforma($row);
        header('Location: proforma-view.php?id=' . urlencode($id) . '&ok=1'); exit;
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proforma <?= htmlspecialchars((string)$row['proforma_no'], ENT_QUOTES) ?></title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}input,textarea,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:80px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}</style></head>
<body><main class="wrap">
<?php if(isset($_GET['ok'])): ?><div class="card" style="background:#ecfdf5">Saved.</div><?php endif; ?>
<div class="card"><h1>Proforma Invoice</h1><a class="btn secondary" href="admin-proformas.php">Back</a> <a class="btn" href="proforma-print.php?id=<?= urlencode((string)$row['id']) ?>" target="_blank">Print</a> <a class="btn" href="proforma-pdf.php?id=<?= urlencode((string)$row['id']) ?>">Download PDF</a></div>
<div class="card"><strong><?= htmlspecialchars((string)$row['proforma_no'], ENT_QUOTES) ?></strong> | Source Quote: <?= htmlspecialchars((string)$row['source_quote_no'], ENT_QUOTES) ?></div>
<div class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="save"><div class="grid">
<div><label>Status</label><select name="status"><?php foreach(['Draft','Final','Archived'] as $s): ?><option value="<?= $s ?>" <?= $row['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div>
<div><label>Customer</label><input name="customer_name" value="<?= htmlspecialchars((string)$row['customer_name'], ENT_QUOTES) ?>"></div>
<div><label>District</label><input name="district" value="<?= htmlspecialchars((string)$row['district'], ENT_QUOTES) ?>"></div>
<div><label>City</label><input name="city" value="<?= htmlspecialchars((string)$row['city'], ENT_QUOTES) ?>"></div>
<div><label>State</label><input name="state" value="<?= htmlspecialchars((string)$row['state'], ENT_QUOTES) ?>"></div>
<div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)$row['pin'], ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"><?= htmlspecialchars((string)$row['billing_address'], ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"><?= htmlspecialchars((string)$row['site_address'], ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Top Note</label><textarea name="notes_top"><?= htmlspecialchars((string)$row['notes_top'], ENT_QUOTES) ?></textarea></div>
<div style="grid-column:1/-1"><label>Bottom Note</label><textarea name="notes_bottom"><?= htmlspecialchars((string)$row['notes_bottom'], ENT_QUOTES) ?></textarea></div>
</div><br><button class="btn" type="submit">Save</button></form></div>
</main></body></html>
