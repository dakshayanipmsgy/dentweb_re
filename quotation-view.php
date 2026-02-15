<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';
documents_ensure_structure();
$employeeStore = new EmployeeFsStore();
$user = current_user();
$viewerType='';$viewerId='';
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) { $viewerType='admin'; $viewerId=(string)($user['id'] ?? ''); }
else { $employee = employee_portal_current_employee($employeeStore); if ($employee !== null) { $viewerType='employee'; $viewerId=(string)($employee['id'] ?? ''); }}
if ($viewerType==='') { header('Location: login.php'); exit; }
$id=safe_text($_GET['id'] ?? ''); $quote=documents_get_quote($id); if($quote===null){http_response_code(404);exit('Quotation not found.');}
if($viewerType==='employee' && ((string)($quote['created_by_type'] ?? '')!=='employee' || (string)($quote['created_by_id'] ?? '')!==$viewerId)){http_response_code(403);exit('Access denied.');}
$redirect=static function(string $t,string $m) use ($id): void { header('Location: quotation-view.php?'.http_build_query(['id'=>$id,'status'=>$t,'message'=>$m])); exit; };
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!verify_csrf_token($_POST['csrf_token'] ?? null)){$redirect('error','Security validation failed.');}
 $action=safe_text($_POST['action'] ?? '');
 if($action==='approve_quote' && $viewerType==='admin'){ $quote['status']='Approved'; $quote['approval']['approved_by_name']=(string)($user['full_name'] ?? 'Admin'); $quote['approval']['approved_at']=date('c'); $quote['updated_at']=date('c'); documents_save_quote($quote); $redirect('success','Quotation approved.'); }
 if($action==='share_update'){ $quote['share']['public_enabled']=isset($_POST['public_enabled']); if(isset($_POST['generate_token']) || (string)($quote['share']['public_token'] ?? '')===''){ $quote['share']['public_token']=bin2hex(random_bytes(16)); } $quote['updated_at']=date('c'); documents_save_quote($quote); $redirect('success','Share settings updated.'); }
}
$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl=((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https://':'http://').($_SERVER['HTTP_HOST'] ?? 'localhost').'/quotation-public.php?token='.urlencode((string)($quote['share']['public_token'] ?? ''));
quotation_render($quote, $quoteDefaults, $company, true, $shareUrl);
