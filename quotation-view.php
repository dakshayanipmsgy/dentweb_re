<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

ini_set('display_errors', '0');

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();
$user = current_user();
$viewerType='';$viewerId='';$viewerName='';
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) { $viewerType='admin'; $viewerId=(string)($user['id'] ?? ''); $viewerName=(string)($user['full_name'] ?? 'Admin'); }
else { $employee = employee_portal_current_employee($employeeStore); if ($employee !== null) { $viewerType='employee'; $viewerId=(string)($employee['id'] ?? ''); $viewerName=(string)($employee['name'] ?? 'Employee'); }}
if ($viewerType==='') { header('Location: login.php'); exit; }

$id=safe_text($_GET['id'] ?? '');
$quote=documents_get_quote($id);
if($quote===null){http_response_code(404);exit('Quotation not found.');}
if($viewerType==='employee' && ((string)($quote['created_by_type'] ?? '')!=='employee' || (string)($quote['created_by_id'] ?? '')!==$viewerId)){http_response_code(403);exit('Access denied.');}

$redirect=static function(string $t,string $m) use ($id): void { header('Location: quotation-view.php?'.http_build_query(['id'=>$id,'status'=>$t,'message'=>$m])); exit; };

if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!verify_csrf_token($_POST['csrf_token'] ?? null)){$redirect('error','Security validation failed.');}
 $action=safe_text($_POST['action'] ?? '');
 $quote = documents_get_quote($id) ?? $quote;

 if($action==='change_mobile_number'){
    if($viewerType!=='admin'){$redirect('error','Administrator access is required.');}
    $result=documents_correct_quotation_mobile($id,(string)($_POST['new_mobile']??''),(string)($_POST['correction_reason']??''),(string)($_POST['expected_version']??''),['id'=>$viewerId,'name'=>$viewerName],isset($_POST['confirm_customer_link']),(string)($_POST['request_id']??''));
    if(empty($result['ok'])){$redirect('error',(string)($result['error']??'Unable to correct mobile number.'));}
    if(empty($result['duplicate'])){log_audit_event('admin',$viewerId,'quotation',$id,'quotation_mobile_corrected',['reason'=>safe_text((string)($_POST['correction_reason']??'')),'new_mobile'=>documents_normalize_mobile((string)($_POST['new_mobile']??''))]);}
    $redirect('success',!empty($result['duplicate'])?'This correction was already applied.':'Mobile number corrected without creating a revision.');
 }

 if(in_array($action, ['approve_quote','mark_accepted','archive_quote','unarchive_quote'], true) && $viewerType==='admin'){
    $targets = ['approve_quote'=>'approved','mark_accepted'=>'accepted','archive_quote'=>'archived','unarchive_quote'=>'unarchived'];
    $messages = [
        'approve_quote'=>'Quotation approved.',
        'mark_accepted'=>'Quotation accepted and locked.',
        'archive_quote'=>'Quotation archived.',
        'unarchive_quote'=>'Quotation unarchived.',
    ];
    $transition = documents_quote_apply_admin_status_transition($quote, $targets[$action], ['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName]);
    if (!($transition['ok'] ?? false)) {
        $redirect('error', (string)($transition['error'] ?? 'Unable to update quotation.'));
    }
    $quote = is_array($transition['quote'] ?? null) ? $transition['quote'] : $quote;
    $redirect('success', $messages[$action]);
 }

 if($action==='share_update'){
    $quote['public_share_enabled']=isset($_POST['public_share_enabled']);
    if(isset($_POST['generate_token']) || (string)($quote['public_share_token'] ?? '')===''){
        $quote['public_share_token']=documents_generate_quote_public_share_token();
        if ((string)($quote['public_share_created_at'] ?? '') === '') {
            $quote['public_share_created_at']=date('c');
        }
    }
    if (!$quote['public_share_enabled']) {
        $quote['public_share_revoked_at']=date('c');
    }
    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    $redirect('success','Share settings updated.');
 }

 $allowedForAccepted = documents_quote_normalize_status((string)($quote['status'] ?? 'draft')) === 'accepted';
 if ($allowedForAccepted && in_array($action, ['create_agreement','create_receipt','create_delivery_challan','create_invoice'], true)) {
    if ($viewerType !== 'admin' && $action !== 'create_delivery_challan') {
        $redirect('error', 'Permission denied.');
    }

    $snapshot = documents_quote_resolve_snapshot($quote);
    if ($action === 'create_agreement') {
        $doc = documents_sales_document_defaults('agreement');
        $doc['id'] = documents_generate_simple_document_id('AGR');
        $doc['quotation_id'] = (string)$quote['id'];
        $doc['customer_mobile'] = (string)($quote['customer_mobile'] ?? '');
        $doc['customer_name'] = (string)($quote['customer_name'] ?? '');
        $doc['created_at'] = date('c');
        $doc['created_by'] = ['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
        $doc['status'] = 'active';
        $doc['execution_date'] = safe_text($_POST['execution_date'] ?? date('Y-m-d'));
        $doc['kwp'] = safe_text($_POST['kwp'] ?? (string)($quote['capacity_kwp'] ?? ''));
        $doc['amount'] = (float)($_POST['amount'] ?? ($quote['calc']['gross_payable'] ?? 0));
        $doc['customer_snapshot'] = $snapshot;
        $doc['html_snapshot'] = '<h2>Vendor–Consumer Agreement</h2><p>Execution Date: '.htmlspecialchars((string)$doc['execution_date'], ENT_QUOTES).'</p><p>Customer: '.htmlspecialchars((string)$doc['customer_name'], ENT_QUOTES).'</p><p>Consumer Account: '.htmlspecialchars((string)($snapshot['consumer_account_no'] ?? ''), ENT_QUOTES).'</p><p>Address: '.htmlspecialchars((string)($snapshot['address'] ?? ''), ENT_QUOTES).'</p><p>Capacity: '.htmlspecialchars((string)$doc['kwp'], ENT_QUOTES).' kWp</p><p>Amount: ₹'.number_format((float)$doc['amount'],2).'</p>';
        documents_save_sales_document('agreement', $doc);
        documents_quote_link_workflow_doc($quote, 'agreement', (string)$doc['id']);
    }

    if ($action === 'create_receipt') {
        $doc = documents_sales_document_defaults('receipt');
        $doc['id'] = documents_generate_simple_document_id('RCPT');
        $doc['quotation_id'] = (string)$quote['id'];
        $doc['customer_mobile'] = (string)($quote['customer_mobile'] ?? '');
        $doc['customer_name'] = (string)($quote['customer_name'] ?? '');
        $doc['created_at'] = date('c');
        $doc['created_by'] = ['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
        $doc['status'] = 'received';
        $doc['receipt_date'] = safe_text($_POST['receipt_date'] ?? date('Y-m-d'));
        $doc['amount_received'] = (float)($_POST['amount_received'] ?? 0);
        $doc['mode'] = safe_text($_POST['mode'] ?? 'other');
        $doc['reference'] = safe_text($_POST['reference'] ?? '');
        $doc['against'] = safe_text($_POST['against'] ?? '');
        documents_save_sales_document('receipt', $doc);
        documents_quote_link_workflow_doc($quote, 'receipt', (string)$doc['id']);
    }

    if ($action === 'create_delivery_challan') {
        $doc = documents_sales_document_defaults('delivery_challan');
        $doc['id'] = documents_generate_simple_document_id('DC');
        $doc['quotation_id'] = (string)$quote['id'];
        $doc['customer_mobile'] = (string)($quote['customer_mobile'] ?? '');
        $doc['customer_name'] = (string)($quote['customer_name'] ?? '');
        $doc['created_at'] = date('c');
        $doc['created_by'] = ['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
        $doc['status'] = 'issued';
        $doc['challan_date'] = safe_text($_POST['challan_date'] ?? date('Y-m-d'));
        $doc['dispatch_from'] = safe_text($_POST['dispatch_from'] ?? '');
        $doc['vehicle_transporter'] = safe_text($_POST['vehicle_transporter'] ?? '');
        $doc['items'] = [[
            'item_name' => safe_text($_POST['item_name'] ?? ''),
            'qty' => (float)($_POST['item_qty'] ?? 0),
            'unit' => safe_text($_POST['item_unit'] ?? 'Nos'),
            'remarks' => safe_text($_POST['item_remarks'] ?? ''),
        ]];
        documents_save_sales_document('delivery_challan', $doc);
        documents_quote_link_workflow_doc($quote, 'delivery_challan', (string)$doc['id']);
    }

    if ($action === 'create_invoice') {
        $company = documents_get_company_profile_for_quotes();
        $doc = documents_sales_document_defaults('invoice');
        $doc['id'] = documents_generate_simple_document_id('INV');
        $doc['quotation_id'] = (string)$quote['id'];
        $doc['customer_mobile'] = (string)($quote['customer_mobile'] ?? '');
        $doc['customer_name'] = (string)($quote['customer_name'] ?? '');
        $doc['created_at'] = date('c');
        $doc['created_by'] = ['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
        $doc['status'] = 'active';
        $doc['invoice_date'] = safe_text($_POST['invoice_date'] ?? date('Y-m-d'));
        $doc['invoice_number'] = safe_text($_POST['invoice_number'] ?? ('INV-' . strtoupper(substr((string)$doc['id'], -8))));
        $doc['bill_to'] = safe_text($_POST['bill_to'] ?? (string)($snapshot['address'] ?? ''));
        $doc['ship_to'] = safe_text($_POST['ship_to'] ?? (string)($quote['site_address'] ?? ''));
        $doc['gstin'] = safe_text((string)($company['gstin'] ?? ''));
        $doc['pricing_snapshot'] = (array)($quote['calc'] ?? []);
        $doc['html_snapshot'] = '<h2>Final Tax Invoice</h2><p>No: '.htmlspecialchars((string)$doc['invoice_number'], ENT_QUOTES).'</p><p>Date: '.htmlspecialchars((string)$doc['invoice_date'], ENT_QUOTES).'</p><p>Bill To: '.htmlspecialchars((string)$doc['bill_to'], ENT_QUOTES).'</p><p>Ship To: '.htmlspecialchars((string)$doc['ship_to'], ENT_QUOTES).'</p><p>GSTIN: '.htmlspecialchars((string)$doc['gstin'], ENT_QUOTES).'</p><p>Gross Payable: ₹'.number_format((float)($quote['calc']['gross_payable'] ?? 0),2).'</p>';
        documents_save_sales_document('invoice', $doc);
        documents_quote_link_workflow_doc($quote, 'invoice', (string)$doc['id']);
    }

    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    $redirect('success', 'Workflow document saved.');
 }
}

$quoteDefaults = load_quote_defaults();
$company = documents_get_company_profile_for_quotes();
$shareUrl=((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https://':'http://').($_SERVER['HTTP_HOST'] ?? 'localhost').'/quotation-public.php?t='.urlencode((string)($quote['public_share_token'] ?? ''));
if($viewerType==='admin' && in_array(documents_quote_normalize_status((string)($quote['status']??'draft')),['approved','accepted'],true)){
 $requestId=bin2hex(random_bytes(16));
 ?><section style="max-width:1100px;margin:16px auto;padding:16px;border:1px solid #dbe7e3;border-radius:12px;background:#fff"><details><summary style="cursor:pointer;font-weight:700">Change mobile number</summary><p>Corrects only the current contact and safe Customer Users linkage. It does not create a revision or rewrite finalized document snapshots.</p><form method="post" style="display:grid;gap:10px;max-width:620px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token']??''),ENT_QUOTES) ?>"><input type="hidden" name="action" value="change_mobile_number"><input type="hidden" name="expected_version" value="<?= htmlspecialchars((string)($quote['updated_at']??$quote['created_at']??''),ENT_QUOTES) ?>"><input type="hidden" name="request_id" value="<?= htmlspecialchars($requestId,ENT_QUOTES) ?>"><label>New mobile number<input name="new_mobile" inputmode="numeric" pattern="[6-9][0-9]{9}" required></label><label>Correction reason<textarea name="correction_reason" required></textarea></label><label><input type="checkbox" name="confirm_customer_link" value="1"> I explicitly confirm migrating or relinking any currently linked Customer User. Existing accounts will not be overwritten.</label><button type="submit">Change mobile number</button></form></details></section><?php
}
quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, $viewerType, $viewerId);
