<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$defaults = documents_quote_defaults_settings();
$settings = documents_quote_normalize_important_points_settings($defaults['important_points'] ?? []);
assert($settings['enabled'] === true);
assert(count(documents_quote_active_important_points($settings)) === 3);

$raw = [
    'enabled' => true,
    'title' => '<script>Important</script>',
    'intro' => "Line one\nLine two",
    'points' => [
        ['id' => 'b', 'text' => '<b>Second</b>', 'active' => false, 'sort_order' => 20],
        ['id' => 'a', 'text' => "First\npoint", 'active' => true, 'sort_order' => 10],
        ['id' => 'empty', 'text' => '', 'active' => true, 'sort_order' => 30],
    ],
];
$normalized = documents_quote_normalize_important_points_settings($raw);
assert(count($normalized['points']) === 2);
assert($normalized['points'][0]['id'] === 'a');
assert(count(documents_quote_active_important_points($normalized)) === 1);
$html = documents_quote_render_important_points($normalized);
assert(strpos($html, '&lt;b&gt;Second&lt;/b&gt;') === false);
assert(strpos($html, 'First<br />\npoint') !== false || strpos($html, 'First<br>') !== false);
assert(strpos($html, '<script>') === false);

$quote = documents_quote_defaults();
$quote = documents_quote_ensure_important_points_snapshot($quote, ['important_points' => $normalized]);
assert(!empty($quote['important_points_snapshot']));
$resolved = documents_quote_resolve_important_points($quote, $defaults);
assert($resolved['points'][0]['id'] === 'a');

echo "important points tests passed\n";
