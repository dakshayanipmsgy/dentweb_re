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
            'loan_tenure_months' => 120,
            'roof_area_factor_per_kw' => 100,
            'co2_factor_kg_per_kwh' => 0.82,
            'tree_equivalence_factor_kg_per_tree_per_year' => 20,
        ],
        'content' => [
            'hero_title' => 'Solar and Finance',
            'hero_subtitle' => 'Understand your suitable solar size, savings, EMI impact, and subsidy basics in simple language.',
            'what_is_solar_rooftop' => 'Rooftop solar means solar panels are installed on your roof to generate electricity for your home or business.',
            'pm_surya_ghar_text' => 'PM Surya Ghar: Muft Bijli Yojana supports eligible residential consumers with subsidy as per government rules and approvals.',
            'on_grid_text' => 'On-grid connects solar directly with electricity grid. It gives best economics where power cuts are limited.',
            'hybrid_text' => 'Hybrid combines solar + battery backup. It helps run selected loads during power cuts, but usually costs more upfront.',
            'which_one_is_suitable_for_whom' => 'On-grid is typically suitable for lowest cost and strong bill savings. Hybrid is suitable if backup during outages is important.',
            'benefits' => "• Lower monthly electricity bill\n• Better long-term cost control\n• Clean energy for 25+ years\n• Property value uplift",
            'important_expectations' => 'Final output depends on shade, roof angle, maintenance, DISCOM billing, and actual day-time usage.',
            'faq' => [
                ['q' => 'Will my bill become zero?', 'a' => 'Not always. It depends on your usage pattern, sanctioned load, and net-metering rules.'],
                ['q' => 'Do I get subsidy instantly?', 'a' => 'Subsidy is credited after successful installation, inspection, and portal process completion.'],
                ['q' => 'Can I run load in power cut with on-grid?', 'a' => 'Standard on-grid systems shut down during power cuts for safety. Hybrid is used for backup needs.'],
            ],
            'disclaimer' => 'These numbers are indicative estimates. Actual values can vary due to tariff changes, approvals, roof conditions, and consumption behavior.',
        ],
        'on_grid_price_table' => [
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
        'hybrid_price_table' => [
            ['model' => '', 'solar_rating_kw' => 1, 'kVA' => '', 'phase' => '', 'battery' => '', 'price_loan_upto_2_lakh' => null, 'price_loan_above_2_lakh' => null],
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

function solar_finance_settings(): array
{
    $path = solar_finance_settings_storage_path();
    $defaults = solar_finance_settings_default();

    if (!is_file($path)) {
        $encoded = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            @file_put_contents($path, $encoded, LOCK_EX);
        }
        return $defaults;
    }

    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $data);
}

function solar_finance_settings_save(array $settings): void
{
    $path = solar_finance_settings_storage_path();
    $payload = array_replace_recursive(solar_finance_settings_default(), $settings);
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save solar and finance settings.');
    }
}
