<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();

$docTypes = ['quotation', 'proforma', 'agreement', 'challan', 'invoice_public', 'invoice_internal', 'receipt', 'sales_return'];
$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];

$companyPath = documents_settings_dir() . '/company_profile.json';
$numberingPath = documents_settings_dir() . '/numbering_rules.json';
$templatePath = documents_templates_dir() . '/template_sets.json';

documents_ensure_structure();
documents_seed_template_sets_if_empty();

$redirectWith = static function (string $tab, string $type, string $msg): void {
    $query = http_build_query([
        'tab' => $tab,
        'status' => $type,
        'message' => $msg,
    ]);
    header('Location: admin-documents.php?' . $query);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('company', 'error', 'Security validation failed. Please retry.');
    }

    $action = safe_text($_POST['action'] ?? '');

    if ($action === 'save_company_profile') {
        $profile = json_load($companyPath, documents_company_profile_defaults());
        $profile = array_merge(documents_company_profile_defaults(), is_array($profile) ? $profile : []);

        $fields = array_keys(documents_company_profile_defaults());
        foreach ($fields as $field) {
            if ($field === 'logo_path' || $field === 'updated_at') {
                continue;
            }
            $profile[$field] = safe_text($_POST[$field] ?? '');
        }

        if (isset($_FILES['company_logo_upload']) && (int) ($_FILES['company_logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = documents_handle_image_upload($_FILES['company_logo_upload'], documents_public_branding_dir(), 'logo_');
            if (!$upload['ok']) {
                $redirectWith('company', 'error', (string) $upload['error']);
            }
            $profile['logo_path'] = '/images/documents/branding/' . $upload['filename'];
        }

        $profile['updated_at'] = date('c');
        $saved = json_save($companyPath, $profile);
        if (!$saved['ok']) {
            $redirectWith('company', 'error', 'Unable to save company profile.');
        }

        $redirectWith('company', 'success', 'Company profile saved successfully.');
    }

    if ($action === 'save_numbering_rule') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $docType = safe_text($_POST['doc_type'] ?? '');
        $segment = safe_text($_POST['segment'] ?? '');
        if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
            $redirectWith('numbering', 'error', 'Invalid document type or segment.');
        }

        $seqDigits = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
        $seqStart = max(1, (int) ($_POST['seq_start'] ?? 1));
        $seqCurrent = max(1, (int) ($_POST['seq_current'] ?? $seqStart));

        $payload['rules'][] = [
            'id' => safe_slug($docType . '-' . $segment . '-' . bin2hex(random_bytes(3))),
            'doc_type' => $docType,
            'segment' => $segment,
            'prefix' => safe_text($_POST['prefix'] ?? ''),
            'format' => safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}'),
            'seq_digits' => $seqDigits,
            'seq_start' => $seqStart,
            'seq_current' => $seqCurrent,
            'active' => isset($_POST['active']),
            'archived_flag' => false,
        ];
        $payload['updated_at'] = date('c');

        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering rule.');
        }

        $redirectWith('numbering', 'success', 'Numbering rule added.');
    }

    if ($action === 'update_numbering_rule' || $action === 'archive_numbering_rule' || $action === 'reset_counter') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $ruleId = safe_text($_POST['rule_id'] ?? '');
        $found = false;
        foreach ($payload['rules'] as &$rule) {
            if ((string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $found = true;

            if ($action === 'archive_numbering_rule') {
                $rule['archived_flag'] = true;
                $rule['active'] = false;
                break;
            }

            if ($action === 'reset_counter') {
                $rule['seq_current'] = (int) ($rule['seq_start'] ?? 1);
                break;
            }

            $docType = safe_text($_POST['doc_type'] ?? '');
            $segment = safe_text($_POST['segment'] ?? '');
            if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
                unset($rule);
                $redirectWith('numbering', 'error', 'Invalid rule update values.');
            }

            $rule['doc_type'] = $docType;
            $rule['segment'] = $segment;
            $rule['prefix'] = safe_text($_POST['prefix'] ?? '');
            $rule['format'] = safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');
            $rule['seq_digits'] = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
            $rule['seq_start'] = max(1, (int) ($_POST['seq_start'] ?? 1));
            $rule['seq_current'] = max(1, (int) ($_POST['seq_current'] ?? 1));
            $rule['active'] = isset($_POST['active']);
        }
        unset($rule);

        if (!$found) {
            $redirectWith('numbering', 'error', 'Rule not found.');
        }

        $payload['updated_at'] = date('c');
        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering updates.');
        }

        $msg = 'Numbering rule updated.';
        if ($action === 'archive_numbering_rule') {
            $msg = 'Numbering rule archived.';
        } elseif ($action === 'reset_counter') {
            $msg = 'Counter reset to start value.';
        }
        $redirectWith('numbering', 'success', $msg);
    }

    if ($action === 'save_quotation_settings') {
        $settings = documents_get_quotation_settings();
        foreach (['RES', 'COM', 'IND', 'INST'] as $segment) {
            $settings['segments'][$segment]['unit_rate_rs_per_kwh'] = (float) ($_POST['segment_' . $segment . '_unit_rate'] ?? $settings['segments'][$segment]['unit_rate_rs_per_kwh']);
            $settings['segments'][$segment]['annual_generation_kwh_per_kw'] = (float) ($_POST['segment_' . $segment . '_annual_generation'] ?? $settings['segments'][$segment]['annual_generation_kwh_per_kw']);
            $settings['segments'][$segment]['bank_interest_rate_percent'] = (float) ($_POST['segment_' . $segment . '_interest'] ?? $settings['segments'][$segment]['bank_interest_rate_percent']);
            $settings['segments'][$segment]['bank_tenure_years'] = max(1, (int) ($_POST['segment_' . $segment . '_tenure'] ?? $settings['segments'][$segment]['bank_tenure_years']));
        }

        $settings['emission_factor_kg_per_kwh'] = max(0, (float) ($_POST['emission_factor_kg_per_kwh'] ?? $settings['emission_factor_kg_per_kwh']));
        $settings['tree_absorption_kg_per_year'] = max(0.01, (float) ($_POST['tree_absorption_kg_per_year'] ?? $settings['tree_absorption_kg_per_year']));
        $settings['watermark']['enabled'] = isset($_POST['watermark_enabled']);
        $settings['watermark']['image_path'] = safe_text($_POST['watermark_image_path'] ?? $settings['watermark']['image_path']);
        $settings['watermark']['opacity'] = max(0.02, min(0.2, (float) ($_POST['watermark_opacity'] ?? $settings['watermark']['opacity'])));
        $settings['watermark']['scale_percent'] = max(20, min(120, (float) ($_POST['watermark_scale_percent'] ?? $settings['watermark']['scale_percent'])));

        if (isset($_FILES['watermark_image_upload']) && (int) ($_FILES['watermark_image_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = documents_handle_image_upload($_FILES['watermark_image_upload'], documents_public_watermarks_dir(), 'watermark_');
            if (!$upload['ok']) {
                $redirectWith('quotation_settings', 'error', (string) $upload['error']);
            }
            $settings['watermark']['image_path'] = '/images/documents/watermarks/' . $upload['filename'];
        }

        $settings['typography']['base_font_size_px'] = max(11, min(20, (float) ($_POST['base_font_size_px'] ?? $settings['typography']['base_font_size_px'])));
        $settings['typography']['heading_scale'] = max(0.8, min(1.4, (float) ($_POST['heading_scale'] ?? $settings['typography']['heading_scale'])));
        foreach (['primary', 'secondary', 'accent', 'muted'] as $colorKey) {
            $color = safe_text($_POST['color_' . $colorKey] ?? '');
            if ($color !== '') {
                $settings['colors'][$colorKey] = $color;
            }
        }

        $saved = json_save(documents_settings_dir() . '/quotation_defaults.json', $settings);
        if (!$saved['ok']) {
            $redirectWith('quotation_settings', 'error', 'Unable to save quotation settings.');
        }
        $redirectWith('quotation_settings', 'success', 'Quotation settings saved successfully.');
    }


    if ($action === 'save_template_set' || $action === 'archive_template_set' || $action === 'unarchive_template_set') {
        $rows = json_load($templatePath, []);
        $rows = is_array($rows) ? $rows : [];

        if ($action === 'save_template_set') {
            $templateId = safe_text($_POST['template_id'] ?? '');
            $name = safe_text($_POST['name'] ?? '');
            $segment = safe_text($_POST['segment'] ?? 'RES');
            $notes = safe_text($_POST['notes'] ?? '');
            $opacity = (float) ($_POST['page_background_opacity'] ?? 1);
            $opacity = max(0.1, min(1.0, $opacity));

            if ($name === '' || !in_array($segment, $segments, true)) {
                $redirectWith('templates', 'error', 'Template name and segment are required.');
            }

            $newBgPath = '';
            if (isset($_FILES['template_background_upload']) && (int) ($_FILES['template_background_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = documents_handle_image_upload($_FILES['template_background_upload'], documents_public_backgrounds_dir(), 'background_');
                if (!$upload['ok']) {
                    $redirectWith('templates', 'error', (string) $upload['error']);
                }
                $newBgPath = '/images/documents/backgrounds/' . $upload['filename'];
            }

            $matched = false;
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') !== $templateId || $templateId === '') {
                    continue;
                }
                $matched = true;
                $row['name'] = $name;
                $row['segment'] = $segment;
                $row['notes'] = $notes;
                $theme = is_array($row['default_doc_theme'] ?? null) ? $row['default_doc_theme'] : [];
                if ($newBgPath !== '') {
                    $theme['page_background_image'] = $newBgPath;
                }
                $theme['page_background_opacity'] = $opacity;
                $row['default_doc_theme'] = $theme;
                $row['updated_at'] = date('c');
            }
            unset($row);

            if (!$matched) {
                $idBase = safe_slug($name);
                if ($idBase === '') {
                    $idBase = 'template-' . time();
                }
                $id = $idBase;
                $counter = 1;
                $existingIds = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $rows);
                while (in_array($id, $existingIds, true)) {
                    $id = $idBase . '-' . $counter;
                    $counter++;
                }

                $now = date('c');
                $rows[] = [
                    'id' => $id,
                    'name' => $name,
                    'segment' => $segment,
                    'default_doc_theme' => [
                        'page_background_image' => $newBgPath,
                        'page_background_opacity' => $opacity,
                    ],
                    'notes' => $notes,
                    'archived_flag' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $saved = json_save($templatePath, $rows);
            if (!$saved['ok']) {
                $redirectWith('templates', 'error', 'Unable to save template set.');
            }

            $redirectWith('templates', 'success', 'Template set saved successfully.');
        }

        $templateId = safe_text($_POST['template_id'] ?? '');
        $found = false;
        foreach ($rows as &$row) {
            if ((string) ($row['id'] ?? '') !== $templateId) {
                continue;
            }
            $found = true;
            $row['archived_flag'] = ($action === 'archive_template_set');
            $row['updated_at'] = date('c');
        }
        unset($row);

        if (!$found) {
            $redirectWith('templates', 'error', 'Template set not found.');
        }

        $saved = json_save($templatePath, $rows);
        if (!$saved['ok']) {
            $redirectWith('templates', 'error', 'Unable to update template archive status.');
        }

        $redirectWith('templates', 'success', $action === 'archive_template_set' ? 'Template archived.' : 'Template unarchived.');
    }
}

$activeTab = safe_text($_GET['tab'] ?? 'company');
if (!in_array($activeTab, ['company', 'numbering', 'templates', 'quotation_settings'], true)) {
    $activeTab = 'company';
}

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');

$company = json_load($companyPath, documents_company_profile_defaults());
$company = array_merge(documents_company_profile_defaults(), is_array($company) ? $company : []);

$numbering = json_load($numberingPath, documents_numbering_defaults());
$numbering = array_merge(documents_numbering_defaults(), is_array($numbering) ? $numbering : []);
$numbering['rules'] = is_array($numbering['rules']) ? $numbering['rules'] : [];

$templates = json_load($templatePath, []);
$templates = is_array($templates) ? $templates : [];
$quotationSettings = documents_get_quotation_settings();
$watermarkFiles = glob(documents_public_watermarks_dir() . '/*.{png,jpg,jpeg,webp,svg}', GLOB_BRACE) ?: [];

$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Documents &amp; Billing Control Center</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6fa; color: #111827; }
    .page { width: 100%; max-width: none; padding: 1.25rem; box-sizing: border-box; }
    .top { display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .top h1 { margin:0; font-size:1.45rem; }
    .btn { display:inline-block; background:#1d4ed8; color:#fff; text-decoration:none; padding:0.55rem 0.8rem; border-radius:8px; border:none; cursor:pointer; font-size:0.92rem; }
    .btn.secondary { background:#fff; color:#1f2937; border:1px solid #cbd5e1; }
    .btn.warn { background:#b91c1c; }
    .banner { margin-bottom:1rem; padding:0.75rem 0.9rem; border-radius:8px; }
    .banner.success { background:#ecfdf5; border:1px solid #34d399; color:#065f46; }
    .banner.error { background:#fef2f2; border:1px solid #f87171; color:#991b1b; }
    .tabs { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; }
    .tab { text-decoration:none; background:#e2e8f0; color:#1f2937; padding:0.55rem 0.8rem; border-radius:8px; font-weight:600; }
    .tab.active { background:#1d4ed8; color:#fff; }
    .tab.disabled { opacity:0.55; pointer-events:none; }
    .panel { background:#fff; border:1px solid #dbe1ea; border-radius:12px; padding:1rem; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0.8rem; }
    label { display:block; font-size:0.84rem; margin-bottom:0.3rem; font-weight:600; }
    input, select, textarea { width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:80px; }
    table { width:100%; border-collapse: collapse; margin-bottom:1rem; }
    th, td { border:1px solid #dbe1ea; padding:0.5rem; text-align:left; vertical-align:top; }
    th { background:#f8fafc; }
    .logo-preview { max-height:90px; display:block; margin-top:0.5rem; }
    .muted { color:#64748b; font-size:0.86rem; }
  </style>
</head>
<body>
  <main class="page">
    <div class="top">
      <div>
        <h1>Documents &amp; Billing Control Center</h1>
        <p class="muted">Admin: <?= htmlspecialchars((string) ($user['full_name'] ?? 'Administrator'), ENT_QUOTES) ?></p>
      </div>
      <div>
        <a class="btn" href="admin-quotations.php">Quotations</a>
        <a class="btn" href="admin-challans.php">Challans</a>
        <a class="btn" href="admin-agreements.php">Agreements</a>
        <a class="btn secondary" href="admin-templates.php">Template Blocks &amp; Media</a>
        <a class="btn secondary" href="admin-dashboard.php">Back to Admin Dashboard</a>
      </div>
    </div>

    <?php if ($message !== '' && ($status === 'success' || $status === 'error')): ?>
      <div class="banner <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <nav class="tabs">
      <a class="tab <?= $activeTab === 'company' ? 'active' : '' ?>" href="?tab=company">Company Profile &amp; Branding</a>
      <a class="tab <?= $activeTab === 'numbering' ? 'active' : '' ?>" href="?tab=numbering">Numbering Rules</a>
      <a class="tab <?= $activeTab === 'templates' ? 'active' : '' ?>" href="?tab=templates">Template Sets</a>
      <a class="tab <?= $activeTab === 'quotation_settings' ? 'active' : '' ?>" href="?tab=quotation_settings">Quotation Settings</a>
      <a class="tab" href="admin-templates.php">Template Blocks &amp; Media</a>
      <a class="tab" href="admin-quotations.php">Quotation Manager</a>
      <a class="tab" href="admin-challans.php">Challans</a>
      <a class="tab" href="admin-agreements.php">Agreements</a>
      <span class="tab disabled">CSV Import (Phase 2+)</span>
    </nav>

    <?php if ($activeTab === 'company'): ?>
      <section class="panel">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_company_profile" />
          <div class="grid">
            <?php foreach ($company as $key => $value): ?>
              <?php if ($key === 'logo_path' || $key === 'updated_at') { continue; } ?>
              <div>
                <label for="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key)), ENT_QUOTES) ?></label>
                <input id="<?= htmlspecialchars($key, ENT_QUOTES) ?>" type="text" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>" />
              </div>
            <?php endforeach; ?>
            <div>
              <label for="company_logo_upload">Company Logo Upload</label>
              <input id="company_logo_upload" type="file" name="company_logo_upload" accept="image/*" />
              <?php if ((string) $company['logo_path'] !== ''): ?>
                <img class="logo-preview" src="<?= htmlspecialchars((string) $company['logo_path'], ENT_QUOTES) ?>" alt="Current logo" />
              <?php endif; ?>
            </div>
          </div>
          <p class="muted">Last updated: <?= htmlspecialchars((string) ($company['updated_at'] ?: 'Never'), ENT_QUOTES) ?></p>
          <button class="btn" type="submit">Save Company Profile</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'numbering'): ?>
      <section class="panel">
        <p class="muted">Financial year mode: <?= htmlspecialchars((string) $numbering['financial_year_mode'], ENT_QUOTES) ?> · FY Start Month: <?= (int) $numbering['fy_start_month'] ?> · Current FY: <?= htmlspecialchars(current_fy_string((int) $numbering['fy_start_month']), ENT_QUOTES) ?></p>
        <table>
          <thead>
            <tr><th>Type</th><th>Segment</th><th>Prefix</th><th>Format</th><th>Digits</th><th>Start</th><th>Current</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php if ($numbering['rules'] === []): ?>
              <tr><td colspan="9">No numbering rules added yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($numbering['rules'] as $rule): ?>
              <?php if (!is_array($rule) || !empty($rule['archived_flag'])) { continue; } ?>
              <tr>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                  <input type="hidden" name="rule_id" value="<?= htmlspecialchars((string) ($rule['id'] ?? ''), ENT_QUOTES) ?>" />
                  <td><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>" <?= ($docType === (string) ($rule['doc_type'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ($segment === (string) ($rule['segment'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><input type="text" name="prefix" value="<?= htmlspecialchars((string) ($rule['prefix'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="text" name="format" value="<?= htmlspecialchars((string) ($rule['format'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="number" name="seq_digits" min="2" max="6" value="<?= (int) ($rule['seq_digits'] ?? 4) ?>" /></td>
                  <td><input type="number" name="seq_start" min="1" value="<?= (int) ($rule['seq_start'] ?? 1) ?>" /></td>
                  <td><input type="number" name="seq_current" min="1" value="<?= (int) ($rule['seq_current'] ?? 1) ?>" /></td>
                  <td><label><input type="checkbox" name="active" <?= !empty($rule['active']) ? 'checked' : '' ?> /> Active</label></td>
                  <td>
                    <button class="btn" type="submit" name="action" value="update_numbering_rule">Save</button>
                    <button class="btn secondary" type="submit" name="action" value="reset_counter">Reset Counter</button>
                    <button class="btn warn" type="submit" name="action" value="archive_numbering_rule">Archive</button>
                  </td>
                </form>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new rule</h3>
        <form method="post" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_numbering_rule" />
          <div><label>Document Type</label><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>"><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Prefix</label><input type="text" name="prefix" value="DE/QTN" /></div>
          <div><label>Format</label><input type="text" name="format" value="{{prefix}}/{{segment}}/{{fy}}/{{seq}}" /></div>
          <div><label>Sequence Digits</label><input type="number" name="seq_digits" min="2" max="6" value="4" /></div>
          <div><label>Starting Number</label><input type="number" name="seq_start" min="1" value="1" /></div>
          <div><label>Current Number</label><input type="number" name="seq_current" min="1" value="1" /></div>
          <div><label>Status</label><label><input type="checkbox" name="active" checked /> Active</label></div>
          <div><button class="btn" type="submit">Save Numbering Rule</button></div>
        </form>
      </section>
    <?php endif; ?>


    <?php if ($activeTab === 'quotation_settings'): ?>
      <section class="panel">
        <h2>Quotation Settings</h2>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_quotation_settings" />
          <h3>Per Segment Defaults</h3>
          <div class="grid">
          <?php foreach (['RES','COM','IND','INST'] as $segment): $seg = $quotationSettings['segments'][$segment] ?? []; ?>
            <div class="card" style="padding:10px;border:1px solid #dbe1ea;border-radius:10px;">
              <strong><?= htmlspecialchars($segment, ENT_QUOTES) ?></strong>
              <label>Unit Rate (₹/kWh)</label><input type="number" step="0.01" name="segment_<?= htmlspecialchars($segment, ENT_QUOTES) ?>_unit_rate" value="<?= htmlspecialchars((string) ($seg['unit_rate_rs_per_kwh'] ?? ''), ENT_QUOTES) ?>">
              <label>Annual Generation (kWh per kW)</label><input type="number" step="1" name="segment_<?= htmlspecialchars($segment, ENT_QUOTES) ?>_annual_generation" value="<?= htmlspecialchars((string) ($seg['annual_generation_kwh_per_kw'] ?? ''), ENT_QUOTES) ?>">
              <label>Bank Interest Rate (%)</label><input type="number" step="0.01" name="segment_<?= htmlspecialchars($segment, ENT_QUOTES) ?>_interest" value="<?= htmlspecialchars((string) ($seg['bank_interest_rate_percent'] ?? ''), ENT_QUOTES) ?>">
              <label>Bank Tenure (Years)</label><input type="number" min="1" name="segment_<?= htmlspecialchars($segment, ENT_QUOTES) ?>_tenure" value="<?= htmlspecialchars((string) ($seg['bank_tenure_years'] ?? ''), ENT_QUOTES) ?>">
            </div>
          <?php endforeach; ?>
          </div>
          <h3>Environment</h3>
          <div class="grid">
            <div><label>Emission Factor (kg CO₂ per kWh)</label><input type="number" step="0.01" name="emission_factor_kg_per_kwh" value="<?= htmlspecialchars((string) ($quotationSettings['emission_factor_kg_per_kwh'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Tree Absorption (kg CO₂/tree/year)</label><input type="number" step="0.01" name="tree_absorption_kg_per_year" value="<?= htmlspecialchars((string) ($quotationSettings['tree_absorption_kg_per_year'] ?? ''), ENT_QUOTES) ?>"></div>
          </div>
          <h3>Watermark</h3>
          <div class="grid">
            <div><label><input type="checkbox" name="watermark_enabled" <?= !empty($quotationSettings['watermark']['enabled']) ? 'checked' : '' ?>> Enable Watermark</label></div>
            <div><label>Watermark Image Path</label><input name="watermark_image_path" value="<?= htmlspecialchars((string) ($quotationSettings['watermark']['image_path'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Upload New Watermark</label><input type="file" name="watermark_image_upload" accept="image/*"></div>
            <div><label>Opacity</label><input type="number" min="0.02" max="0.2" step="0.01" name="watermark_opacity" value="<?= htmlspecialchars((string) ($quotationSettings['watermark']['opacity'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Scale %</label><input type="number" min="20" max="120" step="1" name="watermark_scale_percent" value="<?= htmlspecialchars((string) ($quotationSettings['watermark']['scale_percent'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Available Watermarks</label><select onchange="this.form.watermark_image_path.value=this.value"><option value="">Select existing</option><?php foreach ($watermarkFiles as $file): $pub = '/images/documents/watermarks/' . basename((string) $file); ?><option value="<?= htmlspecialchars($pub, ENT_QUOTES) ?>"><?= htmlspecialchars(basename((string) $file), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          </div>
          <h3>Typography & Colors</h3>
          <div class="grid">
            <div><label>Base Font Size (px)</label><input type="number" min="11" max="20" step="1" name="base_font_size_px" value="<?= htmlspecialchars((string) ($quotationSettings['typography']['base_font_size_px'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Heading Scale</label><input type="number" min="0.8" max="1.4" step="0.1" name="heading_scale" value="<?= htmlspecialchars((string) ($quotationSettings['typography']['heading_scale'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Primary</label><input type="color" name="color_primary" value="<?= htmlspecialchars((string) ($quotationSettings['colors']['primary'] ?? '#0ea5e9'), ENT_QUOTES) ?>"></div>
            <div><label>Secondary</label><input type="color" name="color_secondary" value="<?= htmlspecialchars((string) ($quotationSettings['colors']['secondary'] ?? '#22c55e'), ENT_QUOTES) ?>"></div>
            <div><label>Accent</label><input type="color" name="color_accent" value="<?= htmlspecialchars((string) ($quotationSettings['colors']['accent'] ?? '#f59e0b'), ENT_QUOTES) ?>"></div>
            <div><label>Muted</label><input type="color" name="color_muted" value="<?= htmlspecialchars((string) ($quotationSettings['colors']['muted'] ?? '#64748b'), ENT_QUOTES) ?>"></div>
          </div>
          <p style="margin-top:12px;"><button class="btn" type="submit">Save Quotation Settings</button></p>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'templates'): ?>
      <section class="panel">
        <table>
          <thead><tr><th>Name</th><th>Segment</th><th>Archived</th><th>Background</th><th>Opacity</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($templates as $template): ?>
              <?php if (!is_array($template)) { continue; } ?>
              <?php $theme = is_array($template['default_doc_theme'] ?? null) ? $template['default_doc_theme'] : ['page_background_image' => '', 'page_background_opacity' => 1]; ?>
              <tr>
                <td><?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($template['segment'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= !empty($template['archived_flag']) ? 'Yes' : 'No' ?></td>
                <td><?= htmlspecialchars((string) ($theme['page_background_image'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($theme['page_background_opacity'] ?? 1), ENT_QUOTES) ?></td>
                <td>
                  <details>
                    <summary>Edit</summary>
                    <form method="post" enctype="multipart/form-data" class="grid">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="action" value="save_template_set" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <div><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?>" /></div>
                      <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ((string) ($template['segment'] ?? '') === $segment) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
                      <div><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($template['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
                      <div><label>Background image upload</label><input type="file" name="template_background_upload" accept="image/*" /></div>
                      <div><label>Background opacity (0.1-1)</label><input type="number" name="page_background_opacity" step="0.1" min="0.1" max="1" value="<?= htmlspecialchars((string) ($theme['page_background_opacity'] ?? 1), ENT_QUOTES) ?>" /></div>
                      <div><button class="btn" type="submit">Save</button></div>
                    </form>
                    <form method="post" style="margin-top:0.5rem;">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <button class="btn <?= !empty($template['archived_flag']) ? 'secondary' : 'warn' ?>" type="submit" name="action" value="<?= !empty($template['archived_flag']) ? 'unarchive_template_set' : 'archive_template_set' ?>"><?= !empty($template['archived_flag']) ? 'Unarchive' : 'Archive' ?></button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new template set</h3>
        <form method="post" enctype="multipart/form-data" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_template_set" />
          <div><label>Name</label><input type="text" name="name" required /></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><textarea name="notes"></textarea></div>
          <div><label>Background image upload</label><input type="file" name="template_background_upload" accept="image/*" /></div>
          <div><label>Background opacity</label><input type="number" name="page_background_opacity" step="0.1" min="0.1" max="1" value="1" /></div>
          <div><button class="btn" type="submit">Save Template Set</button></div>
        </form>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
