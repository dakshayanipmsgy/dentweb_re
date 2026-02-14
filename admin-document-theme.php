<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$path = documents_settings_dir() . '/doc_theme.json';
$redirectWith = static function (string $type, string $msg): void {
    header('Location: admin-document-theme.php?' . http_build_query(['status' => $type, 'message' => $msg]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $theme = documents_get_doc_theme();
    $theme['global']['primary_color'] = safe_text($_POST['primary_color'] ?? $theme['global']['primary_color']);
    $theme['global']['accent_color'] = safe_text($_POST['accent_color'] ?? $theme['global']['accent_color']);
    $theme['global']['muted_color'] = safe_text($_POST['muted_color'] ?? $theme['global']['muted_color']);
    $theme['global']['base_font_px'] = max(12, min(18, (int) ($_POST['base_font_px'] ?? $theme['global']['base_font_px'])));
    $theme['global']['heading_scale'] = max(1.0, min(1.8, (float) ($_POST['heading_scale'] ?? $theme['global']['heading_scale'])));
    $theme['global']['card_radius_px'] = max(4, min(30, (int) ($_POST['card_radius_px'] ?? $theme['global']['card_radius_px'])));
    $theme['global']['table_border_color'] = safe_text($_POST['table_border_color'] ?? $theme['global']['table_border_color']);
    $theme['global']['background_enabled'] = isset($_POST['background_enabled']);
    $theme['global']['background_image_path'] = safe_text($_POST['background_image_path'] ?? '');
    $theme['global']['background_opacity'] = max(0.05, min(1.0, (float) ($_POST['background_opacity'] ?? $theme['global']['background_opacity'])));

    foreach (['use_modern_layout','show_icons','show_emojis','charts_enabled','cover_page_enabled','system_overview_page_enabled','savings_page_enabled','pricing_page_enabled','faq_page_enabled','contact_page_enabled'] as $flag) {
        $theme['quotation_defaults'][$flag] = isset($_POST[$flag]);
    }

    foreach (['analysis_years','unit_rate_rs','annual_gen_per_kw','bill_escalation_pct','loan_interest_pct','loan_tenure_years','down_payment_pct','residual_bill_pct','ef_kg_per_kwh','kg_co2_per_tree_per_year'] as $k) {
        $theme['calc_defaults'][$k] = is_numeric($_POST[$k] ?? null) ? (float) $_POST[$k] : $theme['calc_defaults'][$k];
    }
    $theme['calc_defaults']['analysis_years'] = max(1, (int) $theme['calc_defaults']['analysis_years']);
    $theme['calc_defaults']['loan_tenure_years'] = max(1, (int) $theme['calc_defaults']['loan_tenure_years']);
    $financeMode = safe_text($_POST['finance_mode'] ?? (string) $theme['calc_defaults']['finance_mode']);
    $theme['calc_defaults']['finance_mode'] = in_array($financeMode, ['loan','cash'], true) ? $financeMode : 'loan';

    $saved = json_save($path, $theme);
    if (!($saved['ok'] ?? false)) {
        $redirectWith('error', 'Unable to save document theme settings.');
    }
    $redirectWith('success', 'Document theme updated.');
}

$theme = documents_get_doc_theme();
$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Document Theme</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.page{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}input,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}.btn{background:#1d4ed8;color:#fff;border:none;padding:9px 12px;border-radius:8px}label{display:block;font-size:12px;margin-bottom:4px}h2{margin:0 0 10px}a{color:#1d4ed8}</style>
</head><body><main class="page">
<div class="card"><a href="admin-documents.php">← Documents</a></div>
<?php if ($message !== ''): ?><div class="card" style="background:<?= $status==='error'?'#fef2f2':'#ecfdf5' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" class="card">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
<h2>Global Theme</h2><div class="grid">
<div><label>Primary color</label><input name="primary_color" value="<?= htmlspecialchars((string)$theme['global']['primary_color'], ENT_QUOTES) ?>"></div>
<div><label>Accent color</label><input name="accent_color" value="<?= htmlspecialchars((string)$theme['global']['accent_color'], ENT_QUOTES) ?>"></div>
<div><label>Muted color</label><input name="muted_color" value="<?= htmlspecialchars((string)$theme['global']['muted_color'], ENT_QUOTES) ?>"></div>
<div><label>Base font px</label><input type="number" min="12" max="18" name="base_font_px" value="<?= htmlspecialchars((string)$theme['global']['base_font_px'], ENT_QUOTES) ?>"></div>
<div><label>Heading scale</label><input type="number" step="0.05" min="1" max="1.8" name="heading_scale" value="<?= htmlspecialchars((string)$theme['global']['heading_scale'], ENT_QUOTES) ?>"></div>
<div><label>Card radius px</label><input type="number" min="4" max="30" name="card_radius_px" value="<?= htmlspecialchars((string)$theme['global']['card_radius_px'], ENT_QUOTES) ?>"></div>
<div><label>Table border color</label><input name="table_border_color" value="<?= htmlspecialchars((string)$theme['global']['table_border_color'], ENT_QUOTES) ?>"></div>
<div><label>Background image path</label><input name="background_image_path" value="<?= htmlspecialchars((string)$theme['global']['background_image_path'], ENT_QUOTES) ?>"></div>
<div><label>Background opacity</label><input type="number" step="0.01" min="0.05" max="1" name="background_opacity" value="<?= htmlspecialchars((string)$theme['global']['background_opacity'], ENT_QUOTES) ?>"></div>
<div><label><input type="checkbox" name="background_enabled" <?= !empty($theme['global']['background_enabled'])?'checked':'' ?>> Background enabled</label></div>
</div>
<h2>Quotation Defaults</h2><div class="grid"><?php foreach ($theme['quotation_defaults'] as $k=>$v): if ($k==='page_count_style') continue; ?><label><input type="checkbox" name="<?= htmlspecialchars((string)$k, ENT_QUOTES) ?>" <?= !empty($v)?'checked':'' ?>> <?= htmlspecialchars((string)$k, ENT_QUOTES) ?></label><?php endforeach; ?></div>
<h2>Calculator Defaults</h2><div class="grid">
<?php foreach (['analysis_years','unit_rate_rs','annual_gen_per_kw','bill_escalation_pct','loan_interest_pct','loan_tenure_years','down_payment_pct','residual_bill_pct','ef_kg_per_kwh','kg_co2_per_tree_per_year'] as $k): ?>
<div><label><?= htmlspecialchars((string)$k, ENT_QUOTES) ?></label><input type="number" step="0.01" name="<?= htmlspecialchars((string)$k, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string)$theme['calc_defaults'][$k], ENT_QUOTES) ?>"></div>
<?php endforeach; ?>
<div><label>finance_mode</label><select name="finance_mode"><option value="loan" <?= ($theme['calc_defaults']['finance_mode'] ?? '')==='loan'?'selected':'' ?>>loan</option><option value="cash" <?= ($theme['calc_defaults']['finance_mode'] ?? '')==='cash'?'selected':'' ?>>cash</option></select></div>
</div><br><button class="btn" type="submit">Save Theme</button>
</form>
</main></body></html>
