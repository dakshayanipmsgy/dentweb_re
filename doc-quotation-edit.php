<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';

start_session();
$user=$_SESSION['user']??[];
$isAdmin=(($user['role_name']??'')==='admin');
$isEmployee=!$isAdmin && (!empty($_SESSION['employee_logged_in']) || (($user['role_name']??'')==='employee'));
if(!$isAdmin && !$isEmployee){ header('Location: login.php'); exit; }
$employeeId=(string)($_SESSION['employee_id'] ?? ($user['id']??''));

function q_safe(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function q_now(): string { return date(DATE_ATOM); }
function q_read(string $p,array $d): array { if(!is_file($p)) return $d; $j=json_decode((string)file_get_contents($p),true); return is_array($j)?$j:$d; }
function q_write(string $p,array $d): bool { $dir=dirname($p); if(!is_dir($dir)){mkdir($dir,0775,true);} $enc=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); return is_string($enc)&&file_put_contents($p,$enc."\n",LOCK_EX)!==false; }
function q_deals(): array { $d=q_read(__DIR__.'/data/docs/deals.json',['deals'=>[]]); return is_array($d['deals']??null)?$d['deals']:[]; }
function q_quotations(): array { $d=q_read(__DIR__.'/data/docs/quotations.json',['quotations'=>[]]); return is_array($d['quotations']??null)?$d['quotations']:[]; }
function q_save_quotations(array $q): bool { return q_write(__DIR__.'/data/docs/quotations.json',['quotations'=>array_values($q)]); }
function q_templates(): array { $d=q_read(__DIR__.'/data/docs/template_sets.json',['template_sets'=>[]]); return is_array($d['template_sets']??null)?$d['template_sets']:[]; }
function q_company(): array { return q_read(__DIR__.'/data/docs/company_profile.json',[]); }
function q_default_quote(): array { return ['id'=>'','deal_id'=>'','quote_no'=>'','revision'=>0,'status'=>'Draft','template_set_id'=>'','party_snapshot'=>[],'system'=>['segment'=>'RES','system_type'=>'ongrid','capacity_kwp'=>'','site_address'=>'','district'=>'','notes'=>''],'pricing'=>['input_mode'=>'FINAL_INCL_GST','gst_mode'=>'SPLIT_70_30','place_of_supply_state'=>'Jharkhand','tax_type'=>'CGST_SGST','final_amount_incl_gst'=>'','basic_amount_total'=>'','gst_total'=>'','split'=>['basic_5'=>'','gst_5_total'=>'','basic_18'=>'','gst_18_total'=>'','cgst_5'=>'','sgst_5'=>'','igst_5'=>'','cgst_18'=>'','sgst_18'=>'','igst_18'=>'']],'line_items'=>[],'blocks_overrides'=>['intro_text'=>'','scope_of_work'=>'','inclusions'=>'','warranty'=>'','payment_terms'=>'','validity_text'=>'','subsidy_info_block'=>'','system_type_explainer_block'=>'','transportation_charges_block'=>'','terms_conditions'=>''],'dates'=>['quote_date'=>date('Y-m-d'),'valid_until'=>date('Y-m-d', strtotime('+15 days'))],'render'=>['html_path'=>'','pdf_path'=>'','generated_at'=>''],'media_refs'=>[],'show_gst_breakdown'=>true,'audit'=>['created_by_type'=>'','created_by_id'=>'','created_at'=>'','updated_at'=>'']]; }
function q_generate_id(): string { return 'qtn_'.bin2hex(random_bytes(6)); }
function q_determine_tax_type(string $place,string $company='Jharkhand'): string { return strcasecmp(trim($place),trim($company))===0?'CGST_SGST':'IGST'; }
function q_round(float $v): float { return round($v,2); }
function q_split_gst_from_final(float $final,string $gstMode,string $taxType): array {
    $split=['basic_5'=>0.0,'gst_5_total'=>0.0,'basic_18'=>0.0,'gst_18_total'=>0.0,'cgst_5'=>0.0,'sgst_5'=>0.0,'igst_5'=>0.0,'cgst_18'=>0.0,'sgst_18'=>0.0,'igst_18'=>0.0];
    $basic=0.0;$gst=0.0;
    if($gstMode==='FLAT_5'){
        $basic=$final/1.05; $gst=$final-$basic; $split['basic_5']=$basic; $split['gst_5_total']=$gst;
    } else {
        $effective=0.089; $basic=$final/(1+$effective); $basic5=$basic*0.7; $basic18=$basic*0.3; $gst5=$basic5*0.05; $gst18=$basic18*0.18; $gst=$gst5+$gst18;
        $split['basic_5']=$basic5; $split['basic_18']=$basic18; $split['gst_5_total']=$gst5; $split['gst_18_total']=$gst18;
    }
    if($taxType==='CGST_SGST'){
        $split['cgst_5']=$split['gst_5_total']/2; $split['sgst_5']=$split['gst_5_total']/2; $split['cgst_18']=$split['gst_18_total']/2; $split['sgst_18']=$split['gst_18_total']/2;
    } else {
        $split['igst_5']=$split['gst_5_total']; $split['igst_18']=$split['gst_18_total'];
    }
    foreach($split as $k=>$v){ $split[$k]=q_round((float)$v); }
    return ['basic_total'=>q_round($basic),'gst_total'=>q_round($gst),'split'=>$split];
}
function q_itemized_totals(array $items): array {
    $basic=0.0; $gst=0.0;
    foreach($items as $item){ $qty=(float)($item['qty']??0); $rate=(float)($item['rate']??0); $amt=$qty*$rate; $pct=(float)($item['gst_percent']??0); $basic+=$amt; $gst+=($amt*$pct/100); }
    return ['basic_total'=>q_round($basic),'gst_total'=>q_round($gst)];
}
function q_find_template(array $templates,string $id): ?array { foreach($templates as $t){ if((string)($t['id']??'')===$id) return $t; } return null; }
function q_allow_quote(array $deal,bool $isAdmin,string $empId): bool { if($isAdmin) return true; return (string)($deal['assigned_to_employee_id']??'')===$empId || ((string)($deal['created_by_type']??'')==='employee' && (string)($deal['created_by_id']??'')===$empId); }
function q_next_quote_no(string $segment): string {
    $numPath=__DIR__.'/data/docs/numbering.json'; $cntPath=__DIR__.'/data/docs/counters.json';
    $numbering=q_read($numPath,['doc_types'=>['quotation'=>['prefix'=>'DE/QTN','digits'=>4,'use_segment'=>true]],'financial_year_mode'=>'FY','fy_format'=>'YY-YY']);
    $counters=q_read($cntPath,['counters'=>[]]);
    $doc=$numbering['doc_types']['quotation']??['prefix'=>'DE/QTN','digits'=>4,'use_segment'=>true];
    $m=(int)date('n'); $y=(int)date('Y'); $start=$m>=4?$y:$y-1; $end=$start+1; $fy=substr((string)$start,-2).'-'.substr((string)$end,-2);
    $fyLabel=(string)($numbering['financial_year_mode']??'FY').$fy; $seg=!empty($doc['use_segment'])?strtoupper($segment):'_';
    $key='quotation|'.$fyLabel.'|'.$seg; $next=((int)($counters['counters'][$key]??0))+1; $counters['counters'][$key]=$next;
    q_write($cntPath,$counters);
    $digits=max(2,min(6,(int)($doc['digits']??4))); $num=str_pad((string)$next,$digits,'0',STR_PAD_LEFT);
    return trim((string)($doc['prefix']??'DE/QTN')).'/'.$seg.'/'.$fy.'/'.$num;
}

$deals=q_deals(); $quotes=q_quotations(); $templates=q_templates();
$dealId=(string)($_GET['deal_id']??''); $qid=(string)($_GET['id']??'');
$deal=null; foreach($deals as $d){ if((string)($d['id']??'')===$dealId){$deal=$d;break;} }
$quoteIndex=null; $quote=q_default_quote();
if($qid!==''){ foreach($quotes as $i=>$q){ if((string)($q['id']??'')===$qid){$quote=$q; $quoteIndex=$i; break; } } $dealId=(string)($quote['deal_id']??$dealId); }
if($deal===null){ foreach($deals as $d){ if((string)($d['id']??'')===$dealId){$deal=$d;break;} }}
if(!is_array($deal)){ http_response_code(404); echo 'Deal not found'; exit; }
if(!q_allow_quote($deal,$isAdmin,$employeeId)){ http_response_code(403); echo 'Access denied'; exit; }

$errors=[];$messages=[];
$locked=((string)($quote['status']??'Draft'))==='Locked';
$editable = $isAdmin || (!$locked && (string)($quote['status']??'Draft')==='Draft');

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf_token(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)) $errors[]='Invalid CSRF token.';
    else {
        $action=(string)($_POST['action']??'save');
        if($qid==='' && $action==='create_from_deal'){
            $quote=q_default_quote();
            $quote['id']=q_generate_id();
            $quote['deal_id']=(string)($deal['id']??'');
            $quote['quote_no']=q_next_quote_no((string)($deal['segment']??'RES'));
            $quote['template_set_id']=(string)($deal['template_set_id']??'');
            $quote['party_snapshot']=$deal['party']??[];
            $quote['system']['segment']=(string)($deal['segment']??'RES');
            $quote['system']['system_type']=(string)($deal['system_type']??'ongrid');
            $quote['system']['capacity_kwp']=(string)($deal['capacity_kwp']??'');
            $tpl=q_find_template($templates,(string)$quote['template_set_id']);
            if(is_array($tpl)){
                $quote['pricing']['gst_mode']=(string)($tpl['defaults']['gst_mode_default']??'SPLIT_70_30');
                $days=(int)($tpl['defaults']['quotation_valid_days']??15);
                $quote['dates']['valid_until']=date('Y-m-d', strtotime('+'.$days.' days'));
                foreach(($quote['blocks_overrides']??[]) as $k=>$v){ if(isset($tpl['blocks'][$k])) $quote['blocks_overrides'][$k]=(string)$tpl['blocks'][$k]; }
                if(($quote['system']['segment']??'')==='RES' && $quote['blocks_overrides']['intro_text']!==''){
                    $quote['blocks_overrides']['intro_text']=mb_substr((string)$quote['blocks_overrides']['intro_text'],0,220);
                }
            }
            $quote['audit']=['created_by_type'=>$isAdmin?'admin':'employee','created_by_id'=>$isAdmin?(string)($user['id']??'admin'):$employeeId,'created_at'=>q_now(),'updated_at'=>q_now()];
            $quotes[]=$quote; $quoteIndex=array_key_last($quotes); $qid=(string)$quote['id']; $messages[]='Quotation created.';
            q_save_quotations($quotes);
        } elseif($action==='create_revision' && $isAdmin && $qid!=='') {
            $new=$quote; $new['id']=q_generate_id(); $new['revision']=(int)($quote['revision']??0)+1; $new['status']='Draft'; $new['quote_no']=(string)($quote['quote_no']??'').'-R'.$new['revision']; $new['audit']['created_at']=q_now(); $new['audit']['updated_at']=q_now(); $new['audit']['created_by_type']='admin'; $new['audit']['created_by_id']=(string)($user['id']??'admin');
            $quotes[]=$new; q_save_quotations($quotes); header('Location: doc-quotation-edit.php?id='.urlencode((string)$new['id'])); exit;
        } else {
            if(!$editable && !$isAdmin){ $errors[]='You cannot edit this quotation.'; }
            else {
                $quote['party_snapshot']=['name'=>trim((string)($_POST['party_name']??'')),'mobile'=>trim((string)($_POST['party_mobile']??'')),'email'=>trim((string)($_POST['party_email']??'')),'address'=>trim((string)($_POST['party_address']??'')),'city'=>trim((string)($_POST['party_city']??'')),'district'=>trim((string)($_POST['party_district']??'')),'state'=>trim((string)($_POST['party_state']??'Jharkhand')),'pin_code'=>trim((string)($_POST['party_pin_code']??''))];
                $quote['template_set_id']=trim((string)($_POST['template_set_id']??''));
                $quote['system']['segment']=trim((string)($_POST['segment']??'RES')); $quote['system']['system_type']=trim((string)($_POST['system_type']??'ongrid')); $quote['system']['capacity_kwp']=trim((string)($_POST['capacity_kwp']??'')); $quote['system']['site_address']=trim((string)($_POST['site_address']??'')); $quote['system']['district']=trim((string)($_POST['site_district']??'')); $quote['system']['notes']=trim((string)($_POST['system_notes']??''));
                $quote['dates']['quote_date']=trim((string)($_POST['quote_date']??date('Y-m-d'))); $quote['dates']['valid_until']=trim((string)($_POST['valid_until']??date('Y-m-d')));
                $quote['pricing']['input_mode']=trim((string)($_POST['input_mode']??'FINAL_INCL_GST')); $quote['pricing']['gst_mode']=trim((string)($_POST['gst_mode']??'SPLIT_70_30')); $quote['pricing']['place_of_supply_state']=trim((string)($_POST['place_of_supply_state']??'Jharkhand'));
                $quote['pricing']['tax_type']=q_determine_tax_type((string)$quote['pricing']['place_of_supply_state']);
                $quote['pricing']['final_amount_incl_gst']=trim((string)($_POST['final_amount_incl_gst']??''));
                $quote['show_gst_breakdown']=!empty($_POST['show_gst_breakdown']);
                $lineItems=[]; $groups=$_POST['li_group']??[]; $names=$_POST['li_name']??[]; $descs=$_POST['li_desc']??[]; $units=$_POST['li_unit']??[]; $qtys=$_POST['li_qty']??[]; $rates=$_POST['li_rate']??[]; $gsts=$_POST['li_gst']??[]; $hsns=$_POST['li_hsn']??[];
                $rows=max(count($names),count($qtys));
                for($i=0;$i<$rows;$i++){
                    $name=trim((string)($names[$i]??'')); if($name===''){continue;}
                    $qty=(float)($qtys[$i]??1); $rate=(float)($rates[$i]??0); $amt=q_round($qty*$rate); $gstPct=(float)($gsts[$i]??0);
                    $lineItems[]=['group'=>trim((string)($groups[$i]??'Other')),'name'=>$name,'description'=>trim((string)($descs[$i]??'')),'unit'=>trim((string)($units[$i]??'Nos')),'qty'=>$qty,'rate'=>(string)$rate,'amount'=>(string)$amt,'gst_percent'=>$gstPct,'hsn_sac'=>trim((string)($hsns[$i]??''))];
                }
                $quote['line_items']=$lineItems;
                foreach(array_keys($quote['blocks_overrides']) as $bk){ $quote['blocks_overrides'][$bk]=trim((string)($_POST['block_'.$bk]??'')); }
                $mediaRefs=$_POST['media_refs']??[]; $quote['media_refs']=array_values(array_slice(array_filter(array_map('strval',is_array($mediaRefs)?$mediaRefs:[]), static fn(string $m): bool => $m!==''),0,3));
                $mode=(string)$quote['pricing']['input_mode'];
                if($mode==='ITEMIZED'){
                    $tot=q_itemized_totals($lineItems); $quote['pricing']['basic_amount_total']=(string)$tot['basic_total']; $quote['pricing']['gst_total']=(string)$tot['gst_total']; $quote['pricing']['final_amount_incl_gst']=(string)q_round($tot['basic_total']+$tot['gst_total']);
                } else {
                    $final=(float)($quote['pricing']['final_amount_incl_gst']?:0); $calc=q_split_gst_from_final($final,(string)$quote['pricing']['gst_mode'],(string)$quote['pricing']['tax_type']); $quote['pricing']['basic_amount_total']=(string)$calc['basic_total']; $quote['pricing']['gst_total']=(string)$calc['gst_total']; $quote['pricing']['split']=array_map('strval',$calc['split']);
                }
                if($action==='approve' && $isAdmin){ $quote['status']='Approved'; }
                if($action==='lock' && $isAdmin){ $quote['status']='Locked'; }
                $quote['audit']['updated_at']=q_now();
                if($quoteIndex===null){ $quoteIndex=count($quotes); $quotes[]=$quote; } else { $quotes[$quoteIndex]=$quote; }
                q_save_quotations($quotes);
                if($action==='generate_html'){ header('Location: doc-render-quotation.php?id='.urlencode((string)$quote['id']).'&save=1'); exit; }
                if($action==='generate_pdf'){ header('Location: doc-pdf-quotation.php?id='.urlencode((string)$quote['id'])); exit; }
                $messages[]='Quotation saved.';
            }
        }
    }
}

$mediaDir=__DIR__.'/images/docs/media'; $media=[]; if(is_dir($mediaDir)){ $files=glob($mediaDir.'/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}',GLOB_BRACE); if(is_array($files)){ foreach($files as $f){ $media[]='/images/docs/media/'.basename($f);} }}
if($qid===''){ // first load create button
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Quotation Editor</title><link rel="stylesheet" href="style.css"><style>body{background:#f5f7fb}.wrap{max-width:1200px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:12px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}label{display:block;font-size:13px}input,select,textarea{width:100%}table{width:100%;border-collapse:collapse}th,td{padding:6px;border-bottom:1px solid #eee}</style></head><body><div class="wrap">
<h1>Quotation Editor</h1><p><a href="<?= $isAdmin ? 'admin-docs-deals.php':'employee-docs-deals.php' ?>">&larr; Back</a></p>
<?php foreach($errors as $e):?><div style="background:#fee;padding:8px"><?=q_safe($e)?></div><?php endforeach;?><?php foreach($messages as $m):?><div style="background:#efe;padding:8px"><?=q_safe($m)?></div><?php endforeach;?>
<?php if($qid===''): ?><div class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?=q_safe($_SESSION['csrf_token']??'')?>"><input type="hidden" name="action" value="create_from_deal"><button>Create quotation from deal</button></form></div><?php endif; ?>
<?php if($qid!==''): ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=q_safe($_SESSION['csrf_token']??'')?>">
<div class="card"><h3>Header</h3><div class="grid"><label>Quote no<input value="<?=q_safe((string)($quote['quote_no']??''))?>" readonly></label><label>Revision<input value="<?=q_safe((string)($quote['revision']??0))?>" readonly></label><label>Status<input value="<?=q_safe((string)($quote['status']??''))?>" readonly></label><label>Template set<select name="template_set_id"><?php foreach($templates as $t):?><option value="<?=q_safe((string)($t['id']??''))?>" <?=((string)($t['id']??'')===(string)($quote['template_set_id']??''))?'selected':''?>><?=q_safe((string)($t['name']??''))?></option><?php endforeach;?></select></label>
<label>Party name<input name="party_name" value="<?=q_safe((string)($quote['party_snapshot']['name']??''))?>"></label><label>Mobile<input name="party_mobile" value="<?=q_safe((string)($quote['party_snapshot']['mobile']??''))?>"></label><label>Email<input name="party_email" value="<?=q_safe((string)($quote['party_snapshot']['email']??''))?>"></label><label>Address<input name="party_address" value="<?=q_safe((string)($quote['party_snapshot']['address']??''))?>"></label><label>City<input name="party_city" value="<?=q_safe((string)($quote['party_snapshot']['city']??''))?>"></label><label>District<input name="party_district" value="<?=q_safe((string)($quote['party_snapshot']['district']??''))?>"></label><label>State<input name="party_state" value="<?=q_safe((string)($quote['party_snapshot']['state']??'Jharkhand'))?>"></label><label>PIN<input name="party_pin_code" value="<?=q_safe((string)($quote['party_snapshot']['pin_code']??''))?>"></label>
<label>Segment<select name="segment"><?php foreach(['RES','COM','IND','INST','PROD'] as $s):?><option <?=($s===(string)($quote['system']['segment']??''))?'selected':''?>><?=$s?></option><?php endforeach;?></select></label><label>System type<select name="system_type"><?php foreach(['ongrid','hybrid','offgrid','product'] as $st):?><option <?=($st===(string)($quote['system']['system_type']??''))?'selected':''?>><?=$st?></option><?php endforeach;?></select></label><label>Capacity<input name="capacity_kwp" value="<?=q_safe((string)($quote['system']['capacity_kwp']??''))?>"></label><label>Quote date<input type="date" name="quote_date" value="<?=q_safe((string)($quote['dates']['quote_date']??''))?>"></label><label>Valid until<input type="date" name="valid_until" value="<?=q_safe((string)($quote['dates']['valid_until']??''))?>"></label></div></div>
<div class="card"><h3>Pricing</h3><div class="grid"><label>Input mode<select name="input_mode"><?php foreach(['FINAL_INCL_GST','ITEMIZED'] as $m):?><option <?=($m===(string)($quote['pricing']['input_mode']??''))?'selected':''?>><?=$m?></option><?php endforeach;?></select></label><label>GST mode<select name="gst_mode"><?php foreach(['SPLIT_70_30','FLAT_5','ITEMIZED'] as $g):?><option <?=($g===(string)($quote['pricing']['gst_mode']??''))?'selected':''?>><?=$g?></option><?php endforeach;?></select></label><label>Place of supply<input name="place_of_supply_state" value="<?=q_safe((string)($quote['pricing']['place_of_supply_state']??'Jharkhand'))?>"></label><label>Tax type<input value="<?=q_safe((string)($quote['pricing']['tax_type']??''))?>" readonly></label><label>Final incl GST<input name="final_amount_incl_gst" value="<?=q_safe((string)($quote['pricing']['final_amount_incl_gst']??''))?>"></label><label>Basic total<input value="<?=q_safe((string)($quote['pricing']['basic_amount_total']??''))?>" readonly></label><label>GST total<input value="<?=q_safe((string)($quote['pricing']['gst_total']??''))?>" readonly></label><label><input type="checkbox" name="show_gst_breakdown" value="1" <?=!empty($quote['show_gst_breakdown'])?'checked':''?>> Show GST breakdown</label></div></div>
<div class="card"><h3>Line items</h3><table><thead><tr><th>Group</th><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Rate</th><th>GST%</th><th>HSN</th></tr></thead><tbody><?php $items=$quote['line_items']??[]; for($i=0;$i<max(5,count($items));$i++): $it=$items[$i]??[]; ?><tr><td><select name="li_group[]"><?php foreach(['Modules','Inverter','Structure','BOS','Protection','Services','Other'] as $g):?><option <?=($g===(string)($it['group']??''))?'selected':''?>><?=$g?></option><?php endforeach;?></select></td><td><input name="li_name[]" value="<?=q_safe((string)($it['name']??''))?>"></td><td><input name="li_desc[]" value="<?=q_safe((string)($it['description']??''))?>"></td><td><input name="li_unit[]" value="<?=q_safe((string)($it['unit']??'Nos'))?>"></td><td><input name="li_qty[]" value="<?=q_safe((string)($it['qty']??1))?>"></td><td><input name="li_rate[]" value="<?=q_safe((string)($it['rate']??''))?>"></td><td><input name="li_gst[]" value="<?=q_safe((string)($it['gst_percent']??0))?>"></td><td><input name="li_hsn[]" value="<?=q_safe((string)($it['hsn_sac']??''))?>"></td></tr><?php endfor;?></tbody></table></div>
<div class="card"><h3>Blocks + Media</h3><div class="grid"><?php foreach(($quote['blocks_overrides']??[]) as $bk=>$bv):?><label><?=q_safe($bk)?><textarea name="block_<?=q_safe($bk)?>"><?=q_safe((string)$bv)?></textarea></label><?php endforeach;?><label>Site address<textarea name="site_address"><?=q_safe((string)($quote['system']['site_address']??''))?></textarea></label><label>Site district<input name="site_district" value="<?=q_safe((string)($quote['system']['district']??''))?>"></label><label>System notes<textarea name="system_notes"><?=q_safe((string)($quote['system']['notes']??''))?></textarea></label><label>Media refs (max 3)<select name="media_refs[]" multiple size="4"><?php foreach($media as $m):?><option value="<?=q_safe($m)?>" <?=in_array($m,$quote['media_refs']??[],true)?'selected':''?>><?=q_safe($m)?></option><?php endforeach;?></select></label></div></div>
<div class="card"><button name="action" value="save">Save Draft</button> <button name="action" value="generate_html">Generate HTML</button> <button name="action" value="generate_pdf">Generate PDF</button> <?php if($isAdmin):?><button name="action" value="approve">Approve</button><button name="action" value="lock">Lock</button><button name="action" value="create_revision">Create Revision</button><?php endif;?> <a href="doc-view.php?type=quotation&id=<?=urlencode((string)$quote['id'])?>">View</a></div>
</form>
<?php endif; ?>
</div></body></html>
