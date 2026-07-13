<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/solar_finance_reports.php';
require_once __DIR__ . '/../includes/quotation_import_service.php';
require_once __DIR__ . '/../includes/quotation_reference_export.php';

$out=fopen('php://temp','w+'); documents_quote_reference_stream_csv($out); rewind($out);
$headers=fgetcsv($out); $rows=[]; while(($r=fgetcsv($out))!==false){ $rows[]=array_combine($headers,$r); }
$fail=function($m){ fwrite(STDERR,"FAIL: $m\n"); exit(1); };
$fieldRows=array_values(array_filter($rows,fn($r)=>$r['record_type']==='field_definition'));
$names=array_map(fn($r)=>$r['field_name'],$fieldRows); sort($names); $expected=documents_quote_import_template_headers(); sort($expected);
if($names!==$expected) $fail('field_definition rows do not exactly match import headers');
$activeTemplates=array_filter((array)json_load(documents_templates_dir().'/template_sets.json',[]),fn($t)=>is_array($t)&&empty($t['archived_flag']));
$requiredTypes=['allowed_value','rate_chart','finance_scenario','place_of_supply_rule'];
if($activeTemplates) { $requiredTypes[]='template_set'; $requiredTypes[]='template_annexure'; }
foreach($requiredTypes as $type){ if(!array_filter($rows,fn($r)=>$r['record_type']===$type)) $fail("missing $type rows"); }
foreach(documents_quote_import_enum_values() as $field=>$values){ foreach(array_keys($values) as $v){ if(!array_filter($rows,fn($r)=>$r['record_type']==='allowed_value'&&$r['field_name']===$field&&$r['allowed_value']===(string)$v)) $fail("missing enum $field=$v"); } }
$sample=documents_quote_import_generate_sample_row(); if(!isset($sample['error'])) {
if(count($sample)!==count(documents_quote_import_template_headers())) $fail('sample/header count mismatch');
foreach(documents_quote_import_required_columns() as $h){ if(trim((string)($sample[$h]??''))==='') $fail("required sample field blank: $h"); }
$v=documents_validate_quote_draft_input($sample,['source'=>'csv']); if(empty($v['ok'])) $fail('sample row failed dry-run: '.json_encode($v['messages']));
if((int)($v['preview']['annexure_count']??-1)<0) $fail('missing annexure count in preview');
}
fwrite(STDOUT,"PASS: quotation reference export and sample template are valid\n");
