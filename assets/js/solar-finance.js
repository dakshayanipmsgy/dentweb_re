(function () {
  const settingsNode = document.getElementById('solar-finance-settings');
  if (!settingsNode) return;
  const settings = JSON.parse(settingsNode.textContent || '{}');
  const defaults = settings.defaults || {};
  const onGrid = (settings.on_grid_prices || []).slice().sort((a,b)=>a.size_kwp-b.size_kwp);
  const hybrid = (settings.hybrid_prices || []).slice().sort((a,b)=>a.size_kwp-b.size_kwp);
  const fmtRs = (v) => `₹${Math.round(Number(v||0)).toLocaleString('en-IN')}`;

  const el = (id) => document.getElementById(id);
  const bill = el('monthlyBill'), units = el('monthlyUnits'), type = el('systemType');
  const solarSize = el('solarSize'), dailyGen = el('dailyGeneration'), unitRate = el('unitRate');
  const subsidy = el('subsidy'), interest = el('interestRate'), tenure = el('tenureYears');
  const loan = el('loanAmount'), margin = el('marginMoney');
  const msg = el('calcMessage');
  let lastEdited = '';
  let monthlyChart, cumulativeChart;

  unitRate.value = defaults.unit_rate ?? 8;
  dailyGen.value = defaults.daily_generation_per_kw ?? 5;
  interest.value = defaults.interest_rate ?? 6;
  tenure.value = defaults.loan_tenure_years ?? 10;

  function renderExplainers() {
    const wrap = el('sf-explainer-grid');
    wrap.innerHTML = (settings.explainer_blocks || []).map((b) => `<article class="sf-card"><h3><i class="fa-solid ${b.icon || 'fa-circle-info'}"></i> ${b.title || ''}</h3><p class="sf-muted">${b.body || ''}</p></article>`).join('');
  }

  function billUnitsSync(source) {
    const rate = Number(unitRate.value) || Number(defaults.unit_rate) || 8;
    if (source === 'bill' && bill.value !== '') units.value = (Number(bill.value) / rate).toFixed(2);
    if (source === 'units' && units.value !== '') bill.value = (Number(units.value) * rate).toFixed(0);
  }

  function fillHybridOptions() {
    const size = Math.ceil(Number(solarSize.value || 0));
    const rows = hybrid.filter(r => Number(r.size_kwp) === size);
    const kvaOpt = [...new Set(rows.map(r => String(r.inverter_kva)))];
    el('hybridKva').innerHTML = '<option value="">Auto</option>' + kvaOpt.map(v=>`<option>${v}</option>`).join('');
    const phaseOpt = [...new Set(rows.map(r => r.phase))];
    el('hybridPhase').innerHTML = '<option value="">Auto</option>' + phaseOpt.map(v=>`<option>${v}</option>`).join('');
    const batOpt = [...new Set(rows.map(r => String(r.battery_count)))];
    el('hybridBattery').innerHTML = '<option value="">Auto</option>' + batOpt.map(v=>`<option>${v}</option>`).join('');
  }

  function emi(principal, annualRate, years) {
    const n = Math.max(1, Math.round(Number(years) * 12));
    const r = (Number(annualRate) / 12) / 100;
    if (r <= 0) return principal / n;
    return (principal * r * Math.pow(1 + r, n)) / (Math.pow(1 + r, n) - 1);
  }

  function findPrice(sizeRaw) {
    const size = Math.ceil(sizeRaw);
    if (type.value === 'hybrid') {
      const rows = hybrid.filter(r => Number(r.size_kwp) === size);
      if (!rows.length) return null;
      const k = el('hybridKva').value, p = el('hybridPhase').value, b = el('hybridBattery').value;
      if (k && p && b) {
        const exact = rows.find(r => String(r.inverter_kva)===k && r.phase===p && String(r.battery_count)===b);
        if (!exact) return { error: 'No hybrid price combination found for this exact selection.' };
        return { basisSize: size, row: exact };
      }
      return { basisSize: size, row: rows[0] };
    }
    const row = onGrid.find(r => Number(r.size_kwp) >= size);
    if (!row) return null;
    return { basisSize: Number(row.size_kwp), row };
  }

  function run() {
    msg.style.display = 'none';
    if (!bill.value && !units.value) {
      msg.textContent = 'Please enter monthly bill or monthly units.'; msg.style.display = 'block'; return;
    }
    if (lastEdited === 'bill') billUnitsSync('bill');
    if (lastEdited === 'units') billUnitsSync('units');

    const monthlyUnits = Number(units.value || 0);
    const monthlyBill = Number(bill.value || 0);
    const dg = Number(dailyGen.value || 5);
    const ur = Number(unitRate.value || 8);

    if (!solarSize.dataset.manual || solarSize.dataset.manual === '0') {
      solarSize.value = (monthlyUnits / (dg * 30)).toFixed(2);
    }
    const sSize = Number(solarSize.value || 0);
    fillHybridOptions();
    const price = findPrice(sSize);
    if (!price || !price.row) { msg.textContent = 'Pricing not available for this size.'; msg.style.display='block'; return; }
    if (price.error) { msg.textContent = price.error; msg.style.display='block'; return; }

    const cost1 = Number(price.row.price_upto_2_lacs || 0);
    const cost2 = Number(price.row.price_above_2_lacs || cost1);
    if (!loan.value) loan.value = Math.min(cost1, 200000);
    if (!margin.value) margin.value = Math.max(cost1 - Number(loan.value), 0);

    const monthlySolarGeneration = sSize * dg * 30;
    const monthlySolarValue = monthlySolarGeneration * ur;
    const residual = Math.max(monthlyBill - monthlySolarValue, 0);
    const sub = Number(subsidy.value || 0);

    const loan1 = Math.min(cost1, Number(loan.value || 0) || 200000);
    const margin1 = Math.max(cost1 - loan1, 0);
    const loan2 = Math.max(Number(loan.value || (cost2 - Number(margin.value||0))), 0);
    const margin2 = Math.max(Number(margin.value || (cost2 - loan2)), 0);

    const emi1 = emi(Math.max(loan1 - sub,0), Number(interest.value||6), Number(tenure.value||10));
    const emi2 = emi(Math.max(loan2 - sub,0), Number(interest.value||6), Number(tenure.value||10));

    const monthlyOutflows = [monthlyBill, emi1+residual, emi2+residual, residual];
    const years = [...Array(26).keys()];
    const monthsLoan = Math.round(Number(tenure.value||10)*12);
    function cum(mode){
      let upfront=0, monthlyBase=0, emiAmt=0;
      if(mode==='nosolar'){monthlyBase=monthlyBill;}
      if(mode==='loan1'){monthlyBase=residual;emiAmt=emi1;}
      if(mode==='loan2'){monthlyBase=residual;emiAmt=emi2;}
      if(mode==='self'){upfront=Math.max(cost2-sub,0);monthlyBase=residual;}
      return years.map(y=>{
        const m=y*12; const emiPaid=Math.min(m, monthsLoan)*emiAmt; return upfront + (m*monthlyBase) + emiPaid;
      });
    }

    const roof = sSize * (Number(defaults.roof_area_sqft_per_kw)||100);
    const offset = monthlyBill > 0 ? Math.min((monthlySolarValue / monthlyBill) * 100, 100) : 0;

    el('sumSize').textContent = `${sSize.toFixed(2)} kWp`;
    el('sumRoof').textContent = `${roof.toFixed(0)} sq.ft`;
    el('sumMonthGen').textContent = `${monthlySolarGeneration.toFixed(0)} units`;
    el('sumOffset').textContent = `${offset.toFixed(1)}%`;
    el('pricingBasisNote').textContent = `Recommended size: ${sSize.toFixed(2)} kWp | Pricing basis: ${price.basisSize} kWp`;

    const clarity = el('financialClarity');
    clarity.innerHTML = `
      <table class="sf-table"><tr><td>No Solar monthly bill</td><td>${fmtRs(monthlyBill)}</td></tr><tr><td>No Solar 25-year expense</td><td>${fmtRs(monthlyBill*12*25)}</td></tr></table>
      <h4>Loan upto ₹2 lacs</h4><table class="sf-table"><tr><td>System cost</td><td>${fmtRs(cost1)}</td></tr><tr><td>Subsidy</td><td>${fmtRs(sub)}</td></tr><tr><td>Loan amount</td><td>${fmtRs(loan1)}</td></tr><tr><td>Margin money</td><td>${fmtRs(margin1)}</td></tr><tr><td>Loan amount minus subsidy</td><td>${fmtRs(Math.max(loan1-sub,0))}</td></tr><tr><td>EMI</td><td>${fmtRs(emi1)}</td></tr><tr><td>Residual bill</td><td>${fmtRs(residual)}</td></tr></table>
      <h4>Loan above ₹2 lacs</h4><table class="sf-table"><tr><td>System cost</td><td>${fmtRs(cost2)}</td></tr><tr><td>Loan amount</td><td>${fmtRs(loan2)}</td></tr><tr><td>Margin money</td><td>${fmtRs(margin2)}</td></tr><tr><td>EMI</td><td>${fmtRs(emi2)}</td></tr></table>
      <h4>Self Funded</h4><table class="sf-table"><tr><td>Net investment after subsidy</td><td>${fmtRs(Math.max(cost2-sub,0))}</td></tr><tr><td>Residual bill</td><td>${fmtRs(residual)}</td></tr></table>`;

    const annualGen = monthlySolarGeneration*12;
    const gen25 = annualGen*25;
    const annualValue = annualGen*ur;
    const save25 = gen25*ur;
    const co2Year = annualGen*(Number(defaults.emission_factor_kg_per_kwh)||0.82);
    const co2_25 = co2Year*25;
    const trees = co2Year/(Number(defaults.co2_per_tree_kg_per_year)||21);
    el('generationImpact').innerHTML = `<table class="sf-table"><tr><td>Expected monthly generation</td><td>${monthlySolarGeneration.toFixed(0)} units</td></tr><tr><td>Expected annual generation</td><td>${annualGen.toFixed(0)} units</td></tr><tr><td>Units in 25 years</td><td>${gen25.toFixed(0)} units</td></tr><tr><td>Estimated annual solar value</td><td>${fmtRs(annualValue)}</td></tr><tr><td>₹ saved in 25 years</td><td>${fmtRs(save25)}</td></tr><tr><td>Annual CO₂ avoided</td><td>${co2Year.toFixed(0)} kg</td></tr><tr><td>25-year CO₂ avoided</td><td>${co2_25.toFixed(0)} kg</td></tr><tr><td>Equivalent trees planted</td><td>${trees.toFixed(0)} / year</td></tr></table>`;

    const payback = (invest, monthOut) => {
      const monthlySave = monthlyBill - monthOut;
      if (monthlySave <= 0) return 'Not reached';
      return `${(invest / (monthlySave*12)).toFixed(1)} years`;
    };
    el('paybackMeters').innerHTML = `
      <div class='sf-card'><div class='sf-muted'>Loan upto ₹2 lacs</div><div class='sf-summary-kpi'>${payback(Math.max(margin1-sub,0), emi1+residual)}</div></div>
      <div class='sf-card'><div class='sf-muted'>Loan above ₹2 lacs</div><div class='sf-summary-kpi'>${payback(Math.max(margin2-sub,0), emi2+residual)}</div></div>
      <div class='sf-card'><div class='sf-muted'>Self funded</div><div class='sf-summary-kpi'>${payback(Math.max(cost2-sub,0), residual)}</div></div>`;

    if (monthlyChart) monthlyChart.destroy();
    monthlyChart = new Chart(el('monthlyChart'), {type:'bar', data:{labels:['No Solar','Loan ≤₹2L','Loan >₹2L','Self Funded'], datasets:[{data:monthlyOutflows, backgroundColor:['#334155','#0ea5e9','#8b5cf6','#16a34a']}]}, options:{responsive:true, plugins:{legend:{display:false}}}});
    if (cumulativeChart) cumulativeChart.destroy();
    cumulativeChart = new Chart(el('cumulativeChart'), {type:'line', data:{labels:years.map(y=>`${y}y`), datasets:[{label:'No Solar',data:cum('nosolar')},{label:'Loan ≤₹2L',data:cum('loan1')},{label:'Loan >₹2L',data:cum('loan2')},{label:'Self Funded',data:cum('self')}]}, options:{responsive:true}});

    const waText = encodeURIComponent(`Hello Dakshayani team,\nI checked Solar and Finance on your website.\nSystem type: ${type.value}\nAverage monthly bill: ${fmtRs(monthlyBill)}\nAverage monthly units: ${monthlyUnits.toFixed(2)}\nSolar size: ${sSize.toFixed(2)} kWp\nSystem cost: ${fmtRs(cost2)}\nSubsidy: ${fmtRs(sub)}\nLoan amount: ${fmtRs(loan2)}\nMargin money: ${fmtRs(margin2)}\nInterest rate: ${interest.value}%\nTenure: ${tenure.value} years\nBill offset: ${offset.toFixed(1)}%\nPlease share a quotation for this requirement.`);
    el('waQuote').href = `https://wa.me/917070278178?text=${waText}`;

    el('results').classList.add('show');
  }

  ['input','change'].forEach(evt=>{
    bill.addEventListener(evt,()=>{lastEdited='bill'; solarSize.dataset.manual='0'; billUnitsSync('bill');});
    units.addEventListener(evt,()=>{lastEdited='units'; solarSize.dataset.manual='0'; billUnitsSync('units');});
  });
  solarSize.addEventListener('input',()=>{solarSize.dataset.manual='1'; fillHybridOptions();});
  unitRate.addEventListener('input',()=>{if(lastEdited==='bill') billUnitsSync('bill'); if(lastEdited==='units') billUnitsSync('units');});
  type.addEventListener('change',()=>{el('hybridSelectors').style.display = type.value === 'hybrid' ? 'grid' : 'none'; run();});
  el('toggleAdvanced').addEventListener('click',()=>el('advancedBox').classList.toggle('show'));
  el('runCalc').addEventListener('click', run);

  renderExplainers();
})();
