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
        'content' => [
            'page_title' => 'Solar and Finance',
            'hero_text' => 'Simple solar guidance, easy finance comparison, and instant WhatsApp quotation request.',
            'cta_text' => 'Request a quotation',
            'faq' => [
                ['q' => 'Is this estimate exact?', 'a' => 'This is an educational estimate. Final quote depends on site survey, sanction load, and roof conditions.'],
                ['q' => 'Can I edit assumptions?', 'a' => 'Yes, all pro values are editable so advanced users can tune the estimate.'],
            ],
            'explainer_cards' => [
                ['icon' => 'fa-house-signal', 'title' => 'What is rooftop solar?', 'text' => 'Panels on your roof generate electricity during daytime and reduce your monthly bill.'],
                ['icon' => 'fa-bolt', 'title' => 'What is PM Surya Ghar?', 'text' => 'A government scheme for eligible residential consumers to support rooftop solar adoption.'],
                ['icon' => 'fa-code-compare', 'title' => 'On-grid vs Hybrid', 'text' => 'On-grid uses grid as backup. Hybrid adds batteries for backup power during outages.'],
                ['icon' => 'fa-user-check', 'title' => 'Who is eligible?', 'text' => 'Homeowners with usable roof, valid electricity connection, and policy eligibility.'],
                ['icon' => 'fa-list-check', 'title' => 'Which option suits whom?', 'text' => 'On-grid for max savings and stable grid, Hybrid for outage-prone areas needing backup.'],
                ['icon' => 'fa-seedling', 'title' => 'Benefits & expectations', 'text' => 'Lower bills, cleaner energy, and long-term savings. Actual performance depends on sunlight and usage pattern.'],
            ],
        ],
        'defaults' => [
            'daily_generation_per_kw' => 5,
            'unit_rate' => 8,
            'interest_upto_2_lacs' => 6,
            'interest_above_2_lacs' => 8.15,
            'loan_tenure_years' => 10,
            'co2_factor_kg_per_unit' => 0.82,
            'tree_factor_kg_per_tree' => 21,
            'roof_area_sqft_per_kw' => 100,
        ],
        'on_grid_prices' => [
            ['size_kw' => 2, 'loan_upto_2_lacs' => 136900, 'loan_above_2_lacs' => 136900],
            ['size_kw' => 3, 'loan_upto_2_lacs' => 183900, 'loan_above_2_lacs' => 183900],
            ['size_kw' => 4, 'loan_upto_2_lacs' => 248900, 'loan_above_2_lacs' => 248900],
            ['size_kw' => 5, 'loan_upto_2_lacs' => 272900, 'loan_above_2_lacs' => 292900],
            ['size_kw' => 6, 'loan_upto_2_lacs' => 341900, 'loan_above_2_lacs' => 371900],
            ['size_kw' => 7, 'loan_upto_2_lacs' => 382900, 'loan_above_2_lacs' => 422900],
            ['size_kw' => 8, 'loan_upto_2_lacs' => 424900, 'loan_above_2_lacs' => 474900],
            ['size_kw' => 9, 'loan_upto_2_lacs' => 454900, 'loan_above_2_lacs' => 514900],
            ['size_kw' => 10, 'loan_upto_2_lacs' => 483900, 'loan_above_2_lacs' => 553900],
        ],
        'hybrid_prices' => [
            ['size_kw'=>2,'inverter_kva'=>3.0,'phase'=>'1 Phase','battery_count'=>2,'loan_upto_2_lacs'=>216900,'loan_above_2_lacs'=>216900],
            ['size_kw'=>3,'inverter_kva'=>3.0,'phase'=>'1 Phase','battery_count'=>2,'loan_upto_2_lacs'=>264900,'loan_above_2_lacs'=>264900],
            ['size_kw'=>2,'inverter_kva'=>3.0,'phase'=>'1 Phase','battery_count'=>4,'loan_upto_2_lacs'=>241900,'loan_above_2_lacs'=>241900],
            ['size_kw'=>3,'inverter_kva'=>3.0,'phase'=>'1 Phase','battery_count'=>4,'loan_upto_2_lacs'=>289900,'loan_above_2_lacs'=>289900],
            ['size_kw'=>3,'inverter_kva'=>5.0,'phase'=>'1 Phase','battery_count'=>4,'loan_upto_2_lacs'=>308900,'loan_above_2_lacs'=>308900],
            ['size_kw'=>4,'inverter_kva'=>5.0,'phase'=>'1 Phase','battery_count'=>4,'loan_upto_2_lacs'=>367900,'loan_above_2_lacs'=>377900],
            ['size_kw'=>5,'inverter_kva'=>5.0,'phase'=>'1 Phase','battery_count'=>4,'loan_upto_2_lacs'=>388900,'loan_above_2_lacs'=>408900],
            ['size_kw'=>4,'inverter_kva'=>7.5,'phase'=>'1 Phase','battery_count'=>8,'loan_upto_2_lacs'=>472900,'loan_above_2_lacs'=>482900],
            ['size_kw'=>5,'inverter_kva'=>7.5,'phase'=>'1 Phase','battery_count'=>8,'loan_upto_2_lacs'=>486900,'loan_above_2_lacs'=>506900],
            ['size_kw'=>6,'inverter_kva'=>7.5,'phase'=>'1 Phase','battery_count'=>8,'loan_upto_2_lacs'=>555900,'loan_above_2_lacs'=>585900],
            ['size_kw'=>7,'inverter_kva'=>7.5,'phase'=>'1 Phase','battery_count'=>8,'loan_upto_2_lacs'=>598900,'loan_above_2_lacs'=>638900],
            ['size_kw'=>6,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>10,'loan_upto_2_lacs'=>619900,'loan_above_2_lacs'=>649900],
            ['size_kw'=>7,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>10,'loan_upto_2_lacs'=>652900,'loan_above_2_lacs'=>692900],
            ['size_kw'=>8,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>10,'loan_upto_2_lacs'=>667900,'loan_above_2_lacs'=>717900],
            ['size_kw'=>9,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>10,'loan_upto_2_lacs'=>730900,'loan_above_2_lacs'=>790900],
            ['size_kw'=>10,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>10,'loan_upto_2_lacs'=>747900,'loan_above_2_lacs'=>817900],
            ['size_kw'=>10,'inverter_kva'=>10.0,'phase'=>'1 Phase','battery_count'=>15,'loan_upto_2_lacs'=>864900,'loan_above_2_lacs'=>934900],
            ['size_kw'=>10,'inverter_kva'=>15.0,'phase'=>'1 Phase','battery_count'=>20,'loan_upto_2_lacs'=>981900,'loan_above_2_lacs'=>1051900],
            ['size_kw'=>5,'inverter_kva'=>7.5,'phase'=>'3 Phase','battery_count'=>8,'loan_upto_2_lacs'=>551900,'loan_above_2_lacs'=>571900],
            ['size_kw'=>6,'inverter_kva'=>7.5,'phase'=>'3 Phase','battery_count'=>8,'loan_upto_2_lacs'=>625900,'loan_above_2_lacs'=>655900],
            ['size_kw'=>7,'inverter_kva'=>7.5,'phase'=>'3 Phase','battery_count'=>8,'loan_upto_2_lacs'=>666900,'loan_above_2_lacs'=>706900],
            ['size_kw'=>6,'inverter_kva'=>10.0,'phase'=>'3 Phase','battery_count'=>10,'loan_upto_2_lacs'=>714900,'loan_above_2_lacs'=>744900],
            ['size_kw'=>7,'inverter_kva'=>10.0,'phase'=>'3 Phase','battery_count'=>10,'loan_upto_2_lacs'=>755900,'loan_above_2_lacs'=>795900],
            ['size_kw'=>8,'inverter_kva'=>10.0,'phase'=>'3 Phase','battery_count'=>10,'loan_upto_2_lacs'=>756900,'loan_above_2_lacs'=>806900],
            ['size_kw'=>9,'inverter_kva'=>10.0,'phase'=>'3 Phase','battery_count'=>10,'loan_upto_2_lacs'=>833900,'loan_above_2_lacs'=>893900],
            ['size_kw'=>10,'inverter_kva'=>10.0,'phase'=>'3 Phase','battery_count'=>10,'loan_upto_2_lacs'=>836900,'loan_above_2_lacs'=>906900],
            ['size_kw'=>10,'inverter_kva'=>15.0,'phase'=>'3 Phase','battery_count'=>15,'loan_upto_2_lacs'=>956900,'loan_above_2_lacs'=>1026900],
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

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $decoded);
}

function solar_finance_settings_save(array $settings): void
{
    $merged = array_replace_recursive(solar_finance_settings_default(), $settings);
    $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Could not encode solar finance settings.');
    }

    if (file_put_contents(solar_finance_settings_storage_path(), $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not write solar finance settings file.');
    }
}
