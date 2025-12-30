<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_gemini.php';
require_once __DIR__ . '/includes/marketing_campaigns.php';
require_once __DIR__ . '/includes/handover.php';

require_admin();
$admin = current_user();
$csrfToken = $_SESSION['csrf_token'] ?? '';

$allowedTabs = ['overview', 'campaigns', 'insights', 'brand_profile', 'assets', 'meta_auto'];
$tab = is_string($_GET['tab'] ?? null) ? strtolower(trim((string) $_GET['tab'])) : 'campaigns';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'campaigns';
}

$loadError = null;
$campaigns = marketing_campaigns_load($loadError);
$campaigns = array_map('smart_marketing_merge_meta_auto', $campaigns);

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
if (is_array($flashData)) {
    $flashMessage = is_string($flashData['message'] ?? null) ? trim((string) $flashData['message']) : '';
    $candidateTone = is_string($flashData['type'] ?? null) ? strtolower((string) $flashData['type']) : 'info';
    if (in_array($candidateTone, ['success', 'info', 'warning', 'error'], true)) {
        $flashTone = $candidateTone;
    }
}

function smart_marketing_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function smart_marketing_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;
    return trim($value, '_');
}

function smart_marketing_tracking_code_exists(array $campaigns, string $code): bool
{
    foreach ($campaigns as $campaign) {
        if ((string) ($campaign['tracking_code'] ?? '') === $code && $code !== '') {
            return true;
        }
    }

    return false;
}

function smart_marketing_generate_tracking_code(array $campaigns, string $campaignId): string
{
    $attempts = 0;
    do {
        $attempts++;
        $random = bin2hex(random_bytes(3));
        $code = 'cmp_' . trim($campaignId) . '_' . $random;
        if ($attempts > 5) {
            $code = 'cmp_' . trim($campaignId) . '_' . uniqid();
        }
    } while (smart_marketing_tracking_code_exists($campaigns, $code));

    return $code;
}

function smart_marketing_channel_asset_definitions(): array
{
    return [
        'facebook_instagram' => [
            'primary_texts' => ['label' => 'Primary Texts', 'type' => 'list'],
            'headlines' => ['label' => 'Headlines', 'type' => 'list'],
            'descriptions' => ['label' => 'Descriptions', 'type' => 'list'],
            'cta_suggestions' => ['label' => 'CTA Suggestions', 'type' => 'list'],
            'media_concepts' => ['label' => 'Media Concepts', 'type' => 'text'],
            'media_prompts' => ['label' => 'Image Prompts', 'type' => 'text'],
        ],
        'google_search' => [
            'headlines' => ['label' => 'Headlines', 'type' => 'list'],
            'descriptions' => ['label' => 'Descriptions', 'type' => 'list'],
            'keywords' => ['label' => 'Keywords', 'type' => 'list'],
        ],
        'youtube_video' => [
            'hook_lines' => ['label' => 'Hook Lines', 'type' => 'list'],
            'video_script' => ['label' => 'Video Script', 'type' => 'text'],
            'thumbnail_text_ideas' => ['label' => 'Thumbnail Text Ideas', 'type' => 'list'],
        ],
        'whatsapp_broadcast' => [
            'short_messages' => ['label' => 'Short Messages', 'type' => 'list'],
            'long_messages' => ['label' => 'Long Messages', 'type' => 'list'],
            'media_concepts' => ['label' => 'Media Concepts', 'type' => 'text'],
            'media_prompts' => ['label' => 'Image Prompts', 'type' => 'text'],
        ],
        'offline_hoardings' => [
            'headline_options' => ['label' => 'Headline Options', 'type' => 'list'],
            'body_text_options' => ['label' => 'Body Text Options', 'type' => 'list'],
            'taglines' => ['label' => 'Taglines', 'type' => 'list'],
        ],
        'offline_pamphlets' => [
            'front_side_copy' => ['label' => 'Front Side Copy', 'type' => 'text'],
            'back_side_copy' => ['label' => 'Back Side Copy', 'type' => 'text'],
        ],
        'offline_newspaper_radio' => [
            'newspaper_ad_copy' => ['label' => 'Newspaper Ad Copy', 'type' => 'text'],
            'radio_script' => ['label' => 'Radio Script', 'type' => 'text'],
        ],
    ];
}

function smart_marketing_offline_templates_path(): string
{
    return __DIR__ . '/data/marketing/offline_templates.json';
}

function smart_marketing_offline_assets_dir(): string
{
    return __DIR__ . '/data/marketing/offline_assets';
}

function smart_marketing_brand_profile_path(): string
{
    return __DIR__ . '/data/marketing/brand_profile.json';
}

function smart_marketing_brand_profile_defaults(): array
{
    return [
        'firm_name' => '',
        'tagline' => '',
        'primary_contact_number' => '',
        'whatsapp_number' => '',
        'email' => '',
        'website_url' => '',
        'facebook_page_url' => '',
        'instagram_handle' => '',
        'physical_address' => '',
        'default_cta_line' => '',
        'logo_path' => '',
    ];
}

function load_brand_profile(?string &$error = null, bool $forceReload = false): array
{
    static $cache = null;
    static $cacheError = null;

    if ($forceReload) {
        $cache = null;
        $cacheError = null;
    }

    if ($cache !== null) {
        $error = $cacheError;
        return $cache;
    }

    $error = null;
    $defaults = smart_marketing_brand_profile_defaults();
    $path = smart_marketing_brand_profile_path();
    if (!is_file($path)) {
        $cache = $defaults;
        $cacheError = null;
        return $cache;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $error = 'Unable to read brand profile file.';
        $cache = $defaults;
        $cacheError = $error;
        return $cache;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            $error = 'Brand profile file is invalid. Showing empty fields.';
            $cache = $defaults;
            $cacheError = $error;
            return $cache;
        }

        $merged = array_merge($defaults, $decoded);
        $cache = $merged;
        $cacheError = null;
        return $cache;
    } catch (Throwable $exception) {
        $error = 'Brand profile file is invalid. Showing empty fields.';
        $cache = $defaults;
        $cacheError = $error;
        error_log('smart_marketing: invalid brand profile json: ' . $exception->getMessage());
        return $cache;
    }
}

function save_brand_profile(array $profile): bool
{
    $path = smart_marketing_brand_profile_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $data = array_merge(smart_marketing_brand_profile_defaults(), $profile);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode brand profile.');
    }

    $written = file_put_contents($path, $json, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Unable to save brand profile file.');
    }

    // Reset cache
    $error = null;
    load_brand_profile($error, true);

    return true;
}

function smart_marketing_meta_integration_defaults(): array
{
    return [
        'enabled' => false,
        'meta_app_id' => '',
        'meta_app_secret' => '',
        'meta_access_token' => '',
        'ad_account_id' => '',
        'business_id' => '',
        'default_page_id' => '',
        'default_ig_account_id' => '',
    ];
}

function load_meta_integration_settings(): array
{
    $defaults = smart_marketing_meta_integration_defaults();
    $path = __DIR__ . '/data/marketing/meta_integration.json';
    if (!file_exists($path)) {
        return $defaults;
    }

    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function save_meta_integration_settings(array $settings): void
{
    $path = __DIR__ . '/data/marketing/meta_integration.json';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function smart_marketing_meta_auto_defaults(): array
{
    return [
        'enabled' => false,
        'meta_campaign_id' => '',
        'meta_adset_id' => '',
        'meta_ad_id' => '',
        'created_at' => '',
        'last_status_sync' => '',
        'status_snapshot' => '',
        'last_known_campaign_status' => '',
        'last_known_adset_status' => '',
        'last_known_ad_status' => '',
        'last_known_daily_budget' => '',
        'last_insights_sync' => '',
        'insights_period' => '7d',
        'last_insights_raw' => [],
        'last_insights_summary' => '',
        'last_insights_ai_comment' => '',
    ];
}

function smart_marketing_merge_meta_auto(array $campaign): array
{
    $campaign['meta_auto'] = array_merge(
        smart_marketing_meta_auto_defaults(),
        is_array($campaign['meta_auto'] ?? null) ? $campaign['meta_auto'] : []
    );

    return $campaign;
}

function smart_marketing_meta_status_snapshot(string $campaignStatus, string $adsetStatus, string $adStatus, $dailyBudget): string
{
    $campaignStatus = $campaignStatus !== '' ? $campaignStatus : 'Unknown';
    $adsetStatus = $adsetStatus !== '' ? $adsetStatus : 'Unknown';
    $adStatus = $adStatus !== '' ? $adStatus : 'Unknown';
    $budgetLabel = $dailyBudget !== '' ? '₹' . $dailyBudget : '₹—';

    return 'Campaign: ' . $campaignStatus . ', AdSet: ' . $adsetStatus . ', Ad: ' . $adStatus . ', Daily: ' . $budgetLabel;
}

function smart_marketing_meta_api_request(string $url, ?array $postFields = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        return ['success' => false, 'error' => $curlError, 'status' => $httpCode, 'raw' => $response];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['success' => false, 'error' => 'HTTP ' . $httpCode, 'status' => $httpCode, 'raw' => $response];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON response', 'status' => $httpCode, 'raw' => $response];
    }

    return ['success' => true, 'status' => $httpCode, 'data' => $data, 'raw' => $response];
}

function get_brand_field(string $key, string $default = ''): string
{
    $profile = load_brand_profile();
    return isset($profile[$key]) && $profile[$key] !== '' ? (string) $profile[$key] : $default;
}

function smart_marketing_brand_context_lines(?array $profile = null): array
{
    $profile = is_array($profile) ? array_merge(smart_marketing_brand_profile_defaults(), $profile) : load_brand_profile();
    $lines = [];
    $mapping = [
        'firm_name' => 'Name',
        'tagline' => 'Tagline',
        'website_url' => 'Website',
        'primary_contact_number' => 'Primary phone',
        'whatsapp_number' => 'WhatsApp',
        'email' => 'Email',
        'facebook_page_url' => 'Facebook',
        'instagram_handle' => 'Instagram',
        'physical_address' => 'Address',
        'default_cta_line' => 'Default CTA',
        'logo_path' => 'Logo',
    ];

    foreach ($mapping as $field => $label) {
        $value = trim((string) ($profile[$field] ?? ''));
        if ($value !== '') {
            $lines[] = '- ' . $label . ': ' . $value;
        }
    }

    if (empty($lines)) {
        return [];
    }

    array_unshift($lines, 'Brand / Firm details:');
    return $lines;
}

function smart_marketing_brand_context_text(?array $profile = null): string
{
    $lines = smart_marketing_brand_context_lines($profile);
    return implode("\n", $lines);
}

function smart_marketing_media_base_dir(): string
{
    return __DIR__ . '/uploads/smart_marketing_media';
}

function smart_marketing_media_base_url(): string
{
    return '/uploads/smart_marketing_media';
}

function smart_marketing_sanitize_campaign_id_for_path(string $campaignId): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $campaignId) ?? $campaignId;
    $clean = trim($clean, '_');
    return $clean !== '' ? $clean : 'campaign_' . md5($campaignId);
}

function smart_marketing_media_campaign_dir(string $campaignId): string
{
    return smart_marketing_media_base_dir() . '/' . smart_marketing_sanitize_campaign_id_for_path($campaignId);
}

function smart_marketing_media_campaign_url(string $campaignId, string $fileName): string
{
    return smart_marketing_media_base_url() . '/' . smart_marketing_sanitize_campaign_id_for_path($campaignId) . '/' . $fileName;
}

function smart_marketing_media_extension(string $mimeType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'png',
    ];

    $lower = strtolower($mimeType);
    return $map[$lower] ?? 'jpg';
}

function smart_marketing_filter_media_by_channel(array $mediaAssets, string $channelKey): array
{
    return array_values(array_filter($mediaAssets, static function (array $asset) use ($channelKey): bool {
        $channel = (string) ($asset['channel'] ?? '');
        $useFor = (string) ($asset['use_for'] ?? '');
        if ($channelKey === 'facebook_instagram') {
            return in_array($channel, ['facebook_instagram', 'both'], true) || in_array($useFor, ['facebook_instagram', 'both'], true);
        }

        if ($channelKey === 'whatsapp_broadcast') {
            return in_array($channel, ['whatsapp_broadcast', 'both'], true) || in_array($useFor, ['whatsapp_broadcast', 'both'], true);
        }

        return $channel === $channelKey || $useFor === $channelKey;
    }));
}

function smart_marketing_campaign_media_assets(array $campaign): array
{
    $assets = $campaign['media_assets'] ?? [];
    return is_array($assets) ? $assets : [];
}

function smart_marketing_campaign_offline_assets(array $campaign): array
{
    $assets = $campaign['offline_assets'] ?? [];
    return is_array($assets) ? $assets : [];
}

function smart_marketing_asset_channel_label(array $asset): string
{
    $channelKey = (string) ($asset['use_for'] ?? ($asset['channel'] ?? ''));
    if ($channelKey === 'both') {
        return 'Facebook / Instagram & WhatsApp';
    }

    $labels = marketing_channels_labels();
    if (isset($labels[$channelKey])) {
        return $labels[$channelKey];
    }

    return $channelKey !== '' ? ucfirst(str_replace('_', ' ', $channelKey)) : 'Unspecified';
}

function smart_marketing_delete_media_file_if_safe(array $asset): void
{
    $fileUrl = (string) ($asset['file_url'] ?? '');
    if ($fileUrl === '') {
        return;
    }

    $pathPart = parse_url($fileUrl, PHP_URL_PATH);
    $path = $pathPart !== false && $pathPart !== null ? $pathPart : $fileUrl;
    $baseUrl = smart_marketing_media_base_url();

    if (strpos($path, $baseUrl) !== 0) {
        return;
    }

    $relative = substr($path, strlen($baseUrl));
    $fullPath = smart_marketing_media_base_dir() . $relative;
    $realBase = realpath(smart_marketing_media_base_dir());
    $realFull = realpath($fullPath);

    if ($realBase !== false && $realFull !== false && strpos($realFull, $realBase) === 0 && is_file($realFull)) {
        @unlink($realFull);
    }
}

function smart_marketing_load_offline_templates(): array
{
    $path = smart_marketing_offline_templates_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $exception) {
        error_log('smart_marketing: invalid offline templates json: ' . $exception->getMessage());
        return [];
    }
}

function smart_marketing_replace_placeholders(string $layout, array $content): string
{
    $placeholders = [
        '{{headline}}' => $content['headline'] ?? '',
        '{{subheadline}}' => $content['subheadline'] ?? '',
        '{{body_text}}' => $content['body_text'] ?? '',
        '{{offer_line}}' => $content['offer_line'] ?? '',
        '{{contact_block}}' => $content['contact_block'] ?? '',
        '{{dimension_notes}}' => $content['dimension_notes'] ?? '',
    ];

    return strtr($layout, $placeholders);
}

function smart_marketing_collect_channel_assets(array $input): array
{
    $definitions = smart_marketing_channel_asset_definitions();
    $collected = [];
    foreach ($definitions as $channelKey => $fields) {
        if (!isset($input['channel_assets'][$channelKey]) || !is_array($input['channel_assets'][$channelKey])) {
            continue;
        }

        foreach ($fields as $fieldKey => $meta) {
            $value = $input['channel_assets'][$channelKey][$fieldKey] ?? null;
            if ($meta['type'] === 'list') {
                $list = [];
                $raw = is_array($value) ? implode("\n", $value) : (string) $value;
                foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                    $clean = trim($line);
                    if ($clean !== '') {
                        $list[] = $clean;
                    }
                }
                if (!empty($list)) {
                    $collected[$channelKey][$fieldKey] = $list;
                } elseif (is_array($value) && !empty($value)) {
                    $collected[$channelKey][$fieldKey] = array_values(array_filter($value, static fn($v) => trim((string) $v) !== ''));
                }
            } else {
                $text = trim(is_array($value) ? implode("\n", $value) : (string) $value);
                if ($text !== '') {
                    $collected[$channelKey][$fieldKey] = $text;
                }
            }
        }
    }

    return $collected;
}

function smart_marketing_collect_form(array $input): array
{
    $channels = [];
    if (isset($input['channels']) && is_array($input['channels'])) {
        foreach ($input['channels'] as $channel) {
            $channels[] = (string) $channel;
        }
    }

    $onlineCreatives = [];
    $onlineRaw = (string) ($input['ai_online_creatives'] ?? '');
    foreach (preg_split('/\r?\n/', $onlineRaw) ?: [] as $line) {
        $clean = trim($line);
        if ($clean !== '') {
            $onlineCreatives[] = $clean;
        }
    }

    $offlineCreatives = [];
    $offlineRaw = (string) ($input['ai_offline_creatives'] ?? '');
    foreach (preg_split('/\r?\n/', $offlineRaw) ?: [] as $line) {
        $clean = trim($line);
        if ($clean !== '') {
            $offlineCreatives[] = $clean;
        }
    }

    $type = strtolower(trim((string) ($input['type'] ?? '')));
    if (!in_array($type, ['online', 'offline', 'mixed'], true)) {
        $type = 'online';
    }

    $primaryGoal = (string) ($input['primary_goal'] ?? '');
    if ($primaryGoal === '') {
        $primaryGoal = 'Lead Generation';
    }

    $status = strtolower(trim((string) ($input['status'] ?? 'draft')));
    $validStatuses = ['draft', 'planned', 'running', 'completed'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'draft';
    }

    $channelAssets = smart_marketing_collect_channel_assets($input);

    $metaFields = ['objective', 'conversion_location', 'performance_goal', 'budget_strategy', 'audience_summary', 'placement_strategy', 'optimization_and_bidding', 'creative_recommendations', 'tracking_and_reporting'];
    $metaAdsSettings = [];
    foreach ($metaFields as $metaField) {
        $value = trim((string) ($input['meta_ads_settings'][$metaField] ?? ''));
        $metaAdsSettings[$metaField] = $value;
    }

    return [
        'id' => (string) ($input['campaign_id'] ?? ''),
        'name' => trim((string) ($input['name'] ?? '')),
        'tracking_code' => trim((string) ($input['tracking_code'] ?? '')),
        'type' => $type,
        'primary_goal' => $primaryGoal,
        'channels' => $channels,
        'intent_note' => trim((string) ($input['intent_note'] ?? '')),
        'target_areas' => trim((string) ($input['target_areas'] ?? '')),
        'target_persona' => trim((string) ($input['target_persona'] ?? '')),
        'total_budget_rs' => trim((string) ($input['total_budget_rs'] ?? '')),
        'budget_period' => trim((string) ($input['budget_period'] ?? '')),
        'budget_notes' => trim((string) ($input['budget_notes'] ?? '')),
        'start_date' => (string) ($input['start_date'] ?? ''),
        'end_date' => (string) ($input['end_date'] ?? ''),
        'ai_brief' => trim((string) ($input['ai_brief'] ?? '')),
        'ai_strategy_summary' => trim((string) ($input['ai_strategy_summary'] ?? '')),
        'ai_budget_allocation' => trim((string) ($input['ai_budget_allocation'] ?? '')),
        'ai_online_creatives' => $onlineCreatives,
        'ai_offline_creatives' => $offlineCreatives,
        'status' => $status,
        'channel_assets' => $channelAssets,
        'media_assets' => isset($input['media_assets']) && is_array($input['media_assets']) ? $input['media_assets'] : [],
        'meta_ads_settings' => $metaAdsSettings,
        'meta_manual_guide' => trim((string) ($input['meta_manual_guide'] ?? '')),
    ];
}

function smart_marketing_parse_ai(string $text): array
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $section = 'summary';
    $summaryLines = [];
    $online = [];
    $offline = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $lower = strtolower($trimmed);
        if (strpos($lower, '###') === 0) {
            if (strpos($lower, 'strategy') !== false) {
                $section = 'summary';
                continue;
            }
            if (strpos($lower, 'online') !== false) {
                $section = 'online';
                continue;
            }
            if (strpos($lower, 'offline') !== false) {
                $section = 'offline';
                continue;
            }
        }

        $value = ltrim($trimmed, '-• ');
        if ($section === 'online') {
            $online[] = $value;
            continue;
        }
        if ($section === 'offline') {
            $offline[] = $value;
            continue;
        }
        $summaryLines[] = $trimmed;
    }

    $summary = trim(implode("\n", $summaryLines));
    if ($summary === '') {
        $summary = $text;
    }

    return [
        'strategy' => $summary,
        'online' => $online,
        'offline' => $offline,
    ];
}

function smart_marketing_match_channel(string $heading, array $channels): ?string
{
    $labels = marketing_channels_labels();
    $headingSlug = smart_marketing_slug($heading);
    foreach ($channels as $channel) {
        $options = [$channel, $labels[$channel] ?? $channel];
        foreach ($options as $option) {
            if ($headingSlug === smart_marketing_slug($option)) {
                return $channel;
            }
            if (str_contains($headingSlug, smart_marketing_slug($option))) {
                return $channel;
            }
        }
    }

    return null;
}

function smart_marketing_assign_channel_field(array &$output, string $channel, string $fieldKey, string $value, string $type): void
{
    if (!isset($output[$channel])) {
        $output[$channel] = [];
    }

    if ($type === 'list') {
        $output[$channel][$fieldKey] = $output[$channel][$fieldKey] ?? [];
        if (trim($value) !== '') {
            $output[$channel][$fieldKey][] = trim($value);
        }
    } else {
        $existing = trim((string) ($output[$channel][$fieldKey] ?? ''));
        $output[$channel][$fieldKey] = $existing === '' ? trim($value) : $existing . "\n" . trim($value);
    }
}

function smart_marketing_parse_channel_assets(string $text, array $channels): array
{
    $definitions = smart_marketing_channel_asset_definitions();
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $currentChannel = null;
    $currentField = null;
    $parsed = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^#+\s*(.+)$/', $trimmed, $matches)) {
            $maybeChannel = smart_marketing_match_channel((string) ($matches[1] ?? ''), $channels);
            if ($maybeChannel !== null) {
                $currentChannel = $maybeChannel;
                $currentField = null;
                continue;
            }
        }

        if ($currentChannel === null) {
            continue;
        }

        $fieldDefinitions = $definitions[$currentChannel] ?? [];
        if (str_contains($trimmed, ':')) {
            [$label, $rest] = array_pad(explode(':', $trimmed, 2), 2, '');
            $labelSlug = smart_marketing_slug($label);
            foreach ($fieldDefinitions as $fieldKey => $meta) {
                if ($labelSlug === smart_marketing_slug($meta['label'])) {
                    $currentField = $fieldKey;
                    if (trim($rest) !== '') {
                        smart_marketing_assign_channel_field($parsed, $currentChannel, $fieldKey, (string) $rest, $meta['type']);
                    }
                    continue 2;
                }
            }
        }

        if ($currentField !== null && isset($fieldDefinitions[$currentField])) {
            $meta = $fieldDefinitions[$currentField];
            $value = ltrim($trimmed, "-*•\t ");
            smart_marketing_assign_channel_field($parsed, $currentChannel, $currentField, $value, $meta['type']);
            continue;
        }

        // Fallback: push into the first field if nothing is set
        foreach ($fieldDefinitions as $fieldKey => $meta) {
            $value = ltrim($trimmed, "-*•\t ");
            smart_marketing_assign_channel_field($parsed, $currentChannel, $fieldKey, $value, $meta['type']);
            $currentField = $fieldKey;
            break;
        }
    }

    return $parsed;
}

function smart_marketing_parse_offline_copy(string $text): array
{
    $fields = [
        'headline' => '',
        'subheadline' => '',
        'body_text' => '',
        'offer_line' => '',
        'contact_block' => '',
    ];

    $lines = preg_split('/\r?\n/', $text) ?: [];
    $currentField = null;
    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }

        if (str_contains($trimmed, ':')) {
            [$label, $rest] = array_pad(explode(':', $trimmed, 2), 2, '');
            $slug = smart_marketing_slug($label);
            foreach (array_keys($fields) as $key) {
                if ($slug === smart_marketing_slug($key)) {
                    $currentField = $key;
                    $fields[$key] = trim((string) $rest);
                    continue 2;
                }
            }
        }

        if ($currentField !== null) {
            $fields[$currentField] = trim($fields[$currentField] . "\n" . $trimmed);
        }
    }

    if (trim($fields['headline']) === '' && trim($text) !== '') {
        $fields['headline'] = mb_substr(trim($text), 0, 120);
    }

    return $fields;
}

function smart_marketing_generate_simple_id(string $prefix): string
{
    $random = random_int(100, 999);
    return sprintf('%s_%s_%d', $prefix, (new DateTimeImmutable('now'))->format('Ymd_His'), $random);
}

function smart_marketing_task_categories(): array
{
    return [
        'online' => 'Online',
        'offline' => 'Offline',
        'whatsapp' => 'WhatsApp',
        'creative' => 'Creative',
        'other' => 'Other',
    ];
}

function smart_marketing_task_statuses(): array
{
    return [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'done' => 'Done',
    ];
}

function smart_marketing_sum_performance(array $logs): array
{
    $totals = [
        'spend' => 0.0,
        'leads' => 0,
    ];

    foreach ($logs as $log) {
        $totals['spend'] += (float) ($log['spend_rs'] ?? 0);
        $totals['leads'] += (int) ($log['leads'] ?? 0);
    }

    $totals['cpl'] = $totals['leads'] > 0 ? $totals['spend'] / max(1, $totals['leads']) : null;
    return $totals;
}

function smart_marketing_filter_performance_logs(array $logs, string $timeEmphasis): array
{
    $timeEmphasis = in_array($timeEmphasis, ['30', '90', 'all'], true) ? $timeEmphasis : 'all';
    if ($timeEmphasis === 'all') {
        return $logs;
    }

    $days = (int) $timeEmphasis;
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify(sprintf('-%d days', $days))->setTime(0, 0);

    $filtered = [];
    foreach ($logs as $log) {
        $dateString = (string) ($log['date'] ?? '');
        if ($dateString === '') {
            $filtered[] = $log;
            continue;
        }

        try {
            $logDate = new DateTimeImmutable($dateString, new DateTimeZone('Asia/Kolkata'));
            if ($logDate >= $cutoff) {
                $filtered[] = $log;
            }
        } catch (Throwable $exception) {
            $filtered[] = $log;
        }
    }

    return $filtered;
}

function smart_marketing_campaign_performance(array $campaign, string $timeEmphasis = 'all'): array
{
    $logs = is_array($campaign['performance_logs'] ?? null) ? $campaign['performance_logs'] : [];
    $filteredLogs = smart_marketing_filter_performance_logs($logs, $timeEmphasis);
    $totals = smart_marketing_sum_performance($filteredLogs);

    return [
        'spend' => $totals['spend'],
        'leads' => $totals['leads'],
        'cpl' => $totals['cpl'],
        'logs' => $filteredLogs,
    ];
}

function smart_marketing_channel_list(array $channels): string
{
    $labels = marketing_channels_labels();
    $output = [];
    foreach ($channels as $channel) {
        $output[] = $labels[$channel] ?? $channel;
    }

    return implode(', ', $output);
}

function smart_marketing_collect_ai_context(array $input, array $campaigns): array
{
    $defaults = [
        'id' => '',
        'name' => '',
        'type' => 'online',
        'primary_goal' => 'Lead Generation',
        'channels' => [],
        'intent_note' => '',
        'target_areas' => '',
        'target_persona' => '',
        'total_budget_rs' => '',
        'budget_period' => '',
        'budget_notes' => '',
        'ai_brief' => '',
        'ai_strategy_summary' => '',
        'ai_budget_allocation' => '',
        'ai_online_creatives' => [],
        'ai_offline_creatives' => [],
        'channel_assets' => [],
        'meta_ads_settings' => [
            'objective' => '',
            'conversion_location' => '',
            'performance_goal' => '',
            'budget_strategy' => '',
            'audience_summary' => '',
            'placement_strategy' => '',
            'optimization_and_bidding' => '',
            'creative_recommendations' => '',
            'tracking_and_reporting' => '',
        ],
        'meta_manual_guide' => '',
    ];

    $context = $defaults;

    $campaignId = (string) ($input['campaign_id'] ?? '');
    if ($campaignId !== '') {
        $existing = marketing_campaign_find($campaigns, $campaignId);
        if (is_array($existing)) {
            $context = array_merge($context, $existing);
        }
    }

    $channelInput = $input['channels'] ?? ($context['channels'] ?? []);
    $channels = [];
    if (is_array($channelInput)) {
        foreach ($channelInput as $channel) {
            $channels[] = (string) $channel;
        }
    }
    $context['channels'] = $channels;

    foreach (['name', 'type', 'primary_goal', 'intent_note', 'target_areas', 'target_persona', 'total_budget_rs', 'budget_period', 'budget_notes', 'ai_brief', 'ai_strategy_summary', 'ai_budget_allocation'] as $fieldKey) {
        if (isset($input[$fieldKey])) {
            $context[$fieldKey] = trim((string) $input[$fieldKey]);
        }
    }

    foreach (['ai_online_creatives', 'ai_offline_creatives'] as $listKey) {
        if (isset($input[$listKey])) {
            $raw = is_array($input[$listKey]) ? implode("\n", $input[$listKey]) : (string) $input[$listKey];
            $items = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $clean = trim($line);
                if ($clean !== '') {
                    $items[] = $clean;
                }
            }
            $context[$listKey] = $items;
        }
    }

    if (isset($input['meta_ads_settings']) && is_array($input['meta_ads_settings'])) {
        foreach (array_keys($context['meta_ads_settings']) as $metaKey) {
            $context['meta_ads_settings'][$metaKey] = trim((string) ($input['meta_ads_settings'][$metaKey] ?? $context['meta_ads_settings'][$metaKey]));
        }
    }

    if (isset($input['meta_manual_guide'])) {
        $context['meta_manual_guide'] = trim((string) $input['meta_manual_guide']);
    }

    return $context;
}

function smart_marketing_ai_base_prompt(array $context): array
{
    $lines = [
        'You are the Smart Marketing CMO helping with campaigns.',
    ];

    $brandLines = smart_marketing_brand_context_lines();
    if (!empty($brandLines)) {
        $lines[] = '';
        $lines = array_merge($lines, $brandLines);
    }

    $lines[] = 'Generate only the requested content without extra headings unless explicitly asked.';
    $lines[] = 'Campaign Name: ' . ($context['name'] ?? '');
    $lines[] = 'Type: ' . ($context['type'] ?? '');
    $lines[] = 'Primary Goal: ' . ($context['primary_goal'] ?? '');
    $lines[] = 'Channels: ' . smart_marketing_channel_list($context['channels'] ?? []);
    $lines[] = 'Intent: ' . ($context['intent_note'] ?? '');
    $lines[] = 'Target Areas: ' . ($context['target_areas'] ?? '');
    $lines[] = 'Persona: ' . ($context['target_persona'] ?? '');
    $lines[] = 'Total Budget: ' . ($context['total_budget_rs'] ?? '');
    $lines[] = 'Budget Period: ' . ($context['budget_period'] ?? '');
    $lines[] = 'Budget notes: ' . ($context['budget_notes'] ?? '');
    $lines[] = 'AI brief: ' . ($context['ai_brief'] ?? '');

    return $lines;
}

function smart_marketing_meta_context_prompt(array $context, array $brandProfile = []): array
{
    $lines = ['You are the Smart Marketing CMO helping configure manual Meta Ads Manager campaigns.'];
    $brandLines = smart_marketing_brand_context_lines($brandProfile);
    if (!empty($brandLines)) {
        $lines[] = 'Brand details:';
        $lines[] = implode("\n", $brandLines);
    }

    $lines[] = 'Campaign Name: ' . ($context['name'] ?? '');
    $lines[] = 'Type: ' . ($context['type'] ?? '');
    $lines[] = 'Primary Goal: ' . ($context['primary_goal'] ?? '');
    $lines[] = 'Channels: ' . smart_marketing_channel_list($context['channels'] ?? []);
    $lines[] = 'Intent: ' . ($context['intent_note'] ?? '');
    $lines[] = 'Target Areas: ' . ($context['target_areas'] ?? '');
    $lines[] = 'Persona: ' . ($context['target_persona'] ?? '');
    $lines[] = 'Total Budget: ' . ($context['total_budget_rs'] ?? '');
    $lines[] = 'Budget Period: ' . ($context['budget_period'] ?? '');
    $lines[] = 'Budget notes: ' . ($context['budget_notes'] ?? '');
    if (!empty($context['ai_strategy_summary'])) {
        $lines[] = 'Existing strategy summary: ' . ($context['ai_strategy_summary'] ?? '');
    }
    if (!empty($context['ai_brief'])) {
        $lines[] = 'AI brief: ' . ($context['ai_brief'] ?? '');
    }

    return array_values(array_filter($lines, static fn($line) => trim((string) $line) !== ''));
}

function smart_marketing_meta_settings_lines(array $metaSettings): array
{
    $defaults = [
        'objective' => '',
        'conversion_location' => '',
        'performance_goal' => '',
        'budget_strategy' => '',
        'audience_summary' => '',
        'placement_strategy' => '',
        'optimization_and_bidding' => '',
        'creative_recommendations' => '',
        'tracking_and_reporting' => '',
    ];

    $merged = array_merge($defaults, is_array($metaSettings) ? $metaSettings : []);

    return [
        'Objective: ' . ($merged['objective'] ?? ''),
        'Conversion location: ' . ($merged['conversion_location'] ?? ''),
        'Performance goal: ' . ($merged['performance_goal'] ?? ''),
        'Budget strategy: ' . ($merged['budget_strategy'] ?? ''),
        'Audience summary: ' . ($merged['audience_summary'] ?? ''),
        'Placement strategy: ' . ($merged['placement_strategy'] ?? ''),
        'Optimization & bidding: ' . ($merged['optimization_and_bidding'] ?? ''),
        'Creative recommendations: ' . ($merged['creative_recommendations'] ?? ''),
        'Tracking & reporting: ' . ($merged['tracking_and_reporting'] ?? ''),
    ];
}

function smart_marketing_meta_first_list_value($value): string
{
    if (is_array($value)) {
        foreach ($value as $item) {
            $clean = trim((string) $item);
            if ($clean !== '') {
                return $clean;
            }
        }
    }

    $cleanValue = trim((string) $value);
    return $cleanValue;
}

function smart_marketing_meta_pick_image(array $mediaAssets): ?array
{
    foreach ($mediaAssets as $asset) {
        $channel = strtolower((string) ($asset['channel'] ?? ''));
        $useFor = strtolower((string) ($asset['use_for'] ?? ''));
        if ($channel === 'facebook_instagram' || $useFor === 'facebook_instagram' || $useFor === 'both') {
            if (!empty($asset['file_url'])) {
                return $asset;
            }
        }
    }

    foreach ($mediaAssets as $asset) {
        if (!empty($asset['file_url'])) {
            return $asset;
        }
    }

    return null;
}

function smart_marketing_meta_budget_proposal(array $campaign): array
{
    $totalBudget = (float) ($campaign['total_budget_rs'] ?? 0);
    $budgetPeriod = strtolower((string) ($campaign['budget_period'] ?? ''));
    if ($totalBudget <= 0) {
        return [
            'daily' => 1000.0,
            'note' => 'Using default daily budget as total budget is missing.',
        ];
    }

    if (strpos($budgetPeriod, 'week') !== false) {
        $daily = $totalBudget / 7;
    } elseif (strpos($budgetPeriod, 'day') !== false) {
        $daily = $totalBudget;
    } else {
        $daily = $totalBudget / 30;
    }

    return [
        'daily' => max(100.0, round($daily, 2)),
        'note' => 'Derived from total budget and period.',
    ];
}

function smart_marketing_meta_cta_type(string $ctaText): string
{
    $ctaText = strtolower(trim($ctaText));
    $mapping = [
        'learn more' => 'LEARN_MORE',
        'sign up' => 'SIGN_UP',
        'apply now' => 'APPLY_NOW',
        'book now' => 'BOOK_NOW',
        'contact us' => 'CONTACT_US',
        'get quote' => 'GET_QUOTE',
        'get offer' => 'GET_OFFER',
        'send message' => 'MESSAGE_PAGE',
        'shop now' => 'SHOP_NOW',
    ];

    foreach ($mapping as $label => $type) {
        if ($ctaText === $label) {
            return $type;
        }
    }

    return 'LEARN_MORE';
}

function smart_marketing_meta_destination(array $campaign, array $brandProfile): array
{
    $conversionLocation = strtolower((string) ($campaign['meta_ads_settings']['conversion_location'] ?? ''));
    $whatsappNumber = preg_replace('/\D+/', '', (string) ($brandProfile['whatsapp_number'] ?? ''));
    if ($conversionLocation !== '' && strpos($conversionLocation, 'whatsapp') !== false && $whatsappNumber !== '') {
        return [
            'type' => 'whatsapp',
            'url' => 'https://wa.me/' . $whatsappNumber,
        ];
    }

    $website = trim((string) ($brandProfile['website_url'] ?? ''));
    if ($website !== '') {
        return [
            'type' => 'website',
            'url' => $website,
        ];
    }

    return [
        'type' => 'none',
        'url' => '#',
    ];
}

function smart_marketing_meta_preview(array $campaign, array $brandProfile): array
{
    $campaign = smart_marketing_merge_meta_auto($campaign);
    $channelAssets = is_array($campaign['channel_assets']['facebook_instagram'] ?? null)
        ? $campaign['channel_assets']['facebook_instagram']
        : [];
    $primaryText = smart_marketing_meta_first_list_value($channelAssets['primary_texts'] ?? '');
    $headline = smart_marketing_meta_first_list_value($channelAssets['headlines'] ?? '');
    $description = smart_marketing_meta_first_list_value($channelAssets['descriptions'] ?? '');
    $ctaText = smart_marketing_meta_first_list_value($channelAssets['cta_suggestions'] ?? '');
    $destination = smart_marketing_meta_destination($campaign, $brandProfile);
    $objective = trim((string) ($campaign['meta_ads_settings']['objective'] ?? ''));
    $conversionLocation = strtolower((string) ($campaign['meta_ads_settings']['conversion_location'] ?? ''));
    if ($objective === '') {
        $objective = strpos($conversionLocation, 'whatsapp') !== false ? 'MESSAGES' : 'LEAD_GENERATION';
    }

    $audienceHints = [];
    $audienceSummary = (string) ($campaign['meta_ads_settings']['audience_summary'] ?? '');
    foreach (preg_split('/[,\n]+/', $audienceSummary) ?: [] as $interest) {
        $clean = trim($interest);
        if ($clean !== '') {
            $audienceHints[] = $clean;
        }
    }
    $audienceHints = array_slice($audienceHints, 0, 10);

    $budgetProposal = smart_marketing_meta_budget_proposal($campaign);
    $media = smart_marketing_meta_pick_image($campaign['media_assets'] ?? []);

    return [
        'campaign' => [
            'name' => (string) ($campaign['name'] ?? ''),
            'objective' => $objective,
            'special_ad_category' => '',
        ],
        'adset' => [
            'daily_budget' => $budgetProposal['daily'],
            'budget_note' => $budgetProposal['note'],
            'target_areas' => (string) ($campaign['target_areas'] ?? ''),
            'age_range' => '25 - 60 (default)',
            'gender' => 'All',
            'interests' => $audienceHints,
            'placements' => trim((string) ($campaign['meta_ads_settings']['placement_strategy'] ?? 'Automatic placements')),
            'start' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),
            'end' => '',
        ],
        'ad' => [
            'primary_text' => $primaryText,
            'headline' => $headline,
            'description' => $description,
            'cta' => $ctaText === '' ? 'Learn More' : $ctaText,
            'destination' => $destination,
            'image' => $media,
        ],
    ];
}

function smart_marketing_meta_api_post(string $url, array $params): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'cURL is not available on this server.'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    if ($curlError !== '') {
        return ['success' => false, 'message' => $curlError, 'http_code' => $httpCode, 'raw' => $response];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMessage = 'Unexpected response from Meta API.';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $errorMessage = (string) $decoded['error']['message'];
        }
        return ['success' => false, 'message' => $errorMessage, 'http_code' => $httpCode, 'raw' => $response];
    }

    return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
}

function smart_marketing_meta_validate_campaign(array $campaign): ?string
{
    $channelAssets = is_array($campaign['channel_assets']['facebook_instagram'] ?? null)
        ? $campaign['channel_assets']['facebook_instagram']
        : [];
    $primaryText = smart_marketing_meta_first_list_value($channelAssets['primary_texts'] ?? '');
    $headline = smart_marketing_meta_first_list_value($channelAssets['headlines'] ?? '');
    $media = smart_marketing_meta_pick_image($campaign['media_assets'] ?? []);

    if ($primaryText === '' || $headline === '') {
        return 'Please generate FB/IG primary text and headline assets before creating a Meta campaign.';
    }

    if (!$media || empty($media['file_url'])) {
        return 'Please generate at least one FB/IG image asset before creating a Meta campaign.';
    }

    return null;
}

function smart_marketing_meta_create_minimal(array $campaign, array $brandProfile, array $settings): array
{
    $accessToken = trim((string) ($settings['meta_access_token'] ?? ''));
    $adAccountId = trim((string) ($settings['ad_account_id'] ?? ''));
    $pageId = trim((string) ($settings['default_page_id'] ?? ''));
    if ($accessToken === '' || $adAccountId === '' || $pageId === '') {
        return ['success' => false, 'message' => 'Meta settings are incomplete. Please provide access token, ad account ID, and default page ID.'];
    }

    $validationError = smart_marketing_meta_validate_campaign($campaign);
    if ($validationError !== null) {
        return ['success' => false, 'message' => $validationError];
    }

    $campaign = smart_marketing_merge_meta_auto($campaign);
    $baseUrl = 'https://graph.facebook.com/v18.0';
    $channelAssets = is_array($campaign['channel_assets']['facebook_instagram'] ?? null)
        ? $campaign['channel_assets']['facebook_instagram']
        : [];
    $primaryText = smart_marketing_meta_first_list_value($channelAssets['primary_texts'] ?? '');
    $headline = smart_marketing_meta_first_list_value($channelAssets['headlines'] ?? '');
    $description = smart_marketing_meta_first_list_value($channelAssets['descriptions'] ?? '');
    $ctaText = smart_marketing_meta_first_list_value($channelAssets['cta_suggestions'] ?? '');
    $ctaType = smart_marketing_meta_cta_type($ctaText === '' ? 'Learn More' : $ctaText);
    $destination = smart_marketing_meta_destination($campaign, $brandProfile);
    $objective = trim((string) ($campaign['meta_ads_settings']['objective'] ?? ''));
    $conversionLocation = strtolower((string) ($campaign['meta_ads_settings']['conversion_location'] ?? ''));
    if ($objective === '') {
        $objective = strpos($conversionLocation, 'whatsapp') !== false ? 'MESSAGES' : 'LEAD_GENERATION';
    }

    $audienceHints = [];
    $audienceSummary = (string) ($campaign['meta_ads_settings']['audience_summary'] ?? '');
    foreach (preg_split('/[,\n]+/', $audienceSummary) ?: [] as $interest) {
        $clean = trim($interest);
        if ($clean !== '') {
            $audienceHints[] = $clean;
        }
    }
    $audienceHints = array_slice($audienceHints, 0, 8);

    $budgetProposal = smart_marketing_meta_budget_proposal($campaign);
    $dailyBudgetPaise = (int) round($budgetProposal['daily'] * 100);
    if ($dailyBudgetPaise <= 0) {
        $dailyBudgetPaise = 100000; // ₹1000 fallback
    }

    $targeting = [
        'geo_locations' => ['countries' => ['IN']],
        'publisher_platforms' => ['facebook', 'instagram'],
        'facebook_positions' => ['feed', 'marketplace', 'story', 'video_feeds'],
        'instagram_positions' => ['stream', 'story', 'reels', 'explore'],
        'age_min' => 25,
        'age_max' => 60,
    ];

    if (!empty($audienceHints)) {
        $targeting['flexible_spec'] = [[
            'interests' => array_map(static fn($interest) => ['name' => $interest], $audienceHints),
        ]];
    }

    $campaignName = (string) ($campaign['name'] ?? 'Smart Marketing Campaign');

    $campaignCreate = smart_marketing_meta_api_post(
        $baseUrl . '/act_' . urlencode($adAccountId) . '/campaigns',
        [
            'name' => $campaignName,
            'objective' => $objective,
            'status' => 'PAUSED',
            'special_ad_categories' => '[]',
            'access_token' => $accessToken,
        ]
    );

    if (empty($campaignCreate['success'])) {
        return ['success' => false, 'message' => 'Error creating campaign in Meta: ' . ($campaignCreate['message'] ?? 'Unknown error')];
    }

    $metaCampaignId = (string) ($campaignCreate['data']['id'] ?? '');
    if ($metaCampaignId === '') {
        return ['success' => false, 'message' => 'Meta campaign ID missing from response.'];
    }

    $startTime = (new DateTimeImmutable('+5 minutes', new DateTimeZone('Asia/Kolkata')))->format(DateTime::ATOM);
    $adsetCreate = smart_marketing_meta_api_post(
        $baseUrl . '/act_' . urlencode($adAccountId) . '/adsets',
        [
            'name' => $campaignName . ' - Ad Set 1',
            'campaign_id' => $metaCampaignId,
            'daily_budget' => $dailyBudgetPaise,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $objective === 'MESSAGES' ? 'REPLIES' : 'LEAD_GENERATION',
            'targeting' => json_encode($targeting),
            'start_time' => $startTime,
            'status' => 'PAUSED',
            'access_token' => $accessToken,
        ]
    );

    if (empty($adsetCreate['success'])) {
        return ['success' => false, 'message' => 'Error creating ad set in Meta: ' . ($adsetCreate['message'] ?? 'Unknown error')];
    }

    $metaAdsetId = (string) ($adsetCreate['data']['id'] ?? '');
    if ($metaAdsetId === '') {
        return ['success' => false, 'message' => 'Meta ad set ID missing from response.'];
    }

    $media = smart_marketing_meta_pick_image($campaign['media_assets'] ?? []);
    $imageUpload = smart_marketing_meta_api_post(
        $baseUrl . '/act_' . urlencode($adAccountId) . '/adimages',
        [
            'access_token' => $accessToken,
            'url' => (string) ($media['file_url'] ?? ''),
        ]
    );

    if (empty($imageUpload['success'])) {
        return ['success' => false, 'message' => 'Error uploading image to Meta: ' . ($imageUpload['message'] ?? 'Unknown error')];
    }

    $imageHash = '';
    if (is_array($imageUpload['data']['images'] ?? null)) {
        foreach ($imageUpload['data']['images'] as $imgData) {
            $imageHash = (string) ($imgData['hash'] ?? '');
            if ($imageHash !== '') {
                break;
            }
        }
    }

    if ($imageHash === '') {
        return ['success' => false, 'message' => 'Unable to retrieve uploaded image hash from Meta.'];
    }

    $objectStorySpec = [
        'page_id' => $pageId,
        'link_data' => [
            'message' => $primaryText,
            'name' => $headline,
            'description' => $description,
            'link' => $destination['url'],
            'image_hash' => $imageHash,
            'call_to_action' => [
                'type' => $ctaType,
                'value' => ['link' => $destination['url']],
            ],
        ],
    ];

    $igActor = trim((string) ($settings['default_ig_account_id'] ?? ''));
    if ($igActor !== '') {
        $objectStorySpec['instagram_actor_id'] = $igActor;
    }

    $creativeCreate = smart_marketing_meta_api_post(
        $baseUrl . '/act_' . urlencode($adAccountId) . '/adcreatives',
        [
            'name' => $campaignName . ' - Creative 1',
            'object_story_spec' => json_encode($objectStorySpec),
            'access_token' => $accessToken,
        ]
    );

    if (empty($creativeCreate['success'])) {
        return ['success' => false, 'message' => 'Error creating ad creative in Meta: ' . ($creativeCreate['message'] ?? 'Unknown error')];
    }

    $creativeId = (string) ($creativeCreate['data']['id'] ?? '');
    if ($creativeId === '') {
        return ['success' => false, 'message' => 'Meta creative ID missing from response.'];
    }

    $adCreate = smart_marketing_meta_api_post(
        $baseUrl . '/act_' . urlencode($adAccountId) . '/ads',
        [
            'name' => $campaignName . ' - Ad 1',
            'adset_id' => $metaAdsetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status' => 'PAUSED',
            'access_token' => $accessToken,
        ]
    );

    if (empty($adCreate['success'])) {
        return ['success' => false, 'message' => 'Error creating ad in Meta: ' . ($adCreate['message'] ?? 'Unknown error')];
    }

    $metaAdId = (string) ($adCreate['data']['id'] ?? '');
    if ($metaAdId === '') {
        return ['success' => false, 'message' => 'Meta ad ID missing from response.'];
    }

    return [
        'success' => true,
        'meta_campaign_id' => $metaCampaignId,
        'meta_adset_id' => $metaAdsetId,
        'meta_ad_id' => $metaAdId,
    ];
}

$insightsScope = is_string($_GET['scope'] ?? null) ? (string) $_GET['scope'] : 'all';
if ($insightsScope === '') {
    $insightsScope = 'all';
}

$brandProfileError = null;
$brandProfile = load_brand_profile($brandProfileError);
$insightsFocus = [];
$insightsTimeEmphasis = 'all';
$insightsNotes = '';
$aiInsightsOutput = '';
$metaIntegrationSettings = load_meta_integration_settings();
$metaTestResult = null;
$metaPreviewCampaignId = '';
$metaPreviewData = null;
$metaPreviewError = '';
$metaAutoCreationResult = null;
$insightsFocusOptions = [
    'overall_strategy' => 'Overall strategy',
    'online_campaigns' => 'Online campaigns',
    'offline_campaigns' => 'Offline campaigns',
    'whatsapp_followup' => 'WhatsApp & follow-up',
    'budget_cpl' => 'Budget & cost per lead',
];

$message = '';
$tone = 'info';
$formData = [
    'id' => '',
    'name' => '',
    'tracking_code' => '',
    'type' => 'online',
    'primary_goal' => 'Lead Generation',
    'channels' => [],
    'intent_note' => '',
    'target_areas' => '',
    'target_persona' => '',
    'total_budget_rs' => '',
    'budget_period' => '',
    'budget_notes' => '',
    'start_date' => '',
    'end_date' => '',
    'ai_brief' => '',
    'ai_strategy_summary' => '',
    'ai_budget_allocation' => '',
    'ai_online_creatives' => [],
    'ai_offline_creatives' => [],
    'status' => 'draft',
    'channel_assets' => [],
    'whatsapp_packs' => [],
    'offline_assets' => [],
    'media_assets' => [],
    'execution_tasks' => [],
    'performance_logs' => [],
    'last_ai_insights' => '',
    'last_ai_insights_at' => '',
    'meta_ads_settings' => [
        'objective' => '',
        'conversion_location' => '',
        'performance_goal' => '',
        'budget_strategy' => '',
        'audience_summary' => '',
        'placement_strategy' => '',
        'optimization_and_bidding' => '',
        'creative_recommendations' => '',
        'tracking_and_reporting' => '',
    ],
    'meta_manual_guide' => '',
    'meta_auto' => smart_marketing_meta_auto_defaults(),
];

$selectedCampaignId = is_string($_GET['campaign_id'] ?? null) ? (string) $_GET['campaign_id'] : '';
if ($selectedCampaignId !== '') {
    $existing = marketing_campaign_find($campaigns, $selectedCampaignId);
    if ($existing) {
        $formData = array_merge($formData, $existing);
    }
}

foreach (['channel_assets', 'whatsapp_packs', 'offline_assets', 'media_assets', 'performance_logs', 'execution_tasks'] as $arrayKey) {
    if (!isset($formData[$arrayKey]) || !is_array($formData[$arrayKey])) {
        $formData[$arrayKey] = [];
    }
}

if (!isset($formData['meta_ads_settings']) || !is_array($formData['meta_ads_settings'])) {
    $formData['meta_ads_settings'] = [
        'objective' => '',
        'conversion_location' => '',
        'performance_goal' => '',
        'budget_strategy' => '',
        'audience_summary' => '',
        'placement_strategy' => '',
        'optimization_and_bidding' => '',
        'creative_recommendations' => '',
        'tracking_and_reporting' => '',
    ];
}

$formData['meta_ads_settings'] = array_merge([
    'objective' => '',
    'conversion_location' => '',
    'performance_goal' => '',
    'budget_strategy' => '',
    'audience_summary' => '',
    'placement_strategy' => '',
    'optimization_and_bidding' => '',
    'creative_recommendations' => '',
    'tracking_and_reporting' => '',
], $formData['meta_ads_settings']);

if (!isset($formData['meta_manual_guide']) || !is_string($formData['meta_manual_guide'])) {
    $formData['meta_manual_guide'] = '';
}

$formData = smart_marketing_merge_meta_auto($formData);

if ($insightsScope !== 'all' && marketing_campaign_find($campaigns, $insightsScope) === null) {
    $insightsScope = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_action'])) {
    header('Content-Type: application/json');

    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh and try again.']);
        exit;
    }

    $aiAction = (string) ($_POST['ai_action'] ?? '');

    try {
        $settings = ai_settings_load();
        if (trim((string) ($settings['api_key'] ?? '')) === '') {
            throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
        }

        if (in_array($aiAction, ['gen_fb_image', 'gen_wa_image'], true)) {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $imagePrompt = trim((string) ($_POST['image_prompt'] ?? ''));
            $channelKey = $aiAction === 'gen_fb_image' ? 'facebook_instagram' : 'whatsapp_broadcast';

            if ($campaignId === '' || !$campaigns || !marketing_campaign_find($campaigns, $campaignId)) {
                echo json_encode(['success' => false, 'error' => 'Campaign not found.']);
                exit;
            }

            if ($imagePrompt === '') {
                echo json_encode(['success' => false, 'error' => 'Please provide an image prompt.']);
                exit;
            }

            $campaignContext = marketing_campaign_find($campaigns, $campaignId) ?: [];
            $brandProfile = load_brand_profile();
            $promptParts = [];
            $promptParts[] = 'You are generating a promotional image for a solar EPC firm.';
            $brandLines = smart_marketing_brand_context_lines($brandProfile);
            if (!empty($brandLines)) {
                $promptParts[] = implode("\n", $brandLines);
            }

            $campaignLines = [];
            if (!empty($campaignContext['name'])) {
                $campaignLines[] = '- Campaign name: ' . (string) $campaignContext['name'];
            }
            if (!empty($campaignContext['primary_goal'])) {
                $campaignLines[] = '- Goal: ' . (string) $campaignContext['primary_goal'];
            }
            if (!empty($campaignContext['channels'])) {
                $campaignLines[] = '- Channels: ' . smart_marketing_channel_list((array) $campaignContext['channels']);
            }
            if (!empty($campaignContext['intent_note'])) {
                $campaignLines[] = '- Intent: ' . (string) $campaignContext['intent_note'];
            }

            if (!empty($campaignLines)) {
                $promptParts[] = 'Campaign context:';
                $promptParts[] = implode("\n", $campaignLines);
            }

            $promptParts[] = 'Now create an image based on this prompt:';
            $promptParts[] = '"' . $imagePrompt . '"';
            $promptParts[] = 'Focus on making it suitable for ' . ($channelKey === 'facebook_instagram' ? 'Facebook/Instagram ads' : 'WhatsApp Broadcast visuals') . '.';

            $finalPrompt = implode("\n", array_filter($promptParts, static fn($line) => trim((string) $line) !== ''));

            try {
                $imageResult = ai_gemini_generate_image_binary($settings, $finalPrompt);
            } catch (Throwable $exception) {
                error_log('smart_marketing: image generation failed: ' . $exception->getMessage());
                echo json_encode(['success' => false, 'error' => 'Unable to generate image. Please try again.']);
                exit;
            }

            $mediaId = smart_marketing_generate_simple_id('media');
            $extension = smart_marketing_media_extension($imageResult['mimeType'] ?? '');
            $dir = smart_marketing_media_campaign_dir($campaignId);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                echo json_encode(['success' => false, 'error' => 'Unable to prepare storage for generated image.']);
                exit;
            }

            $fileName = $mediaId . '.' . $extension;
            $filePath = $dir . '/' . $fileName;
            if (file_put_contents($filePath, $imageResult['binary']) === false) {
                echo json_encode(['success' => false, 'error' => 'Unable to save generated image.']);
                exit;
            }

            $fileUrl = smart_marketing_media_campaign_url($campaignId, $fileName);
            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $asset = [
                'id' => $mediaId,
                'title' => 'Generated ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i'),
                'use_for' => $channelKey,
                'file_url' => $fileUrl,
                'source_prompt' => $imagePrompt,
                'channel' => $channelKey,
                'notes' => 'Generated by AI image tool',
                'created_at' => $now,
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['media_assets']) || !is_array($campaignRef['media_assets'])) {
                        $campaignRef['media_assets'] = [];
                    }
                    $campaignRef['media_assets'][] = $asset;
                    $campaignRef['updated_at'] = $now;
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);

            echo json_encode([
                'success' => true,
                'media_id' => $mediaId,
                'file_url' => $fileUrl,
                'thumbnail_url' => $fileUrl,
                'source_prompt' => $imagePrompt,
                'channel' => $channelKey,
            ]);
            exit;
        }

        $metaActions = [
            'gen_meta_objective',
            'gen_meta_conversion_location',
            'gen_meta_performance_goal',
            'gen_meta_budget_strategy',
            'gen_meta_audience_summary',
            'gen_meta_placement_strategy',
            'gen_meta_optimization_bidding',
            'gen_meta_creative_reco',
            'gen_meta_tracking_notes',
            'gen_meta_setup_guide',
        ];

        if (in_array($aiAction, $metaActions, true)) {
            $context = smart_marketing_collect_ai_context($_POST, $campaigns);
            $campaignId = (string) ($context['id'] ?? ($_POST['campaign_id'] ?? ''));
            if ($campaignId === '' || !marketing_campaign_find($campaigns, $campaignId)) {
                echo json_encode(['success' => false, 'error' => 'Campaign not found. Save the campaign first.']);
                exit;
            }

            $brandProfile = load_brand_profile();
            $promptLines = smart_marketing_meta_context_prompt($context, $brandProfile);
            $budgetTotal = trim((string) ($context['total_budget_rs'] ?? ''));
            $budgetPeriod = trim((string) ($context['budget_period'] ?? ''));
            $metaSettings = is_array($context['meta_ads_settings'] ?? null) ? $context['meta_ads_settings'] : [];

            switch ($aiAction) {
                case 'gen_meta_objective':
                    $promptLines[] = "Suggest the best Meta campaign Objective for this campaign. Respond with ONLY the name of one objective, in Meta Ads Manager terms (like ‘Leads’, ‘Traffic’, ‘Engagement’). No explanation, no extra text.";
                    break;
                case 'gen_meta_conversion_location':
                    $promptLines[] = "Suggest the best ‘Conversion location’ for a Meta Leads campaign for this context (examples: ‘WhatsApp’, ‘Website’, ‘Instant forms (Facebook)’). Respond with ONLY the exact phrase to use, no explanations.";
                    break;
                case 'gen_meta_performance_goal':
                    $promptLines[] = "Suggest the best ‘Performance goal’ for this Meta campaign (for example ‘Maximize leads’, ‘Maximize number of messaging conversations’, etc.). Respond with ONLY one short phrase. No explanation.";
                    break;
                case 'gen_meta_budget_strategy':
                    $budgetLine = $budgetTotal !== '' ? $budgetTotal : 'not provided';
                    $budgetNote = $budgetPeriod !== '' ? " ({$budgetPeriod})" : '';
                    $promptLines[] = "The admin’s total budget is ₹{$budgetLine}{$budgetNote}. Propose a clear budget strategy: daily or lifetime, recommended daily/lifetime amount, and simple note on pacing. Respond in 3–5 short lines. No headings, no explanations beyond those lines.";
                    break;
                case 'gen_meta_audience_summary':
                    $promptLines[] = "Describe the ideal Meta Ads audience for this campaign, including locations (city/district/localities), age range, any gender bias if applicable, and 5–10 interest/behavior ideas. Respond with 6–12 bullet-style lines, each one short and precise. No paragraphs, no headings.";
                    break;
                case 'gen_meta_placement_strategy':
                    $promptLines[] = "Suggest the Meta placements strategy (Automatic or Manual) and, if Manual, which placements to prioritize (Feed, Stories, Reels, etc.) for this campaign. Respond with 3–6 short lines. No headings.";
                    break;
                case 'gen_meta_optimization_bidding':
                    $promptLines[] = "Suggest how to set Optimization & Delivery for this Meta campaign: which optimization event, whether to use Advantage campaign budget, and any bid cap or cost per result strategy if applicable. Respond in 3–6 short, direct lines. No long explanations.";
                    break;
                case 'gen_meta_creative_reco':
                    $promptLines[] = "Suggest the most suitable creative formats for this Meta campaign (single image, carousel, Reels, video), and how many variations to create. Respond in 3–6 lines, each line a concise recommendation. No headings.";
                    break;
                case 'gen_meta_tracking_notes':
                    $promptLines[] = "Suggest what the admin should monitor in Meta Ads Manager for this campaign: key metrics, how frequently to check, and simple rules of thumb (like when to pause or scale). Respond in 4–8 short lines. No headings, no extra explanations.";
                    break;
                case 'gen_meta_setup_guide':
                    $promptLines[] = 'Meta settings we have decided:';
                    $promptLines = array_merge($promptLines, smart_marketing_meta_settings_lines($metaSettings));
                    $promptLines[] = '';
                    $promptLines[] = "You are a marketing CMO helping a non-marketing founder set up a Meta (Facebook/Instagram) ad campaign in Ads Manager.";
                    $promptLines[] = "Create a detailed, easy-to-follow, step-by-step guide that explains EXACTLY what to click and choose in Meta Ads Manager to implement this campaign.";
                    $promptLines[] = "Output format (strict):";
                    $promptLines[] = "Step 1: [Short heading for step]";
                    $promptLines[] = "* [1–3 short bullet lines]";
                    $promptLines[] = "Step 2: …";
                    $promptLines[] = "* …";
                    $promptLines[] = "Continue until the campaign, ad set, and ad level are fully configured (objective, budget, schedule, audience, placements, optimization, and creative upload).";
                    $promptLines[] = "Constraints:";
                    $promptLines[] = "* Do NOT talk about theory. Only focus on where to click and what to select.";
                    $promptLines[] = "* Use simple language (for a non-marketing person).";
                    $promptLines[] = "* Be generous with steps, but each bullet must be short and precise.";
                    $promptLines[] = "* No intro or outro paragraphs; start directly from “Step 1: …”";
                    $promptLines[] = "* Do NOT mention that you are an AI; just give the guide.";
                    break;
            }

            $prompt = implode("\n", array_filter($promptLines, static fn($line) => trim((string) $line) !== ''));
            $timeout = $aiAction === 'gen_meta_setup_guide' ? 60 : 40;
            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => $timeout]);
            echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
            exit;
        }

        $context = smart_marketing_collect_ai_context($_POST, $campaigns);
        $promptLines = smart_marketing_ai_base_prompt($context);
        $budgetTotal = trim((string) ($context['total_budget_rs'] ?? ''));
        $budgetPeriod = trim((string) ($context['budget_period'] ?? ''));
        $budgetLine = $budgetTotal !== '' ? ('Admin-fixed total budget: ₹' . $budgetTotal . ($budgetPeriod !== '' ? ' (' . $budgetPeriod . ')' : '') . '. Do NOT change this amount.') : '';
        $budgetContextNote = $budgetTotal !== '' ? ('Note: Total budget for this campaign is ₹' . $budgetTotal . ($budgetPeriod !== '' ? ' (' . $budgetPeriod . ')' : '') . '. Keep the creative ideas suitable for this spend level.') : '';
        if ($budgetLine !== '') {
            $promptLines[] = $budgetLine;
        }

        $actionPrompts = [
            'gen_target_areas' => "Generate precise Target Areas for this solar campaign. \nOutput ONLY 4–7 objective entries, each on a separate line. \nEntries must be specific names of localities, clusters, zones, or industrial belts relevant to the campaign’s geographic scope.\n\nNO explanations.\nNO paragraphs.\nNO filler text.\nNO headings.\nONLY the list of areas, one per line.",
            'gen_target_persona' => "Generate an objective Target Persona for this campaign. \nOutput ONLY concise bullet-style lines (4–8 lines).  \nEach line = ONE clear trait (demographic, financial, behavioral, need-based).\n\nExamples of structure (do NOT include the word 'Example'):\n- \"Homeowners with ₹X–₹Y monthly electricity bills\"\n- \"Factory owners needing rooftop solar to reduce OPEX\"\n- \"Middle/upper-middle income families eligible for subsidy\"\n\nNO paragraphs.\nNO stories.\nNO long explanations.\nNO headings.\nONLY compact bullet-style lines, one per line.",
            'gen_ai_brief' => 'Respond ONLY with the AI brief text for this campaign, in 1–2 paragraphs or 4–6 bullet points. No headings, no explanations.',
            'gen_strategy_summary' => 'Write a concise strategy summary for this solar campaign. Respond ONLY with the strategy, without headings or meta comments. Prefer 1–3 short paragraphs.',
            'gen_online_creatives_summary' => 'Suggest 5–10 concise online creative ideas for this campaign (ad angles, hooks, CTAs). Respond ONLY with one idea per line, no headings.',
            'gen_offline_creatives_summary' => 'Suggest 5–10 concise offline creative ideas (hoardings, pamphlets, local events, etc.). Respond ONLY with one idea per line, no headings.',
            'gen_fb_primary_texts' => 'Focus on Facebook/Instagram ads. Generate 2–5 primary ad texts, each 1–3 sentences. Respond ONLY with one option per line, no headings, numbering, or explanations.',
            'gen_fb_headlines' => 'Focus on Facebook/Instagram ads. Generate 3–6 short headlines, each max 7–8 words. Respond ONLY with one headline per line, no headings, numbering, or explanations.',
            'gen_fb_descriptions' => 'Focus on Facebook/Instagram ads. Generate 2–4 short descriptions, each 1–2 sentences. Respond ONLY with one description per line, no headings, numbering, or explanations.',
            'gen_fb_ctas' => 'Focus on Facebook/Instagram ads. Generate 3–6 CTA phrases, each 2–5 words. Respond ONLY with one CTA per line, no headings, numbering, or explanations.',
            'gen_fb_media_concepts' => "Based on the brand and campaign context below, generate ONE concise visual concept for a Facebook/Instagram ad creative for this solar campaign in Jharkhand.\n\nOutput rules:\n\nRespond with ONLY ONE sentence describing what the image should visually show.\nNo headings.\nNo numbering.\nNo bullet points.\nNo extra options or explanations.\nOnly that single sentence.",
            'gen_fb_media_prompts' => "Generate ONE detailed image prompt for a Facebook/Instagram ad creative for this solar campaign in Jharkhand.\nThe prompt should be suitable for an image generator or designer and include setting, subject, style, and mood.\n\nOutput rules:\n\nRespond with ONLY ONE complete prompt (1–3 sentences).\nNo headings.\nNo numbering.\nNo extra options or explanations.\nOnly that single prompt.",
            'gen_search_headlines' => 'Generate 5–10 Google Search Ad headlines for this solar campaign. Each max 30 characters if possible. Respond ONLY with one headline per line, no headings, no numbering, no explanations.',
            'gen_search_descriptions' => 'Generate 3–6 Google Search Ad descriptions for this solar campaign. Each 1–2 sentences, optimized for click-through. Respond ONLY with one description per line, no headings, no numbering, no explanations.',
            'gen_search_keywords' => 'Generate 10–20 relevant Google Search keywords for this solar rooftop campaign in Jharkhand. Include mix of exact, phrase and broad-like terms, but output each keyword as plain text on its own line. Respond ONLY with the keywords, one per line, no headings, no numbering, no explanations.',
            'gen_yt_hooks' => 'Generate 5–10 short YouTube video hook lines for this solar campaign (first 3–5 seconds). Each max 10–12 words. Respond ONLY with one hook line per line, no headings, no numbering, no explanations.',
            'gen_yt_script' => 'Write a concise YouTube video script for this solar campaign, 60–90 seconds long. Use a simple structure: hook, problem, solution, offer, CTA. Respond ONLY with the script text, no headings or explanations.',
            'gen_yt_thumbnail_text' => 'Generate 5–10 short YouTube thumbnail text ideas (2–5 words each) for this solar campaign. Respond ONLY with one idea per line, no headings, no numbering, no explanations.',
            'gen_wa_short' => 'Generate 3–7 short WhatsApp promotional messages for this solar campaign. Each message should be 1–2 sentences, suitable for cold or warm outreach. Respond ONLY with one message per paragraph or line, no headings, no numbering, no explanations.',
            'gen_wa_long' => 'Generate 2–4 longer WhatsApp messages for this solar campaign, each 3–5 sentences, for detailed explanation and soft selling. Respond ONLY with one long message per block/paragraph, no headings, no numbering, no explanations.',
            'gen_wa_media_concepts' => "Generate ONE concise visual concept for an image to attach with a WhatsApp broadcast message for this solar campaign.\n\nOutput rules:\n\nRespond with ONLY ONE short sentence describing the visual.\nNo headings, no numbering, no extra options or explanations.",
            'gen_wa_media_prompts' => "Generate ONE detailed image prompt for a WhatsApp-friendly creative (vertical or square) for this solar campaign.\nThe prompt should specify main elements, any text on image, and general style.\n\nOutput rules:\n\nRespond with ONLY ONE complete prompt (1–3 sentences).\nNo headings.\nNo numbering.\nNo extra options or explanations.\nOnly that single prompt.",
            'gen_offline_hoarding_headlines' => 'Generate 5–10 strong, short headlines for an outdoor hoarding/banner for this solar campaign. Focus on impact and readability from a distance. Each headline max 6–8 words. Respond ONLY with one headline per line, no headings, no numbering, no explanations.',
            'gen_offline_hoarding_body' => 'Generate 3–6 concise body text options for outdoor hoardings for this solar campaign. Each 1–3 short lines, easy to read quickly. Respond ONLY with one option per block/line, no headings, no numbering, no explanations.',
            'gen_offline_hoarding_taglines' => 'Generate 5–10 short memorable taglines (2–6 words each) for this solar brand in Jharkhand. Respond ONLY with one tagline per line, no headings, no numbering, no explanations.',
            'gen_pamphlet_front' => 'Write concise content for the FRONT side of a pamphlet for this solar campaign. Include a strong headline, 2–3 short benefit bullets, and a simple CTA. Respond ONLY with the text content as it should appear, with line breaks. No headings, no explanations.',
            'gen_pamphlet_back' => 'Write content for the BACK side of a pamphlet for this solar campaign. Include more details: how it works, subsidy, installation process, and a CTA. Keep it short and scannable with short paragraphs or bullets. Respond ONLY with the text content as it should appear, with line breaks. No headings, no explanations.',
            'gen_newspaper_ad' => 'Write a short newspaper ad copy for this solar campaign in Jharkhand. About 60–100 words, including a strong headline and a clear CTA. Respond ONLY with the ad copy text (with line breaks if needed), no headings, no explanations.',
            'gen_radio_script' => 'Write a 20–30 second radio script for this solar campaign, including announcer lines and any sound cues. Keep it simple and natural. Respond ONLY with the script text, no headings, no explanations.',
        ];

        if ($aiAction === 'gen_budget_notes') {
            $prompt = implode("\n", $promptLines);
            if ($budgetTotal !== '') {
                $prompt .= "\nThe admin has already decided the total budget: ₹{$budgetTotal}" . ($budgetPeriod !== '' ? " ({$budgetPeriod})" : '') . ".";
                $prompt .= "\nDo NOT change this amount.";
                $prompt .= "\nGenerate crisp Budget Notes for this campaign.";
                $prompt .= "\nOutput ONLY 3–5 short lines.";
                $prompt .= "\nEach line = a direct recommendation for how to use THIS budget across channels and time.";
                $prompt .= "\nYou can mention rupee splits or % splits, but the total must still be ₹{$budgetTotal}.";
                $prompt .= "\nNO paragraphs.";
                $prompt .= "\nNO explanations.";
                $prompt .= "\nNO headings.";
                $prompt .= "\nONLY 3–5 clean, actionable lines.";
            } else {
                $prompt .= "\nGenerate crisp Budget Notes for this campaign.\nOutput ONLY 3–5 short lines.\nEach line = a direct budget recommendation with ₹ ranges or % allocations.\n\nStructure:\n- Clear recommendation\n- Numbers included (₹ or %)\n- No soft language\n\nNO paragraphs.\nNO explanations.\nNO headings.\nONLY 3–5 clean, actionable lines.";
            }

            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 40]);
            echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
            exit;
        }

        if ($aiAction === 'gen_budget_allocation_plan') {
            if ($budgetTotal === '') {
                echo json_encode(['success' => false, 'error' => 'Please set Total Budget for this campaign before generating a Budget Allocation Plan.']);
                exit;
            }

            $prompt = implode("\n", $promptLines);
            $prompt .= "\nThe admin has decided the total budget for this campaign is ₹{$budgetTotal}" . ($budgetPeriod !== '' ? " ({$budgetPeriod})" : '') . ".";
            $prompt .= "\nDo NOT change this total amount.";
            $selectedChannels = smart_marketing_channel_list($context['channels'] ?? []);
            $prompt .= "\nChannels selected for this campaign: " . ($selectedChannels !== '' ? $selectedChannels : 'None specified');
            $prompt .= "\nGoal: " . ($context['primary_goal'] ?? '');
            $prompt .= "\nTarget areas/persona: " . trim(($context['target_areas'] ?? '') . ' | ' . ($context['target_persona'] ?? ''));
            if (!empty($context['ai_strategy_summary'])) {
                $prompt .= "\nStrategy summary: " . $context['ai_strategy_summary'];
            }
            if (!empty($context['ai_brief'])) {
                $prompt .= "\nAI brief: " . $context['ai_brief'];
            }
            $prompt .= "\n\nCreate a clear budget allocation plan, using exactly this total budget.";
            $prompt .= "\n\nOutput format (and stick to it strictly):\n\nTotal budget: ₹{$budgetTotal}" . ($budgetPeriod !== '' ? " ({$budgetPeriod})" : '') . "\n\nChannel-wise allocation:\n\n* Channel name: ₹X (Y%)\n\nTime split (if relevant):\n\n* Week 1: …\n* Week 2: …\n\nNotes:\n\n* Short bullet recommendations (max 3 lines).\n\nConstraints:\n\n* Total of all rupee amounts must equal ₹{$budgetTotal}.\n* Be objective and to-the-point.\n* NO additional intros or explanations outside this structure.\n* NO headings other than exactly those in the format above.\n* NO marketing theory, ONLY allocation and brief notes.";

            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 50]);
            echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
            exit;
        }

        $channelSpecificActionSets = [
            'facebook_instagram' => [
                'label' => 'Channel: Facebook/Instagram ads only.',
                'actions' => ['gen_fb_primary_texts', 'gen_fb_headlines', 'gen_fb_descriptions', 'gen_fb_ctas', 'gen_fb_media_concepts', 'gen_fb_media_prompts'],
            ],
            'google_search' => [
                'label' => 'Channel: Google Search ads only.',
                'actions' => ['gen_search_headlines', 'gen_search_descriptions', 'gen_search_keywords'],
            ],
            'youtube_video' => [
                'label' => 'Channel: YouTube Video ads/scripts only.',
                'actions' => ['gen_yt_hooks', 'gen_yt_script', 'gen_yt_thumbnail_text'],
            ],
            'whatsapp_broadcast' => [
                'label' => 'Channel: WhatsApp Broadcast messages only.',
                'actions' => ['gen_wa_short', 'gen_wa_long', 'gen_wa_media_concepts', 'gen_wa_media_prompts'],
            ],
            'offline_hoardings' => [
                'label' => 'Channel: Offline hoardings/banners only.',
                'actions' => ['gen_offline_hoarding_headlines', 'gen_offline_hoarding_body', 'gen_offline_hoarding_taglines'],
            ],
            'offline_pamphlets' => [
                'label' => 'Channel: Offline pamphlets/leaflets only.',
                'actions' => ['gen_pamphlet_front', 'gen_pamphlet_back'],
            ],
            'offline_newspaper_radio' => [
                'label' => 'Channel: Offline newspaper/radio only.',
                'actions' => ['gen_newspaper_ad', 'gen_radio_script'],
            ],
        ];

        foreach ($channelSpecificActionSets as $set) {
            if (in_array($aiAction, $set['actions'], true)) {
                $promptLines[] = '';
                $promptLines[] = $set['label'];
                if (!empty($context['ai_strategy_summary'])) {
                    $promptLines[] = 'Strategy summary: ' . $context['ai_strategy_summary'];
                }
                if (!empty($context['ai_brief'])) {
                    $promptLines[] = 'AI brief: ' . $context['ai_brief'];
                }
                if ($budgetContextNote !== '') {
                    $promptLines[] = $budgetContextNote;
                }
                $promptLines[] = $actionPrompts[$aiAction] ?? '';

                $prompt = implode("\n", $promptLines);
                $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 40]);
                echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
                exit;
            }
        }

        $channelActionPrefixes = [
            'facebook_instagram' => 'Facebook/Instagram',
            'google_search' => 'Google Search',
            'whatsapp_broadcast' => 'WhatsApp Broadcast',
            'youtube_video' => 'YouTube Video',
            'offline_hoardings' => 'Offline Hoardings',
            'offline_pamphlets' => 'Offline Pamphlets',
            'offline_newspaper_radio' => 'Offline Newspaper / Radio',
        ];

        if (isset($actionPrompts[$aiAction])) {
            $promptLines[] = '';
            if ($aiAction === 'gen_strategy_summary') {
                $promptLines[] = 'Use campaign context above to produce the concise strategy summary.';
            }
            if ($aiAction === 'gen_online_creatives_summary' && !empty($context['ai_strategy_summary'])) {
                $promptLines[] = 'Existing strategy summary: ' . $context['ai_strategy_summary'];
            }
            if ($aiAction === 'gen_offline_creatives_summary' && !empty($context['ai_strategy_summary'])) {
                $promptLines[] = 'Existing strategy summary: ' . $context['ai_strategy_summary'];
            }
            $promptLines[] = $actionPrompts[$aiAction];
            $prompt = implode("\n", $promptLines);
            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 40]);
            echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
            exit;
        }

        foreach ($channelActionPrefixes as $channelKey => $label) {
            if ($aiAction === 'gen_channel_' . $channelKey) {
                $promptLines[] = '';
                $promptLines[] = 'Focus channel: ' . $label . ' (' . $channelKey . ')';
                if (!empty($context['ai_strategy_summary'])) {
                    $promptLines[] = 'Strategy summary: ' . $context['ai_strategy_summary'];
                }
                if (!empty($context['ai_brief'])) {
                    $promptLines[] = 'AI brief: ' . $context['ai_brief'];
                }
                if ($budgetContextNote !== '') {
                    $promptLines[] = $budgetContextNote;
                }
                $promptLines[] = 'Generate concise assets for this channel. Limit each list to 3–6 items.';
                $promptLines[] = 'Respond ONLY with the asset text using simple headings and bullet lists.';
                if ($channelKey === 'facebook_instagram') {
                    $promptLines[] = 'Format:';
                    $promptLines[] = 'Primary Texts: - ...';
                    $promptLines[] = 'Headlines: - ...';
                    $promptLines[] = 'Descriptions: - ...';
                    $promptLines[] = 'CTAs: - ...';
                } elseif ($channelKey === 'google_search') {
                    $promptLines[] = 'Format with short lines: Headlines:, Descriptions:, Keywords:';
                } elseif ($channelKey === 'whatsapp_broadcast') {
                    $promptLines[] = 'Provide 3–6 short and long broadcast options with CTAs.';
                } elseif ($channelKey === 'youtube_video') {
                    $promptLines[] = 'Provide hook lines, a short script idea, and thumbnail text ideas.';
                } elseif ($channelKey === 'offline_hoardings') {
                    $promptLines[] = 'Provide headline options, body text ideas, and taglines for hoardings.';
                } elseif ($channelKey === 'offline_pamphlets') {
                    $promptLines[] = 'Provide concise front and back side copy points.';
                } elseif ($channelKey === 'offline_newspaper_radio') {
                    $promptLines[] = 'Provide a short newspaper copy and a compact radio script outline.';
                }

                $prompt = implode("\n", $promptLines);
                $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 50]);
                echo json_encode(['success' => true, 'content' => trim((string) $raw)]);
                exit;
            }
        }

        throw new RuntimeException('Unknown AI action requested.');
    } catch (Throwable $exception) {
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-smart-marketing.php');
        exit;
    }

    $tab = is_string($_POST['tab'] ?? null) ? strtolower((string) $_POST['tab']) : $tab;
    if (!in_array($tab, $allowedTabs, true)) {
        $tab = 'campaigns';
    }

    $action = (string) ($_POST['action'] ?? '');
    $formData = $formData ?? [];
    if (in_array($action, ['generate_ai', 'save_campaign', 'generate_channel_assets'], true)) {
        $formData = smart_marketing_collect_form($_POST);
        if ($formData['id'] !== '') {
            $existingMeta = marketing_campaign_find($campaigns, $formData['id']);
            if (is_array($existingMeta)) {
                $preserveKeys = ['media_assets', 'whatsapp_packs', 'offline_assets', 'performance_logs', 'execution_tasks', 'meta_auto'];
                foreach ($preserveKeys as $preserveKey) {
                    if (array_key_exists($preserveKey, $existingMeta)) {
                        $formData[$preserveKey] = $existingMeta[$preserveKey];
                    }
                }
                $formData = smart_marketing_merge_meta_auto(array_merge($existingMeta, $formData));
            }
        }
    }

    try {
        if ($tab === 'meta_auto') {
            $metaAction = is_string($_POST['meta_auto_action'] ?? null) ? (string) $_POST['meta_auto_action'] : '';

            if ($metaAction === 'save_settings') {
                $metaIntegrationSettings = [
                    'enabled' => isset($_POST['enabled']),
                    'meta_app_id' => trim((string) ($_POST['meta_app_id'] ?? '')),
                    'meta_app_secret' => trim((string) ($_POST['meta_app_secret'] ?? '')),
                    'meta_access_token' => trim((string) ($_POST['meta_access_token'] ?? '')),
                    'ad_account_id' => trim((string) ($_POST['ad_account_id'] ?? '')),
                    'business_id' => trim((string) ($_POST['business_id'] ?? '')),
                    'default_page_id' => trim((string) ($_POST['default_page_id'] ?? '')),
                    'default_ig_account_id' => trim((string) ($_POST['default_ig_account_id'] ?? '')),
                ];

                save_meta_integration_settings($metaIntegrationSettings);
                $message = 'Meta settings saved successfully.';
                $tone = 'success';
            } elseif ($metaAction === 'test_connection') {
                $settings = load_meta_integration_settings();
                $metaIntegrationSettings = $settings;

                $accessToken = trim((string) ($settings['meta_access_token'] ?? ''));
                $adAccountId = trim((string) ($settings['ad_account_id'] ?? ''));
                $adAccountIdForGraph = $adAccountId;
                if ($adAccountIdForGraph !== '' && strpos($adAccountIdForGraph, 'act_') !== 0) {
                    $adAccountIdForGraph = 'act_' . $adAccountIdForGraph;
                }

                if ($accessToken === '' || $adAccountId === '') {
                    $metaTestResult = [
                        'status' => 'error',
                        'message' => 'Please fill Access Token and Ad Account ID in Meta Settings before testing.',
                    ];
                } elseif (!function_exists('curl_init')) {
                    $metaTestResult = [
                        'status' => 'error',
                        'message' => 'Meta API connection failed.',
                        'details' => 'cURL is not available on this server.',
                    ];
                } else {
                    $url = 'https://graph.facebook.com/v18.0/' . urlencode($adAccountIdForGraph)
                        . '?fields=id,account_status,currency,name&access_token=' . urlencode($accessToken);

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);

                    if ($curlError !== '' || $httpCode !== 200) {
                        $data = json_decode((string) $response, true);
                        $errorMessage = 'Meta API connection failed.';
                        if (is_array($data)) {
                            $errorCode = isset($data['error']['code']) ? (int) $data['error']['code'] : null;
                            $errorSubcode = isset($data['error']['error_subcode']) ? (int) $data['error']['error_subcode'] : null;

                            if ($errorCode === 100 && $errorSubcode === 33) {
                                $errorMessage .= ' The Ad Account ID seems invalid or not accessible. Please ensure:'
                                    . '<br>- You have entered the correct Ad Account ID (numeric or with act_ prefix).'
                                    . '<br>- The access token has the required permissions (ads_read / ads_management).'
                                    . '<br>- The logged-in user has access to this ad account.';
                            }
                        }

                        $detailsParts = [];
                        if ($httpCode !== 0) {
                            $detailsParts[] = 'HTTP code: ' . $httpCode;
                        }
                        if ($curlError !== '') {
                            $detailsParts[] = 'cURL error: ' . $curlError;
                        }

                        $metaTestResult = [
                            'status' => 'error',
                            'message' => $errorMessage,
                            'details' => implode(' | ', array_filter($detailsParts)),
                            'response' => substr((string) $response, 0, 500),
                        ];
                    } else {
                        $data = json_decode((string) $response, true);
                        if (is_array($data) && isset($data['id'])) {
                            $metaTestResult = [
                                'status' => 'success',
                                'data' => [
                                    'id' => (string) ($data['id'] ?? ''),
                                    'name' => (string) ($data['name'] ?? ''),
                                    'currency' => (string) ($data['currency'] ?? ''),
                                    'account_status' => (string) ($data['account_status'] ?? ''),
                                ],
                            ];
                        } else {
                            $metaTestResult = [
                                'status' => 'error',
                                'message' => 'Meta API connection failed.',
                                'details' => 'Unexpected response from Meta API.',
                                'response' => substr((string) $response, 0, 500),
                            ];
                        }
                    }
                }
            } elseif (in_array($metaAction, ['sync_status', 'pause_all', 'start_all', 'change_budget'], true)) {
                $campaignId = (string) ($_POST['campaign_id'] ?? '');
                $metaIntegrationSettings = load_meta_integration_settings();

                if (empty($metaIntegrationSettings['enabled'])
                    || trim((string) ($metaIntegrationSettings['meta_access_token'] ?? '')) === ''
                    || trim((string) ($metaIntegrationSettings['ad_account_id'] ?? '')) === '') {
                    $message = 'Meta automation is not enabled or settings are incomplete.';
                    $tone = 'error';
                } elseif (!function_exists('curl_init')) {
                    $message = 'Meta API actions are unavailable because cURL is missing on this server.';
                    $tone = 'error';
                } else {
                    $campaign = marketing_campaign_find($campaigns, $campaignId);
                    if (!$campaign) {
                        $message = 'Campaign not found for Meta automation.';
                        $tone = 'error';
                    } else {
                        $campaign = smart_marketing_merge_meta_auto($campaign);
                        $metaAuto = $campaign['meta_auto'];

                        if ($metaAuto['meta_campaign_id'] === ''
                            || $metaAuto['meta_adset_id'] === ''
                            || $metaAuto['meta_ad_id'] === '') {
                            $message = 'This CMO campaign does not have linked Meta objects yet.';
                            $tone = 'error';
                        } else {
                            $accessToken = trim((string) ($metaIntegrationSettings['meta_access_token'] ?? ''));
                            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

                            if ($metaAction === 'sync_status') {
                                $campaignUrl = 'https://graph.facebook.com/v18.0/'
                                    . urlencode((string) $metaAuto['meta_campaign_id'])
                                    . '?fields=id,status,effective_status&access_token=' . urlencode($accessToken);
                                $adsetUrl = 'https://graph.facebook.com/v18.0/'
                                    . urlencode((string) $metaAuto['meta_adset_id'])
                                    . '?fields=id,status,effective_status,daily_budget&access_token=' . urlencode($accessToken);
                                $adUrl = 'https://graph.facebook.com/v18.0/'
                                    . urlencode((string) $metaAuto['meta_ad_id'])
                                    . '?fields=id,status,effective_status&access_token=' . urlencode($accessToken);

                                $campaignResponse = smart_marketing_meta_api_request($campaignUrl);
                                $adsetResponse = smart_marketing_meta_api_request($adsetUrl);
                                $adResponse = smart_marketing_meta_api_request($adUrl);

                                if (empty($campaignResponse['success'])
                                    || empty($adsetResponse['success'])
                                    || empty($adResponse['success'])) {
                                    $message = 'Failed to sync status from Meta (campaign/ad set/ad error).';
                                    $tone = 'error';
                                } else {
                                    $campaignStatus = (string) ($campaignResponse['data']['status']
                                        ?? $campaignResponse['data']['effective_status'] ?? '');
                                    $adsetStatus = (string) ($adsetResponse['data']['status']
                                        ?? $adsetResponse['data']['effective_status'] ?? '');
                                    $adStatus = (string) ($adResponse['data']['status']
                                        ?? $adResponse['data']['effective_status'] ?? '');
                                    $dailyBudget = $adsetResponse['data']['daily_budget'] ?? '';
                                    $dailyRs = is_numeric($dailyBudget) ? (string) round(((float) $dailyBudget) / 100, 2) : '';

                                    foreach ($campaigns as &$campaignRef) {
                                        if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                                            $campaignRef = smart_marketing_merge_meta_auto($campaignRef);
                                            $campaignRef['meta_auto']['last_known_campaign_status'] = $campaignStatus;
                                            $campaignRef['meta_auto']['last_known_adset_status'] = $adsetStatus;
                                            $campaignRef['meta_auto']['last_known_ad_status'] = $adStatus;
                                            $campaignRef['meta_auto']['last_known_daily_budget'] = $dailyRs;
                                            $campaignRef['meta_auto']['status_snapshot'] = smart_marketing_meta_status_snapshot(
                                                $campaignStatus,
                                                $adsetStatus,
                                                $adStatus,
                                                $dailyRs
                                            );
                                            $campaignRef['meta_auto']['last_status_sync'] = $now;
                                            $campaignRef['updated_at'] = $now;
                                            break;
                                        }
                                    }
                                    unset($campaignRef);

                                    marketing_campaigns_save($campaigns);
                                    $message = 'Status synced from Meta.';
                                    $tone = 'success';
                                }
                            } elseif ($metaAction === 'pause_all' || $metaAction === 'start_all') {
                                $targetStatus = $metaAction === 'pause_all' ? 'PAUSED' : 'ACTIVE';
                                $adPost = smart_marketing_meta_api_request(
                                    'https://graph.facebook.com/v18.0/' . urlencode((string) $metaAuto['meta_ad_id']),
                                    ['status' => $targetStatus, 'access_token' => $accessToken]
                                );
                                $adsetPost = smart_marketing_meta_api_request(
                                    'https://graph.facebook.com/v18.0/' . urlencode((string) $metaAuto['meta_adset_id']),
                                    ['status' => $targetStatus, 'access_token' => $accessToken]
                                );
                                $campaignPost = smart_marketing_meta_api_request(
                                    'https://graph.facebook.com/v18.0/' . urlencode((string) $metaAuto['meta_campaign_id']),
                                    ['status' => $targetStatus, 'access_token' => $accessToken]
                                );

                                if (empty($adPost['success']) || empty($adsetPost['success']) || empty($campaignPost['success'])) {
                                    $message = $metaAction === 'pause_all'
                                        ? 'Failed to pause all in Meta. Please check Ads Manager.'
                                        : 'Failed to start all in Meta. Please check Ads Manager.';
                                    $tone = 'error';
                                } else {
                                    foreach ($campaigns as &$campaignRef) {
                                        if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                                            $campaignRef = smart_marketing_merge_meta_auto($campaignRef);
                                            $campaignRef['meta_auto']['last_known_campaign_status'] = $targetStatus;
                                            $campaignRef['meta_auto']['last_known_adset_status'] = $targetStatus;
                                            $campaignRef['meta_auto']['last_known_ad_status'] = $targetStatus;
                                            $campaignRef['meta_auto']['status_snapshot'] = smart_marketing_meta_status_snapshot(
                                                $targetStatus,
                                                $targetStatus,
                                                $targetStatus,
                                                $campaignRef['meta_auto']['last_known_daily_budget']
                                            );
                                            $campaignRef['meta_auto']['last_status_sync'] = $now;
                                            $campaignRef['updated_at'] = $now;
                                            break;
                                        }
                                    }
                                    unset($campaignRef);

                                    marketing_campaigns_save($campaigns);
                                    $message = $metaAction === 'pause_all'
                                        ? 'Campaign, Ad Set, and Ad were paused in Meta.'
                                        : 'Campaign, Ad Set, and Ad were started in Meta (ACTIVE).';
                                    $tone = 'success';
                                }
                            } elseif ($metaAction === 'change_budget') {
                                $newDailyBudget = isset($_POST['new_daily_budget_rs']) ? trim((string) $_POST['new_daily_budget_rs']) : '';
                                $dailyRs = (float) $newDailyBudget;

                                if (!is_numeric($newDailyBudget) || $dailyRs <= 0) {
                                    $message = 'Please enter a valid daily budget in rupees.';
                                    $tone = 'error';
                                } else {
                                    $dailyPaise = (int) round($dailyRs * 100);
                                    $adsetPost = smart_marketing_meta_api_request(
                                        'https://graph.facebook.com/v18.0/' . urlencode((string) $metaAuto['meta_adset_id']),
                                        ['daily_budget' => $dailyPaise, 'access_token' => $accessToken]
                                    );

                                    if (empty($adsetPost['success'])) {
                                        $message = 'Failed to update daily budget in Meta. Please check Ads Manager and settings.';
                                        $tone = 'error';
                                    } else {
                                        foreach ($campaigns as &$campaignRef) {
                                            if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                                                $campaignRef = smart_marketing_merge_meta_auto($campaignRef);
                                                $campaignRef['meta_auto']['last_known_daily_budget'] = (string) $dailyRs;
                                                $campaignRef['meta_auto']['status_snapshot'] = smart_marketing_meta_status_snapshot(
                                                    (string) $campaignRef['meta_auto']['last_known_campaign_status'],
                                                    (string) $campaignRef['meta_auto']['last_known_adset_status'],
                                                    (string) $campaignRef['meta_auto']['last_known_ad_status'],
                                                    (string) $dailyRs
                                                );
                                                $campaignRef['meta_auto']['last_status_sync'] = $now;
                                                $campaignRef['updated_at'] = $now;
                                                break;
                                            }
                                        }
                                        unset($campaignRef);

                                        marketing_campaigns_save($campaigns);
                                        $message = 'Daily budget updated to ₹' . $dailyRs . ' in Meta.';
                                        $tone = 'success';
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif ($metaAction === 'fetch_insights') {
                    $campaignId = (string) ($_POST['campaign_id'] ?? '');
                    $period = (string) ($_POST['period'] ?? '7d');
                    $period = $period === '30d' ? '30d' : '7d';
                    $metaIntegrationSettings = load_meta_integration_settings();

                    if (empty($metaIntegrationSettings['enabled'])
                        || trim((string) ($metaIntegrationSettings['meta_access_token'] ?? '')) === ''
                        || trim((string) ($metaIntegrationSettings['ad_account_id'] ?? '')) === '') {
                        $message = 'Meta automation is not enabled or settings are incomplete.';
                        $tone = 'error';
                    } elseif (!function_exists('curl_init')) {
                        $message = 'Meta API actions are unavailable because cURL is missing on this server.';
                        $tone = 'error';
                    } else {
                        $campaign = marketing_campaign_find($campaigns, $campaignId);
                        if (!$campaign) {
                            $message = 'Campaign not found for Meta automation.';
                            $tone = 'error';
                        } else {
                            $campaign = smart_marketing_merge_meta_auto($campaign);
                            $metaAuto = $campaign['meta_auto'];

                            if ($metaAuto['meta_campaign_id'] === '') {
                                $message = 'This campaign is not linked to a Meta campaign yet.';
                                $tone = 'error';
                            } elseif ($metaAuto['meta_ad_id'] === '') {
                                $message = 'Meta Ad ID missing for insights fetch. Sync or recreate the Meta ad first.';
                                $tone = 'error';
                            } else {
                                $accessToken = trim((string) ($metaIntegrationSettings['meta_access_token'] ?? ''));
                                $endpoint = 'https://graph.facebook.com/v18.0/' . urlencode((string) $metaAuto['meta_ad_id']) . '/insights';
                                $params = [
                                    'access_token' => $accessToken,
                                    'date_preset' => $period === '30d' ? 'last_30d' : 'last_7d',
                                    'fields' => 'impressions,clicks,spend,inline_link_clicks,ctr,cpc,cpm,actions',
                                ];

                                $url = $endpoint . '?' . http_build_query($params);
                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                $response = curl_exec($ch);
                                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                $curlError = curl_error($ch);
                                curl_close($ch);

                                if ($curlError !== '' || $httpCode !== 200) {
                                    $message = 'Failed to fetch insights from Meta for this campaign.';
                                    $tone = 'error';
                                } else {
                                    $data = json_decode((string) $response, true);
                                    $insightsRow = null;
                                    if (is_array($data) && isset($data['data'][0]) && is_array($data['data'][0])) {
                                        $insightsRow = $data['data'][0];
                                    }

                                    if ($insightsRow === null) {
                                        $message = 'Meta insights response was empty for this campaign.';
                                        $tone = 'error';
                                    } else {
                                        $impressions = (int) ($insightsRow['impressions'] ?? 0);
                                        $clicks = (int) ($insightsRow['clicks'] ?? 0);
                                        $inlineLinkClicks = (int) ($insightsRow['inline_link_clicks'] ?? 0);
                                        $spend = (float) ($insightsRow['spend'] ?? 0);
                                        $ctr = (float) ($insightsRow['ctr'] ?? 0);
                                        $cpc = (float) ($insightsRow['cpc'] ?? 0);
                                        $cpm = (float) ($insightsRow['cpm'] ?? 0);
                                        $actions = is_array($insightsRow['actions'] ?? null) ? $insightsRow['actions'] : [];

                                        $summary = sprintf(
                                            'Period: last %s days | Impressions: %d | Clicks: %d | Spend: ₹%.2f | CTR: %.2f%% | CPC: ₹%.2f | CPM: ₹%.2f',
                                            $period === '30d' ? '30' : '7',
                                            $impressions,
                                            $clicks,
                                            $spend,
                                            $ctr,
                                            $cpc,
                                            $cpm
                                        );

                                        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

                                        foreach ($campaigns as &$campaignRef) {
                                            if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                                                $campaignRef = smart_marketing_merge_meta_auto($campaignRef);
                                                $campaignRef['meta_auto']['insights_period'] = $period;
                                                $campaignRef['meta_auto']['last_insights_sync'] = $now;
                                                $campaignRef['meta_auto']['last_insights_raw'] = [
                                                    'period' => $period,
                                                    'impressions' => $impressions,
                                                    'clicks' => $clicks,
                                                    'inline_link_clicks' => $inlineLinkClicks,
                                                    'spend' => $spend,
                                                    'ctr' => $ctr,
                                                    'cpc' => $cpc,
                                                    'cpm' => $cpm,
                                                    'actions' => $actions,
                                                ];
                                                $campaignRef['meta_auto']['last_insights_summary'] = $summary;
                                                $campaignRef['updated_at'] = $now;
                                                break;
                                            }
                                        }
                                        unset($campaignRef);

                                        marketing_campaigns_save($campaigns);
                                        $message = 'Meta insights updated successfully.';
                                        $tone = 'success';
                                    }
                                }
                            }
                        }
                    }
                } elseif ($metaAction === 'preview_payload') {
                    $metaPreviewCampaignId = (string) ($_POST['campaign_id'] ?? '');
                    $metaIntegrationSettings = load_meta_integration_settings();

                if (empty($metaIntegrationSettings['enabled'])
                    || trim((string) ($metaIntegrationSettings['meta_access_token'] ?? '')) === ''
                    || trim((string) ($metaIntegrationSettings['ad_account_id'] ?? '')) === '') {
                    $metaPreviewError = 'Please configure Meta settings and enable Meta automation before previewing.';
                } else {
                    $campaign = marketing_campaign_find($campaigns, $metaPreviewCampaignId);
                    if (!$campaign) {
                        $metaPreviewError = 'Campaign not found for preview.';
                    } else {
                        $metaPreviewData = smart_marketing_meta_preview($campaign, $brandProfile);
                        $metaPreviewCampaignId = (string) ($campaign['id'] ?? '');
                    }
                }
            } elseif ($metaAction === 'create_in_meta') {
                $campaignId = (string) ($_POST['campaign_id'] ?? '');
                $campaign = marketing_campaign_find($campaigns, $campaignId);
                $metaIntegrationSettings = load_meta_integration_settings();

                if (!$campaign) {
                    $message = 'Campaign not found for Meta automation.';
                    $tone = 'error';
                } elseif (!empty($campaign['meta_auto']['meta_campaign_id'])) {
                    $message = 'Meta campaign already created for this CMO campaign.';
                    $tone = 'warning';
                } elseif (empty($metaIntegrationSettings['enabled'])
                    || trim((string) ($metaIntegrationSettings['meta_access_token'] ?? '')) === ''
                    || trim((string) ($metaIntegrationSettings['ad_account_id'] ?? '')) === '') {
                    $message = 'Please configure Meta settings and enable automation before creating campaigns.';
                    $tone = 'error';
                } else {
                    $creation = smart_marketing_meta_create_minimal($campaign, $brandProfile, $metaIntegrationSettings);
                    if (empty($creation['success'])) {
                        $message = (string) ($creation['message'] ?? 'Meta creation failed.');
                        $tone = 'error';
                    } else {
                        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
                        foreach ($campaigns as &$campaignRef) {
                            if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                                $campaignRef['meta_auto'] = array_merge(
                                    smart_marketing_meta_auto_defaults(),
                                    [
                                        'enabled' => true,
                                        'meta_campaign_id' => (string) ($creation['meta_campaign_id'] ?? ''),
                                        'meta_adset_id' => (string) ($creation['meta_adset_id'] ?? ''),
                                        'meta_ad_id' => (string) ($creation['meta_ad_id'] ?? ''),
                                        'created_at' => $now,
                                        'last_status_sync' => '',
                                        'last_known_campaign_status' => 'PAUSED',
                                        'last_known_adset_status' => 'PAUSED',
                                        'last_known_ad_status' => 'PAUSED',
                                        'status_snapshot' => smart_marketing_meta_status_snapshot('PAUSED', 'PAUSED', 'PAUSED', ''),
                                    ]
                                );
                                $campaignRef['updated_at'] = $now;
                                $metaAutoCreationResult = $campaignRef['meta_auto'];
                                break;
                            }
                        }
                        unset($campaignRef);

                        marketing_campaigns_save($campaigns);
                        $message = 'Meta campaign created successfully.';
                        $tone = 'success';
                    }
                }
            } else {
                error_log('smart_marketing: unknown meta_auto_action: ' . $metaAction);
            }
        } elseif ($action === 'save_brand_profile') {
            $profile = smart_marketing_brand_profile_defaults();
            foreach (array_keys($profile) as $field) {
                $value = isset($_POST[$field]) ? (string) $_POST[$field] : '';
                $profile[$field] = trim(strip_tags($value));
            }

            save_brand_profile($profile);
            $brandProfile = $profile;
            set_flash('success', 'Brand profile saved successfully.');
            header('Location: admin-smart-marketing.php?tab=brand_profile');
            exit;
        }

        if ($action === 'generate_ai') {
            if ($formData['name'] === '' || empty($formData['channels']) || $formData['ai_brief'] === '') {
                throw new RuntimeException('Please enter the campaign name, select channels, and provide an AI brief.');
            }

            $settings = ai_settings_load();
            if (trim((string) ($settings['api_key'] ?? '')) === '') {
                throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
            }

            $brandContext = smart_marketing_brand_context_text($brandProfile);
            $promptParts = [
                'You are the Smart Marketing CMO helping with a marketing campaign.',
            ];

            if ($brandContext !== '') {
                $promptParts[] = '';
                $promptParts[] = $brandContext;
            }

            $promptParts[] = 'Create a concise campaign strategy with online and offline creative ideas. Use clear headings and bullet points.';
            $promptParts[] = '';
            $promptParts[] = 'Campaign Name: ' . $formData['name'];
            $promptParts[] = 'Type: ' . $formData['type'];
            $promptParts[] = 'Primary Goal: ' . $formData['primary_goal'];
            $promptParts[] = 'Channels: ' . smart_marketing_channel_list($formData['channels']);
            $promptParts[] = 'Target Areas: ' . $formData['target_areas'];
            $promptParts[] = 'Target Persona: ' . $formData['target_persona'];
            $promptParts[] = 'Total Budget: ' . $formData['total_budget_rs'];
            $promptParts[] = 'Budget Period: ' . $formData['budget_period'];
            $promptParts[] = 'Budget Notes: ' . $formData['budget_notes'];
            $promptParts[] = 'Start Date: ' . $formData['start_date'] . ' | End Date: ' . $formData['end_date'];
            $promptParts[] = 'Brief: ' . $formData['ai_brief'];
            $promptParts[] = '';
            $promptParts[] = 'Respond with sections: ';
            $promptParts[] = '### Strategy Summary';
            $promptParts[] = '### Online Creatives (bullets)';
            $promptParts[] = '### Offline Creatives (bullets)';

            $prompt = implode("\n", $promptParts);

            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 30]);
            $parsed = smart_marketing_parse_ai($raw);

            $formData['ai_strategy_summary'] = $parsed['strategy'] ?? $raw;
            $formData['ai_online_creatives'] = $parsed['online'] ?? [];
            $formData['ai_offline_creatives'] = $parsed['offline'] ?? [];

            $message = 'AI strategy and creatives generated. Review before saving.';
            $tone = 'success';
        } elseif ($action === 'generate_channel_assets') {
            if ($formData['name'] === '' || empty($formData['channels']) || $formData['ai_brief'] === '' || $formData['primary_goal'] === '') {
                throw new RuntimeException('Please provide campaign name, goal, channels, and AI brief before generating assets.');
            }

            $settings = ai_settings_load();
            if (trim((string) ($settings['api_key'] ?? '')) === '') {
                throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
            }

            $promptLines = [
                'You are the Smart Marketing CMO for this brand. Generate channel-specific assets for the selected channels.',
            ];

            $brandLines = smart_marketing_brand_context_lines($brandProfile);
            if (!empty($brandLines)) {
                $promptLines[] = '';
                $promptLines = array_merge($promptLines, $brandLines);
            }

            $promptLines = array_merge($promptLines, [
                'Generate channel-specific assets for the selected channels. Respond with markdown sections per channel using headings like ### facebook_instagram.',
                'Keep copy concise and actionable. Use bullet lists where relevant.',
                '',
                'Campaign context:',
                'Name: ' . $formData['name'],
                'Type: ' . $formData['type'],
                'Primary Goal: ' . $formData['primary_goal'],
                'Channels: ' . smart_marketing_channel_list($formData['channels']),
                'Target Areas: ' . $formData['target_areas'],
                'Persona: ' . $formData['target_persona'],
                'Budget notes: ' . $formData['budget_notes'],
                'AI brief: ' . $formData['ai_brief'],
            ]);

            if (trim((string) ($formData['ai_strategy_summary'] ?? '')) !== '') {
                $promptLines[] = 'Existing strategy summary: ' . $formData['ai_strategy_summary'];
            }

            $promptLines[] = '';
            $promptLines[] = 'For each channel, respond with headings and bullet lists using these labels:';
            $promptLines[] = '### facebook_instagram -> Primary Texts, Headlines, Descriptions, CTA Suggestions';
            $promptLines[] = '### google_search -> Headlines, Descriptions, Keywords';
            $promptLines[] = '### youtube_video -> Hook Lines, Video Script, Thumbnail Text Ideas';
            $promptLines[] = '### whatsapp_broadcast -> Short Messages, Long Messages';
            $promptLines[] = '### offline_hoardings -> Headlines, Body Texts, Taglines';
            $promptLines[] = '### offline_pamphlets -> Front Side Copy, Back Side Copy';
            $promptLines[] = '### offline_newspaper_radio -> Newspaper Ad Copy, Radio Script';

            $prompt = implode("\n", $promptLines);
            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 40]);
            $parsedAssets = smart_marketing_parse_channel_assets($raw, $formData['channels']);
            if (!empty($parsedAssets)) {
                $formData['channel_assets'] = $parsedAssets;
            }

            $message = 'Channel assets generated. Review and save to keep them.';
            $tone = 'success';
        } elseif ($action === 'generate_insights_ai') {
            $insightsScope = is_string($_POST['insights_scope'] ?? null) ? (string) $_POST['insights_scope'] : 'all';
            if ($insightsScope === '') {
                $insightsScope = 'all';
            }

            $timeInput = is_string($_POST['insights_time'] ?? null) ? (string) $_POST['insights_time'] : 'all';
            $insightsTimeEmphasis = in_array($timeInput, ['30', '90', 'all'], true) ? $timeInput : 'all';

            $focusInput = $_POST['insights_focus'] ?? [];
            if (!is_array($focusInput)) {
                $focusInput = [$focusInput];
            }
            $insightsFocus = array_values(array_intersect(array_map('strval', $focusInput), array_keys($insightsFocusOptions)));
            $insightsNotes = trim((string) ($_POST['insights_notes'] ?? ''));

            if (empty($campaigns)) {
                throw new RuntimeException('No campaigns yet. Add campaigns before generating insights.');
            }

            $targetCampaign = null;
            $campaignSet = $campaigns;
            if ($insightsScope !== 'all') {
                $targetCampaign = marketing_campaign_find($campaigns, $insightsScope);
                if ($targetCampaign === null) {
                    throw new RuntimeException('Selected campaign not found for insights.');
                }
                $campaignSet = [$targetCampaign];
            }

            $settings = ai_settings_load();
            if (trim((string) ($settings['api_key'] ?? '')) === '') {
                throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
            }

            $timeLabel = $insightsTimeEmphasis === '30' ? 'Last 30 days' : ($insightsTimeEmphasis === '90' ? 'Last 90 days' : 'All available logs');
            $focusLabels = [];
            foreach ($insightsFocus as $focusKey) {
                if (isset($insightsFocusOptions[$focusKey])) {
                    $focusLabels[] = $insightsFocusOptions[$focusKey];
                }
            }

            $promptLines = [
                'You are the Smart Marketing CMO for this brand.',
            ];

            $brandLines = smart_marketing_brand_context_lines($brandProfile);
            if (!empty($brandLines)) {
                $promptLines[] = '';
                $promptLines = array_merge($promptLines, $brandLines);
            }

            $promptLines = array_merge($promptLines, [
                'Use the provided campaign and performance data to produce prioritized, actionable recommendations.',
                'Avoid generic theory and focus on clear next steps the marketing team can implement.',
                'Scope: ' . ($insightsScope === 'all' ? 'All campaigns' : 'Single campaign'),
                'Time emphasis: ' . $timeLabel,
                'Focus areas: ' . (!empty($focusLabels) ? implode(', ', $focusLabels) : 'General performance and optimization'),
            ]);

            if ($insightsNotes !== '') {
                $promptLines[] = 'Additional context from admin: ' . $insightsNotes;
            }

            $promptLines[] = '';
            $promptLines[] = 'Campaign data:';

            foreach ($campaignSet as $campaign) {
                $performance = smart_marketing_campaign_performance($campaign, $insightsTimeEmphasis);
                $channelsList = smart_marketing_channel_list($campaign['channels'] ?? []);
                $promptLines[] = '- ' . ($campaign['name'] ?? '') . ' | Type: ' . ($campaign['type'] ?? '') . ' | Status: ' . ($campaign['status'] ?? '')
                    . ' | Goal: ' . ($campaign['primary_goal'] ?? '') . ' | Channels: ' . $channelsList
                    . ' | Spend ₹' . number_format((float) $performance['spend'], 2) . ' | Leads ' . (int) $performance['leads']
                    . ' | CPL ' . (($performance['cpl'] ?? null) !== null ? '₹' . number_format((float) $performance['cpl'], 2) : 'N/A');

                if ($insightsScope !== 'all') {
                    $promptLines[] = '  Target areas: ' . ($campaign['target_areas'] ?? '');
                    $promptLines[] = '  Target persona: ' . ($campaign['target_persona'] ?? '');
                    $promptLines[] = '  Budget notes: ' . ($campaign['budget_notes'] ?? '');
                    $promptLines[] = '  Strategy summary: ' . ($campaign['ai_strategy_summary'] ?? '');

                    $channelAssetCount = is_array($campaign['channel_assets'] ?? null) ? count($campaign['channel_assets']) : 0;
                    $whatsappCount = is_array($campaign['whatsapp_packs'] ?? null) ? count($campaign['whatsapp_packs']) : 0;
                    $offlineCount = is_array($campaign['offline_assets'] ?? null) ? count($campaign['offline_assets']) : 0;
                    $promptLines[] = '  Assets summary: channel assets for ' . $channelAssetCount . ' channels; WhatsApp packs: ' . $whatsappCount . '; offline assets: ' . $offlineCount . '.';

                    if (!empty($performance['logs'])) {
                        $promptLines[] = '  Recent performance logs:';
                        foreach (array_slice($performance['logs'], 0, 6) as $log) {
                            $promptLines[] = '    - ' . ($log['date'] ?? '') . ' | ' . (marketing_channels_labels()[$log['channel'] ?? ''] ?? ($log['channel'] ?? ''))
                                . ' | Leads: ' . (int) ($log['leads'] ?? 0) . ' | Spend ₹' . number_format((float) ($log['spend_rs'] ?? 0), 2)
                                . ' | Notes: ' . ($log['notes'] ?? '');
                        }
                    }
                }
            }

            $promptLines[] = '';
            $promptLines[] = 'Output format:';
            $promptLines[] = '### Overview';
            $promptLines[] = '### Online actions';
            $promptLines[] = '### Offline actions';
            $promptLines[] = '### WhatsApp & follow-up';
            $promptLines[] = '### Budget & optimization';
            $promptLines[] = 'Within each section, list prioritized, concrete actions tailored to the data (most important first).';

            $aiInsightsOutput = ai_gemini_generate_text($settings, implode("\n", $promptLines), ['timeout' => 60]);

            if ($targetCampaign !== null) {
                $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
                foreach ($campaigns as &$campaignRef) {
                    if ((string) ($campaignRef['id'] ?? '') === (string) $targetCampaign['id']) {
                        $campaignRef['last_ai_insights'] = $aiInsightsOutput;
                        $campaignRef['last_ai_insights_at'] = $now;
                        $campaignRef['updated_at'] = $now;
                        break;
                    }
                }
                unset($campaignRef);

                marketing_campaigns_save($campaigns);

                if ((string) ($formData['id'] ?? '') === (string) ($targetCampaign['id'] ?? '')) {
                    $formData['last_ai_insights'] = $aiInsightsOutput;
                    $formData['last_ai_insights_at'] = $now;
                    $formData['updated_at'] = $now;
                }
            }

            $message = 'AI insights generated successfully.';
            $tone = 'success';
        } elseif ($action === 'save_campaign') {
            if ($formData['name'] === '') {
                throw new RuntimeException('Campaign name is required.');
            }
            if (empty($formData['channels'])) {
                throw new RuntimeException('Select at least one channel.');
            }

            $formData = smart_marketing_merge_meta_auto($formData);
            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            if ($formData['id'] !== '' && ($existing = marketing_campaign_find($campaigns, $formData['id'])) !== null) {
                $preserveKeys = ['media_assets', 'whatsapp_packs', 'offline_assets', 'performance_logs', 'execution_tasks', 'meta_auto'];
                foreach ($preserveKeys as $preserveKey) {
                    if (array_key_exists($preserveKey, $existing)) {
                        $formData[$preserveKey] = $existing[$preserveKey];
                    }
                }
                $updated = smart_marketing_merge_meta_auto(array_merge($existing, $formData));
                $updated['created_at'] = $existing['created_at'] ?? $now;
                $updated['updated_at'] = $now;

                foreach ($campaigns as $idx => $campaign) {
                    if ((string) ($campaign['id'] ?? '') === (string) $formData['id']) {
                        $campaigns[$idx] = $updated;
                        break;
                    }
                }
                $selectedCampaignId = (string) $formData['id'];
            } else {
                $formData['id'] = marketing_campaign_generate_id($campaigns);
                $formData['created_at'] = $now;
                $formData['updated_at'] = $now;
                $campaigns[] = $formData;
                $selectedCampaignId = (string) $formData['id'];
            }

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Campaign saved successfully.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($selectedCampaignId));
            exit;
        } elseif ($action === 'generate_tracking_code') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Save the campaign before generating a tracking code.');
            }

            if (!empty($campaign['tracking_code'])) {
                set_flash('info', 'Tracking code already exists for this campaign.');
                header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#tracking');
                exit;
            }

            $code = smart_marketing_generate_tracking_code($campaigns, $campaignId);
            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $campaignRef['tracking_code'] = $code;
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Tracking code generated for this campaign.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#tracking');
            exit;
        } elseif ($action === 'generate_whatsapp_pack') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found for WhatsApp pack.');
            }

            $label = trim((string) ($_POST['pack_label'] ?? ''));
            $packType = trim((string) ($_POST['pack_type'] ?? 'Cold outreach'));
            $language = trim((string) ($_POST['pack_language'] ?? 'English'));
            $notes = trim((string) ($_POST['pack_notes'] ?? ''));

            if ($label === '') {
                throw new RuntimeException('Pack label is required.');
            }

            $settings = ai_settings_load();
            if (trim((string) ($settings['api_key'] ?? '')) === '') {
                throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
            }

            $brandContext = smart_marketing_brand_context_text($brandProfile);
            $promptParts = [
                'Generate a numbered list of 3 to 7 WhatsApp messages for a campaign. Each should be a single paragraph with a clear CTA.',
            ];

            if ($brandContext !== '') {
                $promptParts[] = $brandContext;
            }

            $promptParts[] = 'Campaign Goal: ' . $campaign['primary_goal'];
            $promptParts[] = 'Target areas: ' . $campaign['target_areas'];
            $promptParts[] = 'Persona: ' . $campaign['target_persona'];
            $promptParts[] = 'Pack label: ' . $label;
            $promptParts[] = 'Pack type: ' . $packType;
            $promptParts[] = 'Language preference: ' . $language;

            if ($notes !== '') {
                $promptParts[] = 'Additional notes: ' . $notes;
            }

            $promptParts[] = 'Only return the messages, numbered. Do not include any other commentary.';
            $prompt = implode("\n", $promptParts);

            $raw = ai_gemini_generate_text($settings, $prompt, ['timeout' => 30]);
            $messages = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $clean = trim((string) preg_replace('/^[0-9]+[\.\)]\s*/', '', $line));
                if ($clean !== '') {
                    $messages[] = $clean;
                }
            }

            if (empty($messages)) {
                throw new RuntimeException('AI response did not contain messages.');
            }

            $pack = [
                'id' => smart_marketing_generate_simple_id('wp'),
                'label' => $label,
                'pack_type' => $packType,
                'language' => $language,
                'notes' => $notes,
                'messages' => $messages,
                'created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['whatsapp_packs']) || !is_array($campaignRef['whatsapp_packs'])) {
                        $campaignRef['whatsapp_packs'] = [];
                    }
                    $campaignRef['whatsapp_packs'][] = $pack;
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'WhatsApp pack generated and saved.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId));
            exit;
        } elseif ($action === 'delete_whatsapp_pack') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $packId = (string) ($_POST['pack_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $updated = false;
            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $packs = $campaignRef['whatsapp_packs'] ?? [];
                    $campaignRef['whatsapp_packs'] = array_values(array_filter($packs, static fn($p) => (string) ($p['id'] ?? '') !== $packId));
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    $updated = true;
                    break;
                }
            }
            unset($campaignRef);

            if ($updated) {
                marketing_campaigns_save($campaigns);
                set_flash('success', 'WhatsApp pack deleted.');
                header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId));
                exit;
            }

            throw new RuntimeException('Unable to delete WhatsApp pack.');
        } elseif ($action === 'add_execution_task') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found for adding task.');
            }

            $title = trim((string) ($_POST['task_title'] ?? ''));
            $description = trim((string) ($_POST['task_description'] ?? ''));
            $category = strtolower((string) ($_POST['task_category'] ?? 'other'));
            $status = strtolower((string) ($_POST['task_status'] ?? 'pending'));
            $dueDate = trim((string) ($_POST['task_due_date'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Task title is required.');
            }

            $validCategories = array_keys(smart_marketing_task_categories());
            if (!in_array($category, $validCategories, true)) {
                $category = 'other';
            }

            $validStatuses = array_keys(smart_marketing_task_statuses());
            if (!in_array($status, $validStatuses, true)) {
                $status = 'pending';
            }

            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $task = [
                'id' => smart_marketing_generate_simple_id('task'),
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'status' => $status,
                'due_date' => $dueDate !== '' ? $dueDate : '',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['execution_tasks']) || !is_array($campaignRef['execution_tasks'])) {
                        $campaignRef['execution_tasks'] = [];
                    }
                    $campaignRef['execution_tasks'][] = $task;
                    $campaignRef['updated_at'] = $now;
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Task added successfully.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#execution-tasks');
            exit;
        } elseif ($action === 'edit_execution_task') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $taskId = (string) ($_POST['task_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found for editing task.');
            }

            $title = trim((string) ($_POST['task_title'] ?? ''));
            $description = trim((string) ($_POST['task_description'] ?? ''));
            $category = strtolower((string) ($_POST['task_category'] ?? 'other'));
            $status = strtolower((string) ($_POST['task_status'] ?? 'pending'));
            $dueDate = trim((string) ($_POST['task_due_date'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Task title is required.');
            }

            $validCategories = array_keys(smart_marketing_task_categories());
            if (!in_array($category, $validCategories, true)) {
                $category = 'other';
            }

            $validStatuses = array_keys(smart_marketing_task_statuses());
            if (!in_array($status, $validStatuses, true)) {
                $status = 'pending';
            }

            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $updated = false;

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $tasks = is_array($campaignRef['execution_tasks'] ?? null) ? $campaignRef['execution_tasks'] : [];
                    foreach ($tasks as &$taskRef) {
                        if ((string) ($taskRef['id'] ?? '') === $taskId) {
                            $taskRef['title'] = $title;
                            $taskRef['description'] = $description;
                            $taskRef['category'] = $category;
                            $taskRef['status'] = $status;
                            $taskRef['due_date'] = $dueDate !== '' ? $dueDate : '';
                            $taskRef['updated_at'] = $now;
                            $updated = true;
                            break;
                        }
                    }
                    unset($taskRef);
                    $campaignRef['execution_tasks'] = $tasks;
                    if ($updated) {
                        $campaignRef['updated_at'] = $now;
                    }
                    break;
                }
            }
            unset($campaignRef);

            if (!$updated) {
                throw new RuntimeException('Task not found for update.');
            }

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Task updated successfully.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#execution-tasks');
            exit;
        } elseif ($action === 'update_execution_task_status') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $taskId = (string) ($_POST['task_id'] ?? '');
            $status = strtolower((string) ($_POST['task_status'] ?? 'pending'));
            $selectedCampaignId = $campaignId;

            $validStatuses = array_keys(smart_marketing_task_statuses());
            if (!in_array($status, $validStatuses, true)) {
                $status = 'pending';
            }

            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $updated = false;

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $tasks = is_array($campaignRef['execution_tasks'] ?? null) ? $campaignRef['execution_tasks'] : [];
                    foreach ($tasks as &$taskRef) {
                        if ((string) ($taskRef['id'] ?? '') === $taskId) {
                            $taskRef['status'] = $status;
                            $taskRef['updated_at'] = $now;
                            $updated = true;
                            break;
                        }
                    }
                    unset($taskRef);
                    $campaignRef['execution_tasks'] = $tasks;
                    if ($updated) {
                        $campaignRef['updated_at'] = $now;
                    }
                    break;
                }
            }
            unset($campaignRef);

            if (!$updated) {
                throw new RuntimeException('Task not found for status update.');
            }

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Task status updated.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#execution-tasks');
            exit;
        } elseif ($action === 'delete_execution_task') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $taskId = (string) ($_POST['task_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $updated = false;

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $tasks = is_array($campaignRef['execution_tasks'] ?? null) ? $campaignRef['execution_tasks'] : [];
                    $filtered = array_values(array_filter($tasks, static fn($t) => (string) ($t['id'] ?? '') !== $taskId));
                    if (count($filtered) !== count($tasks)) {
                        $campaignRef['execution_tasks'] = $filtered;
                        $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                        $updated = true;
                    }
                    break;
                }
            }
            unset($campaignRef);

            if ($updated) {
                marketing_campaigns_save($campaigns);
                set_flash('success', 'Task deleted successfully.');
                header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#execution-tasks');
                exit;
            }

            throw new RuntimeException('Unable to delete task.');
        } elseif ($action === 'add_performance_log') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found.');
            }

            $date = (string) ($_POST['perf_date'] ?? '');
            $channel = (string) ($_POST['perf_channel'] ?? '');
            $notes = trim((string) ($_POST['perf_notes'] ?? ''));

            if ($date === '' || $channel === '') {
                throw new RuntimeException('Date and channel are required for performance log.');
            }

            $entry = [
                'id' => smart_marketing_generate_simple_id('perf'),
                'date' => $date,
                'channel' => $channel,
                'notes' => $notes,
                'impressions' => (int) ($_POST['perf_impressions'] ?? 0),
                'clicks' => (int) ($_POST['perf_clicks'] ?? 0),
                'leads' => (int) ($_POST['perf_leads'] ?? 0),
                'spend_rs' => (float) ($_POST['perf_spend'] ?? 0),
                'created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['performance_logs']) || !is_array($campaignRef['performance_logs'])) {
                        $campaignRef['performance_logs'] = [];
                    }
                    array_unshift($campaignRef['performance_logs'], $entry);
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Performance log added.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#performance');
            exit;
        } elseif ($action === 'delete_performance_log') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $logId = (string) ($_POST['log_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $updated = false;
            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $logs = $campaignRef['performance_logs'] ?? [];
                    $campaignRef['performance_logs'] = array_values(array_filter($logs, static fn($l) => (string) ($l['id'] ?? '') !== $logId));
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    $updated = true;
                    break;
                }
            }
            unset($campaignRef);

            if ($updated) {
                marketing_campaigns_save($campaigns);
                set_flash('success', 'Performance log deleted.');
                header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#performance');
                exit;
            }

            throw new RuntimeException('Unable to delete performance log.');
        } elseif ($action === 'generate_offline_asset') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found for offline asset.');
            }

            $assetType = strtolower(smart_marketing_slug((string) ($_POST['offline_type'] ?? '')));
            $title = trim((string) ($_POST['offline_title'] ?? ''));
            $dimension = trim((string) ($_POST['offline_dimension'] ?? ''));
            $useAiCopy = isset($_POST['offline_use_ai']) ? (bool) $_POST['offline_use_ai'] : false;

            if ($title === '') {
                throw new RuntimeException('Title is required for offline asset.');
            }

            $templates = smart_marketing_load_offline_templates();
            if (!isset($templates[$assetType])) {
                throw new RuntimeException('No template available for the selected offline asset type.');
            }

            $settings = ai_settings_load();
            if ($useAiCopy && trim((string) ($settings['api_key'] ?? '')) === '') {
                throw new RuntimeException('Gemini API key is missing. Configure it in AI Studio.');
            }

            $content = [
                'headline' => '',
                'subheadline' => '',
                'body_text' => '',
                'offer_line' => '',
                'contact_block' => '',
                'dimension_notes' => $dimension,
            ];

            if ($useAiCopy) {
                $brandContext = smart_marketing_brand_context_text($brandProfile);
                $promptParts = [
                    "Create concise offline marketing copy for a {$assetType}. Provide headline, subheadline, body_text, offer_line, contact_block.",
                ];

                if ($brandContext !== '') {
                    $promptParts[] = $brandContext;
                }

                $promptParts[] = 'Campaign goal: ' . $campaign['primary_goal'] . '.';
                $promptParts[] = 'Target areas: ' . $campaign['target_areas'] . '.';
                $promptParts[] = 'Persona: ' . $campaign['target_persona'] . '.';
                $promptParts[] = 'Title: ' . $title . '.';
                $promptParts[] = 'Dimensions/notes: ' . $dimension . '.';

                if (!empty($campaign['channel_assets'])) {
                    $promptParts[] = 'Use any relevant offline messaging cues from existing channel assets.';
                }

                $raw = ai_gemini_generate_text($settings, implode("\n", $promptParts), ['timeout' => 40]);
                $content = array_merge($content, smart_marketing_parse_offline_copy($raw));
            }

            $template = $templates[$assetType];
            $css = trim((string) ($template['default_css'] ?? ''));
            $layout = (string) ($template['html_layout'] ?? '');

            if ($layout === '') {
                throw new RuntimeException('Template layout missing for offline asset.');
            }

            $filled = smart_marketing_replace_placeholders($layout, $content);
            $html = '<!doctype html><html><head><meta charset="utf-8" />';
            if ($css !== '') {
                $html .= '<style>' . $css . '</style>';
            }
            $html .= '</head><body>' . $filled . '</body></html>';

            $dir = smart_marketing_offline_assets_dir();
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $assetId = smart_marketing_generate_simple_id('off');
            $htmlPath = $dir . '/' . $campaignId . '_' . $assetId . '.html';
            $pdfPath = $dir . '/' . $campaignId . '_' . $assetId . '.pdf';

            if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write offline asset HTML.');
            }

            handover_generate_pdf($html, $pdfPath);

            $record = [
                'id' => $assetId,
                'type' => $assetType,
                'title' => $title,
                'html_path' => 'data/marketing/offline_assets/' . basename($htmlPath),
                'pdf_path' => 'data/marketing/offline_assets/' . basename($pdfPath),
                'created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['offline_assets']) || !is_array($campaignRef['offline_assets'])) {
                        $campaignRef['offline_assets'] = [];
                    }
                    $campaignRef['offline_assets'][] = $record;
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Offline printable generated.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#offline');
            exit;
        } elseif ($action === 'delete_offline_asset') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $assetId = (string) ($_POST['asset_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $updated = false;
            $redirectTab = is_string($_POST['redirect_tab'] ?? null) ? strtolower(trim((string) $_POST['redirect_tab'])) : 'campaigns';
            if (!in_array($redirectTab, $allowedTabs, true)) {
                $redirectTab = 'campaigns';
            }
            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $assets = $campaignRef['offline_assets'] ?? [];
                    $campaignRef['offline_assets'] = array_values(array_filter($assets, static fn($a) => (string) ($a['id'] ?? '') !== $assetId));
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    $updated = true;
                    break;
                }
            }
            unset($campaignRef);

            if ($updated) {
                marketing_campaigns_save($campaigns);
                set_flash('success', 'Offline asset deleted.');
                $hash = $redirectTab === 'campaigns' ? '#offline' : '';
                header('Location: admin-smart-marketing.php?tab=' . rawurlencode($redirectTab) . '&campaign_id=' . rawurlencode($campaignId) . $hash);
                exit;
            }

            throw new RuntimeException('Unable to delete offline asset.');
        } elseif ($action === 'add_media_asset') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $campaign = marketing_campaign_find($campaigns, $campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campaign not found for adding media asset.');
            }

            $title = trim((string) ($_POST['media_title'] ?? ''));
            $useFor = strtolower(trim((string) ($_POST['media_use_for'] ?? '')));
            $fileUrl = trim((string) ($_POST['media_file_url'] ?? ''));
            $notes = trim((string) ($_POST['media_notes'] ?? ''));

            if ($title === '' || $fileUrl === '') {
                throw new RuntimeException('Title and File URL / Path are required for a media asset.');
            }

            $validUseFor = ['facebook_instagram', 'whatsapp_broadcast', 'both', 'other'];
            if (!in_array($useFor, $validUseFor, true)) {
                $useFor = 'other';
            }

            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $asset = [
                'id' => smart_marketing_generate_simple_id('media'),
                'title' => $title,
                'use_for' => $useFor,
                'file_url' => $fileUrl,
                'notes' => $notes,
                'created_at' => $now,
            ];

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    if (!isset($campaignRef['media_assets']) || !is_array($campaignRef['media_assets'])) {
                        $campaignRef['media_assets'] = [];
                    }
                    $campaignRef['media_assets'][] = $asset;
                    $campaignRef['updated_at'] = $now;
                    break;
                }
            }
            unset($campaignRef);

            marketing_campaigns_save($campaigns);
            set_flash('success', 'Media asset added successfully.');
            header('Location: admin-smart-marketing.php?tab=campaigns&campaign_id=' . rawurlencode($campaignId) . '#media-assets');
            exit;
        } elseif ($action === 'delete_media_asset') {
            $campaignId = (string) ($_POST['campaign_id'] ?? '');
            $assetId = (string) ($_POST['asset_id'] ?? '');
            $selectedCampaignId = $campaignId;
            $updated = false;
            $redirectTab = is_string($_POST['redirect_tab'] ?? null) ? strtolower(trim((string) $_POST['redirect_tab'])) : 'campaigns';
            if (!in_array($redirectTab, $allowedTabs, true)) {
                $redirectTab = 'campaigns';
            }

            foreach ($campaigns as &$campaignRef) {
                if ((string) ($campaignRef['id'] ?? '') === $campaignId) {
                    $assets = smart_marketing_campaign_media_assets($campaignRef);
                    $assetsAfter = [];
                    foreach ($assets as $asset) {
                        if ((string) ($asset['id'] ?? '') === $assetId) {
                            smart_marketing_delete_media_file_if_safe($asset);
                            continue;
                        }
                        $assetsAfter[] = $asset;
                    }
                    $campaignRef['media_assets'] = array_values($assetsAfter);
                    $campaignRef['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    $updated = true;
                    break;
                }
            }
            unset($campaignRef);

            if ($updated) {
                marketing_campaigns_save($campaigns);
                set_flash('success', 'Media asset deleted.');
                $hash = $redirectTab === 'campaigns' ? '#media-assets' : '';
                header('Location: admin-smart-marketing.php?tab=' . rawurlencode($redirectTab) . '&campaign_id=' . rawurlencode($campaignId) . $hash);
                exit;
            }

            throw new RuntimeException('Unable to delete media asset.');
        } else {
            throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $tone = 'error';
    }
}

$campaignCount = count($campaigns);
$draftCount = count(array_filter($campaigns, static fn($c) => ($c['status'] ?? 'draft') === 'draft'));
$activeCount = count(array_filter($campaigns, static fn($c) => in_array($c['status'] ?? '', ['planned', 'running'], true)));
$completedCount = count(array_filter($campaigns, static fn($c) => ($c['status'] ?? '') === 'completed'));

usort($campaigns, static function (array $left, array $right): int {
    return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
});

$formDataOnlineText = implode("\n", $formData['ai_online_creatives']);
$formDataOfflineText = implode("\n", $formData['ai_offline_creatives']);
$performanceTotals = smart_marketing_sum_performance($formData['performance_logs']);
$whatsappPacksSorted = $formData['whatsapp_packs'];
if (is_array($whatsappPacksSorted)) {
    usort($whatsappPacksSorted, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
}
$offlineAssetsSorted = $formData['offline_assets'];
if (is_array($offlineAssetsSorted)) {
    usort($offlineAssetsSorted, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
}
$mediaAssetsSorted = $formData['media_assets'];
if (is_array($mediaAssetsSorted)) {
    usort($mediaAssetsSorted, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
}
$fbMediaAssets = smart_marketing_filter_media_by_channel($mediaAssetsSorted, 'facebook_instagram');
$waMediaAssets = smart_marketing_filter_media_by_channel($mediaAssetsSorted, 'whatsapp_broadcast');
$executionTasksSorted = $formData['execution_tasks'];
if (is_array($executionTasksSorted)) {
    usort($executionTasksSorted, static function (array $a, array $b): int {
        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });
}

$insightsStatusCounts = [
    'draft' => count(array_filter($campaigns, static fn($c) => ($c['status'] ?? '') === 'draft')),
    'planned' => count(array_filter($campaigns, static fn($c) => ($c['status'] ?? '') === 'planned')),
    'running' => count(array_filter($campaigns, static fn($c) => ($c['status'] ?? '') === 'running')),
    'completed' => count(array_filter($campaigns, static fn($c) => ($c['status'] ?? '') === 'completed')),
];

$insightsTypeCounts = [
    'online' => count(array_filter($campaigns, static fn($c) => ($c['type'] ?? '') === 'online')),
    'offline' => count(array_filter($campaigns, static fn($c) => ($c['type'] ?? '') === 'offline')),
    'mixed' => count(array_filter($campaigns, static fn($c) => ($c['type'] ?? '') === 'mixed')),
];

$insightsCampaignSummaries = [];
$insightsTotals = ['spend' => 0.0, 'leads' => 0, 'cpl' => null];
foreach ($campaigns as $campaign) {
    $campaignPerf = smart_marketing_campaign_performance($campaign, 'all');
    $insightsTotals['spend'] += $campaignPerf['spend'];
    $insightsTotals['leads'] += $campaignPerf['leads'];

    $insightsCampaignSummaries[] = [
        'id' => $campaign['id'] ?? '',
        'name' => $campaign['name'] ?? '',
        'type' => $campaign['type'] ?? '',
        'status' => $campaign['status'] ?? '',
        'channels' => smart_marketing_channel_list($campaign['channels'] ?? []),
        'spend' => $campaignPerf['spend'],
        'leads' => $campaignPerf['leads'],
        'cpl' => $campaignPerf['cpl'],
        'updated_at' => $campaign['updated_at'] ?? '',
    ];
}

if ($insightsTotals['leads'] > 0) {
    $insightsTotals['cpl'] = $insightsTotals['spend'] / max(1, $insightsTotals['leads']);
}

$assetsSummary = [];
$assetsSelectedCampaign = null;
$assetsAllMedia = [];
if ($tab === 'assets') {
    foreach ($campaigns as $campaign) {
        $mediaAssets = smart_marketing_campaign_media_assets($campaign);
        $offlineAssets = smart_marketing_campaign_offline_assets($campaign);

        $assetsSummary[] = [
            'id' => (string) ($campaign['id'] ?? ''),
            'name' => (string) ($campaign['name'] ?? ''),
            'media_count' => count($mediaAssets),
            'offline_count' => count($offlineAssets),
        ];

        if ($selectedCampaignId !== '' && (string) ($campaign['id'] ?? '') === $selectedCampaignId) {
            $assetsSelectedCampaign = $campaign;
            $assetsSelectedCampaign['media_assets'] = $mediaAssets;
            $assetsSelectedCampaign['offline_assets'] = $offlineAssets;
        }

        foreach ($mediaAssets as $asset) {
            $assetsAllMedia[] = array_merge($asset, [
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => (string) ($campaign['name'] ?? ''),
            ]);
        }
    }

    usort($assetsAllMedia, static function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Smart Marketing CMO | Admin</title>
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
  />
  <style>
    body { background: #f5f7fb; font-family: 'Poppins', sans-serif; }
    .fullwidth-wrapper { width: 100% !important; max-width: 100% !important; padding-left: 24px; padding-right: 24px; padding-top: 24px; padding-bottom: 32px; }
    .smart-marketing__shell { display: flex; flex-direction: column; gap: 1rem; }
    .smart-marketing__header { display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }
    .smart-marketing__title { margin: 0; font-size: 2rem; color: #0f172a; }
    .smart-marketing__subtitle { margin: 0; color: #475569; }
    .smart-marketing__actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .smart-marketing__tabs { display: inline-flex; gap: 0.5rem; background: #fff; padding: 0.35rem; border-radius: 999px; box-shadow: 0 12px 30px -24px rgba(15,23,42,0.45); }
    .smart-marketing__tab { border: none; background: transparent; padding: 0.6rem 1.1rem; border-radius: 999px; font-weight: 600; color: #475569; cursor: pointer; }
    .smart-marketing__tab.active { background: #0f172a; color: #fff; }
    .smart-marketing__tab[disabled] { opacity: 0.5; cursor: not-allowed; }
    .card { background: #fff; border-radius: 1.25rem; padding: 1.5rem; box-shadow: 0 20px 45px -30px rgba(15,23,42,0.35); }
    .card h2 { margin-top: 0; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
    .summary-card { background: linear-gradient(135deg, #111827, #1f2937); color: #fff; padding: 1rem 1.25rem; border-radius: 1rem; box-shadow: 0 18px 36px -28px rgba(15,23,42,0.6); }
    .summary-card h3 { margin: 0; font-size: 1rem; opacity: 0.85; }
    .summary-card p { margin: 0.35rem 0 0; font-size: 1.6rem; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    table th, table td { padding: 0.75rem 0.9rem; text-align: left; border-bottom: 1px solid rgba(15,23,42,0.06); }
    table th { background: rgba(15,23,42,0.04); font-weight: 700; color: #0f172a; }
    table tbody tr:hover { background: rgba(15,23,42,0.025); }
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.6rem 1rem; border-radius: 0.85rem; border: none; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #111827; color: #fff; }
    .btn-secondary { background: rgba(15,23,42,0.08); color: #0f172a; }
    .btn-ghost { background: transparent; color: #0f172a; border: 1px solid rgba(15,23,42,0.1); }
    .two-column { display: grid; gap: 1rem; grid-template-columns: 1fr 1fr; }
    .editor-grid { display: grid; gap: 1rem; }
    .editor-grid label { font-weight: 600; color: #0f172a; display: grid; gap: 0.4rem; }
    .editor-grid input[type="text"],
    .editor-grid input[type="date"],
    .editor-grid select,
    .editor-grid textarea { padding: 0.75rem 0.9rem; border-radius: 0.75rem; border: 1px solid rgba(15,23,42,0.15); font: inherit; background: #fff; }
    .editor-grid textarea { min-height: 120px; resize: vertical; }
    .checkbox-group { display: grid; gap: 0.35rem; }
    .checkbox-row { display: flex; gap: 0.6rem; align-items: center; }
    .form-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; justify-content: flex-end; }
    .status-pill { padding: 0.25rem 0.65rem; background: rgba(15,23,42,0.05); border-radius: 999px; font-weight: 600; color: #0f172a; }
    .flash { padding: 1rem 1.25rem; border-radius: 1rem; display: flex; align-items: center; gap: 0.8rem; }
    .flash.info { background: rgba(59,130,246,0.12); color: #1d4ed8; }
    .flash.success { background: rgba(16,185,129,0.12); color: #047857; }
    .flash.warning { background: rgba(250,204,21,0.18); color: #92400e; }
    .flash.error { background: rgba(239,68,68,0.12); color: #b91c1c; }
    .ai-output textarea { background: #f8fafc; }
    .campaign-subnav { display: flex; gap: 0.75rem; flex-wrap: wrap; margin: 0 0 1rem; }
    .campaign-subnav a { padding: 0.45rem 0.9rem; border-radius: 999px; background: rgba(15,23,42,0.06); color: #0f172a; font-weight: 600; text-decoration: none; }
    .campaign-subnav a.disabled { opacity: 0.5; cursor: not-allowed; }
    .card.nested { background: #f8fafc; border: 1px dashed rgba(15,23,42,0.08); margin-top: 1rem; }
    .channel-assets-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .channel-asset-block { background: #fff; border-radius: 1rem; padding: 1rem; border: 1px solid rgba(15,23,42,0.06); box-shadow: 0 14px 28px -32px rgba(15,23,42,0.4); }
    .pack-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .pack-card { background: #fff; border-radius: 1rem; padding: 1rem; border: 1px solid rgba(15,23,42,0.08); box-shadow: 0 12px 24px -32px rgba(15,23,42,0.45); }
    .offline-list, .performance-table-wrapper { margin-top: 1rem; }
    .whatsapp-message { display: grid; gap: 0.35rem; margin-top: 0.5rem; }
    .ai-insights-output { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 1rem; white-space: pre-wrap; line-height: 1.5; font-family: 'Inter', 'Menlo', monospace; }
    .ai-insights-placeholder { color: #94a3b8; }
    .insights-form-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .insights-checkboxes { display: grid; gap: 0.35rem; }
    @media (max-width: 960px) {
      .two-column { grid-template-columns: 1fr; }
      .smart-marketing__header { align-items: flex-start; }
    }
  </style>
</head>
<body class="admin-smart-marketing" data-theme="light">
  <div class="fullwidth-wrapper">
    <main class="smart-marketing__shell">
      <header class="smart-marketing__header">
        <div>
          <h1 class="smart-marketing__title">Smart Marketing CMO</h1>
          <?php $subtitleBrand = trim((string) ($brandProfile['firm_name'] ?? '')); ?>
          <p class="smart-marketing__subtitle">AI-assisted CMO for planning and generating online & offline campaigns<?= $subtitleBrand !== '' ? ' for ' . smart_marketing_safe($subtitleBrand) : '' ?>.</p>
        </div>
        <div class="smart-marketing__actions">
          <a class="btn btn-secondary" href="admin-dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to dashboard</a>
          <a class="btn btn-primary" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Log out</a>
        </div>
      </header>

      <div class="smart-marketing__tabs" role="tablist">
        <a class="smart-marketing__tab <?= $tab === 'overview' ? 'active' : '' ?>" href="?tab=overview">Overview</a>
        <a class="smart-marketing__tab <?= $tab === 'brand_profile' ? 'active' : '' ?>" href="?tab=brand_profile">Brand Profile</a>
        <a class="smart-marketing__tab <?= $tab === 'campaigns' ? 'active' : '' ?>" href="?tab=campaigns">Campaigns</a>
        <a class="smart-marketing__tab <?= $tab === 'insights' ? 'active' : '' ?>" href="?tab=insights">Insights</a>
        <a class="smart-marketing__tab <?= $tab === 'assets' ? 'active' : '' ?>" href="?tab=assets">Assets</a>
        <a class="smart-marketing__tab <?= $tab === 'meta_auto' ? 'active' : '' ?>" href="?tab=meta_auto">Meta Auto</a>
      </div>

      <?php if ($flashMessage !== ''): ?>
        <div class="flash <?= smart_marketing_safe($flashTone) ?>">
          <i class="fa-solid fa-circle-info"></i>
          <span><?= smart_marketing_safe($flashMessage) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($message !== ''): ?>
        <div class="flash <?= smart_marketing_safe($tone) ?>">
          <i class="fa-solid fa-circle-info"></i>
          <span><?= smart_marketing_safe($message) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($loadError !== null): ?>
        <div class="flash error">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span><?= smart_marketing_safe($loadError) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($brandProfileError !== null): ?>
        <div class="flash warning">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span><?= smart_marketing_safe($brandProfileError) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($tab === 'overview'): ?>
        <section class="card" aria-labelledby="overview-heading">
          <h2 id="overview-heading">Overview</h2>
          <?php if ($campaignCount === 0): ?>
            <p>No campaigns yet. Click “Create New Campaign” in the Campaigns tab to start.</p>
          <?php else: ?>
            <div class="summary-grid">
              <div class="summary-card"><h3>Total Campaigns</h3><p><?= $campaignCount ?></p></div>
              <div class="summary-card"><h3>Drafts</h3><p><?= $draftCount ?></p></div>
              <div class="summary-card"><h3>Planned / Running</h3><p><?= $activeCount ?></p></div>
              <div class="summary-card"><h3>Completed</h3><p><?= $completedCount ?></p></div>
            </div>

            <div class="card" style="margin-top:1rem;">
              <h3 style="margin-top:0;">Recent campaigns</h3>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Created</th>
                      <th>Updated</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach (array_slice($campaigns, 0, 8) as $campaign): ?>
                      <tr>
                        <td><a href="?tab=campaigns&campaign_id=<?= rawurlencode((string) ($campaign['id'] ?? '')) ?>"><?= smart_marketing_safe((string) ($campaign['name'] ?? '')) ?></a></td>
                        <td><?= smart_marketing_safe(ucfirst((string) ($campaign['type'] ?? ''))) ?></td>
                        <td><span class="status-pill"><?= smart_marketing_safe(ucfirst((string) ($campaign['status'] ?? 'draft'))) ?></span></td>
                        <td><?= smart_marketing_safe(marketing_format_datetime($campaign['created_at'] ?? null)) ?></td>
                        <td><?= smart_marketing_safe(marketing_format_datetime($campaign['updated_at'] ?? null)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php elseif ($tab === 'brand_profile'): ?>
        <section class="card" aria-labelledby="brand-profile-heading">
          <div class="smart-marketing__header" style="margin-bottom:1rem;">
            <div>
              <h2 id="brand-profile-heading" style="margin:0;">Smart Marketing CMO – Brand / Firm Profile</h2>
              <p style="margin:0;color:#475569;">Store firm details once for consistent AI context across Smart Marketing CMO.</p>
            </div>
          </div>

          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
            <input type="hidden" name="tab" value="brand_profile" />
            <div class="two-column">
              <label>
                Firm Name
                <input type="text" name="firm_name" value="<?= smart_marketing_safe((string) ($brandProfile['firm_name'] ?? '')) ?>" />
              </label>
              <label>
                Tagline / Motto
                <input type="text" name="tagline" value="<?= smart_marketing_safe((string) ($brandProfile['tagline'] ?? '')) ?>" />
              </label>
            </div>
            <div class="two-column">
              <label>
                Primary Contact Number (Call)
                <input type="text" name="primary_contact_number" value="<?= smart_marketing_safe((string) ($brandProfile['primary_contact_number'] ?? '')) ?>" />
              </label>
              <label>
                WhatsApp Number
                <input type="text" name="whatsapp_number" value="<?= smart_marketing_safe((string) ($brandProfile['whatsapp_number'] ?? '')) ?>" />
              </label>
            </div>
            <div class="two-column">
              <label>
                Email
                <input type="text" name="email" value="<?= smart_marketing_safe((string) ($brandProfile['email'] ?? '')) ?>" />
              </label>
              <label>
                Website URL
                <input type="text" name="website_url" value="<?= smart_marketing_safe((string) ($brandProfile['website_url'] ?? '')) ?>" />
              </label>
            </div>
            <div class="two-column">
              <label>
                Facebook Page URL or Name
                <input type="text" name="facebook_page_url" value="<?= smart_marketing_safe((string) ($brandProfile['facebook_page_url'] ?? '')) ?>" />
              </label>
              <label>
                Instagram Handle
                <input type="text" name="instagram_handle" value="<?= smart_marketing_safe((string) ($brandProfile['instagram_handle'] ?? '')) ?>" />
              </label>
            </div>
            <label>
              Physical Address
              <textarea name="physical_address" rows="3" placeholder="Office address, city, state"><?= smart_marketing_safe((string) ($brandProfile['physical_address'] ?? '')) ?></textarea>
            </label>
            <div class="two-column">
              <label>
                Default Call-to-Action Line
                <input type="text" name="default_cta_line" value="<?= smart_marketing_safe((string) ($brandProfile['default_cta_line'] ?? '')) ?>" />
              </label>
              <label>
                Company Logo Path / URL
                <input type="text" name="logo_path" value="<?= smart_marketing_safe((string) ($brandProfile['logo_path'] ?? '')) ?>" />
              </label>
            </div>

            <div class="form-actions" style="justify-content:flex-start;">
              <button class="btn btn-primary" type="submit" name="action" value="save_brand_profile"><i class="fa-solid fa-floppy-disk"></i> Save Brand Profile</button>
            </div>
          </form>

          <div class="card nested" style="margin-top:1rem;">
            <h4 style="margin:0 0 0.5rem;">Preview</h4>
            <p style="margin:0 0 0.75rem;color:#475569;">This is what AI sees as brand context for campaigns, assets, and WhatsApp packs.</p>
            <?php $brandPreviewLines = smart_marketing_brand_context_lines($brandProfile); ?>
            <?php if (empty($brandPreviewLines)): ?>
              <p style="margin:0;">No brand details saved yet.</p>
            <?php else: ?>
              <pre style="margin:0; white-space:pre-wrap; background:#fff; border:1px dashed rgba(15,23,42,0.12); padding:0.75rem; border-radius:0.75rem; color:#0f172a;">
<?= smart_marketing_safe(implode("\n", $brandPreviewLines)) ?>
              </pre>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif ($tab === 'campaigns'): ?>
        <section class="card" aria-labelledby="campaigns-heading">
          <div class="smart-marketing__header" style="margin-bottom:1rem;">
            <div>
              <h2 id="campaigns-heading" style="margin:0;">Campaigns</h2>
              <p style="margin:0;color:#475569;">Create structured campaigns and generate ideas with Gemini.</p>
            </div>
            <div>
              <a class="btn btn-primary" href="?tab=campaigns"><i class="fa-solid fa-plus"></i> Create New Campaign</a>
            </div>
          </div>

          <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:1rem 1.25rem;">
              <h3 style="margin:0 0 0.5rem;">Campaign list</h3>
              <?php if (empty($campaigns)): ?>
                <p style="margin:0.5rem 0 1rem;">No campaigns created yet.</p>
              <?php else: ?>
                <div style="overflow-x:auto;">
                  <table>
                    <thead>
                      <tr>
                        <th>Campaign Name</th>
                        <th>Type</th>
                        <th>Primary Goal</th>
                        <th>Channels</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                          <td><?= smart_marketing_safe((string) ($campaign['name'] ?? '')) ?></td>
                          <td><?= smart_marketing_safe(ucfirst((string) ($campaign['type'] ?? ''))) ?></td>
                          <td><?= smart_marketing_safe((string) ($campaign['primary_goal'] ?? '')) ?></td>
                          <td><?= smart_marketing_safe(smart_marketing_channel_list($campaign['channels'] ?? [])) ?></td>
                          <td><span class="status-pill"><?= smart_marketing_safe(ucfirst((string) ($campaign['status'] ?? 'draft'))) ?></span></td>
                          <td><?= smart_marketing_safe(marketing_format_datetime($campaign['created_at'] ?? null)) ?></td>
                          <td><?= smart_marketing_safe(marketing_format_datetime($campaign['updated_at'] ?? null)) ?></td>
                          <td><a class="btn btn-secondary" href="?tab=campaigns&campaign_id=<?= rawurlencode((string) ($campaign['id'] ?? '')) ?>">View/Edit</a></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card" style="margin-top:1.5rem;">
            <h3 style="margin-top:0;">Campaign editor</h3>
            <form method="post" id="campaign-form">
              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
              <input type="hidden" name="tab" value="campaigns" />
              <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
              <input type="hidden" name="tracking_code" value="<?= smart_marketing_safe((string) ($formData['tracking_code'] ?? '')) ?>" />

              <div class="campaign-subnav">
                <a href="#campaign-info">Campaign Info</a>
                <a href="#ai-assets">AI Assets</a>
                <a href="#whatsapp" <?= ($formData['id'] ?? '') === '' ? 'class="disabled"' : '' ?>>WhatsApp Packs</a>
                <a href="#offline" <?= ($formData['id'] ?? '') === '' ? 'class="disabled"' : '' ?>>Offline Printables</a>
                <a href="#performance" <?= ($formData['id'] ?? '') === '' ? 'class="disabled"' : '' ?>>Performance & Notes</a>
              </div>

              <?php if (($formData['id'] ?? '') !== ''): ?>
                <div class="card nested" id="ai-history">
                  <div class="smart-marketing__header" style="margin:0 0 0.5rem;">
                    <div>
                      <h4 style="margin:0;">Last AI Insights for this campaign</h4>
                      <p style="margin:0;color:#475569;">Saved automatically when insights are generated in the Insights tab.</p>
                    </div>
                    <div>
                      <a class="btn btn-secondary" href="?tab=insights&scope=<?= rawurlencode((string) ($formData['id'] ?? '')) ?>">
                        <i class="fa-solid fa-bolt"></i> Generate new insights
                      </a>
                    </div>
                  </div>
                  <?php $lastInsightsText = trim((string) ($formData['last_ai_insights'] ?? '')); ?>
                  <?php if ($lastInsightsText === ''): ?>
                    <p style="margin:0;">No AI insights generated yet for this campaign. Go to the Insights tab to generate.</p>
                  <?php else: ?>
                    <p style="margin:0 0 0.35rem;color:#475569;">Last updated: <?= smart_marketing_safe((string) ($formData['last_ai_insights_at'] ?? '')) ?></p>
                    <?php $previewText = mb_substr($lastInsightsText, 0, 400); ?>
                    <pre class="ai-insights-output" style="margin:0; white-space:pre-wrap; background:#fff; color:#0f172a; border:1px dashed rgba(15,23,42,0.15);"><?= smart_marketing_safe($previewText) ?><?= mb_strlen($lastInsightsText) > 400 ? '…' : '' ?></pre>
                    <?php if (mb_strlen($lastInsightsText) > 400): ?>
                      <details style="margin-top:0.5rem;">
                        <summary style="cursor:pointer; font-weight:600; color:#0f172a;">View full insights</summary>
                        <pre class="ai-insights-output" style="margin-top:0.5rem; white-space:pre-wrap; background:#fff; color:#0f172a; border:1px dashed rgba(15,23,42,0.15);"><?= smart_marketing_safe($lastInsightsText) ?></pre>
                      </details>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="card nested" id="tracking">
                <div class="smart-marketing__header" style="margin:0 0 0.5rem;">
                  <div>
                    <h4 style="margin:0;">Tracking Code</h4>
                    <p style="margin:0;color:#475569;">Generate a campaign-specific code to track leads from URLs.</p>
                  </div>
                  <div>
                    <button class="btn btn-secondary" type="submit" name="action" value="generate_tracking_code" <?= ($formData['id'] ?? '') === '' ? 'disabled' : '' ?>>
                      <i class="fa-solid fa-barcode"></i> Generate Tracking Code
                    </button>
                  </div>
                </div>
                <?php $trackingCode = trim((string) ($formData['tracking_code'] ?? '')); ?>
                <?php if (($formData['id'] ?? '') === ''): ?>
                  <p style="margin:0;">Save the campaign first before generating a tracking code.</p>
                <?php elseif ($trackingCode === ''): ?>
                  <p style="margin:0;">No tracking code generated yet.</p>
                <?php else: ?>
                  <div class="editor-grid" style="margin-top:0.5rem;">
                    <label style="max-width:420px;">
                      Tracking Code
                      <input type="text" value="<?= smart_marketing_safe($trackingCode) ?>" readonly />
                    </label>
                  </div>
                  <p style="margin:0.25rem 0 0;color:#475569;">Use this code in URLs as ?campaign=<?= smart_marketing_safe($trackingCode) ?>.</p>
                <?php endif; ?>

                <div class="card nested" style="margin-top:1rem;">
                  <h5 style="margin:0 0 0.25rem;">Tracking Links</h5>
                  <p style="margin:0 0 0.5rem;color:#475569;">Share these URLs to attribute leads to this campaign.</p>
                  <?php if ($trackingCode === ''): ?>
                    <p style="margin:0;">Generate a tracking code first to see tracking links.</p>
                  <?php else: ?>
                    <div class="editor-grid" style="margin-top:0.5rem;">
                      <?php $baseUrl = '/contact.php?campaign=' . rawurlencode($trackingCode); ?>
                      <label>
                        Generic lead capture URL
                        <input type="text" readonly value="<?= smart_marketing_safe($baseUrl) ?>" />
                      </label>
                      <label>
                        With source parameter
                        <input type="text" readonly value="<?= smart_marketing_safe($baseUrl . '&source=website') ?>" />
                      </label>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="editor-grid" id="campaign-info">
                <label>
                  Campaign Name
                  <input type="text" name="name" required value="<?= smart_marketing_safe((string) ($formData['name'] ?? '')) ?>" />
                </label>

                <div class="two-column">
                  <label>
                    Campaign Type
                    <select name="type">
                      <option value="online" <?= $formData['type'] === 'online' ? 'selected' : '' ?>>Online</option>
                      <option value="offline" <?= $formData['type'] === 'offline' ? 'selected' : '' ?>>Offline</option>
                      <option value="mixed" <?= $formData['type'] === 'mixed' ? 'selected' : '' ?>>Mixed</option>
                    </select>
                  </label>
                  <label>
                    Status
                    <select name="status">
                      <option value="draft" <?= $formData['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                      <option value="planned" <?= $formData['status'] === 'planned' ? 'selected' : '' ?>>Planned</option>
                      <option value="running" <?= $formData['status'] === 'running' ? 'selected' : '' ?>>Running</option>
                      <option value="completed" <?= $formData['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                  </label>
                </div>

                <div class="two-column">
                  <label>
                    Primary Goal
                    <select name="primary_goal">
                      <?php
                        $goalOptions = ['Lead Generation', 'Brand Awareness', 'Website Traffic', 'Follow-up / Nurturing', 'Other'];
                        foreach ($goalOptions as $goal): ?>
                          <option value="<?= smart_marketing_safe($goal) ?>" <?= ($formData['primary_goal'] ?? '') === $goal ? 'selected' : '' ?>><?= smart_marketing_safe($goal) ?></option>
                        <?php endforeach; ?>
                    </select>
                  </label>
                  <label>
                    Channels
                    <div class="checkbox-group">
                      <?php $channels = marketing_channels_labels(); ?>
                      <?php foreach ($channels as $key => $label): ?>
                        <span class="checkbox-row">
                          <input type="checkbox" id="channel_<?= smart_marketing_safe($key) ?>" name="channels[]" value="<?= smart_marketing_safe($key) ?>" <?= in_array($key, $formData['channels'] ?? [], true) ? 'checked' : '' ?> />
                          <label for="channel_<?= smart_marketing_safe($key) ?>" style="font-weight:500;"><?= smart_marketing_safe($label) ?></label>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  </label>
                </div>

                <label>
                  What I want to do (simple language)
                  <textarea name="intent_note" placeholder="Describe in simple terms what you want to achieve or whom to reach">
<?= smart_marketing_safe((string) ($formData['intent_note'] ?? '')) ?></textarea>
                </label>

                <label>
                  Target Areas
                  <textarea name="target_areas" placeholder="Cities, districts, localities"><?= smart_marketing_safe((string) ($formData['target_areas'] ?? '')) ?></textarea>
                </label>

                <div class="ai-draft-block">
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_target_areas" data-draft-id="draft-target-areas" data-target-input="target_areas"><i class="fa-solid fa-robot"></i> Generate Target Areas with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-target-areas" data-target-input="target_areas" disabled>Insert into Target Areas field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-target-areas" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>

                <label>
                  Target Persona
                  <textarea name="target_persona" placeholder="Describe the ideal customer persona"><?= smart_marketing_safe((string) ($formData['target_persona'] ?? '')) ?></textarea>
                </label>

                <div class="two-column">
                  <label>
                    Total Budget for this campaign (₹, e.g. 25000)
                    <input type="text" name="total_budget_rs" value="<?= smart_marketing_safe((string) ($formData['total_budget_rs'] ?? '')) ?>" />
                  </label>
                  <label>
                    Budget Period
                    <select name="budget_period">
                      <?php $periods = ['' => 'Select period', 'One-time' => 'One-time', 'Per month' => 'Per month', 'Per week' => 'Per week', 'Per day' => 'Per day']; ?>
                      <?php foreach ($periods as $value => $label): ?>
                        <option value="<?= smart_marketing_safe($value) ?>" <?= ((string) ($formData['budget_period'] ?? '') === (string) $value) ? 'selected' : '' ?>><?= smart_marketing_safe($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>

                <div class="ai-draft-block">
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_target_persona" data-draft-id="draft-target-persona" data-target-input="target_persona"><i class="fa-solid fa-robot"></i> Generate Target Persona with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-target-persona" data-target-input="target_persona" disabled>Insert into Target Persona field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-target-persona" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>

                <label>
                  Budget Notes
                  <textarea name="budget_notes" placeholder="e.g., media split, offline allocations"><?= smart_marketing_safe((string) ($formData['budget_notes'] ?? '')) ?></textarea>
                </label>

                <div class="ai-draft-block">
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_budget_notes" data-draft-id="draft-budget-notes" data-target-input="budget_notes"><i class="fa-solid fa-robot"></i> Generate Budget Notes with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-budget-notes" data-target-input="budget_notes" disabled>Insert into Budget Notes field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-budget-notes" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>

                <label>
                  AI Budget Allocation Plan
                  <textarea name="ai_budget_allocation" placeholder="AI-generated budget allocation plan" rows="6"><?= smart_marketing_safe((string) ($formData['ai_budget_allocation'] ?? '')) ?></textarea>
                </label>

                <div class="ai-draft-block">
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_budget_allocation_plan" data-draft-id="draft-budget-allocation" data-target-input="ai_budget_allocation"><i class="fa-solid fa-robot"></i> Generate Budget Allocation Plan with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-budget-allocation" data-target-input="ai_budget_allocation" disabled>Insert into Budget Allocation field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-budget-allocation" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>

                <div class="two-column">
                  <label>
                    Start Date
                    <input type="date" name="start_date" value="<?= smart_marketing_safe((string) ($formData['start_date'] ?? '')) ?>" />
                  </label>
                  <label>
                    End Date
                    <input type="date" name="end_date" value="<?= smart_marketing_safe((string) ($formData['end_date'] ?? '')) ?>" />
                  </label>
                </div>

                <label>
                  AI Brief for This Campaign
                  <textarea name="ai_brief" placeholder="Describe what you want to achieve, key offers, tone, etc."><?= smart_marketing_safe((string) ($formData['ai_brief'] ?? '')) ?></textarea>
                </label>

                <div class="ai-draft-block">
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_ai_brief" data-draft-id="draft-ai-brief" data-target-input="ai_brief"><i class="fa-solid fa-robot"></i> Generate AI Brief with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-ai-brief" data-target-input="ai_brief" disabled>Insert into AI Brief field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-ai-brief" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>

                <div class="ai-output">
                  <label>
                    AI Strategy Summary
                    <textarea name="ai_strategy_summary" placeholder="AI-generated high-level strategy" rows="5"><?= smart_marketing_safe((string) ($formData['ai_strategy_summary'] ?? '')) ?></textarea>
                  </label>

                  <div class="ai-draft-block">
                    <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                      <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_strategy_summary" data-draft-id="draft-strategy-summary" data-target-input="ai_strategy_summary"><i class="fa-solid fa-robot"></i> Generate Strategy Summary with AI</button>
                      <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-strategy-summary" data-target-input="ai_strategy_summary" disabled>Insert into Strategy field</button>
                    </div>
                    <div class="ai-draft-box" id="draft-strategy-summary" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                  </div>

                  <label>
                    Online Creatives (one per line)
                    <textarea name="ai_online_creatives" rows="6" placeholder="- Facebook/Instagram idea
- Google Search ad copy
- YouTube script angle
- WhatsApp hook"><?= smart_marketing_safe($formDataOnlineText) ?></textarea>
                  </label>

                  <div class="ai-draft-block">
                    <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                      <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_online_creatives_summary" data-draft-id="draft-online-creatives" data-target-input="ai_online_creatives"><i class="fa-solid fa-robot"></i> Generate Online Creatives with AI</button>
                      <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-online-creatives" data-target-input="ai_online_creatives" disabled>Insert into Online Creatives</button>
                    </div>
                    <div class="ai-draft-box" id="draft-online-creatives" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                  </div>

                  <label>
                    Offline Creatives (one per line)
                    <textarea name="ai_offline_creatives" rows="6" placeholder="- Hoarding headline
- Pamphlet copy
- Newspaper/Radio script"><?= smart_marketing_safe($formDataOfflineText) ?></textarea>
                  </label>

                  <div class="ai-draft-block">
                    <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                      <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_offline_creatives_summary" data-draft-id="draft-offline-creatives" data-target-input="ai_offline_creatives"><i class="fa-solid fa-robot"></i> Generate Offline Creatives with AI</button>
                      <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-offline-creatives" data-target-input="ai_offline_creatives" disabled>Insert into Offline Creatives</button>
                    </div>
                    <div class="ai-draft-box" id="draft-offline-creatives" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                  </div>
                </div>
              </div>

              <div class="card nested" id="meta-assistant">
                <div class="smart-marketing__header" style="margin:0 0 0.75rem;">
                  <div>
                    <h4 style="margin:0;">Meta Ads Assistant (Manual Setup)</h4>
                    <p style="margin:0;color:#475569;">This assistant helps you configure Meta (Facebook/Instagram) campaign parameters in Ads Manager. Use the AI suggestions and guide to set everything manually inside Meta.</p>
                  </div>
                </div>
                <?php
                  $metaSettings = is_array($formData['meta_ads_settings'] ?? null) ? $formData['meta_ads_settings'] : [];
                  $metaSettings = array_merge([
                    'objective' => '',
                    'conversion_location' => '',
                    'performance_goal' => '',
                    'budget_strategy' => '',
                    'audience_summary' => '',
                    'placement_strategy' => '',
                    'optimization_and_bidding' => '',
                    'creative_recommendations' => '',
                    'tracking_and_reporting' => '',
                  ], $metaSettings);

                  $metaFieldConfigs = [
                    'objective' => [
                      'label' => 'Objective',
                      'action' => 'gen_meta_objective',
                      'generate' => 'Generate Objective with AI',
                      'insert' => 'Insert into Objective field',
                      'rows' => 2,
                    ],
                    'conversion_location' => [
                      'label' => 'Conversion Location',
                      'action' => 'gen_meta_conversion_location',
                      'generate' => 'Generate Conversion Location with AI',
                      'insert' => 'Insert into Conversion Location field',
                      'rows' => 2,
                    ],
                    'performance_goal' => [
                      'label' => 'Performance Goal',
                      'action' => 'gen_meta_performance_goal',
                      'generate' => 'Generate Performance Goal with AI',
                      'insert' => 'Insert into Performance Goal field',
                      'rows' => 2,
                    ],
                    'budget_strategy' => [
                      'label' => 'Budget Strategy',
                      'action' => 'gen_meta_budget_strategy',
                      'generate' => 'Generate Budget Strategy with AI',
                      'insert' => 'Insert into Budget Strategy field',
                      'rows' => 3,
                    ],
                    'audience_summary' => [
                      'label' => 'Audience Summary',
                      'action' => 'gen_meta_audience_summary',
                      'generate' => 'Generate Audience with AI',
                      'insert' => 'Insert into Audience field',
                      'rows' => 4,
                    ],
                    'placement_strategy' => [
                      'label' => 'Placement Strategy',
                      'action' => 'gen_meta_placement_strategy',
                      'generate' => 'Generate Placement Strategy with AI',
                      'insert' => 'Insert into Placement Strategy field',
                      'rows' => 3,
                    ],
                    'optimization_and_bidding' => [
                      'label' => 'Optimization & Bidding',
                      'action' => 'gen_meta_optimization_bidding',
                      'generate' => 'Generate Optimization & Bidding with AI',
                      'insert' => 'Insert into Optimization & Bidding field',
                      'rows' => 3,
                    ],
                    'creative_recommendations' => [
                      'label' => 'Creative Recommendations',
                      'action' => 'gen_meta_creative_reco',
                      'generate' => 'Generate Creative Recommendations with AI',
                      'insert' => 'Insert into Creative Recommendations field',
                      'rows' => 3,
                    ],
                    'tracking_and_reporting' => [
                      'label' => 'Tracking & Reporting Notes',
                      'action' => 'gen_meta_tracking_notes',
                      'generate' => 'Generate Tracking Notes with AI',
                      'insert' => 'Insert into Tracking Notes field',
                      'rows' => 3,
                    ],
                  ];
                ?>
                <div class="editor-grid">
                  <?php foreach ($metaFieldConfigs as $metaKey => $config): ?>
                    <?php
                      $draftId = 'draft-meta-' . smart_marketing_slug($metaKey);
                      $currentValue = trim((string) ($metaSettings[$metaKey] ?? ''));
                      $rows = (int) ($config['rows'] ?? 2);
                    ?>
                    <div class="ai-draft-block" style="margin-bottom:0.75rem;">
                      <label>
                        <?= smart_marketing_safe($config['label']) ?>
                        <textarea name="meta_ads_settings[<?= smart_marketing_safe($metaKey) ?>]" rows="<?= $rows > 0 ? $rows : 2 ?>" placeholder="<?= smart_marketing_safe($config['label']) ?> suggestions"><?= smart_marketing_safe($currentValue) ?></textarea>
                      </label>
                      <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                        <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="<?= smart_marketing_safe($config['action']) ?>" data-draft-id="<?= smart_marketing_safe($draftId) ?>" data-target-input="meta_ads_settings[<?= smart_marketing_safe($metaKey) ?>]"><i class="fa-solid fa-robot"></i> <?= smart_marketing_safe($config['generate']) ?></button>
                        <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="<?= smart_marketing_safe($draftId) ?>" data-target-input="meta_ads_settings[<?= smart_marketing_safe($metaKey) ?>]" disabled><?= smart_marketing_safe($config['insert']) ?></button>
                      </div>
                      <div class="ai-draft-box" id="<?= smart_marketing_safe($draftId) ?>" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="ai-draft-block" style="margin-top:0.5rem;">
                  <label>
                    Meta Manual Setup Guide
                    <textarea name="meta_manual_guide" rows="10" placeholder="Step-by-step instructions for Ads Manager"><?= smart_marketing_safe((string) ($formData['meta_manual_guide'] ?? '')) ?></textarea>
                  </label>
                  <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_meta_setup_guide" data-draft-id="draft-meta-setup-guide" data-target-input="meta_manual_guide"><i class="fa-solid fa-robot"></i> Generate Step-by-Step Meta Setup Guide with AI</button>
                    <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-meta-setup-guide" data-target-input="meta_manual_guide" disabled>Insert into Meta Guide field</button>
                  </div>
                  <div class="ai-draft-box" id="draft-meta-setup-guide" aria-live="polite" style="min-height:90px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                </div>
              </div>

              <div class="card nested" id="ai-assets">
                <div class="smart-marketing__header" style="margin:0 0 1rem;">
                  <div>
                    <h4 style="margin:0;">AI Assets</h4>
                    <p style="margin:0;color:#475569;">Generate channel-specific creatives and ad texts using the Smart Marketing CMO (Gemini).</p>
                  </div>
                  <div class="form-actions" style="margin:0;">
                    <span class="status-pill" style="background:rgba(15,23,42,0.06); color:#0f172a;">Use per-channel AI generators below</span>
                  </div>
                </div>
                <?php if (empty($formData['channels'])): ?>
                  <p style="margin-top:0;">Select channels above to view asset fields.</p>
                <?php else: ?>
                  <?php $assetDefinitions = smart_marketing_channel_asset_definitions(); ?>
                  <?php
                    $perFieldAiConfig = [
                        'facebook_instagram' => [
                            'primary_texts' => [
                                'generate' => 'Generate Primary Texts with AI',
                                'insert' => 'Insert into Primary Texts field',
                                'action' => 'gen_fb_primary_texts',
                            ],
                            'headlines' => [
                                'generate' => 'Generate Headlines with AI',
                                'insert' => 'Insert into Headlines field',
                                'action' => 'gen_fb_headlines',
                            ],
                            'descriptions' => [
                                'generate' => 'Generate Descriptions with AI',
                                'insert' => 'Insert into Descriptions field',
                                'action' => 'gen_fb_descriptions',
                            ],
                            'cta_suggestions' => [
                                'generate' => 'Generate CTAs with AI',
                                'insert' => 'Insert into CTA Suggestions field',
                                'action' => 'gen_fb_ctas',
                            ],
                            'media_concepts' => [
                                'generate' => 'Generate FB/IG Media Concepts with AI',
                                'insert' => 'Insert into Media Concepts field',
                                'action' => 'gen_fb_media_concepts',
                            ],
                            'media_prompts' => [
                                'generate' => 'Generate FB/IG Image Prompts with AI',
                                'insert' => 'Insert into Image Prompts field',
                                'action' => 'gen_fb_media_prompts',
                            ],
                        ],
                        'google_search' => [
                            'headlines' => [
                                'generate' => 'Generate Search Headlines with AI',
                                'insert' => 'Insert into Headlines field',
                                'action' => 'gen_search_headlines',
                            ],
                            'descriptions' => [
                                'generate' => 'Generate Search Descriptions with AI',
                                'insert' => 'Insert into Descriptions field',
                                'action' => 'gen_search_descriptions',
                            ],
                            'keywords' => [
                                'generate' => 'Generate Keywords with AI',
                                'insert' => 'Insert into Keywords field',
                                'action' => 'gen_search_keywords',
                            ],
                        ],
                        'youtube_video' => [
                            'hook_lines' => [
                                'generate' => 'Generate Hook Lines with AI',
                                'insert' => 'Insert into Hook Lines field',
                                'action' => 'gen_yt_hooks',
                            ],
                            'video_script' => [
                                'generate' => 'Generate Video Script with AI',
                                'insert' => 'Insert into Video Script field',
                                'action' => 'gen_yt_script',
                            ],
                            'thumbnail_text_ideas' => [
                                'generate' => 'Generate Thumbnail Text with AI',
                                'insert' => 'Insert into Thumbnail Text Ideas field',
                                'action' => 'gen_yt_thumbnail_text',
                            ],
                        ],
                        'whatsapp_broadcast' => [
                            'short_messages' => [
                                'generate' => 'Generate Short WhatsApp Messages with AI',
                                'insert' => 'Insert into Short Messages field',
                                'action' => 'gen_wa_short',
                            ],
                            'long_messages' => [
                                'generate' => 'Generate Long WhatsApp Messages with AI',
                                'insert' => 'Insert into Long Messages field',
                                'action' => 'gen_wa_long',
                            ],
                            'media_concepts' => [
                                'generate' => 'Generate WhatsApp Media Concepts with AI',
                                'insert' => 'Insert into Media Concepts field',
                                'action' => 'gen_wa_media_concepts',
                            ],
                            'media_prompts' => [
                                'generate' => 'Generate WhatsApp Image Prompts with AI',
                                'insert' => 'Insert into Image Prompts field',
                                'action' => 'gen_wa_media_prompts',
                            ],
                        ],
                        'offline_hoardings' => [
                            'headline_options' => [
                                'generate' => 'Generate Hoarding Headlines with AI',
                                'insert' => 'Insert into Headlines field',
                                'action' => 'gen_offline_hoarding_headlines',
                            ],
                            'body_text_options' => [
                                'generate' => 'Generate Hoarding Body Text with AI',
                                'insert' => 'Insert into Body Text field',
                                'action' => 'gen_offline_hoarding_body',
                            ],
                            'taglines' => [
                                'generate' => 'Generate Hoarding Taglines with AI',
                                'insert' => 'Insert into Taglines field',
                                'action' => 'gen_offline_hoarding_taglines',
                            ],
                        ],
                        'offline_pamphlets' => [
                            'front_side_copy' => [
                                'generate' => 'Generate Front Side Copy with AI',
                                'insert' => 'Insert into Front Side Copy field',
                                'action' => 'gen_pamphlet_front',
                            ],
                            'back_side_copy' => [
                                'generate' => 'Generate Back Side Copy with AI',
                                'insert' => 'Insert into Back Side Copy field',
                                'action' => 'gen_pamphlet_back',
                            ],
                        ],
                        'offline_newspaper_radio' => [
                            'newspaper_ad_copy' => [
                                'generate' => 'Generate Newspaper Ad Copy with AI',
                                'insert' => 'Insert into Newspaper Ad field',
                                'action' => 'gen_newspaper_ad',
                            ],
                            'radio_script' => [
                                'generate' => 'Generate Radio Script with AI',
                                'insert' => 'Insert into Radio Script field',
                                'action' => 'gen_radio_script',
                            ],
                        ],
                    ];
                  ?>
                  <div class="channel-assets-grid">
                    <?php foreach ($formData['channels'] as $channelKey): ?>
                      <?php $fields = $assetDefinitions[$channelKey] ?? []; ?>
                      <div class="channel-asset-block" id="channel-block-<?= smart_marketing_safe($channelKey) ?>">
                        <h5 style="margin:0 0 0.25rem;"><?= smart_marketing_safe(marketing_channels_labels()[$channelKey] ?? $channelKey) ?></h5>
                        <p style="margin:0 0 0.75rem;color:#475569;font-size:0.95rem;">Edit or paste AI suggestions per field.</p>
                        <?php if (isset($perFieldAiConfig[$channelKey])): ?>
                          <?php foreach ($fields as $fieldKey => $meta): ?>
                            <?php
                              $value = $formData['channel_assets'][$channelKey][$fieldKey] ?? '';
                              $textValue = is_array($value) ? implode("\n", $value) : (string) $value;
                              $draftId = 'draft-' . smart_marketing_safe($channelKey) . '-' . smart_marketing_safe($fieldKey);
                              $buttonText = $perFieldAiConfig[$channelKey][$fieldKey]['generate'] ?? 'Generate with AI';
                              $insertLabel = $perFieldAiConfig[$channelKey][$fieldKey]['insert'] ?? 'Insert into field';
                              $aiAction = $perFieldAiConfig[$channelKey][$fieldKey]['action'] ?? '';
                            ?>
                          <div class="ai-draft-block" style="margin-bottom:0.75rem;">
                            <label>
                              <?= smart_marketing_safe($meta['label']) ?>
                              <?php if ($meta['type'] === 'list'): ?>
                                <textarea name="channel_assets[<?= smart_marketing_safe($channelKey) ?>][<?= smart_marketing_safe($fieldKey) ?>]" rows="3" placeholder="One entry per line"><?= smart_marketing_safe($textValue) ?></textarea>
                                <?php else: ?>
                                  <textarea name="channel_assets[<?= smart_marketing_safe($channelKey) ?>][<?= smart_marketing_safe($fieldKey) ?>]" rows="3" placeholder="Write copy for <?= smart_marketing_safe($meta['label']) ?>"><?= smart_marketing_safe($textValue) ?></textarea>
                                <?php endif; ?>
                              </label>
                              <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                                <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="<?= smart_marketing_safe($aiAction) ?>" data-draft-id="<?= smart_marketing_safe($draftId) ?>"><i class="fa-solid fa-robot"></i> <?= smart_marketing_safe($buttonText) ?></button>
                                <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="<?= smart_marketing_safe($draftId) ?>" data-target-input="channel_assets[<?= smart_marketing_safe($channelKey) ?>][<?= smart_marketing_safe($fieldKey) ?>]" disabled><?= smart_marketing_safe($insertLabel) ?></button>
                              </div>
                              <div class="ai-draft-box" id="<?= smart_marketing_safe($draftId) ?>" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                            </div>
                          <?php endforeach; ?>
                          <?php if ($channelKey === 'facebook_instagram'): ?>
                            <div class="ai-draft-block" style="margin-bottom:0.75rem;">
                              <div style="display:flex; flex-direction:column; gap:0.35rem;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                  <h6 style="margin:0; font-size:1rem;">Generate Facebook / Instagram Image</h6>
                                  <button type="button" class="btn btn-secondary ai-generate-image-btn" data-ai-action="gen_fb_image" data-prompt-input="fb-image-prompt" data-status-target="fb-image-status" data-gallery-target="fb-image-gallery"><i class="fa-solid fa-image"></i> Generate FB/IG Image with AI</button>
                                </div>
                                <p style="margin:0;color:#475569;">Paste or type an image prompt and generate a creative saved to this campaign.</p>
                                <textarea id="fb-image-prompt" rows="2" placeholder="Type or paste the image prompt you want to use"></textarea>
                                <p id="fb-image-status" style="margin:0;color:#475569;font-size:0.95rem;"></p>
                                <div id="fb-image-gallery" class="media-gallery" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:0.75rem;">
                                  <?php if (empty($fbMediaAssets)): ?>
                                    <p style="margin:0; color:#475569;">No generated Facebook/Instagram images yet.</p>
                                  <?php else: ?>
                                    <?php foreach ($fbMediaAssets as $asset): ?>
                                      <div class="media-thumb" style="border:1px solid #e2e8f0; border-radius:8px; padding:0.5rem; background:#fff;">
                                        <?php if (!empty($asset['file_url'])): ?>
                                          <a href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener"><img src="<?= smart_marketing_safe((string) $asset['file_url']) ?>" alt="Generated image" style="width:100%; height:160px; object-fit:cover; border-radius:6px;"></a>
                                        <?php else: ?>
                                          <div style="height:160px; background:#f8fafc; display:flex; align-items:center; justify-content:center; color:#94a3b8; border-radius:6px;">No preview</div>
                                        <?php endif; ?>
                                        <div style="margin-top:0.35rem;">
                                          <div style="font-weight:600; font-size:0.95rem;"><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></div>
                                          <div style="font-size:0.85rem; color:#475569;">Prompt: <?= smart_marketing_safe((string) ($asset['source_prompt'] ?? '')) ?></div>
                                          <?php if (!empty($asset['file_url'])): ?>
                                            <a href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener" style="font-size:0.9rem;">Open full image</a>
                                          <?php endif; ?>
                                        </div>
                                      </div>
                                    <?php endforeach; ?>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          <?php elseif ($channelKey === 'whatsapp_broadcast'): ?>
                            <div class="ai-draft-block" style="margin-bottom:0.75rem;">
                              <div style="display:flex; flex-direction:column; gap:0.35rem;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                  <h6 style="margin:0; font-size:1rem;">Generate WhatsApp Image</h6>
                                  <button type="button" class="btn btn-secondary ai-generate-image-btn" data-ai-action="gen_wa_image" data-prompt-input="wa-image-prompt" data-status-target="wa-image-status" data-gallery-target="wa-image-gallery"><i class="fa-solid fa-image"></i> Generate WhatsApp Image with AI</button>
                                </div>
                                <p style="margin:0;color:#475569;">Use a WhatsApp-friendly prompt to generate and save media for broadcasts.</p>
                                <textarea id="wa-image-prompt" rows="2" placeholder="Type or paste the WhatsApp image prompt"></textarea>
                                <p id="wa-image-status" style="margin:0;color:#475569;font-size:0.95rem;"></p>
                                <div id="wa-image-gallery" class="media-gallery" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:0.75rem;">
                                  <?php if (empty($waMediaAssets)): ?>
                                    <p style="margin:0; color:#475569;">No generated WhatsApp images yet.</p>
                                  <?php else: ?>
                                    <?php foreach ($waMediaAssets as $asset): ?>
                                      <div class="media-thumb" style="border:1px solid #e2e8f0; border-radius:8px; padding:0.5rem; background:#fff;">
                                        <?php if (!empty($asset['file_url'])): ?>
                                          <a href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener"><img src="<?= smart_marketing_safe((string) $asset['file_url']) ?>" alt="Generated WhatsApp image" style="width:100%; height:160px; object-fit:cover; border-radius:6px;"></a>
                                        <?php else: ?>
                                          <div style="height:160px; background:#f8fafc; display:flex; align-items:center; justify-content:center; color:#94a3b8; border-radius:6px;">No preview</div>
                                        <?php endif; ?>
                                        <div style="margin-top:0.35rem;">
                                          <div style="font-weight:600; font-size:0.95rem;"><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></div>
                                          <div style="font-size:0.85rem; color:#475569;">Prompt: <?= smart_marketing_safe((string) ($asset['source_prompt'] ?? '')) ?></div>
                                          <?php if (!empty($asset['file_url'])): ?>
                                            <a href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener" style="font-size:0.9rem;">Open full image</a>
                                          <?php endif; ?>
                                        </div>
                                      </div>
                                    <?php endforeach; ?>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="ai-draft-block" style="margin-bottom:0.75rem;">
                            <div class="form-actions" style="justify-content:flex-start; gap:0.5rem;">
                              <button type="button" class="btn btn-secondary ai-generate-btn" data-ai-action="gen_channel_<?= smart_marketing_safe($channelKey) ?>" data-draft-id="draft-channel-<?= smart_marketing_safe($channelKey) ?>" data-channel-key="<?= smart_marketing_safe($channelKey) ?>" data-channel-container="channel-block-<?= smart_marketing_safe($channelKey) ?>"><i class="fa-solid fa-robot"></i> Generate <?= smart_marketing_safe(marketing_channels_labels()[$channelKey] ?? ucfirst($channelKey)) ?> Assets with AI</button>
                              <button type="button" class="btn btn-ghost ai-insert-btn" data-insert-for="draft-channel-<?= smart_marketing_safe($channelKey) ?>" data-channel-container="channel-block-<?= smart_marketing_safe($channelKey) ?>" disabled>Insert into <?= smart_marketing_safe(marketing_channels_labels()[$channelKey] ?? ucfirst($channelKey)) ?> fields</button>
                            </div>
                            <div class="ai-draft-box" id="draft-channel-<?= smart_marketing_safe($channelKey) ?>" aria-live="polite" style="min-height:70px;border:1px dashed #cbd5e1;padding:0.5rem;border-radius:6px;color:#0f172a;background:#f8fafc;"></div>
                          </div>
                          <div class="editor-grid">
                            <?php foreach ($fields as $fieldKey => $meta): ?>
                              <?php
                                $value = $formData['channel_assets'][$channelKey][$fieldKey] ?? '';
                                $textValue = is_array($value) ? implode("\n", $value) : (string) $value;
                              ?>
                              <label>
                                <?= smart_marketing_safe($meta['label']) ?>
                                <?php if ($meta['type'] === 'list'): ?>
                                  <textarea name="channel_assets[<?= smart_marketing_safe($channelKey) ?>][<?= smart_marketing_safe($fieldKey) ?>]" rows="3" placeholder="One entry per line"><?= smart_marketing_safe($textValue) ?></textarea>
                                <?php else: ?>
                                  <textarea name="channel_assets[<?= smart_marketing_safe($channelKey) ?>][<?= smart_marketing_safe($fieldKey) ?>]" rows="3" placeholder="Write copy for <?= smart_marketing_safe($meta['label']) ?>"><?= smart_marketing_safe($textValue) ?></textarea>
                                <?php endif; ?>
                              </label>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="action" value="save_campaign"><i class="fa-solid fa-floppy-disk"></i> Save Campaign</button>
                <?php if (($formData['id'] ?? '') !== ''): ?>
                  <span class="status-pill">Last updated: <?= smart_marketing_safe(marketing_format_datetime($formData['updated_at'] ?? null)) ?></span>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <?php if (($formData['id'] ?? '') !== ''): ?>
            <div class="card nested" id="media-assets">
              <div class="smart-marketing__header" style="margin:0 0 0.75rem;">
                <div>
                  <h4 style="margin:0;">Media Assets (files/links attached to this campaign)</h4>
                  <p style="margin:0;color:#475569;">Register creatives generated elsewhere by storing their links/paths and notes.</p>
                </div>
              </div>

              <div class="card nested" style="margin-bottom:1rem;">
                <h5 style="margin:0 0 0.5rem;">Add Media Asset</h5>
                <form method="post" class="editor-grid">
                  <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                  <input type="hidden" name="tab" value="campaigns" />
                  <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                  <label>
                    Title
                    <input type="text" name="media_title" required placeholder="Ranchi FB ad square creative" />
                  </label>
                  <label>
                    Use For
                    <select name="media_use_for">
                      <option value="facebook_instagram">Facebook / Instagram</option>
                      <option value="whatsapp_broadcast">WhatsApp</option>
                      <option value="both">Both</option>
                      <option value="other">Other</option>
                    </select>
                  </label>
                  <label>
                    File URL / Path
                    <input type="text" name="media_file_url" required placeholder="https://... or /uploads/creative1.jpg" />
                  </label>
                  <label>
                    Notes (optional)
                    <textarea name="media_notes" rows="2" placeholder="Generated in Canva using Prompt #2"></textarea>
                  </label>
                  <div class="form-actions" style="justify-content:flex-start;">
                    <button class="btn btn-secondary" type="submit" name="action" value="add_media_asset"><i class="fa-solid fa-plus"></i> Add Media Asset</button>
                  </div>
                </form>
              </div>

              <div>
                <h5 style="margin:0 0 0.5rem;">Saved Media Assets</h5>
                <?php if (empty($mediaAssetsSorted)): ?>
                  <p style="margin:0;">No media assets added yet.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table">
                      <thead>
                        <tr>
                          <th>Title</th>
                          <th>Use For</th>
                          <th>File URL / Path</th>
                          <th>Notes</th>
                          <th>Created At</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($mediaAssetsSorted as $asset): ?>
                          <tr>
                            <td><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></td>
                            <td><?= smart_marketing_safe(ucwords(str_replace('_', ' ', (string) ($asset['use_for'] ?? '')))) ?></td>
                            <td>
                              <?php $fileUrl = (string) ($asset['file_url'] ?? ''); ?>
                              <?php if ($fileUrl !== ''): ?>
                                <a href="<?= smart_marketing_safe($fileUrl) ?>" target="_blank" rel="noopener"><?= smart_marketing_safe($fileUrl) ?></a>
                              <?php else: ?>
                                —
                              <?php endif; ?>
                            </td>
                            <td><?= nl2br(smart_marketing_safe((string) ($asset['notes'] ?? ''))) ?></td>
                            <td><?= smart_marketing_safe(marketing_format_datetime($asset['created_at'] ?? null)) ?></td>
                            <td>
                              <form method="post" onsubmit="return confirm('Delete this media asset?');">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="campaigns" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                <input type="hidden" name="asset_id" value="<?= smart_marketing_safe((string) ($asset['id'] ?? '')) ?>" />
                                <button class="btn btn-ghost" type="submit" name="action" value="delete_media_asset"><i class="fa-solid fa-trash"></i></button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card nested" id="execution-tasks">
              <div class="smart-marketing__header" style="margin:0 0 0.75rem;">
                <div>
                  <h4 style="margin:0;">Execution Assistant (Tasks)</h4>
                  <p style="margin:0;color:#475569;">Track manual tasks for this campaign. Add, edit, update status, or delete as work progresses.</p>
                </div>
              </div>

              <?php
                $taskTotals = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'done' => 0];
                foreach ($executionTasksSorted as $task) {
                    $taskTotals['total']++;
                    $statusKey = (string) ($task['status'] ?? '');
                    if (isset($taskTotals[$statusKey])) {
                        $taskTotals[$statusKey]++;
                    }
                }
              ?>

              <?php if (empty($executionTasksSorted)): ?>
                <p style="margin:0 0 1rem;">No tasks added yet for this campaign.</p>
              <?php else: ?>
                <div class="status-pill" style="display:inline-flex; gap:0.5rem; align-items:center; background:rgba(15,23,42,0.06);">
                  <span>Total: <?= (int) $taskTotals['total'] ?></span>
                  <span>Pending: <?= (int) $taskTotals['pending'] ?></span>
                  <span>In Progress: <?= (int) $taskTotals['in_progress'] ?></span>
                  <span>Done: <?= (int) $taskTotals['done'] ?></span>
                </div>
              <?php endif; ?>

              <div class="card nested" style="margin-top:1rem;">
                <h5 style="margin:0 0 0.5rem;">Add New Task</h5>
                <form method="post" class="editor-grid">
                  <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                  <input type="hidden" name="tab" value="campaigns" />
                  <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                  <label>
                    Title
                    <input type="text" name="task_title" required placeholder="Create Meta ads for Ranchi" />
                  </label>
                  <label>
                    Description (optional)
                    <textarea name="task_description" rows="3" placeholder="More details, links, or creative notes"></textarea>
                  </label>
                  <div class="two-column">
                    <label>
                      Category
                      <select name="task_category">
                        <?php foreach (smart_marketing_task_categories() as $key => $label): ?>
                          <option value="<?= smart_marketing_safe($key) ?>"><?= smart_marketing_safe($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      Status
                      <select name="task_status">
                        <?php foreach (smart_marketing_task_statuses() as $key => $label): ?>
                          <option value="<?= smart_marketing_safe($key) ?>" <?= $key === 'pending' ? 'selected' : '' ?>><?= smart_marketing_safe($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <label>
                    Due date (optional)
                    <input type="date" name="task_due_date" />
                  </label>
                  <div class="form-actions" style="justify-content:flex-start;">
                    <button class="btn btn-secondary" type="submit" name="action" value="add_execution_task"><i class="fa-solid fa-plus"></i> Add Task</button>
                  </div>
                </form>
              </div>

              <div style="margin-top:1rem;">
                <h5 style="margin:0 0 0.5rem;">Tasks</h5>
                <?php if (empty($executionTasksSorted)): ?>
                  <p style="margin:0;">No tasks added yet.</p>
                <?php else: ?>
                  <div style="overflow-x:auto;">
                    <table>
                      <thead>
                        <tr>
                          <th>Title</th>
                          <th>Category</th>
                          <th>Status</th>
                          <th>Due Date</th>
                          <th>Last Updated</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($executionTasksSorted as $task): ?>
                          <tr>
                            <td>
                              <strong><?= smart_marketing_safe((string) ($task['title'] ?? '')) ?></strong>
                              <?php if (trim((string) ($task['description'] ?? '')) !== ''): ?>
                                <div style="color:#475569; font-size:0.95rem; margin-top:0.2rem; white-space:pre-wrap;"><?= smart_marketing_safe((string) ($task['description'] ?? '')) ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?= smart_marketing_safe(smart_marketing_task_categories()[$task['category'] ?? ''] ?? ucfirst((string) ($task['category'] ?? ''))) ?></td>
                            <td>
                              <form method="post" class="two-column" style="grid-template-columns:1fr auto; align-items:center; gap:0.5rem;">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="campaigns" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                <input type="hidden" name="task_id" value="<?= smart_marketing_safe((string) ($task['id'] ?? '')) ?>" />
                                <select name="task_status">
                                  <?php foreach (smart_marketing_task_statuses() as $statusKey => $statusLabel): ?>
                                    <option value="<?= smart_marketing_safe($statusKey) ?>" <?= ($task['status'] ?? 'pending') === $statusKey ? 'selected' : '' ?>><?= smart_marketing_safe($statusLabel) ?></option>
                                  <?php endforeach; ?>
                                </select>
                                <button class="btn btn-secondary" type="submit" name="action" value="update_execution_task_status"><i class="fa-solid fa-rotate"></i></button>
                              </form>
                            </td>
                            <td><?= smart_marketing_safe(trim((string) ($task['due_date'] ?? '')) !== '' ? (string) $task['due_date'] : '—') ?></td>
                            <td><?= smart_marketing_safe((string) ($task['updated_at'] ?? '')) ?></td>
                            <td style="display:flex; gap:0.5rem; align-items:center;">
                              <form method="post" onsubmit="return confirm('Delete this task?');">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="campaigns" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                <input type="hidden" name="task_id" value="<?= smart_marketing_safe((string) ($task['id'] ?? '')) ?>" />
                                <button class="btn btn-ghost" type="submit" name="action" value="delete_execution_task"><i class="fa-solid fa-trash"></i></button>
                              </form>
                            </td>
                          </tr>
                          <tr>
                            <td colspan="6">
                              <details>
                                <summary style="cursor:pointer; font-weight:600; color:#0f172a;">Edit task</summary>
                                <form method="post" class="editor-grid" style="margin-top:0.75rem;">
                                  <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                  <input type="hidden" name="tab" value="campaigns" />
                                  <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                  <input type="hidden" name="task_id" value="<?= smart_marketing_safe((string) ($task['id'] ?? '')) ?>" />
                                  <label>
                                    Title
                                    <input type="text" name="task_title" required value="<?= smart_marketing_safe((string) ($task['title'] ?? '')) ?>" />
                                  </label>
                                  <label>
                                    Description (optional)
                                    <textarea name="task_description" rows="3" placeholder="More details, links, or creative notes"><?= smart_marketing_safe((string) ($task['description'] ?? '')) ?></textarea>
                                  </label>
                                  <div class="two-column">
                                    <label>
                                      Category
                                      <select name="task_category">
                                        <?php foreach (smart_marketing_task_categories() as $key => $label): ?>
                                          <option value="<?= smart_marketing_safe($key) ?>" <?= ($task['category'] ?? 'other') === $key ? 'selected' : '' ?>><?= smart_marketing_safe($label) ?></option>
                                        <?php endforeach; ?>
                                      </select>
                                    </label>
                                    <label>
                                      Status
                                      <select name="task_status">
                                        <?php foreach (smart_marketing_task_statuses() as $key => $label): ?>
                                          <option value="<?= smart_marketing_safe($key) ?>" <?= ($task['status'] ?? 'pending') === $key ? 'selected' : '' ?>><?= smart_marketing_safe($label) ?></option>
                                        <?php endforeach; ?>
                                      </select>
                                    </label>
                                  </div>
                                  <label>
                                    Due date (optional)
                                    <input type="date" name="task_due_date" value="<?= smart_marketing_safe((string) ($task['due_date'] ?? '')) ?>" />
                                  </label>
                                  <div class="form-actions" style="justify-content:flex-start;">
                                    <button class="btn btn-primary" type="submit" name="action" value="edit_execution_task"><i class="fa-solid fa-floppy-disk"></i> Save Task</button>
                                  </div>
                                </form>
                              </details>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card nested" id="whatsapp">
              <div class="smart-marketing__header" style="margin:0 0 1rem;">
                <div>
                  <h4 style="margin:0;">WhatsApp Packs</h4>
                  <p style="margin:0;color:#475569;">Create WhatsApp message packs for broadcast or follow-ups. These only generate draft messages & links; nothing is auto-sent.</p>
                </div>
              </div>
              <form method="post" style="margin-bottom:1rem;">
                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                <input type="hidden" name="tab" value="campaigns" />
                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                <div class="two-column">
                  <label>
                    Label
                    <input type="text" name="pack_label" placeholder="Cold outreach – Ranchi residential" required />
                  </label>
                  <label>
                    Pack Type
                    <select name="pack_type">
                      <?php $packTypes = ['Cold outreach', 'Lead follow-up', 'Warm follow-up', 'Festive offer', 'Custom']; ?>
                      <?php foreach ($packTypes as $type): ?>
                        <option value="<?= smart_marketing_safe($type) ?>"><?= smart_marketing_safe($type) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div class="two-column">
                  <label>
                    Language preference
                    <select name="pack_language">
                      <?php foreach (['English', 'Hindi', 'Mixed'] as $language): ?>
                        <option value="<?= smart_marketing_safe($language) ?>"><?= smart_marketing_safe($language) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>
                    Notes for AI (optional)
                    <textarea name="pack_notes" rows="3" placeholder="Tone, urgency, follow-up context"></textarea>
                  </label>
                </div>
                <div class="form-actions" style="justify-content:flex-start;">
                  <button class="btn btn-secondary" type="submit" name="action" value="generate_whatsapp_pack"><i class="fa-solid fa-message"></i> Generate WhatsApp Pack with AI</button>
                </div>
              </form>

              <?php if (empty($whatsappPacksSorted)): ?>
                <p>No WhatsApp packs yet. Generate one using the form above.</p>
              <?php else: ?>
                <div class="pack-grid">
                  <?php foreach ($whatsappPacksSorted as $pack): ?>
                    <div class="pack-card">
                      <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem;">
                        <div>
                          <h5 style="margin:0;"><?= smart_marketing_safe((string) ($pack['label'] ?? '')) ?></h5>
                          <p style="margin:0;color:#475569; font-size:0.95rem;">Type: <?= smart_marketing_safe((string) ($pack['pack_type'] ?? '')) ?> · Created <?= smart_marketing_safe((string) ($pack['created_at'] ?? '')) ?></p>
                        </div>
                        <form method="post" onsubmit="return confirm('Delete this WhatsApp pack?');">
                          <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                          <input type="hidden" name="tab" value="campaigns" />
                          <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                          <input type="hidden" name="pack_id" value="<?= smart_marketing_safe((string) ($pack['id'] ?? '')) ?>" />
                          <button class="btn btn-ghost" type="submit" name="action" value="delete_whatsapp_pack"><i class="fa-solid fa-trash"></i></button>
                        </form>
                      </div>
                      <?php foreach ($pack['messages'] ?? [] as $index => $message): ?>
                        <?php $linkId = 'wa_link_' . smart_marketing_slug((string) ($pack['id'] ?? 'pack')) . '_' . $index; ?>
                        <div class="whatsapp-message">
                          <label>Message <?= $index + 1 ?>
                            <textarea rows="3" readonly><?= smart_marketing_safe((string) $message) ?></textarea>
                          </label>
                          <div class="two-column" style="align-items:end;">
                            <label>Mobile (optional)
                              <input type="text" class="wa-phone-input" data-link-target="<?= $linkId ?>" placeholder="91XXXXXXXXXX" value="<?= smart_marketing_safe(get_brand_field('whatsapp_number')) ?>" />
                            </label>
                            <?php $encodedMessage = rawurlencode((string) $message); ?>
                            <div>
                              <a id="<?= $linkId ?>" data-base="<?= smart_marketing_safe((string) $message) ?>" href="https://wa.me/?text=<?= $encodedMessage ?>" class="btn btn-secondary" target="_blank" rel="noopener">Open WhatsApp Draft</a>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="card nested" id="offline">
              <div class="smart-marketing__header" style="margin:0 0 1rem;">
                <div>
                  <h4 style="margin:0;">Offline Printables</h4>
                  <p style="margin:0;color:#475569;">Generate printable drafts like pamphlets or hoardings using templates and AI copy.</p>
                </div>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                <input type="hidden" name="tab" value="campaigns" />
                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                <div class="two-column">
                  <label>
                    Asset Type
                    <select name="offline_type">
                      <option value="pamphlet">Pamphlet</option>
                      <option value="hoarding">Hoarding</option>
                      <option value="newspaper_ad">Newspaper ad</option>
                      <option value="standee">Standee</option>
                    </select>
                  </label>
                  <label>
                    Title for this asset
                    <input type="text" name="offline_title" placeholder="Residential rooftop pamphlet – Ranchi" required />
                  </label>
                </div>
                <div class="two-column">
                  <label>
                    Size / Dimension notes
                    <textarea name="offline_dimension" rows="3" placeholder="e.g., A4 double side, 12x8 ft hoarding"></textarea>
                  </label>
                  <label style="align-self:end; display:flex; gap:0.5rem;">
                    <input type="checkbox" id="offline_use_ai" name="offline_use_ai" value="1" checked />
                    <span>Use AI to propose copy from channel assets</span>
                  </label>
                </div>
                <div class="form-actions" style="justify-content:flex-start;">
                  <button class="btn btn-secondary" type="submit" name="action" value="generate_offline_asset"><i class="fa-solid fa-file-lines"></i> Generate Offline Asset Draft</button>
                </div>
              </form>

              <div class="offline-list">
                <?php if (empty($offlineAssetsSorted)): ?>
                  <p>No offline assets generated yet.</p>
                <?php else: ?>
                  <div style="overflow-x:auto;">
                    <table>
                      <thead>
                        <tr>
                          <th>Title</th>
                          <th>Type</th>
                          <th>Created At</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($offlineAssetsSorted as $asset): ?>
                          <tr>
                            <td><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></td>
                            <td><?= smart_marketing_safe(ucwords(str_replace('_', ' ', (string) ($asset['type'] ?? '')))) ?></td>
                            <td><?= smart_marketing_safe((string) ($asset['created_at'] ?? '')) ?></td>
                            <td style="display:flex; gap:0.5rem; align-items:center;">
                              <?php if (!empty($asset['html_path'])): ?>
                                <a class="btn btn-secondary" href="<?= smart_marketing_safe((string) $asset['html_path']) ?>" target="_blank" rel="noopener">View HTML</a>
                              <?php endif; ?>
                              <?php if (!empty($asset['pdf_path'])): ?>
                                <a class="btn btn-secondary" href="<?= smart_marketing_safe((string) $asset['pdf_path']) ?>" target="_blank" rel="noopener">Download PDF</a>
                              <?php endif; ?>
                              <form method="post" onsubmit="return confirm('Delete this offline asset?');">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="campaigns" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                <input type="hidden" name="asset_id" value="<?= smart_marketing_safe((string) ($asset['id'] ?? '')) ?>" />
                                <button class="btn btn-ghost" type="submit" name="action" value="delete_offline_asset"><i class="fa-solid fa-trash"></i></button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card nested" id="performance">
              <div class="smart-marketing__header" style="margin:0 0 1rem;">
                <div>
                  <h4 style="margin:0;">Performance & Notes</h4>
                  <p style="margin:0;color:#475569;">Track manual performance metrics for this campaign.</p>
                </div>
              </div>
              <div class="summary-grid" style="margin-bottom:1rem;">
                <div class="summary-card"><h3>Total Spend (₹)</h3><p><?= number_format((float) ($performanceTotals['spend'] ?? 0), 2) ?></p></div>
                <div class="summary-card"><h3>Total Leads</h3><p><?= (int) ($performanceTotals['leads'] ?? 0) ?></p></div>
                <div class="summary-card"><h3>Cost per Lead</h3><p><?= ($performanceTotals['cpl'] ?? null) !== null ? '₹' . number_format((float) $performanceTotals['cpl'], 2) : '—' ?></p></div>
              </div>

              <form method="post" style="margin-bottom:1rem;">
                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                <input type="hidden" name="tab" value="campaigns" />
                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                <div class="two-column">
                  <label>Date<input type="date" name="perf_date" required /></label>
                  <label>
                    Channel
                    <select name="perf_channel" required>
                      <?php
                        $channelOptions = !empty($formData['channels']) ? $formData['channels'] : array_keys(marketing_channels_labels());
                        foreach ($channelOptions as $channelKey):
                          $label = marketing_channels_labels()[$channelKey] ?? $channelKey;
                      ?>
                        <option value="<?= smart_marketing_safe($channelKey) ?>"><?= smart_marketing_safe($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
                <div class="two-column">
                  <label>Impressions<input type="number" name="perf_impressions" min="0" /></label>
                  <label>Clicks<input type="number" name="perf_clicks" min="0" /></label>
                </div>
                <div class="two-column">
                  <label>Leads<input type="number" name="perf_leads" min="0" /></label>
                  <label>Spend (₹)<input type="number" step="0.01" name="perf_spend" min="0" /></label>
                </div>
                <label>Notes<textarea name="perf_notes" rows="3" placeholder="Ran for 3 days in Ranchi..."></textarea></label>
                <div class="form-actions" style="justify-content:flex-start;">
                  <button class="btn btn-secondary" type="submit" name="action" value="add_performance_log"><i class="fa-solid fa-clipboard-list"></i> Add Performance Log</button>
                </div>
              </form>

              <div class="performance-table-wrapper">
                <?php if (empty($formData['performance_logs'])): ?>
                  <p>No performance logs yet.</p>
                <?php else: ?>
                  <div style="overflow-x:auto;">
                    <table>
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>Channel</th>
                          <th>Impressions</th>
                          <th>Clicks</th>
                          <th>Leads</th>
                          <th>Spend (₹)</th>
                          <th>Notes</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($formData['performance_logs'] as $log): ?>
                          <tr>
                            <td><?= smart_marketing_safe((string) ($log['date'] ?? '')) ?></td>
                            <td><?= smart_marketing_safe(marketing_channels_labels()[$log['channel'] ?? ''] ?? ($log['channel'] ?? '')) ?></td>
                            <td><?= (int) ($log['impressions'] ?? 0) ?></td>
                            <td><?= (int) ($log['clicks'] ?? 0) ?></td>
                            <td><?= (int) ($log['leads'] ?? 0) ?></td>
                            <td><?= number_format((float) ($log['spend_rs'] ?? 0), 2) ?></td>
                            <td><?= smart_marketing_safe((string) ($log['notes'] ?? '')) ?></td>
                            <td>
                              <form method="post" onsubmit="return confirm('Delete this log entry?');">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="campaigns" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($formData['id'] ?? '')) ?>" />
                                <input type="hidden" name="log_id" value="<?= smart_marketing_safe((string) ($log['id'] ?? '')) ?>" />
                                <button class="btn btn-ghost" type="submit" name="action" value="delete_performance_log"><i class="fa-solid fa-trash"></i></button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <p>Save the campaign to access WhatsApp packs, offline printables, and performance tracking.</p>
          <?php endif; ?>
        </section>
      <?php elseif ($tab === 'assets'): ?>
        <section class="card" aria-labelledby="assets-heading">
          <div class="smart-marketing__header" style="margin-bottom:1rem;">
            <div>
              <h2 id="assets-heading" style="margin:0;">Smart Marketing CMO – Assets</h2>
              <p style="margin:0;color:#475569;">Browse generated media and offline assets from all campaigns.</p>
            </div>
          </div>

          <div class="card nested">
            <h3 style="margin-top:0;">Campaign-wise assets summary</h3>
            <?php if (empty($campaigns)): ?>
              <p style="margin:0;">No campaigns found. Create campaigns in the Campaigns tab first.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>Campaign Name</th>
                      <th>Campaign ID</th>
                      <th>Media Assets</th>
                      <th>Offline Assets</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($assetsSummary as $summary): ?>
                      <tr>
                        <td><?= smart_marketing_safe($summary['name']) ?></td>
                        <td><?= smart_marketing_safe($summary['id']) ?></td>
                        <td><?= (int) $summary['media_count'] ?></td>
                        <td><?= (int) $summary['offline_count'] ?></td>
                        <td>
                          <a class="btn btn-secondary" href="?tab=assets&campaign_id=<?= rawurlencode((string) $summary['id']) ?>">View Assets</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($selectedCampaignId !== '' && $assetsSelectedCampaign === null): ?>
            <div class="card nested" style="margin-top:1rem;">
              <p style="margin:0;">Selected campaign not found.</p>
            </div>
          <?php endif; ?>

          <?php if ($assetsSelectedCampaign !== null): ?>
            <?php $mediaAssets = $assetsSelectedCampaign['media_assets'] ?? []; ?>
            <?php $offlineAssets = $assetsSelectedCampaign['offline_assets'] ?? []; ?>
            <div class="card nested" style="margin-top:1rem;">
              <h3 style="margin-top:0;">Assets for Campaign: <?= smart_marketing_safe((string) ($assetsSelectedCampaign['name'] ?? '')) ?> (<?= smart_marketing_safe((string) ($assetsSelectedCampaign['id'] ?? '')) ?>)</h3>
              <p style="margin:0 0 1rem;color:#475569;">Media: <?= count($mediaAssets) ?> · Offline: <?= count($offlineAssets) ?></p>

              <div id="campaign-media-assets">
                <h4 style="margin:0 0 0.5rem;">Media Files (Images)</h4>
                <?php if (empty($mediaAssets)): ?>
                  <p>No media assets for this campaign yet.</p>
                <?php else: ?>
                  <div class="channel-assets-grid">
                    <?php foreach ($mediaAssets as $asset): ?>
                      <?php $thumb = (string) ($asset['thumbnail_url'] ?? ($asset['file_url'] ?? '')); ?>
                      <div class="channel-asset-block">
                        <?php if ($thumb !== ''): ?>
                          <img src="<?= smart_marketing_safe($thumb) ?>" alt="Media asset thumbnail" style="width:100%;max-width:220px;height:150px;object-fit:cover;border-radius:0.75rem;margin-bottom:0.5rem;" />
                        <?php endif; ?>
                        <div style="display:flex;flex-direction:column;gap:0.35rem;">
                          <strong><?= smart_marketing_safe((string) ($asset['title'] ?? 'Untitled media')) ?></strong>
                          <span style="color:#475569; font-size:0.95rem;">Channel / Use For: <?= smart_marketing_safe(smart_marketing_asset_channel_label($asset)) ?></span>
                          <span style="color:#475569; font-size:0.95rem;">Created: <?= smart_marketing_safe(marketing_format_datetime((string) ($asset['created_at'] ?? ''))) ?></span>
                          <?php if (!empty($asset['source_prompt'])): ?>
                            <small style="color:#475569;">Source Prompt: <?= smart_marketing_safe((string) $asset['source_prompt']) ?></small>
                          <?php endif; ?>
                        </div>

                        <?php if (!empty($asset['file_url'])): ?>
                          <div style="margin-top:0.5rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                            <a class="btn btn-secondary" href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener">Open full image</a>
                            <button type="button" class="btn btn-secondary copy-url-btn" data-url="<?= smart_marketing_safe((string) $asset['file_url']) ?>">Copy URL</button>
                          </div>
                        <?php endif; ?>

                        <form method="post" onsubmit="return confirm('Delete this media asset?');" style="margin-top:0.75rem;">
                          <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                          <input type="hidden" name="tab" value="assets" />
                          <input type="hidden" name="redirect_tab" value="assets" />
                          <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($assetsSelectedCampaign['id'] ?? '')) ?>" />
                          <input type="hidden" name="asset_id" value="<?= smart_marketing_safe((string) ($asset['id'] ?? '')) ?>" />
                          <button class="btn btn-ghost" type="submit" name="action" value="delete_media_asset"><i class="fa-solid fa-trash"></i> Delete</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div id="campaign-offline-assets" style="margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem;">Offline Assets (HTML / PDF)</h4>
                <?php if (empty($offlineAssets)): ?>
                  <p>No offline assets for this campaign yet.</p>
                <?php else: ?>
                  <div style="overflow-x:auto;">
                    <table>
                      <thead>
                        <tr>
                          <th>Title</th>
                          <th>Type</th>
                          <th>Created At</th>
                          <th>HTML Link</th>
                          <th>PDF Link</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($offlineAssets as $asset): ?>
                          <tr>
                            <td><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></td>
                            <td><?= smart_marketing_safe(ucwords(str_replace('_', ' ', (string) ($asset['type'] ?? '')))) ?></td>
                            <td><?= smart_marketing_safe(marketing_format_datetime((string) ($asset['created_at'] ?? ''))) ?></td>
                            <td>
                              <?php if (!empty($asset['html_path'])): ?>
                                <a class="btn btn-secondary" href="<?= smart_marketing_safe((string) $asset['html_path']) ?>" target="_blank" rel="noopener">View HTML</a>
                              <?php else: ?>
                                —
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if (!empty($asset['pdf_path'])): ?>
                                <a class="btn btn-secondary" href="<?= smart_marketing_safe((string) $asset['pdf_path']) ?>" target="_blank" rel="noopener">Open PDF</a>
                              <?php else: ?>
                                —
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($assetsAllMedia)): ?>
            <div class="card nested" style="margin-top:1rem;">
              <h3 style="margin-top:0;">All Media</h3>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>Campaign Name</th>
                      <th>Media Title</th>
                      <th>Channel</th>
                      <th>File URL</th>
                      <th>Created At</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($assetsAllMedia as $asset): ?>
                      <tr>
                        <td><?= smart_marketing_safe((string) ($asset['campaign_name'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe((string) ($asset['title'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe(smart_marketing_asset_channel_label($asset)) ?></td>
                        <td>
                          <?php if (!empty($asset['file_url'])): ?>
                            <a href="<?= smart_marketing_safe((string) $asset['file_url']) ?>" target="_blank" rel="noopener">Open</a>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td><?= smart_marketing_safe(marketing_format_datetime((string) ($asset['created_at'] ?? ''))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php elseif ($tab === 'insights'): ?>
        <section class="card" aria-labelledby="insights-heading">
          <div class="smart-marketing__header" style="margin-bottom:1rem;">
            <div>
              <h2 id="insights-heading" style="margin:0;">Insights</h2>
              <p style="margin:0;color:#475569;">Track portfolio performance and generate AI recommendations grounded in saved data.</p>
            </div>
          </div>

          <?php if (empty($campaigns)): ?>
            <p>No campaigns yet. Create campaigns first in the Campaigns tab to see insights here.</p>
          <?php else: ?>
            <div class="summary-grid" style="margin-bottom:1rem;">
              <div class="summary-card"><h3>Total Campaigns</h3><p><?= $campaignCount ?></p></div>
              <div class="summary-card"><h3>Drafts</h3><p><?= $insightsStatusCounts['draft'] ?></p></div>
              <div class="summary-card"><h3>Planned</h3><p><?= $insightsStatusCounts['planned'] ?></p></div>
              <div class="summary-card"><h3>Running</h3><p><?= $insightsStatusCounts['running'] ?></p></div>
              <div class="summary-card"><h3>Completed</h3><p><?= $insightsStatusCounts['completed'] ?></p></div>
              <div class="summary-card"><h3>Total Spend (₹)</h3><p><?= number_format((float) $insightsTotals['spend'], 2) ?></p></div>
              <div class="summary-card"><h3>Total Leads</h3><p><?= (int) $insightsTotals['leads'] ?></p></div>
              <div class="summary-card"><h3>Avg. Cost per Lead</h3><p><?= ($insightsTotals['cpl'] ?? null) !== null ? '₹' . number_format((float) $insightsTotals['cpl'], 2) : '—' ?></p></div>
              <div class="summary-card"><h3>Type Mix</h3><p style="font-size:1rem;">Online: <?= $insightsTypeCounts['online'] ?> · Offline: <?= $insightsTypeCounts['offline'] ?> · Mixed: <?= $insightsTypeCounts['mixed'] ?></p></div>
            </div>

            <?php
            $insightsMetaCampaigns = array_values(array_filter($campaigns, static function (array $campaign): bool {
                $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto'];
                return (string) ($metaAuto['meta_campaign_id'] ?? '') !== '';
            }));
            ?>

            <div class="card nested" style="margin-top:1rem;">
              <h3 style="margin-top:0;">Meta Campaign Performance (From Meta Auto)</h3>
              <?php if (empty($insightsMetaCampaigns)): ?>
                <p style="margin:0;">No Meta-linked campaigns yet. Meta insights will appear here once campaigns are created and insights are fetched.</p>
              <?php else: ?>
                <div style="overflow-x:auto;">
                  <table>
                    <thead>
                      <tr>
                        <th>Campaign</th>
                        <th>Latest Meta Insights</th>
                        <th>Last Sync</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($insightsMetaCampaigns as $campaign): ?>
                        <?php $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto']; ?>
                        <?php
                          $summary = trim((string) ($metaAuto['last_insights_summary'] ?? ''));
                          $lastSync = trim((string) ($metaAuto['last_insights_sync'] ?? ''));
                        ?>
                        <tr>
                          <td><?= smart_marketing_safe((string) ($campaign['name'] ?? 'Untitled')) ?></td>
                          <td>
                            <?php if ($summary !== ''): ?>
                              <?= smart_marketing_safe($summary) ?>
                            <?php else: ?>
                              <span>No Meta insights fetched yet. Go to Meta Auto tab to fetch.</span>
                            <?php endif; ?>
                          </td>
                          <td><?= $lastSync !== '' ? smart_marketing_safe(marketing_format_datetime($lastSync)) : 'Never' ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

            <div class="card nested" style="margin-top:1rem;">
              <h3 style="margin-top:0;">Campaign insights</h3>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>Campaign Name</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Channels</th>
                      <th>Total Spend (₹)</th>
                      <th>Total Leads</th>
                      <th>Cost per Lead</th>
                      <th>Last Updated</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($insightsCampaignSummaries as $row): ?>
                      <tr>
                        <td><?= smart_marketing_safe((string) ($row['name'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe(ucfirst((string) ($row['type'] ?? ''))) ?></td>
                        <td><span class="status-pill"><?= smart_marketing_safe(ucfirst((string) ($row['status'] ?? ''))) ?></span></td>
                        <td><?= smart_marketing_safe((string) ($row['channels'] ?? '')) ?></td>
                        <td><?= number_format((float) ($row['spend'] ?? 0), 2) ?></td>
                        <td><?= (int) ($row['leads'] ?? 0) ?></td>
                        <td><?= ($row['cpl'] ?? null) !== null ? '₹' . number_format((float) $row['cpl'], 2) : '—' ?></td>
                        <td><?= smart_marketing_safe(marketing_format_datetime($row['updated_at'] ?? null)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card nested" style="margin-top:1.25rem;">
              <div class="smart-marketing__header" style="margin-bottom:1rem;">
                <div>
                  <h3 style="margin:0;">AI Insights & Recommendations</h3>
                  <p style="margin:0;color:#475569;">Gemini will use your saved campaigns and performance logs to propose prioritized actions.</p>
                </div>
              </div>
              <form method="post" style="display:grid; gap:1rem;">
                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                <input type="hidden" name="tab" value="insights" />
                <div class="insights-form-grid">
                  <label>
                    Scope
                    <select name="insights_scope">
                      <option value="all" <?= $insightsScope === 'all' ? 'selected' : '' ?>>All campaigns</option>
                      <?php foreach ($campaigns as $campaign): ?>
                        <option value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" <?= $insightsScope === (string) ($campaign['id'] ?? '') ? 'selected' : '' ?>><?= smart_marketing_safe((string) ($campaign['name'] ?? 'Untitled')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>
                    Time Emphasis
                    <select name="insights_time">
                      <option value="30" <?= $insightsTimeEmphasis === '30' ? 'selected' : '' ?>>Last 30 days</option>
                      <option value="90" <?= $insightsTimeEmphasis === '90' ? 'selected' : '' ?>>Last 90 days</option>
                      <option value="all" <?= $insightsTimeEmphasis === 'all' ? 'selected' : '' ?>>All available logs</option>
                    </select>
                  </label>
                  <label>
                    Focus Area(s)
                    <span class="insights-checkboxes">
                      <?php foreach ($insightsFocusOptions as $key => $label): ?>
                        <span class="checkbox-row">
                          <input type="checkbox" id="focus_<?= smart_marketing_safe($key) ?>" name="insights_focus[]" value="<?= smart_marketing_safe($key) ?>" <?= in_array($key, $insightsFocus, true) ? 'checked' : '' ?> />
                          <label for="focus_<?= smart_marketing_safe($key) ?>" style="font-weight:500;">
                            <?= smart_marketing_safe($label) ?>
                          </label>
                        </span>
                      <?php endforeach; ?>
                    </span>
                  </label>
                </div>
                <label>
                  Additional Notes for AI (optional)
                  <textarea name="insights_notes" rows="3" placeholder="Anything to highlight for this request?"><?= smart_marketing_safe($insightsNotes) ?></textarea>
                </label>
                <div class="form-actions" style="justify-content:flex-start;">
                  <button class="btn btn-primary" type="submit" name="action" value="generate_insights_ai"><i class="fa-solid fa-bolt"></i> Generate AI Insights & Recommendations</button>
                </div>
              </form>

              <div class="ai-insights-output" style="margin-top:1rem;">
                <?php if ($aiInsightsOutput === ''): ?>
                  <p class="ai-insights-placeholder" style="margin:0;">No AI insights generated yet. Select scope and click “Generate AI Insights & Recommendations”.</p>
                <?php else: ?>
                  <?= nl2br(smart_marketing_safe($aiInsightsOutput)) ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php elseif ($tab === 'meta_auto'): ?>
        <section class="card" aria-labelledby="meta-auto-heading">
          <div class="smart-marketing__header" style="margin-bottom:1rem;">
            <div>
              <h2 id="meta-auto-heading" style="margin:0;">Smart Marketing CMO – Meta Auto Integration (Phase B1)</h2>
              <p style="margin:0;color:#475569;">Configure Meta Marketing API settings and launch a minimal 1–1–1 Meta campaign automatically from a CMO campaign.</p>
            </div>
          </div>

          <div class="card nested">
            <h3 style="margin-top:0;">Meta Integration Settings</h3>
            <form method="post" style="display:grid; gap:1rem;">
              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
              <input type="hidden" name="tab" value="meta_auto" />
              <input type="hidden" name="meta_auto_action" value="save_settings" />
              <label style="display:flex; align-items:center; gap:0.5rem;">
                <input type="checkbox" name="enabled" value="1" <?= !empty($metaIntegrationSettings['enabled']) ? 'checked' : '' ?> />
                <span style="font-weight:600;">Enable Meta Automation (flag only)</span>
              </label>

              <div class="two-column">
                <label>Meta App ID<input type="text" name="meta_app_id" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['meta_app_id'] ?? '')) ?>" /></label>
                <label>Meta App Secret<input type="password" name="meta_app_secret" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['meta_app_secret'] ?? '')) ?>" /></label>
              </div>
              <div class="two-column">
                <label>Meta Access Token (long-lived)<input type="password" name="meta_access_token" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['meta_access_token'] ?? '')) ?>" /></label>
                <label>Ad Account ID
                  <input type="text" name="ad_account_id" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['ad_account_id'] ?? '')) ?>" placeholder="act_123456789012345" />
                  <small style="color:#475569;">Enter either the full ID (act_123…) or just the numeric part (123…).</small>
                </label>
              </div>
              <div class="two-column">
                <label>Business ID (optional)<input type="text" name="business_id" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['business_id'] ?? '')) ?>" /></label>
                <label>Default Facebook Page ID<input type="text" name="default_page_id" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['default_page_id'] ?? '')) ?>" /></label>
              </div>
              <label>Default Instagram Account ID<input type="text" name="default_ig_account_id" value="<?= smart_marketing_safe((string) ($metaIntegrationSettings['default_ig_account_id'] ?? '')) ?>" /></label>
              <div class="form-actions" style="justify-content:flex-start;">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Meta Settings</button>
              </div>
            </form>
          </div>

          <div class="card nested" style="margin-top:1rem;">
            <div class="smart-marketing__header" style="margin-bottom:0.75rem;">
              <div>
                <h3 style="margin:0;">Test Meta Connection</h3>
                <p style="margin:0;color:#475569;">This test will use the configured Access Token and Ad Account ID to query Meta’s Marketing API and verify that the connection works.</p>
              </div>
            </div>
            <form method="post" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
              <input type="hidden" name="tab" value="meta_auto" />
              <input type="hidden" name="meta_auto_action" value="test_connection" />
              <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-plug-circle-check"></i> Run Test Now</button>
            </form>

            <?php if ($metaTestResult !== null): ?>
              <?php if (($metaTestResult['status'] ?? '') === 'success'): ?>
                <div class="flash success">
                  <i class="fa-solid fa-circle-check"></i>
                  <div>
                    <p style="margin:0 0 0.35rem 0; font-weight:600;">Meta connection successful.</p>
                    <p style="margin:0;">Ad Account ID: <?= smart_marketing_safe((string) ($metaTestResult['data']['id'] ?? '')) ?></p>
                    <p style="margin:0;">Name: <?= smart_marketing_safe((string) ($metaTestResult['data']['name'] ?? '')) ?></p>
                    <p style="margin:0;">Currency: <?= smart_marketing_safe((string) ($metaTestResult['data']['currency'] ?? '')) ?></p>
                    <p style="margin:0;">Account status: <?= smart_marketing_safe((string) ($metaTestResult['data']['account_status'] ?? '')) ?></p>
                  </div>
                </div>
              <?php else: ?>
                <div class="flash error">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                  <div>
                    <p style="margin:0 0 0.35rem 0; font-weight:600;">Meta API connection failed.</p>
                    <?php if (!empty($metaTestResult['message'])): ?>
                      <p style="margin:0;"><?= smart_marketing_safe((string) $metaTestResult['message']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($metaTestResult['details'])): ?>
                      <p style="margin:0;">Details: <?= smart_marketing_safe((string) $metaTestResult['details']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($metaTestResult['response'])): ?>
                      <p style="margin:0;">Response snippet: <?= smart_marketing_safe((string) $metaTestResult['response']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if ($metaAutoCreationResult !== null): ?>
            <div class="flash success" style="margin-top:1rem;">
              <i class="fa-solid fa-circle-check"></i>
              <div>
                <p style="margin:0 0 0.35rem 0; font-weight:600;">Meta campaign created successfully.</p>
                <p style="margin:0;">Campaign ID: <?= smart_marketing_safe((string) ($metaAutoCreationResult['meta_campaign_id'] ?? '')) ?></p>
                <p style="margin:0;">Ad Set ID: <?= smart_marketing_safe((string) ($metaAutoCreationResult['meta_adset_id'] ?? '')) ?></p>
                <p style="margin:0;">Ad ID: <?= smart_marketing_safe((string) ($metaAutoCreationResult['meta_ad_id'] ?? '')) ?></p>
                <p style="margin:0;">Status: <?= smart_marketing_safe((string) ($metaAutoCreationResult['status_snapshot'] ?? 'PAUSED')) ?> (review and activate in Meta Ads Manager)</p>
              </div>
            </div>
          <?php endif; ?>

          <div class="card nested" style="margin-top:1rem;">
            <div class="smart-marketing__header" style="margin-bottom:0.75rem;">
              <div>
                <h3 style="margin:0;">Manage Meta Campaigns Created by CMO (Phase B1.5)</h3>
                <p style="margin:0;color:#475569;">Sync status, pause/start, or adjust budgets for Meta campaigns linked to CMO campaigns.</p>
              </div>
            </div>

            <?php
            $campaignsWithMeta = array_values(array_filter($campaigns, static function (array $campaign): bool {
                $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto'];
                return (string) ($metaAuto['meta_campaign_id'] ?? '') !== '';
            }));
            ?>

            <?php if (empty($campaignsWithMeta)): ?>
              <p>No Meta-linked campaigns found. Create a Meta campaign first using the launcher below.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>CMO Campaign Name</th>
                      <th>CMO Campaign ID</th>
                      <th>Meta Campaign ID</th>
                      <th>Meta Ad Set ID</th>
                      <th>Meta Ad ID</th>
                      <th>Last Status Snapshot</th>
                      <th>Last Status Sync Time</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($campaignsWithMeta as $campaign): ?>
                      <?php $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto']; ?>
                      <?php
                        $snapshot = trim((string) ($metaAuto['status_snapshot'] ?? ''));
                        $lastSync = trim((string) ($metaAuto['last_status_sync'] ?? ''));
                      ?>
                      <tr>
                        <td><?= smart_marketing_safe((string) ($campaign['name'] ?? 'Untitled')) ?></td>
                        <td><?= smart_marketing_safe((string) ($campaign['tracking_code'] ?? ($campaign['id'] ?? '—'))) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_campaign_id'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_adset_id'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_ad_id'] ?? '')) ?></td>
                        <td><?= $snapshot !== '' ? smart_marketing_safe($snapshot) : 'Not synced yet' ?></td>
                        <td><?= $lastSync !== '' ? smart_marketing_safe(marketing_format_datetime($lastSync)) : 'Never' ?></td>
                        <td>
                          <div style="display:flex; flex-direction:column; gap:0.35rem;">
                            <form method="post" style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                              <input type="hidden" name="tab" value="meta_auto" />
                              <input type="hidden" name="meta_auto_action" value="sync_status" />
                              <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                              <button class="btn btn-secondary" type="submit">Sync Status</button>
                            </form>

                            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                              <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="meta_auto" />
                                <input type="hidden" name="meta_auto_action" value="pause_all" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                                <button class="btn" type="submit">Pause All</button>
                              </form>
                              <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                                <input type="hidden" name="tab" value="meta_auto" />
                                <input type="hidden" name="meta_auto_action" value="start_all" />
                                <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                                <button class="btn btn-primary" type="submit">Start All</button>
                              </form>
                            </div>

                            <form method="post" style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                              <input type="hidden" name="tab" value="meta_auto" />
                              <input type="hidden" name="meta_auto_action" value="change_budget" />
                              <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                              <label style="display:flex; align-items:center; gap:0.25rem;">
                                <span style="font-weight:600;">New Daily Budget (₹)</span>
                                <input type="number" name="new_daily_budget_rs" min="1" step="0.01" value="<?= smart_marketing_safe((string) ($metaAuto['last_known_daily_budget'] ?? '')) ?>" style="width:140px;" />
                              </label>
                              <button class="btn btn-secondary" type="submit">Update Budget</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <div class="card nested" style="margin-top:1rem;">
            <div class="smart-marketing__header" style="margin-bottom:0.75rem;">
              <div>
                <h3 style="margin:0;">Meta Performance (Insights) for CMO Campaigns – Phase C1</h3>
                <p style="margin:0;color:#475569;">Fetch read-only performance metrics from Meta for linked campaigns and view the latest snapshot.</p>
              </div>
            </div>

            <?php if (empty($campaignsWithMeta)): ?>
              <p>No Meta-linked campaigns found. Create or link a Meta campaign first.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>CMO Campaign Name</th>
                      <th>Meta Campaign ID</th>
                      <th>Meta Ad Set ID</th>
                      <th>Meta Ad ID</th>
                      <th>Last Insights Sync</th>
                      <th>Insights Period</th>
                      <th>Performance Snapshot</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($campaignsWithMeta as $campaign): ?>
                      <?php $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto']; ?>
                      <?php
                        $insightsRaw = is_array($metaAuto['last_insights_raw'] ?? null) ? $metaAuto['last_insights_raw'] : [];
                        $insightsSummary = trim((string) ($metaAuto['last_insights_summary'] ?? ''));
                        $lastInsightsSync = trim((string) ($metaAuto['last_insights_sync'] ?? ''));
                        $periodLabel = trim((string) ($metaAuto['insights_period'] ?? '7d'));
                      ?>
                      <tr>
                        <td><?= smart_marketing_safe((string) ($campaign['name'] ?? 'Untitled')) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_campaign_id'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_adset_id'] ?? '')) ?></td>
                        <td><?= smart_marketing_safe((string) ($metaAuto['meta_ad_id'] ?? '')) ?></td>
                        <td><?= $lastInsightsSync !== '' ? smart_marketing_safe(marketing_format_datetime($lastInsightsSync)) : 'No insights fetched yet' ?></td>
                        <td><?= smart_marketing_safe($periodLabel !== '' ? $periodLabel : '7d') ?></td>
                        <td>
                          <?php if ($insightsSummary !== ''): ?>
                            <div style="font-weight:600; color:#0f172a;">Latest Snapshot</div>
                            <div style="color:#334155; font-size:0.95rem;"><?= smart_marketing_safe($insightsSummary) ?></div>
                          <?php else: ?>
                            <span>No insights fetched yet.</span>
                          <?php endif; ?>

                          <?php if (!empty($insightsRaw)): ?>
                            <details style="margin-top:0.5rem;">
                              <summary style="cursor:pointer;">View Insights Details</summary>
                              <ul style="margin:0.5rem 0 0; padding-left:1.25rem; font-size:0.95rem; color:#334155;">
                                <li>Impressions: <?= smart_marketing_safe((string) ($insightsRaw['impressions'] ?? 0)) ?></li>
                                <li>Clicks: <?= smart_marketing_safe((string) ($insightsRaw['clicks'] ?? 0)) ?></li>
                                <li>Inline Link Clicks: <?= smart_marketing_safe((string) ($insightsRaw['inline_link_clicks'] ?? 0)) ?></li>
                                <li>Spend: ₹<?= smart_marketing_safe(number_format((float) ($insightsRaw['spend'] ?? 0), 2)) ?></li>
                                <li>CTR: <?= smart_marketing_safe(number_format((float) ($insightsRaw['ctr'] ?? 0), 2)) ?>%</li>
                                <li>CPC: ₹<?= smart_marketing_safe(number_format((float) ($insightsRaw['cpc'] ?? 0), 2)) ?></li>
                                <li>CPM: ₹<?= smart_marketing_safe(number_format((float) ($insightsRaw['cpm'] ?? 0), 2)) ?></li>
                                <?php if (!empty($insightsRaw['actions']) && is_array($insightsRaw['actions'])): ?>
                                  <li>Actions:
                                    <ul style="margin:0.25rem 0 0.25rem 1rem; padding-left:1rem;">
                                      <?php foreach ($insightsRaw['actions'] as $action): ?>
                                        <?php if (is_array($action)): ?>
                                          <li><?= smart_marketing_safe((string) ($action['action_type'] ?? 'Action')) ?>: <?= smart_marketing_safe((string) ($action['value'] ?? '0')) ?></li>
                                        <?php endif; ?>
                                      <?php endforeach; ?>
                                    </ul>
                                  </li>
                                <?php endif; ?>
                              </ul>
                            </details>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div style="display:flex; flex-direction:column; gap:0.5rem;">
                            <form method="post" style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                              <input type="hidden" name="tab" value="meta_auto" />
                              <input type="hidden" name="meta_auto_action" value="fetch_insights" />
                              <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                              <input type="hidden" name="period" value="7d" />
                              <button class="btn btn-secondary" type="submit">Fetch 7-day Insights</button>
                            </form>
                            <form method="post" style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                              <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                              <input type="hidden" name="tab" value="meta_auto" />
                              <input type="hidden" name="meta_auto_action" value="fetch_insights" />
                              <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                              <input type="hidden" name="period" value="30d" />
                              <button class="btn" type="submit">Fetch 30-day Insights</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($metaPreviewError !== ''): ?>
            <div class="flash error" style="margin-top:1rem;">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <span><?= smart_marketing_safe($metaPreviewError) ?></span>
            </div>
          <?php endif; ?>

          <div class="card nested" style="margin-top:1rem;">
            <div class="smart-marketing__header" style="margin-bottom:0.75rem;">
              <div>
                <h3 style="margin:0;">Meta Campaign Launcher (Phase B1 – Minimal)</h3>
                <p style="margin:0;color:#475569;">Preview a Meta-ready payload for any CMO campaign and trigger a 1–1–1 (campaign, ad set, ad) creation in Meta.</p>
              </div>
            </div>

            <?php if (empty($campaigns)): ?>
              <p>No campaigns found. Create a CMO campaign first.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table>
                  <thead>
                    <tr>
                      <th>Campaign Name</th>
                      <th>Campaign Code</th>
                      <th>Channels</th>
                      <th>Total Budget</th>
                      <th>Meta Auto Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($campaigns as $campaign): ?>
                      <?php $metaAuto = smart_marketing_merge_meta_auto($campaign)['meta_auto']; ?>
                      <tr>
                        <td><?= smart_marketing_safe((string) ($campaign['name'] ?? 'Untitled')) ?></td>
                        <td><?= smart_marketing_safe((string) ($campaign['tracking_code'] ?? ($campaign['id'] ?? '—'))) ?></td>
                        <td><?= smart_marketing_safe(smart_marketing_channel_list((array) ($campaign['channels'] ?? []))) ?></td>
                        <td>
                          <?php
                            $budgetValue = trim((string) ($campaign['total_budget_rs'] ?? ''));
                            $budgetPeriod = trim((string) ($campaign['budget_period'] ?? ''));
                            echo $budgetValue !== '' ? '₹' . smart_marketing_safe($budgetValue) . ($budgetPeriod !== '' ? ' / ' . smart_marketing_safe($budgetPeriod) : '') : '—';
                          ?>
                        </td>
                        <td>
                          <?php if (!empty($metaAuto['meta_campaign_id'])): ?>
                            <span class="status-pill" style="background:#dcfce7; color:#166534;">Created (ID: <?= smart_marketing_safe((string) $metaAuto['meta_campaign_id']) ?>)</span>
                          <?php else: ?>
                            <span class="status-pill" style="background:#f1f5f9; color:#0f172a;">Not created</span>
                          <?php endif; ?>
                        </td>
                        <td style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                          <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                            <input type="hidden" name="tab" value="meta_auto" />
                            <input type="hidden" name="meta_auto_action" value="preview_payload" />
                            <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                            <button class="btn btn-secondary" type="submit">Preview Meta Payload</button>
                          </form>
                          <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= smart_marketing_safe($csrfToken) ?>" />
                            <input type="hidden" name="tab" value="meta_auto" />
                            <input type="hidden" name="meta_auto_action" value="create_in_meta" />
                            <input type="hidden" name="campaign_id" value="<?= smart_marketing_safe((string) ($campaign['id'] ?? '')) ?>" />
                            <button class="btn btn-primary" type="submit">Create in Meta</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($metaPreviewData !== null): ?>
            <div class="card nested" style="margin-top:1rem;">
              <h4 style="margin:0 0 0.5rem;">Meta-ready payload preview</h4>
              <p style="margin:0 0 1rem;color:#475569;">Preview for campaign: <?= smart_marketing_safe((string) ($metaPreviewData['campaign']['name'] ?? $metaPreviewCampaignId)) ?>. This does not call the Meta API.</p>
              <div class="meta-preview" style="display:grid; gap:1rem;">
                <div class="card nested" style="background:#f8fafc;">
                  <h5 style="margin:0 0 0.5rem;">Campaign Level</h5>
                  <ul style="margin:0; padding-left:1.25rem;">
                    <li><strong>Name:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['campaign']['name'] ?? '')) ?></li>
                    <li><strong>Objective:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['campaign']['objective'] ?? '')) ?></li>
                    <li><strong>Special Ad Category:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['campaign']['special_ad_category'] ?? 'None')) ?></li>
                  </ul>
                </div>
                <div class="card nested" style="background:#f8fafc;">
                  <h5 style="margin:0 0 0.5rem;">Ad Set Level</h5>
                  <ul style="margin:0; padding-left:1.25rem;">
                    <li><strong>Daily Budget:</strong> ₹<?= number_format((float) ($metaPreviewData['adset']['daily_budget'] ?? 0), 2) ?> (<?= smart_marketing_safe((string) ($metaPreviewData['adset']['budget_note'] ?? '')) ?>)</li>
                    <li><strong>Target Areas:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['adset']['target_areas'] ?? 'Not specified')) ?></li>
                    <li><strong>Age Range:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['adset']['age_range'] ?? '')) ?></li>
                    <li><strong>Gender:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['adset']['gender'] ?? '')) ?></li>
                    <li><strong>Interests:</strong> <?= !empty($metaPreviewData['adset']['interests']) ? smart_marketing_safe(implode(', ', (array) $metaPreviewData['adset']['interests'])) : 'Not specified' ?></li>
                    <li><strong>Placements:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['adset']['placements'] ?? 'Automatic placements')) ?></li>
                    <li><strong>Start:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['adset']['start'] ?? '')) ?></li>
                  </ul>
                </div>
                <div class="card nested" style="background:#f8fafc;">
                  <h5 style="margin:0 0 0.5rem;">Ad Level (Creative)</h5>
                  <ul style="margin:0; padding-left:1.25rem;">
                    <li><strong>Primary Text:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['ad']['primary_text'] ?? '')) ?></li>
                    <li><strong>Headline:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['ad']['headline'] ?? '')) ?></li>
                    <li><strong>Description:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['ad']['description'] ?? '')) ?></li>
                    <li><strong>CTA:</strong> <?= smart_marketing_safe((string) ($metaPreviewData['ad']['cta'] ?? 'Learn More')) ?></li>
                    <li><strong>Destination:</strong> <?= smart_marketing_safe(ucfirst((string) ($metaPreviewData['ad']['destination']['type'] ?? ''))) ?><?php if (!empty($metaPreviewData['ad']['destination']['url'])): ?> – <a href="<?= smart_marketing_safe((string) $metaPreviewData['ad']['destination']['url']) ?>" target="_blank" rel="noopener">Visit</a><?php endif; ?></li>
                    <li><strong>Image:</strong> <?php if (!empty($metaPreviewData['ad']['image']['file_url'])): ?><a href="<?= smart_marketing_safe((string) $metaPreviewData['ad']['image']['file_url']) ?>" target="_blank" rel="noopener">View image</a><?php else: ?>Not selected<?php endif; ?></li>
                  </ul>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('campaign-form');
  const aiState = { busy: false };

  const setBusy = function (busy) {
    aiState.busy = busy;
    document.querySelectorAll('.ai-generate-btn').forEach(function (btn) {
      btn.disabled = busy;
    });
  };

  const setInsertEnabled = function (draftId, enabled) {
    document.querySelectorAll('.ai-insert-btn[data-insert-for="' + draftId + '"]').forEach(function (btn) {
      btn.disabled = !enabled;
    });
  };

  document.querySelectorAll('.copy-url-btn').forEach(function (button) {
    const originalText = button.textContent;
    button.addEventListener('click', function () {
      const url = button.getAttribute('data-url') || '';
      if (url === '') {
        return;
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          button.textContent = 'Copied!';
          setTimeout(function () { button.textContent = originalText; }, 1200);
        });
        return;
      }

      const input = document.createElement('input');
      input.value = url;
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      document.body.removeChild(input);
      button.textContent = 'Copied!';
      setTimeout(function () { button.textContent = originalText; }, 1200);
    });
  });

  const typeTextIntoElement = function (element, text, callback) {
    element.textContent = '';
    const chars = text.split('');
    let index = 0;

    const writeNext = function () {
      if (index < chars.length) {
        element.textContent += chars[index];
        index += 1;
        setTimeout(writeNext, 22);
      } else if (typeof callback === 'function') {
        callback();
      }
    };

    writeNext();
  };

  const collectPayload = function (action) {
    const payload = new FormData();
    if (!form) {
      payload.append('ai_action', action);
      return payload;
    }

    payload.append('ai_action', action);
    const tokenField = form.querySelector('input[name="csrf_token"]');
    if (tokenField) {
      payload.append('csrf_token', tokenField.value);
    }

    const campaignIdField = form.querySelector('input[name="campaign_id"]');
    if (campaignIdField) {
      payload.append('campaign_id', campaignIdField.value);
    }

    ['name', 'type', 'primary_goal', 'intent_note', 'target_areas', 'target_persona', 'total_budget_rs', 'budget_period', 'budget_notes', 'start_date', 'end_date', 'ai_brief', 'ai_strategy_summary', 'ai_budget_allocation'].forEach(function (fieldName) {
      const field = form.querySelector('[name="' + fieldName + '"]');
      if (field) {
        payload.append(fieldName, field.value);
      }
    });

    ['ai_online_creatives', 'ai_offline_creatives'].forEach(function (fieldName) {
      const field = form.querySelector('textarea[name="' + fieldName + '"]');
      if (field) {
        payload.append(fieldName, field.value);
      }
    });

    const metaFields = ['objective', 'conversion_location', 'performance_goal', 'budget_strategy', 'audience_summary', 'placement_strategy', 'optimization_and_bidding', 'creative_recommendations', 'tracking_and_reporting'];
    metaFields.forEach(function (metaField) {
      const field = form.querySelector('[name="meta_ads_settings[' + metaField + ']"]');
      if (field) {
        payload.append('meta_ads_settings[' + metaField + ']', field.value);
      }
    });

    const guideField = form.querySelector('textarea[name="meta_manual_guide"]');
    if (guideField) {
      payload.append('meta_manual_guide', guideField.value);
    }

    form.querySelectorAll('input[name="channels[]"]:checked').forEach(function (input) {
      payload.append('channels[]', input.value);
    });

    return payload;
  };

  const handleAiResponse = function (draftEl, draftId, data) {
    if (data && data.success && typeof data.content === 'string') {
      typeTextIntoElement(draftEl, data.content, function () {
        setInsertEnabled(draftId, true);
        setBusy(false);
      });
    } else {
      const errorText = data && data.error ? data.error : 'Unable to generate content right now.';
      draftEl.textContent = errorText;
      setBusy(false);
    }
  };

  const appendImageCard = function (container, data) {
    if (!container || !data || !data.file_url) {
      return;
    }

    if (container.children.length === 1 && container.firstElementChild && container.firstElementChild.tagName === 'P') {
      container.innerHTML = '';
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'media-thumb';
    wrapper.style.border = '1px solid #e2e8f0';
    wrapper.style.borderRadius = '8px';
    wrapper.style.padding = '0.5rem';
    wrapper.style.background = '#fff';

    const link = document.createElement('a');
    link.href = data.file_url;
    link.target = '_blank';
    link.rel = 'noopener';

    const img = document.createElement('img');
    img.src = data.thumbnail_url || data.file_url;
    img.alt = 'Generated image';
    img.style.width = '100%';
    img.style.height = '160px';
    img.style.objectFit = 'cover';
    img.style.borderRadius = '6px';
    link.appendChild(img);
    wrapper.appendChild(link);

    const meta = document.createElement('div');
    meta.style.marginTop = '0.35rem';
    const title = document.createElement('div');
    title.style.fontWeight = '600';
    title.style.fontSize = '0.95rem';
    title.textContent = data.title || 'Generated image';
    const prompt = document.createElement('div');
    prompt.style.fontSize = '0.85rem';
    prompt.style.color = '#475569';
    prompt.textContent = data.source_prompt ? 'Prompt: ' + data.source_prompt : '';
    const openLink = document.createElement('a');
    openLink.href = data.file_url;
    openLink.target = '_blank';
    openLink.rel = 'noopener';
    openLink.style.fontSize = '0.9rem';
    openLink.textContent = 'Open full image';
    meta.appendChild(title);
    meta.appendChild(prompt);
    meta.appendChild(openLink);
    wrapper.appendChild(meta);

    container.prepend(wrapper);
  };

  document.querySelectorAll('.ai-generate-image-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      const promptInputId = button.getAttribute('data-prompt-input');
      const statusId = button.getAttribute('data-status-target');
      const galleryId = button.getAttribute('data-gallery-target');
      const aiAction = button.getAttribute('data-ai-action');
      const promptField = promptInputId ? document.getElementById(promptInputId) : null;
      const statusEl = statusId ? document.getElementById(statusId) : null;
      const galleryEl = galleryId ? document.getElementById(galleryId) : null;
      const csrfField = form ? form.querySelector('input[name="csrf_token"]') : null;
      const campaignIdField = form ? form.querySelector('input[name="campaign_id"]') : null;

      if (!promptField || !aiAction) {
        return;
      }

      const prompt = promptField.value.trim();
      if (prompt === '') {
        if (statusEl) { statusEl.textContent = 'Please enter an image prompt.'; }
        return;
      }

      button.disabled = true;
      if (statusEl) { statusEl.textContent = 'Generating image...'; }

      const payload = new FormData();
      payload.append('ai_action', aiAction);
      payload.append('image_prompt', prompt);
      if (csrfField) {
        payload.append('csrf_token', csrfField.value);
      }
      if (campaignIdField) {
        payload.append('campaign_id', campaignIdField.value);
      }

      fetch('admin-smart-marketing.php', { method: 'POST', body: payload })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data && data.success) {
            if (statusEl) { statusEl.textContent = 'Image generated and saved.'; }
            appendImageCard(galleryEl, {
              file_url: data.file_url,
              thumbnail_url: data.thumbnail_url || data.file_url,
              source_prompt: data.source_prompt || prompt,
              title: data.media_id || 'Generated image',
            });
          } else {
            const errorText = data && data.error ? data.error : 'Unable to generate image. Please try again.';
            if (statusEl) { statusEl.textContent = errorText; }
          }
        })
        .catch(function (error) {
          if (statusEl) { statusEl.textContent = error && error.message ? error.message : 'Unable to reach AI service.'; }
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  });

  document.querySelectorAll('.ai-generate-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      if (aiState.busy) {
        return;
      }

      const action = button.getAttribute('data-ai-action');
      const draftId = button.getAttribute('data-draft-id');
      if (!action || !draftId) {
        return;
      }

      const draftEl = document.getElementById(draftId);
      if (!draftEl) {
        return;
      }

      setBusy(true);
      setInsertEnabled(draftId, false);
      draftEl.textContent = 'Generating…';

      const payload = collectPayload(action);

      fetch('admin-smart-marketing.php', { method: 'POST', body: payload })
        .then(function (response) { return response.json(); })
        .then(function (data) { handleAiResponse(draftEl, draftId, data); })
        .catch(function (error) {
          draftEl.textContent = error && error.message ? error.message : 'Unable to reach AI service.';
          setBusy(false);
        });
    });
  });

  document.querySelectorAll('.ai-insert-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      const draftId = button.getAttribute('data-insert-for');
      if (!draftId || button.disabled) {
        return;
      }

      const draftEl = document.getElementById(draftId);
      const draftText = draftEl ? draftEl.textContent.trim() : '';
      if (draftText === '') {
        return;
      }

      const targetInputName = button.getAttribute('data-target-input');
      if (targetInputName && form) {
        const targetField = form.querySelector('[name="' + targetInputName + '"]');
        if (targetField) {
          targetField.value = draftText;
        }
      }
    });
  });

  document.querySelectorAll('.wa-phone-input').forEach(function (input) {
    const targetId = input.getAttribute('data-link-target');
    if (!targetId) { return; }
    const link = document.getElementById(targetId);
    if (!link) { return; }
    const baseMessage = link.dataset.base || '';
    const updateLink = function () {
      const phone = input.value.trim();
      const encoded = encodeURIComponent(baseMessage);
      link.href = phone === '' ? ('https://wa.me/?text=' + encoded) : ('https://wa.me/' + encodeURIComponent(phone) + '?text=' + encoded);
    };
    input.addEventListener('input', updateLink);
    updateLink();
  });
});
</script>
</body>
</html>
