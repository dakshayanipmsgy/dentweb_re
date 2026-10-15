<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/website_settings.php';

function assertPublicSolar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacy = [
    'hero' => [
        'primary_image' => '/images/hero/hero.png',
        'primary_caption' => 'Dakshayani engineers on site for a rooftop commissioning.',
        'announcement_text' => 'Limited window for subsidy applications. Get your documents verified today.',
        'primary_button_link' => '/contact',
        'secondary_button_link' => '/pm-surya-ghar',
    ],
    'theme' => ['primary_color' => '#123456'],
];
$updated = website_settings_normalize($legacy);
$defaults = website_settings_default();
foreach ($legacy['hero'] as $field => $value) {
    assertPublicSolar($updated['hero'][$field] === $defaults['hero'][$field], 'Legacy hero field not upgraded: ' . $field);
}
assertPublicSolar($updated['theme']['primary_color'] === '#123456', 'Custom theme changed');
$custom = $legacy;
$custom['hero']['primary_image'] = '/images/custom-project.jpg';
$custom['hero']['primary_caption'] = 'Our completed rooftop project';
$custom['hero']['announcement_text'] = 'Book a consultation';
$customized = website_settings_normalize($custom);
foreach (['primary_image', 'primary_caption', 'announcement_text'] as $field) {
    assertPublicSolar($customized['hero'][$field] === $custom['hero'][$field], 'Custom field overwritten: ' . $field);
}
assertPublicSolar(website_settings_normalize($updated) === $updated, 'Normalization is not idempotent');
$offers = website_settings_public_seasonal_offers([
    ['label' => 'Expired', 'valid_till' => '2025-12-31'],
    ['label' => 'Current', 'valid_till' => '2026-10-31'],
], new DateTimeImmutable('2026-10-15', new DateTimeZone('Asia/Kolkata')));
assertPublicSolar(count($offers) === 1 && $offers[0]['label'] === 'Current', 'Expired offer appeared');
echo "Public solar content checks passed.\n";
