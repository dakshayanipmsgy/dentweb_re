<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$settings = solar_finance_settings();
$content = $settings['content'] ?? [];
$defaults = $settings['defaults'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars((string) ($content['page_title'] ?? 'Solar and Finance')) ?> | Dakshayani Enterprises</title>
  <link rel="icon" href="/images/favicon.ico" />
  <link rel="stylesheet" href="/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .sf-wrap{width:100%;max-width:none;padding:2rem 2rem 4rem;background:#f8fbff}.sf-hero,.sf-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;box-shadow:0 10px 28px rgba(2,6,23,.06)}
    .sf-hero{padding:2rem;margin-bottom:1.5rem}.sf-grid{display:grid;gap:1rem}.sf-grid.cards{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:1.8rem}
    .sf-card{padding:1.1rem}.sf-card h3{margin:.4rem 0}.sf-flex{display:grid;grid-template-columns:1.3fr 1fr;gap:1rem}.sf-pro{margin-top:1rem}
    .sf-inputs{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.8rem}.sf-inputs label{display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.92rem}
    .sf-inputs input,.sf-inputs select{padding:.55rem .65rem;border:1px solid #c6d1e2;border-radius:10px}.sf-results{margin-top:1.2rem}.sf-metric{background:#f5f8ff;border:1px solid #d4def1;border-radius:12px;padding:.85rem}
    .sf-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.7rem}.sf-finance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem}
    .sf-finance-grid ul{margin:.4rem 0 0;padding-left:1rem}.sf-btn{background:#0f766e;color:#fff;padding:.7rem 1rem;border:none;border-radius:10px;font-weight:700;cursor:pointer}
    .sf-btn.alt{background:#1d4ed8}.sf-note{font-size:.85rem;color:#51607a}
    @media(max-width:900px){.sf-wrap{padding:1rem}.sf-flex{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <header class="site-header"></header>
  <main class="sf-wrap" data-settings='<?= htmlspecialchars(json_encode($settings, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>
    <section class="sf-hero">
      <h1><?= htmlspecialchars((string) ($content['page_title'] ?? 'Solar and Finance')) ?></h1>
      <p><?= htmlspecialchars((string) ($content['hero_text'] ?? 'Simple solar guidance and finance clarity.')) ?></p>
    </section>

    <section class="sf-grid cards">
      <?php foreach (($content['explainer_cards'] ?? []) as $card): ?>
      <article class="sf-card"><i class="fa-solid <?= htmlspecialchars((string) ($card['icon'] ?? 'fa-solar-panel')) ?>"></i><h3><?= htmlspecialchars((string) ($card['title'] ?? '')) ?></h3><p><?= htmlspecialchars((string) ($card['text'] ?? '')) ?></p></article>
      <?php endforeach; ?>
    </section>

    <section class="sf-flex">
      <div class="sf-card">
        <h2>Enter your details</h2>
        <h3>B1) Mandatory Inputs</h3>
        <div class="sf-inputs">
          <label>Monthly Bill (₹)<input type="number" id="monthlyBill" min="0"></label>
          <label>Monthly Units (kWh)<input type="number" id="monthlyUnits" min="0"></label>
          <label>System Type<select id="systemType"><option>On-Grid</option><option>Hybrid</option></select></label>
        </div>
        <details class="sf-pro" open>
          <summary><strong>B2) Advanced / Pro Inputs</strong></summary>
          <div class="sf-inputs" style="margin-top:.7rem">
            <label>Solar Size (kW)<input type="number" step="0.1" id="solarSize"></label>
            <label>Daily Generation per kW<input type="number" step="0.1" id="dailyGeneration" value="<?= htmlspecialchars((string) ($defaults['daily_generation_per_kw'] ?? 5)) ?>"></label>
            <label>Unit Rate (₹/kWh)<input type="number" step="0.01" id="unitRate" value="<?= htmlspecialchars((string) ($defaults['unit_rate'] ?? 8)) ?>"></label>
            <label>System Cost (₹)<input type="number" id="systemCost"></label>
            <label>Subsidy (₹)<input type="number" id="subsidy" value="78000"></label>
            <label>Loan amount (₹)<input type="number" id="loanAmount"></label>
            <label>Margin money (₹)<input type="number" id="marginMoney"></label>
            <label>Interest (%)<input type="number" step="0.01" id="interestRate" value="<?= htmlspecialchars((string) ($defaults['interest_upto_2_lacs'] ?? 6)) ?>"></label>
            <label>Tenure (years)<input type="number" id="loanTenure" value="<?= htmlspecialchars((string) ($defaults['loan_tenure_years'] ?? 10)) ?>"></label>
            <label>Loan - Subsidy (₹)<input type="number" id="effectiveLoan" readonly></label>
            <label>Hybrid Inverter (kVA)<select id="inverterKva"></select></label>
            <label>Phase<select id="phase"></select></label>
            <label>Battery count<select id="batteryCount"></select></label>
          </div>
        </details>
        <p class="sf-note">If you enter only bill or only units, the other field auto-fills using unit rate.</p>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <button class="sf-btn" id="calcBtn">How solar adoption would look like</button>
          <button class="sf-btn alt" type="button" id="resetBtn">Reset All Fields</button>
        </div>
      </div>
      <div class="sf-card">
        <h2>Generation Estimate</h2>
        <div class="sf-kpis" id="kpiPanel"></div>
      </div>
    </section>

    <section class="sf-results" id="results" hidden>
      <div class="sf-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-bottom:1rem">
        <article class="sf-card"><h3>Monthly Outflow Comparison</h3><canvas id="monthlyChart" height="180"></canvas></article>
        <article class="sf-card"><h3>Cumulative Expense Over 25 Years</h3><canvas id="cumulativeChart" height="180"></canvas></article>
      </div>
      <article class="sf-card" style="margin-bottom:1rem"><h3>Payback meters</h3><div id="paybackMeters" class="sf-kpis"></div></article>
      <article class="sf-card"><h3>Financial Clarity</h3><div id="financeBoxes" class="sf-finance-grid"></div></article>
      <div style="margin-top:1rem"><a class="sf-btn alt" target="_blank" id="waQuote" href="#"><i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars((string) ($content['cta_text'] ?? 'Request a quotation')) ?></a></div>
    </section>
  </main>

  <footer class="site-footer"></footer>
  <script src="/script.js" defer></script>
  <script>
    const INR=n=>`₹${Math.round(n||0).toLocaleString('en-IN')}`;
    const settings=JSON.parse(document.querySelector('main').dataset.settings||'{}');
    const onGrid=(settings.on_grid_prices||[]); const hybrid=(settings.hybrid_prices||[]); const d=settings.defaults||{};
    const defaultState={
      monthlyBill:'', monthlyUnits:'', systemType:'On-Grid', solarSize:'',
      dailyGeneration:String(d.daily_generation_per_kw ?? 5),
      unitRate:String(d.unit_rate ?? 8),
      systemCost:'', subsidy:String(d.default_subsidy ?? 78000), loanAmount:'', marginMoney:'',
      interestRate:String(d.interest_upto_2_lacs ?? 6), loanTenure:String(d.loan_tenure_years ?? 10),
      effectiveLoan:''
    };
    let mChart,cChart,debounceTimer=null,syncing=false;
    const ids=['monthlyBill','monthlyUnits','systemType','solarSize','dailyGeneration','unitRate','systemCost','subsidy','loanAmount','marginMoney','interestRate','loanTenure','effectiveLoan','inverterKva','phase','batteryCount'];
    const el=Object.fromEntries(ids.map(id=>[id,document.getElementById(id)]));

    function emi(p,r,y){const n=y*12; const i=(r/100)/12; if(!n||!p)return 0; if(!i)return p/n; return (p*i*Math.pow(1+i,n))/(Math.pow(1+i,n)-1)}
    function roundSize(v){return Math.max(1,Math.round(v*10)/10)}
    function findOnGrid(size){const s=Math.round(size); return onGrid.find(r=>Number(r.size_kw)===s)||onGrid[0]}
    function hybridRowsForSize(size){const s=Math.round(size); return hybrid.filter(r=>Number(r.size_kw)===s)}
    function fillHybridSelectors(){const rows=hybridRowsForSize(Number(el.solarSize.value||0)); const vals=(arr,key)=>[...new Set(arr.map(r=>String(r[key])))]
      const set=(node,list)=>{node.innerHTML=list.map(v=>`<option>${v}</option>`).join('')};
      if(!rows.length){[el.inverterKva,el.phase,el.batteryCount].forEach(node=>node.innerHTML=''); return;}
      set(el.inverterKva,vals(rows,'inverter_kva')); set(el.phase,vals(rows,'phase')); set(el.batteryCount,vals(rows,'battery_count'));
    }
    function selectedHybridRow(){const rows=hybridRowsForSize(Number(el.solarSize.value||0)); if(!rows.length)return null;
      return rows.find(r=>String(r.inverter_kva)===el.inverterKva.value&&String(r.phase)===el.phase.value&&String(r.battery_count)===el.batteryCount.value) || rows[0]; }

    function isHigherLoanApplicable(cost){return Math.round(Number(cost||0)*0.8) >= 200000;}

    function clearResults(){
      document.getElementById('results').hidden=true;
      kpiPanel.innerHTML='';
      paybackMeters.innerHTML='';
      financeBoxes.innerHTML='';
      if(mChart){mChart.destroy();mChart=null;}
      if(cChart){cChart.destroy();cChart=null;}
    }

    function syncMandatoryFields(changed){
      if(syncing) return;
      const rate=Number(el.unitRate.value||d.unit_rate||8);
      if(!rate) return;
      syncing=true;
      if(changed==='bill'){
        const bill=Number(el.monthlyBill.value);
        el.monthlyUnits.value=bill>0?(bill/rate).toFixed(2):'';
      }else if(changed==='units'){
        const units=Number(el.monthlyUnits.value);
        el.monthlyBill.value=units>0?(units*rate).toFixed(2):'';
      }
      syncing=false;
    }

    function applyPriceRefs(){const size=Number(el.solarSize.value||0); if(!size){el.effectiveLoan.value=''; return;} let ref;
      if(el.systemType.value==='On-Grid'){ref=findOnGrid(size);} else {ref=selectedHybridRow();}
      if(!ref) return;
      const loan2=Number(ref.loan_upto_2_lacs||0), loanHigh=Number(ref.loan_above_2_lacs||0);
      const chosen=loan2<=200000?loan2:loanHigh;
      if(!Number(el.systemCost.value)) el.systemCost.value=chosen>0?String(chosen):'';
      const systemCost=Number(el.systemCost.value||0);
      if(!Number(el.loanAmount.value)) el.loanAmount.value=systemCost?String(Math.min(200000,Math.round(systemCost*0.9))):'';
      if(!Number(el.marginMoney.value)) el.marginMoney.value=systemCost?String(Math.max(systemCost-Number(el.loanAmount.value||0), Math.round(systemCost*0.1))):'';
      el.effectiveLoan.value=Math.max(Number(el.loanAmount.value||0)-Number(el.subsidy.value||0),0).toFixed(0);
    }

    function scheduleRender(){
      clearTimeout(debounceTimer);
      debounceTimer=setTimeout(()=>{autosyncAndRender();},320);
    }

    function autosyncAndRender(changed=''){
      if(changed==='bill'||changed==='units') syncMandatoryFields(changed);
      const units=Number(el.monthlyUnits.value||0);
      if(units>0 && !Number(el.solarSize.value)){el.solarSize.value=roundSize(units/(Number(el.dailyGeneration.value||5)*30));}
      fillHybridSelectors();
      applyPriceRefs();
      render();
    }

    function render(){const bill=Number(el.monthlyBill.value||0), units=Number(el.monthlyUnits.value||0); if(!bill&&!units){clearResults(); return;}
      const size=Number(el.solarSize.value||0), gen=Number(el.dailyGeneration.value||5), rate=Number(el.unitRate.value||8), cost=Number(el.systemCost.value||0), subsidy=Number(el.subsidy.value||0);
      if(!size||!gen||!rate||!cost){clearResults(); return;}
      const monthlyBill=bill||size*gen*30*rate; const solarUnits=size*gen*30; const solarValue=solarUnits*rate; const residual=Math.max(monthlyBill-solarValue,0);
      const loanUp=Math.min(200000,Math.round(cost*0.9)); const marginUp=Math.max(cost-loanUp,Math.round(cost*0.1));
      const higherLoanApplicable=isHigherLoanApplicable(cost);
      const loanHigh=higherLoanApplicable?Math.round(cost*0.8):0; const marginHigh=higherLoanApplicable?Math.max(cost-loanHigh,Math.round(cost*0.2)):0;
      const effUp=Math.max(loanUp-subsidy,0), effHigh=higherLoanApplicable?Math.max(loanHigh-subsidy,0):0;
      const tenure=Number(el.loanTenure.value||10); const emiUp=emi(effUp,Number(el.interestRate.value||d.interest_upto_2_lacs||6),tenure);
      const emiHigh=higherLoanApplicable?emi(effHigh,Number(d.interest_above_2_lacs||8.15),tenure):0;

      const monthlyLabels=['No Solar','Loan ≤2L']; const monthlyData=[monthlyBill,emiUp+residual]; const monthlyColors=['#9ca3af','#0f766e'];
      if(higherLoanApplicable){monthlyLabels.push('Loan >2L'); monthlyData.push(emiHigh+residual); monthlyColors.push('#1d4ed8');}
      monthlyLabels.push('Self Funded'); monthlyData.push(residual); monthlyColors.push('#f59e0b');

      const years=[...Array(26).keys()];
      const cumul=(start,mEmi,mRes)=>years.map(y=>{const paidEmi=y*12<=tenure*12?y*12*mEmi:tenure*12*mEmi; const post=Math.max(y*12-tenure*12,0)*mRes; return start+paidEmi+post+Math.min(y*12,tenure*12)*mRes;});
      const cumulativeDatasets=[
        {label:'No Solar',data:years.map(y=>y*12*monthlyBill),borderColor:'#9ca3af'},
        {label:'Loan ≤2L',data:cumul(marginUp,emiUp,residual),borderColor:'#0f766e'}
      ];
      if(higherLoanApplicable){cumulativeDatasets.push({label:'Loan >2L',data:cumul(marginHigh,emiHigh,residual),borderColor:'#1d4ed8'});}
      cumulativeDatasets.push({label:'Self Funded',data:years.map(y=>cost-subsidy+y*12*residual),borderColor:'#f59e0b'});

      document.getElementById('results').hidden=false;
      const kpi=[['Expected monthly generation',`${solarUnits.toFixed(0)} units`],['Expected annual generation',`${(solarUnits*12).toFixed(0)} units`],['Units in 25 years',`${(solarUnits*12*25).toFixed(0)} units`],['Annual saving',INR(Math.max(monthlyBill-residual,0)*12)],['Estimated payback (self funded)',`${((cost-subsidy)/Math.max((monthlyBill-residual)*12,1)).toFixed(1)} years`],['Roof area needed',`${(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0)} sq.ft`],['Bill offset',`${Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1)}%`],['Annual CO₂ reduction',`${((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['25-year CO₂ reduction',`${((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['Tree equivalent',`${(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)).toFixed(0)} trees`]];
      kpiPanel.innerHTML=kpi.map(([k,v])=>`<div class='sf-metric'><strong>${k}</strong><div>${v}</div></div>`).join('');

      const payback=[['Loan up to 2 lacs', (effUp/Math.max((monthlyBill-(emiUp+residual))*12,1))]];
      if(higherLoanApplicable){payback.push(['Loan above 2 lacs',(effHigh/Math.max((monthlyBill-(emiHigh+residual))*12,1))]);}
      payback.push(['Self Funded',((cost-subsidy)/Math.max((monthlyBill-residual)*12,1))]);
      paybackMeters.innerHTML=payback.map(([n,p])=>`<div class='sf-metric'><strong>${n}</strong><div>${isFinite(p)?p.toFixed(1):'N/A'} years</div></div>`).join('');

      const finData=[
        ['No Solar',[['Monthly bill',INR(monthlyBill)],['25 year expense',INR(monthlyBill*12*25)]]],
        ['Loan up to 2 lacs',[['System cost',INR(cost)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanUp)],['Loan - subsidy',INR(effUp)],['Margin',INR(marginUp)],['Interest',`${el.interestRate.value||d.interest_upto_2_lacs||6}%`],['Tenure',`${tenure} years`],['EMI',INR(emiUp)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiUp+residual)]]],
      ];
      if(higherLoanApplicable){
        finData.push(['Loan above 2 lacs',[['System cost',INR(cost)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanHigh)],['Loan - subsidy',INR(effHigh)],['Margin',INR(marginHigh)],['Interest',`${d.interest_above_2_lacs||8.15}%`],['Tenure',`${tenure} years`],['EMI',INR(emiHigh)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiHigh+residual)]]]);
      }
      finData.push(['Self Funded',[['System cost',INR(cost)],['Subsidy',INR(subsidy)],['Net investment',INR(cost-subsidy)],['Residual bill',INR(residual)],['Monthly saving',INR(monthlyBill-residual)],['Annual saving',INR((monthlyBill-residual)*12)]]]);
      financeBoxes.innerHTML=finData.map(([t,rows])=>`<div class='sf-metric'><strong>${t}</strong><ul>${rows.map(r=>`<li>${r[0]}: <b>${r[1]}</b></li>`).join('')}</ul></div>`).join('');

      if(mChart) mChart.destroy(); if(cChart) cChart.destroy();
      mChart=new Chart(monthlyChart,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors}]},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Scenario'}},y:{title:{display:true,text:'Monthly Outflow (₹)'}}}}});
      cChart=new Chart(cumulativeChart,{type:'line',data:{labels:years,datasets:cumulativeDatasets},options:{scales:{x:{title:{display:true,text:'Years'}},y:{title:{display:true,text:'Cumulative Expense (₹)'}}}}});

      const hy=el.systemType.value==='Hybrid'?`Hybrid: ${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A';
      const msg=`Hello Dakshayani, I want a solar quotation.%0A- Monthly bill: ${INR(monthlyBill)}%0A- Monthly units: ${units||Math.round(monthlyBill/rate)}%0A- System type: ${el.systemType.value}%0A- Solar size: ${size} kW%0A- System cost: ${INR(cost)}%0A- Subsidy: ${INR(subsidy)}%0A- Loan amount: ${INR(loanUp)}%0A- Margin money: ${INR(marginUp)}%0A- Interest: ${el.interestRate.value||d.interest_upto_2_lacs||6}% / ${d.interest_above_2_lacs||8.15}%0A- Tenure: ${tenure} years%0A- Hybrid variation: ${hy}%0A- Monthly generation: ${solarUnits.toFixed(0)} units%0A- Annual saving: ${INR((monthlyBill-residual)*12)}`;
      waQuote.href=`https://wa.me/917070278178?text=${msg}`;
    }

    function resetAllFields(){
      Object.entries(defaultState).forEach(([key,val])=>{if(el[key])el[key].value=val;});
      [el.inverterKva,el.phase,el.batteryCount].forEach(node=>node.innerHTML='');
      clearResults();
      autosyncAndRender();
    }

    ['solarSize','dailyGeneration','unitRate','systemCost','subsidy','loanAmount','marginMoney','interestRate','loanTenure'].forEach(id=>{
      el[id].addEventListener('input',()=>scheduleRender());
    });
    ['systemType','inverterKva','phase','batteryCount'].forEach(id=>{
      el[id].addEventListener('change',()=>scheduleRender());
    });
    el.monthlyBill.addEventListener('input',()=>{clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>autosyncAndRender('bill'),320);});
    el.monthlyUnits.addEventListener('input',()=>{clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>autosyncAndRender('units'),320);});
    document.getElementById('calcBtn').addEventListener('click',()=>autosyncAndRender());
    document.getElementById('resetBtn').addEventListener('click',resetAllFields);
    resetAllFields();
  </script>

</body>
</html>
