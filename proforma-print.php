<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$row = documents_get_proforma($id);
if ($row === null) { http_response_code(404); echo 'Proforma not found.'; exit; }
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$renderForPdf = safe_text($_GET['pdf'] ?? '') === '1';
$background = (string) ($row['rendering']['background_image'] ?? '');
$bg = $renderForPdf ? (resolve_public_image_to_absolute($background) ?? $background) : $background;
$op = max(0.1, min(1.0, (float)($row['rendering']['background_opacity'] ?? 1)));
?>
<!doctype html><html><head><meta charset="utf-8"><title>Proforma Print</title><style>@page{size:A4;margin:16mm 12mm}body{font-family:Arial;font-size:12px}.bg{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= $op ?>;z-index:-1}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:6px}.h{display:flex;justify-content:space-between;border-bottom:2px solid #1d4ed8;padding-bottom:6px}</style></head><body>
<?php if($bg!==''): ?><img class="bg" src="<?= htmlspecialchars($bg, ENT_QUOTES) ?>" alt="bg"><?php endif; ?>
<div class="h"><div><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong><br><?= htmlspecialchars((string)$company['address_line'], ENT_QUOTES) ?></div><div><h2 style="margin:0">PROFORMA INVOICE</h2><strong><?= htmlspecialchars((string)$row['proforma_no'], ENT_QUOTES) ?></strong></div></div>
<p><strong>Customer:</strong> <?= htmlspecialchars((string)$row['customer_name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$row['customer_mobile'], ENT_QUOTES) ?>)</p>
<p><?= nl2br(htmlspecialchars((string)$row['billing_address'], ENT_QUOTES)) ?></p>
<?php if ((string)$row['notes_top']!==''): ?><p><?= nl2br(htmlspecialchars((string)$row['notes_top'], ENT_QUOTES)) ?></p><?php endif; ?>
<table><thead><tr><th>Description</th><th>Basic</th><th>GST</th><th>Total</th></tr></thead><tbody>
<tr><td>Solar Power Generation System (5%)</td><td><?= number_format((float)$row['calc']['bucket_5_basic'],2) ?></td><td><?= number_format((float)$row['calc']['bucket_5_gst'],2) ?></td><td><?= number_format((float)$row['calc']['bucket_5_basic']+(float)$row['calc']['bucket_5_gst'],2) ?></td></tr>
<tr><td>Solar Power Generation System (18%)</td><td><?= number_format((float)$row['calc']['bucket_18_basic'],2) ?></td><td><?= number_format((float)$row['calc']['bucket_18_gst'],2) ?></td><td><?= number_format((float)$row['calc']['bucket_18_basic']+(float)$row['calc']['bucket_18_gst'],2) ?></td></tr>
<tr><th colspan="3">Grand Total</th><th><?= number_format((float)$row['calc']['grand_total'],2) ?></th></tr>
</tbody></table>
<?php if ((string)$row['notes_bottom']!==''): ?><p><?= nl2br(htmlspecialchars((string)$row['notes_bottom'], ENT_QUOTES)) ?></p><?php endif; ?>
</body></html>
