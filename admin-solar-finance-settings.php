<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$csrfToken = $_SESSION['csrf_token'] ?? '';
$flash = '';
$tone = 'info';
$settings = solar_finance_settings_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired. Refresh and try again.';
        $tone = 'error';
    } else {
        $decodedOnGrid = json_decode((string) ($_POST['on_grid_json'] ?? '[]'), true);
        $decodedHybrid = json_decode((string) ($_POST['hybrid_json'] ?? '[]'), true);
        if (!is_array($decodedOnGrid) || !is_array($decodedHybrid)) {
            $flash = 'Invalid JSON in price table blocks.';
            $tone = 'error';
        } else {
            $payload = [
                'defaults' => [
                    'unit_rate' => (float) ($_POST['unit_rate'] ?? 8),
                    'daily_generation_per_kw' => (float) ($_POST['daily_generation_per_kw'] ?? 5),
                    'interest_rate' => (float) ($_POST['interest_rate'] ?? 6),
                    'loan_tenure_months' => (int) ($_POST['loan_tenure_months'] ?? 120),
                    'roof_area_factor_per_kw' => (float) ($_POST['roof_area_factor_per_kw'] ?? 100),
                    'co2_factor_kg_per_kwh' => (float) ($_POST['co2_factor_kg_per_kwh'] ?? 0.82),
                    'tree_equivalence_kg_per_tree_per_year' => (float) ($_POST['tree_equivalence_kg_per_tree_per_year'] ?? 20),
                ],
                'content' => [
                    'hero_intro' => trim((string) ($_POST['hero_intro'] ?? '')),
                    'what_is_solar_rooftop' => trim((string) ($_POST['what_is_solar_rooftop'] ?? '')),
                    'pm_surya_ghar' => trim((string) ($_POST['pm_surya_ghar'] ?? '')),
                    'on_grid' => trim((string) ($_POST['on_grid'] ?? '')),
                    'hybrid' => trim((string) ($_POST['hybrid'] ?? '')),
                    'who_should_choose' => trim((string) ($_POST['who_should_choose'] ?? '')),
                    'benefits' => trim((string) ($_POST['benefits'] ?? '')),
                    'expectations' => trim((string) ($_POST['expectations'] ?? '')),
                    'faq' => trim((string) ($_POST['faq'] ?? '')),
                ],
                'on_grid_price_table' => array_values($decodedOnGrid),
                'hybrid_price_table' => array_values($decodedHybrid),
            ];

            if (solar_finance_settings_save($payload)) {
                $flash = 'Solar and Finance settings saved.';
                $tone = 'success';
                $settings = solar_finance_settings_load();
            } else {
                $flash = 'Unable to save settings file.';
                $tone = 'error';
            }
        }
    }
}

$defaults = $settings['defaults'] ?? [];
$content = $settings['content'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Solar & Finance Settings</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="admin-records">
<main class="admin-records__shell">
  <header class="admin-records__header">
    <div><h1>Solar and Finance Settings</h1><p class="admin-muted">Manage defaults, explainer blocks, and both price tables (JSON).</p></div>
    <a class="admin-link" href="admin-dashboard.php">Back to dashboard</a>
  </header>
  <?php if ($flash !== ''): ?><div class="admin-alert admin-alert--<?= htmlspecialchars($tone, ENT_QUOTES) ?>"><?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

  <form method="post" class="admin-form admin-form--stacked">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
    <section class="admin-section"><header class="admin-section__header"><h2>Numeric defaults</h2></header>
      <div class="admin-form__grid">
        <label><span>Unit rate</span><input type="number" step="0.01" name="unit_rate" value="<?= htmlspecialchars((string) ($defaults['unit_rate'] ?? 8), ENT_QUOTES) ?>"></label>
        <label><span>Daily generation per kW</span><input type="number" step="0.01" name="daily_generation_per_kw" value="<?= htmlspecialchars((string) ($defaults['daily_generation_per_kw'] ?? 5), ENT_QUOTES) ?>"></label>
        <label><span>Interest rate (%)</span><input type="number" step="0.01" name="interest_rate" value="<?= htmlspecialchars((string) ($defaults['interest_rate'] ?? 6), ENT_QUOTES) ?>"></label>
        <label><span>Loan tenure (months)</span><input type="number" step="1" name="loan_tenure_months" value="<?= htmlspecialchars((string) ($defaults['loan_tenure_months'] ?? 120), ENT_QUOTES) ?>"></label>
        <label><span>Roof area factor per kW</span><input type="number" step="0.01" name="roof_area_factor_per_kw" value="<?= htmlspecialchars((string) ($defaults['roof_area_factor_per_kw'] ?? 100), ENT_QUOTES) ?>"></label>
        <label><span>CO₂ factor (kg/kWh)</span><input type="number" step="0.01" name="co2_factor_kg_per_kwh" value="<?= htmlspecialchars((string) ($defaults['co2_factor_kg_per_kwh'] ?? 0.82), ENT_QUOTES) ?>"></label>
        <label><span>Tree factor (kg/tree/year)</span><input type="number" step="0.01" name="tree_equivalence_kg_per_tree_per_year" value="<?= htmlspecialchars((string) ($defaults['tree_equivalence_kg_per_tree_per_year'] ?? 20), ENT_QUOTES) ?>"></label>
      </div>
    </section>

    <section class="admin-section"><header class="admin-section__header"><h2>Page content blocks</h2></header>
      <div class="admin-form__grid">
        <?php foreach (['hero_intro','what_is_solar_rooftop','pm_surya_ghar','on_grid','hybrid','who_should_choose','benefits','expectations','faq'] as $key): ?>
          <label style="grid-column:1 / -1"><span><?= htmlspecialchars($key, ENT_QUOTES) ?></span><textarea name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" rows="4"><?= htmlspecialchars((string) ($content[$key] ?? ''), ENT_QUOTES) ?></textarea></label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-section"><header class="admin-section__header"><h2>On-grid price table (JSON)</h2></header>
      <textarea name="on_grid_json" rows="14" style="width:100%;"><?= htmlspecialchars((string) json_encode(array_values((array) ($settings['on_grid_price_table'] ?? [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></textarea>
    </section>

    <section class="admin-section"><header class="admin-section__header"><h2>Hybrid price table (JSON)</h2></header>
      <textarea name="hybrid_json" rows="20" style="width:100%;"><?= htmlspecialchars((string) json_encode(array_values((array) ($settings['hybrid_price_table'] ?? [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></textarea>
    </section>

    <div class="admin-actions"><button type="submit" class="btn btn-primary">Save Solar & Finance Settings</button></div>
  </form>
</main>
</body>
</html>
