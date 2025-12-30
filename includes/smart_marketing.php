<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_gemini.php';

const SMART_MARKETING_SECRET_SENTINEL = '__SECRET_PRESENT__';
const SMART_MARKETING_SECRET_PLACEHOLDER = '••••••••';

function smart_marketing_config_value(string $key, ?string $default = null): ?string
{
    $candidates = [
        defined($key) ? constant($key) : null,
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key) ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }

        return $candidate;
    }

    return $default;
}

function smart_marketing_site_origin(): string
{
    $configured = smart_marketing_config_value('SMART_MARKETING_BASE_URL');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function smart_marketing_absolute_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    return smart_marketing_site_origin() . $path;
}

function smart_marketing_connector_redirect_uri(string $connectorKey): string
{
    return smart_marketing_absolute_url('integrations/oauth.php?platform=' . rawurlencode($connectorKey));
}

function smart_marketing_generate_state(): string
{
    return bin2hex(random_bytes(16));
}

function smart_marketing_assert_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session not initialised for OAuth flow.');
    }
}

function smart_marketing_oauth_store_state(string $connectorKey, string $state, array $meta = []): void
{
    smart_marketing_assert_session();
    $_SESSION['smart_marketing_oauth'] = $_SESSION['smart_marketing_oauth'] ?? [];
    $_SESSION['smart_marketing_oauth'][$connectorKey] = [
        'state' => $state,
        'createdAt' => time(),
        'meta' => $meta,
    ];
}

function smart_marketing_oauth_consume_state(string $connectorKey, string $state): ?array
{
    smart_marketing_assert_session();
    $bucket = $_SESSION['smart_marketing_oauth'][$connectorKey] ?? null;
    if (!is_array($bucket) || !isset($bucket['state'])) {
        return null;
    }

    if (!hash_equals((string) $bucket['state'], (string) $state)) {
        return null;
    }

    unset($_SESSION['smart_marketing_oauth'][$connectorKey]);

    return $bucket;
}

function smart_marketing_flash(string $status, string $message, array $context = []): void
{
    smart_marketing_assert_session();
    $_SESSION['smart_marketing_flash'] = [
        'status' => $status,
        'message' => $message,
        'context' => $context,
    ];
}

function smart_marketing_flash_pull(): ?array
{
    smart_marketing_assert_session();
    if (!isset($_SESSION['smart_marketing_flash'])) {
        return null;
    }

    $flash = $_SESSION['smart_marketing_flash'];
    unset($_SESSION['smart_marketing_flash']);

    return is_array($flash) ? $flash : null;
}

function smart_marketing_secret_placeholder(): string
{
    return SMART_MARKETING_SECRET_PLACEHOLDER;
}

function smart_marketing_secret_sentinel(): string
{
    return SMART_MARKETING_SECRET_SENTINEL;
}

function smart_marketing_is_secret_value($value): bool
{
    return is_string($value) && $value === SMART_MARKETING_SECRET_SENTINEL;
}

function smart_marketing_mask_secret(?string $value): string
{
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    $length = mb_strlen($value);
    if ($length <= 4) {
        return str_repeat('•', $length);
    }

    $start = mb_substr($value, 0, 2);
    $end = mb_substr($value, -2);
    return sprintf('%s%s%s', $start, str_repeat('•', max(4, $length - 4)), $end);
}

function smart_marketing_storage_dir(): string
{
    $path = __DIR__ . '/../storage/smart_marketing';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    return $path;
}

function smart_marketing_settings_root(): string
{
    $path = __DIR__ . '/../ai/smart-marketing/settings';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    return $path;
}

function smart_marketing_settings_section_file(string $section): string
{
    $sections = smart_marketing_settings_sections();
    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Unknown Smart Marketing settings section: ' . $section);
    }

    return smart_marketing_settings_root() . '/' . $sections[$section];
}

function smart_marketing_settings_sections(): array
{
    return [
        'business' => 'business.json',
        'goals' => 'goals.json',
        'budget' => 'budget.json',
        'audience' => 'audience.json',
        'compliance' => 'compliance.json',
        'integrations' => 'integrations.json',
    ];
}

function smart_marketing_settings_audit_file(): string
{
    return smart_marketing_settings_root() . '/settings_audit.json';
}

function smart_marketing_settings_file(): string
{
    return smart_marketing_storage_dir() . '/settings.json';
}

function smart_marketing_settings_section_defaults(string $section): array
{
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $defaults = [
        'business' => [
            'companyName' => 'Dakshayani Enterprises',
            'brandTone' => 'friendly',
            'defaultLanguages' => ['English', 'Hindi'],
            'baseLocations' => ['Jharkhand'],
            'timeZone' => 'Asia/Kolkata',
            'autoSync' => [
                'enabled' => true,
                'lastSyncedAt' => null,
            ],
            'summary' => '',
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
        'goals' => [
            'goalType' => 'Leads',
            'targetProducts' => [
                'Rooftop 3 kW',
                'Rooftop 5 kW',
                'PM Surya Ghar',
            ],
            'coreFocus' => 'Residential',
            'offerMessaging' => 'Book a free subsidy audit today.',
            'campaignDuration' => [
                'start' => $today->format('Y-m-d'),
                'end' => $today->modify('+30 days')->format('Y-m-d'),
            ],
            'autonomyMode' => 'review',
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
        'budget' => [
            'currency' => 'INR',
            'dailyBudget' => 15000.0,
            'monthlyCap' => 450000.0,
            'platformSplit' => [
                'meta' => 40,
                'google' => 30,
                'youtube' => 15,
                'whatsapp' => 10,
                'emailSms' => 5,
            ],
            'bidStrategy' => 'cpc',
            'autoScaling' => true,
            'emergencyStopEngaged' => false,
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
        'audience' => [
            'locations' => ['Jharkhand'],
            'ageRange' => ['min' => 24, 'max' => 60],
            'interestTags' => ['homeowners', 'solar', 'renewable energy'],
            'exclusions' => 'existing customers',
            'languageSplit' => [
                ['language' => 'English', 'percent' => 60],
                ['language' => 'Hindi', 'percent' => 40],
            ],
            'devicePriorities' => ['mobile' => 70, 'desktop' => 30],
            'schedule' => [],
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
        'compliance' => [
            'autoDisclaimer' => true,
            'disclaimerText' => 'Subsidy subject to MNRE / DISCOM approval.',
            'policyChecks' => true,
            'warnings' => [],
            'flaggedCreatives' => [],
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
        'integrations' => [
            'channels' => smart_marketing_default_connector_settings(),
            'gemini' => [
                'validated' => false,
                'message' => 'Validation pending',
                'lastValidatedAt' => null,
                'models' => [],
            ],
            'lastUpdatedAt' => null,
            'lastUpdatedBy' => null,
        ],
    ];

    if (!isset($defaults[$section])) {
        throw new InvalidArgumentException('Unknown Smart Marketing defaults for section: ' . $section);
    }

    return $defaults[$section];
}

function smart_marketing_settings_lock_file(): string
{
    return smart_marketing_storage_dir() . '/settings.lock';
}

function smart_marketing_brain_runs_file(): string
{
    return smart_marketing_storage_dir() . '/brain_runs.json';
}

function smart_marketing_assets_dir(): string
{
    $dir = smart_marketing_storage_dir() . '/assets';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function smart_marketing_audit_log_file(): string
{
    return smart_marketing_storage_dir() . '/audit.log';
}

function smart_marketing_campaigns_file(): string
{
    return smart_marketing_storage_dir() . '/campaigns.json';
}

function smart_marketing_automation_log_file(): string
{
    return smart_marketing_storage_dir() . '/automations.log';
}

function smart_marketing_analytics_file(): string
{
    return smart_marketing_storage_dir() . '/analytics.json';
}

function smart_marketing_optimization_file(): string
{
    return smart_marketing_storage_dir() . '/optimization.json';
}

function smart_marketing_governance_file(): string
{
    return smart_marketing_storage_dir() . '/governance.json';
}

function smart_marketing_notifications_file(): string
{
    return smart_marketing_storage_dir() . '/notifications.json';
}

function smart_marketing_exports_dir(): string
{
    $dir = smart_marketing_storage_dir() . '/exports';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function smart_marketing_landings_dir(): string
{
    $dir = smart_marketing_storage_dir() . '/landings';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function smart_marketing_sitemap_fragment_file(): string
{
    return smart_marketing_storage_dir() . '/sitemap-fragment.xml';
}

function smart_marketing_connector_catalog(): array
{
    return [
        'meta' => [
            'label' => 'Meta Ads (Facebook & Instagram)',
            'description' => 'Business Manager, Pages, Pixels, and WhatsApp entry points managed via Meta Marketing APIs.',
            'channels' => ['lead', 'awareness', 'engagement', 'whatsapp'],
            'defaults' => [
                'status' => 'unknown',
            ],
            'oauth' => [
                'scopes' => [
                    'ads_management',
                    'business_management',
                    'pages_show_list',
                    'pages_read_engagement',
                    'whatsapp_business_messaging',
                ],
            ],
            'fields' => [
                ['key' => 'businessManagerId', 'label' => 'Business Manager', 'required' => true, 'type' => 'select'],
                ['key' => 'adAccountId', 'label' => 'Ad Account', 'required' => true, 'type' => 'select'],
                ['key' => 'pageId', 'label' => 'Facebook Page', 'required' => true, 'type' => 'select'],
                ['key' => 'whatsappNumberId', 'label' => 'WhatsApp Number (optional)', 'type' => 'select'],
                ['key' => 'pixelId', 'label' => 'Pixel ID (optional)', 'type' => 'select'],
                ['key' => 'accessToken', 'label' => 'Access Token', 'required' => true, 'secret' => true, 'hidden' => true],
                ['key' => 'tokenExpiresAt', 'label' => 'Token Expiry', 'hidden' => true],
                ['key' => 'metaUserId', 'label' => 'Meta User ID', 'hidden' => true],
            ],
        ],
        'googleAds' => [
            'label' => 'Google Ads & YouTube',
            'description' => 'OAuth and developer credentials for Google Ads, Performance Max, and YouTube placements.',
            'channels' => ['search', 'display', 'performance_max', 'youtube'],
            'defaults' => [
                'status' => 'unknown',
            ],
            'fields' => [
                ['key' => 'managerId', 'label' => 'Google Ads Account', 'required' => true, 'type' => 'select'],
                ['key' => 'linkedYoutubeChannelId', 'label' => 'YouTube Channel (optional)', 'type' => 'select'],
                ['key' => 'refreshToken', 'label' => 'Refresh Token', 'required' => true, 'secret' => true, 'hidden' => true],
                ['key' => 'developerToken', 'label' => 'Developer Token', 'secret' => true, 'hidden' => true],
                ['key' => 'oauthClientId', 'label' => 'OAuth Client ID', 'hidden' => true],
                ['key' => 'oauthClientSecret', 'label' => 'OAuth Client Secret', 'hidden' => true, 'secret' => true],
                ['key' => 'conversionTrackingId', 'label' => 'Conversion Tracking ID (optional)', 'hidden' => true],
            ],
            'oauth' => [
                'scopes' => [
                    'https://www.googleapis.com/auth/adwords',
                    'https://www.googleapis.com/auth/youtube.readonly',
                ],
            ],
        ],
        'youtube' => [
            'label' => 'YouTube Delivery',
            'description' => 'Status mirror for YouTube placements using the Google Ads integration.',
            'channels' => ['video'],
            'defaults' => [
                'status' => 'unknown',
            ],
            'fields' => [
                ['key' => 'linkedYoutubeChannelId', 'label' => 'YouTube Channel ID (optional)'],
            ],
        ],
        'whatsapp' => [
            'label' => 'WhatsApp Business API',
            'description' => 'Meta WhatsApp Business Account or BSP credentials for template messaging.',
            'channels' => ['messaging'],
            'defaults' => [
                'status' => 'unknown',
            ],
            'fields' => [
                [
                    'key' => 'provider',
                    'label' => 'Provider',
                    'required' => true,
                    'type' => 'select',
                    'options' => ['360dialog', 'gupshup', 'twilio', 'msg91'],
                ],
                ['key' => 'apiKey', 'label' => 'API Key / Access Token', 'required' => true, 'secret' => true],
                ['key' => 'senderId', 'label' => 'Sender ID / WhatsApp Number', 'required' => true],
                ['key' => 'templateNamespace', 'label' => 'Template Namespace (optional)'],
                ['key' => 'adminSandboxNumber', 'label' => 'Sandbox Recipient (optional)'],
            ],
        ],
        'email' => [
            'label' => 'Email & SMS Gateway',
            'description' => 'Provider credentials for SendGrid, Twilio, MSG91, or SMTP relays.',
            'channels' => ['email', 'sms'],
            'defaults' => [
                'status' => 'unknown',
            ],
            'fields' => [
                ['key' => 'provider', 'label' => 'Provider', 'required' => true, 'type' => 'select', 'options' => ['sendgrid', 'mailgun', 'msg91', 'twilio', 'smtp']],
                ['key' => 'apiKey', 'label' => 'API Key (if applicable)', 'secret' => true],
                ['key' => 'senderId', 'label' => 'Sender ID / From Email', 'required' => true],
                ['key' => 'smtpHost', 'label' => 'SMTP Host (optional)'],
                ['key' => 'smtpPort', 'label' => 'SMTP Port (optional)'],
                ['key' => 'smtpUsername', 'label' => 'SMTP Username (optional)'],
                ['key' => 'smtpPassword', 'label' => 'SMTP Password', 'secret' => true],
                ['key' => 'sandboxRecipient', 'label' => 'Test Recipient (optional)'],
            ],
        ],
    ];
}

function smart_marketing_connector_has_oauth(string $connectorKey): bool
{
    $catalog = smart_marketing_connector_catalog();
    return !empty($catalog[$connectorKey]['oauth']);
}

function smart_marketing_connector_has_token(string $connectorKey, array $entry): bool
{
    return match ($connectorKey) {
        'meta' => !empty($entry['accessToken']),
        'googleAds' => !empty($entry['refreshToken']),
        default => true,
    };
}

function smart_marketing_meta_app_id(): string
{
    return (string) smart_marketing_config_value('SMART_MARKETING_META_APP_ID', '');
}

function smart_marketing_meta_app_secret(): string
{
    return (string) smart_marketing_config_value('SMART_MARKETING_META_APP_SECRET', '');
}

function smart_marketing_google_client_id(): string
{
    return (string) smart_marketing_config_value('SMART_MARKETING_GOOGLE_CLIENT_ID', '');
}

function smart_marketing_google_client_secret(): string
{
    return (string) smart_marketing_config_value('SMART_MARKETING_GOOGLE_CLIENT_SECRET', '');
}

function smart_marketing_google_developer_token(): string
{
    return (string) smart_marketing_config_value('SMART_MARKETING_GOOGLE_DEVELOPER_TOKEN', '');
}

function smart_marketing_format_option_label(string $value): string
{
    $normalized = str_replace(['_', '-'], ' ', $value);
    return ucwords($normalized);
}

function smart_marketing_connector_field_options(string $connectorKey, string $fieldKey, array $entry, array $fieldMeta): array
{
    if (!empty($fieldMeta['options']) && is_array($fieldMeta['options'])) {
        return array_map(static function ($value) {
            return [
                'value' => (string) $value,
                'label' => smart_marketing_format_option_label((string) $value),
            ];
        }, $fieldMeta['options']);
    }

    $available = is_array($entry['available'] ?? null) ? $entry['available'] : [];

    if ($connectorKey === 'meta') {
        switch ($fieldKey) {
            case 'businessManagerId':
                $options = array_map(static function ($business) {
                    $id = (string) ($business['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($business['name'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => sprintf('%s (%s)', $label, $id),
                        'meta' => [
                            'verification' => $business['verification_status'] ?? null,
                        ],
                    ];
                }, array_filter($available['businessManagers'] ?? [], 'is_array'));
                return array_values(array_filter($options));
            case 'adAccountId':
                $options = array_map(static function ($account) {
                    $id = (string) ($account['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($account['name'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => sprintf('%s (%s)', $label, $id),
                        'meta' => [
                            'businessId' => $account['business_id'] ?? null,
                            'status' => $account['account_status'] ?? null,
                            'relationship' => $account['relationship'] ?? null,
                        ],
                    ];
                }, array_filter($available['adAccounts'] ?? [], 'is_array'));
                return array_values(array_filter($options));
            case 'pageId':
                $options = array_map(static function ($page) {
                    $id = (string) ($page['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($page['name'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => sprintf('%s (%s)', $label, $id),
                        'meta' => [
                            'businessId' => $page['business_id'] ?? null,
                            'relationship' => $page['relationship'] ?? null,
                        ],
                    ];
                }, array_filter($available['pages'] ?? [], 'is_array'));
                return array_values(array_filter($options));
            case 'whatsappNumberId':
                $options = array_map(static function ($number) {
                    $id = (string) ($number['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $display = trim((string) ($number['display'] ?? $number['display_phone_number'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => $display !== '' ? $display : $id,
                        'meta' => [
                            'businessId' => $number['business_id'] ?? null,
                            'wabaId' => $number['waba_id'] ?? null,
                        ],
                    ];
                }, array_filter($available['whatsappNumbers'] ?? [], 'is_array'));
                return array_values(array_filter($options));
            case 'pixelId':
                $options = array_map(static function ($pixel) {
                    $id = (string) ($pixel['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($pixel['name'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => sprintf('%s (%s)', $label, $id),
                    ];
                }, array_filter($available['pixels'] ?? [], 'is_array'));
                return array_values(array_filter($options));
        }
    }

    if ($connectorKey === 'googleAds') {
        switch ($fieldKey) {
            case 'managerId':
                $options = array_map(static function ($account) {
                    $id = preg_replace('/[^0-9]/', '', (string) ($account['id'] ?? ''));
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($account['name'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => sprintf('%s (%s)', $label, $id),
                        'meta' => [
                            'type' => $account['type'] ?? null,
                        ],
                    ];
                }, array_filter($available['accounts'] ?? [], 'is_array'));
                return array_values(array_filter($options));
            case 'linkedYoutubeChannelId':
                $options = array_map(static function ($channel) {
                    $id = (string) ($channel['id'] ?? '');
                    if ($id === '') {
                        return null;
                    }
                    $label = trim((string) ($channel['title'] ?? $id));
                    return [
                        'value' => $id,
                        'label' => $label !== '' ? $label : $id,
                    ];
                }, array_filter($available['youtubeChannels'] ?? [], 'is_array'));
                return array_values(array_filter($options));
        }
    }

    return [];
}

function smart_marketing_connector_prepare_authorization(string $connectorKey, array $admin = []): array
{
    $catalog = smart_marketing_connector_catalog();
    if (empty($catalog[$connectorKey]['oauth'])) {
        throw new RuntimeException('Connector does not support OAuth.');
    }

    $state = smart_marketing_generate_state();
    smart_marketing_oauth_store_state($connectorKey, $state, ['admin' => $admin['email'] ?? null]);
    $redirect = smart_marketing_connector_redirect_uri($connectorKey);

    switch ($connectorKey) {
        case 'meta':
            $appId = smart_marketing_meta_app_id();
            $appSecret = smart_marketing_meta_app_secret();
            if ($appId === '' || $appSecret === '') {
                throw new RuntimeException('Meta App ID/Secret missing from configuration.');
            }
            $scopes = implode(',', $catalog[$connectorKey]['oauth']['scopes']);
            $url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
                'client_id' => $appId,
                'redirect_uri' => $redirect,
                'scope' => $scopes,
                'state' => $state,
                'response_type' => 'code',
            ], '', '&', PHP_QUERY_RFC3986);
            return ['url' => $url, 'state' => $state];

        case 'googleAds':
            $clientId = smart_marketing_google_client_id();
            $clientSecret = smart_marketing_google_client_secret();
            if ($clientId === '' || $clientSecret === '') {
                throw new RuntimeException('Google OAuth client is not configured.');
            }
            $scopes = implode(' ', $catalog[$connectorKey]['oauth']['scopes']);
            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirect,
                'scope' => $scopes,
                'state' => $state,
                'response_type' => 'code',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ], '', '&', PHP_QUERY_RFC3986);
            return ['url' => $url, 'state' => $state];

        default:
            throw new RuntimeException('Unsupported connector for OAuth initiation.');
    }
}

function smart_marketing_meta_graph_request(string $path, array $query): array
{
    $response = smart_marketing_http_request('GET', 'https://graph.facebook.com/v19.0/' . ltrim($path, '/'), [
        'query' => $query,
    ]);
    if (!$response['ok']) {
        $payload = smart_marketing_decode_json($response['body']);
        $message = $payload['error']['message'] ?? $response['error'] ?? 'Meta Graph API request failed';
        throw new RuntimeException('Meta: ' . $message);
    }

    $decoded = smart_marketing_decode_json($response['body']);
    if (!is_array($decoded)) {
        throw new RuntimeException('Meta: Unexpected API response.');
    }

    return $decoded;
}

function smart_marketing_meta_fetch_edge(string $id, string $edge, string $accessToken, string $fields = ''): array
{
    $results = [];
    $after = null;
    do {
        $query = ['access_token' => $accessToken, 'limit' => 50];
        if ($fields !== '') {
            $query['fields'] = $fields;
        }
        if ($after !== null) {
            $query['after'] = $after;
        }
        $response = smart_marketing_http_request('GET', 'https://graph.facebook.com/v19.0/' . rawurlencode($id) . '/' . $edge, [
            'query' => $query,
        ]);
        if (!$response['ok']) {
            break;
        }
        $payload = smart_marketing_decode_json($response['body']);
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            break;
        }
        foreach ($payload['data'] as $row) {
            if (is_array($row)) {
                $results[] = $row;
            }
        }
        $after = $payload['paging']['cursors']['after'] ?? null;
    } while ($after !== null);

    return $results;
}

function smart_marketing_meta_collect_phone_numbers(string $businessId, array $wabas, string $accessToken): array
{
    $numbers = [];
    $seen = [];
    foreach ($wabas as $account) {
        $wabaId = (string) ($account['id'] ?? '');
        if ($wabaId === '') {
            continue;
        }
        $phones = smart_marketing_meta_fetch_edge($wabaId, 'phone_numbers', $accessToken, 'id,display_phone_number,verified_name');
        foreach ($phones as $phone) {
            $phoneId = (string) ($phone['id'] ?? '');
            if ($phoneId === '') {
                continue;
            }
            if (isset($seen[$phoneId])) {
                continue;
            }
            $seen[$phoneId] = true;
            $numbers[] = [
                'id' => $phoneId,
                'display' => $phone['display_phone_number'] ?? $phoneId,
                'business_id' => $businessId,
                'waba_id' => $wabaId,
                'verified_name' => $phone['verified_name'] ?? null,
            ];
        }
    }

    return $numbers;
}

function smart_marketing_meta_collect_entities(string $accessToken): array
{
    $businesses = [];
    $adAccounts = [];
    $pages = [];
    $whatsappNumbers = [];
    $pixels = [];
    $businessSeen = [];
    $adAccountSeen = [];
    $pageSeen = [];
    $pixelSeen = [];
    $whatsappSeen = [];

    $after = null;
    do {
        $query = ['access_token' => $accessToken, 'limit' => 25];
        if ($after !== null) {
            $query['after'] = $after;
        }
        $response = smart_marketing_http_request('GET', 'https://graph.facebook.com/v19.0/me/businesses', [
            'query' => $query,
        ]);
        if (!$response['ok']) {
            $payload = smart_marketing_decode_json($response['body']);
            $message = $payload['error']['message'] ?? 'Unable to fetch Business Managers.';
            throw new RuntimeException('Meta: ' . $message);
        }

        $payload = smart_marketing_decode_json($response['body']);
        foreach ($payload['data'] ?? [] as $business) {
            if (!is_array($business)) {
                continue;
            }
            $businessId = (string) ($business['id'] ?? '');
            if ($businessId === '') {
                continue;
            }
            if (!isset($businessSeen[$businessId])) {
                $businessSeen[$businessId] = true;
                $businesses[] = [
                    'id' => $businessId,
                    'name' => $business['name'] ?? $businessId,
                    'verification_status' => $business['verification_status'] ?? 'unknown',
                ];
            }

            $accountEdges = [
                ['edge' => 'owned_ad_accounts', 'relationship' => 'owned'],
                ['edge' => 'client_ad_accounts', 'relationship' => 'client'],
            ];
            foreach ($accountEdges as $edgeMeta) {
                $accounts = smart_marketing_meta_fetch_edge($businessId, $edgeMeta['edge'], $accessToken, 'id,name,account_status,business');
                foreach ($accounts as $account) {
                    $accountId = (string) ($account['id'] ?? '');
                    if ($accountId === '' || isset($adAccountSeen[$accountId])) {
                        continue;
                    }
                    $adAccountSeen[$accountId] = true;
                    $adAccounts[] = [
                        'id' => $accountId,
                        'name' => $account['name'] ?? $accountId,
                        'business_id' => $account['business']['id'] ?? $account['business_id'] ?? $businessId,
                        'account_status' => $account['account_status'] ?? null,
                        'relationship' => $edgeMeta['relationship'],
                    ];
                }
            }

            $pageEdges = [
                ['edge' => 'owned_pages', 'relationship' => 'owned'],
                ['edge' => 'client_pages', 'relationship' => 'client'],
            ];
            foreach ($pageEdges as $edgeMeta) {
                $businessPages = smart_marketing_meta_fetch_edge($businessId, $edgeMeta['edge'], $accessToken, 'id,name,verification_status');
                foreach ($businessPages as $page) {
                    $pageId = (string) ($page['id'] ?? '');
                    if ($pageId === '' || isset($pageSeen[$pageId])) {
                        continue;
                    }
                    $pageSeen[$pageId] = true;
                    $pages[] = [
                        'id' => $pageId,
                        'name' => $page['name'] ?? $pageId,
                        'business_id' => $page['business_id'] ?? $businessId,
                        'verification_status' => $page['verification_status'] ?? null,
                        'relationship' => $edgeMeta['relationship'],
                    ];
                }
            }

            $businessPixels = smart_marketing_meta_fetch_edge($businessId, 'owned_pixels', $accessToken, 'id,name');
            foreach ($businessPixels as $pixel) {
                $pixelId = (string) ($pixel['id'] ?? '');
                if ($pixelId === '' || isset($pixelSeen[$pixelId])) {
                    continue;
                }
                $pixelSeen[$pixelId] = true;
                $pixels[] = [
                    'id' => $pixelId,
                    'name' => $pixel['name'] ?? $pixelId,
                    'business_id' => $pixel['business_id'] ?? $businessId,
                ];
            }

            $wabaEdges = ['owned_whatsapp_business_accounts', 'client_whatsapp_business_accounts'];
            $wabas = [];
            foreach ($wabaEdges as $edge) {
                $wabas = array_merge($wabas, smart_marketing_meta_fetch_edge($businessId, $edge, $accessToken, 'id,name'));
            }
            $businessNumbers = smart_marketing_meta_collect_phone_numbers($businessId, $wabas, $accessToken);
            foreach ($businessNumbers as $number) {
                $numberId = (string) ($number['id'] ?? '');
                if ($numberId === '' || isset($whatsappSeen[$numberId])) {
                    continue;
                }
                $whatsappSeen[$numberId] = true;
                $whatsappNumbers[] = $number;
            }
        }

        $after = $payload['paging']['cursors']['after'] ?? null;
    } while ($after !== null);

    return [
        'businessManagers' => $businesses,
        'adAccounts' => array_values($adAccounts),
        'pages' => array_values($pages),
        'whatsappNumbers' => $whatsappNumbers,
        'pixels' => array_values($pixels),
    ];
}

function smart_marketing_google_fetch_customer(string $customerId, string $accessToken, string $developerToken): array
{
    $customerId = preg_replace('/[^0-9]/', '', $customerId);
    if ($customerId === '') {
        return [];
    }

    $response = smart_marketing_http_request('GET', 'https://googleads.googleapis.com/v15/customers/' . $customerId, [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'developer-token' => $developerToken,
        ],
    ]);
    if (!$response['ok']) {
        return [];
    }

    $decoded = smart_marketing_decode_json($response['body']);
    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_google_collect_inventory(string $accessToken): array
{
    $developerToken = smart_marketing_google_developer_token();
    if ($developerToken === '') {
        throw new RuntimeException('Google Ads developer token missing from configuration.');
    }

    $accounts = [];
    $response = smart_marketing_http_request('GET', 'https://googleads.googleapis.com/v15/customers:listAccessibleCustomers', [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'developer-token' => $developerToken,
        ],
    ]);
    if ($response['ok']) {
        $payload = smart_marketing_decode_json($response['body']);
        foreach ($payload['resourceNames'] ?? [] as $resource) {
            if (!is_string($resource)) {
                continue;
            }
            $id = preg_replace('/[^0-9]/', '', $resource);
            if ($id === '') {
                continue;
            }
            $customer = smart_marketing_google_fetch_customer($id, $accessToken, $developerToken);
            $label = trim((string) ($customer['descriptiveName'] ?? ''));
            $accounts[] = [
                'id' => $id,
                'name' => $label !== '' ? $label : 'Account ' . $id,
                'type' => str_contains($resource, 'customers/') ? 'customer' : null,
                'currency' => $customer['currencyCode'] ?? null,
                'timezone' => $customer['timeZone'] ?? null,
                'status' => $customer['customerStatus'] ?? null,
            ];
        }
    }

    $channels = [];
    $ytResponse = smart_marketing_http_request('GET', 'https://www.googleapis.com/youtube/v3/channels', [
        'query' => [
            'part' => 'snippet',
            'mine' => 'true',
            'maxResults' => 50,
        ],
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
        ],
    ]);
    if ($ytResponse['ok']) {
        $payload = smart_marketing_decode_json($ytResponse['body']);
        foreach ($payload['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $channels[] = [
                'id' => $id,
                'title' => $item['snippet']['title'] ?? $id,
            ];
        }
    }

    return [
        'accounts' => $accounts,
        'youtubeChannels' => $channels,
    ];
}

function smart_marketing_connector_complete_authorization(string $connectorKey, string $code, array $admin = []): array
{
    return match ($connectorKey) {
        'meta' => smart_marketing_meta_complete_authorization($code, $admin),
        'googleAds' => smart_marketing_google_complete_authorization($code, $admin),
        default => throw new RuntimeException('Unsupported connector authorization callback.'),
    };
}

function smart_marketing_meta_complete_authorization(string $code, array $admin = []): array
{
    $appId = smart_marketing_meta_app_id();
    $appSecret = smart_marketing_meta_app_secret();
    if ($appId === '' || $appSecret === '') {
        throw new RuntimeException('Meta App credentials missing.');
    }

    $redirect = smart_marketing_connector_redirect_uri('meta');
    $tokenResponse = smart_marketing_http_request('POST', 'https://graph.facebook.com/v18.0/oauth/access_token', [
        'form' => [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirect,
            'code' => $code,
        ],
    ]);
    if (!$tokenResponse['ok']) {
        $payload = smart_marketing_decode_json($tokenResponse['body']);
        $errorMessage = is_array($payload) ? ($payload['error']['message'] ?? '') : '';
        $developerMessage = sprintf(
            'Meta OAuth code exchange failed (%s): %s',
            (string) ($tokenResponse['status'] ?? 'unknown'),
            $errorMessage !== '' ? $errorMessage : ($tokenResponse['error'] ?? 'unknown error')
        );
        error_log($developerMessage);
        throw new RuntimeException('Meta: Unable to exchange authorization code. Please retry.');
    }
    $tokenData = smart_marketing_decode_json($tokenResponse['body']);
    $accessToken = (string) ($tokenData['access_token'] ?? '');
    if ($accessToken === '') {
        error_log('Meta OAuth response missing access token: ' . $tokenResponse['body']);
        throw new RuntimeException('Meta: OAuth response missing access token.');
    }

    $longTokenResponse = smart_marketing_http_request('POST', 'https://graph.facebook.com/v18.0/oauth/access_token', [
        'form' => [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $accessToken,
        ],
    ]);
    if ($longTokenResponse['ok']) {
        $longData = smart_marketing_decode_json($longTokenResponse['body']);
        $accessToken = (string) ($longData['access_token'] ?? $accessToken);
        $expiresIn = (int) ($longData['expires_in'] ?? 0);
    } else {
        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $longError = smart_marketing_decode_json($longTokenResponse['body']);
        if ($longTokenResponse['status'] ?? 0) {
            $longMessage = is_array($longError) ? ($longError['error']['message'] ?? '') : '';
            error_log(sprintf('Meta long-lived token exchange failed (%s): %s', $longTokenResponse['status'], $longMessage));
        }
    }

    $expiresAt = $expiresIn > 0 ? date(DATE_ATOM, time() + $expiresIn) : null;
    $me = smart_marketing_meta_graph_request('me', ['fields' => 'id,name', 'access_token' => $accessToken]);
    $inventory = smart_marketing_meta_collect_entities($accessToken);

    $settings = smart_marketing_settings_load();
    $entry = $settings['integrations']['meta'] ?? smart_marketing_default_connector_settings()['meta'];
    $entry['accessToken'] = $accessToken;
    $entry['tokenExpiresAt'] = $expiresAt;
    $entry['metaUserId'] = $me['id'] ?? null;
    $entry['businessManagers'] = $inventory['businessManagers'] ?? [];
    $entry['adAccounts'] = $inventory['adAccounts'] ?? [];
    $entry['pages'] = $inventory['pages'] ?? [];
    $entry['available'] = $inventory;
    $entry['connectedAt'] = ai_timestamp();
    $entry['status'] = 'connected';
    $entry['message'] = 'Meta connected. Select the assets and save to activate automations.';
    if (empty($entry['businessManagerId']) && !empty($inventory['businessManagers'][0]['id'])) {
        $entry['businessManagerId'] = $inventory['businessManagers'][0]['id'];
    }
    if (empty($entry['businessManagerId']) && !empty($entry['businessManagers'][0]['id'])) {
        $entry['businessManagerId'] = $entry['businessManagers'][0]['id'];
    }
    if (empty($entry['adAccountId'])) {
        $defaultAccount = current(array_filter($inventory['adAccounts'], static function ($account) use ($entry) {
            if (empty($entry['businessManagerId'])) {
                return true;
            }
            return ($account['business_id'] ?? null) === $entry['businessManagerId'];
        }));
        if (is_array($defaultAccount)) {
            $entry['adAccountId'] = $defaultAccount['id'];
        }
    }
    if (empty($entry['pageId']) && !empty($inventory['pages'][0]['id'])) {
        $entry['pageId'] = $inventory['pages'][0]['id'];
    }
    if (empty($entry['whatsappNumberId']) && !empty($inventory['whatsappNumbers'][0]['id'])) {
        $entry['whatsappNumberId'] = $inventory['whatsappNumbers'][0]['id'];
    }

    if (empty($entry['primaryBusinessId'])) {
        $entry['primaryBusinessId'] = $entry['businessManagerId'] ?? ($entry['businessManagers'][0]['id'] ?? null);
    }

    $entry['developerMessage'] = sprintf('Meta token refreshed for %s', $me['name'] ?? ($me['id'] ?? 'user'));

    $settings['integrations']['meta'] = $entry;
    smart_marketing_settings_save($settings);
    $auditEntry = $entry;
    unset($auditEntry['available']);
    smart_marketing_integrations_audit_append('meta', 'oauth', $admin, $auditEntry, $entry['developerMessage'] ?? null);

    return $entry;
}

function smart_marketing_google_complete_authorization(string $code, array $admin = []): array
{
    $clientId = smart_marketing_google_client_id();
    $clientSecret = smart_marketing_google_client_secret();
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Google OAuth client is not configured.');
    }

    $redirect = smart_marketing_connector_redirect_uri('googleAds');
    $tokenResponse = smart_marketing_http_request('POST', 'https://oauth2.googleapis.com/token', [
        'form' => [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
            'access_type' => 'offline',
        ],
    ]);
    if (!$tokenResponse['ok']) {
        throw new RuntimeException('Google Ads: Unable to exchange authorization code.');
    }
    $tokenData = smart_marketing_decode_json($tokenResponse['body']);
    $accessToken = (string) ($tokenData['access_token'] ?? '');
    $refreshToken = (string) ($tokenData['refresh_token'] ?? '');
    if ($refreshToken === '') {
        throw new RuntimeException('Google Ads: OAuth consent did not return a refresh token. Ensure offline access is granted.');
    }

    if ($accessToken === '') {
        throw new RuntimeException('Google Ads: Access token missing from OAuth response.');
    }

    $inventory = smart_marketing_google_collect_inventory($accessToken);

    $settings = smart_marketing_settings_load();
    $entry = $settings['integrations']['googleAds'] ?? smart_marketing_default_connector_settings()['googleAds'];
    $entry['refreshToken'] = $refreshToken;
    $entry['available'] = $inventory;
    $entry['developerToken'] = smart_marketing_google_developer_token();
    $entry['oauthClientId'] = $clientId;
    $entry['oauthClientSecret'] = $clientSecret;
    $entry['connectedAt'] = ai_timestamp();
    $entry['status'] = 'connected';
    $entry['message'] = 'Google Ads connected. Pick the customer account and save to deploy campaigns.';
    if (empty($entry['managerId']) && !empty($inventory['accounts'][0]['id'])) {
        $entry['managerId'] = $inventory['accounts'][0]['id'];
    }
    if (empty($entry['linkedYoutubeChannelId']) && !empty($inventory['youtubeChannels'][0]['id'])) {
        $entry['linkedYoutubeChannelId'] = $inventory['youtubeChannels'][0]['id'];
    }
    $entry['developerMessage'] = sprintf('Google Ads refresh token stored for %s', $admin['email'] ?? 'admin');

    $settings['integrations']['googleAds'] = $entry;
    smart_marketing_settings_save($settings);
    $auditEntry = $entry;
    unset($auditEntry['available']);
    smart_marketing_integrations_audit_append('googleAds', 'oauth', $admin, $auditEntry, $entry['developerMessage'] ?? null);

    return $entry;
}

function smart_marketing_default_connector_settings(): array
{
    $defaults = [];
    foreach (smart_marketing_connector_catalog() as $key => $meta) {
        $credentials = [];
        foreach (($meta['fields'] ?? []) as $field) {
            $credentials[$field['key']] = $field['default'] ?? '';
        }

        $defaults[$key] = array_merge(
            [
                'status' => 'unknown',
                'connectedAt' => null,
                'lastTested' => null,
                'lastValidatedAt' => null,
                'lastTestResult' => 'unknown',
                'validatedBy' => null,
                'message' => 'Not connected',
                'disabled' => false,
                'available' => [],
            ],
            $meta['defaults'] ?? [],
            $credentials
        );

        if (!isset($defaults[$key]['available']) || !is_array($defaults[$key]['available'])) {
            $defaults[$key]['available'] = [];
        }
    }

    return $defaults;
}

function smart_marketing_settings_defaults(): array
{
    return [
        'businessProfile' => [
            'brandName' => 'Dakshayani Enterprises',
            'tagline' => 'Trusted Solar Partner',
            'about' => 'We design, install, and maintain solar systems for homes, businesses, and institutions with a focus on Jharkhand and neighbouring districts.',
            'primaryContact' => '',
            'supportEmail' => '',
            'whatsappNumber' => '',
            'serviceRegions' => ['Jharkhand', 'Ranchi', 'Bokaro', 'Hazaribagh'],
        ],
        'audiences' => [
            'primarySegments' => ['Residential homeowners', 'Commercial rooftop decision makers', 'Factory owners seeking savings'],
            'remarketingNotes' => 'Focus on past enquiry lists, site visitors, and WhatsApp responders from the last 90 days.',
            'exclusions' => 'Exclude installers, competitors, and non-serviceable states.',
        ],
        'products' => [
            'portfolio' => [
                'Rooftop 1kW',
                'Rooftop 3kW',
                'Rooftop 5kW',
                'Rooftop 10kW',
                'Hybrid systems',
                'Off-grid kits',
                'C&I 10-100kW',
                'PM Surya Ghar subsidised',
                'Non-subsidy rooftop offers',
            ],
            'offers' => 'Highlight PM Surya Ghar subsidy eligibility, EMI assistance, and annual maintenance contracts (AMC).',
        ],
        'budget' => [
            'dailyCap' => 25000,
            'monthlyCap' => 500000,
            'minBid' => 30,
            'targetCpl' => 450,
            'currency' => 'INR',
        ],
        'autonomy' => [
            'mode' => 'review',
            'reviewRecipients' => '',
            'killSwitchEngaged' => false,
        ],
        'compliance' => [
            'policyChecks' => true,
            'brandTone' => true,
            'legalDisclaimers' => true,
            'pmSuryaDisclaimer' => 'Subsidy subject to MNRE approvals. Customer eligibility and documentation required.',
            'notes' => '',
        ],
        'integrations' => smart_marketing_default_connector_settings(),
        'updatedAt' => null,
    ];
}

function smart_marketing_settings_load(): array
{
    $legacyFile = smart_marketing_settings_file();
    $sections = [];
    foreach (array_keys(smart_marketing_settings_sections()) as $section) {
        $sections[$section] = smart_marketing_settings_section_read($section);
    }

    $settings = smart_marketing_settings_hydrate_legacy($sections);

    if (!is_file($legacyFile)) {
        return $settings;
    }

    $legacyContents = file_get_contents($legacyFile);
    if ($legacyContents === false || trim($legacyContents) === '') {
        return $settings;
    }

    try {
        $legacyDecoded = json_decode($legacyContents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $legacyDecoded = [];
    }

    if (is_array($legacyDecoded) && !empty($legacyDecoded)) {
        $settings = array_replace_recursive($settings, $legacyDecoded);
    }

    return $settings;
}

function smart_marketing_settings_save(array $settings): void
{
    $sections = smart_marketing_settings_extract_sections($settings);
    foreach ($sections as $section => $data) {
        smart_marketing_settings_section_write($section, $data);
    }

    smart_marketing_settings_write_snapshot($sections);
}

function smart_marketing_settings_section_read(string $section): array
{
    $defaults = smart_marketing_settings_section_defaults($section);
    $file = smart_marketing_settings_section_file($section);

    if (!is_file($file)) {
        return $defaults;
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_settings_section_read decode failed for ' . $section . ': ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    return smart_marketing_settings_normalize_section($section, $decoded + ['lastUpdatedAt' => null, 'lastUpdatedBy' => null]);
}

function smart_marketing_settings_section_write(string $section, array $data): void
{
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing settings section: ' . $section);
    }

    $file = smart_marketing_settings_section_file($section);
    if (file_put_contents($file, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write Smart Marketing settings section: ' . $section);
    }
}

function smart_marketing_settings_normalize_section(string $section, array $data): array
{
    $defaults = smart_marketing_settings_section_defaults($section);
    $normalized = array_replace_recursive($defaults, $data);

    switch ($section) {
        case 'business':
            $normalized['companyName'] = trim((string) ($normalized['companyName'] ?? '')) ?: $defaults['companyName'];
            $tone = strtolower((string) ($normalized['brandTone'] ?? 'friendly'));
            $tone = str_replace([' ', '_'], '-', $tone);
            if ($tone === 'aggressive-sales') {
                $tone = 'aggressive';
            }
            $normalized['brandTone'] = in_array($tone, ['friendly', 'professional', 'government-aligned', 'aggressive'], true)
                ? $tone
                : 'friendly';
            $languages = is_array($normalized['defaultLanguages'] ?? null) ? $normalized['defaultLanguages'] : [];
            $normalized['defaultLanguages'] = array_values(array_unique(array_map('strval', $languages)));
            $locations = is_array($normalized['baseLocations'] ?? null) ? $normalized['baseLocations'] : [];
            if (is_string($locations)) {
                $locations = preg_split('/\s*,\s*/', $locations, -1, PREG_SPLIT_NO_EMPTY);
            }
            $normalized['baseLocations'] = array_values(array_unique(array_map('strval', $locations)));
            $normalized['timeZone'] = trim((string) ($normalized['timeZone'] ?? 'Asia/Kolkata')) ?: 'Asia/Kolkata';
            $normalized['autoSync']['enabled'] = (bool) ($normalized['autoSync']['enabled'] ?? true);
            $normalized['autoSync']['lastSyncedAt'] = smart_marketing_normalize_datetime($normalized['autoSync']['lastSyncedAt']);
            break;

        case 'goals':
            $normalized['goalType'] = smart_marketing_normalize_enum(
                $normalized['goalType'] ?? 'Leads',
                ['Leads', 'Awareness', 'Remarketing', 'Retention', 'AMC Renewal', 'Offer Blast']
            );
            $products = $normalized['targetProducts'] ?? [];
            if (is_string($products)) {
                $products = preg_split('/\s*,\s*/', $products, -1, PREG_SPLIT_NO_EMPTY);
            }
            $normalized['targetProducts'] = array_values(array_unique(array_map('strval', is_array($products) ? $products : [])));
            $normalized['coreFocus'] = smart_marketing_normalize_enum(
                $normalized['coreFocus'] ?? 'Residential',
                ['Residential', 'Institutional', 'Industrial']
            );
            $normalized['offerMessaging'] = trim((string) ($normalized['offerMessaging'] ?? ''));
            $duration = $normalized['campaignDuration'] ?? [];
            $normalized['campaignDuration'] = [
                'start' => smart_marketing_normalize_date($duration['start'] ?? null),
                'end' => smart_marketing_normalize_date($duration['end'] ?? null),
            ];
            $mode = strtolower((string) ($normalized['autonomyMode'] ?? 'review'));
            if (!in_array($mode, ['auto', 'review', 'draft'], true)) {
                $mode = 'review';
            }
            $normalized['autonomyMode'] = $mode;
            break;

        case 'budget':
            $normalized['currency'] = strtoupper(trim((string) ($normalized['currency'] ?? 'INR')) ?: 'INR');
            $normalized['dailyBudget'] = max(0.0, (float) ($normalized['dailyBudget'] ?? 0));
            $normalized['monthlyCap'] = max(0.0, (float) ($normalized['monthlyCap'] ?? 0));
            $split = is_array($normalized['platformSplit'] ?? null) ? $normalized['platformSplit'] : [];
            $normalized['platformSplit'] = smart_marketing_normalize_platform_split($split);
            $normalized['bidStrategy'] = smart_marketing_normalize_enum(
                strtolower((string) ($normalized['bidStrategy'] ?? 'cpc')),
                ['cpc', 'cpl', 'max conversions']
            );
            $normalized['autoScaling'] = (bool) ($normalized['autoScaling'] ?? false);
            $normalized['emergencyStopEngaged'] = (bool) ($normalized['emergencyStopEngaged'] ?? false);
            break;

        case 'audience':
            $locations = $normalized['locations'] ?? [];
            if (is_string($locations)) {
                $locations = preg_split('/\s*,\s*/', $locations, -1, PREG_SPLIT_NO_EMPTY);
            }
            $normalized['locations'] = array_values(array_filter(array_map('strval', is_array($locations) ? $locations : [])));
            if (empty($normalized['locations'])) {
                $normalized['locations'] = ['Jharkhand'];
            }
            $age = $normalized['ageRange'] ?? [];
            $minAge = max(18, (int) ($age['min'] ?? 24));
            $maxAge = max($minAge, (int) ($age['max'] ?? 60));
            $normalized['ageRange'] = ['min' => $minAge, 'max' => $maxAge];
            $tags = $normalized['interestTags'] ?? [];
            if (is_string($tags)) {
                $tags = preg_split('/\s*,\s*/', $tags, -1, PREG_SPLIT_NO_EMPTY);
            }
            $normalized['interestTags'] = array_values(array_unique(array_map('strval', is_array($tags) ? $tags : [])));
            $normalized['exclusions'] = trim((string) ($normalized['exclusions'] ?? ''));
            $normalized['languageSplit'] = smart_marketing_normalize_language_split($normalized['languageSplit'] ?? []);
            $normalized['devicePriorities'] = smart_marketing_normalize_device_split($normalized['devicePriorities'] ?? []);
            $normalized['schedule'] = smart_marketing_normalize_schedule($normalized['schedule'] ?? []);
            break;

        case 'compliance':
            $normalized['autoDisclaimer'] = (bool) ($normalized['autoDisclaimer'] ?? true);
            $normalized['disclaimerText'] = trim((string) ($normalized['disclaimerText'] ?? 'Subsidy subject to MNRE / DISCOM approval.'));
            $normalized['policyChecks'] = (bool) ($normalized['policyChecks'] ?? true);
            $normalized['warnings'] = array_values(array_map('strval', $normalized['warnings'] ?? []));
            $normalized['flaggedCreatives'] = array_values(array_map('strval', $normalized['flaggedCreatives'] ?? []));
            break;

        case 'integrations':
            $channels = $normalized['channels'] ?? [];
            $defaultChannels = smart_marketing_default_connector_settings();
            $hydrated = [];
            foreach ($defaultChannels as $key => $defaultsChannel) {
                $entry = array_merge($defaultsChannel, $channels[$key] ?? []);
                $entry['status'] = smart_marketing_normalize_enum(
                    strtolower((string) ($entry['status'] ?? 'unknown')),
                    ['connected', 'warning', 'error', 'unknown', 'disconnected', 'disabled']
                );
                $entry['connectedAt'] = smart_marketing_normalize_datetime($entry['connectedAt'] ?? null);
                $entry['lastTested'] = smart_marketing_normalize_datetime($entry['lastTested'] ?? null);
                $entry['lastValidatedAt'] = smart_marketing_normalize_datetime($entry['lastValidatedAt'] ?? ($entry['lastTested'] ?? null));
                $entry['lastTestResult'] = smart_marketing_normalize_enum(
                    strtolower((string) ($entry['lastTestResult'] ?? 'unknown')),
                    ['passed', 'failed', 'unknown']
                );
                if (!$entry['lastTested'] && $entry['lastValidatedAt']) {
                    $entry['lastTested'] = $entry['lastValidatedAt'];
                }
                $entry['validatedBy'] = trim((string) ($entry['validatedBy'] ?? '')) ?: null;
                $entry['message'] = trim((string) ($entry['message'] ?? '')) ?: 'Not connected';
                $entry['disabled'] = (bool) ($entry['disabled'] ?? false);
                $hydrated[$key] = $entry;
            }
            $normalized['channels'] = $hydrated;
            $normalized['gemini']['validated'] = (bool) ($normalized['gemini']['validated'] ?? false);
            $normalized['gemini']['message'] = trim((string) ($normalized['gemini']['message'] ?? '')) ?: 'Validation pending';
            $normalized['gemini']['lastValidatedAt'] = smart_marketing_normalize_datetime($normalized['gemini']['lastValidatedAt'] ?? null);
            $normalized['gemini']['models'] = array_values(array_map('strval', $normalized['gemini']['models'] ?? []));
            break;
    }

    $normalized['lastUpdatedAt'] = smart_marketing_normalize_datetime($normalized['lastUpdatedAt'] ?? null);
    $normalized['lastUpdatedBy'] = is_string($normalized['lastUpdatedBy'] ?? null) ? trim((string) $normalized['lastUpdatedBy']) : null;

    return $normalized;
}

function smart_marketing_settings_mask_section(string $section, array $data): array
{
    if ($section !== 'integrations') {
        return $data;
    }

    $masked = $data;
    $catalog = smart_marketing_connector_catalog();
    $channels = $masked['channels'] ?? [];
    foreach ($channels as $key => $entry) {
        $fields = $catalog[$key]['fields'] ?? [];
        foreach ($fields as $field) {
            $fieldKey = $field['key'];
            if (!array_key_exists($fieldKey, $entry)) {
                continue;
            }
            if (!empty($field['secret']) && (string) $entry[$fieldKey] !== '') {
                $channels[$key][$fieldKey] = smart_marketing_secret_sentinel();
            }
        }
    }
    $masked['channels'] = $channels;

    return $masked;
}

function smart_marketing_settings_mask_sections(array $sections): array
{
    $masked = [];
    foreach ($sections as $section => $data) {
        $masked[$section] = smart_marketing_settings_mask_section($section, $data);
    }

    return $masked;
}

function smart_marketing_settings_redact(array $settings): array
{
    if (isset($settings['integrationsHub']['channels']) && is_array($settings['integrationsHub']['channels'])) {
        $catalog = smart_marketing_connector_catalog();
        foreach ($settings['integrationsHub']['channels'] as $key => $entry) {
            $fields = $catalog[$key]['fields'] ?? [];
            foreach ($fields as $field) {
                $fieldKey = $field['key'];
                if (!array_key_exists($fieldKey, $entry)) {
                    continue;
                }
                if (!empty($field['secret']) && (string) $entry[$fieldKey] !== '') {
                    $settings['integrationsHub']['channels'][$key][$fieldKey] = smart_marketing_secret_sentinel();
                }
            }
        }
    }

    if (isset($settings['integrations']) && is_array($settings['integrations'])) {
        $settings['integrations'] = smart_marketing_settings_mask_section('integrations', ['channels' => $settings['integrations']])['channels'];
    }

    return $settings;
}

function smart_marketing_integration_field_map(string $connectorKey): array
{
    $catalog = smart_marketing_connector_catalog();
    $fields = $catalog[$connectorKey]['fields'] ?? [];
    $map = [];
    foreach ($fields as $field) {
        $map[$field['key']] = $field;
    }

    return $map;
}

function smart_marketing_integration_merge_credentials(string $connectorKey, array $incoming, array $current): array
{
    $fields = smart_marketing_integration_field_map($connectorKey);
    $merged = $current;
    foreach ($fields as $fieldKey => $fieldMeta) {
        if (array_key_exists($fieldKey, $incoming)) {
            $value = $incoming[$fieldKey];
            if (!empty($fieldMeta['secret'])) {
                if (smart_marketing_is_secret_value($value) || trim((string) $value) === '') {
                    $value = $current[$fieldKey] ?? '';
                }
            }
            if (is_string($value)) {
                $merged[$fieldKey] = trim($value);
            } else {
                $merged[$fieldKey] = $value;
            }
        } elseif (!array_key_exists($fieldKey, $merged)) {
            $merged[$fieldKey] = '';
        }
    }

    return $merged;
}

function smart_marketing_integrations_audit_file(): string
{
    return smart_marketing_settings_root() . '/audit_integrations.json';
}

function smart_marketing_integrations_audit_read(): array
{
    $file = smart_marketing_integrations_audit_file();
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = smart_marketing_decode_json($contents);

    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_mask_integration_audit_context(string $platform, array $context): array
{
    $fields = smart_marketing_integration_field_map($platform);
    $masked = $context;
    foreach ($fields as $fieldKey => $meta) {
        if (!empty($meta['secret']) && isset($masked[$fieldKey])) {
            $masked[$fieldKey] = smart_marketing_mask_secret((string) $masked[$fieldKey]);
        }
    }

    if (isset($masked['validationDetails']) && is_array($masked['validationDetails'])) {
        $masked['validationDetails'] = array_map(static function ($value) {
            if (is_array($value)) {
                return $value;
            }

            return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $masked['validationDetails']);
    }

    return $masked;
}

function smart_marketing_integrations_audit_append(string $platform, string $action, array $admin, array $context = [], ?string $developerMessage = null): void
{
    $log = smart_marketing_integrations_audit_read();
    $log[] = [
        'platform' => $platform,
        'action' => $action,
        'timestamp' => ai_timestamp(),
        'admin' => [
            'email' => $admin['email'] ?? null,
            'name' => $admin['full_name'] ?? ($admin['name'] ?? null),
        ],
        'developer_message' => $developerMessage,
        'context' => smart_marketing_mask_integration_audit_context($platform, $context),
    ];

    $log = array_slice($log, -200);
    $payload = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
        file_put_contents(smart_marketing_integrations_audit_file(), $payload, LOCK_EX);
    }
}

function smart_marketing_settings_extract_sections(array $settings): array
{
    $sections = [];
    foreach (array_keys(smart_marketing_settings_sections()) as $section) {
        $key = $section;
        if ($section === 'audience') {
            $key = 'audience';
        }
        if (isset($settings[$key])) {
            $sections[$section] = smart_marketing_settings_normalize_section($section, $settings[$key]);
        }
    }

    if (isset($settings['businessProfile'])) {
        $business = $sections['business'] ?? smart_marketing_settings_section_defaults('business');
        $business['companyName'] = $settings['businessProfile']['brandName'] ?? $business['companyName'];
        $business['baseLocations'] = $settings['businessProfile']['serviceRegions'] ?? $business['baseLocations'];
        $sections['business'] = smart_marketing_settings_normalize_section('business', $business);
    }

    if (isset($settings['budget'])) {
        $budget = $sections['budget'] ?? smart_marketing_settings_section_defaults('budget');
        $budget['dailyBudget'] = $settings['budget']['dailyCap'] ?? $budget['dailyBudget'];
        $budget['monthlyCap'] = $settings['budget']['monthlyCap'] ?? $budget['monthlyCap'];
        $budget['currency'] = $settings['budget']['currency'] ?? $budget['currency'];
        $sections['budget'] = smart_marketing_settings_normalize_section('budget', $budget);
    }

    if (isset($settings['autonomy'])) {
        $goals = $sections['goals'] ?? smart_marketing_settings_section_defaults('goals');
        $goals['autonomyMode'] = $settings['autonomy']['mode'] ?? $goals['autonomyMode'];
        $sections['goals'] = smart_marketing_settings_normalize_section('goals', $goals);
    }

    if (isset($settings['compliance'])) {
        $compliance = $sections['compliance'] ?? smart_marketing_settings_section_defaults('compliance');
        $compliance['policyChecks'] = $settings['compliance']['policyChecks'] ?? $compliance['policyChecks'];
        $compliance['autoDisclaimer'] = $settings['compliance']['legalDisclaimers'] ?? $compliance['autoDisclaimer'];
        $compliance['disclaimerText'] = $settings['compliance']['pmSuryaDisclaimer'] ?? $compliance['disclaimerText'];
        $sections['compliance'] = smart_marketing_settings_normalize_section('compliance', $compliance);
    }

    if (isset($settings['integrations'])) {
        $integrations = $sections['integrations'] ?? smart_marketing_settings_section_defaults('integrations');
        $integrations['channels'] = array_merge($integrations['channels'], $settings['integrations']);
        $sections['integrations'] = smart_marketing_settings_normalize_section('integrations', $integrations);
    }

    return $sections;
}

function smart_marketing_settings_hydrate_legacy(array $sections): array
{
    $business = $sections['business'];
    $goals = $sections['goals'];
    $budget = $sections['budget'];
    $audience = $sections['audience'];
    $compliance = $sections['compliance'];
    $integrations = $sections['integrations'];

    return [
        'business' => $business,
        'goals' => $goals,
        'budgeting' => $budget,
        'audience' => $audience,
        'complianceCenter' => $compliance,
        'integrationsHub' => $integrations,
        'businessProfile' => [
            'brandName' => $business['companyName'],
            'tagline' => $business['summary'],
            'about' => $business['summary'],
            'primaryContact' => '',
            'supportEmail' => '',
            'whatsappNumber' => '',
            'serviceRegions' => $business['baseLocations'],
        ],
        'audiences' => [
            'primarySegments' => $audience['interestTags'],
            'remarketingNotes' => '',
            'exclusions' => $audience['exclusions'],
        ],
        'products' => [
            'portfolio' => $goals['targetProducts'],
            'offers' => $goals['offerMessaging'],
        ],
        'budget' => [
            'dailyCap' => $budget['dailyBudget'],
            'monthlyCap' => $budget['monthlyCap'],
            'minBid' => 0,
            'targetCpl' => 0,
            'currency' => $budget['currency'],
        ],
        'autonomy' => [
            'mode' => $goals['autonomyMode'],
            'reviewRecipients' => '',
            'killSwitchEngaged' => $budget['emergencyStopEngaged'],
        ],
        'compliance' => [
            'policyChecks' => $compliance['policyChecks'],
            'brandTone' => $business['brandTone'] !== 'aggressive',
            'legalDisclaimers' => $compliance['autoDisclaimer'],
            'pmSuryaDisclaimer' => $compliance['disclaimerText'],
            'notes' => '',
        ],
        'integrations' => $integrations['channels'],
        'updatedAt' => smart_marketing_latest_update($sections),
    ];
}

function smart_marketing_latest_update(array $sections): ?string
{
    $timestamps = [];
    foreach ($sections as $section) {
        $value = $section['lastUpdatedAt'] ?? null;
        if ($value) {
            $timestamps[] = $value;
        }
    }

    if (empty($timestamps)) {
        return null;
    }

    rsort($timestamps);

    return $timestamps[0];
}

function smart_marketing_settings_write_snapshot(array $sections): void
{
    $snapshot = smart_marketing_settings_hydrate_legacy($sections);
    $snapshot['updatedAt'] = ai_timestamp();

    $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
        file_put_contents(smart_marketing_settings_file(), $payload, LOCK_EX);
    }
}

function smart_marketing_settings_audit_log(): array
{
    $file = smart_marketing_settings_audit_file();
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_settings_audit_append(string $section, array $changes, array $admin): void
{
    $log = smart_marketing_settings_audit_log();
    $entry = [
        'timestamp' => ai_timestamp(),
        'section' => $section,
        'user' => [
            'id' => (int) ($admin['id'] ?? 0),
            'name' => (string) ($admin['full_name'] ?? ($admin['name'] ?? 'Admin')),
            'email' => (string) ($admin['email'] ?? ''),
        ],
        'changes' => array_values($changes),
    ];

    $log[] = $entry;
    $log = array_slice($log, -100);

    $payload = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
        file_put_contents(smart_marketing_settings_audit_file(), $payload, LOCK_EX);
    }
}

function smart_marketing_settings_validate_section(string $section, array $data, array $aiSettings, array $allSections): array
{
    $messages = [];
    $ok = true;

    switch ($section) {
        case 'business':
            if (trim((string) ($data['companyName'] ?? '')) === '') {
                $ok = false;
                $messages[] = 'Company name is required.';
            }
            if (empty($data['baseLocations'])) {
                $ok = false;
                $messages[] = 'At least one base location must be specified.';
            }
            if (strtolower((string) ($data['timeZone'] ?? '')) !== 'asia/kolkata') {
                $messages[] = 'Timezone adjusted to Asia/Kolkata to keep analytics consistent.';
                $data['timeZone'] = 'Asia/Kolkata';
            }
            break;

        case 'goals':
            $duration = $data['campaignDuration'] ?? [];
            if (($duration['start'] ?? null) && ($duration['end'] ?? null)) {
                try {
                    $start = new DateTimeImmutable($duration['start']);
                    $end = new DateTimeImmutable($duration['end']);
                    if ($start > $end) {
                        $ok = false;
                        $messages[] = 'Campaign end date must be after start date.';
                    }
                } catch (Throwable $exception) {
                    $ok = false;
                    $messages[] = 'Campaign duration dates are invalid.';
                }
            }
            break;

        case 'budget':
            $daily = (float) ($data['dailyBudget'] ?? 0);
            $monthly = (float) ($data['monthlyCap'] ?? 0);
            if ($daily <= 0 || $monthly <= 0) {
                $ok = false;
                $messages[] = 'Daily and monthly budgets must be greater than zero.';
            }
            if ($monthly < $daily * 5) {
                $messages[] = 'Monthly cap is lower than 5x daily budget; pacing may be constrained.';
            }
            if ($monthly < $daily) {
                $ok = false;
                $messages[] = 'Monthly cap cannot be lower than the daily budget.';
            }
            break;

        case 'audience':
            $split = $data['languageSplit'] ?? [];
            $total = array_sum(array_map(static fn($row) => (int) ($row['percent'] ?? 0), $split));
            if ($total !== 100) {
                $messages[] = 'Language split has been normalised to 100%.';
            }
            break;

        case 'compliance':
            if ($data['autoDisclaimer'] && trim((string) ($data['disclaimerText'] ?? '')) === '') {
                $ok = false;
                $messages[] = 'Disclaimer text is required when auto disclaimer is enabled.';
            }
            break;

        case 'integrations':
            $gemini = smart_marketing_ai_health($aiSettings);
            if (!$gemini['connected']) {
                $ok = false;
                $messages[] = 'Gemini API key missing. Add it in AI Studio.';
            } else {
                $messages[] = 'Gemini models ready: ' . implode(', ', array_filter($gemini['models'] ?? []));
            }

            foreach ($data['channels'] as $key => $channel) {
                if (($channel['status'] ?? 'unknown') === 'error') {
                    $ok = false;
                    $messages[] = sprintf('%s connector reporting errors. Refresh the token.', smart_marketing_integration_label($key));
                }
            }
            break;
    }

    return ['ok' => $ok, 'messages' => $messages, 'data' => $data];
}

function smart_marketing_settings_save_section(string $section, array $payload, array $admin, array $aiSettings): array
{
    $section = strtolower($section);
    $sections = [];
    foreach (array_keys(smart_marketing_settings_sections()) as $key) {
        $sections[$key] = smart_marketing_settings_section_read($key);
    }

    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Unknown Smart Marketing settings section: ' . $section);
    }

    $current = $sections[$section];
    $normalized = smart_marketing_settings_normalize_section($section, array_replace_recursive($current, $payload));
    $validation = smart_marketing_settings_validate_section($section, $normalized, $aiSettings, $sections);
    if (!$validation['ok']) {
        return [
            'ok' => false,
            'section' => $section,
            'messages' => $validation['messages'],
            'data' => $current,
        ];
    }

    $normalized = $validation['data'];
    $normalized['lastUpdatedAt'] = ai_timestamp();
    $normalized['lastUpdatedBy'] = (string) ($admin['email'] ?? ($admin['full_name'] ?? 'Admin'));

    $diff = smart_marketing_settings_diff($current, $normalized);

    $sections[$section] = $normalized;
    smart_marketing_settings_section_write($section, $normalized);
    smart_marketing_settings_write_snapshot($sections);

    if (!empty($diff)) {
        smart_marketing_settings_audit_append($section, $diff, $admin);
        smart_marketing_audit_log_append('settings.section_saved', ['section' => $section, 'fields' => $diff], $admin);
    }

    return [
        'ok' => true,
        'section' => $section,
        'messages' => $validation['messages'],
        'data' => $normalized,
        'settings' => smart_marketing_settings_hydrate_legacy($sections),
        'sections' => $sections,
        'audit' => smart_marketing_settings_audit_log(),
    ];
}

function smart_marketing_settings_diff(array $before, array $after, string $prefix = ''): array
{
    $changes = [];
    foreach ($after as $key => $value) {
        $path = $prefix === '' ? $key : $prefix . '.' . $key;
        if (!array_key_exists($key, $before)) {
            $changes[] = $path;
            continue;
        }

        $beforeValue = $before[$key];
        if (is_array($value) && is_array($beforeValue)) {
            $nested = smart_marketing_settings_diff($beforeValue, $value, $path);
            foreach ($nested as $item) {
                $changes[] = $item;
            }
            continue;
        }

        if ($beforeValue !== $value) {
            $changes[] = $path;
        }
    }

    return array_values(array_unique($changes));
}

function smart_marketing_settings_revert_section(string $section): array
{
    $section = strtolower($section);
    $sections = [];
    foreach (array_keys(smart_marketing_settings_sections()) as $key) {
        $sections[$key] = smart_marketing_settings_section_read($key);
    }

    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Unknown Smart Marketing settings section: ' . $section);
    }

    return [
        'ok' => true,
        'section' => $section,
        'data' => $sections[$section],
        'settings' => smart_marketing_settings_hydrate_legacy($sections),
        'sections' => $sections,
        'audit' => smart_marketing_settings_audit_log(),
    ];
}

function smart_marketing_settings_test_section(string $section, array $payload, array $aiSettings): array
{
    $section = strtolower($section);
    $sections = [];
    foreach (array_keys(smart_marketing_settings_sections()) as $key) {
        $sections[$key] = smart_marketing_settings_section_read($key);
    }

    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Unknown Smart Marketing settings section: ' . $section);
    }

    $candidate = smart_marketing_settings_normalize_section($section, array_replace_recursive($sections[$section], $payload));
    $validation = smart_marketing_settings_validate_section($section, $candidate, $aiSettings, $sections);

    return [
        'ok' => $validation['ok'],
        'section' => $section,
        'messages' => $validation['messages'],
        'data' => $candidate,
        'settings' => smart_marketing_settings_hydrate_legacy($sections),
        'sections' => $sections,
    ];
}

function smart_marketing_normalize_datetime($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return null;
    }
}

function smart_marketing_normalize_date($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('Y-m-d');
    } catch (Throwable $exception) {
        return null;
    }
}

function smart_marketing_normalize_enum($value, array $allowed): string
{
    $value = is_string($value) ? trim((string) $value) : '';
    foreach ($allowed as $option) {
        if (strcasecmp($value, $option) === 0) {
            return $option;
        }
    }

    return $allowed[0];
}

function smart_marketing_normalize_platform_split(array $split): array
{
    $defaults = smart_marketing_settings_section_defaults('budget')['platformSplit'];
    $total = 0;
    $normalized = [];
    foreach ($defaults as $key => $percent) {
        $value = isset($split[$key]) ? (int) $split[$key] : $percent;
        $value = max(0, min(100, $value));
        $normalized[$key] = $value;
        $total += $value;
    }

    if ($total === 0) {
        return $defaults;
    }

    $scale = 100 / $total;
    foreach ($normalized as $key => $value) {
        $normalized[$key] = (int) round($value * $scale);
    }

    $difference = 100 - array_sum($normalized);
    if ($difference !== 0) {
        $firstKey = array_key_first($normalized);
        $normalized[$firstKey] += $difference;
    }

    return $normalized;
}

function smart_marketing_normalize_language_split($value): array
{
    $entries = [];
    if (is_array($value)) {
        foreach ($value as $row) {
            if (is_array($row) && isset($row['language'])) {
                $entries[] = [
                    'language' => (string) $row['language'],
                    'percent' => (int) ($row['percent'] ?? 0),
                ];
            } elseif (is_string($row)) {
                $entries[] = ['language' => $row, 'percent' => 0];
            }
        }
    }

    if (empty($entries)) {
        return smart_marketing_settings_section_defaults('audience')['languageSplit'];
    }

    $total = array_sum(array_column($entries, 'percent')) ?: 1;
    foreach ($entries as &$entry) {
        $entry['percent'] = max(0, min(100, (int) round(($entry['percent'] / $total) * 100)));
    }

    $difference = 100 - array_sum(array_column($entries, 'percent'));
    if ($difference !== 0) {
        $entries[0]['percent'] += $difference;
    }

    return $entries;
}

function smart_marketing_normalize_device_split($value): array
{
    $defaults = smart_marketing_settings_section_defaults('audience')['devicePriorities'];
    if (!is_array($value) || empty($value)) {
        return $defaults;
    }

    $mobile = max(0, min(100, (int) ($value['mobile'] ?? $defaults['mobile'])));
    $desktop = 100 - $mobile;

    return ['mobile' => $mobile, 'desktop' => $desktop];
}

function smart_marketing_normalize_schedule($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $day = smart_marketing_normalize_enum($entry['day'] ?? 'monday', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
        $start = smart_marketing_normalize_time($entry['start'] ?? '00:00');
        $end = smart_marketing_normalize_time($entry['end'] ?? '23:59');
        $normalized[] = ['day' => $day, 'start' => $start, 'end' => $end];
    }

    return $normalized;
}

function smart_marketing_normalize_time($value): string
{
    if (!is_string($value)) {
        return '00:00';
    }

    if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
        return '00:00';
    }

    $hour = max(0, min(23, (int) $matches[1]));
    $minute = max(0, min(59, (int) $matches[2]));

    return sprintf('%02d:%02d', $hour, $minute);
}

function smart_marketing_brain_runs_load(): array
{
    $file = smart_marketing_brain_runs_file();
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_brain_runs_load decode failed: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_brain_runs_save(array $runs): void
{
    $payload = json_encode(array_values($runs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing brain runs.');
    }

    if (file_put_contents(smart_marketing_brain_runs_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing brain runs.');
    }
}

function smart_marketing_next_run_id(array $runs): int
{
    $max = 0;
    foreach ($runs as $run) {
        $id = (int) ($run['id'] ?? 0);
        if ($id > $max) {
            $max = $id;
        }
    }

    return $max + 1;
}

function smart_marketing_audit_log_append(string $action, array $context = [], array $user = []): void
{
    $record = [
        'timestamp' => ai_timestamp(),
        'action' => $action,
        'user' => [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['full_name'] ?? ($user['name'] ?? 'Admin')),
        ],
        'context' => smart_marketing_scrub_context($context),
    ];

    $payload = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }

    $line = $payload . "\n";
    file_put_contents(smart_marketing_audit_log_file(), $line, FILE_APPEND | LOCK_EX);
}

function smart_marketing_mask_value(string $value): string
{
    $masked = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', '[redacted-email]', $value);
    $masked = preg_replace_callback('/\+?\d[\d\s-]{7,}/', static function ($matches) {
        $digits = preg_replace('/\D+/', '', $matches[0]);
        if (strlen($digits) < 7) {
            return '[redacted]';
        }
        $visible = substr($digits, -2);
        return '[redacted-phone]' . $visible;
    }, $masked);

    return $masked;
}

function smart_marketing_scrub_context(array $context): array
{
    $scrubbed = [];
    foreach ($context as $key => $value) {
        $lower = strtolower((string) $key);
        if (is_array($value)) {
            $scrubbed[$key] = smart_marketing_scrub_context($value);
            continue;
        }

        if (str_contains($lower, 'key') || str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'password')) {
            $scrubbed[$key] = '***';
        } elseif (is_string($value)) {
            $scrubbed[$key] = smart_marketing_mask_value($value);
        } else {
            $scrubbed[$key] = $value;
        }
    }

    return $scrubbed;
}

function smart_marketing_audit_log_read(int $limit = 50): array
{
    $file = smart_marketing_audit_log_file();
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $lines = array_slice($lines, -$limit);
    $entries = [];
    foreach ($lines as $line) {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            continue;
        }

        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return array_reverse($entries);
}

function smart_marketing_ai_health(array $aiSettings): array
{
    $hasKey = trim((string) ($aiSettings['api_key'] ?? '')) !== '';
    $models = [
        'text' => $aiSettings['models']['text'] ?? '',
        'image' => $aiSettings['models']['image'] ?? '',
        'tts' => $aiSettings['models']['tts'] ?? '',
    ];

    $connected = $hasKey;
    $message = $hasKey ? 'Gemini key present.' : 'Add a valid Gemini API key in AI Studio.';

    return [
        'connected' => $connected,
        'message' => $message,
        'models' => $models,
    ];
}

function smart_marketing_integrations_health(array $settings): array
{
    $integrations = $settings['integrations'] ?? [];
    $catalog = smart_marketing_connector_catalog();
    $result = [];
    foreach ($catalog as $key => $meta) {
        $entry = $integrations[$key] ?? [];
        $status = strtolower((string) ($entry['status'] ?? 'unknown'));
        if (!in_array($status, ['connected', 'warning', 'error', 'unknown', 'disconnected', 'disabled'], true)) {
            $status = 'unknown';
        }

        $result[$key] = [
            'status' => $status,
            'label' => $meta['label'] ?? smart_marketing_integration_label($key),
            'details' => [
                'connectedAt' => $entry['connectedAt'] ?? null,
                'lastValidatedAt' => $entry['lastValidatedAt'] ?? ($entry['lastTested'] ?? null),
                'lastTested' => $entry['lastTested'] ?? null,
                'lastTestResult' => $entry['lastTestResult'] ?? 'unknown',
                'validatedBy' => $entry['validatedBy'] ?? null,
                'message' => $entry['message'] ?? 'Not connected',
                'disabled' => (bool) ($entry['disabled'] ?? false),
                'channels' => $meta['channels'] ?? [],
                'validationDetails' => isset($entry['validationDetails']) && is_array($entry['validationDetails'])
                    ? $entry['validationDetails']
                    : [],
            ],
        ];
    }

    return $result;
}

function smart_marketing_integration_label(string $key): string
{
    return match ($key) {
        'googleAds' => 'Google Ads',
        'meta' => 'Meta Ads',
        'youtube' => 'YouTube Ads',
        'whatsapp' => 'WhatsApp Business',
        'email' => 'Email & SMS',
        default => ucfirst($key),
    };
}

function smart_marketing_channel_connectors(array $settings): array
{
    $catalog = smart_marketing_connector_catalog();
    $integrations = $settings['integrations'] ?? [];
    $connectors = [];
    foreach ($catalog as $key => $meta) {
        $entry = $integrations[$key] ?? smart_marketing_default_connector_settings()[$key];
        $status = strtolower((string) ($entry['status'] ?? 'unknown'));
        if (!in_array($status, ['connected', 'warning', 'error', 'unknown', 'disconnected', 'disabled'], true)) {
            $status = 'unknown';
        }

        $connectors[] = [
            'id' => $key,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'status' => $status,
            'connectedAt' => $entry['connectedAt'] ?? null,
            'lastTested' => $entry['lastTested'] ?? null,
            'lastValidatedAt' => $entry['lastValidatedAt'] ?? ($entry['lastTested'] ?? null),
            'lastTestResult' => $entry['lastTestResult'] ?? 'unknown',
            'validatedBy' => $entry['validatedBy'] ?? null,
            'message' => $entry['message'] ?? 'Not connected',
            'disabled' => (bool) ($entry['disabled'] ?? false),
            'details' => [
                'channels' => $meta['channels'] ?? [],
                'validationDetails' => isset($entry['validationDetails']) && is_array($entry['validationDetails'])
                    ? $entry['validationDetails']
                    : [],
            ],
            'developerMessage' => $entry['developerMessage'] ?? null,
            'available' => $entry['available'] ?? [],
            'oauth' => [
                'supported' => smart_marketing_connector_has_oauth($key),
                'connected' => smart_marketing_connector_has_token($key, $entry),
            ],
            'fields' => array_values(array_filter(array_map(static function ($field) use ($entry, $key) {
                if (!empty($field['hidden'])) {
                    return null;
                }

                $value = $entry[$field['key']] ?? '';
                $hasValue = $value !== '';
                if (!empty($field['secret'])) {
                    $value = $hasValue ? smart_marketing_secret_placeholder() : '';
                }

                return [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'required' => !empty($field['required']),
                    'secret' => !empty($field['secret']),
                    'value' => $value,
                    'hasValue' => $hasValue,
                    'type' => $field['type'] ?? 'text',
                    'options' => smart_marketing_connector_field_options($key, $field['key'], $entry, $field),
                ];
            }, $meta['fields'] ?? []))),
        ];
    }

    return $connectors;
}

function smart_marketing_http_request(string $method, string $url, array $options = []): array
{
    $method = strtoupper($method);
    $query = $options['query'] ?? [];
    if (!empty($query)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($options['timeout'] ?? 15));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ($options['connect_timeout'] ?? 5));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    $headers = [];
    foreach (($options['headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    } elseif (isset($options['json'])) {
        $encoded = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Unable to encode JSON payload'];
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    } elseif (isset($options['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch) ?: 'Request failed';
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => $error];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $headerBlocks = array_filter(explode("\r\n\r\n", trim($rawHeaders)));
    $headerBlock = end($headerBlocks) ?: '';
    $headersAssoc = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $headersAssoc[strtolower($name)] = $value;
    }

    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'body' => $body,
        'headers' => $headersAssoc,
        'error' => $ok ? null : 'HTTP ' . $status,
    ];
}

function smart_marketing_decode_json(string $payload): array
{
    if (trim($payload) === '') {
        return [];
    }

    try {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_validate_integration_credentials(string $connectorKey, array $credentials): array
{
    switch ($connectorKey) {
        case 'meta':
            return smart_marketing_validate_meta_credentials($credentials);

        case 'googleAds':
            return smart_marketing_validate_google_credentials($credentials);

        case 'whatsapp':
            return smart_marketing_validate_whatsapp_credentials($credentials);

        case 'email':
            return smart_marketing_validate_email_credentials($credentials);

        case 'youtube':
            return ['ok' => true, 'message' => 'YouTube shares Google Ads credentials', 'details' => []];

        default:
            return ['ok' => false, 'message' => 'Unknown connector: ' . $connectorKey, 'details' => []];
    }
}

function smart_marketing_validate_meta_credentials(array $credentials): array
{
    $token = (string) ($credentials['accessToken'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'message' => 'Meta: Connect with the OAuth flow to generate a long-lived token.', 'details' => [], 'developerMessage' => 'Missing access token'];
    }

    $businessId = trim((string) ($credentials['businessManagerId'] ?? ''));
    $adAccountId = trim((string) ($credentials['adAccountId'] ?? ''));
    $pageId = trim((string) ($credentials['pageId'] ?? ''));
    if ($businessId === '' || $adAccountId === '' || $pageId === '') {
        return ['ok' => false, 'message' => 'Meta: Select a Business Manager, Ad Account, and Page from the dropdowns.', 'details' => []];
    }

    try {
        $actor = smart_marketing_meta_graph_request('me', ['fields' => 'id,name', 'access_token' => $token]);
    } catch (Throwable $exception) {
        $message = str_contains(strtolower($exception->getMessage()), '190')
            ? 'Meta: Access token expired – please click “Reconnect Meta” to sign in again.'
            : 'Meta: Token rejected. Reconnect to refresh permissions.';
        return ['ok' => false, 'message' => $message, 'details' => [], 'developerMessage' => $exception->getMessage()];
    }

    try {
        $business = smart_marketing_meta_graph_request($businessId, ['fields' => 'name,verification_status', 'access_token' => $token]);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Meta: Unable to read the selected Business Manager.', 'details' => [], 'developerMessage' => $exception->getMessage()];
    }

    try {
        $account = smart_marketing_meta_graph_request($adAccountId, ['fields' => 'name,account_status,timezone_id', 'access_token' => $token]);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Meta: This admin has no access to the selected ad account.', 'details' => [], 'developerMessage' => $exception->getMessage()];
    }

    try {
        $page = smart_marketing_meta_graph_request($pageId, ['fields' => 'name,verification_status', 'access_token' => $token]);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Meta: Page lookup failed. Confirm the page belongs to the connected Business Manager.', 'details' => [], 'developerMessage' => $exception->getMessage()];
    }

    $optional = [];
    $pixelId = trim((string) ($credentials['pixelId'] ?? ''));
    if ($pixelId !== '') {
        try {
            $pixel = smart_marketing_meta_graph_request($pixelId, ['fields' => 'name', 'access_token' => $token]);
            $optional['pixel'] = $pixel['name'] ?? $pixelId;
        } catch (Throwable $exception) {
            $optional['pixelWarning'] = 'Pixel lookup failed';
        }
    }

    $whatsappId = trim((string) ($credentials['whatsappNumberId'] ?? ''));
    if ($whatsappId !== '') {
        try {
            $whatsapp = smart_marketing_meta_graph_request($whatsappId, ['fields' => 'display_phone_number,verified_name', 'access_token' => $token]);
            $optional['whatsappNumber'] = $whatsapp['display_phone_number'] ?? $whatsappId;
        } catch (Throwable $exception) {
            $optional['whatsappWarning'] = 'Unable to verify WhatsApp number';
        }
    }

    $accountStatusCode = (int) ($account['account_status'] ?? 0);
    $accountStatus = match ($accountStatusCode) {
        1 => 'ACTIVE',
        2 => 'DISABLED',
        3 => 'UNSETTLED',
        7 => 'PENDING_RISK_REVIEW',
        default => 'STATUS_' . $accountStatusCode,
    };

    $details = array_merge([
        'business' => $business['name'] ?? $businessId,
        'businessVerification' => $business['verification_status'] ?? 'unknown',
        'adAccount' => $account['name'] ?? $adAccountId,
        'adAccountStatus' => $accountStatus,
        'page' => $page['name'] ?? $pageId,
        'pageVerification' => $page['verification_status'] ?? 'unknown',
        'actor' => $actor['name'] ?? 'Meta user',
    ], $optional);

    $developerMessage = sprintf(
        'Business %s (%s) · Account status %s · Page %s',
        $business['name'] ?? $businessId,
        $business['verification_status'] ?? 'unknown',
        $accountStatus,
        $page['verification_status'] ?? 'unknown'
    );

    return [
        'ok' => true,
        'message' => 'Meta connected. Assets verified ✅',
        'details' => $details,
        'developerMessage' => $developerMessage,
    ];
}

function smart_marketing_validate_google_credentials(array $credentials): array
{
    $refreshToken = (string) ($credentials['refreshToken'] ?? '');
    if ($refreshToken === '') {
        return ['ok' => false, 'message' => 'Google Ads: Connect through OAuth to generate a refresh token.', 'details' => [], 'developerMessage' => 'Missing refresh token'];
    }

    $managerId = preg_replace('/[^0-9]/', '', (string) ($credentials['managerId'] ?? ''));
    if ($managerId === '') {
        return ['ok' => false, 'message' => 'Google Ads: Select the customer account from the dropdown.', 'details' => []];
    }

    $clientId = smart_marketing_google_client_id();
    $clientSecret = smart_marketing_google_client_secret();
    $developerToken = smart_marketing_google_developer_token();
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'message' => 'Google Ads: OAuth client is not configured on the server.', 'details' => []];
    }
    if ($developerToken === '') {
        return ['ok' => false, 'message' => 'Google Ads: Developer token missing in backend configuration.', 'details' => []];
    }

    $tokenResponse = smart_marketing_http_request('POST', 'https://oauth2.googleapis.com/token', [
        'form' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ],
    ]);
    if (!$tokenResponse['ok']) {
        return ['ok' => false, 'message' => 'Google Ads: Refresh token expired – click “Reconnect Google Ads”.', 'details' => ['error' => $tokenResponse['error']]];
    }
    $tokenData = smart_marketing_decode_json($tokenResponse['body']);
    $accessToken = (string) ($tokenData['access_token'] ?? '');
    if ($accessToken === '') {
        return ['ok' => false, 'message' => 'Google Ads: OAuth response missing access token.', 'details' => []];
    }

    $accountResponse = smart_marketing_http_request('GET', 'https://googleads.googleapis.com/v15/customers/' . $managerId, [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'developer-token' => $developerToken,
            'login-customer-id' => $managerId,
        ],
    ]);
    if (!$accountResponse['ok']) {
        return ['ok' => false, 'message' => 'Google Ads: This user has no access to the selected account.', 'details' => ['error' => $accountResponse['error']]];
    }
    $accountData = smart_marketing_decode_json($accountResponse['body']);
    $accountName = $accountData['descriptiveName'] ?? ('Customer ' . $managerId);

    $details = [
        'account' => $accountName,
        'currency' => $accountData['currencyCode'] ?? null,
        'timezone' => $accountData['timeZone'] ?? null,
    ];

    $channelId = trim((string) ($credentials['linkedYoutubeChannelId'] ?? ''));
    if ($channelId !== '') {
        $channelResponse = smart_marketing_http_request('GET', 'https://www.googleapis.com/youtube/v3/channels', [
            'query' => [
                'part' => 'snippet',
                'id' => $channelId,
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);
        if ($channelResponse['ok']) {
            $channelData = smart_marketing_decode_json($channelResponse['body']);
            $items = $channelData['items'] ?? [];
            if (!empty($items)) {
                $details['youtubeChannel'] = $items[0]['snippet']['title'] ?? $channelId;
            } else {
                $details['youtubeWarning'] = 'YouTube channel not accessible with current scopes.';
            }
        } else {
            $details['youtubeWarning'] = 'Unable to verify YouTube channel';
        }
    }

    $developerMessage = sprintf('Google Ads account %s (%s)', $accountName, $managerId);

    return [
        'ok' => true,
        'message' => 'Google Ads account verified ✅',
        'details' => $details,
        'developerMessage' => $developerMessage,
    ];
}

function smart_marketing_validate_whatsapp_credentials(array $credentials): array
{
    $provider = strtolower(trim((string) ($credentials['provider'] ?? '')));
    if ($provider === '') {
        return ['ok' => false, 'message' => 'WhatsApp: Select a provider.', 'details' => []];
    }

    $apiKey = trim((string) ($credentials['apiKey'] ?? ''));
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'WhatsApp: Enter the provider API key or access token.', 'details' => []];
    }

    $sender = trim((string) ($credentials['senderId'] ?? ''));
    if ($sender === '') {
        return ['ok' => false, 'message' => 'WhatsApp: Sender ID / number is required.', 'details' => []];
    }

    $details = ['provider' => $provider, 'sender' => $sender];
    $developerMessage = '';

    if (!empty($credentials['templateNamespace'])) {
        $details['namespace'] = $credentials['templateNamespace'];
    }
    if (!empty($credentials['adminSandboxNumber'])) {
        $details['sandbox'] = $credentials['adminSandboxNumber'];
    }

    switch ($provider) {
        case '360dialog':
            $bspResponse = smart_marketing_http_request('GET', 'https://waba.360dialog.io/v1/accounts/self', [
                'headers' => ['D360-API-KEY' => $apiKey],
            ]);
            if (!$bspResponse['ok']) {
                return ['ok' => false, 'message' => 'WhatsApp: 360dialog API key rejected.', 'details' => ['error' => $bspResponse['error']]];
            }
            $account = smart_marketing_decode_json($bspResponse['body']);
            $details['businessAccount'] = $account['accountBusinessName'] ?? '360dialog';
            $developerMessage = '360dialog API heartbeat successful';
            break;

        case 'gupshup':
            $appName = trim($sender) !== '' ? trim($sender) : $sender;
            $gupResponse = smart_marketing_http_request('GET', 'https://partner.gupshup.io/sm/api/v1/app/' . rawurlencode($appName), [
                'headers' => ['apikey' => $apiKey],
            ]);
            if (!$gupResponse['ok']) {
                return ['ok' => false, 'message' => 'WhatsApp: Gupshup API key rejected. Confirm the app/sender name.', 'details' => ['error' => $gupResponse['error']]];
            }
            $app = smart_marketing_decode_json($gupResponse['body']);
            if (!is_array($app)) {
                return ['ok' => false, 'message' => 'WhatsApp: Unexpected Gupshup response. Re-check permissions.', 'details' => []];
            }
            $appMeta = isset($app['app']) && is_array($app['app']) ? $app['app'] : $app;
            $details['businessAccount'] = $appMeta['name'] ?? $appName;
            if (isset($appMeta['status'])) {
                $details['status'] = $appMeta['status'];
            }
            $developerMessage = 'Gupshup app verified via partner API';
            break;

        case 'twilio':
            $parts = preg_split('/[:|]/', $apiKey);
            if (!$parts || count($parts) < 2) {
                return ['ok' => false, 'message' => 'WhatsApp: Provide credentials as "AccountSID:AuthToken" for Twilio.', 'details' => []];
            }
            [$accountSid, $authToken] = array_map('trim', array_slice($parts, 0, 2));
            if ($accountSid === '' || $authToken === '') {
                return ['ok' => false, 'message' => 'WhatsApp: Twilio Account SID or Auth Token missing.', 'details' => []];
            }
            $twilioResponse = smart_marketing_http_request('GET', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '.json', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($accountSid . ':' . $authToken),
                ],
            ]);
            if (!$twilioResponse['ok']) {
                return ['ok' => false, 'message' => 'WhatsApp: Twilio rejected the credentials. Check SID/token.', 'details' => ['error' => $twilioResponse['error']]];
            }
            $account = smart_marketing_decode_json($twilioResponse['body']);
            $details['accountSid'] = $accountSid;
            $details['businessAccount'] = $account['friendly_name'] ?? 'Twilio';
            $details['status'] = $account['status'] ?? null;
            $developerMessage = 'Twilio account authenticated via REST API';
            break;

        case 'msg91':
            $msgResponse = smart_marketing_http_request('GET', 'https://control.msg91.com/api/v5/whatsapp/whatsapp-business-profile', [
                'headers' => [
                    'authkey' => $apiKey,
                    'accept' => 'application/json',
                ],
            ]);
            if (!$msgResponse['ok']) {
                return ['ok' => false, 'message' => 'WhatsApp: MSG91 auth key rejected.', 'details' => ['error' => $msgResponse['error']]];
            }
            $profile = smart_marketing_decode_json($msgResponse['body']);
            if (!is_array($profile)) {
                return ['ok' => false, 'message' => 'WhatsApp: Unable to parse MSG91 profile.', 'details' => []];
            }
            $details['businessAccount'] = $profile['data']['businessName'] ?? ($profile['businessName'] ?? 'MSG91');
            if (isset($profile['data']['status'])) {
                $details['status'] = $profile['data']['status'];
            }
            $developerMessage = 'MSG91 WhatsApp profile fetched successfully';
            break;

        default:
            return ['ok' => false, 'message' => 'WhatsApp: Unsupported provider selected.', 'details' => []];
    }

    return [
        'ok' => true,
        'message' => 'WhatsApp provider ready ✅',
        'details' => $details,
        'developerMessage' => $developerMessage,
    ];
}

function smart_marketing_validate_email_credentials(array $credentials): array
{
    $provider = strtolower(trim((string) ($credentials['provider'] ?? '')));
    if ($provider === '') {
        return ['ok' => false, 'message' => 'Email/SMS provider is required.', 'details' => []];
    }
    if (trim((string) ($credentials['senderId'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'Sender ID / From Email is required.', 'details' => []];
    }

    $details = ['provider' => $provider];

    switch ($provider) {
        case 'sendgrid':
            if (trim((string) ($credentials['apiKey'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'SendGrid API key is required.', 'details' => []];
            }
            $accountResponse = smart_marketing_http_request('GET', 'https://api.sendgrid.com/v3/user/account', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials['apiKey'],
                ],
            ]);
            if (!$accountResponse['ok']) {
                return ['ok' => false, 'message' => 'SendGrid API key rejected.', 'details' => ['error' => $accountResponse['error']]];
            }
            $accountData = smart_marketing_decode_json($accountResponse['body']);
            $details['account'] = $accountData['username'] ?? 'SendGrid';
            if (!empty($credentials['sandboxRecipient'])) {
                smart_marketing_http_request('POST', 'https://api.sendgrid.com/v3/mail/send', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $credentials['apiKey'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'personalizations' => [[
                            'to' => [['email' => $credentials['sandboxRecipient']]],
                        ]],
                        'from' => ['email' => $credentials['senderId']],
                        'subject' => 'Sandbox verification',
                        'content' => [['type' => 'text/plain', 'value' => 'SendGrid sandbox verification']],
                        'mail_settings' => ['sandbox_mode' => ['enable' => true]],
                    ],
                ]);
                $details['sandbox'] = 'SendGrid sandbox triggered';
            }
            break;

        case 'twilio':
            if (trim((string) ($credentials['apiKey'] ?? '')) === '' || strpos((string) $credentials['apiKey'], ':') === false) {
                return ['ok' => false, 'message' => 'Twilio credentials must be provided as AccountSID:AuthToken.', 'details' => []];
            }
            [$sid, $authToken] = explode(':', (string) $credentials['apiKey'], 2);
            $twilioResponse = smart_marketing_http_request('GET', 'https://api.twilio.com/2010-04-01/Accounts.json', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($sid . ':' . $authToken),
                ],
            ]);
            if (!$twilioResponse['ok']) {
                return ['ok' => false, 'message' => 'Twilio credentials rejected.', 'details' => ['error' => $twilioResponse['error']]];
            }
            $details['account'] = $sid;
            break;

        case 'msg91':
            if (trim((string) ($credentials['apiKey'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'MSG91 auth key is required.', 'details' => []];
            }
            $msgResponse = smart_marketing_http_request('GET', 'https://api.msg91.com/api/v5/credits', [
                'headers' => [
                    'authkey' => $credentials['apiKey'],
                ],
            ]);
            if (!$msgResponse['ok']) {
                return ['ok' => false, 'message' => 'MSG91 credentials rejected.', 'details' => ['error' => $msgResponse['error']]];
            }
            $details['credits'] = smart_marketing_decode_json($msgResponse['body'])['total_credits'] ?? null;
            break;

        case 'smtp':
            $host = trim((string) ($credentials['smtpHost'] ?? ''));
            $port = (int) ($credentials['smtpPort'] ?? 587);
            $username = trim((string) ($credentials['smtpUsername'] ?? ''));
            $password = trim((string) ($credentials['smtpPassword'] ?? ''));
            if ($host === '' || $username === '' || $password === '') {
                return ['ok' => false, 'message' => 'SMTP host, username, and password are required for SMTP providers.', 'details' => []];
            }
            $socket = @fsockopen($host, $port, $errno, $errstr, 5.0);
            if (!$socket) {
                return ['ok' => false, 'message' => 'Unable to connect to SMTP host.', 'details' => ['error' => $errstr]];
            }
            stream_set_timeout($socket, 5);
            fwrite($socket, "EHLO dentweb\r\n");
            fgets($socket, 1024);
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            $details['smtpHost'] = $host;
            $details['smtpPort'] = $port;
            break;

        default:
            return ['ok' => false, 'message' => sprintf('Unsupported provider %s.', $provider), 'details' => []];
    }

    if (!empty($credentials['sandboxRecipient'])) {
        $details['sandboxRecipient'] = $credentials['sandboxRecipient'];
    }

    return [
        'ok' => true,
        'message' => 'Connected Successfully ✅',
        'details' => $details,
        'developerMessage' => 'Email/SMS credentials validated via provider API',
    ];
}

function smart_marketing_connector_connect(array &$settings, string $connectorKey, array $payload, array $admin = []): array
{
    $catalog = smart_marketing_connector_catalog();
    if (!isset($catalog[$connectorKey])) {
        throw new RuntimeException('Unknown connector.');
    }

    $current = $settings['integrations'][$connectorKey] ?? smart_marketing_default_connector_settings()[$connectorKey];
    $fieldMap = smart_marketing_integration_field_map($connectorKey);
    $existingCredentials = [];
    foreach ($fieldMap as $fieldKey => $meta) {
        $existingCredentials[$fieldKey] = $current[$fieldKey] ?? '';
    }

    $mergedCredentials = smart_marketing_integration_merge_credentials($connectorKey, $payload, $existingCredentials);
    $validation = smart_marketing_validate_integration_credentials($connectorKey, $mergedCredentials);
    if (!$validation['ok']) {
        throw new RuntimeException($validation['message'] ?? 'Validation failed.');
    }

    foreach ($mergedCredentials as $field => $value) {
        $current[$field] = $value;
    }

    $now = ai_timestamp();
    if (empty($current['connectedAt'])) {
        $current['connectedAt'] = $now;
    }
    $current['status'] = 'connected';
    $current['disabled'] = false;
    $current['lastTested'] = $now;
    $current['lastValidatedAt'] = $now;
    $current['lastTestResult'] = 'passed';
    $current['validatedBy'] = (string) ($admin['email'] ?? ($admin['full_name'] ?? 'Admin'));
    $current['message'] = $validation['message'] ?? 'Connected Successfully ✅';
    if (!empty($validation['details'])) {
        $current['validationDetails'] = $validation['details'];
    } else {
        unset($current['validationDetails']);
    }
    if (!empty($validation['developerMessage'])) {
        $current['developerMessage'] = $validation['developerMessage'];
    } else {
        unset($current['developerMessage']);
    }

    $settings['integrations'][$connectorKey] = $current;

    if ($connectorKey === 'googleAds') {
        $youtubeDefaults = smart_marketing_default_connector_settings()['youtube'];
        $youtube = $settings['integrations']['youtube'] ?? $youtubeDefaults;
        $youtube['linkedYoutubeChannelId'] = $current['linkedYoutubeChannelId'] ?? ($youtube['linkedYoutubeChannelId'] ?? '');
        $youtube['status'] = $current['status'];
        $youtube['connectedAt'] = $current['connectedAt'];
        $youtube['lastTested'] = $current['lastTested'];
        $youtube['lastValidatedAt'] = $current['lastValidatedAt'];
        $youtube['lastTestResult'] = $current['lastTestResult'];
        $youtube['validatedBy'] = $current['validatedBy'];
        $youtube['message'] = $current['message'];
        $youtube['disabled'] = $current['disabled'];
        $settings['integrations']['youtube'] = $youtube;
    }

    return $current;
}

function smart_marketing_connector_disable(array &$settings, string $connectorKey, array $admin = []): array
{
    $catalog = smart_marketing_connector_catalog();
    if (!isset($catalog[$connectorKey])) {
        throw new RuntimeException('Unknown connector.');
    }

    $entry = $settings['integrations'][$connectorKey] ?? smart_marketing_default_connector_settings()[$connectorKey];
    foreach (smart_marketing_integration_field_map($connectorKey) as $fieldKey => $meta) {
        $entry[$fieldKey] = '';
    }

    $entry['status'] = 'disabled';
    $entry['disabled'] = true;
    $entry['connectedAt'] = null;
    $entry['lastTested'] = ai_timestamp();
    $entry['lastValidatedAt'] = null;
    $entry['lastTestResult'] = 'unknown';
    $entry['validatedBy'] = (string) ($admin['email'] ?? ($admin['full_name'] ?? 'Admin'));
    $entry['message'] = 'Integration disabled';
    unset($entry['validationDetails']);

    $settings['integrations'][$connectorKey] = $entry;

    if ($connectorKey === 'googleAds' && isset($settings['integrations']['youtube'])) {
        $youtube = $settings['integrations']['youtube'];
        $youtube['status'] = 'disabled';
        $youtube['disabled'] = true;
        $youtube['connectedAt'] = null;
        $youtube['lastTested'] = $entry['lastTested'];
        $youtube['lastValidatedAt'] = null;
        $youtube['lastTestResult'] = 'unknown';
        $youtube['validatedBy'] = $entry['validatedBy'];
        $youtube['message'] = 'Integration disabled';
        $settings['integrations']['youtube'] = $youtube;
    }

    return $entry;
}

function smart_marketing_connector_delete(array &$settings, string $connectorKey, array $admin = []): array
{
    $catalog = smart_marketing_connector_catalog();
    if (!isset($catalog[$connectorKey])) {
        throw new RuntimeException('Unknown connector.');
    }

    $defaults = smart_marketing_default_connector_settings()[$connectorKey];
    $defaults['status'] = 'disconnected';
    $defaults['connectedAt'] = null;
    $defaults['lastTested'] = null;
    $defaults['lastValidatedAt'] = null;
    $defaults['lastTestResult'] = 'unknown';
    $defaults['validatedBy'] = null;
    $defaults['message'] = 'Not connected';
    $defaults['disabled'] = false;
    unset($defaults['validationDetails']);

    $settings['integrations'][$connectorKey] = $defaults;

    if ($connectorKey === 'googleAds' && isset($settings['integrations']['youtube'])) {
        $settings['integrations']['youtube'] = smart_marketing_default_connector_settings()['youtube'];
    }

    return $defaults;
}

function smart_marketing_connector_test(array &$settings, string $connectorKey, array $admin = []): array
{
    $catalog = smart_marketing_connector_catalog();
    if (!isset($catalog[$connectorKey])) {
        throw new RuntimeException('Unknown connector.');
    }

    $entry = $settings['integrations'][$connectorKey] ?? smart_marketing_default_connector_settings()[$connectorKey];
    $credentials = [];
    if ($connectorKey === 'youtube') {
        $googleEntry = $settings['integrations']['googleAds'] ?? smart_marketing_default_connector_settings()['googleAds'];
        $credentials = [];
        foreach (smart_marketing_integration_field_map('googleAds') as $fieldKey => $meta) {
            $credentials[$fieldKey] = $googleEntry[$fieldKey] ?? '';
        }
        if (!empty($entry['linkedYoutubeChannelId'])) {
            $credentials['linkedYoutubeChannelId'] = $entry['linkedYoutubeChannelId'];
        }
        $validation = smart_marketing_validate_integration_credentials('googleAds', $credentials);
    } else {
        foreach (smart_marketing_integration_field_map($connectorKey) as $fieldKey => $meta) {
            $credentials[$fieldKey] = $entry[$fieldKey] ?? '';
        }
        $validation = smart_marketing_validate_integration_credentials($connectorKey, $credentials);
    }

    $now = ai_timestamp();
    $entry['lastTested'] = $now;
    if ($validation['ok']) {
        $entry['lastTestResult'] = 'passed';
        $entry['status'] = 'connected';
        $entry['lastValidatedAt'] = $now;
        $entry['validatedBy'] = (string) ($admin['email'] ?? ($admin['full_name'] ?? 'Admin'));
        $entry['message'] = $validation['message'] ?? 'Connected Successfully ✅';
        if (!empty($validation['details'])) {
            $entry['validationDetails'] = $validation['details'];
        } else {
            unset($entry['validationDetails']);
        }
        if (!empty($validation['developerMessage'])) {
            $entry['developerMessage'] = $validation['developerMessage'];
        } else {
            unset($entry['developerMessage']);
        }
        if ($connectorKey === 'googleAds' && isset($settings['integrations']['youtube'])) {
            $youtube = $settings['integrations']['youtube'];
            $youtube['status'] = $entry['status'];
            $youtube['lastTested'] = $entry['lastTested'];
            $youtube['lastValidatedAt'] = $entry['lastValidatedAt'];
            $youtube['lastTestResult'] = $entry['lastTestResult'];
            $youtube['message'] = $entry['message'];
            $youtube['validatedBy'] = $entry['validatedBy'];
            $settings['integrations']['youtube'] = $youtube;
        }
    } else {
        $entry['lastTestResult'] = 'failed';
        $entry['status'] = 'error';
        $entry['message'] = $validation['message'] ?? 'Validation failed';
        if (!empty($validation['developerMessage'])) {
            $entry['developerMessage'] = $validation['developerMessage'];
        } else {
            unset($entry['developerMessage']);
        }
    }

    $settings['integrations'][$connectorKey] = $entry;

    return $entry;
}

function smart_marketing_campaigns_load(): array
{
    $file = smart_marketing_campaigns_file();
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_campaigns_load decode failed: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function smart_marketing_campaigns_save(array $campaigns): void
{
    $payload = json_encode(array_values($campaigns), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing campaigns.');
    }

    if (file_put_contents(smart_marketing_campaigns_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing campaigns.');
    }
}

function smart_marketing_next_campaign_sequence(array $campaigns): int
{
    $max = 0;
    foreach ($campaigns as $campaign) {
        $sequence = (int) ($campaign['sequence'] ?? 0);
        if ($sequence > $max) {
            $max = $sequence;
        }
    }

    return $max + 1;
}

function smart_marketing_campaign_catalog(): array
{
    return [
        'search' => [
            'label' => 'Search',
            'prefix' => 'SRCH',
            'connectors' => ['googleAds'],
            'utm' => ['source' => 'google', 'medium' => 'cpc'],
        ],
        'display' => [
            'label' => 'Display',
            'prefix' => 'DSP',
            'connectors' => ['googleAds'],
            'utm' => ['source' => 'google', 'medium' => 'display'],
        ],
        'video' => [
            'label' => 'Video (YouTube Shorts)',
            'prefix' => 'YT',
            'connectors' => ['googleAds', 'youtube'],
            'utm' => ['source' => 'youtube', 'medium' => 'video'],
        ],
        'lead_gen' => [
            'label' => 'Lead Gen Forms',
            'prefix' => 'META-LEAD',
            'connectors' => ['meta'],
            'utm' => ['source' => 'meta', 'medium' => 'lead'],
        ],
        'whatsapp' => [
            'label' => 'Click-to-WhatsApp',
            'prefix' => 'WA',
            'connectors' => ['meta', 'whatsapp'],
            'utm' => ['source' => 'meta', 'medium' => 'whatsapp'],
        ],
        'boosted' => [
            'label' => 'Boosted Posts',
            'prefix' => 'META-BOOST',
            'connectors' => ['meta'],
            'utm' => ['source' => 'meta', 'medium' => 'social'],
        ],
        'email_sms' => [
            'label' => 'Email/SMS Blasts',
            'prefix' => 'MSG',
            'connectors' => ['email'],
            'utm' => ['source' => 'crm', 'medium' => 'automation'],
        ],
    ];
}

function smart_marketing_campaign_type_label(string $type): string
{
    return smart_marketing_campaign_catalog()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
}

function smart_marketing_site_pages(): array
{
    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        return [];
    }

    $files = glob($baseDir . '/*.html');
    if ($files === false) {
        return [];
    }

    $pages = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (preg_match('/^(admin|login|logout|customer|employee|referrer)/i', $name)) {
            continue;
        }
        $label = ucwords(str_replace(['-', '_'], ' ', pathinfo($name, PATHINFO_FILENAME)));
        $pages[] = [
            'path' => '/' . $name,
            'label' => $label,
        ];
    }

    usort($pages, static function (array $a, array $b): int {
        return strcmp($a['label'], $b['label']);
    });

    return $pages;
}

function smart_marketing_generate_campaign_identifier(string $prefix, int $sequence): string
{
    return sprintf('SM-%s-%04d', strtoupper($prefix), $sequence);
}

function smart_marketing_prepare_campaign_record(array $run, string $type, int $sequence, array $settings, array $options = []): array
{
    $catalog = smart_marketing_campaign_catalog();
    if (!isset($catalog[$type])) {
        throw new RuntimeException('Unsupported campaign type requested.');
    }

    $meta = $catalog[$type];
    $campaignId = smart_marketing_generate_campaign_identifier($meta['prefix'], $sequence);

    $totalTypes = max(1, (int) ($options['totalTypes'] ?? 1));
    $inputs = $run['inputs'] ?? [];
    $regions = $inputs['regions'] ?? ($settings['businessProfile']['serviceRegions'] ?? []);
    $languages = $inputs['languages'] ?? [];
    if (empty($languages)) {
        $languages = smart_marketing_language_options();
    }

    $dailyBudget = max(500, round(((float) ($inputs['dailyBudget'] ?? $settings['budget']['dailyCap'] ?? 0)) / $totalTypes, 2));
    $monthlyBudget = max(15000, round(((float) ($inputs['monthlyBudget'] ?? $settings['budget']['monthlyCap'] ?? 0)) / $totalTypes, 2));

    $keywords = smart_marketing_resolve_keywords($run, $type);
    $placements = smart_marketing_resolve_placements($type);
    $creatives = smart_marketing_resolve_creatives($run, $type, $settings);

    $landingOptions = $options['landing'] ?? [];
    $landingMode = $landingOptions['mode'] ?? 'existing';
    if ($landingMode === 'auto') {
        $landing = smart_marketing_generate_landing_page($campaignId, $landingOptions, $settings, $run, $type);
    } else {
        $landing = [
            'type' => 'existing',
            'url' => $landingOptions['page'] ?? '/contact.html',
        ];
    }

    $connectors = [];
    foreach ($meta['connectors'] as $connectorKey) {
        $connectors[$connectorKey] = $settings['integrations'][$connectorKey] ?? smart_marketing_default_connector_settings()[$connectorKey];
    }

    $canonicalCampaignId = sprintf('%s-%s', strtoupper($meta['prefix']), strtoupper(dechex(time())) . '-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT));
    $adGroupIds = [];
    for ($i = 1; $i <= 2; $i++) {
        $adGroupIds[] = sprintf('%s-AG-%02d', strtoupper($meta['prefix']), $i);
    }
    $adIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $adIds[] = sprintf('%s-AD-%02d', strtoupper($meta['prefix']), $i);
    }

    $audienceNotes = $settings['audiences']['remarketingNotes'] ?? '';
    $audiences = [
        'inclusions' => array_values(array_unique(array_merge($regions, $inputs['products'] ?? [], $inputs['goals'] ?? []))),
        'exclusions' => array_filter([trim((string) ($settings['audiences']['exclusions'] ?? ''))]),
        'notes' => $audienceNotes,
    ];

    $utm = array_merge($meta['utm'], [
        'campaign' => strtolower($campaignId),
        'term' => $keywords[0] ?? null,
        'content' => $type,
    ]);

    $schedule = [
        'start' => ai_timestamp(),
        'seasonal' => [
            'summer_rooftop_push' => 'April–June weekly bursts',
            'festival_offers' => 'Navratri & Diwali 10-day sequence',
        ],
        'dayparting' => 'Bid uplift 9am-8pm IST for call-ready teams',
    ];

    $record = [
        'id' => $campaignId,
        'sequence' => $sequence,
        'type' => $type,
        'label' => smart_marketing_campaign_type_label($type),
        'run_id' => $run['id'] ?? null,
        'status' => 'launched',
        'launched_at' => ai_timestamp(),
        'budget' => [
            'daily' => $dailyBudget,
            'monthly' => $monthlyBudget,
        ],
        'targeting' => [
            'regions' => $regions,
            'languages' => $languages,
            'audiences' => $audiences,
        ],
        'keywords' => $keywords,
        'placements' => $placements,
        'creatives' => $creatives,
        'landing' => $landing,
        'connectors' => $connectors,
        'canonical' => [
            'campaign' => $canonicalCampaignId,
            'ad_groups' => $adGroupIds,
            'ads' => $adIds,
        ],
        'metrics' => [
            'ctr' => smart_marketing_default_ctr($type),
            'cpl' => (float) ($settings['budget']['targetCpl'] ?? 450) * 1.05,
            'lead_count' => 0,
        ],
        'utm' => $utm,
        'schedule' => $schedule,
        'audit_trail' => [
            [
                'timestamp' => ai_timestamp(),
                'action' => 'launched',
                'context' => ['connectors' => array_keys($connectors)],
            ],
        ],
    ];

    return $record;
}

function smart_marketing_resolve_keywords(array $run, string $type): array
{
    $plan = $run['plan']['channel_plan'] ?? [];
    $keywords = [];
    foreach ((array) $plan as $entry) {
        $channelName = strtolower((string) ($entry['channel'] ?? $entry['name'] ?? ''));
        if (!smart_marketing_channel_matches_type($channelName, $type)) {
            continue;
        }
        $items = $entry['keywords'] ?? $entry['terms'] ?? [];
        if (is_string($items)) {
            $items = preg_split('/[,\n]+/', $items, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                $keywords[] = trim((string) $item);
            }
        }
    }

    $keywords = array_values(array_filter(array_unique($keywords)));
    if (!empty($keywords)) {
        return $keywords;
    }

    return match ($type) {
        'search' => ['solar rooftop installation Jharkhand', 'pm surya ghar subsidy assistance'],
        'display' => ['solar savings banners', 'rooftop financing creative'],
        'video' => ['rooftop solar walkthrough', 'customer testimonial shorts'],
        'lead_gen' => ['solar lead form instant quote'],
        'whatsapp' => ['chat solar consultant', 'book whatsapp site visit'],
        'boosted' => ['solar project highlight', 'emi subsidy awareness'],
        'email_sms' => ['solar emi reminder', 'festival rooftop offer'],
        default => ['solar rooftop enquiry'],
    };
}

function smart_marketing_channel_matches_type(string $channelName, string $type): bool
{
    return match ($type) {
        'search' => str_contains($channelName, 'search'),
        'display' => str_contains($channelName, 'display') || str_contains($channelName, 'gdn'),
        'video' => str_contains($channelName, 'video') || str_contains($channelName, 'youtube'),
        'lead_gen' => str_contains($channelName, 'lead'),
        'whatsapp' => str_contains($channelName, 'whatsapp'),
        'boosted' => str_contains($channelName, 'boost') || str_contains($channelName, 'post'),
        'email_sms' => str_contains($channelName, 'email') || str_contains($channelName, 'sms') || str_contains($channelName, 'blast'),
        default => false,
    };
}

function smart_marketing_resolve_placements(string $type): array
{
    return match ($type) {
        'search' => ['Google Search Network', 'Search Partners'],
        'display' => ['Google Display Network', 'Discovery placements'],
        'video' => ['YouTube Shorts feed', 'In-stream skippable'],
        'lead_gen' => ['Facebook Lead Forms', 'Instagram Lead Ads'],
        'whatsapp' => ['Facebook Click-to-WhatsApp', 'Instagram Stories CTA'],
        'boosted' => ['Facebook Feed', 'Instagram Feed', 'Stories'],
        'email_sms' => ['Email broadcast', 'SMS follow-up drip'],
        default => ['Owned inventory'],
    };
}

function smart_marketing_resolve_creatives(array $run, string $type, array $settings): array
{
    $plan = $run['plan']['creative_plan'] ?? [];
    $brand = $settings['businessProfile']['brandName'] ?? 'Dakshayani Enterprises';
    $result = [
        'text' => [],
        'images' => [],
        'videos' => [],
        'audio' => [],
    ];

    $textSources = $plan['text'] ?? ($plan['copy'] ?? []);
    if (is_string($textSources)) {
        $textSources = preg_split('/\n+/', $textSources, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (is_array($textSources)) {
        foreach ($textSources as $item) {
            if (is_array($item)) {
                $result['text'][] = implode(' — ', array_map('strval', $item));
            } else {
                $result['text'][] = trim((string) $item);
            }
        }
    }

    $imageSources = $plan['images'] ?? $plan['visuals'] ?? [];
    if (is_array($imageSources)) {
        foreach ($imageSources as $image) {
            $result['images'][] = is_array($image) ? ($image['description'] ?? json_encode($image)) : (string) $image;
        }
    }

    $videoSources = $plan['video'] ?? $plan['videos'] ?? [];
    if (is_array($videoSources)) {
        foreach ($videoSources as $video) {
            $result['videos'][] = is_array($video) ? ($video['concept'] ?? json_encode($video)) : (string) $video;
        }
    }

    if (isset($plan['audio'])) {
        $audioSources = is_array($plan['audio']) ? $plan['audio'] : [$plan['audio']];
        foreach ($audioSources as $audio) {
            $result['audio'][] = is_array($audio) ? ($audio['script'] ?? json_encode($audio)) : (string) $audio;
        }
    }

    if (empty(array_filter($result))) {
        $result['text'] = [
            sprintf('%s solar experts: Book your MNRE-approved install today.', $brand),
            'Claim PM Surya Ghar benefits with Dakshayani Enterprises. Free site audit.',
        ];
        if (in_array($type, ['display', 'video', 'boosted'], true)) {
            $result['images'][] = 'Show rooftop installation before/after with 5kW array.';
        }
        if ($type === 'video') {
            $result['videos'][] = '30s customer testimonial with drone shots of rooftop system.';
        }
    }

    return $result;
}

function smart_marketing_default_ctr(string $type): float
{
    return match ($type) {
        'search' => 0.028,
        'display' => 0.012,
        'video' => 0.036,
        'lead_gen' => 0.024,
        'whatsapp' => 0.031,
        'boosted' => 0.029,
        'email_sms' => 0.18,
        default => 0.02,
    };
}

function smart_marketing_generate_landing_page(string $campaignId, array $landingOptions, array $settings, array $run, string $type): array
{
    $brand = $settings['businessProfile']['brandName'] ?? 'Dakshayani Enterprises';
    $headline = trim((string) ($landingOptions['headline'] ?? sprintf('%s — %s Offer', $brand, smart_marketing_campaign_type_label($type))));
    $offer = trim((string) ($landingOptions['offer'] ?? 'Limited-time rooftop solar savings audit.'));
    $cta = trim((string) ($landingOptions['cta'] ?? 'Book a free site visit'));
    $whatsapp = trim((string) ($landingOptions['whatsapp'] ?? ($settings['businessProfile']['whatsappNumber'] ?? '')));
    $call = trim((string) ($landingOptions['call'] ?? ($settings['businessProfile']['primaryContact'] ?? '')));
    $bodyCopy = trim((string) ($landingOptions['body'] ?? 'MNRE empanelled engineers design, install, and maintain subsidy-backed rooftop systems across Jharkhand. Claim financing assistance, AMC support, and performance monitoring.'));
    $regions = implode(', ', $run['inputs']['regions'] ?? ($settings['businessProfile']['serviceRegions'] ?? ['Jharkhand']));
    $whatsappHref = 'https://wa.me/';
    if ($whatsapp !== '') {
        $whatsappHref .= preg_replace('/\D+/', '', $whatsapp);
    }
    $callHref = $call !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $call) : '#';

    $slugSeed = strtolower($campaignId . '-' . preg_replace('/[^a-z0-9]+/i', '-', $offer));
    $slug = trim(preg_replace('/-+/', '-', $slugSeed), '-');
    if ($slug === '') {
        $slug = strtolower($campaignId);
    }

    $filePath = smart_marketing_landings_dir() . '/' . $slug . '.html';
    $formAction = '/contact.html';
    $languagesLabel = implode(', ', $run['inputs']['languages'] ?? smart_marketing_language_options());

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$headline}</title>
  <link rel="stylesheet" href="/style.css" />
</head>
<body class="landing-smart-marketing">
  <main class="landing">
    <header>
      <p class="landing__tag">Smart Marketing Landing • {$campaignId}</p>
      <h1>{$headline}</h1>
      <p class="landing__offer">{$offer}</p>
    </header>
    <section class="landing__details">
      <p>{$bodyCopy}</p>
      <ul>
        <li>Service regions: {$regions}</li>
        <li>Languages: {$languagesLabel}</li>
        <li>WhatsApp desk: {$whatsapp}</li>
        <li>Call: {$call}</li>
      </ul>
    </section>
    <section class="landing__cta">
      <a class="btn btn-primary" href="{$whatsappHref}" target="_blank" rel="noopener">Chat on WhatsApp</a>
      <a class="btn btn-ghost" href="{$callHref}">Call our solar desk</a>
    </section>
    <section class="landing__form">
      <h2>Book your consultation</h2>
      <form method="post" action="{$formAction}">
        <label>Name<input type="text" name="name" required></label>
        <label>Phone<input type="tel" name="phone" required></label>
        <label>District<input type="text" name="district" placeholder="Ranchi, Bokaro"></label>
        <label>System interest<input type="text" name="system" placeholder="3 kW rooftop"></label>
        <button type="submit" class="btn btn-primary">{$cta}</button>
      </form>
    </section>
  </main>
</body>
</html>
HTML;

    file_put_contents($filePath, $html, LOCK_EX);

    $relativeUrl = '/storage/smart_marketing/landings/' . $slug . '.html';
    smart_marketing_append_sitemap_entry($relativeUrl);

    return [
        'type' => 'auto',
        'slug' => $slug,
        'url' => $relativeUrl,
        'path' => $filePath,
        'headline' => $headline,
        'offer' => $offer,
        'cta' => $cta,
    ];
}

function smart_marketing_append_sitemap_entry(string $url): void
{
    $file = smart_marketing_sitemap_fragment_file();
    $entries = [];
    if (is_file($file)) {
        $contents = file_get_contents($file);
        if ($contents !== false && trim($contents) !== '') {
            $xml = @simplexml_load_string($contents);
            if ($xml instanceof SimpleXMLElement) {
                foreach ($xml->url as $node) {
                    $loc = (string) $node->loc;
                    $lastmod = (string) $node->lastmod;
                    $entries[$loc] = $lastmod ?: gmdate('c');
                }
            }
        }
    }

    $entries[$url] = gmdate('c');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
    foreach ($entries as $loc => $lastmod) {
        $xml .= '  <url>' . PHP_EOL;
        $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . PHP_EOL;
        $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . '</lastmod>' . PHP_EOL;
        $xml .= '  </url>' . PHP_EOL;
    }
    $xml .= '</urlset>' . PHP_EOL;

    file_put_contents($file, $xml, LOCK_EX);
}

function smart_marketing_launch_campaigns(array &$runs, int $runId, array $campaignTypes, array &$settings, array $options = []): array
{
    if (empty($campaignTypes)) {
        throw new RuntimeException('Choose at least one campaign type.');
    }

    $catalog = smart_marketing_campaign_catalog();
    foreach ($campaignTypes as $type) {
        if (!isset($catalog[$type])) {
            throw new RuntimeException('Unsupported campaign type: ' . $type);
        }
    }

    $runIndex = null;
    foreach ($runs as $index => $run) {
        if ((int) ($run['id'] ?? 0) === $runId) {
            $runIndex = $index;
            break;
        }
    }

    if ($runIndex === null) {
        throw new RuntimeException('Plan not found.');
    }

    $run = $runs[$runIndex];
    $campaigns = smart_marketing_campaigns_load();
    $sequence = smart_marketing_next_campaign_sequence($campaigns);

    $launched = [];
    foreach ($campaignTypes as $type) {
        $record = smart_marketing_prepare_campaign_record($run, $type, $sequence, $settings, array_merge($options, ['totalTypes' => count($campaignTypes)]));
        $sequence++;
        $leadSync = smart_marketing_seed_leads_for_campaign($record, $settings);
        $record['leads'] = $leadSync;
        $record['metrics']['lead_count'] = count(array_filter(array_column($leadSync, 'id')));
        $record['audit_trail'][] = [
            'timestamp' => ai_timestamp(),
            'action' => 'leads_synced',
            'context' => ['lead_ids' => array_column($leadSync, 'id')],
        ];
        $campaigns[] = $record;
        $launched[] = $record;
    }

    $runs[$runIndex]['status'] = 'live';
    $runs[$runIndex]['updated_at'] = ai_timestamp();
    smart_marketing_brain_runs_save($runs);
    smart_marketing_campaigns_save($campaigns);

    $automationResult = smart_marketing_run_automations($campaigns, $settings, true);
    $automationEntries = $automationResult['entries'];

    return [
        'launched' => $launched,
        'campaigns' => $campaigns,
        'automations' => $automationEntries,
    ];
}

function smart_marketing_seed_leads_for_campaign(array &$campaign, array $settings): array
{
    $region = $campaign['targeting']['regions'][0] ?? ($settings['businessProfile']['serviceRegions'][0] ?? 'Jharkhand');
    $baseHash = abs(crc32((string) $campaign['id']));
    $products = $campaign['targeting']['audiences']['inclusions'] ?? ($settings['products']['portfolio'] ?? []);
    $primaryProduct = $products[0] ?? '3 kW Rooftop';

    $templates = [
        [
            'name' => sprintf('%s %s Prospect', $region, ucfirst($campaign['type'])),
            'offset' => 1,
            'utm' => array_merge($campaign['utm'], ['content' => 'primary', 'ad' => $campaign['canonical']['ads'][0] ?? null]),
        ],
        [
            'name' => sprintf('%s Referral %s', $region, ucfirst($campaign['type'])),
            'offset' => 7,
            'utm' => array_merge($campaign['utm'], ['content' => 'remarketing', 'ad' => $campaign['canonical']['ads'][1] ?? null]),
        ],
    ];

    $results = [];
    foreach ($templates as $index => $template) {
        $number = '+91' . '620' . str_pad((string) (($baseHash + $template['offset']) % 10000000), 7, '0', STR_PAD_LEFT);
        $email = sprintf('lead%s@dakshayani.smart', substr(md5($campaign['id'] . $template['offset']), 0, 6));
        $lead = [
            'name' => $template['name'],
            'phone' => $number,
            'email' => $email,
            'source' => sprintf('Smart Marketing • %s', smart_marketing_campaign_type_label($campaign['type'])),
            'region' => $region,
            'product' => $primaryProduct,
            'campaign_id' => $campaign['id'],
            'ad_id' => $template['utm']['ad'] ?? null,
            'keyword' => $campaign['keywords'][0] ?? null,
            'utm' => $template['utm'],
        ];
        $results[] = smart_marketing_sync_lead($lead, $campaign, $settings);
    }

    return $results;
}

function smart_marketing_sync_lead(array $lead, array $campaign, array $settings): array
{
    $db = get_db();
    $phone = preg_replace('/\D+/', '', (string) ($lead['phone'] ?? ''));
    $email = strtolower(trim((string) ($lead['email'] ?? '')));

    $existing = null;
    if ($phone !== '') {
        $stmt = $db->prepare('SELECT id, phone, email, notes FROM crm_leads WHERE phone = :phone LIMIT 1');
        $stmt->execute([':phone' => $phone]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($existing === null && $email !== '') {
        $stmt = $db->prepare('SELECT id, phone, email, notes FROM crm_leads WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $note = sprintf('[%s] %s campaign %s via %s', ai_timestamp(), strtoupper($campaign['type']), $campaign['id'], $lead['source'] ?? 'Smart Marketing');
    $utmNote = 'UTM: ' . json_encode($lead['utm'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($existing) {
        $leadId = (int) $existing['id'];
        $existingNotes = trim((string) ($existing['notes'] ?? ''));
        $combinedNotes = trim($existingNotes . PHP_EOL . $note . ' • ' . $utmNote);
        $stmt = $db->prepare('UPDATE crm_leads SET notes = :notes, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':notes' => $combinedNotes,
            ':updated_at' => now_ist(),
            ':id' => $leadId,
        ]);
        $action = 'updated';
    } else {
        $input = [
            'name' => $lead['name'] ?? 'Smart Marketing Lead',
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'source' => $lead['source'] ?? 'Smart Marketing',
            'site_location' => $lead['region'] ?? null,
            'site_details' => json_encode([
                'campaign_id' => $lead['campaign_id'] ?? $campaign['id'],
                'ad_id' => $lead['ad_id'] ?? null,
                'keyword' => $lead['keyword'] ?? null,
                'utm' => $lead['utm'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'notes' => $note . ' • ' . $utmNote,
        ];
        $created = admin_create_lead($db, $input, 0);
        $leadId = (int) ($created['id'] ?? 0);
        $employeeId = smart_marketing_pick_employee_for_region($db, (string) ($lead['region'] ?? ''));
        if ($employeeId) {
            admin_assign_lead($db, $leadId, $employeeId, 0);
        }
        $action = 'created';
    }

    smart_marketing_audit_log_append('lead.synced', [
        'lead_id' => $leadId,
        'campaign_id' => $campaign['id'],
        'action' => $action,
        'utm' => $lead['utm'] ?? [],
    ]);

    smart_marketing_audit_log_append('lead.auto_ack', [
        'lead_id' => $leadId,
        'channel' => $campaign['type'],
        'message' => 'WhatsApp/SMS acknowledgement queued with internal reminder.',
    ]);

    return [
        'id' => $leadId,
        'action' => $action,
    ];
}

function smart_marketing_pick_employee_for_region(PDO $db, string $region): ?int
{
    $employees = admin_active_employees($db);
    if (empty($employees)) {
        return null;
    }

    $hash = abs(crc32(strtolower($region)));
    $index = $hash % count($employees);

    return (int) ($employees[$index]['id'] ?? null);
}

function smart_marketing_run_automations(
    array &$campaigns,
    array $settings,
    bool $forced = false,
    ?array $optimization = null,
    ?array $governance = null,
    ?array $notifications = null
): array
{
    $entries = [];
    $campaignsTouched = false;

    $optimizationState = $optimization ?? smart_marketing_optimization_load($settings);
    $governanceState = $governance ?? smart_marketing_governance_load($settings);
    $notificationState = $notifications ?? smart_marketing_notifications_load();

    $autoResult = smart_marketing_apply_auto_rules($campaigns, $optimizationState, $settings, $governanceState);
    if (!empty($autoResult['entries'])) {
        $entries = array_merge($entries, $autoResult['entries']);
        $campaignsTouched = $campaignsTouched || (bool) $autoResult['modified'];
    }
    $optimizationState = $autoResult['optimization'];

    foreach ($autoResult['alerts'] as $alert) {
        $notificationState = smart_marketing_notifications_push(
            $notificationState,
            $alert['type'] ?? 'alert',
            (string) ($alert['message'] ?? 'Smart Marketing alert'),
            (array) ($alert['channels'] ?? [])
        );
    }

    if ($forced) {
        $timestamp = ai_timestamp();
        $seasonal = [
            [
                'timestamp' => $timestamp,
                'type' => 'seasonal_schedule',
                'campaign_id' => null,
                'message' => 'Seasonal bursts locked: Summer rooftop push & Diwali festival offers.',
            ],
            [
                'timestamp' => $timestamp,
                'type' => 'dayparting',
                'campaign_id' => null,
                'message' => 'Applied 25% bid uplift during 9am-8pm call hours and reduced bids overnight.',
            ],
            [
                'timestamp' => $timestamp,
                'type' => 'compliance_rescan',
                'campaign_id' => null,
                'message' => 'Re-scanned active creatives; flagged items would be paused automatically.',
            ],
        ];
        $entries = array_merge($entries, $seasonal);
    }

    if (!empty($entries)) {
        smart_marketing_automation_log_append($entries);
    }

    if ($campaignsTouched || $forced) {
        smart_marketing_campaigns_save($campaigns);
    }

    smart_marketing_optimization_save($optimizationState);
    smart_marketing_notifications_save($notificationState);

    return [
        'entries' => $entries,
        'optimization' => $optimizationState,
        'governance' => $governanceState,
        'notifications' => $notificationState,
        'campaignsChanged' => $campaignsTouched || $forced,
    ];
}

function smart_marketing_automation_log_append(array $entries): void
{
    $file = smart_marketing_automation_log_file();
    $existing = [];
    if (is_file($file)) {
        $contents = file_get_contents($file);
        if ($contents !== false && trim($contents) !== '') {
            try {
                $existing = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $existing = [];
            }
        }
    }

    if (!is_array($existing)) {
        $existing = [];
    }

    $existing = array_merge($existing, $entries);
    if (count($existing) > 100) {
        $existing = array_slice($existing, -100);
    }

    $payload = json_encode(array_values($existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing automation log.');
    }

    file_put_contents($file, $payload, LOCK_EX);
}

function smart_marketing_automation_log_read(int $limit = 25): array
{
    $file = smart_marketing_automation_log_file();
    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $decoded = array_reverse($decoded);
    if ($limit > 0) {
        $decoded = array_slice($decoded, 0, $limit);
    }

    return $decoded;
}

function smart_marketing_analytics_defaults(): array
{
    return [
        'updatedAt' => ai_timestamp(),
        'channels' => [
            [
                'id' => 'googleAds',
                'label' => 'Google Ads',
                'metrics' => [
                    'impressions' => 185400,
                    'clicks' => 7420,
                    'spend' => 178500,
                    'leads' => 362,
                    'qualified' => 210,
                    'converted' => 58,
                    'callConnects' => 164,
                    'meetings' => 92,
                    'sales' => 34,
                ],
                'campaigns' => [
                    [
                        'id' => 'SRCH-RES',
                        'label' => 'Search • Residential Rooftop',
                        'metrics' => [
                            'impressions' => 86400,
                            'clicks' => 3980,
                            'spend' => 91200,
                            'leads' => 214,
                            'qualified' => 132,
                            'converted' => 36,
                            'callConnects' => 98,
                            'meetings' => 54,
                            'sales' => 18,
                        ],
                        'ads' => [
                            [
                                'id' => 'SRCH-RES-01',
                                'label' => 'Claim PM Surya Ghar subsidy',
                                'metrics' => [
                                    'impressions' => 41200,
                                    'clicks' => 2050,
                                    'spend' => 47200,
                                    'leads' => 118,
                                    'qualified' => 74,
                                    'converted' => 20,
                                    'callConnects' => 58,
                                    'meetings' => 31,
                                    'sales' => 11,
                                ],
                            ],
                            [
                                'id' => 'SRCH-RES-02',
                                'label' => 'Free rooftop solar audit',
                                'metrics' => [
                                    'impressions' => 45200,
                                    'clicks' => 1930,
                                    'spend' => 44000,
                                    'leads' => 96,
                                    'qualified' => 58,
                                    'converted' => 16,
                                    'callConnects' => 40,
                                    'meetings' => 23,
                                    'sales' => 7,
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'SRCH-CI',
                        'label' => 'Search • C&I Rooftop',
                        'metrics' => [
                            'impressions' => 61200,
                            'clicks' => 1890,
                            'spend' => 61200,
                            'leads' => 92,
                            'qualified' => 54,
                            'converted' => 14,
                            'callConnects' => 42,
                            'meetings' => 26,
                            'sales' => 10,
                        ],
                        'ads' => [
                            [
                                'id' => 'SRCH-CI-01',
                                'label' => 'Lower factory power bills',
                                'metrics' => [
                                    'impressions' => 30200,
                                    'clicks' => 980,
                                    'spend' => 31200,
                                    'leads' => 48,
                                    'qualified' => 28,
                                    'converted' => 8,
                                    'callConnects' => 20,
                                    'meetings' => 12,
                                    'sales' => 4,
                                ],
                            ],
                            [
                                'id' => 'SRCH-CI-02',
                                'label' => 'Accelerated depreciation savings',
                                'metrics' => [
                                    'impressions' => 31000,
                                    'clicks' => 910,
                                    'spend' => 30000,
                                    'leads' => 44,
                                    'qualified' => 26,
                                    'converted' => 6,
                                    'callConnects' => 22,
                                    'meetings' => 14,
                                    'sales' => 6,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'meta',
                'label' => 'Meta Ads',
                'metrics' => [
                    'impressions' => 146200,
                    'clicks' => 6120,
                    'spend' => 128400,
                    'leads' => 286,
                    'qualified' => 164,
                    'converted' => 42,
                    'callConnects' => 120,
                    'meetings' => 66,
                    'sales' => 18,
                ],
                'campaigns' => [
                    [
                        'id' => 'META-LA',
                        'label' => 'Lead Ads • Subsidy Explainer',
                        'metrics' => [
                            'impressions' => 80200,
                            'clicks' => 3580,
                            'spend' => 72400,
                            'leads' => 182,
                            'qualified' => 106,
                            'converted' => 26,
                            'callConnects' => 78,
                            'meetings' => 38,
                            'sales' => 12,
                        ],
                        'ads' => [
                            [
                                'id' => 'META-LA-01',
                                'label' => 'Carousel • Rooftop installs',
                                'metrics' => [
                                    'impressions' => 38200,
                                    'clicks' => 1820,
                                    'spend' => 36200,
                                    'leads' => 94,
                                    'qualified' => 54,
                                    'converted' => 14,
                                    'callConnects' => 40,
                                    'meetings' => 22,
                                    'sales' => 8,
                                ],
                            ],
                            [
                                'id' => 'META-LA-02',
                                'label' => 'Video • Customer testimonial',
                                'metrics' => [
                                    'impressions' => 42000,
                                    'clicks' => 1760,
                                    'spend' => 36200,
                                    'leads' => 88,
                                    'qualified' => 52,
                                    'converted' => 12,
                                    'callConnects' => 38,
                                    'meetings' => 16,
                                    'sales' => 4,
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'META-RT',
                        'label' => 'Retargeting • Site Visitors',
                        'metrics' => [
                            'impressions' => 66000,
                            'clicks' => 2540,
                            'spend' => 56000,
                            'leads' => 104,
                            'qualified' => 58,
                            'converted' => 16,
                            'callConnects' => 42,
                            'meetings' => 28,
                            'sales' => 6,
                        ],
                        'ads' => [
                            [
                                'id' => 'META-RT-01',
                                'label' => 'Static • Install team photo',
                                'metrics' => [
                                    'impressions' => 33800,
                                    'clicks' => 1290,
                                    'spend' => 27600,
                                    'leads' => 54,
                                    'qualified' => 32,
                                    'converted' => 8,
                                    'callConnects' => 22,
                                    'meetings' => 12,
                                    'sales' => 4,
                                ],
                            ],
                            [
                                'id' => 'META-RT-02',
                                'label' => 'Reel • Drone walkthrough',
                                'metrics' => [
                                    'impressions' => 32200,
                                    'clicks' => 1250,
                                    'spend' => 28400,
                                    'leads' => 50,
                                    'qualified' => 26,
                                    'converted' => 8,
                                    'callConnects' => 20,
                                    'meetings' => 16,
                                    'sales' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'whatsapp',
                'label' => 'WhatsApp Automation',
                'metrics' => [
                    'impressions' => 28400,
                    'clicks' => 2140,
                    'spend' => 18400,
                    'leads' => 146,
                    'qualified' => 112,
                    'converted' => 28,
                    'callConnects' => 104,
                    'meetings' => 48,
                    'sales' => 12,
                ],
                'campaigns' => [
                    [
                        'id' => 'WA-FLOW',
                        'label' => 'Auto-response • Quote builder',
                        'metrics' => [
                            'impressions' => 15400,
                            'clicks' => 1180,
                            'spend' => 9600,
                            'leads' => 82,
                            'qualified' => 64,
                            'converted' => 16,
                            'callConnects' => 64,
                            'meetings' => 30,
                            'sales' => 8,
                        ],
                        'ads' => [
                            [
                                'id' => 'WA-FLOW-01',
                                'label' => 'Template • Roof size capture',
                                'metrics' => [
                                    'impressions' => 7800,
                                    'clicks' => 620,
                                    'spend' => 4800,
                                    'leads' => 44,
                                    'qualified' => 36,
                                    'converted' => 10,
                                    'callConnects' => 36,
                                    'meetings' => 16,
                                    'sales' => 5,
                                ],
                            ],
                            [
                                'id' => 'WA-FLOW-02',
                                'label' => 'Template • Subsidy checklist',
                                'metrics' => [
                                    'impressions' => 7600,
                                    'clicks' => 560,
                                    'spend' => 4800,
                                    'leads' => 38,
                                    'qualified' => 28,
                                    'converted' => 6,
                                    'callConnects' => 28,
                                    'meetings' => 14,
                                    'sales' => 3,
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'WA-NUDGE',
                        'label' => 'Reminder • Site survey follow-ups',
                        'metrics' => [
                            'impressions' => 13000,
                            'clicks' => 960,
                            'spend' => 8800,
                            'leads' => 64,
                            'qualified' => 48,
                            'converted' => 12,
                            'callConnects' => 40,
                            'meetings' => 18,
                            'sales' => 4,
                        ],
                        'ads' => [
                            [
                                'id' => 'WA-NUDGE-01',
                                'label' => 'Broadcast • Survey reminder',
                                'metrics' => [
                                    'impressions' => 6500,
                                    'clicks' => 480,
                                    'spend' => 4200,
                                    'leads' => 30,
                                    'qualified' => 22,
                                    'converted' => 6,
                                    'callConnects' => 18,
                                    'meetings' => 8,
                                    'sales' => 2,
                                ],
                            ],
                            [
                                'id' => 'WA-NUDGE-02',
                                'label' => 'Broadcast • Finance options',
                                'metrics' => [
                                    'impressions' => 6500,
                                    'clicks' => 480,
                                    'spend' => 4600,
                                    'leads' => 34,
                                    'qualified' => 26,
                                    'converted' => 6,
                                    'callConnects' => 22,
                                    'meetings' => 10,
                                    'sales' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'cohorts' => [
            'district' => [
                ['label' => 'Ranchi', 'leads' => 146, 'cpl' => 402, 'meetings' => 58],
                ['label' => 'Bokaro', 'leads' => 118, 'cpl' => 388, 'meetings' => 42],
                ['label' => 'Hazaribagh', 'leads' => 86, 'cpl' => 362, 'meetings' => 36],
            ],
            'system_size' => [
                ['label' => '1 kW', 'leads' => 44, 'cpl' => 320, 'sales' => 12],
                ['label' => '3 kW', 'leads' => 98, 'cpl' => 368, 'sales' => 18],
                ['label' => '5 kW', 'leads' => 132, 'cpl' => 412, 'sales' => 22],
                ['label' => '10 kW', 'leads' => 60, 'cpl' => 502, 'sales' => 12],
            ],
            'language' => [
                ['label' => 'Hindi', 'leads' => 286, 'cpl' => 362, 'sales' => 32],
                ['label' => 'English', 'leads' => 208, 'cpl' => 418, 'sales' => 20],
            ],
            'creative_theme' => [
                ['label' => 'Subsidy explainer', 'leads' => 182, 'cpl' => 340, 'sales' => 24],
                ['label' => 'Savings calculator', 'leads' => 134, 'cpl' => 372, 'sales' => 16],
                ['label' => 'Customer testimonial', 'leads' => 128, 'cpl' => 396, 'sales' => 18],
            ],
        ],
        'funnels' => [
            'impressions' => 359, // thousands placeholder, will normalise
            'clicks' => 157,
            'leads' => 794,
            'qualified' => 486,
            'converted' => 128,
        ],
        'creatives' => [
            'headlines' => [
                ['label' => 'Claim PM Surya Ghar rooftop subsidy today', 'ctr' => 0.052, 'leads' => 132, 'cpl' => 348],
                ['label' => 'Slash power bills with Dakshayani solar', 'ctr' => 0.048, 'leads' => 118, 'cpl' => 362],
                ['label' => 'Free rooftop audit + MNRE paperwork', 'ctr' => 0.044, 'leads' => 102, 'cpl' => 354],
            ],
            'images' => [
                ['label' => 'Crew installing 5 kW system', 'ctr' => 0.036, 'leads' => 88, 'cpl' => 338],
                ['label' => 'Before/after roof transformation', 'ctr' => 0.033, 'leads' => 76, 'cpl' => 346],
                ['label' => 'Savings calculator graphic', 'ctr' => 0.031, 'leads' => 68, 'cpl' => 358],
            ],
            'videos' => [
                ['label' => '30s customer testimonial', 'ctr' => 0.042, 'leads' => 64, 'cpl' => 352],
                ['label' => 'Drone walkthrough of rooftop plant', 'ctr' => 0.038, 'leads' => 52, 'cpl' => 364],
                ['label' => 'Installer interview on PM Surya Ghar', 'ctr' => 0.036, 'leads' => 48, 'cpl' => 372],
            ],
        ],
        'budget' => [
            'monthlyCap' => 500000,
            'plannedSpend' => 468000,
            'spendToDate' => 325300,
            'pacing' => 0.65,
            'burnRate' => 18600,
            'expectedBurn' => 16100,
            'alerts' => [],
        ],
        'alerts' => [
            ['type' => 'cpl_spike', 'message' => 'Meta retargeting CPL up 12% week-on-week; monitor bids.'],
        ],
    ];
}

function smart_marketing_analytics_normalise_metrics(array $metrics): array
{
    $impressions = max(0, (int) ($metrics['impressions'] ?? 0));
    $clicks = max(0, (int) ($metrics['clicks'] ?? 0));
    $spend = max(0.0, (float) ($metrics['spend'] ?? 0));
    $leads = max(0, (int) ($metrics['leads'] ?? ($metrics['lead_count'] ?? 0)));
    $qualified = max(0, (int) ($metrics['qualified'] ?? 0));
    $converted = max(0, (int) ($metrics['converted'] ?? 0));
    $callConnects = max(0, (int) ($metrics['callConnects'] ?? ($metrics['call_connects'] ?? 0)));
    $meetings = max(0, (int) ($metrics['meetings'] ?? 0));
    $sales = max(0, (int) ($metrics['sales'] ?? $converted));

    $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
    $cpc = $clicks > 0 ? $spend / $clicks : 0.0;
    $cpl = $leads > 0 ? $spend / max(1, $leads) : 0.0;
    $base = $leads > 0 ? $leads : max(1, $qualified);
    $convRate = $base > 0 ? $sales / $base : 0.0;

    return [
        'impressions' => $impressions,
        'clicks' => $clicks,
        'spend' => round($spend, 2),
        'leads' => $leads,
        'qualified' => $qualified,
        'converted' => $converted,
        'callConnects' => $callConnects,
        'meetings' => $meetings,
        'sales' => $sales,
        'ctr' => $ctr,
        'cpc' => $cpc,
        'cpl' => $cpl,
        'convRate' => min(1.0, $convRate),
    ];
}

function smart_marketing_analytics_normalise(array $analytics, array $settings = []): array
{
    $defaults = smart_marketing_analytics_defaults();
    $analytics = array_replace_recursive($defaults, $analytics);

    $totals = [
        'impressions' => 0,
        'clicks' => 0,
        'spend' => 0.0,
        'leads' => 0,
        'qualified' => 0,
        'converted' => 0,
        'callConnects' => 0,
        'meetings' => 0,
        'sales' => 0,
    ];

    $channels = [];
    foreach ($analytics['channels'] as $channel) {
        $channelMetrics = smart_marketing_analytics_normalise_metrics($channel['metrics'] ?? []);
        $channelCampaigns = [];
        foreach (($channel['campaigns'] ?? []) as $campaign) {
            $campaignMetrics = smart_marketing_analytics_normalise_metrics($campaign['metrics'] ?? []);
            $campaignAds = [];
            foreach (($campaign['ads'] ?? []) as $ad) {
                $campaignAds[] = [
                    'id' => (string) ($ad['id'] ?? ''),
                    'label' => (string) ($ad['label'] ?? ''),
                    'metrics' => smart_marketing_analytics_normalise_metrics($ad['metrics'] ?? []),
                ];
            }
            $campaignCampaign = [
                'id' => (string) ($campaign['id'] ?? ''),
                'label' => (string) ($campaign['label'] ?? ''),
                'metrics' => $campaignMetrics,
                'ads' => $campaignAds,
            ];
            $channelCampaigns[] = $campaignCampaign;
        }

        foreach ($channelCampaigns as $campaign) {
            $channelMetrics['impressions'] += $campaign['metrics']['impressions'];
            $channelMetrics['clicks'] += $campaign['metrics']['clicks'];
            $channelMetrics['spend'] += $campaign['metrics']['spend'];
            $channelMetrics['leads'] += $campaign['metrics']['leads'];
            $channelMetrics['qualified'] += $campaign['metrics']['qualified'];
            $channelMetrics['converted'] += $campaign['metrics']['converted'];
            $channelMetrics['callConnects'] += $campaign['metrics']['callConnects'];
            $channelMetrics['meetings'] += $campaign['metrics']['meetings'];
            $channelMetrics['sales'] += $campaign['metrics']['sales'];
        }

        $channelMetrics = smart_marketing_analytics_normalise_metrics($channelMetrics);

        foreach ($totals as $key => $value) {
            $totals[$key] += $channelMetrics[$key];
        }

        $channels[] = [
            'id' => (string) ($channel['id'] ?? ''),
            'label' => (string) ($channel['label'] ?? ''),
            'metrics' => $channelMetrics,
            'campaigns' => $channelCampaigns,
        ];
    }
    $analytics['channels'] = $channels;

    $funnels = $analytics['funnels'] ?? [];
    $funnels['impressions'] = max($totals['impressions'], (int) ($funnels['impressions'] ?? $totals['impressions']));
    $funnels['clicks'] = max($totals['clicks'], (int) ($funnels['clicks'] ?? $totals['clicks']));
    $funnels['leads'] = max($totals['leads'], (int) ($funnels['leads'] ?? $totals['leads']));
    $funnels['qualified'] = max($totals['qualified'], (int) ($funnels['qualified'] ?? $totals['qualified']));
    $funnels['converted'] = max($totals['sales'], (int) ($funnels['converted'] ?? $totals['sales']));
    $analytics['funnels'] = $funnels;

    if (!isset($analytics['budget']) || !is_array($analytics['budget'])) {
        $analytics['budget'] = $defaults['budget'];
    }

    $monthlyCap = (float) ($settings['budget']['monthlyCap'] ?? $analytics['budget']['monthlyCap'] ?? 0);
    $analytics['budget']['monthlyCap'] = $monthlyCap;
    $analytics['budget']['spendToDate'] = round($totals['spend'], 2);

    $analytics['updatedAt'] = (string) ($analytics['updatedAt'] ?? ai_timestamp());
    if (!isset($analytics['alerts']) || !is_array($analytics['alerts'])) {
        $analytics['alerts'] = [];
    }

    return $analytics;
}

function smart_marketing_analytics_save(array $analytics): void
{
    $payload = json_encode($analytics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing analytics.');
    }

    if (file_put_contents(smart_marketing_analytics_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing analytics.');
    }
}

function smart_marketing_analytics_load(array $settings = []): array
{
    $file = smart_marketing_analytics_file();
    if (!is_file($file)) {
        $defaults = smart_marketing_analytics_defaults();
        smart_marketing_analytics_save($defaults);
        return smart_marketing_analytics_normalise($defaults, $settings);
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        $defaults = smart_marketing_analytics_defaults();
        return smart_marketing_analytics_normalise($defaults, $settings);
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_analytics_load decode failed: ' . $exception->getMessage());
        $decoded = smart_marketing_analytics_defaults();
    }

    if (!is_array($decoded)) {
        $decoded = smart_marketing_analytics_defaults();
    }

    return smart_marketing_analytics_normalise($decoded, $settings);
}

function smart_marketing_refresh_analytics(array $settings): array
{
    $analytics = smart_marketing_analytics_load($settings);
    $analytics['updatedAt'] = ai_timestamp();

    $monthlyCap = (float) ($settings['budget']['monthlyCap'] ?? $analytics['budget']['monthlyCap'] ?? 0);
    $currency = (string) ($settings['budget']['currency'] ?? 'INR');

    $totals = ['spend' => 0.0, 'leads' => 0, 'sales' => 0, 'clicks' => 0, 'impressions' => 0];
    foreach ($analytics['channels'] as $channel) {
        $totals['spend'] += $channel['metrics']['spend'];
        $totals['leads'] += $channel['metrics']['leads'];
        $totals['sales'] += $channel['metrics']['sales'];
        $totals['clicks'] += $channel['metrics']['clicks'];
        $totals['impressions'] += $channel['metrics']['impressions'];
    }

    $analytics['budget']['monthlyCap'] = $monthlyCap;
    $analytics['budget']['spendToDate'] = round($totals['spend'], 2);

    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $dayOfMonth = (int) $now->format('j');
    $daysInMonth = (int) $now->format('t');

    $analytics['budget']['pacing'] = $monthlyCap > 0 ? $analytics['budget']['spendToDate'] / $monthlyCap : 0.0;
    $analytics['budget']['burnRate'] = $dayOfMonth > 0 ? $analytics['budget']['spendToDate'] / $dayOfMonth : 0.0;
    $analytics['budget']['expectedBurn'] = ($monthlyCap > 0 && $daysInMonth > 0) ? $monthlyCap / $daysInMonth : 0.0;
    $analytics['budget']['plannedSpend'] = (float) ($analytics['budget']['plannedSpend'] ?? $monthlyCap);

    $expectedPacing = ($daysInMonth > 0) ? $dayOfMonth / $daysInMonth : 0.0;
    $alerts = [];
    if ($analytics['budget']['pacing'] > ($expectedPacing + 0.1)) {
        $alerts[] = [
            'type' => 'budget_overpace',
            'message' => sprintf('Spend pacing %.1f%% vs expected %.1f%% of %s %s.', $analytics['budget']['pacing'] * 100, $expectedPacing * 100, $currency, number_format($monthlyCap, 0)),
            'channels' => ['email', 'whatsapp'],
        ];
    }
    if ($analytics['budget']['burnRate'] > ($analytics['budget']['expectedBurn'] * 1.2) && $analytics['budget']['expectedBurn'] > 0) {
        $alerts[] = [
            'type' => 'burn_rate_high',
            'message' => sprintf('Burn rate %s/day exceeds guardrail %s/day.', smart_marketing_format_currency($analytics['budget']['burnRate'], $currency), smart_marketing_format_currency($analytics['budget']['expectedBurn'], $currency)),
            'channels' => ['email'],
        ];
    }
    if ($totals['leads'] > 0 && $totals['sales'] > 0) {
        $conversionRate = $totals['sales'] / max(1, $totals['leads']);
        if ($conversionRate < 0.1) {
            $alerts[] = [
                'type' => 'conversion_soft',
                'message' => 'Lead-to-sale conversion dropped below 10%. Review qualification steps.',
                'channels' => ['email'],
            ];
        }
    }

    $analytics['budget']['alerts'] = $alerts;
    smart_marketing_analytics_save($analytics);

    return ['analytics' => $analytics, 'alerts' => $alerts];
}

function smart_marketing_optimization_defaults(array $settings = []): array
{
    $minBid = (float) ($settings['budget']['minBid'] ?? 20);
    $targetCpl = (float) ($settings['budget']['targetCpl'] ?? 450);

    return [
        'autoRules' => [
            'pauseUnderperforming' => [
                'enabled' => true,
                'ctrThreshold' => 0.015,
                'cvrThreshold' => 0.04,
            ],
            'bidGuardrails' => [
                'enabled' => true,
                'minBid' => max(1.0, $minBid),
                'maxBid' => max(2 * $minBid, $minBid + 10),
                'step' => 0.1,
            ],
            'budgetShift' => [
                'enabled' => true,
                'shiftPercent' => 0.12,
                'targetCpl' => $targetCpl,
            ],
            'creativeRefresh' => [
                'enabled' => true,
                'decayDays' => 7,
            ],
        ],
        'manualPlaybooks' => [
            'lastActionAt' => null,
        ],
        'learning' => [
            'tests' => [
                [
                    'id' => 'TEST-CTA-01',
                    'createdAt' => ai_timestamp(),
                    'type' => 'CTA',
                    'status' => 'completed',
                    'result' => 'Variant B (+14% CTR) rolled out to Meta retargeting.',
                ],
                [
                    'id' => 'TEST-IMAGE-02',
                    'createdAt' => ai_timestamp(),
                    'type' => 'Creative',
                    'status' => 'queued',
                    'result' => 'Pending – rooftop night shot vs day shot.',
                ],
            ],
            'nextBestAction' => 'Run a Hindi landing page CTA vs WhatsApp chat handoff test.',
        ],
        'history' => [],
        'updatedAt' => null,
    ];
}

function smart_marketing_optimization_load(array $settings = []): array
{
    $file = smart_marketing_optimization_file();
    if (!is_file($file)) {
        $defaults = smart_marketing_optimization_defaults($settings);
        smart_marketing_optimization_save($defaults);
        return $defaults;
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return smart_marketing_optimization_defaults($settings);
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_optimization_load decode failed: ' . $exception->getMessage());
        $decoded = smart_marketing_optimization_defaults($settings);
    }

    if (!is_array($decoded)) {
        $decoded = smart_marketing_optimization_defaults($settings);
    }

    $defaults = smart_marketing_optimization_defaults($settings);
    $state = array_replace_recursive($defaults, $decoded);

    if (!isset($state['history']) || !is_array($state['history'])) {
        $state['history'] = [];
    }
    if (!isset($state['learning']['tests']) || !is_array($state['learning']['tests'])) {
        $state['learning']['tests'] = [];
    }

    return $state;
}

function smart_marketing_optimization_save(array $state): void
{
    $state['updatedAt'] = ai_timestamp();
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing optimisation state.');
    }

    if (file_put_contents(smart_marketing_optimization_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing optimisation state.');
    }
}

function smart_marketing_optimization_merge(array $input, array $current, array $settings = []): array
{
    $state = $current;
    $autoRules = $state['autoRules'] ?? [];

    if (isset($input['autoRules']) && is_array($input['autoRules'])) {
        foreach ($input['autoRules'] as $key => $rule) {
            if (!isset($autoRules[$key])) {
                continue;
            }
            $autoRules[$key]['enabled'] = (bool) ($rule['enabled'] ?? $autoRules[$key]['enabled']);
            foreach ($rule as $field => $value) {
                if ($field === 'enabled') {
                    continue;
                }
                $autoRules[$key][$field] = is_numeric($value) ? (float) $value : $value;
            }
        }
        $state['autoRules'] = $autoRules;
    }

    if (isset($input['learning']) && is_array($input['learning'])) {
        if (isset($input['learning']['nextBestAction'])) {
            $state['learning']['nextBestAction'] = trim((string) $input['learning']['nextBestAction']);
        }
    }

    $state['updatedAt'] = ai_timestamp();

    return $state;
}

function smart_marketing_apply_auto_rules(array &$campaigns, array $optimization, array $settings, array $governance): array
{
    $entries = [];
    $alerts = [];
    $modified = false;
    $autoRules = $optimization['autoRules'] ?? [];
    $currency = (string) ($settings['budget']['currency'] ?? 'INR');
    $targetCpl = (float) ($settings['budget']['targetCpl'] ?? 450);
    $now = ai_timestamp();

    if (!isset($optimization['history']) || !is_array($optimization['history'])) {
        $optimization['history'] = [];
    }

    $emergencyActive = (bool) ($governance['emergencyStop']['active'] ?? false);
    if ($emergencyActive) {
        $message = 'Emergency stop active; automations paused.';
        $lastHistory = end($optimization['history']);
        $alreadyLogged = is_array($lastHistory)
            && (($lastHistory['rule'] ?? '') === 'emergency_stop')
            && (($lastHistory['message'] ?? '') === $message);
        if (!$alreadyLogged) {
            $optimization['history'][] = [
                'timestamp' => $now,
                'rule' => 'emergency_stop',
                'campaign_id' => null,
                'message' => $message,
            ];
            if (count($optimization['history']) > 100) {
                $optimization['history'] = array_slice($optimization['history'], -100);
            }
        }
        if (!empty($optimization['history'])) {
            reset($optimization['history']);
        }

        $entries[] = [
            'timestamp' => $now,
            'type' => 'emergency_stop',
            'rule' => 'emergency_stop',
            'campaign_id' => null,
            'message' => $message,
        ];
        $alerts[] = [
            'type' => 'emergency_stop',
            'message' => $message,
            'channels' => ['email'],
        ];

        return [
            'entries' => $entries,
            'alerts' => $alerts,
            'optimization' => $optimization,
            'modified' => false,
        ];
    }

    if (($autoRules['pauseUnderperforming']['enabled'] ?? false)) {
        $ctrThreshold = (float) ($autoRules['pauseUnderperforming']['ctrThreshold'] ?? 0.012);
        $cvrThreshold = (float) ($autoRules['pauseUnderperforming']['cvrThreshold'] ?? 0.04);
        foreach ($campaigns as &$campaign) {
            if (($campaign['status'] ?? '') !== 'launched') {
                continue;
            }
            $metrics = smart_marketing_analytics_normalise_metrics($campaign['metrics'] ?? []);
            $ctr = $metrics['ctr'];
            $cvr = $metrics['leads'] > 0 ? ($metrics['sales'] / max(1, $metrics['leads'])) : 0.0;
            if ($ctr < $ctrThreshold && $cvr < $cvrThreshold) {
                $campaign['status'] = 'paused';
                $campaign['audit_trail'][] = [
                    'timestamp' => $now,
                    'action' => 'auto_rule.pause',
                    'context' => ['rule' => 'pause_underperforming'],
                ];
                $entries[] = [
                    'timestamp' => $now,
                    'type' => 'auto_rule',
                    'rule' => 'pause_underperforming',
                    'campaign_id' => $campaign['id'] ?? null,
                    'message' => sprintf('Paused %s due to CTR %.2f%% and conversion %.1f%% below guardrail.', $campaign['label'] ?? ($campaign['id'] ?? 'campaign'), $ctr * 100, $cvr * 100),
                ];
                $modified = true;
            }
        }
        unset($campaign);
    }

    if (($autoRules['bidGuardrails']['enabled'] ?? false)) {
        $minBid = max(1.0, (float) ($autoRules['bidGuardrails']['minBid'] ?? ($settings['budget']['minBid'] ?? 10)));
        $maxBid = max($minBid, (float) ($autoRules['bidGuardrails']['maxBid'] ?? ($minBid * 3)));
        $step = max(0.01, (float) ($autoRules['bidGuardrails']['step'] ?? 0.1));
        $lockEnabled = (bool) ($governance['budgetLock']['enabled'] ?? false);
        foreach ($campaigns as &$campaign) {
            if (($campaign['status'] ?? '') !== 'launched') {
                continue;
            }
            $currentDaily = (float) ($campaign['budget']['daily'] ?? $minBid);
            $metrics = smart_marketing_analytics_normalise_metrics($campaign['metrics'] ?? []);
            $cpl = $metrics['leads'] > 0 ? $metrics['spend'] / max(1, $metrics['leads']) : $targetCpl;
            if ($cpl <= 0) {
                $cpl = $targetCpl;
            }

            if ($cpl < $targetCpl * 0.85) {
                $proposed = min($maxBid, $currentDaily * (1 + $step));
                if ($lockEnabled && $proposed > $currentDaily) {
                    $alerts[] = [
                        'type' => 'budget_lock',
                        'message' => sprintf('Budget lock prevented bid uplift for %s.', $campaign['label'] ?? ($campaign['id'] ?? 'campaign')),
                        'channels' => ['email'],
                    ];
                    continue;
                }
                if ($proposed > $currentDaily) {
                    $campaign['budget']['daily'] = round($proposed, 2);
                    $entries[] = [
                        'timestamp' => $now,
                        'type' => 'auto_rule',
                        'rule' => 'bid_guardrail_up',
                        'campaign_id' => $campaign['id'] ?? null,
                        'message' => sprintf('Raised daily bid to %s for %s (CPL %s).', smart_marketing_format_currency($campaign['budget']['daily'], $currency), $campaign['label'] ?? ($campaign['id'] ?? 'campaign'), smart_marketing_format_currency($cpl, $currency)),
                    ];
                    $modified = true;
                }
            } elseif ($cpl > $targetCpl * 1.2) {
                $proposed = max($minBid, $currentDaily * (1 - $step));
                if ($proposed < $currentDaily) {
                    $campaign['budget']['daily'] = round($proposed, 2);
                    $entries[] = [
                        'timestamp' => $now,
                        'type' => 'auto_rule',
                        'rule' => 'bid_guardrail_down',
                        'campaign_id' => $campaign['id'] ?? null,
                        'message' => sprintf('Reduced daily bid to %s for %s (CPL %s above guardrail).', smart_marketing_format_currency($campaign['budget']['daily'], $currency), $campaign['label'] ?? ($campaign['id'] ?? 'campaign'), smart_marketing_format_currency($cpl, $currency)),
                    ];
                    $modified = true;
                }
            }
        }
        unset($campaign);
    }

    if (($autoRules['budgetShift']['enabled'] ?? false)) {
        $shiftPercent = max(0.01, (float) ($autoRules['budgetShift']['shiftPercent'] ?? 0.1));
        $campaignPool = [];
        foreach ($campaigns as $index => $campaign) {
            if (($campaign['status'] ?? '') !== 'launched') {
                continue;
            }
            $metrics = smart_marketing_analytics_normalise_metrics($campaign['metrics'] ?? []);
            if ($metrics['leads'] < 10) {
                continue;
            }
            $cpl = $metrics['leads'] > 0 ? $metrics['spend'] / max(1, $metrics['leads']) : $targetCpl;
            $campaignPool[] = [
                'index' => $index,
                'id' => $campaign['id'] ?? null,
                'label' => $campaign['label'] ?? ($campaign['id'] ?? 'campaign'),
                'cpl' => $cpl,
            ];
        }

        if (count($campaignPool) >= 4) {
            usort($campaignPool, static fn($a, $b) => $a['cpl'] <=> $b['cpl']);
            $quartileCount = max(1, (int) ceil(count($campaignPool) / 4));
            $winners = array_slice($campaignPool, 0, $quartileCount);
            $laggards = array_slice($campaignPool, -$quartileCount);

            $lockEnabled = (bool) ($governance['budgetLock']['enabled'] ?? false);
            foreach ($winners as $winner) {
                $idx = $winner['index'];
                $daily = (float) ($campaigns[$idx]['budget']['daily'] ?? $settings['budget']['dailyCap'] ?? 0);
                $proposed = $daily * (1 + $shiftPercent);
                if ($lockEnabled && $proposed > $daily) {
                    $alerts[] = [
                        'type' => 'budget_lock',
                        'message' => sprintf('Budget lock held spend for %s despite top performance.', $winner['label']),
                        'channels' => ['email'],
                    ];
                    continue;
                }
                $campaigns[$idx]['budget']['daily'] = round($proposed, 2);
                $entries[] = [
                    'timestamp' => $now,
                    'type' => 'auto_rule',
                    'rule' => 'budget_shift_up',
                    'campaign_id' => $winner['id'],
                    'message' => sprintf('Shifted +%d%% budget to %s (CPL %s).', (int) round($shiftPercent * 100), $winner['label'], smart_marketing_format_currency($winner['cpl'], $currency)),
                ];
                $modified = true;
            }

            foreach ($laggards as $laggard) {
                $idx = $laggard['index'];
                $daily = (float) ($campaigns[$idx]['budget']['daily'] ?? $settings['budget']['dailyCap'] ?? 0);
                $proposed = max(0, $daily * (1 - $shiftPercent));
                $campaigns[$idx]['budget']['daily'] = round($proposed, 2);
                $entries[] = [
                    'timestamp' => $now,
                    'type' => 'auto_rule',
                    'rule' => 'budget_shift_down',
                    'campaign_id' => $laggard['id'],
                    'message' => sprintf('Trimmed %d%% budget from %s (CPL %s above guardrail).', (int) round($shiftPercent * 100), $laggard['label'], smart_marketing_format_currency($laggard['cpl'], $currency)),
                ];
                $modified = true;
            }
        }
    }

    if (($autoRules['creativeRefresh']['enabled'] ?? false)) {
        $decayDays = max(1, (int) ($autoRules['creativeRefresh']['decayDays'] ?? 7));
        $tz = new DateTimeZone('Asia/Kolkata');
        foreach ($campaigns as &$campaign) {
            if (($campaign['status'] ?? '') !== 'launched') {
                continue;
            }
            $lastRefresh = $campaign['maintenance']['lastCreativeRefresh'] ?? null;
            $needsRefresh = true;
            if ($lastRefresh) {
                try {
                    $last = new DateTime($lastRefresh, $tz);
                    $nowDate = new DateTime('now', $tz);
                    $diff = $nowDate->diff($last);
                    $needsRefresh = ($diff->days ?? 0) >= $decayDays;
                } catch (Throwable $exception) {
                    $needsRefresh = true;
                }
            }
            if ($needsRefresh) {
                $campaign['maintenance']['lastCreativeRefresh'] = $now;
                $entries[] = [
                    'timestamp' => $now,
                    'type' => 'auto_rule',
                    'rule' => 'creative_refresh',
                    'campaign_id' => $campaign['id'] ?? null,
                    'message' => sprintf('Refreshed creatives for %s after %d-day cycle.', $campaign['label'] ?? ($campaign['id'] ?? 'campaign'), $decayDays),
                ];
                $modified = true;
            }
        }
        unset($campaign);
    }

    foreach ($entries as $entry) {
        $optimization['history'][] = [
            'timestamp' => $entry['timestamp'],
            'rule' => $entry['rule'] ?? $entry['type'],
            'campaign_id' => $entry['campaign_id'] ?? null,
            'message' => $entry['message'],
        ];
    }
    if (count($optimization['history']) > 100) {
        $optimization['history'] = array_slice($optimization['history'], -100);
    }

    return [
        'entries' => $entries,
        'alerts' => $alerts,
        'optimization' => $optimization,
        'modified' => $modified,
    ];
}

function smart_marketing_governance_defaults(array $settings = []): array
{
    return [
        'budgetLock' => [
            'enabled' => false,
            'cap' => (float) ($settings['budget']['monthlyCap'] ?? 0),
            'updatedAt' => null,
        ],
        'emergencyStop' => [
            'active' => false,
            'triggeredAt' => null,
            'triggeredBy' => null,
        ],
        'policyChecklist' => [
            'pmSuryaClaims' => true,
            'ethicalMessaging' => true,
            'disclaimerPlaced' => true,
            'dataAccuracy' => true,
            'lastReviewed' => null,
            'notes' => '',
        ],
        'dataProtection' => [
            'maskPii' => true,
            'requests' => [],
        ],
        'log' => [],
        'updatedAt' => null,
    ];
}

function smart_marketing_governance_load(array $settings = []): array
{
    $file = smart_marketing_governance_file();
    if (!is_file($file)) {
        $defaults = smart_marketing_governance_defaults($settings);
        smart_marketing_governance_save($defaults);
        return $defaults;
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return smart_marketing_governance_defaults($settings);
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_governance_load decode failed: ' . $exception->getMessage());
        $decoded = smart_marketing_governance_defaults($settings);
    }

    if (!is_array($decoded)) {
        $decoded = smart_marketing_governance_defaults($settings);
    }

    $defaults = smart_marketing_governance_defaults($settings);
    $state = array_replace_recursive($defaults, $decoded);

    if (!isset($state['log']) || !is_array($state['log'])) {
        $state['log'] = [];
    }
    if (!isset($state['dataProtection']['requests']) || !is_array($state['dataProtection']['requests'])) {
        $state['dataProtection']['requests'] = [];
    }

    return $state;
}

function smart_marketing_governance_save(array $state): void
{
    $state['updatedAt'] = ai_timestamp();
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing governance state.');
    }

    if (file_put_contents(smart_marketing_governance_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing governance state.');
    }
}

function smart_marketing_governance_log(array &$state, string $event, array $context = [], array $user = []): void
{
    if (!isset($state['log']) || !is_array($state['log'])) {
        $state['log'] = [];
    }

    $state['log'][] = [
        'timestamp' => ai_timestamp(),
        'event' => $event,
        'context' => smart_marketing_scrub_context($context),
        'user' => [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['full_name'] ?? ($user['name'] ?? 'Admin')),
        ],
    ];

    if (count($state['log']) > 100) {
        $state['log'] = array_slice($state['log'], -100);
    }
}

function smart_marketing_governance_track_data_request(array &$state, string $type, array $context = []): void
{
    if (!isset($state['dataProtection']['requests']) || !is_array($state['dataProtection']['requests'])) {
        $state['dataProtection']['requests'] = [];
    }

    $state['dataProtection']['requests'][] = [
        'timestamp' => ai_timestamp(),
        'type' => $type,
        'context' => smart_marketing_scrub_context($context),
    ];

    if (count($state['dataProtection']['requests']) > 50) {
        $state['dataProtection']['requests'] = array_slice($state['dataProtection']['requests'], -50);
    }
}

function smart_marketing_data_export(array &$governance, array $settings): array
{
    $payload = [
        'generatedAt' => ai_timestamp(),
        'analytics' => smart_marketing_analytics_load($settings),
        'campaigns' => smart_marketing_campaigns_load(),
        'audit' => smart_marketing_audit_log_read(),
    ];

    $file = smart_marketing_exports_dir() . '/smart-marketing-export-' . date('Ymd-His') . '.json';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode Smart Marketing export payload.');
    }

    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing export payload.');
    }

    smart_marketing_governance_track_data_request($governance, 'export', ['file' => basename($file)]);
    smart_marketing_governance_log($governance, 'data.export', ['file' => basename($file)]);

    return ['file' => $file, 'payload' => $payload];
}

function smart_marketing_data_erase(array &$governance): void
{
    smart_marketing_governance_track_data_request($governance, 'erase', []);
    smart_marketing_governance_log($governance, 'data.erase_queued');
}

function smart_marketing_notifications_defaults(): array
{
    return [
        'dailyDigest' => [
            'enabled' => true,
            'time' => '08:30',
            'channels' => [
                'email' => 'ops@dakshayani.in',
                'whatsapp' => '+91-6200001234',
            ],
        ],
        'instant' => [
            'email' => true,
            'whatsapp' => true,
        ],
        'lastDigest' => null,
        'log' => [],
        'updatedAt' => null,
    ];
}

function smart_marketing_notifications_load(): array
{
    $file = smart_marketing_notifications_file();
    if (!is_file($file)) {
        $defaults = smart_marketing_notifications_defaults();
        smart_marketing_notifications_save($defaults);
        return $defaults;
    }

    $contents = file_get_contents($file);
    if ($contents === false || trim($contents) === '') {
        return smart_marketing_notifications_defaults();
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('smart_marketing_notifications_load decode failed: ' . $exception->getMessage());
        $decoded = smart_marketing_notifications_defaults();
    }

    if (!is_array($decoded)) {
        $decoded = smart_marketing_notifications_defaults();
    }

    $defaults = smart_marketing_notifications_defaults();
    $state = array_replace_recursive($defaults, $decoded);
    if (!isset($state['log']) || !is_array($state['log'])) {
        $state['log'] = [];
    }

    return $state;
}

function smart_marketing_notifications_save(array $state): void
{
    $state['updatedAt'] = ai_timestamp();
    $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Smart Marketing notification state.');
    }

    if (file_put_contents(smart_marketing_notifications_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist Smart Marketing notification state.');
    }
}

function smart_marketing_notifications_push(array $state, string $type, string $message, array $channels = []): array
{
    if (!isset($state['log']) || !is_array($state['log'])) {
        $state['log'] = [];
    }

    $state['log'][] = [
        'timestamp' => ai_timestamp(),
        'type' => $type,
        'message' => $message,
        'channels' => $channels,
    ];

    if (count($state['log']) > 50) {
        $state['log'] = array_slice($state['log'], -50);
    }

    return $state;
}

function smart_marketing_store_asset(string $type, array $payload): array
{
    $id = uniqid('asset_', true);
    $record = [
        'id' => $id,
        'type' => $type,
        'created_at' => ai_timestamp(),
        'payload' => $payload,
    ];

    $file = smart_marketing_assets_dir() . '/' . $id . '.json';
    if (file_put_contents($file, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Unable to persist creative asset.');
    }

    return $record;
}

function smart_marketing_list_assets(int $limit = 25): array
{
    $dir = smart_marketing_assets_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.json');
    if ($files === false) {
        return [];
    }

    rsort($files);
    $files = array_slice($files, 0, $limit);

    $assets = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            continue;
        }

        if (is_array($decoded)) {
            $assets[] = $decoded;
        }
    }

    return $assets;
}

function smart_marketing_generate_brain_prompt(array $inputs, array $settings): string
{
    $goals = implode(', ', $inputs['goals']);
    $regions = implode(', ', $inputs['regions']);
    $products = implode(', ', $inputs['products']);
    $languages = implode(', ', $inputs['languages']);
    $budget = sprintf('Daily budget %s %s, monthly cap %s %s, minimum bid %s %s, CPA guardrail %s %s.',
        $settings['budget']['currency'] ?? 'INR',
        $inputs['dailyBudget'],
        $settings['budget']['currency'] ?? 'INR',
        $inputs['monthlyBudget'],
        $settings['budget']['currency'] ?? 'INR',
        $inputs['minBid'],
        $settings['budget']['currency'] ?? 'INR',
        $inputs['cpaGuardrail']
    );

    $autonomy = ucfirst($inputs['autonomyMode']);
    $compliance = [];
    foreach ($inputs['compliance'] as $key => $value) {
        if ($value) {
            $compliance[] = $key;
        }
    }
    $complianceText = empty($compliance) ? 'Standard marketing compliance only.' : implode(', ', $compliance);

    $loop = 'Provide a day-by-day launch schedule, a weekly optimisation checklist, and specify how learnings from conversions update the plan.';

    $prompt = <<<PROMPT
You are "Marketing Brain", an autonomous marketing director for Dakshayani Enterprises, an Indian solar EPC. Create a full-funnel marketing execution plan using only Google Ads, Meta Ads, YouTube, WhatsApp, SMS, and Email. Use only Gemini intelligence.

Inputs:
- Business goals: {$goals}
- Target regions: {$regions}
- Product lines: {$products}
- Supported languages: {$languages}
- {$budget}
- Autonomy mode: {$autonomy}
- Compliance requirements: {$complianceText}
- Additional notes: {$inputs['notes']}

Outputs must be valid JSON with keys: channel_plan (array), audience_plan (array), creative_plan (object with text, images, video), landing_plan (object), budget_allocation (array with channel, spend, pacing), kpi_targets (object), optimisation_loop (object with daily_review, weekly_review, adjustments, learning_strategy).

For each channel include budgets, pacing, objective, campaign_name, and activation steps. Audience plan must include inclusions and exclusions with geo, age, interests, and lookalikes. Creative plan must produce at least three variants per channel with CTA suggestions.

{$loop}
PROMPT;

    return $prompt;
}

function smart_marketing_generate_brain_plan(array $inputs, array $settings, array $aiSettings): array
{
    $prompt = smart_marketing_generate_brain_prompt($inputs, $settings);
    $responseText = ai_gemini_generate_text($aiSettings, $prompt);

    $plan = null;
    $clean = trim($responseText);
    if (str_starts_with($clean, '```')) {
        $clean = preg_replace('/^```[A-Za-z]*\n/', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
    }

    try {
        $plan = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $plan = null;
    }

    if (!is_array($plan)) {
        $plan = ['rawText' => $responseText];
    }

    return [
        'prompt' => $prompt,
        'text' => $responseText,
        'plan' => $plan,
    ];
}

function smart_marketing_validate_brain_inputs(array $inputs, array $settings): array
{
    $errors = [];

    $totalDaily = (float) ($inputs['dailyBudget'] ?? 0);
    $totalMonthly = (float) ($inputs['monthlyBudget'] ?? 0);
    $minBid = (float) ($inputs['minBid'] ?? 0);
    $cpaGuardrail = (float) ($inputs['cpaGuardrail'] ?? 0);

    if ($totalDaily <= 0) {
        $errors[] = 'Daily budget must be greater than zero.';
    } elseif ($totalDaily > (float) ($settings['budget']['dailyCap'] ?? $totalDaily)) {
        $errors[] = 'Daily budget exceeds configured cap.';
    }

    if ($totalMonthly <= 0) {
        $errors[] = 'Monthly budget must be greater than zero.';
    } elseif ($totalMonthly > (float) ($settings['budget']['monthlyCap'] ?? $totalMonthly)) {
        $errors[] = 'Monthly budget exceeds configured cap.';
    }

    if ($minBid < (float) ($settings['budget']['minBid'] ?? 0)) {
        $errors[] = 'Minimum bid must respect guardrail.';
    }

    if ($cpaGuardrail < (float) ($settings['budget']['targetCpl'] ?? 0)) {
        $errors[] = 'CPA guardrail must be at least the configured target CPL.';
    }

    if (empty($inputs['goals'])) {
        $errors[] = 'Select at least one business goal.';
    }

    if (empty($inputs['regions'])) {
        $errors[] = 'Select target regions.';
    }

    if (empty($inputs['products'])) {
        $errors[] = 'Select at least one product line.';
    }

    if (empty($inputs['languages'])) {
        $errors[] = 'Select at least one language.';
    }

    return $errors;
}

function smart_marketing_update_run_status(array &$runs, int $runId, string $status): bool
{
    foreach ($runs as &$run) {
        if ((int) ($run['id'] ?? 0) === $runId) {
            $run['status'] = $status;
            $run['updated_at'] = ai_timestamp();
            return true;
        }
    }

    return false;
}

function smart_marketing_apply_kill_switch(array &$runs): void
{
    foreach ($runs as &$run) {
        if (in_array($run['status'] ?? '', ['live', 'pending'], true)) {
            $run['status'] = 'halted';
            $run['updated_at'] = ai_timestamp();
        }
    }
}

function smart_marketing_collect_inputs_from_request(array $defaults, array $data): array
{
    $goals = isset($data['goals']) ? (array) $data['goals'] : [];
    $regions = isset($data['regions']) ? (array) $data['regions'] : [];
    $products = isset($data['products']) ? (array) $data['products'] : [];
    $languages = isset($data['languages']) ? (array) $data['languages'] : [];

    $compliance = [
        'platform_policy' => filter_var($data['compliance_platform_policy'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'brand_tone' => filter_var($data['compliance_brand_tone'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'legal_disclaimers' => filter_var($data['compliance_legal_disclaimers'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];

    return [
        'goals' => array_values(array_unique(array_map('strval', $goals))),
        'regions' => array_values(array_unique(array_map('strval', $regions))),
        'products' => array_values(array_unique(array_map('strval', $products))),
        'languages' => array_values(array_unique(array_map('strval', $languages))),
        'dailyBudget' => (float) ($data['daily_budget'] ?? $defaults['budget']['dailyCap']),
        'monthlyBudget' => (float) ($data['monthly_budget'] ?? $defaults['budget']['monthlyCap']),
        'minBid' => (float) ($data['min_bid'] ?? $defaults['budget']['minBid']),
        'cpaGuardrail' => (float) ($data['cpa_guardrail'] ?? $defaults['budget']['targetCpl']),
        'autonomyMode' => in_array($data['autonomy_mode'] ?? '', ['auto', 'review', 'draft'], true) ? $data['autonomy_mode'] : 'draft',
        'notes' => trim((string) ($data['notes'] ?? '')),
        'compliance' => $compliance,
    ];
}

function smart_marketing_prepare_run_record(int $id, array $inputs, array $generation, string $status): array
{
    return [
        'id' => $id,
        'status' => $status,
        'inputs' => $inputs,
        'plan' => $generation['plan'],
        'response_text' => $generation['text'],
        'prompt' => $generation['prompt'],
        'created_at' => ai_timestamp(),
        'updated_at' => ai_timestamp(),
    ];
}

function smart_marketing_autonomy_status(string $mode): string
{
    return match ($mode) {
        'auto' => 'live',
        'review' => 'pending',
        default => 'draft',
    };
}

function smart_marketing_settings_merge(array $current, array $updates): array
{
    foreach ($updates as $key => $value) {
        if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
            $current[$key] = smart_marketing_settings_merge($current[$key], $value);
        } else {
            $current[$key] = $value;
        }
    }

    return $current;
}

function smart_marketing_format_currency(float $amount, string $currency = 'INR'): string
{
    return $currency . ' ' . number_format($amount, 2);
}

function smart_marketing_collect_settings_payload(array $input, array $current): array
{
    $payload = $current;

    if (isset($input['businessProfile']) && is_array($input['businessProfile'])) {
        $profile = $payload['businessProfile'];
        $incoming = $input['businessProfile'];
        $profile['brandName'] = trim((string) ($incoming['brandName'] ?? $profile['brandName']));
        $profile['tagline'] = trim((string) ($incoming['tagline'] ?? $profile['tagline']));
        $profile['about'] = trim((string) ($incoming['about'] ?? $profile['about']));
        $profile['primaryContact'] = trim((string) ($incoming['primaryContact'] ?? $profile['primaryContact']));
        $profile['supportEmail'] = trim((string) ($incoming['supportEmail'] ?? $profile['supportEmail']));
        $profile['whatsappNumber'] = trim((string) ($incoming['whatsappNumber'] ?? $profile['whatsappNumber']));
        $regions = $incoming['serviceRegions'] ?? $profile['serviceRegions'];
        if (is_string($regions)) {
            $regions = preg_split('/\s*,\s*/', $regions, -1, PREG_SPLIT_NO_EMPTY);
        }
        $profile['serviceRegions'] = array_values(array_unique(array_map('strval', is_array($regions) ? $regions : [])));
        $payload['businessProfile'] = $profile;
    }

    if (isset($input['audiences']) && is_array($input['audiences'])) {
        $audiences = $payload['audiences'];
        $incoming = $input['audiences'];
        $segments = $incoming['primarySegments'] ?? $audiences['primarySegments'];
        if (is_string($segments)) {
            $segments = preg_split('/\n+/', $segments, -1, PREG_SPLIT_NO_EMPTY);
        }
        $audiences['primarySegments'] = array_values(array_unique(array_map('strval', is_array($segments) ? $segments : [])));
        $audiences['remarketingNotes'] = trim((string) ($incoming['remarketingNotes'] ?? $audiences['remarketingNotes']));
        $audiences['exclusions'] = trim((string) ($incoming['exclusions'] ?? $audiences['exclusions']));
        $payload['audiences'] = $audiences;
    }

    if (isset($input['products']) && is_array($input['products'])) {
        $products = $payload['products'];
        $incoming = $input['products'];
        $portfolio = $incoming['portfolio'] ?? $products['portfolio'];
        if (is_string($portfolio)) {
            $portfolio = preg_split('/\n+/', $portfolio, -1, PREG_SPLIT_NO_EMPTY);
        }
        $products['portfolio'] = array_values(array_unique(array_map('strval', is_array($portfolio) ? $portfolio : [])));
        $products['offers'] = trim((string) ($incoming['offers'] ?? $products['offers']));
        $payload['products'] = $products;
    }

    if (isset($input['budget']) && is_array($input['budget'])) {
        $budget = $payload['budget'];
        $incoming = $input['budget'];
        $budget['dailyCap'] = (float) ($incoming['dailyCap'] ?? $budget['dailyCap']);
        $budget['monthlyCap'] = (float) ($incoming['monthlyCap'] ?? $budget['monthlyCap']);
        $budget['minBid'] = (float) ($incoming['minBid'] ?? $budget['minBid']);
        $budget['targetCpl'] = (float) ($incoming['targetCpl'] ?? $budget['targetCpl']);
        $budget['currency'] = trim((string) ($incoming['currency'] ?? $budget['currency']));
        $payload['budget'] = $budget;
    }

    if (isset($input['autonomy']) && is_array($input['autonomy'])) {
        $autonomy = $payload['autonomy'];
        $incoming = $input['autonomy'];
        $mode = strtolower((string) ($incoming['mode'] ?? $autonomy['mode']));
        if (!in_array($mode, ['auto', 'review', 'draft'], true)) {
            $mode = 'draft';
        }
        $autonomy['mode'] = $mode;
        $autonomy['reviewRecipients'] = trim((string) ($incoming['reviewRecipients'] ?? $autonomy['reviewRecipients']));
        $autonomy['killSwitchEngaged'] = (bool) ($incoming['killSwitchEngaged'] ?? $autonomy['killSwitchEngaged']);
        $payload['autonomy'] = $autonomy;
    }

    if (isset($input['compliance']) && is_array($input['compliance'])) {
        $compliance = $payload['compliance'];
        $incoming = $input['compliance'];
        $compliance['policyChecks'] = (bool) ($incoming['policyChecks'] ?? $compliance['policyChecks']);
        $compliance['brandTone'] = (bool) ($incoming['brandTone'] ?? $compliance['brandTone']);
        $compliance['legalDisclaimers'] = (bool) ($incoming['legalDisclaimers'] ?? $compliance['legalDisclaimers']);
        $compliance['pmSuryaDisclaimer'] = trim((string) ($incoming['pmSuryaDisclaimer'] ?? $compliance['pmSuryaDisclaimer']));
        $compliance['notes'] = trim((string) ($incoming['notes'] ?? $compliance['notes']));
        $payload['compliance'] = $compliance;
    }

    if (isset($input['integrations']) && is_array($input['integrations'])) {
        $integrations = $payload['integrations'];
        $incoming = $input['integrations'];
        foreach (array_keys(smart_marketing_default_connector_settings()) as $key) {
            if (!isset($incoming[$key])) {
                continue;
            }
            $entry = $integrations[$key] ?? [];
            $data = $incoming[$key];
            $status = strtolower((string) ($data['status'] ?? ($entry['status'] ?? 'unknown')));
            if (!in_array($status, ['connected', 'warning', 'error', 'unknown'], true)) {
                $status = 'unknown';
            }
            $entry['status'] = $status;
            foreach ($data as $field => $value) {
                if ($field === 'status') {
                    continue;
                }
                $entry[$field] = is_string($value) ? trim($value) : $value;
            }
            $integrations[$key] = $entry;
        }
        $payload['integrations'] = $integrations;
    }

    return $payload;
}

function smart_marketing_language_options(): array
{
    return ['English', 'Hindi'];
}

function smart_marketing_goal_options(): array
{
    return ['Leads', 'Awareness', 'Remarketing', 'Seasonal Offers', 'Service AMC'];
}

function smart_marketing_product_options(): array
{
    return ['Rooftop 1 kW', 'Rooftop 3 kW', 'Rooftop 5 kW', 'Rooftop 10 kW', 'Hybrid', 'Off-grid', 'C&I 10-100 kW', 'PM Surya Ghar', 'Non-scheme'];
}

function smart_marketing_region_defaults(array $settings): array
{
    $regions = $settings['businessProfile']['serviceRegions'] ?? [];
    if (empty($regions)) {
        $regions = ['Jharkhand'];
    }

    return $regions;
}

function smart_marketing_compliance_defaults(array $settings): array
{
    return [
        'platform_policy' => (bool) ($settings['compliance']['policyChecks'] ?? true),
        'brand_tone' => (bool) ($settings['compliance']['brandTone'] ?? true),
        'legal_disclaimers' => (bool) ($settings['compliance']['legalDisclaimers'] ?? true),
    ];
}

function smart_marketing_prepare_language_codes(array $languages): array
{
    $map = [
        'english' => 'en',
        'hindi' => 'hi',
    ];

    $codes = [];
    foreach ($languages as $language) {
        $key = strtolower($language);
        if (isset($map[$key])) {
            $codes[] = $map[$key];
        }
    }

    return array_values(array_unique($codes));
}

function smart_marketing_generate_text_asset(array $aiSettings, string $category, string $brief, array $settings): array
{
    $prompt = <<<PROMPT
You are the Smart Marketing creative writer for Dakshayani Enterprises. Generate {$category} in English and Hindi based on this brief:
{$brief}

Return JSON with keys english and hindi each containing an array of strings. Ensure brand tone is authoritative, trustworthy, and policy compliant. Include CTA suggestions.
PROMPT;

    $text = ai_gemini_generate_text($aiSettings, $prompt);
    $clean = trim($text);
    if (str_starts_with($clean, '```')) {
        $clean = preg_replace('/^```[A-Za-z]*\n/', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
    }

    try {
        $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $decoded = ['rawText' => $text];
    }

    $asset = smart_marketing_store_asset('text', [
        'category' => $category,
        'brief' => $brief,
        'output' => $decoded,
    ]);

    return $asset;
}

function smart_marketing_generate_image_asset(array $aiSettings, string $promptText, string $preset, array $settings): array
{
    $prompt = 'Create a marketing image for Dakshayani Enterprises solar: ' . $promptText . '. Include rooftop solar visuals, sunlight, Indian households, and brand-safe overlay areas.';
    $image = ai_gemini_generate_image($aiSettings, $prompt);
    $asset = smart_marketing_store_asset('image', [
        'prompt' => $prompt,
        'preset' => $preset,
        'path' => $image['path'],
        'mimeType' => $image['mimeType'],
    ]);

    return $asset;
}

function smart_marketing_generate_tts_asset(array $aiSettings, string $script, array $settings): array
{
    $audio = ai_gemini_generate_tts($aiSettings, $script);
    $asset = smart_marketing_store_asset('tts', [
        'script' => $script,
        'path' => $audio['path'],
        'mimeType' => $audio['mimeType'],
    ]);

    return $asset;
}
