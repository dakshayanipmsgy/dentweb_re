<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_gemini.php';

require_admin();
$admin = current_user();
$adminId = (int) ($admin['id'] ?? 0);

$csrfToken = $_SESSION['csrf_token'] ?? '';
$settings = ai_settings_load();
$brandProfile = ai_brand_profile_load();
$smartMarketingBrandProfile = ai_smart_marketing_brand_profile_load();
$chatHistory = ai_chat_history_load($adminId);

$flashContext = $_SESSION['ai_flash_context'] ?? null;
if ($flashContext !== null) {
    unset($_SESSION['ai_flash_context']);
}
$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
if (is_array($flashData)) {
    $flashMessage = is_string($flashData['message'] ?? null) ? trim($flashData['message']) : '';
    $candidateTone = is_string($flashData['type'] ?? null) ? strtolower($flashData['type']) : 'info';
    if (in_array($candidateTone, ['success', 'info', 'warning', 'error'], true)) {
        $flashTone = $candidateTone;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        $_SESSION['ai_flash_context'] = 'settings';
        header('Location: admin-ai-studio.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save-settings':
                $candidate = ai_collect_settings_from_request($settings, $_POST);
                if ($candidate['api_key'] === '') {
                    throw new RuntimeException('Gemini API key is required to save settings.');
                }
                $ping = ai_gemini_ping($candidate, 'Configuration validation ping from AI Studio');
                if (!($ping['ok'] ?? false)) {
                    $error = is_string($ping['error'] ?? null) ? $ping['error'] : 'Unable to connect to Gemini.';
                    throw new RuntimeException('Gemini validation failed: ' . $error);
                }

                $videoCheck = ai_gemini_validate_video_model($candidate);
                $videoNote = $videoCheck['message'] ?? '';

                ai_settings_save($candidate);
                $settings = ai_settings_load();
                set_flash('success', 'Settings saved successfully. Connected. ' . $videoNote);
                $_SESSION['ai_flash_context'] = 'settings';
                header('Location: admin-ai-studio.php');
                exit;

            case 'test-connection':
                $candidate = ai_collect_settings_from_request($settings, $_POST);
                if ($candidate['api_key'] === '') {
                    throw new RuntimeException('Add a Gemini API key before testing the connection.');
                }
                $ping = ai_gemini_ping($candidate, 'Connection test from AI Studio');
                if (!($ping['ok'] ?? false)) {
                    $error = is_string($ping['error'] ?? null) ? $ping['error'] : 'Unable to connect to Gemini.';
                    throw new RuntimeException('Gemini test failed: ' . $error);
                }
                $videoCheck = ai_gemini_validate_video_model($candidate);
                $videoNote = $videoCheck['message'] ?? '';
                set_flash('success', 'Gemini connection confirmed. ' . $videoNote);
                $_SESSION['ai_flash_context'] = 'settings';
                header('Location: admin-ai-studio.php');
                exit;

            case 'save-brand-profile':
                $profile = [
                    'company_name' => (string) ($_POST['brand_company'] ?? ''),
                    'tagline' => (string) ($_POST['brand_tagline'] ?? ''),
                    'phone' => (string) ($_POST['brand_phone'] ?? ''),
                    'whatsapp' => (string) ($_POST['brand_whatsapp'] ?? ''),
                    'email' => (string) ($_POST['brand_email'] ?? ''),
                    'website' => (string) ($_POST['brand_website'] ?? ''),
                    'address' => (string) ($_POST['brand_address'] ?? ''),
                    'cta' => (string) ($_POST['brand_cta'] ?? ''),
                    'disclaimer' => (string) ($_POST['brand_disclaimer'] ?? ''),
                    'primary_color' => (string) ($_POST['brand_primary_color'] ?? ''),
                    'secondary_color' => (string) ($_POST['brand_secondary_color'] ?? ''),
                    'social' => [
                        'facebook' => (string) ($_POST['brand_facebook'] ?? ''),
                        'instagram' => (string) ($_POST['brand_instagram'] ?? ''),
                        'youtube' => (string) ($_POST['brand_youtube'] ?? ''),
                    ],
                ];

                $profile['logo'] = $brandProfile['logo'] ?? '';
                $profile['logo_secondary'] = $brandProfile['logo_secondary'] ?? '';

                if ((string) ($_POST['remove_logo'] ?? '0') === '1') {
                    $profile['logo'] = '';
                }
                if ((string) ($_POST['remove_logo_secondary'] ?? '0') === '1') {
                    $profile['logo_secondary'] = '';
                }

                $logoUpload = ai_brand_profile_store_upload($_FILES['brand_logo'] ?? null);
                if ($logoUpload !== '') {
                    $profile['logo'] = $logoUpload;
                }
                $logoSecondaryUpload = ai_brand_profile_store_upload($_FILES['brand_logo_secondary'] ?? null);
                if ($logoSecondaryUpload !== '') {
                    $profile['logo_secondary'] = $logoSecondaryUpload;
                }

                $brandProfile = ai_brand_profile_save($profile);
                set_flash('success', 'Brand profile updated. Future generations will use the new details.');
                $_SESSION['ai_flash_context'] = 'brand';
                header('Location: admin-ai-studio.php');
                exit;

            case 'reset-brand-profile':
                ai_brand_profile_reset();
                $brandProfile = ai_brand_profile_load();
                set_flash('info', 'Brand profile cleared. Content will stay generic until you add brand details.');
                $_SESSION['ai_flash_context'] = 'brand';
                header('Location: admin-ai-studio.php');
                exit;

            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
        $_SESSION['ai_flash_context'] = in_array($action, ['save-brand-profile', 'reset-brand-profile'], true) ? 'brand' : 'settings';
        header('Location: admin-ai-studio.php');
        exit;
    }
}

$settingsMaskedKey = ai_settings_masked_key($settings['api_key']);
$settingsUpdatedAt = $settings['updated_at'] ?? null;
$settingsUpdatedDisplay = '';
if (is_string($settingsUpdatedAt) && $settingsUpdatedAt !== '') {
    try {
        $dt = new DateTimeImmutable($settingsUpdatedAt);
        $settingsUpdatedDisplay = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        $settingsUpdatedDisplay = $settingsUpdatedAt;
    }
}

$chatHistoryJson = json_encode($chatHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$videoStatus = ['configured' => false, 'ok' => false, 'message' => 'Video model not configured (storyboard fallback).'];
try {
    $videoStatus = ai_gemini_validate_video_model($settings);
} catch (Throwable $exception) {
    $videoStatus = [
        'configured' => ($settings['models']['video'] ?? '') !== '',
        'ok' => false,
        'message' => 'Video Model Error – falling back to storyboard.',
        'error' => $exception->getMessage(),
    ];
}
$settingsForClient = [
    'enabled' => (bool) ($settings['enabled'] ?? false),
    'temperature' => ai_normalize_temperature($settings['temperature'] ?? 0.9),
    'maxTokens' => ai_normalize_max_tokens($settings['max_tokens'] ?? 1024),
    'models' => [
        'text' => $settings['models']['text'] ?? 'gemini-2.5-flash',
        'image' => $settings['models']['image'] ?? 'gemini-2.5-flash-image',
        'tts' => $settings['models']['tts'] ?? 'gemini-2.5-flash-preview-tts',
        'video' => $settings['models']['video'] ?? '',
    ],
    'videoStatus' => $videoStatus,
];
$settingsJson = json_encode($settingsForClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$brandProfileUpdatedDisplay = '';
if (is_string($brandProfile['updated_at'] ?? null) && $brandProfile['updated_at'] !== '') {
    try {
        $brandDt = new DateTimeImmutable($brandProfile['updated_at']);
        $brandProfileUpdatedDisplay = $brandDt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        $brandProfileUpdatedDisplay = $brandProfile['updated_at'];
    }
}
$brandProfileForClient = $brandProfile;
$brandProfileForClient['hasData'] = !ai_brand_profile_is_empty($brandProfile);
$brandProfileJson = json_encode($brandProfileForClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$smartMarketingBrandProfileReady = !ai_smart_marketing_brand_profile_is_empty($smartMarketingBrandProfile);
$smartMarketingBrandProfileForClient = $smartMarketingBrandProfileReady ? $smartMarketingBrandProfile : [];
$smartMarketingBrandProfileForClient['hasData'] = $smartMarketingBrandProfileReady;
$smartMarketingBrandProfileJson = json_encode($smartMarketingBrandProfileForClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$smartMarketingBrandName = $smartMarketingBrandProfileReady && is_string($smartMarketingBrandProfile['firm_name'] ?? null) && trim($smartMarketingBrandProfile['firm_name']) !== ''
    ? trim((string) $smartMarketingBrandProfile['firm_name'])
    : 'your brand';
$smartMarketingBrandPreviewText = $smartMarketingBrandProfileReady
    ? 'If enabled, the sandbox will automatically append ' . $smartMarketingBrandName . ' brand details (contact, CTA, social links) to your prompt.'
    : 'Brand Profile not found. Configure it in Smart Marketing CMO.';
$toastMeta = '';
$videoStatusMessage = is_string($videoStatus['message'] ?? null) ? $videoStatus['message'] : 'Video model not configured (storyboard fallback).';
if (($videoStatus['configured'] ?? false) && !($videoStatus['ok'] ?? true) && isset($videoStatus['error'])) {
    $videoStatusMessage .= ' (' . $videoStatus['error'] . ')';
}
if ($flashMessage !== '') {
    $toastMeta = json_encode([
        'message' => $flashMessage,
        'tone' => $flashTone,
        'context' => $flashContext,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AI Studio | Admin</title>
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
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
</head>
<body class="admin-ai-studio" data-theme="light">
  <main class="admin-ai-studio__shell">
    <header class="admin-ai-studio__header">
      <div>
        <p class="admin-ai-studio__subtitle">Admin workspace</p>
        <h1 class="admin-ai-studio__title">AI Studio</h1>
        <p class="admin-ai-studio__meta">Signed in as <strong><?= htmlspecialchars($admin['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-ai-studio__actions">
        <a href="admin-dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to overview</a>
        <a href="logout.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
      </div>
    </header>

    <section class="admin-panel" aria-labelledby="ai-settings">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-settings">AI Settings (Gemini-only)</h2>
          <p>Configure Gemini model codes, authentication, and response parameters for the admin workspace.</p>
        </div>
        <div class="ai-settings__status" role="status">
          <i class="fa-solid fa-circle-dot" aria-hidden="true"></i>
          <span><?= htmlspecialchars($settings['enabled'] ? 'AI responses enabled' : 'AI responses disabled', ENT_QUOTES) ?></span>
        </div>
      </div>

      <?php if ($flashMessage !== '' && $flashContext === 'settings'): ?>
      <div class="ai-settings__feedback ai-settings__feedback--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
      </div>
      <?php endif; ?>

      <form method="post" class="admin-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <input type="hidden" name="action" value="save-settings" data-ai-settings-action />
        <input type="hidden" name="ai_enabled" value="0" />
        <div class="ai-settings__grid">
          <label>
            Gemini Text Model Code
            <input type="text" name="gemini_text_model" value="<?= htmlspecialchars($settings['models']['text'] ?? 'gemini-2.5-flash', ENT_QUOTES) ?>" required autocomplete="off" />
            <small>Default: gemini-2.5-flash</small>
          </label>
          <label>
            Gemini Image Model Code
            <input type="text" name="gemini_image_model" value="<?= htmlspecialchars($settings['models']['image'] ?? 'gemini-2.5-flash-image', ENT_QUOTES) ?>" required autocomplete="off" />
            <small>Default: gemini-2.5-flash-image</small>
          </label>
          <label>
            Gemini TTS Model Code
            <input type="text" name="gemini_tts_model" value="<?= htmlspecialchars($settings['models']['tts'] ?? 'gemini-2.5-flash-preview-tts', ENT_QUOTES) ?>" required autocomplete="off" />
            <small>Default: gemini-2.5-flash-preview-tts</small>
          </label>
          <label>
            Video Model Code (optional)
            <input type="text" name="gemini_video_model" value="<?= htmlspecialchars($settings['models']['video'] ?? '', ENT_QUOTES) ?>" autocomplete="off" />
            <small>Set this to the identifier of your video generation model. Leave blank to use storyboard-only fallback.</small>
          </label>
          <label class="ai-settings__api">
            Gemini API Key
            <div class="ai-settings__api-field">
              <input
                type="password"
                name="api_key"
                id="ai-api-key"
                placeholder="<?= $settingsMaskedKey !== '' ? htmlspecialchars($settingsMaskedKey, ENT_QUOTES) : 'Enter Gemini API key' ?>"
                autocomplete="new-password"
              />
              <button type="button" class="btn btn-ghost btn-sm" data-ai-reveal data-api-key="<?= htmlspecialchars($settings['api_key'] ?? '', ENT_QUOTES) ?>">
                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                Reveal once
              </button>
            </div>
            <small>Leave blank to keep the stored key. The key is never logged.</small>
          </label>
        </div>

        <div class="ai-settings__controls">
          <label class="dashboard-toggle">
            <input type="checkbox" name="ai_enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?> />
            <span>AI On / Off</span>
          </label>
          <div class="ai-settings__range">
            <label for="ai-temperature">Temperature <span data-ai-temp-value><?= htmlspecialchars(number_format($settings['temperature'], 2, '.', ''), ENT_QUOTES) ?></span></label>
            <input type="range" id="ai-temperature" name="temperature" min="0" max="2" step="0.1" value="<?= htmlspecialchars((string) $settings['temperature'], ENT_QUOTES) ?>" />
          </div>
          <label class="ai-settings__max-tokens">
            Max token limit
            <input type="number" name="max_tokens" min="1" max="8192" value="<?= htmlspecialchars((string) $settings['max_tokens'], ENT_QUOTES) ?>" />
          </label>
          <div class="ai-settings__actions">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
              Save settings
            </button>
            <button type="submit" class="btn btn-ghost" data-ai-test>
              <i class="fa-solid fa-vial-circle-check" aria-hidden="true"></i>
              Test Gemini connection
            </button>
          </div>
        </div>
        <p class="ai-settings__meta">
          Using Gemini text model <strong><?= htmlspecialchars($settings['models']['text'] ?? 'gemini-2.5-flash', ENT_QUOTES) ?></strong> · Temperature <strong><?= htmlspecialchars(number_format($settings['temperature'], 2, '.', ''), ENT_QUOTES) ?></strong> · Max tokens <strong><?= htmlspecialchars((string) $settings['max_tokens'], ENT_QUOTES) ?></strong><br />
          Video status: <?= htmlspecialchars($videoStatusMessage, ENT_QUOTES) ?><br />
          Last updated <?= $settingsUpdatedDisplay !== '' ? htmlspecialchars($settingsUpdatedDisplay, ENT_QUOTES) : '—' ?>
        </p>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="brand-profile">
      <div class="admin-panel__header">
        <div>
          <h2 id="brand-profile">Brand Profile</h2>
          <p>Store your logo, contact lines, and CTA so AI Studio can personalise greetings and marketing content automatically.</p>
        </div>
        <div class="ai-settings__status" role="status">
          <i class="fa-solid fa-id-card" aria-hidden="true"></i>
          <span><?= htmlspecialchars(ai_brand_profile_is_empty($brandProfile) ? 'Brand profile not configured' : 'Brand profile ready', ENT_QUOTES) ?></span>
        </div>
      </div>

      <?php if ($flashMessage !== '' && $flashContext === 'brand'): ?>
      <div class="ai-settings__feedback ai-settings__feedback--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
      </div>
      <?php endif; ?>

      <?php if (ai_brand_profile_is_empty($brandProfile)): ?>
      <p class="ai-settings__meta">Brand Profile not configured. Set your logo and contact details so AI can personalise your content.</p>
      <?php endif; ?>

      <form method="post" class="admin-form" enctype="multipart/form-data" data-brand-form>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <input type="hidden" name="action" value="save-brand-profile" data-brand-action />
        <input type="hidden" name="remove_logo" value="0" data-remove-logo />
        <input type="hidden" name="remove_logo_secondary" value="0" data-remove-logo-secondary />

        <div class="ai-settings__grid">
          <div>
            <p><strong>Company Logo</strong></p>
            <div class="ai-greetings__media" style="padding: 8px; border: 1px dashed #d5d5d5; border-radius: 8px;">
              <?php if (($brandProfile['logo'] ?? '') !== ''): ?>
              <img src="/<?= htmlspecialchars($brandProfile['logo'], ENT_QUOTES) ?>" alt="Company logo" style="max-width: 160px; height: auto; display: block; margin-bottom: 8px;" />
              <p class="ai-settings__meta">Stored as <?= htmlspecialchars(basename($brandProfile['logo']), ENT_QUOTES) ?></p>
              <?php else: ?>
              <p class="ai-settings__meta">PNG/JPEG recommended. Transparent PNG preferred.</p>
              <?php endif; ?>
              <label class="btn btn-ghost btn-sm" for="brand_logo">Change logo</label>
              <input type="file" id="brand_logo" name="brand_logo" accept="image/png,image/jpeg,image/webp" hidden />
              <button type="button" class="btn btn-ghost btn-sm" data-remove-logo-btn <?= ($brandProfile['logo'] ?? '') === '' ? 'disabled' : '' ?>>Remove logo</button>
            </div>
          </div>
          <div>
            <p><strong>Secondary / Watermark</strong></p>
            <div class="ai-greetings__media" style="padding: 8px; border: 1px dashed #d5d5d5; border-radius: 8px;">
              <?php if (($brandProfile['logo_secondary'] ?? '') !== ''): ?>
              <img src="/<?= htmlspecialchars($brandProfile['logo_secondary'], ENT_QUOTES) ?>" alt="Secondary logo" style="max-width: 160px; height: auto; display: block; margin-bottom: 8px;" />
              <p class="ai-settings__meta">Stored as <?= htmlspecialchars(basename($brandProfile['logo_secondary']), ENT_QUOTES) ?></p>
              <?php else: ?>
              <p class="ai-settings__meta">Optional lighter logo / watermark for creatives.</p>
              <?php endif; ?>
              <label class="btn btn-ghost btn-sm" for="brand_logo_secondary">Change secondary logo</label>
              <input type="file" id="brand_logo_secondary" name="brand_logo_secondary" accept="image/png,image/jpeg,image/webp" hidden />
              <button type="button" class="btn btn-ghost btn-sm" data-remove-logo-secondary-btn <?= ($brandProfile['logo_secondary'] ?? '') === '' ? 'disabled' : '' ?>>Remove secondary logo</button>
            </div>
          </div>
          <label>
            Primary brand color (hex)
            <input type="text" name="brand_primary_color" value="<?= htmlspecialchars($brandProfile['primary_color'] ?? '', ENT_QUOTES) ?>" placeholder="#0F4C81" />
          </label>
          <label>
            Secondary brand color (hex)
            <input type="text" name="brand_secondary_color" value="<?= htmlspecialchars($brandProfile['secondary_color'] ?? '', ENT_QUOTES) ?>" placeholder="#FFC857" />
          </label>
          <label>
            Company name
            <input type="text" name="brand_company" value="<?= htmlspecialchars($brandProfile['company_name'] ?? '', ENT_QUOTES) ?>" placeholder="Dakshayani Enterprises" />
          </label>
          <label>
            Tagline / motto
            <input type="text" name="brand_tagline" value="<?= htmlspecialchars($brandProfile['tagline'] ?? '', ENT_QUOTES) ?>" placeholder="Reliable solar for every home" />
          </label>
          <label>
            Phone / mobile number
            <input type="text" name="brand_phone" value="<?= htmlspecialchars($brandProfile['phone'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            WhatsApp number (if different)
            <input type="text" name="brand_whatsapp" value="<?= htmlspecialchars($brandProfile['whatsapp'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            Email address
            <input type="email" name="brand_email" value="<?= htmlspecialchars($brandProfile['email'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            Website URL
            <input type="text" name="brand_website" value="<?= htmlspecialchars($brandProfile['website'] ?? '', ENT_QUOTES) ?>" placeholder="https://example.com" />
          </label>
          <label>
            Physical address
            <textarea name="brand_address" rows="2" placeholder="Street, city, state"><?= htmlspecialchars($brandProfile['address'] ?? '', ENT_QUOTES) ?></textarea>
          </label>
          <label>
            Facebook page URL/name
            <input type="text" name="brand_facebook" value="<?= htmlspecialchars($brandProfile['social']['facebook'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            Instagram handle
            <input type="text" name="brand_instagram" value="<?= htmlspecialchars($brandProfile['social']['instagram'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            YouTube channel URL/name
            <input type="text" name="brand_youtube" value="<?= htmlspecialchars($brandProfile['social']['youtube'] ?? '', ENT_QUOTES) ?>" />
          </label>
          <label>
            Default call-to-action line
            <input type="text" name="brand_cta" value="<?= htmlspecialchars($brandProfile['cta'] ?? '', ENT_QUOTES) ?>" placeholder="Call us today for a free solar consultation" />
          </label>
          <label>
            Disclaimer / footer
            <textarea name="brand_disclaimer" rows="2" placeholder="Offers subject to government guidelines…"><?= htmlspecialchars($brandProfile['disclaimer'] ?? '', ENT_QUOTES) ?></textarea>
          </label>
        </div>

        <div class="ai-settings__controls">
          <div class="ai-settings__actions">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
              Save Brand Profile
            </button>
            <button type="button" class="btn btn-ghost" data-brand-reset>
              <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
              Reset to blank
            </button>
          </div>
          <p class="ai-settings__meta">
            Last updated <?= $brandProfileUpdatedDisplay !== '' ? htmlspecialchars($brandProfileUpdatedDisplay, ENT_QUOTES) : '—' ?>
          </p>
        </div>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="festival-greetings">
      <div class="admin-panel__header">
        <div>
          <h2 id="festival-greetings">Festival &amp; Occasion Greetings</h2>
          <p>Generate inline festive greetings, visuals, and storyboards without any pop-up modals. Built for Indian festivals with Gemini only.</p>
        </div>
        <div class="ai-greetings__status" data-greetings-status aria-live="polite">Loading Gemini status…</div>
      </div>

      <div class="ai-greetings__grid" data-greetings-shell>
        <div class="ai-greetings__left">
          <article class="ai-greetings__card">
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Creator</p>
                <h3>Create Greeting</h3>
                <p>Fill the form and generate captions, long copy, SMS, and media with Gemini.</p>
              </div>
              <div class="ai-greetings__meta" data-greetings-video></div>
            </header>

            <form class="ai-greetings__form" data-greetings-form novalidate>
              <div class="ai-form-grid">
                <label>
                  Occasion
                  <select data-greetings-occasion>
                    <option value="Diwali">Diwali</option>
                    <option value="Chhath">Chhath</option>
                    <option value="Dussehra">Dussehra</option>
                    <option value="Holi">Holi</option>
                    <option value="Makar Sankranti">Makar Sankranti</option>
                    <option value="Eid">Eid</option>
                    <option value="Raksha Bandhan">Raksha Bandhan</option>
                    <option value="Janmashtami">Janmashtami</option>
                    <option value="Ganesh Chaturthi">Ganesh Chaturthi</option>
                    <option value="Navratri">Navratri</option>
                    <option value="Christmas">Christmas</option>
                    <option value="New Year">New Year</option>
                    <option value="Independence Day">Independence Day</option>
                    <option value="Republic Day">Republic Day</option>
                    <option value="Gandhi Jayanti">Gandhi Jayanti</option>
                    <option value="Women’s Day">Women’s Day</option>
                    <option value="Labour Day">Labour Day</option>
                    <option value="Environment Day">Environment Day</option>
                    <option value="custom">Others (Custom)</option>
                  </select>
                </label>
                <label>
                  Custom occasion (optional)
                  <input type="text" placeholder="e.g. Founder’s Day" data-greetings-custom hidden />
                </label>
                <label>
                  Audience / Target
                  <div class="ai-greetings__chips" data-greetings-audience>
                    <label><input type="checkbox" value="Residential Customers" checked /> Residential Customers</label>
                    <label><input type="checkbox" value="Commercial / Industrial" /> Commercial / Industrial</label>
                    <label><input type="checkbox" value="Government / Institutions" /> Government / Institutions</label>
                    <label><input type="checkbox" value="Existing Customers" /> Existing Customers</label>
                    <label><input type="checkbox" value="General Public" /> General Public</label>
                  </div>
                </label>
                <label>
                  Platforms / Usage
                  <div class="ai-greetings__chips" data-greetings-platforms>
                    <label><input type="checkbox" value="Facebook / Instagram Post" checked /> Facebook / Instagram Post</label>
                    <label><input type="checkbox" value="Story / Reel" /> Story / Reel</label>
                    <label><input type="checkbox" value="WhatsApp Status" checked /> WhatsApp Status</label>
                    <label><input type="checkbox" value="Website Banner" /> Website Banner</label>
                    <label><input type="checkbox" value="Email Header + Body" /> Email Header + Body</label>
                    <label><input type="checkbox" value="SMS Text" /> SMS Text</label>
                  </div>
                </label>
                <label>
                  Language
                  <div class="ai-greetings__chips" data-greetings-languages>
                    <label><input type="radio" name="greet-language" value="English" checked /> English</label>
                    <label><input type="radio" name="greet-language" value="Hindi" /> Hindi</label>
                    <label><input type="radio" name="greet-language" value="Hinglish" /> Hinglish</label>
                  </div>
                </label>
                <label>
                  Tone
                  <select data-greetings-tone>
                    <option value="Warm & Festive">Warm &amp; Festive</option>
                    <option value="Professional & Formal">Professional &amp; Formal</option>
                    <option value="Friendly & Local">Friendly &amp; Local</option>
                    <option value="Premium & Minimal">Premium &amp; Minimal</option>
                  </select>
                </label>
                <label class="ai-greetings__toggle">
                  <input type="checkbox" data-greetings-solar checked />
                  <span>Relate to solar / PM Surya Ghar / renewable energy.</span>
                </label>
                <label class="ai-greetings__toggle">
                  <input type="checkbox" data-greetings-brand checked />
                  <span>Use Brand Profile (logo, contact &amp; CTA)</span>
                </label>
                <label>
                  Media Type
                  <select data-greetings-media>
                    <option value="image">Image only</option>
                    <option value="video">Video only</option>
                    <option value="both" selected>Image + Video</option>
                  </select>
                </label>
                <label>
                  Occasion date (optional)
                  <input type="date" data-greetings-date />
                </label>
                <label>
                  Additional instructions
                  <textarea rows="2" placeholder="Mention subsidy, region, or CTA" data-greetings-notes></textarea>
                </label>
              </div>

              <div class="ai-greetings__actions">
                <button type="button" class="btn btn-primary" data-greetings-generate>
                  <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                  Generate Greeting
                </button>
                <button type="button" class="btn btn-ghost" data-greetings-media-generate>
                  <i class="fa-solid fa-palette" aria-hidden="true"></i>
                  Generate Media
                </button>
              </div>
            </form>
          </article>

          <article class="ai-greetings__card" data-greetings-preview>
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Preview</p>
                <h3>Inline outputs</h3>
                <p>Copy text, download media, or send to Smart Marketing without any modal.</p>
              </div>
              <div class="ai-greetings__meta" data-greetings-context></div>
            </header>
            <div class="ai-settings__feedback ai-settings__feedback--info" data-greetings-feedback hidden></div>
            <div class="ai-greetings__preview-body">
              <div class="ai-greetings__text" data-greetings-text>
                <div class="ai-greetings__stack" data-greetings-captions></div>
                <div class="ai-greetings__stack" data-greetings-long></div>
                <div class="ai-greetings__stack" data-greetings-sms></div>
              </div>
              <div class="ai-greetings__media" data-greetings-media-area></div>
            </div>
            <div class="ai-greetings__footer-actions">
              <button type="button" class="btn btn-ghost btn-sm" data-greetings-retry-image hidden>
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                Retry image only
              </button>
              <button type="button" class="btn btn-primary btn-sm" data-greetings-save>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                Save greeting
              </button>
              <button type="button" class="btn btn-ghost btn-sm" data-greetings-send>
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                Use in Smart Marketing
              </button>
            </div>
          </article>
        </div>

        <div class="ai-greetings__right">
          <article class="ai-greetings__card ai-greetings__card--compact">
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Automation</p>
                <h3>Auto-create drafts</h3>
              </div>
            </header>
            <div class="ai-greetings__auto">
              <label class="dashboard-toggle">
                <input type="checkbox" data-greetings-auto />
                <span>Auto-create greeting drafts before major festivals</span>
              </label>
              <label>
                Days before
                <input type="number" min="1" max="30" value="3" data-greetings-auto-days />
              </label>
              <button type="button" class="btn btn-primary btn-sm" data-greetings-auto-save>
                <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                Save automation
              </button>
            </div>
          </article>

          <article class="ai-greetings__card ai-greetings__card--calendar">
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Calendar</p>
                <h3>Occasion picker</h3>
              </div>
            </header>
            <div class="ai-greetings__calendar" data-greetings-calendar></div>
            <div class="ai-greetings__calendar-selection" data-greetings-calendar-selection></div>
          </article>

          <article class="ai-greetings__card ai-greetings__card--compact">
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Upcoming</p>
                <h3>Next 60 days</h3>
              </div>
            </header>
            <div class="ai-greetings__list" data-greetings-upcoming></div>
          </article>

          <article class="ai-greetings__card ai-greetings__card--compact">
            <header class="ai-greetings__card-header">
              <div>
                <p class="ai-greetings__eyebrow">Saved Greetings</p>
                <h3>Drafts &amp; assets</h3>
              </div>
            </header>
            <div class="ai-greetings__saved" data-greetings-saved></div>
          </article>
        </div>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="ai-chat">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-chat">AI Chat (Gemini)</h2>
          <p>Chat with the configured Gemini text model. Streaming replies respect the saved temperature and token settings.</p>
        </div>
        <div class="ai-chat__actions">
          <button type="button" class="btn btn-ghost btn-sm" data-ai-export>
            <i class="fa-solid fa-file-export" aria-hidden="true"></i>
            Export chat as PDF
          </button>
          <button type="button" class="btn btn-ghost btn-sm" data-ai-clear>
            <i class="fa-solid fa-trash" aria-hidden="true"></i>
            Clear history
          </button>
        </div>
      </div>

      <div class="ai-chat" data-ai-chat data-enabled="<?= $settings['enabled'] ? 'true' : 'false' ?>">
        <aside class="ai-chat__sidebar">
          <h3>Quick prompts</h3>
          <ul>
            <li><button type="button" data-ai-prompt="Summarise today's operations updates in three bullet points.">Operations summary</button></li>
            <li><button type="button" data-ai-prompt="Draft a customer email acknowledging receipt of a solar installation query.">Customer email</button></li>
            <li><button type="button" data-ai-prompt="Outline a proposal for expanding rooftop solar adoption in urban schools.">Proposal outline</button></li>
            <li><button type="button" data-ai-prompt="Provide a motivational update for the field installation team this week.">Team motivation</button></li>
          </ul>
        </aside>
        <div class="ai-chat__console">
          <div class="ai-chat__status" data-ai-disabled-message <?= $settings['enabled'] ? 'hidden' : '' ?>>
            <i class="fa-solid fa-power-off" aria-hidden="true"></i>
            <p>AI responses are currently disabled. Enable Gemini in the settings to continue.</p>
          </div>
          <div class="ai-chat__history" data-ai-history aria-live="polite"></div>
          <form class="ai-chat__composer" data-ai-composer>
            <label for="ai-chat-message" class="sr-only">Your message</label>
            <textarea id="ai-chat-message" name="message" rows="3" placeholder="Ask Gemini for assistance…" required></textarea>
            <div class="ai-chat__composer-actions">
              <button type="submit" class="btn btn-primary" data-ai-send>
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                Send
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="ai-blog-generator">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-blog-generator">Blog Generator (Gemini text)</h2>
          <p>Generate long-form blog drafts with the saved Gemini text model and publish directly to the blog system.</p>
        </div>
        <div class="ai-blog-generator__status" data-blog-status aria-live="polite">Idle</div>
      </div>

      <div class="ai-blog-generator" data-blog-generator>
        <form class="ai-blog-generator__form" data-blog-form novalidate>
          <div class="ai-form-grid">
            <label>
              Title
              <input type="text" name="blog_title" data-blog-title required placeholder="Enter working title" />
            </label>
            <label>
              Brief
              <textarea name="blog_brief" data-blog-brief rows="3" placeholder="Summarise the article goals"></textarea>
            </label>
            <label>
              Keywords
              <input type="text" name="blog_keywords" data-blog-keywords placeholder="Comma separated keywords" />
            </label>
            <label>
              Tone
              <input type="text" name="blog_tone" data-blog-tone placeholder="e.g. confident, friendly" />
            </label>
          </div>
          <div class="ai-blog-generator__length" aria-labelledby="blog-length-label">
            <p id="blog-length-label" class="ai-blog-generator__length-title">Blog Length</p>
            <div class="ai-blog-generator__length-options">
              <label class="dashboard-toggle">
                <input type="radio" name="blog_length" value="short" data-blog-length-option />
                <span>Short (~600–800 words)</span>
              </label>
              <label class="dashboard-toggle">
                <input type="radio" name="blog_length" value="standard" data-blog-length-option checked />
                <span>Standard (~1200–1500 words)</span>
              </label>
              <label class="dashboard-toggle">
                <input type="radio" name="blog_length" value="long" data-blog-length-option />
                <span>Long / In-depth (~2000–2500 words)</span>
              </label>
              <label class="dashboard-toggle ai-blog-generator__length-custom">
                <input type="radio" name="blog_length" value="custom" data-blog-length-option />
                <span>Custom (words)</span>
                <input
                  type="number"
                  name="blog_length_custom"
                  data-blog-length-custom
                  min="300"
                  max="3000"
                  inputmode="numeric"
                  placeholder="e.g. 1800"
                  disabled
                />
              </label>
            </div>
          </div>
          <div class="ai-blog-generator__controls">
            <label class="dashboard-toggle">
              <input type="checkbox" data-blog-brand-toggle checked />
              <span>Use Brand Profile (logo, contact &amp; CTA)</span>
            </label>
            <div class="ai-blog-generator__length-indicator">
              <span class="ai-blog-generator__length-summary" data-blog-length-summary>Length: Standard (~1350 words)</span>
              <span class="ai-blog-generator__wordcount" data-blog-wordcount>Approx. word count: —</span>
            </div>
            <div class="ai-blog-generator__progress" data-blog-progress role="status"></div>
            <div class="ai-blog-generator__actions">
              <button type="button" class="btn btn-primary" data-blog-generate>
                <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                Generate blog
              </button>
              <button type="button" class="btn btn-ghost" data-blog-save>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                Save draft
              </button>
              <button type="button" class="btn btn-ghost" data-blog-preview-scroll>
                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                Preview
              </button>
              <button type="button" class="btn btn-primary" data-blog-publish>
                <i class="fa-solid fa-cloud-upload" aria-hidden="true"></i>
                Publish
              </button>
            </div>
            <p class="ai-blog-generator__autosave" data-blog-autosave-status aria-live="polite">Draft not saved</p>
          </div>
        </form>

        <aside class="ai-blog-preview">
          <div class="ai-blog-preview__cover" data-blog-cover hidden></div>
          <h3>Live preview</h3>
          <div class="ai-blog-preview__content" data-blog-preview aria-live="polite">
            <p class="ai-blog-preview__placeholder">Generated blog content will appear here.</p>
          </div>
        </aside>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="ai-image-generator">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-image-generator">AI Image Generator (Gemini image)</h2>
          <p>Create supporting visuals with the saved Gemini image model and attach them to your blog draft.</p>
        </div>
        <div class="ai-image-generator__status" data-image-status aria-live="polite">Idle</div>
      </div>

      <div class="ai-image-generator" data-image-generator>
        <div class="ai-image-generator__inputs">
          <label>
            Prompt
            <textarea rows="2" data-image-prompt placeholder="Describe the illustration you need"></textarea>
          </label>
          <label>
            Aspect Ratio
            <select data-image-aspect-ratio>
              <option value="1:1" selected>Square (1:1)</option>
              <option value="4:5">Portrait (4:5)</option>
              <option value="9:16">Portrait (9:16)</option>
              <option value="16:9">Landscape (16:9)</option>
              <option value="3:2">Landscape (3:2)</option>
            </select>
            <small>Choose output aspect ratio. If the image API does not support exact ratios, we will use the closest supported size.</small>
          </label>
          <div class="ai-image-generator__actions">
            <button type="button" class="btn btn-ghost btn-sm" data-image-autofill>
              <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
              Use blog context
            </button>
            <button type="button" class="btn btn-primary" data-image-generate>
              <i class="fa-solid fa-palette" aria-hidden="true"></i>
              Generate image
            </button>
          </div>
          <label class="dashboard-toggle" style="margin-top: 8px;">
            <input type="checkbox" data-image-brand-toggle checked />
            <span>Use Brand Profile (logo, contact &amp; CTA)</span>
          </label>
        </div>
        <div class="ai-image-generator__preview" data-image-preview hidden>
          <figure>
            <img src="" alt="Generated visual" data-image-output />
            <figcaption data-image-caption></figcaption>
          </figure>
          <div class="ai-image-generator__fix">
            <label>
              Image fix instructions (optional)
              <textarea rows="2" data-image-fix placeholder="Logo missing. Add company logo top-right. Correct phone to +91-XXXXXXXXXX and show website at bottom."></textarea>
            </label>
            <button type="button" class="btn btn-ghost btn-sm" data-image-regenerate>
              <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
              Regenerate image with fixes
            </button>
          </div>
          <div class="ai-image-generator__preview-actions">
            <a href="#" class="btn btn-ghost btn-sm" data-image-download download>
              <i class="fa-solid fa-download" aria-hidden="true"></i>
              Download
            </a>
            <button type="button" class="btn btn-primary btn-sm" data-image-attach>
              <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
              Attach to draft
            </button>
          </div>
        </div>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="ai-tts-generator">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-tts-generator">TTS Generator (Gemini voice)</h2>
          <p>Voice your copy using the saved Gemini TTS model and download it for distribution.</p>
        </div>
        <div class="ai-tts-generator__status" data-tts-status aria-live="polite">Idle</div>
      </div>

      <div class="ai-tts-generator" data-tts-generator>
        <label>
          Text to narrate
          <textarea rows="3" data-tts-text placeholder="Paste the text you want to voice"></textarea>
        </label>
        <div class="ai-tts-generator__controls">
          <label>
            Format
            <select data-tts-format>
              <option value="mp3">MP3</option>
              <option value="wav">WAV</option>
            </select>
          </label>
          <button type="button" class="btn btn-primary" data-tts-generate>
            <i class="fa-solid fa-volume-high" aria-hidden="true"></i>
            Generate audio
          </button>
        </div>
        <div class="ai-tts-generator__player" data-tts-output hidden>
          <audio controls data-tts-audio></audio>
          <a href="#" class="btn btn-ghost btn-sm" data-tts-download download>
            <i class="fa-solid fa-download" aria-hidden="true"></i>
            Download audio
          </a>
        </div>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="ai-sandbox">
      <div class="admin-panel__header">
        <div>
          <h2 id="ai-sandbox">AI Sandbox (Gemini only)</h2>
          <p>Experiment with text, image, and voice outputs using the configured Gemini models without affecting chat or blog workflows.</p>
        </div>
      </div>

      <div class="ai-sandbox" data-sandbox>
        <div class="ai-sandbox__tabs" role="tablist">
          <button type="button" role="tab" aria-selected="true" data-sandbox-tab="text">Text Sandbox</button>
          <button type="button" role="tab" aria-selected="false" data-sandbox-tab="image">Image Sandbox</button>
          <button type="button" role="tab" aria-selected="false" data-sandbox-tab="tts">TTS Sandbox</button>
        </div>

        <div class="ai-sandbox__panels">
          <section class="ai-sandbox__panel" data-sandbox-panel="text" role="tabpanel">
            <header>
              <h3>Gemini Text Model</h3>
              <p class="ai-sandbox__meta">Model: <span data-sandbox-text-model><?= htmlspecialchars($settings['models']['text'] ?? 'gemini-2.5-flash', ENT_QUOTES) ?></span></p>
            </header>
            <form class="ai-sandbox__form" data-sandbox-text-form novalidate>
              <label>
                Prompt
                <textarea rows="3" data-sandbox-text-input placeholder="Ask Gemini for a quick experiment"></textarea>
              </label>
              <label class="dashboard-toggle">
                <input type="checkbox" data-sandbox-brand-toggle />
                <span>Use Brand Profile (logo, contact &amp; CTA)</span>
              </label>
              <p class="ai-sandbox__meta" data-sandbox-brand-preview><?= htmlspecialchars($smartMarketingBrandPreviewText, ENT_QUOTES) ?></p>
              <div class="ai-sandbox__actions">
                <button type="submit" class="btn btn-primary" data-sandbox-text-run>
                  <i class="fa-solid fa-play" aria-hidden="true"></i>
                  Run prompt
                </button>
                <span class="ai-sandbox__status" data-sandbox-text-status aria-live="polite">Idle</span>
              </div>
            </form>
            <div class="ai-sandbox__output" data-sandbox-text-output>
              <p class="ai-sandbox__placeholder">Responses stream here.</p>
            </div>
          </section>

          <section class="ai-sandbox__panel" data-sandbox-panel="image" role="tabpanel" hidden>
            <header>
              <h3>Gemini Image Model</h3>
              <p class="ai-sandbox__meta">Model: <span data-sandbox-image-model><?= htmlspecialchars($settings['models']['image'] ?? 'gemini-2.5-flash-image', ENT_QUOTES) ?></span></p>
            </header>
            <form class="ai-sandbox__form" data-sandbox-image-form novalidate>
              <label>
                Prompt
                <textarea rows="2" data-sandbox-image-input placeholder="Describe the visual you want to explore"></textarea>
              </label>
              <div class="ai-form-grid">
                <label>
                  Aspect Ratio
                  <select name="sandbox_image_aspect_ratio" data-sandbox-image-aspect>
                    <option value="1:1" selected>Square (1:1)</option>
                    <option value="4:5">Portrait (4:5)</option>
                    <option value="9:16">Portrait (9:16)</option>
                    <option value="16:9">Landscape (16:9)</option>
                    <option value="3:2">Landscape (3:2)</option>
                  </select>
                </label>
                <div>
                  <label class="dashboard-toggle" style="margin-top: 4px;">
                    <input type="checkbox" name="sandbox_use_brand_profile" value="1" data-sandbox-image-brand />
                    <span>Use Brand Profile (logo, contact &amp; CTA)</span>
                  </label>
                  <p class="ai-sandbox__meta" data-sandbox-image-brand-help>
                    <?= htmlspecialchars($smartMarketingBrandProfileReady ? 'If enabled, brand details (logo, contact & CTA) will be included as context in the image prompt when relevant.' : 'Brand Profile not found. Configure it in Smart Marketing CMO.', ENT_QUOTES) ?>
                  </p>
                </div>
              </div>
              <div class="ai-sandbox__actions">
                <button type="submit" class="btn btn-primary" data-sandbox-image-run>
                  <i class="fa-solid fa-palette" aria-hidden="true"></i>
                  Generate visual
                </button>
                <span class="ai-sandbox__status" data-sandbox-image-status aria-live="polite">Idle</span>
              </div>
            </form>
            <div class="ai-sandbox__media" data-sandbox-image-output hidden>
              <figure>
                <img src="" alt="Sandbox visual" data-sandbox-image-preview />
                <figcaption data-sandbox-image-caption></figcaption>
              </figure>
              <a href="#" class="btn btn-ghost btn-sm" data-sandbox-image-download download>
                <i class="fa-solid fa-download" aria-hidden="true"></i>
                Download
              </a>
            </div>
          </section>

          <section class="ai-sandbox__panel" data-sandbox-panel="tts" role="tabpanel" hidden>
            <header>
              <h3>Gemini TTS Model</h3>
              <p class="ai-sandbox__meta">Model: <span data-sandbox-tts-model><?= htmlspecialchars($settings['models']['tts'] ?? 'gemini-2.5-flash-preview-tts', ENT_QUOTES) ?></span></p>
            </header>
            <form class="ai-sandbox__form" data-sandbox-tts-form novalidate>
              <label>
                Text
                <textarea rows="3" data-sandbox-tts-input placeholder="Paste text to voice instantly"></textarea>
              </label>
              <div class="ai-sandbox__form-row">
                <label>
                  Format
                  <select data-sandbox-tts-format>
                    <option value="mp3">MP3</option>
                    <option value="wav">WAV</option>
                  </select>
                </label>
              </div>
              <div class="ai-sandbox__actions">
                <button type="submit" class="btn btn-primary" data-sandbox-tts-run>
                  <i class="fa-solid fa-volume-high" aria-hidden="true"></i>
                  Generate audio
                </button>
                <span class="ai-sandbox__status" data-sandbox-tts-status aria-live="polite">Idle</span>
              </div>
            </form>
            <div class="ai-sandbox__media" data-sandbox-tts-output hidden>
              <audio controls data-sandbox-tts-audio></audio>
              <a href="#" class="btn btn-ghost btn-sm" data-sandbox-tts-download download>
                <i class="fa-solid fa-download" aria-hidden="true"></i>
                Download
              </a>
            </div>
          </section>
        </div>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="automation-scheduler" data-scheduler-shell>
      <div class="admin-panel__header">
        <div>
          <h2 id="automation-scheduler">Automation Scheduler</h2>
          <p>Plan multiple AI-powered blog automations, track their statuses, and link each run directly to a published blog.</p>
        </div>
        <div class="automation-scheduler__next" data-scheduler-next aria-live="polite">Next run: —</div>
      </div>

      <div class="automation-scheduler__composer">
        <header>
          <h3>Create automations</h3>
          <p>Queue one-time launches or recurring posts with specific dates and times. Save several entries together.</p>
        </header>
        <div class="automation-scheduler__entries" data-scheduler-entries></div>
        <div class="automation-scheduler__composer-actions">
          <button type="button" class="btn btn-ghost btn-sm" data-scheduler-add>
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Add automation
          </button>
          <button type="button" class="btn btn-primary" data-scheduler-save>
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            Save automations
          </button>
          <span class="automation-scheduler__status" data-scheduler-status aria-live="polite">Idle</span>
        </div>
      </div>

      <template id="scheduler-entry-template">
        <article class="automation-entry" data-entry>
          <input type="hidden" data-entry-id />
          <input type="hidden" data-entry-status value="active" />
          <input type="hidden" data-entry-festival-name />
          <input type="hidden" data-entry-festival-date />
          <div class="automation-entry__grid">
            <label>
              Title / short description
              <input type="text" data-entry-title placeholder="e.g. Rooftop subsidy explainer" />
            </label>
            <label class="automation-entry__topic">
              Focus / topic
              <textarea data-entry-topic placeholder="What should Gemini write about?" rows="2"></textarea>
            </label>
            <label>
              Additional guidance
              <textarea data-entry-description placeholder="Tone, audience, CTA…" rows="2"></textarea>
            </label>
            <label>
              Scheduled date
              <input type="date" data-entry-date />
            </label>
            <label>
              Scheduled time
              <input type="time" data-entry-time value="09:00" />
            </label>
            <label>
              Type
              <select data-entry-type>
                <option value="once">One-time</option>
                <option value="recurring">Recurring</option>
              </select>
            </label>
            <label data-entry-frequency-group>
              Repeat every
              <select data-entry-frequency>
                <option value="daily">Day</option>
                <option value="weekly" selected>Week</option>
                <option value="monthly">Month</option>
              </select>
            </label>
          </div>
          <div class="automation-entry__actions">
            <button type="button" class="btn btn-ghost btn-sm" data-entry-remove>
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
              Remove
            </button>
          </div>
        </article>
      </template>

      <div class="automation-scheduler__list">
        <header>
          <h3>Saved automations</h3>
          <p>Monitor active, paused, and completed automations with their scheduled date/time.</p>
        </header>
        <div data-automation-cards></div>
      </div>

      <div class="automation-festivals" data-festival-list>
        <header>
          <h3>Indian &amp; Hindu festivals</h3>
          <p>Preview upcoming festivals or national days and quickly create automations or blogs for those dates.</p>
        </header>
        <div data-festival-cards></div>
      </div>

      <div class="automation-scheduler__log" data-scheduler-logs>
        <header>
          <h3>Automation log</h3>
          <p>Latest auto-generated posts with associated assets and published links.</p>
        </header>
        <ul></ul>
      </div>
    </section>

    <section class="admin-panel" aria-labelledby="usage-logs">
      <div class="admin-panel__header">
        <div>
          <h2 id="usage-logs">Usage &amp; Logs</h2>
          <p>Track Gemini token usage, approximate spend, and any API issues across the studio.</p>
        </div>
      </div>

      <div class="usage-logs" data-usage-shell>
        <div class="usage-logs__grid">
          <article>
            <h3>Daily usage</h3>
            <p class="usage-logs__metric" data-usage-daily-tokens>0 tokens</p>
            <p class="usage-logs__cost" data-usage-daily-cost>₹0.00</p>
          </article>
          <article>
            <h3>Monthly usage</h3>
            <p class="usage-logs__metric" data-usage-monthly-tokens>0 tokens</p>
            <p class="usage-logs__cost" data-usage-monthly-cost>₹0.00</p>
          </article>
          <article>
            <h3>Aggregate</h3>
            <p class="usage-logs__metric" data-usage-aggregate-tokens>0 tokens</p>
            <p class="usage-logs__cost" data-usage-aggregate-cost>₹0.00</p>
          </article>
        </div>

        <div class="usage-logs__pricing">
          <h3>Pricing reference</h3>
          <ul data-usage-pricing></ul>
        </div>

        <div class="usage-logs__errors">
          <header>
            <h3>Error logs</h3>
            <p>Recent Gemini issues across text, image, and audio calls.</p>
          </header>
          <ul data-error-log></ul>
          <div class="usage-logs__error-actions">
            <button type="button" class="btn btn-primary btn-sm" data-error-retry>
              <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
              Retry last action
            </button>
            <button type="button" class="btn btn-ghost btn-sm" data-error-copy>
              <i class="fa-solid fa-copy" aria-hidden="true"></i>
              Copy error details
            </button>
          </div>
        </div>
      </div>
    </section>
  </main>

  <div class="dashboard-toast-container" data-ai-toast-container hidden></div>

  <script>
    window.csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
    window.aiChatHistory = <?= $chatHistoryJson !== false ? $chatHistoryJson : '[]' ?>;
    window.aiSettings = <?= $settingsJson !== false ? $settingsJson : '{}' ?>;
    window.aiBrandProfile = <?= $brandProfileJson !== false ? $brandProfileJson : '{}' ?>;
    window.smartMarketingBrandProfile = <?= $smartMarketingBrandProfileJson !== false ? $smartMarketingBrandProfileJson : '{}' ?>;
    window.aiToastMeta = <?= $toastMeta !== '' ? $toastMeta : 'null' ?>;
  </script>
  <script>
    (function () {
      'use strict';

      const settingsForm = document.querySelector('[data-ai-settings-action]')?.form;
      const testButton = document.querySelector('[data-ai-test]');
      const actionInput = document.querySelector('[data-ai-settings-action]');
      const tempSlider = document.getElementById('ai-temperature');
      const tempValue = document.querySelector('[data-ai-temp-value]');
      const revealButton = document.querySelector('[data-ai-reveal]');
      const toastContainer = document.querySelector('[data-ai-toast-container]');

      function showToast(message, tone = 'info') {
        if (!toastContainer) {
          return;
        }
        toastContainer.hidden = false;
        const toast = document.createElement('div');
        toast.className = `dashboard-toast dashboard-toast--${tone}`;
        toast.setAttribute('data-state', 'visible');
        toast.innerHTML = `<span>${message}</span>`;
        toastContainer.appendChild(toast);
        setTimeout(() => {
          toast.setAttribute('data-state', 'hidden');
          setTimeout(() => toast.remove(), 250);
        }, 4500);
      }

      const brandProfile = window.aiBrandProfile || {};
      const brandProfileReady = !!(brandProfile && brandProfile.hasData);
      const smartMarketingBrandProfile = window.smartMarketingBrandProfile || {};
      const smartMarketingBrandReady = !!(smartMarketingBrandProfile && smartMarketingBrandProfile.hasData);
      let brandToastShown = false;

      function resolveBrandUsage(requested, silent = false) {
        if (requested && !brandProfileReady) {
          if (!brandToastShown && !silent) {
            showToast('Brand Profile not configured. Using generic tone until you add brand details.', 'warning');
            brandToastShown = true;
          }
          return false;
        }
        return requested;
      }

      if (window.aiToastMeta && window.aiToastMeta.message) {
        showToast(window.aiToastMeta.message, window.aiToastMeta.tone || 'info');
      }

      const brandForm = document.querySelector('[data-brand-form]');
      const brandAction = document.querySelector('[data-brand-action]');
      const brandReset = document.querySelector('[data-brand-reset]');
      const removeLogoField = document.querySelector('[data-remove-logo]');
      const removeLogoSecondaryField = document.querySelector('[data-remove-logo-secondary]');
      const removeLogoBtn = document.querySelector('[data-remove-logo-btn]');
      const removeLogoSecondaryBtn = document.querySelector('[data-remove-logo-secondary-btn]');

      if (removeLogoBtn && brandForm && removeLogoField) {
        removeLogoBtn.addEventListener('click', () => {
          if (!window.confirm('Remove the primary logo?')) return;
          removeLogoField.value = '1';
          brandForm.submit();
        });
      }

      if (removeLogoSecondaryBtn && brandForm && removeLogoSecondaryField) {
        removeLogoSecondaryBtn.addEventListener('click', () => {
          if (!window.confirm('Remove the secondary logo?')) return;
          removeLogoSecondaryField.value = '1';
          brandForm.submit();
        });
      }

      if (brandReset && brandForm && brandAction) {
        brandReset.addEventListener('click', () => {
          if (!window.confirm('Clear all saved brand details?')) {
            return;
          }
          brandAction.value = 'reset-brand-profile';
          brandForm.submit();
        });
      }

      if (settingsForm && testButton && actionInput) {
        testButton.addEventListener('click', function (event) {
          event.preventDefault();
          actionInput.value = 'test-connection';
          settingsForm.submit();
        });
        settingsForm.addEventListener('submit', function () {
          if (actionInput.value !== 'test-connection') {
            actionInput.value = 'save-settings';
          }
        });
      }

      if (tempSlider && tempValue) {
        tempSlider.addEventListener('input', () => {
          tempValue.textContent = Number(tempSlider.value).toFixed(2);
        });
      }

      if (revealButton) {
        let revealed = false;
        revealButton.addEventListener('click', () => {
          if (revealed) {
            revealButton.disabled = true;
            return;
          }
          const field = document.getElementById('ai-api-key');
          if (!field) {
            return;
          }
          const key = revealButton.getAttribute('data-api-key') || '';
          if (key === '') {
            showToast('No API key stored.', 'warning');
            revealButton.disabled = true;
            return;
          }
          field.value = key;
          field.type = 'text';
          field.focus();
          revealButton.disabled = true;
          revealed = true;
        });
      }

      // AI Chat logic
      const chatState = {
        history: Array.isArray(window.aiChatHistory) ? window.aiChatHistory.slice() : [],
        enabled: !!(window.aiSettings && window.aiSettings.enabled),
        pendingPrompt: null,
      };

      const historyContainer = document.querySelector('[data-ai-history]');
      const composer = document.querySelector('[data-ai-composer]');
      const textarea = composer ? composer.querySelector('textarea[name="message"]') : null;
      const sendButton = composer ? composer.querySelector('[data-ai-send]') : null;
      const quickPrompts = document.querySelectorAll('[data-ai-prompt]');
      const clearButton = document.querySelector('[data-ai-clear]');
      const exportButton = document.querySelector('[data-ai-export]');
      const disabledMessage = document.querySelector('[data-ai-disabled-message]');
      const chatShell = document.querySelector('[data-ai-chat]');

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function formatTimestamp(iso) {
        if (!iso) {
          return '';
        }
        try {
          const date = new Date(iso);
          if (Number.isNaN(date.getTime())) {
            return '';
          }
          return date.toLocaleString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
          });
        } catch (error) {
          return '';
        }
      }

      function renderHistory() {
        if (!historyContainer) {
          return;
        }
        historyContainer.innerHTML = '';
        chatState.history.forEach((entry) => {
          const message = document.createElement('article');
          const role = entry.role === 'assistant' ? 'assistant' : 'user';
          message.className = `ai-chat__message ai-chat__message--${role}`;
          const meta = document.createElement('header');
          meta.className = 'ai-chat__meta';
          const label = role === 'assistant' ? 'Gemini' : 'You';
          const time = formatTimestamp(entry.timestamp);
          meta.textContent = time ? `${label} · ${time}` : label;
          const bubble = document.createElement('div');
          bubble.className = 'ai-chat__bubble';
          bubble.innerHTML = escapeHtml(entry.text || '').replace(/\n/g, '<br />');
          message.appendChild(meta);
          message.appendChild(bubble);
          historyContainer.appendChild(message);
        });
        historyContainer.scrollTop = historyContainer.scrollHeight;
      }

      function setChatEnabled(enabled) {
        chatState.enabled = !!enabled;
        if (composer) {
          composer.toggleAttribute('aria-disabled', !chatState.enabled);
        }
        if (textarea) {
          textarea.disabled = !chatState.enabled;
        }
        if (sendButton) {
          sendButton.disabled = !chatState.enabled;
        }
        if (disabledMessage) {
          disabledMessage.hidden = !!chatState.enabled;
        }
        if (chatShell) {
          chatShell.setAttribute('data-enabled', chatState.enabled ? 'true' : 'false');
        }
      }

      function appendMessage(role, text, options = {}) {
        const entry = {
          role: role === 'assistant' ? 'assistant' : 'user',
          text: text,
          timestamp: options.timestamp || new Date().toISOString(),
        };
        chatState.history.push(entry);
        renderHistory();
        return entry;
      }

      function streamIntoBubble(bubble, text) {
        const tokens = text.split(/(\s+)/);
        let index = 0;
        function step() {
          if (index >= tokens.length) {
            return;
          }
          bubble.innerHTML += escapeHtml(tokens[index]).replace(/\n/g, '<br />');
          historyContainer.scrollTop = historyContainer.scrollHeight;
          index += 1;
          setTimeout(step, 45);
        }
        step();
      }

      async function sendPrompt(prompt) {
        if (!chatState.enabled) {
          showToast('Enable Gemini to start chatting.', 'warning');
          return;
        }
        if (!prompt || !prompt.trim()) {
          return;
        }
        appendMessage('user', prompt.trim());
        if (textarea) {
          textarea.value = '';
        }
        if (sendButton) {
          sendButton.disabled = true;
        }

        const placeholder = document.createElement('article');
        placeholder.className = 'ai-chat__message ai-chat__message--assistant';
        const meta = document.createElement('header');
        meta.className = 'ai-chat__meta';
        meta.textContent = 'Gemini · responding…';
        const bubble = document.createElement('div');
        bubble.className = 'ai-chat__bubble';
        placeholder.appendChild(meta);
        placeholder.appendChild(bubble);
        historyContainer.appendChild(placeholder);
        historyContainer.scrollTop = historyContainer.scrollHeight;

        try {
          const response = await fetch('api/gemini.php?action=chat', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message: prompt }),
          });

          if (!response.ok) {
            throw new Error('Request failed');
          }

          const payload = await response.json();
          if (!payload || !payload.success) {
            const errorText = payload && payload.error ? payload.error : 'Gemini did not respond.';
            throw new Error(errorText);
          }

          chatState.history = Array.isArray(payload.history) ? payload.history : chatState.history;
          bubble.innerHTML = '';
          streamIntoBubble(bubble, payload.reply || '');
          meta.textContent = 'Gemini · just now';
        } catch (error) {
          bubble.innerHTML = `<span class="ai-chat__error">${escapeHtml(error.message || 'Failed to reach Gemini.')} <button type="button" data-ai-retry>Retry</button></span>`;
          placeholder.addEventListener('click', (event) => {
            if (event.target && event.target.matches('[data-ai-retry]')) {
              placeholder.remove();
              sendPrompt(prompt);
            }
          }, { once: true });
          showToast('Gemini request failed. Retry or check settings.', 'error');
        } finally {
          if (sendButton) {
            sendButton.disabled = !chatState.enabled;
          }
        }
      }

      if (composer && textarea && historyContainer) {
        composer.addEventListener('submit', (event) => {
          event.preventDefault();
          sendPrompt(textarea.value);
        });
      }

      quickPrompts.forEach((button) => {
        button.addEventListener('click', () => {
          if (!textarea) {
            return;
          }
          const preset = button.getAttribute('data-ai-prompt') || '';
          textarea.value = preset;
          textarea.focus();
        });
      });

      if (clearButton) {
        clearButton.addEventListener('click', async () => {
          if (!window.confirm('Clear the AI chat history?')) {
            return;
          }
          try {
            const response = await fetch('api/gemini.php?action=clear-history', {
              method: 'POST',
              headers: {
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              throw new Error('Unable to clear history.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Unable to clear history.');
            }
            chatState.history = [];
            renderHistory();
            showToast('Chat history cleared.', 'success');
          } catch (error) {
            showToast(error.message || 'Failed to clear history.', 'error');
          }
        });
      }

      if (exportButton) {
        exportButton.addEventListener('click', async () => {
          try {
            const response = await fetch('api/gemini.php?action=export-pdf', {
              method: 'GET',
              headers: {
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              throw new Error('Unable to export chat.');
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'ai-chat-transcript.pdf';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            showToast('Chat exported as PDF.', 'success');
          } catch (error) {
            showToast(error.message || 'Failed to export chat.', 'error');
          }
        });
      }

      // Blog generator, image, and TTS logic
      const blogShell = document.querySelector('[data-blog-generator]');
      const blogForm = document.querySelector('[data-blog-form]');
      const blogStatus = document.querySelector('[data-blog-status]');
      const blogProgress = document.querySelector('[data-blog-progress]');
      const blogAutosave = document.querySelector('[data-blog-autosave-status]');
      const blogPreview = document.querySelector('[data-blog-preview]');
      const blogCover = document.querySelector('[data-blog-cover]');
      const blogTitleInput = document.querySelector('[data-blog-title]');
      const blogBriefInput = document.querySelector('[data-blog-brief]');
      const blogKeywordsInput = document.querySelector('[data-blog-keywords]');
      const blogToneInput = document.querySelector('[data-blog-tone]');
      const blogBrandToggle = document.querySelector('[data-blog-brand-toggle]');
      const blogLengthOptions = document.querySelectorAll('[data-blog-length-option]');
      const blogLengthCustomInput = document.querySelector('[data-blog-length-custom]');
      const blogLengthSummary = document.querySelector('[data-blog-length-summary]');
      const blogWordCount = document.querySelector('[data-blog-wordcount]');
      const blogGenerateButton = document.querySelector('[data-blog-generate]');
      const blogSaveButton = document.querySelector('[data-blog-save]');
      const blogPublishButton = document.querySelector('[data-blog-publish]');
      const blogPreviewButton = document.querySelector('[data-blog-preview-scroll]');

      const blogState = {
        paragraphs: [],
        coverImage: '',
        coverImageAlt: '',
        title: '',
        brief: '',
        keywords: '',
        tone: '',
        lengthPreset: 'standard',
        customWordCount: null,
        lengthConfig: null,
        wordCount: 0,
        outline: null,
        draftId: null,
        publishedUrl: null,
        dirty: false,
        saving: false,
        generating: false,
        regenerating: false,
        useBrandProfile: resolveBrandUsage(blogBrandToggle ? blogBrandToggle.checked : brandProfileReady, true),
        imageFix: '',
      };

      function getBlogLengthConfig(preset, customWordCount) {
        const presets = {
          short: {
            targetWords: 700,
            depthDescription: 'short, focused article with a clear intro, 2–3 sections, and a brief conclusion.',
            label: 'Short (~600–800 words)',
          },
          standard: {
            targetWords: 1350,
            depthDescription: 'full-length blog article with detailed sections and examples.',
            label: 'Standard (~1200–1500 words)',
          },
          long: {
            targetWords: 2250,
            depthDescription: 'in-depth, comprehensive blog article with detailed breakdown, multiple sections, examples, and FAQs.',
            label: 'Long / In-depth (~2000–2500 words)',
          },
        };

        const cleanPreset = (preset || 'standard').toLowerCase();
        const base = presets[cleanPreset] ? { ...presets[cleanPreset], preset: cleanPreset } : { ...presets.standard, preset: 'standard' };
        let targetWords = base.targetWords;
        let custom = null;

        if (cleanPreset === 'custom') {
          const parsed = Number.parseInt(customWordCount, 10);
          if (!Number.isNaN(parsed) && parsed >= 300 && parsed <= 3000) {
            targetWords = parsed;
            custom = parsed;
          }
        }

        const minWords = Math.max(300, Math.round(targetWords * 0.8));
        const maxWords = Math.round(targetWords * 1.2);

        return {
          preset: custom !== null ? 'custom' : base.preset,
          targetWords,
          minWords,
          maxWords,
          depthDescription: base.depthDescription,
          label: custom !== null ? `Custom (${custom} words)` : base.label,
          customWordCount: custom,
        };
      }

      function getSelectedLengthPreset() {
        let selected = 'standard';
        blogLengthOptions.forEach((option) => {
          if (option.checked) {
            selected = option.value;
          }
        });
        return selected;
      }

      function getCustomLengthValue() {
        if (!blogLengthCustomInput) {
          return null;
        }
        const parsed = Number.parseInt(blogLengthCustomInput.value, 10);
        if (Number.isNaN(parsed)) {
          return null;
        }
        return parsed;
      }

      function updateBlogLengthState() {
        const preset = getSelectedLengthPreset();
        const custom = getCustomLengthValue();
        const config = getBlogLengthConfig(preset, custom);
        blogState.lengthPreset = config.preset;
        blogState.customWordCount = config.customWordCount;
        blogState.lengthConfig = config;
        if (blogLengthCustomInput) {
          blogLengthCustomInput.disabled = config.preset !== 'custom';
          blogLengthCustomInput.required = config.preset === 'custom';
        }
        updateBlogLengthSummary();
      }

      function applyLengthSelection(preset, custom) {
        const targetPreset = preset || 'standard';
        let presetApplied = false;
        blogLengthOptions.forEach((option) => {
          const match = option.value === targetPreset;
          option.checked = match;
          if (match) {
            presetApplied = true;
          }
        });
        if (!presetApplied) {
          blogLengthOptions.forEach((option) => {
            option.checked = option.value === 'standard';
          });
        }
        if (blogLengthCustomInput) {
          blogLengthCustomInput.value = custom && Number.isFinite(custom) ? custom : '';
        }
        updateBlogLengthState();
      }

      function updateBlogLengthSummary() {
        if (!blogState.lengthConfig) {
          blogState.lengthConfig = getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount);
        }
        const config = blogState.lengthConfig;
        if (blogLengthSummary) {
          const descriptor = config.label || config.preset;
          blogLengthSummary.textContent = `Length: ${descriptor} (~${config.targetWords} words)`;
        }
        updateBlogWordCount();
      }

      function estimateWordCount(paragraphs) {
        const text = Array.isArray(paragraphs) ? paragraphs.join(' ') : '';
        if (!text.trim()) {
          return 0;
        }
        return text.trim().split(/\s+/).filter(Boolean).length;
      }

      function getApproxWordCount() {
        const estimated = blogState.wordCount || estimateWordCount(blogState.paragraphs);
        return estimated;
      }

      function updateBlogWordCount() {
        if (!blogWordCount) {
          return;
        }
        const config = blogState.lengthConfig || getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount);
        const approx = getApproxWordCount();
        if (!approx) {
          blogWordCount.textContent = `Target: around ${config.targetWords} words (min ${config.minWords}, max ${config.maxWords})`;
          return;
        }
        blogWordCount.textContent = `Approx. word count: ~${approx} · Target ${config.targetWords} (min ${config.minWords}, max ${config.maxWords})`;
      }

      function updateBlogStatus(message) {
        if (blogStatus) {
          blogStatus.textContent = message;
        }
      }

      function updateBlogAutosave(message, tone = 'muted') {
        if (!blogAutosave) {
          return;
        }
        blogAutosave.textContent = message;
        blogAutosave.dataset.tone = tone;
      }

      function syncBlogStateFromInputs(silent = false) {
        blogState.title = blogTitleInput ? blogTitleInput.value.trim() : '';
        blogState.brief = blogBriefInput ? blogBriefInput.value.trim() : '';
        blogState.keywords = blogKeywordsInput ? blogKeywordsInput.value.trim() : '';
        blogState.tone = blogToneInput ? blogToneInput.value.trim() : '';
        const requestedBrand = blogBrandToggle ? blogBrandToggle.checked : brandProfileReady;
        blogState.useBrandProfile = resolveBrandUsage(requestedBrand, silent);
        updateBlogLengthState();
      }

      function markBlogDirty() {
        blogState.dirty = true;
        blogState.publishedUrl = null;
        updateBlogAutosave('Unsaved changes', 'warning');
      }

      function renderBlogCover() {
        if (!blogCover) {
          return;
        }
        blogCover.innerHTML = '';
        if (!blogState.coverImage) {
          blogCover.hidden = true;
          return;
        }
        const figure = document.createElement('figure');
        const img = document.createElement('img');
        const url = blogState.coverImage.startsWith('/') ? blogState.coverImage : `/${blogState.coverImage}`;
        img.src = url;
        img.alt = blogState.coverImageAlt || 'AI generated cover';
        const caption = document.createElement('figcaption');
        caption.textContent = blogState.coverImageAlt || 'AI generated cover image';
        figure.appendChild(img);
        figure.appendChild(caption);
        blogCover.appendChild(figure);
        blogCover.hidden = false;
      }

      function renderBlogPreview() {
        if (!blogPreview) {
          return;
        }
        blogPreview.innerHTML = '';
        if (!blogState.paragraphs.length) {
          const placeholder = document.createElement('p');
          placeholder.className = 'ai-blog-preview__placeholder';
          placeholder.textContent = 'Generated blog content will appear here.';
          blogPreview.appendChild(placeholder);
          return;
        }

        blogState.paragraphs.forEach((paragraph, index) => {
          const block = document.createElement('article');
          block.className = 'ai-blog-preview__block';
          if (/^#{1,6}\s+/.test(paragraph)) {
            const heading = document.createElement('h3');
            heading.textContent = paragraph.replace(/^#{1,6}\s+/, '').trim();
            block.appendChild(heading);
          } else {
            const text = document.createElement('p');
            text.textContent = paragraph;
            block.appendChild(text);
          }
          const actions = document.createElement('div');
          actions.className = 'ai-blog-preview__block-actions';
          const regen = document.createElement('button');
          regen.type = 'button';
          regen.className = 'btn btn-ghost btn-sm';
          regen.textContent = 'Regenerate';
          regen.addEventListener('click', () => {
            regenerateParagraph(index, regen);
          });
          actions.appendChild(regen);
          block.appendChild(actions);
          blogPreview.appendChild(block);
        });
        updateBlogWordCount();
      }

      async function regenerateParagraph(index, triggerButton) {
        if (blogState.regenerating) {
          return;
        }
        const paragraph = blogState.paragraphs[index];
        if (!paragraph) {
          return;
        }
        syncBlogStateFromInputs(!manual);
        blogState.regenerating = true;
        if (triggerButton) {
          triggerButton.disabled = true;
        }
        updateBlogStatus('Regenerating paragraph…');
        try {
          const response = await fetch('api/gemini.php?action=blog-regenerate-paragraph', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              paragraph,
              context: blogState.paragraphs.join(' '),
              title: blogState.title,
              tone: blogState.tone,
              use_brand_profile: blogState.useBrandProfile,
            }),
          });
          if (!response.ok) {
            throw new Error('Unable to regenerate paragraph.');
          }
          const payload = await response.json();
          if (!payload || !payload.success || !payload.paragraph) {
            throw new Error(payload && payload.error ? payload.error : 'Gemini did not return a revision.');
          }
          blogState.paragraphs[index] = payload.paragraph.trim();
          renderBlogPreview();
          markBlogDirty();
          showToast('Paragraph refreshed.', 'success');
        } catch (error) {
          showToast(error.message || 'Unable to regenerate paragraph.', 'error');
        } finally {
          blogState.regenerating = false;
          if (triggerButton) {
            triggerButton.disabled = false;
          }
          updateBlogStatus('Ready');
        }
      }

      async function saveBlogDraft(manual = false) {
        if (!blogShell || blogState.saving) {
          return;
        }
        if (!blogState.dirty && !manual) {
          return;
        }
        syncBlogStateFromInputs(true);
        if ((!blogState.title || blogState.paragraphs.length === 0) && manual) {
          showToast('Add a title and generate content before saving.', 'warning');
          return;
        }
        if (!blogState.title || blogState.paragraphs.length === 0) {
          return;
        }
        blogState.saving = true;
        updateBlogAutosave(manual ? 'Saving draft…' : 'Auto-saving…', 'info');
        try {
          const response = await fetch('api/gemini.php?action=blog-autosave', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              title: blogState.title,
              brief: blogState.brief,
              keywords: blogState.keywords,
              tone: blogState.tone,
              paragraphs: blogState.paragraphs,
              coverImage: blogState.coverImage,
              coverImageAlt: blogState.coverImageAlt,
              length: blogState.lengthConfig || getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount),
              wordCount: getApproxWordCount(),
              draftId: blogState.draftId,
            }),
          });
          if (!response.ok) {
            throw new Error('Unable to save draft.');
          }
          const payload = await response.json();
          blogState.dirty = false;
          if (payload && payload.draftId) {
            blogState.draftId = payload.draftId;
          }
          if (payload && payload.savedAt) {
            const saved = new Date(payload.savedAt);
            updateBlogAutosave(`Draft saved ${saved.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}`, 'success');
          } else {
            updateBlogAutosave('Draft saved', 'success');
          }
          if (manual) {
            showToast('Draft saved successfully.', 'success');
          }
        } catch (error) {
          updateBlogAutosave('Failed to save draft', 'error');
          if (manual) {
            showToast(error.message || 'Failed to save draft.', 'error');
          }
        } finally {
          blogState.saving = false;
        }
      }

      async function publishBlog() {
        if (!blogShell || !blogPublishButton) {
          return;
        }
        syncBlogStateFromInputs();
        if (!blogState.title || blogState.paragraphs.length === 0) {
          showToast('Generate the blog content before publishing.', 'warning');
          return;
        }
        if (!blogState.draftId) {
          showToast('Save the draft at least once before publishing.', 'warning');
          return;
        }
        blogPublishButton.disabled = true;
        updateBlogStatus('Publishing…');
        if (blogProgress) {
          blogProgress.textContent = 'Publishing blog post…';
        }
        try {
          const response = await fetch('api/gemini.php?action=blog-publish', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              title: blogState.title,
              brief: blogState.brief,
              keywords: blogState.keywords,
              tone: blogState.tone,
              paragraphs: blogState.paragraphs,
              coverImage: blogState.coverImage,
              coverImageAlt: blogState.coverImageAlt,
              length: blogState.lengthConfig || getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount),
              wordCount: getApproxWordCount(),
              draftId: blogState.draftId,
            }),
          });
          if (!response.ok) {
            throw new Error('Unable to publish blog.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Gemini could not publish the blog.');
          }
          blogState.draftId = payload.postId || blogState.draftId;
          blogState.publishedUrl = payload.url || null;
          blogState.dirty = false;
          updateBlogAutosave('Published just now', 'success');
          updateBlogStatus('Published successfully');
          if (blogState.publishedUrl) {
            showToast(`Blog published: ${blogState.publishedUrl}`, 'success');
          } else {
            showToast('Blog published to the site. Review it in Blog publishing.', 'success');
          }
          if (blogProgress) {
            blogProgress.textContent = '';
          }
        } catch (error) {
          showToast(error.message || 'Failed to publish blog.', 'error');
          updateBlogStatus('Publish failed');
        } finally {
          blogPublishButton.disabled = false;
          if (blogProgress && blogProgress.textContent === 'Publishing blog post…') {
            blogProgress.textContent = '';
          }
        }
      }

      function startBlogGeneration() {
        if (!blogShell || blogState.generating) {
          return;
        }
        syncBlogStateFromInputs();
        if (
          blogState.lengthPreset === 'custom' &&
          (!blogState.customWordCount || blogState.customWordCount < 300 || blogState.customWordCount > 3000)
        ) {
          showToast('Enter a custom word count between 300 and 3000.', 'warning');
          return;
        }
        if (!blogState.title || !blogState.brief) {
          showToast('Add a title and brief before generating.', 'warning');
          return;
        }
        blogState.generating = true;
        blogState.paragraphs = [];
        blogState.outline = null;
        blogState.wordCount = 0;
        renderBlogPreview();
        updateBlogStatus('Generating blog…');
        if (blogProgress) {
          blogProgress.textContent = 'Generating blog draft…';
        }
        if (blogGenerateButton) {
          blogGenerateButton.disabled = true;
        }

        (async () => {
          try {
            const response = await fetch('api/gemini.php?action=blog-generate', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                title: blogState.title,
                brief: blogState.brief,
                keywords: blogState.keywords,
                tone: blogState.tone,
                use_brand_profile: blogState.useBrandProfile,
                length: blogState.lengthConfig || getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount),
              }),
            });
            if (!response.ok || !response.body) {
              throw new Error('Gemini could not stream the blog.');
            }
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            const handleEvent = (eventName, payload) => {
              if (eventName === 'status' && payload && payload.message) {
                if (blogProgress) {
                  blogProgress.textContent = payload.message;
                }
                updateBlogStatus(payload.message);
              }
              if (eventName === 'outline') {
                blogState.outline = payload && payload.outline ? payload.outline : null;
                if (blogProgress && payload && payload.message) {
                  blogProgress.textContent = payload.message;
                }
                updateBlogStatus('Outline generated');
              }
              if (eventName === 'progress' && payload && payload.message) {
                if (blogProgress) {
                  blogProgress.textContent = payload.message;
                }
              }
              if (eventName === 'chunk' && payload.paragraph) {
                blogState.paragraphs.push(payload.paragraph);
                renderBlogPreview();
              }
              if (eventName === 'done') {
                if (Array.isArray(payload.paragraphs)) {
                  blogState.paragraphs = payload.paragraphs;
                  renderBlogPreview();
                }
                if (payload && payload.draftId) {
                  blogState.draftId = payload.draftId;
                }
                if (typeof payload.usesBrandProfile === 'boolean') {
                  blogState.useBrandProfile = payload.usesBrandProfile;
                  if (blogBrandToggle) {
                    blogBrandToggle.checked = payload.usesBrandProfile || (blogBrandToggle.checked && !brandProfileReady);
                  }
                }
                if (payload.brandProfileMissing) {
                  showToast('Brand Profile missing — generated copy is generic. Add brand details to personalise.', 'warning');
                }
                if (payload.skippedSections && payload.skippedSections.length) {
                  showToast(`Skipped sections: ${payload.skippedSections.join(', ')}.`, 'warning');
                }
                if (typeof payload.wordCount === 'number') {
                  blogState.wordCount = payload.wordCount;
                }
                if (payload.length) {
                  const customLength = payload.length.customWordCount || payload.length.custom || null;
                  applyLengthSelection(payload.length.preset || blogState.lengthPreset, customLength);
                  blogState.lengthConfig = payload.length;
                }
                if (payload.image && payload.image.path) {
                  blogState.coverImage = payload.image.path;
                  blogState.coverImageAlt = payload.image.alt || '';
                  renderBlogCover();
                  showToast('AI illustration attached to the draft.', 'success');
                }
                blogState.dirty = true;
                updateBlogWordCount();
                updateBlogStatus('Draft ready');
                updateBlogAutosave('Draft updated · remember to save', 'info');
              }
              if (eventName === 'error') {
                const message = payload && payload.message ? payload.message : 'Gemini was unable to complete the blog.';
                showToast(message, 'error');
                updateBlogStatus('Generation failed');
              }
            };

            while (true) {
              const { value, done } = await reader.read();
              if (done) {
                break;
              }
              buffer += decoder.decode(value, { stream: true });
              let boundary;
              while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                const rawEvent = buffer.slice(0, boundary).trim();
                buffer = buffer.slice(boundary + 2);
                if (!rawEvent) {
                  continue;
                }
                const lines = rawEvent.split('\n');
                let eventName = 'message';
                let dataString = '';
                lines.forEach((line) => {
                  if (line.startsWith('event:')) {
                    eventName = line.replace('event:', '').trim();
                  }
                  if (line.startsWith('data:')) {
                    dataString += line.replace('data:', '').trim();
                  }
                });
                let parsed = {};
                try {
                  parsed = dataString ? JSON.parse(dataString) : {};
                } catch (parseError) {
                  parsed = {};
                }
                handleEvent(eventName, parsed);
              }
            }
          } catch (error) {
            showToast(error.message || 'Unable to generate blog.', 'error');
            updateBlogStatus('Generation failed');
          } finally {
            blogState.generating = false;
            if (blogGenerateButton) {
              blogGenerateButton.disabled = false;
            }
            if (blogProgress) {
              blogProgress.textContent = '';
            }
          }
        })();
      }

      async function loadBlogDraft() {
        if (!blogShell) {
          return;
        }
        try {
          const response = await fetch('api/gemini.php?action=blog-load-draft', {
            method: 'GET',
            headers: {
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error('Unable to load saved draft.');
          }
          const payload = await response.json();
          const draft = payload && payload.draft ? payload.draft : {};
          if (draft && Object.keys(draft).length > 0) {
            if (blogTitleInput) {
              blogTitleInput.value = draft.title || '';
            }
            if (blogBriefInput) {
              blogBriefInput.value = draft.brief || '';
            }
            if (blogKeywordsInput) {
              blogKeywordsInput.value = draft.keywords || '';
            }
            if (blogToneInput) {
              blogToneInput.value = draft.tone || '';
            }
            blogState.title = draft.title || '';
            blogState.brief = draft.brief || '';
            blogState.keywords = draft.keywords || '';
            blogState.tone = draft.tone || '';
            const draftLength = draft.length || {};
            const draftCustomLength = draftLength.customWordCount || draftLength.custom || null;
            applyLengthSelection(draftLength.preset || 'standard', draftCustomLength);
            blogState.lengthConfig = Object.keys(draftLength).length
              ? draftLength
              : getBlogLengthConfig(blogState.lengthPreset, blogState.customWordCount);
            blogState.paragraphs = Array.isArray(draft.paragraphs) ? draft.paragraphs : [];
            blogState.coverImage = draft.coverImage || '';
            blogState.coverImageAlt = draft.coverImageAlt || '';
            blogState.draftId = draft.draftId || draft.postId || null;
            blogState.wordCount = draft.wordCount || estimateWordCount(blogState.paragraphs);
            blogState.dirty = false;
            renderBlogPreview();
            renderBlogCover();
            if (draft.updatedAt) {
              const loaded = new Date(draft.updatedAt);
              updateBlogAutosave(`Draft loaded · saved ${loaded.toLocaleString('en-IN', { hour: '2-digit', minute: '2-digit' })}`, 'success');
            } else {
              updateBlogAutosave('Draft loaded', 'info');
            }
            updateBlogStatus('Draft loaded');
          } else {
            updateBlogAutosave('No saved draft yet', 'muted');
            updateBlogStatus('Idle');
          }
        } catch (error) {
          updateBlogAutosave('Unable to load draft', 'error');
        }
      }

      if (blogShell) {
        updateBlogStatus('Idle');
        applyLengthSelection(blogState.lengthPreset, blogState.customWordCount);
        updateBlogWordCount();
        loadBlogDraft();
        const inputs = [blogTitleInput, blogBriefInput, blogKeywordsInput, blogToneInput];
        inputs.forEach((input) => {
          if (!input) {
            return;
          }
          input.addEventListener('input', () => {
            markBlogDirty();
          });
        });
        blogLengthOptions.forEach((option) => {
          option.addEventListener('change', () => {
            updateBlogLengthState();
            markBlogDirty();
          });
        });
        if (blogLengthCustomInput) {
          blogLengthCustomInput.addEventListener('input', () => {
            updateBlogLengthState();
            markBlogDirty();
          });
        }
        if (blogGenerateButton) {
          blogGenerateButton.addEventListener('click', startBlogGeneration);
        }
        if (blogSaveButton) {
          blogSaveButton.addEventListener('click', () => {
            saveBlogDraft(true);
          });
        }
        if (blogPublishButton) {
          blogPublishButton.addEventListener('click', publishBlog);
        }
        if (blogPreviewButton && blogPreview) {
          blogPreviewButton.addEventListener('click', () => {
            blogPreview.scrollIntoView({ behavior: 'smooth' });
          });
        }

        window.setInterval(() => {
          saveBlogDraft(false);
        }, 10000);
      }

      const imageShell = document.querySelector('[data-image-generator]');
      const imageStatus = document.querySelector('[data-image-status]');
      const imagePrompt = document.querySelector('[data-image-prompt]');
      const imageAspectRatio = document.querySelector('[data-image-aspect-ratio]');
      const imageAutofill = document.querySelector('[data-image-autofill]');
      const imageGenerate = document.querySelector('[data-image-generate]');
      const imageBrandToggle = document.querySelector('[data-image-brand-toggle]');
      const imagePreview = document.querySelector('[data-image-preview]');
      const imageOutput = document.querySelector('[data-image-output]');
      const imageCaption = document.querySelector('[data-image-caption]');
      const imageDownload = document.querySelector('[data-image-download]');
      const imageAttach = document.querySelector('[data-image-attach]');
      const imageFixInput = document.querySelector('[data-image-fix]');
      const imageRegenerate = document.querySelector('[data-image-regenerate]');

      function updateImageStatus(message) {
        if (imageStatus) {
          imageStatus.textContent = message;
        }
      }

      async function generateImage() {
        if (!imageShell || !imagePrompt) {
          return;
        }
        const prompt = imagePrompt.value.trim();
        if (!prompt) {
          showToast('Write a short prompt for the illustration.', 'warning');
          return;
        }
        const aspectRatio = imageAspectRatio ? imageAspectRatio.value : '1:1';
        const fixInstructions = imageFixInput ? imageFixInput.value.trim() : '';
        blogState.imageFix = fixInstructions;
        if (imageGenerate) {
          imageGenerate.disabled = true;
        }
        if (imageRegenerate) {
          imageRegenerate.disabled = true;
        }
        updateImageStatus(fixInstructions ? 'Regenerating image with fixes…' : 'Generating image…');
        try {
          const useBrandProfile = resolveBrandUsage(imageBrandToggle ? imageBrandToggle.checked : blogState.useBrandProfile || brandProfileReady);
          const response = await fetch('api/gemini.php?action=image-generate', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              prompt,
              draftId: blogState.draftId,
              use_brand_profile: useBrandProfile,
              fix_instructions: fixInstructions,
              title: blogState.title,
              brief: blogState.brief,
              tone: blogState.tone,
              keywords: blogState.keywords,
              aspect_ratio: aspectRatio,
            }),
          });
          if (!response.ok) {
            throw new Error('Unable to generate image.');
          }
          const payload = await response.json();
          if (!payload || !payload.success || !payload.image) {
            throw new Error(payload && payload.error ? payload.error : 'Gemini image output missing.');
          }
          const sizeUsed = payload.image.size_used || payload.image.sizeUsed || (payload.image.dimensions ? `${payload.image.dimensions.width}x${payload.image.dimensions.height}` : '');
          const notice = payload.notice || payload.image.notice;
          const path = payload.image.path;
          const url = path.startsWith('/') ? path : `/${path}`;
          if (imageOutput) {
            imageOutput.src = url;
          }
          if (imageDownload) {
            imageDownload.href = url;
          }
          if (imageCaption) {
            const timestamp = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
            const suffix = sizeUsed ? ` · ${sizeUsed}` : '';
            imageCaption.textContent = `Generated via Gemini image model · ${timestamp}${suffix}`;
          }
          if (imagePreview) {
            imagePreview.hidden = false;
          }
          if (notice) {
            showToast(notice, 'warning');
          }
          blogState.coverImage = path;
          blogState.coverImageAlt = blogState.title ? `AI illustration for ${blogState.title}` : 'AI generated illustration';
          renderBlogCover();
          markBlogDirty();
          updateImageStatus('Image ready and attached');
          showToast('Image attached to the draft.', 'success');
        } catch (error) {
          updateImageStatus('Image generation failed');
          showToast(error.message || 'Unable to generate image.', 'error');
        } finally {
          if (imageGenerate) {
            imageGenerate.disabled = false;
          }
          if (imageRegenerate) {
            imageRegenerate.disabled = false;
          }
        }
      }

      if (imageShell) {
        updateImageStatus('Idle');
        if (imageGenerate) {
          imageGenerate.addEventListener('click', generateImage);
        }
        if (imageRegenerate) {
          imageRegenerate.addEventListener('click', generateImage);
        }
        if (imageAutofill) {
          imageAutofill.addEventListener('click', () => {
            syncBlogStateFromInputs(true);
            const segments = [];
            if (blogState.title) {
              segments.push(`Hero illustration for "${blogState.title}"`);
            }
            if (blogState.keywords) {
              segments.push(`Keywords: ${blogState.keywords}`);
            }
            if (blogState.brief) {
              segments.push(blogState.brief);
            }
            if (!segments.length) {
              showToast('Add blog details to build an image prompt.', 'info');
              return;
            }
            if (imagePrompt) {
              imagePrompt.value = `${segments.join('. ')}.`;
              imagePrompt.focus();
            }
            updateImageStatus('Prompt filled using blog context');
          });
        }
        if (imageAttach && imageOutput) {
          imageAttach.addEventListener('click', () => {
            if (!imageOutput.src) {
              showToast('Generate an image first.', 'warning');
              return;
            }
            const currentPath = imageOutput.src.replace(window.location.origin, '');
            blogState.coverImage = currentPath.startsWith('/') ? currentPath.substring(1) : currentPath;
            blogState.coverImageAlt = blogState.title ? `AI illustration for ${blogState.title}` : 'AI generated illustration';
            renderBlogCover();
            markBlogDirty();
            showToast('Image attached to draft.', 'success');
          });
        }
        if (imageDownload) {
          imageDownload.addEventListener('click', () => {
            if (!imageDownload.href || imageDownload.href === '#') {
              showToast('Generate an image before downloading.', 'info');
            }
          });
        }
      }

      const ttsShell = document.querySelector('[data-tts-generator]');
      const ttsStatus = document.querySelector('[data-tts-status]');
      const ttsText = document.querySelector('[data-tts-text]');
      const ttsFormat = document.querySelector('[data-tts-format]');
      const ttsGenerate = document.querySelector('[data-tts-generate]');
      const ttsOutput = document.querySelector('[data-tts-output]');
      const ttsAudio = document.querySelector('[data-tts-audio]');
      const ttsDownload = document.querySelector('[data-tts-download]');

      function updateTtsStatus(message) {
        if (ttsStatus) {
          ttsStatus.textContent = message;
        }
      }

      if (ttsShell && ttsGenerate) {
        updateTtsStatus('Idle');
        ttsGenerate.addEventListener('click', async () => {
          if (!ttsText) {
            return;
          }
          const text = ttsText.value.trim();
          if (!text) {
            showToast('Enter the text that should be voiced.', 'warning');
            return;
          }
          const format = ttsFormat ? ttsFormat.value : 'mp3';
          ttsGenerate.disabled = true;
          updateTtsStatus('Generating audio…');
          try {
            const response = await fetch('api/gemini.php?action=tts-generate', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ text, format }),
            });
            if (!response.ok) {
              throw new Error('Unable to generate audio.');
            }
            const payload = await response.json();
            if (!payload || !payload.success || !payload.audio) {
              throw new Error(payload && payload.error ? payload.error : 'Gemini TTS failed.');
            }
            const path = payload.audio.path;
            const url = path.startsWith('/') ? path : `/${path}`;
            if (ttsAudio) {
              ttsAudio.src = url;
              ttsAudio.load();
            }
            if (ttsDownload) {
              ttsDownload.href = url;
            }
            if (ttsOutput) {
              ttsOutput.hidden = false;
            }
            updateTtsStatus('Audio ready');
            showToast('Audio file generated.', 'success');
          } catch (error) {
            updateTtsStatus('Audio generation failed');
            showToast(error.message || 'Unable to generate audio.', 'error');
          } finally {
            ttsGenerate.disabled = false;
          }
        });
      }

      const sandboxShell = document.querySelector('[data-sandbox]');
      if (sandboxShell) {
        const sandboxTabs = sandboxShell.querySelectorAll('[data-sandbox-tab]');
        const sandboxPanels = sandboxShell.querySelectorAll('[data-sandbox-panel]');
        const sandboxTextForm = sandboxShell.querySelector('[data-sandbox-text-form]');
        const sandboxTextInput = sandboxShell.querySelector('[data-sandbox-text-input]');
        const sandboxTextOutput = sandboxShell.querySelector('[data-sandbox-text-output]');
        const sandboxBrandToggle = sandboxShell.querySelector('[data-sandbox-brand-toggle]');
        const sandboxBrandPreview = sandboxShell.querySelector('[data-sandbox-brand-preview]');
        const sandboxImageForm = sandboxShell.querySelector('[data-sandbox-image-form]');
        const sandboxImageInput = sandboxShell.querySelector('[data-sandbox-image-input]');
        const sandboxImageOutput = sandboxShell.querySelector('[data-sandbox-image-output]');
        const sandboxImagePreview = sandboxShell.querySelector('[data-sandbox-image-preview]');
        const sandboxImageCaption = sandboxShell.querySelector('[data-sandbox-image-caption]');
        const sandboxImageDownload = sandboxShell.querySelector('[data-sandbox-image-download]');
        const sandboxImageAspect = sandboxShell.querySelector('[data-sandbox-image-aspect]');
        const sandboxImageBrandToggle = sandboxShell.querySelector('[data-sandbox-image-brand]');
        const sandboxImageBrandHelp = sandboxShell.querySelector('[data-sandbox-image-brand-help]');
        const sandboxTtsForm = sandboxShell.querySelector('[data-sandbox-tts-form]');
        const sandboxTtsInput = sandboxShell.querySelector('[data-sandbox-tts-input]');
        const sandboxTtsFormat = sandboxShell.querySelector('[data-sandbox-tts-format]');
        const sandboxTtsOutput = sandboxShell.querySelector('[data-sandbox-tts-output]');
        const sandboxTtsAudio = sandboxShell.querySelector('[data-sandbox-tts-audio]');
        const sandboxTtsDownload = sandboxShell.querySelector('[data-sandbox-tts-download]');
        let sandboxTextTimer = null;

        function activateSandboxTab(name) {
          sandboxTabs.forEach((tab) => {
            const isActive = tab.getAttribute('data-sandbox-tab') === name;
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
          });
          sandboxPanels.forEach((panel) => {
            const isActive = panel.getAttribute('data-sandbox-panel') === name;
            panel.hidden = !isActive;
          });
        }

        function updateSandboxStatus(kind, message) {
          const status = sandboxShell.querySelector(`[data-sandbox-${kind}-status]`);
          if (status) {
            status.textContent = message;
          }
        }

        function streamSandboxText(target, text) {
          if (!target) {
            return;
          }
          if (sandboxTextTimer !== null) {
            window.clearTimeout(sandboxTextTimer);
            sandboxTextTimer = null;
          }
          target.innerHTML = '';
          const article = document.createElement('article');
          article.className = 'ai-sandbox__text';
          target.appendChild(article);
          const tokens = text.split(/(\s+)/);
          let index = 0;
          function step() {
            if (index >= tokens.length) {
              sandboxTextTimer = null;
              return;
            }
            article.innerHTML += escapeHtml(tokens[index]).replace(/\n/g, '<br />');
            target.scrollTop = target.scrollHeight;
            index += 1;
            sandboxTextTimer = window.setTimeout(step, 35);
          }
          step();
        }

        sandboxTabs.forEach((tab) => {
          tab.addEventListener('click', () => {
            const target = tab.getAttribute('data-sandbox-tab');
            if (target) {
              activateSandboxTab(target);
            }
          });
        });

        if (sandboxBrandToggle) {
          sandboxBrandToggle.addEventListener('change', () => {
            if (sandboxBrandToggle.checked && !smartMarketingBrandReady) {
              showToast('Brand Profile not configured; continuing without it.', 'warning');
            }
          });
        }

        if (sandboxImageBrandToggle && sandboxImageBrandHelp && !smartMarketingBrandReady) {
          sandboxImageBrandHelp.classList.add('ai-sandbox__meta--warning');
        }
        if (sandboxImageBrandToggle) {
          sandboxImageBrandToggle.addEventListener('change', () => {
            if (sandboxImageBrandToggle.checked && !smartMarketingBrandReady) {
              showToast('Brand Profile not configured; continuing without it.', 'warning');
            }
          });
        }

        if (sandboxTextForm && sandboxTextInput) {
          sandboxTextForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const prompt = sandboxTextInput.value.trim();
            if (!prompt) {
              showToast('Enter a prompt for Gemini.', 'info');
              return;
            }
            const useBrandProfile = sandboxBrandToggle ? sandboxBrandToggle.checked : false;
            if (useBrandProfile && !smartMarketingBrandReady) {
              showToast('Brand Profile not configured; continuing without it.', 'warning');
            }
            const runButton = sandboxTextForm.querySelector('[data-sandbox-text-run]');
            if (runButton) {
              runButton.disabled = true;
            }
            updateSandboxStatus('text', 'Generating…');
            try {
              const response = await fetch('api/gemini.php?action=sandbox-text', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': window.csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ prompt, use_brand_profile: useBrandProfile }),
              });
              if (!response.ok) {
                throw new Error('Unable to reach Gemini text sandbox.');
              }
              const payload = await response.json();
              if (!payload || !payload.success || !payload.text) {
                throw new Error(payload && payload.error ? payload.error : 'Gemini returned no text.');
              }
              updateSandboxStatus('text', 'Streaming response…');
              streamSandboxText(sandboxTextOutput, payload.text);
              if (payload.notice) {
                showToast(payload.notice, 'warning');
              }
            } catch (error) {
              updateSandboxStatus('text', 'Text generation failed');
              showToast(error.message || 'Gemini text sandbox failed.', 'error');
            } finally {
              if (runButton) {
                runButton.disabled = false;
              }
            }
          });
        }

        if (sandboxImageForm && sandboxImageInput) {
          sandboxImageForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const prompt = sandboxImageInput.value.trim();
            if (!prompt) {
              showToast('Add a description for the visual.', 'info');
              return;
            }
            const aspectRatio = sandboxImageAspect ? sandboxImageAspect.value : '1:1';
            const useBrandProfile = sandboxImageBrandToggle ? sandboxImageBrandToggle.checked : false;
            if (useBrandProfile && !smartMarketingBrandReady) {
              showToast('Brand Profile not configured; continuing without it.', 'warning');
            }
            const runButton = sandboxImageForm.querySelector('[data-sandbox-image-run]');
            if (runButton) {
              runButton.disabled = true;
            }
            updateSandboxStatus('image', 'Generating image…');
            try {
              const response = await fetch('api/gemini.php?action=sandbox-image', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': window.csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ prompt, aspect_ratio: aspectRatio, use_brand_profile: useBrandProfile }),
              });
              if (!response.ok) {
                throw new Error('Unable to reach Gemini image sandbox.');
              }
              const payload = await response.json();
              if (!payload || !payload.success || !payload.image || !payload.image.path) {
                throw new Error(payload && payload.error ? payload.error : 'Gemini did not return an image.');
              }
              const path = payload.image.path;
              const url = path.startsWith('/') ? path : `/${path}`;
              const sizeUsed = payload.image.size_used || payload.image.sizeUsed || (payload.image.dimensions ? `${payload.image.dimensions.width}x${payload.image.dimensions.height}` : '');
              const aspectUsed = payload.image.aspect_ratio || aspectRatio;
              const brandUsed = !!payload.usedBrandProfile;
              if (sandboxImagePreview) {
                sandboxImagePreview.src = url;
              }
              if (sandboxImageCaption) {
                const parts = [];
                if (aspectUsed) {
                  parts.push(`Aspect ratio: ${aspectUsed}${sizeUsed ? ` (size used: ${sizeUsed})` : ''}`);
                } else if (sizeUsed) {
                  parts.push(`Size used: ${sizeUsed}`);
                }
                parts.push(`Brand profile: ${brandUsed ? 'ON' : 'OFF'}`);
                sandboxImageCaption.textContent = `Generated ${new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}${parts.length ? ' · ' + parts.join(' · ') : ''}`;
              }
              if (sandboxImageDownload) {
                sandboxImageDownload.href = url;
              }
              if (sandboxImageOutput) {
                sandboxImageOutput.hidden = false;
              }
              updateSandboxStatus('image', 'Image ready');
              if (payload.notice) {
                showToast(payload.notice, 'warning');
              } else {
                showToast('Sandbox image ready.', 'success');
              }
            } catch (error) {
              updateSandboxStatus('image', 'Image generation failed');
              showToast(error.message || 'Gemini image sandbox failed.', 'error');
            } finally {
              if (runButton) {
                runButton.disabled = false;
              }
            }
          });
        }

        if (sandboxTtsForm && sandboxTtsInput) {
          sandboxTtsForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const text = sandboxTtsInput.value.trim();
            if (!text) {
              showToast('Provide text for the TTS sandbox.', 'info');
              return;
            }
            const format = sandboxTtsFormat ? sandboxTtsFormat.value : 'mp3';
            const runButton = sandboxTtsForm.querySelector('[data-sandbox-tts-run]');
            if (runButton) {
              runButton.disabled = true;
            }
            updateSandboxStatus('tts', 'Generating audio…');
            try {
              const response = await fetch('api/gemini.php?action=sandbox-tts', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': window.csrfToken || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ text, format }),
              });
              if (!response.ok) {
                throw new Error('Unable to reach Gemini TTS sandbox.');
              }
              const payload = await response.json();
              if (!payload || !payload.success || !payload.audio || !payload.audio.path) {
                throw new Error(payload && payload.error ? payload.error : 'Gemini did not return audio.');
              }
              const path = payload.audio.path;
              const url = path.startsWith('/') ? path : `/${path}`;
              if (sandboxTtsAudio) {
                sandboxTtsAudio.src = url;
                sandboxTtsAudio.load();
              }
              if (sandboxTtsDownload) {
                sandboxTtsDownload.href = url;
              }
              if (sandboxTtsOutput) {
                sandboxTtsOutput.hidden = false;
              }
              updateSandboxStatus('tts', 'Audio ready');
              showToast('Sandbox audio ready.', 'success');
            } catch (error) {
              updateSandboxStatus('tts', 'Audio generation failed');
              showToast(error.message || 'Gemini TTS sandbox failed.', 'error');
            } finally {
              if (runButton) {
                runButton.disabled = false;
              }
            }
          });
        }
      }

      const schedulerShell = document.querySelector('[data-scheduler-shell]');
      const schedulerEntries = schedulerShell ? schedulerShell.querySelector('[data-scheduler-entries]') : null;
      const schedulerTemplate = document.getElementById('scheduler-entry-template');
      const schedulerAddButton = schedulerShell ? schedulerShell.querySelector('[data-scheduler-add]') : null;
      const schedulerSaveButton = schedulerShell ? schedulerShell.querySelector('[data-scheduler-save]') : null;
      const schedulerStatus = schedulerShell ? schedulerShell.querySelector('[data-scheduler-status]') : null;
      const schedulerNext = schedulerShell ? schedulerShell.querySelector('[data-scheduler-next]') : null;
      const schedulerLogs = schedulerShell ? schedulerShell.querySelector('[data-scheduler-logs]') : null;
      const automationCards = schedulerShell ? schedulerShell.querySelector('[data-automation-cards]') : null;
      const festivalCards = schedulerShell ? schedulerShell.querySelector('[data-festival-cards]') : null;
      const schedulerState = { autoRunning: false, automations: [], festivals: [] };

      function setSchedulerStatus(message) {
        if (schedulerStatus) {
          schedulerStatus.textContent = message;
        }
      }

      function formatSchedulerDate(value, includeTime = true) {
        if (!value) {
          return '—';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
          return '—';
        }
        const options = { day: '2-digit', month: 'short', year: 'numeric' };
        if (includeTime) {
          options.hour = '2-digit';
          options.minute = '2-digit';
        }
        return date.toLocaleString('en-IN', options);
      }

      function ensureFrequencyVisibility(entry) {
        const typeField = entry.querySelector('[data-entry-type]');
        const frequencyGroup = entry.querySelector('[data-entry-frequency-group]');
        if (!frequencyGroup) {
          return;
        }
        frequencyGroup.hidden = !typeField || typeField.value !== 'recurring';
      }

      function attachEntryEvents(entry) {
        const typeField = entry.querySelector('[data-entry-type]');
        if (typeField) {
          typeField.addEventListener('change', () => ensureFrequencyVisibility(entry));
        }
        entry.querySelectorAll('input, textarea, select').forEach((field) => {
          field.addEventListener('input', () => entry.classList.remove('automation-entry--error'));
        });
        const removeButton = entry.querySelector('[data-entry-remove]');
        if (removeButton) {
          removeButton.addEventListener('click', () => {
            entry.remove();
            if (schedulerEntries && schedulerEntries.children.length === 0) {
              createEntry();
            }
          });
        }
      }

      function normalizeTime(value) {
        if (!value) {
          return '09:00';
        }
        const parts = value.split(':');
        if (parts.length < 2) {
          return '09:00';
        }
        return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
      }

      function createEntry(prefill = {}) {
        if (!schedulerTemplate || !schedulerEntries) {
          return null;
        }
        const fragment = schedulerTemplate.content.cloneNode(true);
        const entry = fragment.querySelector('[data-entry]');
        if (!entry) {
          return null;
        }
        const defaultDate = new Date().toISOString().slice(0, 10);
        const titleField = entry.querySelector('[data-entry-title]');
        const topicField = entry.querySelector('[data-entry-topic]');
        const descriptionField = entry.querySelector('[data-entry-description]');
        const dateField = entry.querySelector('[data-entry-date]');
        const timeField = entry.querySelector('[data-entry-time]');
        const typeField = entry.querySelector('[data-entry-type]');
        const frequencyField = entry.querySelector('[data-entry-frequency]');
        const idField = entry.querySelector('[data-entry-id]');
        const statusField = entry.querySelector('[data-entry-status]');
        const festivalNameField = entry.querySelector('[data-entry-festival-name]');
        const festivalDateField = entry.querySelector('[data-entry-festival-date]');

        if (titleField) {
          titleField.value = prefill.title || '';
        }
        if (topicField) {
          topicField.value = prefill.topic || '';
        }
        if (descriptionField) {
          descriptionField.value = prefill.description || '';
        }
        if (dateField) {
          dateField.value = prefill.date || defaultDate;
        }
        if (timeField) {
          timeField.value = normalizeTime(prefill.time || '09:00');
        }
        if (typeField) {
          typeField.value = prefill.type || 'once';
        }
        if (frequencyField) {
          frequencyField.value = prefill.frequency || 'weekly';
        }
        if (idField) {
          idField.value = prefill.id || '';
        }
        if (statusField) {
          statusField.value = prefill.status || 'active';
        }
        if (festivalNameField) {
          festivalNameField.value = prefill.festival && prefill.festival.name ? prefill.festival.name : '';
        }
        if (festivalDateField) {
          festivalDateField.value = prefill.festival && prefill.festival.date ? prefill.festival.date : '';
        }

        ensureFrequencyVisibility(entry);
        attachEntryEvents(entry);
        schedulerEntries.appendChild(entry);
        return entry;
      }

      function readEntry(entry) {
        const value = (selector) => {
          const field = entry.querySelector(selector);
          return field ? field.value.trim() : '';
        };
        const topic = value('[data-entry-topic]');
        const date = value('[data-entry-date]');
        const title = value('[data-entry-title]');
        const description = value('[data-entry-description]');
        const time = value('[data-entry-time]') || '09:00';
        const type = value('[data-entry-type]') || 'once';
        const frequency = value('[data-entry-frequency]') || 'weekly';
        const id = value('[data-entry-id]');
        const status = value('[data-entry-status]') || 'active';
        const festivalName = value('[data-entry-festival-name]');
        const festivalDate = value('[data-entry-festival-date]');
        if (!topic || !date) {
          entry.classList.add('automation-entry--error');
          return null;
        }
        entry.classList.remove('automation-entry--error');
        const payload = {
          id,
          title,
          topic,
          description,
          date,
          time: normalizeTime(time),
          type,
          frequency,
          status,
        };
        if (festivalName && festivalDate) {
          payload.festival = { name: festivalName, date: festivalDate };
        }
        return payload;
      }

      function collectEntries() {
        if (!schedulerEntries) {
          return [];
        }
        const entries = Array.from(schedulerEntries.querySelectorAll('[data-entry]'));
        const payload = [];
        let invalid = false;
        entries.forEach((entry) => {
          const data = readEntry(entry);
          if (!data) {
            invalid = true;
            return;
          }
          if (data.type !== 'recurring') {
            data.frequency = 'weekly';
          }
          payload.push(data);
        });
        if (invalid) {
          showToast('Fill topic and date for each automation before saving.', 'warning');
          return [];
        }
        return payload;
      }

      function renderSchedulerLogs(logs) {
        if (!schedulerLogs) {
          return;
        }
        const list = schedulerLogs.querySelector('ul');
        if (!list) {
          return;
        }
        list.innerHTML = '';
        if (!Array.isArray(logs) || logs.length === 0) {
          const empty = document.createElement('li');
          empty.className = 'automation-scheduler__empty';
          empty.textContent = 'No automated drafts generated yet.';
          list.appendChild(empty);
          return;
        }
        logs.forEach((entry) => {
          const item = document.createElement('li');
          item.className = 'automation-scheduler__item';
          const title = document.createElement('h4');
          title.textContent = entry.title || entry.topic || 'Automated draft';
          item.appendChild(title);
          const meta = document.createElement('p');
          meta.className = 'automation-scheduler__item-meta';
          const when = entry.created_at ? formatSchedulerDate(entry.created_at) : '—';
          meta.textContent = `${entry.topic || 'Scheduled topic'} · ${when}`;
          item.appendChild(meta);
          if (entry.summary) {
            const summary = document.createElement('p');
            summary.className = 'automation-scheduler__item-summary';
            summary.textContent = entry.summary;
            item.appendChild(summary);
          }
          const assets = document.createElement('div');
          assets.className = 'automation-scheduler__item-assets';
          if (entry.blog && entry.blog.url) {
            const blogLink = document.createElement('a');
            blogLink.href = entry.blog.url;
            blogLink.target = '_blank';
            blogLink.rel = 'noopener';
            blogLink.className = 'btn btn-primary btn-sm';
            blogLink.textContent = 'View blog';
            assets.appendChild(blogLink);
          }
          if (entry.draft) {
            const draftLink = document.createElement('a');
            draftLink.href = entry.draft.startsWith('/') ? entry.draft : `/${entry.draft}`;
            draftLink.className = 'btn btn-ghost btn-sm';
            draftLink.textContent = 'Download draft';
            draftLink.download = '';
            assets.appendChild(draftLink);
          }
          if (Array.isArray(entry.images)) {
            entry.images.forEach((image, index) => {
              if (!image || !image.path) {
                return;
              }
              const link = document.createElement('a');
              link.href = image.path.startsWith('/') ? image.path : `/${image.path}`;
              link.className = 'btn btn-ghost btn-sm';
              link.textContent = `Image ${index + 1}`;
              link.download = '';
              assets.appendChild(link);
            });
          }
          if (entry.audio && entry.audio.path) {
            const audioLink = document.createElement('a');
            audioLink.href = entry.audio.path.startsWith('/') ? entry.audio.path : `/${entry.audio.path}`;
            audioLink.className = 'btn btn-ghost btn-sm';
            audioLink.textContent = 'Audio';
            audioLink.download = '';
            assets.appendChild(audioLink);
          }
          if (assets.childNodes.length > 0) {
            item.appendChild(assets);
          }
          list.appendChild(item);
        });
      }

      function renderAutomations(automations) {
        if (!automationCards) {
          return;
        }
        automationCards.innerHTML = '';
        if (!Array.isArray(automations) || automations.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'automation-scheduler__empty';
          empty.textContent = 'No automations yet. Add one using the composer above.';
          automationCards.appendChild(empty);
          return;
        }
        automations.forEach((automation) => {
          const card = document.createElement('article');
          card.className = 'automation-card';
          card.dataset.state = automation.status || 'active';
          const title = document.createElement('h4');
          title.textContent = automation.title || automation.topic || 'Automation';
          card.appendChild(title);
          const status = document.createElement('span');
          status.className = 'automation-card__status';
          status.dataset.state = automation.status || 'active';
          status.textContent = (automation.status || 'active').replace(/^(.)/, (match) => match.toUpperCase());
          card.appendChild(status);
          const schedule = document.createElement('p');
          schedule.className = 'automation-card__meta';
          const scheduleDate = automation.schedule && automation.schedule.date
            ? formatSchedulerDate(`${automation.schedule.date}T${normalizeTime(automation.schedule.time || '09:00')}:00+05:30`)
            : '—';
          schedule.textContent = `Scheduled: ${scheduleDate} IST`;
          card.appendChild(schedule);
          const nextLine = document.createElement('p');
          nextLine.className = 'automation-card__meta';
          let nextLabel = '—';
          if (automation.status === 'paused') {
            nextLabel = 'Paused';
          } else if (automation.status === 'completed') {
            nextLabel = 'Completed';
          } else if (automation.next_run) {
            nextLabel = formatSchedulerDate(automation.next_run);
          }
          nextLine.textContent = `Next run: ${nextLabel}`;
          card.appendChild(nextLine);
          if (automation.blog_reference && automation.blog_reference.url) {
            const blogInfo = document.createElement('p');
            blogInfo.className = 'automation-card__blog';
            const link = document.createElement('a');
            link.href = automation.blog_reference.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = automation.blog_reference.title ? `View: ${automation.blog_reference.title}` : 'View published blog';
            blogInfo.appendChild(link);
            card.appendChild(blogInfo);
          }
          if (automation.festival && automation.festival.name) {
            const festival = document.createElement('p');
            festival.className = 'automation-card__meta';
            festival.textContent = `Festival: ${automation.festival.name}`;
            card.appendChild(festival);
          }
          const actions = document.createElement('div');
          actions.className = 'automation-card__actions';
          if (automation.status !== 'completed') {
            const runButton = document.createElement('button');
            runButton.type = 'button';
            runButton.className = 'btn btn-primary btn-sm';
            runButton.textContent = 'Run now';
            runButton.addEventListener('click', () => runAutomation(automation.id, false));
            actions.appendChild(runButton);
          }
          const editButton = document.createElement('button');
          editButton.type = 'button';
          editButton.className = 'btn btn-ghost btn-sm';
          editButton.textContent = 'Edit';
          editButton.addEventListener('click', () => populateEntryFromAutomation(automation));
          actions.appendChild(editButton);
          if (automation.status !== 'completed') {
            const toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'btn btn-ghost btn-sm';
            const operation = automation.status === 'paused' ? 'activate' : 'pause';
            toggleButton.textContent = automation.status === 'paused' ? 'Activate' : 'Pause';
            toggleButton.addEventListener('click', () => updateAutomationStatus(automation.id, operation));
            actions.appendChild(toggleButton);
          }
          const deleteButton = document.createElement('button');
          deleteButton.type = 'button';
          deleteButton.className = 'btn btn-danger btn-sm';
          deleteButton.textContent = 'Delete';
          deleteButton.addEventListener('click', () => updateAutomationStatus(automation.id, 'delete'));
          actions.appendChild(deleteButton);
          card.appendChild(actions);
          automationCards.appendChild(card);
        });
      }

      function renderFestivals(festivals) {
        if (!festivalCards) {
          return;
        }
        festivalCards.innerHTML = '';
        if (!Array.isArray(festivals) || festivals.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'automation-scheduler__empty';
          empty.textContent = 'No upcoming festivals found right now.';
          festivalCards.appendChild(empty);
          return;
        }
        festivals.forEach((festival) => {
          const card = document.createElement('article');
          card.className = 'automation-festival-card';
          const title = document.createElement('h4');
          title.textContent = festival.name;
          card.appendChild(title);
          const dateLine = document.createElement('p');
          dateLine.className = 'automation-card__meta';
          const festivalDateLabel = formatSchedulerDate(`${festival.date}T00:00:00+05:30`, false);
          dateLine.textContent = `Date: ${festivalDateLabel}`;
          card.appendChild(dateLine);
          const desc = document.createElement('p');
          desc.textContent = festival.description || 'Plan relevant content for this day.';
          card.appendChild(desc);
          if (Array.isArray(festival.tags) && festival.tags.length > 0) {
            const tags = document.createElement('div');
            tags.className = 'automation-festival-card__tags';
            festival.tags.forEach((tag) => {
              const pill = document.createElement('span');
              pill.textContent = tag;
              tags.appendChild(pill);
            });
            card.appendChild(tags);
          }
          const actions = document.createElement('div');
          actions.className = 'automation-festival-card__actions';
          const scheduleButton = document.createElement('button');
          scheduleButton.type = 'button';
          scheduleButton.className = 'btn btn-primary btn-sm';
          scheduleButton.textContent = 'Schedule automation';
          scheduleButton.addEventListener('click', () => populateFestivalEntry(festival));
          actions.appendChild(scheduleButton);
          const blogLink = document.createElement('a');
          blogLink.href = `admin-blog-manager.php?festival=${encodeURIComponent(festival.name)}&festival_date=${encodeURIComponent(festival.date)}`;
          blogLink.className = 'btn btn-ghost btn-sm';
          blogLink.target = '_blank';
          blogLink.rel = 'noopener';
          blogLink.textContent = 'Create blog for this day';
          actions.appendChild(blogLink);
          card.appendChild(actions);
          festivalCards.appendChild(card);
        });
      }

      function populateEntryFromAutomation(automation) {
        if (!automation) {
          return;
        }
        if (schedulerEntries) {
          Array.from(schedulerEntries.querySelectorAll('[data-entry]')).forEach((entry) => {
            const idField = entry.querySelector('[data-entry-id]');
            if (idField && idField.value === automation.id) {
              entry.remove();
            }
          });
        }
        createEntry({
          id: automation.id,
          title: automation.title,
          topic: automation.topic,
          description: automation.description,
          date: automation.schedule && automation.schedule.date ? automation.schedule.date : undefined,
          time: automation.schedule && automation.schedule.time ? automation.schedule.time : '09:00',
          type: automation.schedule && automation.schedule.type ? automation.schedule.type : 'once',
          frequency: automation.schedule && automation.schedule.frequency ? automation.schedule.frequency : 'weekly',
          festival: automation.festival || null,
          status: automation.status || 'active',
        });
      }

      function populateFestivalEntry(festival) {
        if (!festival) {
          return;
        }
        createEntry({
          title: festival.name,
          topic: `${festival.name} clean energy ideas`,
          description: festival.description || '',
          date: festival.date,
          time: '09:00',
          type: 'once',
          festival,
        });
        showToast(`Automation pre-filled for ${festival.name}.`, 'success');
      }

      function maybeTriggerAutoRun() {
        if (!Array.isArray(schedulerState.automations)) {
          return;
        }
        const now = Date.now();
        const due = schedulerState.automations.find((automation) => {
          if (!automation || automation.status !== 'active' || !automation.next_run) {
            return false;
          }
          const next = new Date(automation.next_run);
          return !Number.isNaN(next.getTime()) && next.getTime() <= now;
        });
        if (due && !schedulerState.autoRunning) {
          schedulerState.autoRunning = true;
          runAutomation(due.id, true).finally(() => {
            schedulerState.autoRunning = false;
          });
        }
      }

      async function loadScheduler() {
        if (!schedulerShell) {
          return;
        }
        try {
          const response = await fetch('api/gemini.php?action=scheduler-status', {
            method: 'GET',
            headers: {
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error('Unable to load scheduler status.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Scheduler status unavailable.');
          }
          schedulerState.automations = payload.automations || [];
          schedulerState.festivals = payload.festivals || [];
          if (schedulerNext) {
            schedulerNext.textContent = payload.next_run ? `Next run: ${formatSchedulerDate(payload.next_run)}` : 'Next run: —';
          }
          renderAutomations(schedulerState.automations);
          renderSchedulerLogs(payload.logs || []);
          renderFestivals(schedulerState.festivals);
          setSchedulerStatus('Idle');
          maybeTriggerAutoRun();
        } catch (error) {
          setSchedulerStatus('Status unavailable');
          showToast(error.message || 'Unable to load scheduler status.', 'error');
        }
      }

      async function saveAutomations() {
        const entries = collectEntries();
        if (!entries.length) {
          return;
        }
        if (schedulerSaveButton) {
          schedulerSaveButton.disabled = true;
        }
        setSchedulerStatus('Saving…');
        try {
          const response = await fetch('api/gemini.php?action=scheduler-save', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: window.csrfToken || '', automations: entries }),
          });
          if (!response.ok) {
            throw new Error('Unable to save automations.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Save failed.');
          }
          showToast('Automations saved.', 'success');
          if (schedulerEntries) {
            schedulerEntries.innerHTML = '';
          }
          createEntry();
          await loadScheduler();
        } catch (error) {
          setSchedulerStatus('Save failed');
          showToast(error.message || 'Failed to save automations.', 'error');
        } finally {
          if (schedulerSaveButton) {
            schedulerSaveButton.disabled = false;
          }
        }
      }

      async function runAutomation(automationId, auto = false) {
        if (!automationId) {
          return;
        }
        if (!auto) {
          setSchedulerStatus('Running…');
        }
        try {
          const response = await fetch('api/gemini.php?action=scheduler-run', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: window.csrfToken || '', automation_id: automationId }),
          });
          if (!response.ok) {
            throw new Error('Automation run failed.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Automation did not finish.');
          }
          if (!auto) {
            showToast('Automation completed and published.', 'success');
          }
          await loadScheduler();
          await loadUsage();
        } catch (error) {
          if (!auto) {
            showToast(error.message || 'Automation run failed.', 'error');
          }
        } finally {
          if (!auto) {
            setSchedulerStatus('Idle');
          }
        }
      }

      async function updateAutomationStatus(automationId, operation) {
        if (!automationId) {
          return;
        }
        if (operation === 'delete' && !window.confirm('Delete this automation permanently?')) {
          return;
        }
        try {
          const response = await fetch('api/gemini.php?action=scheduler-action', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: window.csrfToken || '', automation_id: automationId, operation }),
          });
          if (!response.ok) {
            throw new Error('Unable to update automation.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Action failed.');
          }
          await loadScheduler();
        } catch (error) {
          showToast(error.message || 'Unable to update automation.', 'error');
        }
      }

      if (schedulerAddButton) {
        schedulerAddButton.addEventListener('click', () => createEntry());
      }

      if (schedulerSaveButton) {
        schedulerSaveButton.addEventListener('click', (event) => {
          event.preventDefault();
          saveAutomations();
        });
      }

      if (schedulerShell) {
        createEntry();
        loadScheduler();
        window.setInterval(loadScheduler, 60000);
      }

      const usageShell = document.querySelector('[data-usage-shell]');
      const usageDailyTokens = usageShell ? usageShell.querySelector('[data-usage-daily-tokens]') : null;
      const usageDailyCost = usageShell ? usageShell.querySelector('[data-usage-daily-cost]') : null;
      const usageMonthlyTokens = usageShell ? usageShell.querySelector('[data-usage-monthly-tokens]') : null;
      const usageMonthlyCost = usageShell ? usageShell.querySelector('[data-usage-monthly-cost]') : null;
      const usageAggregateTokens = usageShell ? usageShell.querySelector('[data-usage-aggregate-tokens]') : null;
      const usageAggregateCost = usageShell ? usageShell.querySelector('[data-usage-aggregate-cost]') : null;
      const usagePricingList = usageShell ? usageShell.querySelector('[data-usage-pricing]') : null;
      const errorLogList = usageShell ? usageShell.querySelector('[data-error-log]') : null;
      const errorRetryButton = usageShell ? usageShell.querySelector('[data-error-retry]') : null;
      const errorCopyButton = usageShell ? usageShell.querySelector('[data-error-copy]') : null;
      const usageState = { lastError: null };
      const numberFormat = typeof Intl !== 'undefined' ? new Intl.NumberFormat('en-IN') : null;
      const currencyFormat = typeof Intl !== 'undefined' ? new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 }) : null;

      function formatTokens(value) {
        const total = Math.max(0, Math.round(value));
        return numberFormat ? numberFormat.format(total) : String(total);
      }

      function formatCurrency(value) {
        const amount = Number.isFinite(value) ? value : 0;
        return currencyFormat ? currencyFormat.format(amount) : `₹${amount.toFixed(2)}`;
      }

      function renderUsageErrors(errors) {
        usageState.lastError = null;
        if (!errorLogList) {
          return;
        }
        errorLogList.innerHTML = '';
        if (!Array.isArray(errors) || errors.length === 0) {
          const empty = document.createElement('li');
          empty.className = 'usage-logs__empty';
          empty.textContent = 'No errors logged.';
          errorLogList.appendChild(empty);
          return;
        }
        errors.forEach((entry, index) => {
          const item = document.createElement('li');
          item.className = 'usage-logs__error';
          const title = document.createElement('strong');
          title.textContent = entry.type || 'API failure';
          item.appendChild(title);
          const message = document.createElement('p');
          message.textContent = entry.message || 'Gemini error encountered.';
          item.appendChild(message);
          if (entry.created_at) {
            const time = document.createElement('span');
            time.className = 'usage-logs__error-time';
            const date = new Date(entry.created_at);
            time.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
            item.appendChild(time);
          }
          errorLogList.appendChild(item);
          if (index === 0) {
            usageState.lastError = entry;
          }
        });
      }

      async function loadUsage() {
        if (!usageShell) {
          return;
        }
        try {
          const response = await fetch('api/gemini.php?action=usage-summary', {
            method: 'GET',
            headers: {
              'X-CSRF-Token': window.csrfToken || '',
            },
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error('Unable to load usage summary.');
          }
          const payload = await response.json();
          if (!payload || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Usage summary unavailable.');
          }
          const usage = payload.usage || {};
          const dailyTokens = (usage.daily ? (usage.daily.input_tokens || 0) + (usage.daily.output_tokens || 0) : 0);
          const dailyCost = usage.daily ? usage.daily.cost || 0 : 0;
          const monthlyTokens = (usage.monthly ? (usage.monthly.input_tokens || 0) + (usage.monthly.output_tokens || 0) : 0);
          const monthlyCost = usage.monthly ? usage.monthly.cost || 0 : 0;
          const aggregateTokens = (usage.aggregate ? (usage.aggregate.input_tokens || 0) + (usage.aggregate.output_tokens || 0) : 0);
          const aggregateCost = usage.aggregate ? usage.aggregate.cost || 0 : 0;
          if (usageDailyTokens) {
            usageDailyTokens.textContent = `${formatTokens(dailyTokens)} tokens`;
          }
          if (usageDailyCost) {
            usageDailyCost.textContent = formatCurrency(dailyCost);
          }
          if (usageMonthlyTokens) {
            usageMonthlyTokens.textContent = `${formatTokens(monthlyTokens)} tokens`;
          }
          if (usageMonthlyCost) {
            usageMonthlyCost.textContent = formatCurrency(monthlyCost);
          }
          if (usageAggregateTokens) {
            usageAggregateTokens.textContent = `${formatTokens(aggregateTokens)} tokens`;
          }
          if (usageAggregateCost) {
            usageAggregateCost.textContent = formatCurrency(aggregateCost);
          }
          if (usagePricingList) {
            usagePricingList.innerHTML = '';
            const pricing = payload.usage ? payload.usage.pricing || {} : {};
            if (pricing.text) {
              const item = document.createElement('li');
              item.textContent = `Text input ₹${Number(pricing.text.input_per_million || 0).toFixed(2)} / 1M tokens · output ₹${Number(pricing.text.output_per_million || 0).toFixed(2)} / 1M tokens`;
              usagePricingList.appendChild(item);
            }
            if (pricing.image) {
              const item = document.createElement('li');
              item.textContent = `Image generation ₹${Number(pricing.image.per_call || 0).toFixed(2)} per call`;
              usagePricingList.appendChild(item);
            }
            if (pricing.tts) {
              const item = document.createElement('li');
              item.textContent = `TTS ₹${Number(pricing.tts.per_thousand_chars || 0).toFixed(2)} per 1K characters`;
              usagePricingList.appendChild(item);
            }
          }
          renderUsageErrors(payload.errors || []);
        } catch (error) {
          showToast(error.message || 'Unable to load usage summary.', 'error');
        }
      }

      if (errorRetryButton) {
        errorRetryButton.addEventListener('click', async () => {
          if (!usageState.lastError) {
            showToast('No error available to retry.', 'info');
            return;
          }
          errorRetryButton.disabled = true;
          try {
            const response = await fetch('api/gemini.php?action=error-retry', {
              method: 'POST',
              headers: {
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              throw new Error('Retry failed.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Retry failed.');
            }
            showToast('Last action retried successfully.', 'success');
            await loadUsage();
            if (payload.payload && payload.payload.type === 'scheduler-run') {
              await loadScheduler();
            }
          } catch (error) {
            showToast(error.message || 'Unable to retry last action.', 'error');
          } finally {
            errorRetryButton.disabled = false;
          }
        });
      }

      if (errorCopyButton) {
        errorCopyButton.addEventListener('click', () => {
          if (!usageState.lastError) {
            showToast('No error available to copy.', 'info');
            return;
          }
          const text = JSON.stringify(usageState.lastError, null, 2);
          const clipboard = navigator.clipboard;
          if (clipboard && clipboard.writeText) {
            clipboard.writeText(text).then(() => {
              showToast('Error details copied.', 'success');
            }).catch(() => {
              showToast('Unable to copy error details.', 'error');
            });
            return;
          }
          const temp = document.createElement('textarea');
          temp.value = text;
          temp.setAttribute('readonly', 'readonly');
          temp.style.position = 'absolute';
          temp.style.left = '-9999px';
          document.body.appendChild(temp);
          temp.select();
          try {
            document.execCommand('copy');
            showToast('Error details copied.', 'success');
          } catch (error) {
            showToast('Unable to copy error details.', 'error');
          }
          document.body.removeChild(temp);
        });
      }

      if (usageShell) {
        loadUsage();
        window.setInterval(loadUsage, 45000);
      }

      // ------------------------------------------------------------------
      // Festival & Occasion Greetings
      // ------------------------------------------------------------------
      const greetingsShell = document.querySelector('[data-greetings-shell]');
      if (greetingsShell) {
        const statusEl = document.querySelector('[data-greetings-status]');
        const videoMeta = document.querySelector('[data-greetings-video]');
        const contextMeta = document.querySelector('[data-greetings-context]');
        const formEl = document.querySelector('[data-greetings-form]');
        const occasionSelect = document.querySelector('[data-greetings-occasion]');
        const customOccasion = document.querySelector('[data-greetings-custom]');
        const audienceGroup = document.querySelector('[data-greetings-audience]');
        const platformsGroup = document.querySelector('[data-greetings-platforms]');
        const languageGroup = document.querySelector('[data-greetings-languages]');
        const toneSelect = document.querySelector('[data-greetings-tone]');
        const solarToggle = document.querySelector('[data-greetings-solar]');
        const brandToggle = document.querySelector('[data-greetings-brand]');
        const mediaType = document.querySelector('[data-greetings-media]');
        const notesField = document.querySelector('[data-greetings-notes]');
        const dateField = document.querySelector('[data-greetings-date]');
        const generateTextBtn = document.querySelector('[data-greetings-generate]');
        const generateMediaBtn = document.querySelector('[data-greetings-media-generate]');
        const saveBtn = document.querySelector('[data-greetings-save]');
        const sendBtn = document.querySelector('[data-greetings-send]');
        const captionsHolder = document.querySelector('[data-greetings-captions]');
        const longHolder = document.querySelector('[data-greetings-long]');
        const smsHolder = document.querySelector('[data-greetings-sms]');
        const mediaHolder = document.querySelector('[data-greetings-media-area]');
        const videoStatus = (window.aiSettings && window.aiSettings.videoStatus) || {};
        const autoToggle = document.querySelector('[data-greetings-auto]');
        const autoDays = document.querySelector('[data-greetings-auto-days]');
        const autoSaveBtn = document.querySelector('[data-greetings-auto-save]');
        const calendarShell = document.querySelector('[data-greetings-calendar]');
        const calendarSelection = document.querySelector('[data-greetings-calendar-selection]');
        const upcomingList = document.querySelector('[data-greetings-upcoming]');
          const savedList = document.querySelector('[data-greetings-saved]');
          const feedbackBox = document.querySelector('[data-greetings-feedback]');
          const retryImageBtn = document.querySelector('[data-greetings-retry-image]');

          const greetingState = {
            context: {
              occasion: occasionSelect ? occasionSelect.value : 'Diwali',
              custom_occasion: '',
            audience: ['Residential Customers', 'General Public'],
            platforms: ['Facebook / Instagram Post', 'WhatsApp Status'],
            languages: ['English'],
            tone: 'Warm & Festive',
            solar_context: true,
            media_type: 'both',
            occasion_date: '',
            instructions: '',
            use_brand_profile: resolveBrandUsage(brandToggle ? brandToggle.checked : brandProfileReady, true),
          },
          text: null,
          image: null,
          imageFix: '',
          video: null,
          storyboard: null,
          greetings: [],
          events: [],
          auto: { enabled: false, days_before: 3 },
            lastSavedId: null,
          };
          let lastMediaPayload = null;
          let textInFlight = false;
          let mediaInFlight = false;

          function toggleCustomOccasionField() {
            if (!customOccasion || !occasionSelect) return;
            const isCustom = occasionSelect.value === 'custom';
            customOccasion.hidden = !isCustom;
            if (!isCustom) {
              customOccasion.value = '';
            }
          }

        function setStatus(message) {
          if (statusEl) {
            statusEl.textContent = message;
          }
        }

        function setFeedback(message, tone = 'info') {
          if (!feedbackBox) return;
          if (!message) {
            feedbackBox.hidden = true;
            feedbackBox.textContent = '';
            feedbackBox.className = 'ai-settings__feedback ai-settings__feedback--info';
            return;
          }
          feedbackBox.hidden = false;
          feedbackBox.textContent = message;
          feedbackBox.className = `ai-settings__feedback ai-settings__feedback--${tone}`;
        }

        function setRetryImageVisible(visible) {
          if (!retryImageBtn) return;
          retryImageBtn.hidden = !visible;
          retryImageBtn.disabled = !visible;
        }

        function syncVideoMeta(meta) {
          if (!videoMeta) return;
          if (!meta || (!meta.configured && !meta.message)) {
            videoMeta.textContent = 'Video model not configured (storyboard fallback).';
            return;
          }
          videoMeta.textContent = meta.message || 'Video model status unknown';
        }

        function getCheckedValues(container, allowMultiple = true) {
          if (!container) return [];
          const inputs = Array.from(container.querySelectorAll('input'));
          const selected = inputs.filter((input) => input.checked).map((input) => input.value);
          return allowMultiple ? selected : selected.slice(0, 1);
        }

          function applyContextToForm(context) {
            if (occasionSelect) {
              occasionSelect.value = context.occasion || occasionSelect.value;
            }
            if (customOccasion) {
              customOccasion.value = context.custom_occasion || '';
            }
          if (toneSelect) {
            toneSelect.value = context.tone || 'Warm & Festive';
          }
          if (solarToggle) {
            solarToggle.checked = !!context.solar_context;
          }
          if (mediaType) {
            mediaType.value = context.media_type || 'both';
          }
          if (notesField) {
            notesField.value = context.instructions || '';
          }
          if (dateField) {
            dateField.value = context.occasion_date || '';
          }
          if (languageGroup) {
            const radios = languageGroup.querySelectorAll('input[type="radio"]');
            radios.forEach((radio) => {
              radio.checked = context.languages && context.languages.includes(radio.value);
            });
          }
          if (audienceGroup) {
            audienceGroup.querySelectorAll('input[type="checkbox"]').forEach((box) => {
              box.checked = (context.audience || []).includes(box.value);
            });
          }
            if (platformsGroup) {
              platformsGroup.querySelectorAll('input[type="checkbox"]').forEach((box) => {
                box.checked = (context.platforms || []).includes(box.value);
              });
            }
            toggleCustomOccasionField();
          }

          function readContextFromForm() {
            greetingState.context = {
              occasion: occasionSelect ? occasionSelect.value : greetingState.context.occasion,
              custom_occasion: occasionSelect && occasionSelect.value === 'custom' && customOccasion ? customOccasion.value : '',
              audience: getCheckedValues(audienceGroup, true),
              platforms: getCheckedValues(platformsGroup, true),
              languages: getCheckedValues(languageGroup, false),
              tone: toneSelect ? toneSelect.value : 'Warm & Festive',
              solar_context: solarToggle ? solarToggle.checked : false,
              media_type: mediaType ? mediaType.value : 'both',
              occasion_date: dateField ? dateField.value : '',
              instructions: notesField ? notesField.value : '',
              use_brand_profile: resolveBrandUsage(brandToggle ? brandToggle.checked : brandProfileReady),
            };
            return greetingState.context;
          }

          function validateContext(context) {
            const issues = [];
            if (!context.occasion && !context.custom_occasion) {
              issues.push('Select an occasion.');
            }
            if (occasionSelect && occasionSelect.value === 'custom' && (!context.custom_occasion || context.custom_occasion.trim() === '')) {
              issues.push('Enter your custom occasion.');
            }
            if (!Array.isArray(context.platforms) || context.platforms.length === 0) {
              issues.push('Pick at least one platform.');
            }
            if (!Array.isArray(context.languages) || context.languages.length === 0) {
              issues.push('Pick at least one language.');
            }
            return issues;
          }

          if (occasionSelect) {
            occasionSelect.addEventListener('change', () => {
              toggleCustomOccasionField();
              readContextFromForm();
              renderContextMeta();
            });
            toggleCustomOccasionField();
          }

        function copyText(text) {
          if (!text) return;
          const clipboard = navigator.clipboard;
          if (clipboard && clipboard.writeText) {
            clipboard.writeText(text).then(() => showToast('Copied to clipboard.', 'success')).catch(() => showToast('Unable to copy.', 'error'));
            return;
          }
          const temp = document.createElement('textarea');
          temp.value = text;
          document.body.appendChild(temp);
          temp.select();
          document.execCommand('copy');
          document.body.removeChild(temp);
          showToast('Copied to clipboard.', 'success');
        }

        function renderContextMeta() {
          if (!contextMeta) return;
          const ctx = greetingState.context;
          const occasion = ctx.custom_occasion && ctx.custom_occasion.trim() !== '' ? ctx.custom_occasion : ctx.occasion;
          const parts = [occasion];
          if (ctx.occasion_date) {
            parts.push(ctx.occasion_date);
          }
          contextMeta.textContent = parts.filter(Boolean).join(' · ');
        }

        function renderTextPreview() {
          if (captionsHolder) {
            captionsHolder.innerHTML = '<h4>Captions</h4>';
            if (greetingState.text && Array.isArray(greetingState.text.captions)) {
              greetingState.text.captions.forEach((caption, index) => {
                const block = document.createElement('div');
                block.className = 'ai-greetings__block';
                block.innerHTML = `<div class="ai-greetings__block-title">Option ${index + 1}</div><p>${caption}</p>`;
                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'btn btn-ghost btn-sm';
                copyBtn.textContent = 'Copy';
                copyBtn.addEventListener('click', () => copyText(caption));
                block.appendChild(copyBtn);
                captionsHolder.appendChild(block);
              });
            } else {
              const p = document.createElement('p');
              p.className = 'ai-greetings__placeholder';
              p.textContent = 'Generate greeting text to view captions.';
              const action = document.createElement('button');
              action.type = 'button';
              action.className = 'btn btn-ghost btn-sm';
              action.textContent = 'Generate now';
              action.addEventListener('click', generateGreetingText);
              captionsHolder.appendChild(p);
              captionsHolder.appendChild(action);
            }
          }

          if (longHolder) {
            longHolder.innerHTML = '<h4>Long text</h4>';
            if (greetingState.text && greetingState.text.long_text) {
              const p = document.createElement('p');
              p.textContent = greetingState.text.long_text;
              longHolder.appendChild(p);
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'btn btn-ghost btn-sm';
              btn.textContent = 'Copy';
              btn.addEventListener('click', () => copyText(greetingState.text.long_text));
              longHolder.appendChild(btn);
            } else {
              longHolder.innerHTML += '<p class="ai-greetings__placeholder">No long text yet.</p>';
            }
          }

          if (smsHolder) {
            smsHolder.innerHTML = '<h4>SMS</h4>';
            if (greetingState.text && greetingState.text.sms_text) {
              const p = document.createElement('p');
              p.textContent = greetingState.text.sms_text;
              smsHolder.appendChild(p);
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'btn btn-ghost btn-sm';
              btn.textContent = 'Copy';
              btn.addEventListener('click', () => copyText(greetingState.text.sms_text));
              smsHolder.appendChild(btn);
            } else {
              smsHolder.innerHTML += '<p class="ai-greetings__placeholder">No SMS text yet.</p>';
            }
          }
        }

        function renderStoryboardFrames(storyboard) {
          const list = document.createElement('div');
          list.className = 'ai-greetings__storyboard';
          const summary = document.createElement('p');
          summary.textContent = storyboard.summary || 'Storyboard';
          list.appendChild(summary);
          const frames = Array.isArray(storyboard.frames) ? storyboard.frames : [];
          frames.forEach((frame, idx) => {
            const item = document.createElement('div');
            item.className = 'ai-greetings__storyboard-frame';
            const title = document.createElement('strong');
            title.textContent = frame.scene || `Frame ${idx + 1}`;
            item.appendChild(title);
            if (frame.onscreen_text) {
              const onscreen = document.createElement('p');
              onscreen.textContent = `On-screen: ${frame.onscreen_text}`;
              item.appendChild(onscreen);
            }
            if (frame.voiceover) {
              const voice = document.createElement('p');
              voice.textContent = `Voiceover: ${frame.voiceover}`;
              item.appendChild(voice);
            }
            list.appendChild(item);
          });
          return list;
        }

        function downloadStoryboard(storyboard) {
          if (!storyboard) return;
          const frames = Array.isArray(storyboard.frames) ? storyboard.frames : [];
          const lines = ['Storyboard', '', `Summary: ${storyboard.summary || ''}`, ''];
          frames.forEach((frame, idx) => {
            lines.push(`Frame ${idx + 1}: ${frame.scene || ''}`);
            if (frame.onscreen_text) lines.push(`On-screen: ${frame.onscreen_text}`);
            if (frame.voiceover) lines.push(`Voiceover: ${frame.voiceover}`);
            lines.push('');
          });
          const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = 'storyboard.txt';
          document.body.appendChild(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
        }

        function renderMediaPreview() {
          if (!mediaHolder) return;
          mediaHolder.innerHTML = '';
          if (greetingState.image) {
            const fig = document.createElement('figure');
            const img = document.createElement('img');
            img.src = greetingState.image.path;
            img.alt = 'Generated greeting visual';
            fig.appendChild(img);
            const controls = document.createElement('div');
            controls.className = 'ai-greetings__inline-actions';
            const download = document.createElement('a');
            download.href = greetingState.image.path;
            download.download = '';
            download.className = 'btn btn-ghost btn-sm';
            download.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download image';
            controls.appendChild(download);
            fig.appendChild(controls);
            const fixPanel = document.createElement('div');
            fixPanel.className = 'ai-greetings__fix';
            const fixLabel = document.createElement('label');
            fixLabel.textContent = 'Image fix instructions (optional)';
            const fixField = document.createElement('textarea');
            fixField.rows = 2;
            fixField.placeholder = 'Logo missing. Add company logo top-right. Correct phone to +91-XXXXXXXXXX and show website at bottom.';
            fixField.value = greetingState.imageFix || '';
            fixLabel.appendChild(fixField);
            const fixBtn = document.createElement('button');
            fixBtn.type = 'button';
            fixBtn.className = 'btn btn-ghost btn-sm';
            fixBtn.innerHTML = '<i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Regenerate image with fixes';
            fixBtn.addEventListener('click', () => {
              greetingState.imageFix = fixField.value.trim();
              regenerateGreetingImageWithFix(greetingState.imageFix, fixBtn);
            });
            fixPanel.appendChild(fixLabel);
            fixPanel.appendChild(fixBtn);
            fig.appendChild(fixPanel);
            mediaHolder.appendChild(fig);
          }

          if (greetingState.video) {
            const player = document.createElement('div');
            player.className = 'ai-greetings__video';
            const video = document.createElement('video');
            video.controls = true;
            video.src = greetingState.video.path;
            player.appendChild(video);
            const controls = document.createElement('div');
            controls.className = 'ai-greetings__inline-actions';
            const download = document.createElement('a');
            download.href = greetingState.video.path;
            download.download = '';
            download.className = 'btn btn-ghost btn-sm';
            download.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download video';
            controls.appendChild(download);
            player.appendChild(controls);
            mediaHolder.appendChild(player);
          }

          if (greetingState.storyboard) {
            const storyboardEl = renderStoryboardFrames(greetingState.storyboard);
            const actions = document.createElement('div');
            actions.className = 'ai-greetings__inline-actions';
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'btn btn-ghost btn-sm';
            copyBtn.textContent = 'Copy storyboard';
            copyBtn.addEventListener('click', () => copyText(JSON.stringify(greetingState.storyboard, null, 2)));
            const downloadBtn = document.createElement('button');
            downloadBtn.type = 'button';
            downloadBtn.className = 'btn btn-ghost btn-sm';
            downloadBtn.textContent = 'Download storyboard';
            downloadBtn.addEventListener('click', () => downloadStoryboard(greetingState.storyboard));
            actions.appendChild(copyBtn);
            actions.appendChild(downloadBtn);
            mediaHolder.appendChild(storyboardEl);
            mediaHolder.appendChild(actions);
          }

          if (!greetingState.image && !greetingState.video && !greetingState.storyboard) {
            const placeholder = document.createElement('p');
            placeholder.className = 'ai-greetings__placeholder';
            placeholder.textContent = 'Generate media or storyboard to preview.';
            mediaHolder.appendChild(placeholder);
          }
        }

        function renderPreview() {
          renderContextMeta();
          renderTextPreview();
          renderMediaPreview();
          const hasText = greetingState.text && greetingState.text.captions && greetingState.text.captions.length;
          const hasMedia = greetingState.image || greetingState.video || greetingState.storyboard;
          if (saveBtn) {
            const disabled = !(hasText || hasMedia);
            saveBtn.disabled = disabled;
            saveBtn.title = disabled ? 'Generate greeting text or media before saving.' : '';
          }
          if (sendBtn) {
            const disabled = !(hasText || hasMedia);
            sendBtn.disabled = disabled;
            sendBtn.title = disabled ? 'Generate and save a greeting before sending.' : '';
          }
        }

        function occasionLabel(event) {
          const date = event.date ? ` · ${event.date}` : '';
          return `${event.name}${date}`;
        }

        function renderUpcoming() {
          if (!upcomingList) return;
          upcomingList.innerHTML = '';
          const filtered = greetingState.events.filter((event) => event.days_away <= 60);
          if (!filtered.length) {
            upcomingList.innerHTML = '<p class="ai-greetings__placeholder">No upcoming occasions in the next 60 days.</p>';
            return;
          }
          filtered.forEach((event) => {
            const row = document.createElement('div');
            row.className = 'ai-greetings__row';
            row.innerHTML = `<div><strong>${event.name}</strong><p>${event.date} · ${event.days_away} days</p></div>`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-ghost btn-sm';
            btn.textContent = 'Prepare greeting';
            btn.addEventListener('click', () => {
              applyContextToForm({
                occasion: event.name,
                custom_occasion: '',
                audience: ['General Public'],
                platforms: ['Facebook / Instagram Post', 'WhatsApp Status'],
                languages: ['Hindi'],
                tone: 'Warm & Professional',
                solar_context: true,
                media_type: (videoStatus && videoStatus.configured && videoStatus.ok) ? 'both' : 'image',
                occasion_date: event.date,
                instructions: '',
              });
              readContextFromForm();
              greetingsShell.scrollIntoView({ behavior: 'smooth' });
              showToast(`${event.name} loaded into the form.`, 'success');
            });
            row.appendChild(btn);
            upcomingList.appendChild(row);
          });
        }

        function renderCalendar() {
          if (!calendarShell) return;
          calendarShell.innerHTML = '';
          const today = new Date();
          const year = today.getFullYear();
          const month = today.getMonth();
          const first = new Date(year, month, 1);
          const startDay = first.getDay();
          const daysInMonth = new Date(year, month + 1, 0).getDate();
          const header = document.createElement('div');
          header.className = 'ai-greetings__calendar-head';
          header.textContent = first.toLocaleString('en-IN', { month: 'long', year: 'numeric' });
          calendarShell.appendChild(header);
          const grid = document.createElement('div');
          grid.className = 'ai-greetings__calendar-grid';
          const eventDates = new Map();
          greetingState.events.forEach((event) => {
            if (event.date) {
              eventDates.set(event.date, event.name);
            }
          });
          for (let i = 0; i < startDay; i += 1) {
            grid.appendChild(document.createElement('span'));
          }
          for (let day = 1; day <= daysInMonth; day += 1) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'ai-greetings__calendar-cell';
            cell.textContent = day;
            if (eventDates.has(dateStr)) {
              cell.dataset.event = eventDates.get(dateStr);
            }
            cell.addEventListener('click', () => {
              const name = cell.dataset.event || 'Selected day';
              calendarSelection.innerHTML = `<div><strong>${name}</strong><p>${dateStr}</p></div>`;
              const action = document.createElement('button');
              action.type = 'button';
              action.className = 'btn btn-ghost btn-sm';
              action.textContent = 'Use this occasion';
              action.addEventListener('click', () => {
                applyContextToForm({
                  occasion: name,
                  custom_occasion: '',
                  occasion_date: dateStr,
                  audience: ['General Public'],
                  platforms: ['Facebook / Instagram Post', 'WhatsApp Status'],
                  languages: ['English'],
                  tone: 'Warm & Festive',
                  solar_context: true,
                  media_type: mediaType ? mediaType.value : 'both',
                  instructions: '',
                });
                readContextFromForm();
                greetingsShell.scrollIntoView({ behavior: 'smooth' });
              });
              calendarSelection.appendChild(action);
            });
            grid.appendChild(cell);
          }
          calendarShell.appendChild(grid);
        }

        function renderSaved() {
          if (!savedList) return;
          savedList.innerHTML = '';
          if (!Array.isArray(greetingState.greetings) || greetingState.greetings.length === 0) {
            savedList.innerHTML = '<p class="ai-greetings__placeholder">No saved greetings yet.</p>';
            return;
          }
          greetingState.greetings.forEach((entry) => {
            const card = document.createElement('article');
            card.className = 'ai-greetings__saved-card';
            const meta = document.createElement('div');
            meta.className = 'ai-greetings__saved-meta';
            const autoBadge = entry.source === 'auto' ? '<span class="badge">Auto</span>' : '';
            meta.innerHTML = `<strong>${entry.occasion || 'Greeting'}</strong> ${autoBadge}<p>${entry.occasion_date || ''}</p><p>${entry.created_at || ''}</p>`;
            card.appendChild(meta);
            const tags = document.createElement('p');
            tags.className = 'ai-greetings__tags';
            tags.textContent = `${entry.captions && entry.captions.length ? 'Text' : 'No text'} · ${entry.image ? 'Image' : 'No image'} · ${entry.video ? 'Video' : (entry.storyboard ? 'Storyboard' : 'No video')}`;
            if (entry.uses_brand_profile) {
              const badge = document.createElement('span');
              badge.className = 'badge';
              badge.textContent = 'Brand profile';
              tags.appendChild(badge);
            }
            card.appendChild(tags);
            const body = document.createElement('div');
            body.className = 'ai-greetings__saved-body';
            body.hidden = true;
            if (Array.isArray(entry.captions)) {
              entry.captions.slice(0, 3).forEach((cap, idx) => {
                const p = document.createElement('p');
                p.textContent = `Caption ${idx + 1}: ${cap}`;
                body.appendChild(p);
              });
            }
            if (entry.long_text) {
              const p = document.createElement('p');
              p.textContent = entry.long_text;
              body.appendChild(p);
            }
            if (entry.sms_text) {
              const p = document.createElement('p');
              p.textContent = `SMS: ${entry.sms_text}`;
              body.appendChild(p);
            }
            if (entry.image) {
              const link = document.createElement('a');
              link.href = entry.image.path;
              link.className = 'btn btn-ghost btn-sm';
              link.download = '';
              link.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download image';
              body.appendChild(link);
            }
            if (entry.video) {
              const link = document.createElement('a');
              link.href = entry.video.path;
              link.className = 'btn btn-ghost btn-sm';
              link.download = '';
              link.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download video';
              body.appendChild(link);
            }
            if (entry.storyboard) {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'btn btn-ghost btn-sm';
              btn.textContent = 'Download storyboard';
              btn.addEventListener('click', () => downloadStoryboard(entry.storyboard));
              body.appendChild(btn);
            }
            card.appendChild(body);

            const actions = document.createElement('div');
            actions.className = 'ai-greetings__inline-actions';
            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'btn btn-ghost btn-sm';
            view.textContent = 'View';
            view.addEventListener('click', () => {
              body.hidden = !body.hidden;
              view.textContent = body.hidden ? 'View' : 'Hide';
            });
            actions.appendChild(view);
            if (entry.image) {
              const dl = document.createElement('a');
              dl.href = entry.image.path;
              dl.className = 'btn btn-ghost btn-sm';
              dl.download = '';
              dl.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download image';
              actions.appendChild(dl);
            }
            if (entry.video) {
              const dlv = document.createElement('a');
              dlv.href = entry.video.path;
              dlv.className = 'btn btn-ghost btn-sm';
              dlv.download = '';
              dlv.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Download video';
              actions.appendChild(dlv);
            }
            const send = document.createElement('button');
            send.type = 'button';
            send.className = 'btn btn-primary btn-sm';
            send.textContent = 'Use in Smart Marketing';
            send.addEventListener('click', () => sendGreetingToSmart(entry.id));
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-ghost btn-sm';
            del.textContent = 'Delete';
            del.addEventListener('click', () => deleteGreeting(entry.id));
            actions.appendChild(send);
            actions.appendChild(del);
            card.appendChild(actions);
            savedList.appendChild(card);
          });
        }

        async function loadBootstrap() {
          setStatus('Loading…');
          try {
            const response = await fetch('api/gemini.php?action=greetings-bootstrap', {
              method: 'GET',
              headers: { 'X-CSRF-Token': window.csrfToken || '' },
              credentials: 'same-origin',
            });
            if (!response.ok) {
              throw new Error('Unable to load greetings dashboard.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Unable to load greetings.');
            }
            greetingState.greetings = Array.isArray(payload.greetings) ? payload.greetings : [];
            greetingState.events = Array.isArray(payload.events) ? payload.events : [];
            greetingState.auto = payload.auto || greetingState.auto;
            setStatus(payload.settings && payload.settings.enabled ? 'Gemini ready' : 'AI disabled in settings');
            syncVideoMeta((payload.settings && payload.settings.videoStatus) || videoStatus || {});
            if (autoToggle) {
              autoToggle.checked = !!(greetingState.auto.enabled);
            }
            if (autoDays) {
              autoDays.value = greetingState.auto.days_before || 3;
            }
            renderUpcoming();
            renderCalendar();
            renderSaved();
            renderPreview();
          } catch (error) {
            setStatus('Unable to load greetings.');
            showToast(error.message || 'Unable to load greetings bootstrap.', 'error');
          }
        }

        async function generateGreetingText() {
          if (textInFlight) {
            return;
          }
          const context = readContextFromForm();
          const issues = validateContext(context);
          if (issues.length) {
            showToast(issues.join(' '), 'warning');
            return;
          }
          try {
            textInFlight = true;
            generateTextBtn.disabled = true;
            generateTextBtn.textContent = 'Generating…';
            setRetryImageVisible(false);
            setStatus('Generating text…');
            setFeedback('Generating greeting text…', 'info');
            const response = await fetch('api/gemini.php?action=greetings-generate-text', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify(context),
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || !payload.success) {
              const msg = payload && payload.error ? payload.error : `Gemini could not generate text (HTTP ${response.status}).`;
              throw new Error(msg);
            }
            greetingState.context = payload.context || context;
            greetingState.text = payload.text;
            if (payload.text && payload.text.storyboard) {
              greetingState.storyboard = payload.text.storyboard;
            }
            if (typeof payload.usesBrandProfile === 'boolean' && brandToggle) {
              brandToggle.checked = payload.usesBrandProfile || (brandToggle.checked && !brandProfileReady);
            }
            if (payload.brandProfileMissing) {
              showToast('Brand Profile missing — generated text is generic until you add brand details.', 'warning');
            }
            greetingState.lastSavedId = null;
            lastMediaPayload = null;
            renderPreview();
            setFeedback('Greeting text ready. Proceed to generate media.', 'success');
            setStatus('Text generated');
            showToast('Greeting text ready.', 'success');
          } catch (error) {
            console.error(error);
            setStatus('Text generation failed');
            setFeedback(error.message || 'Could not generate greeting text.', 'error');
            showToast('Could not generate greeting. Please check Gemini settings or try again.', 'error');
          } finally {
            textInFlight = false;
            generateTextBtn.disabled = false;
            generateTextBtn.textContent = 'Generate Greeting';
          }
        }

        async function generateGreetingMedia(payloadOverride = null) {
          if (mediaInFlight) {
            return;
          }
          if (!greetingState.text) {
            setFeedback('Generate greeting text first so media and copy stay in sync.', 'warning');
            showToast('Create greeting text before generating media.', 'warning');
            return;
          }
          const context = payloadOverride && payloadOverride.context ? payloadOverride.context : readContextFromForm();
          const issues = validateContext(context);
          if (issues.length) {
            showToast(issues.join(' '), 'warning');
            return;
          }
          try {
            mediaInFlight = true;
            generateMediaBtn.disabled = true;
            generateMediaBtn.textContent = 'Generating…';
            const wantImage = payloadOverride && typeof payloadOverride.want_image === 'boolean'
              ? payloadOverride.want_image
              : (context.media_type === 'image' || context.media_type === 'both');
            const wantVideo = payloadOverride && typeof payloadOverride.want_video === 'boolean'
              ? payloadOverride.want_video
              : (context.media_type === 'video' || context.media_type === 'both');
            lastMediaPayload = { context, want_image: wantImage, want_video: wantVideo };
            setStatus('Generating media…');
            setFeedback('Generating media with Gemini…', 'info');
            const response = await fetch('api/gemini.php?action=greetings-generate-media', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ ...context, want_image: wantImage, want_video: wantVideo }),
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || !payload.success) {
              const msg = payload && payload.error ? payload.error : `Gemini could not generate media (HTTP ${response.status}).`;
              throw new Error(msg);
            }
            greetingState.context = payload.context || context;
            greetingState.image = payload.image || null;
            greetingState.video = payload.video && payload.video.mode === 'video' ? payload.video.video : null;
            greetingState.storyboard = payload.video && payload.video.mode === 'storyboard' ? payload.video.storyboard : null;
            greetingState.imageFix = '';
            if (typeof payload.usesBrandProfile === 'boolean' && brandToggle) {
              brandToggle.checked = payload.usesBrandProfile || (brandToggle.checked && !brandProfileReady);
            }
            if (payload.brandProfileMissing) {
              showToast('Brand Profile missing — media prompts are generic until you add brand details.', 'warning');
            }
            greetingState.lastSavedId = null;
            renderPreview();
            const mediaSuccess = greetingState.image || greetingState.video || greetingState.storyboard;
            setFeedback(mediaSuccess ? 'Media updated successfully.' : 'Media generated without attachments.', mediaSuccess ? 'success' : 'warning');
            setRetryImageVisible(false);
            setStatus('Media generated');
            showToast('Media updated.', 'success');
          } catch (error) {
            console.error(error);
            setStatus('Media generation failed');
            setFeedback(error.message || 'Could not generate media.', 'error');
            setRetryImageVisible(!!lastMediaPayload);
            showToast('Could not generate media. Please check Gemini settings or try again.', 'error');
          } finally {
            mediaInFlight = false;
            generateMediaBtn.disabled = false;
            generateMediaBtn.textContent = 'Generate Media';
          }
        }

        async function regenerateGreetingImageWithFix(fixText, buttonEl) {
          if (mediaInFlight) {
            return;
          }
          const baseContext = greetingState.context && greetingState.context.occasion ? greetingState.context : readContextFromForm();
          const context = {
            ...baseContext,
            fix_instructions: fixText,
            media_type: 'image',
            use_brand_profile: resolveBrandUsage(brandToggle ? brandToggle.checked : brandProfileReady),
          };
          const payload = { ...context, want_image: true, want_video: false };
          try {
            mediaInFlight = true;
            lastMediaPayload = { context, want_image: true, want_video: false };
            if (buttonEl) {
              buttonEl.disabled = true;
            }
            setStatus('Regenerating image…');
            setFeedback('Applying fixes and regenerating image…', 'info');
            const response = await fetch('api/gemini.php?action=greetings-generate-media', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result || !result.success) {
              const msg = result && result.error ? result.error : `Gemini could not generate media (HTTP ${response.status}).`;
              throw new Error(msg);
            }
            greetingState.context = result.context || context;
            greetingState.image = result.image || greetingState.image;
            if (typeof result.usesBrandProfile === 'boolean' && brandToggle) {
              brandToggle.checked = result.usesBrandProfile || (brandToggle.checked && !brandProfileReady);
            }
            if (result.brandProfileMissing) {
              showToast('Brand Profile missing — media prompts are generic until you add brand details.', 'warning');
            }
            renderPreview();
            setFeedback('Image regenerated with fixes.', 'success');
            setRetryImageVisible(false);
            setStatus('Image regenerated');
            showToast('Image regenerated with fixes.', 'success');
          } catch (error) {
            console.error(error);
            setStatus('Image regeneration failed');
            setFeedback(error.message || 'Image regeneration failed. Please try again or adjust fix instructions.', 'error');
            setRetryImageVisible(!!lastMediaPayload);
            showToast('Image regeneration failed. Please try again or adjust fix instructions.', 'error');
          } finally {
            if (buttonEl) {
              buttonEl.disabled = false;
            }
            mediaInFlight = false;
          }
        }

        async function retryLastMedia() {
          if (!lastMediaPayload) {
            showToast('No media request to retry.', 'warning');
            return;
          }
          if (lastMediaPayload.context) {
            applyContextToForm(lastMediaPayload.context);
            renderContextMeta();
          }
          setFeedback('Retrying last media request…', 'info');
          setStatus('Retrying media…');
          await generateGreetingMedia(lastMediaPayload);
        }

        async function saveGreeting(source = 'manual', silent = false) {
          if (!greetingState.text && !greetingState.image && !greetingState.video && !greetingState.storyboard) {
            if (!silent) showToast('Generate greeting text or media before saving.', 'warning');
            return null;
          }
          const context = readContextFromForm();
          const payload = {
            context,
            captions: greetingState.text ? (greetingState.text.captions || []) : [],
            long_text: greetingState.text ? (greetingState.text.long_text || '') : '',
            sms_text: greetingState.text ? (greetingState.text.sms_text || '') : '',
            image: greetingState.image,
            video: greetingState.video,
            storyboard: greetingState.storyboard,
            source,
          };
          try {
            saveBtn.disabled = true;
            const response = await fetch('api/gemini.php?action=greetings-save-draft', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify(payload),
            });
            if (!response.ok) {
              throw new Error('Unable to save greeting.');
            }
            const result = await response.json();
            if (!result || !result.success) {
              throw new Error(result && result.error ? result.error : 'Save failed.');
            }
            greetingState.greetings = result.greetings || greetingState.greetings;
            greetingState.lastSavedId = result.saved ? result.saved.id : null;
            renderSaved();
            if (!silent) showToast('Greeting saved.', 'success');
            return result.saved || null;
          } catch (error) {
            if (!silent) showToast(error.message || 'Unable to save greeting.', 'error');
            return null;
          } finally {
            saveBtn.disabled = false;
          }
        }

        async function sendGreetingToSmart(id) {
          const targetId = id || greetingState.lastSavedId;
          let greetingId = targetId;
          if (!greetingId) {
            const saved = await saveGreeting('manual', true);
            greetingId = saved ? saved.id : null;
          }
          if (!greetingId) {
            showToast('Save a greeting before sending to Smart Marketing.', 'warning');
            return;
          }
          try {
            sendBtn.disabled = true;
            const response = await fetch('api/gemini.php?action=greetings-send-smart', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ id: greetingId }),
            });
            if (!response.ok) {
              throw new Error('Unable to send to Smart Marketing.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Send failed.');
            }
            showToast('Sent to Smart Marketing.', 'success');
          } catch (error) {
            showToast(error.message || 'Unable to send to Smart Marketing.', 'error');
          } finally {
            sendBtn.disabled = false;
          }
        }

        async function deleteGreeting(id) {
          try {
            const response = await fetch('api/gemini.php?action=greetings-delete', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ id }),
            });
            if (!response.ok) {
              throw new Error('Unable to delete greeting.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Delete failed.');
            }
            greetingState.greetings = payload.greetings || [];
            renderSaved();
            showToast('Greeting removed.', 'success');
          } catch (error) {
            showToast(error.message || 'Unable to delete greeting.', 'error');
          }
        }

        async function saveAutomation() {
          if (!autoToggle || !autoDays) return;
          try {
            autoSaveBtn.disabled = true;
            const response = await fetch('api/gemini.php?action=greetings-auto-settings', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                enabled: autoToggle.checked,
                days_before: Number(autoDays.value || 3),
              }),
            });
            if (!response.ok) {
              throw new Error('Unable to save automation.');
            }
            const payload = await response.json();
            if (!payload || !payload.success) {
              throw new Error(payload && payload.error ? payload.error : 'Automation save failed.');
            }
            greetingState.auto = payload.auto || greetingState.auto;
            showToast('Automation saved.', 'success');
          } catch (error) {
            showToast(error.message || 'Unable to save automation.', 'error');
          } finally {
            autoSaveBtn.disabled = false;
          }
        }

        if (generateTextBtn) {
          generateTextBtn.addEventListener('click', generateGreetingText);
        }
        if (generateMediaBtn) {
          generateMediaBtn.addEventListener('click', generateGreetingMedia);
        }
        if (retryImageBtn) {
          retryImageBtn.addEventListener('click', retryLastMedia);
        }
        if (formEl) {
          formEl.addEventListener('change', readContextFromForm);
        }
        if (saveBtn) {
          saveBtn.addEventListener('click', () => saveGreeting('manual'));
        }
        if (sendBtn) {
          sendBtn.addEventListener('click', () => sendGreetingToSmart());
        }
        if (autoSaveBtn) {
          autoSaveBtn.addEventListener('click', saveAutomation);
        }

        loadBootstrap();
      }

      renderHistory();
      setChatEnabled(!!(window.aiSettings && window.aiSettings.enabled));
    })();
  </script>
</body>
</html>
