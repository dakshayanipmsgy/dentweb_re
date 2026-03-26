<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance.php';

require_admin();

function asf_text(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$csrfToken = $_SESSION['csrf_token'] ?? '';
$flash = '';
$tone = 'info';
$settings = solar_finance_settings_load();
$explainer = solar_finance_explainer_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired. Please retry.';
        $tone = 'error';
    } else {
        $defaults = [
            'unit_rate' => (float) ($_POST['unit_rate'] ?? 8),
            'daily_generation_per_kw' => (float) ($_POST['daily_generation_per_kw'] ?? 5),
            'interest_rate' => (float) ($_POST['interest_rate'] ?? 6),
            'tenure_years' => (int) ($_POST['tenure_years'] ?? 10),
            'roof_area_sqft_per_kw' => (float) ($_POST['roof_area_sqft_per_kw'] ?? 100),
            'emission_factor' => (float) ($_POST['emission_factor'] ?? 0.82),
            'co2_per_tree' => (float) ($_POST['co2_per_tree'] ?? 21),
        ];

        $onGrid = json_decode((string)($_POST['on_grid_prices_json'] ?? '[]'), true);
        $hybrid = json_decode((string)($_POST['hybrid_prices_json'] ?? '[]'), true);
        if (!is_array($onGrid) || !is_array($hybrid)) {
            $flash = 'Invalid JSON in pricing tables.';
            $tone = 'error';
        } else {
            solar_finance_settings_save([
                'defaults' => $defaults,
                'on_grid_prices' => $onGrid,
                'hybrid_prices' => $hybrid,
            ]);

            $explainerKeys = [
                'what_is_solar_rooftop', 'pm_surya_ghar_text', 'who_is_eligible', 'on_grid_text', 'hybrid_text',
                'which_one_is_suitable_for_whom', 'benefits', 'important_expectations', 'faq_text',
                'on_grid_image', 'hybrid_image', 'process_flow_image', 'benefits_image'
            ];
            foreach ($explainerKeys as $key) {
                $explainer[$key] = trim((string)($_POST[$key] ?? ''));
            }
            solar_finance_explainer_save($explainer);

            $settings = solar_finance_settings_load();
            $explainer = solar_finance_explainer_load();
            $flash = 'Solar and Finance settings saved.';
            $tone = 'success';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar & Finance Settings</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="admin-records" data-theme="light">
<main class="admin-records__shell">
  <header class="admin-records__header"><div><h1>Solar & Finance Settings</h1><p class="admin-muted">Manage defaults, pricing tables, and explainer content.</p></div><a class="admin-link" href="admin-dashboard.php">Back to dashboard</a></header>
  <?php if ($flash !== ''): ?><div class="admin-alert admin-alert--<?= asf_text($tone) ?>"><?= asf_text($flash) ?></div><?php endif; ?>
  <section class="admin-section"><section class="admin-section__body">
    <form method="post" class="admin-form admin-form--stacked">
      <input type="hidden" name="csrf_token" value="<?= asf_text($csrfToken) ?>" />
      <h3>Defaults</h3>
      <div class="admin-form__grid">
        <label><span>Unit rate (₹/kWh)</span><input type="number" step="0.01" name="unit_rate" value="<?= asf_text((string)($settings['defaults']['unit_rate'] ?? 8)) ?>"></label>
        <label><span>Daily generation per kW</span><input type="number" step="0.01" name="daily_generation_per_kw" value="<?= asf_text((string)($settings['defaults']['daily_generation_per_kw'] ?? 5)) ?>"></label>
        <label><span>Interest rate (%)</span><input type="number" step="0.01" name="interest_rate" value="<?= asf_text((string)($settings['defaults']['interest_rate'] ?? 6)) ?>"></label>
        <label><span>Tenure (years)</span><input type="number" step="1" name="tenure_years" value="<?= asf_text((string)($settings['defaults']['tenure_years'] ?? 10)) ?>"></label>
        <label><span>Roof area factor (sqft/kW)</span><input type="number" step="0.1" name="roof_area_sqft_per_kw" value="<?= asf_text((string)($settings['defaults']['roof_area_sqft_per_kw'] ?? 100)) ?>"></label>
        <label><span>CO₂ emission factor</span><input type="number" step="0.01" name="emission_factor" value="<?= asf_text((string)($settings['defaults']['emission_factor'] ?? 0.82)) ?>"></label>
        <label><span>CO₂ per tree factor</span><input type="number" step="0.01" name="co2_per_tree" value="<?= asf_text((string)($settings['defaults']['co2_per_tree'] ?? 21)) ?>"></label>
      </div>
      <h3>On-grid price table (JSON)</h3>
      <textarea name="on_grid_prices_json" rows="10"><?= asf_text(json_encode($settings['on_grid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
      <h3>Hybrid price table (JSON)</h3>
      <textarea name="hybrid_prices_json" rows="14"><?= asf_text(json_encode($settings['hybrid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
      <h3>Explainer Content</h3>
      <div class="admin-form__grid">
        <label><span>What is solar rooftop</span><textarea name="what_is_solar_rooftop"><?= asf_text((string)($explainer['what_is_solar_rooftop'] ?? '')) ?></textarea></label>
        <label><span>PM Surya Ghar text</span><textarea name="pm_surya_ghar_text"><?= asf_text((string)($explainer['pm_surya_ghar_text'] ?? '')) ?></textarea></label>
        <label><span>Who is eligible</span><textarea name="who_is_eligible"><?= asf_text((string)($explainer['who_is_eligible'] ?? '')) ?></textarea></label>
        <label><span>On-grid text</span><textarea name="on_grid_text"><?= asf_text((string)($explainer['on_grid_text'] ?? '')) ?></textarea></label>
        <label><span>Hybrid text</span><textarea name="hybrid_text"><?= asf_text((string)($explainer['hybrid_text'] ?? '')) ?></textarea></label>
        <label><span>Suitable for whom</span><textarea name="which_one_is_suitable_for_whom"><?= asf_text((string)($explainer['which_one_is_suitable_for_whom'] ?? '')) ?></textarea></label>
        <label><span>Benefits</span><textarea name="benefits"><?= asf_text((string)($explainer['benefits'] ?? '')) ?></textarea></label>
        <label><span>Important expectations</span><textarea name="important_expectations"><?= asf_text((string)($explainer['important_expectations'] ?? '')) ?></textarea></label>
        <label><span>FAQ</span><textarea name="faq_text"><?= asf_text((string)($explainer['faq_text'] ?? '')) ?></textarea></label>
        <label><span>On-grid image URL</span><input name="on_grid_image" value="<?= asf_text((string)($explainer['on_grid_image'] ?? '')) ?>"></label>
        <label><span>Hybrid image URL</span><input name="hybrid_image" value="<?= asf_text((string)($explainer['hybrid_image'] ?? '')) ?>"></label>
        <label><span>Process image URL</span><input name="process_flow_image" value="<?= asf_text((string)($explainer['process_flow_image'] ?? '')) ?>"></label>
        <label><span>Benefits image URL</span><input name="benefits_image" value="<?= asf_text((string)($explainer['benefits_image'] ?? '')) ?>"></label>
      </div>
      <div class="admin-actions"><button class="btn btn-primary" type="submit">Save Solar & Finance Settings</button></div>
    </form>
  </section></section>
</main>
</body>
</html>
