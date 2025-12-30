<?php
declare(strict_types=1);

function ai_storage_dir(): string
{
    $base = __DIR__ . '/../storage/ai';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $base;
}

function ai_settings_file(): string
{
    return ai_storage_dir() . '/settings.json';
}

function ai_settings_lock_file(): string
{
    return ai_storage_dir() . '/settings.lock';
}

function ai_settings_defaults(): array
{
    return [
        'enabled' => false,
        'api_key' => '',
        'models' => [
            'text' => 'gemini-2.5-flash',
            'image' => 'gemini-2.5-flash-image',
            'tts' => 'gemini-2.5-flash-preview-tts',
            'video' => '',
        ],
        'temperature' => 0.9,
        'max_tokens' => 1024,
        'updated_at' => null,
    ];
}

// -----------------------------------------------------------------------------
// Brand profile helpers
// -----------------------------------------------------------------------------

function ai_brand_profile_file(): string
{
    return ai_storage_dir() . '/brand_profile.json';
}

function ai_smart_marketing_brand_profile_path(): string
{
    return __DIR__ . '/../data/marketing/brand_profile.json';
}

function ai_smart_marketing_brand_profile_defaults(): array
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

function ai_smart_marketing_brand_profile_load(): array
{
    $path = ai_smart_marketing_brand_profile_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_smart_marketing_brand_profile_load: failed to decode json: ' . $exception->getMessage());
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $profile = array_merge(ai_smart_marketing_brand_profile_defaults(), $decoded);
    foreach ($profile as $key => $value) {
        if (is_string($value)) {
            $profile[$key] = trim((string) $value);
        }
    }

    return $profile;
}

function load_smart_marketing_brand_profile(): array
{
    return ai_smart_marketing_brand_profile_load();
}

function ai_smart_marketing_brand_profile_is_empty(array $profile): bool
{
    $keys = [
        'firm_name',
        'tagline',
        'primary_contact_number',
        'whatsapp_number',
        'email',
        'website_url',
        'facebook_page_url',
        'instagram_handle',
        'physical_address',
        'default_cta_line',
        'logo_path',
    ];

    foreach ($keys as $key) {
        if (isset($profile[$key]) && is_string($profile[$key]) && trim((string) $profile[$key]) !== '') {
            return false;
        }
    }

    return true;
}

function ai_smart_marketing_brand_profile_block(array $profile): string
{
    if (ai_smart_marketing_brand_profile_is_empty($profile)) {
        return '';
    }

    $lines = ['Brand Profile (use when relevant):'];
    $fields = [
        'firm_name' => 'Brand/Firm',
        'tagline' => 'Tagline',
        'default_cta_line' => 'Default CTA',
        'whatsapp_number' => 'WhatsApp',
        'primary_contact_number' => 'Phone',
        'email' => 'Email',
        'website_url' => 'Website',
        'physical_address' => 'Address',
        'facebook_page_url' => 'Facebook',
        'instagram_handle' => 'Instagram',
        'logo_path' => 'Logo',
    ];

    foreach ($fields as $key => $label) {
        $value = trim((string) ($profile[$key] ?? ''));
        if ($value !== '') {
            $lines[] = '- ' . $label . ': ' . $value;
        }
    }

    $lines[] = 'Instruction: If you produce marketing copy, include the default CTA and contact details appropriately.';

    return implode("\n", $lines);
}

function ai_smart_marketing_brand_profile_visual_block(array $profile): string
{
    if (ai_smart_marketing_brand_profile_is_empty($profile)) {
        return '';
    }

    $lines = ['Brand context (use only if relevant):'];
    $contactParts = [];

    $lineFields = [
        'Brand name' => 'firm_name',
        'Tagline' => 'tagline',
        'Default CTA text (for overlay if needed)' => 'default_cta_line',
        'Logo reference' => 'logo_path',
        'Facebook' => 'facebook_page_url',
        'Instagram' => 'instagram_handle',
    ];

    foreach ($lineFields as $label => $key) {
        $value = trim((string) ($profile[$key] ?? ''));
        if ($value !== '') {
            $lines[] = '- ' . $label . ': ' . $value;
        }
    }

    foreach ([
        'primary_contact_number' => 'Phone',
        'whatsapp_number' => 'WhatsApp',
        'email' => 'Email',
        'website_url' => 'Website',
    ] as $key => $label) {
        $value = trim((string) ($profile[$key] ?? ''));
        if ($value !== '') {
            $contactParts[] = $label . ': ' . $value;
        }
    }

    if (!empty($contactParts)) {
        $lines[] = '- Contact (for overlay if needed): ' . implode(', ', $contactParts);
    }

    $lines[] = 'Guideline: Only include logo/contact/CTA as text overlay if the user prompt implies an ad/poster/marketing creative; otherwise ignore. Keep brand placement subtle and natural.';

    return implode("\n", $lines);
}

function ai_brand_profile_defaults(): array
{
    return [
        'company_name' => '',
        'tagline' => '',
        'phone' => '',
        'whatsapp' => '',
        'email' => '',
        'website' => '',
        'address' => '',
        'cta' => '',
        'disclaimer' => '',
        'logo' => '',
        'logo_secondary' => '',
        'primary_color' => '',
        'secondary_color' => '',
        'social' => [
            'facebook' => '',
            'instagram' => '',
            'youtube' => '',
        ],
        'updated_at' => null,
    ];
}

function ai_brand_profile_load(): array
{
    $defaults = ai_brand_profile_defaults();
    $path = ai_brand_profile_file();
    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_brand_profile_load: failed to decode profile: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    $profile = array_replace_recursive($defaults, $decoded);
    foreach (['company_name', 'tagline', 'phone', 'whatsapp', 'email', 'website', 'address', 'cta', 'disclaimer', 'logo', 'logo_secondary', 'primary_color', 'secondary_color'] as $key) {
        $profile[$key] = is_string($profile[$key] ?? null) ? trim((string) $profile[$key]) : '';
    }
    if (!is_array($profile['social'] ?? null)) {
        $profile['social'] = ['facebook' => '', 'instagram' => '', 'youtube' => ''];
    }
    foreach (['facebook', 'instagram', 'youtube'] as $channel) {
        $profile['social'][$channel] = is_string($profile['social'][$channel] ?? null) ? trim((string) $profile['social'][$channel]) : '';
    }
    $profile['updated_at'] = is_string($profile['updated_at'] ?? null) ? $profile['updated_at'] : null;

    return $profile;
}

function ai_brand_profile_is_empty(array $profile): bool
{
    $keys = ['company_name', 'tagline', 'phone', 'whatsapp', 'email', 'website', 'address', 'cta', 'logo', 'logo_secondary'];
    foreach ($keys as $key) {
        if (isset($profile[$key]) && is_string($profile[$key]) && trim((string) $profile[$key]) !== '') {
            return false;
        }
    }

    foreach (['facebook', 'instagram', 'youtube'] as $channel) {
        if (isset($profile['social'][$channel]) && is_string($profile['social'][$channel]) && trim((string) $profile['social'][$channel]) !== '') {
            return false;
        }
    }

    return true;
}

function ai_brand_profile_store_upload(?array $file): string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return '';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmp);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mimeType])) {
        throw new RuntimeException('Only PNG, JPEG, or WebP logos are supported.');
    }

    $dir = ai_storage_dir() . '/brand';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = uniqid('brand_', true) . '.' . $allowed[$mimeType];
    $destination = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Unable to store uploaded logo.');
    }

    return 'storage/ai/brand/' . $name;
}

function ai_brand_profile_save(array $input): array
{
    $profile = ai_brand_profile_defaults();
    $profile = array_replace_recursive($profile, $input);
    foreach (['company_name', 'tagline', 'phone', 'whatsapp', 'email', 'website', 'address', 'cta', 'disclaimer', 'logo', 'logo_secondary', 'primary_color', 'secondary_color'] as $key) {
        $profile[$key] = is_string($profile[$key] ?? null) ? trim((string) $profile[$key]) : '';
    }
    if (!is_array($profile['social'] ?? null)) {
        $profile['social'] = ['facebook' => '', 'instagram' => '', 'youtube' => ''];
    }
    foreach (['facebook', 'instagram', 'youtube'] as $channel) {
        $profile['social'][$channel] = is_string($profile['social'][$channel] ?? null) ? trim((string) $profile['social'][$channel]) : '';
    }

    $profile['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);

    $payload = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode brand profile.');
    }

    if (file_put_contents(ai_brand_profile_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save brand profile.');
    }

    return $profile;
}

function ai_brand_profile_reset(): void
{
    $path = ai_brand_profile_file();
    if (is_file($path)) {
        unlink($path);
    }
}

function ai_brand_profile_snapshot(array $profile): array
{
    return [
        'company_name' => $profile['company_name'] ?? '',
        'tagline' => $profile['tagline'] ?? '',
        'phone' => $profile['phone'] ?? '',
        'whatsapp' => $profile['whatsapp'] ?? '',
        'email' => $profile['email'] ?? '',
        'website' => $profile['website'] ?? '',
        'cta' => $profile['cta'] ?? '',
        'logo' => $profile['logo'] ?? '',
    ];
}

function getBrandContextForAI(bool $requested = true): array
{
    $profile = ai_brand_profile_load();
    $cleanField = static function ($value): string {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    };

    $profile = array_map(static function ($value) use ($cleanField) {
        return is_string($value) ? $cleanField($value) : $value;
    }, $profile);

    $empty = ai_brand_profile_is_empty($profile);
    $useBrand = $requested && !$empty;

    $visualStructured = [];
    foreach ([
        'company_name' => 'company_name',
        'logo_path' => 'logo',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'email' => 'email',
        'website' => 'website',
        'address_short' => 'address',
        'cta_line' => 'cta',
    ] as $outputKey => $sourceKey) {
        $value = (string) ($profile[$sourceKey] ?? '');
        if ($value !== '') {
            if ($outputKey === 'address_short') {
                $parts = preg_split('/[,\n]/', $value);
                $value = trim((string) ($parts[0] ?? $value));
            }
            $visualStructured[$outputKey] = $value;
        }
    }

    $textContext = '';
    $visualContext = '';

    if ($useBrand) {
        $contactBits = [];
        foreach (['phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'website' => 'Website'] as $key => $label) {
            if (($profile[$key] ?? '') !== '') {
                $contactBits[] = $label . ': ' . $profile[$key];
            }
        }

        $textLines = ['Brand Profile Context:'];
        if (($profile['company_name'] ?? '') !== '') {
            $textLines[] = '• Company name: ' . $profile['company_name'];
        }
        if (($profile['tagline'] ?? '') !== '') {
            $textLines[] = '• Tagline: ' . $profile['tagline'];
        }
        if (!empty($contactBits)) {
            $textLines[] = '• Contact: ' . implode(' | ', $contactBits);
        }
        if (($profile['address'] ?? '') !== '') {
            $textLines[] = '• Address: ' . $profile['address'];
        }
        if (($profile['cta'] ?? '') !== '') {
            $textLines[] = '• CTA: ' . $profile['cta'];
        }
        $colorLine = [];
        if (($profile['primary_color'] ?? '') !== '') {
            $colorLine[] = 'Primary colour: ' . $profile['primary_color'];
        }
        if (($profile['secondary_color'] ?? '') !== '') {
            $colorLine[] = 'Secondary colour: ' . $profile['secondary_color'];
        }
        if (!empty($colorLine)) {
            $textLines[] = '• Brand colours: ' . implode(' | ', $colorLine);
        }
        if (($profile['disclaimer'] ?? '') !== '') {
            $textLines[] = '• Disclaimer: ' . $profile['disclaimer'];
        }
        $textLines[] = 'Use correct spelling of company name, phone number, email, and website exactly as provided.';
        $textContext = trim(implode("\n", array_filter($textLines)));

        $visualLines = ['Brand Visual Instructions:'];
        if (($profile['logo'] ?? '') !== '') {
            $visualLines[] = '• Logo path: ' . $profile['logo'];
        }
        if (($profile['logo_secondary'] ?? '') !== '') {
            $visualLines[] = '• Secondary logo path: ' . $profile['logo_secondary'];
        }
        $visualLines[] = '• Placement: Integrate the logo as part of the design, with proper blending, not pasted on top.';
        $visualLines[] = '• Style: Use brand colors subtly if possible. CTA should appear cleanly in bottom-right or bottom-center.';
        $visualLines[] = '• Footer: Include company phone/WhatsApp and website on a clean footer bar.';
        $visualLines[] = '• Quality: Place the company logo naturally integrated into the visual — blended into design, matching perspective, shadow, and lighting. Ensure crisp logo edges, realistic placement, correct aspect ratio, and avoid excessive glow or harsh strokes. Never output placeholders like "[Clear space for logo]".';
        $visualLines[] = '• Spelling: Ensure spelling of company name and contact details is 100% accurate.';
        $visualContext = trim(implode("\n", array_filter($visualLines)));
    }

    return [
        'profile' => $profile,
        'is_empty' => $empty,
        'use_brand_profile' => $useBrand,
        'text_context' => $textContext,
        'visual_context' => $visualContext,
        'visual_structured' => $visualStructured,
        'snapshot' => $useBrand ? ai_brand_profile_snapshot($profile) : null,
    ];
}

function ai_brand_profile_status(bool $requested): array
{
    $context = getBrandContextForAI($requested);

    return [
        'profile' => $context['profile'],
        'is_empty' => $context['is_empty'],
        'use_brand_profile' => $context['use_brand_profile'],
        'context' => $context['text_context'],
        'visual_context' => $context['visual_context'],
        'visual_structured' => $context['visual_structured'],
        'snapshot' => $context['snapshot'],
    ];
}

function ai_build_brand_identity_pack(array $brandProfile): string
{
    $fields = [
        'Company name' => 'company_name',
        'Primary contact' => 'phone',
        'WhatsApp' => 'whatsapp',
        'Email' => 'email',
        'Website' => 'website',
        'Logo path' => 'logo',
        'Tagline' => 'tagline',
        'CTA' => 'cta',
    ];

    $lines = ['BRAND IDENTITY PACK (STRICT — DO NOT MODIFY):'];
    foreach ($fields as $label => $key) {
        $value = trim((string) ($brandProfile[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    return implode("\n", $lines);
}

function getBrandFieldsForImage(string $type, array $brandProfile): array
{
    $baseFields = [
        'company_name',
        'logo',
    ];

    $templates = [
        'greeting_whatsapp_status' => ['phone', 'website'],
        'blog_feature' => ['website'],
        'greeting_social' => ['phone', 'whatsapp'],
        'social_creative' => ['phone', 'whatsapp'],
        'default' => ['phone', 'website', 'whatsapp', 'email'],
    ];

    $selected = $templates[$type] ?? $templates['default'];
    $fields = [];

    foreach (array_merge($baseFields, $selected) as $key) {
        $value = trim((string) ($brandProfile[$key] ?? ''));
        if ($value !== '') {
            $fields[$key] = $value;
        }
    }

    if (isset($brandProfile['tagline']) && trim((string) $brandProfile['tagline']) !== '') {
        $fields['tagline'] = trim((string) $brandProfile['tagline']);
    }

    if (isset($brandProfile['cta']) && trim((string) $brandProfile['cta']) !== '') {
        $fields['cta'] = trim((string) $brandProfile['cta']);
    }

    return $fields;
}

function ai_format_brand_details_for_image(array $fields): string
{
    $labelMap = [
        'company_name' => 'Company name',
        'logo' => 'Logo reference',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'website' => 'Website',
        'tagline' => 'Tagline',
        'cta' => 'CTA',
    ];

    $lines = ['USE THESE BRAND DETAILS IN THE IMAGE:'];
    foreach ($fields as $key => $value) {
        if (!isset($labelMap[$key])) {
            continue;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }
        $lines[] = $labelMap[$key] . ': ' . $trimmed;
    }

    return implode("\n", $lines);
}

function ai_image_branding_rules_block(): string
{
    return implode("\n", [
        'IMAGE BRANDING RULES:',
        '• Integrate branding text naturally into the artwork (not floating, not cheap-looking).',
        '• Preserve logo proportions and clarity.',
        '• Place company name and selected contact details inside the image as clean design elements.',
        '• Use brand colors if available.',
        '• Branding must look like professionally-designed marketing material.',
        '• Do not distort or alter the logo.',
        '• Do not change the spelling or formatting of any branding text.',
    ]);
}

function ai_image_branding_safety_block(): string
{
    return implode("\n", [
        'VERY IMPORTANT — STRICT ENFORCEMENT:',
        '• Use the EXACT company name, phone number, email, website, and branding details from the BRAND IDENTITY PACK exactly as written.',
        '• Do NOT change the company name.',
        '• Do NOT shorten, abbreviate, or rephrase the brand name.',
        '• Do NOT invent or guess any branding details.',
        '• Do NOT generate alternate brand names.',
        '• Do NOT modify phone numbers, emails, or websites.',
        '• Use these details verbatim with 0 changes.',
        '• If branding is added to the image, use ONLY the details from the BRAND IDENTITY PACK.',
        '• Never use any other name like “Dakshayani Solar”, “Dakshayani Power Solutions”, or any variation.',
        '• If unsure, do NOT generate substitutes — ONLY use the provided text.',
    ]);
}

function ai_image_branding_blocking_rule(): string
{
    return implode("\n", [
        'ABSOLUTE BLOCKING RULE:',
        'If you (Gemini) are about to generate branding text inside the image:',
        '• Use ONLY the text from the BRAND IDENTITY PACK.',
        '• If any branding detail is missing from the pack, DO NOT invent it.',
        '• If a branding detail is present in the pack, use it EXACTLY as it is.',
        '• If your model attempts to generate alternative company names or phone numbers — you MUST STOP and instead use ONLY the provided values from the BRAND IDENTITY PACK.',
    ]);
}

function ai_apply_brand_identity_to_image_prompt(string $prompt, array $brandProfile, string $usage): string
{
    if (ai_brand_profile_is_empty($brandProfile)) {
        return $prompt;
    }

    $pack = ai_build_brand_identity_pack($brandProfile);
    $fields = ai_format_brand_details_for_image(getBrandFieldsForImage($usage, $brandProfile));
    $blocks = [
        $prompt,
        $pack,
        $fields,
        ai_image_branding_rules_block(),
        ai_image_branding_safety_block(),
        ai_image_branding_blocking_rule(),
    ];

    return implode("\n\n", array_filter($blocks));
}

function selectBrandContactSubset(string $usage, array $brandProfile): array
{
    $available = array_filter([
        'phone' => $brandProfile['phone'] ?? '',
        'whatsapp' => $brandProfile['whatsapp'] ?? '',
        'email' => $brandProfile['email'] ?? '',
        'website' => $brandProfile['website'] ?? '',
    ], static fn($value) => (string) $value !== '');

    if (empty($available)) {
        return [];
    }

    $templates = [
        'greeting_whatsapp_status' => [
            ['phone', 'website'],
            ['whatsapp', 'website'],
            ['phone', 'whatsapp'],
            ['website'],
            ['phone'],
        ],
        'blog_feature' => [
            ['website'],
            ['phone', 'website'],
            ['phone'],
        ],
        'greeting_social' => [
            ['phone', 'whatsapp', 'website'],
            ['phone', 'website'],
            ['phone', 'whatsapp'],
            ['website'],
            ['phone'],
        ],
        'default' => [
            ['phone', 'website'],
            ['whatsapp', 'website'],
            ['phone', 'whatsapp'],
            ['website'],
            ['phone'],
        ],
    ];

    $candidates = $templates[$usage] ?? $templates['default'];
    foreach ($candidates as $fields) {
        $present = array_values(array_filter($fields, static fn($field) => isset($available[$field])));
        if (!empty($present)) {
            return $present;
        }
    }

    return [array_key_first($available)];
}

function ai_build_brand_visual_instructions(array $visualStructured, string $usage): string
{
    if (empty($visualStructured)) {
        return '';
    }

    $lines = [];
    $companyName = $visualStructured['company_name'] ?? '';
    $logoPath = $visualStructured['logo_path'] ?? '';

    if ($companyName !== '' || $logoPath !== '') {
        $lines[] = 'Include the company logo and complete company name clearly in the design. Integrate them professionally into the composition so they look part of the artwork, not pasted on top. Keep the logo’s original proportions.';
        $lines[] = 'Ensure the logo is visible and not cropped or omitted.';
    }

    $contactFields = selectBrandContactSubset($usage, $visualStructured);
    if (!empty($contactFields)) {
        $contactParts = [];
        foreach ($contactFields as $field) {
            if (($visualStructured[$field] ?? '') !== '') {
                $label = ucfirst($field);
                $contactParts[] = $label . ': ' . $visualStructured[$field];
            }
        }

        if (!empty($contactParts)) {
            $lines[] = 'Add a clean contact strip showing: ' . implode(' | ', $contactParts) . '.';
        }
    }

    if (isset($visualStructured['website']) && empty($contactFields)) {
        $lines[] = 'Include a subtle footer with the company website: ' . $visualStructured['website'] . '.';
    }

    $lines[] = 'Use the exact spelling of the company name, phone number, WhatsApp number, email address, and website as provided. Do not invent or change numbers or domains. Never replace or omit these brand elements.';

    return implode(' ', $lines);
}

function ai_clean_text_output(string $text, array $brandProfile = []): string
{
    $clean = str_replace(['[Clear space for Company Logo]', 'Clear space for Company Logo'], '', $text);
    $clean = preg_replace('/\*+/', '', $clean);
    $clean = str_replace(['[]', '()'], '', $clean);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $lines = [];
    $seen = [];
    foreach (preg_split('/\r?\n/', (string) $clean) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $hash = strtolower($line);
        if (isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        $lines[] = $line;
    }

    $cleaned = trim(implode("\n", $lines));

    foreach (['company_name', 'phone', 'whatsapp', 'email', 'website'] as $key) {
        if (isset($brandProfile[$key]) && $brandProfile[$key] !== '') {
            $cleaned = preg_replace('/' . preg_quote((string) $brandProfile[$key], '/') . '/i', (string) $brandProfile[$key], $cleaned);
        }
    }

    return rtrim($cleaned, " \t\n\r\0\x0B,.;");
}

function ai_clean_paragraphs(array $paragraphs, array $brandProfile = []): array
{
    $cleaned = [];
    foreach ($paragraphs as $paragraph) {
        $clean = ai_clean_text_output((string) $paragraph, $brandProfile);
        if ($clean !== '') {
            $cleaned[] = $clean;
        }
    }

    $unique = [];
    $result = [];
    foreach ($cleaned as $paragraph) {
        $hash = strtolower($paragraph);
        if (isset($unique[$hash])) {
            continue;
        }
        $unique[$hash] = true;
        $result[] = $paragraph;
    }

    return $result;
}

function ai_blog_draft_dir(): string
{
    $path = ai_storage_dir() . '/blog_drafts';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    $relative = 'storage/ai/scheduler/generated/' . $fileName;

    return $relative;
}

function ai_blog_draft_path(int $adminId): string
{
    $file = $adminId > 0 ? 'draft_' . $adminId . '.json' : 'draft_default.json';
    return ai_blog_draft_dir() . '/' . $file;
}

function ai_blog_draft_load(int $adminId): array
{
    $path = ai_blog_draft_path($adminId);
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_blog_draft_load: failed to decode draft: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ai_blog_draft_save(int $adminId, array $draft): void
{
    $payload = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode blog draft payload.');
    }

    if (file_put_contents(ai_blog_draft_path($adminId), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist blog draft.');
    }
}

function ai_blog_draft_clear(int $adminId): void
{
    $path = ai_blog_draft_path($adminId);
    if (is_file($path)) {
        unlink($path);
    }
}

function ai_settings_masked_key(string $value): string
{
    if ($value === '') {
        return '';
    }

    $length = strlen($value);
    if ($length <= 4) {
        return str_repeat('•', $length);
    }

    return str_repeat('•', max(0, $length - 4)) . substr($value, -4);
}

function ai_settings_load(): array
{
    $defaults = ai_settings_defaults();
    $path = ai_settings_file();

    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_settings_load: failed to decode settings: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    $settings = array_replace_recursive($defaults, $decoded);

    $settings['enabled'] = (bool) ($settings['enabled'] ?? false);
    $settings['api_key'] = is_string($settings['api_key'] ?? null) ? trim((string) $settings['api_key']) : '';

    $models = is_array($settings['models'] ?? null) ? $settings['models'] : [];
    $settings['models'] = [
        'text' => ai_normalize_model_code($models['text'] ?? '', $defaults['models']['text']),
        'image' => ai_normalize_model_code($models['image'] ?? '', $defaults['models']['image']),
        'tts' => ai_normalize_model_code($models['tts'] ?? '', $defaults['models']['tts']),
        'video' => ai_normalize_model_code($models['video'] ?? '', ''),
    ];

    $settings['temperature'] = ai_normalize_temperature($settings['temperature'] ?? $defaults['temperature']);
    $settings['max_tokens'] = ai_normalize_max_tokens($settings['max_tokens'] ?? $defaults['max_tokens']);
    $settings['updated_at'] = is_string($settings['updated_at'] ?? null) ? $settings['updated_at'] : null;

    return $settings;
}

function ai_settings_save(array $settings): void
{
    $settings['temperature'] = ai_normalize_temperature($settings['temperature'] ?? 0.9);
    $settings['max_tokens'] = ai_normalize_max_tokens($settings['max_tokens'] ?? 1024);
    $settings['models'] = [
        'text' => ai_normalize_model_code($settings['models']['text'] ?? '', 'gemini-2.5-flash'),
        'image' => ai_normalize_model_code($settings['models']['image'] ?? '', 'gemini-2.5-flash-image'),
        'tts' => ai_normalize_model_code($settings['models']['tts'] ?? '', 'gemini-2.5-flash-preview-tts'),
        'video' => ai_normalize_model_code($settings['models']['video'] ?? '', ''),
    ];
    $settings['enabled'] = (bool) ($settings['enabled'] ?? false);
    $settings['api_key'] = is_string($settings['api_key'] ?? null) ? trim((string) $settings['api_key']) : '';

    $settings['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);

    $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode AI settings.');
    }

    $lockHandle = fopen(ai_settings_lock_file(), 'c+');
    if ($lockHandle === false) {
        throw new RuntimeException('Unable to open AI settings lock.');
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire AI settings lock.');
        }

        if (file_put_contents(ai_settings_file(), $payload, LOCK_EX) === false) {
            throw new RuntimeException('Failed to persist AI settings.');
        }

        fflush($lockHandle);
        flock($lockHandle, LOCK_UN);
    } finally {
        fclose($lockHandle);
    }
}

function ai_normalize_model_code(?string $value, string $fallback): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return $fallback;
    }

    $value = preg_replace('/[^A-Za-z0-9._\-]/', '', $value);
    if (!is_string($value) || $value === '') {
        return $fallback;
    }

    return $value;
}

function ai_normalize_temperature($value): float
{
    $number = is_numeric($value) ? (float) $value : 0.0;
    if (!is_finite($number)) {
        $number = 0.0;
    }
    $number = max(0.0, min(2.0, $number));
    return round($number, 2);
}

function ai_normalize_max_tokens($value): int
{
    $number = is_numeric($value) ? (int) $value : 0;
    if ($number <= 0) {
        $number = 1;
    }

    if ($number > 8192) {
        $number = 8192;
    }

    return $number;
}

function ai_collect_settings_from_request(array $current, array $input): array
{
    $updated = $current;

    $updated['enabled'] = isset($input['ai_enabled']) && (string) $input['ai_enabled'] === '1';

    $textModel = ai_normalize_model_code($input['gemini_text_model'] ?? '', $current['models']['text']);
    $imageModel = ai_normalize_model_code($input['gemini_image_model'] ?? '', $current['models']['image']);
    $ttsModel = ai_normalize_model_code($input['gemini_tts_model'] ?? '', $current['models']['tts']);
    $videoModel = ai_normalize_model_code($input['gemini_video_model'] ?? '', $current['models']['video'] ?? '');

    $updated['models']['text'] = $textModel;
    $updated['models']['image'] = $imageModel;
    $updated['models']['tts'] = $ttsModel;
    $updated['models']['video'] = $videoModel;

    if (array_key_exists('api_key', $input)) {
        $candidateKey = trim((string) $input['api_key']);
        if ($candidateKey !== '') {
            $updated['api_key'] = $candidateKey;
        }
    }

    $updated['temperature'] = ai_normalize_temperature($input['temperature'] ?? $current['temperature']);
    $updated['max_tokens'] = ai_normalize_max_tokens($input['max_tokens'] ?? $current['max_tokens']);

    return $updated;
}

function ai_gemini_ping(array $settings, string $prompt = 'Ping from Dakshayani AI Studio'): array
{
    try {
        $response = ai_gemini_generate($settings, [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]]);
        $text = ai_gemini_extract_text($response);
        if (trim($text) === '') {
            throw new RuntimeException('Empty response received from Gemini.');
        }

        return [
            'ok' => true,
            'response' => $text,
        ];
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'error' => $exception->getMessage(),
        ];
    }
}

function ai_gemini_generate(array $settings, array $contents, array $options = []): array
{
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    $model = ai_normalize_model_code($settings['models']['text'] ?? '', 'gemini-2.5-flash');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => ai_normalize_temperature($settings['temperature'] ?? 0.9),
            'maxOutputTokens' => ai_normalize_max_tokens($settings['max_tokens'] ?? 1024),
        ],
    ];

    $result = ai_http_json_post($url, $payload, [
        'Content-Type: application/json',
    ], $options);

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        $message = 'Gemini API error (' . $result['http_code'] . ')';
        if (is_array($result['body']) && isset($result['body']['error']['message'])) {
            $message .= ': ' . (string) $result['body']['error']['message'];
        }
        throw new RuntimeException($message);
    }

    if (!is_array($result['body'])) {
        throw new RuntimeException('Unexpected Gemini response.');
    }

    return $result['body'];
}

function ai_gemini_generate_text(array $settings, string $prompt, array $options = []): string
{
    $contents = [[
        'role' => 'user',
        'parts' => [['text' => $prompt]],
    ]];

    $response = ai_gemini_generate($settings, $contents, $options);
    $text = ai_gemini_extract_text($response);
    if ($text === '') {
        throw new RuntimeException('Gemini returned an empty response.');
    }

    ai_usage_register_text($prompt, $text, $settings['models']['text'] ?? '');

    return $text;
}

function ai_normalize_image_aspect_ratio(string $ratio): string
{
    $candidate = trim($ratio);
    $allowed = ['1:1', '4:5', '9:16', '16:9', '3:2'];
    if (in_array($candidate, $allowed, true)) {
        return $candidate;
    }

    return '1:1';
}

function ai_image_generation_candidates(string $aspectRatio): array
{
    $aspectRatio = ai_normalize_image_aspect_ratio($aspectRatio);
    $map = [
        '1:1' => [
            'dimensions' => ['width' => 1024, 'height' => 1024],
            'orientation' => 'square',
            'ratio' => '1:1',
            'fallbacks' => [],
        ],
        '4:5' => [
            'dimensions' => ['width' => 1024, 'height' => 1280],
            'orientation' => 'portrait',
            'ratio' => '4:5',
            'fallbacks' => [],
        ],
        '9:16' => [
            'dimensions' => ['width' => 1024, 'height' => 1792],
            'orientation' => 'portrait',
            'ratio' => '9:16',
            'fallbacks' => [
                ['width' => 1024, 'height' => 1820, 'ratio' => '9:16'],
                ['width' => 1024, 'height' => 1280, 'ratio' => '4:5'],
            ],
        ],
        '16:9' => [
            'dimensions' => ['width' => 1792, 'height' => 1024],
            'orientation' => 'landscape',
            'ratio' => '16:9',
            'fallbacks' => [
                ['width' => 1536, 'height' => 864, 'ratio' => '16:9'],
                ['width' => 1536, 'height' => 1024, 'ratio' => '3:2'],
            ],
        ],
        '3:2' => [
            'dimensions' => ['width' => 1536, 'height' => 1024],
            'orientation' => 'landscape',
            'ratio' => '3:2',
            'fallbacks' => [
                ['width' => 1536, 'height' => 864, 'ratio' => '16:9'],
            ],
        ],
    ];

    $orientationFallbacks = [
        'square' => [
            ['width' => 1024, 'height' => 1024, 'ratio' => '1:1'],
        ],
        'portrait' => [
            ['width' => 1024, 'height' => 1280, 'ratio' => '4:5'],
            ['width' => 1024, 'height' => 1792, 'ratio' => '9:16'],
        ],
        'landscape' => [
            ['width' => 1536, 'height' => 864, 'ratio' => '16:9'],
            ['width' => 1792, 'height' => 1024, 'ratio' => '16:9'],
            ['width' => 1536, 'height' => 1024, 'ratio' => '3:2'],
        ],
    ];

    $selected = $map[$aspectRatio] ?? $map['1:1'];
    $candidates = [[
        'width' => $selected['dimensions']['width'],
        'height' => $selected['dimensions']['height'],
        'ratio' => $selected['ratio'],
        'fallback' => false,
    ]];

    foreach ($selected['fallbacks'] as $fallback) {
        $candidates[] = [
            'width' => (int) $fallback['width'],
            'height' => (int) $fallback['height'],
            'ratio' => (string) ($fallback['ratio'] ?? $selected['ratio']),
            'fallback' => true,
        ];
    }

    foreach ($orientationFallbacks[$selected['orientation']] ?? [] as $fallback) {
        $alreadyIncluded = array_filter($candidates, static function (array $candidate) use ($fallback): bool {
            return (int) $candidate['width'] === (int) $fallback['width'] && (int) $candidate['height'] === (int) $fallback['height'];
        });
        if (!empty($alreadyIncluded)) {
            continue;
        }
        $candidates[] = [
            'width' => (int) $fallback['width'],
            'height' => (int) $fallback['height'],
            'ratio' => (string) ($fallback['ratio'] ?? $selected['ratio']),
            'fallback' => true,
        ];
    }

    $candidates[] = [
        'width' => 0,
        'height' => 0,
        'ratio' => $selected['ratio'],
        'fallback' => true,
    ];

    return $candidates;
}

function ai_is_image_size_error(string $message): bool
{
    $haystack = strtolower($message);
    foreach (['size', 'dimension', 'resolution', 'height', 'width'] as $needle) {
        if (strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function ai_gemini_generate_image_binary(array $settings, string $prompt, array $options = []): array
{
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    $model = ai_normalize_model_code($settings['models']['image'] ?? '', 'gemini-2.5-flash-image');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
        'generationConfig' => [
            'temperature' => 0.8,
        ],
    ];

    $dimensions = null;
    if (isset($options['dimensions']) && is_array($options['dimensions'])) {
        $width = (int) ($options['dimensions']['width'] ?? 0);
        $height = (int) ($options['dimensions']['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            $dimensions = ['width' => $width, 'height' => $height];
        }
    }

    if ($dimensions !== null) {
        $payload['generationConfig']['responseMimeType'] = 'image/png';
        $payload['generationConfig']['outputImageDimensions'] = $dimensions;
    }

    $response = ai_http_json_post($url, $payload, ['Content-Type: application/json']);
    if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
        $message = 'Gemini image generation failed (' . $response['http_code'] . ')';
        if (is_array($response['body']) && isset($response['body']['error']['message'])) {
            $message .= ': ' . (string) $response['body']['error']['message'];
        }
        throw new RuntimeException($message);
    }

    if (!is_array($response['body'])) {
        throw new RuntimeException('Unexpected Gemini image response.');
    }

    $media = ai_gemini_extract_inline_data($response['body'], 'image/');
    if ($media === null) {
        throw new RuntimeException('Gemini did not return an image.');
    }

    $binary = base64_decode((string) ($media['data'] ?? ''), true);
    if ($binary === false) {
        throw new RuntimeException('Failed to decode Gemini image payload.');
    }

    ai_usage_register_image(['model' => $model]);

    return [
        'binary' => $binary,
        'mimeType' => (string) ($media['mimeType'] ?? ''),
    ];
}

function ai_gemini_generate_image(array $settings, string $prompt, array $options = []): array
{
    $result = ai_gemini_generate_image_binary($settings, $prompt, $options);
    $path = ai_gemini_store_binary(base64_encode($result['binary']), $result['mimeType'], 'generated_images');

    $image = [
        'path' => $path,
        'mimeType' => $result['mimeType'],
    ];

    if (isset($options['dimensions']) && is_array($options['dimensions'])) {
        $width = (int) ($options['dimensions']['width'] ?? 0);
        $height = (int) ($options['dimensions']['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            $image['dimensions'] = ['width' => $width, 'height' => $height];
        }
    }
    if (isset($options['aspect_ratio']) && is_string($options['aspect_ratio'])) {
        $image['aspect_ratio'] = $options['aspect_ratio'];
    }

    return $image;
}

function ai_gemini_generate_tts(array $settings, string $text, string $format = 'mp3'): array
{
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    $model = ai_normalize_model_code($settings['models']['tts'] ?? '', 'gemini-2.5-flash-preview-tts');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $responseMime = strtolower($format) === 'wav' ? 'audio/wav' : 'audio/mpeg';

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [[
                'text' => $text,
            ]],
        ]],
        'generationConfig' => [
            'responseMimeType' => $responseMime,
        ],
    ];

    $response = ai_http_json_post($url, $payload, ['Content-Type: application/json']);
    if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
        $message = 'Gemini TTS failed (' . $response['http_code'] . ')';
        if (is_array($response['body']) && isset($response['body']['error']['message'])) {
            $message .= ': ' . (string) $response['body']['error']['message'];
        }
        throw new RuntimeException($message);
    }

    if (!is_array($response['body'])) {
        throw new RuntimeException('Unexpected Gemini TTS response.');
    }

    $media = ai_gemini_extract_inline_data($response['body'], 'audio/');
    if ($media === null) {
        throw new RuntimeException('Gemini did not return audio.');
    }

    $path = ai_gemini_store_binary($media['data'], $media['mimeType'], 'generated_audio');

    return [
        'path' => $path,
        'mimeType' => $media['mimeType'],
    ];
}

function ai_gemini_validate_video_model(array $settings): array
{
    $code = ai_normalize_model_code($settings['models']['video'] ?? '', '');
    if ($code === '') {
        return [
            'configured' => false,
            'ok' => false,
            'message' => 'Video model not configured (storyboard fallback).',
        ];
    }

    try {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Gemini API key missing.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($code) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => 'Quick capability check for short festive video ideas']],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 32,
            ],
        ];

        $response = ai_http_json_post($url, $payload, ['Content-Type: application/json']);
        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            throw new RuntimeException('HTTP ' . $response['http_code']);
        }

        return [
            'configured' => true,
            'ok' => true,
            'message' => 'Video Model OK',
        ];
    } catch (Throwable $exception) {
        return [
            'configured' => true,
            'ok' => false,
            'message' => 'Video Model Error – falling back to storyboard.',
            'error' => $exception->getMessage(),
        ];
    }
}

function ai_gemini_generate_video(array $settings, string $prompt): array
{
    $apiKey = trim((string) ($settings['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    $model = ai_normalize_model_code($settings['models']['video'] ?? '', '');
    if ($model === '') {
        throw new RuntimeException('Video model not configured.');
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature' => 0.6,
            'responseMimeType' => 'video/mp4',
        ],
    ];

    $response = ai_http_json_post($url, $payload, ['Content-Type: application/json']);
    if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
        $message = 'Gemini video generation failed (' . $response['http_code'] . ')';
        if (is_array($response['body']) && isset($response['body']['error']['message'])) {
            $message .= ': ' . (string) $response['body']['error']['message'];
        }
        throw new RuntimeException($message);
    }

    if (!is_array($response['body'])) {
        throw new RuntimeException('Unexpected Gemini video response.');
    }

    $media = ai_gemini_extract_inline_data($response['body'], 'video/');
    if ($media === null) {
        throw new RuntimeException('Gemini did not return a video payload.');
    }

    $path = ai_gemini_store_binary($media['data'], $media['mimeType'], 'generated_videos');

    return [
        'path' => $path,
        'mimeType' => $media['mimeType'],
    ];
}

function ai_gemini_extract_inline_data(array $response, string $expectedPrefix): ?array
{
    if (isset($response['candidates']) && is_array($response['candidates'])) {
        foreach ($response['candidates'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;
            if (!is_array($content) || !isset($content['parts']) || !is_array($content['parts'])) {
                continue;
            }

            foreach ($content['parts'] as $part) {
                if (!is_array($part)) {
                    continue;
                }

                $inlineData = $part['inlineData'] ?? null;
                if (!is_array($inlineData)) {
                    continue;
                }

                $mimeType = (string) ($inlineData['mimeType'] ?? '');
                $data = (string) ($inlineData['data'] ?? '');

                if ($mimeType !== '' && $data !== '' && str_starts_with($mimeType, $expectedPrefix)) {
                    return [
                        'mimeType' => $mimeType,
                        'data' => $data,
                    ];
                }
            }
        }
    }

    return null;
}

function ai_gemini_store_binary(string $base64, string $mimeType, string $folder): string
{
    $binary = base64_decode($base64, true);
    if ($binary === false) {
        throw new RuntimeException('Failed to decode Gemini media payload.');
    }

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/mp3' => 'mp3',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mp4',
    ];
    $extension = $extensions[strtolower($mimeType)] ?? 'bin';

    $dir = ai_storage_dir() . '/' . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fileName = uniqid('gemini_', true) . '.' . $extension;
    $path = $dir . '/' . $fileName;

    if (file_put_contents($path, $binary) === false) {
        throw new RuntimeException('Unable to store Gemini media output.');
    }

    $relative = 'storage/ai/' . $folder . '/' . $fileName;
    return $relative;
}

function ai_http_json_post(string $url, array $payload, array $headers = [], array $options = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Failed to encode Gemini request payload.');
    }

    $responseBody = null;
    $httpCode = 0;
    $timeout = isset($options['timeout']) ? (float) $options['timeout'] : 20.0;
    $retries = isset($options['retries']) ? (int) $options['retries'] : 0;
    $retryDelay = isset($options['retry_delay']) ? (float) $options['retry_delay'] : 1.0;

    $attempt = 0;

    $executeRequest = static function () use ($url, $headers, $body, $timeout): array {
        $responseBody = null;
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => $timeout,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('Gemini request failed: ' . $error);
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $responseBody = $response;
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", array_merge($headers, [
                        'Content-Length: ' . strlen($body),
                    ])),
                    'content' => $body,
                    'timeout' => $timeout,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $error = error_get_last();
                throw new RuntimeException('Gemini request failed: ' . ($error['message'] ?? 'connection error'));
            }

            $responseBody = $response;
            $httpCode = 200;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $headerLine) {
                    if (preg_match('/^HTTP\/(?:1\.1|2)\s+(\d{3})/', $headerLine, $matches)) {
                        $httpCode = (int) $matches[1];
                        break;
                    }
                }
            }
        }

        return [$responseBody, $httpCode];
    };

    while (true) {
        try {
            [$responseBody, $httpCode] = $executeRequest();
            break;
        } catch (RuntimeException $exception) {
            $attempt++;
            $message = $exception->getMessage();
            $isTimeout = stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false;
            $networkIssue = stripos($message, 'connection') !== false || stripos($message, 'resolve host') !== false;
            if ($attempt > $retries || (!$isTimeout && !$networkIssue)) {
                throw $exception;
            }
            usleep((int) ($retryDelay * 1_000_000));
        }
    }

    $decoded = null;
    if (is_string($responseBody) && trim($responseBody) !== '') {
        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $decoded = null;
        }
    }

    return [
        'http_code' => $httpCode,
        'body' => $decoded,
        'raw' => $responseBody,
    ];
}

function ai_gemini_extract_text(array $response): string
{
    if (isset($response['candidates']) && is_array($response['candidates'])) {
        foreach ($response['candidates'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;
            if (!is_array($content) || !isset($content['parts']) || !is_array($content['parts'])) {
                continue;
            }

            foreach ($content['parts'] as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text = trim($part['text']);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
    }

    if (isset($response['text']) && is_string($response['text'])) {
        return trim($response['text']);
    }

    return '';
}

function ai_chat_history_path(int $userId): string
{
    $fileName = $userId > 0 ? 'chat_' . $userId . '.json' : 'chat_default.json';
    return ai_storage_dir() . '/' . $fileName;
}

function ai_chat_history_load(int $userId): array
{
    $path = ai_chat_history_path($userId);
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_chat_history_load: failed to decode history: ' . $exception->getMessage());
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $role = $entry['role'] ?? '';
        $text = is_string($entry['text'] ?? null) ? $entry['text'] : '';
        $timestamp = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null;

        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $result[] = [
            'role' => $role,
            'text' => $text,
            'timestamp' => $timestamp,
        ];
    }

    return $result;
}

function ai_chat_history_save(int $userId, array $history): void
{
    $normalized = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $role = $entry['role'] ?? '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $normalized[] = [
            'role' => $role,
            'text' => (string) ($entry['text'] ?? ''),
            'timestamp' => is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null,
        ];
    }

    $payload = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode chat history.');
    }

    if (file_put_contents(ai_chat_history_path($userId), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write chat history.');
    }
}

function ai_chat_history_append(int $userId, array $entry): array
{
    $history = ai_chat_history_load($userId);
    $history[] = [
        'role' => in_array($entry['role'] ?? '', ['user', 'assistant'], true) ? $entry['role'] : 'user',
        'text' => (string) ($entry['text'] ?? ''),
        'timestamp' => ai_timestamp(),
    ];

    $history = ai_chat_history_trim($history, 40);
    ai_chat_history_save($userId, $history);

    return $history;
}

function ai_chat_history_replace(int $userId, array $history): array
{
    $history = ai_chat_history_trim($history, 40);
    ai_chat_history_save($userId, $history);
    return $history;
}

function ai_chat_history_clear(int $userId): void
{
    $path = ai_chat_history_path($userId);
    if (is_file($path)) {
        unlink($path);
    }
}

function ai_chat_history_trim(array $history, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    if (count($history) <= $limit) {
        return $history;
    }

    return array_slice($history, -$limit);
}

function ai_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);
}

function ai_convert_history_to_contents(array $history): array
{
    $contents = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $role = $entry['role'] ?? '';
        $text = (string) ($entry['text'] ?? '');
        if ($text === '') {
            continue;
        }

        $contents[] = [
            'role' => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $text]],
        ];
    }

    return $contents;
}

function ai_chat_history_export_pdf(int $userId, string $adminName = 'Administrator'): string
{
    $history = ai_chat_history_load($userId);
    $lines = [];
    $title = 'AI Chat Transcript';

    foreach ($history as $entry) {
        $role = $entry['role'] === 'assistant' ? 'Gemini' : $adminName;
        $timestamp = $entry['timestamp'] ?? '';
        $displayTime = '';
        if ($timestamp !== '') {
            try {
                $dt = new DateTimeImmutable($timestamp);
                $displayTime = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
            } catch (Throwable $exception) {
                $displayTime = $timestamp;
            }
        }
        $prefix = $displayTime !== '' ? sprintf('%s (%s)', $role, $displayTime) : $role;
        $text = preg_replace('/\s+/u', ' ', (string) ($entry['text'] ?? ''));
        $lines[] = trim($prefix . ': ' . $text);
    }

    if (empty($lines)) {
        $lines[] = 'No chat messages recorded yet.';
    }

    return ai_render_simple_pdf($title, $lines);
}

function ai_render_simple_pdf(string $title, array $lines): string
{
    $contentLines = [];
    $contentLines[] = 'BT';
    $contentLines[] = '/F1 16 Tf';
    $contentLines[] = '48 760 Td';
    $contentLines[] = '(' . ai_pdf_escape($title) . ') Tj';
    $contentLines[] = '0 -28 Td';
    $contentLines[] = '/F1 11 Tf';

    foreach ($lines as $line) {
        $wrapped = ai_pdf_wrap_text($line, 90);
        foreach ($wrapped as $index => $segment) {
            if ($index > 0) {
                $contentLines[] = '0 -14 Td';
            }
            $contentLines[] = '(' . ai_pdf_escape($segment) . ') Tj';
        }
        $contentLines[] = '0 -18 Td';
    }

    $contentLines[] = 'ET';

    $stream = implode("\n", $contentLines);
    $length = strlen($stream);

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
    $objects[] = "<< /Length $length >>\nstream\n$stream\nendstream";
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $offsets = [];
    $buffer = "%PDF-1.4\n";
    foreach ($objects as $index => $object) {
        $offsets[$index + 1] = strlen($buffer);
        $buffer .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefPosition = strlen($buffer);
    $buffer .= "xref\n0 " . (count($objects) + 1) . "\n";
    $buffer .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $buffer .= sprintf('%010d 00000 n %s', $offsets[$i], "\n");
    }

    $buffer .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPosition . "\n%%EOF";

    return $buffer;
}

function ai_pdf_escape(string $value): string
{
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    return preg_replace('/[\r\n]+/', ' ', $escaped) ?? $escaped;
}

function ai_pdf_wrap_text(string $text, int $maxLength): array
{
    $text = trim($text);
    if ($text === '') {
        return [''];
    }

    $words = preg_split('/\s+/u', $text);
    if (!is_array($words)) {
        return [$text];
    }

    $lines = [];
    $current = '';
    foreach ($words as $word) {
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (mb_strlen($candidate) > $maxLength) {
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        } else {
            $current = $candidate;
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

function ai_scheduler_dir(): string
{
    $path = ai_storage_dir() . '/scheduler';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    return $path;
}

function ai_scheduler_settings_path(): string
{
    return ai_scheduler_dir() . '/settings.json';
}

function ai_scheduler_logs_path(): string
{
    return ai_scheduler_dir() . '/logs.json';
}

function ai_scheduler_generated_dir(): string
{
    $path = ai_scheduler_dir() . '/generated';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    return $path;
}

function ai_scheduler_default_timezone(): string
{
    return 'Asia/Kolkata';
}

function ai_scheduler_generate_id(): string
{
    return 'auto_' . substr(bin2hex(random_bytes(10)), 0, 18);
}

function ai_scheduler_normalize_status(?string $status): string
{
    $allowed = ['active', 'paused', 'completed'];
    $candidate = strtolower(trim((string) $status));

    return in_array($candidate, $allowed, true) ? $candidate : 'active';
}

function ai_scheduler_normalize_schedule_type(?string $type): string
{
    $allowed = ['once', 'recurring'];
    $candidate = strtolower(trim((string) $type));

    return in_array($candidate, $allowed, true) ? $candidate : 'once';
}

function ai_scheduler_normalize_time(string $time): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches)) {
        return '09:00';
    }

    $hour = max(0, min(23, (int) $matches[1]));
    $minute = max(0, min(59, (int) $matches[2]));

    return sprintf('%02d:%02d', $hour, $minute);
}

function ai_scheduler_normalize_date(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date))) {
        $now = new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
        return $now->format('Y-m-d');
    }

    return $date;
}

function ai_scheduler_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'item-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    return $slug;
}

function ai_scheduler_settings_defaults(): array
{
    return [
        'timezone' => ai_scheduler_default_timezone(),
        'automations' => [],
    ];
}

function ai_scheduler_normalize_frequency(string $value): string
{
    $candidate = strtolower(trim($value));
    $allowed = ['daily', 'weekly', 'monthly'];

    return in_array($candidate, $allowed, true) ? $candidate : 'weekly';
}

function ai_scheduler_settings_load(): array
{
    $defaults = ai_scheduler_settings_defaults();
    $path = ai_scheduler_settings_path();

    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_scheduler_settings_load: failed to decode settings: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    $settings = array_merge($defaults, $decoded);
    $settings['timezone'] = is_string($settings['timezone'] ?? null) && $settings['timezone'] !== ''
        ? $settings['timezone']
        : ai_scheduler_default_timezone();
    $automations = [];
    if (is_array($settings['automations'])) {
        foreach ($settings['automations'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $automations[] = ai_scheduler_normalize_entry($entry);
        }
    }
    $settings['automations'] = $automations;

    return $settings;
}

function ai_scheduler_increment(DateTimeImmutable $reference, string $frequency): DateTimeImmutable
{
    $frequency = ai_scheduler_normalize_frequency($frequency);

    switch ($frequency) {
        case 'daily':
            return $reference->modify('+1 day');
        case 'monthly':
            return $reference->modify('+1 month');
        case 'weekly':
        default:
            return $reference->modify('+7 days');
    }
}

function ai_scheduler_calculate_next_run_for_entry(array $entry, ?DateTimeImmutable $now = null, ?DateTimeZone $timezone = null): ?DateTimeImmutable
{
    $status = ai_scheduler_normalize_status($entry['status'] ?? 'active');
    if ($status !== 'active') {
        return null;
    }

    $schedule = is_array($entry['schedule'] ?? null) ? $entry['schedule'] : [];
    $type = ai_scheduler_normalize_schedule_type($schedule['type'] ?? 'once');
    $timezone = $timezone ?? new DateTimeZone($entry['timezone'] ?? ai_scheduler_default_timezone());
    $now = $now ?? new DateTimeImmutable('now', $timezone);

    $date = ai_scheduler_normalize_date((string) ($schedule['date'] ?? ''));
    $time = ai_scheduler_normalize_time((string) ($schedule['time'] ?? '09:00'));

    try {
        $scheduled = new DateTimeImmutable($date . ' ' . $time, $timezone);
    } catch (Throwable $exception) {
        $scheduled = $now;
    }

    if ($type === 'once') {
        if ($entry['last_run'] !== null) {
            return null;
        }

        return $scheduled;
    }

    $frequency = ai_scheduler_normalize_frequency((string) ($schedule['frequency'] ?? 'weekly'));
    $candidate = $scheduled;

    if (is_string($entry['last_run']) && $entry['last_run'] !== '') {
        try {
            $lastRun = new DateTimeImmutable($entry['last_run']);
            $candidate = ai_scheduler_increment($lastRun, $frequency);
        } catch (Throwable $exception) {
            $candidate = $scheduled;
        }
    }

    if ($candidate <= $now) {
        while ($candidate <= $now) {
            $candidate = ai_scheduler_increment($candidate, $frequency);
        }
    }

    return $candidate;
}

function ai_scheduler_normalize_entry(array $entry): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
    $defaults = [
        'id' => '',
        'title' => '',
        'description' => '',
        'topic' => '',
        'status' => 'active',
        'timezone' => ai_scheduler_default_timezone(),
        'schedule' => [
            'type' => 'once',
            'date' => $now->format('Y-m-d'),
            'time' => '09:00',
            'frequency' => 'weekly',
        ],
        'last_run' => null,
        'next_run' => null,
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'updated_at' => $now->format(DateTimeInterface::ATOM),
        'blog_reference' => [],
        'festival' => null,
    ];

    $normalised = array_merge($defaults, $entry);
    $normalised['title'] = trim((string) $normalised['title']);
    $normalised['description'] = trim((string) $normalised['description']);
    $normalised['topic'] = trim((string) $normalised['topic']);
    $normalised['status'] = ai_scheduler_normalize_status($normalised['status']);
    $normalised['timezone'] = is_string($normalised['timezone']) && $normalised['timezone'] !== ''
        ? $normalised['timezone']
        : ai_scheduler_default_timezone();

    try {
        $timezone = new DateTimeZone($normalised['timezone']);
    } catch (Throwable $exception) {
        $timezone = new DateTimeZone(ai_scheduler_default_timezone());
        $normalised['timezone'] = ai_scheduler_default_timezone();
    }

    if (!is_array($normalised['schedule'])) {
        $normalised['schedule'] = $defaults['schedule'];
    }

    $schedule = $normalised['schedule'];
    $schedule['type'] = ai_scheduler_normalize_schedule_type($schedule['type'] ?? 'once');
    $schedule['date'] = ai_scheduler_normalize_date((string) ($schedule['date'] ?? ''));
    $schedule['time'] = ai_scheduler_normalize_time((string) ($schedule['time'] ?? '09:00'));
    $schedule['frequency'] = ai_scheduler_normalize_frequency((string) ($schedule['frequency'] ?? 'weekly'));
    $normalised['schedule'] = $schedule;

    if (!is_string($normalised['created_at']) || $normalised['created_at'] === '') {
        $normalised['created_at'] = $now->format(DateTimeInterface::ATOM);
    }

    if (!is_string($normalised['updated_at']) || $normalised['updated_at'] === '') {
        $normalised['updated_at'] = $normalised['created_at'];
    }

    if (!is_string($normalised['last_run']) || trim($normalised['last_run']) === '') {
        $normalised['last_run'] = null;
    }

    $normalised['blog_reference'] = is_array($normalised['blog_reference']) ? $normalised['blog_reference'] : [];
    $normalised['festival'] = is_array($normalised['festival']) ? $normalised['festival'] : null;

    if (!is_string($normalised['id']) || $normalised['id'] === '') {
        $normalised['id'] = ai_scheduler_generate_id();
    }

    $nextRun = ai_scheduler_calculate_next_run_for_entry($normalised, $now, $timezone);
    $normalised['next_run'] = $nextRun ? $nextRun->format(DateTimeInterface::ATOM) : null;

    return $normalised;
}

function ai_scheduler_settings_save(array $settings): array
{
    $defaults = ai_scheduler_settings_defaults();
    $merged = array_merge($defaults, $settings);
    $merged['timezone'] = is_string($merged['timezone']) && $merged['timezone'] !== ''
        ? $merged['timezone']
        : ai_scheduler_default_timezone();

    $automations = [];
    if (is_array($merged['automations'])) {
        foreach ($merged['automations'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $automations[] = ai_scheduler_normalize_entry($entry);
        }
    }

    usort($automations, static function (array $a, array $b): int {
        $left = $a['next_run'] ?? '';
        $right = $b['next_run'] ?? '';
        return strcmp($left, $right);
    });

    $merged['automations'] = $automations;

    $payload = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode scheduler settings.');
    }

    if (file_put_contents(ai_scheduler_settings_path(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist scheduler settings.');
    }

    return $merged;
}

function ai_scheduler_list_automations(): array
{
    $settings = ai_scheduler_settings_load();

    return $settings['automations'];
}

function ai_scheduler_find_automation(string $automationId): ?array
{
    $automationId = trim($automationId);
    if ($automationId === '') {
        return null;
    }

    foreach (ai_scheduler_list_automations() as $automation) {
        if (($automation['id'] ?? '') === $automationId) {
            return $automation;
        }
    }

    return null;
}

function ai_scheduler_prepare_entry(array $input, ?array $existing = null, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
    $topic = trim((string) ($input['topic'] ?? ($existing['topic'] ?? '')));
    if ($topic === '') {
        throw new RuntimeException('Each automation requires a topic.');
    }

    $title = trim((string) ($input['title'] ?? ($existing['title'] ?? '')));
    if ($title === '') {
        $title = $topic;
    }

    $description = trim((string) ($input['description'] ?? ($existing['description'] ?? '')));
    $status = ai_scheduler_normalize_status($input['status'] ?? ($existing['status'] ?? 'active'));
    $type = ai_scheduler_normalize_schedule_type($input['type'] ?? ($existing['schedule']['type'] ?? 'once'));
    $date = ai_scheduler_normalize_date((string) ($input['date'] ?? ($existing['schedule']['date'] ?? '')));
    $time = ai_scheduler_normalize_time((string) ($input['time'] ?? ($existing['schedule']['time'] ?? '09:00')));
    $frequency = ai_scheduler_normalize_frequency((string) ($input['frequency'] ?? ($existing['schedule']['frequency'] ?? 'weekly')));
    $timezone = is_string($input['timezone'] ?? null) && $input['timezone'] !== ''
        ? $input['timezone']
        : ($existing['timezone'] ?? ai_scheduler_default_timezone());

    $festival = null;
    if (isset($input['festival']) && is_array($input['festival'])) {
        $festivalName = trim((string) ($input['festival']['name'] ?? ''));
        $festivalDate = trim((string) ($input['festival']['date'] ?? ''));
        if ($festivalName !== '' && $festivalDate !== '') {
            $festival = [
                'name' => $festivalName,
                'date' => ai_scheduler_normalize_date($festivalDate),
            ];
        }
    } elseif ($existing && isset($existing['festival'])) {
        $festival = $existing['festival'];
    }

    $prepared = $existing ?? [];
    $prepared['id'] = isset($prepared['id']) ? (string) $prepared['id'] : (is_string($input['id'] ?? null) ? trim((string) $input['id']) : '');
    $prepared['title'] = $title;
    $prepared['description'] = $description;
    $prepared['topic'] = $topic;
    $prepared['status'] = $status;
    $prepared['timezone'] = $timezone;
    $prepared['schedule'] = [
        'type' => $type,
        'date' => $date,
        'time' => $time,
        'frequency' => $frequency,
    ];
    $prepared['festival'] = $festival;
    $prepared['updated_at'] = $now->format(DateTimeInterface::ATOM);
    if (!isset($prepared['created_at'])) {
        $prepared['created_at'] = $prepared['updated_at'];
    }
    if (!isset($prepared['last_run'])) {
        $prepared['last_run'] = null;
    }
    if (!isset($prepared['blog_reference'])) {
        $prepared['blog_reference'] = [];
    }

    return $prepared;
}

function ai_scheduler_save_entries(array $entries): array
{
    if (empty($entries)) {
        return ai_scheduler_settings_load();
    }

    $state = ai_scheduler_settings_load();
    $existing = [];
    foreach ($state['automations'] as $automation) {
        $existing[$automation['id']] = $automation;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = is_string($entry['id'] ?? null) ? trim((string) $entry['id']) : '';
        $reference = $id !== '' && isset($existing[$id]) ? $existing[$id] : null;
        $prepared = ai_scheduler_prepare_entry($entry, $reference, $now);
        $normalised = ai_scheduler_normalize_entry($prepared);
        $existing[$normalised['id']] = $normalised;
    }

    $state['automations'] = array_values($existing);

    return ai_scheduler_settings_save($state);
}

function ai_scheduler_set_status(string $automationId, string $status): array
{
    $automationId = trim($automationId);
    if ($automationId === '') {
        throw new RuntimeException('Automation id missing.');
    }

    $state = ai_scheduler_settings_load();
    $updated = false;
    $now = new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
    foreach ($state['automations'] as &$automation) {
        if (($automation['id'] ?? '') === $automationId) {
            $automation['status'] = ai_scheduler_normalize_status($status);
            $automation['updated_at'] = $now->format(DateTimeInterface::ATOM);
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        throw new RuntimeException('Automation not found.');
    }

    return ai_scheduler_settings_save($state);
}

function ai_scheduler_delete_automation(string $automationId): array
{
    $automationId = trim($automationId);
    if ($automationId === '') {
        throw new RuntimeException('Automation id missing.');
    }

    $state = ai_scheduler_settings_load();
    $filtered = array_values(array_filter($state['automations'], static function (array $automation) use ($automationId): bool {
        return ($automation['id'] ?? '') !== $automationId;
    }));

    if (count($filtered) === count($state['automations'])) {
        throw new RuntimeException('Automation not found.');
    }

    $state['automations'] = $filtered;

    return ai_scheduler_settings_save($state);
}

function ai_scheduler_record_run(string $automationId, array $updates): array
{
    $automationId = trim($automationId);
    if ($automationId === '') {
        throw new RuntimeException('Automation id missing.');
    }

    $state = ai_scheduler_settings_load();
    $found = false;
    foreach ($state['automations'] as &$automation) {
        if (($automation['id'] ?? '') === $automationId) {
            $automation = array_merge($automation, $updates);
            $found = true;
            break;
        }
    }

    if (!$found) {
        throw new RuntimeException('Automation not found.');
    }

    return ai_scheduler_settings_save($state);
}

function ai_scheduler_logs_load(): array
{
    $path = ai_scheduler_logs_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_scheduler_logs_load: failed to decode log file: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ai_scheduler_logs_append(array $entry): void
{
    $logs = ai_scheduler_logs_load();
    $entry['id'] = $entry['id'] ?? bin2hex(random_bytes(6));
    $entry['automation_id'] = isset($entry['automation_id']) ? (string) $entry['automation_id'] : '';
    $entry['created_at'] = $entry['created_at'] ?? (new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone())))->format(DateTimeInterface::ATOM);
    $logs[] = $entry;

    if (count($logs) > 75) {
        $logs = array_slice($logs, -75);
    }

    $payload = json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode scheduler logs.');
    }

    if (file_put_contents(ai_scheduler_logs_path(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist scheduler logs.');
    }
}

function ai_scheduler_store_generated_post(array $draft): string
{
    $draft['created_at'] = $draft['created_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);
    $fileName = 'draft_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = ai_scheduler_generated_dir() . '/' . $fileName;
    $payload = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode generated draft.');
    }

    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to store generated draft.');
    }

    return $path;
}

function ai_scheduler_festival_catalog(): array
{
    return [
        ['name' => 'Independence Day', 'date' => '2024-08-15', 'category' => 'National Day', 'description' => 'India celebrates its freedom movement and national spirit.', 'tags' => ['patriotic', 'national']],
        ['name' => 'Raksha Bandhan', 'date' => '2024-08-19', 'category' => 'Festival', 'description' => 'Celebrate the bond between siblings with stories of clean energy for homes.', 'tags' => ['family']],
        ['name' => 'Teachers’ Day', 'date' => '2024-09-05', 'category' => 'Special Day', 'description' => 'Honour educators and mentors who accelerate climate literacy.', 'tags' => ['education']],
        ['name' => 'Ganesh Chaturthi', 'date' => '2024-09-07', 'category' => 'Festival', 'description' => 'Welcoming Lord Ganesha with sustainable celebrations.', 'tags' => ['festive']],
        ['name' => 'Gandhi Jayanti', 'date' => '2024-10-02', 'category' => 'National Day', 'description' => 'Reflect on Mahatma Gandhi’s principles and green innovation.', 'tags' => ['national']],
        ['name' => 'Dussehra', 'date' => '2024-10-12', 'category' => 'Festival', 'description' => 'Victory of good over evil with cleaner energy transitions.', 'tags' => ['festival']],
        ['name' => 'Diwali', 'date' => '2024-11-01', 'category' => 'Festival', 'description' => 'Festival of Lights—perfect for solar storytelling.', 'tags' => ['festival']],
        ['name' => 'Bhai Dooj', 'date' => '2024-11-03', 'category' => 'Festival', 'description' => 'Celebrate sibling bonds and community-led sustainability.', 'tags' => ['family']],
        ['name' => 'Guru Nanak Jayanti', 'date' => '2024-11-15', 'category' => 'Festival', 'description' => 'Teachings of Guru Nanak inspire inclusive energy access.', 'tags' => ['spiritual']],
        ['name' => 'Christmas', 'date' => '2024-12-25', 'category' => 'Festival', 'description' => 'Year-end reflections on renewable milestones.', 'tags' => ['global']],
        ['name' => 'Makar Sankranti', 'date' => '2025-01-14', 'category' => 'Festival', 'description' => 'Harvest celebrations meet rooftop solar adoption.', 'tags' => ['harvest']],
        ['name' => 'Republic Day', 'date' => '2025-01-26', 'category' => 'National Day', 'description' => 'Spotlight national clean-energy commitments.', 'tags' => ['patriotic']],
        ['name' => 'Maha Shivaratri', 'date' => '2025-02-26', 'category' => 'Festival', 'description' => 'Night of devotion—power temples sustainably.', 'tags' => ['spiritual']],
        ['name' => 'Holi', 'date' => '2025-03-14', 'category' => 'Festival', 'description' => 'Colourful stories on green manufacturing and recycling.', 'tags' => ['festival']],
        ['name' => 'Eid al-Fitr', 'date' => '2025-03-31', 'category' => 'Festival', 'description' => 'Celebrate unity and equitable energy access.', 'tags' => ['festival']],
        ['name' => 'Gudi Padwa', 'date' => '2025-03-30', 'category' => 'Festival', 'description' => 'Marathi new year with resilient rooftop design tips.', 'tags' => ['regional']],
        ['name' => 'Ram Navami', 'date' => '2025-04-06', 'category' => 'Festival', 'description' => 'Narratives on dharma and responsible innovation.', 'tags' => ['spiritual']],
        ['name' => 'Earth Day', 'date' => '2025-04-22', 'category' => 'Special Day', 'description' => 'Global climate awareness—ideal for impact reports.', 'tags' => ['climate']],
        ['name' => 'Labour Day', 'date' => '2025-05-01', 'category' => 'Special Day', 'description' => 'Celebrate workforce powering India’s energy transition.', 'tags' => ['workforce']],
        ['name' => 'World Environment Day', 'date' => '2025-06-05', 'category' => 'Special Day', 'description' => 'Focus on policy, recycling, and ESG goals.', 'tags' => ['climate']],
        ['name' => 'Eid al-Adha', 'date' => '2025-06-08', 'category' => 'Festival', 'description' => 'Stories on community solar and resilience.', 'tags' => ['festival']],
        ['name' => 'Independence Day (2025)', 'date' => '2025-08-15', 'category' => 'National Day', 'description' => 'Showcase a year of national clean-tech achievements.', 'tags' => ['patriotic']],
        ['name' => 'Teachers’ Day (2025)', 'date' => '2025-09-05', 'category' => 'Special Day', 'description' => 'Celebrate climate educators and training programs.', 'tags' => ['education']],
        ['name' => 'Gandhi Jayanti (2025)', 'date' => '2025-10-02', 'category' => 'National Day', 'description' => 'Non-violence and sustainability narratives.', 'tags' => ['national']],
        ['name' => 'Dussehra (2025)', 'date' => '2025-10-12', 'category' => 'Festival', 'description' => 'Winning over pollution with clean tech.', 'tags' => ['festival']],
        ['name' => 'Diwali (2025)', 'date' => '2025-10-20', 'category' => 'Festival', 'description' => 'Light up homes with solar success stories.', 'tags' => ['festival']],
    ];
}

function ai_scheduler_upcoming_festivals(int $limit = 16): array
{
    $timezone = new DateTimeZone(ai_scheduler_default_timezone());
    $today = new DateTimeImmutable('today', $timezone);
    $entries = [];
    foreach (ai_scheduler_festival_catalog() as $festival) {
        $date = ai_scheduler_normalize_date((string) ($festival['date'] ?? ''));
        try {
            $dt = new DateTimeImmutable($date . ' 00:00:00', $timezone);
        } catch (Throwable $exception) {
            continue;
        }
        if ($dt < $today) {
            continue;
        }
        $entries[] = [
            'name' => $festival['name'],
            'date' => $dt->format('Y-m-d'),
            'category' => $festival['category'],
            'description' => $festival['description'],
            'tags' => $festival['tags'] ?? [],
            'slug' => $festival['slug'] ?? ai_scheduler_slug($festival['name'] . '-' . $dt->format('Ymd')),
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp($a['date'], $b['date']);
    });

    return array_slice($entries, 0, max(1, $limit));
}

function ai_usage_dir(): string
{
    $path = ai_storage_dir() . '/usage';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }

    return $path;
}

function ai_usage_metrics_path(): string
{
    return ai_usage_dir() . '/metrics.json';
}

function ai_usage_pricing_path(): string
{
    return ai_usage_dir() . '/pricing.json';
}

function ai_usage_pricing_defaults(): array
{
    return [
        'text' => [
            'input_per_million' => 1.25,
            'output_per_million' => 5.0,
        ],
        'image' => [
            'per_call' => 0.06,
        ],
        'tts' => [
            'per_thousand_chars' => 0.015,
        ],
    ];
}

function ai_usage_pricing_load(): array
{
    $defaults = ai_usage_pricing_defaults();
    $path = ai_usage_pricing_path();

    if (!is_file($path)) {
        file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_usage_pricing_load: failed to decode pricing: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $decoded);
}

function ai_usage_metrics_defaults(): array
{
    return [
        'daily' => [
            'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ],
        'monthly' => [
            'month' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m'),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ],
        'aggregate' => [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ],
    ];
}

function ai_usage_metrics_load(): array
{
    $defaults = ai_usage_metrics_defaults();
    $path = ai_usage_metrics_path();

    if (!is_file($path)) {
        file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_usage_metrics_load: failed to decode metrics: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $decoded);
}

function ai_usage_metrics_save(array $metrics): void
{
    $payload = json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode usage metrics.');
    }

    if (file_put_contents(ai_usage_metrics_path(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist usage metrics.');
    }
}

function ai_estimate_tokens(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    return max(1, (int) ceil($length / 4));
}

function ai_usage_register_text(string $inputText, string $outputText, string $model, array $context = []): void
{
    $pricing = ai_usage_pricing_load();
    $metrics = ai_usage_metrics_load();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $today = $now->format('Y-m-d');
    $month = $now->format('Y-m');

    $inputTokens = ai_estimate_tokens($inputText);
    $outputTokens = ai_estimate_tokens($outputText);
    $cost = 0.0;
    $textPricing = $pricing['text'];
    $cost += ($inputTokens / 1_000_000) * (float) ($textPricing['input_per_million'] ?? 0);
    $cost += ($outputTokens / 1_000_000) * (float) ($textPricing['output_per_million'] ?? 0);

    if (($metrics['daily']['date'] ?? '') !== $today) {
        $metrics['daily'] = [
            'date' => $today,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    if (($metrics['monthly']['month'] ?? '') !== $month) {
        $metrics['monthly'] = [
            'month' => $month,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    $metrics['daily']['input_tokens'] += $inputTokens;
    $metrics['daily']['output_tokens'] += $outputTokens;
    $metrics['daily']['cost'] += $cost;

    $metrics['monthly']['input_tokens'] += $inputTokens;
    $metrics['monthly']['output_tokens'] += $outputTokens;
    $metrics['monthly']['cost'] += $cost;

    $metrics['aggregate']['input_tokens'] += $inputTokens;
    $metrics['aggregate']['output_tokens'] += $outputTokens;
    $metrics['aggregate']['cost'] += $cost;

    ai_usage_metrics_save($metrics);
}

function ai_usage_register_image(array $context = []): void
{
    $pricing = ai_usage_pricing_load();
    $metrics = ai_usage_metrics_load();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $today = $now->format('Y-m-d');
    $month = $now->format('Y-m');
    $cost = (float) ($pricing['image']['per_call'] ?? 0);

    if (($metrics['daily']['date'] ?? '') !== $today) {
        $metrics['daily'] = [
            'date' => $today,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    if (($metrics['monthly']['month'] ?? '') !== $month) {
        $metrics['monthly'] = [
            'month' => $month,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    $metrics['daily']['cost'] += $cost;
    $metrics['monthly']['cost'] += $cost;
    $metrics['aggregate']['cost'] += $cost;

    ai_usage_metrics_save($metrics);
}

function ai_usage_register_tts(string $text, array $context = []): void
{
    $pricing = ai_usage_pricing_load();
    $metrics = ai_usage_metrics_load();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $today = $now->format('Y-m-d');
    $month = $now->format('Y-m');

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $thousands = max(1, (int) ceil($length / 1000));
    $cost = $thousands * (float) ($pricing['tts']['per_thousand_chars'] ?? 0);

    if (($metrics['daily']['date'] ?? '') !== $today) {
        $metrics['daily'] = [
            'date' => $today,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    if (($metrics['monthly']['month'] ?? '') !== $month) {
        $metrics['monthly'] = [
            'month' => $month,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0.0,
        ];
    }

    $metrics['daily']['cost'] += $cost;
    $metrics['monthly']['cost'] += $cost;
    $metrics['aggregate']['cost'] += $cost;

    ai_usage_metrics_save($metrics);
}

function ai_usage_summary(): array
{
    $metrics = ai_usage_metrics_load();

    return [
        'daily' => $metrics['daily'],
        'monthly' => $metrics['monthly'],
        'aggregate' => $metrics['aggregate'],
        'pricing' => ai_usage_pricing_load(),
    ];
}

function ai_error_log_path(): string
{
    return ai_usage_dir() . '/errors.json';
}

function ai_error_log_load(): array
{
    $path = ai_error_log_path();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_error_log_load: failed to decode errors: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ai_error_log_save(array $entries): void
{
    $payload = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode error log.');
    }

    if (file_put_contents(ai_error_log_path(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist error log.');
    }
}

function ai_error_log_append(string $type, string $message, array $context = []): void
{
    $entries = ai_error_log_load();
    $entries[] = [
        'id' => bin2hex(random_bytes(6)),
        'type' => $type,
        'message' => $message,
        'context' => $context,
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM),
    ];

    if (count($entries) > 100) {
        $entries = array_slice($entries, -100);
    }

    ai_error_log_save($entries);
}

// -----------------------------------------------------------------------------
// Festival & Occasion Greetings helpers
// -----------------------------------------------------------------------------

function ai_greetings_file(): string
{
    return ai_storage_dir() . '/greetings.json';
}

function ai_greetings_lock_file(): string
{
    return ai_storage_dir() . '/greetings.lock';
}

function ai_greetings_auto_file(): string
{
    return ai_storage_dir() . '/greetings_auto.json';
}

function ai_greetings_load(): array
{
    $path = ai_greetings_file();
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('ai_greetings_load: failed to decode greetings: ' . $exception->getMessage());
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ai_greetings_save(array $records): void
{
    $payload = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode greetings payload.');
    }

    $lock = fopen(ai_greetings_lock_file(), 'c+');
    if ($lock === false) {
        throw new RuntimeException('Unable to open greetings lock.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire greetings lock.');
        }

        if (file_put_contents(ai_greetings_file(), $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist greetings.');
        }

        fflush($lock);
        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

function ai_greetings_add(array $record): array
{
    $record['id'] = $record['id'] ?? uniqid('greeting_', true);
    $record['created_at'] = $record['created_at'] ?? ai_timestamp();

    $records = ai_greetings_load();
    $records[] = $record;
    ai_greetings_save($records);

    return $record;
}

function ai_greetings_delete(string $id): bool
{
    $records = ai_greetings_load();
    $filtered = array_values(array_filter($records, fn($item) => is_array($item) && ($item['id'] ?? '') !== $id));
    ai_greetings_save($filtered);

    return count($records) !== count($filtered);
}

function ai_greetings_find(string $id): ?array
{
    foreach (ai_greetings_load() as $record) {
        if (!is_array($record)) {
            continue;
        }
        if ((string) ($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function ai_greetings_auto_settings(): array
{
    $defaults = [
        'enabled' => false,
        'days_before' => 3,
    ];

    $path = ai_greetings_auto_file();
    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'enabled' => (bool) ($decoded['enabled'] ?? false),
        'days_before' => max(1, (int) ($decoded['days_before'] ?? 3)),
    ];
}

function ai_greetings_auto_settings_save(array $settings): void
{
    $payload = json_encode([
        'enabled' => (bool) ($settings['enabled'] ?? false),
        'days_before' => max(1, (int) ($settings['days_before'] ?? 3)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Unable to encode greetings automation settings.');
    }

    if (file_put_contents(ai_greetings_auto_file(), $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist greetings automation settings.');
    }
}

function ai_greeting_events_catalog(): array
{
    $year = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y');

    return [
        ['name' => 'Makar Sankranti', 'date' => sprintf('%d-01-14', $year)],
        ['name' => 'Republic Day', 'date' => sprintf('%d-01-26', $year)],
        ['name' => 'Maha Shivratri', 'date' => sprintf('%d-03-08', $year)],
        ['name' => 'Holi', 'date' => sprintf('%d-03-25', $year)],
        ['name' => 'Ram Navami', 'date' => sprintf('%d-04-17', $year)],
        ['name' => 'Labour Day', 'date' => sprintf('%d-05-01', $year)],
        ['name' => 'Environment Day', 'date' => sprintf('%d-06-05', $year)],
        ['name' => 'Eid al-Adha', 'date' => sprintf('%d-06-17', $year)],
        ['name' => 'Independence Day', 'date' => sprintf('%d-08-15', $year)],
        ['name' => 'Raksha Bandhan', 'date' => sprintf('%d-08-19', $year)],
        ['name' => 'Janmashtami', 'date' => sprintf('%d-08-26', $year)],
        ['name' => 'Ganesh Chaturthi', 'date' => sprintf('%d-09-07', $year)],
        ['name' => 'Onam', 'date' => sprintf('%d-09-15', $year)],
        ['name' => 'Gandhi Jayanti', 'date' => sprintf('%d-10-02', $year)],
        ['name' => 'Navratri', 'date' => sprintf('%d-10-03', $year)],
        ['name' => 'Durga Puja', 'date' => sprintf('%d-10-12', $year)],
        ['name' => 'Dussehra', 'date' => sprintf('%d-10-12', $year)],
        ['name' => 'Karva Chauth', 'date' => sprintf('%d-10-20', $year)],
        ['name' => 'Diwali', 'date' => sprintf('%d-11-01', $year)],
        ['name' => 'Chhath Puja', 'date' => sprintf('%d-11-07', $year)],
        ['name' => 'Christmas', 'date' => sprintf('%d-12-25', $year)],
        ['name' => 'New Year', 'date' => sprintf('%d-12-31', $year)],
        ['name' => 'Women’s Day', 'date' => sprintf('%d-03-08', $year)],
    ];
}

function ai_greeting_upcoming_events(int $days = 90): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
    $limit = $today->modify('+' . max(1, $days) . ' days');

    $events = [];
    foreach (ai_greeting_events_catalog() as $event) {
        if (!isset($event['name'], $event['date'])) {
            continue;
        }
        try {
            $date = new DateTimeImmutable($event['date'], new DateTimeZone('Asia/Kolkata'));
        } catch (Throwable $exception) {
            continue;
        }

        if ($date < $today) {
            $date = $date->modify('+1 year');
        }

        if ($date > $limit) {
            continue;
        }

        $daysAway = (int) $today->diff($date)->format('%a');
        $events[] = [
            'name' => (string) $event['name'],
            'date' => $date->format('Y-m-d'),
            'days_away' => $daysAway,
        ];
    }

    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

    return $events;
}

function ai_greeting_normalize_request(array $input): array
{
    $occasion = trim((string) ($input['occasion'] ?? ''));
    $custom = trim((string) ($input['custom_occasion'] ?? ''));
    $occasionName = $custom !== '' ? $custom : $occasion;

    $date = trim((string) ($input['occasion_date'] ?? ''));
    $audience = array_values(array_filter(array_map('trim', is_array($input['audience'] ?? null) ? $input['audience'] : [])));
    $platforms = array_values(array_filter(array_map('trim', is_array($input['platforms'] ?? null) ? $input['platforms'] : [])));
    $languages = array_values(array_filter(array_map('trim', is_array($input['languages'] ?? null) ? $input['languages'] : [])));
    $tone = trim((string) ($input['tone'] ?? 'Warm & Festive'));
    $solar = (bool) ($input['solar_context'] ?? false);
    $mediaType = trim((string) ($input['media_type'] ?? 'image'));
    $fixInstructions = trim((string) ($input['fix_instructions'] ?? ''));
    $instructions = trim((string) ($input['instructions'] ?? ''));

    $brandStatus = ai_brand_profile_status((bool) ($input['use_brand_profile'] ?? true));

    return [
        'occasion' => $occasionName !== '' ? $occasionName : 'Festival greeting',
        'custom_occasion' => $custom,
        'occasion_date' => $date,
        'audience' => $audience,
        'platforms' => $platforms,
        'languages' => $languages,
        'tone' => $tone !== '' ? $tone : 'Warm & Festive',
        'solar_context' => $solar,
        'media_type' => $mediaType !== '' ? $mediaType : 'image',
        'instructions' => $instructions,
        'fix_instructions' => $fixInstructions,
        'use_brand_profile' => $brandStatus['use_brand_profile'],
        'brand_context' => $brandStatus['context'],
        'brand_visual_context' => $brandStatus['visual_context'],
        'brand_structured' => $brandStatus['visual_structured'],
        'brand_snapshot' => $brandStatus['snapshot'],
        'brand_profile' => $brandStatus['profile'],
        'brand_profile_empty' => $brandStatus['is_empty'],
    ];
}

function ai_greeting_prompt(array $context, bool $forMedia = false): string
{
    $occasion = $context['occasion'];
    $tone = $context['tone'];
    $languages = empty($context['languages']) ? ['English'] : $context['languages'];
    $platforms = empty($context['platforms']) ? ['Facebook / Instagram Post'] : $context['platforms'];
    $audience = empty($context['audience']) ? ['General Public'] : $context['audience'];
    $instructions = $context['instructions'] ?? '';
    $fixInstructions = $context['fix_instructions'] ?? '';
    $solarLine = $context['solar_context'] ? 'Softly connect to solar energy / PM Surya Ghar without over-promising.' : 'Keep the message strictly festive without sales pressure.';
    $dateLine = $context['occasion_date'] !== '' ? 'Date: ' . $context['occasion_date'] . '.' : '';

    $brandInstructions = '';
    if ($context['use_brand_profile'] ?? false) {
        $brandInstructions = 'Brand context: ' . ($context['brand_context'] ?? '') . ' Mention the company name once or twice only, include a short CTA near the end using phone/WhatsApp/email/website, and avoid repeating contact details every sentence. Ensure CTA and contact align with the brand profile exactly with no invented digits or domains.';
    }

    $customResearch = '';
    if (($context['custom_occasion'] ?? '') !== '') {
        $customResearch = 'Research and understand the cultural meaning of this custom occasion before generating greetings. Infer mood, colors, symbols, and traditions.';
    }

    if ($forMedia) {
        $visualBrand = '';
        if ($context['use_brand_profile'] ?? false) {
            $usage = 'greeting_social';
            foreach ($platforms as $platform) {
                if (stripos($platform, 'whatsapp status') !== false) {
                    $usage = 'greeting_whatsapp_status';
                    break;
                }
            }
            $visualBrand = ' ' . ai_build_brand_visual_instructions($context['brand_structured'] ?? [], $usage) . ' Use correct aspect ratios for FB/IG/WhatsApp. Integrate branding so it looks like part of the artwork, not pasted. CTA must be clean, aligned, and balanced. Never distort the logo and avoid harsh effects. Use exactly the phone/WhatsApp/email/website from the brand profile.';
        }

        if (is_string($fixInstructions) && trim($fixInstructions) !== '') {
            $visualBrand .= ' Regenerate this image to fix the following issues from the previous version: ' . trim($fixInstructions) . '.';
        }

        $prompt = sprintf(
            'Create a professional visual description for %s. Tone: %s. Audience: %s. Platforms: %s. Languages: %s. %s %s %s Additional instructions: %s',
            $occasion,
            $tone,
            implode(', ', $audience),
            implode(', ', $platforms),
            implode(', ', $languages),
            $solarLine,
            $visualBrand,
            $customResearch,
            $instructions
        );

        if ($context['use_brand_profile'] ?? false) {
            $prompt = ai_apply_brand_identity_to_image_prompt(
                $prompt,
                is_array($context['brand_profile'] ?? null) ? $context['brand_profile'] : [],
                $usage ?? 'greeting_social'
            );
        }

        return $prompt;
    }

    return sprintf(
        "You are crafting professional festive greetings. Occasion: %s. %s Tone: %s. Languages: %s. Audience: %s. Platforms: %s. %s %s %s Ensure respectful, culturally sensitive messaging. Provide JSON with keys captions (2-3 strings), long_text (paragraph), sms_text (<= 160 chars). Additional instructions: %s",
        $occasion,
        $dateLine,
        $tone,
        implode(', ', $languages),
        implode(', ', $audience),
        implode(', ', $platforms),
        $solarLine,
        $brandInstructions,
        $customResearch,
        $instructions
    );
}

function ai_greeting_storyboard_prompt(array $context): string
{
    $occasion = $context['occasion'];
    $tone = $context['tone'];
    $languages = empty($context['languages']) ? ['English'] : $context['languages'];
    $audience = empty($context['audience']) ? ['General Public'] : $context['audience'];
    $solarLine = $context['solar_context'] ? 'Include gentle solar / clean energy references.' : 'Keep focus on the occasion without energy references.';

    $brandNote = '';
    if ($context['use_brand_profile'] ?? false) {
        $brandNote = ' Include a branding frame near the end with company name and CTA, and leave onscreen space for logo/contact strip. ' . ($context['brand_context'] ?? '');
    }

    return sprintf(
        "Storyboard for a short festive clip about %s. Tone: %s. Audience: %s. Languages: %s. %s%s Provide JSON with keys frames (array of {scene, onscreen_text, voiceover}), summary (1-2 lines).",
        $occasion,
        $tone,
        implode(', ', $audience),
        implode(', ', $languages),
        $solarLine,
        $brandNote
    );
}

function ai_greeting_extract_json(string $text): ?array
{
    $clean = trim($text);
    $clean = preg_replace('/^```json|```$/im', '', $clean);
    $clean = trim($clean ?? $text);

    try {
        $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $exception) {
        return null;
    }
}

function ai_greeting_generate_text(array $settings, array $context): array
{
    $videoStatus = ai_gemini_validate_video_model($settings);
    $prompt = ai_greeting_prompt($context);

    $needsStoryboard = in_array($context['media_type'] ?? 'image', ['video', 'both'], true)
        && !($videoStatus['configured'] ?? false);

    if ($needsStoryboard) {
        $prompt .= ' If video model is unavailable, also include a concise storyboard JSON under key storyboard with frames.';
    }

    $raw = ai_gemini_generate_text($settings, $prompt);
    $decoded = ai_greeting_extract_json($raw);

    if (!is_array($decoded)) {
        $captions = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim((string) $line);
            if ($line !== '' && count($captions) < 3) {
                $captions[] = $line;
            }
        }

        return [
            'captions' => ai_clean_paragraphs($captions, $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []),
            'long_text' => ai_clean_text_output($raw, $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []),
            'sms_text' => substr(ai_clean_text_output($raw, $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []), 0, 160),
        ];
    }

    return [
        'captions' => ai_clean_paragraphs(array_values(array_filter($decoded['captions'] ?? [])), $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []),
        'long_text' => ai_clean_text_output((string) ($decoded['long_text'] ?? ''), $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []),
        'sms_text' => ai_clean_text_output((string) ($decoded['sms_text'] ?? ''), $context['use_brand_profile'] ? ($context['brand_snapshot'] ?? []) : []),
        'storyboard' => is_array($decoded['storyboard'] ?? null) ? $decoded['storyboard'] : null,
    ];
}

function ai_greeting_generate_storyboard(array $settings, array $context): array
{
    $prompt = ai_greeting_storyboard_prompt($context);
    $raw = ai_gemini_generate_text($settings, $prompt);
    $decoded = ai_greeting_extract_json($raw);

    if (!is_array($decoded)) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw) ?: [])));
        $frames = [];
        foreach ($lines as $index => $line) {
            $frames[] = [
                'scene' => 'Frame ' . ($index + 1),
                'onscreen_text' => $line,
                'voiceover' => $line,
            ];
        }

        return [
            'summary' => 'Storyboard draft',
            'frames' => $frames,
        ];
    }

    return [
        'summary' => (string) ($decoded['summary'] ?? ''),
        'frames' => is_array($decoded['frames'] ?? null) ? $decoded['frames'] : [],
    ];
}

function ai_greeting_generate_image(array $settings, array $context): array
{
    $prompt = ai_greeting_prompt($context, true);
    return ai_gemini_generate_image($settings, $prompt);
}

function ai_greeting_generate_video(array $settings, array $context, array $videoStatus): array
{
    $prompt = ai_greeting_prompt($context, true) . ' Create a short, vertical-friendly clip suitable for reels/status.';

    if (!$videoStatus['configured'] || !$videoStatus['ok']) {
        return [
            'mode' => 'storyboard',
            'storyboard' => ai_greeting_generate_storyboard($settings, $context),
        ];
    }

    try {
        $media = ai_gemini_generate_video($settings, $prompt);
        return [
            'mode' => 'video',
            'video' => $media,
        ];
    } catch (Throwable $exception) {
        error_log('ai_greeting_generate_video: falling back to storyboard: ' . $exception->getMessage());
        return [
            'mode' => 'storyboard',
            'storyboard' => ai_greeting_generate_storyboard($settings, $context),
            'error' => $exception->getMessage(),
        ];
    }
}

function ai_greetings_run_autodraft(array $settings, int $adminId): array
{
    $auto = ai_greetings_auto_settings();
    if (!($auto['enabled'] ?? false)) {
        return ai_greetings_load();
    }

    $records = ai_greetings_load();
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));

    foreach (ai_greeting_upcoming_events(90) as $event) {
        $eventDate = $event['date'] ?? '';
        if ($eventDate === '') {
            continue;
        }

        try {
            $date = new DateTimeImmutable($eventDate, new DateTimeZone('Asia/Kolkata'));
        } catch (Throwable $exception) {
            continue;
        }

        $daysAway = (int) $today->diff($date)->format('%a');
        if ($daysAway > ($auto['days_before'] ?? 3)) {
            continue;
        }

        $already = array_filter($records, function ($row) use ($eventDate, $event) {
            return is_array($row)
                && ($row['occasion'] ?? '') === $event['name']
                && ($row['occasion_date'] ?? '') === $eventDate
                && ($row['source'] ?? '') === 'auto';
        });
        if (!empty($already)) {
            continue;
        }

        $context = ai_greeting_normalize_request([
            'occasion' => $event['name'],
            'occasion_date' => $eventDate,
            'audience' => ['General Public'],
            'platforms' => ['Facebook / Instagram Post', 'WhatsApp Status'],
            'languages' => ['Hindi', 'English'],
            'tone' => 'Warm & Professional',
            'solar_context' => true,
            'media_type' => ($videoStatus['configured'] ?? false) && ($videoStatus['ok'] ?? false) ? 'both' : 'image',
            'instructions' => 'Auto-drafted greeting',
        ]);

        try {
            $text = ai_greeting_generate_text($settings, $context);
            $image = null;
            try {
                $image = ai_greeting_generate_image($settings, $context);
            } catch (Throwable $exception) {
                error_log('ai_greetings_run_autodraft: image generation failed: ' . $exception->getMessage());
            }

            $video = null;
            $storyboard = null;
            if (($context['media_type'] ?? 'image') !== 'image') {
                try {
                    $videoResult = ai_greeting_generate_video($settings, $context, $videoStatus);
                    if (($videoResult['mode'] ?? '') === 'video') {
                        $video = $videoResult['video'];
                    } else {
                        $storyboard = $videoResult['storyboard'] ?? null;
                    }
                } catch (Throwable $exception) {
                    error_log('ai_greetings_run_autodraft: video/storyboard generation failed: ' . $exception->getMessage());
                }
            }

            $records[] = [
                'id' => uniqid('greeting_', true),
                'occasion' => $context['occasion'],
                'occasion_date' => $context['occasion_date'],
                'audience' => $context['audience'],
                'platforms' => $context['platforms'],
                'languages' => $context['languages'],
                'tone' => $context['tone'],
                'solar_context' => $context['solar_context'],
                'media_type' => $context['media_type'],
                'captions' => $text['captions'],
                'long_text' => $text['long_text'],
                'sms_text' => $text['sms_text'],
                'image' => $image,
                'video' => $video,
                'storyboard' => $storyboard,
                'created_at' => ai_timestamp(),
                'created_by' => $adminId,
                'source' => 'auto',
                'uses_brand_profile' => ($context['use_brand_profile'] ?? false) && !($context['brand_profile_empty'] ?? false),
                'brand_snapshot' => $context['brand_snapshot'] ?? null,
            ];
        } catch (Throwable $exception) {
            error_log('ai_greetings_run_autodraft: generation failed: ' . $exception->getMessage());
        }
    }

    ai_greetings_save($records);

    return $records;
}
