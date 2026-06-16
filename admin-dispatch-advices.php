<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/commercial_lifecycle.php';
require_once __DIR__ . '/includes/customer_document_acceptance.php';

require_admin();
documents_ensure_structure();

function da_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function da_redirect(string $message, string $status = 'success', string $tab = 'active', array $extra = []): void
{
    header('Location: admin-dispatch-advices.php?' . http_build_query(array_merge(['tab' => $tab, 'message' => $message, 'status' => $status], $extra)));
    exit;
}

function da_status_label(array $advice): string
{
    $status = strtolower(safe_text((string) ($advice['status'] ?? 'draft')));
    $labels = ['draft' => 'Draft', 'finalized' => 'Finalized', 'shared' => 'Shared', 'acknowledged' => 'Customer Accepted', 'customer_accepted' => 'Customer Accepted', 'archived' => 'Archived', 'cancelled' => 'Cancelled', 'superseded' => 'Superseded'];
    if (($status === 'shared' || $status === 'finalized') && documents_dispatch_advice_has_current_acceptance($advice)) {
        return 'Customer Accepted';
    }
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function da_status_class(string $label): string
{
    return strtolower(str_replace([' ', '/'], ['_', '_'], $label));
}

function da_item_rows_from_post(array $post): array
{
    return documents_normalize_dispatch_advice_items(array_map(null, $post['name'] ?? [], $post['description'] ?? [], $post['brand_model'] ?? [], $post['qty'] ?? [], $post['unit'] ?? [], $post['remarks'] ?? [], $post['catalog_item_id'] ?? [], $post['line_id'] ?? []));
}

function da_catalog_item_payload(array $item): string
{
    return da_h(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$defaultTemplate = "Dear {customer_name},\n\nYour Material Dispatch Advice {dispatch_advice_no} for quotation {quotation_no} is ready.\n\nPlease review the items planned for dispatch before we send them:\n{public_link}\n\nTentative dispatch date: {dispatch_date}\nDelivery location: {delivery_address}\n\nPlease contact us before dispatch if any clarification is needed.\n\nRegards,\n{company_name}\n{company_phone}";
$allowedTabs = ['active', 'drafts', 'customer_accepted', 'archived_cancelled', 'editor', 'settings'];
$tab = safe_text((string) ($_GET['tab'] ?? 'active'));
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'active';
}
if (safe_text((string) ($_GET['edit'] ?? '')) !== '') {
    $tab = 'editor';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        da_redirect('Security validation failed.', 'error', $tab);
    }

    $action = safe_text((string) ($_POST['action'] ?? ''));
    $id = safe_text((string) ($_POST['id'] ?? ''));
    $returnTab = safe_text((string) ($_POST['return_tab'] ?? $tab));
    if (!in_array($returnTab, $allowedTabs, true)) {
        $returnTab = 'active';
    }

    if ($action === 'bulk_transition') {
        $ids = (array) ($_POST['selected_ids'] ?? []);
        $bulkAction = safe_text((string) ($_POST['bulk_action'] ?? ''));
        $reason = safe_text((string) ($_POST['bulk_reason'] ?? ''));
        $ok = 0;
        $skip = 0;
        $errors = [];
        foreach ($ids as $rawId) {
            $doc = documents_get_dispatch_advice((string) $rawId);
            if (!$doc) {
                $skip++;
                continue;
            }
            $result = documents_dispatch_advice_apply_admin_transition($doc, $bulkAction, current_user() ?: ['role' => 'admin', 'name' => 'Admin'], ['reason' => $reason]);
            if (!empty($result['ok'])) {
                $ok++;
            } else {
                $skip++;
                $errors[] = (string) ($doc['dispatch_advice_no'] ?? $rawId) . ': ' . (string) ($result['message'] ?? 'Skipped');
            }
        }
        da_redirect('Bulk action complete: ' . $ok . ' succeeded, ' . $skip . ' skipped. ' . implode(' ', array_slice($errors, 0, 3)), $skip && !$ok ? 'error' : 'success', $returnTab);
    }

    if ($action === 'save_template') {
        $settings = json_load(documents_dispatch_advice_settings_path(), []);
        $settings['whatsapp_template'] = (string) ($_POST['whatsapp_template'] ?? '');
        json_save(documents_dispatch_advice_settings_path(), $settings);
        da_redirect('WhatsApp template saved.', 'success', 'settings');
    }

    if ($action === 'catalog_add') {
        $result = documents_dispatch_catalog_upsert(['name' => $_POST['catalog_name'] ?? '', 'default_unit' => $_POST['catalog_unit'] ?? 'Nos']);
        da_redirect(!empty($result['ok']) ? 'Catalog item saved.' : (string) ($result['error'] ?? 'Unable to save catalog item.'), empty($result['ok']) ? 'error' : 'success', 'settings');
    }

    $advice = $id !== '' ? documents_get_dispatch_advice($id) : null;

    if ($action === 'create') {
        $quotes = array_values(array_filter(documents_list_quotes(), 'documents_dispatch_quote_eligible'));
        foreach ($quotes as $quote) {
            if ((string) ($quote['id'] ?? '') === (string) ($_POST['quotation_id'] ?? '')) {
                $advice = documents_dispatch_advice_defaults();
                $advice['id'] = 'da_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
                $advice['segment'] = (string) ($quote['segment'] ?? 'RES');
                $advice['dispatch_advice_no'] = documents_generate_dispatch_advice_number($advice['segment']);
                $advice['quotation_id'] = (string) ($quote['id'] ?? '');
                $advice['quotation_no'] = (string) ($quote['quote_no'] ?? '');
                $advice['agreement_id'] = (string) ($quote['agreement_id'] ?? '');
                $advice['agreement_no'] = (string) ($quote['agreement_no'] ?? '');
                $advice['customer_name'] = (string) ($quote['customer_name'] ?? $quote['customer_snapshot']['name'] ?? '');
                $advice['customer_mobile'] = documents_dispatch_advice_customer_mobile($advice, $quote);
                $advice['delivery_address'] = (string) ($quote['site_address'] ?? $quote['customer_snapshot']['address'] ?? '');
                $advice['items'] = documents_dispatch_advice_suggested_items($quote);
                $advice['planned_dispatch_date'] = date('Y-m-d');
                $advice['created_at'] = date('c');
                $advice['updated_at'] = date('c');
                documents_save_dispatch_advice($advice);
                da_redirect('Dispatch Advice created. Review materials before finalizing.', 'success', 'editor', ['edit' => $advice['id']]);
            }
        }
        da_redirect('Only accepted current quotations are eligible.', 'error', 'editor');
    }

    if (!$advice) {
        da_redirect('Document not found.', 'error', $returnTab);
    }

    if ($action === 'catalog_create_for_advice' && (string) ($advice['status'] ?? '') === 'draft') {
        $result = documents_dispatch_catalog_upsert(['name' => $_POST['catalog_name'] ?? '', 'default_unit' => $_POST['catalog_unit'] ?? 'Nos']);
        if (!empty($result['ok'])) {
            $item = (array) $result['item'];
            $advice['items'][] = ['catalog_item_id' => $item['id'], 'name' => $item['name'], 'description' => $item['default_description'] ?? '', 'brand_model' => $item['default_brand_model'] ?? '', 'qty' => 1, 'unit' => $item['default_unit'] ?? 'Nos', 'remarks' => ''];
            $advice['items'] = documents_normalize_dispatch_advice_items($advice['items']);
            $advice['updated_at'] = date('c');
            documents_save_dispatch_advice($advice);
        }
        da_redirect(!empty($result['created']) ? 'Generic item created and added.' : 'Existing generic item added.', 'success', 'editor', ['edit' => $advice['id']]);
    }

    if (in_array($action, ['save', 'finalize'], true)) {
        if ((string) ($advice['status'] ?? '') !== 'draft') {
            da_redirect('Create a revision to change a finalized document.', 'error', 'editor', ['edit' => $advice['id']]);
        }
        $advice['planned_dispatch_date'] = safe_text((string) ($_POST['planned_dispatch_date'] ?? ''));
        $advice['delivery_address'] = safe_text((string) ($_POST['delivery_address'] ?? ''));
        $advice['customer_note'] = safe_text((string) ($_POST['customer_note'] ?? ''));
        $advice['items'] = da_item_rows_from_post($_POST);
        if ($action === 'finalize') {
            $validation = documents_validate_dispatch_advice($advice);
            if (empty($validation['ok'])) {
                da_redirect((string) ($validation['error'] ?? 'Validation failed.'), 'error', 'editor', ['edit' => $advice['id']]);
            }
            $advice['status'] = 'finalized';
        }
    } elseif ($action === 'share_toggle') {
        $advice['public_share_enabled'] = empty($advice['public_share_enabled']);
        if (!empty($advice['public_share_enabled']) && safe_text((string) ($advice['public_token'] ?? '')) === '') {
            $advice['public_token'] = bin2hex(random_bytes(32));
        }
    } elseif ($action === 'share_whatsapp') {
        if (!in_array((string) ($advice['status'] ?? ''), ['finalized', 'shared', 'acknowledged', 'customer_accepted'], true)) {
            da_redirect('Finalize this Dispatch Advice before sharing.', 'error', 'editor', ['edit' => $advice['id']]);
        }
        $quote = documents_get_quote((string) ($advice['quotation_id'] ?? '')) ?? [];
        $mobile = documents_dispatch_advice_customer_mobile($advice, $quote);
        if ($mobile === '') {
            da_redirect('Customer mobile is missing or invalid.', 'error', 'editor', ['edit' => $advice['id']]);
        }
        $advice['customer_mobile'] = $mobile;
        $advice['public_share_enabled'] = true;
        if (safe_text((string) ($advice['public_token'] ?? '')) === '') {
            $advice['public_token'] = bin2hex(random_bytes(24));
        }
        $settings = json_load(documents_dispatch_advice_settings_path(), []);
        $template = (string) ($settings['whatsapp_template'] ?? $defaultTemplate);
        $url = documents_dispatch_advice_public_url($advice);
        $built = customer_acceptance_dispatch_template($advice, [], load_company_profile(), $url, $template);
        if (!empty($built['unresolved'])) {
            da_redirect('Share template has unresolved placeholders: ' . implode(', ', $built['unresolved']), 'error', 'editor', ['edit' => $advice['id']]);
        }
        $advice['status'] = (string) ($advice['status'] ?? '') === 'finalized' ? 'shared' : $advice['status'];
        $advice['share_audit'][] = ['event' => 'share_initiated', 'channel' => 'whatsapp', 'at' => date('c'), 'to_mobile_mask' => customer_acceptance_mask_mobile($mobile), 'public_url_snapshot' => $url, 'message_snapshot' => $built['message'], 'actor' => current_user()];
        $advice['share_audit'][] = ['event' => 'whatsapp_opened', 'channel' => 'whatsapp', 'at' => date('c'), 'actor' => current_user()];
        $advice['updated_at'] = date('c');
        documents_save_dispatch_advice($advice);
        header('Location: https://wa.me/91' . $mobile . '?text=' . rawurlencode((string) $built['message']));
        exit;
    } elseif ($action === 'revision') {
        $old = $advice;
        $old['status'] = 'superseded';
        documents_save_dispatch_advice($old);
        $advice['id'] = 'da_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $advice['dispatch_advice_no'] = documents_generate_dispatch_advice_number((string) ($advice['segment'] ?? 'RES'));
        $advice['revision_no'] = (int) ($old['revision_no'] ?? 1) + 1;
        $advice['supersedes_id'] = (string) ($old['id'] ?? '');
        $advice['status'] = 'draft';
        $advice['public_share_enabled'] = false;
        $advice['public_token'] = '';
        $advice['customer_acceptance'] = [];
        $advice['accepted_at'] = '';
        $advice['created_at'] = date('c');
    } elseif (in_array($action, ['cancel', 'archive', 'restore'], true)) {
        $result = documents_dispatch_advice_apply_admin_transition($advice, $action, current_user() ?: ['role' => 'admin', 'name' => 'Admin'], ['reason' => safe_text((string) ($_POST['cancel_reason'] ?? 'Manual row action'))]);
        da_redirect((string) ($result['message'] ?? 'Updated.'), empty($result['ok']) ? 'error' : 'success', $returnTab, $returnTab === 'editor' ? ['edit' => $id] : []);
    } else {
        da_redirect('Unsupported action.', 'error', $returnTab);
    }

    $advice['updated_at'] = date('c');
    documents_save_dispatch_advice($advice);
    da_redirect('Saved.', 'success', 'editor', ['edit' => $advice['id']]);
}

$allAdvices = documents_list_dispatch_advices();
$counts = ['active' => 0, 'drafts' => 0, 'customer_accepted' => 0, 'archived_cancelled' => 0];
foreach ($allAdvices as $row) {
    $bucket = documents_dispatch_advice_tab($row);
    if (isset($counts[$bucket])) {
        $counts[$bucket]++;
    }
}

$quotes = array_values(array_filter(documents_list_quotes(), 'documents_dispatch_quote_eligible'));
$catalog = documents_dispatch_catalog();
$settings = json_load(documents_dispatch_advice_settings_path(), []);
$edit = documents_get_dispatch_advice(safe_text((string) ($_GET['edit'] ?? '')));
$listRows = in_array($tab, ['active', 'drafts', 'customer_accepted', 'archived_cancelled'], true) ? documents_dispatch_advices_for_tab($tab, $allAdvices) : [];
$csrf = (string) ($_SESSION['csrf_token'] ?? '');
$filterSearch = trim((string) ($_GET['q'] ?? ''));
$filterStatus = safe_text((string) ($_GET['status_filter'] ?? ''));
$filterDate = safe_text((string) ($_GET['date'] ?? ''));
if ($filterSearch !== '' || $filterStatus !== '' || $filterDate !== '') {
    $listRows = array_values(array_filter($listRows, static function (array $d) use ($filterSearch, $filterStatus, $filterDate): bool {
        $haystack = strtolower((string) ($d['dispatch_advice_no'] ?? '') . ' ' . (string) ($d['customer_name'] ?? '') . ' ' . (string) ($d['quotation_no'] ?? ''));
        if ($filterSearch !== '' && !str_contains($haystack, strtolower($filterSearch))) return false;
        if ($filterStatus !== '' && strtolower((string) ($d['status'] ?? '')) !== strtolower($filterStatus)) return false;
        if ($filterDate !== '' && (string) ($d['planned_dispatch_date'] ?? '') !== $filterDate) return false;
        return true;
    }));
}

$tabLabels = ['active' => 'Active', 'drafts' => 'Drafts', 'customer_accepted' => 'Customer Accepted', 'archived_cancelled' => 'Archived / Cancelled', 'editor' => 'Create / Edit', 'settings' => 'Catalog / Settings'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Material Dispatch Advice</title>
<link rel="stylesheet" href="assets/css/admin-unified.css">
</head>
<body class="admin-shell commercial-admin">
<main class="commercial-shell">
<header class="card commercial-header">
    <div>
        <p class="admin-kicker">Commercial workspace</p>
        <h1>Material Dispatch Advice</h1>
        <p>Prepare and share customer-approved material dispatch plans before creating Delivery Challans.</p>
    </div>
    <nav class="commercial-header__actions" aria-label="Dispatch Advice actions">
        <a class="btn secondary" href="admin-dashboard.php">Dashboard</a>
        <a class="btn secondary" href="admin-documents.php">Document Center</a>
        <a class="btn commercial-header__primary" href="admin-dispatch-advices.php?tab=editor">+ New Dispatch Advice</a>
    </nav>
</header>
<?= render_commercial_lifecycle('dispatch_advice') ?>

<?php if (isset($_GET['message'])): ?>
    <div class="card" role="status" style="background:<?= da_h(($_GET['status'] ?? '') === 'error' ? '#fef2f2' : '#ecfdf5') ?>"><?= da_h($_GET['message']) ?></div>
<?php endif; ?>

<nav class="workspace-tabs" aria-label="Dispatch Advice tabs">
    <?php foreach ($tabLabels as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="admin-dispatch-advices.php?tab=<?= da_h($key) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
            <?= da_h($label) ?>
            <?php if (isset($counts[$key])): ?><span class="muted-helper">(<?= (int) $counts[$key] ?>)</span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (in_array($tab, ['active', 'drafts', 'customer_accepted', 'archived_cancelled'], true)): ?>
<section class="card workspace-panel">
    <div class="commercial-toolbar">
        <div>
            <h2><?= da_h(['active' => 'Active Dispatch Advices', 'drafts' => 'Draft Dispatch Advices', 'customer_accepted' => 'Customer Accepted Dispatch Advices', 'archived_cancelled' => 'Archived / Cancelled Dispatch Advices'][$tab]) ?></h2>
            <p class="muted-helper">Review planned dispatch documents, public sharing state, customer acceptance, and linked Challan progress.</p>
        </div>
        <form class="filter-grid" method="get">
            <input type="hidden" name="tab" value="<?= da_h($tab) ?>">
            <label>Search<input name="q" value="<?= da_h($filterSearch) ?>" placeholder="Customer / advice no"></label>
            <label>Status<input name="status_filter" value="<?= da_h($filterStatus) ?>" placeholder="draft, shared…"></label>
            <label>Date<input type="date" name="date" value="<?= da_h($filterDate) ?>"></label>
            <button class="btn secondary">Filter</button>
        </form>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= da_h($csrf) ?>">
        <input type="hidden" name="return_tab" value="<?= da_h($tab) ?>">
        <div class="commercial-toolbar list-toolbar">
            <label><input type="checkbox" class="js-select-all-da"> Select all <span class="muted-helper js-selected-count">(0 selected)</span></label>
            <div class="row-action-group">
                <select name="bulk_action" aria-label="Bulk action"><option value="archive">Archive</option><option value="cancel">Cancel</option><option value="restore">Restore</option></select>
                <input name="bulk_reason" placeholder="Reason for cancel">
                <button class="btn secondary" name="action" value="bulk_transition">Apply bulk action</button>
            </div>
        </div>
        <div class="responsive-table">
            <table>
                <thead><tr><th></th><th>Dispatch Advice</th><th>Customer</th><th>Quotation</th><th>Planned Dispatch Date</th><th>Materials</th><th>Status</th><th>Challan</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($listRows as $d): $challan = documents_challan_for_dispatch_advice((string) ($d['id'] ?? '')); $workflow = documents_challan_workflow_status($d, $challan); $publicUrl = !empty($d['public_share_enabled']) ? documents_dispatch_advice_public_url($d) : ''; ?>
                    <tr>
                        <td><input class="js-bulk-da" type="checkbox" name="selected_ids[]" value="<?= da_h($d['id'] ?? '') ?>"></td>
                        <td><strong><?= da_h($d['dispatch_advice_no'] ?? '') ?></strong><br><span class="muted-helper">Revision <?= (int) ($d['revision_no'] ?? 1) ?> · Updated <?= da_h($d['updated_at'] ?: $d['created_at'] ?? '') ?></span></td>
                        <td><?= da_h($d['customer_name'] ?? '') ?><br><span class="muted-helper"><?= da_h(customer_acceptance_mask_mobile((string) ($d['customer_mobile'] ?? ''))) ?></span></td>
                        <td><?= da_h($d['quotation_no'] ?? '—') ?></td>
                        <td><?= da_h($d['planned_dispatch_date'] ?? '—') ?></td>
                        <td><?= count((array) ($d['items'] ?? [])) ?> item(s)<?php if (!empty($d['items'][0]['name'])): ?><br><span class="muted-helper"><?= da_h($d['items'][0]['name']) ?></span><?php endif; ?></td>
                        <td><span class="status-badge status-badge--<?= da_h(da_status_class(da_status_label($d))) ?>"><?= da_h(da_status_label($d)) ?></span></td>
                        <td><span class="status-badge status-badge--<?= da_h(strtolower($workflow)) ?>"><?= da_h(str_replace('_', ' / ', $workflow)) ?></span><?php if ($challan): ?><br><span class="muted-helper"><?= da_h($challan['challan_no'] ?? $challan['dc_number'] ?? '') ?></span><?php endif; ?></td>
                        <td><div class="row-action-group"><a class="btn" href="admin-dispatch-advices.php?tab=editor&edit=<?= urlencode((string) $d['id']) ?>">Open</a><?php if ($publicUrl !== ''): ?><a class="btn secondary" href="<?= da_h($publicUrl) ?>" target="_blank" rel="noopener">Share</a><?php endif; ?><details class="more-actions"><summary class="btn secondary">More</summary><div class="more-actions__menu"><a class="btn secondary" href="dispatch-advice-view.php?id=<?= urlencode((string) $d['id']) ?>" target="_blank">View / Print HTML</a><?php if ($publicUrl !== ''): ?><a class="btn secondary" href="<?= da_h($publicUrl) ?>" target="_blank" rel="noopener">Copy/Open public link</a><?php endif; ?><button class="btn secondary" name="action" value="revision" formaction="admin-dispatch-advices.php" formmethod="post" onclick="this.closest('td').querySelector('.js-row-id').disabled=false">Create revision</button><button class="btn secondary" name="action" value="archive" onclick="this.closest('td').querySelector('.js-row-id').disabled=false">Archive</button><button class="btn danger" name="action" value="cancel" onclick="this.closest('td').querySelector('.js-row-id').disabled=false">Cancel</button><?php if ($tab === 'archived_cancelled'): ?><button class="btn secondary" name="action" value="restore" onclick="this.closest('td').querySelector('.js-row-id').disabled=false">Restore</button><?php endif; ?></div></details><input class="js-row-id" type="hidden" name="id" value="<?= da_h($d['id'] ?? '') ?>" disabled></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$listRows): ?><tr><td colspan="9" class="empty-state"><strong>No records in this tab.</strong><br>Use Create / Edit to start a new Dispatch Advice from an accepted quotation.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</section>
<?php elseif ($tab === 'editor' && !$edit): ?>
<section class="card workspace-panel">
    <div class="commercial-toolbar"><div><h2>Create Dispatch Advice</h2><p class="muted-helper">Section 1 — Select accepted quotation. Only accepted, current, non-archived quotations with customer mobile details appear here.</p></div></div>
    <?php if ($quotes): ?>
        <form method="post" class="form-section-card">
            <input type="hidden" name="csrf_token" value="<?= da_h($csrf) ?>">
            <div class="form-grid form-grid--two"><label>Accepted quotation / customer<select name="quotation_id" required><?php foreach ($quotes as $q): ?><option value="<?= da_h($q['id'] ?? '') ?>"><?= da_h(($q['quote_no'] ?? '') . ' | ' . ($q['customer_name'] ?? $q['customer_snapshot']['name'] ?? '') . ' | ' . customer_acceptance_mask_mobile((string) ($q['customer_mobile'] ?? $q['customer_snapshot']['mobile'] ?? ''))) ?></option><?php endforeach; ?></select></label><div><label>System summary</label><p class="muted-helper">A draft Dispatch Advice is created with suggested materials from the packing list or quotation. Review and finalize before sharing.</p></div></div>
            <div class="sticky-action-footer"><button class="btn" name="action" value="create">Create Dispatch Advice</button></div>
        </form>
    <?php else: ?><div class="empty-state"><strong>No eligible accepted quotations.</strong><br>Accept a current quotation before creating a Material Dispatch Advice.</div><?php endif; ?>
</section>
<?php elseif ($tab === 'editor' && $edit): $locked = (string) ($edit['status'] ?? '') !== 'draft'; $shareMobile = documents_dispatch_advice_customer_mobile($edit, documents_get_quote((string) ($edit['quotation_id'] ?? '')) ?? []); $shareEligible = in_array((string) ($edit['status'] ?? ''), ['finalized', 'shared', 'acknowledged', 'customer_accepted'], true); $publicUrl = !empty($edit['public_share_enabled']) ? documents_dispatch_advice_public_url($edit) : ''; $acceptance = (array) ($edit['customer_acceptance'] ?? []); ?>
<form method="post" class="workspace-panel">
    <input type="hidden" name="csrf_token" value="<?= da_h($csrf) ?>"><input type="hidden" name="id" value="<?= da_h($edit['id']) ?>"><input type="hidden" name="return_tab" value="editor">
    <section class="form-section-card"><h2>Document summary <span class="status-badge status-badge--<?= da_h(da_status_class(da_status_label($edit))) ?>"><?= da_h(da_status_label($edit)) ?></span></h2><div class="form-grid"><div><label>Dispatch Advice</label><input value="<?= da_h($edit['dispatch_advice_no']) ?>" readonly></div><div><label>Quotation</label><input value="<?= da_h($edit['quotation_no']) ?>" readonly></div><div><label>Agreement</label><input value="<?= da_h($edit['agreement_no']) ?>" readonly></div><div><label>Customer</label><input value="<?= da_h($edit['customer_name']) ?>" readonly></div><div><label>Customer mobile</label><input value="<?= da_h(customer_acceptance_mask_mobile((string) $edit['customer_mobile'])) ?>" readonly></div><div><label>Revision</label><input value="<?= (int) ($edit['revision_no'] ?? 1) ?>" readonly></div></div></section>
    <section class="form-section-card"><h3>Section 2 — Dispatch details</h3><p class="muted-helper">Maintain customer-safe dispatch information before sharing.</p><div class="form-grid"><div><label>Planned dispatch date<input type="date" name="planned_dispatch_date" value="<?= da_h($edit['planned_dispatch_date']) ?>" <?= $locked ? 'disabled' : '' ?>></label></div><div class="full-span"><label>Delivery address<textarea name="delivery_address" <?= $locked ? 'disabled' : '' ?>><?= da_h($edit['delivery_address']) ?></textarea></label></div><div class="full-span"><label>Customer note<textarea name="customer_note" <?= $locked ? 'disabled' : '' ?>><?= da_h($edit['customer_note']) ?></textarea></label></div></div></section>
    <section class="form-section-card"><h3>Section 3 — Materials</h3><div class="responsive-table"><table><thead><tr><th>#</th><th>Item / description</th><th>Brand / model</th><th>Qty</th><th>Remarks</th><th>Remove</th></tr></thead><tbody class="js-items-body"><?php foreach ((array) $edit['items'] as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><input name="name[]" value="<?= da_h($r['name'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>><textarea name="description[]" <?= $locked ? 'disabled' : '' ?>><?= da_h($r['description'] ?? '') ?></textarea></td><td><input name="brand_model[]" value="<?= da_h($r['brand_model'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td><td><input name="qty[]" value="<?= da_h($r['qty'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>><input name="unit[]" value="<?= da_h($r['unit'] ?? 'Nos') ?>" <?= $locked ? 'disabled' : '' ?>></td><td><input name="remarks[]" value="<?= da_h($r['remarks'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td><td><button type="button" class="btn secondary js-remove-item" <?= $locked ? 'disabled' : '' ?>>Remove</button><input type="hidden" name="catalog_item_id[]" value="<?= da_h($r['catalog_item_id'] ?? '') ?>"><input type="hidden" name="line_id[]" value="<?= da_h($r['line_id'] ?? '') ?>"></td></tr><?php endforeach; ?><?php if (empty($edit['items'])): ?><tr class="js-empty-items"><td colspan="6" class="empty-state"><strong>No items added yet.</strong><br>Click + Add Item to choose the materials planned for dispatch.</td></tr><?php endif; ?></tbody></table></div><?php if (!$locked): ?><details class="item-picker"><summary class="btn secondary">+ Add Item</summary><div class="card"><input class="js-item-search" placeholder="Search catalog items" aria-label="Search catalog items"><?php foreach ($catalog as $ci): if (empty($ci['active'])) continue; ?><button type="button" class="btn secondary js-add-catalog-item" data-name="<?= da_h($ci['name']) ?>" data-item="<?= da_catalog_item_payload($ci) ?>">+ <?= da_h($ci['name']) ?></button><?php endforeach; ?><hr><button type="button" class="btn secondary" id="create-generic-item">+ Create New Generic Item</button></div></details><?php endif; ?></section>
    <section class="form-section-card"><h3>Section 4 — Public sharing and customer confirmation</h3><div class="form-grid"><div><label>Public link</label><p><span class="status-badge"><?= !empty($edit['public_share_enabled']) ? 'Enabled' : 'Disabled' ?></span></p></div><div><label>Customer confirmation</label><p><span class="status-badge"><?= !empty($acceptance['confirmed_at']) ? 'Confirmed' : 'Pending' ?></span></p><p class="muted-helper"><?= da_h(trim((string) ($acceptance['acceptance_ref'] ?? '') . ' ' . (string) ($acceptance['confirmed_at'] ?? ''))) ?></p></div><div><label>Customer mobile</label><p><?= $shareMobile !== '' ? da_h(customer_acceptance_mask_mobile($shareMobile)) : '<span class="status-badge status-badge--archived">Missing</span>' ?></p></div></div><div class="row-action-group"><button class="btn secondary" name="action" value="share_toggle"><?= !empty($edit['public_share_enabled']) ? 'Disable' : 'Enable' ?> public link</button><?php if ($publicUrl !== ''): ?><a class="btn secondary" href="<?= da_h($publicUrl) ?>" target="_blank" rel="noopener">Copy/Open public link</a><?php endif; ?><button class="btn secondary" name="action" value="share_whatsapp" formtarget="_blank" <?= (!$shareEligible || $shareMobile === '') ? 'disabled' : '' ?>>Share</button></div><?php if (!$shareEligible): ?><p class="muted-helper">Finalize this Dispatch Advice before sharing.</p><?php elseif ($shareMobile === ''): ?><p class="muted-helper">Customer mobile is missing or invalid.</p><?php endif; ?></section>
    <div class="sticky-action-footer"><?php if (!$locked): ?><button class="btn" name="action" value="save">Save Draft</button><button class="btn" name="action" value="finalize">Finalize</button><?php else: ?><button class="btn" name="action" value="revision">Create Revision</button><a class="btn secondary" href="dispatch-advice-view.php?id=<?= urlencode((string) $edit['id']) ?>" target="_blank">View / Print HTML</a><button class="btn secondary" name="action" value="share_whatsapp" formtarget="_blank" <?= (!$shareEligible || $shareMobile === '') ? 'disabled' : '' ?>>Share</button><?php endif; ?><input name="cancel_reason" placeholder="Cancel reason"><button class="btn danger" name="action" value="cancel">Cancel</button><button class="btn secondary" name="action" value="archive">Archive</button></div>
</form>
<?php elseif ($tab === 'settings'): $template = (string) ($settings['whatsapp_template'] ?? $defaultTemplate); $preview = customer_acceptance_dispatch_template(documents_dispatch_advice_defaults() + ['customer_name' => 'Sample Customer', 'customer_mobile' => '9876543210', 'dispatch_advice_no' => 'DE/DA/RES/26-27/0001', 'quotation_no' => 'DE/Q/0001', 'planned_dispatch_date' => date('Y-m-d'), 'delivery_address' => 'Sample site', 'items' => [['name' => 'Solar Panels', 'qty' => 6, 'unit' => 'Nos']]], [], load_company_profile(), 'https://example.test/dispatch-advice-public.php?token=sample', $template); ?>
<section class="card workspace-panel"><h2>Catalog / Settings</h2><p class="muted-helper">Reusable generic items and WhatsApp messaging are isolated here so day-to-day Dispatch Advice work stays focused.</p></section>
<section class="card"><h3>Generic Item Catalog</h3><div class="responsive-table"><table><thead><tr><th>Item name</th><th>Default unit</th><th>State</th></tr></thead><tbody><?php foreach ($catalog as $ci): ?><tr><td><strong><?= da_h($ci['name'] ?? '') ?></strong></td><td><?= da_h($ci['default_unit'] ?? 'Nos') ?></td><td><span class="status-badge"><?= empty($ci['active']) ? 'Inactive' : 'Active' ?></span></td></tr><?php endforeach; ?></tbody></table></div><form method="post" class="commercial-toolbar list-toolbar"><input type="hidden" name="csrf_token" value="<?= da_h($csrf) ?>"><input name="catalog_name" placeholder="+ Add item" required><input name="catalog_unit" value="Nos" required><button class="btn" name="action" value="catalog_add">Add item</button></form></section>
<section class="card"><h3>WhatsApp Sharing Template</h3><form method="post"><input type="hidden" name="csrf_token" value="<?= da_h($csrf) ?>"><textarea name="whatsapp_template" rows="12"><?= da_h($template) ?></textarea><p class="muted-helper">Supported placeholders: {customer_name}, {customer_mobile_mask}, {dispatch_advice_no}, {dispatch_advice_version}, {quotation_no}, {agreement_no}, {dispatch_date}, {delivery_address}, {item_count}, {item_summary}, {acceptance_ref}, {confirmed_at}, {public_link}, {company_name}, {company_phone}, {company_whatsapp}</p><?php if (!empty($preview['unresolved'])): ?><p class="status-badge status-badge--archived">Unresolved placeholders: <?= da_h(implode(', ', $preview['unresolved'])) ?></p><?php endif; ?><div class="form-section-card"><h4>Preview</h4><pre><?= da_h($preview['message'] ?? '') ?></pre></div><button class="btn" name="action" value="save_template">Save Template</button></form></section>
<?php endif; ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const boxes = [...document.querySelectorAll('.js-bulk-da')];
  const count = document.querySelector('.js-selected-count');
  const refresh = () => { if (count) count.textContent = '(' + boxes.filter(b => b.checked).length + ' selected)'; };
  document.querySelector('.js-select-all-da')?.addEventListener('change', event => { boxes.forEach(box => box.checked = event.target.checked); refresh(); });
  boxes.forEach(box => box.addEventListener('change', refresh));
  const tbody = document.querySelector('.js-items-body');
  const add = item => {
    if (!tbody) return;
    const existing = [...tbody.querySelectorAll('input[name="catalog_item_id[]"]')].some(input => input.value === item.id);
    if (existing && !confirm(item.name + ' is already listed. Add another identical line?')) return;
    tbody.querySelector('.js-empty-items')?.remove();
    const row = document.createElement('tr');
    row.innerHTML = '<td></td><td><input name="name[]"><textarea name="description[]"></textarea></td><td><input name="brand_model[]"></td><td><input name="qty[]"><input name="unit[]"></td><td><input name="remarks[]"></td><td><button type="button" class="btn secondary js-remove-item">Remove</button><input type="hidden" name="catalog_item_id[]"><input type="hidden" name="line_id[]"></td>';
    row.querySelector('input[name="name[]"]').value = item.name || '';
    row.querySelector('textarea[name="description[]"]').value = item.default_description || '';
    row.querySelector('input[name="brand_model[]"]').value = item.default_brand_model || '';
    row.querySelector('input[name="qty[]"]').value = '1';
    row.querySelector('input[name="unit[]"]').value = item.default_unit || 'Nos';
    row.querySelector('input[name="catalog_item_id[]"]').value = item.id || '';
    tbody.appendChild(row);
    [...tbody.querySelectorAll('tr')].forEach((tr, i) => { if (!tr.classList.contains('js-empty-items')) tr.firstElementChild.textContent = String(i + 1); });
    row.querySelector('input[name="qty[]"]')?.focus();
  };
  document.querySelectorAll('.js-add-catalog-item').forEach(button => button.addEventListener('click', () => add(JSON.parse(button.dataset.item))));
  document.addEventListener('click', event => { if (event.target.classList?.contains('js-remove-item')) event.target.closest('tr')?.remove(); });
  document.querySelector('.js-item-search')?.addEventListener('input', event => { const q = event.target.value.toLowerCase(); document.querySelectorAll('.js-add-catalog-item').forEach(button => button.hidden = !button.dataset.name.toLowerCase().includes(q)); });
  document.getElementById('create-generic-item')?.addEventListener('click', () => {
    const name = prompt('Generic item name'); if (!name) return;
    const unit = prompt('Default unit', 'Nos') || 'Nos';
    const form = document.querySelector('form.workspace-panel');
    [['action','catalog_create_for_advice'],['catalog_name',name],['catalog_unit',unit]].forEach(([key, value]) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.appendChild(input); });
    form.submit();
  });
});
</script>
</body>
</html>
