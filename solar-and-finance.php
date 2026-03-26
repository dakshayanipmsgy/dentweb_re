<?php
require_once __DIR__ . '/includes/bootstrap.php';
$settings = solar_finance_settings();
$payload = htmlspecialchars(json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Solar and Finance | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="/style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    .sf-wrap{width:100%;max-width:none;padding:2rem clamp(1rem,3vw,3rem) 4rem;background:#f8fafc}
    .sf-hero,.sf-card,.sf-panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1.25rem;box-shadow:0 8px 20px rgba(15,23,42,.06)}
    .sf-hero{display:grid;gap:1rem;grid-template-columns:2fr 1fr;margin-bottom:1rem}
    .sf-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .sf-input-grid{display:grid;gap:.8rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
    .sf-result-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    .sf-chart-bars .bar{height:28px;background:#0f766e;border-radius:999px;color:#fff;padding:0 .6rem;display:flex;align-items:center;white-space:nowrap;font-size:.86rem;margin:.5rem 0}
    .sf-cumulative{height:220px;background:linear-gradient(180deg,#eff6ff,#fff);border-radius:12px;border:1px solid #dbeafe;padding:.4rem}
    .sf-row{display:flex;justify-content:space-between;gap:.8rem;border-bottom:1px dashed #e2e8f0;padding:.35rem 0}
    .sf-muted{color:#64748b;font-size:.9rem}
    details.sf-panel summary{cursor:pointer;font-weight:700}
    .wa-cta{display:inline-flex;align-items:center;gap:.5rem;background:#16a34a;color:#fff;padding:.8rem 1rem;border-radius:999px;text-decoration:none;font-weight:700}
    @media(max-width:900px){.sf-hero{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div id="header-placeholder"></div>
<main class="sf-wrap">
  <section class="sf-hero">
    <div>
      <h1>Solar and Finance</h1>
      <p>Understand rooftop solar in simple language, check suitable system size, compare finance options, and request a quotation instantly on WhatsApp.</p>
    </div>
    <div class="sf-card">
      <h3>How this helps</h3>
      <ul>
        <li>Know your solar requirement</li>
        <li>Compare loan and self-funded outcomes</li>
        <li>See monthly and long-term expense impact</li>
      </ul>
    </div>
  </section>

  <section class="sf-panel">
    <h2>Quick solar education</h2>
    <div class="sf-grid" id="explainerCards"></div>
  </section>

  <section class="sf-panel" style="margin-top:1rem">
    <h2>Mandatory inputs</h2>
    <div class="sf-input-grid">
      <label>Average monthly bill (₹)<input type="number" id="monthlyBill" min="0" placeholder="e.g. 3500"></label>
      <label>Average monthly units<input type="number" id="monthlyUnits" min="0" placeholder="e.g. 420"></label>
      <label>System type
        <select id="systemType"><option value="on-grid">On-grid</option><option value="hybrid">Hybrid</option></select>
      </label>
      <label>Unit rate (₹/kWh)<input type="number" id="unitRate" step="0.01"></label>
    </div>

    <details class="sf-panel" style="margin-top:1rem">
      <summary>Advanced / Pro Inputs</summary>
      <div class="sf-input-grid" style="margin-top:.8rem">
        <label>Solar size (kWp)<input type="number" id="solarSize" step="0.1"></label>
        <label>Daily generation per kW<input type="number" id="dailyGen" step="0.1"></label>
        <label>System cost (₹)<input type="number" id="systemCost"></label>
        <label>Subsidy (₹)<input type="number" id="subsidy" value="0"></label>
        <label>Loan amount (₹)<input type="number" id="loanAmount"></label>
        <label>Margin money (₹)<input type="number" id="marginMoney"></label>
        <label>Interest rate (%)<input type="number" id="interestRate" step="0.01"></label>
        <label>Loan tenure (years)<input type="number" id="tenure"></label>
        <label id="kvaWrap">Inverter kVA<select id="hybridKva"></select></label>
        <label id="phaseWrap">Phase<select id="hybridPhase"></select></label>
        <label id="batteryWrap">Battery count<select id="hybridBattery"></select></label>
      </div>
      <p class="sf-muted" id="hybridInfo"></p>
    </details>

    <button class="btn btn-primary" id="runCalc" style="margin-top:1rem">How solar adoption would look like</button>
    <p class="sf-muted" id="calculationNote"></p>
  </section>

  <section class="sf-panel" id="results" style="margin-top:1rem" hidden>
    <h2>Results</h2>
    <div class="sf-result-grid" id="recommendation"></div>

    <h3>Monthly outflow comparison</h3>
    <div class="sf-chart-bars" id="monthlyBars"></div>

    <h3>Cumulative expense over 25 years</h3>
    <svg id="cumulativeLine" class="sf-cumulative" viewBox="0 0 1000 260" preserveAspectRatio="none"></svg>

    <h3>Payback meters</h3>
    <div class="sf-grid" id="paybacks"></div>

    <h3>Financial clarity</h3>
    <div class="sf-result-grid" id="financialCards"></div>

    <h3>Generation estimate & green impact</h3>
    <div class="sf-result-grid" id="impactCards"></div>

    <a class="wa-cta" id="waQuote" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Request a Quotation</a>
  </section>
</main>
<footer class="site-footer"></footer>
<script id="solar-finance-settings" type="application/json"><?= $payload ?></script>
<script src="/script.js" defer></script>
<script>
(function(){
const settings = JSON.parse(document.getElementById('solar-finance-settings').textContent || '{}');
const d = settings.defaults || {};
const onGrid = settings.on_grid_prices || [];
const hybrid = settings.hybrid_prices || [];
const state = {lastTouched: null, manualSolar:false};
const q = (id)=>document.getElementById(id);
const INR = (n)=>new Intl.NumberFormat('en-IN',{maximumFractionDigits:0}).format(Math.max(0, Number(n)||0));
['unitRate','dailyGen','interestRate','tenure'].forEach((id, i)=> q(id).value = [d.unit_rate,d.daily_generation_per_kw,d.interest_rate,d.loan_tenure_years][i] ?? '');
q('systemType').addEventListener('change', syncHybridOptions);
q('monthlyBill').addEventListener('input', ()=>{state.lastTouched='bill';syncBillUnits();});
q('monthlyUnits').addEventListener('input', ()=>{state.lastTouched='units';syncBillUnits();});
q('solarSize').addEventListener('input', ()=>{state.manualSolar=true;});
function syncBillUnits(){
  const rate=Number(q('unitRate').value)||8,b=Number(q('monthlyBill').value)||0,u=Number(q('monthlyUnits').value)||0;
  if(state.lastTouched==='bill' && b>0){q('monthlyUnits').value=(b/rate).toFixed(1);} 
  if(state.lastTouched==='units' && u>0){q('monthlyBill').value=(u*rate).toFixed(0);} 
}
function solarCeil(v){const arr=[...new Set(onGrid.map(r=>Number(r.solar_kwp)).filter(Boolean))].sort((a,b)=>a-b);return arr.find(x=>x>=v)||arr[arr.length-1]||0;}
function emi(principal, annual, years){const p=Math.max(0,principal),r=(annual/12)/100,n=Math.max(1,years*12);if(r===0)return p/n;const pow=Math.pow(1+r,n);return p*r*pow/(pow-1);}
function syncHybridOptions(){
  const type=q('systemType').value;
  ['kvaWrap','phaseWrap','batteryWrap'].forEach(id=>q(id).style.display= type==='hybrid'?'block':'none');
  if(type!=='hybrid') return;
  const size = Math.round(Number(q('solarSize').value||0));
  const rows = hybrid.filter(r=>Number(r.solar_kwp)===size);
  const fill=(id,vals)=>{q(id).innerHTML='<option value="">Default</option>'+vals.map(v=>`<option>${v}</option>`).join('');};
  fill('hybridKva',[...new Set(rows.map(r=>r.kva))]);
  fill('hybridPhase',[...new Set(rows.map(r=>r.phase))]);
  fill('hybridBattery',[...new Set(rows.map(r=>r.battery_count))]);
}
function pickCost(type, solarSize){
  const priceSize = solarCeil(solarSize);
  if(type==='on-grid'){
    const row = onGrid.find(r=>Number(r.solar_kwp)===priceSize);
    return {priceSize,row};
  }
  const rows=hybrid.filter(r=>Number(r.solar_kwp)===priceSize);
  if(!rows.length)return {priceSize,row:null,msg:'No hybrid baseline found for this size.'};
  const k=q('hybridKva').value,p=q('hybridPhase').value,b=q('hybridBattery').value;
  if(k||p||b){
    const exact=rows.find(r=>(!k||String(r.kva)===k)&&(!p||r.phase===p)&&(!b||String(r.battery_count)===b));
    if(!exact)return {priceSize,row:rows[0],msg:'No hybrid price combination found for this exact selection.'};
    return {priceSize,row:exact};
  }
  return {priceSize,row:rows[0]};
}
q('runCalc').addEventListener('click', ()=>{
  syncBillUnits();
  const bill=Number(q('monthlyBill').value)||0,units=Number(q('monthlyUnits').value)||0,rate=Number(q('unitRate').value)||8;
  if(!(bill>0||units>0)){q('calculationNote').textContent='Enter at least bill or units.';return;}
  const mUnits = units>0?units:bill/rate;
  if(!state.manualSolar){q('solarSize').value=(mUnits/((Number(q('dailyGen').value)||5)*30)).toFixed(2);}
  const solarSize=Number(q('solarSize').value)||0,dailyGen=Number(q('dailyGen').value)||5,type=q('systemType').value;
  const costPick=pickCost(type,solarSize);
  if(!q('systemCost').value){q('systemCost').value=costPick.row ? costPick.row.loan_upto_2_lac : 0;}
  q('calculationNote').textContent = `Recommended size ${solarSize.toFixed(2)} kWp. Pricing basis ${costPick.priceSize||'-'} kWp. ${costPick.msg||''}`;

  const monthlySolar=solarSize*dailyGen*30, monthlyValue=monthlySolar*rate,residual=Math.max(bill-monthlyValue,0);
  const roof=solarSize*(Number(d.roof_area_sqft_per_kw)||100),offset=bill>0?Math.min(100,(monthlyValue/bill)*100):0;
  const subsidy=Number(q('subsidy').value)||0,interest=Number(q('interestRate').value)||6,tenure=Number(q('tenure').value)||10;
  const sys1 = costPick.row? Number(costPick.row.loan_upto_2_lac):Number(q('systemCost').value)||0;
  const sys2 = costPick.row? Number(costPick.row.loan_above_2_lac):Number(q('systemCost').value)||0;
  const loan1=Math.min(sys1,200000), margin1=sys1-loan1, eff1=Math.max(loan1-subsidy,0), emi1=emi(eff1,interest,tenure);
  const margin2=Number(q('marginMoney').value)||Number(d.loan_above_2_lakh_default_margin_money)||80000; const loan2=Math.max(sys2-margin2,0), eff2=Math.max(loan2-subsidy,0), emi2=emi(eff2,interest,tenure);
  q('loanAmount').value=Math.round(loan2); q('marginMoney').value=Math.round(margin2); q('systemCost').value=Math.round(type==='on-grid'?sys1:sys2);

  const scen={
    no:bill,
    l2:emi1+residual,
    labove:emi2+residual,
    self:residual
  };
  const maxV=Math.max(...Object.values(scen),1);
  q('monthlyBars').innerHTML = Object.entries({'No Solar':scen.no,'Loan upto ₹2 lacs':scen.l2,'Loan above ₹2 lacs':scen.labove,'Self Funded':scen.self}).map(([k,v])=>`<div class='bar' style='width:${(v/maxV*100).toFixed(1)}%'>${k}: ₹${INR(v)}/mo</div>`).join('');

  const years=[...Array(26).keys()];
  const cum=(monthly,upfront=0,emiM=0)=>years.map(y=>upfront + ((y*12)*(monthly + (y<tenure?emiM:0))));
  const lines=[{n:'No Solar',c:'#ef4444',d:cum(bill)},{n:'Loan <=2L',c:'#0ea5e9',d:cum(residual,margin1,emi1)},{n:'Loan >2L',c:'#6366f1',d:cum(residual,margin2,emi2)},{n:'Self',c:'#16a34a',d:cum(residual,Math.max((type==='on-grid'?sys1:sys2)-subsidy,0),0)}];
  const maxY=Math.max(...lines.flatMap(l=>l.d),1);
  const svg=q('cumulativeLine');
  svg.innerHTML = lines.map(l=>{const pts=l.d.map((v,i)=>`${(i/25)*1000},${250-(v/maxY*220)}`).join(' ');return `<polyline fill='none' stroke='${l.c}' stroke-width='4' points='${pts}'/><text x='12' y='${20+18*lines.indexOf(l)}' fill='${l.c}'>${l.n}</text>`;}).join('');

  const yearlyValue=monthlyValue*12, save25=yearlyValue*25;
  q('recommendation').innerHTML = `
    <div class='sf-card'><h4>Recommendation summary</h4><div class='sf-row'><span>Recommended size</span><strong>${solarSize.toFixed(2)} kWp</strong></div><div class='sf-row'><span>Roof area needed</span><strong>${INR(roof)} sq ft</strong></div><div class='sf-row'><span>Likely monthly generation</span><strong>${INR(monthlySolar)} units</strong></div><div class='sf-row'><span>Estimated bill offset</span><strong>${offset.toFixed(1)}%</strong></div></div>
    <div class='sf-card'><h4>Best picks</h4><p>Lowest monthly outflow: <strong>${Object.entries(scen).sort((a,b)=>a[1]-b[1])[0][0]}</strong></p><p>Lowest upfront: <strong>Loan up to ₹2 lacs</strong></p><p>Highest lifetime savings: <strong>Self funded</strong> (usually)</p></div>`;
  q('paybacks').innerHTML = [
    ['Loan upto ₹2 lacs',(margin1+subsidy)/(Math.max(bill-scen.l2,1))*12],
    ['Loan above ₹2 lacs',(margin2+subsidy)/(Math.max(bill-scen.labove,1))*12],
    ['Self funded',Math.max((type==='on-grid'?sys1:sys2)-subsidy,0)/(Math.max(bill-residual,1))*12]
  ].map(([k,m])=>`<div class='sf-card'><h4>${k}</h4><p><strong>${(m/12).toFixed(1)} years</strong> approx payback</p><progress max='25' value='${Math.min(25,m/12)}' style='width:100%'></progress></div>`).join('');

  q('financialCards').innerHTML = `
    <div class='sf-card'><h4>No Solar</h4><p>Monthly bill: ₹${INR(bill)}</p><p>25-year expense: ₹${INR(bill*12*25)}</p></div>
    <div class='sf-card'><h4>Loan up to ₹2 lacs</h4><p>System cost: ₹${INR(sys1)}</p><p>Subsidy: ₹${INR(subsidy)}</p><p>Loan amount: ₹${INR(loan1)}</p><p>Margin money: ₹${INR(margin1)}</p><p>Loan amount minus subsidy: ₹${INR(eff1)}</p><p>EMI: ₹${INR(emi1)}</p><p>Residual bill: ₹${INR(residual)}</p><p>Total monthly outflow: ₹${INR(scen.l2)}</p></div>
    <div class='sf-card'><h4>Loan above ₹2 lacs</h4><p>System cost: ₹${INR(sys2)}</p><p>Subsidy: ₹${INR(subsidy)}</p><p>Loan amount: ₹${INR(loan2)}</p><p>Margin money: ₹${INR(margin2)}</p><p>Loan amount minus subsidy: ₹${INR(eff2)}</p><p>EMI: ₹${INR(emi2)}</p><p>Residual bill: ₹${INR(residual)}</p><p>Total monthly outflow: ₹${INR(scen.labove)}</p></div>
    <div class='sf-card'><h4>Self Funded</h4><p>System cost: ₹${INR(type==='on-grid'?sys1:sys2)}</p><p>Subsidy: ₹${INR(subsidy)}</p><p>Net investment after subsidy: ₹${INR(Math.max((type==='on-grid'?sys1:sys2)-subsidy,0))}</p><p>Residual bill: ₹${INR(residual)}</p><p>Estimated monthly saving: ₹${INR(Math.max(bill-residual,0))}</p><p>Estimated annual saving: ₹${INR(Math.max(bill-residual,0)*12)}</p></div>`;

  const co2Year=monthlySolar*12*(Number(d.emission_factor_kg_per_kwh)||0.82), co2_25=co2Year*25, trees=co2Year/(Number(d.co2_per_tree_kg)||21);
  q('impactCards').innerHTML = `
    <div class='sf-card'><h4>Generation estimate</h4><p>Monthly generation: ${INR(monthlySolar)} units</p><p>Annual generation: ${INR(monthlySolar*12)} units</p><p>25-year generation: ${INR(monthlySolar*12*25)} units</p><p>Estimated annual solar value: ₹${INR(yearlyValue)}</p><p>₹ saved in 25 years: ₹${INR(save25)}</p><p>Bill offset: ${offset.toFixed(1)}%</p></div>
    <div class='sf-card'><h4>Green impact</h4><p>Annual CO₂ avoided: ${INR(co2Year)} kg</p><p>25-year CO₂ avoided: ${INR(co2_25)} kg</p><p>Equivalent trees planted: ${INR(trees)} / year</p></div>`;

  const msg = encodeURIComponent(`Hello Dakshayani team,%0AI checked Solar and Finance on your website.%0ASystem type: ${type}%0AMonthly bill: ₹${Math.round(bill)}%0AMonthly units: ${mUnits.toFixed(1)}%0ASolar size: ${solarSize.toFixed(2)} kWp%0ASystem cost: ₹${Math.round(type==='on-grid'?sys1:sys2)}%0ASubsidy: ₹${Math.round(subsidy)}%0ALoan amount: ₹${Math.round(loan2)}%0AMargin money: ₹${Math.round(margin2)}%0AInterest: ${interest}%0ATenure: ${tenure} years%0APlease share a quotation for this requirement.`);
  q('waQuote').href = `https://wa.me/917070278178?text=${msg}`;
  q('results').hidden=false;
  syncHybridOptions();
});
(function loadExplainers(){
  const cards = (settings.explainer && settings.explainer.cards) || [];
  q('explainerCards').innerHTML = cards.map(c=>`<article class='sf-card'><h3><i class='fa-solid ${c.icon||'fa-circle-info'}'></i> ${c.title||''}</h3><p>${c.body||''}</p></article>`).join('');
})();
syncHybridOptions();
})();
</script>
</body>
</html>
