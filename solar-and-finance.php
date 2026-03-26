<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$settings = solar_finance_settings();
$defaults = $settings['defaults'] ?? [];
$content = $settings['content'] ?? [];

function sf_safe(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <style>
    .sf-page{padding:1.25rem clamp(1rem,2.5vw,2.75rem) 3rem;background:#f8fafc}
    .sf-shell{max-width:none;width:100%}
    .sf-hero{background:linear-gradient(120deg,#0f766e,#0284c7);color:#fff;border-radius:20px;padding:1.2rem 1.2rem 1.6rem}
    .sf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
    .sf-card{background:#fff;border:1px solid #dbe6f2;border-radius:16px;padding:1rem;box-shadow:0 8px 22px rgba(15,23,42,.05)}
    .sf-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem}
    .sf-form-grid label{display:block;font-size:.85rem;font-weight:700;color:#334155;margin-bottom:.25rem}
    .sf-form-grid input,.sf-form-grid select{width:100%;padding:.62rem .7rem;border:1px solid #cbd5e1;border-radius:10px}
    .sf-calc-btn{margin-top:1rem;width:100%;padding:.9rem 1rem;border:0;border-radius:12px;background:#0f766e;color:#fff;font-weight:700;cursor:pointer}
    .sf-kpi{font-size:1.5rem;font-weight:800;color:#0f172a}
    .sf-muted{color:#475569;font-size:.92rem}
    .sf-badge{display:inline-flex;align-items:center;gap:.35rem;background:#e0f2fe;color:#0c4a6e;padding:.25rem .6rem;border-radius:999px;font-size:.78rem;font-weight:700}
    .sf-accordion details{background:#fff;border:1px solid #dbe6f2;border-radius:12px;padding:.7rem .85rem}
    .sf-accordion{display:grid;gap:.6rem}
    #resultsSection[hidden]{display:none}
  </style>
</head>
<body>
<header class="site-header"></header>
<main class="sf-page">
  <div class="sf-shell">
    <section class="sf-hero">
      <h1><?= sf_safe((string)($content['hero_title'] ?? 'Solar and Finance')) ?></h1>
      <p><?= sf_safe((string)($content['hero_subtitle'] ?? '')) ?></p>
      <div class="sf-grid">
        <div class="sf-card"><h3><i class="fa-solid fa-solar-panel"></i> Simple Education</h3><p class="sf-muted">Understand rooftop solar, PM Surya Ghar, on-grid and hybrid basics.</p></div>
        <div class="sf-card"><h3><i class="fa-solid fa-chart-line"></i> Smart Calculator</h3><p class="sf-muted">See recommended size, EMI, payback, and monthly outflow instantly.</p></div>
        <div class="sf-card"><h3><i class="fa-brands fa-whatsapp"></i> Direct Quotation</h3><p class="sf-muted">Share your filled details and request quotation on WhatsApp.</p></div>
      </div>
    </section>

    <section style="margin-top:1rem" class="sf-grid">
      <article class="sf-card"><h3>What is solar rooftop?</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['what_is_solar_rooftop'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>PM Surya Ghar</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['pm_surya_ghar_text'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>On-grid</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['on_grid_text'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>Hybrid</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['hybrid_text'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>Which one suits whom?</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['which_one_is_suitable_for_whom'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>Benefits</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['benefits'] ?? ''))) ?></p></article>
      <article class="sf-card"><h3>Important expectations</h3><p class="sf-muted"><?= nl2br(sf_safe((string)($content['important_expectations'] ?? ''))) ?></p></article>
    </section>

    <section class="sf-card" style="margin-top:1rem">
      <h2>Enter details</h2>
      <div class="sf-form-grid" id="sfForm">
        <div><label>System Type</label><select id="systemType"><option>On-Grid</option><option>Hybrid</option></select></div>
        <div><label>Solar size (kW)</label><input type="number" id="solarSize" min="0" step="0.1"></div>
        <div><label>Daily generation per kW (units)</label><input type="number" id="dailyGen" min="0" step="0.1" value="<?= sf_safe((string)($defaults['daily_generation_per_kw'] ?? 5)) ?>"></div>
        <div><label>Unit rate (₹/unit)</label><input type="number" id="unitRate" min="0" step="0.1" value="<?= sf_safe((string)($defaults['unit_rate'] ?? 8)) ?>"></div>
        <div><label>Average monthly bill (₹)</label><input type="number" id="monthlyBill" min="0" step="1"></div>
        <div><label>Average monthly units</label><input type="number" id="monthlyUnits" min="0" step="1"></div>
        <div><label>Property type</label><select id="propertyType"><option>Home</option><option>Apartment / Society</option><option>Shop / Office</option><option>School / Hospital / Institution</option><option>Other</option></select></div>
        <div><label>Roof type</label><select id="roofType"><option>RCC</option><option>Sheet roof</option><option>Ground mount</option><option>Not sure</option></select></div>
        <div><label>Shadow-free roof area (sq ft)</label><input type="number" id="roofArea" min="0" step="1"></div>
        <div><label>Daytime usage level</label><select id="dayUsage"><option>Low</option><option>Medium</option><option>High</option></select></div>
        <div><label>Need backup during power cuts?</label><select id="needBackup"><option>No</option><option>Yes</option></select></div>
        <div><label>Location / City</label><input type="text" id="locationCity"></div>
        <div><label>Supply phase (optional)</label><select id="supplyPhase"><option value="">Optional</option><option>Single phase</option><option>Three phase</option></select></div>
        <div><label>Solar system cost (₹)</label><input type="number" id="systemCost" min="0" step="1"></div>
        <div><label>Subsidy (₹)</label><input type="number" id="subsidy" min="0" step="1" value="0"></div>
        <div><label>Loan amount (₹)</label><input type="number" id="loanAmount" min="0" step="1" value="0"></div>
        <div><label>Margin money (₹)</label><input type="number" id="marginMoney" min="0" step="1" value="0"></div>
        <div><label>Loan interest rate (%)</label><input type="number" id="interestRate" min="0" step="0.1" value="<?= sf_safe((string)($defaults['interest_rate'] ?? 6)) ?>"></div>
        <div><label>Loan tenure (months)</label><input type="number" id="loanTenure" min="1" step="1" value="<?= sf_safe((string)($defaults['loan_tenure_months'] ?? 120)) ?>"></div>
      </div>
      <button class="sf-calc-btn" id="calcBtn">How solar adoption would look like</button>
    </section>

    <section id="resultsSection" style="margin-top:1rem" hidden>
      <div class="sf-grid" id="topMetrics"></div>
      <div class="sf-grid" id="scenarioCards" style="margin-top:1rem"></div>
      <div class="sf-card" style="margin-top:1rem">
        <h3>Assumptions / disclaimer</h3>
        <p class="sf-muted"><?= sf_safe((string)($content['disclaimer'] ?? '')) ?></p>
        <a id="waCta" class="sf-calc-btn" style="display:inline-block;text-decoration:none;text-align:center;margin-top:.5rem" target="_blank" rel="noopener">Request a Quotation</a>
      </div>
    </section>

    <section class="sf-card sf-accordion" style="margin-top:1rem">
      <h3>FAQ</h3>
      <?php foreach (($content['faq'] ?? []) as $faq): ?>
      <details><summary><?= sf_safe((string)($faq['q'] ?? '')) ?></summary><p class="sf-muted"><?= sf_safe((string)($faq['a'] ?? '')) ?></p></details>
      <?php endforeach; ?>
    </section>
  </div>
</main>
<footer class="site-footer"></footer>
<script>
window.__SOLAR_FINANCE_SETTINGS__ = <?= json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(() => {
  const settings = window.__SOLAR_FINANCE_SETTINGS__ || {};
  const defaults = settings.defaults || {};
  const onGridTable = Array.isArray(settings.on_grid_price_table) ? settings.on_grid_price_table : [];
  const hybridTable = Array.isArray(settings.hybrid_price_table) ? settings.hybrid_price_table : [];

  const $ = (id) => document.getElementById(id);
  const els = {
    systemType: $('systemType'), solarSize: $('solarSize'), dailyGen: $('dailyGen'), unitRate: $('unitRate'), monthlyBill: $('monthlyBill'),
    monthlyUnits: $('monthlyUnits'), roofArea: $('roofArea'), systemCost: $('systemCost'), subsidy: $('subsidy'), loanAmount: $('loanAmount'),
    marginMoney: $('marginMoney'), interestRate: $('interestRate'), loanTenure: $('loanTenure'), calcBtn: $('calcBtn'),
  };

  let syncingField = null;
  const money = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', {maximumFractionDigits:0});

  const findDefaultRow = (systemType, size) => {
    if (!size) return null;
    if (systemType === 'Hybrid') {
      // Explicit rule: first matching hybrid row only.
      return hybridTable.find((row) => Number(row.solar_rating_kw) === Number(size)) || null;
    }
    return onGridTable.find((row) => Number(row.solar_rating_kw) === Number(size)) || null;
  };

  const emi = (p, annualRate, months) => {
    if (p <= 0 || months <= 0) return 0;
    const r = (annualRate / 12) / 100;
    if (r === 0) return p / months;
    return p * r * Math.pow(1 + r, months) / (Math.pow(1 + r, months) - 1);
  };

  const syncBillUnits = (source) => {
    const rate = Number(els.unitRate.value) || 0;
    if (rate <= 0) return;
    if (source === 'bill') {
      const bill = Number(els.monthlyBill.value);
      if (Number.isFinite(bill) && bill >= 0) {
        syncingField = 'units';
        els.monthlyUnits.value = bill > 0 ? (bill / rate).toFixed(0) : '';
      }
    }
    if (source === 'units') {
      const units = Number(els.monthlyUnits.value);
      if (Number.isFinite(units) && units >= 0) {
        syncingField = 'bill';
        els.monthlyBill.value = units > 0 ? (units * rate).toFixed(0) : '';
      }
    }
    setTimeout(() => { syncingField = null; }, 0);
  };

  els.monthlyBill.addEventListener('input', () => { if (syncingField !== 'bill') syncBillUnits('bill'); });
  els.monthlyUnits.addEventListener('input', () => { if (syncingField !== 'units') syncBillUnits('units'); });
  els.unitRate.addEventListener('input', () => { if (els.monthlyBill.value) syncBillUnits('bill'); else if (els.monthlyUnits.value) syncBillUnits('units'); });

  const calculateScenario = (label, systemCost) => {
    const dailyGen = Number(els.dailyGen.value) || Number(defaults.daily_generation_per_kw) || 5;
    const unitRate = Number(els.unitRate.value) || Number(defaults.unit_rate) || 8;
    let monthlyUnits = Number(els.monthlyUnits.value) || 0;
    const enteredBill = Number(els.monthlyBill.value);
    const subsidy = Math.max(Number(els.subsidy.value) || 0, 0);
    const loanAmount = Math.max(Number(els.loanAmount.value) || 0, 0);
    const interestRate = Math.max(Number(els.interestRate.value) || 0, 0);
    const tenure = Math.max(Number(els.loanTenure.value) || Number(defaults.loan_tenure_months) || 120, 1);
    const marginMoney = Math.max(Number(els.marginMoney.value) || 0, 0);
    const solarSize = Math.max(Number(els.solarSize.value) || 0, 0);

    if (!monthlyUnits && enteredBill > 0 && unitRate > 0) monthlyUnits = enteredBill / unitRate;
    const recommendedSize = monthlyUnits > 0 ? (monthlyUnits / (dailyGen * 30)) : solarSize;
    if (!els.solarSize.value && recommendedSize > 0) els.solarSize.value = recommendedSize.toFixed(2);

    const usedSolarSize = Math.max(Number(els.solarSize.value) || recommendedSize || 0, 0);
    const monthlySolarGeneration = usedSolarSize * dailyGen * 30;
    const annualGeneration = monthlySolarGeneration * 12;
    const generation25 = annualGeneration * 25;
    const monthlySolarValue = monthlySolarGeneration * unitRate;

    const monthlyBillFallback = monthlySolarValue;
    const monthlyBill = Number.isFinite(enteredBill) && enteredBill > 0 ? enteredBill : monthlyBillFallback;
    const residualBill = Math.max(monthlyBill - monthlySolarValue, 0);
    const billOffsetPct = monthlyBill > 0 ? Math.min((monthlySolarValue / monthlyBill) * 100, 100) : 0;
    const roofAreaFactor = Number(defaults.roof_area_factor_per_kw) || 100;
    const requiredRoofArea = usedSolarSize * roofAreaFactor;
    const availableRoofArea = Number(els.roofArea.value) || 0;

    const effectiveLoanPrincipal = Math.max(loanAmount - subsidy, 0);
    const monthlyEmi = emi(effectiveLoanPrincipal, interestRate, tenure);
    const noSolarMonthly = monthlyBill;
    const withLoanMonthly = monthlyEmi + residualBill;
    const selfFundedMonthly = residualBill;

    const noSolar25 = noSolarMonthly * 12 * 25;
    const withLoan25 = (monthlyEmi * tenure) + (residualBill * 12 * 25) + marginMoney;
    const selfFundedUpfront = Math.max(systemCost - subsidy, 0);
    const selfFunded25 = selfFundedUpfront + (residualBill * 12 * 25);

    const annualSavings = Math.max((monthlyBill - residualBill) * 12, 0);
    const selfPaybackYears = annualSavings > 0 ? selfFundedUpfront / annualSavings : 0;
    const loanRecoveryYears = annualSavings > 0 ? marginMoney / annualSavings : 0;

    const co2Factor = Number(defaults.co2_factor_kg_per_kwh) || 0.82;
    const treeFactor = Number(defaults.tree_equivalence_factor_kg_per_tree_per_year) || 20;
    const co2Annual = annualGeneration * co2Factor;
    const co2TwentyFive = co2Annual * 25;
    const treesEquivalent = treeFactor > 0 ? co2Annual / treeFactor : 0;

    return { label, systemCost, subsidy, loanAmount, effectiveLoanPrincipal, monthlyEmi, residualBill, withLoanMonthly, selfFundedMonthly, noSolarMonthly, noSolar25, withLoan25, selfFunded25, annualSavings, selfPaybackYears, loanRecoveryYears, monthlySolarGeneration, annualGeneration, generation25, billOffsetPct, requiredRoofArea, availableRoofArea, monthlyBill, monthlyBillEstimated: !(enteredBill > 0), co2Annual, co2TwentyFive, treesEquivalent, usedSolarSize };
  };

  const render = (a, b, defaultsRef) => {
    $('resultsSection').hidden = false;
    $('resultsSection').scrollIntoView({behavior: 'smooth', block: 'start'});
    const roofStatus = a.availableRoofArea <= 0 ? 'Enter roof area to check fit' : (a.availableRoofArea >= a.requiredRoofArea ? 'Sufficient roof area' : (a.availableRoofArea >= a.requiredRoofArea * 0.85 ? 'May be tight' : 'Insufficient roof area'));
    $('topMetrics').innerHTML = `
      <article class="sf-card"><div class="sf-badge">Recommended</div><div class="sf-kpi">${a.usedSolarSize.toFixed(2)} kW</div><p class="sf-muted">Recommended solar size</p></article>
      <article class="sf-card"><div class="sf-kpi">${a.requiredRoofArea.toFixed(0)} sq ft</div><p class="sf-muted">Approx roof area needed · ${roofStatus}</p></article>
      <article class="sf-card"><div class="sf-kpi">${a.billOffsetPct.toFixed(1)}%</div><p class="sf-muted">Bill offset percentage</p></article>
      <article class="sf-card"><div class="sf-kpi">${a.monthlySolarGeneration.toFixed(0)} units</div><p class="sf-muted">Monthly generation (Annual ${a.annualGeneration.toFixed(0)} | 25Y ${a.generation25.toFixed(0)})</p></article>
      <article class="sf-card"><div class="sf-kpi">${a.co2Annual.toFixed(0)} kg</div><p class="sf-muted">CO₂ avoided/year · 25Y ${a.co2TwentyFive.toFixed(0)} kg · Trees ${a.treesEquivalent.toFixed(1)}</p></article>
    `;

    const renderScenarioCard = (s, refCost, refLabel) => `
      <article class="sf-card">
        <h3>${s.label}</h3>
        <p class="sf-muted">Default table price (${refLabel}): ${money(refCost)}</p>
        <p class="sf-muted">System cost: ${money(s.systemCost)} · Payable(full cost): ${money(s.systemCost)}</p>
        <p class="sf-muted">Subsidy: ${money(s.subsidy)} · Loan amount: ${money(s.loanAmount)} · Loan minus subsidy: ${money(s.effectiveLoanPrincipal)}</p>
        <p class="sf-muted">EMI: ${money(s.monthlyEmi)} · Residual bill: ${money(s.residualBill)} · Total monthly outflow: ${money(s.withLoanMonthly)}</p>
        <p class="sf-muted">No Solar (25Y): ${money(s.noSolar25)} · Solar+Loan (25Y): ${money(s.withLoan25)} · Self-funded (25Y): ${money(s.selfFunded25)}</p>
        <p class="sf-muted">Self-funded upfront: ${money(Math.max(s.systemCost - s.subsidy, 0))} · Annual savings: ${money(s.annualSavings)}</p>
        <p class="sf-muted">Self-funded payback: ${s.selfPaybackYears ? s.selfPaybackYears.toFixed(1)+' years' : 'N/A'} · Loan contribution recovery: ${s.loanRecoveryYears ? s.loanRecoveryYears.toFixed(1)+' years' : 'N/A'}</p>
        ${s.monthlyBillEstimated ? '<p class="sf-muted"><strong>Note:</strong> Monthly bill estimated because bill was not entered.</p>' : ''}
      </article>`;

    $('scenarioCards').innerHTML = renderScenarioCard(a, defaultsRef.a, 'Loan upto ₹2 lakh') + renderScenarioCard(b, defaultsRef.b, 'Loan above ₹2 lakh');

    const fields = ['systemType','solarSize','monthlyBill','monthlyUnits','unitRate','systemCost','subsidy','loanAmount','marginMoney','interestRate','loanTenure','roofType','propertyType','locationCity','roofArea','needBackup','supplyPhase'];
    const detailMap = Object.fromEntries(fields.map((id) => [id, ($(id)?.value || '').toString()]));
    const lines = [
      'Hi Dakshayani team, please share quotation for Solar and Finance details:',
      `System Type: ${detailMap.systemType}`,
      `Solar Size (kW): ${detailMap.solarSize}`,
      `Monthly Bill: ${detailMap.monthlyBill}`,
      `Monthly Units: ${detailMap.monthlyUnits}`,
      `Unit Rate: ${detailMap.unitRate}`,
      `Cost: ${detailMap.systemCost}`,
      `Subsidy: ${detailMap.subsidy}`,
      `Loan Amount: ${detailMap.loanAmount}`,
      `Margin Money: ${detailMap.marginMoney}`,
      `Interest: ${detailMap.interestRate}%`,
      `Tenure: ${detailMap.loanTenure} months`,
      `Roof Type: ${detailMap.roofType}`,
      `Property Type: ${detailMap.propertyType}`,
      `City/Location: ${detailMap.locationCity}`,
      `Roof Area: ${detailMap.roofArea} sq ft`,
      `Backup needed: ${detailMap.needBackup}`,
      `Phase: ${detailMap.supplyPhase || 'N/A'}`,
    ];
    $('waCta').href = 'https://wa.me/917070278178?text=' + encodeURIComponent(lines.join('\n'));
  };

  els.calcBtn.addEventListener('click', () => {
    const systemType = els.systemType.value;
    const size = Math.round(Number(els.solarSize.value) || 0);
    const row = findDefaultRow(systemType, size);
    const defaultA = Number(row?.price_loan_upto_2_lakh) || 0;
    const defaultB = Number(row?.price_loan_above_2_lakh) || 0;
    const manualCost = Number(els.systemCost.value);
    const useManual = Number.isFinite(manualCost) && manualCost > 0;
    const scenarioA = calculateScenario('Scenario A: Loan upto ₹2 lakh', useManual ? manualCost : defaultA);
    const scenarioB = calculateScenario('Scenario B: Loan above ₹2 lakh', useManual ? manualCost : defaultB);
    render(scenarioA, scenarioB, {a: defaultA, b: defaultB});
  });
})();
</script>
<script src="/script.js" defer></script>
<script src="/site-content.js" defer></script>
</body>
</html>
