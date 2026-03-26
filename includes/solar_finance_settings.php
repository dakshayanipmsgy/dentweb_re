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
        'page_title' => 'Solar and Finance',
        'hero_intro' => 'Understand solar in simple words, compare loan vs self-funded, and estimate your monthly outflow before you decide.',
        'cta_text' => 'Request a quotation',
        'defaults' => [
            'daily_generation' => 5,
            'unit_rate' => 8,
            'interest_rate' => 6,
            'loan_tenure_months' => 120,
            'tariff_escalation' => 3,
            'solar_degradation' => 0.6,
            'fixed_charge' => 0,
            'annual_om' => 3000,
            'inverter_reserve' => 1500,
            'billing_assumption' => 'net_metering',
            'shading_loss' => 10,
        ],
        'factors' => [
            'roof_area_sqft_per_kw' => 100,
            'co2_per_unit_kg' => 0.82,
            'tree_per_ton_co2' => 45,
        ],
        'explainers' => [
            [
                'title' => 'What is rooftop solar?',
                'text' => 'Solar panels on your roof generate electricity in daytime. Your meter records what you use and what you export.',
                'icon' => 'fa-solar-panel',
            ],
            [
                'title' => 'PM Surya Ghar: Muft Bijli Yojana',
                'text' => 'Eligible homeowners can receive subsidy support. We help with document preparation and process guidance.',
                'icon' => 'fa-indian-rupee-sign',
            ],
            [
                'title' => 'On-grid vs Hybrid',
                'text' => 'On-grid suits most city homes. Hybrid adds battery backup for frequent power cuts.',
                'icon' => 'fa-bolt',
            ],
            [
                'title' => 'Who is eligible?',
                'text' => 'Homes, schools, hospitals, offices, and shops with usable roof space and valid power connection can usually adopt rooftop solar.',
                'icon' => 'fa-user-check',
            ],
            [
                'title' => 'Important expectations',
                'text' => 'Generation changes with weather, season, and shading. Keep small annual maintenance budget and realistic savings expectations.',
                'icon' => 'fa-circle-info',
            ],
        ],
        'faqs' => [
            [
                'q' => 'Will my bill become zero?',
                'a' => 'Many customers reduce bills heavily, but fixed charges and usage patterns may keep a small bill.',
            ],
            [
                'q' => 'How much roof area is needed?',
                'a' => 'A common estimate is about 90–110 sq ft per kW depending on panel type and layout.',
            ],
            [
                'q' => 'Is loan better than self-funded?',
                'a' => 'Loan can reduce upfront pressure. Self-funded often gives higher lifetime savings.',
            ],
        ],
    ];
}

function solar_finance_settings_normalize(array $data): array
{
    $defaults = solar_finance_settings_default();
    $normalized = array_replace_recursive($defaults, $data);

    $normalized['page_title'] = trim((string) ($normalized['page_title'] ?? $defaults['page_title']));
    $normalized['hero_intro'] = trim((string) ($normalized['hero_intro'] ?? $defaults['hero_intro']));
    $normalized['cta_text'] = trim((string) ($normalized['cta_text'] ?? $defaults['cta_text']));

    return $normalized;
}

function solar_finance_settings(): array
{
    if (array_key_exists('solar_finance_settings_cache', $GLOBALS) && is_array($GLOBALS['solar_finance_settings_cache'])) {
        return $GLOBALS['solar_finance_settings_cache'];
    }

    $defaults = solar_finance_settings_default();
    $path = solar_finance_settings_storage_path();

    if (!is_file($path)) {
        $normalizedDefaults = solar_finance_settings_normalize($defaults);
        $encodedDefaults = json_encode($normalizedDefaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedDefaults !== false) {
            @file_put_contents($path, $encodedDefaults, LOCK_EX);
        }

        $GLOBALS['solar_finance_settings_cache'] = $normalizedDefaults;
        return $normalizedDefaults;
    }

    $raw = file_get_contents($path);
    $decoded = json_decode($raw ?: '', true);
    if (!is_array($decoded)) {
        $decoded = $defaults;
    }

    $normalized = solar_finance_settings_normalize($decoded);
    $GLOBALS['solar_finance_settings_cache'] = $normalized;

    return $normalized;
}

function solar_finance_settings_save(array $data): void
{
    $path = solar_finance_settings_storage_path();
    $normalized = solar_finance_settings_normalize($data);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        throw new RuntimeException('Failed to encode solar and finance settings.');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write solar and finance settings file.');
    }

    $GLOBALS['solar_finance_settings_cache'] = null;
}
