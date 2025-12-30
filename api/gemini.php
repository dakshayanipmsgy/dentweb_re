<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_gemini.php';
require_once __DIR__ . '/../includes/blog.php';
require_once __DIR__ . '/../includes/blog_service.php';
require_once __DIR__ . '/../includes/smart_marketing.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    ensure_api_access('admin');
} catch (Throwable $exception) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';
$admin = current_user();
$adminId = (int) ($admin['id'] ?? 0);

switch ($action) {
    case 'chat':
        handle_chat_request($adminId);
        break;
    case 'clear-history':
        handle_clear_history($adminId);
        break;
    case 'export-pdf':
        handle_export_pdf($adminId, (string) ($admin['full_name'] ?? 'Administrator'));
        break;
    case 'blog-generate':
        handle_blog_generate($adminId);
        break;
    case 'blog-autosave':
        handle_blog_autosave($adminId);
        break;
    case 'blog-load-draft':
        handle_blog_load_draft($adminId);
        break;
    case 'blog-publish':
        handle_blog_publish($adminId);
        break;
    case 'blog-regenerate-paragraph':
        handle_blog_regenerate_paragraph($adminId);
        break;
    case 'image-generate':
        handle_image_generate($adminId);
        break;
    case 'tts-generate':
        handle_tts_generate($adminId);
        break;
    case 'sandbox-text':
        handle_sandbox_text($adminId);
        break;
    case 'sandbox-image':
        handle_sandbox_image($adminId);
        break;
    case 'sandbox-tts':
        handle_sandbox_tts($adminId);
        break;
    case 'greetings-bootstrap':
        handle_greetings_bootstrap($adminId);
        break;
    case 'greetings-generate-text':
        handle_greetings_generate_text($adminId);
        break;
    case 'greetings-generate-media':
        handle_greetings_generate_media($adminId);
        break;
    case 'greetings-save-draft':
        handle_greetings_save_draft($adminId);
        break;
    case 'greetings-delete':
        handle_greetings_delete($adminId);
        break;
    case 'greetings-send-smart':
        handle_greetings_send_smart_marketing($adminId);
        break;
    case 'greetings-auto-settings':
        handle_greetings_auto_settings();
        break;
    case 'greetings-list':
        handle_greetings_list($adminId);
        break;
    case 'scheduler-status':
        handle_scheduler_status();
        break;
    case 'scheduler-save':
        handle_scheduler_save();
        break;
    case 'scheduler-run':
        handle_scheduler_run($adminId);
        break;
    case 'scheduler-action':
        handle_scheduler_action();
        break;
    case 'usage-summary':
        handle_usage_summary();
        break;
    case 'error-retry':
        handle_error_retry($adminId);
        break;
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported action.']);
        break;
}

function handle_chat_request(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST for chat requests.']);
        return;
    }

    $body = file_get_contents('php://input');
    $payload = [];
    if (is_string($body) && trim($body) !== '') {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $payload = [];
        }
    }

    $message = isset($payload['message']) && is_string($payload['message']) ? trim($payload['message']) : '';
    if ($message === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
        return;
    }

    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'AI is currently disabled. Enable Gemini in settings.']);
        return;
    }

    if (($settings['api_key'] ?? '') === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing.']);
        return;
    }

    $history = ai_chat_history_load($adminId);
    $history[] = [
        'role' => 'user',
        'text' => $message,
        'timestamp' => ai_timestamp(),
    ];

    $contents = ai_convert_history_to_contents($history);

    try {
        $response = ai_gemini_generate($settings, $contents);
        $replyText = ai_gemini_extract_text($response);
        if ($replyText === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        $history[] = [
            'role' => 'assistant',
            'text' => $replyText,
            'timestamp' => ai_timestamp(),
        ];
        $history = ai_chat_history_replace($adminId, $history);

        ai_usage_register_text($message, $replyText, $settings['models']['text'] ?? '');

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'reply' => $replyText,
            'history' => $history,
        ]);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'chat',
            'prompt' => $message,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ]);
    }
}

function handle_clear_history(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to clear history.']);
        return;
    }

    ai_chat_history_clear($adminId);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
}

function handle_export_pdf(int $adminId, string $adminName): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET to export chat.']);
        return;
    }

    $pdf = ai_chat_history_export_pdf($adminId, $adminName);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="ai-chat-transcript.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
}

function handle_blog_generate(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to generate blogs.']);
        return;
    }

    $body = file_get_contents('php://input');
    $payload = [];
    if (is_string($body) && trim($body) !== '') {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $payload = [];
        }
    }

    $title = trim((string) ($payload['title'] ?? ''));
    $brief = trim((string) ($payload['brief'] ?? ''));
    $keywords = trim((string) ($payload['keywords'] ?? ''));
    $tone = trim((string) ($payload['tone'] ?? ''));
    $brandPreference = (bool) ($payload['use_brand_profile'] ?? true);
    $lengthInput = is_array($payload['length'] ?? null) ? $payload['length'] : [];
    $lengthPreset = is_string($lengthInput['preset'] ?? null) ? (string) $lengthInput['preset'] : (string) ($payload['length'] ?? '');
    $customLength = $lengthInput['custom'] ?? ($lengthInput['customWordCount'] ?? null);
    $lengthConfig = blog_resolve_length_config($lengthPreset, $customLength);
    $brandContext = getBrandContextForAI($brandPreference);

    if ($title === '' || $brief === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Title and brief are required to generate a blog.']);
        return;
    }

    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'AI is disabled. Enable Gemini in settings.']);
        return;
    }

    if (($settings['api_key'] ?? '') === '') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing.']);
        return;
    }

    ignore_user_abort(true);
    @set_time_limit(0);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $brandStatus = ai_brand_profile_status($brandPreference);
    $timeoutSeconds = 65;
    $requestOptions = ['timeout' => $timeoutSeconds, 'retries' => 1, 'retry_delay' => 1.5];

    $prompt = blog_build_full_prompt($title, $brief, $keywords, $tone, $brandContext, $lengthConfig);
    try {
        sse_emit('status', ['message' => 'Generating blog draft…']);
        $rawHtml = ai_gemini_generate_text($settings, $prompt, $requestOptions);
    } catch (Throwable $exception) {
        $message = stripos($exception->getMessage(), 'timed out') !== false
            ? 'Gemini took too long to respond for this blog. Please try again or simplify the topic. (Timeout after ' . $timeoutSeconds . ' seconds.)'
            : $exception->getMessage();
        sse_emit('error', ['message' => $message]);
        return;
    }

    $cleanRawHtml = extract_single_blog($rawHtml);
    if ($cleanRawHtml === '') {
        sse_emit('error', ['message' => 'Gemini returned empty content.']);
        return;
    }

    $article = ai_clean_blog_html($cleanRawHtml, $title, $brandContext['profile'] ?? []);
    $paragraphs = ai_clean_paragraphs($article['paragraphs'], $brandContext['profile'] ?? []);
    $wordCount = (int) ($article['word_count'] ?? str_word_count(strip_tags($article['html'] ?? '')));

    if (empty($paragraphs)) {
        sse_emit('error', ['message' => 'Gemini returned empty content.']);
        return;
    }

    foreach ($paragraphs as $paragraph) {
        sse_emit('chunk', ['paragraph' => $paragraph]);
    }

    $imageInfo = null;
    try {
        $imagePromptParts = [$title];
        if ($keywords !== '') {
            $imagePromptParts[] = $keywords;
        }
        $imagePromptParts[] = 'High-quality editorial illustration for renewable energy blog.';
        if ($brandContext['use_brand_profile']) {
            $imagePromptParts[] = ai_build_brand_visual_instructions($brandContext['visual_structured'] ?? [], 'blog_feature');
        }
        $imagePromptParts[] = 'Use correct aspect ratios for FB/IG/WhatsApp. CTA must sit cleanly in bottom-right or bottom-center without distortion. Include logo and full company name within the layout.';

        $imagePrompt = implode(' · ', $imagePromptParts);
        if ($brandContext['use_brand_profile']) {
            $imagePrompt = ai_apply_brand_identity_to_image_prompt(
                $imagePrompt,
                $brandContext['profile'] ?? [],
                'blog_feature'
            );
        }

        $imageInfo = ai_gemini_generate_image($settings, $imagePrompt);
        if (isset($imageInfo['path'])) {
            $imageInfo['path'] = blog_ai_normalize_cover_image((string) $imageInfo['path']);
        }
    } catch (Throwable $exception) {
        $imageInfo = null;
    }

    $coverImage = $imageInfo['path'] ?? '';
    $coverAlt = $imageInfo ? ('AI generated illustration for ' . $title) : '';

    try {
        $savedDraft = blog_ai_save_draft($adminId, [
            'title' => $title,
            'brief' => $brief,
            'keywords' => $keywords,
            'tone' => $tone,
            'paragraphs' => $paragraphs,
            'bodyHtml' => $article['html'] ?? '',
            'coverImage' => $coverImage,
            'coverImageAlt' => $coverAlt,
            'length' => $lengthConfig,
            'wordCount' => $wordCount,
        ]);
    } catch (Throwable $exception) {
        sse_emit('error', ['message' => 'Draft storage failed: ' . $exception->getMessage()]);
        return;
    }

    $payload = [
        'success' => true,
        'paragraphs' => $paragraphs,
        'excerpt' => ai_build_excerpt_from_paragraphs($paragraphs, $brief),
        'draftId' => $savedDraft['draft_id'] ?? null,
        'usesBrandProfile' => $brandStatus['use_brand_profile'],
        'brandProfileMissing' => $brandPreference && $brandStatus['is_empty'],
        'wordCount' => $wordCount,
        'length' => $lengthConfig,
    ];
    if ($imageInfo) {
        $payload['image'] = $imageInfo;
        $payload['image']['alt'] = $coverAlt;
    }
    sse_emit('done', $payload);
}

function blog_resolve_length_config(string $preset, $customWordCount = null): array
{
    $map = [
        'short' => ['target' => 700, 'label' => 'Short (~600–800 words)', 'depth' => 'short, focused article with a clear intro, 2–3 sections, and a brief conclusion.'],
        'standard' => ['target' => 1350, 'label' => 'Standard (~1200–1500 words)', 'depth' => 'full-length blog article with detailed sections and examples.'],
        'long' => ['target' => 2250, 'label' => 'Long / In-depth (~2000–2500 words)', 'depth' => 'in-depth, comprehensive blog article with detailed breakdown, multiple sections, examples, and FAQs.'],
    ];

    $cleanPreset = strtolower(trim($preset));
    if (!in_array($cleanPreset, ['short', 'standard', 'long', 'custom'], true)) {
        $cleanPreset = 'standard';
    }

    $base = $map[$cleanPreset === 'custom' ? 'standard' : $cleanPreset];
    $target = (int) $base['target'];
    $custom = null;

    if ($cleanPreset === 'custom') {
        $candidate = (int) $customWordCount;
        if ($candidate >= 300 && $candidate <= 3000) {
            $target = $candidate;
            $custom = $candidate;
        }
    }

    $min = max(300, (int) round($target * 0.8));
    $max = (int) round($target * 1.2);

    return [
        'preset' => $custom !== null ? 'custom' : $cleanPreset,
        'targetWords' => $target,
        'minWords' => $min,
        'maxWords' => $max,
        'depthDescription' => $base['depth'],
        'label' => $base['label'],
        'customWordCount' => $custom,
    ];
}

function blog_length_prompt_lines(array $lengthConfig): array
{
    $targetLine = sprintf(
        'Target length: around %d words. Minimum: %d words. Maximum: %d words.',
        $lengthConfig['targetWords'],
        $lengthConfig['minWords'],
        $lengthConfig['maxWords']
    );

    $lines = [$targetLine, 'Depth: ' . ($lengthConfig['depthDescription'] ?? '')];

    switch ($lengthConfig['preset']) {
        case 'short':
            $lines[] = 'Write a short, focused blog post of around ' . $lengthConfig['targetWords'] . ' words.';
            $lines[] = 'Use one <h1> title, 2–3 <h2> sections, and a concise conclusion.';
            break;
        case 'long':
            $lines[] = 'Write an in-depth, comprehensive blog article of around ' . $lengthConfig['targetWords'] . ' words (at least ' . $lengthConfig['minWords'] . ', at most ' . $lengthConfig['maxWords'] . ').';
            $lines[] = 'Use a rich structure: <h1> title, several <h2> sections, optional <h3> subsections, bullet lists where useful, and a detailed conclusion with CTA.';
            break;
        case 'custom':
            $lines[] = 'Write a blog article of around ' . $lengthConfig['targetWords'] . ' words (±20%).';
            $lines[] = 'Structure it with <h1>, <h2>, <h3>, <p>, and lists. Do not return a short summary.';
            break;
        default:
            $lines[] = 'Write a detailed blog post of around ' . $lengthConfig['targetWords'] . ' words (at least ' . $lengthConfig['minWords'] . ', at most ' . $lengthConfig['maxWords'] . ').';
            $lines[] = 'Use one <h1> title, multiple <h2> sections, optional <h3> subheadings, and a full conclusion with CTA.';
            break;
    }

    $lines[] = 'Use HTML tags (<h1>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>, <u>) so the final blog renders correctly.';

    return $lines;
}

function blog_build_full_prompt(string $title, string $brief, string $keywords, string $tone, array $brandContext, array $lengthConfig): string
{
    $lines = [
        'Write one comprehensive blog article as HTML. Return exactly one article — do not include multiple versions or repeated drafts.',
        'Use a single <h1> for the main title, then <h2>/<h3> headings and <p>/<ul>/<li> content. Avoid adding a second <h1> or starting a new article later in the response.',
        'Do not include system notes or explanations. Provide only the blog content.',
        'Working title: ' . $title,
        'Brief: ' . $brief,
    ];

    if ($keywords !== '') {
        $lines[] = 'Target keywords: ' . $keywords;
    }
    if ($tone !== '') {
        $lines[] = 'Tone: ' . $tone;
    }
    if ($brandContext['use_brand_profile']) {
        $lines[] = 'Brand context: ' . ($brandContext['text_context'] ?? '');
        $lines[] = 'Use the exact brand name and contact details as provided. Keep CTA concise.';
    }

    $lines = array_merge($lines, blog_length_prompt_lines($lengthConfig));
    $lines[] = 'Do not generate more than one blog. If you think of alternative angles, ignore them and stick to a single final draft.';

    return implode("\n", array_filter($lines));
}

function blog_build_outline_prompt(string $title, string $brief, string $keywords, string $tone, array $brandContext, array $lengthConfig): string
{
    $lines = [
        'Plan a structured outline for a long-form blog article.',
        'Working title: ' . $title,
        'Brief: ' . $brief,
    ];

    if ($keywords !== '') {
        $lines[] = 'Target keywords: ' . $keywords;
    }
    if ($tone !== '') {
        $lines[] = 'Tone: ' . $tone;
    }
    if ($brandContext['use_brand_profile']) {
        $lines[] = 'Brand context: ' . ($brandContext['text_context'] ?? '');
        $lines[] = 'Respect spelling of company name, phone, WhatsApp, email, and website exactly as provided.';
    }

    $lines = array_merge($lines, blog_length_prompt_lines($lengthConfig));
    $lines[] = 'Write a full blog article, not a short summary.';
    $lines[] = 'Return JSON with keys "title" and "sections". sections must be an array of 5-8 items, each with "heading" (H2/H3 wording) and "bullets" (2-3 bullet points describing what to cover). Include entries for Introduction and Conclusion.';
    $lines[] = 'Keep the outline concise and ready for fast follow-up generation.';

    return implode("\n", $lines);
}

function blog_parse_outline(string $raw, string $fallbackTitle): array
{
    $title = $fallbackTitle;
    $sections = [];

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (isset($decoded['title']) && is_string($decoded['title']) && trim($decoded['title']) !== '') {
            $title = trim($decoded['title']);
        }
        if (isset($decoded['sections']) && is_array($decoded['sections'])) {
            foreach ($decoded['sections'] as $section) {
                $heading = is_string($section['heading'] ?? null) ? trim($section['heading']) : '';
                $bulletsRaw = $section['bullets'] ?? [];
                $bullets = [];
                if (is_array($bulletsRaw)) {
                    foreach ($bulletsRaw as $bullet) {
                        if (is_string($bullet)) {
                            $clean = trim($bullet);
                            if ($clean !== '') {
                                $bullets[] = $clean;
                            }
                        }
                    }
                }
                if ($heading !== '') {
                    $sections[] = ['heading' => $heading, 'bullets' => $bullets];
                }
            }
        }
    }

    if (empty($sections)) {
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[-*\d.]+\s*(.+)$/', $line, $matches)) {
                $heading = trim($matches[1]);
                if ($heading !== '') {
                    $sections[] = ['heading' => $heading, 'bullets' => []];
                }
            }
        }
    }

    if (empty($sections)) {
        $sections[] = ['heading' => 'Key Insights', 'bullets' => ['Break down the topic into practical sections.', 'Highlight data, examples, and CTA ideas where relevant.']];
    }

    return ['title' => $title, 'sections' => $sections];
}

function blog_prepare_sections(array $sections): array
{
    $intro = [];
    $conclusion = [];
    $body = [];

    foreach ($sections as $section) {
        $heading = is_string($section['heading'] ?? null) ? trim($section['heading']) : '';
        $bullets = is_array($section['bullets'] ?? null) ? array_values(array_filter(array_map('trim', $section['bullets']))) : [];
        if ($heading === '') {
            continue;
        }
        if (stripos($heading, 'intro') !== false) {
            $intro = $bullets;
            continue;
        }
        if (stripos($heading, 'conclusion') !== false || stripos($heading, 'summary') !== false) {
            $conclusion = $bullets;
            continue;
        }
        $body[] = [
            'heading' => $heading,
            'bullets' => $bullets,
        ];
    }

    if (empty($intro)) {
        $intro = ['Set the context and preview the key sections.', 'Explain why the topic matters to the reader.'];
    }

    if (empty($body)) {
        $body[] = ['heading' => 'Main Analysis', 'bullets' => ['Expand on the core ideas.', 'Add examples and data points.']];
    }

    if (empty($conclusion)) {
        $conclusion = ['Summarise the most useful takeaways.', 'Add a concise CTA using the provided brand contacts if available.'];
    }

    return [
        'introduction' => $intro,
        'sections' => $body,
        'conclusion' => $conclusion,
    ];
}

function blog_build_section_prompt(
    string $title,
    string $brief,
    string $tone,
    array $brandContext,
    array $lengthConfig,
    string $heading,
    array $bullets,
    string $role
): string {
    $lines = [
        'You are writing the ' . $role . ' section of a blog titled "' . $title . '".',
        'Brief: ' . $brief,
        'Section heading: ' . $heading,
    ];

    $lines = array_merge($lines, blog_length_prompt_lines($lengthConfig));

    if (!empty($bullets)) {
        $lines[] = 'Cover these points:';
        foreach ($bullets as $bullet) {
            $lines[] = '- ' . $bullet;
        }
    }

    if ($tone !== '') {
        $lines[] = 'Tone: ' . $tone . '.';
    }

    if ($brandContext['use_brand_profile']) {
        $lines[] = 'Brand context: ' . ($brandContext['text_context'] ?? '');
        $lines[] = 'Use the exact company name and contact details as provided. For conclusions, add a CTA that references the correct phone/WhatsApp/email/website without inventing or altering digits.';
        $lines[] = 'Keep the overall length around ' . $lengthConfig['targetWords'] . ' words while respecting the CTA guidance.';
    }

    if ($role === 'introduction') {
        $lines[] = 'Write 2-3 paragraphs that set up the topic and preview the sections. Avoid conclusions or CTAs here.';
    } elseif ($role === 'conclusion') {
        $lines[] = 'Write 2-3 paragraphs that summarise the article and close with a concise CTA using the provided brand contacts.';
    } else {
        $lines[] = 'Write 2-4 paragraphs for this section only.';
    }

    $lines[] = 'Return clean HTML for this section starting with an <h2> heading and followed by paragraphs and lists as needed. Use <strong>/<em>/<u> for emphasis. Avoid markdown artefacts, stray asterisks, or placeholder text.';
    $lines[] = 'Write a full blog article, not a short summary. Maintain the requested structure with <h1> for the main title, <h2> for sections, optional <h3> for subsections, <p> for paragraphs, and lists where useful.';

    return implode("\n", $lines);
}

function blog_build_expand_prompt(
    string $title,
    string $brief,
    string $tone,
    string $keywords,
    string $html,
    array $lengthConfig,
    array $brandContext
): string {
    $lines = [
        'Expand each section with more detail, examples, and explanations while keeping the same headings and structure.',
        'Current blog HTML:',
        $html,
        sprintf('Target length: around %d words. Minimum: %d. Maximum: %d.', $lengthConfig['targetWords'], $lengthConfig['minWords'], $lengthConfig['maxWords']),
        'Depth expectation: ' . ($lengthConfig['depthDescription'] ?? ''),
        'Write a full blog article, not a short summary.',
    ];

    if ($brief !== '') {
        $lines[] = 'Brief: ' . $brief;
    }
    if ($keywords !== '') {
        $lines[] = 'Keywords: ' . $keywords;
    }
    if ($tone !== '') {
        $lines[] = 'Tone: ' . $tone;
    }
    if ($brandContext['use_brand_profile']) {
        $lines[] = 'Brand context: ' . ($brandContext['text_context'] ?? '');
        $lines[] = 'In the conclusion, include a soft call-to-action using the brand’s name and contact details. Keep the overall length around ' . $lengthConfig['targetWords'] . ' words.';
    }

    $lines[] = 'Maintain HTML tags: <h1> for the main title, <h2> for major sections, <h3> for subsections, <p> for paragraphs, and <ul>/<li> for bullet points.';

    return implode("\n", $lines);
}

function handle_blog_autosave(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to save drafts.']);
        return;
    }

    $payload = decode_json_body();
    $title = trim((string) ($payload['title'] ?? ''));
    $brief = trim((string) ($payload['brief'] ?? ''));
    $keywords = trim((string) ($payload['keywords'] ?? ''));
    $tone = trim((string) ($payload['tone'] ?? ''));
    $paragraphs = ai_normalize_paragraphs($payload['paragraphs'] ?? []);
    $coverImage = trim((string) ($payload['coverImage'] ?? ''));
    $coverAlt = trim((string) ($payload['coverImageAlt'] ?? ''));
    $lengthMeta = blog_normalize_length_meta($payload['length'] ?? []);
    $wordCount = isset($payload['wordCount']) ? (int) $payload['wordCount'] : 0;
    $draftId = isset($payload['draftId']) && $payload['draftId'] !== ''
        ? (string) $payload['draftId']
        : null;

    if ($title === '' || empty($paragraphs)) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'Add a title and generate content before saving the draft.',
        ]);
        return;
    }

    try {
        $draft = blog_ai_save_draft($adminId, [
            'title' => $title,
            'brief' => $brief,
            'keywords' => $keywords,
            'tone' => $tone,
            'paragraphs' => $paragraphs,
            'coverImage' => $coverImage,
            'coverImageAlt' => $coverAlt,
            'length' => $lengthMeta,
            'wordCount' => $wordCount,
        ], $draftId);
        blog_log_event('blog_draft_save_success', [
            'draft_id' => $draft['draft_id'] ?? null,
            'slug' => $draft['slug'] ?? null,
        ]);
    } catch (Throwable $exception) {
        blog_log_event('blog_draft_save_error', [
            'draft_id' => $draftId,
            'developer_message' => $exception->getMessage(),
        ]);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save draft. Please retry.',
            'developer_message' => $exception->getMessage(),
        ]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'savedAt' => $draft['updated_at'] ?? ai_timestamp(),
        'draftId' => $draft['draft_id'] ?? null,
        'slug' => $draft['slug'] ?? null,
    ]);
}

function handle_blog_load_draft(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET to load drafts.']);
        return;
    }

    $draft = blog_ai_find_draft_for_admin($adminId);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'draft' => $draft]);
}

function handle_blog_publish(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to publish blogs.']);
        return;
    }

    $payload = decode_json_body();
    $title = trim((string) ($payload['title'] ?? ''));
    $brief = trim((string) ($payload['brief'] ?? ''));
    $keywords = trim((string) ($payload['keywords'] ?? ''));
    $tone = trim((string) ($payload['tone'] ?? ''));
    $paragraphs = ai_normalize_paragraphs($payload['paragraphs'] ?? []);
    $coverImage = trim((string) ($payload['coverImage'] ?? ''));
    $coverImageAlt = trim((string) ($payload['coverImageAlt'] ?? ''));
    $lengthMeta = blog_normalize_length_meta($payload['length'] ?? []);
    $wordCount = isset($payload['wordCount']) ? (int) $payload['wordCount'] : 0;
    $draftId = isset($payload['draftId']) ? (string) $payload['draftId'] : '';

    if ($title === '' || empty($paragraphs)) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Title and generated content are required before publishing.']);
        return;
    }

    if ($draftId === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Draft is missing. Save the draft once before publishing.']);
        return;
    }

    try {
        $draft = blog_ai_save_draft($adminId, [
            'title' => $title,
            'brief' => $brief,
            'keywords' => $keywords,
            'tone' => $tone,
            'paragraphs' => $paragraphs,
            'coverImage' => $coverImage,
            'coverImageAlt' => $coverImageAlt,
            'length' => $lengthMeta,
            'wordCount' => $wordCount,
        ], $draftId);
    } catch (Throwable $exception) {
        blog_log_event('blog_publish_error', [
            'draft_id' => $draftId,
            'developer_message' => $exception->getMessage(),
        ]);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Unable to prepare draft for publishing.',
            'developer_message' => $exception->getMessage(),
        ]);
        return;
    }

    try {
        $published = blog_service()->publishPost($draftId);
        blog_log_event('blog_publish_success', [
            'draft_id' => $draftId,
            'slug' => $published['slug'] ?? null,
        ]);
    } catch (Throwable $exception) {
        blog_log_event('blog_publish_error', [
            'draft_id' => $draftId,
            'developer_message' => $exception->getMessage(),
        ]);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Publishing failed. Please retry.',
            'developer_message' => $exception->getMessage(),
        ]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'postId' => $published['post_id'] ?? $draftId,
        'slug' => $published['slug'] ?? '',
        'url' => $published['url'] ?? '',
    ]);
}

function handle_blog_regenerate_paragraph(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to regenerate paragraphs.']);
        return;
    }

    $payload = decode_json_body();
    $paragraph = trim((string) ($payload['paragraph'] ?? ''));
    $context = trim((string) ($payload['context'] ?? ''));
    $title = trim((string) ($payload['title'] ?? ''));
    $tone = trim((string) ($payload['tone'] ?? ''));
    $brandStatus = ai_brand_profile_status((bool) ($payload['use_brand_profile'] ?? true));

    if ($paragraph === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Paragraph content is required.']);
        return;
    }

    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'AI is disabled. Enable Gemini in settings.']);
        return;
    }

    $prompt = 'Rewrite the following paragraph for a ';
    $prompt .= $brandStatus['use_brand_profile'] && ($brandStatus['profile']['company_name'] ?? '') !== ''
        ? $brandStatus['profile']['company_name']
        : 'Dakshayani Energy';
    $prompt .= ' blog post.';
    if ($title !== '') {
        $prompt .= "\nBlog title: " . $title;
    }
    if ($tone !== '') {
        $prompt .= "\nTone: " . $tone;
    }
    if ($brandStatus['use_brand_profile']) {
        $prompt .= "\nBrand context: " . $brandStatus['context'];
        $prompt .= "\nKeep brand mentions light (1-2 times) and close with a concise CTA using the provided contacts.";
    }
    if ($context !== '') {
        $prompt .= "\nArticle context: " . $context;
    }
    $prompt .= "\nParagraph:\n" . $paragraph;
    $prompt .= "\nReturn a refined paragraph only.";

    try {
        $text = ai_gemini_generate_text($settings, $prompt);
    } catch (Throwable $exception) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    $clean = trim($text);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'paragraph' => $clean]);
}

function handle_image_generate(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to generate images.']);
        return;
    }

    $payload = decode_json_body();
    $prompt = trim((string) ($payload['prompt'] ?? ''));
    $brandContext = getBrandContextForAI((bool) ($payload['use_brand_profile'] ?? true));
    $fixInstructions = trim((string) ($payload['fix_instructions'] ?? ''));
    $title = trim((string) ($payload['title'] ?? ''));
    $brief = trim((string) ($payload['brief'] ?? ''));
    $tone = trim((string) ($payload['tone'] ?? ''));
    $keywords = trim((string) ($payload['keywords'] ?? ''));
    $aspectRatio = ai_normalize_image_aspect_ratio((string) ($payload['aspect_ratio'] ?? '1:1'));

    if ($prompt === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Prompt is required to generate an image.']);
        return;
    }

    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'AI is disabled. Enable Gemini in settings.']);
        return;
    }

    $segments = [$prompt];
    $candidates = ai_image_generation_candidates($aspectRatio);
    if ($title !== '') {
        $segments[] = 'Blog title: ' . $title;
    }
    if ($brief !== '') {
        $segments[] = 'Context: ' . $brief;
    }
    if ($tone !== '') {
        $segments[] = 'Tone: ' . $tone;
    }
    if ($keywords !== '') {
        $segments[] = 'Keywords: ' . $keywords;
    }
    if ($fixInstructions !== '') {
        $segments[] = 'Regenerate this image to fix the following issues from the previous version: ' . $fixInstructions . '.';
    }
    if (!empty($candidates)) {
        $primary = $candidates[0];
        $segments[] = 'Target aspect ratio: ' . $aspectRatio . ' (approximate size ' . $primary['width'] . 'x' . $primary['height'] . '). If unavailable, choose the closest size in the same orientation.';
    }

    if ($brandContext['use_brand_profile']) {
        $segments[] = ai_build_brand_visual_instructions($brandContext['visual_structured'] ?? [], 'blog_feature');
        $segments[] = 'Ensure the company logo and full company name are clearly visible. Use the exact phone, WhatsApp, email, and website from the brand profile without changing any digits or domains.';
    }

    $prompt = implode("\n", array_filter($segments));

    if ($brandContext['use_brand_profile']) {
        $prompt = ai_apply_brand_identity_to_image_prompt(
            $prompt,
            $brandContext['profile'] ?? [],
            'blog_feature'
        );
    }

    try {
        $image = null;
        $sizeUsed = '';
        $notice = null;
        $lastError = null;

        foreach ($candidates as $candidate) {
            try {
                $image = ai_gemini_generate_image($settings, $prompt, [
                    'dimensions' => ['width' => $candidate['width'], 'height' => $candidate['height']],
                    'aspect_ratio' => $candidate['ratio'],
                ]);
                if ((int) $candidate['width'] > 0 && (int) $candidate['height'] > 0) {
                    $sizeUsed = $candidate['width'] . 'x' . $candidate['height'];
                }
                if (!empty($candidate['fallback'])) {
                    $notice = 'Generated with fallback size due to API constraints.';
                }
                break;
            } catch (Throwable $exception) {
                $lastError = $exception;
                if (!ai_is_image_size_error($exception->getMessage())) {
                    throw $exception;
                }
                $notice = 'Generated with fallback size due to API constraints.';
                continue;
            }
        }

        if ($image === null) {
            throw $lastError ?? new RuntimeException('Unable to generate image.');
        }

        $image['aspect_ratio'] = $aspectRatio;
        if ($sizeUsed === '' && isset($image['dimensions']['width'], $image['dimensions']['height'])) {
            $sizeUsed = (int) $image['dimensions']['width'] . 'x' . (int) $image['dimensions']['height'];
        }
        if ($sizeUsed !== '') {
            $image['size_used'] = $sizeUsed;
        }
        if ($notice !== null && $notice !== '') {
            $image['notice'] = $notice;
        }

        ai_usage_register_image(['action' => 'blog-image']);
        if (isset($image['path'])) {
            $image['path'] = blog_ai_normalize_cover_image((string) $image['path']);
        }
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'blog-image',
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
        ]);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    $draftId = isset($payload['draftId']) && $payload['draftId'] !== '' ? (string) $payload['draftId'] : null;
    $altText = isset($payload['alt']) && $payload['alt'] !== ''
        ? (string) $payload['alt']
        : 'AI generated visual for blog post';
    try {
        blog_ai_update_cover_image($adminId, $image['path'], $altText, $draftId);
    } catch (Throwable $exception) {
        blog_log_event('blog_draft_save_error', [
            'draft_id' => $draftId,
            'developer_message' => $exception->getMessage(),
        ]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'image' => $image]);
}

function handle_tts_generate(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to generate audio.']);
        return;
    }

    $payload = decode_json_body();
    $text = trim((string) ($payload['text'] ?? ''));
    $format = trim((string) ($payload['format'] ?? 'mp3'));

    if ($text === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Text is required to generate audio.']);
        return;
    }

    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'AI is disabled. Enable Gemini in settings.']);
        return;
    }

    try {
        $audio = ai_gemini_generate_tts($settings, $text, $format);
        ai_usage_register_tts($text, ['action' => 'blog-tts']);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'blog-tts',
            'text' => $text,
        ]);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'audio' => $audio]);
}

function decode_json_body(): array
{
    $body = file_get_contents('php://input');
    if (!is_string($body) || trim($body) === '') {
        return [];
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ai_normalize_paragraphs($value): array
{
    $paragraphs = [];
    if (is_array($value)) {
        foreach ($value as $item) {
            $paragraph = trim((string) $item);
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }
    }

    return $paragraphs;
}

function ai_normalize_paragraphs_from_text(string $text): array
{
    $parts = preg_split('/\n{2,}/', trim($text)) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $clean = trim(preg_replace('/\s+/', ' ', $part) ?? '');
        if ($clean !== '') {
            $result[] = $clean;
        }
    }

    return $result;
}

function ai_build_excerpt_from_paragraphs(array $paragraphs, string $fallback = ''): string
{
    $source = $fallback !== '' ? $fallback : implode(' ', $paragraphs);
    $source = preg_replace('/\s+/', ' ', trim($source) ?? '');
    if ($source === '') {
        return '';
    }

    $limit = 220;
    if (mb_strlen($source) <= $limit) {
        return $source;
    }

    $truncated = mb_substr($source, 0, $limit);
    $lastSpace = mb_strrpos($truncated, ' ');
    if ($lastSpace !== false) {
        $truncated = mb_substr($truncated, 0, $lastSpace);
    }

    return rtrim($truncated) . '…';
}

function extract_single_blog($raw): string
{
    $text = trim((string) $raw);
    if ($text === '') {
        return '';
    }

    $cutPositions = [];
    $headingPatterns = [
        '/<h1[^>]*>/i',
        '/^\s*#\s+/m',
    ];

    foreach ($headingPatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) && count($matches[0]) > 1) {
            $cutPositions[] = (int) ($matches[0][1][1] ?? 0);
        }
    }

    $titlePattern = '/(^|\n)\s*(Title|Blog\s+Title|Heading|Article\s+Title)\s*[:：]/i';
    if (preg_match_all($titlePattern, $text, $matches, PREG_OFFSET_CAPTURE) && count($matches[0]) > 1) {
        $cutPositions[] = (int) ($matches[0][1][1] ?? 0);
    }

    $introPattern = '/(^|\n)\s*(Introduction|Intro)\s*[:：]/i';
    if (preg_match_all($introPattern, $text, $matches, PREG_OFFSET_CAPTURE) && count($matches[0]) > 1) {
        $cutPositions[] = (int) ($matches[0][1][1] ?? 0);
    }

    $validCuts = array_values(array_filter($cutPositions, static function ($position): bool {
        return is_int($position) && $position > 0;
    }));

    if (!empty($validCuts)) {
        $text = substr($text, 0, min($validCuts));
    }

    return rtrim($text);
}

function ai_clean_blog_html(string $html, string $title, array $brandProfile = []): array
{
    $clean = ai_clean_text_output($html, $brandProfile);
    $clean = preg_replace('/\*+|\[|\]|-{2,}/', ' ', $clean);
    $clean = preg_replace('/\n{3,}/', "\n\n", (string) $clean);

    if (!preg_match('/<\w+[^>]*>/', (string) $clean)) {
        $blocks = ai_normalize_paragraphs_from_text((string) $clean);
        $clean = '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        foreach ($blocks as $block) {
            $clean .= '<p>' . htmlspecialchars($block, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    $wrapped = '<div>' . $clean . '</div>';
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $body = $dom->documentElement;
    if ($body === null) {
        return ['html' => '', 'paragraphs' => [], 'word_count' => 0];
    }

    $h1Nodes = iterator_to_array($xpath->query('//h1') ?: []);
    if (empty($h1Nodes) && $title !== '') {
        $h1 = $dom->createElement('h1', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
        $body->insertBefore($h1, $body->firstChild);
        $h1Nodes[] = $h1;
    }

    if (count($h1Nodes) > 1) {
        foreach (array_slice($h1Nodes, 1) as $node) {
            $newNode = $dom->createElement('h2', $node->textContent);
            $node->parentNode?->replaceChild($newNode, $node);
        }
    }

    foreach (['h1', 'h2', 'h3', 'p', 'li'] as $tag) {
        $nodes = iterator_to_array($xpath->query('//' . $tag) ?: []);
        foreach ($nodes as $node) {
            $text = trim((string) $node->textContent);
            if ($text === '') {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    $htmlOutput = '';
    foreach ($body->childNodes as $child) {
        $htmlOutput .= $dom->saveHTML($child);
    }

    $headingCount = $xpath->query('//h2|//h3')->length;
    $blocks = iterator_to_array($xpath->query('//h1|//h2|//h3|//p|//li') ?: []);
    $paragraphs = [];
    foreach ($blocks as $block) {
        $text = trim(preg_replace('/\s+/', ' ', (string) $block->textContent) ?? '');
        if ($text !== '') {
            $paragraphs[] = $text;
        }
    }

    $wordCount = str_word_count(strip_tags($htmlOutput));

    if ($headingCount < 3 && $title !== '') {
        $htmlOutput = '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $htmlOutput;
    }

    return [
        'html' => $htmlOutput,
        'paragraphs' => $paragraphs,
        'word_count' => $wordCount,
    ];
}

function ai_paragraphs_to_html(array $paragraphs): string
{
    $htmlParts = [];
    foreach ($paragraphs as $paragraph) {
        if (preg_match('/^(#{1,6})\s+(.+)$/', $paragraph, $matches)) {
            $level = min(6, max(1, strlen($matches[1])));
            $text = trim($matches[2]);
            $htmlParts[] = sprintf('<h%d>%s</h%d>', $level, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), $level);
        } else {
            $htmlParts[] = '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }

    return implode("\n", $htmlParts);
}

function ai_keywords_to_tags(string $keywords): array
{
    $parts = preg_split('/[\n,]+/', $keywords) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim($part);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return $tags;
}

function blog_log_event(string $event, array $context = []): void
{
    try {
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        $timestamp = gmdate(DateTimeInterface::ATOM);
    }
    $payload = [
        'event' => $event,
        'timestamp' => $timestamp,
        'context' => $context,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        error_log('blog_event:' . $encoded);
    }
}

function blog_ai_find_raw_draft(int $adminId, ?string $draftId = null): ?array
{
    $service = blog_service();
    $drafts = $service->listDrafts();
    $matches = [];
    foreach ($drafts as $draft) {
        $ownerId = $draft['extra']['owner_id'] ?? ($draft['author']['id'] ?? '');
        if ($draftId !== null) {
            if (($draft['draft_id'] ?? '') === $draftId) {
                return $draft;
            }
            continue;
        }
        if ((string) $ownerId === (string) $adminId) {
            $matches[] = $draft;
        }
    }
    if ($draftId !== null) {
        return null;
    }
    if (empty($matches)) {
        return null;
    }
    usort($matches, static function (array $a, array $b): int {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });
    return $matches[0];
}

function blog_ai_find_draft_for_admin(int $adminId, ?string $draftId = null): ?array
{
    $draft = blog_ai_find_raw_draft($adminId, $draftId);
    if ($draft === null) {
        return null;
    }

    $extra = $draft['extra'] ?? [];

    return [
        'draftId' => $draft['draft_id'] ?? null,
        'postId' => $draft['draft_id'] ?? null,
        'title' => $draft['title'] ?? '',
        'brief' => $extra['brief'] ?? '',
        'keywords' => $extra['keywords'] ?? '',
        'tone' => $extra['tone'] ?? '',
        'paragraphs' => $extra['paragraphs'] ?? [],
        'bodyHtml' => $extra['body_html'] ?? '',
        'coverImage' => $draft['hero_image'] ?? '',
        'coverImageAlt' => $draft['hero_image_alt'] ?? '',
        'length' => blog_normalize_length_meta($extra['length'] ?? []),
        'wordCount' => isset($extra['word_count']) ? (int) $extra['word_count'] : 0,
        'updatedAt' => $draft['updated_at'] ?? null,
        'slug' => $draft['slug'] ?? null,
    ];
}

function blog_normalize_length_meta($value, array $fallback = []): array
{
    $preset = 'standard';
    $custom = null;

    if (is_array($value)) {
        if (isset($value['preset'])) {
            $preset = (string) $value['preset'];
        }
        if (isset($value['customWordCount'])) {
            $custom = $value['customWordCount'];
        } elseif (isset($value['custom'])) {
            $custom = $value['custom'];
        }
    } elseif (is_string($value)) {
        $preset = $value;
    }

    if ($preset === 'standard' && isset($fallback['preset'])) {
        $preset = (string) $fallback['preset'];
    }
    if ($custom === null && isset($fallback['customWordCount'])) {
        $custom = $fallback['customWordCount'];
    } elseif ($custom === null && isset($fallback['custom'])) {
        $custom = $fallback['custom'];
    }

    return blog_resolve_length_config($preset, $custom);
}

function blog_ai_build_payload(array $data, int $adminId, ?array $existing = null): array
{
    $existingExtra = $existing['extra'] ?? [];
    $paragraphsSource = $data['paragraphs'] ?? $existingExtra['paragraphs'] ?? [];
    $paragraphs = ai_normalize_paragraphs($paragraphsSource);

    $title = trim((string) ($data['title'] ?? ($existing['title'] ?? '')));
    if ($title === '') {
        throw new RuntimeException('Draft title is required.');
    }

    $brief = trim((string) ($data['brief'] ?? ($existingExtra['brief'] ?? '')));
    $keywords = trim((string) ($data['keywords'] ?? ($existingExtra['keywords'] ?? '')));
    $tone = trim((string) ($data['tone'] ?? ($existingExtra['tone'] ?? '')));
    $coverImage = trim((string) ($data['coverImage'] ?? ($existing['hero_image'] ?? '')));
    if ($coverImage !== '') {
        $coverImage = blog_ai_normalize_cover_image($coverImage);
    }
    $coverAlt = trim((string) ($data['coverImageAlt'] ?? ($existing['hero_image_alt'] ?? '')));

    $bodyHtml = trim((string) ($data['bodyHtml'] ?? ($existingExtra['body_html'] ?? '')));
    if ($bodyHtml !== '') {
        $article = ai_clean_blog_html($bodyHtml, $title);
        $bodyHtml = $article['html'];
        $paragraphs = !empty($article['paragraphs']) ? $article['paragraphs'] : $paragraphs;
    }

    if (empty($paragraphs)) {
        throw new RuntimeException('Draft must contain content.');
    }

    if ($bodyHtml === '') {
        $bodyHtml = ai_paragraphs_to_html($paragraphs);
    }
    $summary = blog_render_excerpt($bodyHtml, $brief);
    $tags = ai_keywords_to_tags($keywords);

    $length = blog_normalize_length_meta($data['length'] ?? [], $existingExtra['length'] ?? []);
    $wordCount = isset($data['wordCount']) ? (int) $data['wordCount'] : str_word_count(strip_tags($bodyHtml));

    $currentUser = current_user();
    $authorName = isset($data['authorName']) && $data['authorName'] !== ''
        ? (string) $data['authorName']
        : trim((string) ($currentUser['full_name'] ?? 'Administrator'));

    $attachments = $data['attachments'] ?? ($existing['attachments'] ?? []);

    return [
        'title' => $title,
        'summary' => $summary,
        'body_html' => $bodyHtml,
        'hero_image' => $coverImage,
        'hero_image_alt' => $coverAlt,
        'tags' => $tags,
        'author' => [
            'id' => (string) $adminId,
            'name' => $authorName,
        ],
        'attachments' => $attachments,
        'extra' => [
            'owner_id' => (string) $adminId,
            'brief' => $brief,
            'keywords' => $keywords,
            'tone' => $tone,
            'paragraphs' => $paragraphs,
            'body_html' => $bodyHtml,
            'length' => $length,
            'word_count' => $wordCount,
            'source' => 'ai-studio',
        ],
    ];
}

function blog_ai_save_draft(int $adminId, array $data, ?string $draftId = null): array
{
    $service = blog_service();
    $existing = $draftId !== null ? blog_ai_find_raw_draft($adminId, $draftId) : null;

    $payload = blog_ai_build_payload($data, $adminId, $existing);

    if ($draftId !== null) {
        return $service->updateDraft($draftId, $payload);
    }

    return $service->createDraft($payload);
}

function blog_ai_update_cover_image(int $adminId, string $path, string $alt, ?string $draftId = null): void
{
    $existing = blog_ai_find_raw_draft($adminId, $draftId);
    if ($existing === null) {
        return;
    }

    $data = [
        'title' => $existing['title'] ?? '',
        'brief' => $existing['extra']['brief'] ?? '',
        'keywords' => $existing['extra']['keywords'] ?? '',
        'tone' => $existing['extra']['tone'] ?? '',
        'paragraphs' => $existing['extra']['paragraphs'] ?? [],
        'coverImage' => $path,
        'coverImageAlt' => $alt,
        'attachments' => $existing['attachments'] ?? [],
    ];

    blog_ai_save_draft($adminId, $data, $existing['draft_id'] ?? null);
}

function blog_ai_normalize_cover_image(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    try {
        return blog_service()->promoteExternalAsset($path);
    } catch (Throwable $exception) {
        throw new RuntimeException('Unable to store hero image: ' . $exception->getMessage());
    }
}

function sse_emit(string $event, array $data): void
{
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $encoded = '{}';
    }

    echo 'event: ' . $event . "\n";
    echo 'data: ' . $encoded . "\n\n";
    @ob_flush();
    flush();
}

function handle_sandbox_text(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST for sandbox text requests.']);
        return;
    }

    $payload = decode_json_body();
    $prompt = trim((string) ($payload['prompt'] ?? ''));
    $useBrandProfile = (bool) ($payload['use_brand_profile'] ?? false);

    if ($prompt === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Prompt is required.']);
        return;
    }

    $brandNotice = null;
    if ($useBrandProfile) {
        $brandProfile = ai_smart_marketing_brand_profile_load();
        if (ai_smart_marketing_brand_profile_is_empty($brandProfile)) {
            $brandNotice = 'Brand Profile not configured; continuing without it.';
            $useBrandProfile = false;
        } else {
            $block = ai_smart_marketing_brand_profile_block($brandProfile);
            if ($block !== '') {
                $prompt .= "\n\n" . $block;
            }
        }
    }

    $settings = ai_settings_load();
    if (($settings['api_key'] ?? '') === '') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing in settings.']);
        return;
    }

    try {
        $text = ai_gemini_generate_text($settings, $prompt);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'text' => $text,
            'notice' => $brandNotice,
            'usedBrandProfile' => $useBrandProfile,
        ]);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'sandbox-text',
            'prompt' => $prompt,
            'use_brand_profile' => $useBrandProfile,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_sandbox_image(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST for sandbox image requests.']);
        return;
    }

    $payload = decode_json_body();
    $prompt = trim((string) ($payload['prompt'] ?? ''));
    $aspectRatio = ai_normalize_image_aspect_ratio((string) ($payload['aspect_ratio'] ?? '1:1'));
    $useBrandProfile = (bool) ($payload['use_brand_profile'] ?? false);

    if ($prompt === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Prompt is required.']);
        return;
    }

    $settings = ai_settings_load();
    if (($settings['api_key'] ?? '') === '') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing in settings.']);
        return;
    }

    try {
        $brandNotice = null;
        $usedBrandProfile = false;
        $brandBlock = '';
        if ($useBrandProfile) {
            $smartMarketingProfile = load_smart_marketing_brand_profile();
            if (ai_smart_marketing_brand_profile_is_empty($smartMarketingProfile)) {
                $brandNotice = 'Brand Profile not configured; generating without it.';
                $useBrandProfile = false;
            } else {
                $brandBlock = ai_smart_marketing_brand_profile_visual_block($smartMarketingProfile);
                $usedBrandProfile = $brandBlock !== '';
            }
        }

        $segments = [$prompt];
        $candidates = ai_image_generation_candidates($aspectRatio);
        if (!empty($candidates)) {
            $primary = $candidates[0];
            $segments[] = 'Target aspect ratio: ' . $aspectRatio . ' (size ' . $primary['width'] . 'x' . $primary['height'] . '). If unavailable, pick the closest supported size in the same orientation.';
        }
        if ($brandBlock !== '') {
            $segments[] = $brandBlock;
        }
        if ($usedBrandProfile) {
            $segments[] = 'Use brand overlays only when the prompt implies a marketing creative or poster. Keep logo placement subtle and avoid forcing text onto scenic art.';
        }

        $assembledPrompt = implode("\n\n", array_filter($segments));

        $image = null;
        $sizeUsed = '';
        $notice = $brandNotice;
        $lastError = null;

        foreach ($candidates as $candidate) {
            try {
                $image = ai_gemini_generate_image($settings, $assembledPrompt, [
                    'dimensions' => ['width' => $candidate['width'], 'height' => $candidate['height']],
                    'aspect_ratio' => $candidate['ratio'],
                ]);
                if ((int) $candidate['width'] > 0 && (int) $candidate['height'] > 0) {
                    $sizeUsed = $candidate['width'] . 'x' . $candidate['height'];
                }
                if (!empty($candidate['fallback'])) {
                    $notice = $notice ? $notice . ' Used fallback size due to API constraints.' : 'Used fallback size due to API constraints.';
                }
                break;
            } catch (Throwable $exception) {
                $lastError = $exception;
                if (!ai_is_image_size_error($exception->getMessage())) {
                    throw $exception;
                }
                $notice = $notice ? $notice . ' Used fallback size due to API constraints.' : 'Used fallback size due to API constraints.';
                continue;
            }
        }

        if ($image === null) {
            if ($lastError !== null) {
                throw $lastError;
            }
            throw new RuntimeException('Gemini did not return an image.');
        }

        $image['aspect_ratio'] = $image['aspect_ratio'] ?? $aspectRatio;
        if ($sizeUsed !== '') {
            $image['size_used'] = $sizeUsed;
        }

        ai_usage_register_image([
            'action' => 'sandbox-image',
            'aspect_ratio' => $aspectRatio,
            'size_used' => $sizeUsed,
            'brand_profile' => $usedBrandProfile ? 'on' : 'off',
        ]);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'image' => $image,
            'usedBrandProfile' => $usedBrandProfile,
            'notice' => $notice,
        ]);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'sandbox-image',
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
            'use_brand_profile' => $useBrandProfile,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_sandbox_tts(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST for sandbox audio requests.']);
        return;
    }

    $payload = decode_json_body();
    $text = trim((string) ($payload['text'] ?? ''));
    $format = trim((string) ($payload['format'] ?? 'mp3'));

    if ($text === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Text is required.']);
        return;
    }

    $settings = ai_settings_load();
    if (($settings['api_key'] ?? '') === '') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing in settings.']);
        return;
    }

    try {
        $audio = ai_gemini_generate_tts($settings, $text, $format);
        ai_usage_register_tts($text, ['action' => 'sandbox-tts']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'audio' => $audio]);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'sandbox-tts',
            'text' => $text,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_scheduler_status(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET to fetch scheduler status.']);
        return;
    }

    $settings = ai_scheduler_settings_load();
    $logs = array_reverse(ai_scheduler_logs_load());
    $automations = $settings['automations'];
    $nextRun = null;
    foreach ($automations as $automation) {
        $status = ai_scheduler_normalize_status($automation['status'] ?? 'active');
        $candidate = $automation['next_run'] ?? null;
        if ($status !== 'active' || !is_string($candidate) || $candidate === '') {
            continue;
        }
        if ($nextRun === null || strcmp($candidate, $nextRun) < 0) {
            $nextRun = $candidate;
        }
    }

    $festivals = ai_scheduler_upcoming_festivals();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'timezone' => $settings['timezone'],
        'automations' => $automations,
        'next_run' => $nextRun,
        'logs' => $logs,
        'festivals' => $festivals,
    ]);
}

function handle_scheduler_save(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to update scheduler settings.']);
        return;
    }

    $payload = decode_json_body();
    if (empty($payload) && !empty($_POST)) {
        $payload = $_POST;
    }

    $token = isset($payload['csrf_token']) ? trim((string) $payload['csrf_token']) : '';
    if (!verify_csrf_token($token !== '' ? $token : null)) {
        header('Content-Type: application/json');
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh and retry.']);
        return;
    }

    $entries = isset($payload['automations']) && is_array($payload['automations']) ? $payload['automations'] : [];
    if (empty($entries)) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Add at least one automation entry.']);
        return;
    }

    try {
        $state = ai_scheduler_save_entries($entries);
    } catch (Throwable $exception) {
        ai_error_log_append('API failure', $exception->getMessage(), ['action' => 'scheduler-save']);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'automations' => $state['automations'],
    ]);
}

function handle_scheduler_run(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to trigger the scheduler.']);
        return;
    }

    $payload = decode_json_body();
    if (empty($payload) && !empty($_POST)) {
        $payload = $_POST;
    }

    $token = isset($payload['csrf_token']) ? trim((string) $payload['csrf_token']) : '';
    if (!verify_csrf_token($token !== '' ? $token : null)) {
        header('Content-Type: application/json');
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh and retry.']);
        return;
    }

    $automationId = isset($payload['automation_id']) ? trim((string) $payload['automation_id']) : '';
    if ($automationId === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Automation id is required.']);
        return;
    }

    try {
        $result = perform_scheduler_run($adminId, $automationId);
        header('Content-Type: application/json');
        echo json_encode(['success' => true] + $result);
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'scheduler-run',
            'automation_id' => $automationId,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_scheduler_action(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to manage scheduler actions.']);
        return;
    }

    $payload = decode_json_body();
    if (empty($payload) && !empty($_POST)) {
        $payload = $_POST;
    }

    $token = isset($payload['csrf_token']) ? trim((string) $payload['csrf_token']) : '';
    if (!verify_csrf_token($token !== '' ? $token : null)) {
        header('Content-Type: application/json');
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh and retry.']);
        return;
    }

    $automationId = isset($payload['automation_id']) ? trim((string) $payload['automation_id']) : '';
    $operation = strtolower(trim((string) ($payload['operation'] ?? '')));
    if ($automationId === '' || $operation === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Automation id and action are required.']);
        return;
    }

    try {
        switch ($operation) {
            case 'activate':
                $state = ai_scheduler_set_status($automationId, 'active');
                break;
            case 'pause':
                $state = ai_scheduler_set_status($automationId, 'paused');
                break;
            case 'delete':
                $state = ai_scheduler_delete_automation($automationId);
                break;
            default:
                throw new RuntimeException('Unsupported scheduler action.');
        }
    } catch (Throwable $exception) {
        ai_error_log_append('API failure', $exception->getMessage(), ['action' => 'scheduler-action']);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'automations' => $state['automations']]);
}

function perform_scheduler_run(int $adminId, string $automationId): array
{
    $settings = ai_settings_load();
    if (($settings['api_key'] ?? '') === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    $automation = ai_scheduler_find_automation($automationId);
    if ($automation === null) {
        throw new RuntimeException('Automation not found.');
    }

    $topic = trim((string) ($automation['topic'] ?? ''));
    if ($topic === '') {
        throw new RuntimeException('Automation topic is empty.');
    }

    $status = ai_scheduler_normalize_status($automation['status'] ?? 'active');
    if ($status === 'completed') {
        throw new RuntimeException('This automation is already completed. Duplicate runs are blocked.');
    }

    $title = trim((string) ($automation['title'] ?? ''));
    if ($title === '') {
        $title = $topic;
    }

    $contextLine = trim((string) ($automation['description'] ?? ''));
    $festivalNote = '';
    if (isset($automation['festival']['name'])) {
        $festivalNote = 'The story should reference ' . $automation['festival']['name'] . ' on ' . ($automation['festival']['date'] ?? '') . '. ';
    }

    $prompt = <<<PROMPT
You are the editorial voice of Dakshayani Energy. Prepare a complete blog draft focused on "{$topic}".
{$festivalNote}{$contextLine}
Respond using this structure:
Title: <concise headline>
Summary: <two sentences>
Body:
<5-7 markdown paragraphs with headings where suitable>
Keep the tone informative, optimistic, and tailored to clean energy professionals in India.
PROMPT;

    $blogText = ai_gemini_generate_text($settings, $prompt);

    $lines = preg_split('/\r?\n/', trim($blogText)) ?: [];
    $detectedTitle = $title;
    $summary = '';
    $bodyLines = [];
    foreach ($lines as $line) {
        if ($detectedTitle === $title && stripos($line, 'title:') === 0) {
            $titleCandidate = trim(substr($line, strlen('title:')));
            if ($titleCandidate !== '') {
                $detectedTitle = $titleCandidate;
                continue;
            }
        }
        if ($summary === '' && stripos($line, 'summary:') === 0) {
            $summaryCandidate = trim(substr($line, strlen('summary:')));
            if ($summaryCandidate !== '') {
                $summary = $summaryCandidate;
                continue;
            }
        }
        if (stripos($line, 'body:') === 0) {
            $bodyLines[] = trim(substr($line, strlen('body:')));
            continue;
        }
        $bodyLines[] = $line;
    }

    if ($detectedTitle !== '') {
        $title = $detectedTitle;
    }

    $bodyText = trim(implode("\n", $bodyLines));
    $paragraphs = ai_normalize_paragraphs_from_text($bodyText !== '' ? $bodyText : $blogText);
    if (empty($paragraphs)) {
        throw new RuntimeException('Gemini returned empty content.');
    }

    $images = [];
    $imageCount = random_int(1, 3);
    for ($i = 0; $i < $imageCount; $i++) {
        try {
            $snippet = $paragraphs[$i % count($paragraphs)] ?? $topic;
            $imagePrompt = sprintf('%s – editorial illustration %d. %s', $topic, $i + 1, $snippet);
            $image = ai_gemini_generate_image($settings, $imagePrompt);
            ai_usage_register_image(['action' => 'scheduler-image']);
            $images[] = $image;
        } catch (Throwable $exception) {
            ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
                'action' => 'scheduler-image',
                'prompt' => $topic,
                'automation_id' => $automationId,
            ]);
        }
    }

    $summaryPrompt = "Craft a 45-second spoken summary for Dakshayani Energy on the topic: {$topic}. Highlight the core insights in warm, confident language. Source material: " . implode(' ', array_slice($paragraphs, 0, 5));
    $summaryText = ai_gemini_generate_text($settings, $summaryPrompt);
    $summaryText = trim(mb_substr($summaryText, 0, 800));
    if ($summaryText === '') {
        throw new RuntimeException('Gemini returned an empty summary.');
    }

    $audio = ai_gemini_generate_tts($settings, $summaryText, 'mp3');
    ai_usage_register_tts($summaryText, ['action' => 'scheduler-tts']);

    $draftPath = ai_scheduler_store_generated_post([
        'topic' => $topic,
        'title' => $title,
        'summary' => $summary !== '' ? $summary : ai_build_excerpt_from_paragraphs($paragraphs),
        'paragraphs' => $paragraphs,
        'images' => $images,
        'audio' => $audio,
        'source' => 'automation-scheduler',
        'automation_id' => $automationId,
    ]);

    $service = blog_service();
    $bodyHtml = ai_paragraphs_to_html($paragraphs);
    $attachments = [];
    $heroImage = '';
    foreach ($images as $index => $image) {
        $path = isset($image['path']) ? $service->promoteExternalAsset($image['path']) : '';
        if ($path === '') {
            continue;
        }
        if ($heroImage === '') {
            $heroImage = $path;
        }
        $attachments[] = $path;
    }

    $audioAttachment = isset($audio['path']) ? $service->promoteExternalAsset($audio['path']) : '';
    if ($audioAttachment !== '') {
        $attachments[] = $audioAttachment;
    }

    $tags = ['Automation', 'AI Studio'];
    if (!empty($automation['festival']['name'] ?? '')) {
        $tags[] = $automation['festival']['name'];
    }
    $tags = array_values(array_unique($tags));

    $draft = $service->createDraft([
        'title' => $title,
        'summary' => $summary !== '' ? $summary : ai_build_excerpt_from_paragraphs($paragraphs),
        'body_html' => $bodyHtml,
        'tags' => $tags,
        'hero_image' => $heroImage,
        'hero_image_alt' => $title,
        'attachments' => $attachments,
        'author' => [
            'id' => (string) $adminId,
            'name' => 'Automation Scheduler',
        ],
        'extra' => [
            'source' => 'automation-scheduler',
            'automation_id' => $automationId,
            'festival' => $automation['festival'] ?? null,
        ],
    ]);

    $published = $service->publishPost($draft['draft_id'], ['actor' => $adminId]);

    $now = new DateTimeImmutable('now', new DateTimeZone(ai_scheduler_default_timezone()));
    $record = ai_scheduler_record_run($automationId, [
        'last_run' => $now->format(DateTimeInterface::ATOM),
        'updated_at' => $now->format(DateTimeInterface::ATOM),
        'status' => ($automation['schedule']['type'] ?? 'once') === 'once' ? 'completed' : ($automation['status'] ?? 'active'),
        'blog_reference' => [
            'draft_id' => $draft['draft_id'],
            'published_slug' => $published['slug'] ?? '',
            'url' => $published['url'] ?? '',
            'title' => $title,
        ],
    ]);

    ai_scheduler_logs_append([
        'automation_id' => $automationId,
        'topic' => $topic,
        'draft' => $draftPath,
        'title' => $title,
        'summary' => $summaryText,
        'images' => $images,
        'audio' => $audio,
        'blog' => [
            'slug' => $published['slug'] ?? '',
            'url' => $published['url'] ?? '',
        ],
    ]);

    $updatedAutomation = ai_scheduler_find_automation($automationId);

    return [
        'automation' => $updatedAutomation,
        'blog' => $published,
        'draft' => $draft,
        'images' => $images,
        'audio' => $audio,
        'state' => $record,
    ];
}

function handle_usage_summary(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET to fetch usage summary.']);
        return;
    }

    $usage = ai_usage_summary();
    $errors = array_reverse(ai_error_log_load());

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'usage' => $usage,
        'errors' => $errors,
    ]);
}

function handle_error_retry(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST to retry actions.']);
        return;
    }

    $errors = ai_error_log_load();
    if (!$errors) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No errors recorded.']);
        return;
    }

    $last = end($errors);
    $context = is_array($last['context'] ?? null) ? $last['context'] : [];
    $action = (string) ($context['action'] ?? '');

    try {
        switch ($action) {
            case 'sandbox-text':
                $prompt = trim((string) ($context['prompt'] ?? ''));
                if ($prompt === '') {
                    throw new RuntimeException('No prompt captured for retry.');
                }
                $settings = ai_settings_load();
                $text = ai_gemini_generate_text($settings, $prompt);
                $payload = ['type' => 'sandbox-text', 'text' => $text];
                break;
            case 'sandbox-image':
                $prompt = trim((string) ($context['prompt'] ?? ''));
                if ($prompt === '') {
                    throw new RuntimeException('No prompt captured for retry.');
                }
                $settings = ai_settings_load();
                $image = ai_gemini_generate_image($settings, $prompt);
                ai_usage_register_image(['action' => 'sandbox-image']);
                $payload = ['type' => 'sandbox-image', 'image' => $image];
                break;
            case 'sandbox-tts':
                $textInput = trim((string) ($context['text'] ?? ''));
                if ($textInput === '') {
                    throw new RuntimeException('No text captured for retry.');
                }
                $settings = ai_settings_load();
                $audio = ai_gemini_generate_tts($settings, $textInput, 'mp3');
                ai_usage_register_tts($textInput, ['action' => 'sandbox-tts']);
                $payload = ['type' => 'sandbox-tts', 'audio' => $audio];
                break;
            case 'scheduler-run':
                $automationId = trim((string) ($context['automation_id'] ?? ''));
                if ($automationId === '') {
                    throw new RuntimeException('No automation id captured for retry.');
                }
                $payload = ['type' => 'scheduler-run'] + perform_scheduler_run($adminId, $automationId);
                break;
            default:
                throw new RuntimeException('Last error cannot be retried automatically.');
        }
    } catch (Throwable $exception) {
        ai_error_log_append(ai_classify_error($exception->getMessage()), $exception->getMessage(), [
            'action' => 'retry-' . $action,
        ]);
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'payload' => $payload]);
}

function ai_classify_error(string $message): string
{
    $normalized = strtolower($message);
    if (str_contains($normalized, 'empty')) {
        return 'Empty response';
    }
    if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
        return 'Timeout';
    }
    if (str_contains($normalized, '429') || str_contains($normalized, 'rate')) {
        return 'Rate limit';
    }

    return 'API failure';
}

// -----------------------------------------------------------------------------
// Festival & Occasion Greetings
// -----------------------------------------------------------------------------

function greetings_ensure_ready(): array
{
    $settings = ai_settings_load();
    if (!($settings['enabled'] ?? false)) {
        throw new RuntimeException('AI is disabled. Enable Gemini to generate greetings.');
    }

    if (($settings['api_key'] ?? '') === '') {
        throw new RuntimeException('Gemini API key is missing.');
    }

    return $settings;
}

function greetings_parse_payload(): array
{
    $body = file_get_contents('php://input');
    $payload = [];
    if (is_string($body) && trim($body) !== '') {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $payload = [];
        }
    }

    return is_array($payload) ? $payload : [];
}

function handle_greetings_bootstrap(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET.']);
        return;
    }

    $settings = ai_settings_load();
    $videoStatus = ai_gemini_validate_video_model($settings);
    $greetings = ($settings['enabled'] ?? false) ? ai_greetings_run_autodraft($settings, $adminId) : ai_greetings_load();
    usort($greetings, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'settings' => [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'models' => $settings['models'] ?? [],
            'videoStatus' => $videoStatus,
        ],
        'greetings' => $greetings,
        'events' => ai_greeting_upcoming_events(90),
        'auto' => ai_greetings_auto_settings(),
    ]);
}

function handle_greetings_generate_text(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    try {
        $settings = greetings_ensure_ready();
        $payload = greetings_parse_payload();
        $context = ai_greeting_normalize_request($payload);
        $text = ai_greeting_generate_text($settings, $context);
        $usesBrand = ($context['use_brand_profile'] ?? false) && !($context['brand_profile_empty'] ?? false);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'context' => $context,
            'text' => $text,
            'usesBrandProfile' => $usesBrand,
            'brandProfileMissing' => ($payload['use_brand_profile'] ?? false) && ($context['brand_profile_empty'] ?? false),
        ]);
    } catch (Throwable $exception) {
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_greetings_generate_media(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    try {
        $settings = greetings_ensure_ready();
        $payload = greetings_parse_payload();
        $context = ai_greeting_normalize_request($payload);
        $needImage = (bool) ($payload['want_image'] ?? true);
        $needVideo = (bool) ($payload['want_video'] ?? false);
        $videoStatus = ai_gemini_validate_video_model($settings);
        $usesBrand = ($context['use_brand_profile'] ?? false) && !($context['brand_profile_empty'] ?? false);

        $image = null;
        if ($needImage) {
            $image = ai_greeting_generate_image($settings, $context);
        }

        $video = null;
        if ($needVideo) {
            $video = ai_greeting_generate_video($settings, $context, $videoStatus);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'context' => $context,
            'image' => $image,
            'video' => $video,
            'videoStatus' => $videoStatus,
            'usesBrandProfile' => $usesBrand,
            'brandProfileMissing' => ($payload['use_brand_profile'] ?? false) && ($context['brand_profile_empty'] ?? false),
        ]);
    } catch (Throwable $exception) {
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_greetings_save_draft(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    try {
        $payload = greetings_parse_payload();
        $context = ai_greeting_normalize_request($payload['context'] ?? $payload);
        $record = [
            'occasion' => $context['occasion'],
            'occasion_date' => $context['occasion_date'],
            'audience' => $context['audience'],
            'platforms' => $context['platforms'],
            'languages' => $context['languages'],
            'tone' => $context['tone'],
            'solar_context' => $context['solar_context'],
            'media_type' => $context['media_type'],
            'captions' => $payload['captions'] ?? [],
            'long_text' => $payload['long_text'] ?? '',
            'sms_text' => $payload['sms_text'] ?? '',
            'image' => $payload['image'] ?? null,
            'video' => $payload['video'] ?? null,
            'storyboard' => $payload['storyboard'] ?? null,
            'created_by' => $adminId,
            'source' => $payload['source'] ?? 'manual',
            'uses_brand_profile' => ($context['use_brand_profile'] ?? false) && !($context['brand_profile_empty'] ?? false),
            'brand_snapshot' => $context['brand_snapshot'] ?? null,
        ];

        $saved = ai_greetings_add($record);
        $list = ai_greetings_load();
        usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'saved' => $saved, 'greetings' => $list]);
    } catch (Throwable $exception) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
    }
}

function handle_greetings_delete(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    $payload = greetings_parse_payload();
    $id = (string) ($payload['id'] ?? '');

    if ($id === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing greeting id.']);
        return;
    }

    ai_greetings_delete($id);
    $list = ai_greetings_load();
    usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'greetings' => $list]);
}

function handle_greetings_list(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use GET.']);
        return;
    }

    $list = ai_greetings_load();
    usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'greetings' => $list]);
}

function handle_greetings_send_smart_marketing(int $adminId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    $payload = greetings_parse_payload();
    $id = (string) ($payload['id'] ?? '');
    if ($id === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing greeting id.']);
        return;
    }

    $greeting = ai_greetings_find($id);
    if ($greeting === null) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Greeting not found.']);
        return;
    }

    $asset = smart_marketing_store_asset('greeting', [
        'id' => $greeting['id'],
        'occasion' => $greeting['occasion'] ?? 'Greeting',
        'occasion_date' => $greeting['occasion_date'] ?? null,
        'captions' => $greeting['captions'] ?? [],
        'long_text' => $greeting['long_text'] ?? '',
        'sms_text' => $greeting['sms_text'] ?? '',
        'image' => $greeting['image'] ?? null,
        'video' => $greeting['video'] ?? null,
        'storyboard' => $greeting['storyboard'] ?? null,
        'source' => 'AI Studio Festival Greetings',
        'uses_brand_profile' => (bool) ($greeting['uses_brand_profile'] ?? false),
        'brand_snapshot' => $greeting['brand_snapshot'] ?? null,
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'asset' => $asset]);
}

function handle_greetings_auto_settings(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        return;
    }

    $payload = greetings_parse_payload();
    $enabled = (bool) ($payload['enabled'] ?? false);
    $days = max(1, (int) ($payload['days_before'] ?? 3));

    ai_greetings_auto_settings_save([
        'enabled' => $enabled,
        'days_before' => $days,
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'auto' => ai_greetings_auto_settings()]);
}
