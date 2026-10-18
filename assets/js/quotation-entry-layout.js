(function () {
  'use strict';
  const editor = document.getElementById('quotationEditor');
  const form = editor?.querySelector('form[data-quotation-save-form="admin"]');
  const root = form?.querySelector(':scope > .grid');
  if (!root || form.dataset.entryLayout === 'ready') return;
  const field = name => form.elements.namedItem(name);
  const card = id => document.getElementById('quote-' + id);
  const inner = id => card(id)?.querySelector(':scope > .section-grid');
  const wrapper = name => field(name)?.closest('.section-grid > div');
  const customer = inner('customer-details'), system = inner('system-details'), pricing = inner('pricing-template');
  const save = card('save-actions');
  if (!customer || !system || !pricing || !save) return;

  // Move the existing controls, never clone inputs or change their values/names.
  document.body.classList.add('quotation-entry-page');
  function title(id, number, label, help) {
    const summary = card(id)?.querySelector(':scope > summary');
    if (!summary) return;
    summary.querySelector('.section-number').textContent = number;
    summary.querySelector('strong').textContent = label;
    summary.querySelector('small').textContent = help;
  }
  function options(parent, label) {
    const details = document.createElement('details');
    details.className = 'entry-extra-fields full-span';
    const summary = document.createElement('summary'); summary.textContent = label;
    const grid = document.createElement('div'); grid.className = 'section-grid';
    details.append(summary, grid); parent.append(details);
    return {details, grid};
  }
  function move(names, target) { names.forEach(name => { const node = wrapper(name); if (node) target.append(node); }); }
  title('customer-details', '1', 'Customer', 'Find an existing customer by mobile, or enter a new customer below.');
  title('system-details', '2', 'System', 'Choose a model to fill the capacity, price and standard scope.');
  title('pricing-template', '3', 'Price & payment', 'Confirm the price, funding choice, bill and subsidy.');
  title('finance-savings', '', 'Finance & savings', 'Edit linked loan splits, rates and generation assumptions.');
  title('items-scope', '', 'Scope & extra requirements', 'Review the selected kit, add items or record a special requirement.');
  title('notes-terms', '', 'Notes & annexures', 'Use the saved template, or customize individual sections.');
  title('customer-layout', '4', 'Customer-facing layout', 'Build a visual rooftop proposal and check the customer preview.');
  const customerExtra = options(customer, 'Billing, meter & customer details');
  const oldCustomerExtra = customer.querySelector(':scope > .optional-fields');
  if (oldCustomerExtra) {
    [...oldCustomerExtra.querySelector(':scope > .section-grid').children].forEach(node => customerExtra.grid.append(node));
    oldCustomerExtra.remove();
  }
  move(['party_type','billing_address','state','pin'], customerExtra.grid);
  const copyBilling = document.getElementById('copyBillingAddressBtn');
  const billing = wrapper('billing_address');
  if (copyBilling && billing) billing.append(copyBilling);
  const siteLabel = wrapper('site_address')?.querySelector(':scope > label');
  if (siteLabel && field('site_address')?.id) siteLabel.htmlFor = field('site_address').id;
  move(['customer_mobile','customer_name','district','city','site_address'], customer);
  Object.entries({customer_mobile:'10-digit mobile number',customer_name:'Customer name',district:'e.g. Ranchi',city:'e.g. Ranchi',site_address:'Installation address',project_summary_line:'e.g. 5 kW rooftop solar with high structure'}).forEach(([name,hint])=>{
    const input=field(name);if(input&&!input.placeholder)input.placeholder=hint;
  });
  customer.append(customerExtra.details);
  const followup = field('sales_assigned_to')?.closest('details')?.parentElement;
  if (followup && customer.contains(followup)) customerExtra.grid.append(followup);

  // One visible mobile input; the existing independent lookup form still owns its request.
  const lookup = editor.querySelector('form[method="get"]');
  const lookupSection = card('find-customer');
  const mobile = field('customer_mobile');
  if (lookup && lookupSection && mobile) {
    const lookupMobile = lookup.querySelector('[name="lookup_mobile"]');
    const originalButton = lookup.querySelector('button');
    if (lookupMobile && originalButton) {
      const mobileLabel = mobile.closest('.section-grid > div')?.querySelector(':scope > label');
      mobile.id ||= 'quotation-customer-mobile';
      if (mobileLabel) mobileLabel.htmlFor = mobile.id;
      lookup.dataset.currentMobile = mobile.value.trim();
      const row = document.createElement('div'); row.className = 'entry-phone-row';
      mobile.before(row); row.append(mobile);
      const find = document.createElement('button'); find.type = 'button'; find.className = 'btn secondary';
      find.textContent = 'Find customer'; row.append(find);
      find.addEventListener('click', () => { lookupMobile.value = mobile.value; lookup.requestSubmit(); });
      new MutationObserver(() => { find.disabled = originalButton.disabled; }).observe(originalButton, {attributes:true, attributeFilter:['disabled']});
      const status = lookup.querySelector('[role="status"]');
      if (status) row.after(status);
      lookupSection.hidden = true;
      if (!mobile.value) mobile.focus();
    }
  }
  const systemExtra = system.querySelector(':scope > .optional-fields');
  if (systemExtra) systemExtra.querySelector('summary').textContent = 'Application & hybrid details';
  move(['special_requests_text'], inner('items-scope'));
  if (systemExtra) system.append(systemExtra);
  field('system_type')?.addEventListener('change', () => {
    if (systemExtra && String(field('system_type').value).toLowerCase() === 'hybrid') systemExtra.open = true;
    if (String(field('system_type').value).toLowerCase() === 'product') card('items-scope').open = true;
  });

  const pricingExtra = options(pricing, 'Dates, GST, transport & other prices');
  move(['quotation_date','valid_until','pricing_mode','tax_profile_id','show_tax_breakup','place_of_supply_state',
    'transportation_rs','discount_rs','discount_note'], pricingExtra.grid);
  const breakdown = document.getElementById('quote-price-breakdown');
  const alternativePrices = breakdown?.querySelector('details');
  if (alternativePrices) {
    [...alternativePrices.children].filter(node => node.tagName !== 'SUMMARY').forEach(node => pricingExtra.grid.append(node));
    alternativePrices.remove();
  }
  move(['template_set_id','primary_finance_scenario'], pricing);
  if (breakdown) { breakdown.classList.add('full-span'); pricing.append(breakdown); }
  move(['monthly_bill_rs','subsidy_expected_rs'], pricing);
  pricing.append(pricingExtra.details);

  // Each loan band opens with all its linked fields together, without a second accordion.
  const finance = inner('finance-savings');
  if (finance) {
    const oldFinanceExtra = finance.querySelector(':scope > .optional-fields');
    if (oldFinanceExtra) {
      [...oldFinanceExtra.querySelector(':scope > .section-grid').children].forEach(node => finance.append(node));
      oldFinanceExtra.remove();
    }
    ['loan_upto_2_lacs','loan_above_2_lacs'].forEach((prefix, index) => {
      const group = document.createElement('fieldset'); group.className = 'entry-loan-group full-span';
      const legend = document.createElement('legend'); legend.textContent = index ? 'Loan above ₹2 lakh' : 'Loan up to ₹2 lakh';
      const grid = document.createElement('div'); grid.className = 'section-grid'; group.append(legend, grid);
      move(['applicable','finance_mode','margin_ratio_pct','loan_ratio_pct','margin_money_rs','loan_amount_rs','interest_pct','tenure_years'].map(suffix => prefix + '_' + suffix), grid);
      finance.append(group);
    });
    [...finance.children].forEach(node => {
      if (node.querySelector(':scope > h3')?.textContent.match(/^Loan (up to|above)/)) node.hidden = true;
    });
  }

  const main = document.createElement('div'); main.className = 'entry-main-column';
  ['customer-details','system-details','pricing-template'].forEach(id => main.append(card(id)));
  if (card('customer-layout')) main.append(card('customer-layout'));
  const optional = document.createElement('section'); optional.className = 'entry-options';
  const heading = document.createElement('h3'); heading.textContent = 'Optional details'; optional.append(heading);
  ['finance-savings','items-scope','notes-terms'].forEach(id => { card(id).open = false; optional.append(card(id)); });
  main.append(optional);
  const quick = root.querySelector('.form-quick-actions');
  if (quick) {
    quick.classList.add('entry-mobile-save');
    const reviewButton = quick.querySelector('[data-review-quote]');
    if (reviewButton) { reviewButton.textContent = 'Check required fields'; save.querySelector('.save-actions').prepend(reviewButton); }
  }
  root.append(main, save);
  root.classList.add('entry-workspace'); save.classList.add('entry-review');
  save.setAttribute('aria-label','Review and save quotation');
  save.style.gridColumn = ''; save.style.gridRow = '';
  const saveHeading = save.querySelector('.static-section-heading');
  saveHeading.querySelector('.section-number').textContent = '5';
  saveHeading.querySelector('strong').textContent = 'Review & save';
  saveHeading.querySelector('small').textContent = 'Your details stay here if a field needs attention.';
  const total = document.createElement('div'); total.className = 'entry-live-total'; total.setAttribute('aria-live','polite');
  saveHeading.after(total);
  const format = value => '₹' + Number(value || 0).toLocaleString('en-IN', {maximumFractionDigits:2});
  function updateTotal() {
    total.replaceChildren();
    const label = document.createElement('span'); label.textContent = 'System total including GST';
    const amount = document.createElement('strong'); amount.textContent = format(field('system_total_incl_gst_rs')?.value);
    const subsidy = document.createElement('small'); subsidy.textContent = 'Expected subsidy ' + format(field('subsidy_expected_rs')?.value);
    total.append(label, amount, subsidy);
    const status = quick?.querySelector('[data-builder-status]');
    if (status) status.textContent = amount.textContent + ' · ' + (field('customer_name')?.value || 'New quotation');
  }
  form.addEventListener('input', updateTotal); form.addEventListener('change', updateTotal); updateTotal();
  const progress = editor.querySelector('.quote-builder-progress');
  if (progress) {
    progress.replaceChildren();
    [['customer-details','Customer'],['system-details','System'],['pricing-template','Price'],['customer-layout','Layout'],['save-actions','Review']].forEach(([id,label], index) => {
      const button = document.createElement('button'); button.type = 'button'; button.dataset.target = 'quote-' + id;
      button.textContent = (index + 1) + '  ' + label; progress.append(button);
    });
  }
  editor.querySelector('.editor-intro p')?.replaceChildren(document.createTextNode('Customer, system, price — then save. Open optional details only when you need them.'));
  // Keep the commercial document links available without consuming the first screen.
  const lifecycle = document.querySelector('[aria-label="Commercial lifecycle"]');
  if (lifecycle) {
    const links = document.createElement('details'); links.className = 'entry-document-links';
    const summary = document.createElement('summary'); summary.textContent = 'Other commercial documents';
    lifecycle.before(links); links.append(summary, lifecycle);
  }
  form.dataset.entryLayout = 'ready';
})();
