<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
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

 if($action==='approve_quote' && $viewerType==='admin'){
    $quote['status']='approved';
    $quote['approval']['approved_by_name']=$viewerName;
    $quote['approval']['approved_at']=date('c');
    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    $redirect('success','Quotation approved.');
 }

 if($action==='mark_accepted' && $viewerType==='admin'){
    if (documents_quote_normalize_status((string)($quote['status'] ?? 'draft')) === 'accepted') {
        $redirect('success','Already accepted.');
    }
    if (documents_quote_normalize_status((string)($quote['status'] ?? 'draft')) !== 'approved') {
        $redirect('error','Only approved quotations can be marked accepted.');
    }
    $valid = documents_quote_has_valid_acceptance_data($quote);
    if (!($valid['ok'] ?? false)) {
        $redirect('error', (string)($valid['error'] ?? 'Acceptance data missing.'));
    }
    $quote['status']='accepted';
    $quote['accepted_at']=date('c');
    $quote['accepted_by']=['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
    $quote['acceptance']['accepted_by_admin_id']=$viewerId;
    $quote['acceptance']['accepted_by_admin_name']=$viewerName;
    $quote['acceptance']['accepted_at']=$quote['accepted_at'];
    $quote['workflow']=array_merge(documents_quote_workflow_defaults(), is_array($quote['workflow'] ?? null) ? $quote['workflow'] : []);
    $quote['locked_flag'] = true;
    $quote['locked_at'] = date('c');
    $quote['is_current_version'] = true;
    $syncResult = documents_sync_after_quote_accepted($quote);
    $quote = $syncResult['quote'];
    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    documents_quote_set_current_for_series($quote);
    $redirect('success','Quotation marked accepted by customer.');
 }

 if($action==='archive_quote' && $viewerType==='admin'){
    $quote['status']='archived';
    $quote['archived_flag']=true;
    $quote['archived_at']=date('c');
    $quote['archived_by']=['type'=>$viewerType,'id'=>$viewerId,'name'=>$viewerName];
    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    $redirect('success','Quotation archived.');
 }

 if($action==='unarchive_quote' && $viewerType==='admin'){
    $quote['status']=documents_quote_normalize_status((string)($quote['accepted_at'] ?? '')) !== '' ? 'accepted' : 'approved';
    $quote['archived_flag']=false;
    $quote['archived_at']='';
    $quote['archived_by']=['type'=>'','id'=>'','name'=>''];
    $quote['updated_at']=date('c');
    documents_save_quote($quote);
    $redirect('success','Quotation unarchived.');
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
quotation_render($quote, $quoteDefaults, $company, false, $shareUrl, $viewerType, $viewerId);
