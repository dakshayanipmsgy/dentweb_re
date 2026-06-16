<?php
declare(strict_types=1);

function material_document_safe(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function material_document_print_styles(): string { return '*{box-sizing:border-box}body{margin:0;background:#eef4f2;color:#193b35;font:14px/1.5 Arial,Helvetica,sans-serif}.document{max-width:960px;margin:24px auto;padding:30px;background:#fff;border:1px solid #d9e7e2;border-radius:18px}.document-header{display:flex;justify-content:space-between;gap:24px;padding-bottom:18px;border-bottom:3px solid #0f766e}.company-logo{max-width:130px;max-height:58px}.document-title{text-align:right}.document-title h2{margin:0;color:#0f766e;font-size:28px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.meta p{margin:0;padding:12px;background:#f5faf8;border:1px solid #d9e7e2;border-radius:12px}.additional-details{margin:18px 0;padding:16px;background:#f8fcfb;border:1px solid #d9e7e2;border-radius:14px;break-inside:avoid;page-break-inside:avoid}.additional-details h3{margin:0 0 12px;color:#0f766e;font-size:18px}.additional-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.additional-detail{padding:10px 12px;background:#fff;border:1px solid #d9e7e2;border-radius:10px}.additional-detail-full{grid-column:1/-1}.additional-detail-label{display:block;margin-bottom:4px;color:#40625d;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.02em}.additional-detail-value{color:#193b35}table{width:100%;border-collapse:collapse;margin-top:14px}th,td{padding:11px;border:1px solid #d9e7e2;text-align:left;vertical-align:top}th{background:#eaf5f2;color:#0f3f38}.item-name{font-weight:700}.item-description{display:block;margin-top:3px;color:#5b716d}.disclaimer{margin-top:18px;padding:12px;background:#fff8e6;border-left:4px solid #d9a928}footer{margin-top:32px;text-align:right;font-weight:700}@media (max-width:700px){.meta,.additional-details-grid{grid-template-columns:1fr}}@media print{body{background:#fff}.document{margin:0;max-width:none;border:0;border-radius:0}tr,.additional-details{break-inside:avoid;page-break-inside:avoid}thead{display:table-header-group}.no-print{display:none!important}}'; }
function material_document_normalized_details(array $details): array
{
    $normalized = [];
    foreach ($details as $detail) {
        if (!is_array($detail)) {
            continue;
        }
        $label = trim((string)($detail['label'] ?? ''));
        $value = trim((string)($detail['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $normalized[] = [
            'label' => $label,
            'value' => $value,
            'multiline' => !empty($detail['multiline']),
        ];
    }
    return $normalized;
}
function material_document_render_additional_details(array $options): void
{
    $details = material_document_normalized_details(is_array($options['additional_details'] ?? null) ? $options['additional_details'] : []);
    if ($details === []) {
        return;
    }
    $title = trim((string)($options['additional_details_title'] ?? 'Additional Details'));
    ?><section class="additional-details"><h3><?=material_document_safe($title !== '' ? $title : 'Additional Details')?></h3><div class="additional-details-grid"><?php foreach ($details as $detail): $isMultiline = !empty($detail['multiline']); ?><div class="additional-detail<?= $isMultiline ? ' additional-detail-full' : '' ?>"><span class="additional-detail-label"><?=material_document_safe((string)$detail['label'])?></span><span class="additional-detail-value"><?= $isMultiline ? nl2br(material_document_safe((string)$detail['value'])) : material_document_safe((string)$detail['value']) ?></span></div><?php endforeach; ?></div></section><?php
}

function render_material_document(array $doc, array $company, array $items, array $options = []): void
{
    $name = (string)($company['brand_name'] ?? $company['company_name'] ?? 'Dakshayani Enterprises');
    $address = trim((string)($company['address'] ?? $company['registered_address'] ?? ''));
    $mobile = (string)($options['customer_mobile'] ?? '');
    ?><article class="document">
    <header class="document-header"><div><?php if(trim((string)($company['logo_path']??''))!==''):?><img class="company-logo" src="<?=material_document_safe((string)$company['logo_path'])?>" alt="<?=material_document_safe($name)?> logo"><?php endif;?><h1><?=material_document_safe($name)?></h1><?php if($address!==''):?><p><?=material_document_safe($address)?></p><?php endif;?><p><?=material_document_safe((string)($company['phone_primary']??''))?><?php if(!empty($company['email_primary'])):?> · <?=material_document_safe((string)$company['email_primary'])?><?php endif;?></p><p><?php if(!empty($company['gstin'])):?>GSTIN: <?=material_document_safe((string)$company['gstin'])?><?php endif;?><?php if(!empty($company['website'])):?> · <?=material_document_safe((string)$company['website'])?><?php endif;?></p></div><div class="document-title"><h2><?=material_document_safe((string)($options['title']??'Material Document'))?></h2><strong><?=material_document_safe((string)($options['number']??''))?></strong><?php if(($options['version']??'')!==''):?><p>Version <?=material_document_safe((string)$options['version'])?></p><?php endif;?></div></header>
    <div class="meta"><p><b>Date</b><br><?=material_document_safe((string)($options['date']??''))?><br><b>Status</b><br><?=material_document_safe((string)($options['status']??''))?></p><p><b>Quotation</b><br><?=material_document_safe((string)($options['quotation_no']??''))?><br><b>Dispatch Advice</b><br><?=material_document_safe((string)($options['dispatch_advice_no']??($options['agreement_no']??'')))?></p><p><b>Customer</b><br><?=material_document_safe((string)($options['customer_name']??''))?><br><?=material_document_safe($mobile)?></p><p><b>Delivery address</b><br><?=nl2br(material_document_safe((string)($options['delivery_address']??'')))?><?php if(($options['transport']??'')!==''):?><br><b>Vehicle / transporter</b><br><?=material_document_safe((string)$options['transport'])?><?php endif;?></p></div>
    <?php material_document_render_additional_details($options); ?>
    <table><thead><tr><th>#</th><th>Item / description</th><th>Brand / model</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach($items as $i=>$r):?><tr><td><?=$i+1?></td><td><span class="item-name"><?=material_document_safe((string)($r['name']??''))?></span><?php if(trim((string)($r['description']??''))!==''):?><span class="item-description"><?=material_document_safe((string)$r['description'])?></span><?php endif;?></td><td><?=material_document_safe((string)($r['brand_model']??''))?></td><td><?=material_document_safe((string)($r['qty']??''))?> <?=material_document_safe((string)($r['unit']??''))?></td><td><?=material_document_safe((string)($r['remarks']??''))?></td></tr><?php endforeach;if(!$items):?><tr><td colspan="5">No material items were found.</td></tr><?php endif;?></tbody></table>
    <?php if(trim((string)($options['note']??''))!==''):?><h3><?=material_document_safe((string)($options['note_title']??'Note'))?></h3><p><?=nl2br(material_document_safe((string)$options['note']))?></p><?php endif;?><p class="disclaimer"><strong>Important:</strong> <?=material_document_safe((string)($options['disclaimer']??''))?></p><footer><?=material_document_safe((string)($options['footer']??'For Dakshayani Enterprises — Authorised Signatory'))?></footer></article><?php
}
