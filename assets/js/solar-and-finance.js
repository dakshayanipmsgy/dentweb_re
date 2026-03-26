(function(){
  const el = document.getElementById('solarFinanceData');
  if (!el) return;
  const payload = JSON.parse(el.textContent || '{}');
  const settings = payload.settings || {};
  const defaults = settings.defaults || {};
  const onGrid = Array.isArray(settings.on_grid_prices) ? settings.on_grid_prices : [];
  const hybrid = Array.isArray(settings.hybrid_prices) ? settings.hybrid_prices : [];

  const fmtRs = (v)=>`₹${Math.round(Number(v)||0).toLocaleString('en-IN')}`;
  const val = (id)=>Number(document.getElementById(id)?.value || 0);
  const set = (id,v)=>{const n=document.getElementById(id); if(n) n.value = Number.isFinite(v)? (Math.round(v*100)/100):'';};

  const state = { lastTouched: 'monthlyBill', manualSolarSize:false, monthlyChart:null, cumulativeChart:null };

  const educationalCards = [
    ['What is solar rooftop', payload.explainer_source?.what_is_solar_rooftop || ''],
    ['PM Surya Ghar: Muft Bijli Yojana', payload.explainer_source?.pm_surya_ghar_text || ''],
    ['On-grid', payload.explainer_source?.on_grid_text || ''],
    ['Hybrid', payload.explainer_source?.hybrid_text || ''],
    ['Which one suits whom', payload.explainer_source?.which_one_is_suitable_for_whom || ''],
    ['Benefits', payload.explainer_source?.benefits || ''],
    ['Important expectations', payload.explainer_source?.important_expectations || '']
  ];
  (payload.faq||[]).forEach((f)=>educationalCards.push([`FAQ: ${f.q||''}`, f.a||'']));
  const eduGrid = document.getElementById('sfEducationGrid');
  eduGrid.innerHTML = educationalCards.map(([title,body])=>`<article class="sf-card"><h3>${title}</h3><p>${body}</p></article>`).join('');

  set('unitRate', defaults.unit_rate || 8);
  set('dailyGeneration', defaults.daily_generation_per_kw || 5);
  set('interestRate', defaults.interest_rate || 6);
  set('tenureYears', defaults.tenure_years || 10);

  const monthlyBillEl = document.getElementById('monthlyBill');
  const monthlyUnitsEl = document.getElementById('monthlyUnits');
  const solarSizeEl = document.getElementById('solarSize');

  monthlyBillEl?.addEventListener('input',()=>{state.lastTouched='monthlyBill'; syncBillUnits();});
  monthlyUnitsEl?.addEventListener('input',()=>{state.lastTouched='monthlyUnits'; syncBillUnits();});
  solarSizeEl?.addEventListener('input',()=>{state.manualSolarSize=true;});

  function syncBillUnits(){
    const rate = Math.max(val('unitRate'), 0.01);
    const bill = val('monthlyBill');
    const units = val('monthlyUnits');
    if (state.lastTouched === 'monthlyBill' && bill > 0) set('monthlyUnits', bill / rate);
    if (state.lastTouched === 'monthlyUnits' && units > 0) set('monthlyBill', units * rate);
    if (!state.manualSolarSize) {
      const u = val('monthlyUnits');
      const d = Math.max(val('dailyGeneration'),0.1);
      if (u > 0) set('solarSize', u / (d*30));
    }
    updateHybridOptions();
  }

  ['unitRate','dailyGeneration','systemType'].forEach((id)=>document.getElementById(id)?.addEventListener('input', syncBillUnits));

  function pickOnGridPrice(size){
    const sorted = [...onGrid].sort((a,b)=>a.size_kwp-b.size_kwp);
    const row = sorted.find((r)=>Number(r.size_kwp)>=size);
    return row || null;
  }

  function buildHybridChoices(size){
    return hybrid.filter((r)=>Number(r.size_kwp)===size);
  }

  function updateHybridOptions(){
    const size = Math.ceil(val('solarSize'));
    const rows = buildHybridChoices(size);
    const inv = [...new Set(rows.map((r)=>String(r.inverter_kva)))];
    const invSel = document.getElementById('hybridInverter');
    const phSel = document.getElementById('hybridPhase');
    const batSel = document.getElementById('hybridBattery');
    if (!invSel || !phSel || !batSel) return;
    invSel.innerHTML = ['<option value="">Default</option>',...inv.map((v)=>`<option>${v}</option>`)].join('');
    const phase = [...new Set(rows.map((r)=>r.phase))];
    phSel.innerHTML = ['<option value="">Default</option>',...phase.map((v)=>`<option>${v}</option>`)].join('');
    const bat = [...new Set(rows.map((r)=>String(r.battery_count)))];
    batSel.innerHTML = ['<option value="">Default</option>',...bat.map((v)=>`<option>${v}</option>`)].join('');
  }

  ['hybridInverter','hybridPhase','hybridBattery','systemType','solarSize'].forEach((id)=>document.getElementById(id)?.addEventListener('change', updateHybridOptions));

  function emi(principal, annual, years){
    const p = Math.max(principal,0), n=Math.max(years*12,1), r=Math.max(annual,0)/1200;
    if (r===0) return p/n;
    const k=Math.pow(1+r,n);
    return (p*r*k)/(k-1);
  }

  document.getElementById('solarFinanceForm')?.addEventListener('submit',(e)=>{
    e.preventDefault();
    syncBillUnits();
    const bill = val('monthlyBill');
    const units = val('monthlyUnits');
    if (bill<=0 && units<=0) return alert('Please enter at least bill or units.');

    const solarSize = val('solarSize');
    const type = document.getElementById('systemType').value;
    const daily = val('dailyGeneration');
    const rate = val('unitRate');
    const subsidy = val('subsidy');
    const interest = val('interestRate');
    const years = val('tenureYears');

    const priceBasis = Math.ceil(solarSize);
    let systemCost1 = 0, systemCost2 = 0, pricingNote = '';

    if (type === 'On-grid') {
      const row = pickOnGridPrice(priceBasis);
      if (!row) pricingNote = 'No price row available for this size yet.';
      systemCost1 = Number(row?.loan_upto_2_lakh || 0);
      systemCost2 = Number(row?.loan_above_2_lakh || 0);
    } else {
      const rows = buildHybridChoices(priceBasis);
      const inv = document.getElementById('hybridInverter').value;
      const ph = document.getElementById('hybridPhase').value;
      const bat = document.getElementById('hybridBattery').value;
      let row = rows[0] || null;
      if (inv || ph || bat) {
        row = rows.find((r)=>String(r.inverter_kva)===inv && r.phase===ph && String(r.battery_count)===bat) || null;
        if (!row) pricingNote = 'No hybrid price combination found for this exact selection.';
      }
      systemCost1 = Number(row?.loan_upto_2_lakh || 0);
      systemCost2 = Number(row?.loan_above_2_lakh || 0);
    }

    if (!val('systemCost')) set('systemCost', systemCost2 || systemCost1);

    const systemCost = val('systemCost') || systemCost2 || systemCost1;
    const loan1 = Math.min(systemCost1 || systemCost, 200000);
    const margin1 = (systemCost1 || systemCost) - loan1;
    const marginDefault = defaults.default_margin_above_2_lakh || 50000;
    const autoLoan2 = Math.max((systemCost2 || systemCost) - marginDefault, 0);

    if (!val('loanAmount')) set('loanAmount', autoLoan2);
    if (!val('marginMoney')) set('marginMoney', marginDefault);

    const loan2 = val('loanAmount');
    const margin2 = val('marginMoney');

    const monthlySolarGen = solarSize * daily * 30;
    const monthlySolarValue = monthlySolarGen * rate;
    const residualBill = Math.max(bill - monthlySolarValue, 0);

    const effective1 = Math.max(loan1 - subsidy, 0);
    const effective2 = Math.max(loan2 - subsidy, 0);
    const emi1 = emi(effective1, interest, years);
    const emi2 = emi(effective2, interest, years);

    const outflows = [bill, emi1 + residualBill, emi2 + residualBill, residualBill];

    const years25 = Array.from({length:26}, (_,i)=>i);
    const cumulative = {
      noSolar: years25.map((y)=>bill*12*y),
      loan1: years25.map((y)=> (Math.min(y,years)*(emi1*12+residualBill*12)) + Math.max(y-years,0)*(residualBill*12)),
      loan2: years25.map((y)=> (Math.min(y,years)*(emi2*12+residualBill*12)) + Math.max(y-years,0)*(residualBill*12)),
      self: years25.map((y)=> (systemCost - subsidy) + (residualBill*12*y))
    };

    const paybackNoLoan = (systemCost - subsidy) / Math.max((bill-residualBill),1);
    const paybackLoan1 = Math.max((margin1) / Math.max((bill-(emi1+residualBill)),1), 0);
    const paybackLoan2 = Math.max((margin2) / Math.max((bill-(emi2+residualBill)),1), 0);

    document.getElementById('resultsSection').hidden = false;
    document.getElementById('hybridMessage').textContent = pricingNote;
    document.getElementById('recommendationSummary').innerHTML = `
      <strong>Recommended:</strong> ${solarSize.toFixed(2)} kWp (pricing basis ${priceBasis} kWp) ·
      Roof area ~ ${(solarSize * (defaults.roof_area_sqft_per_kw || 100)).toFixed(0)} sqft ·
      Monthly generation ~ ${monthlySolarGen.toFixed(0)} units ·
      Bill offset ~ ${Math.min((monthlySolarValue/Math.max(bill,1))*100,100).toFixed(1)}%
    `;

    drawMonthly(outflows);
    drawCumulative(years25, cumulative);

    document.getElementById('paybackGrid').innerHTML = [
      ['Payback (Loan up to ₹2L)', `${paybackLoan1.toFixed(1)} months`],
      ['Payback (Loan above ₹2L)', `${paybackLoan2.toFixed(1)} months`],
      ['Payback (Self Funded)', `${paybackNoLoan.toFixed(1)} months`]
    ].map((r)=>`<div class="sf-card"><h4>${r[0]}</h4><p>${r[1]}</p></div>`).join('');

    document.getElementById('clarityGrid').innerHTML = [
      ['No Solar', `Monthly ${fmtRs(bill)} · 25Y ${fmtRs(cumulative.noSolar[25])}`],
      ['Loan up to ₹2 lacs', `Cost ${fmtRs(systemCost1||systemCost)} · Subsidy ${fmtRs(subsidy)} · Loan ${fmtRs(loan1)} · Margin ${fmtRs(margin1)} · EMI ${fmtRs(emi1)} · Residual ${fmtRs(residualBill)}`],
      ['Loan above ₹2 lacs', `Cost ${fmtRs(systemCost2||systemCost)} · Subsidy ${fmtRs(subsidy)} · Loan ${fmtRs(loan2)} · Margin ${fmtRs(margin2)} · EMI ${fmtRs(emi2)} · Residual ${fmtRs(residualBill)}`],
      ['Self funded', `System ${fmtRs(systemCost)} · Net ${(fmtRs(systemCost-subsidy))} · Annual saving ${fmtRs((bill-residualBill)*12)}`]
    ].map((r)=>`<div class="sf-card"><h4>${r[0]}</h4><p>${r[1]}</p></div>`).join('');

    const annualGen = monthlySolarGen*12;
    const gen25 = annualGen*25;
    document.getElementById('generationGrid').innerHTML = [
      ['Monthly generation', `${monthlySolarGen.toFixed(0)} units`],
      ['Annual / 25-year', `${annualGen.toFixed(0)} / ${gen25.toFixed(0)} units`],
      ['Solar value & savings', `${fmtRs(annualGen*rate)} per year · ${fmtRs(gen25*rate)} in 25 years`]
    ].map((r)=>`<div class="sf-card"><h4>${r[0]}</h4><p>${r[1]}</p></div>`).join('');

    const co2Annual = annualGen * (defaults.co2_emission_factor || 0.82);
    const co225 = co2Annual * 25;
    const trees = co2Annual / Math.max((defaults.co2_per_tree || 20),0.1);
    document.getElementById('greenGrid').innerHTML = [
      ['Annual CO₂ avoided', `${co2Annual.toFixed(1)} kg`],
      ['25-year CO₂ avoided', `${co225.toFixed(1)} kg`],
      ['Equivalent trees', `${trees.toFixed(1)} trees/year`]
    ].map((r)=>`<div class="sf-card"><h4>${r[0]}</h4><p>${r[1]}</p></div>`).join('');

    document.getElementById('waQuoteButton').onclick = ()=>{
      const lines = [
        'Hello Dakshayani team,',
        'I checked Solar and Finance on your website.',
        `System type: ${type}`,
        `Monthly bill: ${fmtRs(bill)}`,
        `Monthly units: ${units.toFixed(1)}`,
        `Solar size: ${solarSize.toFixed(2)} kWp`,
        `System cost: ${fmtRs(systemCost)}`,
        `Subsidy: ${fmtRs(subsidy)}`,
        `Loan amount: ${fmtRs(loan2)}`,
        `Margin money: ${fmtRs(margin2)}`,
        `Interest rate: ${interest}%`,
        `Tenure: ${years} years`,
        `Bill offset: ${Math.min((monthlySolarValue/Math.max(bill,1))*100,100).toFixed(1)}%`,
        'Please share a quotation for this requirement.'
      ];
      window.open(`https://wa.me/917070278178?text=${encodeURIComponent(lines.join('\n'))}`,'_blank');
    };
  });

  function drawMonthly(values){
    const ctx = document.getElementById('monthlyOutflowChart');
    if (!ctx || !window.Chart) return;
    if (state.monthlyChart) state.monthlyChart.destroy();
    state.monthlyChart = new Chart(ctx, { type:'bar', data:{ labels:['No Solar','Loan ≤₹2L','Loan >₹2L','Self Funded'], datasets:[{ data: values, backgroundColor:['#475569','#0ea5e9','#1d4ed8','#059669'] }] }, options:{ responsive:true, plugins:{ legend:{display:false} } } });
  }
  function drawCumulative(labels, ds){
    const ctx = document.getElementById('cumulativeChart');
    if (!ctx || !window.Chart) return;
    if (state.cumulativeChart) state.cumulativeChart.destroy();
    state.cumulativeChart = new Chart(ctx, { type:'line', data:{ labels, datasets:[
      {label:'No Solar', data:ds.noSolar, borderColor:'#475569'},
      {label:'Loan ≤₹2L', data:ds.loan1, borderColor:'#0ea5e9'},
      {label:'Loan >₹2L', data:ds.loan2, borderColor:'#1d4ed8'},
      {label:'Self Funded', data:ds.self, borderColor:'#059669'}
    ] }, options:{ responsive:true } });
  }
})();
