<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

function sf_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$settings = solar_finance_settings_load();
$content = $settings['content'] ?? [];
$defaults = $settings['defaults'] ?? [];

$payload = [
    'defaults' => [
        'unit_rate' => (float) ($defaults['unit_rate'] ?? 8),
        'daily_generation_per_kw' => (float) ($defaults['daily_generation_per_kw'] ?? 5),
        'interest_rate' => (float) ($defaults['interest_rate'] ?? 6),
        'loan_tenure_months' => (int) ($defaults['loan_tenure_months'] ?? 120),
        'roof_area_factor_per_kw' => (float) ($defaults['roof_area_factor_per_kw'] ?? 100),
        'co2_factor_kg_per_kwh' => (float) ($defaults['co2_factor_kg_per_kwh'] ?? 0.82),
        'tree_equivalence_kg_per_tree_per_year' => (float) ($defaults['tree_equivalence_kg_per_tree_per_year'] ?? 20),
    ],
    'on_grid_price_table' => array_values((array) ($settings['on_grid_price_table'] ?? [])),
    'hybrid_price_table' => array_values((array) ($settings['hybrid_price_table'] ?? [])),
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
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    .sf-page { width:100%; max-width:none; padding:1rem clamp(1rem, 3vw, 2.25rem) 4rem; }
    .sf-hero { display:grid; grid-template-columns:1.2fr 1fr; gap:1rem; background:linear-gradient(135deg,#0f172a,#1d4ed8); color:#fff; border-radius:18px; padding:1.25rem; }
    .sf-hero h1 { color:#fff; font-size:clamp(1.8rem,3vw,2.5rem); }
    .sf-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:1rem; margin-top:1rem; }
    .sf-card { background:#fff; border:1px solid #dbeafe; border-radius:14px; padding:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06); }
    .sf-card h2,.sf-card h3 { margin:0 0 .55rem; font-size:1.1rem; }
    .sf-col-4{ grid-column:span 4; } .sf-col-6{grid-column:span 6;} .sf-col-8{grid-column:span 8;} .sf-col-12{grid-column:span 12;}
    .sf-input-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.8rem; }
    .sf-input-grid label { display:block; font-size:.88rem; font-weight:600; color:#334155; margin-bottom:.25rem; }
    .sf-input-grid input,.sf-input-grid select,.sf-input-grid textarea { width:100%; padding:.55rem .6rem; border:1px solid #cbd5e1; border-radius:10px; font-size:.94rem; }
    .sf-kpi { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.7rem; }
    .sf-kpi-item { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:.7rem; }
    .sf-kpi-item p { margin:0; font-size:.82rem; color:#64748b; }
    .sf-kpi-item strong { font-size:1.1rem; color:#0f172a; }
    .sf-actions { display:flex; flex-wrap:wrap; gap:.7rem; margin-top:.7rem; }
    .sf-btn { border:none; border-radius:999px; padding:.75rem 1rem; font-weight:700; cursor:pointer; }
    .sf-btn-primary { background:#1d4ed8; color:#fff; }
    .sf-btn-accent { background:#16a34a; color:#fff; }
    .sf-note { font-size:.82rem; color:#64748b; }
    .sf-comp { width:100%; border-collapse:collapse; }
    .sf-comp th,.sf-comp td { border:1px solid #e2e8f0; padding:.5rem; text-align:left; font-size:.86rem; }
    .sf-accordion details { border:1px solid #e2e8f0; border-radius:10px; padding:.5rem .7rem; margin-bottom:.45rem; background:#fff; }
    @media (max-width: 980px){ .sf-col-4,.sf-col-6,.sf-col-8{grid-column:span 12;} .sf-input-grid{grid-template-columns:repeat(2,minmax(0,1fr));} .sf-kpi{grid-template-columns:repeat(2,minmax(0,1fr));} .sf-hero{grid-template-columns:1fr;} }
    @media (max-width: 620px){ .sf-input-grid{grid-template-columns:1fr;} .sf-kpi{grid-template-columns:1fr;} }
  </style>
</head>
<body>
<header class="site-header"></header>
<main class="sf-page">
  <section class="sf-hero">
    <div>
      <h1>Solar and Finance</h1>
      <p><?= sf_safe((string) ($content['hero_intro'] ?? '')) ?></p>
      <div class="sf-actions"><a class="btn btn-secondary" href="#sf-calculator">Jump to Calculator</a></div>
    </div>
    <div class="sf-card" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.35);color:#fff;">
      <h3 style="color:#fff;">In this page</h3>
      <p>✅ Solar basics in simple language<br/>✅ On-grid vs Hybrid comparison<br/>✅ PM Surya Ghar basics<br/>✅ Interactive finance calculator<br/>✅ One-click quotation request</p>
    </div>
  </section>

  <section class="sf-grid">
    <article class="sf-card sf-col-4"><h2><i class="fa-solid fa-solar-panel"></i> What is Solar Rooftop</h2><p><?= sf_safe((string) ($content['what_is_solar_rooftop'] ?? '')) ?></p></article>
    <article class="sf-card sf-col-4"><h2><i class="fa-solid fa-building-columns"></i> PM Surya Ghar</h2><p><?= sf_safe((string) ($content['pm_surya_ghar'] ?? '')) ?></p></article>
    <article class="sf-card sf-col-4"><h2><i class="fa-solid fa-user-check"></i> Which one suits whom</h2><p><?= sf_safe((string) ($content['who_should_choose'] ?? '')) ?></p></article>
    <article class="sf-card sf-col-6"><h2>On-grid</h2><p><?= sf_safe((string) ($content['on_grid'] ?? '')) ?></p></article>
    <article class="sf-card sf-col-6"><h2>Hybrid</h2><p><?= sf_safe((string) ($content['hybrid'] ?? '')) ?></p></article>
    <article class="sf-card sf-col-6">
      <h2>On-grid vs Hybrid (quick comparison)</h2>
      <table class="sf-comp"><thead><tr><th>Point</th><th>On-grid</th><th>Hybrid</th></tr></thead><tbody>
        <tr><td>Upfront Cost</td><td>Lower</td><td>Higher</td></tr>
        <tr><td>Backup in Power Cut</td><td>No</td><td>Yes (selected loads)</td></tr>
        <tr><td>Best for</td><td>Fast bill savings</td><td>Backup + savings</td></tr>
      </tbody></table>
    </article>
    <article class="sf-card sf-col-6 sf-accordion">
      <h2>Benefits & expectations</h2>
      <details open><summary>Benefits</summary><p><?= nl2br(sf_safe((string) ($content['benefits'] ?? ''))) ?></p></details>
      <details><summary>Important expectations</summary><p><?= sf_safe((string) ($content['expectations'] ?? '')) ?></p></details>
      <details><summary>FAQ</summary><p><?= nl2br(sf_safe((string) ($content['faq'] ?? ''))) ?></p></details>
    </article>
  </section>

  <section id="sf-calculator" class="sf-grid">
    <article class="sf-card sf-col-12">
      <h2>Solar requirement and financial calculator</h2>
      <div class="sf-input-grid">
        <div><label>System Type</label><select id="systemType"><option>On-Grid</option><option>Hybrid</option></select></div>
        <div><label>Solar size (kW)</label><input id="solarSize" type="number" step="0.1" min="0" placeholder="Auto recommended" /></div>
        <div><label>Daily generation per kW (units)</label><input id="dailyGen" type="number" step="0.1" min="0" /></div>
        <div><label>Unit rate (₹/unit)</label><input id="unitRate" type="number" step="0.01" min="0" /></div>
        <div><label>Average monthly bill (₹)</label><input id="monthlyBill" type="number" step="0.01" min="0" /></div>
        <div><label>Average monthly units</label><input id="monthlyUnits" type="number" step="0.01" min="0" /></div>

        <div><label>Property type</label><select id="propertyType"><option>Home</option><option>Apartment / Society</option><option>Shop / Office</option><option>School / Hospital / Institution</option><option>Other</option></select></div>
        <div><label>Roof type</label><select id="roofType"><option>RCC</option><option>Sheet roof</option><option>Ground mount</option><option>Not sure</option></select></div>
        <div><label>Shadow-free roof area (sq ft)</label><input id="roofArea" type="number" step="1" min="0" /></div>
        <div><label>Daytime usage level</label><select id="dayUse"><option>Low</option><option>Medium</option><option>High</option></select></div>
        <div><label>Need backup during cuts?</label><select id="backupNeed"><option>No</option><option>Yes</option></select></div>
        <div><label>Location / City</label><input id="city" type="text" placeholder="Ranchi" /></div>
        <div><label>Single / Three phase (optional)</label><select id="phase"><option value="">Not specified</option><option>Single phase</option><option>Three phase</option></select></div>

        <div><label>Solar system cost (₹)</label><input id="manualCost" type="number" step="1" min="0" placeholder="Optional override" /></div>
        <div><label>Subsidy (₹)</label><input id="subsidy" type="number" step="1" min="0" value="0" /></div>
        <div><label>Loan amount (₹)</label><input id="loanAmount" type="number" step="1" min="0" value="0" /></div>
        <div><label>Margin money (₹)</label><input id="marginMoney" type="number" step="1" min="0" value="0" /></div>
        <div><label>Loan interest rate (%)</label><input id="interest" type="number" step="0.01" min="0" /></div>
        <div><label>Loan tenure (months)</label><input id="tenure" type="number" step="1" min="1" /></div>
      </div>
      <p class="sf-note" id="estimateNote"></p>
      <div class="sf-actions">
        <button id="calculateBtn" class="sf-btn sf-btn-primary">How solar adoption would look like</button>
      </div>
    </article>
  </section>

  <section id="results" class="sf-grid" hidden>
    <article class="sf-card sf-col-12">
      <h2>Key insights</h2>
      <div class="sf-kpi" id="kpis"></div>
      <p class="sf-note">Hybrid default selection rule used: for selected kW, first matching hybrid table row is used for default estimates.</p>
    </article>
    <article class="sf-card sf-col-6"><h3>Scenario A: Loan up to ₹2 lakh</h3><div id="scenarioA"></div></article>
    <article class="sf-card sf-col-6"><h3>Scenario B: Loan above ₹2 lakh</h3><div id="scenarioB"></div></article>
    <article class="sf-card sf-col-12"><h3>Suitability summary</h3><div id="suitability"></div></article>
    <article class="sf-card sf-col-12"><h3>Assumptions / disclaimer</h3><p>This calculator gives indicative estimates. Actual results depend on tariff changes, rooftop shadow, final engineering, DISCOM/net-meter approvals, billing structure, and usage behavior.</p></article>
    <article class="sf-card sf-col-12"><button id="quoteBtn" class="sf-btn sf-btn-accent">Request a Quotation</button></article>
  </section>
</main>
<footer class="site-footer"></footer>

<script id="sf-settings" type="application/json"><?= sf_safe((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
<script>
(() => {
  const settings = JSON.parse(document.getElementById('sf-settings').textContent || '{}');
  const defaults = settings.defaults || {};
  const onGridPrices = Array.isArray(settings.on_grid_price_table) ? settings.on_grid_price_table : [];
  const hybridPrices = Array.isArray(settings.hybrid_price_table) ? settings.hybrid_price_table : [];

  const el = (id) => document.getElementById(id);
  const fields = {
    systemType: el('systemType'), solarSize: el('solarSize'), dailyGen: el('dailyGen'), unitRate: el('unitRate'),
    monthlyBill: el('monthlyBill'), monthlyUnits: el('monthlyUnits'), roofArea: el('roofArea'), backupNeed: el('backupNeed'),
    propertyType: el('propertyType'), roofType: el('roofType'), dayUse: el('dayUse'), city: el('city'), phase: el('phase'),
    manualCost: el('manualCost'), subsidy: el('subsidy'), loanAmount: el('loanAmount'), marginMoney: el('marginMoney'),
    interest: el('interest'), tenure: el('tenure'), estimateNote: el('estimateNote')
  };

  fields.dailyGen.value = defaults.daily_generation_per_kw || 5;
  fields.unitRate.value = defaults.unit_rate || 8;
  fields.interest.value = defaults.interest_rate || 6;
  fields.tenure.value = defaults.loan_tenure_months || 120;

  let syncing = false;
  const syncBillUnits = (source) => {
    if (syncing) return;
    syncing = true;
    const rate = Math.max(parseFloat(fields.unitRate.value) || 0, 0);
    if (source === 'bill') {
      const bill = Math.max(parseFloat(fields.monthlyBill.value) || 0, 0);
      if (rate > 0) fields.monthlyUnits.value = (bill / rate).toFixed(2);
    }
    if (source === 'units') {
      const units = Math.max(parseFloat(fields.monthlyUnits.value) || 0, 0);
      fields.monthlyBill.value = (units * rate).toFixed(2);
    }
    syncing = false;
  };

  fields.monthlyBill.addEventListener('input', () => syncBillUnits('bill'));
  fields.monthlyUnits.addEventListener('input', () => syncBillUnits('units'));

  const inr = (num) => Number(num || 0).toLocaleString('en-IN', {maximumFractionDigits: 0});
  const num = (v) => Math.max(parseFloat(v) || 0, 0);

  const pickDefaultPrice = (systemType, kw) => {
    const source = (systemType === 'Hybrid') ? hybridPrices : onGridPrices;
    return source.find((row) => Number(row.solar_rating_kw || 0) === Number(kw)) || null;
  };

  const emi = (principal, annualRate, tenureMonths) => {
    if (principal <= 0 || tenureMonths <= 0) return 0;
    const r = (annualRate / 12) / 100;
    if (r <= 0) return principal / tenureMonths;
    const f = Math.pow(1 + r, tenureMonths);
    return (principal * r * f) / (f - 1);
  };

  function computeScenario(systemCost) {
    const solarSize = num(fields.solarSize.value);
    const dailyGen = num(fields.dailyGen.value);
    const unitRate = num(fields.unitRate.value);
    const monthlyUnits = num(fields.monthlyUnits.value);
    let monthlyBill = num(fields.monthlyBill.value);

    const monthlySolarGeneration = solarSize * dailyGen * 30;
    const annualGeneration = monthlySolarGeneration * 12;
    const generation25 = annualGeneration * 25;
    const monthlySolarValue = monthlySolarGeneration * unitRate;

    let billIsEstimated = false;
    if (!monthlyBill) {
      monthlyBill = monthlySolarValue;
      billIsEstimated = true;
    }

    const subsidy = num(fields.subsidy.value);
    const loanAmount = num(fields.loanAmount.value);
    const marginMoney = num(fields.marginMoney.value);
    const interest = num(fields.interest.value);
    const tenure = Math.max(parseInt(fields.tenure.value || '0', 10), 1);

    const effectivePrincipal = Math.max(loanAmount - subsidy, 0);
    const monthlyEmi = emi(effectivePrincipal, interest, tenure);
    const residualBill = Math.max(monthlyBill - monthlySolarValue, 0);

    const monthlyOutflowLoan = monthlyEmi + residualBill;
    const monthlyOutflowSelf = residualBill;

    const noSolar25 = monthlyBill * 12 * 25;
    const withSolarLoan25 = (monthlyOutflowLoan * tenure) + (residualBill * Math.max(25 * 12 - tenure, 0)) + marginMoney;
    const withSolarSelf25 = systemCost + (residualBill * 12 * 25);

    const annualSavings = Math.max((monthlyBill - residualBill) * 12, 0);
    const selfFundPayback = annualSavings > 0 ? systemCost / annualSavings : 0;
    const loanContributionPayback = annualSavings > 0 ? marginMoney / annualSavings : 0;

    const billOffsetPercent = monthlyBill > 0 ? Math.min((monthlySolarValue / monthlyBill) * 100, 100) : 0;
    const roofNeeded = solarSize * num(defaults.roof_area_factor_per_kw || 100);
    const roofAvailable = num(fields.roofArea.value);
    const roofStatus = !roofAvailable ? 'Not entered' : (roofAvailable >= roofNeeded ? 'Sufficient' : (roofAvailable >= roofNeeded * 0.85 ? 'May be tight' : 'Insufficient'));

    const co2Annual = annualGeneration * num(defaults.co2_factor_kg_per_kwh || 0.82);
    const co2TwentyFive = co2Annual * 25;
    const treesEquivalent = (num(defaults.tree_equivalence_kg_per_tree_per_year || 20) > 0) ? (co2Annual / num(defaults.tree_equivalence_kg_per_tree_per_year || 20)) : 0;

    return { monthlySolarGeneration, annualGeneration, generation25, monthlySolarValue, monthlyBill, billIsEstimated, subsidy, loanAmount, effectivePrincipal,
      monthlyEmi, residualBill, monthlyOutflowLoan, monthlyOutflowSelf, noSolar25, withSolarLoan25, withSolarSelf25, annualSavings, selfFundPayback,
      loanContributionPayback, billOffsetPercent, roofNeeded, roofStatus, co2Annual, co2TwentyFive, treesEquivalent };
  }

  function renderScenario(targetId, title, scenarioCost, calculated) {
    const box = document.getElementById(targetId);
    box.innerHTML = `
      <p><strong>System cost:</strong> ₹${inr(scenarioCost)}</p>
      <p><strong>Payable:</strong> ₹${inr(scenarioCost)}</p>
      <p><strong>Subsidy:</strong> ₹${inr(calculated.subsidy)}</p>
      <p><strong>Loan amount:</strong> ₹${inr(calculated.loanAmount)}</p>
      <p><strong>Loan amount - subsidy:</strong> ₹${inr(calculated.effectivePrincipal)}</p>
      <p><strong>EMI:</strong> ₹${inr(calculated.monthlyEmi)} / month</p>
      <p><strong>Residual bill:</strong> ₹${inr(calculated.residualBill)} / month</p>
      <p><strong>Total monthly outflow (loan):</strong> ₹${inr(calculated.monthlyOutflowLoan)}</p>
      <p><strong>Total monthly outflow (self-funded):</strong> ₹${inr(calculated.monthlyOutflowSelf)}</p>
      <p><strong>Cumulative cost (25Y) no solar:</strong> ₹${inr(calculated.noSolar25)}</p>
      <p><strong>Cumulative cost (25Y) with solar loan:</strong> ₹${inr(calculated.withSolarLoan25)}</p>
      <p><strong>Cumulative cost (25Y) with solar self-funded:</strong> ₹${inr(calculated.withSolarSelf25)}</p>
      <p><strong>Self-funded payback:</strong> ${calculated.selfFundPayback ? calculated.selfFundPayback.toFixed(1) : '—'} years</p>
      <p><strong>Loan/upfront recovery payback:</strong> ${calculated.loanContributionPayback ? calculated.loanContributionPayback.toFixed(1) : '—'} years</p>
    `;
  }

  document.getElementById('calculateBtn').addEventListener('click', () => {
    const dailyGen = num(fields.dailyGen.value);
    const monthlyUnits = num(fields.monthlyUnits.value);
    const recSize = (dailyGen > 0 && monthlyUnits > 0) ? (monthlyUnits / (dailyGen * 30)) : 0;
    if (!num(fields.solarSize.value) && recSize > 0) fields.solarSize.value = recSize.toFixed(2);

    const solarSizeRounded = Math.max(Math.round(num(fields.solarSize.value)), 1);
    const defaultPriceRow = pickDefaultPrice(fields.systemType.value, solarSizeRounded);
    const scenarioAPrice = Number(defaultPriceRow?.price_loan_upto_2_lakh || 0);
    const scenarioBPrice = Number(defaultPriceRow?.price_loan_above_2_lakh || 0);
    const manualCost = num(fields.manualCost.value);
    const useCostA = manualCost > 0 ? manualCost : scenarioAPrice;
    const useCostB = manualCost > 0 ? manualCost : scenarioBPrice;

    const calcA = computeScenario(useCostA);
    const calcB = computeScenario(useCostB);

    fields.estimateNote.textContent = calcA.billIsEstimated ? 'Monthly bill not entered. Estimated bill used from solar generation value.' : '';

    const kpis = document.getElementById('kpis');
    kpis.innerHTML = `
      <div class="sf-kpi-item"><p>Recommended solar size</p><strong>${recSize ? recSize.toFixed(2) : num(fields.solarSize.value).toFixed(2)} kW</strong></div>
      <div class="sf-kpi-item"><p>Approx roof area needed</p><strong>${calcA.roofNeeded.toFixed(0)} sq ft</strong><p>${calcA.roofStatus}</p></div>
      <div class="sf-kpi-item"><p>Bill offset</p><strong>${calcA.billOffsetPercent.toFixed(1)}%</strong></div>
      <div class="sf-kpi-item"><p>Annual savings</p><strong>₹${inr(calcA.annualSavings)}</strong></div>
      <div class="sf-kpi-item"><p>Monthly generation</p><strong>${calcA.monthlySolarGeneration.toFixed(0)} units</strong></div>
      <div class="sf-kpi-item"><p>Annual generation</p><strong>${calcA.annualGeneration.toFixed(0)} units</strong></div>
      <div class="sf-kpi-item"><p>25-year generation</p><strong>${calcA.generation25.toFixed(0)} units</strong></div>
      <div class="sf-kpi-item"><p>₹ saved in 25 years (today's rate)</p><strong>₹${inr(calcA.monthlySolarValue * 12 * 25)}</strong></div>
      <div class="sf-kpi-item"><p>CO₂ avoided annually</p><strong>${calcA.co2Annual.toFixed(0)} kg</strong></div>
      <div class="sf-kpi-item"><p>CO₂ avoided in 25 years</p><strong>${calcA.co2TwentyFive.toFixed(0)} kg</strong></div>
      <div class="sf-kpi-item"><p>Equivalent trees</p><strong>${calcA.treesEquivalent.toFixed(0)} trees/year</strong></div>
      <div class="sf-kpi-item"><p>Default price row used</p><strong>${defaultPriceRow?.model ? defaultPriceRow.model : 'On-grid row'}</strong></div>
    `;

    renderScenario('scenarioA', 'Scenario A', useCostA, calcA);
    renderScenario('scenarioB', 'Scenario B', useCostB, calcB);

    document.getElementById('suitability').innerHTML = `
      <p><strong>Best for lowest upfront:</strong> ${fields.systemType.value === 'On-Grid' ? 'On-grid' : 'On-grid (typically), but compare with your need for backup.'}</p>
      <p><strong>Best for lower monthly outflow:</strong> ${calcA.monthlyOutflowLoan <= calcB.monthlyOutflowLoan ? 'Scenario A' : 'Scenario B'} (based on current inputs).</p>
      <p><strong>Best for maximum lifetime saving:</strong> ${calcA.withSolarSelf25 <= calcB.withSolarSelf25 ? 'Scenario A self-funded' : 'Scenario B self-funded'}.</p>
      <p><strong>Financial clarity:</strong> Interest ${num(fields.interest.value)}%, tenure ${num(fields.tenure.value)} months, margin money ₹${inr(num(fields.marginMoney.value))}.</p>
      <p><strong>Manual system cost override:</strong> ${manualCost > 0 ? 'Enabled (default table shown only for reference).' : 'Not enabled (using default table prices).'}</p>
    `;

    document.getElementById('results').hidden = false;
    document.getElementById('results').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.getElementById('quoteBtn').addEventListener('click', () => {
    const details = [
      '*Quotation Request - Solar and Finance*',
      `System Type: ${fields.systemType.value}`,
      `Solar Size (kW): ${fields.solarSize.value || 'NA'}`,
      `Monthly Bill (₹): ${fields.monthlyBill.value || 'NA'}`,
      `Monthly Units: ${fields.monthlyUnits.value || 'NA'}`,
      `Unit Rate (₹/unit): ${fields.unitRate.value || 'NA'}`,
      `Manual Cost (₹): ${fields.manualCost.value || 'Default pricing'}`,
      `Subsidy (₹): ${fields.subsidy.value || 0}`,
      `Loan Amount (₹): ${fields.loanAmount.value || 0}`,
      `Margin Money (₹): ${fields.marginMoney.value || 0}`,
      `Interest (%): ${fields.interest.value || 'NA'}`,
      `Tenure (months): ${fields.tenure.value || 'NA'}`,
      `Property Type: ${fields.propertyType.value}`,
      `Roof Type: ${fields.roofType.value}`,
      `Roof Area (sq ft): ${fields.roofArea.value || 'NA'}`,
      `Need Backup: ${fields.backupNeed.value}`,
      `Daytime Usage: ${fields.dayUse.value}`,
      `Phase: ${fields.phase.value || 'Not specified'}`,
      `City/Location: ${fields.city.value || 'NA'}`,
    ].join('\n');

    window.open(`https://wa.me/917070278178?text=${encodeURIComponent(details)}`, '_blank', 'noopener');
  });
})();
</script>
<script src="script.js" defer></script>
<script src="site-content.js" defer></script>
</body>
</html>
