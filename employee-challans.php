<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

$employeeStore = new EmployeeFsStore();
employee_portal_require_login();
$employee = employee_portal_current_employee($employeeStore);
if ($employee === null) {
    header('Location: login.php?login_type=employee');
    exit;
}

documents_ensure_structure();
documents_seed_template_sets_if_empty();

$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];
$units = ['Nos', 'Set', 'm', 'kWp', 'Box', 'Lot'];
$templates = array_values(array_filter(json_load(documents_templates_dir() . '/template_sets.json', []), static fn($row): bool => is_array($row) && !($row['archived_flag'] ?? false)));
$allQuotes = array_values(array_filter(documents_list_quotes(), static fn(array $q): bool => (string) ($q['created_by_type'] ?? '') === 'employee' && (string) ($q['created_by_id'] ?? '') === (string) ($employee['id'] ?? '')));

$redirectWith = static function (string $type, string $msg): void {
    header('Location: employee-challans.php?' . http_build_query(['status' => $type, 'message' => $msg]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if (!in_array($action, ['save_draft', 'issue'], true)) {
        $redirectWith('error', 'Invalid action.');
    }

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
    if ($linkedQuote !== null && ((string) ($linkedQuote['created_by_type'] ?? '') !== 'employee' || (string) ($linkedQuote['created_by_id'] ?? '') !== (string) ($employee['id'] ?? ''))) {
        $redirectWith('error', 'You can link only your own quotations.');
    }

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

    $items = [];
    foreach ((array) ($_POST['item_name'] ?? []) as $i => $name) {
        $items[] = [
            'name' => safe_text((string) $name),
            'description' => safe_text((string) (($_POST['item_description'][$i] ?? ''))),
            'unit' => safe_text((string) (($_POST['item_unit'][$i] ?? 'Nos'))),
            'qty' => (float) (($_POST['item_qty'][$i] ?? 0)),
            'remarks' => safe_text((string) (($_POST['item_remarks'][$i] ?? ''))),
        ];
    }

    $challan = documents_challan_defaults();
    $number = documents_generate_challan_number($segment);
    if (!$number['ok']) {
        $redirectWith('error', (string) ($number['error'] ?? 'Unable to create challan number.'));
    }

    $siteAddress = safe_text($_POST['site_address'] ?? '') ?: safe_text((string) ($linkedQuote['site_address'] ?? $snapshot['address']));
    $deliveryAddress = safe_text($_POST['delivery_address'] ?? '') ?: $siteAddress;
    $deliveryDate = safe_text($_POST['delivery_date'] ?? date('Y-m-d')) ?: date('Y-m-d');

    $challan['id'] = 'dc_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $challan['challan_no'] = (string) $number['challan_no'];
    $challan['status'] = $action === 'issue' ? 'Issued' : 'Draft';
    $challan['segment'] = $segment;
    $challan['template_set_id'] = $templateSetId;
    $challan['linked_quote_id'] = $linkedQuote !== null ? (string) ($linkedQuote['id'] ?? '') : '';
    $challan['linked_quote_no'] = $linkedQuote !== null ? (string) ($linkedQuote['quote_no'] ?? '') : '';
    $challan['party_type'] = $partyType;
    $challan['customer_snapshot'] = $snapshot;
    $challan['site_address'] = $siteAddress;
    $challan['delivery_address'] = $deliveryAddress;
    $challan['delivery_date'] = $deliveryDate;
    $challan['vehicle_no'] = safe_text($_POST['vehicle_no'] ?? '');
    $challan['driver_name'] = safe_text($_POST['driver_name'] ?? '');
    $challan['delivery_notes'] = safe_text($_POST['delivery_notes'] ?? '');
    $challan['items'] = documents_normalize_challan_items($items);
    $challan['created_by_type'] = 'employee';
    $challan['created_by_id'] = (string) ($employee['id'] ?? '');
    $challan['created_by_name'] = (string) ($employee['name'] ?? 'Employee');
    $challan['created_at'] = date('c');
    $challan['updated_at'] = date('c');

    if (($challan['customer_snapshot']['mobile'] ?? '') === '' || ($challan['customer_snapshot']['name'] ?? '') === '') {
        $redirectWith('error', 'Customer mobile and name are required.');
    }
    if ($action === 'issue' && !documents_challan_has_valid_items($challan)) {
        $redirectWith('error', 'At least one item with name and qty > 0 is required before issuing.');
    }

    $saved = documents_save_challan($challan);
    if (!$saved['ok']) {
        documents_log('Employee challan save failed: ' . (string) ($saved['error'] ?? 'unknown'));
        $redirectWith('error', 'Unable to save challan. Please retry.');
    }

    header('Location: challan-view.php?id=' . urlencode((string) $challan['id']) . '&status=success&message=' . urlencode($action === 'issue' ? 'Challan issued.' : 'Challan saved as draft.'));
    exit;
}

$rows = array_values(array_filter(documents_list_challans(), static fn(array $r): bool => (string) ($r['created_by_type'] ?? '') === 'employee' && (string) ($r['created_by_id'] ?? '') === (string) ($employee['id'] ?? '')));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Employee Challans</title><style>body{margin:0;font-family:Arial,sans-serif;background:#f4f6fa}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}input,select,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:80px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;vertical-align:top;font-size:13px}.btn{display:inline-block;border:none;border-radius:8px;background:#1d4ed8;color:#fff;text-decoration:none;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}</style></head><body><main class="wrap">
<div class="card"><h1 style="margin-top:0">Delivery Challans (Employee)</h1><a class="btn secondary" href="employee-documents.php">Back to Documents</a></div>
<?php if (isset($_GET['message'])): ?><div class="card" style="background:<?= safe_text($_GET['status'] ?? '') === 'error' ? '#fef2f2' : '#ecfdf5' ?>"><?= htmlspecialchars((string) ($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" class="card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><h2 style="margin-top:0">Create Challan</h2><div class="grid">
<div><label>Link My Quotation (optional)</label><select name="linked_quote_id"><option value="">-- Not linked --</option><?php foreach ($allQuotes as $q): ?><option value="<?= htmlspecialchars((string) $q['id'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $q['quote_no'] . ' | ' . $q['customer_name'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer">customer</option><option value="lead">lead</option></select></div>
<div><label>Template Set</label><select name="template_set_id"><option value="">-- Optional --</option><?php foreach ($templates as $tpl): ?><option value="<?= htmlspecialchars((string) ($tpl['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($tpl['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label>Segment</label><select name="segment"><?php foreach ($segments as $seg): ?><option value="<?= htmlspecialchars($seg, ENT_QUOTES) ?>"><?= htmlspecialchars($seg, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>"></div><div><label>Customer Mobile</label><input name="customer_mobile"></div><div><label>Customer Name</label><input name="customer_name"></div><div><label>Consumer Account No (JBVNL)</label><input name="consumer_account_no"></div>
<div><label>City</label><input name="city"></div><div><label>District</label><input name="district"></div><div><label>PIN</label><input name="pin_code"></div><div><label>State</label><input name="state" value="Jharkhand"></div><div><label>Vehicle No</label><input name="vehicle_no"></div><div><label>Driver Name</label><input name="driver_name"></div>
<div style="grid-column:1/-1"><label>Customer Address</label><textarea name="customer_address"></textarea></div><div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"></textarea></div><div style="grid-column:1/-1"><label>Delivery Address</label><textarea name="delivery_address"></textarea></div><div style="grid-column:1/-1"><label>Delivery Notes</label><textarea name="delivery_notes"></textarea></div>
</div><h3>Items</h3><table><thead><tr><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php for ($i=0; $i<5; $i++): ?><tr><td><input name="item_name[]"></td><td><input name="item_description[]"></td><td><select name="item_unit[]"><?php foreach ($units as $u): ?><option value="<?= htmlspecialchars($u, ENT_QUOTES) ?>"><?= htmlspecialchars($u, ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="item_qty[]"></td><td><input name="item_remarks[]"></td></tr><?php endfor; ?></tbody></table>
<button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button> <button class="btn" type="submit" name="action" value="issue">Save & Issue</button></form>
<div class="card"><h2 style="margin-top:0">My Challans</h2><table><thead><tr><th>Challan No</th><th>Status</th><th>Customer</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= htmlspecialchars((string) $r['challan_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) $r['status'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($r['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) $r['delivery_date'], ENT_QUOTES) ?></td><td><a class="btn secondary" href="challan-view.php?id=<?= urlencode((string) $r['id']) ?>">View</a></td></tr><?php endforeach; if ($rows===[]): ?><tr><td colspan="5">No challans yet.</td></tr><?php endif; ?></tbody></table></div>
</main></body></html>
