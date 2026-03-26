<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$settings = solar_finance_settings_load();
$payload = htmlspecialchars(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance | Dakshayani Enterprises</title>
  <link rel="icon" href="/images/favicon.ico" />
  <link rel="stylesheet" href="/style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
  <style>
    .sf-page{width:100%;max-width:none;padding:1.5rem 1rem 4rem}
    @media(min-width:992px){.sf-page{padding:2rem 2.5rem 5rem}}
    .sf-hero,.sf-card{background:#fff;border:1px solid #e2e8f0;border-radius:1rem;box-shadow:0 10px 20px rgba(15,23,42,.06)}
    .sf-hero{padding:1.5rem 1.25rem;margin-bottom:1.5rem}
    .sf-grid{display:grid;gap:1rem}
    .sf-grid.cols-4{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .sf-grid.cols-2{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    .sf-card{padding:1rem}
    .sf-input{width:100%;padding:.7rem;border:1px solid #cbd5e1;border-radius:.6rem}
    .sf-section-title{font-size:1.35rem;margin:0 0 .75rem}
    .sf-muted{color:#64748b;font-size:.92rem}
    .sf-results{display:none}
    .sf-results.show{display:block}
    .sf-advanced{display:none}
    .sf-advanced.show{display:block}
    .sf-summary-kpi{font-size:1.5rem;font-weight:800;color:#0f172a}
    .sf-cta{display:inline-flex;gap:.6rem;align-items:center;padding:.9rem 1.2rem;background:#16a34a;color:#fff;border-radius:.7rem;font-weight:700}
    .sf-note{background:#fffbeb;border:1px solid #facc15;padding:.6rem .75rem;border-radius:.5rem}
    .sf-table{width:100%;border-collapse:collapse}.sf-table td{padding:.45rem .2rem;border-bottom:1px solid #eef2f7;font-size:.92rem}
  </style>
</head>
<body>
  <header class="site-header"></header>
  <main class="sf-page">
    <section class="sf-hero">
      <p class="hero-eyebrow"><i class="fa-solid fa-solar-panel"></i> Solar and Finance</p>
      <h1 style="font-size:clamp(1.8rem,4vw,2.8rem);">Understand solar, compare finance options, and plan your next step</h1>
      <p class="sf-muted">Beginner-first calculator with optional pro controls. Start with your bill or units, then see your expected savings and monthly outflow across 4 scenarios.</p>
    </section>

    <section class="sf-card" style="margin-bottom:1.25rem;">
      <h2 class="sf-section-title">Quick Solar Education</h2>
      <div class="sf-grid cols-4" id="sf-explainer-grid"></div>
    </section>

    <section class="sf-grid cols-2" style="align-items:start;">
      <div class="sf-card">
        <h2 class="sf-section-title">Mandatory Inputs</h2>
        <label>Average monthly electricity bill (₹)<input class="sf-input" id="monthlyBill" type="number" min="0" step="1" /></label><br/>
        <label>Average monthly units consumed<input class="sf-input" id="monthlyUnits" type="number" min="0" step="0.01" /></label><br/>
        <label>System type
          <select class="sf-input" id="systemType"><option value="on-grid">On-grid</option><option value="hybrid">Hybrid</option></select>
        </label>
        <p class="sf-muted">Enter bill or units (one is enough). The other value auto-fills.</p>

        <button id="toggleAdvanced" class="btn btn-secondary" type="button" style="margin-top:.75rem;">Advanced / Pro Inputs</button>
        <div id="advancedBox" class="sf-advanced">
          <hr/>
          <div class="sf-grid cols-2">
            <label>Solar size (kWp)<input id="solarSize" class="sf-input" type="number" min="0" step="0.1"/></label>
            <label>Daily generation per kW<input id="dailyGeneration" class="sf-input" type="number" min="0" step="0.1"/></label>
            <label>Unit rate (₹/kWh)<input id="unitRate" class="sf-input" type="number" min="0" step="0.1"/></label>
            <label>Subsidy (₹)<input id="subsidy" class="sf-input" type="number" min="0" step="1" value="0"/></label>
            <label>Interest rate (%)<input id="interestRate" class="sf-input" type="number" min="0" step="0.1"/></label>
            <label>Loan tenure (years)<input id="tenureYears" class="sf-input" type="number" min="1" step="1"/></label>
            <label>Loan amount (₹)<input id="loanAmount" class="sf-input" type="number" min="0" step="1"/></label>
            <label>Margin money (₹)<input id="marginMoney" class="sf-input" type="number" min="0" step="1"/></label>
          </div>
          <div id="hybridSelectors" class="sf-grid cols-2" style="margin-top:.75rem;display:none;">
            <label>Inverter kVA<select id="hybridKva" class="sf-input"></select></label>
            <label>Phase<select id="hybridPhase" class="sf-input"></select></label>
            <label>Battery count<select id="hybridBattery" class="sf-input"></select></label>
          </div>
        </div>
        <button id="runCalc" class="btn btn-primary" type="button" style="width:100%;margin-top:1rem;">How solar adoption would look like</button>
        <p id="calcMessage" class="sf-note" style="display:none;margin-top:.75rem;"></p>
      </div>

      <div class="sf-card">
        <h2 class="sf-section-title">Recommendation Summary</h2>
        <div class="sf-grid cols-2">
          <div><div class="sf-muted">Recommended size</div><div class="sf-summary-kpi" id="sumSize">-</div></div>
          <div><div class="sf-muted">Roof area needed</div><div class="sf-summary-kpi" id="sumRoof">-</div></div>
          <div><div class="sf-muted">Monthly generation</div><div class="sf-summary-kpi" id="sumMonthGen">-</div></div>
          <div><div class="sf-muted">Bill offset</div><div class="sf-summary-kpi" id="sumOffset">-</div></div>
        </div>
        <p class="sf-muted" id="pricingBasisNote"></p>
      </div>
    </section>

    <section id="results" class="sf-results" style="margin-top:1.25rem;">
      <div class="sf-grid cols-2">
        <article class="sf-card"><h3>Monthly Outflow Comparison</h3><canvas id="monthlyChart" height="170"></canvas></article>
        <article class="sf-card"><h3>Cumulative Expense Over 25 Years</h3><canvas id="cumulativeChart" height="170"></canvas></article>
      </div>
      <div class="sf-grid cols-2" style="margin-top:1rem;">
        <article class="sf-card"><h3>Financial Clarity</h3><div id="financialClarity"></div></article>
        <article class="sf-card"><h3>Generation & Green Impact</h3><div id="generationImpact"></div></article>
      </div>
      <div class="sf-card" style="margin-top:1rem;">
        <h3>Payback meters</h3>
        <div id="paybackMeters" class="sf-grid cols-4"></div>
      </div>
      <div class="sf-card" style="margin-top:1rem;">
        <h3>Request a Quotation</h3>
        <p class="sf-muted">Share your computed requirement directly on WhatsApp.</p>
        <a id="waQuote" href="#" class="sf-cta" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Request a Quotation</a>
      </div>
    </section>
  </main>
  <footer class="site-footer"></footer>
  <script id="solar-finance-settings" type="application/json"><?= $payload ?></script>
  <script src="/script.js" defer></script>
  <script src="/assets/js/solar-finance.js" defer></script>
</body>
</html>
