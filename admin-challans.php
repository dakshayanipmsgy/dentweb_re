<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
documents_seed_template_sets_if_empty();

$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];
$units = ['Nos', 'Set', 'm', 'kWp', 'Box', 'Lot'];

$templates = array_values(array_filter(json_load(documents_templates_dir() . '/template_sets.json', []), static function ($row): bool {
    return is_array($row) && !($row['archived_flag'] ?? false);
}));
$allQuotes = documents_list_quotes();

$redirectWith = static function (string $type, string $msg): void {
    header('Location: admin-challans.php?' . http_build_query(['status' => $type, 'message' => $msg]));
    exit;
};

$buildSuggestedItems = static function (array $quote): array {
    $systemType = strtolower((string) ($quote['system_type'] ?? ''));
    $capacity = (float) ($quote['capacity_kwp'] ?? 0);
    if ($capacity <= 0) {
        $capacity = 1;
    }

    $rows = [
        ['name' => 'Solar PV Module', 'description' => strtoupper($systemType ?: 'System') . ' module supply', 'unit' => 'Nos', 'qty' => max(1, ceil($capacity * 2)), 'remarks' => 'Suggested'],
        ['name' => 'Inverter', 'description' => 'As per approved design', 'unit' => 'Set', 'qty' => 1, 'remarks' => 'Suggested'],
        ['name' => 'Mounting Structure', 'description' => 'Rooftop mounting hardware', 'unit' => 'Lot', 'qty' => 1, 'remarks' => 'Suggested'],
        ['name' => 'DC/AC Cables & Accessories', 'description' => 'Cable + connectors + lugs', 'unit' => 'Lot', 'qty' => 1, 'remarks' => 'Suggested'],
    ];

    return documents_normalize_challan_items($rows);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if (!in_array($action, ['save_draft', 'issue', 'add_suggested_items'], true)) {
        $redirectWith('error', 'Invalid action.');
    }

    $challanId = safe_text($_POST['challan_id'] ?? '');
    $existing = $challanId !== '' ? documents_get_challan($challanId) : null;
    $isCreate = $existing === null;
    $challan = $existing ?? documents_challan_defaults();

    $partyType = safe_text($_POST['party_type'] ?? 'customer');
    if (!in_array($partyType, ['customer', 'lead'], true)) {
        $partyType = 'lead';
    }

    $templateSetId = safe_text($_POST['template_set_id'] ?? '');
    $segment = safe_text($_POST['segment'] ?? 'RES');
    if (!in_array($segment, $segments, true)) {
        $segment = 'RES';
    }

    $selectedTemplate = null;
    foreach ($templates as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $templateSetId) {
            $selectedTemplate = $tpl;
            break;
        }
    }
    if ($selectedTemplate !== null && (string) ($selectedTemplate['segment'] ?? '') !== '') {
        $segment = (string) $selectedTemplate['segment'];
    }

    $linkedQuoteId = safe_text($_POST['linked_quote_id'] ?? '');
    $linkedQuote = $linkedQuoteId !== '' ? documents_get_quote($linkedQuoteId) : null;

    $snapshot = documents_customer_snapshot_defaults();
    if ($linkedQuote !== null) {
        $snapshot = array_merge($snapshot, is_array($linkedQuote['customer_snapshot'] ?? null) ? $linkedQuote['customer_snapshot'] : []);
        $snapshot['mobile'] = safe_text((string) ($linkedQuote['customer_mobile'] ?? $snapshot['mobile']));
        $snapshot['name'] = safe_text((string) ($linkedQuote['customer_name'] ?? $snapshot['name']));
        $segment = safe_text((string) ($linkedQuote['segment'] ?? $segment)) ?: $segment;
        $templateSetId = safe_text((string) ($linkedQuote['template_set_id'] ?? $templateSetId));
    } else {
        $mobile = normalize_customer_mobile((string) ($_POST['customer_mobile'] ?? ''));
        $snapshot['mobile'] = $mobile;
        if ($partyType === 'customer') {
            $customer = documents_find_customer_by_mobile($mobile);
            if ($customer !== null) {
                $snapshot = array_merge($snapshot, $customer);
            }
        }
        $snapshot['name'] = safe_text($_POST['customer_name'] ?? $snapshot['name']);
        $snapshot['address'] = safe_text($_POST['customer_address'] ?? $snapshot['address']);
        $snapshot['city'] = safe_text($_POST['city'] ?? $snapshot['city']);
        $snapshot['district'] = safe_text($_POST['district'] ?? $snapshot['district']);
        $snapshot['pin_code'] = safe_text($_POST['pin_code'] ?? $snapshot['pin_code']);
        $snapshot['state'] = safe_text($_POST['state'] ?? $snapshot['state']);
        $snapshot['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? $snapshot['consumer_account_no']);
    }

    $siteAddress = safe_text($_POST['site_address'] ?? '');
    if ($siteAddress === '') {
        $siteAddress = safe_text((string) ($linkedQuote['site_address'] ?? $snapshot['address']));
    }
    $deliveryAddress = safe_text($_POST['delivery_address'] ?? '');
    if ($deliveryAddress === '') {
        $deliveryAddress = $siteAddress;
    }

    $deliveryDate = safe_text($_POST['delivery_date'] ?? date('Y-m-d'));
    if ($deliveryDate === '') {
        $deliveryDate = date('Y-m-d');
    }

    $items = [];
    $names = $_POST['item_name'] ?? [];
    $descs = $_POST['item_description'] ?? [];
    $unitsIn = $_POST['item_unit'] ?? [];
    $qtys = $_POST['item_qty'] ?? [];
    $remarks = $_POST['item_remarks'] ?? [];
    if (is_array($names)) {
        foreach ($names as $idx => $name) {
            $items[] = [
                'name' => safe_text((string) $name),
                'description' => safe_text((string) ($descs[$idx] ?? '')),
                'unit' => safe_text((string) ($unitsIn[$idx] ?? 'Nos')),
                'qty' => (float) ($qtys[$idx] ?? 0),
                'remarks' => safe_text((string) ($remarks[$idx] ?? '')),
            ];
        }
    }

    if ($action === 'add_suggested_items') {
        if ($linkedQuote === null) {
            $redirectWith('error', 'Suggested items require a linked quotation.');
        }
        $items = $buildSuggestedItems($linkedQuote);
    }

    $challan['party_type'] = $partyType;
    $challan['template_set_id'] = $templateSetId;
    $challan['segment'] = $segment;
    $challan['linked_quote_id'] = $linkedQuote !== null ? (string) ($linkedQuote['id'] ?? '') : '';
    $challan['linked_quote_no'] = $linkedQuote !== null ? (string) ($linkedQuote['quote_no'] ?? '') : '';
    $challan['customer_snapshot'] = $snapshot;
    $challan['site_address'] = $siteAddress;
    $challan['delivery_address'] = $deliveryAddress;
    $challan['delivery_date'] = $deliveryDate;
    $challan['vehicle_no'] = safe_text($_POST['vehicle_no'] ?? '');
    $challan['driver_name'] = safe_text($_POST['driver_name'] ?? '');
    $challan['delivery_notes'] = safe_text($_POST['delivery_notes'] ?? '');
    $challan['items'] = documents_normalize_challan_items($items);
    $challan['rendering']['background_image'] = safe_text($_POST['background_image'] ?? ($challan['rendering']['background_image'] ?? ''));
    $challan['rendering']['background_opacity'] = max(0.1, min(1.0, (float) ($_POST['background_opacity'] ?? ($challan['rendering']['background_opacity'] ?? 1))));

    if ($snapshot['mobile'] === '' || $snapshot['name'] === '') {
        $redirectWith('error', 'Customer mobile and name are required.');
    }

    if ($action === 'issue' && !documents_challan_has_valid_items($challan)) {
        $redirectWith('error', 'At least one item with name and qty > 0 is required before issuing.');
    }

    if ($isCreate) {
        $number = documents_generate_challan_number($segment);
        if (!$number['ok']) {
            $redirectWith('error', (string) ($number['error'] ?? 'Unable to create challan number.'));
        }
        $user = current_user();
        $challan['id'] = 'dc_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $challan['challan_no'] = (string) $number['challan_no'];
        $challan['created_by_type'] = 'admin';
        $challan['created_by_id'] = (string) ($user['id'] ?? '');
        $challan['created_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $challan['created_at'] = date('c');
    }

    $challan['status'] = $action === 'issue' ? 'Issued' : 'Draft';
    $challan['updated_at'] = date('c');

    $saved = documents_save_challan($challan);
    if (!$saved['ok']) {
        documents_log('Admin challan save failed: ' . (string) ($saved['error'] ?? 'unknown'));
        $redirectWith('error', 'Unable to save challan. Please retry.');
    }

    header('Location: challan-view.php?id=' . urlencode((string) $challan['id']) . '&status=success&message=' . urlencode($action === 'issue' ? 'Challan issued.' : 'Challan saved as draft.'));
    exit;
}

$filters = [
    'status' => safe_text($_GET['status_filter'] ?? ''),
    'query' => strtolower(safe_text($_GET['q'] ?? '')),
    'quote' => strtolower(safe_text($_GET['quote_q'] ?? '')),
];

$rows = array_values(array_filter(documents_list_challans(), static function (array $row) use ($filters): bool {
    if ($filters['status'] !== '' && (string) ($row['status'] ?? '') !== $filters['status']) {
        return false;
    }
    if ($filters['query'] !== '') {
        $name = strtolower((string) ($row['customer_snapshot']['name'] ?? ''));
        $mobile = strtolower((string) ($row['customer_snapshot']['mobile'] ?? ''));
        $challanNo = strtolower((string) ($row['challan_no'] ?? ''));
        if (!str_contains($name, $filters['query']) && !str_contains($mobile, $filters['query']) && !str_contains($challanNo, $filters['query'])) {
            return false;
        }
    }
    return true;
}));

$quotes = array_values(array_filter($allQuotes, static function (array $q) use ($filters): bool {
    if ($filters['quote'] === '') {
        return true;
    }
    $hay = strtolower((string) ($q['quote_no'] ?? '') . ' ' . (string) ($q['customer_name'] ?? '') . ' ' . (string) ($q['customer_mobile'] ?? ''));
    return str_contains($hay, $filters['quote']);
}));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Challans</title><link rel="stylesheet" href="assets/css/admin-unified.css"></head>
<body class="admin-shell commercial-admin"><main class="commercial-shell">
<header class="card commercial-header"><div><p class="admin-kicker">Commercial workspace</p><h1>Delivery Challans</h1><p>Prepare and track dispatch while retaining the linked quotation and customer context.</p></div><nav class="commercial-header__actions" aria-label="Page actions"><a class="btn secondary" href="admin-dashboard.php">Dashboard</a><a class="btn secondary" href="admin-documents.php">Document Center</a><a class="btn secondary" href="admin-dispatch-advices.php">Dispatch Advices</a><a class="btn commercial-header__primary" href="#create-challan">+ New Challan</a></nav></header>
<nav class="commercial-flow-strip" aria-label="Commercial lifecycle"><a href="admin-quotations.php">Quotation</a><span>→</span><a href="admin-agreements.php">Agreement</a><span>→</span><a class="active" href="admin-challans.php">Challan</a><span>→</span><a href="admin-invoices.php">Invoice</a><span>→</span><a href="admin-documents.php?tab=accepted_customers">Receipt</a></nav>
<?php if (isset($_GET['message'])): ?><div class="card" style="background:<?= safe_text($_GET['status'] ?? '') === 'error' ? '#fef2f2' : '#ecfdf5' ?>"><?= htmlspecialchars((string) ($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" id="create-challan">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="challan_id" value="">
<section class="form-section-card"><h2>Create Challan</h2><p class="muted-helper">Start with the quotation, customer, delivery date, address, and items. Optional dispatch and appearance fields are tucked away.</p></section>
<section class="form-section-card"><h3>1. Link quotation / customer lookup</h3><p class="muted-helper">Linking a quotation keeps the commercial hand-off traceable.</p><div class="form-grid form-grid--two"><div><label>Link Quotation (optional)</label><select name="linked_quote_id"><option value="">-- Not linked --</option><?php foreach ($quotes as $q): ?><option value="<?= htmlspecialchars((string) $q['id'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $q['quote_no'] . ' | ' . $q['customer_name'] . ' (' . $q['customer_mobile'] . ')', ENT_QUOTES) ?></option><?php endforeach; ?></select></div><div><label>Quote Search</label><input name="quote_q" form="filterForm" value="<?= htmlspecialchars((string) ($filters['quote'] ?? ''), ENT_QUOTES) ?>"><span class="muted-helper">Filters the quotation selector after submitting the list filters.</span></div></div></section>
<section class="form-section-card"><h3>2. Customer details</h3><div class="form-grid"><div><label>Customer Mobile</label><input name="customer_mobile"></div><div><label>Customer Name</label><input name="customer_name"></div><div><label>Consumer Account No (JBVNL)</label><input name="consumer_account_no"></div><div><label>Party Type</label><select name="party_type"><option value="customer">Customer</option><option value="lead">Lead</option></select></div><div><label>Segment</label><select name="segment"><?php foreach ($segments as $seg): ?><option value="<?= htmlspecialchars($seg, ENT_QUOTES) ?>"><?= htmlspecialchars($seg, ENT_QUOTES) ?></option><?php endforeach; ?></select></div></div></section>
<section class="form-section-card"><h3>3. Delivery details</h3><div class="form-grid"><div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>"></div><div><label>City</label><input name="city"></div><div><label>District</label><input name="district"></div><div><label>PIN</label><input name="pin_code"></div><div><label>State</label><input name="state" value="Jharkhand"></div></div><details class="advanced-fields"><summary>Optional delivery notes and appearance</summary><div class="form-grid"><div><label>Template Set</label><select name="template_set_id"><option value="">-- Optional --</option><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string) ($tpl['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($tpl['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div><div><label>Background image path</label><input name="background_image"></div><div><label>Background opacity</label><input type="number" min="0.1" max="1" step="0.05" name="background_opacity" value="1"></div><div class="full-span"><label>Delivery Notes</label><textarea name="delivery_notes"></textarea></div></div></details></section>
<section class="form-section-card"><h3>4. Vehicle / driver details</h3><p class="muted-helper">Optional dispatch information.</p><div class="form-grid form-grid--two"><div><label>Vehicle No</label><input name="vehicle_no"></div><div><label>Driver Name</label><input name="driver_name"></div></div></section>
<section class="form-section-card"><h3>5. Addresses</h3><div class="form-grid"><div><label>Customer Address</label><textarea name="customer_address"></textarea></div><div><label>Site Address</label><textarea name="site_address"></textarea></div><div><label>Delivery Address</label><textarea name="delivery_address"></textarea></div></div></section>
<section class="form-section-card"><h3>6. Items</h3><p class="muted-helper">At least one valid item is required before issue.</p><div class="responsive-table"><table><thead><tr><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php for ($i=0; $i<5; $i++): ?><tr><td><input name="item_name[]"></td><td><input name="item_description[]"></td><td><select name="item_unit[]"><?php foreach ($units as $u): ?><option value="<?= htmlspecialchars($u, ENT_QUOTES) ?>"><?= htmlspecialchars($u, ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="item_qty[]"></td><td><input name="item_remarks[]"></td></tr><?php endfor; ?></tbody></table></div></section>
<footer class="sticky-action-footer"><span class="muted-helper">Save a draft anytime; issue only when items are ready.</span><button class="btn secondary" type="submit" name="action" value="add_suggested_items">Add Suggested Items</button><button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button><button class="btn" type="submit" name="action" value="issue">Save &amp; Issue</button></footer>
</form>
<section class="card"><div class="commercial-toolbar"><div><h2>Challan List</h2><p class="muted-helper">Scan current dispatch records and open secondary output actions only when needed.</p></div></div><form id="filterForm" method="get" class="filter-grid"><div><label>Status</label><select name="status_filter"><option value="">All</option><?php foreach (['Draft','Issued','Archived'] as $st): ?><option value="<?= $st ?>" <?= $filters['status']===$st?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select></div><div><label>Search challan / customer / mobile</label><input name="q" value="<?= htmlspecialchars((string) $filters['query'], ENT_QUOTES) ?>"></div><div><label>Quote Search</label><input name="quote_q" value="<?= htmlspecialchars((string) $filters['quote'], ENT_QUOTES) ?>"></div><div><button class="btn secondary" type="submit">Apply Filters</button></div></form><div class="responsive-table"><table><thead><tr><th>Challan</th><th>Status</th><th>Customer</th><th>Delivery Date</th><th>Created By</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><strong><?= htmlspecialchars((string) $r['challan_no'], ENT_QUOTES) ?></strong></td><td><span class="status-badge status-badge--<?= strtolower(htmlspecialchars((string) $r['status'], ENT_QUOTES)) ?>"><?= htmlspecialchars((string) $r['status'], ENT_QUOTES) ?></span></td><td><?= htmlspecialchars((string) ($r['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?><br><span class="muted-helper"><?= htmlspecialchars((string) ($r['customer_snapshot']['mobile'] ?? ''), ENT_QUOTES) ?></span></td><td><?= htmlspecialchars((string) $r['delivery_date'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) $r['created_by_name'], ENT_QUOTES) ?></td><td><div class="row-action-group"><a class="btn" href="challan-view.php?id=<?= urlencode((string) $r['id']) ?>">View</a><details class="more-actions"><summary class="btn secondary">More</summary><div class="more-actions__menu"><a class="btn secondary" href="challan-print.php?id=<?= urlencode((string) $r['id']) ?>" target="_blank" rel="noopener">Print</a></div></details></div></td></tr><?php endforeach; if ($rows === []): ?><tr><td colspan="6" class="empty-state">No challans found.</td></tr><?php endif; ?></tbody></table></div></section>
</main></body></html>
