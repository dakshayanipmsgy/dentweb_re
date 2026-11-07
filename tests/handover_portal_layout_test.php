<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/handover.php';
$body = '<div class="handover-shell"><section class="handover-section"><h2>Warranty</h2><p>Saved customer-specific terms &amp; serials.</p></section></div>';
$html = handover_wrap_document(['name' => 'Test <Customer>'], $body, '');
foreach (['A4 portrait', 'Print / Save PDF', '@media print', 'Saved customer-specific terms &amp; serials.', 'Test &lt;Customer&gt;'] as $text) {
    if (!str_contains($html, $text)) { throw new RuntimeException('Missing: ' . $text); }
}
$again = handover_wrap_document(['name' => 'Test'], handover_extract_body_content($html), '');
if (substr_count($again, 'aria-label="Handover document actions"') !== 1) { throw new RuntimeException('Toolbar duplicated on reopen'); }
if (!str_contains($again, $body)) { throw new RuntimeException('Saved document content changed'); }
echo "handover_portal_layout_test passed\n";

$legacy = '<style>body{color:black}</style><body></style></head><body class="handover-doc">' . $body . '</body></html>';
if (handover_extract_body_content($legacy) !== $body) { throw new RuntimeException('Legacy stray body tag included'); }
$legacyCss = '<html><head><style>.section-box{color:navy}</style></head><body>';
$normalized = handover_wrap_document(['name' => 'Test'], $body, $legacyCss);
if (substr_count($normalized, '<body') !== 1 || !str_contains($normalized, '.section-box{color:navy}')) { throw new RuntimeException('Legacy HTML CSS field not normalized'); }
