<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$settings = solar_finance_settings();
$csrfToken = $_SESSION['csrf_token'] ?? '';
$flash = '';
$tone = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired. Refresh and try again.';
        $tone = 'error';
    } else {
        try {
            $explainers = [];
            $rows = preg_split('/\r\n|\r|\n/', (string) ($_POST['explainers'] ?? '')) ?: [];
            foreach ($rows as $row) {
                $parts = array_map('trim', explode('|', $row));
                if (count($parts) >= 3 && $parts[0] !== '') {
                    $explainers[] = ['title' => $parts[0], 'text' => $parts[1], 'icon' => $parts[2]];
                }
            }

            $faqs = [];
            $faqRows = preg_split('/\r\n|\r|\n/', (string) ($_POST['faqs'] ?? '')) ?: [];
            foreach ($faqRows as $row) {
                $parts = array_map('trim', explode('|', $row));
                if (count($parts) >= 2 && $parts[0] !== '') {
                    $faqs[] = ['q' => $parts[0], 'a' => $parts[1]];
                }
            }

            $updated = [
                'page_title' => trim((string) ($_POST['page_title'] ?? 'Solar and Finance')),
                'hero_intro' => trim((string) ($_POST['hero_intro'] ?? '')),
                'cta_text' => trim((string) ($_POST['cta_text'] ?? 'Request a quotation')),
                'defaults' => [
                    'daily_generation' => (float) ($_POST['daily_generation'] ?? 5),
                    'unit_rate' => (float) ($_POST['unit_rate'] ?? 8),
                    'interest_rate' => (float) ($_POST['interest_rate'] ?? 6),
                    'loan_tenure_months' => (int) ($_POST['loan_tenure_months'] ?? 120),
                    'tariff_escalation' => (float) ($_POST['tariff_escalation'] ?? 3),
                    'solar_degradation' => (float) ($_POST['solar_degradation'] ?? 0.6),
                    'fixed_charge' => (float) ($_POST['fixed_charge'] ?? 0),
                    'annual_om' => (float) ($_POST['annual_om'] ?? 3000),
                    'inverter_reserve' => (float) ($_POST['inverter_reserve'] ?? 1500),
                    'billing_assumption' => trim((string) ($_POST['billing_assumption'] ?? 'net_metering')),
                    'shading_loss' => (float) ($_POST['shading_loss'] ?? 10),
                ],
                'factors' => [
                    'roof_area_sqft_per_kw' => (float) ($_POST['roof_area_sqft_per_kw'] ?? 100),
                    'co2_per_unit_kg' => (float) ($_POST['co2_per_unit_kg'] ?? 0.82),
                    'tree_per_ton_co2' => (float) ($_POST['tree_per_ton_co2'] ?? 45),
                ],
                'explainers' => $explainers,
                'faqs' => $faqs,
            ];
            solar_finance_settings_save($updated);
            $settings = solar_finance_settings();
            $flash = 'Solar and Finance content updated.';
        } catch (Throwable $e) {
            $flash = 'Unable to save: ' . $e->getMessage();
            $tone = 'error';
        }
    }
}

$explainerText = implode("\n", array_map(static fn($e) => ($e['title'] ?? '') . ' | ' . ($e['text'] ?? '') . ' | ' . ($e['icon'] ?? 'fa-circle-info'), $settings['explainers'] ?? []));
$faqText = implode("\n", array_map(static fn($f) => ($f['q'] ?? '') . ' | ' . ($f['a'] ?? ''), $settings['faqs'] ?? []));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance Settings</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="admin-records">
<main class="admin-records__shell">
  <?php if ($flash !== ''): ?><div class="admin-alert admin-alert--<?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <header class="admin-records__header"><h1>Solar and Finance Page Settings</h1><a href="admin-dashboard.php" class="admin-link">Back to overview</a></header>
  <section class="admin-section"><section class="admin-section__body">
    <form method="post" class="admin-form admin-form--stacked">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
      <div class="admin-form__grid">
        <label><span>Page title</span><input type="text" name="page_title" value="<?= htmlspecialchars((string) ($settings['page_title'] ?? '')) ?>" /></label>
        <label><span>CTA text</span><input type="text" name="cta_text" value="<?= htmlspecialchars((string) ($settings['cta_text'] ?? '')) ?>" /></label>
      </div>
      <label><span>Hero intro</span><textarea name="hero_intro" rows="3"><?= htmlspecialchars((string) ($settings['hero_intro'] ?? '')) ?></textarea></label>
      <h3>Default values</h3>
      <div class="admin-form__grid">
        <label><span>Daily generation</span><input type="number" step="0.1" name="daily_generation" value="<?= htmlspecialchars((string) ($settings['defaults']['daily_generation'] ?? 5)) ?>" /></label>
        <label><span>Unit rate</span><input type="number" step="0.1" name="unit_rate" value="<?= htmlspecialchars((string) ($settings['defaults']['unit_rate'] ?? 8)) ?>" /></label>
        <label><span>Interest rate</span><input type="number" step="0.1" name="interest_rate" value="<?= htmlspecialchars((string) ($settings['defaults']['interest_rate'] ?? 6)) ?>" /></label>
        <label><span>Loan tenure months</span><input type="number" name="loan_tenure_months" value="<?= htmlspecialchars((string) ($settings['defaults']['loan_tenure_months'] ?? 120)) ?>" /></label>
      </div>
      <h3>Configurable factors</h3>
      <div class="admin-form__grid">
        <label><span>Roof area sq ft per kW</span><input type="number" step="0.1" name="roof_area_sqft_per_kw" value="<?= htmlspecialchars((string) ($settings['factors']['roof_area_sqft_per_kw'] ?? 100)) ?>" /></label>
        <label><span>CO₂ kg per unit</span><input type="number" step="0.01" name="co2_per_unit_kg" value="<?= htmlspecialchars((string) ($settings['factors']['co2_per_unit_kg'] ?? 0.82)) ?>" /></label>
        <label><span>Tree equivalent per ton CO₂</span><input type="number" step="0.1" name="tree_per_ton_co2" value="<?= htmlspecialchars((string) ($settings['factors']['tree_per_ton_co2'] ?? 45)) ?>" /></label>
      </div>
      <h3>Explainers and FAQ</h3>
      <label><span>Explainers (one line each: title | text | icon)</span><textarea name="explainers" rows="8"><?= htmlspecialchars($explainerText) ?></textarea></label>
      <label><span>FAQ (one line each: question | answer)</span><textarea name="faqs" rows="8"><?= htmlspecialchars($faqText) ?></textarea></label>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </section></section>
</main>
</body>
</html>
