<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_admin();

$user = current_user();
$redirect = static function (string $type, string $message): never {
    header('Location: admin-dispatch-advices.php?' . http_build_query(['status' => $type, 'message' => $message]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) { $redirect('error', 'Security validation failed.'); }
    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'create') {
        $quote = documents_get_quote(safe_text($_POST['quotation_id'] ?? ''));
        if ($quote === null) { $redirect('error', 'Quotation not found.'); }
        $packing = documents_get_packing_list_for_quote((string) $quote['id'], true);
        if ($packing === null) { $redirect('error', 'Unable to prepare packing list.'); }
        $items = documents_dispatch_advice_items_from_packing_list($packing);
        if ($items === []) { $redirect('error', 'No pending items remain to dispatch.'); }
        $snapshot = documents_quote_resolve_snapshot($quote);
        $advice = array_merge(documents_dispatch_advice_defaults(), [
            'id' => 'da_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
            'advice_no' => 'DA-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))),
            'quotation_id' => (string) $quote['id'], 'packing_list_id' => (string) ($packing['id'] ?? ''),
            'customer_snapshot' => $snapshot, 'site_address_snapshot' => (string) ($quote['site_address'] ?? $snapshot['address'] ?? ''),
            'planned_dispatch_date' => safe_text($_POST['planned_dispatch_date'] ?? date('Y-m-d')),
            'items' => $items, 'created_by' => ['role' => 'admin', 'id' => (string) ($user['id'] ?? ''), 'name' => (string) ($user['full_name'] ?? 'Admin')],
            'created_at' => date('c'),
        ]);
        $saved = documents_save_dispatch_advice($advice);
        $redirect(($saved['ok'] ?? false) ? 'success' : 'error', ($saved['ok'] ?? false) ? 'Dispatch Advice created with exact pending items.' : 'Unable to save Dispatch Advice.');
    }
    if ($action === 'convert') {
        $advice = documents_get_dispatch_advice(safe_text($_POST['id'] ?? ''));
        if ($advice === null) { $redirect('error', 'Dispatch Advice not found.'); }
        $result = documents_create_delivery_challan_from_dispatch_advice($advice, ['role' => 'admin', 'id' => (string) ($user['id'] ?? ''), 'name' => (string) ($user['full_name'] ?? 'Admin')]);
        if (!($result['ok'] ?? false)) { $redirect('error', (string) ($result['error'] ?? 'Unable to create Delivery Challan.')); }
        header('Location: challan-view.php?id=' . urlencode((string) $result['challan']['id']));
        exit;
    }
    if ($action === 'enable_share') {
        $advice = documents_get_dispatch_advice(safe_text($_POST['id'] ?? ''));
        if ($advice === null) { $redirect('error', 'Dispatch Advice not found.'); }
        $result = documents_enable_dispatch_advice_public_share($advice);
        if (!($result['ok'] ?? false)) { $redirect('error', 'Unable to enable secure sharing.'); }
        $_SESSION['dispatch_advice_share'] = ['id' => $advice['id'], 'token' => $result['token']];
        $redirect('success', 'Secure public link enabled. Use WhatsApp below; the token is shown only in this session.');
    }
}
$quotes = array_values(array_filter(documents_list_quotes(), static fn(array $q): bool => documents_quote_normalize_status((string) ($q['status'] ?? '')) === 'accepted'));
$rows = documents_list_dispatch_advices();
$share = is_array($_SESSION['dispatch_advice_share'] ?? null) ? $_SESSION['dispatch_advice_share'] : [];
unset($_SESSION['dispatch_advice_share']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dispatch Advices</title><link rel="stylesheet" href="layout-styles.css"></head><body>
<?php require __DIR__ . '/partials/header.php'; ?>
<main class="admin-page"><header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Dispatch Advices</h1><p>Plan exact pending items before creating one or more Delivery Challans.</p></div><nav class="commercial-header__actions"><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn secondary" href="admin-challans.php">Delivery Challans</a></nav></header>
<?php if (isset($_GET['message'])): ?><div class="card"><?= htmlspecialchars((string) $_GET['message'], ENT_QUOTES) ?></div><?php endif; ?>
<section class="card"><h2>New Dispatch Advice</h2><form method="post" class="form-grid form-grid--two"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="create"><div><label>Accepted quotation</label><select name="quotation_id" required><option value="">Select</option><?php foreach ($quotes as $q): ?><option value="<?= htmlspecialchars((string) $q['id'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($q['quote_no'] ?? $q['id']) . ' — ' . (string) ($q['customer_name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div><div><label>Planned dispatch date</label><input type="date" name="planned_dispatch_date" value="<?= date('Y-m-d') ?>"></div><div><button class="btn" type="submit">Create from pending items</button></div></form></section>
<section class="card"><h2>Dispatch Advice list</h2><div class="responsive-table"><table><thead><tr><th>Advice</th><th>Customer</th><th>Date</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): $isShared = (string) ($share['id'] ?? '') === (string) $row['id']; $publicUrl = $isShared ? ('dispatch-advice-public.php?token=' . urlencode((string) $share['token'])) : ''; $mobile = documents_normalize_whatsapp_mobile((string) ($row['customer_snapshot']['mobile'] ?? '')); ?><tr><td><?= htmlspecialchars((string) $row['advice_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($row['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) $row['planned_dispatch_date'], ENT_QUOTES) ?></td><td><?= count((array) $row['items']) ?></td><td><?= htmlspecialchars(ucfirst((string) $row['status']), ENT_QUOTES) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>"><button class="btn" type="submit">Create Delivery Challan</button></form><?php if ($isShared && $mobile !== ''): ?><a class="btn secondary" target="_blank" rel="noopener noreferrer" href="https://wa.me/<?= urlencode($mobile) ?>?text=<?= urlencode('Your Dispatch Advice: ' . $publicUrl) ?>">WhatsApp secure link</a><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="action" value="enable_share"><input type="hidden" name="id" value="<?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>"><button class="btn secondary" type="submit">Enable secure share</button></form><?php endif; ?></td></tr><?php endforeach; if ($rows === []): ?><tr><td colspan="6">No Dispatch Advices yet.</td></tr><?php endif; ?></tbody></table></div></section></main></body></html>
