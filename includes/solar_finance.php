<?php
declare(strict_types=1);

function solar_finance_defaults_path(): string
{
    return __DIR__ . '/../data/solar_finance/settings.json';
}

function solar_finance_explainer_path(): string
{
    return __DIR__ . '/../data/leads/lead_explainer_content.json';
}

function solar_finance_settings_load(): array
{
    $path = solar_finance_defaults_path();
    if (!is_file($path)) {
        return ['defaults' => [], 'on_grid_prices' => [], 'hybrid_prices' => []];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['defaults' => [], 'on_grid_prices' => [], 'hybrid_prices' => []];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['defaults' => [], 'on_grid_prices' => [], 'hybrid_prices' => []];
}

function solar_finance_settings_save(array $settings): void
{
    $path = solar_finance_defaults_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function solar_finance_explainer_load(): array
{
    $path = solar_finance_explainer_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function solar_finance_explainer_save(array $data): void
{
    $path = solar_finance_explainer_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
