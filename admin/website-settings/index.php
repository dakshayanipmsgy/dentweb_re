<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();
start_session();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf_token'];

$settings = website_settings();
$flashMessage = '';
$flashTone = 'info';
$defaults = website_settings_default();
$allowedButtonStyles = website_settings_allowed_button_styles();
$allowedCardStyles = website_settings_allowed_card_styles();
$allowedFontSizes = website_settings_allowed_font_sizes();
$allowedFontWeights = website_settings_allowed_font_weights();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        $flashMessage = 'Session expired. Please refresh and try again.';
        $flashTone = 'error';
    } else {
        $global = [
            'site_tagline' => trim((string) ($_POST['site_tagline'] ?? '')),
            'header_callout' => trim((string) ($_POST['header_callout'] ?? '')),
            'tagline_color' => website_settings_normalize_hex($_POST['tagline_color'] ?? '', $defaults['global']['tagline_color']),
            'tagline_font_size' => in_array($_POST['tagline_font_size'] ?? '', $allowedFontSizes, true) ? (string) $_POST['tagline_font_size'] : $defaults['global']['tagline_font_size'],
            'tagline_font_weight' => in_array($_POST['tagline_font_weight'] ?? '', $allowedFontWeights, true) ? (string) $_POST['tagline_font_weight'] : $defaults['global']['tagline_font_weight'],
            'callout_color' => website_settings_normalize_hex($_POST['callout_color'] ?? '', $defaults['global']['callout_color']),
            'callout_font_size' => in_array($_POST['callout_font_size'] ?? '', $allowedFontSizes, true) ? (string) $_POST['callout_font_size'] : $defaults['global']['callout_font_size'],
            'callout_font_weight' => in_array($_POST['callout_font_weight'] ?? '', $allowedFontWeights, true) ? (string) $_POST['callout_font_weight'] : $defaults['global']['callout_font_weight'],
            'subheader_bg_color' => website_settings_normalize_hex($_POST['subheader_bg_color'] ?? '', $defaults['global']['subheader_bg_color']),
        ];

        $hero = [
            'kicker' => trim((string) ($_POST['hero_kicker'] ?? '')),
            'title' => trim((string) ($_POST['hero_title'] ?? '')),
            'subtitle' => trim((string) ($_POST['hero_subtitle'] ?? '')),
            'primary_image' => trim((string) ($_POST['hero_primary_image'] ?? '')),
            'primary_caption' => trim((string) ($_POST['hero_primary_caption'] ?? '')),
            'primary_button_text' => trim((string) ($_POST['hero_primary_button_text'] ?? '')),
            'primary_button_link' => trim((string) ($_POST['hero_primary_button_link'] ?? '')),
            'secondary_button_text' => trim((string) ($_POST['hero_secondary_button_text'] ?? '')),
            'secondary_button_link' => trim((string) ($_POST['hero_secondary_button_link'] ?? '')),
            'announcement_badge' => trim((string) ($_POST['hero_announcement_badge'] ?? '')),
            'announcement_text' => trim((string) ($_POST['hero_announcement_text'] ?? '')),
        ];

        $sections = [
            'what_our_customers_say_title' => trim((string) ($_POST['what_our_customers_say_title'] ?? '')),
            'what_our_customers_say_subtitle' => trim((string) ($_POST['what_our_customers_say_subtitle'] ?? '')),
            'seasonal_offer_title' => trim((string) ($_POST['seasonal_offer_title'] ?? '')),
            'seasonal_offer_text' => trim((string) ($_POST['seasonal_offer_text'] ?? '')),
            'cta_strip_title' => trim((string) ($_POST['cta_strip_title'] ?? '')),
            'cta_strip_text' => trim((string) ($_POST['cta_strip_text'] ?? '')),
            'cta_strip_cta_text' => trim((string) ($_POST['cta_strip_cta_text'] ?? '')),
            'cta_strip_cta_link' => trim((string) ($_POST['cta_strip_cta_link'] ?? '')),
        ];

        $theme = [
            'primary_color' => website_settings_normalize_hex($_POST['theme_primary_color'] ?? '', $defaults['theme']['primary_color']),
            'secondary_color' => website_settings_normalize_hex($_POST['theme_secondary_color'] ?? '', $defaults['theme']['secondary_color']),
            'accent_color' => website_settings_normalize_hex($_POST['theme_accent_color'] ?? '', $defaults['theme']['accent_color']),
            'button_style' => trim((string) ($_POST['theme_button_style'] ?? '')),
            'card_style' => trim((string) ($_POST['theme_card_style'] ?? '')),
        ];

        if (!in_array($theme['button_style'], $allowedButtonStyles, true)) {
            $theme['button_style'] = $defaults['theme']['button_style'];
        }

        if (!in_array($theme['card_style'], $allowedCardStyles, true)) {
            $theme['card_style'] = $defaults['theme']['card_style'];
        }

        $testimonialsInput = $_POST['testimonials'] ?? [];
        $testimonials = [];
        if (is_array($testimonialsInput)) {
            foreach ($testimonialsInput as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));
                $location = trim((string) ($entry['location'] ?? ''));
                $message = trim((string) ($entry['message'] ?? ''));
                $systemSize = trim((string) ($entry['system_size'] ?? ''));
                $type = trim((string) ($entry['type'] ?? ''));

                if ($name === '' && $location === '' && $message === '') {
                    continue;
                }

                $testimonials[] = [
                    'name' => $name,
                    'location' => $location,
                    'message' => $message,
                    'system_size' => $systemSize,
                    'type' => $type,
                ];
            }
        }

        $offersInput = $_POST['seasonal_offers'] ?? [];
        $seasonalOffers = [];
        if (is_array($offersInput)) {
            foreach ($offersInput as $offer) {
                if (!is_array($offer)) {
                    continue;
                }

                $label = trim((string) ($offer['label'] ?? ''));
                $description = trim((string) ($offer['description'] ?? ''));
                $validTill = trim((string) ($offer['valid_till'] ?? ''));
                $ctaText = trim((string) ($offer['cta_text'] ?? ''));
                $ctaLink = trim((string) ($offer['cta_link'] ?? ''));

                if ($label === '' && $description === '' && $validTill === '') {
                    continue;
                }

                $seasonalOffers[] = [
                    'label' => $label,
                    'description' => $description,
                    'valid_till' => $validTill,
                    'cta_text' => $ctaText,
                    'cta_link' => $ctaLink,
                ];
            }
        }

        $errors = [];
        if ($global['site_tagline'] === '') {
            $errors[] = 'Site tagline is required.';
        }
        if ($hero['title'] === '') {
            $errors[] = 'Hero title is required.';
        }
        if ($sections['what_our_customers_say_title'] === '') {
            $errors[] = 'Testimonials title is required.';
        }
        if ($sections['seasonal_offer_title'] === '') {
            $errors[] = 'Seasonal offer title is required.';
        }

        if (count($errors) > 0) {
            $flashMessage = implode(' ', $errors);
            $flashTone = 'error';
        } else {
            $merged = $settings;
            $merged['global'] = array_merge($settings['global'] ?? [], $global);
            $merged['hero'] = array_merge($settings['hero'] ?? [], $hero);
            $merged['sections'] = array_merge($settings['sections'] ?? [], $sections);
            $merged['theme'] = array_merge($settings['theme'] ?? [], $theme);
            $merged['testimonials'] = $testimonials;
            $merged['seasonal_offers'] = $seasonalOffers;

            try {
                website_settings_save($merged);
                $settings = website_settings();
                $flashMessage = 'Website content & theme saved successfully.';
                $flashTone = 'success';
            } catch (Throwable $exception) {
                $flashMessage = 'Unable to save settings: ' . $exception->getMessage();
                $flashTone = 'error';
            }
        }
    }
}

$testimonialsJson = htmlspecialchars(json_encode($settings['testimonials'] ?? []), ENT_QUOTES, 'UTF-8');
$offersJson = htmlspecialchars(json_encode($settings['seasonal_offers'] ?? []), ENT_QUOTES, 'UTF-8');
$themeJson = htmlspecialchars(json_encode($settings['theme'] ?? []), ENT_QUOTES, 'UTF-8');
$globalJson = htmlspecialchars(json_encode($settings['global'] ?? []), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Content & Theme | Admin</title>
    <link rel="icon" href="/images/favicon.ico" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/style.css">
    <style>
      .fullwidth-wrapper {
        width: 100% !important;
        max-width: 100% !important;
        padding-left: 20px;
        padding-right: 20px;
      }
    </style>
  </head>
  <body class="bg-slate-50 text-slate-900">
    <div class="fullwidth-wrapper py-8" x-data="websiteSettings(<?= $themeJson ?>, <?= $globalJson ?>)" x-init="initData(<?= $testimonialsJson ?>, <?= $offersJson ?>)">
      <header class="flex items-center justify-between mb-6">
        <div>
          <p class="text-sm text-slate-500">Admin</p>
          <h1 class="text-2xl font-semibold text-slate-900">Website Content &amp; Theme</h1>
          <p class="text-slate-600">Manage marketing content, seasonal offers, and the visual theme used across the site.</p>
        </div>
        <div class="flex items-center space-x-3">
          <a href="/admin-dashboard.php" class="text-sm text-slate-700 hover:text-slate-900">Back to overview</a>
          <button type="button" class="px-3 py-2 text-sm font-medium bg-slate-900 text-white rounded-lg shadow" @click="submitForm()">Save</button>
        </div>
      </header>

      <?php if ($flashMessage !== ''): ?>
        <div class="mb-4 rounded-lg border <?php echo $flashTone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($flashTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-slate-200 bg-white text-slate-700'); ?> px-4 py-3">
          <p class="font-semibold">Notification</p>
          <p class="text-sm mt-1"><?= htmlspecialchars($flashMessage) ?></p>
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-8" id="website-settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Global &amp; Taglines</h2>
              <p class="text-sm text-slate-600">Define the always-on messages shown across the portal.</p>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Site tagline</span>
              <input type="text" name="site_tagline" value="<?= htmlspecialchars($settings['global']['site_tagline'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" required>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Header callout</span>
              <input type="text" name="header_callout" value="<?= htmlspecialchars($settings['global']['header_callout'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-3">
              <p class="text-sm font-semibold text-slate-800">Site tagline styling</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block space-y-2">
                  <span class="text-sm font-medium text-slate-700">Tagline text color</span>
                  <div class="flex items-center space-x-3">
                    <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="globalStyle.tagline_color" aria-label="Tagline color picker">
                    <input type="text" name="tagline_color" x-model="globalStyle.tagline_color" @blur="normalizeTaglineColor()" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#0ea5e9">
                  </div>
                </label>
                <label class="block space-y-1">
                  <span class="text-sm font-medium text-slate-700">Tagline font size</span>
                  <select name="tagline_font_size" x-model="globalStyle.tagline_font_size" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="small">Small</option>
                    <option value="normal">Normal</option>
                    <option value="large">Large</option>
                  </select>
                </label>
                <label class="block space-y-1">
                  <span class="text-sm font-medium text-slate-700">Tagline font weight</span>
                  <select name="tagline_font_weight" x-model="globalStyle.tagline_font_weight" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="normal">Normal</option>
                    <option value="medium">Medium</option>
                    <option value="semibold">Semibold</option>
                    <option value="bold">Bold</option>
                  </select>
                </label>
              </div>
            </div>
            <div class="space-y-3">
              <p class="text-sm font-semibold text-slate-800">Header callout styling</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block space-y-2">
                  <span class="text-sm font-medium text-slate-700">Callout text color</span>
                  <div class="flex items-center space-x-3">
                    <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="globalStyle.callout_color" aria-label="Callout color picker">
                    <input type="text" name="callout_color" x-model="globalStyle.callout_color" @blur="normalizeCalloutColor()" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#0f172a">
                  </div>
                </label>
                <label class="block space-y-1">
                  <span class="text-sm font-medium text-slate-700">Callout font size</span>
                  <select name="callout_font_size" x-model="globalStyle.callout_font_size" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="small">Small</option>
                    <option value="normal">Normal</option>
                    <option value="large">Large</option>
                  </select>
                </label>
                <label class="block space-y-1">
                  <span class="text-sm font-medium text-slate-700">Callout font weight</span>
                  <select name="callout_font_weight" x-model="globalStyle.callout_font_weight" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="normal">Normal</option>
                    <option value="medium">Medium</option>
                    <option value="semibold">Semibold</option>
                    <option value="bold">Bold</option>
                  </select>
                </label>
              </div>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <label class="block space-y-2 md:col-span-2">
              <span class="text-sm font-medium text-slate-700">Subheader background color (marquee strip)</span>
              <div class="flex items-center space-x-3">
                <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="globalStyle.subheader_bg_color" aria-label="Subheader background picker">
                <input type="text" name="subheader_bg_color" x-model="globalStyle.subheader_bg_color" @blur="normalizeSubheaderBg()" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#ffffff">
              </div>
              <p class="text-xs text-slate-500">Optional. Leave blank to use the default header background.</p>
            </label>
            <div class="flex flex-col items-start md:items-end space-y-2">
              <button type="button" class="text-sm text-emerald-700 font-medium" @click="resetGlobalStyles()">Reset tagline &amp; callout styles</button>
              <div class="w-full md:w-auto rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600" :style="previewBackgroundStyle()">
                <p class="font-semibold text-slate-700 mb-1">Live preview</p>
                <div class="space-x-2">
                  <span class="inline-flex items-center px-2 py-1 rounded-full border border-slate-200" :style="previewTextStyle('tagline')">Tagline sample</span>
                  <span class="inline-flex items-center px-2 py-1 rounded-full border border-slate-200" :style="previewTextStyle('callout')">Callout sample</span>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Home Hero &amp; Announcement</h2>
              <p class="text-sm text-slate-600">Primary hero messaging and announcement badge.</p>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Hero kicker / badge</span>
              <input type="text" name="hero_kicker" value="<?= htmlspecialchars($settings['hero']['kicker'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Hero title</span>
              <input type="text" name="hero_title" value="<?= htmlspecialchars($settings['hero']['title'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" required>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Hero subtitle</span>
              <input type="text" name="hero_subtitle" value="<?= htmlspecialchars($settings['hero']['subtitle'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Hero main image URL</span>
              <input type="text" name="hero_primary_image" value="<?= htmlspecialchars($settings['hero']['primary_image'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="https://... or /images/...">
              <p class="text-xs text-slate-500">If left blank, the homepage will fall back to the default hero image.</p>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Hero image caption</span>
              <input type="text" name="hero_primary_caption" value="<?= htmlspecialchars($settings['hero']['primary_caption'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="Short caption visible under the hero image">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Primary button text</span>
              <input type="text" name="hero_primary_button_text" value="<?= htmlspecialchars($settings['hero']['primary_button_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Primary button link</span>
              <input type="text" name="hero_primary_button_link" value="<?= htmlspecialchars($settings['hero']['primary_button_link'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Secondary button text</span>
              <input type="text" name="hero_secondary_button_text" value="<?= htmlspecialchars($settings['hero']['secondary_button_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Secondary button link</span>
              <input type="text" name="hero_secondary_button_link" value="<?= htmlspecialchars($settings['hero']['secondary_button_link'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Announcement badge</span>
              <input type="text" name="hero_announcement_badge" value="<?= htmlspecialchars($settings['hero']['announcement_badge'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Announcement text</span>
              <input type="text" name="hero_announcement_text" value="<?= htmlspecialchars($settings['hero']['announcement_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Mid-page CTA Strip</h2>
              <p class="text-sm text-slate-600">Highlight a key promise or action button on the homepage strip.</p>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">CTA heading</span>
              <input type="text" name="cta_strip_title" value="<?= htmlspecialchars($settings['sections']['cta_strip_title'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">CTA description</span>
              <input type="text" name="cta_strip_text" value="<?= htmlspecialchars($settings['sections']['cta_strip_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">CTA button text</span>
              <input type="text" name="cta_strip_cta_text" value="<?= htmlspecialchars($settings['sections']['cta_strip_cta_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">CTA button link</span>
              <input type="text" name="cta_strip_cta_link" value="<?= htmlspecialchars($settings['sections']['cta_strip_cta_link'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">What Our Customers Say / Testimonials</h2>
              <p class="text-sm text-slate-600">Manage testimonial section headline and individual quotes.</p>
            </div>
            <button type="button" class="px-3 py-2 text-sm bg-emerald-600 text-white rounded-lg" @click="addTestimonial()">Add testimonial</button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Section title</span>
              <input type="text" name="what_our_customers_say_title" value="<?= htmlspecialchars($settings['sections']['what_our_customers_say_title'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" required>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Section subtitle</span>
              <input type="text" name="what_our_customers_say_subtitle" value="<?= htmlspecialchars($settings['sections']['what_our_customers_say_subtitle'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="space-y-4" x-show="testimonials.length > 0">
            <template x-for="(testimonial, index) in testimonials" :key="index">
              <div class="border border-slate-200 rounded-xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <p class="text-sm font-semibold text-slate-800">Testimonial <span x-text="index + 1"></span></p>
                  <button type="button" class="text-sm text-red-600" @click="removeTestimonial(index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">Name</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="testimonial.name" :name="`testimonials[${index}][name]`">
                  </label>
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">Location</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="testimonial.location" :name="`testimonials[${index}][location]`">
                  </label>
                </div>
                <label class="block text-sm space-y-1">
                  <span class="text-slate-700">Message</span>
                  <textarea class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" rows="3" x-model="testimonial.message" :name="`testimonials[${index}][message]`"></textarea>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">System size</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="testimonial.system_size" :name="`testimonials[${index}][system_size]`">
                  </label>
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">Type</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="testimonial.type" :name="`testimonials[${index}][type]`">
                  </label>
                </div>
              </div>
            </template>
          </div>
          <p class="text-sm text-slate-500" x-show="testimonials.length === 0">No testimonials yet. Add one to get started.</p>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Seasonal Offers</h2>
              <p class="text-sm text-slate-600">Headline and offer cards for seasonal promotions.</p>
            </div>
            <button type="button" class="px-3 py-2 text-sm bg-emerald-600 text-white rounded-lg" @click="addOffer()">Add offer</button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Section title</span>
              <input type="text" name="seasonal_offer_title" value="<?= htmlspecialchars($settings['sections']['seasonal_offer_title'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" required>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Section description</span>
              <input type="text" name="seasonal_offer_text" value="<?= htmlspecialchars($settings['sections']['seasonal_offer_text'] ?? '') ?>" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
            </label>
          </div>
          <div class="space-y-4" x-show="offers.length > 0">
            <template x-for="(offer, index) in offers" :key="index">
              <div class="border border-slate-200 rounded-xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <p class="text-sm font-semibold text-slate-800">Offer <span x-text="index + 1"></span></p>
                  <button type="button" class="text-sm text-red-600" @click="removeOffer(index)">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">Label</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="offer.label" :name="`seasonal_offers[${index}][label]`">
                  </label>
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">Valid till</span>
                    <input type="date" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="offer.valid_till" :name="`seasonal_offers[${index}][valid_till]`">
                  </label>
                </div>
                <label class="block text-sm space-y-1">
                  <span class="text-slate-700">Description</span>
                  <textarea class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" rows="3" x-model="offer.description" :name="`seasonal_offers[${index}][description]`"></textarea>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">CTA text</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="offer.cta_text" :name="`seasonal_offers[${index}][cta_text]`">
                  </label>
                  <label class="block text-sm space-y-1">
                    <span class="text-slate-700">CTA link</span>
                    <input type="text" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" x-model="offer.cta_link" :name="`seasonal_offers[${index}][cta_link]`">
                  </label>
                </div>
              </div>
            </template>
          </div>
          <p class="text-sm text-slate-500" x-show="offers.length === 0">No seasonal offers yet. Add one to display on the site.</p>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-semibold text-slate-900">Theme Basics</h2>
              <p class="text-sm text-slate-600">Base color tokens and shared UI styles.</p>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <label class="block space-y-2">
              <span class="text-sm font-medium text-slate-700">Primary color</span>
              <div class="flex items-center space-x-3">
                <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="theme.primary_color" aria-label="Primary color picker">
                <input type="text" name="theme_primary_color" x-model="theme.primary_color" @blur="normalizeHexInput('primary_color')" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#0f766e">
              </div>
            </label>
            <label class="block space-y-2">
              <span class="text-sm font-medium text-slate-700">Secondary color</span>
              <div class="flex items-center space-x-3">
                <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="theme.secondary_color" aria-label="Secondary color picker">
                <input type="text" name="theme_secondary_color" x-model="theme.secondary_color" @blur="normalizeHexInput('secondary_color')" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#f59e0b">
              </div>
            </label>
            <label class="block space-y-2">
              <span class="text-sm font-medium text-slate-700">Accent color</span>
              <div class="flex items-center space-x-3">
                <input type="color" class="h-10 w-14 rounded-lg border border-slate-200" x-model="theme.accent_color" aria-label="Accent color picker">
                <input type="text" name="theme_accent_color" x-model="theme.accent_color" @blur="normalizeHexInput('accent_color')" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500" placeholder="#0ea5e9">
              </div>
            </label>
          </div>
          <div class="flex items-center justify-between">
            <button type="button" class="text-sm text-emerald-700 font-medium" @click="resetColors()">Reset to default colors</button>
            <div class="flex items-center space-x-2 text-xs text-slate-500">
              <span class="inline-block w-3 h-3 rounded-full" :style="`background:${theme.primary_color}`"></span>
              <span class="inline-block w-3 h-3 rounded-full" :style="`background:${theme.secondary_color}`"></span>
              <span class="inline-block w-3 h-3 rounded-full" :style="`background:${theme.accent_color}`"></span>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Button style</span>
              <select name="theme_button_style" x-model="theme.button_style" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                <?php
                  $buttonOptions = [
                    'rounded' => 'Rounded',
                    'pill' => 'Pill',
                    'outline' => 'Outline',
                    'sharp' => 'Sharp corners',
                    'solid-heavy' => 'Solid heavy',
                  ];
                  $currentButton = $settings['theme']['button_style'] ?? '';
                  foreach ($buttonOptions as $value => $label):
                ?>
                  <option value="<?= htmlspecialchars($value) ?>" <?= $currentButton === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block space-y-1">
              <span class="text-sm font-medium text-slate-700">Card style</span>
              <select name="theme_card_style" x-model="theme.card_style" class="w-full rounded-lg border-slate-200 focus:border-emerald-500 focus:ring-emerald-500">
                <?php
                  $cardOptions = [
                    'soft' => 'Soft shadow',
                    'strong' => 'Strong shadow',
                    'border' => 'Bordered',
                    'flat' => 'Flat surface',
                  ];
                  $currentCard = $settings['theme']['card_style'] ?? '';
                  foreach ($cardOptions as $value => $label):
                ?>
                  <option value="<?= htmlspecialchars($value) ?>" <?= $currentCard === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-2">
              <p class="text-sm font-medium text-slate-700">Button preview</p>
              <div class="space-x-2">
                <button type="button" class="px-4 py-2 text-white text-sm font-semibold" :class="buttonPreviewClass()" :style="buttonPreviewStyle('primary')">Primary CTA</button>
                <button type="button" class="px-4 py-2 text-sm font-semibold" :class="buttonPreviewClass()" :style="buttonPreviewStyle('secondary')">Secondary CTA</button>
              </div>
            </div>
            <div class="space-y-2">
              <p class="text-sm font-medium text-slate-700">Card preview</p>
              <div class="p-4 border border-slate-200" :class="cardPreviewClass()" :style="cardPreviewStyle()">
                <p class="font-semibold text-slate-900">Sample card</p>
                <p class="text-sm text-slate-600">Shows how testimonials and offer cards will look.</p>
                <button type="button" class="mt-3 px-3 py-2 text-xs font-semibold text-white" :class="buttonPreviewClass()" :style="buttonPreviewStyle('primary')">Learn more</button>
              </div>
            </div>
          </div>
        </section>

        <div class="flex justify-end">
          <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg shadow hover:bg-emerald-700">Save settings</button>
        </div>
      </form>
    </div>

    <script>
      function websiteSettings(initialTheme = {}, initialGlobal = {}) {
        const defaultTheme = {
          primary_color: '#0f766e',
          secondary_color: '#f59e0b',
          accent_color: '#0ea5e9',
          button_style: 'rounded',
          card_style: 'soft',
        };

        const defaultGlobalStyle = {
          tagline_color: '',
          tagline_font_size: 'normal',
          tagline_font_weight: 'semibold',
          callout_color: '',
          callout_font_size: 'normal',
          callout_font_weight: 'semibold',
          subheader_bg_color: '',
        };

        const fontSizeScale = {
          small: '0.75rem',
          normal: '0.8rem',
          large: '0.95rem',
        };

        const fontWeightScale = {
          normal: '400',
          medium: '500',
          semibold: '600',
          bold: '700',
        };

        return {
          testimonials: [],
          offers: [],
          theme: { ...defaultTheme, ...(initialTheme || {}) },
          globalStyle: { ...defaultGlobalStyle, ...(initialGlobal || {}) },
          initData(testimonials = [], offers = []) {
            this.testimonials = Array.isArray(testimonials) ? testimonials : [];
            this.offers = Array.isArray(offers) ? offers : [];
          },
          addTestimonial() {
            this.testimonials.push({ name: '', location: '', message: '', system_size: '', type: '' });
          },
          removeTestimonial(index) {
            this.testimonials.splice(index, 1);
          },
          addOffer() {
            this.offers.push({ label: '', description: '', valid_till: '', cta_text: '', cta_link: '' });
          },
          removeOffer(index) {
            this.offers.splice(index, 1);
          },
          resetColors() {
            this.theme.primary_color = defaultTheme.primary_color;
            this.theme.secondary_color = defaultTheme.secondary_color;
            this.theme.accent_color = defaultTheme.accent_color;
          },
          formatHex(value, fallback) {
            const raw = String(value || '').trim();
            if (!raw) return fallback;
            const withHash = raw.startsWith('#') ? raw : `#${raw}`;
            return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(withHash) ? withHash.toUpperCase() : fallback;
          },
          normalizeHexInput(field) {
            this.theme[field] = this.formatHex(this.theme[field], defaultTheme[field]);
          },
          normalizeTaglineColor() {
            this.globalStyle.tagline_color = this.formatHex(this.globalStyle.tagline_color, defaultGlobalStyle.tagline_color);
          },
          normalizeCalloutColor() {
            this.globalStyle.callout_color = this.formatHex(this.globalStyle.callout_color, defaultGlobalStyle.callout_color);
          },
          normalizeSubheaderBg() {
            this.globalStyle.subheader_bg_color = this.formatHex(this.globalStyle.subheader_bg_color, defaultGlobalStyle.subheader_bg_color);
          },
          buttonPreviewClass() {
            const map = {
              rounded: 'rounded-lg shadow-sm',
              pill: 'rounded-full shadow-sm',
              outline: 'rounded-lg border border-slate-300 bg-white text-slate-800',
              sharp: 'rounded-md shadow-md',
              'solid-heavy': 'rounded-lg shadow-xl',
            };
            return map[this.theme.button_style] || map.rounded;
          },
          buttonPreviewStyle(kind = 'primary') {
            const base = kind === 'secondary' ? this.theme.secondary_color : this.theme.primary_color;
            const textColor = kind === 'secondary' ? '#111827' : '#0f172a';
            const isOutline = this.theme.button_style === 'outline';
            const isHeavy = this.theme.button_style === 'solid-heavy';
            if (isOutline) {
              return `background: transparent; color: ${base}; border-color: ${base}; box-shadow:none;`;
            }
            const shadow = isHeavy ? '0 18px 36px rgba(15,23,42,0.2)' : '0 12px 22px rgba(15,23,42,0.14)';
            return `background:${base}; color:${textColor}; box-shadow:${shadow};`;
          },
          cardPreviewClass() {
            const map = {
              soft: 'rounded-2xl shadow-md bg-white',
              strong: 'rounded-xl shadow-xl bg-white',
              border: 'rounded-lg border bg-white',
              flat: 'rounded-lg bg-slate-50',
            };
            return map[this.theme.card_style] || map.soft;
          },
          cardPreviewStyle() {
            const style = this.theme.card_style;
            const shadow = style === 'strong' ? '0 20px 45px rgba(15,23,42,0.16)' : style === 'border' ? '0 6px 16px rgba(15,23,42,0.08)' : '0 12px 32px rgba(15,23,42,0.08)';
            const borderColor = style === 'flat' ? 'transparent' : this.theme.secondary_color;
            const surface = style === 'flat' ? '#f8fafc' : '#ffffff';
            return `border-color: ${borderColor}; box-shadow: ${shadow}; background:${surface};`;
          },
          resetGlobalStyles() {
            this.globalStyle = { ...defaultGlobalStyle };
          },
          previewTextStyle(kind = 'tagline') {
            const isTagline = kind === 'tagline';
            const color = isTagline ? this.globalStyle.tagline_color : this.globalStyle.callout_color;
            const sizeKey = isTagline ? this.globalStyle.tagline_font_size : this.globalStyle.callout_font_size;
            const weightKey = isTagline ? this.globalStyle.tagline_font_weight : this.globalStyle.callout_font_weight;
            const size = fontSizeScale[sizeKey] || fontSizeScale.normal;
            const weight = fontWeightScale[weightKey] || fontWeightScale.semibold;
            const fallbackColor = isTagline ? '#0ea5e9' : '#0f172a';
            return `color: ${color || fallbackColor}; font-size:${size}; font-weight:${weight};`;
          },
          previewBackgroundStyle() {
            return this.globalStyle.subheader_bg_color ? `background:${this.globalStyle.subheader_bg_color};` : '';
          },
          submitForm() {
            document.getElementById('website-settings-form').requestSubmit();
          }
        }
      }
    </script>
  </body>
</html>
