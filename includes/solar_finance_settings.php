<?php
declare(strict_types=1);

function solar_finance_settings_storage_path(): string
{
    $dir = __DIR__ . '/../storage/site';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/solar_finance_settings.json';
}

function solar_finance_settings_default(): array
{
    return [
        'defaults' => [
            'unit_rate' => 8,
            'daily_generation_per_kw' => 5,
            'interest_rate' => 6,
            'tenure_years' => 10,
            'roof_area_sqft_per_kw' => 100,
            'co2_emission_factor' => 0.82,
            'co2_per_tree' => 20,
            'default_margin_above_2_lakh' => 50000,
        ],
        'explainer' => [
            'hero_title' => 'Solar and Finance',
            'hero_subtitle' => 'Understand your solar requirement, compare finance options, and see what solar adoption may look like for your home or business.',
            'faq' => [
                ['q' => 'Can I start with just my monthly bill?', 'a' => 'Yes. Enter either monthly bill or monthly units, and we auto-fill the other value.'],
                ['q' => 'Is subsidy guaranteed?', 'a' => 'Subsidy depends on government policy and eligibility checks. Treat this page as a planning estimate.'],
                ['q' => 'Should I choose on-grid or hybrid?', 'a' => 'On-grid is usually lower cost. Hybrid is useful when backup during outages matters.'],
            ],
        ],
        'on_grid_prices' => [
            ['size_kwp' => 1, 'loan_upto_2_lakh' => null, 'loan_above_2_lakh' => null],
            ['size_kwp' => 2, 'loan_upto_2_lakh' => 136900, 'loan_above_2_lakh' => 136900],
            ['size_kwp' => 3, 'loan_upto_2_lakh' => 183900, 'loan_above_2_lakh' => 183900],
            ['size_kwp' => 4, 'loan_upto_2_lakh' => 248900, 'loan_above_2_lakh' => 248900],
            ['size_kwp' => 5, 'loan_upto_2_lakh' => 272900, 'loan_above_2_lakh' => 292900],
            ['size_kwp' => 6, 'loan_upto_2_lakh' => 341900, 'loan_above_2_lakh' => 371900],
            ['size_kwp' => 7, 'loan_upto_2_lakh' => 382900, 'loan_above_2_lakh' => 422900],
            ['size_kwp' => 8, 'loan_upto_2_lakh' => 424900, 'loan_above_2_lakh' => 474900],
            ['size_kwp' => 9, 'loan_upto_2_lakh' => 454900, 'loan_above_2_lakh' => 514900],
            ['size_kwp' => 10, 'loan_upto_2_lakh' => 483900, 'loan_above_2_lakh' => 553900],
        ],
        'hybrid_prices' => [
            ['model' => '2S-3I-B2-S', 'size_kwp' => 2, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'loan_upto_2_lakh' => 216900, 'loan_above_2_lakh' => 216900],
            ['model' => '3S-3I-B2-S', 'size_kwp' => 3, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'loan_upto_2_lakh' => 264900, 'loan_above_2_lakh' => 264900],
            ['model' => '2S-3I-B4-S', 'size_kwp' => 2, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lakh' => 241900, 'loan_above_2_lakh' => 241900],
            ['model' => '3S-3I-B4-S', 'size_kwp' => 3, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lakh' => 289900, 'loan_above_2_lakh' => 289900],
            ['model' => '3S-5I-B4-S', 'size_kwp' => 3, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lakh' => 308900, 'loan_above_2_lakh' => 308900],
            ['model' => '4S-5I-B4-S', 'size_kwp' => 4, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lakh' => 367900, 'loan_above_2_lakh' => 377900],
            ['model' => '5S-5I-B4-S', 'size_kwp' => 5, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lakh' => 388900, 'loan_above_2_lakh' => 408900],
            ['model' => '4S-8I-B8-S', 'size_kwp' => 4, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 472900, 'loan_above_2_lakh' => 482900],
            ['model' => '5S-8I-B8-S', 'size_kwp' => 5, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 486900, 'loan_above_2_lakh' => 506900],
            ['model' => '6S-8I-B8-S', 'size_kwp' => 6, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 555900, 'loan_above_2_lakh' => 585900],
            ['model' => '7S-8I-B8-S', 'size_kwp' => 7, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 598900, 'loan_above_2_lakh' => 638900],
            ['model' => '6S-10I-B10-S', 'size_kwp' => 6, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 619900, 'loan_above_2_lakh' => 649900],
            ['model' => '7S-10I-B10-S', 'size_kwp' => 7, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 652900, 'loan_above_2_lakh' => 692900],
            ['model' => '8S-10I-B10-S', 'size_kwp' => 8, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 667900, 'loan_above_2_lakh' => 717900],
            ['model' => '9S-10I-B10-S', 'size_kwp' => 9, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 730900, 'loan_above_2_lakh' => 790900],
            ['model' => '10S-10I-B10-S', 'size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 747900, 'loan_above_2_lakh' => 817900],
            ['model' => '10S-10I-B15-S', 'size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 15, 'loan_upto_2_lakh' => 864900, 'loan_above_2_lakh' => 934900],
            ['model' => '10S-15I-B20-S', 'size_kwp' => 10, 'inverter_kva' => 15.0, 'phase' => '1 Phase', 'battery_count' => 20, 'loan_upto_2_lakh' => 981900, 'loan_above_2_lakh' => 1051900],
            ['model' => '5S-8I-B8-T', 'size_kwp' => 5, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 551900, 'loan_above_2_lakh' => 571900],
            ['model' => '6S-8I-B8-T', 'size_kwp' => 6, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 625900, 'loan_above_2_lakh' => 655900],
            ['model' => '7S-8I-B8-T', 'size_kwp' => 7, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lakh' => 666900, 'loan_above_2_lakh' => 706900],
            ['model' => '6S-10I-B10-T', 'size_kwp' => 6, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 714900, 'loan_above_2_lakh' => 744900],
            ['model' => '7S-10I-B10-T', 'size_kwp' => 7, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 755900, 'loan_above_2_lakh' => 795900],
            ['model' => '8S-10I-B10-T', 'size_kwp' => 8, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 756900, 'loan_above_2_lakh' => 806900],
            ['model' => '9S-10I-B10-T', 'size_kwp' => 9, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 833900, 'loan_above_2_lakh' => 893900],
            ['model' => '10S-10I-B10-T', 'size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lakh' => 836900, 'loan_above_2_lakh' => 906900],
            ['model' => '10S-15I-B15-T', 'size_kwp' => 10, 'inverter_kva' => 15.0, 'phase' => '3 Phase', 'battery_count' => 15, 'loan_upto_2_lakh' => 956900, 'loan_above_2_lakh' => 1026900],
        ],
    ];
}

function solar_finance_settings_normalize(array $data): array
{
    $defaults = solar_finance_settings_default();
    $normalized = array_replace_recursive($defaults, $data);

    foreach (['unit_rate', 'daily_generation_per_kw', 'interest_rate', 'tenure_years', 'roof_area_sqft_per_kw', 'co2_emission_factor', 'co2_per_tree', 'default_margin_above_2_lakh'] as $key) {
        $normalized['defaults'][$key] = (float) ($normalized['defaults'][$key] ?? $defaults['defaults'][$key]);
    }

    $normalized['on_grid_prices'] = array_values(array_filter(array_map(static function ($row): ?array {
        if (!is_array($row)) {
            return null;
        }
        $size = (float) ($row['size_kwp'] ?? 0);
        if ($size <= 0) {
            return null;
        }
        return [
            'size_kwp' => $size,
            'loan_upto_2_lakh' => ($row['loan_upto_2_lakh'] === null || $row['loan_upto_2_lakh'] === '') ? null : (float) $row['loan_upto_2_lakh'],
            'loan_above_2_lakh' => ($row['loan_above_2_lakh'] === null || $row['loan_above_2_lakh'] === '') ? null : (float) $row['loan_above_2_lakh'],
        ];
    }, (array) ($normalized['on_grid_prices'] ?? []))));

    $normalized['hybrid_prices'] = array_values(array_filter(array_map(static function ($row): ?array {
        if (!is_array($row)) {
            return null;
        }
        $size = (float) ($row['size_kwp'] ?? 0);
        if ($size <= 0) {
            return null;
        }
        return [
            'model' => trim((string) ($row['model'] ?? '')),
            'size_kwp' => $size,
            'inverter_kva' => (float) ($row['inverter_kva'] ?? 0),
            'phase' => trim((string) ($row['phase'] ?? '1 Phase')),
            'battery_count' => (int) ($row['battery_count'] ?? 0),
            'loan_upto_2_lakh' => ($row['loan_upto_2_lakh'] === null || $row['loan_upto_2_lakh'] === '') ? null : (float) $row['loan_upto_2_lakh'],
            'loan_above_2_lakh' => ($row['loan_above_2_lakh'] === null || $row['loan_above_2_lakh'] === '') ? null : (float) $row['loan_above_2_lakh'],
        ];
    }, (array) ($normalized['hybrid_prices'] ?? []))));

    return $normalized;
}

function solar_finance_settings(): array
{
    $cacheKey = 'solar_finance_settings_cache';
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $path = solar_finance_settings_storage_path();
    $defaults = solar_finance_settings_default();
    if (!is_file($path)) {
        solar_finance_settings_save($defaults);
        $GLOBALS[$cacheKey] = solar_finance_settings_normalize($defaults);
        return $GLOBALS[$cacheKey];
    }

    $raw = file_get_contents($path);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = $defaults;
    }

    $GLOBALS[$cacheKey] = solar_finance_settings_normalize($decoded);

    return $GLOBALS[$cacheKey];
}

function solar_finance_settings_save(array $data): void
{
    $path = solar_finance_settings_storage_path();
    $normalized = solar_finance_settings_normalize($data);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode solar and finance settings.');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save solar and finance settings.');
    }

    $GLOBALS['solar_finance_settings_cache'] = null;
}
