<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
start_session();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$flash = '';
$tone = 'info';
$settings = solar_finance_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        $flash = 'Session expired. Please refresh and try again.';
        $tone = 'error';
    } else {
        $updated = $settings;
        $updated['defaults']['unit_rate'] = (float) ($_POST['unit_rate'] ?? 8);
        $updated['defaults']['daily_generation_per_kw'] = (float) ($_POST['daily_generation_per_kw'] ?? 5);
        $updated['defaults']['interest_rate'] = (float) ($_POST['interest_rate'] ?? 6);
        $updated['defaults']['tenure_years'] = (float) ($_POST['tenure_years'] ?? 10);
        $updated['defaults']['roof_area_sqft_per_kw'] = (float) ($_POST['roof_area_sqft_per_kw'] ?? 100);
        $updated['defaults']['co2_emission_factor'] = (float) ($_POST['co2_emission_factor'] ?? 0.82);
        $updated['defaults']['co2_per_tree'] = (float) ($_POST['co2_per_tree'] ?? 20);
        $updated['defaults']['default_margin_above_2_lakh'] = (float) ($_POST['default_margin_above_2_lakh'] ?? 50000);

        $onGrid = json_decode((string) ($_POST['on_grid_prices_json'] ?? '[]'), true);
        $hybrid = json_decode((string) ($_POST['hybrid_prices_json'] ?? '[]'), true);
        if (!is_array($onGrid) || !is_array($hybrid)) {
            $flash = 'Price table JSON is invalid. Please keep valid JSON arrays.';
            $tone = 'error';
        } else {
            $updated['on_grid_prices'] = $onGrid;
            $updated['hybrid_prices'] = $hybrid;
            solar_finance_settings_save($updated);
            $settings = solar_finance_settings();
            $flash = 'Solar and Finance settings saved.';
            $tone = 'success';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin · Solar and Finance Settings</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    body{background:#f8fafc;padding:1rem;font-family:Inter,system-ui,sans-serif}.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem;margin-bottom:1rem}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem}label{display:grid;gap:.35rem}input,textarea{width:100%;padding:.55rem;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
    textarea{min-height:230px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  </style>
</head>
<body>
  <a href="admin-dashboard.php" class="btn btn-secondary">← Back to dashboard</a>
  <h1>Solar and Finance Settings</h1>
  <?php if ($flash !== ''): ?><p class="<?= $tone === 'error' ? 'error-box':'success-box' ?>"><?= htmlspecialchars($flash, ENT_QUOTES) ?></p><?php endif; ?>
  <form method="post" class="box">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES) ?>" />
    <div class="grid">
      <label>Default unit rate<input type="number" step="0.1" name="unit_rate" value="<?= htmlspecialchars((string) ($settings['defaults']['unit_rate'] ?? 8), ENT_QUOTES) ?>"></label>
      <label>Default daily generation / kW<input type="number" step="0.1" name="daily_generation_per_kw" value="<?= htmlspecialchars((string) ($settings['defaults']['daily_generation_per_kw'] ?? 5), ENT_QUOTES) ?>"></label>
      <label>Default interest rate<input type="number" step="0.1" name="interest_rate" value="<?= htmlspecialchars((string) ($settings['defaults']['interest_rate'] ?? 6), ENT_QUOTES) ?>"></label>
      <label>Default tenure years<input type="number" step="1" name="tenure_years" value="<?= htmlspecialchars((string) ($settings['defaults']['tenure_years'] ?? 10), ENT_QUOTES) ?>"></label>
      <label>Roof area sqft per kW<input type="number" step="0.1" name="roof_area_sqft_per_kw" value="<?= htmlspecialchars((string) ($settings['defaults']['roof_area_sqft_per_kw'] ?? 100), ENT_QUOTES) ?>"></label>
      <label>CO₂ emission factor<input type="number" step="0.01" name="co2_emission_factor" value="<?= htmlspecialchars((string) ($settings['defaults']['co2_emission_factor'] ?? 0.82), ENT_QUOTES) ?>"></label>
      <label>CO₂ per tree factor<input type="number" step="0.1" name="co2_per_tree" value="<?= htmlspecialchars((string) ($settings['defaults']['co2_per_tree'] ?? 20), ENT_QUOTES) ?>"></label>
      <label>Default margin (loan above ₹2L)<input type="number" step="1" name="default_margin_above_2_lakh" value="<?= htmlspecialchars((string) ($settings['defaults']['default_margin_above_2_lakh'] ?? 50000), ENT_QUOTES) ?>"></label>
    </div>

    <h3>On-grid price table JSON</h3>
    <textarea name="on_grid_prices_json"><?= htmlspecialchars((string) json_encode($settings['on_grid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></textarea>

    <h3>Hybrid price table JSON</h3>
    <textarea name="hybrid_prices_json"><?= htmlspecialchars((string) json_encode($settings['hybrid_prices'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></textarea>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</body>
</html>
