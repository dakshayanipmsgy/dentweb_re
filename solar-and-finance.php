<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

function saf(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$settings = solar_finance_settings();
$contentPath = __DIR__ . '/data/leads/lead_explainer_content.json';
$explainerSource = [];
if (is_file($contentPath)) {
    $decoded = json_decode((string) file_get_contents($contentPath), true);
    if (is_array($decoded)) {
        $explainerSource = $decoded;
    }
}

$faqList = $settings['explainer']['faq'] ?? [];
$pagePayload = [
    'settings' => $settings,
    'explainer_source' => [
        'what_is_solar_rooftop' => (string) ($explainerSource['what_is_solar_rooftop'] ?? 'Rooftop solar means installing solar panels on your roof to generate electricity for your daily use.'),
        'pm_surya_ghar_text' => (string) ($explainerSource['pm_surya_ghar_text'] ?? 'PM Surya Ghar is a government initiative that supports residential rooftop solar adoption, subject to eligibility and policy.'),
        'on_grid_text' => (string) ($explainerSource['on_grid_text'] ?? 'On-grid systems work with the utility grid and usually offer the best upfront economics.'),
        'hybrid_text' => (string) ($explainerSource['hybrid_text'] ?? 'Hybrid systems include battery backup and can support selected loads during outages.'),
        'which_one_is_suitable_for_whom' => (string) ($explainerSource['which_one_is_suitable_for_whom'] ?? 'On-grid is suitable when your priority is savings. Hybrid is suitable when backup comfort is important.'),
        'benefits' => (string) ($explainerSource['benefits'] ?? 'Lower monthly bills, clean energy generation, and long-term control over electricity expenses.'),
        'important_expectations' => (string) ($explainerSource['important_expectations'] ?? 'Generation depends on roof conditions, shading, weather, and maintenance. Estimates are indicative.'),
    ],
    'faq' => $faqList,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance | Dakshayani Enterprises</title>
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/solar-and-finance.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body data-public-page="1">
  <div data-site-header></div>

  <main class="solar-finance-page">
    <section class="sf-hero">
      <h1><?= saf((string) ($settings['explainer']['hero_title'] ?? 'Solar and Finance')) ?></h1>
      <p><?= saf((string) ($settings['explainer']['hero_subtitle'] ?? 'Understand solar and compare finance choices.')) ?></p>
      <div class="sf-hero-pills">
        <span>Understand solar requirement</span>
        <span>Compare loan &amp; self-funded options</span>
        <span>Get quotation on WhatsApp</span>
      </div>
    </section>

    <section class="sf-section">
      <h2>Quick Solar Education</h2>
      <div class="sf-grid sf-grid-4" id="sfEducationGrid"></div>
    </section>

    <section class="sf-section">
      <h2>Solar &amp; Finance Calculator</h2>
      <form id="solarFinanceForm" class="sf-form-grid" autocomplete="off">
        <div class="sf-card">
          <h3>Mandatory inputs</h3>
          <label>Average monthly electricity bill (₹)
            <input type="number" min="0" step="1" id="monthlyBill" />
          </label>
          <label>Average monthly electricity units consumed
            <input type="number" min="0" step="0.1" id="monthlyUnits" />
          </label>
          <label>System type
            <select id="systemType">
              <option value="On-grid">On-grid</option>
              <option value="Hybrid">Hybrid</option>
            </select>
          </label>
          <p class="sf-note">Enter bill or units (at least one). The other will auto-fill.</p>
        </div>

        <div class="sf-card">
          <h3>Auto-filled core fields (editable)</h3>
          <label>Recommended solar size (kWp)<input type="number" id="solarSize" min="0" step="0.1" /></label>
          <label>Daily generation per kW per day<input type="number" id="dailyGeneration" min="0" step="0.1" /></label>
          <label>Unit rate (₹/kWh)<input type="number" id="unitRate" min="0" step="0.1" /></label>
          <label>System cost (₹)<input type="number" id="systemCost" min="0" step="1" /></label>
          <label>Subsidy (₹)<input type="number" id="subsidy" min="0" step="1" value="0" /></label>
          <label>Loan amount (₹)<input type="number" id="loanAmount" min="0" step="1" /></label>
          <label>Margin money (₹)<input type="number" id="marginMoney" min="0" step="1" /></label>
          <label>Interest rate (%)<input type="number" id="interestRate" min="0" step="0.1" /></label>
          <label>Loan tenure (years)<input type="number" id="tenureYears" min="1" step="1" /></label>
        </div>

        <details class="sf-card sf-advanced" id="advancedInputs">
          <summary>Advanced / Pro Inputs</summary>
          <div class="sf-advanced-grid">
            <label>Hybrid inverter kVA
              <select id="hybridInverter"></select>
            </label>
            <label>Hybrid phase
              <select id="hybridPhase"></select>
            </label>
            <label>Hybrid battery count
              <select id="hybridBattery"></select>
            </label>
          </div>
          <p class="sf-note" id="hybridMessage"></p>
        </details>

        <div class="sf-action-row">
          <button type="submit" class="btn btn-primary">How solar adoption would look like</button>
        </div>
      </form>
    </section>

    <section id="resultsSection" class="sf-section" hidden>
      <h2>Your Result Snapshot</h2>
      <div id="recommendationSummary" class="sf-summary"></div>
      <div class="sf-grid sf-grid-2">
        <div class="sf-card"><canvas id="monthlyOutflowChart"></canvas></div>
        <div class="sf-card"><canvas id="cumulativeChart"></canvas></div>
      </div>
      <div class="sf-grid sf-grid-4" id="paybackGrid"></div>
      <div class="sf-grid sf-grid-4" id="clarityGrid"></div>
      <div class="sf-grid sf-grid-3" id="generationGrid"></div>
      <div class="sf-grid sf-grid-3" id="greenGrid"></div>
      <div class="sf-card sf-cta">
        <h3>Request a Quotation</h3>
        <p>Share your selected details instantly on WhatsApp.</p>
        <button type="button" id="waQuoteButton" class="btn btn-secondary">Request a Quotation</button>
      </div>
    </section>
  </main>

  <div data-site-footer></div>
  <script id="solarFinanceData" type="application/json"><?= htmlspecialchars(json_encode($pagePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="script.js"></script>
  <script src="assets/js/solar-and-finance.js"></script>
</body>
</html>
