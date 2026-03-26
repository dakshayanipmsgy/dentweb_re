<?php
declare(strict_types=1);

function solar_finance_settings_path(): string
{
    $dir = __DIR__ . '/../storage/site';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/solar_finance_settings.json';
}

function solar_finance_settings_defaults(): array
{
    return [
        'defaults' => [
            'unit_rate' => 8,
            'daily_generation_per_kw' => 5,
            'interest_rate' => 6,
            'loan_tenure_years' => 10,
            'roof_area_sqft_per_kw' => 100,
            'emission_factor_kg_per_kwh' => 0.82,
            'co2_per_tree_kg' => 21,
            'loan_above_2_lakh_default_margin_money' => 80000,
        ],
        'explainer' => [
            'cards' => [
                ['title' => 'What is solar rooftop?', 'icon' => 'fa-solar-panel', 'body' => 'Solar rooftop means panels on your roof that generate electricity in daytime. This reduces your monthly power bill.'],
                ['title' => 'PM Surya Ghar: Muft Bijli Yojana', 'icon' => 'fa-indian-rupee-sign', 'body' => 'Eligible homes can get central subsidy support for rooftop solar. Subsidy lowers your effective investment.'],
                ['title' => 'On-grid system', 'icon' => 'fa-plug-circle-bolt', 'body' => 'Best for lower upfront cost and maximum bill savings when power cuts are manageable.'],
                ['title' => 'Hybrid system', 'icon' => 'fa-battery-half', 'body' => 'Best when backup is important. Hybrid combines solar with batteries for improved power continuity.'],
                ['title' => 'Who should choose what?', 'icon' => 'fa-compass-drafting', 'body' => 'On-grid for savings-first users. Hybrid for homes/offices where backup and reliability are critical.'],
                ['title' => 'Benefits', 'icon' => 'fa-leaf', 'body' => 'Lower electricity bills, protection from tariff hikes, cleaner energy, and long-term value for your property.'],
                ['title' => 'Important expectations', 'icon' => 'fa-circle-info', 'body' => 'Solar performance changes with season, shade, and roof condition. Proper design and maintenance matter.'],
                ['title' => 'FAQ', 'icon' => 'fa-circle-question', 'body' => 'Typical payback is often within a few years depending on usage, tariff, subsidy, and system type.'],
            ],
        ],
        'on_grid_prices' => [
            ['solar_kwp' => 2, 'loan_upto_2_lac' => 136900, 'loan_above_2_lac' => 136900],
            ['solar_kwp' => 3, 'loan_upto_2_lac' => 183900, 'loan_above_2_lac' => 183900],
            ['solar_kwp' => 4, 'loan_upto_2_lac' => 248900, 'loan_above_2_lac' => 248900],
            ['solar_kwp' => 5, 'loan_upto_2_lac' => 272900, 'loan_above_2_lac' => 292900],
            ['solar_kwp' => 6, 'loan_upto_2_lac' => 341900, 'loan_above_2_lac' => 371900],
            ['solar_kwp' => 7, 'loan_upto_2_lac' => 382900, 'loan_above_2_lac' => 422900],
            ['solar_kwp' => 8, 'loan_upto_2_lac' => 424900, 'loan_above_2_lac' => 474900],
            ['solar_kwp' => 9, 'loan_upto_2_lac' => 454900, 'loan_above_2_lac' => 514900],
            ['solar_kwp' => 10, 'loan_upto_2_lac' => 483900, 'loan_above_2_lac' => 553900],
        ],
        'hybrid_prices' => [
            ['solar_kwp' => 2, 'kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'loan_upto_2_lac' => 216900, 'loan_above_2_lac' => 216900],
            ['solar_kwp' => 3, 'kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'loan_upto_2_lac' => 264900, 'loan_above_2_lac' => 264900],
            ['solar_kwp' => 2, 'kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lac' => 241900, 'loan_above_2_lac' => 241900],
            ['solar_kwp' => 3, 'kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lac' => 289900, 'loan_above_2_lac' => 289900],
            ['solar_kwp' => 3, 'kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lac' => 308900, 'loan_above_2_lac' => 308900],
            ['solar_kwp' => 4, 'kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lac' => 367900, 'loan_above_2_lac' => 377900],
            ['solar_kwp' => 5, 'kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'loan_upto_2_lac' => 388900, 'loan_above_2_lac' => 408900],
            ['solar_kwp' => 4, 'kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 472900, 'loan_above_2_lac' => 482900],
            ['solar_kwp' => 5, 'kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 486900, 'loan_above_2_lac' => 506900],
            ['solar_kwp' => 6, 'kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 555900, 'loan_above_2_lac' => 585900],
            ['solar_kwp' => 7, 'kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 598900, 'loan_above_2_lac' => 638900],
            ['solar_kwp' => 6, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 619900, 'loan_above_2_lac' => 649900],
            ['solar_kwp' => 7, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 652900, 'loan_above_2_lac' => 692900],
            ['solar_kwp' => 8, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 667900, 'loan_above_2_lac' => 717900],
            ['solar_kwp' => 9, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 730900, 'loan_above_2_lac' => 790900],
            ['solar_kwp' => 10, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 747900, 'loan_above_2_lac' => 817900],
            ['solar_kwp' => 10, 'kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 15, 'loan_upto_2_lac' => 864900, 'loan_above_2_lac' => 934900],
            ['solar_kwp' => 10, 'kva' => 15.0, 'phase' => '1 Phase', 'battery_count' => 20, 'loan_upto_2_lac' => 981900, 'loan_above_2_lac' => 1051900],
            ['solar_kwp' => 5, 'kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 551900, 'loan_above_2_lac' => 571900],
            ['solar_kwp' => 6, 'kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 625900, 'loan_above_2_lac' => 655900],
            ['solar_kwp' => 7, 'kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'loan_upto_2_lac' => 666900, 'loan_above_2_lac' => 706900],
            ['solar_kwp' => 6, 'kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 714900, 'loan_above_2_lac' => 744900],
            ['solar_kwp' => 7, 'kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 755900, 'loan_above_2_lac' => 795900],
            ['solar_kwp' => 8, 'kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 756900, 'loan_above_2_lac' => 806900],
            ['solar_kwp' => 9, 'kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 833900, 'loan_above_2_lac' => 893900],
            ['solar_kwp' => 10, 'kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'loan_upto_2_lac' => 836900, 'loan_above_2_lac' => 906900],
            ['solar_kwp' => 10, 'kva' => 15.0, 'phase' => '3 Phase', 'battery_count' => 15, 'loan_upto_2_lac' => 956900, 'loan_above_2_lac' => 1026900],
        ],
    ];
}

function solar_finance_settings_normalize(array $data): array
{
    $defaults = solar_finance_settings_defaults();
    $merged = array_replace_recursive($defaults, $data);
    return $merged;
}

function solar_finance_settings(): array
{
    if (array_key_exists('solar_finance_settings_cache', $GLOBALS) && is_array($GLOBALS['solar_finance_settings_cache'])) {
        return $GLOBALS['solar_finance_settings_cache'];
    }

    $path = solar_finance_settings_path();
    if (!is_file($path)) {
        $seed = solar_finance_settings_defaults();
        @file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $GLOBALS['solar_finance_settings_cache'] = $seed;
        return $seed;
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw ?: '', true);
    if (!is_array($decoded)) {
        $decoded = solar_finance_settings_defaults();
    }

    $normalized = solar_finance_settings_normalize($decoded);
    $GLOBALS['solar_finance_settings_cache'] = $normalized;
    return $normalized;
}

function solar_finance_settings_save(array $data): void
{
    $path = solar_finance_settings_path();
    $normalized = solar_finance_settings_normalize($data);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode solar finance settings.');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save solar finance settings.');
    }

    $GLOBALS['solar_finance_settings_cache'] = null;
}
