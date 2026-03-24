<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

function solar_details_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function solar_details_ensure_utf8(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    if (function_exists('mb_convert_encoding')) {
        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    return $value;
}

function solar_details_is_html(string $value): bool
{
    return $value !== strip_tags($value);
}

function solar_details_decode_html_entities(string $value): string
{
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function solar_details_format_rich_text(string $value): string
{
    $value = solar_details_ensure_utf8(trim($value));
    if ($value === '') {
        return '';
    }

    $decodedValue = solar_details_decode_html_entities($value);
    if (solar_details_is_html($decodedValue)) {
        return $decodedValue;
    }

    $decodedValue = str_replace(["\r\n", "\r"], "\n", $decodedValue);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $decodedValue)), static fn(string $line): bool => $line !== ''));
    if ($lines === []) {
        return '';
    }

    $html = [];
    $currentListType = '';
    foreach ($lines as $line) {
        $unorderedLine = preg_match('/^[\-\*\x{2022}\x{25CF}\x{25E6}\x{2043}]\s+/u', $line) === 1;
        $emojiBulletLine = preg_match('/^\p{So}\s+/u', $line) === 1;
        $orderedLine = preg_match('/^\d+[\.\)]\s+/u', $line) === 1;

        if ($unorderedLine || $emojiBulletLine) {
            $text = preg_replace('/^[\-\*\x{2022}\x{25CF}\x{25E6}\x{2043}\p{So}]\s+/u', '', $line) ?? $line;
            if ($currentListType !== 'ul') {
                if ($currentListType === 'ol') {
                    $html[] = '</ol>';
                }
                $html[] = '<ul>';
                $currentListType = 'ul';
            }
            $html[] = '<li>' . solar_details_safe($text) . '</li>';
            continue;
        }

        if ($orderedLine) {
            $text = preg_replace('/^\d+[\.\)]\s+/u', '', $line) ?? $line;
            if ($currentListType !== 'ol') {
                if ($currentListType === 'ul') {
                    $html[] = '</ul>';
                }
                $html[] = '<ol>';
                $currentListType = 'ol';
            }
            $html[] = '<li>' . solar_details_safe($text) . '</li>';
            continue;
        }

        if ($currentListType === 'ul') {
            $html[] = '</ul>';
            $currentListType = '';
        } elseif ($currentListType === 'ol') {
            $html[] = '</ol>';
            $currentListType = '';
        }

        $html[] = '<p>' . solar_details_safe($line) . '</p>';
    }

    if ($currentListType === 'ul') {
        $html[] = '</ul>';
    } elseif ($currentListType === 'ol') {
        $html[] = '</ol>';
    }

    return implode("\n", $html);
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
        'what_is_solar_rooftop' => '',
        'pm_surya_ghar_text' => 'PM Surya Ghar: Muft Bijli Yojana ek residential-focused scheme hai jisme eligible gharon ko rooftop solar lagane par subsidy support mil sakta hai, policy aur eligibility ke hisaab se.',
        'who_is_eligible' => '',
        'on_grid_text' => 'On-grid system mein aapka solar system direct grid ke saath kaam karta hai. Din mein solar power use hoti hai, extra power grid mein jaa sakti hai, aur billing net-metering rules ke hisaab se hoti hai.',
        'hybrid_text' => 'Hybrid system mein solar ke saath battery backup hota hai. Isse light cut hone par bhi selected load chalaya ja sakta hai. Initial cost on-grid se thodi zyada hoti hai.',
        'which_one_is_suitable_for_whom' => '',
        'benefits' => '',
        'important_expectations' => '',
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

    $merged = array_merge($defaults, $decoded);
    foreach ($merged as $key => $value) {
        if (is_string($value)) {
            $merged[$key] = solar_details_ensure_utf8($value);
        }
    }

    return $merged;
}

function solar_details_resolve_text(array $content, string $key, string $fallback): string
{
    $value = trim(solar_details_ensure_utf8((string) ($content[$key] ?? '')));
    return $value !== '' ? $value : $fallback;
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
    body { margin: 0; font-family: Inter, system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.58; }
    .wrap { max-width: 1050px; margin: 0 auto; padding: 1rem; }
    .hero { background: linear-gradient(120deg, #1d4ed8, #0ea5e9); color: #fff; border-radius: 18px; padding: 1.2rem; }
    .hero h1 { margin: 0 0 0.4rem; }
    .hero p { line-height: 1.55; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-top: 1rem; align-items: start; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; box-shadow: 0 8px 20px rgba(15,23,42,0.06); }
    .card h2, .card h3 { margin-top: 0; margin-bottom: 0.75rem; line-height: 1.35; }
    .text-block { color: #334155; }
    .text-block p { margin: 0 0 0.8rem; }
    .text-block p:last-child { margin-bottom: 0; }
    .text-block ul, .text-block ol { margin: 0 0 0.85rem 1.2rem; padding: 0; }
    .text-block li { margin-bottom: 0.45rem; }
    .text-block li:last-child { margin-bottom: 0; }
    .image-slot { display: block; max-width: 100%; width: 100%; height: auto; margin: 0.85rem auto 0; border-radius: 12px; border: 1px solid #cbd5e1; background: #f1f5f9; object-fit: cover; }
    .muted { color: #475569; }
    .cta { background: #0f172a; color: #fff; border-radius: 14px; padding: 1rem; margin-top: 1rem; }
    .cta a { color: #93c5fd; text-decoration: none; font-weight: 700; }
    .chips { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.6rem; }
    .chip { padding: 0.3rem 0.55rem; border-radius: 999px; background: #dbeafe; color: #1e3a8a; font-size: 0.85rem; font-weight: 700; }
    @media (max-width: 640px) {
      .hero { padding: 1rem; }
      .wrap { padding: 0.75rem; }
      .grid { grid-template-columns: 1fr; }
      .card { padding: 0.9rem; }
      .text-block { line-height: 1.62; }
      .text-block ul, .text-block ol { margin-left: 1rem; }
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
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'what_is_solar_rooftop', 'Rooftop solar ka matlab hai aapki chhat par panels lagana jisse ghar/business ki electricity demand ka bada hissa solar se aa sake.')); ?></div>
      </article>
      <article class="card">
        <h2>B) PM Surya Ghar: Muft Bijli Yojana</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['pm_surya_ghar_text']); ?></div>
      </article>
      <article class="card">
        <h2>C) Who is Eligible?</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'who_is_eligible', 'Generally residential consumers jinke paas suitable roof space aur DISCOM/net-metering eligibility ho. Final eligibility local policy aur documents par depend karti hai.')); ?></div>
      </article>
    </section>

    <section class="grid">
      <article class="card">
        <h2>D) On-grid</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['on_grid_text']); ?></div>
        <?php if (trim((string) $content['on_grid_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['on_grid_image']); ?>" alt="On-grid diagram" />
        <?php endif; ?>
      </article>
      <article class="card">
        <h2>D) Hybrid</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['hybrid_text']); ?></div>
        <?php if (trim((string) $content['hybrid_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['hybrid_image']); ?>" alt="Hybrid diagram" />
        <?php endif; ?>
      </article>
    </section>

    <section class="card">
      <h2>E) Which one is suitable for whom?</h2>
      <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'which_one_is_suitable_for_whom', 'Agar aapka goal monthly bill reduction hai aur power-cut concern kam hai, on-grid usually best value deta hai. Agar backup bhi chahiye, hybrid better ho sakta hai.')); ?></div>
      <h2>F) Process (Inquiry to Installation)</h2>
      <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['process_text']); ?></div>
      <?php if (trim((string) $content['process_flow_image']) !== ''): ?>
        <img class="image-slot" src="<?php echo solar_details_safe((string) $content['process_flow_image']); ?>" alt="Solar process flow" />
      <?php endif; ?>
    </section>

    <section class="grid">
      <article class="card">
        <h2>G) Benefits</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'benefits', "Lower electricity bill\nEnvironment friendly energy\nLow maintenance\nLong system life")); ?></div>
        <?php if (trim((string) $content['benefits_image']) !== ''): ?>
          <img class="image-slot" src="<?php echo solar_details_safe((string) $content['benefits_image']); ?>" alt="Solar benefits" />
        <?php endif; ?>
      </article>
      <article class="card">
        <h2>H) Important Expectations</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'important_expectations', "On-grid system grid dependency ke saath kaam karta hai.\nHybrid system backup deta hai but cost higher ho sakti hai.\nSubsidy policy/applicability time ke saath change ho sakti hai.")); ?></div>
      </article>
    </section>

    <section class="card">
      <h2>I) FAQ</h2>
      <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['faq_text']); ?></div>
    </section>

    <section class="cta">
      <h2 style="margin-top:0;">Ready to explore your solar option?</h2>
      <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['cta_text']); ?></div>
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
