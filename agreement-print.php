<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$row = documents_get_agreement($id);
if ($row === null) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

$templates = documents_get_agreement_templates();
$template = is_array($templates[$row['template_key']] ?? null) ? $templates[$row['template_key']] : null;
if ((string) ($row['generated_html_snapshot'] ?? '') === '') {
    $row['generated_html_snapshot'] = documents_render_agreement_html($row, $template);
    $row['updated_at'] = date('c');
    documents_save_agreement($row);
}

$renderForPdf = safe_text($_GET['pdf'] ?? '') === '1';
$background = safe_text($row['rendering']['background_image'] ?? '');
if ($background === '') {
    $company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
    $background = safe_text($company['letterhead_background'] ?? '');
}
$bg = $renderForPdf ? (resolve_public_image_to_absolute($background) ?? $background) : $background;
$op = max(0.1, min(1.0, (float) ($row['rendering']['background_opacity'] ?? 1)));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Agreement Print</title>
  <style>
    @page{size:A4;margin:14mm}
    body{font-family:Arial,sans-serif;font-size:13px;line-height:1.5}
    .bg{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= $op ?>;z-index:-1}
    .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
  </style>
</head>
<body>
<?php if ($bg !== ''): ?><img class="bg" src="<?= htmlspecialchars($bg, ENT_QUOTES) ?>" alt="bg"><?php endif; ?>
<div class="head">
  <div><strong>Agreement No:</strong> <?= htmlspecialchars((string) $row['agreement_no'], ENT_QUOTES) ?></div>
  <div><strong>Date:</strong> <?= htmlspecialchars(documents_format_display_date((string) $row['execution_date']), ENT_QUOTES) ?></div>
</div>
<div><?= $row['generated_html_snapshot'] ?></div>
</body>
</html>
