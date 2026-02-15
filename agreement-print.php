<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$agreement = documents_get_agreement($id);
if ($agreement === null) { http_response_code(404); echo 'Agreement not found.'; exit; }
$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$appearance = documents_load_document_appearance();
$fontScale = max(0.8, min(1.3, (float) ($appearance['global']['font_scale'] ?? 1)));
$html = documents_agreement_render_html($agreement, $company);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agreement <?= htmlspecialchars((string)$agreement['agreement_no'], ENT_QUOTES) ?></title>
<style>
html{font-size:calc(16px * <?= htmlspecialchars((string)$fontScale, ENT_QUOTES) ?>)}body{font-family:Arial,sans-serif;background:#fff;margin:0;padding:14px;color:#111}.card{max-width:900px;margin:0 auto}.meta{display:flex;justify-content:space-between;border-bottom:2px solid #1e3a8a;padding-bottom:8px;margin-bottom:10px}@media print{.print-watermark{display:block;position:fixed;inset:0;z-index:0;background-image:url('<?= htmlspecialchars((string)($appearance['print_watermark']['image_path'] ?? ''), ENT_QUOTES) ?>');background-position:center;background-repeat:<?= htmlspecialchars((string)($appearance['print_watermark']['repeat'] ?? 'no-repeat'), ENT_QUOTES) ?>;background-size:<?= (int)($appearance['print_watermark']['size_percent'] ?? 70) ?>%;opacity:<?= htmlspecialchars((string)($appearance['print_watermark']['opacity'] ?? 0.08), ENT_QUOTES) ?>}body>*{position:relative;z-index:1}}
</style></head><body><div class="print-watermark"></div><div class="card"><div class="meta"><div><strong><?= htmlspecialchars((string)$agreement['agreement_no'], ENT_QUOTES) ?></strong></div><div><?= htmlspecialchars((string)$agreement['customer_name'], ENT_QUOTES) ?></div></div><?= $html ?></div></body></html>
