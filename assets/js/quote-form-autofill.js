(function () {
    const config = window.quoteFormAutofillConfig || {};
    const quoteForm = config.form instanceof HTMLFormElement
        ? config.form
        : document.querySelector('form input[name="action"][value="save_quote"]')?.form;
    if (!quoteForm) return;

    const settingsBySegment = (config.settingsBySegment && typeof config.settingsBySegment === 'object') ? config.settingsBySegment : {};
    const defaultEnergy = Number(config.defaultEnergy);
    const safeDefaultEnergy = Number.isFinite(defaultEnergy) ? defaultEnergy : 1450;

    const field = (name) => quoteForm.querySelector('[name="' + name + '"]');
    const loanEnabled = field('loan_enabled');
    const templateSet = field('template_set_id');
    const totalInput = field('system_total_incl_gst_rs');
    const transportInput = field('transportation_rs');
    const subsidyInput = field('subsidy_expected_rs');
    const discountInput = field('discount_rs');
    const discountNoteInput = field('discount_note');
    const capacityInput = quoteForm.querySelector('#computedCapacityKwp') || field('capacity_kwp');
    const schemeTypeInput = field('scheme_type');
    const customerTypeInput = field('customer_type');
    const pmSuryagharInput = field('is_pm_suryaghar');
    const quoteIdInput = field('quote_id');
    const unitRateInput = field('unit_rate_rs_per_kwh');
    const annualGenerationInput = field('annual_generation_per_kw');
    const monthlyBillInput = field('monthly_bill_rs');
    const loanAmountInput = field('loan_amount');
    const loanInterestInput = field('loan_interest_pct');
    const loanTenureInput = field('loan_tenure_years');
    const loanMarginInput = field('loan_margin_pct');

    const resetLoanBtn = config.resetLoanBtn || document.getElementById('resetLoanDefaults');
    const resetMonthlyBtn = config.resetMonthlyBtn || document.getElementById('resetMonthlySuggestion');
    const resetSubsidyBtn = config.resetSubsidyBtn || document.getElementById('resetSubsidyDefault');

    const overrideField = (key) => field('customer_savings_override_' + key);
    const overrideInputs = {
        monthly_bill_rs: overrideField('monthly_bill_rs'),
        unit_rate_rs_per_kwh: overrideField('unit_rate_rs_per_kwh'),
        annual_generation_per_kw: overrideField('annual_generation_per_kw'),
        transportation_rs: overrideField('transportation_rs'),
        discount_rs: overrideField('discount_rs'),
        discount_note: overrideField('discount_note'),
        subsidy_expected_rs: overrideField('subsidy_expected_rs'),
        loan_enabled: overrideField('loan_enabled'),
        loan_interest_pct: overrideField('loan_interest_pct'),
        loan_tenure_years: overrideField('loan_tenure_years'),
        loan_margin_pct: overrideField('loan_margin_pct'),
        loan_amount: overrideField('loan_amount')
    };

    const fieldOverrideKey = new Map([
        [monthlyBillInput, 'monthly_bill_rs'],
        [unitRateInput, 'unit_rate_rs_per_kwh'],
        [annualGenerationInput, 'annual_generation_per_kw'],
        [transportInput, 'transportation_rs'],
        [discountInput, 'discount_rs'],
        [subsidyInput, 'subsidy_expected_rs'],
        [loanAmountInput, 'loan_amount'],
        [loanInterestInput, 'loan_interest_pct'],
        [loanTenureInput, 'loan_tenure_years'],
        [loanMarginInput, 'loan_margin_pct']
    ]);
    const isOverridden = (key) => !!(overrideInputs[key] && overrideInputs[key].value === '1');
    const setOverridden = (key, value) => {
        if (!overrideInputs[key]) return;
        overrideInputs[key].value = value ? '1' : '0';
    };

    const managedFields = [monthlyBillInput, subsidyInput, loanAmountInput, loanInterestInput, loanTenureInput, loanMarginInput, unitRateInput, annualGenerationInput].filter(Boolean);
    managedFields.forEach((input) => {
        input.addEventListener('input', () => {
            input.dataset.touched = '1';
            const key = fieldOverrideKey.get(input);
            if (key) {
                setOverridden(key, true);
            }
        });
    });
    loanEnabled?.addEventListener('change', () => setOverridden('loan_enabled', true));
    transportInput?.addEventListener('input', () => setOverridden('transportation_rs', true));
    discountInput?.addEventListener('input', () => setOverridden('discount_rs', true));
    discountNoteInput?.addEventListener('input', () => setOverridden('discount_note', true));

    const parseNum = (value) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    };
    const fieldEmpty = (input) => !input || String(input.value || '').trim() === '';
    const setIfAllowed = (input, value, options) => {
        const force = !!(options && options.force);
        const noDecimals = !!(options && options.noDecimals);
        if (!input) return;
        const overrideKey = fieldOverrideKey.get(input);
        if (!force && overrideKey && isOverridden(overrideKey)) return;
        if (!force && input.dataset.touched === '1' && !fieldEmpty(input)) return;
        const val = noDecimals ? Math.round(value) : Math.round(value * 100) / 100;
        input.value = String(val);
        if (force && overrideKey) {
            setOverridden(overrideKey, false);
        }
    };

    const currentSegmentCode = () => {
        const selected = templateSet?.selectedOptions?.[0];
        const code = String(selected?.dataset?.segment || 'RES').toUpperCase();
        return settingsBySegment[code] ? code : 'RES';
    };

    const currentSegmentSettings = () => {
        const code = currentSegmentCode();
        return settingsBySegment[code] || settingsBySegment.RES || {
            unit_rate_rs_per_kwh: 0,
            annual_generation_per_kw: safeDefaultEnergy,
            loan_bestcase: { max_loan_rs: 200000, interest_pct: 6, tenure_years: 10, min_margin_pct: 10 }
        };
    };

    const isPmSuryaGharContext = () => {
        if (schemeTypeInput) return String(schemeTypeInput.value || '').trim().toLowerCase() === 'pm surya ghar';
        if (customerTypeInput) return String(customerTypeInput.value || '').trim().toLowerCase() === 'pm surya ghar';
        if (pmSuryagharInput) {
            if (pmSuryagharInput.type === 'checkbox') return !!pmSuryagharInput.checked;
            return ['1', 'true', 'yes', 'pm surya ghar'].includes(String(pmSuryagharInput.value || '').trim().toLowerCase());
        }
        return currentSegmentCode() === 'RES';
    };

    const subsidyByCapacity = (capacity) => {
        if (Math.abs(capacity - 2.0) < 0.01) return 60000;
        if (capacity >= 3.0 - 0.001) return 78000;
        return 0;
    };

    const applySubsidyDefault = (force) => {
        const shouldForce = !!force;
        if (!subsidyInput || !capacityInput) return;
        const isNewQuote = !quoteIdInput || String(quoteIdInput.value || '').trim() === '';
        const isEmpty = fieldEmpty(subsidyInput);
        if (!shouldForce) {
            if (!isPmSuryaGharContext()) return;
            if (subsidyInput.dataset.touched === '1' && !isEmpty) return;
            if (!isEmpty && !isNewQuote) return;
        }
        subsidyInput.value = String(subsidyByCapacity(parseNum(capacityInput.value)));
    };

    const computeGrossPayable = () => {
        const grossBeforeDiscount = parseNum(totalInput?.value) + parseNum(transportInput?.value);
        const discount = Math.max(0, parseNum(discountInput?.value));
        return Math.max(0, grossBeforeDiscount - discount);
    };

    const applyLoanDefaults = (force) => {
        const shouldForce = !!force;
        if (!loanEnabled || !loanEnabled.checked) return;
        const loanCfg = currentSegmentSettings().loan_bestcase || {};
        const grossPayable = computeGrossPayable();
        const maxLoan = parseNum(loanCfg.max_loan_rs || 200000);
        const minMarginPct = parseNum(loanCfg.min_margin_pct || 10);
        const desiredLoan = grossPayable - (grossPayable * (minMarginPct / 100));
        const loanAmount = Math.max(0, Math.min(desiredLoan, maxLoan));
        const marginAmount = Math.max(0, grossPayable - loanAmount);

        setIfAllowed(loanAmountInput, loanAmount, { force: shouldForce });
        setIfAllowed(loanMarginInput, marginAmount, { force: shouldForce });
        setIfAllowed(loanInterestInput, parseNum(loanCfg.interest_pct || 6), { force: shouldForce });
        setIfAllowed(loanTenureInput, parseNum(loanCfg.tenure_years || 10), { force: shouldForce, noDecimals: true });
    };

    const applyMonthlySuggestion = (force) => {
        const shouldForce = !!force;
        const segSettings = currentSegmentSettings();
        if (unitRateInput && fieldEmpty(unitRateInput) && (!unitRateInput.dataset.touched || shouldForce)) {
            unitRateInput.value = String(parseNum(segSettings.unit_rate_rs_per_kwh || 0));
        }
        if (annualGenerationInput && fieldEmpty(annualGenerationInput) && (!annualGenerationInput.dataset.touched || shouldForce)) {
            annualGenerationInput.value = String(parseNum(segSettings.annual_generation_per_kw || safeDefaultEnergy));
        }

        const capacity = parseNum(capacityInput?.value);
        const annualGeneration = parseNum(annualGenerationInput?.value || segSettings.annual_generation_per_kw || safeDefaultEnergy);
        const unitRate = parseNum(unitRateInput?.value || segSettings.unit_rate_rs_per_kwh || 0);
        const currentMonthlyBill = parseNum(monthlyBillInput?.value);
        if (!shouldForce && isOverridden('monthly_bill_rs')) return;
        if (!shouldForce && monthlyBillInput && !fieldEmpty(monthlyBillInput) && currentMonthlyBill > 0 && monthlyBillInput.dataset.touched === '1') return;
        setIfAllowed(monthlyBillInput, (capacity * annualGeneration * unitRate) / 12, { force: shouldForce, noDecimals: true });
    };

    const bindRecalc = (input, handler) => { if (input) input.addEventListener('input', handler); };
    bindRecalc(totalInput, () => applyLoanDefaults(false));
    bindRecalc(transportInput, () => applyLoanDefaults(false));
    bindRecalc(discountInput, () => applyLoanDefaults(false));
    bindRecalc(subsidyInput, () => applyLoanDefaults(false));
    bindRecalc(capacityInput, () => { applyMonthlySuggestion(false); applySubsidyDefault(false); });
    bindRecalc(unitRateInput, () => applyMonthlySuggestion(false));
    bindRecalc(annualGenerationInput, () => applyMonthlySuggestion(false));

    if (loanEnabled) {
        loanEnabled.addEventListener('change', () => {
            if (loanEnabled.checked) applyLoanDefaults(false);
        });
    }
    if (templateSet) {
        templateSet.addEventListener('change', () => {
            applyLoanDefaults(false);
            applyMonthlySuggestion(false);
            applySubsidyDefault(false);
        });
    }
    if (schemeTypeInput) schemeTypeInput.addEventListener('change', () => applySubsidyDefault(false));
    if (customerTypeInput) customerTypeInput.addEventListener('change', () => applySubsidyDefault(false));
    if (pmSuryagharInput) pmSuryagharInput.addEventListener('change', () => applySubsidyDefault(false));

    resetLoanBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        [loanAmountInput, loanInterestInput, loanTenureInput, loanMarginInput].forEach((input) => { if (input) input.dataset.touched = ''; });
        setOverridden('loan_amount', false);
        setOverridden('loan_interest_pct', false);
        setOverridden('loan_tenure_years', false);
        setOverridden('loan_margin_pct', false);
        applyLoanDefaults(true);
    });
    resetMonthlyBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (monthlyBillInput) monthlyBillInput.dataset.touched = '';
        setOverridden('monthly_bill_rs', false);
        if (unitRateInput) unitRateInput.dataset.touched = '';
        setOverridden('unit_rate_rs_per_kwh', false);
        if (annualGenerationInput) annualGenerationInput.dataset.touched = '';
        setOverridden('annual_generation_per_kw', false);
        applyMonthlySuggestion(true);
    });
    resetSubsidyBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (subsidyInput) subsidyInput.dataset.touched = '';
        setOverridden('subsidy_expected_rs', false);
        applySubsidyDefault(true);
        applyLoanDefaults(false);
    });

    applyLoanDefaults(false);
    applyMonthlySuggestion(false);
    applySubsidyDefault(false);
})();
