<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_admin.php';
require_once __DIR__ . '/includes/handover.php';

require_admin();
start_session();

$customerStore = new CustomerFsStore();
$templates = load_handover_templates();

$errors = [];
$success = '';
$renderedHtml = '';
$customerMobileValue = '';

$mobile = (string) ($_GET['mobile'] ?? ($_POST['customer_mobile'] ?? ''));
if ($mobile === '') {
    $errors[] = 'Customer not found.';
}

$customer = $mobile !== '' ? $customerStore->findByMobile($mobile) : null;
$customerMobileValue = is_array($customer) ? (string) ($customer['mobile'] ?? $mobile) : (string) $mobile;
if ($customer === null) {
    $errors[] = 'Customer not found.';
}

if ($customer !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['handover_action'] ?? '') === 'save_generate') {
    $submittedHtml = trim((string) ($_POST['handover_html_content'] ?? ''));
    if ($submittedHtml === '') {
        $errors[] = 'Handover content cannot be empty.';
    }

    if ($errors === []) {
        $directory = handover_storage_directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $errors[] = 'Unable to create handover storage directory.';
        }

        if ($errors === []) {
            $normalizedMobile = handover_normalize_mobile((string) ($customer['mobile'] ?? ''));
            $suffix = $normalizedMobile !== '' ? $normalizedMobile : uniqid('handover_', true);
            $htmlFileName = 'handover_' . $suffix . '.html';
            $fullHtml = handover_wrap_document($customer, $submittedHtml, (string) ($templates['handover_style_css'] ?? ''));
            $htmlPath = rtrim($directory, '/\\') . '/' . $htmlFileName;

            if (file_put_contents($htmlPath, $fullHtml, LOCK_EX) === false) {
                $errors[] = 'Unable to write handover HTML file.';
            }

            if ($errors === []) {
                $timestamp = date('Y-m-d H:i:s');
                $relativeHtmlPath = 'handovers/' . $htmlFileName;
                $update = $customerStore->updateCustomer($mobile, [
                    'handover_document_path' => $relativeHtmlPath,
                    'handover_html_path' => $relativeHtmlPath,
                    'handover_generated_at' => $timestamp,
                ]);

                if ($update['success']) {
                    set_flash('success', 'Handover document generated successfully.');
                    header('Location: admin-users.php?view=' . urlencode((string) ($customer['mobile'] ?? '')));
                    exit;
                }

                $errors = $update['errors'];
            }
        }
    }

    $renderedHtml = $submittedHtml;
}

if ($renderedHtml === '' && $customer !== null && $errors === []) {
    $existingHtmlPath = trim((string) ($customer['handover_html_path'] ?? ''));
    if ($existingHtmlPath !== '') {
        $absolutePath = __DIR__ . '/' . ltrim($existingHtmlPath, '/');
        if (is_file($absolutePath)) {
            $existingContent = file_get_contents($absolutePath);
            if ($existingContent !== false) {
                $renderedHtml = handover_extract_body_content($existingContent);
            }
        }
    }

    if ($renderedHtml === '') {
        $sections = handover_generate_sections($templates, $customer, $customer['handover_overrides'] ?? []);
        $renderedHtml = handover_render_sections_html($customer, $sections);
    }
}

function handover_editor_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Generate Handover | Dakshayani Enterprises</title>
    <link rel="stylesheet" href="style.css" />
    <style>
        body { background: #f5f7fb; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .page-shell { max-width: 1100px; margin: 0 auto; padding: 1.75rem 1rem 2.5rem; }
        .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.5rem; box-shadow: 0 18px 40px rgba(15,23,42,0.08); }
        h1 { margin: 0 0 0.35rem; }
        p { color: #4b5563; }
        .alert { padding: 0.75rem 1rem; border-radius: 12px; margin-bottom: 1rem; }
        .alert-error { background: #fef2f2; border: 1px solid #fecdd3; color: #991b1b; }
        .notice { background: #ecfdf3; border: 1px solid #bbf7d0; color: #166534; padding: 0.75rem 1rem; border-radius: 12px; margin-bottom: 1rem; }
        label { display: block; font-weight: 700; color: #111827; margin-bottom: 0.35rem; }
        textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 0.75rem; font: inherit; min-height: 460px; background: #f9fafb; }
        .actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem; }
        .btn { border: none; border-radius: 10px; padding: 0.75rem 1.4rem; cursor: pointer; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-primary { background: linear-gradient(135deg, #1f4b99, #2d68d8); color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .breadcrumb { margin: 0 0 1rem; color: #374151; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; }
    </style>
</head>
<body>
<div class="page-shell">
    <a class="breadcrumb" href="admin-users.php?view=<?= handover_editor_safe($customerMobileValue) ?>">
        &#8592; Back to customer details
    </a>
    <div class="card">
        <h1>Generate Handover Document</h1>
        <p>This is the final handover document for this customer. You can edit the HTML below; changes will only apply to this customer.</p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert">
                <ul style="padding-left: 1.25rem; margin: 0;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= handover_editor_safe($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="handover_action" value="save_generate" />
            <input type="hidden" name="customer_mobile" value="<?= handover_editor_safe($customerMobileValue) ?>" />
            <div class="notice">You can paste or edit HTML directly, including headings, images, and formatting. The saved file will include the global handover CSS.</div>
            <label for="handover_html_content">Handover HTML (final, per-customer)</label>
            <textarea id="handover_html_content" name="handover_html_content" spellcheck="false"><?= handover_editor_safe($renderedHtml) ?></textarea>
            <div class="actions">
                <a class="btn btn-secondary" href="admin-users.php?view=<?= handover_editor_safe($customerMobileValue) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary">Save &amp; Generate Handover (HTML)</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
