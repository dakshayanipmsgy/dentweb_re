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
$html = documents_render_agreement_body_html($agreement, $company);
$theme = documents_get_effective_doc_theme((string) ($agreement['template_set_id'] ?? ''));
$font = (float) ($theme['font_scale'] ?? 1);
$bg = !empty($theme['enable_background']) ? (string) (($theme['background_media_path'] ?? '') ?: ($agreement['rendering']['background_image'] ?? '')) : '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agreement <?= htmlspecialchars((string) $agreement['agreement_no'], ENT_QUOTES) ?></title>
<style>@page{size:A4;margin:14mm}body{font-family:Arial,sans-serif;font-size:<?= 12.5*$font ?>px;color:<?= htmlspecialchars((string)$theme['text_color'], ENT_QUOTES) ?>;margin:0}.page{position:relative;min-height:260mm}.bg{position:fixed;inset:0;z-index:-1;opacity:.12;background:<?= $bg!=='' ? 'url(' . htmlspecialchars($bg, ENT_QUOTES) . ') center/cover no-repeat' : 'none' ?>}.meta{display:flex;justify-content:space-between;border-bottom:2px solid <?= htmlspecialchars((string)$theme['primary_color'], ENT_QUOTES) ?>;padding-bottom:8px;margin-bottom:12px}</style>
</head><body><div class="page"><?php if($bg!==''): ?><div class="bg"></div><?php endif; ?><div class="meta"><div><strong>Agreement No:</strong> <?= htmlspecialchars((string)$agreement['agreement_no'], ENT_QUOTES) ?><br><strong>Status:</strong> <?= htmlspecialchars((string)$agreement['status'], ENT_QUOTES) ?></div><div><strong>Customer:</strong> <?= htmlspecialchars((string)$agreement['customer_name'], ENT_QUOTES) ?></div></div><?= $html ?></div><script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script></body></html>
