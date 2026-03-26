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
            <label>System cost (Self funded / Loan up to 2 lacs) (₹)<input type="number" id="systemCostBase"></label>
            <label id="systemCostHighLoanWrap">System cost (Loan above 2 lacs) (₹)<input type="number" id="systemCostHighLoan"></label>
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
      <div style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap">
        <button class="sf-btn" type="button" id="createReportBtn">Create Report</button>
        <button class="sf-btn alt" type="button" id="openReportBtn" disabled>Open Report</button>
        <button class="sf-btn alt" type="button" id="printReportBtn" disabled>Print Report</button>
        <a class="sf-btn alt" target="_blank" id="shareReportWa" href="#" style="display:none"><i class="fa-brands fa-whatsapp"></i> Share Report</a>
        <a class="sf-btn alt" target="_blank" id="waQuote" href="#"><i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars((string) ($content['cta_text'] ?? 'Request a quotation')) ?></a>
      </div>
    </section>
  </main>

  <footer class="site-footer"></footer>
  <script src="/script.js" defer></script>
  <script>
    const INR=n=>`₹${Math.round(n||0).toLocaleString('en-IN')}`;
    const settings=JSON.parse(document.querySelector('main').dataset.settings||'{}');
    const onGrid=(settings.on_grid_prices||[]),hybrid=(settings.hybrid_prices||[]),d=settings.defaults||{};
    const defaultState={monthlyBill:'',monthlyUnits:'',systemType:'On-Grid',solarSize:'',dailyGeneration:String(d.daily_generation_per_kw??5),unitRate:String(d.unit_rate??8),systemCostBase:'',systemCostHighLoan:'',subsidy:String(d.default_subsidy??78000),loanAmount:'',marginMoney:'',interestRate:String(d.interest_upto_2_lacs??6),loanTenure:String(d.loan_tenure_years??10),effectiveLoan:''};
    let mChart,cChart,debounceTimer=null,isProgrammaticUpdate=false,lastRenderData=null,lastReportUrl='';
    const manualOverride=new Set();
    const debouncedIds=['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','systemCostBase','systemCostHighLoan','subsidy','loanAmount','marginMoney','interestRate','loanTenure'];
    const ids=[...debouncedIds,'systemType','effectiveLoan','inverterKva','phase','batteryCount'];
    const el=Object.fromEntries(ids.map(id=>[id,document.getElementById(id)]));
    const kpiPanel=document.getElementById('kpiPanel'),paybackMeters=document.getElementById('paybackMeters'),financeBoxes=document.getElementById('financeBoxes'),waQuote=document.getElementById('waQuote');
    const systemCostHighLoanWrap=document.getElementById('systemCostHighLoanWrap');
    const createReportBtn=document.getElementById('createReportBtn'),openReportBtn=document.getElementById('openReportBtn'),printReportBtn=document.getElementById('printReportBtn'),shareReportWa=document.getElementById('shareReportWa');

    const num=(v,fallback=0)=>{const n=Number(v); return Number.isFinite(n)?n:fallback;};
    const roundSize=v=>Math.max(1,Math.round(v*10)/10);
    const emi=(p,r,y)=>{const n=y*12,i=(r/100)/12; if(!n||!p)return 0; if(!i)return p/n; return (p*i*Math.pow(1+i,n))/(Math.pow(1+i,n)-1);};
    const findOnGrid=size=>onGrid.find(r=>Number(r.size_kw)===Math.round(size))||onGrid[0]||null;
    const hybridRowsForSize=size=>hybrid.filter(r=>Number(r.size_kw)===Math.round(size));
    const setField=(id,val)=>{if(!el[id])return; isProgrammaticUpdate=true; el[id].value=val; isProgrammaticUpdate=false;};
    const shouldAutofill=id=>!manualOverride.has(id)||String(el[id]?.value||'').trim()==='';
    const basePriceForRow=row=>Math.max(num(row?.loan_upto_2_lacs),0);
    const highPriceForRow=row=>Math.max(num(row?.loan_above_2_lacs)||basePriceForRow(row),0);
    const refreshEffectiveLoan=()=>setField('effectiveLoan', String(Math.max(num(el.loanAmount.value)-num(el.subsidy.value),0).toFixed(0)));

    function clearResults(){
      document.getElementById('results').hidden=true;
      kpiPanel.innerHTML=''; paybackMeters.innerHTML=''; financeBoxes.innerHTML='';
      if(mChart){mChart.destroy();mChart=null;} if(cChart){cChart.destroy();cChart=null;}
      lastRenderData=null;
    }

    function fillHybridSelectors(){
      const rows=hybridRowsForSize(num(el.solarSize.value));
      if(!rows.length){['inverterKva','phase','batteryCount'].forEach(id=>setField(id,'')); [el.inverterKva,el.phase,el.batteryCount].forEach(node=>node.innerHTML=''); return null;}
      const vals=(key)=>[...new Set(rows.map(r=>String(r[key])))];
      const options={inverterKva:vals('inverter_kva'),phase:vals('phase'),batteryCount:vals('battery_count')};
      Object.entries(options).forEach(([id,list])=>{
        const node=el[id],current=node.value;
        node.innerHTML=list.map(v=>`<option>${v}</option>`).join('');
        setField(id, list.includes(current)?current:list[0]);
      });
      return rows.find(r=>String(r.inverter_kva)===el.inverterKva.value&&String(r.phase)===el.phase.value&&String(r.battery_count)===el.batteryCount.value) || rows[0];
    }

    function recalculateSolarFinance(meta={}){
      const {changedField=''}=meta;
      const rate=Math.max(num(el.unitRate.value,d.unit_rate||8),0);
      const gen=Math.max(num(el.dailyGeneration.value,d.daily_generation_per_kw||5),0);
      const units=num(el.monthlyUnits.value), bill=num(el.monthlyBill.value);

      if(changedField==='monthlyBill'&&rate>0){setField('monthlyUnits', bill>0?(bill/rate).toFixed(2):'');}
      if(changedField==='monthlyUnits'&&rate>0){setField('monthlyBill', units>0?(units*rate).toFixed(2):'');}

      const currentUnits=num(el.monthlyUnits.value);
      const recommendedSize=(currentUnits>0&&gen>0)?roundSize(currentUnits/(gen*30)):0;
      if(recommendedSize>0&&(changedField==='monthlyUnits'||changedField==='monthlyBill'||changedField==='systemType'||shouldAutofill('solarSize'))){
        setField('solarSize', String(recommendedSize));
      }

      let selectedRow=null;
      if(el.systemType.value==='Hybrid'){selectedRow=fillHybridSelectors();}
      else {
        [el.inverterKva,el.phase,el.batteryCount].forEach(node=>node.innerHTML='');
        selectedRow=findOnGrid(num(el.solarSize.value));
      }
      if(el.systemType.value==='Hybrid'&&!selectedRow){selectedRow=fillHybridSelectors();}
      if(el.systemType.value==='Hybrid'&&selectedRow===null){selectedRow=findOnGrid(num(el.solarSize.value));}

      const recommendedRow=selectedRow||findOnGrid(num(el.solarSize.value));
      const recommendedBaseCost=basePriceForRow(recommendedRow);
      const recommendedHighCost=highPriceForRow(recommendedRow);
      if(recommendedBaseCost>0&&shouldAutofill('systemCostBase')) setField('systemCostBase', String(Math.round(recommendedBaseCost)));
      const baseCost=Math.max(num(el.systemCostBase.value),0);
      const higherLoanApplicable=Math.round(baseCost*0.8) >= 200000;
      systemCostHighLoanWrap.hidden=!higherLoanApplicable;
      if(higherLoanApplicable&&recommendedHighCost>0&&shouldAutofill('systemCostHighLoan')) setField('systemCostHighLoan', String(Math.round(recommendedHighCost)));

      const autoLoan=Math.min(200000,Math.round(baseCost*0.9));
      const loanAmount=Math.max(num(el.loanAmount.value,autoLoan),0);
      if(baseCost>0&&shouldAutofill('loanAmount')) setField('loanAmount', String(autoLoan));
      const autoMargin=Math.max(baseCost-num(el.loanAmount.value,autoLoan), Math.round(baseCost*0.1));
      if(baseCost>0&&shouldAutofill('marginMoney')) setField('marginMoney', String(autoMargin));
      refreshEffectiveLoan();
      render();
    }

    function render(){const bill=Number(el.monthlyBill.value||0), units=Number(el.monthlyUnits.value||0); if(!bill&&!units){clearResults(); return;}
      const size=Number(el.solarSize.value||0), gen=Number(el.dailyGeneration.value||5), rate=Number(el.unitRate.value||8), baseCost=Number(el.systemCostBase.value||0), subsidy=Number(el.subsidy.value||0);
      if(!size||!gen||!rate||!baseCost){clearResults(); return;}
      const monthlyBill=bill||size*gen*30*rate; const solarUnits=size*gen*30; const solarValue=solarUnits*rate; const residual=Math.max(monthlyBill-solarValue,0);
      const loanUp=Math.max(num(el.loanAmount.value),0)||Math.min(200000,Math.round(baseCost*0.9));
      const marginUp=Math.max(num(el.marginMoney.value),0)||Math.max(baseCost-loanUp,Math.round(baseCost*0.1));
      const higherLoanApplicable=Math.round(baseCost*0.8) >= 200000;
      const highLoanCost=higherLoanApplicable?Math.max(Number(el.systemCostHighLoan.value||0), baseCost):0;
      const loanHigh=higherLoanApplicable?Math.round(highLoanCost*0.8):0; const marginHigh=higherLoanApplicable?Math.max(highLoanCost-loanHigh,Math.round(highLoanCost*0.2)):0;
      const effUp=Math.max(loanUp-subsidy,0), effHigh=higherLoanApplicable?Math.max(loanHigh-subsidy,0):0;
      const tenure=num(el.loanTenure.value,10); const emiUp=emi(effUp,num(el.interestRate.value,d.interest_upto_2_lacs||6),tenure);
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
      cumulativeDatasets.push({label:'Self Funded',data:years.map(y=>baseCost-subsidy+y*12*residual),borderColor:'#f59e0b'});

      document.getElementById('results').hidden=false;
      const kpi=[['Expected monthly generation',`${solarUnits.toFixed(0)} units`],['Expected annual generation',`${(solarUnits*12).toFixed(0)} units`],['Units in 25 years',`${(solarUnits*12*25).toFixed(0)} units`],['Annual saving',INR(Math.max(monthlyBill-residual,0)*12)],['Estimated payback (self funded)',`${((baseCost-subsidy)/Math.max((monthlyBill-residual)*12,1)).toFixed(1)} years`],['Roof area needed',`${(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0)} sq.ft`],['Bill offset',`${Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1)}%`],['Annual CO₂ reduction',`${((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['25-year CO₂ reduction',`${((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['Tree equivalent',`${(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)).toFixed(0)} trees`]];
      kpiPanel.innerHTML=kpi.map(([k,v])=>`<div class='sf-metric'><strong>${k}</strong><div>${v}</div></div>`).join('');

      const payback=[['Loan up to 2 lacs', (effUp/Math.max((monthlyBill-(emiUp+residual))*12,1))]];
      if(higherLoanApplicable){payback.push(['Loan above 2 lacs',(effHigh/Math.max((monthlyBill-(emiHigh+residual))*12,1))]);}
      payback.push(['Self Funded',((baseCost-subsidy)/Math.max((monthlyBill-residual)*12,1))]);
      paybackMeters.innerHTML=payback.map(([n,p])=>`<div class='sf-metric'><strong>${n}</strong><div>${isFinite(p)?p.toFixed(1):'N/A'} years</div></div>`).join('');

      const finData=[
        ['No Solar',[['Monthly bill',INR(monthlyBill)],['25 year expense',INR(monthlyBill*12*25)]]],
        ['Loan up to 2 lacs',[['System cost',INR(baseCost)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanUp)],['Loan - subsidy',INR(effUp)],['Margin',INR(marginUp)],['Interest',`${el.interestRate.value||d.interest_upto_2_lacs||6}%`],['Tenure',`${tenure} years`],['EMI',INR(emiUp)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiUp+residual)]]],
      ];
      if(higherLoanApplicable){
        finData.push(['Loan above 2 lacs',[['System cost',INR(highLoanCost)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanHigh)],['Loan - subsidy',INR(effHigh)],['Margin',INR(marginHigh)],['Interest',`${d.interest_above_2_lacs||8.15}%`],['Tenure',`${tenure} years`],['EMI',INR(emiHigh)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiHigh+residual)]]]);
      }
      finData.push(['Self Funded',[['System cost',INR(baseCost)],['Subsidy',INR(subsidy)],['Net investment',INR(baseCost-subsidy)],['Residual bill',INR(residual)],['Monthly saving',INR(monthlyBill-residual)],['Annual saving',INR((monthlyBill-residual)*12)]]]);
      financeBoxes.innerHTML=finData.map(([t,rows])=>`<div class='sf-metric'><strong>${t}</strong><ul>${rows.map(r=>`<li>${r[0]}: <b>${r[1]}</b></li>`).join('')}</ul></div>`).join('');

      if(mChart) mChart.destroy(); if(cChart) cChart.destroy();
      mChart=new Chart(monthlyChart,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors}]},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Scenario'}},y:{title:{display:true,text:'Monthly Outflow (₹)'}}}}});
      cChart=new Chart(cumulativeChart,{type:'line',data:{labels:years,datasets:cumulativeDatasets},options:{scales:{x:{title:{display:true,text:'Years'}},y:{title:{display:true,text:'Cumulative Expense (₹)'}}}}});

      const hy=el.systemType.value==='Hybrid'?`Hybrid: ${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A';
      const msg=`Hello Dakshayani, I want a solar quotation.%0A- Monthly bill: ${INR(monthlyBill)}%0A- Monthly units: ${units||Math.round(monthlyBill/rate)}%0A- System type: ${el.systemType.value}%0A- Solar size: ${size} kW%0A- System cost (base): ${INR(baseCost)}%0A- Subsidy: ${INR(subsidy)}%0A- Loan amount: ${INR(loanUp)}%0A- Margin money: ${INR(marginUp)}%0A- Interest: ${el.interestRate.value||d.interest_upto_2_lacs||6}% / ${d.interest_above_2_lacs||8.15}%0A- Tenure: ${tenure} years%0A- Hybrid variation: ${hy}%0A- Monthly generation: ${solarUnits.toFixed(0)} units%0A- Annual saving: ${INR((monthlyBill-residual)*12)}`;
      waQuote.href=`https://wa.me/917070278178?text=${msg}`;

      lastRenderData={createdAt:new Date().toLocaleString('en-IN'),inputs:{monthlyBill:INR(monthlyBill),monthlyUnits:String((units||Math.round(monthlyBill/rate))),systemType:el.systemType.value,solarSize:`${size} kW`,dailyGeneration:`${gen} units/kW/day`,unitRate:INR(rate),subsidy:INR(subsidy),loanAmount:INR(loanUp),marginMoney:INR(marginUp),interestRate:`${el.interestRate.value||d.interest_upto_2_lacs||6}%`,tenure:`${tenure} years`,hybridConfig:el.systemType.value==='Hybrid'?`${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:''},summary:{recommendedSize:`${size} kW`,roofArea:`${(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0)} sq.ft`,billOffset:`${Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1)}%`,monthlyGeneration:`${solarUnits.toFixed(0)} units`,annualGeneration:`${(solarUnits*12).toFixed(0)} units`,greenImpact:`${((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg CO₂/year; ${(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)).toFixed(0)} trees equivalent`},financial:finData.map(([title,rows])=>({title,rows})),monthly:{labels:monthlyLabels,data:monthlyData},cumulative:{labels:years,data:cumulativeDatasets.map(ds=>({label:ds.label,data:ds.data,borderColor:ds.borderColor}))},payback:payback.map(([name,val])=>({name,years:isFinite(val)?val.toFixed(1):'N/A'})),ctaUrl:waQuote.href};
    }

    function buildReportHtml(data){
      const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
      const inputRows=Object.entries(data.inputs).filter(([,v])=>String(v).trim()!=='').map(([k,v])=>`<tr><th>${esc(k.replace(/([A-Z])/g,' $1').replace(/^./,c=>c.toUpperCase()))}</th><td>${esc(v)}</td></tr>`).join('');
      const summaryRows=Object.entries(data.summary).filter(([,v])=>String(v).trim()!=='').map(([k,v])=>`<tr><th>${esc(k.replace(/([A-Z])/g,' $1').replace(/^./,c=>c.toUpperCase()))}</th><td>${esc(v)}</td></tr>`).join('');
      const financeBlocks=data.financial.map(section=>`<div class="card"><h3>${esc(section.title)}</h3><ul>${section.rows.filter(r=>String(r[1]).trim()!=='').map(r=>`<li>${esc(r[0])}: <b>${esc(r[1])}</b></li>`).join('')}</ul></div>`).join('');
      return `<!doctype html><html><head><meta charset="utf-8"><title>Solar and Finance Report</title><script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script><style>body{font-family:Arial,sans-serif;margin:20px;background:#f8fbff;color:#0f172a}h1,h2{margin:.3rem 0}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #dbe4f0;padding:8px;text-align:left}th{width:35%;background:#f5f8ff}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.card{background:#fff;border:1px solid #dbe4f0;border-radius:10px;padding:12px}.cta{margin-top:16px;padding:14px;background:#e6fffa;border:1px solid #99f6e4;border-radius:10px}@media print{.noprint{display:none}}</style></head><body><h1>Solar and Finance Report</h1><div>Dakshayani Enterprises</div><div>Created: ${esc(data.createdAt)}</div><h2>Customer-entered inputs</h2><table>${inputRows}</table><h2>Recommended / calculated summary</h2><table>${summaryRows}</table><h2>Financial comparison</h2><div class="grid">${financeBlocks}</div><h2>Charts</h2><div class="grid"><div class="card"><h3>Monthly Outflow Comparison</h3><canvas id="rMonthly"></canvas></div><div class="card"><h3>Cumulative Expense Over 25 Years</h3><canvas id="rCumulative"></canvas></div></div><div class="card"><h3>Payback meters</h3><canvas id="rPayback"></canvas></div><div class="cta"><h3>Request a quotation</h3><p><a href="${esc(data.ctaUrl)}" target="_blank">WhatsApp: +91 70702 78178</a></p><button class="noprint" onclick="window.print()">Print Report</button></div><script>const data=${JSON.stringify(data)};new Chart(document.getElementById('rMonthly'),{type:'bar',data:{labels:data.monthly.labels,datasets:[{label:'Monthly Outflow (₹)',data:data.monthly.data,backgroundColor:['#9ca3af','#0f766e','#1d4ed8','#f59e0b']}]}});new Chart(document.getElementById('rCumulative'),{type:'line',data:{labels:data.cumulative.labels,datasets:data.cumulative.data}});new Chart(document.getElementById('rPayback'),{type:'bar',data:{labels:data.payback.map(p=>p.name),datasets:[{label:'Payback (years)',data:data.payback.map(p=>Number(p.years)||0),backgroundColor:'#0f766e'}]}});<\/script></body></html>`;
    }

    function createReport(openNow=true){
      if(!lastRenderData)return;
      if(lastReportUrl) URL.revokeObjectURL(lastReportUrl);
      const html=buildReportHtml(lastRenderData);
      lastReportUrl=URL.createObjectURL(new Blob([html],{type:'text/html'}));
      openReportBtn.disabled=false;
      printReportBtn.disabled=false;
      shareReportWa.style.display='inline-flex';
      shareReportWa.href=`https://wa.me/917070278178?text=${encodeURIComponent('Solar and Finance Report is ready. Please help us with the next steps for quotation sharing.')}`;
      if(openNow) window.open(lastReportUrl,'_blank','noopener');
    }

    function resetAllFields(){
      manualOverride.clear();
      Object.entries(defaultState).forEach(([key,val])=>setField(key,val));
      [el.inverterKva,el.phase,el.batteryCount].forEach(node=>{node.innerHTML='';});
      clearResults();
      recalculateSolarFinance({changedField:'reset'});
    }

    function bindInput(id, eventName){
      el[id].addEventListener(eventName,()=>{
        if(!isProgrammaticUpdate && id!=='effectiveLoan') manualOverride.add(id);
        const run=()=>recalculateSolarFinance({changedField:id});
        if(eventName==='input' && debouncedIds.includes(id) && id!=='systemType'){clearTimeout(debounceTimer); debounceTimer=setTimeout(run,220); return;}
        run();
      });
    }

    ['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','systemCostBase','systemCostHighLoan','subsidy','loanAmount','marginMoney','interestRate','loanTenure'].forEach(id=>bindInput(id,'input'));
    ['systemType','inverterKva','phase','batteryCount'].forEach(id=>bindInput(id,'change'));
    document.getElementById('calcBtn').addEventListener('click',()=>recalculateSolarFinance({changedField:'manualTrigger'}));
    document.getElementById('resetBtn').addEventListener('click',resetAllFields);
    createReportBtn.addEventListener('click',()=>createReport(true));
    openReportBtn.addEventListener('click',()=>{if(lastReportUrl) window.open(lastReportUrl,'_blank','noopener');});
    printReportBtn.addEventListener('click',()=>{if(lastReportUrl){const w=window.open(lastReportUrl,'_blank','noopener'); if(w){w.addEventListener('load',()=>w.print(),{once:true});}}});
    resetAllFields();
  </script>

</body>
</html>
