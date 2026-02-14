<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$agreement = documents_get_agreement($id);
if ($agreement === null) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$globalStyle = documents_get_document_style_settings();
$styleOverride = is_array(($agreement['style_override'] ?? null)) ? $agreement['style_override'] : [];
$style = documents_merge_style_with_override($globalStyle, $styleOverride);
$html = documents_render_agreement_body_html($agreement, $company);

$background = safe_text((string) ($style['layout']['page_background_image'] ?? ($agreement['rendering']['background_image'] ?? '')));
$bgOpacity = max(0.0, min(1.0, (float) ($style['layout']['page_background_opacity'] ?? ($agreement['rendering']['background_opacity'] ?? 1))));
$backgroundResolved = !empty($style['layout']['page_background_enabled']) ? $background : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Agreement Print <?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?></title>
  <style>
    @page { size: A4; margin: 16mm 14mm; }
    body{font-family:Arial,sans-serif;color:#111;font-size:<?= (int) ($style['typography']['base_font_size_px'] ?? 14) ?>px;line-height:1.5;margin:0}
    .page{position:relative;min-height:260mm;padding:2mm}
    .page-bg-img{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= htmlspecialchars((string) $bgOpacity, ENT_QUOTES) ?>;z-index:-1}
    .meta{display:flex;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #94a3b8}
    .meta .right{text-align:right}
  </style>
</head>
<body>
<div class="page">
  <?php if ($backgroundResolved !== ''): ?><img class="page-bg-img" src="<?= htmlspecialchars($backgroundResolved, ENT_QUOTES) ?>" alt="background"><?php endif; ?>
  <div class="meta">
    <div>
      <strong>Agreement No:</strong> <?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?><br>
      <strong>Status:</strong> <?= htmlspecialchars((string) $agreement['status'], ENT_QUOTES) ?>
    </div>
    <div class="right">
      <strong>Customer:</strong> <?= htmlspecialchars((string) $agreement['customer_name'], ENT_QUOTES) ?><br>
      <strong>Mobile:</strong> <?= htmlspecialchars((string) $agreement['customer_mobile'], ENT_QUOTES) ?>
    </div>
  </div>
  <?= $html ?>
</div>
<script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script>
</body>
</html>
