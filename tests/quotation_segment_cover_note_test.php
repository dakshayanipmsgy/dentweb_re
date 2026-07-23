<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_view_renderer.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$defaults = documents_quote_defaults_settings();
$company = documents_get_company_profile_for_quotes();
$expected = [
    'RES' => ['A note for your home', 'Dear Homeowner'],
    'COM' => ['A note for your business', 'Dear Business Customer'],
    'IND' => ['A note for your facility', 'Dear Industrial Customer'],
    'INST' => ['A note for your organization', 'Dear Organization'],
    'PROD' => ['A note for you', 'Dear Customer'],
    'UNKNOWN' => ['A note for you', 'Dear Customer'],
];

foreach ($expected as $segment => [$kicker, $heading]) {
    $quote = documents_quote_defaults();
    $quote['segment'] = $segment;
    $quote['cover_notes_html_snapshot'] = '<p>Original cover body for the customer.</p>';
    $presentation = quotation_cover_note_presentation($quote, $defaults);
    $assert($presentation['kicker'] === $kicker && $presentation['heading'] === $heading, "{$segment} presentation");
    $html = quotation_render_to_html($quote, $defaults, $company, false, '', 'public', '');
    $assert(str_contains($html, $kicker) && str_contains($html, $heading), "{$segment} renderer headings");
    $assert(str_contains($html, 'Original cover body for the customer.'), "{$segment} body preserved");
    if ($segment !== 'RES') {
        $assert(!str_contains($html, 'A note for your home') && !str_contains($html, 'Dear Homeowner'), "{$segment} excludes residential labels");
    }
}

$legacy = documents_quote_defaults();
$legacy['segment'] = 'COM';
$legacy['cover_notes_html_snapshot'] = '<p>Accepted legacy content remains byte-for-byte.</p>';
$legacy['cover_note_presentation_snapshot'] = ['kicker'=>'A NOTE FOR YOUR HOME', 'heading'=>'Dear Homeowner'];
$legacy['status'] = 'accepted';
$legacy['accepted_at'] = '2026-01-01T00:00:00+00:00';
$legacy['calc'] = ['grand_total'=>234567.89, 'gross_payable'=>234567.89];
$before = $legacy;
foreach (['public', 'admin', 'print', 'browser-client-export', 'pdf-export'] as $viewer) {
    $html = quotation_render_to_html($legacy, $defaults, $company, false, '', $viewer, 'test');
    $assert(str_contains($html, 'Dear Business Customer'), "legacy {$viewer} normalized");
    $assert(!str_contains($html, 'Dear Homeowner') && str_contains($html, 'Accepted legacy content remains byte-for-byte.'), "legacy {$viewer} safe body");
}
$assert($legacy === $before, 'rendering does not mutate accepted quotation, pricing, or acceptance');

$custom = $legacy;
$custom['cover_note_kicker'] = 'A partnership note';
$custom['cover_note_heading'] = 'Dear Acme Team';
$customPresentation = quotation_cover_note_presentation($custom, $defaults);
$assert($customPresentation['kicker'] === 'A partnership note' && $customPresentation['heading'] === 'Dear Acme Team', 'custom commercial headings preserved');

$clone = documents_quote_reset_clone_state($legacy, 'new-revision');
$assert(($clone['cover_note_presentation_snapshot']['heading'] ?? '') === 'Dear Business Customer', 'revision/clone snapshot is segment-correct');
$assert(($clone['calc']['gross_payable'] ?? null) === 234567.89, 'clone presentation normalization does not alter pricing');

$bulk = file_get_contents(__DIR__ . '/../includes/quotation_bulk_actions.php');
$assert(is_string($bulk) && substr_count($bulk, 'quotation_render_to_html(') >= 3, 'print, combined print and browser PDF share renderer');

fwrite(STDOUT, "PASS: segment-aware quotation cover notes preserve content, pricing and acceptance across render/export paths\n");
