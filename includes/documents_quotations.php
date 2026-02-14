<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function documents_quotations_dir(): string
{
    return documents_base_dir() . '/quotations';
}

function documents_template_blocks_path(): string
{
    return documents_templates_dir() . '/template_blocks.json';
}

function documents_seed_template_blocks_if_missing(): void
{
    $path = documents_template_blocks_path();
    if (is_file($path)) {
        return;
    }

    $starter = [
        'pm-surya-ghar-residential-ongrid-subsidy' => [
            'system_inclusions' => "- Solar PV modules, inverter, structure, wiring and balance-of-system materials.\n- Net-metering ready integration and basic commissioning support.\n- Standard installation with safety accessories as applicable.",
            'payment_terms' => "- 70% advance with order confirmation.\n- Balance payable before final handover and commissioning.\n- Subsidy portion (if applicable) as per government policy timeline.",
            'warranty' => "- Module performance and product warranty as per OEM policy.\n- Inverter warranty as per OEM policy.\n- Installation workmanship support as per company standard terms.",
            'system_type_explainer' => "On-grid solar system works with utility grid and is designed to reduce monthly electricity bills through net metering where available.",
            'transportation' => "Transportation and unloading within standard service area is included unless explicitly stated otherwise.",
            'terms_conditions' => "- Civil/electrical modifications outside standard scope are extra.\n- Permissions, approvals, and subsidy disbursement are subject to authority process.\n- Any scope changes after finalization may impact timelines and pricing.",
            'pm_surya_ghar_info' => "Eligible residential consumers may receive PM Surya Ghar subsidy as per latest MNRE/State DISCOM guidelines and approved capacity slabs.",
        ],
    ];

    json_save($path, $starter);
}

function documents_quote_default_tax_type(string $placeOfSupplyState, string $companyState): string
{
    return strtolower(trim($placeOfSupplyState)) === strtolower(trim($companyState)) ? 'CGST_SGST' : 'IGST';
}

function documents_round2(float $value): float
{
    return round($value, 2);
}

function calc_solar_split_from_total(float $grandTotal, string $taxType): array
{
    $grandTotal = max(0, $grandTotal);
    $basicTotalRaw = $grandTotal / 1.089;
    $bucket5BasicRaw = $basicTotalRaw * 0.70;
    $bucket18BasicRaw = $basicTotalRaw * 0.30;

    $bucket5GstRaw = $bucket5BasicRaw * 0.05;
    $bucket18GstRaw = $bucket18BasicRaw * 0.18;

    $calc = [
        'basic_total' => documents_round2($basicTotalRaw),
        'bucket_5_basic' => documents_round2($bucket5BasicRaw),
        'bucket_5_gst' => documents_round2($bucket5GstRaw),
        'bucket_18_basic' => documents_round2($bucket18BasicRaw),
        'bucket_18_gst' => documents_round2($bucket18GstRaw),
        'gst_split' => [
            'cgst_5' => 0.0,
            'sgst_5' => 0.0,
            'cgst_18' => 0.0,
            'sgst_18' => 0.0,
            'igst_5' => 0.0,
            'igst_18' => 0.0,
        ],
        'grand_total' => documents_round2($grandTotal),
    ];

    if ($taxType === 'IGST') {
        $calc['gst_split']['igst_5'] = $calc['bucket_5_gst'];
        $calc['gst_split']['igst_18'] = $calc['bucket_18_gst'];
    } else {
        $calc['gst_split']['cgst_5'] = documents_round2($calc['bucket_5_gst'] / 2);
        $calc['gst_split']['sgst_5'] = documents_round2($calc['bucket_5_gst'] / 2);
        $calc['gst_split']['cgst_18'] = documents_round2($calc['bucket_18_gst'] / 2);
        $calc['gst_split']['sgst_18'] = documents_round2($calc['bucket_18_gst'] / 2);
    }

    return $calc;
}

function calc_flat_5_from_total(float $grandTotal, string $taxType): array
{
    $grandTotal = max(0, $grandTotal);
    $basicRaw = $grandTotal / 1.05;
    $gstRaw = $basicRaw * 0.05;

    $calc = [
        'basic_total' => documents_round2($basicRaw),
        'bucket_5_basic' => documents_round2($basicRaw),
        'bucket_5_gst' => documents_round2($gstRaw),
        'bucket_18_basic' => 0.0,
        'bucket_18_gst' => 0.0,
        'gst_split' => [
            'cgst_5' => 0.0,
            'sgst_5' => 0.0,
            'cgst_18' => 0.0,
            'sgst_18' => 0.0,
            'igst_5' => 0.0,
            'igst_18' => 0.0,
        ],
        'grand_total' => documents_round2($grandTotal),
    ];

    if ($taxType === 'IGST') {
        $calc['gst_split']['igst_5'] = $calc['bucket_5_gst'];
    } else {
        $calc['gst_split']['cgst_5'] = documents_round2($calc['bucket_5_gst'] / 2);
        $calc['gst_split']['sgst_5'] = documents_round2($calc['bucket_5_gst'] / 2);
    }

    return $calc;
}

function documents_calculate_quote(string $pricingMode, float $totalInclusive, string $taxType): array
{
    if ($pricingMode === 'flat_5') {
        return calc_flat_5_from_total($totalInclusive, $taxType);
    }

    return calc_solar_split_from_total($totalInclusive, $taxType);
}

function documents_load_template_sets(): array
{
    $templates = json_load(documents_templates_dir() . '/template_sets.json', []);
    return is_array($templates) ? $templates : [];
}

function documents_load_template_blocks(): array
{
    $blocks = json_load(documents_template_blocks_path(), []);
    return is_array($blocks) ? $blocks : [];
}

function documents_next_quote_number(string $segment): array
{
    $path = documents_settings_dir() . '/numbering_rules.json';
    $numbering = json_load($path, documents_numbering_defaults());
    $numbering = array_merge(documents_numbering_defaults(), is_array($numbering) ? $numbering : []);
    $rules = is_array($numbering['rules']) ? $numbering['rules'] : [];

    $selectedIndex = null;
    foreach ($rules as $index => $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if (($rule['doc_type'] ?? '') !== 'quotation' || ($rule['segment'] ?? '') !== $segment) {
            continue;
        }
        if (!empty($rule['archived_flag']) || empty($rule['active'])) {
            continue;
        }
        $selectedIndex = $index;
        break;
    }

    if ($selectedIndex === null) {
        return ['ok' => false, 'error' => 'No active numbering rule found for quotation (' . $segment . ').'];
    }

    $rule = $rules[$selectedIndex];
    $seqCurrent = max(1, (int) ($rule['seq_current'] ?? 1));
    $seqDigits = max(2, min(6, (int) ($rule['seq_digits'] ?? 4)));
    $prefix = safe_text($rule['prefix'] ?? 'DE/QTN');
    $fy = current_fy_string((int) ($numbering['fy_start_month'] ?? 4));
    $format = safe_text($rule['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');

    $quoteNo = strtr($format, [
        '{{prefix}}' => $prefix,
        '{{segment}}' => $segment,
        '{{fy}}' => $fy,
        '{{seq}}' => str_pad((string) $seqCurrent, $seqDigits, '0', STR_PAD_LEFT),
    ]);

    $rules[$selectedIndex]['seq_current'] = $seqCurrent + 1;
    $numbering['rules'] = $rules;
    $numbering['updated_at'] = date('c');

    $saved = json_save($path, $numbering);
    if (!$saved['ok']) {
        return ['ok' => false, 'error' => 'Unable to update numbering counter.'];
    }

    return ['ok' => true, 'quote_no' => $quoteNo];
}

function documents_quote_file_path(string $id): string
{
    return documents_quotations_dir() . '/' . $id . '.json';
}

function documents_load_quote(string $id): ?array
{
    $id = safe_text($id);
    if ($id === '') {
        return null;
    }

    $quote = json_load(documents_quote_file_path($id), null);
    return is_array($quote) ? $quote : null;
}

function documents_save_quote(array $quote): array
{
    $id = safe_text((string) ($quote['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'Missing quote id'];
    }

    return json_save(documents_quote_file_path($id), $quote);
}

function documents_list_quotes(): array
{
    documents_ensure_dir(documents_quotations_dir());
    $files = glob(documents_quotations_dir() . '/qtn_*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        $row = json_load((string) $file, null);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $rows;
}
