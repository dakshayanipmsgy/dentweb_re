<?php
declare(strict_types=1);

function solar_details_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function solar_details_message_settings(): array
{
    $path = __DIR__ . '/data/leads/lead_message_settings.json';
    $defaults = [
        'company_name' => 'Dakshayani Enterprises',
        'company_phone' => '',
    ];
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'company_name' => trim((string) ($decoded['company_name'] ?? $defaults['company_name'])),
        'company_phone' => trim((string) ($decoded['company_phone'] ?? '')),
    ];
}

function solar_details_defaults(): array
{
    return [
        'page_title' => 'Solar Rooftop Details',
        'hero_intro' => 'Aasaan bhaasha mein samjhiye rooftop solar, PM Surya Ghar Yojana, on-grid vs hybrid aur pura installation process.',
        'pm_surya_ghar_text' => 'PM Surya Ghar: Muft Bijli Yojana ek residential-focused scheme hai jisme eligible gharon ko rooftop solar lagane par subsidy support mil sakta hai, policy aur eligibility ke hisaab se.',
        'on_grid_text' => 'On-grid system mein aapka solar system direct grid ke saath kaam karta hai. Din mein solar power use hoti hai, extra power grid mein jaa sakti hai, aur billing net-metering rules ke hisaab se hoti hai.',
        'hybrid_text' => 'Hybrid system mein solar ke saath battery backup hota hai. Isse light cut hone par bhi selected load chalaya ja sakta hai. Initial cost on-grid se thodi zyada hoti hai.',
        'process_text' => "1) Site survey\n2) Load understanding & design\n3) Final proposal\n4) Installation\n5) Net-meter / testing\n6) Documentation & subsidy guidance (if applicable)",
        'faq_text' => "Q: Kitna bill kam ho sakta hai?\nA: Load, usage pattern, roof area aur system size par depend karta hai.\n\nQ: On-grid mein light chali gayi toh?\nA: Safety ke liye typical on-grid system blackout mein band hota hai.\n\nQ: Subsidy guaranteed hai?\nA: Nahi, subsidy policy, eligibility aur government process par depend karti hai.",
        'cta_text' => 'Apne ghar/business ke liye suitable solar option jaanne ke liye humse baat karein. Survey se quotation tak guided support milega.',
        'on_grid_image' => '',
        'hybrid_image' => '',
        'process_flow_image' => '',
        'benefits_image' => '',
    ];
}

function solar_details_load_content(): array
{
    $defaults = solar_details_defaults();
    $path = __DIR__ . '/data/leads/lead_explainer_content.json';
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

$content = solar_details_load_content();
$settings = solar_details_message_settings();
$companyName = trim((string) ($settings['company_name'] ?? 'Dakshayani Enterprises')) ?: 'Dakshayani Enterprises';
$companyPhone = trim((string) ($settings['company_phone'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo solar_details_safe((string) $content['page_title']); ?> | <?php echo solar_details_safe($companyName); ?></title>
  <style>
    body { margin: 0; font-family: Inter, system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; }
    .wrap { max-width: 1050px; margin: 0 auto; padding: 1rem; }
    .hero { background: linear-gradient(120deg, #1d4ed8, #0ea5e9); color: #fff; border-radius: 18px; padding: 1.2rem; }
    .hero h1 { margin: 0 0 0.4rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; box-shadow: 0 8px 20px rgba(15,23,42,0.06); }
    .card h2, .card h3 { margin-top: 0; }
    .text-block { white-space: pre-line; line-height: 1.55; color: #334155; }
    .image-slot { width: 100%; border-radius: 12px; border: 1px solid #cbd5e1; background: #f1f5f9; min-height: 160px; object-fit: cover; }
    .muted { color: #475569; }
    .cta { background: #0f172a; color: #fff; border-radius: 14px; padding: 1rem; margin-top: 1rem; }
    .cta a { color: #93c5fd; text-decoration: none; font-weight: 700; }
    .chips { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.6rem; }
    .chip { padding: 0.3rem 0.55rem; border-radius: 999px; background: #dbeafe; color: #1e3a8a; font-size: 0.85rem; font-weight: 700; }
    @media (max-width: 640px) {
      .hero { padding: 1rem; }
      .wrap { padding: 0.75rem; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1><?php echo solar_details_safe((string) $content['page_title']); ?></h1>
      <p style="margin:0;"><?php echo solar_details_safe((string) $content['hero_intro']); ?></p>
      <div class="chips">
        <span class="chip">🏠 Rooftop Solar</span>
        <span class="chip">⚡ PM Surya Ghar</span>
        <span class="chip">🔋 On-grid vs Hybrid</span>
      </div>
    </section>

    <section class="grid">
      <article class="card">
        <h2>A) What is Solar Rooftop?</h2>
        <p class="text-block">Rooftop solar ka matlab hai aapki chhat par panels lagana jisse ghar/business ki electricity demand ka bada hissa solar se aa sake.</p>
      </article>
      <article class="card">
        <h2>B) PM Surya Ghar: Muft Bijli Yojana</h2>
        <p class="text-block"><?php echo solar_details_safe((string) $content['pm_surya_ghar_text']); ?></p>
      </article>
      <article class="card">
        <h2>C) Who is Eligible?</h2>
        <p class="text-block">Generally residential consumers jinke paas suitable roof space aur DISCOM/net-metering eligibility ho. Final eligibility local policy aur documents par depend karti hai.</p>
      </article>
    </section>

    <section class="grid">
      <article class="card">
        <h2>D) On-grid</h2>
        <p class="text-block"><?php echo solar_details_safe((string) $content['on_grid_text']); ?></p>
        <?php if (trim((string) $content['on_grid_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['on_grid_image']); ?>" alt="On-grid diagram" />
        <?php endif; ?>
      </article>
      <article class="card">
        <h2>D) Hybrid</h2>
        <p class="text-block"><?php echo solar_details_safe((string) $content['hybrid_text']); ?></p>
        <?php if (trim((string) $content['hybrid_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['hybrid_image']); ?>" alt="Hybrid diagram" />
        <?php endif; ?>
      </article>
    </section>

    <section class="card">
      <h2>E) Which one is suitable for whom?</h2>
      <p class="text-block">Agar aapka goal monthly bill reduction hai aur power-cut concern kam hai, on-grid usually best value deta hai. Agar backup bhi chahiye, hybrid better ho sakta hai.</p>
      <h2>F) Process (Inquiry to Installation)</h2>
      <p class="text-block"><?php echo solar_details_safe((string) $content['process_text']); ?></p>
      <?php if (trim((string) $content['process_flow_image']) !== ''): ?>
        <img class="image-slot" src="<?php echo solar_details_safe((string) $content['process_flow_image']); ?>" alt="Solar process flow" />
      <?php endif; ?>
    </section>

    <section class="grid">
      <article class="card">
        <h2>G) Benefits</h2>
        <ul class="muted">
          <li>Lower electricity bill</li>
          <li>Environment friendly energy</li>
          <li>Low maintenance</li>
          <li>Long system life</li>
        </ul>
        <?php if (trim((string) $content['benefits_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['benefits_image']); ?>" alt="Solar benefits" />
        <?php endif; ?>
      </article>
      <article class="card">
        <h2>H) Important Expectations</h2>
        <ul class="muted">
          <li>On-grid system grid dependency ke saath kaam karta hai.</li>
          <li>Hybrid system backup deta hai but cost higher ho sakti hai.</li>
          <li>Subsidy policy/applicability time ke saath change ho sakti hai.</li>
        </ul>
      </article>
    </section>

    <section class="card">
      <h2>I) FAQ</h2>
      <p class="text-block"><?php echo solar_details_safe((string) $content['faq_text']); ?></p>
    </section>

    <section class="cta">
      <h2 style="margin-top:0;">Ready to explore your solar option?</h2>
      <p class="text-block"><?php echo solar_details_safe((string) $content['cta_text']); ?></p>
      <p style="margin-bottom:0;">
        <strong><?php echo solar_details_safe($companyName); ?></strong>
        <?php if ($companyPhone !== ''): ?>
          &nbsp;|&nbsp;<a href="tel:<?php echo solar_details_safe($companyPhone); ?>">Call: <?php echo solar_details_safe($companyPhone); ?></a>
          &nbsp;|&nbsp;<a href="https://wa.me/<?php echo solar_details_safe(preg_replace('/\D+/', '', $companyPhone) ?? ''); ?>" target="_blank" rel="noopener">WhatsApp Us</a>
        <?php endif; ?>
      </p>
    </section>
  </div>
</body>
</html>
