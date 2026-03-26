<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
$settings = solar_finance_settings();
$message='';$tone='success';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message='Invalid CSRF token.';$tone='error';
    } else {
        try {
            $settings['defaults']['unit_rate'] = (float) ($_POST['unit_rate'] ?? 8);
            $settings['defaults']['daily_generation_per_kw'] = (float) ($_POST['daily_generation_per_kw'] ?? 5);
            $settings['defaults']['interest_rate'] = (float) ($_POST['interest_rate'] ?? 6);
            $settings['defaults']['loan_tenure_years'] = (float) ($_POST['loan_tenure_years'] ?? 10);
            $settings['defaults']['roof_area_sqft_per_kw'] = (float) ($_POST['roof_area_sqft_per_kw'] ?? 100);
            $settings['defaults']['emission_factor_kg_per_kwh'] = (float) ($_POST['emission_factor_kg_per_kwh'] ?? 0.82);
            $settings['defaults']['co2_per_tree_kg'] = (float) ($_POST['co2_per_tree_kg'] ?? 21);
            $settings['defaults']['loan_above_2_lakh_default_margin_money'] = (float) ($_POST['loan_above_2_lakh_default_margin_money'] ?? 80000);
            $onGrid = json_decode((string)($_POST['on_grid_prices_json'] ?? '[]'), true);
            $hybrid = json_decode((string)($_POST['hybrid_prices_json'] ?? '[]'), true);
            $explainer = json_decode((string)($_POST['explainer_cards_json'] ?? '[]'), true);
            if (!is_array($onGrid) || !is_array($hybrid) || !is_array($explainer)) {
                throw new RuntimeException('JSON blocks must be valid arrays.');
            }
            $settings['on_grid_prices'] = $onGrid;
            $settings['hybrid_prices'] = $hybrid;
            $settings['explainer']['cards'] = $explainer;
            solar_finance_settings_save($settings);
            $settings = solar_finance_settings();
            $message='Solar and Finance settings saved.';
        } catch (Throwable $e) {
            $message='Failed to save: '.$e->getMessage();$tone='error';
        }
    }
}
$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Solar and Finance Settings</title><link rel="stylesheet" href="style.css">
</head><body class="admin-records"><main class="admin-records__shell">
<header class="admin-records__header"><div><h1>Solar and Finance Settings</h1><p class="admin-muted">Manage defaults, explainers, and pricing tables.</p></div><a class="admin-link" href="admin-dashboard.php">Back</a></header>
<?php if($message!==''): ?><div class="admin-alert admin-alert--<?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="post" class="admin-form admin-form--stacked">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<div class="admin-form__grid">
<label><span>Default unit rate</span><input type="number" step="0.01" name="unit_rate" value="<?= htmlspecialchars((string)($settings['defaults']['unit_rate'] ?? 8)) ?>"></label>
<label><span>Daily generation per kW</span><input type="number" step="0.01" name="daily_generation_per_kw" value="<?= htmlspecialchars((string)($settings['defaults']['daily_generation_per_kw'] ?? 5)) ?>"></label>
<label><span>Default interest rate</span><input type="number" step="0.01" name="interest_rate" value="<?= htmlspecialchars((string)($settings['defaults']['interest_rate'] ?? 6)) ?>"></label>
<label><span>Default tenure years</span><input type="number" step="1" name="loan_tenure_years" value="<?= htmlspecialchars((string)($settings['defaults']['loan_tenure_years'] ?? 10)) ?>"></label>
<label><span>Roof area sq ft per kW</span><input type="number" step="0.01" name="roof_area_sqft_per_kw" value="<?= htmlspecialchars((string)($settings['defaults']['roof_area_sqft_per_kw'] ?? 100)) ?>"></label>
<label><span>Emission factor (kg CO2 per kWh)</span><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($settings['defaults']['emission_factor_kg_per_kwh'] ?? 0.82)) ?>"></label>
<label><span>CO2 per tree kg</span><input type="number" step="0.01" name="co2_per_tree_kg" value="<?= htmlspecialchars((string)($settings['defaults']['co2_per_tree_kg'] ?? 21)) ?>"></label>
<label><span>Default margin money (>2L)</span><input type="number" step="1" name="loan_above_2_lakh_default_margin_money" value="<?= htmlspecialchars((string)($settings['defaults']['loan_above_2_lakh_default_margin_money'] ?? 80000)) ?>"></label>
</div>
<label><span>Explainer cards JSON</span><textarea name="explainer_cards_json" rows="10"><?= htmlspecialchars(json_encode($settings['explainer']['cards'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<label><span>On-grid prices JSON</span><textarea name="on_grid_prices_json" rows="10"><?= htmlspecialchars(json_encode($settings['on_grid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<label><span>Hybrid prices JSON</span><textarea name="hybrid_prices_json" rows="12"><?= htmlspecialchars(json_encode($settings['hybrid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<div class="admin-actions"><button type="submit" class="btn btn-primary">Save settings</button></div>
</form></main></body></html>
