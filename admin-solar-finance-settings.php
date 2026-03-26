<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
start_session();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$settings = solar_finance_settings_load();
$flash = '';
$tone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = 'Session expired. Refresh and try again.';
        $tone = 'error';
    } else {
        try {
            $defaults = [
                'unit_rate' => (float) ($_POST['unit_rate'] ?? 8),
                'daily_generation_per_kw' => (float) ($_POST['daily_generation_per_kw'] ?? 5),
                'interest_rate' => (float) ($_POST['interest_rate'] ?? 6),
                'loan_tenure_years' => (int) ($_POST['loan_tenure_years'] ?? 10),
                'roof_area_sqft_per_kw' => (float) ($_POST['roof_area_sqft_per_kw'] ?? 100),
                'emission_factor_kg_per_kwh' => (float) ($_POST['emission_factor_kg_per_kwh'] ?? 0.82),
                'co2_per_tree_kg_per_year' => (float) ($_POST['co2_per_tree_kg_per_year'] ?? 21),
            ];
            $explainer = json_decode((string) ($_POST['explainer_blocks_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $onGrid = json_decode((string) ($_POST['on_grid_prices_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $hybrid = json_decode((string) ($_POST['hybrid_prices_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);

            $settings['defaults'] = $defaults;
            $settings['explainer_blocks'] = is_array($explainer) ? $explainer : [];
            $settings['on_grid_prices'] = is_array($onGrid) ? $onGrid : [];
            $settings['hybrid_prices'] = is_array($hybrid) ? $hybrid : [];
            solar_finance_settings_save($settings);
            $settings = solar_finance_settings_load();
            $flash = 'Solar and Finance settings saved.';
            $tone = 'success';
        } catch (Throwable $e) {
            $flash = 'Save failed: ' . $e->getMessage();
            $tone = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>Solar & Finance Settings</title><link rel="stylesheet" href="/style.css"/></head>
<body class="admin-records"><main class="admin-records__shell"><header class="admin-records__header"><div><h1>Solar & Finance Settings</h1><p class="admin-muted">Edit defaults, explainers, on-grid and hybrid pricing tables (JSON).</p></div><a class="admin-link" href="/admin-dashboard.php">Back</a></header>
<?php if ($flash !== ''): ?><div class="admin-alert admin-alert--<?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<form method="post" class="admin-section admin-form admin-form--stacked"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>"/>
<div class="admin-form__grid">
<label><span>Default unit rate</span><input name="unit_rate" type="number" step="0.1" value="<?= htmlspecialchars((string) ($settings['defaults']['unit_rate'] ?? 8)) ?>"/></label>
<label><span>Default daily generation per kW</span><input name="daily_generation_per_kw" type="number" step="0.1" value="<?= htmlspecialchars((string) ($settings['defaults']['daily_generation_per_kw'] ?? 5)) ?>"/></label>
<label><span>Default interest rate</span><input name="interest_rate" type="number" step="0.1" value="<?= htmlspecialchars((string) ($settings['defaults']['interest_rate'] ?? 6)) ?>"/></label>
<label><span>Default tenure (years)</span><input name="loan_tenure_years" type="number" step="1" value="<?= htmlspecialchars((string) ($settings['defaults']['loan_tenure_years'] ?? 10)) ?>"/></label>
<label><span>Roof area factor sqft/kW</span><input name="roof_area_sqft_per_kw" type="number" step="1" value="<?= htmlspecialchars((string) ($settings['defaults']['roof_area_sqft_per_kw'] ?? 100)) ?>"/></label>
<label><span>CO₂ emission factor</span><input name="emission_factor_kg_per_kwh" type="number" step="0.01" value="<?= htmlspecialchars((string) ($settings['defaults']['emission_factor_kg_per_kwh'] ?? 0.82)) ?>"/></label>
<label><span>CO₂ per tree per year</span><input name="co2_per_tree_kg_per_year" type="number" step="0.1" value="<?= htmlspecialchars((string) ($settings['defaults']['co2_per_tree_kg_per_year'] ?? 21)) ?>"/></label>
</div>
<label><span>Explainer blocks JSON</span><textarea name="explainer_blocks_json" rows="12"><?= htmlspecialchars((string) json_encode($settings['explainer_blocks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<label><span>On-grid prices JSON</span><textarea name="on_grid_prices_json" rows="12"><?= htmlspecialchars((string) json_encode($settings['on_grid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<label><span>Hybrid prices JSON</span><textarea name="hybrid_prices_json" rows="16"><?= htmlspecialchars((string) json_encode($settings['hybrid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea></label>
<div class="admin-actions"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form></main></body></html>
