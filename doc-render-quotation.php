<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
start_session();
$u=$_SESSION['user']??[]; $isAdmin=(($u['role_name']??'')==='admin'); $isEmployee=!$isAdmin && (!empty($_SESSION['employee_logged_in']) || (($u['role_name']??'')==='employee')); if(!$isAdmin && !$isEmployee){header('Location: login.php'); exit;}
$employeeId=(string)($_SESSION['employee_id'] ?? ($u['id']??''));
function r_read(string $p,array $d): array { if(!is_file($p))return $d; $j=json_decode((string)file_get_contents($p),true); return is_array($j)?$j:$d; }
function r_write(string $p,array $d): bool { $dir=dirname($p); if(!is_dir($dir)){mkdir($dir,0775,true);} $e=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); return is_string($e)&&file_put_contents($p,$e."\n",LOCK_EX)!==false; }
function r_safe(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$id=(string)($_GET['id']??''); $save=!empty($_GET['save']);
$deals=r_read(__DIR__.'/data/docs/deals.json',['deals'=>[]])['deals']??[]; $quotes=r_read(__DIR__.'/data/docs/quotations.json',['quotations'=>[]]); $quote=null; $idx=null;
foreach(($quotes['quotations']??[]) as $i=>$q){ if((string)($q['id']??'')===$id){$quote=$q;$idx=$i;break;} }
if(!is_array($quote)){http_response_code(404); echo 'Not found'; exit;}
$deal=null; foreach($deals as $d){ if((string)($d['id']??'')===(string)($quote['deal_id']??'')){$deal=$d;break;} }
if(!$isAdmin){ $ok=is_array($deal) && (((string)($deal['assigned_to_employee_id']??'')===$employeeId)||((string)($deal['created_by_type']??'')==='employee' && (string)($deal['created_by_id']??'')===$employeeId)); if(!$ok){http_response_code(403); echo 'Access denied'; exit;} }
$company=r_read(__DIR__.'/data/docs/company_profile.json',[]); $templates=r_read(__DIR__.'/data/docs/template_sets.json',['template_sets'=>[]])['template_sets']??[]; $tpl=[]; foreach($templates as $t){ if((string)($t['id']??'')===(string)($quote['template_set_id']??'')){$tpl=$t;break;}}
$segment=(string)($quote['system']['segment']??'RES'); $isResidential=$segment==='RES';
ob_start();
?>
<!doctype html><html><head><meta charset="utf-8"><title><?=r_safe((string)($quote['quote_no']??'Quotation'))?></title><style>@page{size:A4;margin:14mm}body{font-family:Arial,sans-serif;color:#111}h1,h2,h3{margin:0 0 8px}.hdr{display:flex;justify-content:space-between;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:12px}.small{font-size:12px;color:#444}.tbl{width:100%;border-collapse:collapse;font-size:12px}.tbl th,.tbl td{border:1px solid #ddd;padding:6px;vertical-align:top}.page-break{page-break-before:always}.section{margin-bottom:12px}.footer{margin-top:12px;border-top:1px solid #ddd;padding-top:8px;font-size:12px}</style></head><body>
<?php if(!empty($tpl['cover']['enabled']) && !empty($tpl['cover']['cover_image_path'])): ?><div style="text-align:center"><img src="<?=r_safe((string)$tpl['cover']['cover_image_path'])?>" style="max-width:100%;max-height:70vh"><h1><?=r_safe((string)($tpl['cover']['title']??'Solar Power Proposal'))?></h1></div><div class="page-break"></div><?php endif; ?>
<div class="hdr"><div><?php if(!empty($company['logo_path'])):?><img src="<?=r_safe((string)$company['logo_path'])?>" style="height:52px"><?php endif; ?><h2><?=r_safe((string)($company['company_name']??'Dakshayani Enterprises'))?></h2><div class="small"><?=r_safe((string)($company['address_line']??''))?></div><div class="small">GSTIN: <?=r_safe((string)($company['gstin']??''))?></div></div><div><h3>Quotation</h3><div class="small">No: <?=r_safe((string)($quote['quote_no']??''))?></div><div class="small">Date: <?=r_safe((string)($quote['dates']['quote_date']??''))?></div><div class="small">Valid Until: <?=r_safe((string)($quote['dates']['valid_until']??''))?></div></div></div>
<div class="section"><strong>To:</strong> <?=r_safe((string)($quote['party_snapshot']['name']??''))?>, <?=r_safe((string)($quote['party_snapshot']['city']??''))?> (<?=r_safe((string)($quote['party_snapshot']['mobile']??''))?>)</div>
<?php if($isResidential): ?>
<div class="section"><h3>Residential Summary</h3><table class="tbl"><tr><th>System</th><td><?=r_safe((string)($quote['system']['system_type']??''))?></td><th>Capacity</th><td><?=r_safe((string)($quote['system']['capacity_kwp']??''))?> kWp</td></tr><tr><th>Final Amount</th><td colspan="3">₹<?=r_safe((string)($quote['pricing']['final_amount_incl_gst']??''))?> (Incl. GST)</td></tr></table></div>
<div class="section"><p><?=nl2br(r_safe((string)($quote['blocks_overrides']['intro_text']??'')))?></p></div>
<div class="page-break"></div><h3>Annexure</h3>
<?php else: ?><h3>Detailed Proposal</h3><?php endif; ?>
<div class="section"><table class="tbl"><thead><tr><th>Group</th><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Rate</th><th>Amount</th></tr></thead><tbody><?php foreach(($quote['line_items']??[]) as $li): ?><tr><td><?=r_safe((string)($li['group']??''))?></td><td><?=r_safe((string)($li['name']??''))?></td><td><?=r_safe((string)($li['description']??''))?></td><td><?=r_safe((string)($li['qty']??''))?></td><td><?=r_safe((string)($li['unit']??''))?></td><td><?=r_safe((string)($li['rate']??''))?></td><td><?=r_safe((string)($li['amount']??''))?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if(!empty($quote['show_gst_breakdown'])): ?><div class="section"><h3>Pricing Breakdown</h3><table class="tbl"><tr><th>Basic Amount</th><td>₹<?=r_safe((string)($quote['pricing']['basic_amount_total']??''))?></td><th>GST Total</th><td>₹<?=r_safe((string)($quote['pricing']['gst_total']??''))?></td></tr><tr><th>Tax Type</th><td><?=r_safe((string)($quote['pricing']['tax_type']??''))?></td><th>Final</th><td>₹<?=r_safe((string)($quote['pricing']['final_amount_incl_gst']??''))?></td></tr></table></div><?php endif; ?>
<?php foreach(($quote['blocks_overrides']??[]) as $k=>$v): if(trim((string)$v)==='') continue; ?><div class="section"><h3><?=r_safe(ucwords(str_replace('_',' ',(string)$k)))?></h3><p><?=nl2br(r_safe((string)$v))?></p></div><?php endforeach; ?>
<?php if(!empty($quote['media_refs']) && is_array($quote['media_refs'])): ?><div class="section"><h3>Media</h3><?php foreach($quote['media_refs'] as $m): ?><img src="<?=r_safe((string)$m)?>" style="max-width:48%;margin:1%" /><?php endforeach; ?></div><?php endif; ?>
<div class="footer">For <?=r_safe((string)($company['company_name']??''))?><?php if(!empty($company['signatory']['name'])):?> — <?=r_safe((string)$company['signatory']['name'])?><?php endif; ?></div>
</body></html>
<?php
$html=(string)ob_get_clean();
if($save){
    $fileBase=preg_replace('/[^A-Za-z0-9_\-]/','_', (string)($quote['quote_no']??$quote['id']));
    $dir=__DIR__.'/data/docs/generated/quotations'; if(!is_dir($dir)){mkdir($dir,0775,true);} $rel='data/docs/generated/quotations/'.$fileBase.'_rev'.(int)($quote['revision']??0).'.html';
    file_put_contents(__DIR__.'/'.$rel,$html,LOCK_EX);
    $quote['render']['html_path']=$rel; $quote['render']['generated_at']=date(DATE_ATOM); if($idx!==null){$quotes['quotations'][$idx]=$quote; r_write(__DIR__.'/data/docs/quotations.json',$quotes);} 
}
echo $html;
