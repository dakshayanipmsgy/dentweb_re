<?php
declare(strict_types=1);

function quotation_format_inr(float $amount, int $decimals = 0, bool $forceDecimals = false): string
{
    $hasFraction = abs($amount - round($amount)) > 0.009;
    $fractionDigits = $forceDecimals || ($decimals > 0 && $hasFraction) ? $decimals : 0;
    return '₹' . number_format($amount, $fractionDigits, '.', ',');
}

function quotation_format_inr_indian(float $amount, bool $showDecimals = false): string
{
    $rounded = $showDecimals ? $amount : round($amount);
    $parts = explode('.', number_format($rounded, $showDecimals ? 2 : 0, '.', ''));
    $int = $parts[0];
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return '₹' . $int . ($showDecimals && isset($parts[1]) ? '.' . $parts[1] : '');
}

function quotation_sanitize_html(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (strpos($raw, '<') === false) {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $bulletLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([\-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $bulletLines[] = '<li>' . htmlspecialchars($m[2], ENT_QUOTES) . '</li>';
            }
        }
        if ($bulletLines !== []) {
            return '<ul>' . implode('', $bulletLines) . '</ul>';
        }
        return nl2br(htmlspecialchars($raw, ENT_QUOTES));
    }

    $allow = '<p><br><b><strong><i><em><u><ul><ol><li><table><thead><tbody><tr><th><td><hr><h1><h2><h3><h4><blockquote><small><span><div>';
    $clean = strip_tags($raw, $allow);
    $clean = preg_replace('#<(script|iframe|style)[^>]*>.*?</\1>#is', '', (string) $clean) ?? '';
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/\s+(style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    return $clean;
}

function quotation_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) < 10) {
        return '';
    }
    if (strlen($digits) > 12) {
        $digits = substr($digits, -12);
    }
    return $digits;
}

function quotation_shadow_css(string $preset): string
{
    $map = [
        'none' => 'none',
        'soft' => '0 8px 18px rgba(15, 23, 42, 0.06)',
        'medium' => '0 10px 24px rgba(15, 23, 42, 0.10)',
        'strong' => '0 14px 30px rgba(15, 23, 42, 0.16)',
    ];
    return $map[$preset] ?? $map['soft'];
}

function quotation_render(array $quote, array $quoteDefaults, array $company, bool $showAdmin = false, string $shareUrl = '', string $viewerType = 'admin', string $viewerId = ''): void
{
    ini_set('display_errors', '0');
    ob_start();

    $segment = (string) ($quote['segment'] ?? 'RES');
    $segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
    $transport = (float) ($quote['calc']['transportation_rs'] ?? $quote['finance_inputs']['transportation_rs'] ?? 0);
    $subsidy = (float) ($quote['calc']['subsidy_expected_rs'] ?? $quote['finance_inputs']['subsidy_expected_rs'] ?? 0);
    $calc = is_array($quote['calc'] ?? null) && !empty($quote['calc'])
        ? (array) $quote['calc']
        : documents_calc_quote_pricing_with_tax_profile($quote, $transport, $subsidy, (float) ($quote['input_total_gst_inclusive'] ?? 0), $quoteDefaults);
    $ann = is_array($quote['annexures_overrides'] ?? null) ? $quote['annexures_overrides'] : [];
    $templateSetId = safe_text((string) ($quote['template_set_id'] ?? ''));
    if ($templateSetId !== '') {
        $templateBlocks = documents_get_template_blocks();
        $templateAnnex = documents_quote_annexure_from_template($templateBlocks, $templateSetId);
        foreach ($templateAnnex as $annKey => $annValue) {
            if (trim((string) ($ann[$annKey] ?? '')) === '' && trim((string) $annValue) !== '') {
                $ann[$annKey] = (string) $annValue;
            }
        }
    }

    $quoteItems = documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : []);
    $itemRows = [];
    foreach ($quoteItems as $quoteItem) {
        if (!is_array($quoteItem)) {
            continue;
        }
        $lineType = (string) ($quoteItem['type'] ?? 'component');
        $qty = (float) ($quoteItem['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ($lineType === 'kit') {
            $kit = documents_inventory_get_kit((string) ($quoteItem['kit_id'] ?? ''));
            $kitName = safe_text((string) ($quoteItem['name_snapshot'] ?? ''));
            if ($kitName === '') {
                $kitName = safe_text((string) ($kit['name'] ?? 'Kit'));
            }
            $description = safe_text((string) ($quoteItem['description_snapshot'] ?? ''));
            if ($description === '') {
                $description = safe_text((string) ($kit['description'] ?? ''));
            }
            $itemRows[] = [
                'name' => ($kitName !== '' ? $kitName : 'Kit'),
                'description' => $description,
                'custom_description' => safe_text((string) ($quoteItem['custom_description'] ?? '')),
                'hsn' => safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541'),
                'qty' => $qty,
                'unit' => safe_text((string) ($quoteItem['unit'] ?? '')) ?: 'set',
            ];
            continue;
        }

        $component = documents_inventory_get_component((string) ($quoteItem['component_id'] ?? ''));
        $variantSnapshot = is_array($quoteItem['variant_snapshot'] ?? null) ? $quoteItem['variant_snapshot'] : [];
        $name = safe_text((string) ($quoteItem['name_snapshot'] ?? ''));
        if ($name === '') {
            $name = safe_text((string) ($component['name'] ?? 'Component'));
            $variantName = safe_text((string) ($variantSnapshot['display_name'] ?? ''));
            if ($variantName !== '') {
                $name .= ' (' . $variantName . ')';
            }
        }
        $description = safe_text((string) ($quoteItem['description_snapshot'] ?? ''));
        if ($description === '') {
            $description = safe_text((string) ($component['description'] ?? ''));
            if ($description === '') {
                $description = safe_text((string) ($component['notes'] ?? ''));
            }
        }
        $itemRows[] = [
            'name' => $name,
            'description' => $description,
            'hsn' => safe_text((string) ($quoteItem['hsn_snapshot'] ?? '')) ?: (safe_text((string) ($component['hsn'] ?? '')) ?: (safe_text((string) ($quoteDefaults['defaults']['hsn_solar'] ?? '8541')) ?: '8541')),
            'qty' => $qty,
            'unit' => safe_text((string) ($quoteItem['unit'] ?? '')) ?: safe_text((string) ($component['default_unit'] ?? '')),
        ];
    }

    $coverNote = trim((string) ($quote['cover_note_text'] ?? '')) ?: trim((string) ($quoteDefaults['defaults']['cover_note_template'] ?? ''));
    $specialReq = trim((string) ($quote['special_requests_text'] ?? $quote['special_requests_inclusive'] ?? ''));

    $tokens = is_array($quoteDefaults['global']['ui_tokens'] ?? null) ? $quoteDefaults['global']['ui_tokens'] : [];
    $colors = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];
    $grads = is_array($tokens['gradients'] ?? null) ? $tokens['gradients'] : [];
    $headerFooter = is_array($tokens['header_footer'] ?? null) ? $tokens['header_footer'] : [];
    $typo = is_array($tokens['typography'] ?? null) ? $tokens['typography'] : [];

    $showDecimals = !empty($quoteDefaults['global']['quotation_ui']['show_decimals']);

    $loanDefaults = is_array($segmentDefaults['loan_bestcase'] ?? null) ? $segmentDefaults['loan_bestcase'] : [];
    $loanOverrides = is_array($quote['finance_inputs']['loan'] ?? null) ? $quote['finance_inputs']['loan'] : [];
    $loanInterest = (float) ($loanOverrides['interest_pct'] ?? $loanDefaults['interest_pct'] ?? $quoteDefaults['segments']['RES']['loan_bestcase']['interest_pct'] ?? 6.0);
    $loanTenureYears = (float) ($loanOverrides['tenure_years'] ?? $loanDefaults['tenure_years'] ?? $quoteDefaults['segments']['RES']['loan_bestcase']['tenure_years'] ?? 10);
    $loanTenureMonths = max(1, (int) round($loanTenureYears * 12));

    $annualGeneration = (float) ($quote['finance_inputs']['annual_generation_per_kw'] ?? $segmentDefaults['annual_generation_per_kw'] ?? $quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450);
    $unitRate = (float) ($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?? $segmentDefaults['unit_rate_rs_per_kwh'] ?? $quoteDefaults['segments']['RES']['unit_rate_rs_per_kwh'] ?? 8);
    $emissionFactor = (float) ($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82);
    $treeAbsorption = (float) ($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20);

    $rawSubsidyInput = $quote['finance_inputs']['subsidy_expected_rs'] ?? $quote['calc']['subsidy_expected_rs'] ?? null;
    $subsidyProvided = !( $rawSubsidyInput === null || (is_string($rawSubsidyInput) && trim($rawSubsidyInput) === ''));

    $companyName = (string) ($company['brand_name'] ?: ($company['company_name'] ?? 'Dakshayani Enterprises'));
    $website = trim((string) ($company['website'] ?? ''));
    $phonePrimary = trim((string) ($company['phone_primary'] ?? ''));
    $whatsappRaw = trim((string) ($company['whatsapp_number'] ?? ''));
    $whatsappDisplay = $whatsappRaw !== '' ? $whatsappRaw : trim((string) ($company['phone_secondary'] ?? ''));
    $phoneBits = [];
    if ($phonePrimary !== '') {
        $phoneBits[] = '📞 ' . htmlspecialchars($phonePrimary, ENT_QUOTES);
    }
    if ($whatsappDisplay !== '' && $whatsappDisplay !== $phonePrimary) {
        $phoneBits[] = '💬 WhatsApp ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES);
    }
    $whatsappDigits = quotation_normalize_whatsapp($whatsappDisplay);
    $waLink = $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '';
    $logoSrc = trim((string) ($company['logo_path'] ?? ''));
    $hasLogo = $logoSrc !== '';

    $bankFields = [
        ['icon' => '🏦', 'label' => 'Bank name', 'value' => trim((string) ($company['bank_name'] ?? ''))],
        ['icon' => '👤', 'label' => 'A/c name', 'value' => trim((string) ($company['bank_account_name'] ?? ''))],
        ['icon' => '🔢', 'label' => 'A/c no', 'value' => trim((string) ($company['bank_account_no'] ?? ''))],
        ['icon' => '✅', 'label' => 'IFSC', 'value' => trim((string) ($company['bank_ifsc'] ?? ''))],
        ['icon' => '📍', 'label' => 'Branch', 'value' => trim((string) ($company['bank_branch'] ?? ''))],
        ['icon' => '💳', 'label' => 'UPI ID', 'value' => trim((string) ($company['upi_id'] ?? ''))],
    ];
    $bankFields = array_values(array_filter($bankFields, static function (array $row): bool {
        return $row['value'] !== '';
    }));

    $whyPoints = $quoteDefaults['global']['quotation_ui']['why_dakshayani_points'] ?? ['Local Jharkhand EPC team'];
    if (!is_array($whyPoints) || $whyPoints === []) {
        $whyPoints = ['Local Jharkhand EPC team'];
    }

    $headerGrad = is_array($grads['header'] ?? null) ? $grads['header'] : [];
    $footerGrad = is_array($grads['footer'] ?? null) ? $grads['footer'] : [];
    $headerBg = !empty($headerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($headerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($headerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($headerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['header_bg'] ?? '#0f766e');
    $footerBg = !empty($footerGrad['enabled'])
        ? 'linear-gradient(' . ((string) ($footerGrad['direction'] ?? 'to right')) . ', ' . ((string) ($footerGrad['a'] ?? '#0ea5e9')) . ', ' . ((string) ($footerGrad['b'] ?? '#22c55e')) . ')'
        : (string) ($quoteDefaults['global']['branding']['footer_bg'] ?? '#0f172a');

    $headerText = (string) ($headerFooter['header_text_color'] ?? '#ffffff');
    $footerText = (string) ($headerFooter['footer_text_color'] ?? '#ffffff');
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation</title>
<style>
:root{--color-primary:<?= htmlspecialchars((string)($colors['primary'] ?? '#0ea5e9'), ENT_QUOTES) ?>;--color-accent:<?= htmlspecialchars((string)($colors['accent'] ?? '#22c55e'), ENT_QUOTES) ?>;--color-text:<?= htmlspecialchars((string)($colors['text'] ?? '#0f172a'), ENT_QUOTES) ?>;--color-muted:<?= htmlspecialchars((string)($colors['muted_text'] ?? '#475569'), ENT_QUOTES) ?>;--page-bg:<?= htmlspecialchars((string)($colors['page_bg'] ?? '#f8fafc'), ENT_QUOTES) ?>;--card-bg:<?= htmlspecialchars((string)($colors['card_bg'] ?? '#ffffff'), ENT_QUOTES) ?>;--border-color:<?= htmlspecialchars((string)($colors['border'] ?? '#e2e8f0'), ENT_QUOTES) ?>;--base-font-size:<?= (int)($typo['base_px'] ?? 14) ?>px;--h1-size:<?= (int)($typo['h1_px'] ?? 24) ?>px;--h2-size:<?= (int)($typo['h2_px'] ?? 18) ?>px;--h3-size:<?= (int)($typo['h3_px'] ?? 16) ?>px;--line-height:<?= (float)($typo['line_height'] ?? 1.6) ?>;--shadow-preset:<?= quotation_shadow_css((string)($tokens['shadow'] ?? 'soft')) ?>;--header-bg:<?= htmlspecialchars($headerBg, ENT_QUOTES) ?>;--footer-bg:<?= htmlspecialchars($footerBg, ENT_QUOTES) ?>;--header-text-color:<?= htmlspecialchars($headerText, ENT_QUOTES) ?>;--footer-text-color:<?= htmlspecialchars($footerText, ENT_QUOTES) ?>}
body{font-family:Inter,Arial,sans-serif;background:var(--page-bg);margin:0;color:var(--color-text);font-size:var(--base-font-size);line-height:var(--line-height)}
h1{font-size:var(--h1-size)}h2{font-size:var(--h2-size)}h3{font-size:var(--h3-size)}.wrap{max-width:1100px;margin:0 auto;padding:12px}.card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:14px;margin-bottom:12px;box-shadow:var(--shadow-preset)}.h{font-weight:700}.sec{border-bottom:2px solid var(--color-primary);padding-bottom:5px;margin-bottom:8px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.bank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.metric{background:var(--page-bg);border:1px solid var(--border-color);border-radius:10px;padding:8px}.hero{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.hero .metric b{display:block;font-size:1.2rem;margin-top:4px}.save-line{margin-top:10px;padding:10px 12px;border:1px solid #86efac;background:#f0fdf4;color:#166534;border-radius:10px;font-weight:700}.chip{background:#ccfbf1;color:#134e4a;border-radius:99px;padding:5px 10px;display:inline-block;margin:3px 6px 0 0}.muted{color:var(--color-muted)}.item-master-description{color:var(--color-muted);font-size:.88em;line-height:1.35;margin-top:2px;white-space:pre-wrap;word-break:break-word}.item-custom-description{color:#1e293b;font-size:.9em;line-height:1.4;margin-top:4px;font-weight:600;white-space:pre-wrap;word-break:break-word}table{width:100%;border-collapse:collapse}th,td{border:1px solid var(--border-color);padding:8px;text-align:left}th{background:#f8fafc}.right{text-align:right}.center{text-align:center}.footer{background:var(--footer-bg);color:var(--footer-text-color)}.footer a,.footer a:visited{color:var(--footer-text-color)}.footer-brand-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}.footer-logo{display:inline-flex;align-items:center;justify-content:center}.footer-logo img{max-height:38px;width:auto;display:block}.footer-brand-name{color:var(--footer-text-color);font-size:1.1em;font-weight:700;line-height:1.3}.header{background:var(--header-bg);color:var(--header-text-color)}.header a,.header a:visited{color:var(--header-text-color)}.header-top{display:flex;align-items:center;gap:12px}.header-logo{background:rgba(255,255,255,.18);padding:4px 8px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}.header-logo img{max-height:50px;width:auto;display:block}.header-main{min-width:0}.screen-only{display:block}.hide-print{display:block}.chart-block{margin-bottom:12px;break-inside:avoid;page-break-inside:avoid}.chart-title{font-weight:700;margin:2px 0 8px}.chart-legend{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}.legend-item{display:flex;align-items:center;gap:6px;font-size:.92rem}.legend-swatch{width:12px;height:12px;border-radius:3px;display:inline-block}.bar-chart,.bar-chart *{box-sizing:border-box}.bar-chart{position:relative;overflow:hidden;display:flex;align-items:flex-end;justify-content:space-around;gap:10px;min-height:220px;padding:10px 12px 14px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg)}.bar-wrap{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:8px;flex:1;min-width:0}.bar-area{height:160px;width:100%;padding:8px 8px 0;display:flex;align-items:flex-end;justify-content:center;overflow:hidden}.bar{width:min(52px,100%);max-height:100%;border-radius:8px 8px 4px 4px;min-height:2px}.bar-label{font-size:.82rem;text-align:center;line-height:1.35;width:100%;overflow-wrap:anywhere}.axis-label{font-size:.82rem;color:var(--color-muted);text-align:center;margin-top:6px}.line-chart svg{width:100%;height:220px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg)}.chart-print-img{display:none;width:100%;height:auto;max-width:100%;border:1px solid var(--border-color);border-radius:10px;background:#fff}.payback-meter{width:100%;height:16px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:8px}.payback-meter-fill{height:100%;background:linear-gradient(to right,var(--color-primary),var(--color-accent));width:0}.scenario-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}@media (max-width:700px){.header-top{flex-direction:column;align-items:flex-start}.footer-brand-row{align-items:flex-start}.scenario-grid{grid-template-columns:1fr}.bank-grid{grid-template-columns:1fr}}@media print{.hide-print,.screen-only{display:none!important}.card{break-inside:avoid;box-shadow:none}.chart-block,.bar-chart,.line-chart{overflow:visible!important;height:auto!important}.bar-chart,.line-chart svg{display:none!important}.chart-print-img{display:block!important}.item-master-description,.item-custom-description{white-space:pre-wrap;word-break:break-word;line-height:1.35}}</style>
</head><body><div id="quotation-root" class="wrap">
<section class="card header"><div class="header-top"><?php if ($hasLogo): ?><div class="header-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo" /></div><?php endif; ?><div class="header-main"><div class="h"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><div><?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars((string)($company['city'] ?? ''), ENT_QUOTES) ?></div><div><?= implode(' | ', $phoneBits) ?><?= $phoneBits !== [] ? ' | ' : '' ?>✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?><?= $waLink !== '' ? ' · <a href="' . htmlspecialchars($waLink, ENT_QUOTES) . '">Chat</a>' : '' ?></div><div>GSTIN <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>Quote No <b><?= htmlspecialchars((string)($quote['quote_no'] ?? ''), ENT_QUOTES) ?></b></div></div></div></section>
<section class="card"><span class="chip">✅ MNRE compliant</span><span class="chip"><?= $segment === 'RES' ? '✅ PM Surya Ghar eligible' : 'ℹ️ Segment specific policy' ?></span><span class="chip">🔌 Net metering supported</span><span class="chip">🛡️ 25+ year life / warranty</span></section>
<section class="card"><div class="h sec">🏠 Customer &amp; Site 📍</div><div class="grid2"><div class="metric"><b>Customer</b><div><?= htmlspecialchars((string)($quote['customer_name'] ?? ''), ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars((string)($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></div></div><div class="metric"><b>Site</b><div><?= htmlspecialchars((string)($quote['site_address'] ?? ''), ENT_QUOTES) ?></div><div class="muted"><?= htmlspecialchars((string)($quote['district'] ?? ''), ENT_QUOTES) ?></div></div></div></section>
<?php if ($coverNote !== ''): ?><section class="card"><div><?= quotation_sanitize_html($coverNote) ?></div></section><?php endif; ?>
<section class="card"><div class="h sec">⚡ At a glance</div><div class="hero"><div class="metric">System Size<b><?= htmlspecialchars((string)($quote['capacity_kwp'] ?? '0'), ENT_QUOTES) ?> kWp</b></div><div class="metric">Monthly Bill (Without Solar)<b><?= quotation_format_inr_indian((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0), $showDecimals) ?></b></div><div class="metric">Monthly Outflow (With Solar – Bank Finance)<b id="heroOutflowBank">-</b></div><div class="metric">Monthly Outflow (With Solar – Self Funded)<b id="heroOutflowSelf">-</b></div></div><div class="save-line">🟢 You save approx <span id="heroSaving">-</span> every month</div></section>
<section class="card"><div class="h sec">📦 Item summary</div><table><thead><tr><th>Sr No</th><th>Item and Description</th><th>HSN</th><th class="center">Quantity</th><th class="center">Unit</th></tr></thead><tbody><?php if ($itemRows === []): ?><tr><td colspan="5" class="center muted">No line items added.</td></tr><?php else: foreach ($itemRows as $idx => $item): ?><tr><td><?= (int)$idx + 1 ?></td><td><div><?= htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES) ?></div><?php $itemDesc=(string)($item['description'] ?? ''); if (trim($itemDesc) !== ''): ?><div class="item-master-description"><?= htmlspecialchars($itemDesc, ENT_QUOTES) ?></div><?php endif; ?><?php $customDesc=(string)($item['custom_description'] ?? ''); if (trim($customDesc) !== ''): ?><div class="item-custom-description">📝 <?= htmlspecialchars($customDesc, ENT_QUOTES) ?></div><?php endif; ?></td><td><?= htmlspecialchars((string)($item['hsn'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['qty'] ?? ''), ENT_QUOTES) ?></td><td class="center"><?= htmlspecialchars((string)($item['unit'] ?? ''), ENT_QUOTES) ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
<?php if($specialReq!==''): ?><section class="card"><div class="h sec">✍️ Special Requests From Consumer (Inclusive in the rate)</div><div><?= quotation_sanitize_html($specialReq) ?></div><div><i>In case of conflict between annexures and special requests, special requests will be prioritized.</i></div></section><?php endif; ?>
<section class="card"><div class="h sec">💰 Pricing summary</div><table><thead><tr><th>#</th><th>Particular</th><th class="right">Amount</th></tr></thead><tbody><tr><td>1</td><td>Gross payable</td><td class="right" id="upfront"></td></tr><tr><td>2</td><td>Subsidy expected</td><td class="right"><?= quotation_format_inr_indian((float)($calc['subsidy_expected_rs'] ?? 0), $showDecimals) ?></td></tr><tr><td>3</td><td><b>Net Investment/Cost After Subsidy Credit</b></td><td class="right"><b id="upfrontNet"></b></td></tr></tbody></table></section>
<section class="card"><div class="h sec">📊 Charts &amp; graphics</div>
<div class="chart-block">
<div class="chart-title">Monthly Outflow Comparison</div>
<div id="monthlyOutflowChart" class="bar-chart"></div><img id="monthlyOutflowChartPrint" class="chart-print-img" alt="Monthly outflow comparison chart for print">
<div class="axis-label">Scenario</div>
<div class="axis-label">Monthly Outflow (₹)</div>
<div id="monthlyOutflowLegend" class="chart-legend"></div>
</div>
<div class="chart-block">
<div class="chart-title">Cumulative Expense Over 25 Years</div>
<div id="cumulativeLegend" class="chart-legend"></div>
<div class="line-chart"><svg id="cumulativeExpenseChart" viewBox="0 0 920 220" preserveAspectRatio="none"></svg><img id="cumulativeExpenseChartPrint" class="chart-print-img" alt="Cumulative expense chart for print"></div>
<div class="axis-label">Years</div>
<div class="axis-label">Cumulative Expense (₹)</div>
</div>
<div class="chart-block">
<div class="chart-title">Payback Meter</div>
<div class="metric">Estimated payback<b id="payback">-</b><div class="payback-meter"><div class="payback-meter-fill" id="paybackMeterFill"></div></div></div>
</div>
</section>
<section class="card"><div class="h sec">🏦 Finance clarity</div><div class="scenario-grid"><div class="metric"><b>With Loan</b><div style="margin-top:6px">Margin<b id="margin">-</b></div><div>Loan eligible<b id="loan">-</b></div><div>Effective principal<b id="loanEff">-</b></div><div>EMI<b id="emi">-</b></div><div>Residual bill<b id="residual">-</b></div><div>Total outflow<b id="outflow">-</b></div></div><div class="metric"><b>Self Funded</b><div style="margin-top:6px">Upfront investment<b id="upfrontFinance">-</b></div><div>Investment minus subsidy<b id="upfrontNetFinance">-</b></div><div>Residual bill<b id="selfResidual">-</b></div></div></div></section>
<section class="card"><div class="h sec">🔆 Generation estimate</div><table><tbody><tr><th>Expected monthly generation (units)</th><td class="right" id="genMonthly">-</td></tr><tr><th>Expected annual generation (units)</th><td class="right" id="genAnnual">-</td></tr><tr><th>Estimated payback period (years)</th><td class="right" id="genPayback">-</td></tr><tr><th>Units produced in 25 years (units)</th><td class="right" id="gen25">-</td></tr></tbody></table></section>
<section class="card"><div class="h sec">🌱 Your Green Impact</div><div class="grid4"><div class="metric">CO₂/year<b id="co2y">-</b></div><div class="metric">Trees/year<b id="treey">-</b></div><div class="metric">CO₂ over 25 years<b id="co225">-</b></div><div class="metric">Trees over 25 years<b id="tree25">-</b></div></div></section>
<section class="card"><div class="h sec">⭐ Why <?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><ul><?php foreach ($whyPoints as $point): ?><li><?= htmlspecialchars((string)$point, ENT_QUOTES) ?></li><?php endforeach; ?></ul></section>
<section class="card"><div class="h sec">📑 Annexures</div><?php foreach(['warranty'=>'Warranty','system_inclusions'=>'System inclusions','pm_subsidy_info'=>'PM subsidy info','completion_milestones'=>'Completion milestones','payment_terms'=>'Payment terms','system_type_explainer'=>'System Type explainer (ongrid vs hybrid vs offgrid)','transportation'=>'Transportation','terms_conditions'=>'Terms and conditions'] as $k=>$label): ?><?php $annVal = trim((string)($ann[$k] ?? '')); if ($annVal === '') { continue; } ?><div class="metric"><div class="h"><?= htmlspecialchars($label, ENT_QUOTES) ?></div><div><?= quotation_sanitize_html($annVal) ?></div></div><?php endforeach; ?></section>
<?php $nextStepsHtml = trim((string)($ann['next_steps'] ?? '')); if ($nextStepsHtml !== ''): ?><section class="card"><div class="h sec">🚀 Next steps</div><div><?= quotation_sanitize_html($nextStepsHtml) ?></div></section><?php endif; ?>
<?php if ($bankFields !== []): ?>
<section class="card"><div class="h sec">Bank Details</div><div class="bank-grid"><?php foreach ($bankFields as $bankField): ?><div class="metric"><div class="h"><?= htmlspecialchars((string) ($bankField['icon'] . ' ' . $bankField['label']), ENT_QUOTES) ?></div><div><?= htmlspecialchars((string) $bankField['value'], ENT_QUOTES) ?></div></div><?php endforeach; ?></div></section>
<?php endif; ?>
<section class="card footer"><div class="footer-brand-row"><?php if ($hasLogo): ?><div class="footer-logo"><img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES) ?> logo" /></div><?php endif; ?><div class="footer-brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div></div><div>Registered office: <?= htmlspecialchars((string)($company['address_line'] ?? ''), ENT_QUOTES) ?></div><div>📞 <?= htmlspecialchars((string)($company['phone_primary'] ?? ''), ENT_QUOTES) ?><?= $whatsappDisplay !== '' ? ' · 💬 ' . htmlspecialchars($whatsappDisplay, ENT_QUOTES) : '' ?> · ✉️ <?= htmlspecialchars((string)($company['email_primary'] ?? ''), ENT_QUOTES) ?> · 🌐 <?= htmlspecialchars($website, ENT_QUOTES) ?></div><div>GSTIN: <?= htmlspecialchars((string)($company['gstin'] ?? ''), ENT_QUOTES) ?> · UDYAM: <?= htmlspecialchars((string)($company['udyam'] ?? ''), ENT_QUOTES) ?> · PAN: <?= htmlspecialchars((string)($company['pan'] ?? ''), ENT_QUOTES) ?></div><div>JREDA: <?= htmlspecialchars((string)($company['jreda_license'] ?? ''), ENT_QUOTES) ?> · DWSD: <?= htmlspecialchars((string)($company['dwsd_license'] ?? ''), ENT_QUOTES) ?></div><div><small>🧾 <?= htmlspecialchars((string)($quoteDefaults['global']['quotation_ui']['footer_disclaimer'] ?? 'Values are indicative and subject to site conditions, DISCOM approvals, and policy updates.'), ENT_QUOTES) ?></small></div></section>
</div>
<script>
const q={gross:<?= json_encode((float)$calc['gross_payable']) ?>,subsidy:<?= json_encode((float)$calc['subsidy_expected_rs']) ?>,subsidyProvided:<?= json_encode($subsidyProvided) ?>,monthly:<?= json_encode((float)($quote['finance_inputs']['monthly_bill_rs'] ?? 0)) ?>,unit:<?= json_encode($unitRate) ?>,cap:<?= json_encode((float)($quote['capacity_kwp'] ?? 0)) ?>,gen:<?= json_encode($annualGeneration) ?>};
const showDecimals=<?= json_encode($showDecimals) ?>;
const r=x=>'₹'+Number(x).toLocaleString('en-IN',{minimumFractionDigits:showDecimals?2:0,maximumFractionDigits:showDecimals?2:0});
const nUnits=x=>Number(x).toLocaleString('en-US',{maximumFractionDigits:0});
const rr=(<?= json_encode($loanInterest) ?>)/100/12,n=<?= json_encode($loanTenureMonths) ?>;
const minMargin=q.gross*0.10,loan=Math.max(0,q.gross-minMargin),margin=q.gross-loan,loanEff=Math.max(0,loan-q.subsidy),emi=loanEff>0?(loanEff*rr*Math.pow(1+rr,n))/((Math.pow(1+rr,n))-1):0,mUnits=q.monthly/Math.max(0.1,q.unit),solar=(q.cap*q.gen)/12,res=Math.max(0,mUnits-solar)*q.unit,out=emi+res;
['margin','loan','loanEff','emi','residual','outflow','selfResidual'].forEach((id)=>{const map={margin,loan,loanEff,emi,residual:res,outflow:out,selfResidual:res};const el=document.getElementById(id);if(el)el.textContent=r(map[id]);});
const upfrontNet=q.gross-q.subsidy;
const financeMap={upfront:q.gross,upfrontNet,upfrontFinance:q.gross,upfrontNetFinance:upfrontNet};
Object.keys(financeMap).forEach((id)=>{const el=document.getElementById(id);if(el)el.textContent=r(financeMap[id]);});
const heroSaving=Math.max(0,q.monthly-res);document.getElementById('heroOutflowBank').textContent=r(out);document.getElementById('heroOutflowSelf').textContent=r(res);document.getElementById('heroSaving').textContent=r(heroSaving);
const invested=q.subsidyProvided?Math.max(0,upfrontNet):q.gross;
const annualValue=q.cap*q.gen*q.unit;
let paybackYears=NaN;
const paybackEl=document.getElementById('payback');
if(!Number.isFinite(annualValue)||annualValue<=0){paybackEl.textContent='—';}else{paybackYears=invested/annualValue;paybackEl.textContent='~'+(Number.isFinite(paybackYears)?paybackYears.toFixed(1):'—')+' years';}
const genPaybackEl=document.getElementById('genPayback');if(genPaybackEl){genPaybackEl.textContent=Number.isFinite(paybackYears)?paybackYears.toFixed(1):'—';}
const paybackFill=document.getElementById('paybackMeterFill');if(paybackFill){const pct=Number.isFinite(paybackYears)?Math.max(0,Math.min(100,(paybackYears/25)*100)):0;paybackFill.style.width=pct.toFixed(1)+'%';}

const monthlySeries=[
  {label:'No Solar',value:Math.max(0,q.monthly),color:'#ef4444'},
  {label:'With Solar (Loan)',value:Math.max(0,out),color:'#0ea5e9'},
  {label:'With Solar (Self funded)',value:Math.max(0,res),color:'#22c55e'}
];
const monthlyChart=document.getElementById('monthlyOutflowChart');
const monthlyLegend=document.getElementById('monthlyOutflowLegend');
if(monthlyChart&&monthlyLegend){
  const maxVal=Math.max(1,...monthlySeries.map((x)=>x.value));
  monthlyChart.innerHTML=monthlySeries.map((item)=>{const h=Math.max(2,(item.value/maxVal)*100);return `<div class="bar-wrap"><div class="bar-area"><div class="bar" style="background:${item.color};height:${h}%"></div></div><div class="bar-label">${item.label}<br>${r(item.value)}</div></div>`;}).join('');
  monthlyLegend.innerHTML=monthlySeries.map((item)=>`<span class="legend-item"><span class="legend-swatch" style="background:${item.color}"></span>${item.label}</span>`).join('');
}

const cumSeries=[
  {label:'No Solar',color:'#ef4444',points:[]},
  {label:'With Solar (Loan)',color:'#0ea5e9',points:[]},
  {label:'With Solar (Self funded)',color:'#22c55e',points:[]}
];
for(let y=0;y<=25;y+=1){const m=y*12;cumSeries[0].points.push({x:y,y:m*q.monthly});cumSeries[1].points.push({x:y,y:m*out});cumSeries[2].points.push({x:y,y:q.gross+(m*res)});}
const svg=document.getElementById('cumulativeExpenseChart');
const cumLegend=document.getElementById('cumulativeLegend');
if(svg&&cumLegend){
  const w=920,h=220,p=26;
  const allY=cumSeries.flatMap((s)=>s.points.map((pt)=>pt.y));
  const maxY=Math.max(1,...allY);
  const xToPx=(x)=>p+((w-(2*p))*x/25);
  const yToPx=(y)=>h-p-((h-(2*p))*y/maxY);
  let lines=`<line x1="${p}" y1="${h-p}" x2="${w-p}" y2="${h-p}" stroke="#94a3b8" stroke-width="1"/><line x1="${p}" y1="${p}" x2="${p}" y2="${h-p}" stroke="#94a3b8" stroke-width="1"/>`;
  for(let yr=0;yr<=25;yr+=5){const x=xToPx(yr);lines+=`<text x="${x}" y="${h-6}" text-anchor="middle" font-size="10" fill="#64748b">${yr}</text>`;}
  [0,0.25,0.5,0.75,1].forEach((tick)=>{const yVal=maxY*tick;const yy=yToPx(yVal);lines+=`<line x1="${p}" y1="${yy}" x2="${w-p}" y2="${yy}" stroke="#e2e8f0" stroke-width="1"/>`;});
  cumSeries.forEach((series)=>{const d=series.points.map((pt,i)=>`${i===0?'M':'L'} ${xToPx(pt.x)} ${yToPx(pt.y)}`).join(' ');lines+=`<path d="${d}" fill="none" stroke="${series.color}" stroke-width="2.5"/>`;});
  svg.innerHTML=lines;
  cumLegend.innerHTML=cumSeries.map((item)=>`<span class="legend-item"><span class="legend-swatch" style="background:${item.color}"></span>${item.label}</span>`).join('');
}


const buildChartPrintImages=()=>{
  const monthlyPrintImg=document.getElementById('monthlyOutflowChartPrint');
  if(monthlyPrintImg){
    const canvas=document.createElement('canvas');
    canvas.width=920;
    canvas.height=320;
    const ctx=canvas.getContext('2d');
    if(ctx){
      ctx.fillStyle='#ffffff';
      ctx.fillRect(0,0,canvas.width,canvas.height);
      const maxVal=Math.max(1,...monthlySeries.map((x)=>x.value));
      const baseY=245;
      const chartTop=36;
      const slotW=canvas.width/monthlySeries.length;
      const barW=72;
      ctx.strokeStyle='#94a3b8';
      ctx.lineWidth=1;
      ctx.beginPath();
      ctx.moveTo(32,baseY);
      ctx.lineTo(canvas.width-32,baseY);
      ctx.stroke();
      monthlySeries.forEach((item,index)=>{
        const h=Math.max(2,((item.value/maxVal)*(baseY-chartTop)));
        const x=(index*slotW)+(slotW/2)-(barW/2);
        const y=baseY-h;
        ctx.fillStyle=item.color;
        ctx.fillRect(x,y,barW,h);
        ctx.fillStyle='#0f172a';
        ctx.font='14px Arial';
        ctx.textAlign='center';
        ctx.fillText(item.label,(index*slotW)+(slotW/2),275);
        ctx.fillText(r(item.value),(index*slotW)+(slotW/2),296);
      });
      monthlyPrintImg.src=canvas.toDataURL('image/png');
    }
  }

  const cumulativeSvg=document.getElementById('cumulativeExpenseChart');
  const cumulativePrintImg=document.getElementById('cumulativeExpenseChartPrint');
  if(cumulativeSvg&&cumulativePrintImg){
    const svgData=new XMLSerializer().serializeToString(cumulativeSvg);
    const encoded='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svgData);
    cumulativePrintImg.src=encoded;
  }
};
if(document.readyState==='complete'){
  buildChartPrintImages();
}else{
  window.addEventListener('load',buildChartPrintImages,{once:true});
}
window.addEventListener('beforeprint',buildChartPrintImages);

const yearly=q.cap*q.gen,co2=yearly*<?= json_encode($emissionFactor) ?>,tree=co2/Math.max(0.1,<?= json_encode($treeAbsorption) ?>);
document.getElementById('co2y').textContent=co2.toFixed(0)+' kg';document.getElementById('treey').textContent=tree.toFixed(1);document.getElementById('co225').textContent=(co2*25).toFixed(0)+' kg';document.getElementById('tree25').textContent=(tree*25).toFixed(1);
document.getElementById('genMonthly').textContent=nUnits(solar);document.getElementById('genAnnual').textContent=nUnits(yearly);const gen25El=document.getElementById('gen25');if(gen25El){gen25El.textContent=nUnits(yearly*25);}
</script>
</body></html>
<?php
    $output = ob_get_clean();
    echo ltrim((string) $output);
}
