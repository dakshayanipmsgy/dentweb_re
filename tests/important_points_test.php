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
assert(strpos($html, 'First<br />') !== false || strpos($html, 'First<br>') !== false);
assert(strpos($html, '<script>') === false);

$quote = documents_quote_defaults();
$legacy = documents_quote_ensure_important_points_snapshot($quote, ['important_points' => $normalized]);
assert(!empty($legacy['important_points_snapshot']));

// Draft, approved, and publicly shared quotations ignore snapshots made before acceptance.
$latest = documents_quote_normalize_important_points_settings([
    'enabled' => true,
    'title' => 'Latest title',
    'intro' => 'Latest intro',
    'points' => [
        ['id' => 'edited', 'text' => 'Edited text', 'active' => true, 'sort_order' => 20],
        ['id' => 'new', 'text' => 'New point', 'active' => true, 'sort_order' => 10],
        ['id' => 'inactive', 'text' => 'Hidden point', 'active' => false, 'sort_order' => 5],
    ],
]);
foreach (['draft', 'approved'] as $status) {
    $unaccepted = $legacy;
    $unaccepted['status'] = $status;
    $unaccepted['public_share_enabled'] = true;
    $unaccepted['public_share_token'] = 'shared';
    $resolved = documents_quote_resolve_important_points($unaccepted, ['important_points' => $latest]);
    assert($resolved['title'] === 'Latest title');
    assert(array_column(documents_quote_active_important_points($resolved), 'id') === ['new', 'edited']);
}

// Acceptance replaces a stale legacy snapshot with what is live at that moment.
$accepted = documents_quote_capture_important_points_snapshot($legacy, ['important_points' => $latest]);
$accepted['status'] = 'accepted';
$accepted['customer_acceptance']['accepted_important_points'] = $accepted['important_points_snapshot'];
$accepted['customer_acceptance']['accepted_important_points_hash'] = hash('sha256', json_encode($accepted['important_points_snapshot'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$acceptedSnapshot = $accepted['customer_acceptance']['accepted_important_points'];
$acceptedHash = $accepted['customer_acceptance']['accepted_important_points_hash'];

$later = $latest;
$later['title'] = 'Changed after acceptance';
$later['points'][] = ['id' => 'later', 'text' => 'Must not enter history', 'active' => true, 'sort_order' => 30];
$resolved = documents_quote_resolve_important_points($accepted, ['important_points' => $later]);
assert($resolved === $acceptedSnapshot);
assert($accepted['customer_acceptance']['accepted_important_points'] === $acceptedSnapshot);
assert($accepted['customer_acceptance']['accepted_important_points_hash'] === $acceptedHash);
assert(hash('sha256', json_encode($resolved, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) === $acceptedHash);

echo "important points tests passed\n";
