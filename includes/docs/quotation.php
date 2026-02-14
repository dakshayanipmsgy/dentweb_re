<?php

declare(strict_types=1);

require_once __DIR__ . '/../handover.php';

function quotation_data_dir(): string
{
    $dir = __DIR__ . '/../../data/docs/quotations';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function quotation_docs_base_dir(): string
{
    return __DIR__ . '/../../data/docs';
}

function quotation_now_iso(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return date(DATE_ATOM);
    }
}

function quotation_load_json(string $path, array $fallback): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function quotation_save_json(string $path, array $payload): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function quotation_default_numbering(): array
{
    return [
        'financial_year_mode' => 'FY',
        'fy_format' => 'YY-YY',
        'doc_types' => [
            'quotation' => ['enabled' => true, 'prefix' => 'DE/QTN', 'use_segment' => true, 'digits' => 4],
        ],
    ];
}

function quotation_default_counters(): array
{
    return ['counters' => [], 'updated_at' => ''];
}

function quotation_current_fy(array $numbering, bool $withMode): string
{
    $month = (int) date('n');
    $year = (int) date('Y');
    $fyStart = $month >= 4 ? $year : $year - 1;
    $fyEnd = $fyStart + 1;

    $format = strtoupper((string) ($numbering['fy_format'] ?? 'YY-YY'));
    $startYY = substr((string) $fyStart, -2);
    $endYY = substr((string) $fyEnd, -2);

    $label = $startYY . '-' . $endYY;
    if ($format === 'YYYY-YY') {
        $label = $fyStart . '-' . $endYY;
    } elseif ($format === 'YYYY-YYYY') {
        $label = $fyStart . '-' . $fyEnd;
    }

    if (!$withMode) {
        return $label;
    }

    $mode = strtoupper(trim((string) ($numbering['financial_year_mode'] ?? 'FY')));
    return $mode !== '' ? $mode . $label : $label;
}

function quotation_counter_key(string $docType, string $fyLabel, string $segment): string
{
    return $docType . '|' . $fyLabel . '|' . $segment;
}

function next_doc_number(string $docType, string $segment): string
{
    $base = quotation_docs_base_dir();
    $numbering = quotation_load_json($base . '/numbering.json', quotation_default_numbering());
    $countersPath = $base . '/counters.json';
    $counters = quotation_load_json($countersPath, quotation_default_counters());

    $docConfig = $numbering['doc_types'][$docType] ?? [];
    if (!is_array($docConfig) || empty($docConfig['enabled'])) {
        throw new RuntimeException('Document type is disabled.');
    }

    $useSegment = !empty($docConfig['use_segment']);
    $segmentCode = strtoupper(trim($segment));
    if ($segmentCode === '') {
        $segmentCode = 'RES';
    }

    $fyWithMode = quotation_current_fy($numbering, true);
    $fyWithoutMode = quotation_current_fy($numbering, false);
    $key = quotation_counter_key($docType, $fyWithMode, $useSegment ? $segmentCode : '_');

    $current = (int) ($counters['counters'][$key] ?? 0);
    $next = $current + 1;
    $digits = max(2, min(6, (int) ($docConfig['digits'] ?? 4)));

    $prefix = trim((string) ($docConfig['prefix'] ?? strtoupper($docType)));
    $number = $prefix . '/' . ($useSegment ? $segmentCode . '/' : '') . $fyWithoutMode . '/' . str_pad((string) $next, $digits, '0', STR_PAD_LEFT);

    $counters['counters'][$key] = $next;
    $counters['updated_at'] = quotation_now_iso();
    if (!quotation_save_json($countersPath, $counters)) {
        throw new RuntimeException('Unable to update counters.');
    }

    return $number;
}

function quotation_default_record(): array
{
    return [
        'id' => '',
        'quote_number' => '',
        'doc_type' => 'quotation',
        'segment' => 'RES',
        'customer_type' => 'customer',
        'customer_id_or_mobile' => '',
        'customer_name' => '',
        'billing_address' => '',
        'shipping_address' => '',
        'gstin' => '',
        'place_of_supply_state_code' => '20',
        'system_type' => 'ongrid',
        'capacity_kwp' => 3,
        'template_set_id' => '',
        'pricing' => [
            'mode' => 'SPLIT_70_30',
            'final_gst_inclusive' => 0,
            'basic_total' => 0,
            'bucket_5' => ['basic' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0],
            'bucket_18' => ['basic' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0],
            'round_off' => 0,
            'grand_total' => 0,
        ],
        'valid_until' => '',
        'notes' => '',
        'created_by' => '',
        'status' => 'draft',
        'html_path' => '',
        'pdf_path' => '',
        'created_at' => '',
        'updated_at' => '',
    ];
}

function quotation_calculate_split_70_30(float $finalGstInclusive, string $stateCode = '20'): array
{
    $taxFactor = (0.70 * 1.05) + (0.30 * 1.18);
    $basicTotal = $taxFactor > 0 ? ($finalGstInclusive / $taxFactor) : 0.0;
    $basic70 = $basicTotal * 0.70;
    $basic30 = $basicTotal * 0.30;

    $gst5 = $basic70 * 0.05;
    $gst18 = $basic30 * 0.18;

    $isIntraState = trim($stateCode) === '20';
    $bucket5 = [
        'basic' => round($basic70, 2),
        'gst' => round($gst5, 2),
        'cgst' => $isIntraState ? round($gst5 / 2, 2) : 0.0,
        'sgst' => $isIntraState ? round($gst5 / 2, 2) : 0.0,
        'igst' => $isIntraState ? 0.0 : round($gst5, 2),
    ];

    $bucket18 = [
        'basic' => round($basic30, 2),
        'gst' => round($gst18, 2),
        'cgst' => $isIntraState ? round($gst18 / 2, 2) : 0.0,
        'sgst' => $isIntraState ? round($gst18 / 2, 2) : 0.0,
        'igst' => $isIntraState ? 0.0 : round($gst18, 2),
    ];

    $gross = $bucket5['basic'] + $bucket18['basic'] + $bucket5['gst'] + $bucket18['gst'];
    $grandTotal = round($finalGstInclusive, 0);

    return [
        'mode' => 'SPLIT_70_30',
        'final_gst_inclusive' => round($finalGstInclusive, 2),
        'basic_total' => round($bucket5['basic'] + $bucket18['basic'], 2),
        'bucket_5' => $bucket5,
        'bucket_18' => $bucket18,
        'round_off' => round($grandTotal - $gross, 2),
        'grand_total' => round($grandTotal, 2),
    ];
}

function quotation_load_template_sets(): array
{
    $path = quotation_docs_base_dir() . '/template_sets.json';
    $decoded = quotation_load_json($path, ['template_sets' => []]);
    $items = $decoded['template_sets'] ?? [];
    return is_array($items) ? $items : [];
}

function quotation_find_template_set(string $id): ?array
{
    foreach (quotation_load_template_sets() as $set) {
        if ((string) ($set['id'] ?? '') === $id) {
            return $set;
        }
    }

    return null;
}

function quotation_load_company_profile(): array
{
    $path = quotation_docs_base_dir() . '/company_profile.json';
    return quotation_load_json($path, []);
}

function quotation_generate_id(): string
{
    return 'qtn_' . bin2hex(random_bytes(5));
}

function quotation_save_record(array $quotation): bool
{
    $id = trim((string) ($quotation['id'] ?? ''));
    if ($id === '') {
        return false;
    }

    return quotation_save_json(quotation_data_dir() . '/' . $id . '.json', $quotation);
}

function quotation_create_revision_number(string $quoteNumber, int $nextRevision): string
{
    $base = preg_replace('/-R\d+$/', '', $quoteNumber) ?? $quoteNumber;
    return $base . '-R' . $nextRevision;
}

function quotation_html_output_path(string $id): string
{
    return quotation_data_dir() . '/' . $id . '.html';
}

function quotation_pdf_output_path(string $id): string
{
    return quotation_data_dir() . '/' . $id . '.pdf';
}

function quotation_generate_pdf_from_html(string $html, string $outputPath): bool
{
    if (function_exists('handover_generate_pdf')) {
        return handover_generate_pdf($html, $outputPath);
    }

    return false;
}
