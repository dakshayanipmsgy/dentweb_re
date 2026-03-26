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
    .sf-report{width:100%;max-width:none;padding:2rem;font-family:Arial,sans-serif;color:#111827}
    .sf-report h1,.sf-report h2,.sf-report h3{margin:.2rem 0 .65rem}
    .sf-report .meta,.sf-report .grid{display:grid;gap:.8rem}
    .sf-report .meta{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .sf-report .grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin:1rem 0}
    .sf-report .box{border:1px solid #d1d9e6;border-radius:12px;padding:.9rem;background:#fff}
    .sf-report table{width:100%;border-collapse:collapse;margin-top:.4rem}
    .sf-report th,.sf-report td{border:1px solid #d1d9e6;padding:.45rem;text-align:left;font-size:.92rem}
    .sf-report .note{font-size:.86rem;color:#4b5563}
    @media print{.sf-report{padding:.5rem}.no-print{display:none!important}}
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
        <h4 style="margin:.9rem 0 .4rem">Customer details (optional)</h4>
        <div class="sf-inputs">
          <label>Customer Name<input type="text" id="customerName" placeholder="Enter customer name"></label>
          <label>Location<input type="text" id="customerLocation" placeholder="Enter location"></label>
          <label>Phone Number<input type="tel" id="customerPhone" placeholder="Enter phone number"></label>
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
      customerPhone:'',
    };
    let mChart,cChart,debounceTimer=null,isProgrammaticUpdate=false;
    let latestReportData=null;
    const manualOverride=new Set();
    const debouncedIds=['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','subsidy','loanTenure','systemCostSelf','systemCostUp2','systemCostAbove2','loanAmountUp2','marginMoneyUp2','interestRateUp2','loanAmountAbove2','marginMoneyAbove2','interestRateAbove2'];
    const ids=[...debouncedIds,'systemType','inverterKva','phase','batteryCount','loanAboveGroupWrap','customerName','customerLocation','customerPhone'];
    const el=Object.fromEntries(ids.map(id=>[id,document.getElementById(id)]));
    const kpiPanel=document.getElementById('kpiPanel'),paybackMeters=document.getElementById('paybackMeters'),financeBoxes=document.getElementById('financeBoxes'),waQuote=document.getElementById('waQuote');
    const createReportBtn=document.getElementById('createReportBtn');

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
    const esc=s=>String(s??'').replace(/[&<>"']/g,ch=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch]));
    const formatPaybackText=month=>month===null?'Not recovered within 25 years':`${(month/12).toFixed(1)} years`;
    const buildCumulativeSeries=(upfront,emiMonthly,residualMonthly,tenureMonths,horizonMonths)=>{
      const series=[Math.max(upfront,0)];
      for(let month=1;month<=horizonMonths;month++){
        const emiPart=month<=tenureMonths?Math.max(emiMonthly,0):0;
        series.push(series[month-1]+emiPart+Math.max(residualMonthly,0));
      }
      return series;
    };
    const buildNoSolarSeries=(monthlyBill,horizonMonths)=>Array.from({length:horizonMonths+1},(_,idx)=>idx*Math.max(monthlyBill,0));
    const findPaybackMonth=(noSolarSeries,solarSeries)=>{
      for(let i=0;i<Math.min(noSolarSeries.length,solarSeries.length);i++){
        if(noSolarSeries[i]>=solarSeries[i]) return i;
      }
      return null;
    };

    function clearResults(){
      document.getElementById('results').hidden=true;
      kpiPanel.innerHTML=''; paybackMeters.innerHTML=''; financeBoxes.innerHTML='';
      latestReportData=null;
      if(mChart){mChart.destroy();mChart=null;} if(cChart){cChart.destroy();cChart=null;}
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
      const tenureMonths=Math.max(0,Math.round(tenure*12));
      const horizonMonths=25*12;
      const noSolarMonthlyCumulative=buildNoSolarSeries(monthlyBill,horizonMonths);
      const loanUpMonthlyCumulative=buildCumulativeSeries(marginUp,emiUp,residual,tenureMonths,horizonMonths);
      const loanHighMonthlyCumulative=higherLoanApplicable?buildCumulativeSeries(marginHigh,emiHigh,residual,tenureMonths,horizonMonths):null;
      const paybackLoanUpMonth=findPaybackMonth(noSolarMonthlyCumulative,loanUpMonthlyCumulative);
      const paybackLoanHighMonth=higherLoanApplicable?findPaybackMonth(noSolarMonthlyCumulative,loanHighMonthlyCumulative):null;

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
      const kpi=[['Expected monthly generation',`${solarUnits.toFixed(0)} units`],['Expected annual generation',`${(solarUnits*12).toFixed(0)} units`],['Units in 25 years',`${(solarUnits*12*25).toFixed(0)} units`],['Annual saving',INR(Math.max(monthlyBill-residual,0)*12)],['Estimated payback (self funded)',`${((costSelf-subsidy)/Math.max((monthlyBill-residual)*12,1)).toFixed(1)} years`],['Roof area needed',`${(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0)} sq.ft`],['Bill offset',`${Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1)}%`],['Annual CO₂ reduction',`${((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['25-year CO₂ reduction',`${((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82)).toFixed(0)} kg`],['Tree equivalent',`${(((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)).toFixed(0)} trees`]];
      kpiPanel.innerHTML=kpi.map(([k,v])=>`<div class='sf-metric'><strong>${k}</strong><div>${v}</div></div>`).join('');

      const payback=[['Loan up to 2 lacs',formatPaybackText(paybackLoanUpMonth)]];
      if(higherLoanApplicable){payback.push(['Loan above 2 lacs',formatPaybackText(paybackLoanHighMonth)]);}
      const selfPayback=((costSelf-subsidy)/Math.max((monthlyBill-residual)*12,1));
      payback.push(['Self Funded',`${isFinite(selfPayback)?selfPayback.toFixed(1):'N/A'} years`]);
      paybackMeters.innerHTML=payback.map(([n,p])=>`<div class='sf-metric'><strong>${n}</strong><div>${p}</div></div>`).join('');

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
      latestReportData={
        generatedAt:new Date(),
        customer:{
          name:document.getElementById('customerName').value.trim(),
          location:document.getElementById('customerLocation').value.trim(),
          phone:document.getElementById('customerPhone').value.trim()
        },
        summary:{
          systemType:el.systemType.value,recommendedSize:size,monthlyBill,monthlyUnits:units||Math.round(monthlyBill/rate),
          unitRate:rate,dailyGenerationPerKw:gen,systemCostSelf:costSelf,systemCostUp2:costUp2,systemCostAbove2:costAbove2
        },
        financial:{
          monthlyOutflow:{noSolar:monthlyBill,loanUp2:emiUp+residual,loanAbove2:higherLoanApplicable?(emiHigh+residual):null,selfFunded:residual},
          cumulative25y:{noSolar:noSolarMonthlyCumulative[horizonMonths],loanUp2:loanUpMonthlyCumulative[horizonMonths],loanAbove2:higherLoanApplicable?loanHighMonthlyCumulative[horizonMonths]:null,selfFunded:(costSelf-subsidy)+(horizonMonths*residual)},
          payback:{loanUp2:formatPaybackText(paybackLoanUpMonth),loanAbove2:higherLoanApplicable?formatPaybackText(paybackLoanHighMonth):null,selfFunded:`${isFinite(selfPayback)?selfPayback.toFixed(1):'N/A'} years`}
        },
        generation:{monthly:solarUnits,annual:solarUnits*12,years25:solarUnits*12*25,billOffset:Math.min((solarValue/Math.max(monthlyBill,1))*100,100),roofArea:size*Number(d.roof_area_sqft_per_kw||100)},
        green:{annualCo2:(solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82),years25Co2:(solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82),trees:((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)},
        charts:{monthly:mChart?.toBase64Image?.()||'',cumulative:cChart?.toBase64Image?.()||''}
      };

      const hy=el.systemType.value==='Hybrid'?`Hybrid: ${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A';
      const msg=`Hello Dakshayani, I want a solar quotation.%0A- Monthly bill: ${INR(monthlyBill)}%0A- Monthly units: ${units||Math.round(monthlyBill/rate)}%0A- System type: ${el.systemType.value}%0A- Solar size: ${size} kW%0A- System cost (Self): ${INR(costSelf)}%0A- System cost (Loan up to 2 lacs): ${INR(costUp2)}%0A- System cost (Loan above 2 lacs): ${INR(costAbove2)}%0A- Subsidy: ${INR(subsidy)}%0A- Loan amount (up to 2 lacs): ${INR(loanUp)}%0A- Margin money (up to 2 lacs): ${INR(marginUp)}%0A- Interest (up to 2 lacs): ${el.interestRateUp2.value||d.interest_upto_2_lacs||6}%0A- Loan amount (above 2 lacs): ${INR(loanHigh)}%0A- Margin money (above 2 lacs): ${INR(marginHigh)}%0A- Interest (above 2 lacs): ${el.interestRateAbove2.value||d.interest_above_2_lacs||8.15}%0A- Tenure: ${tenure} years%0A- Hybrid variation: ${hy}%0A- Monthly generation: ${solarUnits.toFixed(0)} units%0A- Annual saving: ${INR((monthlyBill-residual)*12)}`;
      waQuote.href=`https://wa.me/917070278178?text=${msg}`;
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
    createReportBtn.addEventListener('click',()=>{
      if(!latestReportData){return;}
      const data=latestReportData;
      const reportWin=window.open('','_blank');
      if(!reportWin){return;}
      const customer={
        name:document.getElementById('customerName').value.trim()||data.customer.name,
        location:document.getElementById('customerLocation').value.trim()||data.customer.location,
        phone:document.getElementById('customerPhone').value.trim()||data.customer.phone
      };
      const customerRows=[
        customer.name?`<div class="box"><strong>Customer Name</strong><div>${esc(customer.name)}</div></div>`:'',
        customer.location?`<div class="box"><strong>Location</strong><div>${esc(customer.location)}</div></div>`:'',
        customer.phone?`<div class="box"><strong>Phone Number</strong><div>${esc(customer.phone)}</div></div>`:''
      ].join('');
      const loanAboveHtml=data.financial.monthlyOutflow.loanAbove2!==null?`<tr><td>Loan above 2 lacs</td><td>${INR(data.financial.monthlyOutflow.loanAbove2)}</td><td>${INR(data.financial.cumulative25y.loanAbove2)}</td><td>${esc(data.financial.payback.loanAbove2)}</td></tr>`:'';
      reportWin.document.write(`<!doctype html><html><head><title>Solar and Finance Report</title><meta charset="utf-8"><style>
        body{margin:0;background:#f3f7ff}.sf-report{width:100%;max-width:1180px;margin:0 auto;padding:1.2rem;font-family:Arial,sans-serif;color:#111827}
        .sf-report h1,.sf-report h2,.sf-report h3{margin:.2rem 0 .65rem}.sf-report .meta,.sf-report .grid{display:grid;gap:.8rem}
        .sf-report .meta{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.sf-report .grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin:1rem 0}
        .sf-report .box{border:1px solid #d1d9e6;border-radius:12px;padding:.9rem;background:#fff}.sf-report table{width:100%;border-collapse:collapse;margin-top:.4rem}
        .sf-report th,.sf-report td{border:1px solid #d1d9e6;padding:.45rem;text-align:left;font-size:.92rem}.sf-report .note{font-size:.86rem;color:#4b5563}
        .sf-report img{max-width:100%;border:1px solid #d1d9e6;border-radius:8px;margin:.4rem 0}@media print{body{background:#fff}.sf-report{padding:.2rem}}
      </style></head><body><div class="sf-report">
        <h1>Solar and Finance Report</h1>
        <p>Date generated: ${esc(data.generatedAt.toLocaleString('en-IN'))}</p>
        ${customerRows?`<div class="meta">${customerRows}</div>`:''}
        <div class="grid">
          <div class="box"><h3>Main Summary</h3><table><tbody>
            <tr><td>System Type</td><td>${esc(data.summary.systemType)}</td></tr>
            <tr><td>Recommended / Selected Solar Size</td><td>${esc(data.summary.recommendedSize)} kW</td></tr>
            <tr><td>Average monthly bill</td><td>${INR(data.summary.monthlyBill)}</td></tr>
            <tr><td>Average monthly units</td><td>${esc(data.summary.monthlyUnits)} units</td></tr>
            <tr><td>Unit rate</td><td>${INR(data.summary.unitRate)} / kWh</td></tr>
            <tr><td>Daily solar generation per kW</td><td>${esc(data.summary.dailyGenerationPerKw)} units</td></tr>
            <tr><td>System cost (Self Funded)</td><td>${INR(data.summary.systemCostSelf)}</td></tr>
            <tr><td>System cost (Loan up to 2 lacs)</td><td>${INR(data.summary.systemCostUp2)}</td></tr>
            <tr><td>System cost (Loan above 2 lacs)</td><td>${INR(data.summary.systemCostAbove2)}</td></tr>
          </tbody></table></div>
          <div class="box"><h3>Generation Estimate</h3><table><tbody>
            <tr><td>Monthly generation</td><td>${esc(data.generation.monthly.toFixed(0))} units</td></tr>
            <tr><td>Annual generation</td><td>${esc(data.generation.annual.toFixed(0))} units</td></tr>
            <tr><td>25-year generation</td><td>${esc(data.generation.years25.toFixed(0))} units</td></tr>
            <tr><td>Bill offset</td><td>${esc(data.generation.billOffset.toFixed(1))}%</td></tr>
            <tr><td>Roof area needed</td><td>${esc(data.generation.roofArea.toFixed(0))} sq.ft</td></tr>
          </tbody></table></div>
        </div>
        <div class="box"><h3>Financial Comparison</h3><table><thead><tr><th>Scenario</th><th>Monthly Outflow</th><th>Cumulative Expense (25 years)</th><th>Payback meter</th></tr></thead><tbody>
          <tr><td>No Solar</td><td>${INR(data.financial.monthlyOutflow.noSolar)}</td><td>${INR(data.financial.cumulative25y.noSolar)}</td><td>-</td></tr>
          <tr><td>Loan up to 2 lacs</td><td>${INR(data.financial.monthlyOutflow.loanUp2)}</td><td>${INR(data.financial.cumulative25y.loanUp2)}</td><td>${esc(data.financial.payback.loanUp2)}</td></tr>
          ${loanAboveHtml}
          <tr><td>Self Funded</td><td>${INR(data.financial.monthlyOutflow.selfFunded)}</td><td>${INR(data.financial.cumulative25y.selfFunded)}</td><td>${esc(data.financial.payback.selfFunded)}</td></tr>
        </tbody></table></div>
        <div class="grid">
          <div class="box"><h3>Green Impact</h3><table><tbody>
            <tr><td>Annual CO₂ reduction</td><td>${esc(data.green.annualCo2.toFixed(0))} kg</td></tr>
            <tr><td>25-year CO₂ reduction</td><td>${esc(data.green.years25Co2.toFixed(0))} kg</td></tr>
            <tr><td>Tree equivalent</td><td>${esc(data.green.trees.toFixed(0))} trees</td></tr>
          </tbody></table></div>
          <div class="box"><h3>Assumptions Note</h3><p class="note">Figures are indicative and depend on actual site conditions, usage pattern, tariffs, and approvals.</p></div>
        </div>
        ${(data.charts.monthly||data.charts.cumulative)?`<div class="grid">${data.charts.monthly?`<div class="box"><h3>Monthly Outflow Comparison</h3><img src="${data.charts.monthly}" alt="Monthly outflow chart"></div>`:''}${data.charts.cumulative?`<div class="box"><h3>Cumulative Expense Over 25 Years</h3><img src="${data.charts.cumulative}" alt="Cumulative expense chart"></div>`:''}</div>`:''}
        <p class="note">Tip: Use Ctrl+P (or ⌘+P) to print or save as PDF.</p>
      </div></body></html>`);
      reportWin.document.close();
    });
    resetAllFields();
  </script>

</body>
</html>
