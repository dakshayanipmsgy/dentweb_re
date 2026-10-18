(function () {
  'use strict';
  const form = document.querySelector('form[data-quotation-save-form="admin"]');
  if (!form) return;
  const field = name => form.elements.namedItem(name);
  const number = input => Number(input?.value || 0);
  const money = n => '₹' + Number(n).toLocaleString('en-IN', {maximumFractionDigits:2});
  let dirty = false;
  const systemSection=document.querySelector('#quote-system-details .section-grid');
  if(systemSection){
    const model=document.getElementById('rateChartModelField');
    const type=field('system_type')?.parentElement;
    if(model)systemSection.prepend(model);if(type)systemSection.prepend(type);
  }
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('quotation-saved', () => { dirty = false; });
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue=''; } });

  // Keep native tab ordering. Every generated field gets an associated label.
  let labelIndex=0;
  form.querySelectorAll('input:not([type=hidden]),select,textarea').forEach(input => {
    const label=input.closest('label') || input.parentElement.querySelector(':scope > label');
    if(label&&!label.contains(input)){input.id ||= 'quote-field-'+(++labelIndex);label.htmlFor=input.id;}
    if(input.name.includes('mobile')){input.inputMode='tel';input.autocomplete='tel';}
    if(input.readOnly)input.tabIndex=-1;
  });
  form.addEventListener('keydown', e => {
    if(e.key==='Enter' && e.target.matches('input:not([type=submit]):not([type=button])')){
      e.preventDefault();
      const focusable=[...form.querySelectorAll('input,select,textarea,button,[contenteditable=true]')].filter(n=>!n.disabled&&n.tabIndex!==-1&&n.getClientRects().length);
      const next=focusable[focusable.indexOf(e.target)+1];next?.focus();
    }
  });

  // Lookup uses the existing authenticated endpoint; no page navigation or discarded values.
  const lookup=document.querySelector('#quotationEditor form[method=get]');
  if(lookup){
    const status=document.createElement('p');status.setAttribute('role','status');lookup.append(status);
    lookup.addEventListener('submit',async e=>{
      e.preventDefault();const mobile=lookup.querySelector('[name=lookup_mobile]').value.trim();
      const current=field('customer_mobile').value.trim();
      if(current&&current!==mobile&&dirty&&!window.confirm('Load a different customer? Your system and price entries will be retained.'))return;
      const button=lookup.querySelector('button');button.disabled=true;status.textContent='Finding customer…';
      try{
        const url=new URL(location.href);url.searchParams.set('lookup_mobile',mobile);url.searchParams.set('lookup_json','1');
        const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});
        if(response.redirected||!response.ok)throw new Error('Lookup failed. Your entries are retained; sign in again if needed.');
        const payload=await response.json();const result=payload.result;const row=result?.record;
        field('customer_mobile').value=mobile;
        if(row){
          const mapping={customer_name:row.name,city:row.city,district:row.district,state:row.state,pin:row.pin||row.area_pincode,billing_address:row.address||row.area_or_locality,site_address:row.site_address||row.address||row.area_or_locality,consumer_account_no:row.consumer_account_no,meter_number:row.meter_number};
          Object.entries(mapping).forEach(([name,value])=>{const input=field(name);if(input&&(current!==mobile||!input.value))input.value=value||'';});
          field('party_type').value=result.type==='customer'?'customer':'lead';
          ['type','lead_id','lead_mobile'].forEach(k=>{if(field('source_'+k))field('source_'+k).value=result.source?.[k]||'';});
          if(current!==mobile||!field('sales_assigned_to').value)field('sales_assigned_to').value=row.assigned_to_name||'';
          if(current!==mobile||!field('sales_followup_date').value)field('sales_followup_date').value=row.next_followup_date||'';
          status.textContent='Customer details loaded. Review any missing fields.';
        }else{
          if(current!==mobile){['customer_name','billing_address','site_address','consumer_account_no','meter_number','sales_assigned_to','sales_followup_date','sales_outcome'].forEach(n=>{if(field(n))field(n).value='';});}
          ['type','lead_id','lead_mobile'].forEach(k=>{if(field('source_'+k))field('source_'+k).value='';});
          status.textContent='No existing record found. Enter the customer name to continue.';
        }
        form.dispatchEvent(new Event('input',{bubbles:true}));field('customer_name').focus();
      }catch(error){status.textContent=error.message||'Lookup failed. All entered details are retained.';}
      finally{button.disabled=false;}
    });
  }

  const base=field('breakdown_base_price'),rows=document.getElementById('breakdownRows');
  if(base&&rows){
    const self=field('scenario_price_self_funded');let syncing=false;
    let previous=number(self);
    const extras=()=>[...rows.querySelectorAll('[name="breakdown_amount[]"]')].reduce((sum,n)=>sum+number(n),0);
    const recalc=()=>{
      if(syncing)return;syncing=true;
      const total=Math.round((number(base)+extras())*100)/100;
      ['scenario_price_loan_upto_2_lacs','scenario_price_loan_above_2_lacs'].forEach(n=>{
        const input=field(n);const difference=Math.round((number(input)-previous)*100)/100;
        input.value=Math.max(0,Math.round((total+difference)*100)/100);input.dispatchEvent(new Event('input',{bubbles:true}));
      });
      self.value=total;previous=total;self.dispatchEvent(new Event('input',{bubbles:true}));
      document.getElementById('breakdownTotal').textContent=' Total including charges: '+money(total);syncing=false;
    };
    base.addEventListener('input',recalc);rows.addEventListener('input',recalc);
    document.getElementById('rateChartModelSelect')?.addEventListener('change',()=>{base.value=number(self);recalc();});
    self.addEventListener('input',()=>{if(syncing)return;previous=number(self);base.value=Math.max(0,Math.round((previous-extras())*100)/100);document.getElementById('breakdownTotal').textContent=' Total including charges: '+money(previous);});
    document.getElementById('addBreakdownCharge').addEventListener('click',()=>{
      const row=document.createElement('div');row.className='charge-row';row.innerHTML='<label>Charge name<input name="breakdown_label[]" maxlength="150" required placeholder="e.g. High structure"></label><label>Amount ₹<input name="breakdown_amount[]" type="number" min="0" step="0.01" value="0" required></label><button type="button" data-remove-charge>Remove charge</button>';rows.append(row);row.querySelector('input').focus();
    });
    rows.addEventListener('click',e=>{if(e.target.closest('[data-remove-charge]')){e.target.closest('.charge-row').remove();recalc();}});
    document.getElementById('breakdownTotal').textContent=' Total including charges: '+money(number(self));
    // Keep the full scenario controls as advanced options, not mandatory duplicate price entry.
    const details=document.createElement('details');details.innerHTML='<summary>Different prices for financing (optional)</summary>';
    ['scenario_price_self_funded','scenario_price_loan_upto_2_lacs','scenario_price_loan_above_2_lacs'].forEach(n=>{const input=field(n);const wrapper=input.closest('.section-grid > div');if(wrapper)details.append(wrapper);});
    document.getElementById('quote-price-breakdown').append(details);
  }
  const review=document.getElementById('quoteReviewSummary');
  field('primary_finance_scenario')?.addEventListener('change',()=>{
    const above=field('loan_above_2_lacs_applicable');
    if(field('primary_finance_scenario').value.includes('loan_above_2_lacs')&&above&&!above.disabled){above.checked=true;above.dispatchEvent(new Event('change',{bubbles:true}));}
  });
  function updatePriceReview(){
    if(!review)return;let card=review.querySelector('[data-price-review]');if(!card){card=document.createElement('div');card.dataset.priceReview='';review.append(card);}
    card.textContent='Price '+money(number(field('system_total_incl_gst_rs')))+' | Subsidy '+money(number(field('subsidy_expected_rs')))+' | '+(field('primary_finance_scenario')?.selectedOptions[0]?.textContent||'');
  }
  form.addEventListener('input',updatePriceReview);form.addEventListener('change',updatePriceReview);updatePriceReview();
})();
