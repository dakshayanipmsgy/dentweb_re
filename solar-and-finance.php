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
    .sf-btn.alt{background:#1d4ed8}.sf-note{font-size:.85rem;color:#51607a}.sf-alert{margin-top:.65rem;font-size:.85rem;color:#b91c1c}
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
            <label>Subsidy (₹)<input type="number" id="subsidy" value="78000"></label>
            <label>Tenure (years)<input type="number" id="loanTenure" value="<?= htmlspecialchars((string) ($defaults['loan_tenure_years'] ?? 10)) ?>"></label>
            <label>Hybrid Inverter (kVA)<select id="inverterKva"></select></label>
            <label>Phase<select id="phase"></select></label>
            <label>Battery count<select id="batteryCount"></select></label>
          </div>
          <h4 style="margin:.9rem 0 .4rem">Group A — Pricing</h4>
          <div class="sf-inputs">
            <label>System Cost (Self Funded) (₹)<input type="number" id="systemCostSelf"></label>
            <label>System Cost (Loan up to 2 lacs) (₹)<input type="number" id="systemCostUp2"></label>
            <label>System Cost (Loan above 2 lacs) (₹)<input type="number" id="systemCostAbove2"></label>
          </div>
          <h4 style="margin:.9rem 0 .4rem">Group B — Loan up to 2 lacs</h4>
          <div class="sf-inputs">
            <label>Loan amount (Loan up to 2 lacs) (₹)<input type="number" id="loanAmountUp2"></label>
            <label>Interest % (Loan up to 2 lacs)<input type="number" step="0.01" id="interestRateUp2" value="<?= htmlspecialchars((string) ($defaults['interest_upto_2_lacs'] ?? 6)) ?>"></label>
            <label>Margin money (Loan up to 2 lacs) (₹)<input type="number" id="marginMoneyUp2"></label>
          </div>
          <div id="loanAboveGroupWrap">
            <h4 style="margin:.9rem 0 .4rem">Group C — Loan above 2 lacs</h4>
            <div class="sf-inputs">
              <label>Loan amount (Loan above 2 lacs) (₹)<input type="number" id="loanAmountAbove2"></label>
              <label>Interest % (Loan above 2 lacs)<input type="number" step="0.01" id="interestRateAbove2" value="<?= htmlspecialchars((string) ($defaults['interest_above_2_lacs'] ?? 8.15)) ?>"></label>
              <label>Margin money (Loan above 2 lacs) (₹)<input type="number" id="marginMoneyAbove2"></label>
            </div>
          </div>
        </details>
        <p class="sf-note">If you enter only bill or only units, the other field auto-fills using unit rate.</p>
        <h4 style="margin:.9rem 0 .4rem">Customer details for report (optional)</h4>
        <div class="sf-inputs">
          <label>Customer Name<input type="text" id="customerName" maxlength="120" placeholder="Enter customer name"></label>
          <label>Location<input type="text" id="customerLocation" maxlength="150" placeholder="Enter location"></label>
          <label>Mobile Number<input type="text" id="customerMobile" maxlength="20" placeholder="e.g. 9999999999"></label>
        </div>
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
      <article class="sf-card" style="margin-top:1rem">
        <h3>Shareable Report</h3>
        <p class="sf-note">Add customer name, location and mobile number to generate a printable report.</p>
        <button class="sf-btn" type="button" id="createReportBtn">Create Report</button>
        <div id="reportValidationMsg" class="sf-alert" role="status" aria-live="polite"></div>
      </article>
      <div style="margin-top:1rem"><a class="sf-btn alt" target="_blank" id="waQuote" href="#"><i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars((string) ($content['cta_text'] ?? 'Request a quotation')) ?></a></div>
    </section>
  </main>

  <footer class="site-footer"></footer>
  <script src="/script.js" defer></script>
  <script>
    const INR=n=>`₹${Math.round(n||0).toLocaleString('en-IN')}`;
    const settings=JSON.parse(document.querySelector('main').dataset.settings||'{}');
    const onGrid=(settings.on_grid_prices||[]),hybrid=(settings.hybrid_prices||[]),d=settings.defaults||{};
    const defaultState={
      monthlyBill:'',
      monthlyUnits:'',
      systemType:'On-Grid',
      solarSize:'',
      dailyGeneration:String(d.daily_generation_per_kw??5),
      unitRate:String(d.unit_rate??8),
      subsidy:String(d.default_subsidy??78000),
      loanTenure:String(d.loan_tenure_years??10),
      systemCostSelf:'',
      systemCostUp2:'',
      systemCostAbove2:'',
      loanAmountUp2:'',
      marginMoneyUp2:'',
      interestRateUp2:String(d.interest_upto_2_lacs??6),
      loanAmountAbove2:'',
      marginMoneyAbove2:'',
      interestRateAbove2:String(d.interest_above_2_lacs??8.15),
      customerName:'',
      customerLocation:'',
      customerMobile:'',
    };
    let mChart,cChart,debounceTimer=null,isProgrammaticUpdate=false;
    let latestComputation=null;
    const manualOverride=new Set();
    const debouncedIds=['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','subsidy','loanTenure','systemCostSelf','systemCostUp2','systemCostAbove2','loanAmountUp2','marginMoneyUp2','interestRateUp2','loanAmountAbove2','marginMoneyAbove2','interestRateAbove2'];
    const ids=[...debouncedIds,'systemType','inverterKva','phase','batteryCount','loanAboveGroupWrap','customerName','customerLocation','customerMobile'];
    const el=Object.fromEntries(ids.map(id=>[id,document.getElementById(id)]));
    const kpiPanel=document.getElementById('kpiPanel'),paybackMeters=document.getElementById('paybackMeters'),financeBoxes=document.getElementById('financeBoxes'),waQuote=document.getElementById('waQuote');
    const reportValidationMsg=document.getElementById('reportValidationMsg');

    const num=(v,fallback=0)=>{const n=Number(v); return Number.isFinite(n)?n:fallback;};
    const roundSize=v=>Math.max(1,Math.round(v*10)/10);
    const emi=(p,r,y)=>{const n=y*12,i=(r/100)/12; if(!n||!p)return 0; if(!i)return p/n; return (p*i*Math.pow(1+i,n))/(Math.pow(1+i,n)-1);};
    const findOnGrid=size=>onGrid.find(r=>Number(r.size_kw)===Math.round(size))||onGrid[0]||null;
    const hybridRowsForSize=size=>hybrid.filter(r=>Number(r.size_kw)===Math.round(size));
    const setField=(id,val)=>{if(!el[id])return; isProgrammaticUpdate=true; el[id].value=val; isProgrammaticUpdate=false;};
    const markUserEdited=(id)=>{
      if(!el[id]) return;
      manualOverride.add(id);
      el[id].dataset.userEdited='true';
    };
    const clearUserEdited=(id)=>{
      if(!el[id]) return;
      manualOverride.delete(id);
      el[id].dataset.userEdited='false';
    };
    const shouldAutofill=id=>!manualOverride.has(id);
    const getScenarioPrices=row=>{
      if(!row){return {up2:0,self:0,above2:0};}
      const up2=Math.max(num(row.loan_upto_2_lacs),0);
      const aboveRaw=Math.max(num(row.loan_above_2_lacs),0);
      return {up2,self:up2,above2:aboveRaw||up2};
    };
    const isHigherLoanApplicableForCost=cost=>Math.round(cost*0.8) >= 200000;

    function clearResults(){
      document.getElementById('results').hidden=true;
      kpiPanel.innerHTML=''; paybackMeters.innerHTML=''; financeBoxes.innerHTML='';
      latestComputation=null;
      if(mChart){mChart.destroy();mChart=null;} if(cChart){cChart.destroy();cChart=null;}
    }

    function findPaybackMonths({upfront=0,emiMonthly=0,residualMonthly=0,loanMonths=0,noSolarMonthly=0,horizonMonths=25*12}){
      let cumulativeNoSolar=0;
      let cumulativeSolar=Math.max(upfront,0);
      if(cumulativeSolar<=cumulativeNoSolar){return 0;}
      for(let m=1;m<=horizonMonths;m++){
        cumulativeNoSolar+=noSolarMonthly;
        const monthOutflow=(m<=loanMonths?emiMonthly:0)+residualMonthly;
        cumulativeSolar+=monthOutflow;
        if(cumulativeSolar<=cumulativeNoSolar){return m;}
      }
      return null;
    }

    function formatPayback(months){
      if(months===null){return 'No crossover in 25 years';}
      const years=months/12;
      return `${months} months (${years.toFixed(1)} years)`;
    }

    function normalizeIndianMobile(raw=''){
      const value=String(raw||'').trim();
      if(!value){return null;}
      const cleaned=value.replace(/[^\d+]/g,'');
      const digits=cleaned.replace(/\D/g,'');
      let local=null;
      if(digits.length===10){local=digits;}
      else if(digits.length===11&&digits.startsWith('0')){local=digits.slice(1);}
      else if(digits.length===12&&digits.startsWith('91')){local=digits.slice(2);}
      if(!local || !/^[6-9]\d{9}$/.test(local)){return null;}
      return `+91 ${local}`;
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

      const prices=getScenarioPrices(selectedRow||findOnGrid(num(el.solarSize.value)));
      if(prices.up2>0&&shouldAutofill('systemCostUp2')) setField('systemCostUp2', String(Math.round(prices.up2)));
      if(prices.self>0&&shouldAutofill('systemCostSelf')) setField('systemCostSelf', String(Math.round(prices.self)));
      if(prices.above2>0&&shouldAutofill('systemCostAbove2')) setField('systemCostAbove2', String(Math.round(prices.above2)));

      const subsidy=Math.max(num(el.subsidy.value),0);
      const up2Cost=Math.max(num(el.systemCostUp2.value),0);
      const autoLoanUp2=Math.min(200000,Math.round(up2Cost*0.9));
      if(up2Cost>0&&shouldAutofill('loanAmountUp2')) setField('loanAmountUp2', String(autoLoanUp2));
      const currentLoanUp2=Math.max(num(el.loanAmountUp2.value,autoLoanUp2),0);
      const autoMarginUp2=Math.max(up2Cost-currentLoanUp2, Math.round(up2Cost*0.1));
      if(up2Cost>0&&shouldAutofill('marginMoneyUp2')) setField('marginMoneyUp2', String(autoMarginUp2));

      const above2Cost=Math.max(num(el.systemCostAbove2.value),0);
      const higherLoanApplicable=isHigherLoanApplicableForCost(above2Cost);
      if(el.loanAboveGroupWrap){el.loanAboveGroupWrap.style.display=higherLoanApplicable?'block':'none';}
      if(higherLoanApplicable){
        const autoLoanAbove2=Math.round(above2Cost*0.8);
        if(above2Cost>0&&shouldAutofill('loanAmountAbove2')) setField('loanAmountAbove2', String(autoLoanAbove2));
        const currentLoanAbove2=Math.max(num(el.loanAmountAbove2.value,autoLoanAbove2),0);
        const autoMarginAbove2=Math.max(above2Cost-currentLoanAbove2, Math.round(above2Cost*0.2));
        if(above2Cost>0&&shouldAutofill('marginMoneyAbove2')) setField('marginMoneyAbove2', String(autoMarginAbove2));
      }
      render();
    }

    function render(){const bill=Number(el.monthlyBill.value||0), units=Number(el.monthlyUnits.value||0); if(!bill&&!units){clearResults(); return;}
      const size=Number(el.solarSize.value||0), gen=Number(el.dailyGeneration.value||5), rate=Number(el.unitRate.value||8), subsidy=Number(el.subsidy.value||0);
      const costSelf=Math.max(num(el.systemCostSelf.value),0), costUp2=Math.max(num(el.systemCostUp2.value),0), costAbove2=Math.max(num(el.systemCostAbove2.value),0);
      if(!size||!gen||!rate||!costSelf||!costUp2||!costAbove2){clearResults(); return;}
      const monthlyBill=bill||size*gen*30*rate; const solarUnits=size*gen*30; const solarValue=solarUnits*rate; const residual=Math.max(monthlyBill-solarValue,0);
      const loanUp=Math.max(num(el.loanAmountUp2.value),0)||Math.min(200000,Math.round(costUp2*0.9));
      const marginUp=Math.max(num(el.marginMoneyUp2.value),0)||Math.max(costUp2-loanUp,Math.round(costUp2*0.1));
      const higherLoanApplicable=isHigherLoanApplicableForCost(costAbove2);
      const loanHigh=higherLoanApplicable?(Math.max(num(el.loanAmountAbove2.value),0)||Math.round(costAbove2*0.8)):0;
      const marginHigh=higherLoanApplicable?(Math.max(num(el.marginMoneyAbove2.value),0)||Math.max(costAbove2-loanHigh,Math.round(costAbove2*0.2))):0;
      const effUp=Math.max(loanUp-subsidy,0), effHigh=higherLoanApplicable?Math.max(loanHigh-subsidy,0):0;
      const tenure=num(el.loanTenure.value,10); const emiUp=emi(effUp,num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure);
      const emiHigh=higherLoanApplicable?emi(effHigh,num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure):0;
      const loanMonths=Math.max(Math.round(tenure*12),0);
      const selfUpfront=Math.max(costSelf-subsidy,0);
      const paybackUpMonths=findPaybackMonths({upfront:marginUp,emiMonthly:emiUp,residualMonthly:residual,loanMonths,noSolarMonthly:monthlyBill});
      const paybackHighMonths=higherLoanApplicable?findPaybackMonths({upfront:marginHigh,emiMonthly:emiHigh,residualMonthly:residual,loanMonths,noSolarMonthly:monthlyBill}):null;
      const paybackSelfMonths=findPaybackMonths({upfront:selfUpfront,emiMonthly:0,residualMonthly:residual,loanMonths:0,noSolarMonthly:monthlyBill});

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
      cumulativeDatasets.push({label:'Self Funded',data:years.map(y=>costSelf-subsidy+y*12*residual),borderColor:'#f59e0b'});

      document.getElementById('results').hidden=false;
      const kpi=[['Expected monthly generation',`${solarUnits.toFixed(0)} units`],['Expected annual generation',`${(solarUnits*12).toFixed(0)} units`],['Units in 25 years',`${(solarUnits*12*25).toFixed(0)} units`],['Annual saving',INR(Math.max(monthlyBill-residual,0)*12)],['Estimated payback (self funded)',formatPayback(paybackSelfMonths)],['Roof area needed',`${(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0)} sq.ft`],['Bill offset',`${Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1)}%`],['Annual CO₂ reduction',`${((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['25-year CO₂ reduction',`${((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['Tree equivalent',`${(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)).toFixed(0)} trees`]];
      kpiPanel.innerHTML=kpi.map(([k,v])=>`<div class='sf-metric'><strong>${k}</strong><div>${v}</div></div>`).join('');

      const payback=[['Loan up to 2 lacs', paybackUpMonths]];
      if(higherLoanApplicable){payback.push(['Loan above 2 lacs',paybackHighMonths]);}
      payback.push(['Self Funded',paybackSelfMonths]);
      paybackMeters.innerHTML=payback.map(([n,m])=>`<div class='sf-metric'><strong>${n}</strong><div>${formatPayback(m)}</div></div>`).join('');

      const finData=[
        ['No Solar',[['Monthly bill',INR(monthlyBill)],['25 year expense',INR(monthlyBill*12*25)]]],
        ['Loan up to 2 lacs',[['System cost',INR(costUp2)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanUp)],['Loan - subsidy',INR(effUp)],['Margin',INR(marginUp)],['Interest',`${el.interestRateUp2.value||d.interest_upto_2_lacs||6}%`],['Tenure',`${tenure} years`],['EMI',INR(emiUp)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiUp+residual)]]],
      ];
      if(higherLoanApplicable){
        finData.push(['Loan above 2 lacs',[['System cost',INR(costAbove2)],['Subsidy',INR(subsidy)],['Loan amount',INR(loanHigh)],['Loan - subsidy',INR(effHigh)],['Margin',INR(marginHigh)],['Interest',`${el.interestRateAbove2.value||d.interest_above_2_lacs||8.15}%`],['Tenure',`${tenure} years`],['EMI',INR(emiHigh)],['Residual bill',INR(residual)],['Total monthly outflow',INR(emiHigh+residual)]]]);
      }
      finData.push(['Self Funded',[['System cost',INR(costSelf)],['Subsidy',INR(subsidy)],['Net investment',INR(costSelf-subsidy)],['Residual bill',INR(residual)],['Monthly saving',INR(monthlyBill-residual)],['Annual saving',INR((monthlyBill-residual)*12)]]]);
      financeBoxes.innerHTML=finData.map(([t,rows])=>`<div class='sf-metric'><strong>${t}</strong><ul>${rows.map(r=>`<li>${r[0]}: <b>${r[1]}</b></li>`).join('')}</ul></div>`).join('');

      if(mChart) mChart.destroy(); if(cChart) cChart.destroy();
      mChart=new Chart(monthlyChart,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors}]},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Scenario'}},y:{title:{display:true,text:'Monthly Outflow (₹)'}}}}});
      cChart=new Chart(cumulativeChart,{type:'line',data:{labels:years,datasets:cumulativeDatasets},options:{scales:{x:{title:{display:true,text:'Years'}},y:{title:{display:true,text:'Cumulative Expense (₹)'}}}}});
      latestComputation={
        timestamp:new Date(),
        monthlyBill,units:units||Math.round(monthlyBill/rate),systemType:el.systemType.value,size,gen,rate,subsidy,tenure,
        solarUnits,annualGeneration:solarUnits*12,generation25:solarUnits*12*25,annualSaving:Math.max(monthlyBill-residual,0)*12,residual,monthlyOutflowUp2:emiUp+residual,monthlyOutflowHigh:emiHigh+residual,
        billOffset:Math.min((solarValue/Math.max(monthlyBill,1))*100,100),roofArea:(size*Number(d.roof_area_sqft_per_kw||100)),annualCo2:((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)),
        co225:((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82)),trees:(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)),
        costs:{costSelf,costUp2,costAbove2,loanUp,marginUp,loanHigh,marginHigh,effUp,effHigh,emiUp,emiHigh,higherLoanApplicable,selfUpfront},
        paybacks:{up2:paybackUpMonths,high:paybackHighMonths,self:paybackSelfMonths},
        finance:finData,
        hybridSummary:el.systemType.value==='Hybrid'?`${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A',
      };

      const hy=el.systemType.value==='Hybrid'?`Hybrid: ${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A';
      const msg=`Hello Dakshayani, I want a solar quotation.%0A- Monthly bill: ${INR(monthlyBill)}%0A- Monthly units: ${units||Math.round(monthlyBill/rate)}%0A- System type: ${el.systemType.value}%0A- Solar size: ${size} kW%0A- System cost (Self): ${INR(costSelf)}%0A- System cost (Loan up to 2 lacs): ${INR(costUp2)}%0A- System cost (Loan above 2 lacs): ${INR(costAbove2)}%0A- Subsidy: ${INR(subsidy)}%0A- Loan amount (up to 2 lacs): ${INR(loanUp)}%0A- Margin money (up to 2 lacs): ${INR(marginUp)}%0A- Interest (up to 2 lacs): ${el.interestRateUp2.value||d.interest_upto_2_lacs||6}%0A- Loan amount (above 2 lacs): ${INR(loanHigh)}%0A- Margin money (above 2 lacs): ${INR(marginHigh)}%0A- Interest (above 2 lacs): ${el.interestRateAbove2.value||d.interest_above_2_lacs||8.15}%0A- Tenure: ${tenure} years%0A- Hybrid variation: ${hy}%0A- Monthly generation: ${solarUnits.toFixed(0)} units%0A- Annual saving: ${INR((monthlyBill-residual)*12)}`;
      waQuote.href=`https://wa.me/917070278178?text=${msg}`;
    }

    function createReport(){
      reportValidationMsg.textContent='';
      if(!latestComputation){
        reportValidationMsg.textContent='Please calculate solar and finance results before creating a report.';
        return;
      }
      const name=(el.customerName.value||'').trim();
      const location=(el.customerLocation.value||'').trim();
      const mobile=normalizeIndianMobile(el.customerMobile.value||'');
      if(!name || !location || !mobile){
        reportValidationMsg.textContent='Customer name, location and mobile number are required to generate a report.';
        return;
      }
      const monthlyChartImg=document.getElementById('monthlyChart')?.toDataURL('image/png')||'';
      const cumulativeChartImg=document.getElementById('cumulativeChart')?.toDataURL('image/png')||'';
      const p=latestComputation;
      const reportWindow=window.open('', '_blank');
      if(!reportWindow){
        reportValidationMsg.textContent='Pop-up blocked. Please allow pop-ups to open the report.';
        return;
      }
      const paybackItems=[['Loan up to 2 lacs',p.paybacks.up2]];
      if(p.costs.higherLoanApplicable){paybackItems.push(['Loan above 2 lacs',p.paybacks.high]);}
      paybackItems.push(['Self Funded',p.paybacks.self]);
      const financeHtml=p.finance.map(([title,rows])=>`<div style="border:1px solid #d8e1ef;border-radius:10px;padding:10px"><h4 style="margin:0 0 6px">${title}</h4><ul style="margin:0;padding-left:18px">${rows.map(([k,v])=>`<li>${k}: <strong>${v}</strong></li>`).join('')}</ul></div>`).join('');
      const html=`<!doctype html><html><head><meta charset="utf-8"><title>Solar & Finance Report</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#0f172a}h1,h2,h3{margin:0 0 10px}section{margin:0 0 20px;border:1px solid #e2e8f0;border-radius:12px;padding:14px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #e2e8f0;padding:8px;text-align:left}img{max-width:100%;border:1px solid #e2e8f0;border-radius:8px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.muted{color:#475569}</style></head><body>
      <h1>Dakshayani Solar & Finance Report</h1>
      <p class="muted">Customer-facing report for sharing and print.</p>
      <section><h2>1) Customer details</h2><table><tr><th>Name</th><td>${name}</td></tr><tr><th>Location</th><td>${location}</td></tr><tr><th>Mobile</th><td>${mobile}</td></tr><tr><th>Report date/time</th><td>${p.timestamp.toLocaleString('en-IN')}</td></tr></table></section>
      <section><h2>2) Inputs used</h2><table>
      <tr><th>Monthly bill</th><td>${INR(p.monthlyBill)}</td><th>Monthly units</th><td>${p.units}</td></tr>
      <tr><th>System type</th><td>${p.systemType}</td><th>Solar size</th><td>${p.size} kW</td></tr>
      <tr><th>Daily generation/kW</th><td>${p.gen}</td><th>Unit rate</th><td>${INR(p.rate)}</td></tr>
      <tr><th>System cost (self)</th><td>${INR(p.costs.costSelf)}</td><th>System cost (loan ≤2L)</th><td>${INR(p.costs.costUp2)}</td></tr>
      <tr><th>System cost (loan &gt;2L)</th><td>${INR(p.costs.costAbove2)}</td><th>Subsidy</th><td>${INR(p.subsidy)}</td></tr>
      <tr><th>Loan/margin ≤2L</th><td>${INR(p.costs.loanUp)} / ${INR(p.costs.marginUp)}</td><th>Interest ≤2L</th><td>${el.interestRateUp2.value||d.interest_upto_2_lacs||6}%</td></tr>
      <tr><th>Loan/margin &gt;2L</th><td>${INR(p.costs.loanHigh)} / ${INR(p.costs.marginHigh)}</td><th>Interest &gt;2L</th><td>${el.interestRateAbove2.value||d.interest_above_2_lacs||8.15}%</td></tr>
      <tr><th>Tenure</th><td>${p.tenure} years</td><th>Hybrid config</th><td>${p.hybridSummary}</td></tr></table></section>
      <section><h2>3) At a glance summary</h2><div class="grid"><div><strong>No Solar monthly</strong><div>${INR(p.monthlyBill)}</div></div><div><strong>Loan ≤2L monthly</strong><div>${INR(p.monthlyOutflowUp2)}</div></div>${p.costs.higherLoanApplicable?`<div><strong>Loan &gt;2L monthly</strong><div>${INR(p.monthlyOutflowHigh)}</div></div>`:''}<div><strong>Self funded monthly</strong><div>${INR(p.residual)}</div></div><div><strong>Recommended solar size</strong><div>${p.size} kW</div></div></div></section>
      <section><h2>4) Charts</h2><h3>Monthly outflow comparison graph</h3><img src="${monthlyChartImg}" alt="Monthly outflow chart"><h3>Cumulative expense over 25 years graph</h3><img src="${cumulativeChartImg}" alt="Cumulative expense chart"><h3>Payback meters</h3><ul>${paybackItems.map(([label,months])=>`<li>${label}: <strong>${formatPayback(months)}</strong></li>`).join('')}</ul></section>
      <section><h2>5) Financial clarity</h2><div class="grid">${financeHtml}</div></section>
      <section><h2>6) Generation estimate</h2><table><tr><th>Monthly generation</th><td>${p.solarUnits.toFixed(0)} units</td><th>Annual generation</th><td>${p.annualGeneration.toFixed(0)} units</td></tr><tr><th>25-year generation</th><td>${p.generation25.toFixed(0)} units</td><th>Savings estimate</th><td>${INR(p.annualSaving)} / year</td></tr><tr><th>Roof area</th><td>${p.roofArea.toFixed(0)} sq.ft</td><th>Bill offset</th><td>${p.billOffset.toFixed(1)}%</td></tr></table></section>
      <section><h2>7) Green impact</h2><table><tr><th>Annual CO₂ reduction</th><td>${p.annualCo2.toFixed(0)} kg</td></tr><tr><th>25-year CO₂ reduction</th><td>${p.co225.toFixed(0)} kg</td></tr><tr><th>Trees equivalent</th><td>${p.trees.toFixed(0)} trees</td></tr></table></section>
      <section><h2>8) Footer / CTA</h2><p>Thank you for considering solar with Dakshayani Enterprises. For installation and quotation support, please contact our team.</p></section>
      </body></html>`;
      reportWindow.document.open();
      reportWindow.document.write(html);
      reportWindow.document.close();
    }

    function resetAllFields(){
      manualOverride.clear();
      Object.entries(defaultState).forEach(([key,val])=>setField(key,val));
      debouncedIds.forEach(clearUserEdited);
      [el.inverterKva,el.phase,el.batteryCount].forEach(node=>{node.innerHTML='';});
      clearResults();
      recalculateSolarFinance({changedField:'reset'});
    }

    function bindInput(id, eventName){
      el[id].addEventListener(eventName,()=>{
        if(!isProgrammaticUpdate) markUserEdited(id);
        const run=()=>recalculateSolarFinance({changedField:id});
        if(eventName==='input' && debouncedIds.includes(id) && id!=='systemType'){clearTimeout(debounceTimer); debounceTimer=setTimeout(run,220); return;}
        run();
      });
    }

    ['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','subsidy','loanTenure','systemCostSelf','systemCostUp2','systemCostAbove2','loanAmountUp2','marginMoneyUp2','interestRateUp2','loanAmountAbove2','marginMoneyAbove2','interestRateAbove2'].forEach(id=>bindInput(id,'input'));
    ['systemType','inverterKva','phase','batteryCount'].forEach(id=>bindInput(id,'change'));
    document.getElementById('calcBtn').addEventListener('click',()=>recalculateSolarFinance({changedField:'manualTrigger'}));
    document.getElementById('resetBtn').addEventListener('click',resetAllFields);
    document.getElementById('createReportBtn').addEventListener('click',createReport);
    resetAllFields();
  </script>

</body>
</html>
