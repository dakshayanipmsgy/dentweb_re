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
        'content' => [
            'page_title' => 'Solar and Finance',
            'hero_intro' => 'Simple solar samjho, apna suitable system size estimate karo, finance compare karo, and direct quotation request bhejo.',
        ],
        'defaults' => [
            'unit_rate' => 8,
            'daily_generation_per_kw' => 5,
            'interest_rate' => 6,
            'loan_tenure_months' => 120,
            'roof_area_factor_per_kw' => 100,
            'co2_factor_kg_per_unit' => 0.82,
            'tree_equivalence_per_ton_co2' => 45,
        ],
        'on_grid_prices' => [
            ['solar_rating_kw' => 1, 'price_loan_upto_2_lakh' => null, 'price_loan_above_2_lakh' => null],
            ['solar_rating_kw' => 2, 'price_loan_upto_2_lakh' => 136900, 'price_loan_above_2_lakh' => 136900],
            ['solar_rating_kw' => 3, 'price_loan_upto_2_lakh' => 183900, 'price_loan_above_2_lakh' => 183900],
            ['solar_rating_kw' => 4, 'price_loan_upto_2_lakh' => 248900, 'price_loan_above_2_lakh' => 248900],
            ['solar_rating_kw' => 5, 'price_loan_upto_2_lakh' => 272900, 'price_loan_above_2_lakh' => 292900],
            ['solar_rating_kw' => 6, 'price_loan_upto_2_lakh' => 341900, 'price_loan_above_2_lakh' => 371900],
            ['solar_rating_kw' => 7, 'price_loan_upto_2_lakh' => 382900, 'price_loan_above_2_lakh' => 422900],
            ['solar_rating_kw' => 8, 'price_loan_upto_2_lakh' => 424900, 'price_loan_above_2_lakh' => 474900],
            ['solar_rating_kw' => 9, 'price_loan_upto_2_lakh' => 454900, 'price_loan_above_2_lakh' => 514900],
            ['solar_rating_kw' => 10, 'price_loan_upto_2_lakh' => 483900, 'price_loan_above_2_lakh' => 553900],
        ],
        'hybrid_prices' => [
            ['model' => 'NA', 'solar_rating_kw' => 1, 'kVA' => '', 'phase' => '', 'battery' => '', 'price_loan_upto_2_lakh' => null, 'price_loan_above_2_lakh' => null],
            ['model' => '2S-3I-B4-S', 'solar_rating_kw' => 2, 'kVA' => '3', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 216900, 'price_loan_above_2_lakh' => 216900],
            ['model' => '3S-3I-B4-S', 'solar_rating_kw' => 3, 'kVA' => '3', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 264900, 'price_loan_above_2_lakh' => 264900],
            ['model' => '2S-3I-B4-S', 'solar_rating_kw' => 2, 'kVA' => '3', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 241900, 'price_loan_above_2_lakh' => 241900],
            ['model' => '3S-3I-B4-S', 'solar_rating_kw' => 3, 'kVA' => '3', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 289900, 'price_loan_above_2_lakh' => 289900],
            ['model' => '3S-5I-B4-S', 'solar_rating_kw' => 3, 'kVA' => '5', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 308900, 'price_loan_above_2_lakh' => 308900],
            ['model' => '4S-5I-B4-S', 'solar_rating_kw' => 4, 'kVA' => '5', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 367900, 'price_loan_above_2_lakh' => 377900],
            ['model' => '5S-5I-B4-S', 'solar_rating_kw' => 5, 'kVA' => '5', 'phase' => 'Single', 'battery' => '4', 'price_loan_upto_2_lakh' => 388900, 'price_loan_above_2_lakh' => 408900],
            ['model' => '4S-8I-B8-S', 'solar_rating_kw' => 4, 'kVA' => '8', 'phase' => 'Single', 'battery' => '8', 'price_loan_upto_2_lakh' => 472900, 'price_loan_above_2_lakh' => 482900],
            ['model' => '5S-8I-B8-S', 'solar_rating_kw' => 5, 'kVA' => '8', 'phase' => 'Single', 'battery' => '8', 'price_loan_upto_2_lakh' => 486900, 'price_loan_above_2_lakh' => 506900],
            ['model' => '6S-8I-B8-S', 'solar_rating_kw' => 6, 'kVA' => '8', 'phase' => 'Single', 'battery' => '8', 'price_loan_upto_2_lakh' => 555900, 'price_loan_above_2_lakh' => 585900],
            ['model' => '7S-8I-B8-S', 'solar_rating_kw' => 7, 'kVA' => '8', 'phase' => 'Single', 'battery' => '8', 'price_loan_upto_2_lakh' => 598900, 'price_loan_above_2_lakh' => 638900],
            ['model' => '6S-10I-B10-S', 'solar_rating_kw' => 6, 'kVA' => '10', 'phase' => 'Single', 'battery' => '10', 'price_loan_upto_2_lakh' => 619900, 'price_loan_above_2_lakh' => 649900],
            ['model' => '7S-10I-B10-S', 'solar_rating_kw' => 7, 'kVA' => '10', 'phase' => 'Single', 'battery' => '10', 'price_loan_upto_2_lakh' => 652900, 'price_loan_above_2_lakh' => 692900],
            ['model' => '8S-10I-B10-S', 'solar_rating_kw' => 8, 'kVA' => '10', 'phase' => 'Single', 'battery' => '10', 'price_loan_upto_2_lakh' => 667900, 'price_loan_above_2_lakh' => 717900],
            ['model' => '9S-10I-B10-S', 'solar_rating_kw' => 9, 'kVA' => '10', 'phase' => 'Single', 'battery' => '10', 'price_loan_upto_2_lakh' => 730900, 'price_loan_above_2_lakh' => 790900],
            ['model' => '10S-10I-B10-S', 'solar_rating_kw' => 10, 'kVA' => '10', 'phase' => 'Single', 'battery' => '10', 'price_loan_upto_2_lakh' => 747900, 'price_loan_above_2_lakh' => 817900],
            ['model' => '10S-10I-B15-S', 'solar_rating_kw' => 10, 'kVA' => '10', 'phase' => 'Single', 'battery' => '15', 'price_loan_upto_2_lakh' => 864900, 'price_loan_above_2_lakh' => 934900],
            ['model' => '10S-15I-B20-S', 'solar_rating_kw' => 10, 'kVA' => '15', 'phase' => 'Single', 'battery' => '20', 'price_loan_upto_2_lakh' => 981900, 'price_loan_above_2_lakh' => 1051900],
            ['model' => '5S-8I-B8-T', 'solar_rating_kw' => 5, 'kVA' => '8', 'phase' => 'Three', 'battery' => '8', 'price_loan_upto_2_lakh' => 551900, 'price_loan_above_2_lakh' => 571900],
            ['model' => '6S-8I-B8-T', 'solar_rating_kw' => 6, 'kVA' => '8', 'phase' => 'Three', 'battery' => '8', 'price_loan_upto_2_lakh' => 625900, 'price_loan_above_2_lakh' => 655900],
            ['model' => '7S-8I-B8-T', 'solar_rating_kw' => 7, 'kVA' => '8', 'phase' => 'Three', 'battery' => '8', 'price_loan_upto_2_lakh' => 666900, 'price_loan_above_2_lakh' => 706900],
            ['model' => '6S-10I-B10-T', 'solar_rating_kw' => 6, 'kVA' => '10', 'phase' => 'Three', 'battery' => '10', 'price_loan_upto_2_lakh' => 714900, 'price_loan_above_2_lakh' => 744900],
            ['model' => '7S-10I-B10-T', 'solar_rating_kw' => 7, 'kVA' => '10', 'phase' => 'Three', 'battery' => '10', 'price_loan_upto_2_lakh' => 755900, 'price_loan_above_2_lakh' => 795900],
            ['model' => '8S-10I-B10-T', 'solar_rating_kw' => 8, 'kVA' => '10', 'phase' => 'Three', 'battery' => '10', 'price_loan_upto_2_lakh' => 756900, 'price_loan_above_2_lakh' => 806900],
            ['model' => '9S-10I-B10-T', 'solar_rating_kw' => 9, 'kVA' => '10', 'phase' => 'Three', 'battery' => '10', 'price_loan_upto_2_lakh' => 833900, 'price_loan_above_2_lakh' => 893900],
            ['model' => '10S-10I-B10-T', 'solar_rating_kw' => 10, 'kVA' => '10', 'phase' => 'Three', 'battery' => '10', 'price_loan_upto_2_lakh' => 836900, 'price_loan_above_2_lakh' => 906900],
            ['model' => '10S-15I-B15-T', 'solar_rating_kw' => 10, 'kVA' => '15', 'phase' => 'Three', 'battery' => '15', 'price_loan_upto_2_lakh' => 956900, 'price_loan_above_2_lakh' => 1026900],
        ],
    ];
}

function solar_finance_settings_normalize(array $data): array
{
    $defaults = solar_finance_settings_default();
    return array_replace_recursive($defaults, $data);
}

function solar_finance_settings(): array
{
    if (array_key_exists('solar_finance_settings_cache', $GLOBALS) && is_array($GLOBALS['solar_finance_settings_cache'])) {
        return $GLOBALS['solar_finance_settings_cache'];
    }

    $defaults = solar_finance_settings_default();
    $path = solar_finance_settings_path();

    if (!is_file($path)) {
        $normalizedDefaults = solar_finance_settings_normalize($defaults);
        $encodedDefaults = json_encode($normalizedDefaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedDefaults !== false) {
            @file_put_contents($path, $encodedDefaults, LOCK_EX);
        }
        $GLOBALS['solar_finance_settings_cache'] = $normalizedDefaults;
        return $normalizedDefaults;
    }

    $json = file_get_contents($path);
    $decoded = json_decode($json ?: '', true);
    if (!is_array($decoded)) {
        $decoded = $defaults;
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
        throw new RuntimeException('Failed to encode solar finance settings');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write solar finance settings file');
    }

    $GLOBALS['solar_finance_settings_cache'] = null;
}
