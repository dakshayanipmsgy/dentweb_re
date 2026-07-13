<?php
declare(strict_types=1);

function documents_quote_import_batches_dir(): string { $dir = dirname(documents_quotations_dir()) . '/quotation_import_batches'; documents_ensure_dir($dir); return $dir; }
function documents_quote_import_batch_path(string $batchId): string { return documents_quote_import_batches_dir() . '/' . safe_filename($batchId) . '.json'; }
function documents_quote_import_now(): string { return date('c'); }
function documents_quote_import_admin_snapshot(): array { $u = current_user(); return ['id'=>(string)($u['id']??''),'name'=>(string)($u['name']??$u['username']??''),'role'=>(string)($u['role_name']??'')]; }
function documents_quote_import_required_columns(): array { return ['import_key','customer_name','customer_mobile','party_type','template_set','system_type','selected_model_number','main_solar_kwp','non_dcr_kwp','quotation_date','valid_until','scenario_price_self_funded','scenario_price_loan_upto_2_lacs','scenario_price_loan_above_2_lacs']; }
function documents_quote_import_optional_columns(): array { return ['consumer_account_no','billing_address','site_address','district','city','state','pin','meter_number','meter_serial_number','area_or_locality','sanctioned_load_kw','phase','average_monthly_units','shadow_free_area_sqft','monthly_bill_rs','unit_rate_rs_per_kwh','annual_generation_per_kw','subsidy_amount','transportation_amount','discount_amount','special_requests_text','email','gstin','pan','loan_upto_2_lacs_margin_money_rs','loan_upto_2_lacs_loan_amount_rs','loan_upto_2_lacs_interest_pct','loan_upto_2_lacs_tenure_years','loan_above_2_lacs_margin_money_rs','loan_above_2_lacs_loan_amount_rs','loan_above_2_lacs_interest_pct','loan_above_2_lacs_tenure_years']; }
function documents_quote_import_template_headers(): array { return array_merge(documents_quote_import_required_columns(), documents_quote_import_optional_columns()); }
function documents_quote_import_safe_csv_cell($value): string { $v=(string)$value; return preg_match('/^[=+\-@]/',$v) ? "'".$v : $v; }
function documents_quote_import_row_hash(array $row): string { ksort($row); return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
function documents_quote_import_normalize_mobile(string $mobile): string { return documents_normalize_whatsapp_mobile($mobile) ?: preg_replace('/\D+/', '', $mobile); }
function documents_quote_import_batches(): array { $rows=[]; foreach (glob(documents_quote_import_batches_dir().'/*.json') ?: [] as $file) { $r=json_load($file,[]); if(is_array($r)) $rows[]=$r; } usort($rows, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??''))); return $rows; }
function documents_quote_import_load_batch(string $batchId): ?array { $p=documents_quote_import_batch_path($batchId); if(!is_file($p)) return null; $r=json_load($p,[]); return is_array($r)?$r:null; }
function documents_quote_import_save_batch(array $batch): array { $id=safe_filename((string)($batch['batch_id']??'')); if($id==='') return ['ok'=>false,'error'=>'Missing batch ID']; return json_save(documents_quote_import_batch_path($id), $batch); }
function documents_quote_import_existing_identities(): array { $keys=[];$hashes=[];$likely=[]; foreach (documents_list_quotes() as $q) { $m=is_array($q['import_metadata']??null)?$q['import_metadata']:[]; if((string)($m['import_key']??'')!=='') $keys[(string)$m['import_key']] = (string)($q['id']??''); if((string)($m['row_hash']??'')!=='') $hashes[(string)$m['row_hash']] = (string)($q['id']??''); $mob=documents_quote_import_normalize_mobile((string)($q['customer_mobile']??'')); $date=(string)($q['quotation_date']??''); $model=(string)($q['selected_model_number']??''); $amt=(string)round((float)($q['input_total_gst_inclusive']??$q['calc']['grand_total']??0),2); if($mob!==''&&$date!==''&&$model!==''&&$amt!=='0') $likely[$mob.'|'.$date.'|'.$model.'|'.$amt]=(string)($q['id']??''); } return ['keys'=>$keys,'hashes'=>$hashes,'likely'=>$likely]; }

function documents_validate_quote_draft_input(array $input, array $context = []): array {
    $messages=[]; $warnings=[]; $required=documents_quote_import_required_columns();
    foreach($required as $col){ if(trim((string)($input[$col]??''))==='') $messages[]=['field'=>$col,'message'=>'Required field is missing.']; }
    foreach(['quotation_date','valid_until'] as $d){ $v=(string)($input[$d]??''); if($v!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) $messages[]=['field'=>$d,'message'=>'Use ISO date YYYY-MM-DD.']; }
    $main=(float)($input['main_solar_kwp']??0); $non=(float)($input['non_dcr_kwp']??0); if($main<0||$non<0||($main+$non)<=0) $messages[]=['field'=>'main_solar_kwp','message'=>'Total capacity must be greater than zero.'];
    $mobile=documents_quote_import_normalize_mobile((string)($input['customer_mobile']??'')); if($mobile==='') $messages[]=['field'=>'customer_mobile','message'=>'Valid mobile is required.'];
    $hash=documents_quote_import_row_hash($input); $ids=documents_quote_import_existing_identities(); $key=(string)($input['import_key']??'');
    if($key!=='' && isset($ids['keys'][$key])) $messages[]=['field'=>'import_key','message'=>'Import key already committed in quotation '.$ids['keys'][$key].'.'];
    if(isset($ids['hashes'][$hash])) $messages[]=['field'=>'row_hash','message'=>'This exact row was already committed in quotation '.$ids['hashes'][$hash].'.'];
    $likelyKey=$mobile.'|'.(string)($input['quotation_date']??'').'|'.(string)($input['selected_model_number']??'').'|'.(string)round((float)($input['scenario_price_self_funded']??0),2); if(isset($ids['likely'][$likelyKey])) $warnings[]=['field'=>'duplicate','message'=>'Likely duplicate of quotation '.$ids['likely'][$likelyKey].'.'];
    return ['ok'=>$messages===[],'status'=>$messages? 'Error' : ($warnings?'Warning':'Valid'),'messages'=>array_merge($messages,$warnings),'errors'=>$messages,'warnings'=>$warnings,'row_hash'=>$hash,'normalized_mobile'=>$mobile];
}

function documents_build_quote_draft_from_input(array $input, array $context = []): array {
    $q=documents_quote_defaults(); $now=documents_quote_import_now(); $admin=documents_quote_import_admin_snapshot();
    $q['id']='quote_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)); $q['quote_no']=''; $q['status']='Draft'; $q['created_at']=$now; $q['updated_at']=$now; $q['created_by_type']='admin'; $q['created_by_id']=$admin['id']; $q['created_by_name']=$admin['name'];
    foreach(['party_type','customer_name','customer_mobile','consumer_account_no','billing_address','site_address','district','city','state','pin','meter_number','meter_serial_number','quotation_date','valid_until','system_type','selected_model_number','special_requests_text'] as $f) if(array_key_exists($f,$input)) $q[$f]=(string)$input[$f];
    $q['customer_mobile']=documents_quote_import_normalize_mobile((string)$q['customer_mobile']); $q['main_solar_kwp']=(float)($input['main_solar_kwp']??0); $q['non_dcr_kwp']=(float)($input['non_dcr_kwp']??0); $q['capacity_kwp']=$q['main_solar_kwp']+$q['non_dcr_kwp']; $q['input_total_gst_inclusive']=(float)($input['scenario_price_self_funded']??0);
    $q['finance_scenarios']=['self_funded'=>['price_rs'=>(float)($input['scenario_price_self_funded']??0)],'loan_upto_2_lacs'=>['price_rs'=>(float)($input['scenario_price_loan_upto_2_lacs']??0)],'loan_above_2_lacs'=>['price_rs'=>(float)($input['scenario_price_loan_above_2_lacs']??0)]];
    $q['calc']=['grand_total'=>$q['input_total_gst_inclusive']]; $q['quote_items']=[['id'=>'imported_system','item_type'=>'system','name'=>'Imported solar system '.$q['selected_model_number'],'qty'=>1,'unit'=>'Set','capacity_kwp'=>$q['capacity_kwp'],'price'=>$q['input_total_gst_inclusive'],'amount'=>$q['input_total_gst_inclusive']]];
    $q['items']=[['description'=>'Imported solar system '.$q['selected_model_number'],'qty'=>1,'unit'=>'Set','rate'=>$q['input_total_gst_inclusive'],'amount'=>$q['input_total_gst_inclusive']]];
    $q['public_share_enabled']=false; $q['locked_flag']=false; $q['accepted_at']=''; $q['approval']=['approved_by_id'=>'','approved_by_name'=>'','approved_at'=>''];
    return $q;
}

function documents_commit_quote_draft(array $validated, array $context = []): array {
    if(empty($validated['ok']) && !in_array((string)($validated['status']??''), ['Valid','Warning'], true)) return ['ok'=>false,'error'=>'Row is invalid.'];
    $input=is_array($validated['input']??null)?$validated['input']:$validated; $quote=documents_build_quote_draft_from_input($input,$context); $seg=(string)($quote['segment']??'RES'); $num=documents_generate_quote_number($seg); if(empty($num['ok'])) return ['ok'=>false,'error'=>(string)($num['error']??'Numbering failed')]; $quote['quote_no']=(string)$num['quote_no'];
    $quote['import_metadata']=['batch_id'=>(string)($context['batch_id']??''),'import_key'=>(string)($input['import_key']??''),'source_row'=>(int)($context['source_row']??0),'source_filename'=>safe_filename((string)($context['source_filename']??'')),'row_hash'=>(string)($validated['row_hash']??documents_quote_import_row_hash($input)),'importing_admin'=>documents_quote_import_admin_snapshot(),'imported_at'=>documents_quote_import_now()];
    $hashQuote=$quote; unset($hashQuote['import_metadata']['post_import_hash']); $quote['import_metadata']['post_import_hash']=hash('sha256', json_encode($hashQuote, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); $saved=documents_save_quote($quote); return !empty($saved['ok']) ? ['ok'=>true,'quote'=>$quote,'quote_id'=>$quote['id']] : ['ok'=>false,'error'=>(string)($saved['error']??'Save failed')];
}
