<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/solar_finance_settings.php';

function sf_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sf_lead_content(): array
{
    $path = __DIR__ . '/data/leads/lead_explainer_content.json';
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

$settings = solar_finance_settings();
$leadContent = sf_lead_content();
$pageTitle = trim((string) ($settings['content']['page_title'] ?? 'Solar and Finance')) ?: 'Solar and Finance';
$heroIntro = trim((string) ($settings['content']['hero_intro'] ?? ''));
if ($heroIntro === '') {
    $heroIntro = 'Simple solar samjho, apna suitable system size estimate karo, finance compare karo, and direct quotation request bhejo.';
}
$payload = ['settings' => $settings, 'leadContent' => $leadContent];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo sf_safe($pageTitle); ?> | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="/style.css" />
  <style>
    .sf-wrap { width: 100%; max-width: none; padding: 1rem 1rem 4rem; box-sizing: border-box; }
    .sf-hero { background: linear-gradient(120deg,#0f172a,#0ea5e9); color: #fff; border-radius: 18px; padding: 1.25rem; margin-bottom: 1rem; }
    .sf-grid { display: grid; grid-template-columns: repeat(12,minmax(0,1fr)); gap: 1rem; }
    .sf-card { grid-column: span 12; background: #fff; border:1px solid #dbe5f0; border-radius: 14px; padding: 1rem; }
    .sf-inputs { grid-column: span 12; }
    .sf-results { grid-column: span 12; }
    .sf-2 { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: .8rem; }
    .sf-field label { display:block; font-size:.86rem; font-weight:700; margin-bottom:.25rem; }
    .sf-field input,.sf-field select,.sf-field textarea { width:100%; padding:.55rem .65rem; border:1px solid #cbd5e1; border-radius:9px; }
    .sf-mini { font-size:.82rem;color:#475569; }
    .sf-kpi { background:#f8fafc; border:1px solid #d9e2ee; border-radius:10px; padding:.65rem; }
    .sf-kpi b { font-size:1.05rem; display:block; margin-top:.2rem; }
    .sf-table { width:100%; border-collapse: collapse; }
    .sf-table th,.sf-table td { border:1px solid #dbe5f0; padding:.5rem; text-align:left; font-size:.9rem; }
    .sf-badge { display:inline-block; border-radius:999px; padding:.22rem .6rem; background:#dbeafe; color:#1d4ed8; font-weight:700; font-size:.75rem; }
    .sf-cta { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:.8rem; }
    .sf-btn { border:none; border-radius:10px; background:#0f766e; color:#fff; padding:.7rem 1rem; font-weight:700; cursor:pointer; }
    .sf-btn.alt { background:#0f172a; }
    .sf-disclaimer { background:#fffbeb; border:1px solid #fde68a; color:#78350f; border-radius:10px; padding:.7rem; font-size:.87rem; }
    .sf-edu .sf-card { min-height:100%; }
    .sf-edu h3 { margin-top:0; }
    @media (min-width: 1000px){ .sf-inputs{grid-column:span 5;} .sf-results{grid-column:span 7;} .sf-wrap{padding:1.2rem 1.8rem 4rem;} }
  </style>
</head>
<body data-active-nav="solar-and-finance">
  <header class="site-header"></header>
  <main class="sf-wrap">
    <section class="sf-hero">
      <h1 style="margin:.2rem 0"><?php echo sf_safe($pageTitle); ?></h1>
      <p style="margin:0"><?php echo sf_safe($heroIntro); ?></p>
    </section>

    <section class="sf-grid sf-edu" style="margin-bottom:1rem;">
      <article class="sf-card"><span class="sf-badge">What is rooftop solar?</span><h3>Generate your own electricity</h3><p><?php echo sf_safe((string) ($leadContent['what_is_solar_rooftop'] ?? 'Rooftop solar means installing solar panels on your roof to reduce monthly electricity bills.')); ?></p></article>
      <article class="sf-card"><span class="sf-badge">PM Surya Ghar</span><h3>Government support basics</h3><p><?php echo sf_safe((string) ($leadContent['pm_surya_ghar_text'] ?? 'Eligible homes may receive subsidy support under PM Surya Ghar, subject to policy and approval.')); ?></p></article>
      <article class="sf-card"><span class="sf-badge">On-grid vs Hybrid</span><h3>Which is right for you?</h3><p><b>On-grid:</b> lower upfront cost, no battery backup during outage.<br><b>Hybrid:</b> includes battery backup, higher cost, better for power-cut zones.</p></article>
      <article class="sf-card"><span class="sf-badge">Expectations</span><h3>What affects actual savings?</h3><p><?php echo sf_safe((string) ($leadContent['important_expectations'] ?? 'Savings vary by roof, shadow, tariff, daytime usage, approvals, and meter/billing type.')); ?></p></article>
    </section>

    <section class="sf-grid">
      <aside class="sf-card sf-inputs">
        <h2 style="margin-top:0;">1) Your inputs</h2>
        <div class="sf-2">
          <div class="sf-field"><label>System Type</label><select id="systemType"><option>On-Grid</option><option>Hybrid</option></select></div>
          <div class="sf-field"><label>Solar size (kW)</label><input id="solarSize" type="number" min="0" step="0.1" /></div>
          <div class="sf-field"><label>Daily generation / kW (units)</label><input id="dailyGeneration" type="number" min="0" step="0.1" /></div>
          <div class="sf-field"><label>Unit rate (₹)</label><input id="unitRate" type="number" min="0" step="0.1" /></div>
          <div class="sf-field"><label>Average monthly bill (₹)</label><input id="monthlyBill" type="number" min="0" step="1" /></div>
          <div class="sf-field"><label>Average monthly units</label><input id="monthlyUnits" type="number" min="0" step="1" /></div>
          <div class="sf-field"><label>Property type</label><select id="propertyType"><option>Home</option><option>Apartment / Society</option><option>Shop / Office</option><option>School / Hospital / Institution</option><option>Other</option></select></div>
          <div class="sf-field"><label>Roof type</label><select id="roofType"><option>RCC</option><option>Sheet roof</option><option>Ground mount</option><option>Not sure</option></select></div>
          <div class="sf-field"><label>Shadow-free roof area (sq ft)</label><input id="roofArea" type="number" min="0" step="1" /></div>
          <div class="sf-field"><label>Daytime usage level</label><select id="daytimeUsage"><option>Low</option><option>Medium</option><option>High</option></select></div>
          <div class="sf-field"><label>Need backup during power cuts?</label><select id="backupNeed"><option>No</option><option>Yes</option></select></div>
          <div class="sf-field"><label>City / Location</label><input id="city" type="text" /></div>
          <div class="sf-field"><label>Connection phase (optional)</label><select id="phase"><option value="">Not specified</option><option>Single phase</option><option>Three phase</option></select></div>
        </div>
        <details style="margin-top:.7rem;"><summary><b>Finance inputs (advanced)</b></summary>
          <div class="sf-2" style="margin-top:.7rem;">
            <div class="sf-field"><label>Solar system cost (₹) - manual override</label><input id="systemCost" type="number" min="0" step="100" /></div>
            <div class="sf-field"><label>Subsidy (₹)</label><input id="subsidy" type="number" min="0" step="100" value="0" /></div>
            <div class="sf-field"><label>Loan amount (₹)</label><input id="loanAmount" type="number" min="0" step="100" value="0" /></div>
            <div class="sf-field"><label>Margin money (₹)</label><input id="marginMoney" type="number" min="0" step="100" value="0" /></div>
            <div class="sf-field"><label>Loan interest rate (%)</label><input id="interestRate" type="number" min="0" step="0.1" /></div>
            <div class="sf-field"><label>Loan tenure (months)</label><input id="loanTenure" type="number" min="1" step="1" /></div>
          </div>
        </details>
        <div class="sf-cta"><button class="sf-btn" id="calcBtn">How solar adoption would look like</button></div>
      </aside>

      <section class="sf-card sf-results" id="resultsCard">
        <h2 style="margin-top:0;">2) Results</h2>
        <p class="sf-mini">Fill details and click the button to see your estimate.</p>
      </section>
    </section>

    <section class="sf-card" style="margin-top:1rem;">
      <h2 style="margin-top:0;">FAQ</h2>
      <details><summary>Which one is suitable for whom?</summary><p>On-grid is usually preferred for lowest cost and strong daytime usage. Hybrid is preferred when backup during power cuts is important.</p></details>
      <details><summary>Are these values exact?</summary><p>No. These are indicative estimates for understanding. Final values depend on site survey and utility approvals.</p></details>
    </section>
  </main>
  <footer class="site-footer"></footer>
  <script>window.__SF_PAYLOAD__ = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
  <script>
    (function () {
      const payload = window.__SF_PAYLOAD__ || {};
      const cfg = payload.settings || {};
      const d = cfg.defaults || {};
      const onGrid = Array.isArray(cfg.on_grid_prices) ? cfg.on_grid_prices : [];
      const hybrid = Array.isArray(cfg.hybrid_prices) ? cfg.hybrid_prices : [];

      const $ = (id) => document.getElementById(id);
      const ids = ['systemType','solarSize','dailyGeneration','unitRate','monthlyBill','monthlyUnits','propertyType','roofType','roofArea','daytimeUsage','backupNeed','city','phase','systemCost','subsidy','loanAmount','marginMoney','interestRate','loanTenure'];
      const fields = Object.fromEntries(ids.map((id) => [id, $(id)]));
      fields.dailyGeneration.value = d.daily_generation_per_kw ?? 5;
      fields.unitRate.value = d.unit_rate ?? 8;
      fields.interestRate.value = d.interest_rate ?? 6;
      fields.loanTenure.value = d.loan_tenure_months ?? 120;

      let syncing = false;
      fields.monthlyBill.addEventListener('input', () => {
        if (syncing) return;
        syncing = true;
        const bill = Number(fields.monthlyBill.value || 0);
        const rate = Number(fields.unitRate.value || 0);
        if (rate > 0) fields.monthlyUnits.value = (bill / rate).toFixed(0);
        syncing = false;
      });
      fields.monthlyUnits.addEventListener('input', () => {
        if (syncing) return;
        syncing = true;
        const units = Number(fields.monthlyUnits.value || 0);
        const rate = Number(fields.unitRate.value || 0);
        if (rate > 0) fields.monthlyBill.value = (units * rate).toFixed(0);
        syncing = false;
      });

      function fmt(n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
      function pickDefaultPriceRow(systemType, sizeKw) {
        const table = systemType === 'Hybrid' ? hybrid : onGrid;
        // Hybrid explicit rule: use the first row where solar_rating_kw matches and ignore later duplicates.
        return table.find((row) => Number(row.solar_rating_kw || 0) === Number(sizeKw || 0)) || null;
      }
      function emi(principal, annualRate, months) {
        if (principal <= 0 || months <= 0) return 0;
        const monthly = annualRate / 12 / 100;
        if (monthly <= 0) return principal / months;
        return (principal * monthly * Math.pow(1 + monthly, months)) / (Math.pow(1 + monthly, months) - 1);
      }

      function render() {
        const systemType = fields.systemType.value;
        const sizeInput = Number(fields.solarSize.value || 0);
        const daily = Number(fields.dailyGeneration.value || 0);
        const rate = Number(fields.unitRate.value || 0);
        const monthlyUnits = Number(fields.monthlyUnits.value || 0);
        const monthlyBillRaw = Number(fields.monthlyBill.value || 0);
        const monthlySolarGeneration = sizeInput * daily * 30;
        const monthlySolarValue = monthlySolarGeneration * rate;
        const monthlyBill = monthlyBillRaw > 0 ? monthlyBillRaw : monthlySolarValue;
        const estimatedBill = monthlyBillRaw <= 0;
        const recommended = monthlyUnits > 0 && daily > 0 ? (monthlyUnits / (daily * 30)) : 0;
        if (!sizeInput && recommended > 0) {
          fields.solarSize.value = recommended.toFixed(2);
        }
        const sizeKw = Number(fields.solarSize.value || recommended || 0);
        const roofFactor = Number(d.roof_area_factor_per_kw || 100);
        const roofNeed = sizeKw * roofFactor;
        const availableRoof = Number(fields.roofArea.value || 0);
        const roofStatus = availableRoof <= 0 ? 'Add roof area to assess suitability.' : (availableRoof >= roofNeed ? 'Sufficient roof area.' : (availableRoof >= roofNeed * 0.85 ? 'Roof area may be tight.' : 'Roof area may be insufficient.'));
        const billOffset = monthlyBill > 0 ? Math.min((monthlySolarValue / monthlyBill) * 100, 100) : 0;

        const row = pickDefaultPriceRow(systemType, Math.round(sizeKw));
        const defaultA = Number(row?.price_loan_upto_2_lakh || 0);
        const defaultB = Number(row?.price_loan_above_2_lakh || 0);
        const manualCost = Number(fields.systemCost.value || 0);
        const subsidy = Number(fields.subsidy.value || 0);
        const loanAmount = Number(fields.loanAmount.value || 0);
        const marginMoney = Number(fields.marginMoney.value || 0);
        const interestRate = Number(fields.interestRate.value || 0);
        const tenureMonths = Number(fields.loanTenure.value || 0);

        const buildScenario = (label, scenarioCost) => {
          const systemCost = manualCost > 0 ? manualCost : scenarioCost;
          const effectivePrincipal = Math.max(loanAmount - subsidy, 0);
          const emiValue = emi(effectivePrincipal, interestRate, tenureMonths);
          const residualBill = Math.max(monthlyBill - monthlySolarValue, 0);
          const annualSavings = Math.max((monthlyBill - residualBill) * 12, 0);
          const outflowLoan = emiValue + residualBill;
          const outflowSelf = residualBill;
          const cumulativeNoSolar = monthlyBill * 12 * 25;
          const cumulativeLoan = outflowLoan * 12 * 25 + Math.max(systemCost - loanAmount, 0);
          const cumulativeSelf = (systemCost - subsidy) + (outflowSelf * 12 * 25);
          const selfPaybackYears = annualSavings > 0 ? Math.max((systemCost - subsidy) / annualSavings, 0) : 0;
          const loanPaybackYears = annualSavings > 0 ? Math.max((marginMoney + Math.max(systemCost - loanAmount, 0)) / annualSavings, 0) : 0;

          return { label, systemCost, effectivePrincipal, emiValue, residualBill, annualSavings, outflowLoan, outflowSelf, cumulativeNoSolar, cumulativeLoan, cumulativeSelf, selfPaybackYears, loanPaybackYears };
        };

        const scenarioA = buildScenario('Scenario A: Loan upto ₹2 lakh price', defaultA);
        const scenarioB = buildScenario('Scenario B: Loan above ₹2 lakh price', defaultB);

        const annualGeneration = monthlySolarGeneration * 12;
        const gen25 = annualGeneration * 25;
        const saved25 = gen25 * rate;
        const co2AnnualKg = annualGeneration * Number(d.co2_factor_kg_per_unit || 0.82);
        const co2TotalKg = co2AnnualKg * 25;
        const trees = (co2TotalKg / 1000) * Number(d.tree_equivalence_per_ton_co2 || 45);

        const result = document.getElementById('resultsCard');
        result.innerHTML = `
          <h2 style="margin-top:0;">2) Results</h2>
          ${estimatedBill ? '<div class="sf-disclaimer">Monthly bill not entered, so current bill is estimated from solar value.</div>' : ''}
          <div class="sf-2" style="margin-top:.7rem;">
            <div class="sf-kpi"><span>Recommended solar size</span><b>${recommended > 0 ? recommended.toFixed(2) + ' kW' : 'Add monthly units'}</b></div>
            <div class="sf-kpi"><span>Approx roof area needed</span><b>${roofNeed.toFixed(0)} sq ft</b><div class="sf-mini">${roofStatus}</div></div>
            <div class="sf-kpi"><span>Bill offset</span><b>${billOffset.toFixed(1)}%</b></div>
            <div class="sf-kpi"><span>Monthly generation</span><b>${monthlySolarGeneration.toFixed(0)} units</b></div>
          </div>

          <h3 style="margin-top:1rem;">Financial scenarios</h3>
          <div class="sf-2">
            ${[scenarioA, scenarioB].map((s) => `
              <div class="sf-kpi">
                <b>${s.label}</b>
                <div class="sf-mini">Default table price: ${fmt(s.systemCost)}</div>
                <table class="sf-table" style="margin-top:.45rem;"><tbody>
                  <tr><th>System cost / payable</th><td>${fmt(s.systemCost)}</td></tr>
                  <tr><th>Subsidy</th><td>${fmt(subsidy)}</td></tr>
                  <tr><th>Loan amount</th><td>${fmt(loanAmount)}</td></tr>
                  <tr><th>Loan amount minus subsidy</th><td>${fmt(s.effectivePrincipal)}</td></tr>
                  <tr><th>EMI</th><td>${fmt(s.emiValue)}</td></tr>
                  <tr><th>Residual bill</th><td>${fmt(s.residualBill)}</td></tr>
                  <tr><th>Total monthly outflow (Loan)</th><td>${fmt(s.outflowLoan)}</td></tr>
                  <tr><th>Total monthly outflow (Self-funded)</th><td>${fmt(s.outflowSelf)}</td></tr>
                  <tr><th>Annual savings</th><td>${fmt(s.annualSavings)}</td></tr>
                  <tr><th>25y cumulative: No solar</th><td>${fmt(s.cumulativeNoSolar)}</td></tr>
                  <tr><th>25y cumulative: With solar (Loan)</th><td>${fmt(s.cumulativeLoan)}</td></tr>
                  <tr><th>25y cumulative: With solar (Self)</th><td>${fmt(s.cumulativeSelf)}</td></tr>
                  <tr><th>Self-funded payback</th><td>${s.selfPaybackYears.toFixed(1)} years</td></tr>
                  <tr><th>Loan/upfront recovery payback</th><td>${s.loanPaybackYears.toFixed(1)} years</td></tr>
                </tbody></table>
              </div>
            `).join('')}
          </div>

          <h3 style="margin-top:1rem;">Generation and green impact</h3>
          <div class="sf-2">
            <div class="sf-kpi"><span>Annual generation</span><b>${annualGeneration.toFixed(0)} units</b></div>
            <div class="sf-kpi"><span>25-year generation</span><b>${gen25.toFixed(0)} units</b></div>
            <div class="sf-kpi"><span>₹ saved in 25 years (today's rate)</span><b>${fmt(saved25)}</b></div>
            <div class="sf-kpi"><span>CO₂ avoided annually</span><b>${(co2AnnualKg / 1000).toFixed(2)} ton</b></div>
            <div class="sf-kpi"><span>CO₂ avoided in 25 years</span><b>${(co2TotalKg / 1000).toFixed(2)} ton</b></div>
            <div class="sf-kpi"><span>Equivalent trees</span><b>${trees.toFixed(0)}</b></div>
          </div>

          <div class="sf-disclaimer" style="margin-top:1rem;">Assumptions: indicative estimates only. Actual output and financials depend on roof, shadow, tariff, policy, approvals, and billing structure.</div>
          <div class="sf-cta"><a class="sf-btn alt" href="${buildWhatsAppLink({ systemType, sizeKw, monthlyBill, monthlyUnits, rate, subsidy, loanAmount, marginMoney, interestRate, tenureMonths, roofType: fields.roofType.value, propertyType: fields.propertyType.value, city: fields.city.value, roofArea: fields.roofArea.value, backupNeed: fields.backupNeed.value, systemCost: manualCost || defaultA })}" target="_blank" rel="noopener" style="text-decoration:none;">Request a Quotation</a></div>
        `;
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      function buildWhatsAppLink(data) {
        const lines = [
          'Hello Dakshayani Team, I need a quotation from Solar and Finance page.',
          '',
          `System type: ${data.systemType}`,
          `Solar size: ${Number(data.sizeKw || 0).toFixed(2)} kW`,
          `Monthly bill: ${fmt(data.monthlyBill)}`,
          `Monthly units: ${Number(data.monthlyUnits || 0).toFixed(0)}`,
          `Unit rate: ${fmt(data.rate)}`,
          `Cost (selected): ${fmt(data.systemCost)}`,
          `Subsidy: ${fmt(data.subsidy)}`,
          `Loan amount: ${fmt(data.loanAmount)}`,
          `Margin money: ${fmt(data.marginMoney)}`,
          `Interest rate: ${Number(data.interestRate || 0)}%`,
          `Tenure: ${Number(data.tenureMonths || 0)} months`,
          `Roof type: ${data.roofType || ''}`,
          `Property type: ${data.propertyType || ''}`,
          `City: ${data.city || ''}`,
          `Roof area: ${data.roofArea || ''} sq ft`,
          `Backup needed: ${data.backupNeed || ''}`,
        ];
        return `https://wa.me/917070278178?text=${encodeURIComponent(lines.join('\n'))}`;
      }

      document.getElementById('calcBtn').addEventListener('click', render);
    })();
  </script>
  <script src="/script.js" defer></script>
</body>
</html>
