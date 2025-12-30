<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_gemini.php';
require_once __DIR__ . '/includes/smart_marketing.php';

require_admin();

$admin = current_user();
$csrfToken = $_SESSION['csrf_token'] ?? '';
$aiSettings = ai_settings_load();

// Load current settings
$siteContent = smart_marketing_settings_section_read('site_content');
$festivalThemes = [
    'default' => 'Standard décor',
    'diwali' => 'Diwali lights',
    'holi' => 'Holi colours',
    'christmas' => 'Christmas glow',
];

$flashMessage = '';
$flashTone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $flashMessage = 'Session expired. Please refresh and try again.';
        $flashTone = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_site_settings') {
            try {
                $updates = [
                    'festival' => [
                        'current' => $_POST['festival_theme'] ?? 'default',
                        'custom' => [
                            'primary' => $_POST['custom_primary'] ?? '#f97316',
                            'dark' => $_POST['custom_dark'] ?? '#c2410c',
                            'overlay' => $_POST['custom_overlay'] ?? 'rgba(17, 24, 39, 0.88)',
                            'banner' => $_POST['custom_banner_text'] ?? '',
                            'bannerBg' => $_POST['custom_banner_bg'] ?? '',
                            'bannerColor' => $_POST['custom_banner_color'] ?? '#ffffff',
                        ],
                    ],
                    'hero' => [
                        'title' => $_POST['hero_title'] ?? '',
                        'subtitle' => $_POST['hero_subtitle'] ?? '',
                        'image' => $_POST['hero_image'] ?? '',
                        'announcement' => $_POST['hero_announcement'] ?? '',
                        'link' => $_POST['hero_link'] ?? '',
                    ],
                    // Preserve existing offers/testimonials if not being edited here yet
                    'offers' => $siteContent['offers'] ?? [],
                    'testimonials' => $siteContent['testimonials'] ?? [],
                ];

                smart_marketing_settings_save_section('site_content', $updates, $admin, $aiSettings);
                $siteContent = smart_marketing_settings_section_read('site_content'); // Reload
                $flashMessage = 'Site settings updated successfully.';
                $flashTone = 'success';
            } catch (Throwable $e) {
                $flashMessage = 'Error saving settings: ' . $e->getMessage();
                $flashTone = 'error';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Site Settings | Dakshayani Enterprises</title>
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="admin-records" data-theme="light">
  <main class="admin-records__shell">
    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone) ?>" role="status">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
    <?php endif; ?>

    <header class="admin-records__header">
      <div>
        <h1>Site Settings</h1>
        <p class="admin-muted">Manage festival themes, hero content, and site-wide announcements.</p>
      </div>
      <div class="admin-records__meta">
        <a class="admin-link" href="admin-dashboard.php"><i class="fa-solid fa-gauge-high"></i> Back to overview</a>
      </div>
    </header>

    <section class="admin-section">
      <header class="admin-section__header">
        <div>
          <h2>General Configuration</h2>
          <p class="admin-muted">Control the visual theme and main landing content.</p>
        </div>
      </header>

      <section class="admin-section__body">
        <form method="post" class="admin-form admin-form--stacked">
          <input type="hidden" name="action" value="save_site_settings" />
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          
          <div class="admin-form__grid">
            <label>
              <span>Festival Theme</span>
              <select name="festival_theme">
                <option value="custom" <?= ($siteContent['festival']['current'] ?? 'default') === 'custom' ? 'selected' : '' ?>>
                    Custom Theme
                </option>
                <?php foreach ($festivalThemes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= ($siteContent['festival']['current'] ?? 'default') === $key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="admin-muted">Changes the color palette and banner style.</small>
            </label>
          </div>

          <!-- Custom Theme Controls (only visible if Custom is selected) -->
          <?php $customTheme = $siteContent['festival']['custom'] ?? []; ?>
          <div id="custom-theme-controls" style="display: <?= ($siteContent['festival']['current'] ?? 'default') === 'custom' ? 'block' : 'none' ?>; margin-top: 1rem; padding: 1rem; border: 1px solid var(--border); border-radius: 0.5rem;">
            <h3>Custom Theme Settings</h3>
            <div class="admin-form__grid">
                <label>
                    <span>Primary Color</span>
                    <input type="color" name="custom_primary" value="<?= htmlspecialchars($customTheme['primary'] ?? '#f97316') ?>" />
                </label>
                <label>
                    <span>Dark/Secondary Color</span>
                    <input type="color" name="custom_dark" value="<?= htmlspecialchars($customTheme['dark'] ?? '#c2410c') ?>" />
                </label>
            </div>
            <div class="admin-form__grid">
                <label>
                    <span>Overlay Color (RGBA)</span>
                    <input type="text" name="custom_overlay" value="<?= htmlspecialchars($customTheme['overlay'] ?? 'rgba(17, 24, 39, 0.88)') ?>" placeholder="rgba(0,0,0,0.8)" />
                </label>
                <label>
                    <span>Banner Text Color</span>
                    <input type="color" name="custom_banner_color" value="<?= htmlspecialchars($customTheme['bannerColor'] ?? '#ffffff') ?>" />
                </label>
            </div>
            <div class="admin-form__grid">
                <label>
                    <span>Banner Background (CSS)</span>
                    <input type="text" name="custom_banner_bg" value="<?= htmlspecialchars($customTheme['bannerBg'] ?? 'linear-gradient(...)') ?>" placeholder="linear-gradient(...)" />
                </label>
                 <label>
                    <span>Banner Text</span>
                    <input type="text" name="custom_banner_text" value="<?= htmlspecialchars($customTheme['banner'] ?? '') ?>" placeholder="Custom banner message" />
                </label>
            </div>
          </div>

          <script>
            document.querySelector('select[name="festival_theme"]').addEventListener('change', function(e) {
                const customControls = document.getElementById('custom-theme-controls');
                customControls.style.display = e.target.value === 'custom' ? 'block' : 'none';
            });
          </script>

          <hr class="admin-divider" />
          <h3>Hero Section</h3>

          <div class="admin-form__grid">
            <label>
              <span>Hero Title</span>
              <input type="text" name="hero_title" value="<?= htmlspecialchars($siteContent['hero']['title'] ?? '') ?>" placeholder="Main headline" />
            </label>
            <label>
              <span>Hero Subtitle</span>
              <input type="text" name="hero_subtitle" value="<?= htmlspecialchars($siteContent['hero']['subtitle'] ?? '') ?>" placeholder="Supporting text" />
            </label>
          </div>

          <div class="admin-form__grid">
            <label>
              <span>Hero Image URL</span>
              <input type="text" name="hero_image" value="<?= htmlspecialchars($siteContent['hero']['image'] ?? '') ?>" placeholder="images/..." />
            </label>
            <label>
                <span>Announcement (Optional)</span>
                <input type="text" name="hero_announcement" value="<?= htmlspecialchars($siteContent['hero']['announcement'] ?? '') ?>" placeholder="Top banner text" />
            </label>
          </div>
           <div class="admin-form__grid">
            <label>
                <span>Announcement Link (Optional)</span>
                <input type="text" name="hero_link" value="<?= htmlspecialchars($siteContent['hero']['link'] ?? '') ?>" placeholder="https://..." />
            </label>
           </div>

          <div class="admin-actions">
             <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </section>
    </section>
  </main>
</body>
</html>
