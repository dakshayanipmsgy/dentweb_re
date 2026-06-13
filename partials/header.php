<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$ws = website_settings();
$global = $ws['global'] ?? [];
?>
<header class="global-header" data-component="global-header">
  <div class="energy-topbar">
    <div class="container"><span>Jharkhand’s long-term energy services company</span><a href="tel:+917070278178"><i class="fa-solid fa-phone"></i> +91 70702 78178</a></div>
  </div>
  <div class="container header-inner">
    <a href="/index.php" class="brand" aria-label="Dakshayani Enterprises home">
      <img src="/images/logo/New dakshayani logo centered small.png" alt="Dakshayani Enterprises" class="brand-logo-em" />
      <span class="brand-text">Dakshayani <small>Energy Services</small></span>
    </a>
    <nav class="nav-desktop" aria-label="Primary navigation">
      <a href="/index.php" class="nav-link">Home</a>
      <div class="nav-dropdown">
        <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">Energy Solutions <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
        <div class="nav-dropdown-menu nav-mega-menu" role="menu">
          <a href="/pm-surya-ghar.html" class="nav-link" role="menuitem"><strong>Home Solar</strong><span>PM Surya Ghar process support</span></a>
          <a href="/commercial-industrial-solar.html" class="nav-link" role="menuitem"><strong>Commercial &amp; Industrial</strong><span>Solar EPC for institutions and businesses</span></a>
          <a href="/hybrid-solar-battery.html" class="nav-link" role="menuitem"><strong>Hybrid &amp; Battery</strong><span>Backup-ready energy systems</span></a>
          <a href="/ev-charging-solar.html" class="nav-link" role="menuitem"><strong>EV Charging</strong><span>Solar-ready charging infrastructure</span></a>
          <a href="/govt-epc.html" class="nav-link" role="menuitem"><strong>Government EPC</strong><span>Compliant project delivery</span></a>
        </div>
      </div>
      <div class="nav-dropdown">
        <button type="button" class="nav-link nav-dropdown-toggle" aria-haspopup="true" aria-expanded="false">Services &amp; Partners <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
        <div class="nav-dropdown-menu" role="menu">
          <a href="/energycare-amc.html" class="nav-link" role="menuitem">Dakshayani EnergyCare AMC/O&amp;M</a>
          <a href="/solar-material-supply.html" class="nav-link" role="menuitem">Solar Material Supply</a>
          <a href="/installer-partner-network.html" class="nav-link" role="menuitem">Installer / Partner Network</a>
          <a href="/solar-and-finance.php" class="nav-link" role="menuitem">Solar &amp; Finance</a>
        </div>
      </div>
      <a href="/about.html" class="nav-link">About</a>
      <a href="/knowledge-hub.html" class="nav-link">Knowledge</a>
    </nav>
    <div class="nav-actions" role="group" aria-label="Header quick actions">
      <a href="/contact.php" class="btn btn-primary nav-consult-link">Talk to an expert</a>
      <a href="/login.php" class="btn btn-secondary nav-login-link">Login</a>
    </div>
    <button type="button" class="menu-btn" aria-label="Open navigation menu" aria-controls="mobile-menu" aria-expanded="false" id="mobile-menu-button"><i class="fas fa-bars" aria-hidden="true"></i><span class="sr-only">Toggle navigation</span></button>
  </div>
  <nav id="mobile-menu" class="nav-mobile" aria-label="Mobile navigation">
    <div class="nav-mobile-header"><strong>Explore Dakshayani</strong><button type="button" class="nav-mobile-close" data-close-mobile aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="nav-mobile-section"><a href="/index.php">Home</a><a href="/about.html">About Us</a></div>
    <div class="nav-mobile-divider"></div>
    <div class="nav-mobile-section"><p class="nav-mobile-label">Energy solutions</p><a href="/pm-surya-ghar.html">Home Solar / PM Surya Ghar</a><a href="/commercial-industrial-solar.html">Commercial &amp; Industrial Solar</a><a href="/hybrid-solar-battery.html">Hybrid Solar &amp; Battery</a><a href="/ev-charging-solar.html">EV Charging</a><a href="/govt-epc.html">Government EPC</a></div>
    <div class="nav-mobile-divider"></div>
    <div class="nav-mobile-section"><p class="nav-mobile-label">Lifetime support</p><a href="/energycare-amc.html">EnergyCare AMC / O&amp;M</a><a href="/solar-material-supply.html">Solar Material Supply</a><a href="/installer-partner-network.html">Installer / Partner Network</a><a href="/knowledge-hub.html">Knowledge Hub</a><a href="/solar-and-finance.php">Solar &amp; Finance</a></div>
    <div class="nav-mobile-divider"></div>
    <div class="nav-mobile-section"><a href="/contact.php" class="btn btn-primary" data-close-mobile>Talk to an expert</a><a href="/login.php" class="btn btn-secondary" data-close-mobile>Login Portal</a></div>
  </nav>
</header>
