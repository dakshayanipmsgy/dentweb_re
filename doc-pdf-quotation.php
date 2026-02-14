<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';
start_session();
$u=$_SESSION['user']??[]; $isAdmin=(($u['role_name']??'')==='admin'); $isEmployee=!$isAdmin && (!empty($_SESSION['employee_logged_in']) || (($u['role_name']??'')==='employee')); if(!$isAdmin && !$isEmployee){header('Location: login.php'); exit;}
$employeeId=(string)($_SESSION['employee_id'] ?? ($u['id']??''));
function p_read(string $p,array $d): array { if(!is_file($p))return $d; $j=json_decode((string)file_get_contents($p),true); return is_array($j)?$j:$d; }
function p_write(string $p,array $d): bool { $enc=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); return is_string($enc)&&file_put_contents($p,$enc."\n",LOCK_EX)!==false; }
$id=(string)($_GET['id']??'');
$quotes=p_read(__DIR__.'/data/docs/quotations.json',['quotations'=>[]]); $deals=p_read(__DIR__.'/data/docs/deals.json',['deals'=>[]])['deals']??[];
$quote=null; $idx=null; foreach(($quotes['quotations']??[]) as $i=>$q){ if((string)($q['id']??'')===$id){$quote=$q;$idx=$i;break;}}
if(!is_array($quote)){http_response_code(404); echo 'Quotation not found'; exit;}
$deal=null; foreach($deals as $d){ if((string)($d['id']??'')===(string)($quote['deal_id']??'')){$deal=$d;break;} }
if(!$isAdmin){ $ok=is_array($deal) && (((string)($deal['assigned_to_employee_id']??'')===$employeeId)||((string)($deal['created_by_type']??'')==='employee' && (string)($deal['created_by_id']??'')===$employeeId)); if(!$ok){http_response_code(403); echo 'Access denied'; exit;} }
$_GET['save']='1'; $_GET['id']=$id; ob_start(); include __DIR__.'/doc-render-quotation.php'; $html=(string)ob_get_clean();
$fileBase=preg_replace('/[^A-Za-z0-9_\-]/','_', (string)($quote['quote_no']??$quote['id']));
$dir=__DIR__.'/data/docs/generated/quotations'; if(!is_dir($dir)){mkdir($dir,0775,true);} $htmlRel='data/docs/generated/quotations/'.$fileBase.'_rev'.(int)($quote['revision']??0).'.html'; $pdfRel='data/docs/generated/quotations/'.$fileBase.'_rev'.(int)($quote['revision']??0).'.pdf';
file_put_contents(__DIR__.'/'.$htmlRel,$html,LOCK_EX);
handover_generate_pdf($html,__DIR__.'/'.$pdfRel);
$quote['render']['html_path']=$htmlRel; $quote['render']['pdf_path']=$pdfRel; $quote['render']['generated_at']=date(DATE_ATOM); if($idx!==null){$quotes['quotations'][$idx]=$quote; p_write(__DIR__.'/data/docs/quotations.json',$quotes);} 
header('Location: doc-view.php?type=quotation&id='.urlencode($id));
exit;
