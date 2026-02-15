<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_admin();
documents_ensure_structure();

$path = documents_settings_dir() . '/quote_visual_defaults.json';
$segments = ['RES','COM','IND','INST'];
$redirect = static function(string $type,string $msg): void {
    header('Location: admin-quote-settings.php?' . http_build_query(['status'=>$type,'message'=>$msg]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('error','Security validation failed.');
    }
    $settings = documents_get_quote_visual_defaults();
    foreach ($segments as $seg) {
        $settings[$seg]['unit_rate_rs'] = (float) ($_POST[$seg.'_unit_rate_rs'] ?? 0);
        $settings[$seg]['annual_generation_kwh_per_kw'] = (float) ($_POST[$seg.'_annual_generation_kwh_per_kw'] ?? 1450);
        $settings[$seg]['interest_rate_percent'] = (float) ($_POST[$seg.'_interest_rate_percent'] ?? 7);
        $settings[$seg]['tenure_years'] = (int) ($_POST[$seg.'_tenure_years'] ?? 5);
        $settings[$seg]['down_payment_percent'] = (float) ($_POST[$seg.'_down_payment_percent'] ?? 10);
        $settings[$seg]['remaining_bill_percent_after_solar'] = (float) ($_POST[$seg.'_remaining_bill_percent_after_solar'] ?? 10);
    }
    $settings['environment']['emission_factor_kg_per_kwh'] = (float) ($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
    $settings['environment']['tree_absorption_kg_per_year'] = (float) ($_POST['tree_absorption_kg_per_year'] ?? 20);
    $settings['watermark']['enabled'] = isset($_POST['watermark_enabled']);
    $settings['watermark']['opacity'] = max(0.02, min(0.25, (float) ($_POST['watermark_opacity'] ?? 0.12)));

    if (isset($_FILES['watermark_upload']) && (int) ($_FILES['watermark_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = documents_handle_image_upload($_FILES['watermark_upload'], documents_public_watermarks_dir(), 'wm_');
        if (!$upload['ok']) {
            $redirect('error', (string) $upload['error']);
        }
        $settings['watermark']['image_path'] = '/images/documents/watermarks/' . $upload['filename'];
    }

    $saved = json_save($path, $settings);
    if (!$saved['ok']) {
        $redirect('error','Unable to save settings.');
    }
    $redirect('success','Quote visual settings saved.');
}

$settings = documents_get_quote_visual_defaults();
$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quote Visual Settings</title>
<style>body{font-family:Arial;margin:0;background:#f8fafc}.wrap{max-width:1100px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}label{font-size:12px;font-weight:700}input{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px}.btn{background:#0f766e;color:#fff;border:none;border-radius:8px;padding:8px 12px}.muted{font-size:12px;color:#64748b}</style></head>
<body><main class="wrap">
<div class="card"><h1>Quote Visual Settings</h1><a href="admin-documents.php">Back to Documents</a></div>
<?php if ($message!==''): ?><div class="card" style="background:<?= $status==='success'?'#ecfdf5':'#fef2f2' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<?php foreach ($segments as $seg): $row = $settings[$seg] ?? []; ?>
<h3><?= $seg ?></h3><div class="grid">
<div><label>Unit Rate ₹</label><input type="number" step="0.01" name="<?= $seg ?>_unit_rate_rs" value="<?= htmlspecialchars((string)($row['unit_rate_rs'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Annual Generation/kW</label><input type="number" step="1" name="<?= $seg ?>_annual_generation_kwh_per_kw" value="<?= htmlspecialchars((string)($row['annual_generation_kwh_per_kw'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Interest %</label><input type="number" step="0.01" name="<?= $seg ?>_interest_rate_percent" value="<?= htmlspecialchars((string)($row['interest_rate_percent'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Tenure Years</label><input type="number" step="1" name="<?= $seg ?>_tenure_years" value="<?= htmlspecialchars((string)($row['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Down Payment %</label><input type="number" step="0.01" name="<?= $seg ?>_down_payment_percent" value="<?= htmlspecialchars((string)($row['down_payment_percent'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Remaining Bill %</label><input type="number" step="0.01" name="<?= $seg ?>_remaining_bill_percent_after_solar" value="<?= htmlspecialchars((string)($row['remaining_bill_percent_after_solar'] ?? ''), ENT_QUOTES) ?>"></div>
</div>
<?php endforeach; $env = $settings['environment'] ?? []; $wm = $settings['watermark'] ?? []; ?>
<h3>Environment</h3><div class="grid"><div><label>Emission factor</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($env['emission_factor_kg_per_kwh'] ?? ''), ENT_QUOTES) ?>"></div><div><label>Tree absorption kg/year</label><input type="number" step="0.1" name="tree_absorption_kg_per_year" value="<?= htmlspecialchars((string)($env['tree_absorption_kg_per_year'] ?? ''), ENT_QUOTES) ?>"></div></div>
<h3>Print Watermark</h3><p class="muted">Watermark appears only on Ctrl+P print, not on screen.</p><div class="grid"><div><label><input type="checkbox" name="watermark_enabled" <?= !empty($wm['enabled'])?'checked':'' ?>> Enabled</label></div><div><label>Opacity (0.02-0.25)</label><input type="number" min="0.02" max="0.25" step="0.01" name="watermark_opacity" value="<?= htmlspecialchars((string)($wm['opacity'] ?? 0.12), ENT_QUOTES) ?>"></div><div><label>Upload image</label><input type="file" name="watermark_upload" accept="image/png,image/jpeg,image/webp"></div></div>
<?php if (!empty($wm['image_path'])): ?><p>Current: <?= htmlspecialchars((string)$wm['image_path'], ENT_QUOTES) ?></p><?php endif; ?>
<br><button class="btn" type="submit">Save Settings</button>
</form></main></body></html>
