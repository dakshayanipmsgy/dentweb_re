<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$settings = solar_finance_settings();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed.';
    } else {
        try {
            $settings['defaults']['unit_rate'] = (float) ($_POST['unit_rate'] ?? 8);
            $settings['defaults']['daily_generation_per_kw'] = (float) ($_POST['daily_generation_per_kw'] ?? 5);
            $settings['defaults']['interest_rate'] = (float) ($_POST['interest_rate'] ?? 6);
            $settings['defaults']['loan_tenure_months'] = (int) ($_POST['loan_tenure_months'] ?? 120);
            $settings['defaults']['roof_area_factor_per_kw'] = (float) ($_POST['roof_area_factor_per_kw'] ?? 100);
            $settings['defaults']['co2_factor_kg_per_kwh'] = (float) ($_POST['co2_factor_kg_per_kwh'] ?? 0.82);
            $settings['defaults']['tree_equivalence_factor_kg_per_tree_per_year'] = (float) ($_POST['tree_equivalence_factor_kg_per_tree_per_year'] ?? 20);

            foreach (['hero_title','hero_subtitle','what_is_solar_rooftop','pm_surya_ghar_text','on_grid_text','hybrid_text','which_one_is_suitable_for_whom','benefits','important_expectations','disclaimer'] as $field) {
                $settings['content'][$field] = trim((string) ($_POST[$field] ?? ''));
            }

            $onGridRows = json_decode((string) ($_POST['on_grid_price_table_json'] ?? '[]'), true);
            $hybridRows = json_decode((string) ($_POST['hybrid_price_table_json'] ?? '[]'), true);
            if (is_array($onGridRows) && is_array($hybridRows)) {
                $settings['on_grid_price_table'] = array_values($onGridRows);
                $settings['hybrid_price_table'] = array_values($hybridRows);
            } else {
                throw new RuntimeException('Invalid JSON in price table fields.');
            }

            solar_finance_settings_save($settings);
            $settings = solar_finance_settings();
            $message = 'Solar and Finance settings saved.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · Solar and Finance Settings</title>
<style>body{font-family:Arial,sans-serif;background:#f8fafc;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}label{display:block;font-size:12px;font-weight:700;margin-bottom:4px}input,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:130px}.btn{background:#0f766e;color:#fff;border:0;border-radius:8px;padding:9px 14px}</style>
</head>
<body>
<main class="wrap">
  <div class="card"><h1>Solar and Finance Settings</h1><p>File-based defaults and content for <code>/solar-and-finance.php</code>.</p></div>
  <?php if ($message !== ''): ?><div class="card" style="border-color:#22c55e"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="card" style="border-color:#ef4444"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
    <h3>Numeric Defaults</h3>
    <div class="grid">
      <?php foreach (['unit_rate','daily_generation_per_kw','interest_rate','loan_tenure_months','roof_area_factor_per_kw','co2_factor_kg_per_kwh','tree_equivalence_factor_kg_per_tree_per_year'] as $k): ?>
        <div><label><?= htmlspecialchars($k, ENT_QUOTES) ?></label><input type="number" step="0.01" name="<?= htmlspecialchars($k, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($settings['defaults'][$k] ?? ''), ENT_QUOTES) ?>"></div>
      <?php endforeach; ?>
    </div>
    <h3>Content Blocks</h3>
    <div class="grid">
      <?php foreach (['hero_title','hero_subtitle','what_is_solar_rooftop','pm_surya_ghar_text','on_grid_text','hybrid_text','which_one_is_suitable_for_whom','benefits','important_expectations','disclaimer'] as $k): ?>
        <div style="grid-column:1/-1"><label><?= htmlspecialchars($k, ENT_QUOTES) ?></label><textarea name="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($settings['content'][$k] ?? ''), ENT_QUOTES) ?></textarea></div>
      <?php endforeach; ?>
    </div>
    <h3>Price Tables (JSON)</h3>
    <div><label>On-grid price table JSON</label><textarea name="on_grid_price_table_json"><?= htmlspecialchars((string) json_encode($settings['on_grid_price_table'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></textarea></div>
    <div><label>Hybrid price table JSON</label><textarea name="hybrid_price_table_json"><?= htmlspecialchars((string) json_encode($settings['hybrid_price_table'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></textarea></div>
    <p><button class="btn" type="submit">Save settings</button></p>
  </form>
</main>
</body>
</html>
