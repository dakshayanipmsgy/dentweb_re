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

function solar_finance_settings_default(): array
{
    return [
        'defaults' => [
            'unit_rate' => 8,
            'daily_generation_per_kw' => 5,
            'interest_rate' => 6,
            'loan_tenure_years' => 10,
            'roof_area_sqft_per_kw' => 100,
            'emission_factor_kg_per_kwh' => 0.82,
            'co2_per_tree_kg_per_year' => 21,
        ],
        'explainer_blocks' => [
            ['title' => 'What is rooftop solar?', 'body' => 'Rooftop solar means solar panels on your roof that generate electricity in daytime and reduce your bill.', 'icon' => 'fa-solar-panel'],
            ['title' => 'PM Surya Ghar: Muft Bijli Yojana', 'body' => 'A Government-backed rooftop program that supports eligible households with subsidy support.', 'icon' => 'fa-landmark'],
            ['title' => 'On-grid system', 'body' => 'Connected to the electricity grid. Ideal when you want lower cost and bill savings.', 'icon' => 'fa-plug-circle-bolt'],
            ['title' => 'Hybrid system', 'body' => 'Solar + battery backup. Useful where power cuts are frequent or backup is important.', 'icon' => 'fa-car-battery'],
            ['title' => 'Who should choose what?', 'body' => 'On-grid for maximum payback, Hybrid for backup + reliability. We show both finance outcomes below.', 'icon' => 'fa-scale-balanced'],
            ['title' => 'Benefits', 'body' => 'Lower bills, cleaner energy, long-term savings, better property value, and reduced carbon footprint.', 'icon' => 'fa-leaf'],
            ['title' => 'Important expectations', 'body' => 'Generation depends on sunlight, roof shading, and season. We use practical assumptions you can edit.', 'icon' => 'fa-circle-info'],
            ['title' => 'FAQ', 'body' => 'You can start with your bill or units. Advanced inputs are optional and only for power users.', 'icon' => 'fa-circle-question'],
        ],
        'on_grid_prices' => [
            ['size_kwp' => 2, 'price_upto_2_lacs' => 136900, 'price_above_2_lacs' => 136900],
            ['size_kwp' => 3, 'price_upto_2_lacs' => 183900, 'price_above_2_lacs' => 183900],
            ['size_kwp' => 4, 'price_upto_2_lacs' => 248900, 'price_above_2_lacs' => 248900],
            ['size_kwp' => 5, 'price_upto_2_lacs' => 272900, 'price_above_2_lacs' => 292900],
            ['size_kwp' => 6, 'price_upto_2_lacs' => 341900, 'price_above_2_lacs' => 371900],
            ['size_kwp' => 7, 'price_upto_2_lacs' => 382900, 'price_above_2_lacs' => 422900],
            ['size_kwp' => 8, 'price_upto_2_lacs' => 424900, 'price_above_2_lacs' => 474900],
            ['size_kwp' => 9, 'price_upto_2_lacs' => 454900, 'price_above_2_lacs' => 514900],
            ['size_kwp' => 10, 'price_upto_2_lacs' => 483900, 'price_above_2_lacs' => 553900],
        ],
        'hybrid_prices' => [
            ['size_kwp' => 2, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'price_upto_2_lacs' => 216900, 'price_above_2_lacs' => 216900],
            ['size_kwp' => 3, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 2, 'price_upto_2_lacs' => 264900, 'price_above_2_lacs' => 264900],
            ['size_kwp' => 2, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'price_upto_2_lacs' => 241900, 'price_above_2_lacs' => 241900],
            ['size_kwp' => 3, 'inverter_kva' => 3.0, 'phase' => '1 Phase', 'battery_count' => 4, 'price_upto_2_lacs' => 289900, 'price_above_2_lacs' => 289900],
            ['size_kwp' => 3, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'price_upto_2_lacs' => 308900, 'price_above_2_lacs' => 308900],
            ['size_kwp' => 4, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'price_upto_2_lacs' => 367900, 'price_above_2_lacs' => 377900],
            ['size_kwp' => 5, 'inverter_kva' => 5.0, 'phase' => '1 Phase', 'battery_count' => 4, 'price_upto_2_lacs' => 388900, 'price_above_2_lacs' => 408900],
            ['size_kwp' => 4, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 472900, 'price_above_2_lacs' => 482900],
            ['size_kwp' => 5, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 486900, 'price_above_2_lacs' => 506900],
            ['size_kwp' => 6, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 555900, 'price_above_2_lacs' => 585900],
            ['size_kwp' => 7, 'inverter_kva' => 7.5, 'phase' => '1 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 598900, 'price_above_2_lacs' => 638900],
            ['size_kwp' => 6, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 619900, 'price_above_2_lacs' => 649900],
            ['size_kwp' => 7, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 652900, 'price_above_2_lacs' => 692900],
            ['size_kwp' => 8, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 667900, 'price_above_2_lacs' => 717900],
            ['size_kwp' => 9, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 730900, 'price_above_2_lacs' => 790900],
            ['size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 747900, 'price_above_2_lacs' => 817900],
            ['size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '1 Phase', 'battery_count' => 15, 'price_upto_2_lacs' => 864900, 'price_above_2_lacs' => 934900],
            ['size_kwp' => 10, 'inverter_kva' => 15.0, 'phase' => '1 Phase', 'battery_count' => 20, 'price_upto_2_lacs' => 981900, 'price_above_2_lacs' => 1051900],
            ['size_kwp' => 5, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 551900, 'price_above_2_lacs' => 571900],
            ['size_kwp' => 6, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 625900, 'price_above_2_lacs' => 655900],
            ['size_kwp' => 7, 'inverter_kva' => 7.5, 'phase' => '3 Phase', 'battery_count' => 8, 'price_upto_2_lacs' => 666900, 'price_above_2_lacs' => 706900],
            ['size_kwp' => 6, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 714900, 'price_above_2_lacs' => 744900],
            ['size_kwp' => 7, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 755900, 'price_above_2_lacs' => 795900],
            ['size_kwp' => 8, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 756900, 'price_above_2_lacs' => 806900],
            ['size_kwp' => 9, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 833900, 'price_above_2_lacs' => 893900],
            ['size_kwp' => 10, 'inverter_kva' => 10.0, 'phase' => '3 Phase', 'battery_count' => 10, 'price_upto_2_lacs' => 836900, 'price_above_2_lacs' => 906900],
            ['size_kwp' => 10, 'inverter_kva' => 15.0, 'phase' => '3 Phase', 'battery_count' => 15, 'price_upto_2_lacs' => 956900, 'price_above_2_lacs' => 1026900],
        ],
    ];
}

function solar_finance_settings_normalize(array $data): array
{
    return array_replace_recursive(solar_finance_settings_default(), $data);
}

function solar_finance_settings_load(): array
{
    $path = solar_finance_settings_path();
    if (!is_file($path)) {
        $defaults = solar_finance_settings_default();
        file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $defaults;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return solar_finance_settings_default();
    }

    return solar_finance_settings_normalize($decoded);
}

function solar_finance_settings_save(array $data): void
{
    $normalized = solar_finance_settings_normalize($data);
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode solar finance settings.');
    }

    if (file_put_contents(solar_finance_settings_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save solar finance settings.');
    }
}
