'use strict';
// Referrer column, price book updated 21 October 2026. DCR and non-DCR kept distinct.
const SOLAR_PLANS = {
 "ongrid": [
  {
   "kw": 1,
   "price": 85900,
   "low": 85900,
   "code": "O1-1P-D",
   "config": "1 kW panels · 3 kW inverter · 1 phase"
  },
  {
   "kw": 2,
   "price": 142900,
   "low": 142900,
   "code": "O2-1P-D",
   "config": "2 kW panels · 1 phase"
  },
  {
   "kw": 3,
   "price": 192900,
   "low": 192900,
   "code": "O3-1P-D",
   "config": "3 kW panels · 1 phase"
  },
  {
   "kw": 5,
   "price": 308900,
   "low": 281900,
   "code": "O5-1P-D",
   "config": "5 kW panels · 1 phase"
  },
  {
   "kw": 8,
   "price": 501400,
   "low": 433900,
   "code": "O8-3P-D",
   "config": "8 kW panels · 3 phase"
  },
  {
   "kw": 10,
   "price": 587400,
   "low": 492900,
   "code": "O10-3P-D",
   "config": "10 kW panels · 3 phase"
  }
 ],
 "hybrid": [
  {
   "kw": 1,
   "price": 155900,
   "low": 155900,
   "code": "H1-T3-A2-1P-D",
   "config": "3 kVA inverter · 2 × 150 Ah lead-acid · 1 phase"
  },
  {
   "kw": 2,
   "price": 222900,
   "low": 222900,
   "code": "H2-T3-A2-1P-D",
   "config": "3 kVA inverter · 2 × 150 Ah lead-acid · 1 phase"
  },
  {
   "kw": 3,
   "price": 268900,
   "low": 268900,
   "code": "H3-T3-A2-1P-D",
   "config": "3 kVA inverter · 2 × 150 Ah lead-acid · 1 phase"
  },
  {
   "kw": 5,
   "price": 424900,
   "low": 397900,
   "code": "H5-T5-A4-1P-D",
   "config": "5 kVA inverter · 4 × 150 Ah lead-acid · 1 phase"
  },
  {
   "kw": 8,
   "price": 689400,
   "low": 621900,
   "code": "H8-L8-A4-3P-D",
   "config": "8 kW inverter · 4 × 150 Ah lead-acid · 3 phase"
  },
  {
   "kw": 10,
   "price": 811400,
   "low": 716900,
   "code": "H10-L10-A4-3P-D",
   "config": "10 kW inverter · 4 × 150 Ah lead-acid · 3 phase"
  }
 ]
};
function solarTargetBill(kw){return kw*150*7;}
function solarEMI(principal,rate,months=120){const r=rate/1200;return principal<=0?0:principal*r/(1-Math.pow(1+r,-months));}
function solarFinance(plan,nonDcr=plan.kw>3){const price=nonDcr?plan.low:plan.price,dp=Math.round(price*(price<=270000?.1:.2)),loan=price-dp,rate=loan<=200000?5.75:7.9,subsidy=(nonDcr||plan.code.startsWith('H'))?0:plan.kw===1?30000:plan.kw===2?60000:78000;return{price,dp,loan,rate,subsidy,before:Math.round(solarEMI(loan,rate)),after:Math.round(solarEMI(loan-subsidy,rate))};}
function solarComparison(plan,type,bill,tariff,yieldUnits,fixed){const f=solarFinance(plan),nonAvoidable=Math.min(bill,fixed),potential=plan.kw*yieldUnits*tariff*(type==='hybrid'?.85:1),saving=Math.min(Math.max(0,bill-nonAvoidable),potential),remaining=Math.round(bill-saving);return{...f,saving:Math.round(saving),remaining,beforeOutflow:remaining+f.before,afterOutflow:remaining+f.after,difference:Math.round(saving-f.after)};}
if(typeof module!=='undefined')module.exports={SOLAR_PLANS,solarTargetBill,solarEMI,solarFinance,solarComparison};
if(typeof document!=='undefined'){
 const rupee=n=>'₹'+Math.round(n).toLocaleString('en-IN');
 let currentType='ongrid';
 function renderPlans(type){currentType=type;document.querySelectorAll('[data-type]').forEach(b=>{b.classList.toggle('active',b.dataset.type===type);b.setAttribute('aria-pressed',String(b.dataset.type===type));});
 document.querySelector('#type-note').textContent=type==='ongrid'?'1–3 kW में DCR, उससे बड़े सिस्टम में कम कीमत वाला DN विकल्प दिखाया है। Full DCR की कीमत starred note में देखें।':'Battery सहित सबसे कम कीमत वाला विकल्प। 3 kW से ऊपर DN कीमत है; Full DCR की कीमत starred note में देखें।';
 document.querySelector('#plan-grid').innerHTML=SOLAR_PLANS[type].map(p=>{const dn=p.kw>3,f=solarFinance(p),dcr=solarFinance(p,false);return `<article class="plan ${p.kw===3?'featured':''}"><span class="tag">${dn?'DN · DCR + NON-DCR':p.kw===3?'घर के लिए तुलना की शुरुआत':'DCR · SUBSIDY विकल्प'}</span><h3>${p.kw} kW</h3><p class="sub">${p.config}${dn?`<br>3 kW DCR + ${p.kw-3} kW non-DCR`:""}</p><div class="price">कुल कीमत <strong>${rupee(f.price)}${dn?'<sup>*</sup>':''}</strong></div><div class="metrics"><div><span>${f.price<=270000?'10':'20'}% प्रस्तावित DP</span><strong>${rupee(f.dp)}</strong></div><div><span>${dn?'मासिक EMI ≈':'Subsidy credit + recast बाद EMI ≈'}</span><strong>${rupee(f.after)}<span>/माह</span></strong></div></div><dl><div><dt>${dn?"गणना में subsidy":"पात्र subsidy"}</dt><dd>${rupee(f.subsidy)}</dd></div><div><dt>Subsidy से पहले EMI ≈</dt><dd>${rupee(f.before)}/माह</dd></div><div><dt>अनुमानित दर · अवधि</dt><dd>${f.rate}% · 120 माह</dd></div><div><dt>लोन राशि</dt><dd>${rupee(f.loan)}</dd></div><div><dt>बचत की क्षमता का उदाहरण</dt><dd>लगभग ${rupee(p.kw*150*7*(type==='hybrid'?.85:1))}/माह*</dd></div></dl><button class="choose" type="button" data-plan="${p.kw}">इस प्लान का हिसाब देखें ↗</button>${dn?`<p class="dcr-star-note">*Full DCR की कीमत अधिक होगी: ${rupee(p.price)}। ऊपर mixed DN की कीमत है। पात्रता की पुष्टि तक गणना में subsidy नहीं घटाई गई है।</p><details><summary>Full DCR finance विकल्प देखें</summary><p>DP ${rupee(dcr.dp)}, पात्र subsidy ${rupee(dcr.subsidy)}, subsidy credit + bank recast के बाद EMI लगभग ${rupee(dcr.after)}/माह (${dcr.rate}%, 120 माह)। Subsidy से पहले EMI ${rupee(dcr.before)}/माह।</p></details>`:''}${type==='hybrid'&&p.kw===2?'<details><summary>₹610 ज्यादा DP देकर छोटा loan विकल्प देखें</summary><p>DP ₹22,900 करने पर loan ₹2,00,000। पात्र 5.75% दर पर EMI लगभग ₹2,195, और ₹60,000 subsidy credit + bank recast के बाद लगभग ₹1,537/माह (120 माह)। बैंक और hybrid eligibility की पुष्टि जरूरी।</p></details>':''}<p class="micro">*150 units/kW/माह × ₹7/unit; hybrid में 15% loss माना है। वास्तविक paid bill, छत और net metering से सीमित। ${f.loan>600000?'लोन ₹6 लाख से ऊपर: उपयुक्त बैंक या अधिक DP की पुष्टि जरूरी।':''}</p></article>`;}).join('');
 document.querySelectorAll('[data-plan]').forEach(b=>b.addEventListener('click',()=>{document.querySelector('#calc-size').value=b.dataset.plan;document.querySelector('#calc-type').value=currentType;updateCalculator();document.querySelector('#compare').scrollIntoView({behavior:'smooth'});}));}
 function number(id,min,max,fallback){const v=Number(document.querySelector(id).value);return Number.isFinite(v)?Math.max(min,Math.min(max,v)):fallback;}
 function updateCalculator(){const kw=number('#calc-size',1,10,3),type=document.querySelector('#calc-type').value,plan=SOLAR_PLANS[type].find(p=>p.kw===kw),bill=solarTargetBill(kw),tariff=number('#tariff',0,30,7),yieldUnits=number('#yield',1,180,150),fixed=number('#fixed',0,10000,200),r=solarComparison(plan,type,bill,tariff,yieldUnits,fixed),max=Math.max(1,bill,r.afterOutflow,r.beforeOutflow);document.querySelector('#monthly-bill').value=bill;document.querySelector('#calc-result').innerHTML=`<span class="tag">आपकी तुलना · ${kw>3?'DN PACKAGE':'DCR PACKAGE'}</span><h3>${kw} kW ${type==='ongrid'?'ऑन-ग्रिड':'हाइब्रिड'}</h3><div class="bar-row"><div class="bar-head"><span>बिना सोलर · लक्षित बिजली बिल</span><strong>${rupee(bill)}</strong></div><div class="bar-track"><div class="bar-fill" style="width:${bill/max*100}%"></div></div></div><div class="bar-row"><div class="bar-head"><span>${kw>3?'सोलर · EMI + बचा बिल':'सोलर · subsidy के बाद*'}</span><strong>${rupee(r.afterOutflow)}</strong></div><div class="bar-track"><div class="bar-fill solar" style="width:${r.afterOutflow/max*100}%"></div></div></div><div class="calc-lines">EMI ${rupee(r.after)} + बचा बिजली बिल ${rupee(r.remaining)}<br>${kw>3?'Subsidy घटाए बिना · मासिक खर्च:':'Subsidy आने से पहले:'} ${rupee(r.beforeOutflow)}/माह<br>जिसमें EMI ${rupee(r.before)} + बचा बिल ${rupee(r.remaining)}</div><div class="difference"><span>${r.difference>=0?'अनुमानित मासिक राहत':'इस उदाहरण में अतिरिक्त मासिक खर्च'}</span><strong>${rupee(Math.abs(r.difference))}</strong></div><p>शुरुआती DP: <b>${rupee(r.dp)}</b> · कुल कीमत: ${rupee(r.price)}<br>${kw>3?"गणना में subsidy:":"पात्र subsidy:"} ${rupee(r.subsidy)} · दर: ${r.rate}% · 120 माह</p><a class="button" href="#contact">मेरी छत के लिए जांचें ↗</a><p>${kw>3?`*Mixed DN: 3 kW DCR + ${kw-3} kW non-DCR। पात्रता की पुष्टि तक subsidy नहीं घटाई गई है। Full DCR की कीमत अधिक होगी: ${rupee(plan.price)}।`:'*Subsidy principal में credit और bank recast होने पर अनुमान।'} बचत ${rupee(r.saving)}/माह; उत्पादन, tariff और fixed charges की बाईं ओर दी गई assumptions पर। Maintenance, battery replacement और loan fees शामिल नहीं। ${type==='hybrid'?'Hybrid subsidy eligibility की पुष्टि आवश्यक।':''} ${r.loan>600000?'यह loan ₹6 लाख से ऊपर है; financing route की अलग पुष्टि होगी।':''}</p>`;}
 document.querySelectorAll('[data-type]').forEach(b=>b.addEventListener('click',()=>renderPlans(b.dataset.type)));
 ['monthly-bill','calc-size','calc-type','tariff','yield','fixed'].forEach(id=>document.getElementById(id).addEventListener('input',updateCalculator));
 renderPlans('ongrid');updateCalculator();
}


