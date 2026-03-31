(function () {
    const config = window.quoteFormAutofillConfig || {};
    const quoteForm = config.form instanceof HTMLFormElement
        ? config.form
        : document.querySelector('form input[name="action"][value="save_quote"]')?.form;
    if (!quoteForm) return;

    const settingsBySegment = (config.settingsBySegment && typeof config.settingsBySegment === 'object') ? config.settingsBySegment : {};
    const safeDefaultEnergy = Number.isFinite(Number(config.defaultEnergy)) ? Number(config.defaultEnergy) : 1450;

    const field = (name) => quoteForm.querySelector('[name="' + name + '"]');
    const quoteIdInput = field('quote_id');
    const templateSet = field('template_set_id');
    const capacityInput = quoteForm.querySelector('#computedCapacityKwp') || field('capacity_kwp');
    const schemeTypeInput = field('scheme_type');
    const customerTypeInput = field('customer_type');
    const pmSuryagharInput = field('is_pm_suryaghar');

    const unitRateInput = field('unit_rate_rs_per_kwh');
    const annualGenerationInput = field('annual_generation_per_kw');
    const monthlyBillInput = field('monthly_bill_rs');
    const subsidyInput = field('subsidy_expected_rs');
    const monthlyBillTouchedFlag = field('monthly_bill_touched');

    const totalInput = field('system_total_incl_gst_rs');
    const transportInput = field('transportation_rs');
    const discountInput = field('discount_rs');
    const primaryScenarioInput = field('primary_finance_scenario');

    const priceSelfInput = field('scenario_price_self_funded');
    const priceUp2Input = field('scenario_price_loan_upto_2_lacs');
    const priceAbove2Input = field('scenario_price_loan_above_2_lacs');

    const up2ModeInput = field('loan_upto_2_lacs_finance_mode');
    const up2MarginPctInput = field('loan_upto_2_lacs_margin_ratio_pct');
    const up2LoanPctInput = field('loan_upto_2_lacs_loan_ratio_pct');
    const up2MarginRsInput = field('loan_upto_2_lacs_margin_money_rs');
    const up2LoanRsInput = field('loan_upto_2_lacs_loan_amount_rs');
    const up2InterestInput = field('loan_upto_2_lacs_interest_pct');
    const up2TenureInput = field('loan_upto_2_lacs_tenure_years');

    const above2ModeInput = field('loan_above_2_lacs_finance_mode');
    const above2MarginPctInput = field('loan_above_2_lacs_margin_ratio_pct');
    const above2LoanPctInput = field('loan_above_2_lacs_loan_ratio_pct');
    const above2MarginRsInput = field('loan_above_2_lacs_margin_money_rs');
    const above2LoanRsInput = field('loan_above_2_lacs_loan_amount_rs');
    const above2InterestInput = field('loan_above_2_lacs_interest_pct');
    const above2TenureInput = field('loan_above_2_lacs_tenure_years');

    const legacyLoanEnabledInput = field('loan_enabled');
    const legacyLoanAmountInput = field('loan_amount');
    const legacyLoanInterestInput = field('loan_interest_pct');
    const legacyLoanTenureInput = field('loan_tenure_years');
    const legacyLoanMarginInput = field('loan_margin_pct');

    const resetMonthlyBtn = config.resetMonthlyBtn || document.getElementById('resetMonthlySuggestion');
    const resetSubsidyBtn = config.resetSubsidyBtn || document.getElementById('resetSubsidyDefault');

    const parseNum = (value) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    };
    const fieldEmpty = (input) => !input || String(input.value || '').trim() === '';
    const isExistingQuote = () => !!(quoteIdInput && String(quoteIdInput.value || '').trim() !== '');

    const managedFields = [
        monthlyBillInput, subsidyInput, unitRateInput, annualGenerationInput,
        up2MarginPctInput, up2LoanPctInput, up2MarginRsInput, up2LoanRsInput, up2InterestInput, up2TenureInput,
        above2MarginPctInput, above2LoanPctInput, above2MarginRsInput, above2LoanRsInput, above2InterestInput, above2TenureInput
    ].filter(Boolean);
    managedFields.forEach((input) => {
        input.addEventListener('input', () => {
            input.dataset.touched = '1';
            if (input === monthlyBillInput && monthlyBillTouchedFlag) monthlyBillTouchedFlag.value = '1';
        });
    });

    const setIfAllowed = (input, value, options) => {
        if (!input) return;
        const force = !!(options && options.force);
        const noDecimals = !!(options && options.noDecimals);
        if (!force && input.dataset.touched === '1' && !fieldEmpty(input)) return;
        if (!force && input === monthlyBillInput && monthlyBillTouchedFlag?.value === '1') return;
        const rounded = noDecimals ? Math.round(value) : (Math.round(value * 100) / 100);
        input.value = String(rounded);
        if (force) input.dataset.touched = '';
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

    const applyMonthlySuggestion = (force) => {
        const shouldForce = !!force;
        if (!shouldForce && isExistingQuote() && !fieldEmpty(monthlyBillInput)) return;

        const segSettings = currentSegmentSettings();
        if (unitRateInput && fieldEmpty(unitRateInput)) setIfAllowed(unitRateInput, parseNum(segSettings.unit_rate_rs_per_kwh || 0), { force: shouldForce });
        if (annualGenerationInput && fieldEmpty(annualGenerationInput)) setIfAllowed(annualGenerationInput, parseNum(segSettings.annual_generation_per_kw || safeDefaultEnergy), { force: shouldForce });

        const capacity = parseNum(capacityInput?.value);
        const annualGeneration = parseNum(annualGenerationInput?.value || segSettings.annual_generation_per_kw || safeDefaultEnergy);
        const unitRate = parseNum(unitRateInput?.value || segSettings.unit_rate_rs_per_kwh || 0);
        const monthlySuggestion = (capacity * annualGeneration * unitRate) / 12;
        setIfAllowed(monthlyBillInput, monthlySuggestion, { force: shouldForce, noDecimals: true });
    };

    const applySubsidyDefault = (force) => {
        const shouldForce = !!force;
        if (!subsidyInput || !capacityInput) return;
        if (!shouldForce && !isPmSuryaGharContext()) return;
        if (!shouldForce && subsidyInput.dataset.touched === '1' && !fieldEmpty(subsidyInput)) return;
        setIfAllowed(subsidyInput, subsidyByCapacity(parseNum(capacityInput.value)), { force: shouldForce });
    };

    const grossPayable = () => {
        const gross = parseNum(totalInput?.value) + parseNum(transportInput?.value);
        return Math.max(0, gross - Math.max(0, parseNum(discountInput?.value)));
    };

    const scenarioPrice = (key) => {
        if (key === 'self_funded') return parseNum(priceSelfInput?.value);
        if (key.includes('loan_above_2_lacs')) return parseNum(priceAbove2Input?.value);
        return parseNum(priceUp2Input?.value);
    };

    const applyScenarioFinanceDefaults = (prefix, priceInput, marginPctInput, loanPctInput, marginRsInput, loanRsInput, interestInput, tenureInput, modeInput, fallbackMaxLoan) => {
        const price = parseNum(priceInput?.value);
        const mode = String(modeInput?.value || 'ratio') === 'manual' ? 'manual' : 'ratio';
        const segLoan = currentSegmentSettings().loan_bestcase || {};
        const maxLoan = parseNum(segLoan.max_loan_rs || fallbackMaxLoan);
        const minMarginPct = parseNum(segLoan.min_margin_pct || 10);
        const defaultTenure = parseNum(segLoan.tenure_years || 10);
        const defaultInterest = parseNum(segLoan.interest_pct || 6);

        if (mode === 'ratio') {
            let marginPct = parseNum(marginPctInput?.value);
            if (marginPct <= 0) marginPct = minMarginPct;
            marginPct = Math.min(100, Math.max(0, marginPct));
            let loanPct = parseNum(loanPctInput?.value);
            if (loanPct <= 0) loanPct = Math.max(0, 100 - marginPct);
            if (Math.abs((marginPct + loanPct) - 100) > 0.01) loanPct = Math.max(0, 100 - marginPct);

            let desiredLoan = price * (loanPct / 100);
            if (maxLoan > 0) desiredLoan = Math.min(desiredLoan, maxLoan);
            const marginRs = Math.max(0, price - desiredLoan);

            setIfAllowed(marginPctInput, marginPct, {});
            setIfAllowed(loanPctInput, Math.max(0, 100 - marginPct), {});
            setIfAllowed(loanRsInput, desiredLoan, {});
            setIfAllowed(marginRsInput, marginRs, {});
        }

        setIfAllowed(interestInput, defaultInterest, {});
        setIfAllowed(tenureInput, defaultTenure, { noDecimals: true });
    };

    const applyAllScenarioFinanceDefaults = () => {
        applyScenarioFinanceDefaults('up2', priceUp2Input, up2MarginPctInput, up2LoanPctInput, up2MarginRsInput, up2LoanRsInput, up2InterestInput, up2TenureInput, up2ModeInput, 200000);
        applyScenarioFinanceDefaults('above2', priceAbove2Input, above2MarginPctInput, above2LoanPctInput, above2MarginRsInput, above2LoanRsInput, above2InterestInput, above2TenureInput, above2ModeInput, 0);
        syncLegacyLoanFields();
    };

    const syncLegacyLoanFields = () => {
        const selected = String(primaryScenarioInput?.value || 'loan_upto_2_lacs_subsidy_to_loan');
        const isLoan = selected !== 'self_funded';
        const useAbove2 = selected.includes('loan_above_2_lacs');

        const loanAmount = useAbove2 ? parseNum(above2LoanRsInput?.value) : parseNum(up2LoanRsInput?.value);
        const interest = useAbove2 ? parseNum(above2InterestInput?.value) : parseNum(up2InterestInput?.value);
        const tenure = useAbove2 ? parseNum(above2TenureInput?.value) : parseNum(up2TenureInput?.value);
        const marginPct = useAbove2 ? parseNum(above2MarginPctInput?.value) : parseNum(up2MarginPctInput?.value);

        if (legacyLoanEnabledInput) legacyLoanEnabledInput.value = isLoan ? '1' : '0';
        if (legacyLoanAmountInput) legacyLoanAmountInput.value = String(isLoan ? loanAmount : 0);
        if (legacyLoanInterestInput) legacyLoanInterestInput.value = String(isLoan ? interest : 0);
        if (legacyLoanTenureInput) legacyLoanTenureInput.value = String(isLoan ? tenure : 0);
        if (legacyLoanMarginInput) legacyLoanMarginInput.value = String(isLoan ? marginPct : 0);
    };

    const bindRecalc = (input, handler) => { if (input) input.addEventListener('input', handler); };
    bindRecalc(capacityInput, () => { applyMonthlySuggestion(false); applySubsidyDefault(false); });
    bindRecalc(unitRateInput, () => applyMonthlySuggestion(false));
    bindRecalc(annualGenerationInput, () => applyMonthlySuggestion(false));
    bindRecalc(priceUp2Input, applyAllScenarioFinanceDefaults);
    bindRecalc(priceAbove2Input, applyAllScenarioFinanceDefaults);
    bindRecalc(priceSelfInput, syncLegacyLoanFields);
    bindRecalc(totalInput, applyAllScenarioFinanceDefaults);
    bindRecalc(transportInput, applyAllScenarioFinanceDefaults);
    bindRecalc(discountInput, applyAllScenarioFinanceDefaults);

    [up2ModeInput, above2ModeInput, up2MarginPctInput, above2MarginPctInput, up2LoanPctInput, above2LoanPctInput, up2InterestInput, above2InterestInput, up2TenureInput, above2TenureInput, up2LoanRsInput, above2LoanRsInput].forEach((el) => {
        el?.addEventListener('change', applyAllScenarioFinanceDefaults);
        el?.addEventListener('input', syncLegacyLoanFields);
    });

    templateSet?.addEventListener('change', () => {
        applyMonthlySuggestion(false);
        applySubsidyDefault(false);
        applyAllScenarioFinanceDefaults();
    });
    primaryScenarioInput?.addEventListener('change', syncLegacyLoanFields);

    resetMonthlyBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (monthlyBillTouchedFlag) monthlyBillTouchedFlag.value = '0';
        if (monthlyBillInput) monthlyBillInput.dataset.touched = '';
        if (unitRateInput) unitRateInput.dataset.touched = '';
        if (annualGenerationInput) annualGenerationInput.dataset.touched = '';
        applyMonthlySuggestion(true);
    });

    resetSubsidyBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        if (subsidyInput) subsidyInput.dataset.touched = '';
        applySubsidyDefault(true);
    });

    if (isExistingQuote() && monthlyBillInput && !fieldEmpty(monthlyBillInput)) {
        monthlyBillInput.dataset.touched = '1';
        if (monthlyBillTouchedFlag) monthlyBillTouchedFlag.value = '1';
    }

    applyMonthlySuggestion(false);
    applySubsidyDefault(false);
    applyAllScenarioFinanceDefaults();
    syncLegacyLoanFields();
})();
