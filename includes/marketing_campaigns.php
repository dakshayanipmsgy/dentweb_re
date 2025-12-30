<?php

declare(strict_types=1);

function marketing_campaigns_file_path(): string
{
    return __DIR__ . '/../data/marketing/campaigns.json';
}

function marketing_campaigns_load(?string &$error = null): array
{
    $error = null;
    $path = marketing_campaigns_file_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $error = 'Error reading campaign data.';
        return [];
    }

    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $error = 'Error reading campaign data: invalid JSON.';
        return [];
    }

    if (!is_array($decoded)) {
        $error = 'Error reading campaign data: unexpected format.';
        return [];
    }

    return $decoded;
}

function marketing_campaigns_save(array $campaigns): void
{
    $path = marketing_campaigns_file_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $json = json_encode(array_values($campaigns), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode campaigns for storage.');
    }

    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Unable to save campaign data.');
    }
}

function marketing_campaign_find(array $campaigns, $id): ?array
{
    foreach ($campaigns as $campaign) {
        if ((string) ($campaign['id'] ?? '') === (string) $id) {
            return $campaign;
        }
    }

    return null;
}

function marketing_campaign_generate_id(array $campaigns): string
{
    $ids = array_map(static fn($item) => (string) ($item['id'] ?? ''), $campaigns);
    $next = (string) (max(array_map('intval', $ids ?: [0])) + 1);
    if ($next === '1' && !empty($ids)) {
        $next = (string) (time());
    }

    return $next;
}

function marketing_channels_labels(): array
{
    return [
        'facebook_instagram' => 'Facebook / Instagram Ads',
        'google_search' => 'Google Search Ads',
        'youtube_video' => 'YouTube / Video',
        'whatsapp_broadcast' => 'WhatsApp Broadcast',
        'offline_hoardings' => 'Offline: Hoardings / Banners',
        'offline_pamphlets' => 'Offline: Pamphlets / Leaflets',
        'offline_newspaper_radio' => 'Offline: Newspaper / Radio',
    ];
}

function marketing_format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

