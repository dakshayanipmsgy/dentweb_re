<?php
declare(strict_types=1);

/** Opt in on an explicit editor save; merely opening old records never upgrades them. */
function quotation_workflow_enabled(array $quote): bool
{
    return (int) ($quote['workflow_version'] ?? 0) >= 2;
}

function quotation_workflow_primary(string $key): string
{
    return str_replace('_subsidy_not_to_loan', '_subsidy_to_loan', $key);
}

function quotation_workflow_loan(float $price, bool $above = false): array
{
    $price = round(max(0, $price), 2);
    $loan = round($above ? $price * .8 : min($price * .9, 200000), 2);
    return ['loan_amount_rs' => $loan, 'margin_money_rs' => round($price - $loan, 2),
        'loan_ratio_pct' => $price > 0 ? $loan / $price * 100 : 0,
        'margin_ratio_pct' => $price > 0 ? ($price - $loan) / $price * 100 : 0,
        'tenure_years' => 10, 'tenure_months' => 120];
}

/** Normalize a linked editable split; retain the requested ratio across cap/price changes. */
function quotation_workflow_finance(float $price, bool $above, string $mode, float $requestedPct, float $requestedLoan): array
{
    $price = round(max(0, is_finite($price) ? $price : 0), 2);
    $mode = $mode === 'manual' ? 'manual' : 'ratio';
    $requestedPct = max(0, min(100, is_finite($requestedPct) ? $requestedPct : ($above ? 80 : 90)));
    $loan = $mode === 'manual' ? (is_finite($requestedLoan) ? $requestedLoan : 0) : $price * $requestedPct / 100;
    $loan = round(max(0, min($price, $above ? $price : 200000, $loan)), 2);
    $margin = round($price - $loan, 2);
    return ['finance_mode'=>$mode, 'finance_override'=>true, 'requested_loan_ratio_pct'=>$requestedPct,
        'loan_amount_rs'=>$loan, 'margin_money_rs'=>$margin,
        'loan_ratio_pct'=>$price > 0 ? $loan / $price * 100 : 0,
        'margin_ratio_pct'=>$price > 0 ? $margin / $price * 100 : 0,
        'tenure_years'=>10, 'tenure_months'=>120];
}

/** Integer paise arithmetic keeps the entered breakdown and payable total identical. */
function quotation_workflow_breakdown(array $request, float $selfFundedPrice): ?array
{
    if (!array_key_exists('breakdown_base_price', $request)) return null;
    $raw = trim((string) $request['breakdown_base_price']);
    if ($raw === '') return null;
    if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0) throw new InvalidArgumentException('Enter a valid system price.');
    $base = (int) round((float) $raw * 100);
    $rows = []; $total = $base;
    foreach ((array) ($request['breakdown_label'] ?? []) as $i => $label) {
        $label = trim(strip_tags((string) $label));
        $amount = trim((string) ($request['breakdown_amount'][$i] ?? ''));
        if ($label === '' && ($amount === '' || (float) $amount === 0.0)) continue;
        if ($label === '' || strlen($label) > 150 || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) throw new InvalidArgumentException('Each additional charge needs a name and a valid non-negative amount.');
        $paise = (int) round((float) $amount * 100);
        $rows[] = ['label' => $label, 'amount' => $paise / 100]; $total += $paise;
        if (count($rows) > 30) throw new InvalidArgumentException('Use at most 30 additional charges.');
    }
    if (abs($total - (int) round($selfFundedPrice * 100)) > 1) throw new InvalidArgumentException('System price plus additional charges must equal the self-funded price. Review the price breakdown.');
    return ['base_system_price' => $base / 100, 'additions' => $rows, 'total' => $total / 100];
}

function quotation_workflow_amount(string $raw): string
{
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', trim($raw)) || (float) $raw <= 0 || (float) $raw > 10000000) throw new InvalidArgumentException('Enter an amount between Rs. 0.01 and Rs. 1,00,00,000, with at most two decimal places.');
    return number_format((float) $raw, 2, '.', '');
}

function quotation_workflow_upi(string $raw, string $reference): string
{
    return 'upi://pay?' . http_build_query(['pa' => 'd.entranchi@ybl', 'pn' => 'Dakshayani Enterprises',
        'am' => quotation_workflow_amount($raw), 'cu' => 'INR', 'tn' => substr('Quotation ' . $reference, 0, 80)], '', '&', PHP_QUERY_RFC3986);
}

/** Small formatting allowlist. In particular, strip pasted event/style attributes. */
function quotation_workflow_clean_html(string $html): string
{
    $html = preg_replace('~<(script|style|iframe|object)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th><a>');
    return preg_replace_callback('~<([a-z]+)\b([^>]*)>~i', static function (array $m): string {
        $tag = strtolower($m[1]);
        if ($tag === 'a' && preg_match('~href\s*=\s*([\x22\x27])(.*?)\1~is', $m[2], $href)) {
            $url = html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~^(https?://|mailto:)~i', $url)) return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">';
        }
        return '<' . $tag . '>';
    }, $html) ?? '';
}

function quotation_workflow_apply_annexures(array $quote, array $request, array $defaults): array
{
    if (!isset($request['ann_mode']) || !is_array($request['ann_mode'])) return $quote;
    foreach ($request['ann_mode'] as $key => $mode) {
        if (!array_key_exists($key, $defaults)) continue;
        $mode = in_array($mode, ['inherit', 'custom', 'hide'], true) ? $mode : 'inherit';
        $quote['annexure_modes'][$key] = $mode;
        $quote['annexures_overrides'][$key] = $mode === 'hide' ? '' : quotation_workflow_clean_html((string) ($mode === 'inherit' ? $defaults[$key] : ($request['ann_' . $key] ?? '')));
    }
    $quote['cover_notes_html_snapshot'] = (string) ($quote['annexures_overrides']['cover_notes'] ?? '');
    return $quote;
}

/** Resolve quotation-owned follow-up, falling back to the linked lead's existing CRM fields. */
function quotation_workflow_followup(array $quote): array
{
    $lead = [];
    $leadId = (string) ($quote['source']['lead_id'] ?? '');
    if ($leadId !== '' && function_exists('load_all_leads')) {
        static $leadsById = null;
        if ($leadsById === null) {
            $leadsById = [];
            foreach (load_all_leads() as $entry) $leadsById[(string) ($entry['id'] ?? '')] = $entry;
        }
        $lead = $leadsById[$leadId] ?? [];
    }
    return array_merge(['assigned_to_name'=>(string)($lead['assigned_to_name']??''),
        'next_followup_date'=>(string)($lead['next_followup_date']??''),
        'outcome'=>(string)($lead['status']??'')], (array)($quote['sales_followup']??[]));
}

function quotation_workflow_filter_quotes(array $quotes, string $search = '', string $owner = '', string $due = ''): array
{
    $search = strtolower(trim($search)); $owner = strtolower(trim($owner)); $today=date('Y-m-d');
    return array_values(array_filter($quotes, static function (array $q) use ($search,$owner,$due,$today): bool {
        $sales=quotation_workflow_followup($q);
        $haystack=strtolower(implode(' ',[(string)($q['quote_no']??''),(string)($q['customer_name']??''),(string)($q['customer_mobile']??'')]));
        if($search!==''&&!str_contains($haystack,$search))return false;
        if($owner!==''&&!str_contains(strtolower((string)$sales['assigned_to_name']),$owner))return false;
        $date=(string)$sales['next_followup_date'];
        if($due==='today'&&$date!==$today)return false;
        if($due==='overdue'&&($date===''||$date>=$today))return false;
        return true;
    }));
}
