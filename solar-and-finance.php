<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance.php';

$settings = solar_finance_settings_load();
$explainer = solar_finance_explainer_load();

function sf_safe(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sf_block(array $source, string $key, string $fallback): string {
    $value = trim((string)($source[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance | Dakshayani Enterprises</title>
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body>
<header class="site-header"></header>
<main class="solar-finance-page">
  <section class="sf-hero">
    <h1>Solar and Finance — simple, clear, and practical</h1>
    <p>Understand rooftop solar, estimate what size may suit you, compare finance choices, and request quotation on WhatsApp in one flow.</p>
  </section>

  <section class="sf-section">
    <h2>Quick Solar Education</h2>
    <div class="sf-edu-grid">
      <article class="sf-card"><h3>What is Solar Rooftop?</h3><p><?= sf_safe(sf_block($explainer, 'what_is_solar_rooftop', 'Solar rooftop means generating electricity from panels installed on your roof.')); ?></p></article>
      <article class="sf-card"><h3>PM Surya Ghar: Muft Bijli Yojana</h3><p><?= sf_safe(sf_block($explainer, 'pm_surya_ghar_text', 'A government-backed residential scheme where eligible homes may receive subsidy support.')); ?></p></article>
      <article class="sf-card"><h3>On-grid</h3><p><?= sf_safe(sf_block($explainer, 'on_grid_text', 'Works with the electricity grid and is usually lower in upfront cost.')); ?></p></article>
      <article class="sf-card"><h3>Hybrid</h3><p><?= sf_safe(sf_block($explainer, 'hybrid_text', 'Hybrid combines solar with batteries for backup during outages.')); ?></p></article>
      <article class="sf-card"><h3>Which one suits whom?</h3><p><?= sf_safe(sf_block($explainer, 'which_one_is_suitable_for_whom', 'On-grid suits users focused on savings; hybrid suits users needing backup reliability.')); ?></p></article>
      <article class="sf-card"><h3>Benefits & Expectations</h3><p><?= sf_safe(sf_block($explainer, 'important_expectations', 'Savings depend on roof, usage pattern, system size, and tariff conditions.')); ?></p></article>
    </div>
  </section>

  <section class="sf-section">
    <h2>Calculator Inputs</h2>
    <div class="sf-input-grid">
      <div class="sf-card">
        <h3>Mandatory Inputs</h3>
        <div class="sf-form-grid">
          <label>Average monthly electricity bill (₹)<input type="number" min="0" id="monthlyBill"></label>
          <label>Average monthly units consumed<input type="number" min="0" id="monthlyUnits"></label>
          <label>System type<select id="systemType"><option>On-grid</option><option>Hybrid</option></select></label>
          <label>Unit rate (₹ / kWh)<input type="number" step="0.01" min="0" id="unitRate"></label>
        </div>
      </div>
      <div class="sf-card">
        <h3>Auto-filled core fields (editable)</h3>
        <div class="sf-form-grid">
          <label>Recommended solar size (kWp)<input type="number" step="0.01" min="0" id="solarSize"></label>
          <label>Daily generation per kW/day<input type="number" step="0.01" min="0" id="dailyGen"></label>
          <label>System cost (₹)<input type="number" min="0" id="systemCost"></label>
          <label>Subsidy (₹)<input type="number" min="0" id="subsidy"></label>
          <label>Loan amount (₹)<input type="number" min="0" id="loanAmount"></label>
          <label>Margin money (₹)<input type="number" min="0" id="marginMoney"></label>
          <label>Interest rate (%)<input type="number" step="0.01" min="0" id="interestRate"></label>
          <label>Loan tenure (years)<input type="number" step="1" min="1" id="tenureYears"></label>
        </div>
      </div>
    </div>

    <details class="sf-advanced" id="advancedWrap">
      <summary>Advanced / Pro Inputs</summary>
      <div class="sf-form-grid sf-advanced-grid">
        <label>Inverter kVA (Hybrid)<select id="hybridKva"></select></label>
        <label>Phase (Hybrid)<select id="hybridPhase"></select></label>
        <label>Battery count (Hybrid)<select id="hybridBattery"></select></label>
      </div>
    </details>

    <div class="sf-actions"><button class="btn btn-primary" id="runCalc" type="button">How solar adoption would look like</button></div>
    <p class="sf-note" id="calcNote"></p>
  </section>

  <section class="sf-section" id="results" hidden>
    <h2>Results</h2>
    <div class="sf-summary" id="recommendationSummary"></div>
    <div class="sf-chart-card">
      <h3>Monthly Outflow Comparison</h3>
      <div id="outflowBars" class="sf-bars"></div>
    </div>
    <div class="sf-chart-card">
      <h3>Cumulative Expense Over 25 Years</h3>
      <canvas id="cumChart" width="1100" height="300"></canvas>
    </div>
    <div class="sf-chart-card" id="paybackMeters"></div>
    <div class="sf-results-grid">
      <article class="sf-card" id="financialClarity"></article>
      <article class="sf-card" id="generationEstimate"></article>
      <article class="sf-card" id="greenImpact"></article>
    </div>
    <div class="sf-whatsapp-cta">
      <button class="btn btn-secondary" id="quoteWhatsApp" type="button"><i class="fa-brands fa-whatsapp"></i> Request a Quotation</button>
    </div>
  </section>
</main>
<footer class="site-footer"></footer>
<script>window.solarFinanceSettings = <?= json_encode($settings, JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="script.js"></script>
<script src="assets/js/solar-finance.js"></script>
</body>
</html>
