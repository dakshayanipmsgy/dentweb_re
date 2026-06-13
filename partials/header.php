<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$settings = website_settings();
$site = $settings['site'];
$items = array_values(array_filter($settings['navigation'], static fn(array $item): bool => $item['enabled'] && $item['label'] !== '' && $item['url'] !== ''));
$groups = [];
foreach ($items as $item) { $groups[$item['group']][] = $item; }
function nav_link(array $item, string $class = 'nav-link'): string {
    $target = $item['new_tab'] ? ' target="_blank" rel="noopener"' : '';
    return '<a class="' . $class . '" href="' . htmlspecialchars($item['url']) . '"' . $target . '>' . htmlspecialchars($item['label']) . '</a>';
}
?>
<header class="global-header" data-component="global-header">
  <div class="energy-topbar"><div class="container"><span><?= htmlspecialchars($site['service_areas']) ?></span><div><a href="tel:<?= preg_replace('/[^+0-9]/', '', $site['primary_phone']) ?>"><?= htmlspecialchars($site['primary_phone']) ?></a><a href="https://wa.me/<?= htmlspecialchars($site['whatsapp']) ?>">WhatsApp</a></div></div></div>
  <div class="container header-inner">
    <a href="/index.php" class="brand" aria-label="<?= htmlspecialchars($site['company_name']) ?> home"><img src="/images/logo/New dakshayani logo centered small.png" alt="" class="brand-logo-em"><span class="brand-text"><?= htmlspecialchars($site['company_name']) ?><small>Solar · Storage · EnergyCare</small></span></a>
    <nav class="nav-desktop" aria-label="Primary navigation">
      <?php foreach ($groups[''] ?? [] as $item): ?><?= nav_link($item) ?><?php endforeach; ?>
      <?php foreach ($groups as $name => $links): if ($name === '') continue; ?><div class="nav-dropdown"><button type="button" class="nav-link nav-dropdown-toggle" aria-expanded="false"><?= htmlspecialchars($name) ?> <span aria-hidden="true">⌄</span></button><div class="nav-dropdown-menu"><?php foreach ($links as $item): ?><?= nav_link($item) ?><?php endforeach; ?></div></div><?php endforeach; ?>
    </nav>
    <div class="nav-actions"><a href="/contact.php" class="btn btn-primary">Book Site Visit</a><a href="/login.php" class="btn btn-secondary">Login</a></div>
    <button type="button" class="menu-btn" aria-label="Open navigation menu" aria-controls="mobile-menu" aria-expanded="false" id="mobile-menu-button"><span aria-hidden="true">☰</span></button>
  </div>
  <nav id="mobile-menu" class="nav-mobile" aria-label="Mobile navigation" hidden><div class="nav-mobile-header"><strong>Explore Dakshayani</strong><button type="button" data-close-mobile aria-label="Close menu">×</button></div><?php foreach ($groups as $name => $links): ?><div class="nav-mobile-section"><?php if ($name): ?><p class="nav-mobile-label"><?= htmlspecialchars($name) ?></p><?php endif; ?><?php foreach ($links as $item): ?><?= nav_link($item, '') ?><?php endforeach; ?></div><?php endforeach; ?><div class="nav-mobile-section"><a class="btn btn-primary" href="/contact.php">Book Site Visit</a><a class="btn btn-secondary" href="/login.php">Login Portal</a></div></nav>
</header>
