<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ws = website_settings();
$global = $ws['global'] ?? [];
$hero = $ws['hero'] ?? [];
$sections = $ws['sections'] ?? [];
$testimonials = $ws['testimonials'] ?? [];
$offers = $ws['seasonal_offers'] ?? [];
$theme = $ws['theme'] ?? [];
$primaryColor = $theme['primary_color'] ?? '#333333';
$secondaryColor = $theme['secondary_color'] ?? '#00374a';
$accentColor = $theme['accent_color'] ?? '#f5ec00';
$buttonStyle = $theme['button_style'] ?? 'rounded';
$cardStyle = $theme['card_style'] ?? 'soft';

$buttonTokens = [
    'rounded' => [
        'radius' => '0.85rem',
        'primary_shadow' => '0 12px 24px rgba(251, 191, 36, 0.35)',
        'primary_hover_shadow' => '0 16px 30px rgba(217, 119, 6, 0.45)',
        'secondary_shadow' => '0 12px 26px rgba(30, 58, 138, 0.25)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
    'pill' => [
        'radius' => '999px',
        'primary_shadow' => '0 14px 28px rgba(251, 191, 36, 0.35)',
        'primary_hover_shadow' => '0 18px 32px rgba(217, 119, 6, 0.45)',
        'secondary_shadow' => '0 14px 28px rgba(30, 58, 138, 0.3)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
    'outline' => [
        'radius' => '0.9rem',
        'primary_shadow' => '0 10px 18px rgba(15, 23, 42, 0.08)',
        'primary_hover_shadow' => '0 12px 24px rgba(15, 23, 42, 0.12)',
        'secondary_shadow' => '0 10px 18px rgba(15, 23, 42, 0.08)',
        'primary_border' => '2px solid ' . $primaryColor,
        'secondary_border' => '2px solid ' . $secondaryColor,
        'primary_bg' => 'transparent',
        'primary_hover_bg' => $primaryColor,
        'secondary_bg' => 'transparent',
        'secondary_hover_bg' => $secondaryColor,
        'base_border' => '1px solid rgba(148, 163, 184, 0.35)',
    ],
    'sharp' => [
        'radius' => '0.35rem',
        'primary_shadow' => '0 10px 22px rgba(15, 23, 42, 0.14)',
        'primary_hover_shadow' => '0 14px 26px rgba(15, 23, 42, 0.2)',
        'secondary_shadow' => '0 10px 20px rgba(15, 23, 42, 0.12)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid rgba(148, 163, 184, 0.45)',
    ],
    'solid-heavy' => [
        'radius' => '0.75rem',
        'primary_shadow' => '0 18px 36px rgba(15, 23, 42, 0.22)',
        'primary_hover_shadow' => '0 22px 46px rgba(15, 23, 42, 0.28)',
        'secondary_shadow' => '0 18px 32px rgba(15, 23, 42, 0.2)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
];

$cardTokens = [
    'soft' => [
        'radius' => '1.25rem',
        'shadow' => '0 12px 32px rgba(15, 23, 42, 0.08)',
        'border' => '1px solid rgba(148, 163, 184, 0.16)',
        'surface' => '#ffffff',
    ],
    'strong' => [
        'radius' => '1.1rem',
        'shadow' => '0 20px 45px rgba(15, 23, 42, 0.16)',
        'border' => '1px solid rgba(148, 163, 184, 0.18)',
        'surface' => '#ffffff',
    ],
    'border' => [
        'radius' => '1rem',
        'shadow' => '0 4px 10px rgba(15, 23, 42, 0.06)',
        'border' => '1px solid rgba(15, 23, 42, 0.12)',
        'surface' => '#ffffff',
    ],
    'flat' => [
        'radius' => '0.9rem',
        'shadow' => 'none',
        'border' => '1px solid transparent',
        'surface' => '#f8fafc',
    ],
];

$buttonStyle = array_key_exists($buttonStyle, $buttonTokens) ? $buttonStyle : 'rounded';
$cardStyle = array_key_exists($cardStyle, $cardTokens) ? $cardStyle : 'soft';
$buttonToken = $buttonTokens[$buttonStyle];
$cardToken = $cardTokens[$cardStyle];
$buttonPrimaryText = ($buttonToken['primary_bg'] === 'transparent') ? $primaryColor : '#ffffff';
$buttonSecondaryText = ($buttonToken['secondary_bg'] === 'transparent') ? $secondaryColor : '#ffffff';
$embeddedContent = [
    'theme' => $theme,
    'hero' => $hero,
    'sections' => $sections,
    'offers' => $offers,
    'testimonials' => $testimonials,
    'global' => $global,
];
$embeddedContentJson = htmlspecialchars(json_encode($embeddedContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$heroTitle = $hero['title'] ?? '';
$heroSubtitle = $hero['subtitle'] ?? '';

$schemaGraph = [
    [
        '@type' => 'Article',
        'headline' => $heroTitle,
        'description' => $heroSubtitle,
        'author' => [
            '@type' => 'Organization',
            'name' => 'Dakshayani Enterprises',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Dakshayani Enterprises',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => 'https://dakshayani.co.in/images/logo/New%20dakshayani%20logo%20centered%20small.png',
            ],
        ],
        'image' => 'https://dakshayani.co.in/images/og/dakshayani-hero.jpg',
        'datePublished' => '2024-11-01',
        'mainEntityOfPage' => 'https://dakshayani.co.in/',
    ],
    [
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'How quickly can Dakshayani file PM Surya Ghar subsidies?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'All documentation is pre-verified and filed within 21 days with MNRE/JREDA and the local DISCOM.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Do you offer real-time solar generation monitoring?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Customers receive dashboards with Solar API and inverter integrations, plus WhatsApp alerts for uptime.',
                ],
            ],
        ],
    ],
    [
        '@type' => 'Review',
        'itemReviewed' => [
            '@type' => 'LocalBusiness',
            'name' => 'Dakshayani Enterprises',
        ],
        'reviewRating' => [
            '@type' => 'Rating',
            'ratingValue' => '5',
            'bestRating' => '5',
        ],
        'author' => [
            '@type' => 'Person',
            'name' => 'Asha Verma',
        ],
        'reviewBody' => 'Seamless 8 kW rooftop installation with real-time monitoring and transparent subsidy support.',
        'datePublished' => '2024-10-02',
    ],
];

$schemaContext = [
    '@context' => 'https://schema.org',
    '@graph' => $schemaGraph,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dakshayani Enterprises | Solar EPC &amp; Subsidies in Jharkhand</title>
  <meta name="description" content="Ranchi’s trusted solar EPC provider. Get ₹78,000 PM Surya Ghar subsidies, rooftop solar, and zero bills. Contact us today!" />
  <meta name="keywords" content="Solar EPC Jharkhand, PM Surya Ghar subsidy, rooftop solar Ranchi, hybrid solar systems, Solar in Chhattisgarh, Solar in Odisha, Solar in UP" />
  <meta name="robots" content="index,follow" />
  <meta name="theme-color" content="#0f172a" />
  <link rel="canonical" href="https://dakshayani.co.in/" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="Dakshayani Enterprises | Solar EPC &amp; Subsidies in Jharkhand" />
  <meta property="og:description" content="Turnkey solar EPC, PM Surya Ghar subsidy support, and smart monitoring dashboards for homes, MSMEs, and farmers across Jharkhand." />
  <meta property="og:url" content="https://dakshayani.co.in/" />
  <meta property="og:image" content="https://dakshayani.co.in/images/og/dakshayani-hero.jpg" />
  <meta property="og:locale" content="en_IN" />
  <meta property="og:site_name" content="Dakshayani Enterprises" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="Dakshayani Enterprises | Rooftop Solar EPC" />
  <meta name="twitter:description" content="Trusted EPC partner delivering PM Surya Ghar subsidies, hybrid-ready systems, and real-time monitoring dashboards." />
  <meta name="twitter:image" content="https://dakshayani.co.in/images/og/dakshayani-hero.jpg" />

  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@700&amp;family=Poppins:wght@400;700;800;900&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root {
      --primary-main: <?= htmlspecialchars($primaryColor) ?>;
      --secondary-main: <?= htmlspecialchars($secondaryColor) ?>;
      --accent-blue: <?= htmlspecialchars($accentColor) ?>;
      --accent-strong: <?= htmlspecialchars($accentColor) ?>;
      --btn-radius: <?= htmlspecialchars($buttonToken['radius']) ?>;
      --btn-primary-shadow: <?= htmlspecialchars($buttonToken['primary_shadow']) ?>;
      --btn-primary-hover-shadow: <?= htmlspecialchars($buttonToken['primary_hover_shadow']) ?>;
      --btn-secondary-shadow: <?= htmlspecialchars($buttonToken['secondary_shadow']) ?>;
      --btn-primary-border: <?= htmlspecialchars($buttonToken['primary_border']) ?>;
      --btn-secondary-border: <?= htmlspecialchars($buttonToken['secondary_border']) ?>;
      --btn-primary-bg: <?= htmlspecialchars($buttonToken['primary_bg'] ?? $primaryColor) ?>;
      --btn-primary-hover-bg: <?= htmlspecialchars($buttonToken['primary_hover_bg'] ?? $accentColor) ?>;
      --btn-secondary-bg: <?= htmlspecialchars($buttonToken['secondary_bg'] ?? $secondaryColor) ?>;
      --btn-secondary-hover-bg: <?= htmlspecialchars($buttonToken['secondary_hover_bg'] ?? $secondaryColor) ?>;
      --btn-base-border: <?= htmlspecialchars($buttonToken['base_border'] ?? '1px solid transparent') ?>;
      --btn-primary-text: <?= htmlspecialchars($buttonPrimaryText) ?>;
      --btn-secondary-text: <?= htmlspecialchars($buttonSecondaryText) ?>;
      --card-shadow: <?= htmlspecialchars($cardToken['shadow']) ?>;
      --card-border: <?= htmlspecialchars($cardToken['border']) ?>;
      --card-surface: <?= htmlspecialchars($cardToken['surface']) ?>;
      --radius-card: <?= htmlspecialchars($cardToken['radius']) ?>;
    }
  </style>
  <script>
    window.DakshayaniThemeTokens = {
      buttons: <?= json_encode($buttonTokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      cards: <?= json_encode($cardTokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    };
  </script>
  <script id="site-settings-json" type="application/json"><?= $embeddedContentJson ?></script>
  <script>
    window.DAKSHAYANI_RECAPTCHA_SITE_KEY = window.DAKSHAYANI_RECAPTCHA_SITE_KEY || 'replace-with-site-key';
    window.DAKSHAYANI_GOOGLE_CLIENT_ID = window.DAKSHAYANI_GOOGLE_CLIENT_ID || 'replace-with-google-client-id.apps.googleusercontent.com';
  </script>
  <script type="application/ld+json">
    <?= json_encode($schemaContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
  </script>
</head>
<body>
  <header class="site-header"></header>

  <?php
  $siteTagline = trim((string) ($global['site_tagline'] ?? ''));
  $headerCallout = trim((string) ($global['header_callout'] ?? ''));
  $showSubheader = $siteTagline !== '' || $headerCallout !== '';
  ?>

  <?php if ($showSubheader): ?>
    <div class="site-subheader" aria-label="Site announcements">
      <div class="container site-subheader-stack">
        <div class="subheader-marquee" data-subheader-marquee>
          <div class="subheader-marquee__inner" data-marquee-inner>
            <div class="subheader-marquee__track" data-marquee-track>
              <span class="nav-theme-badge nav-tagline" data-site-tagline <?= $siteTagline === '' ? 'hidden' : '' ?>>
                <?= htmlspecialchars($siteTagline) ?>
              </span>
              <div class="nav-theme-badge header-callout" role="status" data-header-callout <?= $headerCallout === '' ? 'hidden' : '' ?>>
                <?= htmlspecialchars($headerCallout) ?>
              </div>
            </div>
            <div class="subheader-marquee__track" data-marquee-track-clone aria-hidden="true"></div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <main>
    <section id="hero" class="hero section" data-hero-section hidden>
      <div class="container hero-grid">
        <div class="hero-content">
          <div class="hero-announcement" data-hero-announcement hidden>
            <span class="badge" data-hero-announcement-badge></span>
            <p data-hero-announcement-text></p>
          </div>
          <h1 class="hero-title" data-hero-title></h1>
          <p class="hero-sub" data-hero-subtitle></p>

          <div class="hero-actions">
            <a href="#" class="btn btn-primary" data-hero-primary>
              <i class="fa-solid fa-calendar-check"></i>
              <span data-hero-primary-text></span>
            </a>
            <a href="#" class="btn btn-secondary" data-hero-secondary hidden>
              <i class="fa-solid fa-handshake-angle"></i>
              <span data-hero-secondary-text></span>
            </a>
          </div>

          <div class="hero-assurance">
            <div class="assurance-item">
              <span class="assurance-value">500+</span>
              <span class="assurance-label">Homes &amp; MSMEs energised</span>
            </div>
            <div class="assurance-item">
              <span class="assurance-value">₹78k</span>
              <span class="assurance-label">PM Surya Ghar subsidy managed</span>
            </div>
            <div class="assurance-item">
              <span class="assurance-value">98%</span>
              <span class="assurance-label">Net-metering success rate</span>
            </div>
          </div>
        </div>

        <div class="hero-media">
          <figure class="hero-media-main" data-hero-main>
            <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="" loading="lazy" data-hero-main-image />
            <figcaption data-hero-main-caption></figcaption>
          </figure>
          <div class="hero-mini-gallery" data-hero-gallery></div>
        </div>
      </div>
      <div class="hero-trust">
        <span><i class="fa-solid fa-shield-heart"></i>MNRE empanelled EPC partner</span>
        <img src="images/logo/New dakshayani logo centered small.png" alt="Dakshayani Enterprises" loading="lazy" />
        <span><i class="fa-solid fa-solar-panel"></i>Tier-1 modules &amp; hybrid inverters</span>
      </div>
    </section>

    <div data-home-sections hidden>
      <div data-home-sections-list></div>
    </div>

    <section class="section offers-section" id="offers">
      <div class="container">
        <div class="head">
          <h2 data-offers-title><?= htmlspecialchars($sections['seasonal_offer_title'] ?? '') ?></h2>
          <p class="sub" data-offers-subtitle><?= htmlspecialchars($sections['seasonal_offer_text'] ?? '') ?></p>
        </div>
        <div class="offers-grid" data-offers-list></div>
        <p class="site-search-empty" data-offers-empty hidden>Seasonal offers will be published here shortly.</p>
      </div>
    </section>

    <section class="section capability-showcase">
      <div class="container">
        <div class="head">
          <h2>Strategic solutions at a glance</h2>
          <p>Explore the ventures that make Dakshayani Enterprises Jharkhand's comprehensive EPC and technology leader.</p>
        </div>
        <div class="capability-grid">
          <a class="capability-card" href="govt-epc.html">
            <span class="capability-kicker">Govt. EPC &amp; Infrastructure</span>
            <h3>Building Jharkhand's Future: Govt. Contracts &amp; DMFT Projects.</h3>
            <p>Turnkey civil, DWSD, and renewable works with DMFT-compliant reporting.</p>
            <span class="capability-link">Learn more <i class="fa-solid fa-arrow-right"></i></span>
          </a>
          <a class="capability-card" href="e-mobility.html">
            <span class="capability-kicker">E-Mobility &amp; Charging</span>
            <h3>Future-Proof Energy: EV Charging Design &amp; Integration.</h3>
            <p>Consultancy and EPC readiness for solar-synchronised AC and DC charging.</p>
            <span class="capability-link">Plan a charging hub <i class="fa-solid fa-arrow-right"></i></span>
          </a>
          <a class="capability-card" href="innovation-tech.html">
            <span class="capability-kicker">Innovation &amp; Technology Ventures</span>
            <h3>Engineering Innovation: Robotics &amp; Advanced Concepts.</h3>
            <p>Techvan Power lab driving robotics, 3D-printed materials, and climate engineering.</p>
            <span class="capability-link">See innovation tracks <i class="fa-solid fa-arrow-right"></i></span>
          </a>
          <a class="capability-card capability-card--accent" href="rewards.html">
            <span class="capability-kicker">Channel Network</span>
            <h3>Partner with Us: India's Most Rewarding Solar Channel Program.</h3>
            <p>Apply as an advocate, installer, or EPC ally and access transparent payouts.</p>
            <span class="capability-link">Apply to partner <i class="fa-solid fa-arrow-right"></i></span>
          </a>
        </div>
      </div>
    </section>

    <section class="section trust-band">
      <div class="container">
        <div class="trust-grid">
          <figure class="trust-card">
            <img src="images/pmsgy.jpg" alt="PM Surya Ghar programme" loading="lazy" />
            <figcaption>Govt. empanelled for PM Surya Ghar</figcaption>
          </figure>
          <figure class="trust-card">
            <img src="images/large solar small.jpg" alt="Large-scale solar commissioning by Dakshayani" loading="lazy" />
            <figcaption>10+ MW installed across Jharkhand</figcaption>
          </figure>
          <figure class="trust-card">
            <img src="images/dedicatedgrounops.jpg" alt="Operations team at Dakshayani office" loading="lazy" />
            <figcaption>Dedicated on-ground ops &amp; service</figcaption>
          </figure>
          <figure class="trust-card">
            <img src="images/collage.jpg" alt="Collage of Dakshayani solar projects" loading="lazy" />
            <figcaption>Integrated design, EPC &amp; maintenance</figcaption>
          </figure>
        </div>
      </div>
    </section>

    <section id="installs" class="section installs" data-installs-section>
      <div class="container">
        <div class="head">
          <h2>Newest Solar Installs Across Jharkhand</h2>
        </div>

        <div class="install-slider" data-install-slider>
          <div class="slides-viewport" data-slides-viewport>
            <div class="slides-wrapper" data-slides-wrapper>
              <?php
              $installDir = __DIR__ . '/images/all sites pics';
              $installWebPath = '/images/all sites pics/';
              $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
              $installImages = [];

                  if (is_dir($installDir)) {
                  $files = array_diff(scandir($installDir), ['.', '..']);

                  foreach ($files as $file) {
                      $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                      if (in_array($extension, $allowedExtensions, true)) {
                          $installImages[] = $file;
                      }
                  }

                  // Sort desc to show newest first if named by date, or random
                  // rsort($installImages); 
                  
                  // Limit the number of images to 20 for performance
                  if (count($installImages) > 20) {
                      $installImages = array_slice($installImages, 0, 20);
                  }
              }

              $loopImages = array_merge($installImages, $installImages);
              ?>

              <?php foreach ($loopImages as $imageName): ?>
                <div class="slide">
                  <img src="<?= htmlspecialchars($installWebPath . rawurlencode($imageName)) ?>" alt="Solar Installation" loading="lazy" />
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="solutions" class="section alt solutions">
      <div class="container">
        <div class="head">
          <h2>Complete Solar Solutions Under One Roof</h2>
          <p>We handle everything in-house—from subsidy paperwork and structural design to smart monitoring and lifetime maintenance.</p>
        </div>
        <div class="solutions-grid">
          <article class="solution-card">
            <div class="solution-body">
              <div class="solution-heading">
                <div class="solution-icon"><i class="fa-solid fa-house-signal"></i></div>
                <div>
                  <p class="solution-tag">Rooftop Comfort</p>
                  <h3>Residential Rooftop Systems</h3>
                </div>
              </div>
              <p>Grid-synced, PM Surya Ghar compliant systems with ironclad subsidy filing, net-metering readiness, and real-time app dashboards for every family member.</p>
              <ul>
                <li><i class="fa-solid fa-solar-panel"></i> 3–10 kW mono-PERC arrays sized for duplexes and apartments</li>
                <li><i class="fa-solid fa-clipboard-check"></i> End-to-end paperwork, DISCOM coordination, and safety checks handled internally</li>
                <li><i class="fa-solid fa-plug-circle-bolt"></i> Hybrid upgrades, battery pairing, and EV-ready connections</li>
              </ul>
            </div>
          </article>
          <article class="solution-card">
            <div class="solution-body">
              <div class="solution-heading">
                <div class="solution-icon"><i class="fa-solid fa-industry"></i></div>
                <div>
                  <p class="solution-tag">MSME to Megawatt</p>
                  <h3>Commercial &amp; Industrial EPC</h3>
                </div>
              </div>
              <p>High-uptime plants engineered for factories, warehouses, hospitals, and campuses with clear ROI modeling and continuous SCADA visibility.</p>
              <ul>
                <li><i class="fa-solid fa-ruler-combined"></i> HT structure design, shading studies, and load analysis aligned to production schedules</li>
                <li><i class="fa-solid fa-chart-line"></i> PPA-ready proposals with payback simulations and LCOE benchmarks</li>
                <li><i class="fa-solid fa-user-shield"></i> Safety-first execution by certified engineers with QA/commissioning reports</li>
              </ul>
            </div>
          </article>
          <article class="solution-card">
            <div class="solution-body">
              <div class="solution-heading">
                <div class="solution-icon"><i class="fa-solid fa-seedling"></i></div>
                <div>
                  <p class="solution-tag">Rural Dependability</p>
                  <h3>Petrol Pumps, Agriculture &amp; Rural Energy</h3>
                </div>
              </div>
              <p>Resilient systems for petrol pumps, irrigation, cold storage, and village institutions—built to keep essentials running with minimal intervention.</p>
              <ul>
                <li><i class="fa-solid fa-water"></i> Solar irrigation, drip automation, and pump controllers tuned for local water tables</li>
                <li><i class="fa-solid fa-satellite-dish"></i> Remote diagnostics with SMS/WhatsApp alerts for uptime and fuel savings</li>
                <li><i class="fa-solid fa-people-group"></i> On-site training for operators and livelihood groups to maintain reliability</li>
              </ul>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section id="journey" class="section journey">
      <div class="container">
        <div class="head">
          <h2>Your Simple Solar Journey with Dakshayani Enterprises</h2>
          <p>We manage the complexity so you enjoy the savings. Our structured, foolproof system ensures transparency.</p>
        </div>
        <div class="journey-flow">
          <div class="journey-step">
            <div class="step-icon">1</div>
            <h3 class="text-xl">Consultation &amp; Quote</h3>
            <p>Free site survey, load analysis, and custom system design.</p>
          </div>
          <div class="journey-step">
            <div class="step-icon">2</div>
            <h3 class="text-xl">Subsidy &amp; Financing</h3>
            <p>Full paperwork for PM Surya Ghar and low-interest bank loans.</p>
          </div>
          <div class="journey-step">
            <div class="step-icon">3</div>
            <h3 class="text-xl">Certified Installation</h3>
            <p>Certified crew, Tier-1 components, and focus on safety/earthing.</p>
          </div>
          <div class="journey-step">
            <div class="step-icon">4</div>
            <h3 class="text-xl">Power On &amp; ROI</h3>
            <p>Net-metering, real-time monitoring, and system handover.</p>
          </div>
        </div>
      </div>
    </section>

    <section id="impact" class="section alt impact-section">
      <div class="container impact-layout">
        <div class="impact-summary">
          <h2>Our Impact &amp; Expertise in Jharkhand</h2>
          <p>Quantifiable results and deep local knowledge build trust. We are JREDA/DISCOM authorized.</p>
          <ul class="impact-list">
            <li><i class="fa-solid fa-check"></i>Expanding service to Chhattisgarh, Odisha &amp; UP</li>
            <li><i class="fa-solid fa-check"></i>Dedicated subsidy desk tracking every milestone</li>
            <li><i class="fa-solid fa-check"></i>Preventive maintenance and 24-hour support helpline</li>
          </ul>
        </div>
        <div class="impact-stats">
          <div class="card text-center">
            <div class="icon-large"><i class="fa-solid fa-house-chimney-crack"></i></div>
            <p class="stat-value">500+</p>
            <p class="stat-subtitle">Successful Residential Projects</p>
          </div>
          <div class="card text-center">
            <div class="icon-large"><i class="fa-solid fa-warehouse"></i></div>
            <p class="stat-value">10+ MW</p>
            <p class="stat-subtitle">Total Installed Capacity</p>
          </div>
          <div class="card text-center">
            <div class="icon-large"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <p class="stat-value">98%</p>
            <p class="stat-subtitle">Guaranteed Power Output &amp; ROI</p>
          </div>
          <div class="card text-center">
            <div class="icon-large"><i class="fa-solid fa-solar-panel"></i></div>
            <p class="stat-value">Tier-1</p>
            <p class="stat-subtitle">Adani, UTL, Premier Energies</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section media-section">
      <div class="container">
        <div class="head">
          <h2>See Our Teams in Action</h2>
          <p>From structure assembly to final commissioning, here’s a glimpse of our on-ground excellence.</p>
        </div>
        <div class="media-grid">
          <iframe src="https://www.youtube.com/embed/ybAm15bn6ok?si=YTYTgY12qS6dVHzB" title="Installation Video 1" allowfullscreen loading="lazy"></iframe>
          <iframe src="https://www.youtube.com/embed/xAOc5eP1mkA?si=3tk0Ks8Qzfcm1pap" title="Installation Video 2" allowfullscreen loading="lazy"></iframe>
          <iframe src="https://www.youtube.com/embed/cPiLFBfKR94?si=Kc1XF-OCwJKBx6Ye" title="Installation Video 3" allowfullscreen loading="lazy"></iframe>
        </div>
      </div>
    </section>

    <section id="projects" class="section testimonials">
      <div class="container">
        <div class="head">
          <h2 data-testimonial-title><?= htmlspecialchars($sections['what_our_customers_say_title'] ?? '') ?></h2>
          <p data-testimonial-subtitle><?= htmlspecialchars($sections['what_our_customers_say_subtitle'] ?? '') ?></p>
        </div>
        <div class="testimonial-grid" data-testimonial-list></div>
        <p class="site-search-empty" data-testimonial-empty hidden>Testimonials will appear here soon.</p>
        <div class="text-center mt-8">
          <a href="solar-projects.html" class="btn btn-primary">
            View More Projects &amp; Testimonials <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>
      </div>
    </section>

    <section class="section alt faq-section" id="faqs">
      <div class="container">
        <div class="head">
          <h2>Frequently asked questions</h2>
          <p class="sub">Clear answers on subsidies, compliance, and metering keep your project moving smoothly.</p>
        </div>
        <div class="faq-list">
          <details open>
            <summary>How does the PM Surya Ghar subsidy process work with Dakshayani?</summary>
            <div class="faq-body">
              <p>Our subsidy cell raises your application on the PM Surya Ghar portal within 24 hours of signing. We coordinate site photographs, DISCOM feasibility, and documentation uploads. Once inspection is complete, the subsidy is credited directly to your bank account while we reconcile the balance with transparent invoices.</p>
              <ul>
                <li>Dedicated case manager for every household or MSME</li>
                <li>Weekly status updates via WhatsApp and email</li>
                <li>Support with Aadhaar e-sign, net-metering forms, and bank coordination</li>
              </ul>
            </div>
          </details>
          <details>
            <summary>What is the typical timeline for net-metering approvals in Jharkhand?</summary>
            <div class="faq-body">
              <p>For LT rooftop systems, approvals arrive within 21 working days on average. HT and commercial connections may take 30–45 days depending on load sanction. Our engineering desk submits drawings, follows up with the DISCOM office, and schedules meter installation so you can start exporting power without delays.</p>
              <ul>
                <li>Pre-filled DISCOM forms and load details submitted by our team</li>
                <li>Joint inspection with DISCOM engineers for faster closure</li>
                <li>Commissioning report shared with you and stored in our customer portal</li>
              </ul>
            </div>
          </details>
        </div>
      </div>
    </section>

    <section id="contact-form" class="section contact-section">
      <div class="head container">
        <h2>Your Hassle-Free Solar Future Starts Here</h2>
        <p>Talk to Vishesh Vardhan’s team about your PM Surya Ghar subsidy slot.</p>
      </div>

      <div class="container grid cols-2">
        <div class="contact-card">
          <h3 class="text-2xl font-bold">Direct Contact</h3>
          <p class="text-lg mt-4">
            <i class="fa-solid fa-phone"></i>
            <a href="tel:+917070278178">+91 70702 78178</a>
          </p>
          <a href="https://wa.me/917070278178" target="_blank" class="btn btn-secondary mt-4">
            <i class="fab fa-whatsapp"></i>
            WhatsApp Chat Now
          </a>
          <p class="text-sm mt-6">Official Scheme Link: <a href="https://pmsuryaghar.gov.in" target="_blank" rel="noopener">pmsuryaghar.gov.in</a></p>
        </div>

        <div class="form-card">
          <h3 class="text-2xl font-bold">Get Your Free Solar Consultation</h3>
          <form id="homepage-lead-form" class="mt-4" novalidate>
            <div class="form-group">
              <label class="form-label" for="lead-name">Full Name</label>
              <input type="text" id="lead-name" name="name" class="form-control" placeholder="Full Name" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="lead-phone">Phone Number</label>
              <input type="tel" id="lead-phone" name="phone" class="form-control" placeholder="Phone Number" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="lead-city">City</label>
              <input type="text" id="lead-city" name="city" class="form-control" placeholder="City (e.g., Ranchi, Bokaro, Raipur)" required />
            </div>
            <div class="form-group">
              <label class="form-label" for="lead-type">Project Type</label>
              <select id="lead-type" name="projectType" class="form-control" required>
                <option value="">Select Project Type</option>
                <option value="Residential">Residential (PM Surya Ghar)</option>
                <option value="Commercial">Commercial / Industrial</option>
                <option value="General Inquiry">General Inquiry</option>
              </select>
            </div>
            <input type="hidden" name="leadSource" value="Website Homepage" />
            <div id="homepage-lead-form-alert" class="form-alert" role="status" aria-live="polite"></div>
            <button type="submit" class="btn btn-primary btn-block mt-4">
              <i class="fa-solid fa-arrow-right"></i>
              Send Inquiry
            </button>
          </form>
        </div>
      </div>
    </section>

    <section class="section cta-strip" data-cta-strip hidden>
      <div class="container cta-wrapper">
        <div class="cta-text">
          <h2 data-cta-strip-title></h2>
          <p data-cta-strip-text></p>
        </div>
        <a href="#" class="btn btn-secondary" data-cta-strip-button hidden>
          <i class="fa-solid fa-paper-plane"></i>
          <span data-cta-strip-button-text></span>
        </a>
      </div>
    </section>
  </main>

  <footer class="site-footer"></footer>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const viewport = document.querySelector('[data-slides-viewport]');
      const wrapper = document.querySelector('[data-slides-wrapper]');

      if (!viewport || !wrapper || !wrapper.children.length) {
        return;
      }

      const slides = Array.from(wrapper.querySelectorAll('.slide'));
      let slideSetWidth = 0;
      let isDragging = false;
      let startX = 0;
      let startScrollLeft = 0;
      let animationId = null;

      const calculateWidths = () => {
        // We have duplicated images, so half width is one set.
        slideSetWidth = wrapper.scrollWidth / 2;
      };

      // Reset to start of first set if at 0, or seamless loop
      const checkInfiniteLoop = () => {
        if (slideSetWidth <= 0) return;
        
        // If we scrolled past the end of the first set (showing start of second set), reset to start
        // Actually, usually we show set1 then set2.
        // To loop infinitely scrolling right (camera moves right), when we reach end of set1, we jump back to 0.
        // wrapper width = 2 * slideSetWidth.
        
        if (viewport.scrollLeft >= slideSetWidth) {
           viewport.scrollLeft -= slideSetWidth; 
        } else if (viewport.scrollLeft <= 0) {
           viewport.scrollLeft += slideSetWidth;
        }
      };

      const updateOnLoad = () => {
        calculateWidths();
      };

      slides.forEach((slide) => {
        const image = slide.querySelector('img');
        if (image && !image.complete) {
          image.addEventListener('load', updateOnLoad, { once: true });
        }
      });

      // Initial calculation
      calculateWidths();

      const autoScroll = () => {
        if (!isDragging && slideSetWidth > 0) {
          // Scroll right (camera moves right, items move left)
          viewport.scrollLeft += 0.8; 
          checkInfiniteLoop();
        }
        animationId = requestAnimationFrame(autoScroll);
      };

      // Start looping
      animationId = requestAnimationFrame(autoScroll);

      viewport.addEventListener('pointerdown', (event) => {
        isDragging = true;
        startX = event.clientX;
        startScrollLeft = viewport.scrollLeft;
        viewport.setPointerCapture(event.pointerId);
        cancelAnimationFrame(animationId); // Pause on drag
      });

      viewport.addEventListener('pointermove', (event) => {
        if (!isDragging) return;
        const deltaX = startX - event.clientX; // Drag left (positive delta) -> scroll right
        viewport.scrollLeft = startScrollLeft + deltaX;
        checkInfiniteLoop();
      });

      ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
        viewport.addEventListener(eventName, (event) => {
          if (!isDragging) return;
          isDragging = false;
          if (viewport.hasPointerCapture(event.pointerId)) {
            viewport.releasePointerCapture(event.pointerId);
          }
          // Resume scrolling
          cancelAnimationFrame(animationId);
          animationId = requestAnimationFrame(autoScroll);
        });
      });

      window.addEventListener('resize', () => {
        calculateWidths();
        checkInfiniteLoop();
      });
    });
  </script>

  <script src="script.js" defer></script>
  <script src="site-content.js" defer></script>
</body>
</html>
