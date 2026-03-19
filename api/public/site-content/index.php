<?php
declare(strict_types=1);

// Suppress warnings to ensure clean JSON output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $settings = website_settings();

    $theme = $settings['theme'] ?? [];
    $hero = $settings['hero'] ?? [];
    $sections = $settings['sections'] ?? [];
    $global = $settings['global'] ?? [];
    $announcementBar = $settings['announcement_bar'] ?? [];
    $calculator = $settings['savings_calculator'] ?? [];

    $offers = array_values(array_map(static function (array $offer): array {
        return [
            'label' => $offer['label'] ?? '',
            'description' => $offer['description'] ?? '',
            'valid_till' => $offer['valid_till'] ?? '',
            'cta_text' => $offer['cta_text'] ?? '',
            'cta_link' => $offer['cta_link'] ?? '',
        ];
    }, $settings['seasonal_offers'] ?? []));

    $testimonials = array_values(array_map(static function (array $testimonial): array {
        return [
            'name' => $testimonial['name'] ?? '',
            'location' => $testimonial['location'] ?? '',
            'message' => $testimonial['message'] ?? '',
            'type' => $testimonial['type'] ?? '',
            'system_size' => $testimonial['system_size'] ?? '',
        ];
    }, $settings['testimonials'] ?? []));

    $response = [
        'theme' => $theme,
        'hero' => [
            'kicker' => $hero['kicker'] ?? '',
            'title' => $hero['title'] ?? '',
            'subtitle' => $hero['subtitle'] ?? '',
            'background_type' => $hero['background_type'] ?? 'image',
            'background_image' => $hero['background_image'] ?? '',
            'background_video' => $hero['background_video'] ?? '',
            'primary_image' => $hero['primary_image'] ?? '',
            'primary_caption' => $hero['primary_caption'] ?? '',
            'announcement_badge' => $hero['announcement_badge'] ?? '',
            'announcement_text' => $hero['announcement_text'] ?? '',
            'primary_button_text' => $hero['primary_button_text'] ?? '',
            'primary_button_link' => $hero['primary_button_link'] ?? '',
            'secondary_button_text' => $hero['secondary_button_text'] ?? '',
            'secondary_button_link' => $hero['secondary_button_link'] ?? '',
        ],
        'announcement_bar' => [
            'enabled' => !empty($announcementBar['enabled']),
            'text' => $announcementBar['text'] ?? '',
            'link' => $announcementBar['link'] ?? '',
            'start_date' => $announcementBar['start_date'] ?? '',
            'end_date' => $announcementBar['end_date'] ?? '',
            'dismissible' => !array_key_exists('dismissible', $announcementBar) || !empty($announcementBar['dismissible']),
        ],
        'savings_calculator' => $calculator,
        'global' => [
            'site_tagline' => $global['site_tagline'] ?? '',
            'header_callout' => $global['header_callout'] ?? '',
            'tagline_color' => $global['tagline_color'] ?? '',
            'tagline_font_size' => $global['tagline_font_size'] ?? '',
            'tagline_font_weight' => $global['tagline_font_weight'] ?? '',
            'callout_color' => $global['callout_color'] ?? '',
            'callout_font_size' => $global['callout_font_size'] ?? '',
            'callout_font_weight' => $global['callout_font_weight'] ?? '',
            'subheader_bg_color' => $global['subheader_bg_color'] ?? '',
        ],
        'sections' => $sections,
        'offers' => $offers,
        'seasonal_offers' => $offers,
        'testimonials' => $testimonials,
    ];

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load site content']);
}
