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

$variantMap = [];
$variantsByComponent = [];
foreach (documents_inventory_component_variants(false) as $variantRow) {
    if (!is_array($variantRow)) {
        continue;
    }
    $variantId = (string) ($variantRow['id'] ?? '');
    $componentId = (string) ($variantRow['component_id'] ?? '');
    if ($variantId === '' || $componentId === '') {
        continue;
    }
    $variantMap[$variantId] = $variantRow;
    if (!isset($variantsByComponent[$componentId])) {
        $variantsByComponent[$componentId] = [];
    }
    $variantsByComponent[$componentId][] = $variantRow;
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
            if ($packingList !== null) {
                $componentMap = [];
                foreach (documents_inventory_components(true) as $cmp) {
                    if (!is_array($cmp)) {
                        continue;
                    }
                    $componentMap[(string) ($cmp['id'] ?? '')] = $cmp;
                }

                $dispatchQtyMap = is_array($_POST['packing_dispatch_qty'] ?? null) ? $_POST['packing_dispatch_qty'] : [];
                $dispatchFtMap = is_array($_POST['packing_dispatch_ft'] ?? null) ? $_POST['packing_dispatch_ft'] : [];
                $manualNoteMap = is_array($_POST['packing_manual_note'] ?? null) ? $_POST['packing_manual_note'] : [];
                $ruleVariantIds = is_array($_POST['rule_variant_id'] ?? null) ? $_POST['rule_variant_id'] : [];
                $ruleVariantQtys = is_array($_POST['rule_dispatch_qty'] ?? null) ? $_POST['rule_dispatch_qty'] : [];
                $ruleLineIds = is_array($_POST['rule_line_id'] ?? null) ? $_POST['rule_line_id'] : [];

                foreach ((array) ($packingList['required_items'] ?? []) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $lineId = (string) ($line['line_id'] ?? '');
                    $componentId = (string) ($line['component_id'] ?? '');
                    $mode = (string) ($line['mode'] ?? 'fixed_qty');
                    $component = $componentMap[$componentId] ?? null;
                    $isCuttable = is_array($component) && !empty($component['is_cuttable']);

                    if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
                        $dispatchQty = max(0, (float) ($dispatchQtyMap[$lineId] ?? 0));
                        $dispatchFt = max(0, (float) ($dispatchFtMap[$lineId] ?? 0));
                        if ($dispatchQty <= 0 && $dispatchFt <= 0) {
                            continue;
                        }
                        $items[] = [
                            'name' => (string) ($line['component_name_snapshot'] ?? ''),
                            'description' => 'From packing list',
                            'unit' => $isCuttable ? 'ft' : (string) ($line['unit'] ?? 'Nos'),
                            'qty' => $isCuttable ? $dispatchFt : $dispatchQty,
                            'remarks' => '',
                            'component_id' => $componentId,
                            'line_id' => $lineId,
                            'mode' => $mode,
                            'dispatch_qty' => $isCuttable ? 0 : $dispatchQty,
                            'dispatch_ft' => $isCuttable ? $dispatchFt : 0,
                        ];
                    } elseif ($mode === 'unfixed_manual') {
                        $dispatchQty = max(0, (float) ($dispatchQtyMap[$lineId] ?? 0));
                        $dispatchFt = max(0, (float) ($dispatchFtMap[$lineId] ?? 0));
                        $manualNote = safe_text((string) ($manualNoteMap[$lineId] ?? ''));
                        if ($dispatchQty <= 0 && $dispatchFt <= 0 && $manualNote === '') {
                            continue;
                        }
                        $items[] = [
                            'name' => (string) ($line['component_name_snapshot'] ?? ''),
                            'description' => 'Manual dispatch',
                            'unit' => $isCuttable ? 'ft' : (string) ($line['unit'] ?? 'Nos'),
                            'qty' => $isCuttable ? $dispatchFt : $dispatchQty,
                            'remarks' => $manualNote,
                            'component_id' => $componentId,
                            'line_id' => $lineId,
                            'mode' => $mode,
                            'manual_note' => $manualNote,
                            'dispatch_qty' => $isCuttable ? 0 : $dispatchQty,
                            'dispatch_ft' => $isCuttable ? $dispatchFt : 0,
                        ];
                    }
                }

                foreach ($ruleVariantIds as $i => $variantIdRaw) {
                    $variantId = safe_text((string) $variantIdRaw);
                    $qty = max(0, (float) ($ruleVariantQtys[$i] ?? 0));
                    $lineId = safe_text((string) ($ruleLineIds[$i] ?? ''));
                    if ($variantId === '' || $lineId === '' || $qty <= 0) {
                        continue;
                    }
                    $variant = $variantMap[$variantId] ?? null;
                    if (!is_array($variant)) {
                        continue;
                    }
                    $componentId = (string) ($variant['component_id'] ?? '');
                    $wattage = (float) ($variant['wattage_wp'] ?? 0);
                    $items[] = [
                        'name' => (string) ($variant['display_name'] ?? 'Variant'),
                        'description' => 'Rule fulfillment dispatch',
                        'unit' => 'Nos',
                        'qty' => $qty,
                        'remarks' => 'Rule line ' . $lineId,
                        'component_id' => $componentId,
                        'line_id' => $lineId,
                        'mode' => 'rule_fulfillment',
                        'variant_id' => $variantId,
                        'variant_name_snapshot' => (string) ($variant['display_name'] ?? ''),
                        'wattage_wp' => $wattage,
                        'dispatch_wp' => $qty * $wattage,
                        'dispatch_qty' => $qty,
                        'dispatch_ft' => 0,
                    ];
                }
            } else {
                foreach ((array) ($_POST['item_name'] ?? []) as $i => $name) {
                    $itemName = safe_text((string) $name);
                    $items[] = [
                        'name' => $itemName,
                        'description' => safe_text((string) (($_POST['item_description'][$i] ?? ''))),
                        'unit' => safe_text((string) (($_POST['item_unit'][$i] ?? 'Nos'))),
                        'qty' => (float) (($_POST['item_qty'][$i] ?? 0)),
                        'remarks' => safe_text((string) (($_POST['item_remarks'][$i] ?? ''))),
                        'component_id' => '',
                        'dispatch_qty' => 0,
                        'dispatch_ft' => 0,
                    ];
                }
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
                        $lineId = (string) ($line['line_id'] ?? '');
                        if ($lineId !== '') {
                            $requiredMap[$lineId] = $line;
                        }
                    }
                }

                $dispatchRows = [];
                foreach ((array) ($challan['items'] ?? []) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $componentId = (string) ($line['component_id'] ?? '');
                    $lineId = (string) ($line['line_id'] ?? '');
                    $mode = (string) ($line['mode'] ?? 'fixed_qty');
                    $qty = max(0, (float) ($line['dispatch_qty'] ?? $line['qty'] ?? 0));
                    $ft = max(0, (float) ($line['dispatch_ft'] ?? 0));
                    $wp = max(0, (float) ($line['dispatch_wp'] ?? 0));
                    if ($mode !== 'rule_fulfillment' && $ft <= 0 && $qty <= 0) {
                        continue;
                    }
                    if ($mode === 'rule_fulfillment' && $qty <= 0) {
                        continue;
                    }
                    $dispatchRows[] = [
                        'line_id' => $lineId,
                        'component_id' => $componentId,
                        'mode' => $mode,
                        'dispatch_qty' => $qty,
                        'dispatch_ft' => $ft,
                        'dispatch_wp' => $wp,
                        'variant_id' => (string) ($line['variant_id'] ?? ''),
                        'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                        'wattage_wp' => (float) ($line['wattage_wp'] ?? 0),
                        'manual_note' => (string) ($line['manual_note'] ?? ''),
                    ];
                }

                $stock = documents_inventory_load_stock();
                $stockEntries = documents_inventory_load_stock_entries();
                foreach ($dispatchRows as $dispatch) {
                    $lineId = (string) ($dispatch['line_id'] ?? '');
                    $requiredLine = $requiredMap[$lineId] ?? null;
                    if (!is_array($requiredLine)) {
                        $redirectWith('error', 'One or more challan items are not part of packing list.');
                    }
                    $mode = (string) ($requiredLine['mode'] ?? 'fixed_qty');
                    $componentId = (string) ($requiredLine['component_id'] ?? $dispatch['component_id'] ?? '');
                    if ($componentId === '') {
                        $redirectWith('error', 'Component missing in dispatch line.');
                    }
                    $component = documents_inventory_get_component($componentId);
                    if ($component === null) {
                        $redirectWith('error', 'Component not found in inventory.');
                    }

                    if (in_array($mode, ['fixed_qty', 'capacity_qty'], true)) {
                        if ((float) ($dispatch['dispatch_qty'] ?? 0) > (float) ($requiredLine['pending_qty'] ?? 0) + 0.00001 || (float) ($dispatch['dispatch_ft'] ?? 0) > (float) ($requiredLine['pending_ft'] ?? 0) + 0.00001) {
                            $redirectWith('error', 'Dispatch cannot exceed pending quantity.');
                        }
                    }

                    if ($mode === 'rule_fulfillment') {
                        $variantId = (string) ($dispatch['variant_id'] ?? '');
                        if ($variantId === '') {
                            $redirectWith('error', 'Variant is required for rule fulfillment dispatch.');
                        }
                        $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                        if ((float) ($dispatch['dispatch_qty'] ?? 0) > (float) ($entry['on_hand_qty'] ?? 0) + 0.00001) {
                            $redirectWith('error', 'Insufficient stock for selected variant.');
                        }

                        $targetWp = (float) ($requiredLine['target_wp'] ?? 0);
                        $alreadyWp = (float) ($requiredLine['dispatched_wp'] ?? 0);
                        $nowWp = (float) ($dispatch['dispatch_wp'] ?? 0);
                        $allowPct = max(0, (float) ($requiredLine['allow_overbuild_pct'] ?? 0));
                        $maxWp = $targetWp * (1 + ($allowPct / 100));
                        if (($alreadyWp + $nowWp) > $maxWp + 0.00001) {
                            $redirectWith('error', 'Rule dispatch overbuild exceeds allowed percentage.');
                        }
                    } elseif (!empty($component['is_cuttable'])) {
                        $entry = documents_inventory_component_stock($stock, $componentId);
                        if ((float) ($dispatch['dispatch_ft'] ?? 0) > documents_inventory_total_remaining_ft($entry) + 0.00001) {
                            $redirectWith('error', 'Insufficient cuttable stock.');
                        }
                    } else {
                        $entry = documents_inventory_component_stock($stock, $componentId);
                        if ((float) ($dispatch['dispatch_qty'] ?? 0) > (float) ($entry['on_hand_qty'] ?? 0) + 0.00001) {
                            $redirectWith('error', 'Insufficient stock quantity.');
                        }
                    }
                }

                foreach ($dispatchRows as $dispatch) {
                    $lineId = (string) ($dispatch['line_id'] ?? '');
                    $requiredLine = $requiredMap[$lineId] ?? null;
                    if (!is_array($requiredLine)) {
                        continue;
                    }
                    $componentId = (string) ($requiredLine['component_id'] ?? $dispatch['component_id'] ?? '');
                    $component = documents_inventory_get_component($componentId);
                    if ($component === null) {
                        continue;
                    }
                    $mode = (string) ($requiredLine['mode'] ?? 'fixed_qty');
                    $variantId = $mode === 'rule_fulfillment' ? (string) ($dispatch['variant_id'] ?? '') : '';
                    $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                    $tx = [
                        'id' => 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
                        'type' => 'OUT',
                        'component_id' => $componentId,
                        'variant_id' => $variantId,
                        'qty' => 0,
                        'length_ft' => 0,
                        'lot_consumption' => [],
                        'entry_consumption' => [],
                        'ref_type' => 'delivery_challan',
                        'ref_id' => (string) ($challan['id'] ?? ''),
                        'created_at' => date('c'),
                        'created_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => (string) ($user['full_name'] ?? $viewerType)],
                    ];

                    if (!empty($component['is_cuttable']) && $mode !== 'rule_fulfillment') {
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
                        $consumed = documents_inventory_consume_from_location_breakdown($entry, $needQty, '');
                        if (!($consumed['ok'] ?? false)) {
                            $redirectWith('error', (string) ($consumed['error'] ?? 'Insufficient stock quantity.'));
                        }
                        $entry = (array) ($consumed['entry'] ?? $entry);
                        $entryConsume = documents_inventory_consume_entries_fifo($stockEntries, $componentId, $variantId, $needQty);
                        if (!($entryConsume['ok'] ?? false)) {
                            $redirectWith('error', (string) ($entryConsume['error'] ?? 'Insufficient stock entries.'));
                        }
                        $stockEntries = (array) ($entryConsume['entries'] ?? $stockEntries);
                        $tx['qty'] = $needQty;
                        $tx['entry_consumption'] = (array) ($entryConsume['entry_consumption'] ?? []);
                        $tx['location_consumption'] = (array) ($consumed['location_consumption'] ?? []);
                        $tx['location_id'] = (string) ($consumed['location_id'] ?? 'mixed');
                    }
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
                    documents_inventory_append_transaction($tx);
                }

                documents_inventory_save_stock($stock);
                documents_inventory_save_stock_entries($stockEntries);
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
$stockSnapshot = documents_inventory_load_stock();
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
<h2>Items</h2>
<?php if ($packingList !== null): ?>
  <table><thead><tr><th>Component</th><th>Mode</th><th>Pending / Target</th><th>Dispatch now</th></tr></thead><tbody>
  <?php foreach ((array) ($packingList['required_items'] ?? []) as $line): ?>
    <?php $lineId = (string) ($line['line_id'] ?? ''); $mode = (string) ($line['mode'] ?? 'fixed_qty'); $componentId = (string) ($line['component_id'] ?? ''); $component = documents_inventory_get_component($componentId); $isCuttable = is_array($component) && !empty($component['is_cuttable']); ?>
    <tr>
      <td><?= htmlspecialchars((string) ($line['component_name_snapshot'] ?? ''), ENT_QUOTES) ?><input type="hidden" name="packing_line_id[]" value="<?= htmlspecialchars($lineId, ENT_QUOTES) ?>" /></td>
      <td><?= htmlspecialchars($mode, ENT_QUOTES) ?></td>
      <td><?php if ($mode === 'rule_fulfillment'): ?>Target <?= htmlspecialchars((string) ($line['target_wp'] ?? 0), ENT_QUOTES) ?> Wp, Remaining <?= htmlspecialchars((string) max(0, (float) ($line['target_wp'] ?? 0) - (float) ($line['dispatched_wp'] ?? 0)), ENT_QUOTES) ?> Wp<?php elseif ($mode === 'unfixed_manual'): ?>Manual dispatch<?php else: ?><?= htmlspecialchars((string) (((float) ($line['pending_ft'] ?? 0) > 0) ? (($line['pending_ft'] ?? 0) . ' ft') : (($line['pending_qty'] ?? 0) . ' ' . ($line['unit'] ?? ''))), ENT_QUOTES) ?><?php endif; ?></td>
      <td>
        <?php if (in_array($mode, ['fixed_qty', 'capacity_qty', 'unfixed_manual'], true)): ?>
          <input type="hidden" name="packing_component_id[<?= htmlspecialchars($lineId, ENT_QUOTES) ?>]" value="<?= htmlspecialchars($componentId, ENT_QUOTES) ?>" />
          <?php if ($isCuttable || (string) ($line['unit'] ?? '') === 'ft'): ?>
            <label>Feet</label><input type="number" step="0.01" min="0" name="packing_dispatch_ft[<?= htmlspecialchars($lineId, ENT_QUOTES) ?>]" value="<?= htmlspecialchars((string) (($editable && $mode==='unfixed_manual') ? 0 : 0), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?> />
          <?php else: ?>
            <label>Qty</label><input type="number" step="0.01" min="0" name="packing_dispatch_qty[<?= htmlspecialchars($lineId, ENT_QUOTES) ?>]" value="0" <?= $editable?'':'disabled' ?> />
          <?php endif; ?>
          <?php if ($mode === 'unfixed_manual'): ?><label>Note</label><input name="packing_manual_note[<?= htmlspecialchars($lineId, ENT_QUOTES) ?>]" value="" <?= $editable?'':'disabled' ?> /><?php endif; ?>
        <?php else: ?>
          <?php $remainingWp = max(0, (float) ($line['target_wp'] ?? 0) - (float) ($line['dispatched_wp'] ?? 0)); $allowPct = max(0, (float) ($line['allow_overbuild_pct'] ?? 0)); $allowWp = $remainingWp * (1 + ($allowPct / 100)); ?>
          <div class="muted">Remaining <?= htmlspecialchars((string) $remainingWp, ENT_QUOTES) ?> Wp (allow upto <?= htmlspecialchars((string) $allowWp, ENT_QUOTES) ?>)</div>
          <?php foreach ((array) ($variantsByComponent[$componentId] ?? []) as $variant): $vid=(string)($variant['id']??''); $vStock=documents_inventory_component_stock($stockSnapshot, $componentId, $vid); $available=(float)($vStock['on_hand_qty'] ?? 0); ?>
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:6px;align-items:center;margin-top:4px;">
              <input type="hidden" name="rule_line_id[]" value="<?= htmlspecialchars($lineId, ENT_QUOTES) ?>" />
              <input type="hidden" name="rule_variant_id[]" value="<?= htmlspecialchars($vid, ENT_QUOTES) ?>" />
              <span><?= htmlspecialchars((string) ($variant['display_name'] ?? $vid), ENT_QUOTES) ?> (<?= htmlspecialchars((string) ($variant['wattage_wp'] ?? 0), ENT_QUOTES) ?>Wp, Avl <?= htmlspecialchars((string) $available, ENT_QUOTES) ?>)</span>
              <input type="number" step="0.01" min="0" max="<?= htmlspecialchars((string) $available, ENT_QUOTES) ?>" name="rule_dispatch_qty[]" value="0" <?= $editable?'':'disabled' ?> />
              <span class="muted">Wp/qty: <?= htmlspecialchars((string) ($variant['wattage_wp'] ?? 0), ENT_QUOTES) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php else: ?>
<table><thead><tr><th>Name</th><th>Description</th><th>Unit</th><th>Qty</th><th>Remarks</th></tr></thead><tbody><?php foreach ($challan['items'] as $it): ?><tr><td><input name="item_name[]" value="<?= htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><input name="item_description[]" value="<?= htmlspecialchars((string) ($it['description'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><select name="item_unit[]" <?= $editable?'':'disabled' ?>><?php foreach ($units as $u): ?><option value="<?= htmlspecialchars($u, ENT_QUOTES) ?>" <?= (string)($it['unit']??'')===$u?'selected':'' ?>><?= htmlspecialchars($u, ENT_QUOTES) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="item_qty[]" value="<?= htmlspecialchars((string) ($it['qty'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td><td><input name="item_remarks[]" value="<?= htmlspecialchars((string) ($it['remarks'] ?? ''), ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
<?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save">Save Draft</button><?php endif; ?>
<?php if ((string) ($challan['status'] ?? '') === 'Draft'): ?><button class="btn" type="submit" name="action" value="issue">Mark Issued</button><?php endif; ?>
<?php if ((string) ($challan['status'] ?? '') !== 'Archived'): ?><button class="btn secondary" type="submit" name="action" value="archive">Archive</button><?php endif; ?>
</form>
</main></body></html>
