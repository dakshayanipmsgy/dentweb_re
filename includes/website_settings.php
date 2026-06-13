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
            'site_tagline' => 'Solar, Battery Backup & EnergyCare for Jharkhand',
            'header_callout' => 'Local EPC, process coordination and long-term after-sales support',
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
            'title' => 'Solar, Battery Backup & EnergyCare for Jharkhand',
            'subtitle' => 'One accountable local team for assessment, EPC, subsidy and finance process support, commissioning and long-term care.',
            'primary_image' => '/images/hero/hero.png',
            'primary_caption' => 'Dakshayani engineers on site for a rooftop commissioning.',
            'primary_button_text' => 'Book Free Site Visit',
            'primary_button_link' => '/contact.php',
            'secondary_button_text' => 'Explore Solar & Finance',
            'secondary_button_link' => '/solar-and-finance.php',
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
        'testimonials' => [],
        'seasonal_offers' => [
            [
                'label' => 'Winter Solar Upgrade Offer',
                'description' => 'Special pricing for 5 kW+ systems with 5-year CMC included.',
                'valid_till' => '2025-12-31',
                'cta_text' => 'Enquire Now',
                'cta_link' => '/contact.php',
            ],
        ],
        'site' => [
            'schema_version' => 2,
            'company_name' => 'Dakshayani Enterprises',
            'positioning' => 'Jharkhand’s reliable solar, storage and EnergyCare company for households, institutions, businesses and government projects.',
            'primary_phone' => '+91 70702 78178',
            'secondary_phone' => '+91 93049 49688',
            'whatsapp' => '917070278178',
            'primary_email' => 'connect@dakshayani.co.in',
            'secondary_email' => 'dakshayanienterprises@gmail.com',
            'address' => 'Ranchi, Jharkhand, India',
            'service_areas' => 'Ranchi and across Jharkhand',
            'social' => ['facebook' => '', 'instagram' => '', 'linkedin' => '', 'youtube' => ''],
        ],
        'navigation' => [
            ['label' => 'Home', 'url' => '/index.php', 'group' => '', 'enabled' => true, 'new_tab' => false, 'order' => 10],
            ['label' => 'PM Surya Ghar', 'url' => '/pm-surya-ghar.html', 'group' => 'Solar Solutions', 'enabled' => true, 'new_tab' => false, 'order' => 20],
            ['label' => 'Commercial & Industrial', 'url' => '/commercial-industrial-solar.html', 'group' => 'Solar Solutions', 'enabled' => true, 'new_tab' => false, 'order' => 30],
            ['label' => 'Government EPC', 'url' => '/govt-epc.html', 'group' => 'Solar Solutions', 'enabled' => true, 'new_tab' => false, 'order' => 40],
            ['label' => 'Solar & Finance', 'url' => '/solar-and-finance.php', 'group' => 'Solar Solutions', 'enabled' => true, 'new_tab' => false, 'order' => 50],
            ['label' => 'AMC & O&M', 'url' => '/energycare-amc.html', 'group' => 'EnergyCare', 'enabled' => true, 'new_tab' => false, 'order' => 60],
            ['label' => 'Service / Complaint', 'url' => '/contact.php', 'group' => 'EnergyCare', 'enabled' => true, 'new_tab' => false, 'order' => 70],
            ['label' => 'Hybrid + Battery', 'url' => '/hybrid-solar-battery.html', 'group' => 'Future Energy', 'enabled' => true, 'new_tab' => false, 'order' => 80],
            ['label' => 'EV Charging + Solar', 'url' => '/ev-charging-solar.html', 'group' => 'Future Energy', 'enabled' => true, 'new_tab' => false, 'order' => 90],
            ['label' => 'Material Supply', 'url' => '/solar-material-supply.html', 'group' => 'Partners & Supply', 'enabled' => true, 'new_tab' => false, 'order' => 100],
            ['label' => 'Installer Network', 'url' => '/installer-partner-network.html', 'group' => 'Partners & Supply', 'enabled' => true, 'new_tab' => false, 'order' => 110],
            ['label' => 'Blog', 'url' => '/blog/index.php', 'group' => 'Knowledge', 'enabled' => true, 'new_tab' => false, 'order' => 120],
            ['label' => 'Calculator', 'url' => '/calculator.html', 'group' => 'Knowledge', 'enabled' => true, 'new_tab' => false, 'order' => 130],
        ],
        'faqs' => [
            ['question' => 'What happens before you recommend a solar system?', 'answer' => 'We review the site, electricity use, connection details and customer priorities before preparing a proposal. Final suitability is subject to site assessment.', 'enabled' => true, 'order' => 10],
            ['question' => 'Can Dakshayani support subsidy and finance processes?', 'answer' => 'Yes. We support eligible customers with documentation and coordination. Approval, subsidy and finance remain subject to the applicable authority or lender.', 'enabled' => true, 'order' => 20],
            ['question' => 'Do you support systems after commissioning?', 'answer' => 'Yes. EnergyCare can cover health checks, preventive maintenance, cleaning guidance, repair coordination and upgrade planning.', 'enabled' => true, 'order' => 30],
        ],
        'featured_projects' => [],
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

function website_settings_safe_url(string $value, string $fallback = ''): string
{
    $value = trim($value);
    if ($value === '') return $fallback;
    if (preg_match('/^(javascript|data|vbscript):/i', $value)) return $fallback;
    if ($value[0] === '/' || $value[0] === '#' || preg_match('/^(https?:|mailto:|tel:)/i', $value)) return $value;
    return $fallback;
}

function website_settings_normalize_repeatable(array $items, array $fields): array
{
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $clean = [];
        foreach ($fields as $field => $default) {
            $value = $item[$field] ?? $default;
            if (is_bool($default)) $clean[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            elseif (is_int($default)) $clean[$field] = (int) $value;
            elseif (str_contains($field, 'url') || str_contains($field, 'link')) $clean[$field] = website_settings_safe_url((string) $value, (string) $default);
            else $clean[$field] = trim((string) $value);
        }
        $result[] = $clean;
    }
    usort($result, static fn(array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    return array_slice($result, 0, 100);
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

    $normalized['site']['schema_version'] = 2;
    foreach (['company_name', 'positioning', 'primary_phone', 'secondary_phone', 'whatsapp', 'primary_email', 'secondary_email', 'address', 'service_areas'] as $field) {
        $normalized['site'][$field] = trim((string) ($normalized['site'][$field] ?? $defaults['site'][$field]));
    }
    foreach (array_keys($defaults['site']['social']) as $network) {
        $normalized['site']['social'][$network] = website_settings_safe_url((string) ($normalized['site']['social'][$network] ?? ''));
    }
    $normalized['navigation'] = website_settings_normalize_repeatable(is_array($data['navigation'] ?? null) ? $data['navigation'] : $defaults['navigation'], ['label' => '', 'url' => '', 'group' => '', 'enabled' => true, 'new_tab' => false, 'order' => 0]);
    $normalized['faqs'] = website_settings_normalize_repeatable(is_array($data['faqs'] ?? null) ? $data['faqs'] : $defaults['faqs'], ['question' => '', 'answer' => '', 'enabled' => true, 'order' => 0]);
    $normalized['featured_projects'] = website_settings_normalize_repeatable(is_array($data['featured_projects'] ?? null) ? $data['featured_projects'] : $defaults['featured_projects'], ['title' => '', 'category' => '', 'location' => '', 'image' => '', 'enabled' => true, 'order' => 0]);

    return $normalized;
}

/** Return only offers safe to render publicly. Admin settings remain unchanged. */
function website_settings_public_seasonal_offers(array $offers, ?DateTimeImmutable $today = null): array
{
    $timezone = new DateTimeZone('Asia/Kolkata');
    $today = ($today ?? new DateTimeImmutable('today', $timezone))->setTimezone($timezone)->setTime(0, 0);

    return array_values(array_filter($offers, static function ($offer) use ($today, $timezone): bool {
        if (!is_array($offer)) {
            return false;
        }

        $validTill = trim((string) ($offer['valid_till'] ?? ''));
        if ($validTill === '') {
            return true;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $validTill, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $validTill) {
            return false;
        }

        return $date >= $today;
    }));
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

    $temp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($temp, $encoded, LOCK_EX) === false || !rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Unable to write website settings file');
    }

    // Invalidate cache
    $GLOBALS['website_settings_cache'] = null;
}
