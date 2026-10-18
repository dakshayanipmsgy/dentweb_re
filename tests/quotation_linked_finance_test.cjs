// Execute the actual form script with ordinary input/change events; no duplicated finance implementation.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Input extends EventTarget {
  constructor(value = '') {
    super(); Object.assign(this, {value:String(value), dataset:{}, min:'', max:'', step:'0.01',
      validity:{stepMismatch:false}, checked:true, disabled:false, readOnly:false});
  }
  setCustomValidity() {}
}
class Form {
  constructor(fields) { this.fields=fields; }
  querySelector(selector) {
    if(selector==='#computedCapacityKwp')return this.fields.capacity_kwp;
    const name=selector.match(/^\[name="([^"]+)"\]$/)?.[1];
    return this.fields[name]||null;
  }
  appendChild(input) { this.fields[input.name]=input; }
}
function setup(saved = false) {
  const values={quote_id:saved?'TEST':'',capacity_kwp:5,monthly_bill_rs:5400,unit_rate_rs_per_kwh:8,
    annual_generation_per_kw:1620,monthly_bill_touched:0,subsidy_expected_rs:78000,
    system_total_incl_gst_rs:294900,scenario_price_self_funded:294900,
    scenario_price_loan_upto_2_lacs:294900,scenario_price_loan_above_2_lacs:294900,
    primary_finance_scenario:'loan_upto_2_lacs_subsidy_to_loan',loan_upto_2_lacs_applicable:1,
    loan_above_2_lacs_applicable:1};
  for(const [prefix,pct] of [['loan_upto_2_lacs',90],['loan_above_2_lacs',80]]) {
    Object.assign(values,{[prefix+'_finance_mode']:'ratio',[prefix+'_margin_ratio_pct']:100-pct,
      [prefix+'_loan_ratio_pct']:pct,[prefix+'_margin_money_rs']:'',[prefix+'_loan_amount_rs']:'',
      [prefix+'_interest_pct']:5.75,[prefix+'_tenure_years']:10,
      [prefix+'_requested_loan_ratio_pct']:pct});
  }
  const inputs=Object.fromEntries(Object.entries(values).map(([n,v])=>[n,new Input(v)]));
  const form=new Form(inputs);
  const document={querySelector:()=>null,getElementById:()=>null,createElement:()=>new Input()};
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../assets/js/quote-form-autofill.js'),'utf8'),
    {window:{quoteFormAutofillConfig:{form}},document,HTMLFormElement:Form});
  const edit=(name,value,event='input')=>{inputs[name].value=String(value);inputs[name].dispatchEvent(new Event(event));if(event==='input')inputs[name].dispatchEvent(new Event('blur'));};
  return {inputs,edit};
}
const {inputs,edit}=setup();
const up='loan_upto_2_lacs', above='loan_above_2_lacs';
function split(prefix,loan,mode) {
  const price=Number(inputs['scenario_price_'+prefix].value);
  assert.equal(Number(inputs[prefix+'_loan_amount_rs'].value),loan);
  assert.equal(Number(inputs[prefix+'_margin_money_rs'].value),Math.round((price-loan)*100)/100);
  assert.ok(Math.abs(Number(inputs[prefix+'_loan_ratio_pct'].value)+Number(inputs[prefix+'_margin_ratio_pct'].value)-100)<.001);
  if(mode)assert.equal(inputs[prefix+'_finance_mode'].value,mode);
}
split(up,200000,'ratio');
edit(up+'_margin_ratio_pct',50);split(up,147450,'ratio');
edit(up+'_loan_ratio_pct',40);split(up,117960,'ratio');
edit(up+'_margin_money_rs',100000);split(up,194900,'manual');
edit(up+'_loan_amount_rs',150000);split(up,150000,'manual');
edit('scenario_price_loan_upto_2_lacs',300000);split(up,150000,'manual');
edit(up+'_finance_mode','ratio','change');split(up,150000,'ratio');
edit(up+'_loan_ratio_pct',90);split(up,200000,'ratio');
inputs[up+'_loan_ratio_pct'].dispatchEvent(new Event('blur')); // Tabbing without editing must retain the requested 90%.
edit('scenario_price_loan_upto_2_lacs',100000);split(up,90000,'ratio');
edit('scenario_price_loan_upto_2_lacs',294900);split(up,200000,'ratio');
edit(up+'_loan_amount_rs',400000);split(up,200000,'manual');
edit(above+'_loan_ratio_pct',85);split(above,250665,'ratio');
edit(above+'_margin_ratio_pct',25);split(above,221175,'ratio');
edit(above+'_margin_money_rs',50000);split(above,244900,'manual');
edit(above+'_loan_amount_rs',240000);split(above,240000,'manual');
edit(above+'_finance_mode','ratio','change');
split(above,240000,'ratio'); // Switching modes must not introduce rounding changes to money.
split(up,200000,'manual'); // Changing one band's mode must not reset the other band.
edit(up+'_margin_money_rs',400000);split(up,0,'manual');
inputs[up+'_margin_money_rs'].value='';
for(const digit of '100000') {
  inputs[up+'_margin_money_rs'].value+=digit;
  inputs[up+'_margin_money_rs'].dispatchEvent(new Event('input'));
}
assert.equal(inputs[up+'_margin_money_rs'].value,'100000');
inputs[up+'_margin_money_rs'].dispatchEvent(new Event('blur'));
split(up,194900,'manual');
edit(up+'_loan_ratio_pct',50);split(up,147450,'ratio');
assert.equal(Number(inputs.monthly_bill_rs.value),5400);
edit('monthly_bill_rs',4000);edit('scenario_price_loan_upto_2_lacs',300000);
assert.equal(Number(inputs.monthly_bill_rs.value),4000);
assert.equal(Number(inputs[up+'_tenure_years'].value),10);
assert.equal(Number(inputs[above+'_tenure_years'].value),10);
inputs[above+'_applicable'].checked=false;
inputs[above+'_applicable'].dispatchEvent(new Event('change'));
for(const suffix of ['finance_mode','margin_ratio_pct','loan_ratio_pct','margin_money_rs','loan_amount_rs'])assert.equal(inputs[above+'_'+suffix].disabled,false);
const reopened=setup(true).inputs;
assert.equal(Number(reopened[up+'_loan_amount_rs'].value),200000);
console.log('PASS: both bands support linked percentage, amount and mode edits; caps, price changes, saved initialization and bill/tenure values remain consistent');
