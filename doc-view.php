<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
start_session();
$u=$_SESSION['user']??[]; $isAdmin=(($u['role_name']??'')==='admin'); $isEmployee=!$isAdmin && (!empty($_SESSION['employee_logged_in']) || (($u['role_name']??'')==='employee')); if(!$isAdmin && !$isEmployee){header('Location: login.php'); exit;}
$employeeId=(string)($_SESSION['employee_id'] ?? ($u['id']??''));
function v_read(string $p,array $d): array { if(!is_file($p))return $d; $j=json_decode((string)file_get_contents($p),true); return is_array($j)?$j:$d; }
function v_safe(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$type=(string)($_GET['type']??''); $id=(string)($_GET['id']??'');
if($type!=='quotation'){http_response_code(400); echo 'Unsupported type'; exit;}
$quotes=v_read(__DIR__.'/data/docs/quotations.json',['quotations'=>[]])['quotations']??[]; $deals=v_read(__DIR__.'/data/docs/deals.json',['deals'=>[]])['deals']??[];
$quote=null; foreach($quotes as $q){ if((string)($q['id']??'')===$id){$quote=$q;break;}}
if(!is_array($quote)){http_response_code(404); echo 'Not found'; exit;}
$deal=null; foreach($deals as $d){ if((string)($d['id']??'')===(string)($quote['deal_id']??'')){$deal=$d;break;}}
if(!$isAdmin){ $ok=is_array($deal) && (((string)($deal['assigned_to_employee_id']??'')===$employeeId)||((string)($deal['created_by_type']??'')==='employee' && (string)($deal['created_by_id']??'')===$employeeId)); if(!$ok){http_response_code(403); echo 'Access denied'; exit;} }
$htmlPath=(string)($quote['render']['html_path']??''); $pdfPath=(string)($quote['render']['pdf_path']??'');
if(isset($_GET['download']) && $_GET['download']==='pdf' && $pdfPath!==''){ $abs=__DIR__.'/'.$pdfPath; if(is_file($abs)){ header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.basename($abs).'"'); readfile($abs); exit; } }
?>
<!doctype html><html><head><meta charset="utf-8"><title>Document View</title><style>body{font-family:Arial;background:#f5f7fb}.wrap{max-width:1100px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:12px;margin-bottom:12px}</style></head><body><div class="wrap">
<h1>Quotation Viewer</h1><p><a href="<?= $isAdmin?'admin-docs-deals.php':'employee-docs-deals.php' ?>">&larr; Back</a></p>
<div class="card"><strong><?=v_safe((string)($quote['quote_no']??''))?></strong> | Status: <?=v_safe((string)($quote['status']??''))?> | Revision: <?=v_safe((string)($quote['revision']??0))?><br>Audit: created <?=v_safe((string)($quote['audit']['created_at']??''))?>, updated <?=v_safe((string)($quote['audit']['updated_at']??''))?></div>
<div class="card"><a href="doc-render-quotation.php?id=<?=urlencode($id)?>" target="_blank">View HTML</a> | <a href="doc-pdf-quotation.php?id=<?=urlencode($id)?>">Generate PDF</a> | <?php if($pdfPath!==''):?><a href="doc-view.php?type=quotation&id=<?=urlencode($id)?>&download=pdf">Download PDF</a><?php else:?>PDF not generated<?php endif;?></div>
<?php if($htmlPath!=='' && is_file(__DIR__.'/'.$htmlPath)): ?><div class="card"><?=(string)file_get_contents(__DIR__.'/'.$htmlPath)?></div><?php endif; ?>
</div></body></html>
