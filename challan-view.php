<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();

$viewerType = '';
$viewerId = '';
$user = current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
    }
}
if ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$challan = documents_get_challan($id);
if ($challan === null) {
    http_response_code(404);
    echo 'Challan not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}


$packingList = null;
if ((string) ($challan['linked_quote_id'] ?? '') !== '') {
    $packingList = documents_get_packing_list_for_quote((string) ($challan['linked_quote_id'] ?? ''), true);
}

$redirectWith = static function (string $status, string $message) use ($id): void {
    header('Location: challan-view.php?id=' . urlencode($id) . '&status=' . urlencode($status) . '&message=' . urlencode($message));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if (in_array($action, ['save', 'issue', 'archive'], true)) {
        if ((string) ($challan['status'] ?? 'Draft') !== 'Draft' && $action === 'save') {
            $redirectWith('error', 'Only Draft challans can be edited.');
        }

        if ($action === 'save') {
            $challan['delivery_date'] = safe_text($_POST['delivery_date'] ?? $challan['delivery_date']);
            $challan['site_address'] = safe_text($_POST['site_address'] ?? $challan['site_address']);
            $challan['delivery_address'] = safe_text($_POST['delivery_address'] ?? $challan['delivery_address']);
            $challan['vehicle_no'] = safe_text($_POST['vehicle_no'] ?? $challan['vehicle_no']);
            $challan['driver_name'] = safe_text($_POST['driver_name'] ?? $challan['driver_name']);
            $challan['delivery_notes'] = safe_text($_POST['delivery_notes'] ?? $challan['delivery_notes']);
            $challan['customer_snapshot']['consumer_account_no'] = safe_text($_POST['consumer_account_no'] ?? ($challan['customer_snapshot']['consumer_account_no'] ?? ''));

            $items = [];
            $packNameMap = [];
            if ($packingList !== null) {
                foreach ((array) ($packingList['required_items'] ?? []) as $line) {
                    if (is_array($line)) {
                        $packNameMap[strtolower(trim((string) ($line['component_name_snapshot'] ?? '')))] = (string) ($line['component_id'] ?? '');
                    }
                }
            }
            foreach ((array) ($_POST['item_name'] ?? []) as $i => $name) {
                $itemName = safe_text((string) $name);
                $items[] = [
                    'name' => $itemName,
                    'description' => safe_text((string) (($_POST['item_description'][$i] ?? ''))),
                    'unit' => safe_text((string) (($_POST['item_unit'][$i] ?? 'Nos'))),
                    'qty' => (float) (($_POST['item_qty'][$i] ?? 0)),
                    'remarks' => safe_text((string) (($_POST['item_remarks'][$i] ?? ''))),
                    'component_id' => (string) ($packNameMap[strtolower(trim($itemName))] ?? ''),
                    'dispatch_qty' => 0,
                    'dispatch_ft' => 0,
                ];
            }
            $challan['items'] = documents_normalize_challan_items($items);
        }

        if ($action === 'issue') {
            if (!documents_challan_has_valid_items($challan)) {
                $redirectWith('error', 'At least one valid item is required before issuing.');
            }

            if ($packingList !== null) {
                $requiredMap = [];
                foreach ((array) ($packingList['required_items'] ?? []) as $line) {
                    if (is_array($line)) {
                        $requiredMap[(string) ($line['component_id'] ?? '')] = $line;
                    }
                }

                $dispatchRows = [];
                foreach ((array) ($challan['items'] ?? []) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $componentId = (string) ($line['component_id'] ?? '');
                    if ($componentId === '') {
                        continue;
                    }
                    $qty = max(0, (float) ($line['qty'] ?? 0));
                    if ($qty <= 0) {
                        continue;
                    }
                    $unit = strtolower((string) ($line['unit'] ?? ''));
                    $dispatchRows[] = [
                        'component_id' => $componentId,
                        'dispatch_qty' => $unit === 'ft' ? 0 : $qty,
                        'dispatch_ft' => $unit === 'ft' ? $qty : 0,
                    ];
                }

                $stock = documents_inventory_load_stock();
                foreach ($dispatchRows as $dispatch) {
                    $componentId = (string) ($dispatch['component_id'] ?? '');
                    $requiredLine = $requiredMap[$componentId] ?? null;
                    if (!is_array($requiredLine)) {
                        $redirectWith('error', 'One or more challan items are not part of packing list.');
                    }
                    if ((float) ($dispatch['dispatch_qty'] ?? 0) > (float) ($requiredLine['pending_qty'] ?? 0) + 0.00001 || (float) ($dispatch['dispatch_ft'] ?? 0) > (float) ($requiredLine['pending_ft'] ?? 0) + 0.00001) {
                        $redirectWith('error', 'Dispatch cannot exceed pending quantity.');
                    }

                    $component = documents_inventory_get_component($componentId);
                    if ($component === null) {
                        $redirectWith('error', 'Component not found in inventory.');
                    }
                    $entry = documents_inventory_component_stock($stock, $componentId);
                    if (!empty($component['is_cuttable'])) {
                        if ((float) ($dispatch['dispatch_ft'] ?? 0) > documents_inventory_total_remaining_ft($entry) + 0.00001) {
                            $redirectWith('error', 'Insufficient cuttable stock.');
                        }
                    } else {
                        if ((float) ($dispatch['dispatch_qty'] ?? 0) > (float) ($entry['on_hand_qty'] ?? 0) + 0.00001) {
                            $redirectWith('error', 'Insufficient stock quantity.');
                        }
                    }
                }

                foreach ($dispatchRows as $dispatch) {
                    $componentId = (string) ($dispatch['component_id'] ?? '');
                    $component = documents_inventory_get_component($componentId);
                    if ($component === null) {
                        continue;
                    }
                    $entry = documents_inventory_component_stock($stock, $componentId);
                    $tx = [
                        'id' => 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                        'type' => 'OUT',
                        'component_id' => $componentId,
                        'qty' => 0,
                        'length_ft' => 0,
                        'lot_consumption' => [],
                        'ref_type' => 'delivery_challan',
                        'ref_id' => (string) ($challan['id'] ?? ''),
                        'created_at' => date('c'),
                        'created_by' => (string) ($user['full_name'] ?? $viewerType),
                    ];

                    if (!empty($component['is_cuttable'])) {
                        $needFt = (float) ($dispatch['dispatch_ft'] ?? 0);
                        $consume = documents_inventory_consume_fifo_lots((array) ($entry['lots'] ?? []), $needFt);
                        if (!($consume['ok'] ?? false)) {
                            $redirectWith('error', 'Insufficient lot balance for cuttable stock.');
                        }
                        $entry['lots'] = (array) ($consume['lots'] ?? []);
                        $tx['length_ft'] = $needFt;
                        $tx['lot_consumption'] = (array) ($consume['lot_consumption'] ?? []);
                    } else {
                        $needQty = (float) ($dispatch['dispatch_qty'] ?? 0);
                        $entry['on_hand_qty'] = (float) ($entry['on_hand_qty'] ?? 0) - $needQty;
                        $tx['qty'] = $needQty;
                    }
                    $entry['updated_at'] = date('c');
                    $stock['stock_by_component_id'][$componentId] = $entry;
                    documents_inventory_append_transaction($tx);
                }

                documents_inventory_save_stock($stock);
                $packingList = documents_apply_dispatch_to_packing_list($packingList, (string) ($challan['id'] ?? ''), $dispatchRows);
                documents_save_packing_list($packingList);
            }

            $challan['status'] = 'Issued';
        }

        if ($action === 'archive') {
            $challan['status'] = 'Archived';
        }

        $challan['updated_at'] = date('c');
        $saved = documents_save_challan($challan);
        if (!$saved['ok']) {
            documents_log('Challan update failed for ' . (string) ($challan['id'] ?? '') . ': ' . (string) ($saved['error'] ?? 'unknown'));
            $redirectWith('error', 'Unable to save challan changes.');
        }

        $msg = $action === 'issue' ? 'Challan marked issued.' : ($action === 'archive' ? 'Challan archived.' : 'Challan saved.');
        $redirectWith('success', $msg);
    }
}

$editable = (string) ($challan['status'] ?? 'Draft') === 'Draft';
$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$backLink = $viewerType === 'admin' ? 'admin-challans.php' : 'employee-challans.php';
$units = ['Nos', 'Set', 'm', 'ft', 'kWp', 'Box', 'Lot'];
if ($challan['items'] === []) {
    $challan['items'] = [documents_challan_item_defaults()];
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Challan <?= htmlspecialchars((string) $challan['challan_no'], ENT_QUOTES) ?></title><style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left;font-size:13px}input,textarea,select{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:80px}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}</style></head>
<body><main class="wrap">
<?php if ($message !== ''): ?><div class="card" style="background:<?= $status==='error'?'#fef2f2':'#ecfdf5' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><h1>Delivery Challan</h1><p><strong><?= htmlspecialchars((string) $challan['challan_no'], ENT_QUOTES) ?></strong> · Status: <?= htmlspecialchars((string) $challan['status'], ENT_QUOTES) ?></p><a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a> </div>
<div class="card"><table><tr><th>Customer</th><td><?= htmlspecialchars((string) ($challan['customer_snapshot']['name'] ?? ''), ENT_QUOTES) ?></td><th>Mobile</th><td><?= htmlspecialchars((string) ($challan['customer_snapshot']['mobile'] ?? ''), ENT_QUOTES) ?></td></tr><tr><th>Consumer Account No</th><td><?= htmlspecialchars((string) ($challan['customer_snapshot']['consumer_account_no'] ?? ''), ENT_QUOTES) ?></td><th>Linked Quote</th><td><?= htmlspecialchars((string) ($challan['linked_quote_no'] ?: '-'), ENT_QUOTES) ?></td></tr></table></div>
<form method="post" class="card">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
<h2 style="margin-top:0">Delivery Details</h2>
<div class="grid"><div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?= htmlspecialchars((string) $challan['delivery_date'], ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></div><div><label>Vehicle No</label><input name="vehicle_no" value="<?= htmlspecialchars((string) $challan['vehicle_no'], ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></div><div><label>Driver Name</label><input name="driver_name" value="<?= htmlspecialchars((string) $challan['driver_name'], ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></div><div><label>Consumer Account No</label><input name="consumer_account_no" value="<?= htmlspecialchars((string) ($challan['customer_snapshot']['consumer_account_no'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></div><div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address" <?= $editable?'':'disabled' ?>><?= htmlspecialchars((string) $challan['site_address'], ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Delivery Address</label><textarea name="delivery_address" <?= $editable?'':'disabled' ?>><?= htmlspecialchars((string) $challan['delivery_address'], ENT_QUOTES) ?></textarea></div><div style="grid-column:1/-1"><label>Delivery Notes</label><textarea name="delivery_notes" <?= $editable?'':'disabled' ?>><?= htmlspecialchars((string) $challan['delivery_notes'], ENT_QUOTES) ?></textarea></div></div>
<h2>Items</h2><table><thead><tr><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach ($challan['items'] as $it): ?><tr><td><input name="item_name[]" value="<?= htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><input name="item_description[]" value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><select name="item_unit[]" <?= $editable?'':'disabled' ?>><?php foreach ($units as $u): ?><option value="<?= htmlspecialchars($u, ENT_QUOTES) ?>" <?= (string)($it['unit']??'')===$u?'selected':'' ?>><?= htmlspecialchars($u, ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="item_qty[]" value="<?= htmlspecialchars((string) ($it['qty'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><input name="item_remarks[]" value="<?= htmlspecialchars((string) ($it['remarks'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td></tr><?php endforeach; ?></tbody></table>
<?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save">Save Draft</button><?php endif; ?>
<?php if ((string) ($challan['status'] ?? '') === 'Draft'): ?><button class="btn" type="submit" name="action" value="issue">Mark Issued</button><?php endif; ?>
<?php if ((string) ($challan['status'] ?? '') !== 'Archived'): ?><button class="btn secondary" type="submit" name="action" value="archive">Archive</button><?php endif; ?>
</form>
</main></body></html>
