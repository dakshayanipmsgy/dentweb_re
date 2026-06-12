<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$ws = website_settings();
$global = $ws['global'] ?? [];
?>
<link rel="stylesheet" href="/energycare-revamp.css">
<script defer src="/home-energycare.js"></script>
<header class="global-header" data-component="global-header">
  <div class="container header-inner">
    <a href="/index.php" class="brand" aria-label="Dakshayani Enterprises home">
      <img src="/images/logo/New dakshayani logo centered small.png" alt="Dakshayani Enterprises" class="brand-logo-em" />
      <span class="brand-text">Dakshayani Enterprises</span>
    </a>

    <nav class="nav-desktop" aria-label="Primary navigation">
      <a href="/index.php" class="nav-link">Home</a>
      <a href="/about.html" class="nav-link">About</a>
      <div class="nav-dropdown">
        <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
          Solar &amp; Energy
          <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="nav-dropdown-menu" role="menu">
          <a href="/pm-surya-ghar.html" class="nav-link" role="menuitem">PM Surya Ghar</a>
          <a href="/solar-projects.html" class="nav-link" role="menuitem">Solar EPC Projects</a>
          <a href="/energycare.html" class="nav-link" role="menuitem">EnergyCare AMC</a>
          <a href="/powerstore.html" class="nav-link" role="menuitem">PowerStore Materials</a>
          <a href="/e-mobility.html" class="nav-link" role="menuitem">EV Charging</a>
          <a href="/govt-epc.html" class="nav-link" role="menuitem">Govt. EPC</a>
        </div>
      </div>
      <div class="nav-dropdown">
        <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
          Growth Hub
          <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="nav-dropdown-menu" role="menu">
          <a href="/installer-network.html" class="nav-link" role="menuitem">Installer Network</a>
          <a href="/solar-and-finance.php" class="nav-link" role="menuitem">Solar &amp; Finance</a>
          <a href="/calculator.html" class="nav-link" role="menuitem">Solar Calculator</a>
          <a href="/blog/index.php" class="nav-link" role="menuitem">Blog &amp; Insights</a>
          <a href="/knowledge-hub.html" class="nav-link" role="menuitem">Knowledge Hub</a>
          <a href="/meera-gh2.html" class="nav-link" role="menuitem">Meera GH2</a>
          <a href="/policies.html" class="nav-link" role="menuitem">Policies</a>
        </div>
      </div>
    </nav>

    <div class="nav-actions" role="group" aria-label="Header quick actions">
      <a href="/contact.html" class="btn btn-primary nav-quote-link">Get Quote</a>
      <a href="/login.php" class="btn btn-secondary nav-login-link">Login Portal</a>
      <span class="nav-theme-badge" data-site-theme-label hidden></span>
    </div>

    <button
      type="button"
      class="menu-btn"
      aria-label="Open navigation menu"
      aria-controls="mobile-menu"
      aria-expanded="false"
      id="mobile-menu-button"
    >
      <i class="fas fa-bars" aria-hidden="true"></i>
      <span class="sr-only">Toggle navigation</span>
    </button>
  </div>

  <nav id="mobile-menu" class="nav-mobile" aria-label="Mobile navigation">
    <div class="nav-mobile-header">
      <button type="button" class="nav-mobile-close" data-close-mobile aria-label="Close menu">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div class="nav-mobile-section" aria-label="Primary pages">
      <a href="/index.php">Home</a>
      <a href="/about.html">About</a>
      <a href="/contact.html">Get Quote / Contact</a>
    </div>
    <div class="nav-mobile-divider" role="presentation"></div>
    <div class="nav-mobile-section" aria-label="Solar and energy services">
      <p class="nav-mobile-label">Solar &amp; Energy</p>
      <a href="/pm-surya-ghar.html">PM Surya Ghar</a>
      <a href="/solar-projects.html">Solar EPC Projects</a>
      <a href="/energycare.html">EnergyCare AMC</a>
      <a href="/powerstore.html">PowerStore Materials</a>
      <a href="/e-mobility.html">EV Charging</a>
      <a href="/govt-epc.html">Govt. EPC</a>
    </div>
    <div class="nav-mobile-divider" role="presentation"></div>
    <div class="nav-mobile-section" aria-label="Growth hub">
      <p class="nav-mobile-label">Growth Hub</p>
      <a href="/installer-network.html">Installer Network</a>
      <a href="/solar-and-finance.php">Solar &amp; Finance</a>
      <a href="/calculator.html">Solar Calculator</a>
      <a href="/blog/index.php">Blog &amp; Insights</a>
      <a href="/knowledge-hub.html">Knowledge Hub</a>
      <a href="/meera-gh2.html">Meera GH2</a>
      <a href="/policies.html">Policies</a>
    </div>
    <div class="nav-mobile-divider" role="presentation"></div>

    <div class="nav-mobile-section" aria-label="Quick actions">
      <a href="/login.php" class="btn btn-secondary" data-close-mobile>Login Portal</a>
      <p class="nav-mobile-theme" data-site-theme-label hidden></p>
    </div>
  </nav>
</header>

<div class="site-search-overlay" data-site-search hidden>
  <div class="site-search-backdrop" data-close-search></div>
  <div class="site-search-dialog" role="dialog" aria-modal="true" aria-labelledby="site-search-title">
    <form class="site-search-form" data-site-search-form>
      <div class="site-search-header">
        <h2 id="site-search-title">Search Dakshayani Knowledge Hub</h2>
        <button type="button" class="site-search-close" data-close-search aria-label="Close search">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div class="site-search-input">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" placeholder="Search blogs, FAQs, case studies…" aria-label="Search site content" required/>
        <select name="segment" aria-label="Filter by segment">
          <option value="">All segments</option>
          <option value="residential">Residential</option>
          <option value="commercial">Commercial</option>
          <option value="agriculture">Agriculture</option>
        </select>
      </div>
      <div class="site-search-results" data-site-search-results>
        <p class="site-search-empty">Type to explore Dakshayani insights, project learnings, and FAQs.</p>
      </div>
    </form>
  </div>
</div>
