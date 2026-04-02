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
    .sf-card{padding:1.1rem}.sf-card h3{margin:.4rem 0}.sf-flex{display:grid;grid-template-columns:1fr;gap:1rem}.sf-pro{margin-top:1rem}
    .sf-inputs{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.8rem}.sf-inputs label{display:flex;flex-direction:column;gap:.35rem;font-weight:600;font-size:.92rem}
    .sf-inputs input,.sf-inputs select{padding:.55rem .65rem;border:1px solid #c6d1e2;border-radius:10px}.sf-results{margin-top:1.2rem}.sf-metric{background:#f5f8ff;border:1px solid #d4def1;border-radius:12px;padding:.85rem}
    .sf-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.7rem}
    .sf-finance-table-wrap{margin-top:.65rem;overflow-x:auto}
    .sf-finance-table{width:100%;min-width:760px;border-collapse:collapse;background:#fbfdff}
    .sf-finance-table th,.sf-finance-table td{border:1px solid #dbe4f0;padding:.6rem .65rem;text-align:left;vertical-align:top}
    .sf-finance-table thead th{background:#f1f6ff;font-size:.92rem}
    .sf-finance-table tbody th{font-weight:700;color:#1f2d46;background:#f8fbff;white-space:nowrap}
    .sf-finance-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem;margin:.65rem 0}
    .sf-finance-line{background:#f8fbff;border:1px solid #dbe4f0;border-radius:10px;padding:.6rem .75rem;font-size:.92rem;color:#1f2d46}
    .sf-finance-note{margin-top:.75rem;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:.7rem .85rem;font-size:.92rem;color:#164e63}
    .sf-btn{background:#0f766e;color:#fff;padding:.7rem 1rem;border:none;border-radius:10px;font-weight:700;cursor:pointer}
    .sf-btn.alt{background:#1d4ed8}.sf-btn.report{background:#0b5ed7}.sf-note{font-size:.85rem;color:#51607a}.sf-customer{margin-top:1rem}.sf-error{font-size:.82rem;color:#b91c1c;margin-top:.35rem}
    .sf-glance{margin-top:1rem}.sf-glance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
    .sf-glance-group{background:#f8faff;border:1px solid #dbe6f7;border-radius:14px;padding:1rem}
    .sf-glance-group h3{margin:0 0 .7rem;font-size:1rem}
    .sf-glance-list{display:grid;gap:.45rem}
    .sf-glance-item{display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px dashed #d8e1ef}
    .sf-glance-item:last-child{border-bottom:none;padding-bottom:0}
    .sf-glance-label{font-weight:600;color:#23324d;font-size:.9rem}
    .sf-glance-value{font-weight:700;color:#0f172a;text-align:right}
    .sf-explainer-wrap{margin-top:2.25rem;padding:1.25rem;background:#f6f9ff;border:1px solid #dbe4f0;border-radius:18px}
    .sf-explainer-wrap .sf-grid.cards{margin-bottom:0}
    .sf-explainer-wrap .sf-card{height:100%}
    .sf-explainer-wrap h2{margin:.1rem 0 .9rem}
    @media(max-width:900px){.sf-wrap{padding:1rem}.sf-flex{grid-template-columns:1fr}}
    @media(max-width:640px){.sf-explainer-wrap{margin-top:1.75rem;padding:1rem}}
  </style>
</head>
<body>
  <header class="site-header"></header>
  <main class="sf-wrap" data-settings='<?= htmlspecialchars(json_encode($settings, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>
    <section class="sf-hero">
      <h1><?= htmlspecialchars((string) ($content['page_title'] ?? 'Solar and Finance')) ?></h1>
      <p><?= htmlspecialchars((string) ($content['hero_text'] ?? 'Simple solar guidance and finance clarity.')) ?></p>
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
        <details class="sf-pro">
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
    </section>

    <section class="sf-card sf-glance">
      <h2>Solar at a Glance</h2>
      <p class="sf-note">A quick summary of your system, pricing, generation, savings, payback, and green impact.</p>
      <div id="glancePanel" class="sf-glance-grid"></div>
    </section>

    <section class="sf-results" id="results" hidden>
      <div class="sf-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-bottom:1rem">
        <article class="sf-card"><h3>Monthly Outflow Comparison</h3><canvas id="monthlyChart" height="180"></canvas></article>
        <article class="sf-card"><h3>Cumulative Expense Over 25 Years</h3><canvas id="cumulativeChart" height="180"></canvas></article>
      </div>
      <article class="sf-card" style="margin-bottom:1rem"><h3>Payback meters</h3><div id="paybackMeters" class="sf-kpis"></div></article>
      <article class="sf-card"><h3>Detailed Financial Summary</h3><div id="financeBoxes"></div></article>
      <section class="sf-card sf-customer">
        <h2>Customer details (for report generation)</h2>
        <p class="sf-note">You can explore the calculator without these details. They are required only when you click <strong>Generate Report</strong>.</p>
        <div class="sf-inputs">
          <label>Customer Name<input type="text" id="customerName" placeholder="Enter customer name"></label>
          <label>Location<input type="text" id="customerLocation" placeholder="City / Area"></label>
          <label>Mobile Number<input type="text" id="customerMobile" placeholder="e.g. 9999999999"></label>
        </div>
        <div id="customerError" class="sf-error" role="alert" aria-live="polite"></div>
      </section>
      <div style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap">
        <button class="sf-btn report" type="button" id="generateReportBtn">Generate Report</button>
        <button class="sf-btn" type="button" id="generateQuotationBtn">Generate Quotation</button>
        <a class="sf-btn alt" target="_blank" rel="noopener" id="waQuote" href="#"><i class="fa-brands fa-whatsapp"></i> Show Interest</a>
      </div>
    </section>

    <section class="sf-explainer-wrap">
      <h2>Explainer Cards</h2>
      <div class="sf-grid cards">
        <?php foreach (($content['explainer_cards'] ?? []) as $card): ?>
        <article class="sf-card"><i class="fa-solid <?= htmlspecialchars((string) ($card['icon'] ?? 'fa-solar-panel')) ?>"></i><h3><?= htmlspecialchars((string) ($card['title'] ?? '')) ?></h3><p><?= htmlspecialchars((string) ($card['text'] ?? '')) ?></p></article>
        <?php endforeach; ?>
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
    };
    let mChart,cChart,debounceTimer=null,isProgrammaticUpdate=false;
    const manualOverride=new Set();
    const debouncedIds=['monthlyBill','monthlyUnits','solarSize','dailyGeneration','unitRate','subsidy','loanTenure','systemCostSelf','systemCostUp2','systemCostAbove2','loanAmountUp2','marginMoneyUp2','interestRateUp2','loanAmountAbove2','marginMoneyAbove2','interestRateAbove2'];
    const ids=[...debouncedIds,'systemType','inverterKva','phase','batteryCount','loanAboveGroupWrap','customerName','customerLocation','customerMobile','customerError','generateReportBtn','generateQuotationBtn'];
    const el=Object.fromEntries(ids.map(id=>[id,document.getElementById(id)]));
    const glancePanel=document.getElementById('glancePanel'),paybackMeters=document.getElementById('paybackMeters'),financeBoxes=document.getElementById('financeBoxes'),waQuote=document.getElementById('waQuote');
    let latestSnapshot=null, latestReportUrl='';
    let autoQuoteState={quoteId:'',quoteViewUrl:'',mobileKey:'',lastSyncHash:'',timer:null,inFlight:false};

    const num=(v,fallback=0)=>{const n=Number(v); return Number.isFinite(n)?n:fallback;};
    const floorRecommendedSize=v=>{
      const n=Number(v);
      if(!Number.isFinite(n)||n<=0) return 0;
      return Math.floor(n);
    };
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
      const selfRaw=Math.max(num(row.self_funded_price ?? row.system_cost_self ?? row.system_cost_self_funded),0);
      const aboveRaw=Math.max(num(row.loan_above_2_lacs),0);
      return {up2,self:selfRaw||up2,above2:aboveRaw||up2};
    };
    const isHigherLoanApplicableForCost=cost=>(cost*0.9) > 200000;


    const normalizeIndianMobile=(value)=>{
      const digits=String(value||'').replace(/\D+/g,'');
      if(!digits) return '';
      let core='';
      if(/^0\d{10}$/.test(digits)) core=digits.slice(1);
      else if(/^91\d{10}$/.test(digits)) core=digits.slice(2);
      else if(/^\d{10}$/.test(digits)) core=digits;
      if(!/^\d{10}$/.test(core)) return '';
      return `91${core}`;
    };
    const displayIndianMobile=(normalized)=>{
      const digits=String(normalized||'').replace(/\D+/g,'');
      return /^91\d{10}$/.test(digits)?`+${digits}`:'';
    };
    const mobileKey=value=>{
      const normalized=normalizeIndianMobile(value);
      return normalized?normalized.slice(-10):'';
    };
    const setCustomerError=(msg)=>{el.customerError.textContent=msg||'';};

    const getQuoteViewUrl=(quoteId, quoteViewUrl)=>{
      const direct=String(quoteViewUrl||'').trim();
      if(direct) return direct;
      const fallbackId=String(quoteId||'').trim();
      if(!fallbackId) return '';
      return `/quotation-view.php?id=${encodeURIComponent(fallbackId)}`;
    };
    const validateCustomerDetails=(purpose='quotation')=>{
      const name=(el.customerName.value||'').trim();
      const location=(el.customerLocation.value||'').trim();
      const normalizedMobile=normalizeIndianMobile(el.customerMobile.value||'');
      if(!name||!location||!normalizedMobile){
        const msg=purpose==='report'
          ? 'Please enter customer name, location and mobile number to generate the report.'
          : 'Please enter customer name, location and mobile number to generate quotation.';
        setCustomerError(msg);
        return null;
      }
      setCustomerError('');
      return {name,location,normalizedMobile};
    };

    async function syncAutoQuotation(reason='',opts={}){
      const force=Boolean(opts?.force);
      const createNewQuote=Boolean(opts?.createNewQuote);
      if(autoQuoteState.inFlight) return;
      if(!latestSnapshot || document.getElementById('results').hidden) return;
      const name=(el.customerName.value||'').trim();
      const location=(el.customerLocation.value||'').trim();
      const normalizedMobile=normalizeIndianMobile(el.customerMobile.value||'');
      if(!name||!location||!normalizedMobile) return;
      const snapshot={...latestSnapshot,customer:{name,location,mobile_normalized:normalizedMobile,mobile_raw:(el.customerMobile.value||'').trim()}};
      const payload={
        ...snapshot,
        linked_quote_id:createNewQuote?'':(autoQuoteState.quoteId||''),
        create_new_quote:createNewQuote,
        sync_reason:reason||'input'
      };
      const nextMobileKey=mobileKey(normalizedMobile);
      const syncHash=JSON.stringify([
        nextMobileKey,
        snapshot.customer.name,
        snapshot.customer.location,
        snapshot.inputs?.system_type,
        snapshot.inputs?.solar_size_kw,
        snapshot.inputs?.system_cost_up2,
        snapshot.inputs?.system_cost_above2,
        snapshot.inputs?.higher_loan_applicable,
        snapshot.inputs?.monthly_bill,
        snapshot.inputs?.subsidy,
        snapshot.inputs?.interest_rate_up2,
        snapshot.inputs?.interest_rate_above2,
        snapshot.inputs?.loan_amount_up2,
        snapshot.inputs?.loan_amount_above2,
        snapshot.inputs?.margin_money_up2,
        snapshot.inputs?.margin_money_above2
      ]);
      if(!force && autoQuoteState.lastSyncHash===syncHash && autoQuoteState.mobileKey===nextMobileKey) return;
      autoQuoteState.inFlight=true;
      try{
        const res=await fetch('/solar-and-finance-auto-quotation.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data=await res.json();
        if(res.ok && data?.success){
          autoQuoteState.quoteId=String(data.quote_id||autoQuoteState.quoteId||'');
          autoQuoteState.quoteViewUrl=String(data.quote_view_url||autoQuoteState.quoteViewUrl||'');
          autoQuoteState.mobileKey=nextMobileKey;
          autoQuoteState.lastSyncHash=syncHash;
          return data;
        }
        return data;
      }catch(_err){
        return null;
      }finally{
        autoQuoteState.inFlight=false;
      }
    }
    function scheduleAutoQuotationSync(reason=''){
      clearTimeout(autoQuoteState.timer);
      autoQuoteState.timer=setTimeout(()=>{syncAutoQuotation(reason);},800);
    }

    function clearResults(){
      document.getElementById('results').hidden=true;
      glancePanel.innerHTML=''; paybackMeters.innerHTML=''; financeBoxes.innerHTML='';
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
      const recommendedSize=(currentUnits>0&&gen>0)?floorRecommendedSize(currentUnits/(gen*30)):0;
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
        const autoLoanAbove2=Math.round(above2Cost*0.9);
        if(above2Cost>0&&shouldAutofill('loanAmountAbove2')) setField('loanAmountAbove2', String(autoLoanAbove2));
        const currentLoanAbove2=Math.max(num(el.loanAmountAbove2.value,autoLoanAbove2),0);
        const autoMarginAbove2=Math.max(above2Cost-currentLoanAbove2, Math.round(above2Cost*0.1));
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
      const loanHigh=higherLoanApplicable?(Math.max(num(el.loanAmountAbove2.value),0)||Math.round(costAbove2*0.9)):0;
      const marginHigh=higherLoanApplicable?(Math.max(num(el.marginMoneyAbove2.value),0)||Math.max(costAbove2-loanHigh,Math.round(costAbove2*0.1))):0;
      const effUpToLoan=Math.max(loanUp-subsidy,0), effHighToLoan=higherLoanApplicable?Math.max(loanHigh-subsidy,0):0;
      const remainingSubsidyUpNotToLoan=Math.max(subsidy-marginUp,0);
      const remainingSubsidyHighNotToLoan=higherLoanApplicable?Math.max(subsidy-marginHigh,0):0;
      const effUpNotToLoan=Math.max(loanUp-remainingSubsidyUpNotToLoan,0);
      const effHighNotToLoan=higherLoanApplicable?Math.max(loanHigh-remainingSubsidyHighNotToLoan,0):0;
      const tenure=num(el.loanTenure.value,10);
      const emiUpToLoan=emi(effUpToLoan,num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure);
      const emiUpNotToLoan=emi(effUpNotToLoan,num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure);
      const emiHighToLoan=higherLoanApplicable?emi(effHighToLoan,num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure):0;
      const emiHighNotToLoan=higherLoanApplicable?emi(effHighNotToLoan,num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure):0;

      const monthlyLabels=['No Solar','Loan up to ₹2 lacs (subsidy to loan)','Loan up to ₹2 lacs (subsidy self kept)'];
      const monthlyData=[monthlyBill,emiUpToLoan+residual,emiUpNotToLoan+residual];
      const monthlyColors=['#9ca3af','#0f766e','#14b8a6'];
      if(higherLoanApplicable){monthlyLabels.push('Loan above ₹2 lacs (subsidy to loan)'); monthlyData.push(emiHighToLoan+residual); monthlyColors.push('#1d4ed8'); monthlyLabels.push('Loan above ₹2 lacs (subsidy self kept)'); monthlyData.push(emiHighNotToLoan+residual); monthlyColors.push('#6366f1');}
      monthlyLabels.push('Self Funded'); monthlyData.push(residual); monthlyColors.push('#f59e0b');

      const years=[...Array(26).keys()];
      const cumul=(start,mEmi,mRes)=>years.map(y=>{const months=y*12; const paidEmi=months<=tenure*12?months*mEmi:tenure*12*mEmi; return start+paidEmi+(months*mRes);});
      const initialInvestmentUpNotToLoan=Math.max(marginUp-subsidy,0);
      const initialInvestmentHighNotToLoan=Math.max(marginHigh-subsidy,0);
      const cumulativeDatasets=[
        {label:'No Solar',data:years.map(y=>y*12*monthlyBill),borderColor:'#9ca3af'},
        {label:'Loan up to ₹2 lacs (subsidy to loan)',data:cumul(marginUp,emiUpToLoan,residual),borderColor:'#0f766e'},
        {label:'Loan up to ₹2 lacs (subsidy self kept)',data:cumul(initialInvestmentUpNotToLoan,emiUpNotToLoan,residual),borderColor:'#14b8a6'}
      ];
      if(higherLoanApplicable){cumulativeDatasets.push({label:'Loan above ₹2 lacs (subsidy to loan)',data:cumul(marginHigh,emiHighToLoan,residual),borderColor:'#1d4ed8'}); cumulativeDatasets.push({label:'Loan above ₹2 lacs (subsidy self kept)',data:cumul(initialInvestmentHighNotToLoan,emiHighNotToLoan,residual),borderColor:'#6366f1'});}
      cumulativeDatasets.push({label:'Self Funded',data:years.map(y=>costSelf-subsidy+y*12*residual),borderColor:'#f59e0b'});

      const PAYBACK_HORIZON_MONTHS=25*12;
      const selfFundedPaybackYears=(costSelf-subsidy)/Math.max((monthlyBill-residual)*12,1);
      const findLoanPaybackMonth=(marginMoney,monthlyOutflowLoan,monthlyBillWithoutSolar,horizonMonths=PAYBACK_HORIZON_MONTHS)=>{
        let cumulativeLoanSide=Math.max(marginMoney,0);
        let cumulativeNoSolar=0;
        for(let month=1;month<=horizonMonths;month+=1){
          cumulativeLoanSide+=monthlyOutflowLoan;
          cumulativeNoSolar+=monthlyBillWithoutSolar;
          if(cumulativeLoanSide<=cumulativeNoSolar){return month;}
        }
        return null;
      };
      const formatLoanPayback=(month)=>{
        if(month===null){return 'Not within 25 years';}
        const years=Math.floor(month/12);
        const remMonths=month%12;
        if(years===0){return `${remMonths} month${remMonths===1?'':'s'}`;}
        if(remMonths===0){return `${years} year${years===1?'':'s'}`;}
        return `${years} year${years===1?'':'s'} ${remMonths} month${remMonths===1?'':'s'}`;
      };
      const formatSelfFundedPayback=(decimalYears)=>{
        if(!Number.isFinite(decimalYears)||decimalYears<0){return '—';}
        const totalMonths=Math.round(decimalYears*12);
        if(totalMonths>PAYBACK_HORIZON_MONTHS){return 'Not within 25 years';}
        return formatLoanPayback(totalMonths);
      };

      document.getElementById('results').hidden=false;
      const monthlySaving=Math.max(monthlyBill-residual,0);
      const annualSaving=monthlySaving*12;
      const saving25=annualSaving*25;
      const monthlyOutflowLoanUp2ToLoan=emiUpToLoan+residual;
      const monthlyOutflowLoanUp2NotToLoan=emiUpNotToLoan+residual;
      const monthlyOutflowLoanHighToLoan=emiHighToLoan+residual;
      const monthlyOutflowLoanHighNotToLoan=emiHighNotToLoan+residual;
      const roofArea=(size*Number(d.roof_area_sqft_per_kw||100)).toFixed(0);
      const billOffset=Math.min((solarValue/Math.max(monthlyBill,1))*100,100).toFixed(1);
      const annualCo2=((solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82));
      const co225=annualCo2*25;
      const treeFactor=Number(d.tree_factor_kg_per_tree||21);
      const annualTrees=annualCo2/Math.max(treeFactor,1);
      const trees25=co225/Math.max(treeFactor,1);

      const groupedGlanceData=[
        {
          title:'Group 1 — System',
          rows:[
            ['Solar system type',el.systemType.value||'—'],
            ['Solar size (kW / kWp)',`${size.toFixed(1)} kW`],
            ...(el.systemType.value==='Hybrid'&&el.inverterKva.value?[['Inverter',`${el.inverterKva.value} kVA`]]:[]),
            ...(el.systemType.value==='Hybrid'&&el.batteryCount.value?[['Battery count',`${el.batteryCount.value}`]]:[]),
            ...(el.systemType.value==='Hybrid'&&el.phase.value?[['Phase',el.phase.value]]:[]),
          ]
        },
        {
          title:'Group 2 — Pricing',
          rows:[
            ['Self Funded price',INR(costSelf)],
            ['Loan up to 2 lacs price',INR(costUp2)],
            ...(higherLoanApplicable?[['Loan above 2 lacs price',INR(costAbove2)]]:[])
          ]
        },
        {
          title:'Group 3 — Generation & Savings',
          rows:[
            ['Expected monthly generation',`${solarUnits.toFixed(0)} units`],
            ['Expected annual generation',`${(solarUnits*12).toFixed(0)} units`],
            ['Expected generation in 25 years',`${(solarUnits*12*25).toFixed(0)} units`],
            ['Estimated monthly savings',INR(monthlySaving)],
            ['Estimated annual savings',INR(annualSaving)],
            ['Estimated savings in 25 years',INR(saving25)]
          ]
        },
        {
          title:'Group 4 — Payback & Outflow',
          rows:[
            ['Estimated payback period — Self Funded',formatSelfFundedPayback(selfFundedPaybackYears)],
            ['Estimated payback period — Loan up to ₹2 lacs (subsidy to loan)',formatLoanPayback(findLoanPaybackMonth(marginUp,monthlyOutflowLoanUp2ToLoan,monthlyBill))],
            ['Estimated payback period — Loan up to ₹2 lacs (subsidy self kept)',formatLoanPayback(findLoanPaybackMonth(initialInvestmentUpNotToLoan,monthlyOutflowLoanUp2NotToLoan,monthlyBill))],
            ...(higherLoanApplicable?[[ 'Estimated payback period — Loan above ₹2 lacs (subsidy to loan)',formatLoanPayback(findLoanPaybackMonth(marginHigh,monthlyOutflowLoanHighToLoan,monthlyBill))],[ 'Estimated payback period — Loan above ₹2 lacs (subsidy self kept)',formatLoanPayback(findLoanPaybackMonth(initialInvestmentHighNotToLoan,monthlyOutflowLoanHighNotToLoan,monthlyBill))]]:[]),
            ['Monthly outflow — No Solar',INR(monthlyBill)],
            ['Monthly outflow — Self Funded',INR(residual)],
            ['Monthly outflow — Loan up to ₹2 lacs (subsidy to loan)',INR(monthlyOutflowLoanUp2ToLoan)],
            ['Monthly outflow — Loan up to ₹2 lacs (subsidy self kept)',INR(monthlyOutflowLoanUp2NotToLoan)],
            ...(higherLoanApplicable?[[ 'Monthly outflow — Loan above ₹2 lacs (subsidy to loan)',INR(monthlyOutflowLoanHighToLoan)],[ 'Monthly outflow — Loan above ₹2 lacs (subsidy self kept)',INR(monthlyOutflowLoanHighNotToLoan)]]:[])
          ]
        },
        {
          title:'Group 5 — Feasibility & Green Impact',
          rows:[
            ['Roof area needed',`${roofArea} sq.ft`],
            ['Bill offset (%)',`${billOffset}%`],
            ['Annual CO₂ reduction',`${annualCo2.toFixed(0)} kg`],
            ['25-year CO₂ reduction',`${co225.toFixed(0)} kg`],
            ['Annual trees equivalent',`${annualTrees.toFixed(0)} trees`],
            ['25-year trees equivalent',`${trees25.toFixed(0)} trees`]
          ]
        }
      ];
      glancePanel.innerHTML=groupedGlanceData.map(group=>`<article class="sf-glance-group"><h3>${group.title}</h3><div class="sf-glance-list">${group.rows.filter(([,v])=>v!==''&&v!==null&&v!==undefined).map(([label,value])=>`<div class="sf-glance-item"><span class="sf-glance-label">${label}</span><span class="sf-glance-value">${value}</span></div>`).join('')}</div></article>`).join('');

      const payback=[[ 'Loan up to ₹2 lacs (subsidy to loan)',formatLoanPayback(findLoanPaybackMonth(marginUp,monthlyOutflowLoanUp2ToLoan,monthlyBill))],[ 'Loan up to ₹2 lacs (subsidy self kept)',formatLoanPayback(findLoanPaybackMonth(initialInvestmentUpNotToLoan,monthlyOutflowLoanUp2NotToLoan,monthlyBill))]];
      if(higherLoanApplicable){
        payback.push(['Loan above ₹2 lacs (subsidy to loan)',formatLoanPayback(findLoanPaybackMonth(marginHigh,monthlyOutflowLoanHighToLoan,monthlyBill))]);
        payback.push(['Loan above ₹2 lacs (subsidy self kept)',formatLoanPayback(findLoanPaybackMonth(initialInvestmentHighNotToLoan,monthlyOutflowLoanHighNotToLoan,monthlyBill))]);
      }
      payback.push(['Self Funded',formatSelfFundedPayback(selfFundedPaybackYears)]);
      paybackMeters.innerHTML=payback.map(([n,p])=>`<div class='sf-metric'><strong>${n}</strong><div>${p}</div></div>`).join('');

      const scenarioColumns=[
        {label:'Self Funded',metrics:{systemPrice:INR(costSelf),subsidy:INR(subsidy),marginMoney:'—',marginMoneySubsidy:INR(costSelf-subsidy),loanAmount:'—',loanSubsidy:'—',emi:'—',monthlyOutflow:INR(residual),payback:formatSelfFundedPayback(selfFundedPaybackYears)}},
        {label:'Loan up to ₹2 lacs (subsidy to loan)',metrics:{systemPrice:INR(costUp2),subsidy:INR(subsidy),marginMoney:INR(marginUp),marginMoneySubsidy:'—',loanAmount:INR(loanUp),loanSubsidy:INR(effUpToLoan),emi:INR(emiUpToLoan),monthlyOutflow:INR(monthlyOutflowLoanUp2ToLoan),payback:formatLoanPayback(findLoanPaybackMonth(marginUp,monthlyOutflowLoanUp2ToLoan,monthlyBill))}},
        {label:'Loan up to ₹2 lacs (subsidy self kept)',metrics:{systemPrice:INR(costUp2),subsidy:INR(subsidy),marginMoney:INR(marginUp),marginMoneySubsidy:INR(initialInvestmentUpNotToLoan),loanAmount:INR(loanUp),loanSubsidy:INR(effUpNotToLoan),emi:INR(emiUpNotToLoan),monthlyOutflow:INR(monthlyOutflowLoanUp2NotToLoan),payback:formatLoanPayback(findLoanPaybackMonth(initialInvestmentUpNotToLoan,monthlyOutflowLoanUp2NotToLoan,monthlyBill))}}
      ];
      if(higherLoanApplicable){
        scenarioColumns.push({label:'Loan above ₹2 lacs (subsidy to loan)',metrics:{systemPrice:INR(costAbove2),subsidy:INR(subsidy),marginMoney:INR(marginHigh),marginMoneySubsidy:'—',loanAmount:INR(loanHigh),loanSubsidy:INR(effHighToLoan),emi:INR(emiHighToLoan),monthlyOutflow:INR(monthlyOutflowLoanHighToLoan),payback:formatLoanPayback(findLoanPaybackMonth(marginHigh,monthlyOutflowLoanHighToLoan,monthlyBill))}});
        scenarioColumns.push({label:'Loan above ₹2 lacs (subsidy self kept)',metrics:{systemPrice:INR(costAbove2),subsidy:INR(subsidy),marginMoney:INR(marginHigh),marginMoneySubsidy:INR(initialInvestmentHighNotToLoan),loanAmount:INR(loanHigh),loanSubsidy:INR(effHighNotToLoan),emi:INR(emiHighNotToLoan),monthlyOutflow:INR(monthlyOutflowLoanHighNotToLoan),payback:formatLoanPayback(findLoanPaybackMonth(initialInvestmentHighNotToLoan,monthlyOutflowLoanHighNotToLoan,monthlyBill))}});
      }
      const showMarginMoneySubsidy=subsidy>0;
      const financeRows=[
        ['Margin Money','marginMoney'],
        ...(showMarginMoneySubsidy?[['Margin Money - Subsidy','marginMoneySubsidy']]:[]),
        ['Loan Amount','loanAmount'],
        ['Loan - Subsidy','loanSubsidy'],
        ['EMI','emi'],
        ['Monthly Outflow','monthlyOutflow'],
        ['Payback Time','payback'],
      ];
      const financeSummaryLines=[
        `<div class="sf-finance-line"><strong>System Price (Loan up to ₹2 lacs):</strong> ${INR(costUp2)}</div>`,
        higherLoanApplicable?`<div class="sf-finance-line"><strong>System Price (Loan above ₹2 lacs):</strong> ${INR(costAbove2)}</div>`:'',
        `<div class="sf-finance-line"><strong>Subsidy:</strong> ${INR(subsidy)}</div>`,
      ].filter(Boolean).join('');
      financeBoxes.innerHTML=`<div class="sf-finance-summary">${financeSummaryLines}</div><div class="sf-finance-table-wrap"><table class="sf-finance-table"><thead><tr><th>Metric</th>${scenarioColumns.map(s=>`<th>${s.label}</th>`).join('')}</tr></thead><tbody>${financeRows.map(([rowLabel,rowKey])=>`<tr><th scope="row">${rowLabel}</th>${scenarioColumns.map(s=>`<td><b>${s.metrics[rowKey]??'—'}</b></td>`).join('')}</tr>`).join('')}</tbody></table></div><div class="sf-finance-note"><strong>Residual Bill (same across all scenarios):</strong> ${INR(residual)}/month</div>`;

      if(mChart) mChart.destroy(); if(cChart) cChart.destroy();
      mChart=new Chart(monthlyChart,{type:'bar',data:{labels:monthlyLabels,datasets:[{label:'Monthly Outflow (₹)',data:monthlyData,backgroundColor:monthlyColors}]},options:{plugins:{legend:{display:true}},scales:{x:{title:{display:true,text:'Scenario'}},y:{title:{display:true,text:'Monthly Outflow (₹)'}}}}});
      cChart=new Chart(cumulativeChart,{type:'line',data:{labels:years,datasets:cumulativeDatasets},options:{scales:{x:{title:{display:true,text:'Years'}},y:{title:{display:true,text:'Cumulative Expense (₹)'}}}}});

      const hy=el.systemType.value==='Hybrid'?`Hybrid: ${el.inverterKva.value} kVA, ${el.phase.value}, ${el.batteryCount.value} batteries`:'N/A';
      const monthlyOutflowHighToLoan=higherLoanApplicable?(emiHighToLoan+residual):0;
      const monthlyOutflowHighNotToLoan=higherLoanApplicable?(emiHighNotToLoan+residual):0;
      latestSnapshot={
        customer:{
          name:(el.customerName.value||'').trim(),
          location:(el.customerLocation.value||'').trim(),
          mobile_normalized:normalizeIndianMobile(el.customerMobile.value||''),
          mobile_raw:(el.customerMobile.value||'').trim()
        },
        inputs:{
          monthly_bill:monthlyBill,monthly_units:units||Math.round(monthlyBill/rate),system_type:el.systemType.value,solar_size_kw:size,daily_generation_per_kw:gen,unit_rate:rate,
          subsidy,loan_tenure_years:tenure,system_cost_self:costSelf,system_cost_self_funded:costSelf,system_cost_up2:costUp2,system_cost_above2:costAbove2,loan_amount_up2:loanUp,margin_money_up2:marginUp,interest_rate_up2:num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),
          loan_amount_above2:loanHigh,margin_money_above2:marginHigh,interest_rate_above2:num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),higher_loan_applicable:higherLoanApplicable,hybrid_variation:hy
        },
        results:{
          generation:{monthly_units:solarUnits,annual_units:solarUnits*12,units_25_year:solarUnits*12*25,roof_area_sqft:size*Number(d.roof_area_sqft_per_kw||100),bill_offset_percent:Math.min((solarValue/Math.max(monthlyBill,1))*100,100),daily_generation_assumption:gen},
          environment:{annual_co2_kg:(solarUnits*12)*Number(d.co2_factor_kg_per_unit||0.82),co2_25_year_kg:(solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82),tree_equivalent:((solarUnits*12*25)*Number(d.co2_factor_kg_per_unit||0.82))/Number(d.tree_factor_kg_per_tree||21)},
          finance:{
            no_solar:{monthly_bill:monthlyBill,expense_25_year:monthlyBill*12*25},
            loan_upto_2_lacs_subsidy_to_loan:{system_cost:costUp2,subsidy,loan_amount:loanUp,effective_loan:effUpToLoan,margin_money:marginUp,interest_rate:num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure_years:tenure,emi:emiUpToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowLoanUp2ToLoan,net_own_investment_after_subsidy:initialInvestmentUpNotToLoan,initial_investment_after_subsidy_credit:initialInvestmentUpNotToLoan},
            loan_upto_2_lacs_subsidy_not_to_loan:{system_cost:costUp2,subsidy,loan_amount:loanUp,remaining_subsidy_after_margin_adjustment:remainingSubsidyUpNotToLoan,effective_loan:effUpNotToLoan,effective_loan_amount_after_remaining_subsidy_adjustment:effUpNotToLoan,margin_money:marginUp,interest_rate:num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure_years:tenure,emi:emiUpNotToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowLoanUp2NotToLoan,net_own_investment_after_subsidy:initialInvestmentUpNotToLoan,initial_investment_after_subsidy_credit:initialInvestmentUpNotToLoan},
            loan_above_2_lacs_subsidy_to_loan:higherLoanApplicable?{system_cost:costAbove2,subsidy,loan_amount:loanHigh,effective_loan:effHighToLoan,margin_money:marginHigh,interest_rate:num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure_years:tenure,emi:emiHighToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowHighToLoan,net_own_investment_after_subsidy:initialInvestmentHighNotToLoan,initial_investment_after_subsidy_credit:initialInvestmentHighNotToLoan}:null,
            loan_above_2_lacs_subsidy_not_to_loan:higherLoanApplicable?{system_cost:costAbove2,subsidy,loan_amount:loanHigh,remaining_subsidy_after_margin_adjustment:remainingSubsidyHighNotToLoan,effective_loan:effHighNotToLoan,effective_loan_amount_after_remaining_subsidy_adjustment:effHighNotToLoan,margin_money:marginHigh,interest_rate:num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure_years:tenure,emi:emiHighNotToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowHighNotToLoan,net_own_investment_after_subsidy:initialInvestmentHighNotToLoan,initial_investment_after_subsidy_credit:initialInvestmentHighNotToLoan}:null,
            loan_upto_2:{system_cost:costUp2,subsidy,loan_amount:loanUp,effective_loan:effUpToLoan,margin_money:marginUp,interest_rate:num(el.interestRateUp2.value,d.interest_upto_2_lacs||6),tenure_years:tenure,emi:emiUpToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowLoanUp2ToLoan},
            loan_above_2:higherLoanApplicable?{system_cost:costAbove2,subsidy,loan_amount:loanHigh,effective_loan:effHighToLoan,margin_money:marginHigh,interest_rate:num(el.interestRateAbove2.value,d.interest_above_2_lacs||8.15),tenure_years:tenure,emi:emiHighToLoan,residual_bill:residual,total_monthly_outflow:monthlyOutflowHighToLoan}:null,
            self_funded:{system_cost:costSelf,subsidy,net_investment:costSelf-subsidy,residual_bill:residual,monthly_saving:monthlyBill-residual,annual_saving:(monthlyBill-residual)*12,payback_years:selfFundedPaybackYears}
          },
          payback:{loan_upto_2_lacs_subsidy_to_loan:formatLoanPayback(findLoanPaybackMonth(marginUp,monthlyOutflowLoanUp2ToLoan,monthlyBill)),loan_upto_2_lacs_subsidy_not_to_loan:formatLoanPayback(findLoanPaybackMonth(initialInvestmentUpNotToLoan,monthlyOutflowLoanUp2NotToLoan,monthlyBill)),loan_above_2_lacs_subsidy_to_loan:higherLoanApplicable?formatLoanPayback(findLoanPaybackMonth(marginHigh,monthlyOutflowHighToLoan,monthlyBill)):'',loan_above_2_lacs_subsidy_not_to_loan:higherLoanApplicable?formatLoanPayback(findLoanPaybackMonth(initialInvestmentHighNotToLoan,monthlyOutflowHighNotToLoan,monthlyBill)):'',self_funded:formatSelfFundedPayback(selfFundedPaybackYears)},
          charts:{monthly_labels:monthlyLabels,monthly_data:monthlyData,cumulative_years:years,cumulative_datasets:cumulativeDatasets.map(ds=>({label:ds.label,data:ds.data,borderColor:ds.borderColor}))}
        }
      };

      const quoteLines=[
        'Hello Dakshayani, I want a solar quotation.',
        `- Monthly bill: ${INR(monthlyBill)}`,`- Monthly units: ${units||Math.round(monthlyBill/rate)}`,`- System type: ${el.systemType.value}`,`- Solar size: ${size} kW`,
        `- System cost (Self): ${INR(costSelf)}`,`- System cost (Loan up to 2 lacs): ${INR(costUp2)}`,`- System cost (Loan above 2 lacs): ${INR(costAbove2)}`,`- Subsidy: ${INR(subsidy)}`,
        `- Loan amount (up to 2 lacs): ${INR(loanUp)}`,`- Margin money (up to 2 lacs): ${INR(marginUp)}`,`- Interest (up to 2 lacs): ${el.interestRateUp2.value||d.interest_upto_2_lacs||6}%`,
        `- Loan amount (above 2 lacs): ${INR(loanHigh)}`,`- Margin money (above 2 lacs): ${INR(marginHigh)}`,`- Interest (above 2 lacs): ${el.interestRateAbove2.value||d.interest_above_2_lacs||8.15}%`,
        `- Tenure: ${tenure} years`,`- Hybrid variation: ${hy}`,`- Monthly generation: ${solarUnits.toFixed(0)} units`,`- Annual saving: ${INR((monthlyBill-residual)*12)}`
      ];
      const customerName=(el.customerName.value||'').trim();
      const customerLocation=(el.customerLocation.value||'').trim();
      const normalizedMobile=normalizeIndianMobile(el.customerMobile.value||'');
      if(customerName) quoteLines.push(`- Customer name: ${customerName}`);
      if(customerLocation) quoteLines.push(`- Location: ${customerLocation}`);
      if(normalizedMobile) quoteLines.push(`- Mobile: ${displayIndianMobile(normalizedMobile)}`);
      if(latestReportUrl) quoteLines.push(`- Report link: ${latestReportUrl}`);
      waQuote.href=`https://wa.me/917070278178?text=${encodeURIComponent(quoteLines.join('\n'))}`;
      scheduleAutoQuotationSync('render');
    }



    async function generateReport(){
      recalculateSolarFinance({changedField:'manualTrigger'});
      const customerDetails=validateCustomerDetails('report');
      if(!customerDetails){
        alert(el.customerError.textContent||'Please enter customer details.');
        return;
      }
      const {name,location,normalizedMobile}=customerDetails;
      if(!latestSnapshot || document.getElementById('results').hidden){
        const msg='Please calculate your solar estimate first.';
        setCustomerError(msg);
        alert(msg);
        return;
      }
      latestSnapshot.customer={name,location,mobile_normalized:normalizedMobile,mobile_raw:(el.customerMobile.value||'').trim()};
      const monthlyImg=document.getElementById('monthlyChart')?.toDataURL('image/png')||'';
      const cumulativeImg=document.getElementById('cumulativeChart')?.toDataURL('image/png')||'';
      const payload={...latestSnapshot,charts_images:{monthly_outflow:monthlyImg,cumulative_expense:cumulativeImg}};
      try{
        const res=await fetch('/solar-and-finance-generate-report.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data=await res.json();
        if(!res.ok||!data.success){
          throw new Error(data.message||'Unable to generate report.');
        }
        latestReportUrl=data.report_url||'';
        window.open(data.report_url,'_blank','noopener');
        recalculateSolarFinance({changedField:'postReport'});
      }catch(err){
        alert(err?.message||'Unable to generate report.');
      }
    }
    async function generateQuotation(){
      recalculateSolarFinance({changedField:'manualTrigger'});
      if(!latestSnapshot || document.getElementById('results').hidden){
        const msg='Please calculate your solar estimate first.';
        setCustomerError(msg);
        alert(msg);
        return;
      }
      const customerDetails=validateCustomerDetails('quotation');
      if(!customerDetails){
        alert(el.customerError.textContent||'Please enter customer details.');
        return;
      }
      if(autoQuoteState.quoteId){
        autoQuoteState.quoteId='';
        autoQuoteState.quoteViewUrl='';
      }
      const popup=window.open('about:blank','_blank','noopener');
      if(!latestSnapshot) return;
      latestSnapshot.customer={
        name:customerDetails.name,
        location:customerDetails.location,
        mobile_normalized:customerDetails.normalizedMobile,
        mobile_raw:(el.customerMobile.value||'').trim()
      };
      try{
        const data=await syncAutoQuotation('manual_generate_quotation',{force:true,createNewQuote:true});
        const quoteId=String(data?.quote_id||autoQuoteState.quoteId||'');
        const viewUrl=getQuoteViewUrl(quoteId, String(data?.quote_view_url||autoQuoteState.quoteViewUrl||''));
        if(!viewUrl){
          throw new Error(data?.message||'Unable to generate quotation. Please try again.');
        }
        autoQuoteState.quoteId=quoteId;
        autoQuoteState.quoteViewUrl=viewUrl;
        if(popup){popup.location.href=viewUrl;}
        else {window.open(viewUrl,'_blank','noopener');}
      }catch(err){
        if(popup){popup.close();}
        alert(err?.message||'Unable to generate quotation.');
      }
    }
    function resetAllFields(){
      manualOverride.clear();
      Object.entries(defaultState).forEach(([key,val])=>setField(key,val));
      debouncedIds.forEach(clearUserEdited);
      [el.inverterKva,el.phase,el.batteryCount].forEach(node=>{node.innerHTML='';});
      setField('customerName',''); setField('customerLocation',''); setField('customerMobile','');
      autoQuoteState={quoteId:'',quoteViewUrl:'',mobileKey:'',lastSyncHash:'',timer:null,inFlight:false};
      latestSnapshot=null; latestReportUrl=''; setCustomerError('');
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
    ['customerName','customerLocation','customerMobile'].forEach(id=>{
      el[id].addEventListener('input',()=>{scheduleAutoQuotationSync(`customer_${id}_input`);});
      el[id].addEventListener('blur',()=>{syncAutoQuotation(`customer_${id}_blur`);});
    });
    document.getElementById('calcBtn').addEventListener('click',()=>recalculateSolarFinance({changedField:'manualTrigger'}));
    document.getElementById('resetBtn').addEventListener('click',resetAllFields);
    el.generateReportBtn.addEventListener('click',generateReport);
    el.generateQuotationBtn.addEventListener('click',generateQuotation);
    resetAllFields();
  </script>

</body>
</html>
