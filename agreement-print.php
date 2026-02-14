<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$row = documents_get_agreement($id);
if ($row === null) { http_response_code(404); echo 'Agreement not found.'; exit; }
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$renderForPdf = safe_text($_GET['pdf'] ?? '') === '1';
$background = (string) ($row['rendering']['background_image'] ?? '');
$bg = $renderForPdf ? (resolve_public_image_to_absolute($background) ?? $background) : $background;
$op = max(0.1, min(1.0, (float)($row['rendering']['background_opacity'] ?? 1)));
$safeHtml = static fn(string $v): string => strip_tags($v, '<p><br><ul><ol><li><strong><em><b><i><u>');
?>
<!doctype html><html><head><meta charset="utf-8"><title>Agreement Print</title><style>@page{size:A4;margin:16mm 12mm}body{font-family:Arial;font-size:12px;line-height:1.45}.bg{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= $op ?>;z-index:-1}.h{display:flex;justify-content:space-between;border-bottom:2px solid #1d4ed8;padding-bottom:6px}</style></head><body><?php if($bg!==''): ?><img class="bg" src="<?= htmlspecialchars($bg, ENT_QUOTES) ?>" alt="bg"><?php endif; ?><div class="h"><div><strong><?= htmlspecialchars((string)($company['brand_name'] ?: $company['company_name']), ENT_QUOTES) ?></strong></div><div><h2 style="margin:0">Vendor–Consumer Agreement</h2><strong><?= htmlspecialchars((string)$row['agreement_no'], ENT_QUOTES) ?></strong></div></div><p>Date: <?= htmlspecialchars(substr((string)$row['updated_at'],0,10), ENT_QUOTES) ?></p><p><strong>Consumer:</strong> <?= htmlspecialchars((string)$row['customer_name'], ENT_QUOTES) ?> (<?= htmlspecialchars((string)$row['customer_mobile'], ENT_QUOTES) ?>)</p><p><?= nl2br(htmlspecialchars((string)$row['address'], ENT_QUOTES)) ?><br><?= htmlspecialchars((string)$row['district'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$row['state'], ENT_QUOTES) ?> - <?= htmlspecialchars((string)$row['pin'], ENT_QUOTES) ?></p><div><?= $safeHtml((string)$row['agreement_text']) ?></div><?php if(trim((string)$row['special_terms_override'])!==''): ?><h3>Special Terms (Overrides)</h3><p><?= nl2br(htmlspecialchars((string)$row['special_terms_override'], ENT_QUOTES)) ?></p><?php endif; ?><br><br><table style="width:100%"><tr><td style="width:40%">Vendor Signature<br><br>__________________</td><td></td><td style="width:40%">Consumer Signature<br><br>__________________</td></tr><tr><td>Witness 1<br><br>__________________</td><td></td><td>Witness 2<br><br>__________________</td></tr></table></body></html>
