<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="index,follow">
  <title>Install Dakshayani App | Help</title>
  <meta name="description" content="Simple steps to install the Dakshayani app on Android, iPhone, and desktop browsers.">
  <link rel="stylesheet" href="style.css">
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
  <style>
    body{margin:0;background:#f8fafc;color:#0f172a;font-family:Poppins,Arial,sans-serif}.help-shell{max-width:980px;margin:0 auto;padding:clamp(1rem,4vw,2rem)}.help-hero,.help-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 16px 42px rgba(15,23,42,.08)}.help-hero{padding:clamp(1.25rem,4vw,2.25rem);background:linear-gradient(135deg,#0f766e,#0f172a);color:#fff}.help-hero p{max-width:680px;color:rgba(255,255,255,.86);line-height:1.7}.help-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin:1rem 0}.help-card{padding:1.25rem}.help-card h2{margin-top:0}.steps{padding-left:1.25rem;line-height:1.85}.help-links{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1rem}.help-links a,.help-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:.65rem 1rem;border-radius:999px;text-decoration:none;font-weight:800}.help-button{background:#0f766e;color:#fff}.help-links a:not(.help-button){background:#e0f2fe;color:#075985}.note{background:#ecfeff;border:1px solid #99f6e4;border-radius:18px;padding:1rem;line-height:1.7}@media(max-width:640px){.help-shell{padding:.85rem}.help-hero,.help-card{border-radius:18px}}
  </style>
</head>
<body>
  <main class="help-shell">
    <section class="help-hero">
      <p style="margin:0 0 .35rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase">App installation help</p>
      <h1>Install the Dakshayani app</h1>
      <p>Install Dakshayani on your phone or computer for quicker access to your secure Admin, Customer, or Employee workspace. These instructions are safe for logged-out users and do not show private account data.</p>
      <div class="help-links"><a class="help-button" href="login.php">Go to login</a><a href="privacy-policy.php">Privacy Policy</a><a href="terms.php">Terms</a></div>
    </section>

    <section class="help-grid" aria-label="Install instructions">
      <article class="help-card">
        <h2>Android Chrome</h2>
        <ol class="steps">
          <li>Open the Dakshayani website in Chrome.</li>
          <li>Tap the menu button.</li>
          <li>Tap <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li>
          <li>Confirm the install.</li>
          <li>Open <strong>Dakshayani</strong> from your phone home screen.</li>
        </ol>
      </article>
      <article class="help-card">
        <h2>iPhone Safari</h2>
        <ol class="steps">
          <li>Open the Dakshayani website in Safari.</li>
          <li>Tap the Share button.</li>
          <li>Tap <strong>Add to Home Screen</strong>.</li>
          <li>Tap <strong>Add</strong>.</li>
          <li>Open <strong>Dakshayani</strong> from your iPhone home screen.</li>
        </ol>
      </article>
      <article class="help-card">
        <h2>Desktop Chrome / Edge</h2>
        <ol class="steps">
          <li>Open the Dakshayani website in Chrome or Edge.</li>
          <li>Look for the install icon in the address bar, or open the browser menu.</li>
          <li>Choose <strong>Install Dakshayani</strong> or <strong>Apps</strong> then <strong>Install this site as an app</strong>.</li>
          <li>Confirm the install.</li>
          <li>Open Dakshayani from your desktop app list or shortcut.</li>
        </ol>
      </article>
    </section>

    <section class="note">
      <strong>Tip:</strong> If you do not see an install option, keep using the website in your browser and try again after the page finishes loading. Your secure access still depends on normal server-side login and role checks.
    </section>
  </main>
</body>
</html>
