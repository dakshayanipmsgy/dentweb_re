<?php
require_once __DIR__ . '/includes/bootstrap.php';
$sf = solar_finance_settings();
$pageTitle = $sf['page_title'] ?? 'Solar and Finance';
$heroIntro = $sf['hero_intro'] ?? '';
$ctaText = $sf['cta_text'] ?? 'Request a quotation';
$settingsJson = htmlspecialchars(json_encode($sf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle) ?> | Dakshayani Enterprises</title>
  <meta name="description" content="Simple, customer-friendly solar and finance calculator with loan vs self-funded comparison." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header class="site-header"></header>
<main class="sf-page" data-solar-finance-page>
  <section class="sf-hero">
    <div class="sf-wrap">
      <p class="sf-kicker">Solar clarity made easy</p>
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
      <p><?= htmlspecialchars($heroIntro) ?></p>
      <div class="sf-hero-points">
        <span><i class="fa-solid fa-circle-check"></i> Understand what solar is</span>
        <span><i class="fa-solid fa-circle-check"></i> Compare loan vs self-funded</span>
        <span><i class="fa-solid fa-circle-check"></i> Estimate savings, payback & green impact</span>
      </div>
      <a href="#sf-form" class="btn btn-primary">Start your estimate</a>
    </div>
  </section>

  <section id="sf-form" class="sf-section">
    <div class="sf-wrap">
      <h2>Tell us about your requirement</h2>
      <p class="sf-sub">Fill only basics first. Advanced assumptions are optional.</p>
      <form class="sf-grid" data-sf-form>
        <label>Solar power size (kW)<input type="number" step="0.1" min="0" name="solar_size" required /></label>
        <label>Average daily generation (kWh / kW / day)<input type="number" step="0.1" min="0" name="daily_generation" /></label>
        <label>Electricity unit rate (₹ / unit)<input type="number" step="0.1" min="0" name="unit_rate" /></label>
        <label>Average monthly bill (₹)<input type="number" step="1" min="0" name="monthly_bill" /></label>
        <label>Average monthly units consumed<input type="number" step="1" min="0" name="monthly_units" /></label>
        <label>Cost of solar system (₹)<input type="number" step="1" min="0" name="system_cost" required /></label>
        <label>Subsidy (₹)<input type="number" step="1" min="0" name="subsidy" value="0" /></label>
        <label>Loan amount (₹)<input type="number" step="1" min="0" name="loan_amount" value="0" /></label>
        <label>Margin money (₹)<input type="number" step="1" min="0" name="margin_money" value="0" /></label>
        <label>Interest rate (% per year)<input type="number" step="0.1" min="0" name="interest_rate" /></label>
        <label>Loan tenure (months)<input type="number" step="1" min="1" name="loan_tenure_months" /></label>
        <label>Property type
          <select name="property_type">
            <option>Home</option><option>Apartment</option><option>Society</option><option>School</option><option>Hospital</option><option>Office</option><option>Shop</option><option>Industry</option>
          </select>
        </label>
        <label>Roof type
          <select name="roof_type">
            <option>RCC</option><option>Sheet</option><option>Mixed</option><option>Other</option>
          </select>
        </label>
        <label>Available shadow-free roof area (sq ft)<input type="number" step="1" min="0" name="roof_area" /></label>
        <label>Connection type
          <select name="connection_type"><option>Single phase</option><option>Three phase</option></select>
        </label>
        <label>Daytime usage level
          <select name="daytime_usage"><option>Low</option><option>Medium</option><option>High</option></select>
        </label>
        <label>City / location<input type="text" name="location" placeholder="Ranchi" /></label>
      </form>

      <details class="sf-advanced">
        <summary>Advanced assumptions (optional)</summary>
        <div class="sf-grid sf-grid-advanced">
          <label>Annual tariff escalation (%)<input type="number" step="0.1" min="0" name="tariff_escalation" data-sf-adv /></label>
          <label>Annual solar degradation (%)<input type="number" step="0.1" min="0" name="solar_degradation" data-sf-adv /></label>
          <label>Monthly fixed/minimum bill (₹)<input type="number" step="1" min="0" name="fixed_charge" data-sf-adv /></label>
          <label>Annual O&amp;M allowance (₹)<input type="number" step="1" min="0" name="annual_om" data-sf-adv /></label>
          <label>Inverter replacement reserve (₹/year)<input type="number" step="1" min="0" name="inverter_reserve" data-sf-adv /></label>
          <label>Billing assumption
            <select name="billing_assumption" data-sf-adv>
              <option value="net_metering">Net metering assumed</option>
              <option value="simple_offset">Simple offset</option>
              <option value="gross_metering">Gross metering</option>
            </select>
          </label>
          <label>Shading / efficiency loss (%)<input type="number" step="0.1" min="0" max="100" name="shading_loss" data-sf-adv /></label>
        </div>
      </details>

      <div class="sf-actions">
        <button class="btn btn-primary" type="button" data-sf-calc>How solar adoption would look like</button>
        <p class="sf-note" data-sf-note></p>
      </div>
    </div>
  </section>

  <section class="sf-section sf-results" data-sf-results hidden>
    <div class="sf-wrap">
      <h2>Results at a glance</h2>
      <div class="sf-cards" data-sf-summary></div>
      <div class="sf-chart-grid">
        <article class="sf-card"><h3>Monthly outflow comparison</h3><canvas id="sfOutflowChart"></canvas></article>
        <article class="sf-card"><h3>Cumulative expense over 25 years</h3><canvas id="sfCumulativeChart"></canvas></article>
      </div>
      <div class="sf-chart-grid">
        <article class="sf-card"><h3>Payback meter (Solar — Loan)</h3><div data-sf-payback-loan></div></article>
        <article class="sf-card"><h3>Payback meter (Self Funded)</h3><div data-sf-payback-self></div></article>
      </div>
      <div class="sf-finance-grid">
        <article class="sf-card"><h3>Financial clarity — With Loan</h3><div data-sf-fin-loan></div></article>
        <article class="sf-card"><h3>Financial clarity — Self Funded</h3><div data-sf-fin-self></div></article>
      </div>
      <div class="sf-finance-grid">
        <article class="sf-card"><h3>Generation estimate</h3><div data-sf-generation></div></article>
        <article class="sf-card"><h3>Green impact</h3><div data-sf-green></div></article>
      </div>
      <article class="sf-card"><h3>Recommendation summary</h3><div data-sf-recommend></div></article>
    </div>
  </section>

  <section class="sf-section sf-edu">
    <div class="sf-wrap">
      <h2>Easy solar education</h2>
      <div class="sf-edu-grid" data-sf-explainers></div>
      <div class="sf-faq" data-sf-faq></div>
    </div>
  </section>

  <section class="sf-section sf-cta">
    <div class="sf-wrap sf-cta-inner">
      <h2>Need help choosing? Talk to Dakshayani Enterprises</h2>
      <button class="btn btn-primary" data-sf-whatsapp><?= htmlspecialchars($ctaText) ?></button>
    </div>
  </section>
</main>
<footer class="site-footer"></footer>
<script id="solar-finance-settings" type="application/json"><?= $settingsJson ?></script>
<script src="script.js"></script>
<script>
(() => {
  const settings = JSON.parse(document.getElementById('solar-finance-settings').textContent || '{}');
  const form = document.querySelector('[data-sf-form]');
  const resultsEl = document.querySelector('[data-sf-results]');
  const summaryEl = document.querySelector('[data-sf-summary]');
  const noteEl = document.querySelector('[data-sf-note]');
  let outflowChart; let cumulativeChart;

  const defaults = settings.defaults || {};
  const factors = settings.factors || {};
  const currency = (v) => `₹${Number(v || 0).toLocaleString('en-IN', {maximumFractionDigits: 0})}`;
  const unit = (v) => `${Number(v || 0).toLocaleString('en-IN', {maximumFractionDigits: 0})} units`;

  ['daily_generation','unit_rate','interest_rate','loan_tenure_months','tariff_escalation','solar_degradation','fixed_charge','annual_om','inverter_reserve','billing_assumption','shading_loss'].forEach((key) => {
    const input = form.querySelector(`[name="${key}"]`) || document.querySelector(`[name="${key}"]`);
    if (input && defaults[key] !== undefined) input.value = defaults[key];
  });

  let lockSync = false;
  const monthlyBill = form.querySelector('[name="monthly_bill"]');
  const monthlyUnits = form.querySelector('[name="monthly_units"]');
  const unitRate = form.querySelector('[name="unit_rate"]');

  monthlyBill?.addEventListener('input', () => {
    if (lockSync) return;
    const rate = parseFloat(unitRate.value);
    if (rate > 0 && monthlyBill.value !== '') {
      lockSync = true;
      monthlyUnits.value = (parseFloat(monthlyBill.value || '0') / rate).toFixed(1);
      lockSync = false;
    }
  });

  monthlyUnits?.addEventListener('input', () => {
    if (lockSync) return;
    const rate = parseFloat(unitRate.value);
    if (rate > 0 && monthlyUnits.value !== '') {
      lockSync = true;
      monthlyBill.value = (parseFloat(monthlyUnits.value || '0') * rate).toFixed(0);
      lockSync = false;
    }
  });

  function emi(principal, annualRate, tenureMonths) {
    if (principal <= 0 || tenureMonths <= 0) return 0;
    const r = (annualRate / 12) / 100;
    if (r === 0) return principal / tenureMonths;
    const factor = Math.pow(1 + r, tenureMonths);
    return principal * r * factor / (factor - 1);
  }

  function meter(months) {
    if (!Number.isFinite(months) || months <= 0) return '<p>Payback not estimated</p>';
    const capped = Math.min(100, (months / 240) * 100);
    return `<div class="sf-meter"><div class="sf-meter-fill" style="width:${capped}%"></div></div><p>${months.toFixed(1)} months</p>`;
  }

  function calc() {
    const f = new FormData(form);
    const v = Object.fromEntries(f.entries());
    document.querySelectorAll('[data-sf-adv]').forEach((el) => v[el.name] = el.value);

    const solarSize = parseFloat(v.solar_size || '0');
    const dailyGen = parseFloat(v.daily_generation || defaults.daily_generation || '5');
    const rate = parseFloat(v.unit_rate || defaults.unit_rate || '8');
    let bill = parseFloat(v.monthly_bill || '0');
    let units = parseFloat(v.monthly_units || '0');
    const systemCost = parseFloat(v.system_cost || '0');
    const subsidy = parseFloat(v.subsidy || '0');
    const loanAmount = parseFloat(v.loan_amount || '0');
    const marginMoney = parseFloat(v.margin_money || '0');
    const interestRate = parseFloat(v.interest_rate || defaults.interest_rate || '6');
    const tenure = parseFloat(v.loan_tenure_months || defaults.loan_tenure_months || '120');
    const escalation = parseFloat(v.tariff_escalation || defaults.tariff_escalation || '3') / 100;
    const degradation = parseFloat(v.solar_degradation || defaults.solar_degradation || '0.6') / 100;
    const fixedCharge = parseFloat(v.fixed_charge || '0');
    const annualOm = parseFloat(v.annual_om || '0');
    const inverterReserve = parseFloat(v.inverter_reserve || '0');
    const shadingLoss = parseFloat(v.shading_loss || defaults.shading_loss || '10') / 100;

    const monthlySolarUnits = solarSize * dailyGen * 30 * Math.max(0, 1 - shadingLoss);
    const annualSolarUnits = monthlySolarUnits * 12;
    const monthlySolarValue = monthlySolarUnits * rate;

    noteEl.textContent = '';
    if (!bill && !units) {
      bill = monthlySolarUnits * rate;
      units = monthlySolarUnits;
      noteEl.textContent = 'Monthly bill was not entered, so this estimate is based on solar size, generation and tariff.';
    }

    if (!bill && units) bill = units * rate;
    if (!units && bill && rate > 0) units = bill / rate;

    const residualBill = Math.max(bill - monthlySolarValue, fixedCharge || 0);
    const effectivePrincipal = Math.max(loanAmount - subsidy, 0);
    const monthlyEmi = emi(effectivePrincipal, interestRate, tenure);
    const noSolarOutflow = bill;
    const loanOutflow = monthlyEmi + residualBill;
    const selfOutflow = residualBill;
    const selfUpfront = Math.max(systemCost - subsidy, 0);

    const annualSavingLoan = (noSolarOutflow - loanOutflow) * 12;
    const annualSavingSelf = (noSolarOutflow - selfOutflow) * 12;
    const paybackLoanMonths = annualSavingLoan > 0 ? (marginMoney / annualSavingLoan) * 12 : NaN;
    const paybackSelfMonths = annualSavingSelf > 0 ? (selfUpfront / annualSavingSelf) * 12 : NaN;

    let units25 = 0;
    let rupee25 = 0;
    let cNo = 0;
    let cLoan = marginMoney;
    let cSelf = selfUpfront;
    const years = []; const lineNo = []; const lineLoan = []; const lineSelf = [];
    for (let y = 1; y <= 25; y++) {
      const tariffYear = rate * Math.pow(1 + escalation, y - 1);
      const genYear = annualSolarUnits * Math.pow(1 - degradation, y - 1);
      units25 += genYear;
      rupee25 += genYear * rate;
      const annualBillNo = (bill * 12) * Math.pow(1 + escalation, y - 1);
      const annualResidual = Math.max(annualBillNo - (genYear * tariffYear), (fixedCharge * 12));
      const annualEmi = y <= Math.ceil(tenure / 12) ? monthlyEmi * 12 : 0;
      cNo += annualBillNo;
      cLoan += annualResidual + annualEmi + annualOm + inverterReserve;
      cSelf += annualResidual + annualOm + inverterReserve;
      years.push(y); lineNo.push(cNo); lineLoan.push(cLoan); lineSelf.push(cSelf);
    }

    const roofNeed = solarSize * parseFloat(factors.roof_area_sqft_per_kw || 100);
    const billOffset = bill > 0 ? Math.min(100, (monthlySolarValue / bill) * 100) : 0;
    const co2AnnualKg = annualSolarUnits * parseFloat(factors.co2_per_unit_kg || 0.82);
    const co2TotalKg = units25 * parseFloat(factors.co2_per_unit_kg || 0.82);
    const treeEquivalent = (co2TotalKg / 1000) * parseFloat(factors.tree_per_ton_co2 || 45);

    summaryEl.innerHTML = [
      ['Solar size', `${solarSize} kW`], ['Monthly bill without solar', currency(noSolarOutflow)],
      ['With solar (loan)', currency(loanOutflow)], ['With solar (self-funded)', currency(selfOutflow)],
      ['Monthly savings estimate', currency(noSolarOutflow - selfOutflow)], ['Bill offset', `${billOffset.toFixed(1)}%`],
      ['Roof area needed', `${roofNeed.toFixed(0)} sq ft`]
    ].map(([k,val]) => `<article class="sf-pill"><h4>${k}</h4><p>${val}</p></article>`).join('');

    const loanNode = document.querySelector('[data-sf-fin-loan]');
    const selfNode = document.querySelector('[data-sf-fin-self]');
    const genNode = document.querySelector('[data-sf-generation]');
    const greenNode = document.querySelector('[data-sf-green]');
    const recommendNode = document.querySelector('[data-sf-recommend]');
    document.querySelector('[data-sf-payback-loan]').innerHTML = meter(paybackLoanMonths);
    document.querySelector('[data-sf-payback-self]').innerHTML = meter(paybackSelfMonths);

    loanNode.innerHTML = `<ul><li>Full system cost: ${currency(systemCost)}</li><li>Subsidy: ${currency(subsidy)}</li><li>Loan amount: ${currency(loanAmount)}</li><li>Loan amount minus subsidy: ${currency(effectivePrincipal)}</li><li>Margin money: ${currency(marginMoney)}</li><li>Interest rate: ${interestRate}%</li><li>Loan tenure: ${tenure} months</li><li>EMI: ${currency(monthlyEmi)}</li><li>Residual bill: ${currency(residualBill)}</li><li>Total monthly outflow: ${currency(loanOutflow)}</li></ul>`;
    selfNode.innerHTML = `<ul><li>Full system cost: ${currency(systemCost)}</li><li>Subsidy: ${currency(subsidy)}</li><li>Net investment: ${currency(selfUpfront)}</li><li>Residual bill: ${currency(residualBill)}</li><li>Estimated monthly saving: ${currency(noSolarOutflow - selfOutflow)}</li><li>Estimated annual saving: ${currency(annualSavingSelf)}</li></ul>`;
    genNode.innerHTML = `<ul><li>Expected monthly generation: ${unit(monthlySolarUnits)}</li><li>Expected annual generation: ${unit(annualSolarUnits)}</li><li>Estimated payback: ${isFinite(paybackSelfMonths) ? paybackSelfMonths.toFixed(1)+' months' : 'Not estimated'}</li><li>Units produced in 25 years: ${unit(units25)}</li><li>₹ saved in 25 years (today's rate): ${currency(rupee25)}</li></ul>`;
    greenNode.innerHTML = `<ul><li>CO₂ saved annually: ${(co2AnnualKg/1000).toFixed(2)} t</li><li>CO₂ saved in 25 years: ${(co2TotalKg/1000).toFixed(2)} t</li><li>Tree equivalent: ${treeEquivalent.toFixed(0)} trees</li></ul>`;

    const lowestOutflow = [
      ['No Solar', noSolarOutflow], ['Solar + Loan', loanOutflow], ['Solar Self-funded', selfOutflow]
    ].sort((a,b)=>a[1]-b[1])[0][0];
    const bestLifetime = [
      ['No Solar', cNo], ['Solar + Loan', cLoan], ['Solar Self-funded', cSelf]
    ].sort((a,b)=>a[1]-b[1])[0][0];
    recommendNode.innerHTML = `<ul><li><strong>Best for lowest monthly outflow:</strong> ${lowestOutflow}</li><li><strong>Best for highest lifetime savings:</strong> ${bestLifetime}</li><li><strong>Best for lowest initial investment:</strong> Solar + Loan</li><li><strong>System size check:</strong> ${roofNeed <= (parseFloat(v.roof_area||'0')||Infinity) ? 'Roof area appears suitable.' : 'Roof may be tight. Consider lower kW or optimized layout.'}</li></ul>`;

    if (outflowChart) outflowChart.destroy();
    outflowChart = new Chart(document.getElementById('sfOutflowChart'), {
      type: 'bar',
      data: { labels: ['No Solar','With Solar (Loan)','With Solar (Self Funded)'], datasets: [{ data: [noSolarOutflow, loanOutflow, selfOutflow], backgroundColor:['#475569','#0ea5e9','#16a34a']}]},
      options: { plugins: {legend:{display:false}}, responsive:true, maintainAspectRatio:false }
    });

    if (cumulativeChart) cumulativeChart.destroy();
    cumulativeChart = new Chart(document.getElementById('sfCumulativeChart'), {
      type: 'line',
      data: { labels: years, datasets: [{label:'No Solar',data:lineNo,borderColor:'#475569',tension:.2},{label:'With Solar (Loan)',data:lineLoan,borderColor:'#0ea5e9',tension:.2},{label:'With Solar (Self Funded)',data:lineSelf,borderColor:'#16a34a',tension:.2}]},
      options: { responsive:true, maintainAspectRatio:false }
    });

    resultsEl.hidden = false;
    resultsEl.scrollIntoView({behavior:'smooth', block:'start'});

    document.querySelector('[data-sf-whatsapp]').dataset.msg = [
      `Solar and Finance enquiry`, `Solar size: ${solarSize} kW`, `Daily generation: ${dailyGen} kWh/kW/day`, `Unit rate: ₹${rate}`,
      `Monthly bill: ${currency(bill)}`, `Monthly units: ${units.toFixed(1)}`, `System cost: ${currency(systemCost)}`,
      `Subsidy: ${currency(subsidy)}`, `Loan amount: ${currency(loanAmount)}`, `Margin money: ${currency(marginMoney)}`,
      `Interest rate: ${interestRate}%`, `Tenure: ${tenure} months`, `Property type: ${v.property_type || ''}`,
      `Roof type: ${v.roof_type || ''}`, `Roof area: ${v.roof_area || ''} sq ft`, `Connection type: ${v.connection_type || ''}`,
      `Daytime usage: ${v.daytime_usage || ''}`, `City/Location: ${v.location || ''}`
    ].join('\n');
  }

  document.querySelector('[data-sf-calc]').addEventListener('click', calc);
  document.querySelector('[data-sf-whatsapp]').addEventListener('click', (e) => {
    const text = e.currentTarget.dataset.msg || 'I want a solar quotation.';
    window.open(`https://wa.me/917070278178?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
  });

  const explainerWrap = document.querySelector('[data-sf-explainers]');
  (settings.explainers || []).forEach((item) => {
    explainerWrap.insertAdjacentHTML('beforeend', `<article class="sf-card"><h3><i class="fa-solid ${item.icon || 'fa-circle-info'}"></i> ${item.title}</h3><p>${item.text}</p></article>`);
  });
  const faqWrap = document.querySelector('[data-sf-faq]');
  faqWrap.innerHTML = (settings.faqs || []).map((f) => `<details class="sf-card"><summary>${f.q}</summary><p>${f.a}</p></details>`).join('');
})();
</script>
</body>
</html>
