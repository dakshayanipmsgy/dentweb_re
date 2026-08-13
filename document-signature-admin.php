<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/document_signature.php';
require_admin();

$type = safe_text((string)($_POST['document_type'] ?? ''));
$id = safe_text((string)($_POST['document_id'] ?? ''));
$return = (string)($_POST['return_url'] ?? 'admin-documents.php');
if (!preg_match('#^[a-zA-Z0-9_-]+\.php(?:\?[a-zA-Z0-9_=&%.-]*)?$#', $return)) $return = 'admin-documents.php';
$go = static function(string $message, string $status) use ($return): void {
    $join = str_contains($return, '?') ? '&' : '?';
    header('Location: ' . $return . $join . http_build_query(['message' => $message, 'status' => $status])); exit;
};
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $go('Security validation failed.', 'error');

$loaders = ['quotation'=>'documents_get_quote','dispatch_advice'=>'documents_get_dispatch_advice','challan'=>'documents_get_challan','invoice'=>'documents_get_invoice'];
$savers = ['quotation'=>'documents_save_quote','dispatch_advice'=>'documents_save_dispatch_advice','challan'=>'documents_save_challan','invoice'=>'documents_save_invoice'];
$isUniversal = $type === 'universal' && $id === 'global';
$document = $isUniversal ? ['id' => 'global', 'signature' => document_signature_universal_reference()] : (isset($loaders[$type]) ? $loaders[$type]($id) : null);
if (!is_array($document)) $go('Document not found.', 'error');
$editable = match ($type) {
    'universal' => true,
    'quotation' => !documents_quote_is_locked($document) && documents_quote_normalize_status((string)($document['status'] ?? 'draft')) === 'draft',
    'dispatch_advice' => (string)($document['status'] ?? '') === 'draft',
    'challan' => strtolower((string)($document['status'] ?? 'draft')) === 'draft' || strtolower((string)($document['workflow_status'] ?? '')) === 'created',
    'invoice' => documents_invoice_is_draft($document),
    default => false,
};
if (!$editable) $go('Create or open an editable draft/revision to change this signature.', 'error');

$old = document_signature_reference($document);
if ((string)($_POST['signature_action'] ?? '') === 'remove') {
    unset($document['signature']);
    $saved = $isUniversal ? json_save(document_signature_settings_path(), ['signature' => [], 'updated_at' => date('c')]) : $savers[$type]($document);
    if (empty($saved['ok'])) $go('Unable to save the document.', 'error');
    document_signature_delete($old);
    $go('Signature removed.', 'success');
}
$stored = document_signature_store((array)($_FILES['signature_image'] ?? []), $type, $id);
if (empty($stored['ok'])) $go((string)($stored['error'] ?? 'Upload failed.'), 'error');
$document['signature'] = $stored['reference'];
$document['updated_at'] = date('c');
$saved = $isUniversal ? json_save(document_signature_settings_path(), ['signature' => $document['signature'], 'updated_at' => date('c')]) : $savers[$type]($document);
if (empty($saved['ok'])) { document_signature_delete($stored['reference']); $go('Unable to save the signature reference.', 'error'); }
document_signature_delete($old);
$go($old === [] ? 'Signature uploaded.' : 'Signature replaced.', 'success');
