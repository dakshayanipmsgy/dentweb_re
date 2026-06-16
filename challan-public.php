<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/customer_document_acceptance.php';
require_once __DIR__ . '/includes/challan_view_renderer.php';
$token = (string)($_GET['token'] ?? '');
$challan = documents_get_challan_by_public_token($token);
if (!$challan || !in_array((string)($challan['status'] ?? ''), ['issued'], true)) { http_response_code(404); exit('Link unavailable'); }
$company = load_company_profile();
$confirm = 'customer-document-acceptance.php?' . http_build_query(['type'=>'challan','token'=>$token]);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width"><title>Delivery Challan</title><style>body{font:14px Arial;background:#eef3f1;color:#172033;margin:0}.page{max-width:210mm;margin:20px auto}.document{background:#fff;border:1px solid #ccd5e1;padding:14mm;box-shadow:0 6px 24px #0002}.document-header{display:flex;justify-content:space-between;gap:25px;border-bottom:3px solid #087b61}.document-title{text-align:right}.company-logo{max-width:140px;max-height:70px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:18px 0}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccd5e1;padding:8px;text-align:left}.actions{text-align:center;margin:24px;background:#fff;padding:22px;border-radius:12px}.btn{display:inline-block;background:#087b61;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-size:17px}@media(max-width:700px){.page{margin:0}.document{padding:18px}.document-header{display:block}.document-title{text-align:left}.meta{grid-template-columns:1fr 1fr}table{font-size:12px}}@media print{@page{size:A4;margin:10mm}body{background:#fff}.page{margin:0}.document{border:0;box-shadow:none;padding:0}.actions{display:none}}</style></head><body><main class="page"><?php render_challan_document($challan,$company,true);?><div class="actions"><h2>Confirm receipt of materials</h2><p>Please check the items listed below. Confirm only after you have received these materials.</p><a class="btn" href="<?=htmlspecialchars($confirm,ENT_QUOTES)?>">Confirm Items Received</a></div></main></body></html>
