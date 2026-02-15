<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_admin();
documents_ensure_structure();
$path = documents_settings_dir() . '/quote_defaults.json';
$redirect = static function(string $type,string $message): void { header('Location: admin-quote-settings.php?status='.$type.'&message='.urlencode($message)); exit; };
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { $redirect('error', 'Security validation failed.'); }
    $d = documents_get_quote_defaults_settings();
    $d['global']['energy_defaults']['annual_generation_per_kw'] = (float)($_POST['annual_generation_per_kw'] ?? 1450);
    $d['global']['energy_defaults']['emission_factor_kg_per_kwh'] = (float)($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
    $d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] = (float)($_POST['tree_absorption_kg_per_tree_per_year'] ?? 20);
    $d['global']['typography']['base_font_px'] = (int)($_POST['base_font_px'] ?? 14);
    $d['global']['typography']['heading_scale'] = (float)($_POST['heading_scale'] ?? 1);
    $d['global']['typography']['density'] = safe_text($_POST['density'] ?? 'comfortable');
    foreach (['RES','COM','IND','INST'] as $seg) {
        $d['segments'][$seg]['unit_rate_rs_per_kwh'] = (float)($_POST['unit_rate_'.$seg] ?? ($d['segments'][$seg]['unit_rate_rs_per_kwh'] ?? 0));
        $d['segments'][$seg]['loan_defaults']['tenure_years'] = (int)($_POST['tenure_'.$seg] ?? ($d['segments'][$seg]['loan_defaults']['tenure_years'] ?? 7));
        $d['segments'][$seg]['estimated_bill_after_solar_rs'] = (float)($_POST['estimated_bill_after_solar_'.$seg] ?? ($d['segments'][$seg]['estimated_bill_after_solar_rs'] ?? 200));
    }
    $d['global']['branding']['watermark']['enabled'] = isset($_POST['wm_enabled']);
    $d['global']['branding']['watermark']['opacity'] = (float)($_POST['wm_opacity'] ?? 0.08);
    $badgeLines = preg_split('/\r\n|\r|\n/', (string)($_POST['header_badges'] ?? ''));
    $badges = [];
    foreach ($badgeLines as $line) {
        $line = trim((string)$line);
        if ($line !== '') { $badges[] = $line; }
    }
    if ($badges === []) {
        $badges = ['MNRE-Compliance EPC', 'JREDA / JBVNL Assistance', 'End-to-End Subsidy Support'];
    }
    $d['global']['branding']['header_badges'] = $badges;
    if (isset($_FILES['wm_upload']) && (int)($_FILES['wm_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = documents_handle_image_upload($_FILES['wm_upload'], documents_public_watermarks_dir(), 'watermark_');
        if (!($up['ok'] ?? false)) { $redirect('error', (string)($up['error'] ?? 'Upload failed.')); }
        $d['global']['branding']['watermark']['image_path'] = '/images/documents/watermarks/' . $up['filename'];
    }
    $saved = json_save($path, $d);
    if (!($saved['ok'] ?? false)) { $redirect('error','Unable to save settings.'); }
    $redirect('success','Quote defaults saved.');
}
$d = documents_get_quote_defaults_settings();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quote Settings</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}input,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px}.btn{display:inline-block;background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:8px 12px;text-decoration:none}</style></head><body><main class="wrap">
<div class="card"><h1>Quote Design & Finance Defaults</h1><a class="btn" href="admin-documents.php">Back</a></div>
<?php if (safe_text($_GET['message'] ?? '') !== ''): ?><div class="card"><?= htmlspecialchars((string)($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card grid"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)$d['global']['energy_defaults']['annual_generation_per_kw'], ENT_QUOTES) ?>"></div>
<div><label>Emission factor kg/kWh</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)$d['global']['energy_defaults']['emission_factor_kg_per_kwh'], ENT_QUOTES) ?>"></div>
<div><label>Tree absorption kg/tree/year</label><input type="number" step="0.01" name="tree_absorption_kg_per_tree_per_year" value="<?= htmlspecialchars((string)$d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'], ENT_QUOTES) ?>"></div>
<div><label>Base font px</label><input type="number" name="base_font_px" value="<?= htmlspecialchars((string)$d['global']['typography']['base_font_px'], ENT_QUOTES) ?>"></div>
<div><label>Heading scale</label><input type="number" step="0.1" name="heading_scale" value="<?= htmlspecialchars((string)$d['global']['typography']['heading_scale'], ENT_QUOTES) ?>"></div>
<div><label>Density</label><select name="density"><?php foreach(['compact','comfortable','spacious'] as $dn): ?><option value="<?= $dn ?>" <?= ($d['global']['typography']['density']??'comfortable')===$dn?'selected':'' ?>><?= $dn ?></option><?php endforeach; ?></select></div>
<?php foreach(['RES','COM','IND','INST'] as $seg): ?><div><label><?= $seg ?> unit rate ₹/kWh</label><input type="number" step="0.01" name="unit_rate_<?= $seg ?>" value="<?= htmlspecialchars((string)$d['segments'][$seg]['unit_rate_rs_per_kwh'], ENT_QUOTES) ?>"></div><div><label><?= $seg ?> loan tenure years</label><input type="number" name="tenure_<?= $seg ?>" value="<?= htmlspecialchars((string)($d['segments'][$seg]['loan_defaults']['tenure_years'] ?? ''), ENT_QUOTES) ?>"></div><div><label><?= $seg ?> est. bill after solar ₹</label><input type="number" step="0.01" name="estimated_bill_after_solar_<?= $seg ?>" value="<?= htmlspecialchars((string)($d['segments'][$seg]['estimated_bill_after_solar_rs'] ?? 200), ENT_QUOTES) ?>"></div><?php endforeach; ?>
<div style="grid-column:1/-1"><label>Header badges (one per line)</label><textarea name="header_badges" style="width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:8px;padding:8px"><?php foreach (($d['global']['branding']['header_badges'] ?? []) as $badge) { echo htmlspecialchars((string)$badge, ENT_QUOTES) . "
"; } ?></textarea></div>
<div><label><input type="checkbox" name="wm_enabled" <?= !empty($d['global']['branding']['watermark']['enabled'])?'checked':'' ?>> Enable print watermark</label></div>
<div><label>Watermark image upload</label><input type="file" name="wm_upload" accept="image/*"></div>
<div><label>Watermark opacity</label><input type="number" step="0.01" min="0" max="1" name="wm_opacity" value="<?= htmlspecialchars((string)$d['global']['branding']['watermark']['opacity'], ENT_QUOTES) ?>"></div>
<div><button class="btn" type="submit">Save</button></div></form></main></body></html>
