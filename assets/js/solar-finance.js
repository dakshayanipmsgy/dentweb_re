(function () {
  const settings = window.solarFinanceSettings || {};
  const defaults = settings.defaults || {};
  const onGrid = Array.isArray(settings.on_grid_prices) ? settings.on_grid_prices : [];
  const hybrid = Array.isArray(settings.hybrid_prices) ? settings.hybrid_prices : [];
  const el = (id) => document.getElementById(id);
  const monthlyBill = el('monthlyBill');
  const monthlyUnits = el('monthlyUnits');
  const systemType = el('systemType');
  const unitRate = el('unitRate');
  const solarSize = el('solarSize');
  const dailyGen = el('dailyGen');
  const systemCost = el('systemCost');
  const subsidy = el('subsidy');
  const loanAmount = el('loanAmount');
  const marginMoney = el('marginMoney');
  const interestRate = el('interestRate');
  const tenureYears = el('tenureYears');
  const note = el('calcNote');
  const kvaSel = el('hybridKva');
  const phaseSel = el('hybridPhase');
  const batSel = el('hybridBattery');

  let lastTouched = 'bill';

  const inr = (v) => `₹${Math.round(v || 0).toLocaleString('en-IN')}`;
  const num = (v) => Number(v || 0);

  function setDefaults() {
    unitRate.value = defaults.unit_rate || 8;
    dailyGen.value = defaults.daily_generation_per_kw || 5;
    interestRate.value = defaults.interest_rate || 6;
    tenureYears.value = defaults.tenure_years || 10;
    subsidy.value = 0;
    seedHybridOptions();
  }

  function seedHybridOptions() {
    const size = Math.ceil(num(solarSize.value || 0));
    const rows = hybrid.filter((r) => Number(r.size_kw) === size);
    const uniq = (arr) => [...new Set(arr)];
    const kvas = uniq(rows.map((r) => String(r.kva)));
    kvaSel.innerHTML = '<option value="">Default</option>' + kvas.map((v) => `<option value="${v}">${v} kVA</option>`).join('');
    const phases = uniq(rows.map((r) => String(r.phase)));
    phaseSel.innerHTML = '<option value="">Default</option>' + phases.map((v) => `<option value="${v}">${v}</option>`).join('');
    const bats = uniq(rows.map((r) => String(r.batteries)));
    batSel.innerHTML = '<option value="">Default</option>' + bats.map((v) => `<option value="${v}">${v}</option>`).join('');
  }

  function emi(principal, annualRate, years) {
    const r = annualRate / 1200;
    const n = Math.max(1, Math.round(years * 12));
    if (r <= 0) return principal / n;
    return principal * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
  }

  function choosePrice(size) {
    const ceilSize = Math.ceil(size);
    if (systemType.value === 'On-grid') {
      const row = onGrid.find((r) => Number(r.size_kw) === ceilSize);
      return row ? { row, pricingBasis: ceilSize } : { row: null, pricingBasis: ceilSize };
    }
    const rows = hybrid.filter((r) => Number(r.size_kw) === ceilSize);
    if (!rows.length) return { row: null, pricingBasis: ceilSize };
    const k = kvaSel.value;
    const p = phaseSel.value;
    const b = batSel.value;
    if (k && p && b) {
      const exact = rows.find((r) => String(r.kva) === k && String(r.phase) === p && String(r.batteries) === b);
      return { row: exact || null, pricingBasis: ceilSize, exactAttempted: true };
    }
    return { row: rows[0], pricingBasis: ceilSize, exactAttempted: false };
  }

  function autofill() {
    const rate = Math.max(0.01, num(unitRate.value) || 8);
    if (lastTouched === 'bill' && num(monthlyBill.value) > 0) {
      monthlyUnits.value = (num(monthlyBill.value) / rate).toFixed(2);
    } else if (lastTouched === 'units' && num(monthlyUnits.value) > 0) {
      monthlyBill.value = (num(monthlyUnits.value) * rate).toFixed(2);
    }

    if (num(monthlyUnits.value) > 0) {
      const rec = num(monthlyUnits.value) / (Math.max(0.01, num(dailyGen.value)) * 30);
      if (!solarSize.dataset.manual || solarSize.dataset.manual !== '1') {
        solarSize.value = rec.toFixed(2);
      }
    }
    seedHybridOptions();

    const picked = choosePrice(num(solarSize.value));
    if (!picked.row) {
      note.textContent = picked.exactAttempted ? 'No hybrid price combination found for this exact selection.' : 'No pricing row available for this size. Please adjust size.';
      return;
    }
    note.textContent = `Exact recommended size: ${num(solarSize.value).toFixed(2)} kWp. Pricing basis size: ${picked.pricingBasis} kWp.`;
    const cost = num(picked.row.loan_upto_2_lakh);
    systemCost.value = Math.round(cost);
    if (num(loanAmount.value) === 0) {
      loanAmount.value = Math.round(Math.min(cost, 200000));
      marginMoney.value = Math.round(cost - num(loanAmount.value));
    }
  }

  function run() {
    autofill();
    const bill = num(monthlyBill.value);
    const units = num(monthlyUnits.value);
    const size = num(solarSize.value);
    const genDay = num(dailyGen.value);
    const rate = num(unitRate.value);
    const subsidyAmt = num(subsidy.value);
    const interest = num(interestRate.value);
    const tenure = num(tenureYears.value);
    const roofFactor = num(defaults.roof_area_sqft_per_kw) || 100;
    const annualGen = size * genDay * 30 * 12;
    const monthGen = size * genDay * 30;
    const residual = Math.max(bill - monthGen * rate, 0);
    const offset = bill > 0 ? Math.min(100, (monthGen * rate / bill) * 100) : 0;

    const costUpto = num(systemCost.value);
    const loanUpto = num(loanAmount.value) || Math.min(costUpto, 200000);
    const marginUpto = num(marginMoney.value) || Math.max(0, costUpto - loanUpto);
    const principalUpto = Math.max(0, loanUpto - subsidyAmt);
    const emiUpto = emi(principalUpto, interest, tenure);

    const picked = choosePrice(size);
    const costAbove = num(picked.row ? picked.row.loan_above_2_lakh : costUpto);
    const loanAbove = Math.max(0, costAbove - marginUpto);
    const principalAbove = Math.max(0, loanAbove - subsidyAmt);
    const emiAbove = emi(principalAbove, interest, tenure);

    const monthlyScenarios = {
      noSolar: bill,
      loanUpto: emiUpto + residual,
      loanAbove: emiAbove + residual,
      selfFunded: residual,
    };

    const years = 25;
    const months = years * 12;
    const series = { noSolar: [], loanUpto: [], loanAbove: [], selfFunded: [] };
    let cNo = 0; let cU = 0; let cA = 0; let cS = Math.max(0, costUpto - subsidyAmt);
    for (let m = 1; m <= months; m += 1) {
      cNo += bill;
      cU += residual + (m <= tenure * 12 ? emiUpto : 0);
      cA += residual + (m <= tenure * 12 ? emiAbove : 0);
      cS += residual;
      if (m % 12 === 0) {
        series.noSolar.push(cNo);
        series.loanUpto.push(cU + marginUpto);
        series.loanAbove.push(cA + marginUpto);
        series.selfFunded.push(cS);
      }
    }

    renderResults({ bill, units, size, roofArea: size * roofFactor, monthGen, annualGen, offset, subsidyAmt, rate, residual, costUpto, loanUpto, marginUpto, principalUpto, emiUpto, costAbove, loanAbove, principalAbove, emiAbove, monthlyScenarios, series });
  }

  function renderResults(d) {
    el('results').hidden = false;
    el('recommendationSummary').innerHTML = `<div class="sf-card"><strong>Recommended size:</strong> ${d.size.toFixed(2)} kWp · <strong>Roof area:</strong> ${Math.round(d.roofArea)} sq.ft · <strong>Monthly generation:</strong> ${Math.round(d.monthGen)} units · <strong>Bill offset:</strong> ${d.offset.toFixed(1)}%</div>`;

    const bars = [
      ['No Solar', d.monthlyScenarios.noSolar],
      ['Loan ≤ ₹2L', d.monthlyScenarios.loanUpto],
      ['Loan > ₹2L', d.monthlyScenarios.loanAbove],
      ['Self Funded', d.monthlyScenarios.selfFunded],
    ];
    const maxV = Math.max(...bars.map((b) => b[1]), 1);
    el('outflowBars').innerHTML = bars.map(([l, v]) => `<div class="sf-bar"><div class="sf-bar-col" style="height:${(v / maxV) * 100}%"></div><small>${l}<br>${inr(v)}</small></div>`).join('');

    drawLineChart(el('cumChart'), d.series);
    const paybackU = paybackMonths(d.costUpto + d.marginUpto - d.subsidyAmt, d.bill - d.monthlyScenarios.loanUpto);
    const paybackA = paybackMonths(d.costAbove + d.marginUpto - d.subsidyAmt, d.bill - d.monthlyScenarios.loanAbove);
    const paybackS = paybackMonths((d.costUpto - d.subsidyAmt), d.bill - d.monthlyScenarios.selfFunded);
    el('paybackMeters').innerHTML = `<h3>Payback Meters</h3>${meter('Loan ≤ ₹2L', paybackU)}${meter('Loan > ₹2L', paybackA)}${meter('Self Funded', paybackS)}`;

    el('financialClarity').innerHTML = `<h3>Financial Clarity</h3><p><strong>No Solar:</strong> ${inr(d.bill)}/month · 25y ${inr(d.series.noSolar[24])}</p><p><strong>Loan ≤ ₹2L:</strong> Cost ${inr(d.costUpto)}, Subsidy ${inr(d.subsidyAmt)}, Loan ${inr(d.loanUpto)}, Margin ${inr(d.marginUpto)}, Effective Principal ${inr(d.principalUpto)}, EMI ${inr(d.emiUpto)}, Residual ${inr(d.residual)}</p><p><strong>Loan > ₹2L:</strong> Cost ${inr(d.costAbove)}, Loan ${inr(d.loanAbove)}, Effective Principal ${inr(d.principalAbove)}, EMI ${inr(d.emiAbove)}</p><p><strong>Self funded:</strong> Net investment ${inr(d.costUpto - d.subsidyAmt)}</p>`;
    const saveAnnual = d.annualGen * d.rate;
    el('generationEstimate').innerHTML = `<h3>Generation Estimate</h3><p>Monthly: ${Math.round(d.monthGen)} units</p><p>Annual: ${Math.round(d.annualGen)} units</p><p>25-year units: ${Math.round(d.annualGen * 25)}</p><p>Annual solar value: ${inr(saveAnnual)}</p><p>25-year value: ${inr(saveAnnual * 25)}</p><p>Estimated bill offset: ${d.offset.toFixed(1)}%</p>`;
    const emission = num(defaults.emission_factor) || 0.82;
    const treeFactor = num(defaults.co2_per_tree) || 21;
    const co2Year = d.annualGen * emission;
    el('greenImpact').innerHTML = `<h3>Green Impact</h3><p>Annual CO₂ avoided: ${Math.round(co2Year)} kg</p><p>25-year CO₂ avoided: ${Math.round(co2Year * 25)} kg</p><p>Equivalent trees planted: ${Math.round((co2Year * 25) / treeFactor)}</p>`;

    el('quoteWhatsApp').onclick = () => {
      const msg = [
        'Hello Dakshayani team,',
        'I checked Solar and Finance on your website.',
        `System type: ${systemType.value}`,
        `Monthly bill: ${inr(d.bill)}`,
        `Monthly units: ${d.units}`,
        `Solar size: ${d.size.toFixed(2)} kWp`,
        `System cost: ${inr(d.costUpto)}`,
        `Subsidy: ${inr(d.subsidyAmt)}`,
        `Loan amount: ${inr(d.loanUpto)}`,
        `Margin money: ${inr(d.marginUpto)}`,
        `Interest: ${interestRate.value}% | Tenure: ${tenureYears.value} years`,
        `Bill offset: ${d.offset.toFixed(1)}%`,
        'Please share a quotation for this requirement.'
      ].join('\n');
      const url = `https://wa.me/917070278178?text=${encodeURIComponent(msg)}`;
      window.open(url, '_blank') || (window.location.href = url);
    };
  }

  function paybackMonths(invest, monthSaving) {
    if (monthSaving <= 0) return Infinity;
    return invest / monthSaving;
  }

  function meter(label, months) {
    const years = months === Infinity ? 'Not in range' : `${(months / 12).toFixed(1)} years`;
    const pct = months === Infinity ? 100 : Math.min(100, (months / (25 * 12)) * 100);
    return `<div class="sf-meter"><div class="sf-meter-label">${label}: ${years}</div><div class="sf-meter-track"><div class="sf-meter-fill" style="width:${pct}%"></div></div></div>`;
  }

  function drawLineChart(canvas, series) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width; const h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    const keys = Object.keys(series);
    const max = Math.max(...keys.flatMap((k) => series[k]));
    const colors = { noSolar: '#111827', loanUpto: '#2563eb', loanAbove: '#7c3aed', selfFunded: '#059669' };
    keys.forEach((k) => {
      ctx.beginPath(); ctx.strokeStyle = colors[k]; ctx.lineWidth = 2;
      series[k].forEach((v, i) => {
        const x = 40 + (i / 24) * (w - 60);
        const y = h - 30 - ((v / max) * (h - 60));
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.stroke();
    });
  }

  [monthlyBill, monthlyUnits].forEach((i) => i.addEventListener('input', () => {
    lastTouched = i === monthlyBill ? 'bill' : 'units';
    solarSize.dataset.manual = '';
    autofill();
  }));
  [unitRate, dailyGen, systemType, kvaSel, phaseSel, batSel].forEach((i) => i.addEventListener('input', autofill));
  solarSize.addEventListener('input', () => { solarSize.dataset.manual = '1'; autofill(); });
  el('runCalc').addEventListener('click', run);

  setDefaults();
  autofill();
}());
