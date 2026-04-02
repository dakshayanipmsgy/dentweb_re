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
    const up2ApplicableInput = field('loan_upto_2_lacs_applicable');
    const above2ApplicableInput = field('loan_above_2_lacs_applicable');

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
    const LOAN_UPTO_2_LACS_CAP_RS = 200000;
    const LOAN_ABOVE_2_LACS_THRESHOLD_RATIO = 0.9;
    const LOAN_ABOVE_2_LACS_THRESHOLD_RS = 200000;
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

    const markExistingValueAsTouched = (input) => {
        if (!input) return;
        if (String(input.value || '').trim() !== '') {
            input.dataset.touched = '1';
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
            loan_bestcase: { tenure_years: 10, min_margin_pct: 10 }
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

    const scenarioPrice = (key) => {
        if (key === 'self_funded') return parseNum(priceSelfInput?.value);
        if (key.includes('loan_above_2_lacs')) return parseNum(priceAbove2Input?.value);
        return parseNum(priceUp2Input?.value);
    };

    const selectedPrimaryScenarioPrice = () => {
        const selected = String(primaryScenarioInput?.value || 'loan_upto_2_lacs_subsidy_to_loan');
        const scenarioBasedPrice = scenarioPrice(selected);
        if (scenarioBasedPrice > 0) {
            return scenarioBasedPrice;
        }
        return parseNum(totalInput?.value);
    };

    const syncSelectedPrimarySystemPrice = () => {
        if (!totalInput) return;
        totalInput.value = String(Math.max(0, Math.round(selectedPrimaryScenarioPrice() * 100) / 100));
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const round2 = (value) => Math.round(parseNum(value) * 100) / 100;
    const pctFor = (amount, total) => (total > 0 ? (amount / total) * 100 : 0);

    const getScenarioDefaults = (scenarioType) => {
        const segLoan = currentSegmentSettings().loan_bestcase || {};
        const defaultTenure = parseNum(segLoan.tenure_years || 10);
        if (scenarioType === 'up2') {
            return { marginPct: 10, loanPct: 90, interest: 5.75, tenure: defaultTenure, capLoan: true };
        }
        return { marginPct: 20, loanPct: 80, interest: 8.15, tenure: defaultTenure, capLoan: false };
    };

    const enforceLoanRules = (loanAmount, price, applyCap) => {
        let finalLoan = clamp(round2(loanAmount), 0, Math.max(0, round2(price)));
        if (applyCap) finalLoan = Math.min(finalLoan, LOAN_UPTO_2_LACS_CAP_RS);
        const finalMargin = Math.max(0, round2(price) - finalLoan);
        return { loan: round2(finalLoan), margin: round2(finalMargin) };
    };

    const syncScenarioByPercent = (ctx, changedField) => {
        const price = parseNum(ctx.priceInput?.value);
        const defaults = getScenarioDefaults(ctx.type);
        let marginPct = parseNum(ctx.marginPctInput?.value);
        let loanPct = parseNum(ctx.loanPctInput?.value);

        if (fieldEmpty(ctx.marginPctInput)) marginPct = defaults.marginPct;
        if (fieldEmpty(ctx.loanPctInput)) loanPct = defaults.loanPct;
        if (changedField === 'margin_pct') loanPct = 100 - marginPct;
        if (changedField === 'loan_pct') marginPct = 100 - loanPct;
        if (!Number.isFinite(marginPct)) marginPct = defaults.marginPct;
        marginPct = clamp(marginPct, 0, 100);
        loanPct = clamp(100 - marginPct, 0, 100);

        const computedLoan = price * (loanPct / 100);
        const amounts = enforceLoanRules(computedLoan, price, defaults.capLoan);
        const finalMarginPct = clamp(pctFor(amounts.margin, price), 0, 100);
        const finalLoanPct = clamp(100 - finalMarginPct, 0, 100);

        setIfAllowed(ctx.marginPctInput, round2(finalMarginPct), { force: true });
        setIfAllowed(ctx.loanPctInput, round2(finalLoanPct), { force: true });
        setIfAllowed(ctx.loanRsInput, amounts.loan, { force: true });
        setIfAllowed(ctx.marginRsInput, amounts.margin, { force: true });
    };

    const syncScenarioByAmount = (ctx, changedField) => {
        const price = parseNum(ctx.priceInput?.value);
        const defaults = getScenarioDefaults(ctx.type);
        let marginRs = parseNum(ctx.marginRsInput?.value);
        let loanRs = parseNum(ctx.loanRsInput?.value);

        if (changedField === 'margin_rs') {
            loanRs = price - marginRs;
        } else if (changedField === 'loan_rs') {
            loanRs = parseNum(ctx.loanRsInput?.value);
        } else {
            loanRs = fieldEmpty(ctx.loanRsInput) ? price * (defaults.loanPct / 100) : loanRs;
        }

        const amounts = enforceLoanRules(loanRs, price, defaults.capLoan);
        marginRs = amounts.margin;
        loanRs = amounts.loan;

        const finalMarginPct = clamp(pctFor(marginRs, price), 0, 100);
        const finalLoanPct = clamp(100 - finalMarginPct, 0, 100);

        setIfAllowed(ctx.marginRsInput, marginRs, { force: true });
        setIfAllowed(ctx.loanRsInput, loanRs, { force: true });
        setIfAllowed(ctx.marginPctInput, round2(finalMarginPct), { force: true });
        setIfAllowed(ctx.loanPctInput, round2(finalLoanPct), { force: true });
    };

    const applyScenarioFinanceDefaults = (ctx, changedField) => {
        const defaults = getScenarioDefaults(ctx.type);
        const mode = String(ctx.modeInput?.value || 'ratio') === 'manual' ? 'manual' : 'ratio';
        const isManualAmountChange = changedField === 'margin_rs' || changedField === 'loan_rs';

        if (!isExistingQuote()) {
            if (fieldEmpty(ctx.marginPctInput)) setIfAllowed(ctx.marginPctInput, defaults.marginPct, {});
            if (fieldEmpty(ctx.loanPctInput)) setIfAllowed(ctx.loanPctInput, defaults.loanPct, {});
            if (fieldEmpty(ctx.interestInput)) setIfAllowed(ctx.interestInput, defaults.interest, {});
            if (fieldEmpty(ctx.tenureInput)) setIfAllowed(ctx.tenureInput, defaults.tenure, { noDecimals: true });
        } else {
            if (fieldEmpty(ctx.interestInput)) setIfAllowed(ctx.interestInput, defaults.interest, {});
            if (fieldEmpty(ctx.tenureInput)) setIfAllowed(ctx.tenureInput, defaults.tenure, { noDecimals: true });
            if (changedField === 'init') return;
        }

        if (isManualAmountChange) {
            if (ctx.modeInput) ctx.modeInput.value = 'manual';
            syncScenarioByAmount(ctx, changedField);
            return;
        }
        if (changedField === 'margin_pct' || changedField === 'loan_pct') {
            syncScenarioByPercent(ctx, changedField);
            return;
        }
        if (mode === 'manual') {
            syncScenarioByAmount(ctx, changedField);
            return;
        }
        syncScenarioByPercent(ctx, changedField);
    };

    const up2ScenarioCtx = {
        type: 'up2',
        priceInput: priceUp2Input,
        marginPctInput: up2MarginPctInput,
        loanPctInput: up2LoanPctInput,
        marginRsInput: up2MarginRsInput,
        loanRsInput: up2LoanRsInput,
        interestInput: up2InterestInput,
        tenureInput: up2TenureInput,
        modeInput: up2ModeInput
    };
    const above2ScenarioCtx = {
        type: 'above2',
        priceInput: priceAbove2Input,
        marginPctInput: above2MarginPctInput,
        loanPctInput: above2LoanPctInput,
        marginRsInput: above2MarginRsInput,
        loanRsInput: above2LoanRsInput,
        interestInput: above2InterestInput,
        tenureInput: above2TenureInput,
        modeInput: above2ModeInput
    };

    const applyAllScenarioFinanceDefaults = (changedField) => {
        syncSelectedPrimarySystemPrice();
        applyScenarioFinanceDefaults(up2ScenarioCtx, changedField);
        applyScenarioFinanceDefaults(above2ScenarioCtx, changedField);
        syncLegacyLoanFields();
    };

    const syncScenarioApplicability = () => {
        const above2Price = parseNum(priceAbove2Input?.value);
        const isApplicable = (above2Price * LOAN_ABOVE_2_LACS_THRESHOLD_RATIO) > LOAN_ABOVE_2_LACS_THRESHOLD_RS;
        if (above2ApplicableInput) {
            above2ApplicableInput.disabled = !isApplicable;
            if (!isApplicable) above2ApplicableInput.checked = false;
        }
        const shouldDisableAbove2Inputs = !isApplicable || (above2ApplicableInput && !above2ApplicableInput.checked);
        [
            above2ModeInput, above2MarginPctInput, above2LoanPctInput, above2MarginRsInput, above2LoanRsInput,
            above2InterestInput, above2TenureInput
        ].filter(Boolean).forEach((el) => {
            el.disabled = shouldDisableAbove2Inputs;
        });
        if (!isApplicable && primaryScenarioInput && String(primaryScenarioInput.value || '').includes('loan_above_2_lacs')) {
            primaryScenarioInput.value = 'loan_upto_2_lacs_subsidy_to_loan';
            syncSelectedPrimarySystemPrice();
            syncLegacyLoanFields();
        }
        if (up2ApplicableInput) {
            up2ApplicableInput.checked = true;
        }
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
    bindRecalc(priceUp2Input, () => applyAllScenarioFinanceDefaults('price'));
    bindRecalc(priceAbove2Input, () => { applyAllScenarioFinanceDefaults('price'); syncScenarioApplicability(); });
    bindRecalc(priceSelfInput, syncLegacyLoanFields);
    bindRecalc(transportInput, () => applyAllScenarioFinanceDefaults('price'));
    bindRecalc(discountInput, () => applyAllScenarioFinanceDefaults('price'));

    [up2ModeInput, above2ModeInput, up2InterestInput, above2InterestInput, up2TenureInput, above2TenureInput].forEach((el) => {
        el?.addEventListener('change', () => applyAllScenarioFinanceDefaults('mode'));
        el?.addEventListener('input', syncLegacyLoanFields);
    });
    up2MarginPctInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(up2ScenarioCtx, 'margin_pct'); syncLegacyLoanFields(); });
    up2LoanPctInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(up2ScenarioCtx, 'loan_pct'); syncLegacyLoanFields(); });
    up2MarginRsInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(up2ScenarioCtx, 'margin_rs'); syncLegacyLoanFields(); });
    up2LoanRsInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(up2ScenarioCtx, 'loan_rs'); syncLegacyLoanFields(); });
    above2MarginPctInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(above2ScenarioCtx, 'margin_pct'); syncLegacyLoanFields(); });
    above2LoanPctInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(above2ScenarioCtx, 'loan_pct'); syncLegacyLoanFields(); });
    above2MarginRsInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(above2ScenarioCtx, 'margin_rs'); syncLegacyLoanFields(); });
    above2LoanRsInput?.addEventListener('input', () => { applyScenarioFinanceDefaults(above2ScenarioCtx, 'loan_rs'); syncLegacyLoanFields(); });
    above2ApplicableInput?.addEventListener('change', syncScenarioApplicability);

    templateSet?.addEventListener('change', () => {
        applyMonthlySuggestion(false);
        applySubsidyDefault(false);
        applyAllScenarioFinanceDefaults('template');
    });
    primaryScenarioInput?.addEventListener('change', () => {
        syncSelectedPrimarySystemPrice();
        syncLegacyLoanFields();
    });

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
    if (isExistingQuote()) {
        [
            unitRateInput, annualGenerationInput, subsidyInput,
            priceSelfInput, priceUp2Input, priceAbove2Input, primaryScenarioInput,
            up2ModeInput, up2MarginPctInput, up2LoanPctInput, up2MarginRsInput, up2LoanRsInput, up2InterestInput, up2TenureInput,
            above2ModeInput, above2MarginPctInput, above2LoanPctInput, above2MarginRsInput, above2LoanRsInput, above2InterestInput, above2TenureInput
        ].forEach(markExistingValueAsTouched);
    }

    applyMonthlySuggestion(false);
    applySubsidyDefault(false);
    syncSelectedPrimarySystemPrice();
    applyAllScenarioFinanceDefaults('init');
    syncScenarioApplicability();
    syncLegacyLoanFields();
})();
