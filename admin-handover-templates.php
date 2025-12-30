<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';

require_admin();
start_session();

$templates = load_handover_templates();
$message = '';
$tone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = [];
    foreach (handover_template_defaults() as $key => $default) {
        $next[$key] = (string) ($_POST[$key] ?? '');
    }

    try {
        save_handover_templates($next);
        $templates = load_handover_templates();
        $message = 'Templates saved successfully.';
        $tone = 'success';
    } catch (Throwable $exception) {
        $message = 'Unable to save templates: ' . $exception->getMessage();
        $tone = 'error';
    }
}

function admin_handover_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$placeholders = [
    '{{consumer_name}}',
    '{{address}}',
    '{{consumer_no}}',
    '{{mobile}}',
    '{{invoice_no}}',
    '{{premises_type}}',
    '{{scheme_type}}',
    '{{system_type}}',
    '{{system_capacity_kwp}}',
    '{{installation_date}}',
    '{{jbvnl_account_number}}',
    '{{application_id}}',
    '{{city}}',
    '{{district}}',
    '{{pin_code}}',
    '{{state}}',
    '{{circle_name}}',
    '{{division_name}}',
    '{{sub_division_name}}',
    '{{solar_plant_installation_date}}',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Handover Templates | Admin</title>
    <link rel="stylesheet" href="style.css" />
    <style>
        body { background: #f5f7fb; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .fullwidth-wrapper { width: 100% !important; max-width: 100% !important; padding-left: 20px; padding-right: 20px; padding-top: 28px; padding-bottom: 40px; }
        .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.5rem; box-shadow: 0 18px 40px rgba(15,23,42,0.08); }
        h1 { margin: 0 0 0.35rem; }
        p { color: #4b5563; }
        .placeholder-note { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 12px; padding: 0.9rem 1rem; margin-bottom: 1rem; }
        .placeholder-note strong { color: #1e3a8a; }
        form .field { margin-bottom: 1rem; }
        label { display: block; font-weight: 700; color: #111827; margin-bottom: 0.35rem; }
        textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 0.75rem; font: inherit; min-height: 200px; background: #f9fafb; }
        .actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem; }
        .btn { border: none; border-radius: 10px; padding: 0.75rem 1.4rem; cursor: pointer; font-weight: 700; }
        .btn-primary { background: linear-gradient(135deg, #1f4b99, #2d68d8); color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .alert { padding: 0.75rem 1rem; border-radius: 12px; margin-bottom: 1rem; }
        .alert-success { background: #ecfdf3; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fef2f2; border: 1px solid #fecdd3; color: #991b1b; }
    </style>
</head>
<body>
<div class="fullwidth-wrapper">
    <div class="card">
        <h1>Handover Templates</h1>
        <p>Configure the default content for each handover section. HTML is allowed in all text areas.</p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= $tone === 'success' ? 'success' : 'error' ?>" role="status"><?= admin_handover_safe($message) ?></div>
        <?php endif; ?>

        <div class="placeholder-note">
            <strong>Available placeholders:</strong>
            <div><?= implode(', ', array_map('admin_handover_safe', $placeholders)) ?></div>
        </div>

        <form method="post">
            <div class="field">
                <label for="handover_style_css">Handover Document CSS (global style)</label>
                <textarea id="handover_style_css" name="handover_style_css" rows="8"><?= admin_handover_safe($templates['handover_style_css'] ?? '') ?></textarea>
            </div>

            <?php foreach (array_diff_key(handover_template_defaults(), ['handover_style_css' => true]) as $key => $default): ?>
                <div class="field">
                    <label for="<?= admin_handover_safe($key) ?>"><?= admin_handover_safe(str_replace('_', ' ', ucfirst($key))) ?></label>
                    <textarea id="<?= admin_handover_safe($key) ?>" name="<?= admin_handover_safe($key) ?>" rows="15"><?= admin_handover_safe($templates[$key] ?? '') ?></textarea>
                </div>
            <?php endforeach; ?>

            <div class="actions">
                <a class="btn btn-secondary" href="admin-dashboard.php">Back to admin</a>
                <button type="submit" class="btn btn-primary">Save templates</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
