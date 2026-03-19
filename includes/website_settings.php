<?php
declare(strict_types=1);

function website_settings_allowed_button_styles(): array
{
    return ['rounded', 'pill', 'outline', 'sharp', 'solid-heavy'];
}

function website_settings_allowed_card_styles(): array
{
    return ['soft', 'strong', 'border', 'flat'];
}

function website_settings_allowed_font_sizes(): array
{
    return ['small', 'normal', 'large'];
}

function website_settings_allowed_font_weights(): array
{
    return ['normal', 'medium', 'semibold', 'bold'];
}

function website_settings_storage_path(): string
{
    $dir = __DIR__ . '/../storage/site';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/website_settings.json';
}

function website_settings_default(): array
{
    return [
        'global' => [
            'site_tagline' => 'Smart Solar for Every Home in Jharkhand',
            'header_callout' => 'PM Surya Ghar Muft Bijli Yojana specialist for Ranchi & Jharkhand',
            'tagline_color' => '',
            'tagline_font_size' => 'normal',
            'tagline_font_weight' => 'semibold',
            'callout_color' => '',
            'callout_font_size' => 'normal',
            'callout_font_weight' => 'semibold',
            'subheader_bg_color' => '',
        ],
        'hero' => [
            'kicker' => 'Trusted EPC partner in Jharkhand',
            'title' => 'Go Solar with Dakshayani Enterprises',
            'subtitle' => 'Rooftop solar systems with subsidy, EMI support, and full CMC.',
            'primary_image' => '/images/hero/hero.png',
            'primary_caption' => 'Dakshayani engineers on site for a rooftop commissioning.',
            'primary_button_text' => 'Book Free Site Visit',
            'primary_button_link' => '/contact',
            'secondary_button_text' => 'Check Subsidy Eligibility',
            'secondary_button_link' => '/pm-surya-ghar',
            'announcement_badge' => 'PM Surya Ghar – Up to 300 units free*',
            'announcement_text' => 'Limited window for subsidy applications. Get your documents verified today.',
        ],
        'sections' => [
            'what_our_customers_say_title' => 'What Our Customers Say',
            'what_our_customers_say_subtitle' => 'Homeowners and institutions across Jharkhand trust Dakshayani for hassle-free solar installations.',
            'seasonal_offer_title' => 'Seasonal Solar Offers',
            'seasonal_offer_text' => 'Festive offers on 3 kW to 10 kW rooftop systems. Extra warranty and free service visits on select packages.',
            'cta_strip_title' => 'Ready to lock in 25 years of solar savings?',
            'cta_strip_text' => 'Share your latest electricity bill and we will deliver a personalised payback report within 24 hours.',
            'cta_strip_cta_text' => 'Email Your Bill',
            'cta_strip_cta_link' => 'mailto:connect@dakshayani.co.in',
        ],
        'testimonials' => [
            [
                'name' => 'Rahul Sharma',
                'location' => 'Ranchi',
                'message' => 'Dakshayani Enterprises handled everything from subsidy paperwork to installation. My bill is now almost zero.',
                'system_size' => '5 kW',
                'type' => 'Residential',
            ],
            [
                'name' => 'St. Mary School',
                'location' => 'Hatia',
                'message' => 'Our 20 kW system has significantly reduced diesel generator usage and monthly expenses.',
                'system_size' => '20 kW',
                'type' => 'Institutional',
            ],
        ],
        'seasonal_offers' => [
            [
                'label' => 'Winter Solar Upgrade Offer',
                'description' => 'Special pricing for 5 kW+ systems with 5-year CMC included.',
                'valid_till' => '2025-12-31',
                'cta_text' => 'Enquire Now',
                'cta_link' => '/contact',
            ],
        ],
        'theme' => [
            'primary_color' => '#0f766e',
            'secondary_color' => '#f59e0b',
            'accent_color' => '#0ea5e9',
            'button_style' => 'rounded',
            'card_style' => 'soft',
        ],
    ];
}

function website_settings_normalize_hex(?string $value, string $fallback): string
{
    $hex = trim((string) $value);
    if ($hex === '') {
        return $fallback;
    }

    if ($hex[0] !== '#') {
        $hex = '#' . $hex;
    }

    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex) !== 1) {
        return $fallback;
    }

    return strtoupper($hex);
}

function website_settings_normalize(array $data): array
{
    $defaults = website_settings_default();
    $normalized = array_replace_recursive($defaults, $data);

    $normalized['theme']['primary_color'] = website_settings_normalize_hex($normalized['theme']['primary_color'] ?? '', $defaults['theme']['primary_color']);
    $normalized['theme']['secondary_color'] = website_settings_normalize_hex($normalized['theme']['secondary_color'] ?? '', $defaults['theme']['secondary_color']);
    $normalized['theme']['accent_color'] = website_settings_normalize_hex($normalized['theme']['accent_color'] ?? '', $defaults['theme']['accent_color']);

    if (!in_array($normalized['theme']['button_style'] ?? '', website_settings_allowed_button_styles(), true)) {
        $normalized['theme']['button_style'] = $defaults['theme']['button_style'];
    }

    if (!in_array($normalized['theme']['card_style'] ?? '', website_settings_allowed_card_styles(), true)) {
        $normalized['theme']['card_style'] = $defaults['theme']['card_style'];
    }

    $normalized['hero']['primary_image'] = trim((string) ($normalized['hero']['primary_image'] ?? $defaults['hero']['primary_image']));
    $normalized['hero']['primary_caption'] = trim((string) ($normalized['hero']['primary_caption'] ?? $defaults['hero']['primary_caption']));

    $normalized['global']['tagline_color'] = website_settings_normalize_hex($normalized['global']['tagline_color'] ?? '', $defaults['global']['tagline_color']);
    $normalized['global']['callout_color'] = website_settings_normalize_hex($normalized['global']['callout_color'] ?? '', $defaults['global']['callout_color']);
    $normalized['global']['subheader_bg_color'] = website_settings_normalize_hex($normalized['global']['subheader_bg_color'] ?? '', $defaults['global']['subheader_bg_color']);

    $normalized['global']['tagline_font_size'] = in_array($normalized['global']['tagline_font_size'] ?? '', website_settings_allowed_font_sizes(), true)
        ? $normalized['global']['tagline_font_size']
        : $defaults['global']['tagline_font_size'];
    $normalized['global']['callout_font_size'] = in_array($normalized['global']['callout_font_size'] ?? '', website_settings_allowed_font_sizes(), true)
        ? $normalized['global']['callout_font_size']
        : $defaults['global']['callout_font_size'];

    $normalized['global']['tagline_font_weight'] = in_array($normalized['global']['tagline_font_weight'] ?? '', website_settings_allowed_font_weights(), true)
        ? $normalized['global']['tagline_font_weight']
        : $defaults['global']['tagline_font_weight'];
    $normalized['global']['callout_font_weight'] = in_array($normalized['global']['callout_font_weight'] ?? '', website_settings_allowed_font_weights(), true)
        ? $normalized['global']['callout_font_weight']
        : $defaults['global']['callout_font_weight'];

    return $normalized;
}

function website_settings(): array
{
    if (array_key_exists('website_settings_cache', $GLOBALS) && is_array($GLOBALS['website_settings_cache'])) {
        return $GLOBALS['website_settings_cache'];
    }

    $defaults = website_settings_default();
    $path = website_settings_storage_path();

    // Ensure a canonical settings file exists so admin and public views stay in sync.
    if (!is_file($path)) {
        $normalizedDefaults = website_settings_normalize($defaults);
        $encodedDefaults = json_encode($normalizedDefaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedDefaults !== false) {
            @file_put_contents($path, $encodedDefaults, LOCK_EX);
        }

        $GLOBALS['website_settings_cache'] = $normalizedDefaults;
        return $normalizedDefaults;
    }

    $json = file_get_contents($path);
    $data = json_decode($json ?: '', true);

    if (!is_array($data)) {
        $data = $defaults;
    }

    $normalized = website_settings_normalize($data);
    $GLOBALS['website_settings_cache'] = $normalized;
    return $normalized;
}

function website_settings_save(array $data): void
{
    $path = website_settings_storage_path();
    $normalized = website_settings_normalize($data);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode website settings');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write website settings file');
    }

    // Invalidate cache
    $GLOBALS['website_settings_cache'] = null;
}
