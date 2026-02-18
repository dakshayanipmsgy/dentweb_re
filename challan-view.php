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
$viewerName = 'User';
$user = current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
    $viewerName = (string) ($user['full_name'] ?? 'Admin');
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
        $viewerName = (string) ($employee['name'] ?? 'Employee');
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
if ($viewerType === 'employee' && ((string) ($challan['created_by']['role'] ?? $challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by']['id'] ?? $challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$components = documents_inventory_components(false);
$componentMap = [];
$componentClientMap = [];
foreach ($components as $component) {
    if (!is_array($component)) {
        continue;
    }
    $componentId = (string) ($component['id'] ?? '');
    if ($componentId === '') {
        continue;
    }
    $componentMap[$componentId] = $component;
    $componentClientMap[$componentId] = [
        'id' => $componentId,
        'name' => (string) ($component['name'] ?? $componentId),
        'unit' => (string) ($component['unit'] ?? 'Nos'),
        'hsn' => (string) ($component['hsn'] ?? ''),
        'is_cuttable' => !empty($component['is_cuttable']),
        'has_variants' => !empty($component['has_variants']),
    ];
}
$variantMap = [];
$variantsByComponent = [];
foreach (documents_inventory_component_variants(false) as $variant) {
    if (!is_array($variant)) {
        continue;
    }
    $variantId = (string) ($variant['id'] ?? '');
    $componentId = (string) ($variant['component_id'] ?? '');
    if ($variantId === '' || $componentId === '') {
        continue;
    }
    $variantMap[$variantId] = $variant;
    $variantsByComponent[$componentId][] = [
        'id' => $variantId,
        'name' => (string) ($variant['display_name'] ?? $variantId),
        'wattage_wp' => (float) ($variant['wattage_wp'] ?? 0),
    ];
}

$redirectWith = static function (string $status, string $message) use ($id): void {
    header('Location: challan-view.php?id=' . urlencode($id) . '&status=' . urlencode($status) . '&message=' . urlencode($message));
    exit;
};

$packingList = null;
$quoteId = (string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? '');
if ($quoteId !== '') {
    $packingList = documents_get_packing_list_for_quote($quoteId, false);
    if ($packingList !== null && (string) ($challan['packing_list_id'] ?? '') === '') {
        $challan['packing_list_id'] = (string) ($packingList['id'] ?? '');
    }
}

$stockSnapshot = documents_inventory_load_stock();
$packingByRequiredItem = [];
$packingGroups = [];
if ($packingList !== null) {
    foreach ((array) ($packingList['required_items'] ?? []) as $requiredLineRaw) {
        if (!is_array($requiredLineRaw)) {
            continue;
        }
        $requiredLine = array_merge(documents_packing_required_line_defaults(), $requiredLineRaw);
        $requiredItemId = (string) ($requiredLine['required_item_id'] ?? '');
        if ($requiredItemId === '') {
            $requiredItemId = (string) ($requiredLine['line_id'] ?? '');
        }
        if ($requiredItemId === '') {
            continue;
        }
        $requiredLine['required_item_id'] = $requiredItemId;
        $packingByRequiredItem[$requiredItemId] = $requiredLine;

        $groupKey = (string) ($requiredLine['kit_id'] ?? '');
        if ($groupKey === '') {
            $groupKey = '__direct_components';
        }
        if (!isset($packingGroups[$groupKey])) {
            $packingGroups[$groupKey] = [
                'kit_id' => (string) ($requiredLine['kit_id'] ?? ''),
                'kit_name' => (string) ($requiredLine['kit_name_snapshot'] ?? ''),
                'items' => [],
            ];
            if ($groupKey === '__direct_components') {
                $packingGroups[$groupKey]['kit_name'] = 'Direct quotation components';
            }
        }
        $packingGroups[$groupKey]['items'][] = $requiredLine;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    $isDraft = (string) ($challan['status'] ?? 'draft') === 'draft';
    if (in_array($action, ['save_draft', 'finalize', 'archive'], true)) {
        if (!$isDraft && in_array($action, ['save_draft', 'finalize'], true)) {
            $redirectWith('error', 'Only draft DC can be edited/finalized.');
        }

        if (in_array($action, ['save_draft', 'finalize'], true)) {
            $challan['delivery_date'] = safe_text($_POST['delivery_date'] ?? $challan['delivery_date']);
            $challan['site_address'] = safe_text($_POST['site_address'] ?? $challan['site_address']);
            $challan['site_address_snapshot'] = $challan['site_address'];
            $challan['delivery_address'] = safe_text($_POST['delivery_address'] ?? $challan['delivery_address']);
            $challan['vehicle_no'] = safe_text($_POST['vehicle_no'] ?? $challan['vehicle_no']);
            $challan['driver_name'] = safe_text($_POST['driver_name'] ?? $challan['driver_name']);
            $challan['delivery_notes'] = safe_text($_POST['delivery_notes'] ?? $challan['delivery_notes']);

            $lineIds = is_array($_POST['line_id'] ?? null) ? $_POST['line_id'] : [];
            $sourceTypes = is_array($_POST['line_source_type'] ?? null) ? $_POST['line_source_type'] : [];
            $requiredItemIds = is_array($_POST['line_required_item_id'] ?? null) ? $_POST['line_required_item_id'] : [];
            $kitIds = is_array($_POST['line_kit_id'] ?? null) ? $_POST['line_kit_id'] : [];
            $kitNames = is_array($_POST['line_kit_name_snapshot'] ?? null) ? $_POST['line_kit_name_snapshot'] : [];
            $componentIds = is_array($_POST['line_component_id'] ?? null) ? $_POST['line_component_id'] : [];
            $variantIds = is_array($_POST['line_variant_id'] ?? null) ? $_POST['line_variant_id'] : [];
            $qtys = is_array($_POST['line_qty'] ?? null) ? $_POST['line_qty'] : [];
            $lengths = is_array($_POST['line_length_ft'] ?? null) ? $_POST['line_length_ft'] : [];
            $pieces = is_array($_POST['line_pieces'] ?? null) ? $_POST['line_pieces'] : [];
            $notes = is_array($_POST['line_notes'] ?? null) ? $_POST['line_notes'] : [];
            $lotIdsRows = is_array($_POST['line_lot_ids'] ?? null) ? $_POST['line_lot_ids'] : [];

            $lines = [];
            foreach ($componentIds as $idx => $componentIdRaw) {
                $componentId = safe_text((string) $componentIdRaw);
                if ($componentId === '') {
                    continue;
                }
                $component = $componentMap[$componentId] ?? null;
                if (!is_array($component)) {
                    $redirectWith('error', 'Invalid component selected.');
                }

                $sourceType = in_array((string) ($sourceTypes[$idx] ?? 'extra'), ['packing', 'extra'], true) ? (string) ($sourceTypes[$idx] ?? 'extra') : 'extra';
                $requiredItemId = safe_text((string) ($requiredItemIds[$idx] ?? ''));
                $kitId = safe_text((string) ($kitIds[$idx] ?? ''));
                $kitName = safe_text((string) ($kitNames[$idx] ?? ''));

                $hasVariants = !empty($component['has_variants']);
                $variantId = safe_text((string) ($variantIds[$idx] ?? ''));
                if ($hasVariants) {
                    if ($variantId === '') {
                        $redirectWith('error', 'Variant is required for variant-enabled component lines.');
                    }
                    $variant = $variantMap[$variantId] ?? null;
                    if (!is_array($variant) || (string) ($variant['component_id'] ?? '') !== $componentId) {
                        $redirectWith('error', 'Variant must belong to the selected component.');
                    }
                }

                $isCuttable = !empty($component['is_cuttable']);
                $qty = max(0, (float) ($qtys[$idx] ?? 0));
                $lengthFt = max(0, (float) ($lengths[$idx] ?? 0));
                if ($isCuttable) {
                    if ($lengthFt <= 0) {
                        $redirectWith('error', 'Length (ft) is required for cuttable item lines.');
                    }
                } elseif ($qty <= 0) {
                    $redirectWith('error', 'Qty is required for non-cuttable item lines.');
                }

                $lineLotIds = [];
                $lotRow = $lotIdsRows[$idx] ?? [];
                if (is_array($lotRow)) {
                    foreach ($lotRow as $lotId) {
                        $lotId = safe_text((string) $lotId);
                        if ($lotId !== '') {
                            $lineLotIds[] = $lotId;
                        }
                    }
                }

                $entry = documents_inventory_component_stock($stockSnapshot, $componentId, $variantId);
                if ($isCuttable) {
                    $availableFt = documents_inventory_total_remaining_ft($entry);
                    if ($availableFt <= 0.00001 || $lengthFt > $availableFt + 0.00001) {
                        $redirectWith('error', 'Line exceeds ready stock for ' . (string) ($component['name'] ?? 'component') . '.');
                    }
                } else {
                    $availableQty = max(0, (float) ($entry['on_hand_qty'] ?? 0));
                    if ($availableQty <= 0.00001 || $qty > $availableQty + 0.00001) {
                        $redirectWith('error', 'Line exceeds ready stock for ' . (string) ($component['name'] ?? 'component') . '.');
                    }
                }

                $variantName = '';
                if ($variantId !== '') {
                    $variantName = (string) (($variantMap[$variantId]['display_name'] ?? $variantId));
                }
                $lines[] = [
                    'line_id' => safe_text((string) ($lineIds[$idx] ?? '')) ?: ('line_' . bin2hex(random_bytes(4))),
                    'source_type' => $sourceType,
                    'packing_required_item_id' => $sourceType === 'packing' ? $requiredItemId : '',
                    'kit_id' => $sourceType === 'packing' ? $kitId : '',
                    'kit_name_snapshot' => $sourceType === 'packing' ? $kitName : '',
                    'component_id' => $componentId,
                    'component_name_snapshot' => (string) ($component['name'] ?? $componentId),
                    'has_variants_snapshot' => $hasVariants,
                    'variant_id' => $variantId,
                    'variant_name_snapshot' => $variantName,
                    'is_cuttable_snapshot' => $isCuttable,
                    'qty' => $isCuttable ? 0 : $qty,
                    'length_ft' => $isCuttable ? $lengthFt : 0,
                    'pieces' => max(0, (int) ($pieces[$idx] ?? 0)),
                    'lot_ids' => $lineLotIds,
                    'unit_snapshot' => (string) ($component['unit'] ?? ($isCuttable ? 'ft' : 'Nos')),
                    'hsn_snapshot' => (string) ($component['hsn'] ?? ''),
                    'notes' => safe_text((string) ($notes[$idx] ?? '')),
                ];
            }

            $challan['lines'] = documents_normalize_challan_lines($lines);
            if ($challan['lines'] === []) {
                $redirectWith('error', 'Add at least one valid line.');
            }

            $challan['items'] = array_map(static function (array $line): array {
                return [
                    'name' => (string) ($line['component_name_snapshot'] ?? ''),
                    'description' => (string) ($line['notes'] ?? ''),
                    'unit' => (string) ($line['unit_snapshot'] ?? ''),
                    'qty' => !empty($line['is_cuttable_snapshot']) ? (float) ($line['length_ft'] ?? 0) : (float) ($line['qty'] ?? 0),
                    'remarks' => (string) ($line['notes'] ?? ''),
                    'component_id' => (string) ($line['component_id'] ?? ''),
                    'line_id' => (string) ($line['line_id'] ?? ''),
                    'variant_id' => (string) ($line['variant_id'] ?? ''),
                    'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                    'dispatch_qty' => (float) ($line['qty'] ?? 0),
                    'dispatch_ft' => (float) ($line['length_ft'] ?? 0),
                ];
            }, $challan['lines']);
        }

        if ($action === 'finalize') {
            $stock = documents_inventory_load_stock();
            $txnIds = [];
            $dispatchRows = [];
            foreach ((array) ($challan['lines'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $componentId = (string) ($line['component_id'] ?? '');
                $variantId = (string) ($line['variant_id'] ?? '');
                $component = $componentMap[$componentId] ?? null;
                if (!is_array($component)) {
                    $redirectWith('error', 'Component not found for one or more lines.');
                }

                $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                $txId = 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
                $tx = [
                    'id' => $txId,
                    'type' => 'OUT',
                    'component_id' => $componentId,
                    'variant_id' => $variantId,
                    'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                    'unit' => (string) ($line['unit_snapshot'] ?? ''),
                    'qty' => 0,
                    'length_ft' => 0,
                    'lot_consumption' => [],
                    'location_consumption' => [],
                    'ref_type' => 'delivery_challan',
                    'ref_id' => (string) ($challan['id'] ?? ''),
                    'reason' => 'DC Finalized',
                    'notes' => (string) ($line['notes'] ?? ''),
                    'created_at' => date('c'),
                    'created_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                ];

                $dispatchWp = 0.0;
                if (!empty($line['is_cuttable_snapshot'])) {
                    $needFt = max(0, (float) ($line['length_ft'] ?? 0));
                    if ($needFt > documents_inventory_total_remaining_ft($entry) + 0.00001) {
                        $redirectWith('error', 'Insufficient cuttable stock for ' . (string) ($line['component_name_snapshot'] ?? 'component') . '.');
                    }
                    $consume = documents_inventory_consume_fifo_lots((array) ($entry['lots'] ?? []), $needFt);
                    if (!($consume['ok'] ?? false)) {
                        $redirectWith('error', (string) ($consume['error'] ?? 'Unable to consume lots for cuttable stock.'));
                    }
                    $entry['lots'] = (array) ($consume['lots'] ?? []);
                    $tx['length_ft'] = $needFt;
                    $tx['lot_consumption'] = (array) ($consume['lot_consumption'] ?? []);
                } else {
                    $needQty = max(0, (float) ($line['qty'] ?? 0));
                    if ($needQty > (float) ($entry['on_hand_qty'] ?? 0) + 0.00001) {
                        $redirectWith('error', 'Insufficient stock quantity for ' . (string) ($line['component_name_snapshot'] ?? 'component') . '.');
                    }
                    $consumed = documents_inventory_consume_from_location_breakdown($entry, $needQty, '');
                    if (!($consumed['ok'] ?? false)) {
                        $redirectWith('error', (string) ($consumed['error'] ?? 'Insufficient stock quantity.'));
                    }
                    $entry = (array) ($consumed['entry'] ?? $entry);
                    $tx['qty'] = $needQty;
                    $tx['location_consumption'] = (array) ($consumed['location_consumption'] ?? []);
                    $tx['location_id'] = (string) ($consumed['location_id'] ?? 'mixed');
                    if ($variantId !== '') {
                        $wattage = max(0, (float) (($variantMap[$variantId]['wattage_wp'] ?? 0)));
                        $dispatchWp = $wattage * $needQty;
                    }
                }

                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
                documents_inventory_append_transaction($tx);
                $txnIds[] = $txId;

                $dispatchRows[] = [
                    'required_item_id' => (string) ($line['packing_required_item_id'] ?? ''),
                    'line_id' => (string) ($line['packing_required_item_id'] ?: ($line['line_id'] ?? '')),
                    'component_id' => $componentId,
                    'dispatch_qty' => max(0, (float) ($line['qty'] ?? 0)),
                    'dispatch_ft' => max(0, (float) ($line['length_ft'] ?? 0)),
                    'dispatch_wp' => $dispatchWp,
                    'variant_id' => $variantId,
                    'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                    'wattage_wp' => isset($variantMap[$variantId]) ? (float) ($variantMap[$variantId]['wattage_wp'] ?? 0) : 0,
                ];
            }
            $savedStock = documents_inventory_save_stock($stock);
            if (!($savedStock['ok'] ?? false)) {
                $redirectWith('error', 'Failed to update inventory stock.');
            }
            $challan['inventory_txn_ids'] = $txnIds;
            $challan['status'] = 'final';

            if ($packingList !== null) {
                $packingList = documents_apply_dispatch_to_packing_list($packingList, (string) ($challan['id'] ?? ''), array_values(array_filter($dispatchRows, static fn(array $row): bool => (string) ($row['required_item_id'] ?? '') !== '')));
                $savedPacking = documents_save_packing_list($packingList);
                if (!($savedPacking['ok'] ?? false)) {
                    $redirectWith('error', 'Inventory updated but failed to update packing list.');
                }
            }
        }

        if ($action === 'save_draft') {
            $challan['status'] = 'draft';
        }
        if ($action === 'archive') {
            $challan['status'] = 'archived';
            $challan['archived_flag'] = true;
        }

        $challan['updated_at'] = date('c');
        $saved = documents_save_challan($challan);
        if (!$saved['ok']) {
            $redirectWith('error', 'Unable to save delivery challan.');
        }

        $successMessage = $action === 'finalize' ? 'DC finalized and inventory + packing updated.' : ($action === 'save_draft' ? 'DC draft saved.' : 'DC archived.');
        $redirectWith('success', $successMessage);
    }
}

$backLink = (is_array($user) && (($user['role_name'] ?? '') === 'admin')) ? 'admin-documents.php?tab=accepted_customers&view=' . urlencode((string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? '')) : 'employee-challans.php';
$statusParam = safe_text($_GET['status'] ?? '');
$messageParam = safe_text($_GET['message'] ?? '');
$editable = (string) ($challan['status'] ?? 'draft') === 'draft';
$lines = documents_normalize_challan_lines((array) ($challan['lines'] ?? []));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Delivery Challan Builder</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111;margin:0}.wrap{max-width:1320px;margin:20px auto;padding:0 14px}.card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}.btn{display:inline-block;background:#0b57d0;color:#fff;border:1px solid #0b57d0;padding:8px 12px;border-radius:8px;text-decoration:none;cursor:pointer}.btn.secondary{background:#fff;color:#0b57d0}.btn.warn{background:#b91c1c;border-color:#b91c1c}.btn.disabled{opacity:.5;pointer-events:none}.muted{color:#555}label{display:block;font-size:12px;margin-bottom:4px}input,select,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ee;padding:8px;vertical-align:top}thead th{background:#f1f5f9}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.badge{padding:2px 8px;border-radius:999px;font-size:12px;background:#e2e8f0}.pill{padding:2px 6px;border-radius:999px;font-size:12px;display:inline-block}.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:#b91c1c}.row-actions{display:flex;gap:6px;align-items:center}.line-extra{background:#fffbeb}
</style></head>
<body><main class="wrap">
<div class="card"><h1 style="margin:0 0 8px">Delivery Challan</h1>
<p><strong><?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></strong> · Status: <span class="badge"><?= htmlspecialchars(strtoupper((string) ($challan['status'] ?? 'draft')), ENT_QUOTES) ?></span></p>
<div class="row-actions"><a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a><a class="btn secondary" href="challan-print.php?id=<?= urlencode((string) ($challan['id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a></div></div>
<?php if ($statusParam !== '' && $messageParam !== ''): ?><div class="card"><strong><?= htmlspecialchars(strtoupper($statusParam), ENT_QUOTES) ?>:</strong> <?= htmlspecialchars($messageParam, ENT_QUOTES) ?></div><?php endif; ?>

<?php if ($packingList !== null): ?>
<div class="card"><h3 style="margin-top:0">Quotation Kits / Packing List</h3>
<?php foreach ($packingGroups as $group): ?>
  <h4><?= htmlspecialchars((string) ($group['kit_name'] ?: 'Kit'), ENT_QUOTES) ?></h4>
  <table><thead><tr><th>Component</th><th>Required</th><th>Supplied</th><th>Remaining</th><th>Ready</th><th>Action</th></tr></thead><tbody>
  <?php foreach ((array) ($group['items'] ?? []) as $req):
    $componentId=(string)($req['component_id']??'');
    $entry=documents_inventory_component_stock($stockSnapshot,$componentId,'');
    $availableQty=(float)($entry['on_hand_qty']??0);
    $availableFt=documents_inventory_total_remaining_ft($entry);
    $isCuttable=!empty($req['is_cuttable'])||strtolower((string)($req['unit']??''))==='ft';
    $remaining=$isCuttable?max(0,(float)($req['pending_ft']??0)):max(0,(float)($req['pending_qty']??0));
    $avail=$isCuttable?$availableFt:$availableQty;
    $readyClass=$avail<=0.00001?'bad':($avail+0.00001<$remaining?'warn':'ok');
    $readyText=$avail<=0.00001?'❌ Not ready':($avail+0.00001<$remaining?'⚠ Low stock':'✅ Ready');
    $disabled=$avail<=0.00001?'disabled':'';
  ?>
    <tr>
      <td><?= htmlspecialchars((string) ($req['component_name_snapshot'] ?? ''), ENT_QUOTES) ?><?= !empty($req['has_variants']) ? ' <span class="muted">(variant required)</span>' : '' ?></td>
      <td><?php if ((string)($req['mode']??'')==='rule_fulfillment'): ?>Target <?= (float)($req['target_wp']??0) ?> Wp<?php elseif($isCuttable): ?><?= (float)($req['required_ft']??0) ?> ft<?php else: ?><?= (float)($req['required_qty']??0) ?><?php endif; ?></td>
      <td><?php if ((string)($req['mode']??'')==='rule_fulfillment'): ?><?= (float)($req['supplied_total_wp']??$req['dispatched_wp']??0) ?> Wp<?php elseif($isCuttable): ?><?= (float)($req['supplied_ft']??$req['dispatched_ft']??0) ?> ft<?php else: ?><?= (float)($req['supplied_qty']??$req['dispatched_qty']??0) ?><?php endif; ?></td>
      <td><?php if ((string)($req['mode']??'')==='rule_fulfillment'): ?><?= max(0,(float)($req['target_wp']??0)-(float)($req['supplied_total_wp']??$req['dispatched_wp']??0)) ?> Wp<?php elseif($isCuttable): ?><?= (float)($req['pending_ft']??0) ?> ft<?php else: ?><?= (float)($req['pending_qty']??0) ?><?php endif; ?></td>
      <td><span class="pill <?= $readyClass ?>"><?= htmlspecialchars($readyText, ENT_QUOTES) ?></span></td>
      <td><button class="btn secondary add-packing-line" type="button" <?= $disabled ?> data-required='<?= htmlspecialchars(json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>Add to DC</button></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" class="card">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
<div class="grid"><div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?= htmlspecialchars((string) ($challan['delivery_date'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div><div><label>Site Address</label><input name="site_address" value="<?= htmlspecialchars((string) ($challan['site_address'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div><div><label>Delivery Address</label><input name="delivery_address" value="<?= htmlspecialchars((string) ($challan['delivery_address'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div><div><label>Vehicle No</label><input name="vehicle_no" value="<?= htmlspecialchars((string) ($challan['vehicle_no'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div></div>
<div style="margin-top:8px"><label>Delivery Notes</label><textarea name="delivery_notes" <?= $editable ? '' : 'disabled' ?>><?= htmlspecialchars((string) ($challan['delivery_notes'] ?? ''), ENT_QUOTES) ?></textarea></div>

<h3>DC Lines</h3>
<table id="dc-lines"><thead><tr><th>Source</th><th>Component</th><th>Variant</th><th>Qty</th><th>Length(ft)</th><th>Pieces</th><th>Notes</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($lines as $line): $cid=(string)($line['component_id']??''); $vid=(string)($line['variant_id']??''); ?>
<tr class="dc-line-row <?= (string)($line['source_type']??'extra')==='extra'?'line-extra':'' ?>">
<td>
<input type="hidden" name="line_id[]" value="<?= htmlspecialchars((string)($line['line_id']??''),ENT_QUOTES) ?>">
<input type="hidden" name="line_source_type[]" value="<?= htmlspecialchars((string)($line['source_type']??'extra'),ENT_QUOTES) ?>" class="line-source-type">
<input type="hidden" name="line_required_item_id[]" value="<?= htmlspecialchars((string)($line['packing_required_item_id']??''),ENT_QUOTES) ?>">
<input type="hidden" name="line_kit_id[]" value="<?= htmlspecialchars((string)($line['kit_id']??''),ENT_QUOTES) ?>">
<input type="hidden" name="line_kit_name_snapshot[]" value="<?= htmlspecialchars((string)($line['kit_name_snapshot']??''),ENT_QUOTES) ?>">
<?php if((string)($line['source_type']??'extra')==='packing'): ?><?= htmlspecialchars((string)(($line['kit_name_snapshot']??'')?:'Quotation kit'),ENT_QUOTES) ?><?php else: ?><span class="pill warn">Not part of quotation</span><?php endif; ?>
</td>
<td><select name="line_component_id[]" class="component-select" <?= $editable?'':'disabled' ?>><option value="">Select</option><?php foreach($componentMap as $cmpId=>$cmp): ?><option value="<?= htmlspecialchars($cmpId,ENT_QUOTES) ?>" <?= $cmpId===$cid?'selected':'' ?>><?= htmlspecialchars((string)($cmp['name']??$cmpId),ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
<td><select name="line_variant_id[]" class="variant-select" <?= $editable?'':'disabled' ?>><option value="">N/A</option><?php foreach((array)($variantsByComponent[$cid]??[]) as $v): ?><option value="<?= htmlspecialchars((string)($v['id']??''),ENT_QUOTES) ?>" <?= ((string)($v['id']??''))===$vid?'selected':'' ?>><?= htmlspecialchars((string)($v['name']??''),ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
<td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="<?= htmlspecialchars((string)((float)($line['qty']??0)),ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td>
<td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="<?= htmlspecialchars((string)((float)($line['length_ft']??0)),ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td>
<td><input type="number" step="1" min="0" name="line_pieces[]" value="<?= htmlspecialchars((string)((int)($line['pieces']??0)),ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td>
<td><input name="line_notes[]" value="<?= htmlspecialchars((string)($line['notes']??''),ENT_QUOTES) ?>" <?= $editable?'':'disabled' ?>></td>
<td><?php if($editable): ?><button type="button" class="btn warn remove-line">×</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if ($editable): ?><p style="margin-top:8px"><button type="button" id="add-extra-line" class="btn secondary">+ Add Extra Item (Not part of quotation)</button></p><?php endif; ?>

<div class="row-actions" style="margin-top:12px"><?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button><button class="btn" type="submit" name="action" value="finalize">Finalize DC</button><?php endif; ?><?php if ((string) ($challan['status'] ?? '') !== 'archived'): ?><button class="btn warn" type="submit" name="action" value="archive">Archive</button><?php endif; ?></div>
</form>
</main>
<script>
const COMPONENTS = <?= json_encode($componentClientMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const VARIANTS = <?= json_encode($variantsByComponent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const editable = <?= $editable ? 'true' : 'false' ?>;
const tbody = document.querySelector('#dc-lines tbody');

function wireRow(tr) {
  const componentSelect = tr.querySelector('.component-select');
  const variantSelect = tr.querySelector('.variant-select');
  const qtyInput = tr.querySelector('.qty-input');
  const lengthInput = tr.querySelector('.length-input');
  const refresh = () => {
    const cid = componentSelect.value;
    const c = COMPONENTS[cid] || null;
    const hasVariants = !!(c && c.has_variants);
    const isCuttable = !!(c && c.is_cuttable);
    variantSelect.innerHTML = '<option value="">N/A</option>';
    if (hasVariants) {
      (VARIANTS[cid] || []).forEach(v => {
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = v.name;
        variantSelect.appendChild(opt);
      });
      variantSelect.disabled = false;
    } else {
      variantSelect.value = '';
      variantSelect.disabled = true;
    }
    qtyInput.disabled = isCuttable;
    lengthInput.disabled = !isCuttable;
  };
  componentSelect?.addEventListener('change', refresh);
  tr.querySelector('.remove-line')?.addEventListener('click', () => tr.remove());
  refresh();
}

if (editable) {
  document.querySelectorAll('.dc-line-row').forEach(wireRow);
  const rowTemplate = () => {
    const tr = document.createElement('tr');
    tr.className = 'dc-line-row line-extra';
    tr.innerHTML = `<td><input type="hidden" name="line_id[]" value="line_${Math.random().toString(16).slice(2)}"><input type="hidden" name="line_source_type[]" value="extra" class="line-source-type"><input type="hidden" name="line_required_item_id[]" value=""><input type="hidden" name="line_kit_id[]" value=""><input type="hidden" name="line_kit_name_snapshot[]" value=""><span class="pill warn">Not part of quotation</span></td><td><select name="line_component_id[]" class="component-select"><option value="">Select</option>${Object.values(COMPONENTS).map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}</select></td><td><select name="line_variant_id[]" class="variant-select"><option value="">N/A</option></select></td><td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="0"></td><td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="0"></td><td><input type="number" step="1" min="0" name="line_pieces[]" value="0"></td><td><input name="line_notes[]" value=""></td><td><button type="button" class="btn warn remove-line">×</button></td>`;
    return tr;
  };
  document.getElementById('add-extra-line')?.addEventListener('click', () => {
    const tr = rowTemplate();
    tbody.appendChild(tr);
    wireRow(tr);
  });

  document.querySelectorAll('.add-packing-line').forEach(btn => {
    btn.addEventListener('click', () => {
      const req = JSON.parse(btn.dataset.required || '{}');
      const tr = rowTemplate();
      tr.classList.remove('line-extra');
      tr.querySelector('.line-source-type').value = 'packing';
      tr.querySelector('input[name="line_required_item_id[]"]').value = req.required_item_id || req.line_id || '';
      tr.querySelector('input[name="line_kit_id[]"]').value = req.kit_id || '';
      tr.querySelector('input[name="line_kit_name_snapshot[]"]').value = req.kit_name_snapshot || '';
      tr.children[0].innerHTML = tr.children[0].innerHTML.replace('<span class="pill warn">Not part of quotation</span>', (req.kit_name_snapshot || 'Quotation kit'));
      tbody.appendChild(tr);
      const cmp = tr.querySelector('.component-select');
      cmp.value = req.component_id || '';
      wireRow(tr);
      cmp.dispatchEvent(new Event('change'));
      const remainingQty = Math.max(0, parseFloat(req.pending_qty || 0));
      const remainingFt = Math.max(0, parseFloat(req.pending_ft || 0));
      if ((req.unit || '').toLowerCase() === 'ft' || req.is_cuttable) {
        tr.querySelector('.length-input').value = remainingFt > 0 ? remainingFt : 0;
      } else {
        tr.querySelector('.qty-input').value = remainingQty > 0 ? remainingQty : 0;
      }
    });
  });
}
</script>
</body></html>
