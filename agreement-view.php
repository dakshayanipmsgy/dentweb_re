<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$row = documents_get_agreement($id);
if ($row === null) { http_response_code(404); echo 'Agreement not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { header('Location: agreement-view.php?id=' . urlencode($id) . '&err=csrf'); exit; }
    if (safe_text($_POST['action'] ?? '') === 'save') {
        $row['status'] = in_array($_POST['status'] ?? '', ['Draft','Final','Archived'], true) ? (string)$_POST['status'] : 'Draft';
        $row['agreement_text'] = trim((string)($_POST['agreement_text'] ?? ''));
        $row['special_terms_override'] = trim((string)($_POST['special_terms_override'] ?? ''));
        $row['address'] = safe_text($_POST['address'] ?? '');
        $row['district'] = safe_text($_POST['district'] ?? '');
        $row['state'] = safe_text($_POST['state'] ?? '');
        $row['pin'] = safe_text($_POST['pin'] ?? '');
        $row['updated_at'] = date('c');
        documents_save_agreement($row);
        header('Location: agreement-view.php?id=' . urlencode($id) . '&ok=1'); exit;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agreement <?= htmlspecialchars((string)$row['agreement_no'], ENT_QUOTES) ?></title><style>body{font-family:Arial;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}input,textarea,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:120px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}</style></head><body><main class="wrap"><?php if(isset($_GET['ok'])): ?><div class="card" style="background:#ecfdf5">Saved.</div><?php endif; ?><div class="card"><h1>Vendor–Consumer Agreement</h1><a class="btn secondary" href="admin-agreements.php">Back</a> <a class="btn" href="agreement-print.php?id=<?= urlencode((string)$row['id']) ?>" target="_blank">Print</a> <a class="btn" href="agreement-pdf.php?id=<?= urlencode((string)$row['id']) ?>">Download PDF</a></div><div class="card"><strong><?= htmlspecialchars((string)$row['agreement_no'], ENT_QUOTES) ?></strong></div><div class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="save"><div class="grid"><div><label>Status</label><select name="status"><?php foreach(['Draft','Final','Archived'] as $s): ?><option value="<?= $s ?>" <?= $row['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div><div><label>District</label><input name="district" value="<?= htmlspecialchars((string)$row['district'], ENT_QUOTES) ?>"></div><div><label>State</label><input name="state" value="<?= htmlspecialchars((string)$row['state'], ENT_QUOTES) ?>"></div><div><label>PIN</label><input name="pin" value="<?= htmlspecialchars((string)$row['pin'], ENT_QUOTES) ?>"></div><div style="grid-column:1/-1"><label>Address</label><textarea name="address"><?= htmlspecialchars((string)$row['address'], ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Agreement Text</label><textarea name="agreement_text"><?= htmlspecialchars((string)$row['agreement_text'], ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Special Terms Override</label><textarea name="special_terms_override"><?= htmlspecialchars((string)$row['special_terms_override'], ENT_QUOTES) ?></textarea></div></div><br><button class="btn" type="submit">Save</button></form></div></main></body></html>
