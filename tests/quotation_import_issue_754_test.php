<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/solar_finance_reports.php';
require_once __DIR__ . '/../includes/quotation_import_service.php';

$headers = documents_quote_import_template_headers();
$defs = array_filter(documents_quote_import_reference_rows(), static fn($r) => ($r['reference_type'] ?? '') === 'field_definition');
$defNames = array_column($defs, 'field_name');
foreach ($headers as $h) {
    if (!in_array($h, $defNames, true)) { fwrite(STDERR, "FAIL: missing field definition for $h\n"); exit(1); }
    $reg = documents_quote_import_field_registry()[$h] ?? null;
    if (!$reg || (($reg['mapping'] ?? '') === '' && empty($reg['derived']))) { fwrite(STDERR, "FAIL: missing mapping/derived metadata for $h\n"); exit(1); }
}

$templatePath = documents_templates_dir() . '/template_sets.json';
$blockPath = documents_templates_dir() . '/template_blocks.json';
$origTemplatesJson = is_file($templatePath) ? (string) file_get_contents($templatePath) : null;
$origBlocksJson = is_file($blockPath) ? (string) file_get_contents($blockPath) : null;
$origTemplatesPerm = is_file($templatePath) ? (fileperms($templatePath) & 0777) : null;
$origBlocksPerm = is_file($blockPath) ? (fileperms($blockPath) & 0777) : null;
register_shutdown_function(static function () use ($templatePath, $blockPath, $origTemplatesJson, $origBlocksJson, $origTemplatesPerm, $origBlocksPerm): void {
    if ($origTemplatesJson !== null) { file_put_contents($templatePath, $origTemplatesJson); if ($origTemplatesPerm !== null) { chmod($templatePath, $origTemplatesPerm); } }
    if ($origBlocksJson !== null) { file_put_contents($blockPath, $origBlocksJson); if ($origBlocksPerm !== null) { chmod($blockPath, $origBlocksPerm); } }
});
$templates = documents_quote_import_templates_all();
$usable = null;
foreach ($templates as $tpl) { if (empty($tpl['archived_flag']) && trim((string)($tpl['segment'] ?? '')) !== '') { $usable = $tpl; break; } }
if (!$usable) { $usable = ['id'=>'issue_754_template','name'=>'Issue 754 Template','segment'=>'RES','archived_flag'=>false]; $templates[] = $usable; json_save(documents_templates_dir() . '/template_sets.json', $templates); }
$templateId = (string)$usable['id'];
$blocks = documents_sync_template_block_entries($templates);
$blocks[$templateId]['blocks']['cover_notes'] = '<p>Template cover</p>';
$blocks[$templateId]['blocks']['payment_terms'] = "Line A\nLine B";
json_save(documents_templates_dir() . '/template_blocks.json', $blocks);

$base = [
 'import_key'=>'ISSUE-754','customer_name'=>'Issue 754','customer_mobile'=>'9876543210','party_type'=>'lead','template_set'=>$templateId,'system_type'=>'Ongrid','selected_model_number'=>'ISSUE-754-MODEL','main_solar_kwp'=>'3','non_dcr_kwp'=>'0','quotation_date'=>'2026-07-13','valid_until'=>'2026-07-20','scenario_price_self_funded'=>'200000','scenario_price_loan_upto_2_lacs'=>'200000','scenario_price_loan_above_2_lacs'=>'200000'
];
$q = documents_build_quote_draft_from_input($base, ['dry_run'=>true]);
if (($q['template_set_id'] ?? '') !== $templateId || ($q['cover_notes_html_snapshot'] ?? '') !== '<p>Template cover</p>') { fwrite(STDERR, "FAIL: template annexures were not snapshotted canonically\n"); exit(1); }
$override = $base + ['ann_cover_notes'=>"CSV\nOverride", 'ann_payment_terms'=>'__CLEAR__'];
$q2 = documents_build_quote_draft_from_input($override, ['dry_run'=>true]);
if (($q2['annexures_overrides']['cover_notes'] ?? '') !== "CSV\nOverride") { fwrite(STDERR, "FAIL: multiline CSV override not preserved\n"); exit(1); }
if (($q2['annexures_overrides']['payment_terms'] ?? 'x') !== '') { fwrite(STDERR, "FAIL: explicit clear did not store empty override\n"); exit(1); }
$blocks[$templateId]['blocks']['cover_notes'] = '<p>Edited later</p>';
json_save(documents_templates_dir() . '/template_blocks.json', $blocks);
if (($q['cover_notes_html_snapshot'] ?? '') !== '<p>Template cover</p>') { fwrite(STDERR, "FAIL: old quotation snapshot mutated after template edit\n"); exit(1); }
foreach (['template_set','rate_chart_model','system_kit','tax_profile','enum_option','company_default','quotation_default','finance_default','energy_default'] as $type) {
    if (!array_filter(documents_quote_import_reference_rows(), static fn($r) => ($r['reference_type'] ?? '') === $type)) { fwrite(STDERR, "FAIL: reference rows missing $type\n"); exit(1); }
}
fwrite(STDOUT, "PASS: issue 754 import schema, annexure snapshot, overrides, and reference rows verified\n");
