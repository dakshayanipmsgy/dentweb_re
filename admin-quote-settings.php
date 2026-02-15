<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_admin();
documents_ensure_structure();

$path = documents_settings_dir() . '/quote_defaults.json';
$redirect = static function(string $type,string $message): void {
    header('Location: admin-quote-settings.php?status=' . $type . '&message=' . urlencode($message));
    exit;
};

$hex = static function(string $value, string $fallback): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('error', 'Security validation failed.');
    }

    $d = documents_get_quote_defaults_settings();

    $d['global']['theme']['primary_color'] = $hex((string)($_POST['theme_primary_color'] ?? ''), (string)($d['global']['theme']['primary_color'] ?? '#0f766e'));
    $d['global']['theme']['accent_color'] = $hex((string)($_POST['theme_accent_color'] ?? ''), (string)($d['global']['theme']['accent_color'] ?? '#f59e0b'));
    $d['global']['theme']['text_color'] = $hex((string)($_POST['theme_text_color'] ?? ''), (string)($d['global']['theme']['text_color'] ?? '#0f172a'));
    $d['global']['theme']['muted_text_color'] = $hex((string)($_POST['theme_muted_text_color'] ?? ''), (string)($d['global']['theme']['muted_text_color'] ?? '#475569'));
    $d['global']['theme']['background_color'] = $hex((string)($_POST['theme_background_color'] ?? ''), (string)($d['global']['theme']['background_color'] ?? '#eef3f9'));
    $d['global']['theme']['card_background_color'] = $hex((string)($_POST['theme_card_background_color'] ?? ''), (string)($d['global']['theme']['card_background_color'] ?? '#ffffff'));
    $d['global']['theme']['border_color'] = $hex((string)($_POST['theme_border_color'] ?? ''), (string)($d['global']['theme']['border_color'] ?? '#dbe1ea'));
    $d['global']['theme']['shadow_strength'] = in_array((string)($_POST['theme_shadow_strength'] ?? 'soft'), ['none','soft','medium','strong'], true) ? (string)$_POST['theme_shadow_strength'] : 'soft';
    $d['global']['theme']['border_radius_px'] = (int)($_POST['theme_border_radius_px'] ?? 14);
    $d['global']['theme']['base_font_px'] = (int)($_POST['theme_base_font_px'] ?? 14);
    $d['global']['theme']['h1_px'] = (int)($_POST['theme_h1_px'] ?? 24);
    $d['global']['theme']['h2_px'] = (int)($_POST['theme_h2_px'] ?? 18);
    $d['global']['theme']['h3_px'] = (int)($_POST['theme_h3_px'] ?? 15);
    $d['global']['theme']['line_height'] = (float)($_POST['theme_line_height'] ?? 1.5);
    $d['global']['theme']['header_gradient_enabled'] = isset($_POST['theme_header_gradient_enabled']);
    $d['global']['theme']['header_gradient_from'] = $hex((string)($_POST['theme_header_gradient_from'] ?? ''), (string)($d['global']['theme']['header_gradient_from'] ?? '#0f766e'));
    $d['global']['theme']['header_gradient_to'] = $hex((string)($_POST['theme_header_gradient_to'] ?? ''), (string)($d['global']['theme']['header_gradient_to'] ?? '#22c55e'));
    $d['global']['theme']['footer_gradient_enabled'] = isset($_POST['theme_footer_gradient_enabled']);
    $d['global']['theme']['footer_gradient_from'] = $hex((string)($_POST['theme_footer_gradient_from'] ?? ''), (string)($d['global']['theme']['footer_gradient_from'] ?? '#0f172a'));
    $d['global']['theme']['footer_gradient_to'] = $hex((string)($_POST['theme_footer_gradient_to'] ?? ''), (string)($d['global']['theme']['footer_gradient_to'] ?? '#1e293b'));

    $d['global']['energy_defaults']['annual_generation_per_kw'] = (float)($_POST['annual_generation_per_kw'] ?? 1450);
    $d['global']['energy_defaults']['emission_factor_kg_per_kwh'] = (float)($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
    $d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] = (float)($_POST['tree_absorption_kg_per_tree_per_year'] ?? 20);

    $d['global']['calculation_defaults']['unit_rate_residential'] = (float)($_POST['unit_rate_residential'] ?? ($d['segments']['RES']['unit_rate_rs_per_kwh'] ?? 8));
    $d['global']['calculation_defaults']['unit_rate_industrial'] = (float)($_POST['unit_rate_industrial'] ?? ($d['segments']['IND']['unit_rate_rs_per_kwh'] ?? 11));
    $d['global']['calculation_defaults']['unit_rate_commercial'] = (float)($_POST['unit_rate_commercial'] ?? ($d['segments']['COM']['unit_rate_rs_per_kwh'] ?? 10));
    $d['global']['calculation_defaults']['unit_rate_institutional'] = (float)($_POST['unit_rate_institutional'] ?? ($d['segments']['INST']['unit_rate_rs_per_kwh'] ?? 9));
    $d['global']['calculation_defaults']['loan_bestcase_interest_pct'] = (float)($_POST['loan_bestcase_interest_pct'] ?? ($d['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6));
    $d['global']['calculation_defaults']['loan_bestcase_tenure_years'] = (int)($_POST['loan_bestcase_tenure_years'] ?? ($d['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10));
    $d['global']['calculation_defaults']['annual_generation_per_kw'] = (float)($_POST['annual_generation_per_kw'] ?? 1450);
    $d['global']['calculation_defaults']['emission_factor_kg_per_kwh'] = (float)($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
    $d['global']['calculation_defaults']['tree_absorption_kg_per_tree_per_year'] = (float)($_POST['tree_absorption_kg_per_tree_per_year'] ?? 20);

    $d['segments']['RES']['unit_rate_rs_per_kwh'] = (float)$d['global']['calculation_defaults']['unit_rate_residential'];
    $d['segments']['IND']['unit_rate_rs_per_kwh'] = (float)$d['global']['calculation_defaults']['unit_rate_industrial'];
    $d['segments']['COM']['unit_rate_rs_per_kwh'] = (float)$d['global']['calculation_defaults']['unit_rate_commercial'];
    $d['segments']['INST']['unit_rate_rs_per_kwh'] = (float)$d['global']['calculation_defaults']['unit_rate_institutional'];
    $d['segments']['RES']['loan_bestcase']['interest_pct'] = (float)$d['global']['calculation_defaults']['loan_bestcase_interest_pct'];
    $d['segments']['RES']['loan_bestcase']['tenure_years'] = (int)$d['global']['calculation_defaults']['loan_bestcase_tenure_years'];

    foreach (['primary_color','secondary_color','accent_color','header_bg','header_text','footer_bg','footer_text','chip_bg','chip_text'] as $colorKey) {
        $d['global']['branding'][$colorKey] = safe_text($_POST[$colorKey] ?? (string) ($d['global']['branding'][$colorKey] ?? ''));
    }

    $d['defaults']['hsn_solar'] = safe_text($_POST['default_hsn_solar'] ?? (string) ($d['defaults']['hsn_solar'] ?? '8541')) ?: '8541';
    $d['defaults']['cover_note_template'] = trim((string) ($_POST['cover_note_template'] ?? (string) ($d['defaults']['cover_note_template'] ?? '')));
    $d['global']['branding']['watermark']['enabled'] = isset($_POST['wm_enabled']);
    $d['global']['branding']['watermark']['opacity'] = (float)($_POST['wm_opacity'] ?? 0.08);

    if (isset($_FILES['wm_upload']) && (int)($_FILES['wm_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = documents_handle_image_upload($_FILES['wm_upload'], documents_public_watermarks_dir(), 'watermark_');
        if (!($up['ok'] ?? false)) {
            $redirect('error', (string)($up['error'] ?? 'Upload failed.'));
        }
        $d['global']['branding']['watermark']['image_path'] = '/images/documents/watermarks/' . $up['filename'];
    }

    $saved = json_save($path, $d);
    if (!($saved['ok'] ?? false)) {
        $redirect('error', 'Unable to save settings.');
    }
    $redirect('success', 'Quote defaults saved.');
}

$d = documents_get_quote_defaults_settings();
$theme = $d['global']['theme'] ?? [];
$calc = $d['global']['calculation_defaults'] ?? [];
$colorField = static function(string $label, string $name, string $value): void {
    echo '<div><label>' . htmlspecialchars($label, ENT_QUOTES) . '</label><div style="display:flex;gap:8px;align-items:center"><input type="color" name="' . htmlspecialchars($name, ENT_QUOTES) . '_picker" value="' . htmlspecialchars($value, ENT_QUOTES) . '" data-target="' . htmlspecialchars($name, ENT_QUOTES) . '"><input name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '" pattern="^#[0-9a-fA-F]{6}$" required><span style="width:20px;height:20px;border:1px solid #999;border-radius:4px;background:' . htmlspecialchars($value, ENT_QUOTES) . '" data-swatch="' . htmlspecialchars($name, ENT_QUOTES) . '"></span></div></div>';
};
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quote Settings</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}input,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px}.btn{display:inline-block;background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:8px 12px;text-decoration:none}.tabs{display:flex;gap:8px;margin-bottom:8px}.tab-btn{cursor:pointer;background:#e2e8f0;border:1px solid #cbd5e1;padding:8px 12px;border-radius:8px}.tab-btn.active{background:#1d4ed8;color:#fff}.tab{display:none}.tab.active{display:block}.strip{height:24px;border-radius:8px;border:1px solid #cbd5e1}</style></head>
<body><main class="wrap">
<div class="card"><h1>Quote Design & Finance Defaults</h1><a class="btn" href="admin-documents.php">Back</a></div>
<?php if (safe_text($_GET['message'] ?? '') !== ''): ?><div class="card"><?= htmlspecialchars((string)($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
<div class="tabs"><button type="button" class="tab-btn active" data-tab="design">Design + Defaults</button><button type="button" class="tab-btn" data-tab="legacy">Existing Branding</button></div>
<div class="tab active" id="tab-design">
<div class="grid">
<?php $colorField('Primary color','theme_primary_color',(string)($theme['primary_color'] ?? '#0f766e')); ?>
<?php $colorField('Accent color','theme_accent_color',(string)($theme['accent_color'] ?? '#f59e0b')); ?>
<?php $colorField('Text color','theme_text_color',(string)($theme['text_color'] ?? '#0f172a')); ?>
<?php $colorField('Muted text color','theme_muted_text_color',(string)($theme['muted_text_color'] ?? '#475569')); ?>
<?php $colorField('Background color','theme_background_color',(string)($theme['background_color'] ?? '#eef3f9')); ?>
<?php $colorField('Card background color','theme_card_background_color',(string)($theme['card_background_color'] ?? '#ffffff')); ?>
<?php $colorField('Border color','theme_border_color',(string)($theme['border_color'] ?? '#dbe1ea')); ?>
<div><label>Shadow strength</label><select name="theme_shadow_strength"><?php foreach(['none','soft','medium','strong'] as $sh): ?><option value="<?= $sh ?>" <?= (($theme['shadow_strength'] ?? 'soft')===$sh)?'selected':'' ?>><?= $sh ?></option><?php endforeach; ?></select></div>
<div><label>Border radius px</label><input type="number" name="theme_border_radius_px" value="<?= htmlspecialchars((string)($theme['border_radius_px'] ?? 14), ENT_QUOTES) ?>"></div>
<div><label>Base font px</label><input type="number" name="theme_base_font_px" value="<?= htmlspecialchars((string)($theme['base_font_px'] ?? 14), ENT_QUOTES) ?>"></div>
<div><label>H1 px</label><input type="number" name="theme_h1_px" value="<?= htmlspecialchars((string)($theme['h1_px'] ?? 24), ENT_QUOTES) ?>"></div>
<div><label>H2 px</label><input type="number" name="theme_h2_px" value="<?= htmlspecialchars((string)($theme['h2_px'] ?? 18), ENT_QUOTES) ?>"></div>
<div><label>H3 px</label><input type="number" name="theme_h3_px" value="<?= htmlspecialchars((string)($theme['h3_px'] ?? 15), ENT_QUOTES) ?>"></div>
<div><label>Line height</label><input type="number" step="0.1" name="theme_line_height" value="<?= htmlspecialchars((string)($theme['line_height'] ?? 1.5), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="theme_header_gradient_enabled" <?= !empty($theme['header_gradient_enabled'])?'checked':'' ?>> Header gradient enabled</label></div>
<?php $colorField('Header gradient from','theme_header_gradient_from',(string)($theme['header_gradient_from'] ?? '#0f766e')); ?>
<?php $colorField('Header gradient to','theme_header_gradient_to',(string)($theme['header_gradient_to'] ?? '#22c55e')); ?>
<div style="grid-column:1/-1"><div class="strip" id="headerStrip"></div></div>
<div><label><input type="checkbox" name="theme_footer_gradient_enabled" <?= !empty($theme['footer_gradient_enabled'])?'checked':'' ?>> Footer gradient enabled</label></div>
<?php $colorField('Footer gradient from','theme_footer_gradient_from',(string)($theme['footer_gradient_from'] ?? '#0f172a')); ?>
<?php $colorField('Footer gradient to','theme_footer_gradient_to',(string)($theme['footer_gradient_to'] ?? '#1e293b')); ?>
<div style="grid-column:1/-1"><div class="strip" id="footerStrip"></div></div>

<div><label>Residential unit rate</label><input type="number" step="0.01" name="unit_rate_residential" value="<?= htmlspecialchars((string)($calc['unit_rate_residential'] ?? $d['segments']['RES']['unit_rate_rs_per_kwh'] ?? 8), ENT_QUOTES) ?>"></div>
<div><label>Industrial unit rate</label><input type="number" step="0.01" name="unit_rate_industrial" value="<?= htmlspecialchars((string)($calc['unit_rate_industrial'] ?? $d['segments']['IND']['unit_rate_rs_per_kwh'] ?? 11), ENT_QUOTES) ?>"></div>
<div><label>Commercial unit rate</label><input type="number" step="0.01" name="unit_rate_commercial" value="<?= htmlspecialchars((string)($calc['unit_rate_commercial'] ?? $d['segments']['COM']['unit_rate_rs_per_kwh'] ?? 10), ENT_QUOTES) ?>"></div>
<div><label>Institutional unit rate</label><input type="number" step="0.01" name="unit_rate_institutional" value="<?= htmlspecialchars((string)($calc['unit_rate_institutional'] ?? $d['segments']['INST']['unit_rate_rs_per_kwh'] ?? 9), ENT_QUOTES) ?>"></div>
<div><label>Loan best-case interest %</label><input type="number" step="0.01" name="loan_bestcase_interest_pct" value="<?= htmlspecialchars((string)($calc['loan_bestcase_interest_pct'] ?? $d['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6), ENT_QUOTES) ?>"></div>
<div><label>Loan best-case tenure years</label><input type="number" step="1" name="loan_bestcase_tenure_years" value="<?= htmlspecialchars((string)($calc['loan_bestcase_tenure_years'] ?? $d['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10), ENT_QUOTES) ?>"></div>
<div><label>Annual generation per kW</label><input type="number" step="0.01" name="annual_generation_per_kw" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450), ENT_QUOTES) ?>"></div>
<div><label>Emission factor</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82), ENT_QUOTES) ?>"></div>
<div><label>CO2 absorption per tree per year</label><input type="number" step="0.01" name="tree_absorption_kg_per_tree_per_year" value="<?= htmlspecialchars((string)($d['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20), ENT_QUOTES) ?>"></div>
</div></div>

<div class="tab" id="tab-legacy"><div class="grid">
<div><label>Default HSN (solar)</label><input name="default_hsn_solar" value="<?= htmlspecialchars((string)($d['defaults']['hsn_solar'] ?? '8541'), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><label>Default Cover Note Template</label><input name="cover_note_template" value="<?= htmlspecialchars((string)($d['defaults']['cover_note_template'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Primary color</label><input type="color" name="primary_color" value="<?= htmlspecialchars((string)($d['global']['branding']['primary_color'] ?? '#0f766e'), ENT_QUOTES) ?>"></div>
<div><label>Secondary color</label><input type="color" name="secondary_color" value="<?= htmlspecialchars((string)($d['global']['branding']['secondary_color'] ?? '#22c55e'), ENT_QUOTES) ?>"></div>
<div><label>Accent color</label><input type="color" name="accent_color" value="<?= htmlspecialchars((string)($d['global']['branding']['accent_color'] ?? '#f59e0b'), ENT_QUOTES) ?>"></div>
<div><label>Header background</label><input type="color" name="header_bg" value="<?= htmlspecialchars((string)($d['global']['branding']['header_bg'] ?? '#0f766e'), ENT_QUOTES) ?>"></div>
<div><label>Header text</label><input type="color" name="header_text" value="<?= htmlspecialchars((string)($d['global']['branding']['header_text'] ?? '#ecfeff'), ENT_QUOTES) ?>"></div>
<div><label>Footer background</label><input type="color" name="footer_bg" value="<?= htmlspecialchars((string)($d['global']['branding']['footer_bg'] ?? '#0f172a'), ENT_QUOTES) ?>"></div>
<div><label>Footer text</label><input type="color" name="footer_text" value="<?= htmlspecialchars((string)($d['global']['branding']['footer_text'] ?? '#e2e8f0'), ENT_QUOTES) ?>"></div>
<div><label>Chip background</label><input type="color" name="chip_bg" value="<?= htmlspecialchars((string)($d['global']['branding']['chip_bg'] ?? '#ccfbf1'), ENT_QUOTES) ?>"></div>
<div><label>Chip text</label><input type="color" name="chip_text" value="<?= htmlspecialchars((string)($d['global']['branding']['chip_text'] ?? '#134e4a'), ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="wm_enabled" <?= !empty($d['global']['branding']['watermark']['enabled'])?'checked':'' ?>> Enable print watermark</label></div>
<div><label>Watermark image upload</label><input type="file" name="wm_upload" accept="image/*"></div>
<div><label>Watermark opacity</label><input type="number" step="0.01" min="0" max="1" name="wm_opacity" value="<?= htmlspecialchars((string)$d['global']['branding']['watermark']['opacity'], ENT_QUOTES) ?>"></div>
</div></div>
<br><button class="btn" type="submit">Save</button>
</form></main>
<script>
document.querySelectorAll('.tab-btn').forEach((btn)=>btn.addEventListener('click',()=>{document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));btn.classList.add('active');document.getElementById('tab-'+btn.dataset.tab).classList.add('active');}));
document.querySelectorAll('input[type=color][data-target]').forEach((picker)=>picker.addEventListener('input',()=>{const t=document.querySelector('input[name="'+picker.dataset.target+'"]');if(t){t.value=picker.value;t.dispatchEvent(new Event('input'));}}));
document.querySelectorAll('input[pattern^="^"]').forEach((inp)=>inp.addEventListener('input',()=>{const sw=document.querySelector('[data-swatch="'+inp.name+'"]');if(/^#[0-9a-fA-F]{6}$/.test(inp.value)){if(sw)sw.style.background=inp.value;const pk=document.querySelector('input[type=color][data-target="'+inp.name+'"]');if(pk)pk.value=inp.value;}updateStrips();}));
function updateStrips(){const h1=document.querySelector('input[name=theme_header_gradient_from]').value,h2=document.querySelector('input[name=theme_header_gradient_to]').value,f1=document.querySelector('input[name=theme_footer_gradient_from]').value,f2=document.querySelector('input[name=theme_footer_gradient_to]').value;document.getElementById('headerStrip').style.background='linear-gradient(90deg,'+h1+','+h2+')';document.getElementById('footerStrip').style.background='linear-gradient(90deg,'+f1+','+f2+')';}
updateStrips();
</script></body></html>
