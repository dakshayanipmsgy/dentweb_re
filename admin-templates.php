<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
documents_seed_template_sets_if_empty();

$templatePath = documents_templates_dir() . '/template_sets.json';
$templates = json_load($templatePath, []);
$templates = is_array($templates) ? $templates : [];
$templateBlocks = documents_sync_template_block_entries($templates);
$libraryPath = documents_media_dir() . '/library.json';
$library = documents_get_media_library();
$docTheme = documents_get_doc_theme_settings();

$redirectWith = static function (string $type, string $msg, string $templateId = ''): void {
    $query = ['status' => $type, 'message' => $msg];
    if ($templateId !== '') {
        $query['template_id'] = $templateId;
    }
    header('Location: admin-templates.php?' . http_build_query($query));
    exit;
};

$sanitizeHtml = static function (string $value): string {
    $value = trim((string) $value);
    return strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }
    $action = safe_text($_POST['action'] ?? '');
    $selectedTemplateId = safe_text($_POST['template_set_id'] ?? '');

    if ($action === 'save_blocks' || $action === 'reset_blocks' || $action === 'insert_starter_res' || $action === 'insert_starter_com') {
        if ($selectedTemplateId === '' || !isset($templateBlocks[$selectedTemplateId])) {
            $redirectWith('error', 'Template set not found.');
        }

        if ($action === 'reset_blocks') {
            $templateBlocks[$selectedTemplateId]['blocks'] = documents_template_block_defaults();
        } elseif ($action === 'insert_starter_res') {
            $templateBlocks[$selectedTemplateId]['blocks']['pm_subsidy_info'] = 'PM Surya Ghar subsidy is subject to beneficiary eligibility, portal approvals and DISCOM processes.';
            $templateBlocks[$selectedTemplateId]['blocks']['system_inclusions'] = '<ul><li>ALMM Solar Modules</li><li>Inverter with remote monitoring</li><li>Mounting structure</li><li>Protections and earthing</li><li>Installation and commissioning</li></ul>';
            $templateBlocks[$selectedTemplateId]['blocks']['payment_terms'] = '<ol><li>70% advance with work order.</li><li>20% before dispatch.</li><li>10% after commissioning.</li></ol>';
            $templateBlocks[$selectedTemplateId]['blocks']['warranty'] = '<ul><li>Modules: As per OEM card</li><li>Inverter: As per OEM card</li><li>Structure: 5 years</li></ul>';
        } elseif ($action === 'insert_starter_com') {
            $templateBlocks[$selectedTemplateId]['blocks']['system_inclusions'] = '<ul><li>Detailed BoQ and engineered design</li><li>Industrial grade mounting structures</li><li>Remote monitoring and generation reports</li><li>Testing and commissioning</li></ul>';
            $templateBlocks[$selectedTemplateId]['blocks']['payment_terms'] = '<ol><li>50% with work order</li><li>40% before dispatch</li><li>10% post commissioning</li></ol>';
            $templateBlocks[$selectedTemplateId]['blocks']['warranty'] = '<p>Product warranties as per manufacturer terms. Service support as agreed in contract.</p>';
        } else {
            foreach (documents_template_block_defaults() as $key => $_) {
                $templateBlocks[$selectedTemplateId]['blocks'][$key] = $sanitizeHtml((string) ($_POST['block_' . $key] ?? ''));
            }
            $templateBlocks[$selectedTemplateId]['attachments']['include_ongrid_diagram'] = isset($_POST['include_ongrid_diagram']);
            $templateBlocks[$selectedTemplateId]['attachments']['include_hybrid_diagram'] = isset($_POST['include_hybrid_diagram']);
            $templateBlocks[$selectedTemplateId]['attachments']['include_offgrid_diagram'] = isset($_POST['include_offgrid_diagram']);
            $templateBlocks[$selectedTemplateId]['attachments']['ongrid_diagram_media_id'] = safe_text($_POST['ongrid_diagram_media_id'] ?? '');
            $templateBlocks[$selectedTemplateId]['attachments']['hybrid_diagram_media_id'] = safe_text($_POST['hybrid_diagram_media_id'] ?? '');
            $templateBlocks[$selectedTemplateId]['attachments']['offgrid_diagram_media_id'] = safe_text($_POST['offgrid_diagram_media_id'] ?? '');
        }

        $templateBlocks[$selectedTemplateId]['updated_at'] = date('c');
        $saved = json_save(documents_templates_dir() . '/template_blocks.json', $templateBlocks);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to save template blocks.', $selectedTemplateId);
        }
        $redirectWith('success', 'Template blocks saved.', $selectedTemplateId);
    }


    if ($action === 'save_doc_theme') {
        $settings = documents_get_doc_theme_settings();
        $target = safe_text($_POST['theme_scope'] ?? 'global');
        $payload = [
            'enable_background' => isset($_POST['enable_background']),
            'font_scale' => max(0.85, min(1.25, (float) ($_POST['font_scale'] ?? 1))),
            'primary_color' => safe_text($_POST['primary_color'] ?? '#0B3A6A'),
            'secondary_color' => safe_text($_POST['secondary_color'] ?? '#1F7A6B'),
            'accent_color' => safe_text($_POST['accent_color'] ?? '#F2B705'),
            'text_color' => safe_text($_POST['text_color'] ?? '#1B1B1B'),
            'muted_text_color' => safe_text($_POST['muted_text_color'] ?? '#666666'),
            'box_bg' => safe_text($_POST['box_bg'] ?? '#F6F8FB'),
            'background_media_id' => safe_text($_POST['background_media_id'] ?? ''),
            'show_cover_page' => isset($_POST['show_cover_page']),
            'show_system_overview_page' => isset($_POST['show_system_overview_page']),
            'show_financials_page' => isset($_POST['show_financials_page']),
            'show_impact_page' => isset($_POST['show_impact_page']),
            'show_next_steps_page' => isset($_POST['show_next_steps_page']),
            'show_contact_page' => isset($_POST['show_contact_page']),
            'show_placeholders_when_missing' => isset($_POST['show_placeholders_when_missing']),
            'co2_factor_kg_per_kwh' => safe_text($_POST['co2_factor_kg_per_kwh'] ?? ''),
            'trees_factor_kg_per_tree_per_year' => safe_text($_POST['trees_factor_kg_per_tree_per_year'] ?? ''),
        ];
        if ($target === 'global') {
            $settings['global'] = array_merge((array) ($settings['global'] ?? []), $payload);
        } elseif ($selectedTemplateId !== '') {
            $settings['per_template_set'][$selectedTemplateId] = array_merge((array) ($settings['per_template_set'][$selectedTemplateId] ?? []), $payload);
        }
        foreach ($library as $media) {
            if (!is_array($media)) { continue; }
            if ((string) ($media['id'] ?? '') === (string) ($payload['background_media_id'] ?? '')) {
                $path = (string) ($media['file_path'] ?? '');
                if ($target === 'global') { $settings['global']['background_media_path'] = $path; }
                elseif ($selectedTemplateId !== '') { $settings['per_template_set'][$selectedTemplateId]['background_media_path'] = $path; }
            }
        }
        $saved = json_save(documents_doc_theme_path(), $settings);
        if (!$saved['ok']) { $redirectWith('error', 'Unable to save document theme.', $selectedTemplateId); }
        $redirectWith('success', 'Document theme saved.', $selectedTemplateId);
    }

    if ($action === 'upload_media') {
        $mediaType = safe_text($_POST['media_type'] ?? '');
        $title = safe_text($_POST['title'] ?? '');
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? ''))), static fn($t): bool => $t !== ''));
        if ($title === '' || !in_array($mediaType, ['background', 'diagram'], true)) {
            $redirectWith('error', 'Provide media title and type.', $selectedTemplateId);
        }
        $dir = $mediaType === 'background' ? documents_public_backgrounds_dir() : documents_public_diagrams_dir();
        $prefix = $mediaType === 'background' ? 'bg_' . date('Ymd_His') . '_' : 'diag_' . date('Ymd_His') . '_';
        $upload = documents_handle_image_upload($_FILES['media_upload'] ?? [], $dir, $prefix);
        if (!$upload['ok']) {
            $redirectWith('error', (string) $upload['error'], $selectedTemplateId);
        }
        $publicPath = ($mediaType === 'background' ? '/images/documents/backgrounds/' : '/images/documents/diagrams/') . $upload['filename'];
        $library[] = [
            'id' => 'med_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
            'type' => $mediaType,
            'title' => $title,
            'file_path' => $publicPath,
            'tags' => $tags,
            'archived_flag' => false,
            'uploaded_at' => date('c'),
            'uploaded_by' => (string) (current_user()['full_name'] ?? 'admin'),
        ];
        $saved = json_save($libraryPath, $library);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to update media library.', $selectedTemplateId);
        }
        $redirectWith('success', 'Media uploaded.', $selectedTemplateId);
    }

    if ($action === 'toggle_archive_media') {
        $mediaId = safe_text($_POST['media_id'] ?? '');
        foreach ($library as &$row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $mediaId) {
                continue;
            }
            $row['archived_flag'] = !($row['archived_flag'] ?? false);
        }
        unset($row);
        json_save($libraryPath, $library);
        $redirectWith('success', 'Media archive status updated.', $selectedTemplateId);
    }

    if ($action === 'set_template_background') {
        $mediaId = safe_text($_POST['media_id'] ?? '');
        $opacity = max(0.1, min(1.0, (float) ($_POST['background_opacity'] ?? 1)));
        $path = '';
        foreach ($library as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $mediaId || (string) ($row['type'] ?? '') !== 'background') {
                continue;
            }
            $path = (string) ($row['file_path'] ?? '');
            break;
        }
        if ($path === '') {
            $redirectWith('error', 'Background media not found.', $selectedTemplateId);
        }
        foreach ($templates as &$template) {
            if (!is_array($template) || (string) ($template['id'] ?? '') !== $selectedTemplateId) {
                continue;
            }
            $template['default_doc_theme']['page_background_image'] = $path;
            $template['default_doc_theme']['page_background_opacity'] = $opacity;
            $template['updated_at'] = date('c');
        }
        unset($template);
        json_save($templatePath, $templates);
        $redirectWith('success', 'Default template background updated.', $selectedTemplateId);
    }
}

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$selectedTemplateId = safe_text($_GET['template_id'] ?? '');
if ($selectedTemplateId === '' && isset($templates[0]['id'])) {
    $selectedTemplateId = (string) $templates[0]['id'];
}
$selectedBlocks = documents_default_template_block_entry();
if ($selectedTemplateId !== '' && isset($templateBlocks[$selectedTemplateId])) {
    $selectedBlocks = $templateBlocks[$selectedTemplateId];
}
$diagramOptions = array_values(array_filter($library, static fn($m): bool => is_array($m) && (string) ($m['type'] ?? '') === 'diagram' && !($m['archived_flag'] ?? false)));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Template Blocks & Media</title>
<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}textarea,input,select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:8px}textarea{min-height:90px}.ok{background:#ecfdf5;padding:8px;border:1px solid #34d399}.err{background:#fef2f2;padding:8px;border:1px solid #f87171}.media-item{display:grid;grid-template-columns:110px 1fr;gap:10px;border:1px solid #dbe1ea;border-radius:8px;padding:8px;margin-bottom:8px}.thumb{max-width:100px;max-height:80px}</style>
</head><body><main class="wrap">
<div class="card"><h1>Template Blocks & Media Library</h1><a class="btn secondary" href="admin-documents.php?tab=templates">Back to Documents</a></div>
<?php if ($message !== ''): ?><div class="<?= $status === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card">
<form method="get"><label>Template Set</label><select name="template_id" onchange="this.form.submit()"><?php foreach ($templates as $tpl): if (!is_array($tpl)) continue; ?><option value="<?= htmlspecialchars((string)$tpl['id'], ENT_QUOTES) ?>" <?= ((string)$tpl['id']===$selectedTemplateId)?'selected':'' ?>><?= htmlspecialchars((string)$tpl['name'], ENT_QUOTES) ?><?= !empty($tpl['archived_flag']) ? ' [Archived]' : '' ?></option><?php endforeach; ?></select></form>
<form method="post" style="margin-top:10px">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_blocks"><input type="hidden" name="template_set_id" value="<?= htmlspecialchars($selectedTemplateId, ENT_QUOTES) ?>">
<div class="grid">
<?php foreach (documents_template_block_defaults() as $key => $_): ?><div style="grid-column:1/-1"><label><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key)), ENT_QUOTES) ?></label><textarea name="block_<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars((string)($selectedBlocks['blocks'][$key] ?? ''), ENT_QUOTES) ?></textarea></div><?php endforeach; ?>
<div><label><input type="checkbox" name="include_ongrid_diagram" <?= !empty($selectedBlocks['attachments']['include_ongrid_diagram'])?'checked':'' ?>> Include Ongrid Diagram</label><select name="ongrid_diagram_media_id"><option value="">Select diagram</option><?php foreach($diagramOptions as $d): ?><option value="<?= htmlspecialchars((string)$d['id'], ENT_QUOTES) ?>" <?= ((string)($selectedBlocks['attachments']['ongrid_diagram_media_id'] ?? '') === (string)$d['id'])?'selected':'' ?>><?= htmlspecialchars((string)$d['title'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label><input type="checkbox" name="include_hybrid_diagram" <?= !empty($selectedBlocks['attachments']['include_hybrid_diagram'])?'checked':'' ?>> Include Hybrid Diagram</label><select name="hybrid_diagram_media_id"><option value="">Select diagram</option><?php foreach($diagramOptions as $d): ?><option value="<?= htmlspecialchars((string)$d['id'], ENT_QUOTES) ?>" <?= ((string)($selectedBlocks['attachments']['hybrid_diagram_media_id'] ?? '') === (string)$d['id'])?'selected':'' ?>><?= htmlspecialchars((string)$d['title'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label><input type="checkbox" name="include_offgrid_diagram" <?= !empty($selectedBlocks['attachments']['include_offgrid_diagram'])?'checked':'' ?>> Include Offgrid Diagram</label><select name="offgrid_diagram_media_id"><option value="">Select diagram</option><?php foreach($diagramOptions as $d): ?><option value="<?= htmlspecialchars((string)$d['id'], ENT_QUOTES) ?>" <?= ((string)($selectedBlocks['attachments']['offgrid_diagram_media_id'] ?? '') === (string)$d['id'])?'selected':'' ?>><?= htmlspecialchars((string)$d['title'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
</div><br>
<button class="btn" type="submit">Save Blocks</button>
<button class="btn secondary" type="submit" name="action" value="insert_starter_res">Insert Residential PM starter text (DE/534 style)</button>
<button class="btn secondary" type="submit" name="action" value="insert_starter_com">Insert Commercial/Industrial starter text (DE/425 style)</button>
<button class="btn secondary" type="submit" name="action" value="reset_blocks" onclick="return confirm('Reset all blocks to blank?')">Reset Blocks to Blank</button>
</form>
<div class="card" style="margin-top:10px"><h3>Preview</h3><?php foreach (($selectedBlocks['blocks'] ?? []) as $key=>$value): ?><h4><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$key)), ENT_QUOTES) ?></h4><div><?= strip_tags((string)$value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>') ?></div><?php endforeach; ?></div>
</div>


<?php $effectiveTheme = documents_get_effective_doc_theme($selectedTemplateId); $themeSpecific = is_array($docTheme['per_template_set'][$selectedTemplateId] ?? null) ? $docTheme['per_template_set'][$selectedTemplateId] : []; ?>
<div class="card"><h2>Document Theme</h2>
<form method="post" class="grid">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="save_doc_theme"><input type="hidden" name="template_set_id" value="<?= htmlspecialchars($selectedTemplateId, ENT_QUOTES) ?>">
<div><label>Scope</label><select name="theme_scope"><option value="global">Global</option><option value="template" <?= $selectedTemplateId!==''?'selected':'' ?>>Template Specific</option></select></div>
<div><label><input type="checkbox" name="enable_background" <?= !empty($effectiveTheme['enable_background'])?'checked':'' ?>> Enable Background</label></div>
<div><label>Font Scale (0.85-1.25)</label><input type="range" min="0.85" max="1.25" step="0.01" name="font_scale" value="<?= htmlspecialchars((string)$effectiveTheme['font_scale'], ENT_QUOTES) ?>"></div>
<div><label>Primary Color</label><input name="primary_color" value="<?= htmlspecialchars((string)$effectiveTheme['primary_color'], ENT_QUOTES) ?>"></div>
<div><label>Secondary Color</label><input name="secondary_color" value="<?= htmlspecialchars((string)$effectiveTheme['secondary_color'], ENT_QUOTES) ?>"></div>
<div><label>Accent Color</label><input name="accent_color" value="<?= htmlspecialchars((string)$effectiveTheme['accent_color'], ENT_QUOTES) ?>"></div>
<div><label>Text Color</label><input name="text_color" value="<?= htmlspecialchars((string)$effectiveTheme['text_color'], ENT_QUOTES) ?>"></div>
<div><label>Muted Text</label><input name="muted_text_color" value="<?= htmlspecialchars((string)$effectiveTheme['muted_text_color'], ENT_QUOTES) ?>"></div>
<div><label>Box BG</label><input name="box_bg" value="<?= htmlspecialchars((string)$effectiveTheme['box_bg'], ENT_QUOTES) ?>"></div>
<div><label>Background Media</label><select name="background_media_id"><option value="">None</option><?php foreach($library as $item): if(!is_array($item) || (string)($item['type']??'')!=='background' || !empty($item['archived_flag'])) continue; ?><option value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>" <?= ((string)($effectiveTheme['background_media_id'] ?? '')===(string)$item['id'])?'selected':'' ?>><?= htmlspecialchars((string)$item['title'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label><input type="checkbox" name="show_cover_page" <?= !array_key_exists('show_cover_page',$effectiveTheme)||!empty($effectiveTheme['show_cover_page'])?'checked':'' ?>> Cover Page</label></div>
<div><label><input type="checkbox" name="show_system_overview_page" <?= !array_key_exists('show_system_overview_page',$effectiveTheme)||!empty($effectiveTheme['show_system_overview_page'])?'checked':'' ?>> System Overview</label></div>
<div><label><input type="checkbox" name="show_financials_page" <?= !array_key_exists('show_financials_page',$effectiveTheme)||!empty($effectiveTheme['show_financials_page'])?'checked':'' ?>> Financials</label></div>
<div><label><input type="checkbox" name="show_impact_page" <?= !array_key_exists('show_impact_page',$effectiveTheme)||!empty($effectiveTheme['show_impact_page'])?'checked':'' ?>> Impact</label></div>
<div><label><input type="checkbox" name="show_next_steps_page" <?= !array_key_exists('show_next_steps_page',$effectiveTheme)||!empty($effectiveTheme['show_next_steps_page'])?'checked':'' ?>> Next Steps</label></div>
<div><label><input type="checkbox" name="show_contact_page" <?= !array_key_exists('show_contact_page',$effectiveTheme)||!empty($effectiveTheme['show_contact_page'])?'checked':'' ?>> Contact</label></div>
<div><label>Default CO2 factor</label><input name="co2_factor_kg_per_kwh" value="<?= htmlspecialchars((string)($effectiveTheme['co2_factor_kg_per_kwh'] ?? ''), ENT_QUOTES) ?>"></div>
<div><label>Default trees factor</label><input name="trees_factor_kg_per_tree_per_year" value="<?= htmlspecialchars((string)($effectiveTheme['trees_factor_kg_per_tree_per_year'] ?? ''), ENT_QUOTES) ?>"></div>
<div style="grid-column:1/-1"><button class="btn" type="submit">Save Theme</button></div>
</form></div>
<div class="card"><h2>Media Library</h2>
<form method="post" enctype="multipart/form-data" class="grid">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="upload_media"><input type="hidden" name="template_set_id" value="<?= htmlspecialchars($selectedTemplateId, ENT_QUOTES) ?>">
<div><label>Type</label><select name="media_type"><option value="background">Background</option><option value="diagram">Diagram</option></select></div>
<div><label>Title</label><input type="text" name="title" required></div>
<div><label>Tags (comma separated)</label><input type="text" name="tags"></div>
<div><label>Image</label><input type="file" name="media_upload" accept="image/jpeg,image/png,image/webp" required></div>
<div><button class="btn" type="submit">Upload</button></div>
</form>
<?php foreach ($library as $item): if(!is_array($item)) continue; ?>
<div class="media-item">
<div><img class="thumb" src="<?= htmlspecialchars((string)$item['file_path'], ENT_QUOTES) ?>" alt="thumb"></div>
<div><strong><?= htmlspecialchars((string)$item['title'], ENT_QUOTES) ?></strong> (<?= htmlspecialchars((string)$item['type'], ENT_QUOTES) ?>)<?= !empty($item['archived_flag']) ? ' [Archived]' : '' ?><br>
<small><?= htmlspecialchars(implode(', ', is_array($item['tags'] ?? null) ? $item['tags'] : []), ENT_QUOTES) ?></small><br>
<form method="post" style="display:inline-block;margin-top:6px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="toggle_archive_media"><input type="hidden" name="template_set_id" value="<?= htmlspecialchars($selectedTemplateId, ENT_QUOTES) ?>"><input type="hidden" name="media_id" value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>"><button class="btn secondary" type="submit"><?= !empty($item['archived_flag']) ? 'Unarchive' : 'Archive' ?></button></form>
<?php if ((string)($item['type'] ?? '') === 'background' && empty($item['archived_flag'])): ?>
<form method="post" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="set_template_background"><input type="hidden" name="template_set_id" value="<?= htmlspecialchars($selectedTemplateId, ENT_QUOTES) ?>"><input type="hidden" name="media_id" value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>"><input type="number" step="0.1" min="0.1" max="1" name="background_opacity" value="1" style="width:80px"><button class="btn" type="submit">Use as default background for selected template set</button></form>
<?php endif; ?>
</div></div>
<?php endforeach; ?>
</div>
</main></body></html>
