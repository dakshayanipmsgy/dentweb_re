<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/quotation_view_renderer.php';

$path = documents_templates_dir() . '/template_sets.json';
$before = is_file($path) ? file_get_contents($path) : null;
register_shutdown_function(static function () use ($path, $before): void {
    if ($before === null) @unlink($path); else file_put_contents($path, $before);
});
documents_ensure_structure();
json_save($path, documents_template_starters());

$fail = static function (string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); };
$templates = documents_list_quotation_templates(false);
$bySegment = [];
foreach ($templates as $template) $bySegment[(string)$template['segment']] = $template;
foreach (array_keys(documents_quotation_segments()) as $segment) if (!isset($bySegment[$segment])) $fail("missing canonical $segment template");

$res = documents_quote_defaults();
$res['segment'] = 'RES';
$res['template_set_id'] = (string)$bySegment['RES']['id'];
if (empty(documents_quote_template_compatibility($res)['ok'])) $fail('RES template rejected');

$commercial = $res;
$commercial['segment'] = 'COM';
$commercial['quote_no'] = 'COM-SEGMENT-TEST';
$commercial['customer_name'] = 'Commercial Customer';
$commercial['annexures_overrides']['pm_subsidy_info'] = 'PM Surya Ghar residential subsidy wording';
$mismatch = documents_quote_template_compatibility($commercial);
if (($mismatch['code'] ?? '') !== 'segment_mismatch') $fail('legacy mismatch did not fail safe');
$blocked = quotation_render_to_html($commercial, documents_quote_defaults_settings(), documents_get_company_profile_for_quotes(), false, '', 'public', '');
if (!str_contains($blocked, 'Quotation unavailable') || str_contains($blocked, 'PM Surya Ghar')) $fail('public mismatch leaked quotation content');

$commercial['template_set_id'] = (string)$bySegment['COM']['id'];
$profile = documents_quote_segment_render_profile($commercial);
if (empty($profile['compatible']) || !empty($profile['allow_subsidy'])) $fail('commercial render profile is not segment safe');
$html = quotation_render_to_html($commercial, documents_quote_defaults_settings(), documents_get_company_profile_for_quotes(), false, '', 'admin', 'test');
foreach (['PM Surya Ghar', 'PM Subsidy Information', 'subsidy to loan', 'subsidy self kept'] as $forbidden) {
    if (stripos($html, $forbidden) !== false) $fail("commercial render leaked: $forbidden");
}

$archived = $templates;
foreach ($archived as &$template) if ((string)$template['id'] === (string)$commercial['template_set_id']) $template['archived_flag'] = true;
unset($template);
json_save($path, $archived);
if (($c = documents_quote_template_compatibility($commercial))['code'] !== 'archived_template') $fail('archived template was accepted');

fwrite(STDOUT, "PASS: canonical segments, template compatibility, fail-safe rendering, and commercial content isolation verified\n");
