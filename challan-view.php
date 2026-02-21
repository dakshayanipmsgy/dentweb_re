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

$quote = documents_get_quote((string) ($challan['quote_id'] ?: $challan['linked_quote_id']));
$packingList = null;
$packingListId = safe_text((string) ($challan['packing_list_id'] ?? ''));
if ($packingListId !== '') {
    foreach (documents_packing_lists(true) as $pack) {
        if ((string) ($pack['id'] ?? '') === $packingListId) {
            $packingList = $pack;
            break;
        }
    }
}
if ($packingList === null && $quote !== null) {
    $packingList = documents_get_packing_list_for_quote((string) ($quote['id'] ?? ''), false);
    if ($packingList !== null) {
        $challan['packing_list_id'] = (string) ($packingList['id'] ?? '');
    }
}

$components = documents_inventory_components(false);
$componentMap = [];
$componentClientMap = [];
foreach ($components as $component) {
    if (!is_array($component)) { continue; }
    $componentId = (string) ($component['id'] ?? '');
    if ($componentId === '') { continue; }
    $componentMap[$componentId] = $component;
    $componentClientMap[$componentId] = [
        'id' => $componentId,
        'name' => (string) ($component['name'] ?? $componentId),
        'description' => (string) ($component['description'] ?? ''),
        'unit' => (string) ($component['unit'] ?? 'Nos'),
        'hsn' => (string) ($component['hsn'] ?? ''),
        'is_cuttable' => !empty($component['is_cuttable']),
        'has_variants' => !empty($component['has_variants']),
    ];
}

$variantMap = [];
$variantsByComponent = [];
$variantStockById = [];
$stockSnapshot = documents_inventory_load_stock();
foreach (documents_inventory_component_variants(true) as $variant) {
    if (!is_array($variant)) { continue; }
    $variantId = (string) ($variant['id'] ?? '');
    $componentId = (string) ($variant['component_id'] ?? '');
    if ($variantId === '' || $componentId === '') { continue; }
    $variantMap[$variantId] = $variant;
    $isCuttable = !empty($componentMap[$componentId]['is_cuttable']);
    $varStock = documents_inventory_compute_on_hand($stockSnapshot, $componentId, $variantId, $isCuttable);
    $variantsByComponent[$componentId][] = [
        'id' => $variantId,
        'name' => (string) ($variant['display_name'] ?? $variantId),
        'stock' => $varStock,
        'wattage_wp' => (float) ($variant['wattage_wp'] ?? 0),
        'archived' => !empty($variant['archived']),
    ];
    $variantStockById[$variantId] = $varStock;
}

$locationsMap = [];
foreach (documents_inventory_locations(true) as $locationRow) {
    if (!is_array($locationRow)) {
        continue;
    }
    $locId = (string) ($locationRow['id'] ?? '');
    if ($locId === '') {
        continue;
    }
    $locationsMap[$locId] = (string) ($locationRow['name'] ?? $locId);
}

$cuttableStockByComponentVariant = [];
foreach ($componentMap as $componentId => $component) {
    if (empty($component['is_cuttable'])) {
        continue;
    }
    $variantEntries = $variantsByComponent[$componentId] ?? [['id' => '']];
    foreach ($variantEntries as $variantEntry) {
        $variantId = (string) ($variantEntry['id'] ?? '');
        $entry = documents_inventory_component_stock($stockSnapshot, $componentId, $variantId);
        $lotsOut = [];
        $totalFt = 0.0;
        foreach ((array) ($entry['lots'] ?? []) as $lot) {
            if (!is_array($lot)) {
                continue;
            }
            $lotId = safe_text((string) ($lot['lot_id'] ?? ''));
            $remainingFt = max(0, (float) ($lot['remaining_length_ft'] ?? 0));
            if ($lotId === '' || $remainingFt <= 0) {
                continue;
            }
            $locationId = safe_text((string) ($lot['location_id'] ?? ''));
            $lotsOut[] = [
                'lot_id' => $lotId,
                'remaining_length_ft' => $remainingFt,
                'location_id' => $locationId,
                'location_name' => $locationsMap[$locationId] ?? $locationId,
            ];
            $totalFt += $remainingFt;
        }
        $cuttableStockByComponentVariant[$componentId . '|' . $variantId] = [
            'lots' => $lotsOut,
            'total_remaining_ft' => $totalFt,
        ];
    }
}

$packByLineId = [];
$quotationGroups = [];
if (is_array($packingList)) {
    foreach ((array) ($packingList['required_items'] ?? []) as $requiredLine) {
        if (!is_array($requiredLine)) { continue; }
        $requiredLine = array_merge(documents_packing_required_line_defaults(), $requiredLine);
        $lineId = (string) ($requiredLine['line_id'] ?? '');
        if ($lineId !== '') { $packByLineId[$lineId] = $requiredLine; }

        $componentId = (string) ($requiredLine['component_id'] ?? '');
        if ($componentId === '') { continue; }
        $mode = (string) ($requiredLine['mode'] ?? 'fixed_qty');
        $pendingQty = max(0, (float) ($requiredLine['pending_qty'] ?? 0));
        $pendingFt = max(0, (float) ($requiredLine['pending_ft'] ?? 0));
        $pendingWp = max(0, (float) ($requiredLine['target_wp'] ?? 0) - (float) ($requiredLine['dispatched_wp'] ?? 0));
        $fulfilled = in_array($mode, ['fixed_qty', 'capacity_qty'], true)
            ? ($pendingQty <= 0.00001 && $pendingFt <= 0.00001)
            : ((bool) ($requiredLine['fulfilled_flag'] ?? false));

        $groupKey = safe_text((string) ($requiredLine['source_kit_id'] ?? ''));
        $groupName = safe_text((string) ($requiredLine['source_kit_name_snapshot'] ?? ''));
        if ($groupKey === '') {
            $groupKey = 'direct_components';
            $groupName = '📦 Components (direct in quotation)';
        } else {
            $groupName = '🧩 Kit: ' . ($groupName ?: $groupKey);
        }

        if (!isset($quotationGroups[$groupKey])) {
            $quotationGroups[$groupKey] = ['name' => $groupName, 'items' => []];
        }

        $component = $componentMap[$componentId] ?? [];
        $isCuttable = !empty($component['is_cuttable']);
        $onHand = documents_inventory_compute_on_hand($stockSnapshot, $componentId, '', $isCuttable);
        $status = $fulfilled ? 'Fulfilled' : (($onHand > 0) ? 'Ready (in stock)' : 'Low/0 stock');
        $pendingText = $mode === 'rule_fulfillment'
            ? ('Pending ' . round($pendingWp, 2) . ' Wp')
            : ($isCuttable ? ('Pending ' . round($pendingFt, 2) . ' ft') : ('Pending ' . round($pendingQty, 2)));

        $quotationGroups[$groupKey]['items'][] = [
            'packing_line_id' => (string) ($requiredLine['line_id'] ?? ''),
            'component_id' => $componentId,
            'component_name' => (string) ($requiredLine['component_name_snapshot'] ?? ($component['name'] ?? $componentId)),
            'notes' => (string) ($requiredLine['remarks'] ?? ''),
            'mode' => $mode,
            'pending_qty' => $pendingQty,
            'pending_ft' => $pendingFt,
            'pending_wp' => $pendingWp,
            'fulfilled' => $fulfilled,
            'status' => $status,
            'on_hand' => $onHand,
            'has_variants' => !empty($component['has_variants']),
            'is_cuttable' => $isCuttable,
            'pending_text' => $pendingText,
            'hint' => $mode === 'rule_fulfillment' ? 'Select variants to reach Wp.' : '',
        ];
    }
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
            $componentIds = is_array($_POST['line_component_id'] ?? null) ? $_POST['line_component_id'] : [];
            $variantIds = is_array($_POST['line_variant_id'] ?? null) ? $_POST['line_variant_id'] : [];
            $qtys = is_array($_POST['line_qty'] ?? null) ? $_POST['line_qty'] : [];
            $lengths = is_array($_POST['line_length_ft'] ?? null) ? $_POST['line_length_ft'] : [];
            $pieces = is_array($_POST['line_pieces'] ?? null) ? $_POST['line_pieces'] : [];
            $notes = is_array($_POST['line_notes'] ?? null) ? $_POST['line_notes'] : [];
            $origins = is_array($_POST['line_origin'] ?? null) ? $_POST['line_origin'] : [];
            $packingLineIds = is_array($_POST['line_packing_line_id'] ?? null) ? $_POST['line_packing_line_id'] : [];
            $lineSelectedLotIds = is_array($_POST['line_selected_lot_ids'] ?? null) ? $_POST['line_selected_lot_ids'] : [];
            $lineLotCuts = is_array($_POST['line_lot_cuts'] ?? null) ? $_POST['line_lot_cuts'] : [];

            $lines = [];
            foreach ($componentIds as $idx => $componentIdRaw) {
                $componentId = safe_text((string) $componentIdRaw);
                $lineId = safe_text((string) ($lineIds[$idx] ?? '')) ?: ('line_' . bin2hex(random_bytes(4)));
                $origin = strtolower(safe_text((string) ($origins[$idx] ?? 'extra')));
                $origin = $origin === 'quotation' ? 'quotation' : 'extra';
                if ($componentId === '') {
                    continue;
                }
                $component = $componentMap[$componentId] ?? null;
                if (!is_array($component)) {
                    $redirectWith('error', 'Invalid component selected.');
                }
                $hasVariants = !empty($component['has_variants']);
                $variantId = safe_text((string) ($variantIds[$idx] ?? ''));
                $lineErrors = [];
                if ($hasVariants) {
                    if ($variantId === '') {
                        $lineErrors[] = 'Variant is required for this component.';
                    } else {
                        $variant = $variantMap[$variantId] ?? null;
                        if (!is_array($variant) || (string) ($variant['component_id'] ?? '') !== $componentId) {
                            $lineErrors[] = 'Variant must belong to selected component.';
                        }
                    }
                }

                $isCuttable = !empty($component['is_cuttable']);
                $qty = max(0, (float) ($qtys[$idx] ?? 0));
                $lengthFt = max(0, (float) ($lengths[$idx] ?? 0));
                $pieceCount = max(0, (int) ($pieces[$idx] ?? 0));
                if ($isCuttable) {
                    if ($lengthFt <= 0) {
                        $lineErrors[] = 'Cut length (ft) is required for cuttable items.';
                    }
                    if ($pieceCount <= 0) {
                        $lineErrors[] = 'Pieces are required for cuttable items.';
                    }
                } elseif ($qty <= 0) {
                    $lineErrors[] = 'Quantity is required.';
                }

                $selectedLotIdsRaw = $lineSelectedLotIds[$idx] ?? [];
                if (!is_array($selectedLotIdsRaw)) {
                    $decodedSelected = json_decode((string) $selectedLotIdsRaw, true);
                    $selectedLotIdsRaw = is_array($decodedSelected) ? $decodedSelected : (preg_split('/,/', (string) $selectedLotIdsRaw) ?: []);
                }
                $selectedLotIds = array_values(array_filter(array_map(static fn($lotId): string => safe_text((string) $lotId), $selectedLotIdsRaw), static fn(string $lotId): bool => $lotId !== ''));
                $lotCuts = [];
                $lineLotCutsRaw = $lineLotCuts[$idx] ?? [];
                if (!is_array($lineLotCutsRaw)) {
                    $decodedCuts = json_decode((string) $lineLotCutsRaw, true);
                    $lineLotCutsRaw = is_array($decodedCuts) ? $decodedCuts : [];
                }
                foreach ($lineLotCutsRaw as $cut) {
                    if (!is_array($cut)) {
                        continue;
                    }
                    $lotId = safe_text((string) ($cut['lot_id'] ?? ''));
                    $cutCount = max(0, (int) ($cut['count'] ?? 0));
                    $cutLength = max(0, (float) ($cut['cut_length_ft'] ?? 0));
                    if ($lotId === '' || $cutCount <= 0 || $cutLength <= 0) {
                        continue;
                    }
                    $lotCuts[] = ['lot_id' => $lotId, 'count' => $cutCount, 'cut_length_ft' => $cutLength];
                }

                $variantName = $variantId !== '' ? (string) (($variantMap[$variantId]['display_name'] ?? $variantId)) : '';

                $lines[] = [
                    'line_id' => $lineId,
                    'line_origin' => $origin,
                    'packing_line_id' => safe_text((string) ($packingLineIds[$idx] ?? '')),
                    'component_id' => $componentId,
                    'component_name_snapshot' => (string) ($component['name'] ?? $componentId),
                    'has_variants_snapshot' => $hasVariants,
                    'variant_id' => $variantId,
                    'variant_name_snapshot' => $variantName,
                    'is_cuttable_snapshot' => $isCuttable,
                    'qty' => $isCuttable ? 0 : $qty,
                    'length_ft' => $isCuttable ? $lengthFt : 0,
                    'pieces' => $pieceCount,
                    'selected_lot_ids' => $selectedLotIds,
                    'lot_ids' => $selectedLotIds,
                    'lot_cuts' => $lotCuts,
                    'line_errors' => $lineErrors,
                    'unit_snapshot' => (string) ($component['unit'] ?? ($isCuttable ? 'ft' : 'Nos')),
                    'hsn_snapshot' => (string) ($component['hsn'] ?? ''),
                    'notes' => safe_text((string) ($notes[$idx] ?? '')),
                ];
            }

            $challan['lines'] = documents_normalize_challan_lines($lines);
            if ($challan['lines'] === []) {
                $redirectWith('error', 'Add at least one line.');
            }
            $lineErrorsFound = false;
            foreach ($challan['lines'] as $line) {
                if (!empty($line['line_errors'])) {
                    $lineErrorsFound = true;
                    break;
                }
            }
            if ($action === 'finalize' && $lineErrorsFound) {
                $redirectWith('error', 'Resolve line errors before finalizing. Save draft to keep your edits.');
            }

            $challan['items'] = array_map(static function (array $line): array {
                $name = (string) ($line['component_name_snapshot'] ?? '');
                if ((string) ($line['variant_name_snapshot'] ?? '') !== '') {
                    $name .= ' - ' . (string) ($line['variant_name_snapshot'] ?? '');
                }
                return [
                    'name' => $name,
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
                if (!is_array($line)) { continue; }
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
                    'allow_negative' => true,
                ];

                if (!empty($line['is_cuttable_snapshot'])) {
                    $needFt = max(0, (float) ($line['length_ft'] ?? 0));
                    if ($needFt <= 0) { continue; }
                    $availableFt = documents_inventory_total_remaining_ft($entry);
                    $plannedCuts = is_array($line['lot_cuts'] ?? null) ? $line['lot_cuts'] : [];
                    $consume = ['lots' => (array) ($entry['lots'] ?? []), 'lot_consumption' => []];
                    if ($plannedCuts !== []) {
                        $planned = documents_inventory_consume_planned_lot_cuts((array) ($entry['lots'] ?? []), $plannedCuts);
                        $consume = [
                            'lots' => (array) ($planned['lots'] ?? []),
                            'lot_consumption' => (array) ($planned['lot_consumption'] ?? []),
                        ];
                        $plannedUsedFt = 0.0;
                        foreach ((array) ($consume['lot_consumption'] ?? []) as $consumeLine) {
                            if (!is_array($consumeLine)) {
                                continue;
                            }
                            $plannedUsedFt += max(0, (float) ($consumeLine['used_ft'] ?? 0));
                        }
                        if ($plannedUsedFt + 0.00001 < $needFt) {
                            $remainingNeed = $needFt - $plannedUsedFt;
                            $fifoTopup = documents_inventory_consume_fifo_lots((array) ($consume['lots'] ?? []), min($remainingNeed, max(0, $availableFt - $plannedUsedFt)));
                            $consume['lots'] = (array) ($fifoTopup['lots'] ?? $consume['lots']);
                            $consume['lot_consumption'] = array_merge((array) ($consume['lot_consumption'] ?? []), (array) ($fifoTopup['lot_consumption'] ?? []));
                        }
                    } else {
                        $consume = documents_inventory_consume_fifo_lots((array) ($entry['lots'] ?? []), min($needFt, $availableFt));
                    }
                    $entry['lots'] = (array) ($consume['lots'] ?? []);
                    $consumedFt = 0.0;
                    foreach ((array) ($consume['lot_consumption'] ?? []) as $consumeLine) {
                        if (!is_array($consumeLine)) {
                            continue;
                        }
                        $consumedFt += max(0, (float) ($consumeLine['used_ft'] ?? 0));
                    }
                    $shortFt = max(0, $needFt - $consumedFt);
                    if ($shortFt > 0.00001) {
                        $entry['lots'][] = [
                            'lot_id' => 'NEG-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)),
                            'remaining_length_ft' => -round($shortFt, 4),
                            'location_id' => '',
                            'note' => 'Negative stock via DC finalize',
                        ];
                    }
                    $tx['length_ft'] = $needFt;
                    $tx['lot_consumption'] = (array) ($consume['lot_consumption'] ?? []);
                } else {
                    $needQty = max(0, (float) ($line['qty'] ?? 0));
                    if ($needQty <= 0) { continue; }
                    $availableQty = max(0, (float) ($entry['on_hand_qty'] ?? 0));
                    $consumeQty = min($needQty, $availableQty);
                    $consumed = $consumeQty > 0 ? documents_inventory_consume_from_location_breakdown($entry, $consumeQty, '') : ['ok' => true, 'entry' => $entry, 'location_consumption' => []];
                    if (!($consumed['ok'] ?? false)) {
                        $consumed = ['ok' => true, 'entry' => $entry, 'location_consumption' => []];
                    }
                    $entry = (array) ($consumed['entry'] ?? $entry);
                    $entry['on_hand_qty'] = ((float) ($entry['on_hand_qty'] ?? 0)) - ($needQty - $consumeQty);
                    $tx['qty'] = $needQty;
                    $tx['location_consumption'] = (array) ($consumed['location_consumption'] ?? []);
                }

                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
                documents_inventory_append_transaction($tx);
                $txnIds[] = $txId;

                if ((string) ($line['line_origin'] ?? 'extra') === 'quotation') {
                    $packingLineId = (string) ($line['packing_line_id'] ?? '');
                    $packingRef = $packingLineId !== '' ? ($packByLineId[$packingLineId] ?? null) : null;
                    $dispatchWp = 0.0;
                    if (is_array($packingRef) && (string) ($packingRef['mode'] ?? '') === 'rule_fulfillment') {
                        if ($variantId === '') {
                            $redirectWith('error', 'Variant is required for rule-based quotation item dispatch.');
                        }
                        $dispatchWp = ((float) ($variantMap[$variantId]['wattage_wp'] ?? 0)) * (float) ($line['qty'] ?? 0);
                    }
                    $dispatchRows[] = [
                        'line_id' => $packingLineId,
                        'component_id' => $componentId,
                        'dispatch_qty' => (float) ($line['qty'] ?? 0),
                        'dispatch_ft' => (float) ($line['length_ft'] ?? 0),
                        'dispatch_wp' => $dispatchWp,
                        'variant_id' => $variantId,
                        'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                        'wattage_wp' => (float) ($variantMap[$variantId]['wattage_wp'] ?? 0),
                    ];
                }
            }

            $savedStock = documents_inventory_save_stock($stock);
            if (!($savedStock['ok'] ?? false)) {
                $redirectWith('error', 'Failed to update inventory stock.');
            }

            if (is_array($packingList) && $dispatchRows !== []) {
                $updatedPack = documents_apply_dispatch_to_packing_list($packingList, (string) ($challan['id'] ?? ''), $dispatchRows);
                $savedPack = documents_save_packing_list($updatedPack);
                if (!($savedPack['ok'] ?? false)) {
                    $redirectWith('error', 'Inventory updated, but packing list update failed.');
                }
            }

            if ($viewerType === 'employee') {
                $transactions = documents_inventory_load_transactions();
                documents_inventory_sync_verification_log($transactions, true);
            }
            $challan['inventory_txn_ids'] = $txnIds;
            $challan['status'] = 'final';
        }

        if ($action === 'save_draft') {
            $challan['status'] = 'draft';
        }
        if ($action === 'archive') {
            $challanStatus = (string) ($challan['status'] ?? 'draft');
            if ($challanStatus === 'final') {
                $stock = documents_inventory_load_stock();
                $allTransactions = documents_inventory_load_transactions();
                $transactionsById = [];
                foreach ($allTransactions as $txIndex => $txRow) {
                    if (!is_array($txRow)) {
                        continue;
                    }
                    $txMerged = array_merge(documents_inventory_transaction_defaults(), $txRow);
                    $transactionsById[(string) ($txMerged['id'] ?? '')] = ['idx' => $txIndex, 'tx' => $txMerged];
                }

                $reversalTxnIds = [];
                $sourceTxnIds = array_values(array_filter(array_map(static fn($id): string => safe_text((string) $id), (array) ($challan['inventory_txn_ids'] ?? [])), static fn(string $id): bool => $id !== ''));
                foreach ($sourceTxnIds as $sourceTxnId) {
                    if (!isset($transactionsById[$sourceTxnId])) {
                        continue;
                    }
                    $source = $transactionsById[$sourceTxnId]['tx'];
                    if (!empty($source['archived_flag']) || !empty($source['voided_flag'])) {
                        continue;
                    }
                    if (strtoupper((string) ($source['type'] ?? '')) !== 'OUT') {
                        continue;
                    }

                    $componentId = (string) ($source['component_id'] ?? '');
                    $variantId = (string) ($source['variant_id'] ?? '');
                    if ($componentId === '') {
                        continue;
                    }

                    $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
                    $lengthFt = max(0, (float) ($source['length_ft'] ?? 0));
                    $qty = max(0, (float) ($source['qty'] ?? 0));
                    if ($lengthFt > 0) {
                        $lotConsumption = (array) ($source['lot_consumption'] ?? []);
                        foreach ($lotConsumption as $consume) {
                            if (!is_array($consume)) {
                                continue;
                            }
                            $lotId = (string) ($consume['lot_id'] ?? '');
                            $usedFt = max(0, (float) ($consume['used_ft'] ?? 0));
                            if ($lotId === '' || $usedFt <= 0) {
                                continue;
                            }
                            $matched = false;
                            foreach ($entry['lots'] as $lotIdx => $lot) {
                                if (!is_array($lot) || (string) ($lot['lot_id'] ?? '') !== $lotId) {
                                    continue;
                                }
                                $currentRemaining = (float) ($lot['remaining_length_ft'] ?? 0);
                                $entry['lots'][$lotIdx]['remaining_length_ft'] = round($currentRemaining + $usedFt, 4);
                                $matched = true;
                                break;
                            }
                            if (!$matched) {
                                $entry['lots'][] = [
                                    'lot_id' => $lotId,
                                    'original_length_ft' => $usedFt,
                                    'remaining_length_ft' => $usedFt,
                                    'location_id' => (string) ($source['consume_location_id'] ?? $source['location_id'] ?? ''),
                                    'created_at' => date('c'),
                                    'created_by' => ['role' => 'system', 'id' => '', 'name' => 'DC archive reversal'],
                                    'notes' => 'Recreated during DC archive reversal for txn ' . $sourceTxnId,
                                ];
                            }
                        }
                    } elseif ($qty > 0) {
                        $locationRows = is_array($source['location_consumption'] ?? null) ? $source['location_consumption'] : [];
                        if ($locationRows !== []) {
                            foreach ($locationRows as $locRow) {
                                if (!is_array($locRow)) {
                                    continue;
                                }
                                $consumeQty = max(0, (float) ($locRow['qty'] ?? 0));
                                $locationId = safe_text((string) ($locRow['location_id'] ?? ''));
                                if ($consumeQty <= 0) {
                                    continue;
                                }
                                $entry = documents_inventory_add_to_location_breakdown($entry, $consumeQty, $locationId);
                                $entry['batches'][] = documents_inventory_create_batch($locationId, $consumeQty, ['role' => 'system', 'id' => '', 'name' => 'DC archive reversal'], $sourceTxnId);
                            }
                        } else {
                            $entry = documents_inventory_add_to_location_breakdown($entry, $qty, (string) ($source['consume_location_id'] ?? ''));
                            $entry['batches'][] = documents_inventory_create_batch((string) ($source['consume_location_id'] ?? ''), $qty, ['role' => 'system', 'id' => '', 'name' => 'DC archive reversal'], $sourceTxnId);
                        }
                    }
                    $entry['updated_at'] = date('c');
                    documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);

                    $reversalTxnId = 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
                    $reversalTx = array_merge(documents_inventory_transaction_defaults(), [
                        'id' => $reversalTxnId,
                        'type' => 'REVERSAL_IN',
                        'component_id' => $componentId,
                        'variant_id' => $variantId,
                        'variant_name_snapshot' => (string) ($source['variant_name_snapshot'] ?? ''),
                        'qty' => $qty,
                        'length_ft' => $lengthFt,
                        'lot_consumption' => (array) ($source['lot_consumption'] ?? []),
                        'batch_consumption' => (array) ($source['batch_consumption'] ?? []),
                        'batch_ids' => (array) ($source['batch_ids'] ?? []),
                        'location_consumption' => (array) ($source['location_consumption'] ?? []),
                        'unit' => (string) ($source['unit'] ?? ''),
                        'ref_type' => 'delivery_challan_archive_reversal',
                        'ref_id' => (string) ($challan['id'] ?? ''),
                        'reverses_txn_id' => $sourceTxnId,
                        'notes' => 'Reversal generated while archiving DC ' . (string) ($challan['challan_no'] ?? $challan['id'] ?? ''),
                        'created_at' => date('c'),
                        'created_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                    ]);
                    $allTransactions[] = $reversalTx;
                    $reversalTxnIds[] = $reversalTxnId;

                    $sourceIdx = (int) ($transactionsById[$sourceTxnId]['idx'] ?? -1);
                    if ($sourceIdx >= 0 && isset($allTransactions[$sourceIdx]) && is_array($allTransactions[$sourceIdx])) {
                        $allTransactions[$sourceIdx] = array_merge(documents_inventory_transaction_defaults(), (array) $allTransactions[$sourceIdx], [
                            'archived_flag' => true,
                            'voided_flag' => true,
                            'archived_at' => date('c'),
                            'voided_at' => date('c'),
                            'archived_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                            'voided_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                            'reversed_by_txn_id' => $reversalTxnId,
                        ]);
                    }
                }

                $savedStock = documents_inventory_save_stock($stock);
                if (!($savedStock['ok'] ?? false)) {
                    $redirectWith('error', 'Failed to reverse inventory while archiving DC.');
                }
                $savedTx = documents_inventory_save_transactions($allTransactions);
                if (!($savedTx['ok'] ?? false)) {
                    $redirectWith('error', 'Inventory stock was reversed, but transaction history update failed.');
                }

                $stock = documents_inventory_cleanup_dc_usage_locks(
                    $stock,
                    $allTransactions,
                    (string) ($challan['id'] ?? ''),
                    $sourceTxnIds
                );
                $savedStockCleanup = documents_inventory_save_stock($stock);
                if (!($savedStockCleanup['ok'] ?? false)) {
                    $redirectWith('error', 'Inventory rollback completed, but failed to clear DC usage locks.');
                }

                if (is_array($packingList)) {
                    $updatedPack = documents_reverse_dispatch_from_packing_list($packingList, (string) ($challan['id'] ?? ''));
                    $savedPack = documents_save_packing_list($updatedPack);
                    if (!($savedPack['ok'] ?? false)) {
                        $redirectWith('error', 'Inventory reversed, but packing list rollback failed.');
                    }
                }
                $challan['reversal_txn_ids'] = $reversalTxnIds;
            }
            $challan['status'] = 'archived';
            $challan['archived_flag'] = true;
            $challan['archived_at'] = date('c');
            $challan['archived_by'] = ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName];
        }

        $challan['updated_at'] = date('c');
        $saved = documents_save_challan($challan);
        if (!$saved['ok']) {
            documents_log('Challan update failed for ' . (string) ($challan['id'] ?? '') . ': ' . (string) ($saved['error'] ?? 'Unknown error'));
            $redirectWith('error', 'Unable to save delivery challan.');
        }
        $redirectWith('success', $action === 'finalize' ? 'DC finalized and inventory updated.' : ($action === 'save_draft' ? 'DC draft saved.' : 'DC archived.'));
    }
}

$backLink = (is_array($user) && (($user['role_name'] ?? '') === 'admin')) ? 'admin-documents.php?tab=accepted_customers&view=' . urlencode((string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? '')) : 'employee-challans.php';
$statusParam = safe_text($_GET['status'] ?? '');
$messageParam = safe_text($_GET['message'] ?? '');
$editable = (string) ($challan['status'] ?? 'draft') === 'draft';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Delivery Challan Builder</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111;margin:0}.wrap{max-width:1300px;margin:20px auto;padding:0 14px}.card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}.btn{display:inline-block;background:#0b57d0;color:#fff;border:1px solid #0b57d0;padding:7px 10px;border-radius:8px;text-decoration:none;cursor:pointer}.btn.secondary{background:#fff;color:#0b57d0}.btn.warn{background:#b91c1c;border-color:#b91c1c}.muted{color:#666}.row-actions{display:flex;gap:6px;align-items:center}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}input,select,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px}.tree-item{border:1px solid #e2e8f0;border-radius:10px;padding:8px;margin:6px 0}.tree-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.small{font-size:12px}.pill{padding:2px 8px;border-radius:999px;font-size:11px}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.done{background:#e2e8f0;color:#334155}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ee;padding:8px;vertical-align:top}thead th{background:#f1f5f9}.mono{font-family:ui-monospace,monospace}
</style></head>
<body><main class="wrap">
<div class="card"><h1 style="margin:0 0 8px">Delivery Challan</h1>
<p><strong><?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></strong> · Status: <?= htmlspecialchars(strtoupper((string) ($challan['status'] ?? 'draft')), ENT_QUOTES) ?></p>
<div class="row-actions"><a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a><a class="btn secondary" href="challan-print.php?id=<?= urlencode((string) ($challan['id'] ?? '')) ?>" target="_blank" rel="noopener">View as HTML</a></div>
</div>
<?php if ($statusParam !== '' && $messageParam !== ''): ?><div class="card"><strong><?= htmlspecialchars(strtoupper($statusParam), ENT_QUOTES) ?>:</strong> <?= htmlspecialchars($messageParam, ENT_QUOTES) ?></div><?php endif; ?>

<form method="post" class="card">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
<div class="grid">
<div><label>Delivery Date</label><input type="date" name="delivery_date" value="<?= htmlspecialchars((string) ($challan['delivery_date'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div>
<div><label>Site Address</label><input name="site_address" value="<?= htmlspecialchars((string) ($challan['site_address'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div>
<div><label>Delivery Address</label><input name="delivery_address" value="<?= htmlspecialchars((string) ($challan['delivery_address'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div>
<div><label>Vehicle No</label><input name="vehicle_no" value="<?= htmlspecialchars((string) ($challan['vehicle_no'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></div>
</div>
<div style="margin-top:8px"><label>Delivery Notes</label><textarea name="delivery_notes" <?= $editable ? '' : 'disabled' ?>><?= htmlspecialchars((string) ($challan['delivery_notes'] ?? ''), ENT_QUOTES) ?></textarea></div>

<h3>Quotation Items</h3>
<label class="small"><input type="checkbox" id="show-in-stock-only" checked <?= $editable ? '' : 'disabled' ?>> Show only in-stock items</label>
<div id="quotation-tree">
<?php foreach ($quotationGroups as $group): ?>
<details open><summary><strong><?= htmlspecialchars((string) ($group['name'] ?? 'Group'), ENT_QUOTES) ?></strong></summary>
<?php foreach ((array) ($group['items'] ?? []) as $item): $statusClass = ($item['status'] === 'Fulfilled') ? 'done' : (($item['on_hand'] > 0) ? 'ok' : 'bad'); ?>
<div class="tree-item quotation-tree-item" data-stock="<?= htmlspecialchars((string) ((float) ($item['on_hand'] ?? 0)), ENT_QUOTES) ?>">
<div class="tree-head"><div>
<div><strong><?= htmlspecialchars((string) ($item['component_name'] ?? ''), ENT_QUOTES) ?></strong></div>
<div class="small muted"><?= htmlspecialchars((string) ($item['pending_text'] ?? ''), ENT_QUOTES) ?> · Stock: <?= htmlspecialchars((string) round((float) ($item['on_hand'] ?? 0), 2), ENT_QUOTES) ?> <?= !empty($item['is_cuttable']) ? 'ft' : 'qty' ?></div>
<?php if ((string) ($item['hint'] ?? '') !== ''): ?><div class="small muted"><?= htmlspecialchars((string) ($item['hint'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
</div>
<div class="row-actions"><span class="pill <?= $statusClass ?>"><?= htmlspecialchars((string) ($item['status'] ?? ''), ENT_QUOTES) ?></span><?php if ($editable): ?><button type="button" class="btn secondary add-quotation-line" data-payload='<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>+ Add to DC</button><?php endif; ?></div>
</div></div>
<?php endforeach; ?>
</details>
<?php endforeach; ?>
<?php if ($quotationGroups === []): ?><p class="muted">No quotation packing list items available.</p><?php endif; ?>
</div>

<h3>Extra items not present in quotation</h3>
<?php if ($editable): ?><p><button type="button" id="add-extra-line" class="btn secondary">+ Add Line</button></p><?php endif; ?>

<table id="dc-lines"><thead><tr><th style="width:5%">Sr.</th><th style="width:44%">Components</th><th style="width:10%">HSN</th><th style="width:16%">Quantity / pieces</th><th style="width:15%">length</th><th style="width:10%">Actions</th></tr></thead><tbody>
<?php foreach ((array) ($challan['lines'] ?? []) as $line): if (!is_array($line)) { continue; } $componentId=(string)($line['component_id']??''); $variantId=(string)($line['variant_id']??''); $isCuttable=!empty($line['is_cuttable_snapshot']); $lineStock=documents_inventory_compute_on_hand($stockSnapshot,$componentId,$variantId,$isCuttable); ?>
<tr class="dc-line-row">
<td class="sr-col"></td>
<td>
<input type="hidden" name="line_id[]" value="<?= htmlspecialchars((string) ($line['line_id'] ?? ''), ENT_QUOTES) ?>" />
<input type="hidden" name="line_origin[]" class="line-origin" value="<?= htmlspecialchars((string) ($line['line_origin'] ?? 'extra'), ENT_QUOTES) ?>" />
<input type="hidden" name="line_packing_line_id[]" class="line-packing-line-id" value="<?= htmlspecialchars((string) ($line['packing_line_id'] ?? ''), ENT_QUOTES) ?>" />
<select name="line_component_id[]" class="component-select" <?= $editable ? '' : 'disabled' ?>>
<option value="">Select</option><?php foreach ($componentClientMap as $component): ?><option value="<?= htmlspecialchars((string) ($component['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($component['id'] ?? ''))===$componentId?'selected':'' ?>><?= htmlspecialchars((string) ($component['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?>
</select>
<select name="line_variant_id[]" class="variant-select" style="margin-top:6px" <?= $editable ? '' : 'disabled' ?>><option value="">N/A</option><?php foreach ((array) ($variantsByComponent[$componentId] ?? []) as $variant): ?><option value="<?= htmlspecialchars((string) ($variant['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($variant['id'] ?? ''))===$variantId?'selected':'' ?>><?= htmlspecialchars((string) ($variant['name'] ?? ''), ENT_QUOTES) ?><?= !empty($variant['archived']) ? ' (archived)' : '' ?> — Stock: <?= htmlspecialchars((string) round((float) ($variant['stock'] ?? 0), 2), ENT_QUOTES) ?></option><?php endforeach; ?></select>
<input name="line_notes[]" placeholder="Description / notes" value="<?= htmlspecialchars((string) ($line['notes'] ?? ''), ENT_QUOTES) ?>" style="margin-top:6px" <?= $editable ? '' : 'disabled' ?>>
<div class="small muted stock-hint" style="margin-top:6px">Stock: <?= htmlspecialchars((string) round($lineStock, 2), ENT_QUOTES) ?><?= $isCuttable ? ' ft' : '' ?><?= $lineStock <= 0 ? ' (will go negative)' : '' ?></div>
<div class="small" style="margin-top:6px;color:#b91c1c"><?php foreach ((array) ($line['line_errors'] ?? []) as $lineError): ?><div><?= htmlspecialchars((string) $lineError, ENT_QUOTES) ?></div><?php endforeach; ?></div>
<div class="cuttable-panel small" style="margin-top:6px"></div>
</td>
<td class="mono"><input value="<?= htmlspecialchars((string) ($line['hsn_snapshot'] ?? ''), ENT_QUOTES) ?>" class="hsn-display" readonly></td>
<td>
<input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="<?= htmlspecialchars((string) ((float) ($line['qty'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>>
<input type="number" step="1" min="0" name="line_pieces[]" class="pieces-input" value="<?= htmlspecialchars((string) ((int) ($line['pieces'] ?? 0)), ENT_QUOTES) ?>" style="margin-top:6px" <?= $editable ? '' : 'disabled' ?>>
<input type="hidden" name="line_selected_lot_ids[]" class="line-selected-lot-ids" value="<?= htmlspecialchars(json_encode((array) ($line['selected_lot_ids'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<input type="hidden" name="line_lot_cuts[]" class="line-lot-cuts-input" value="<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<div class="line-lot-cuts" data-line-id="<?= htmlspecialchars((string) ($line['line_id'] ?? ''), ENT_QUOTES) ?>" data-lot-cuts='<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'></div>
</td>
<td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="<?= htmlspecialchars((string) ((float) ($line['length_ft'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td class="row-actions"><?php if ($editable): ?><button type="button" class="btn warn remove-line">×</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<div class="row-actions" style="margin-top:12px"><?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button><button class="btn" type="submit" name="action" value="finalize">Finalize DC</button><?php endif; ?><?php if ((string) ($challan['status'] ?? '') !== 'archived'): ?><button class="btn warn" type="submit" name="action" value="archive">Archive</button><?php endif; ?></div>
</form>
</main>
<script>
const COMPONENTS = <?= json_encode($componentClientMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const VARIANTS = <?= json_encode($variantsByComponent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const CUTTABLE_STOCK = <?= json_encode($cuttableStockByComponentVariant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const editable = <?= $editable ? 'true' : 'false' ?>;

const componentOptions = `<option value="">Select</option>${Object.values(COMPONENTS).map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}`;
const refreshSr = () => document.querySelectorAll('#dc-lines tbody tr').forEach((tr, idx) => { const el=tr.querySelector('.sr-col'); if (el) el.textContent = String(idx+1); });
const stockKey = (componentId, variantId) => `${componentId || ''}|${variantId || ''}`;
const readJsonArray = (raw) => { try { const val = JSON.parse(raw || '[]'); return Array.isArray(val) ? val : []; } catch { return []; } };

const buildCuttablePlan = (lots, pieces, cutLength) => {
  const sorted = [...lots].map(l => ({...l, remaining: Number(l.remaining_length_ft || 0)})).sort((a,b) => a.remaining - b.remaining);
  const planMap = {};
  for (let i = 0; i < pieces; i += 1) {
    let best = sorted.filter(l => l.remaining + 0.00001 >= cutLength).sort((a,b) => a.remaining - b.remaining)[0] || null;
    if (!best) {
      best = [...sorted].sort((a,b) => b.remaining - a.remaining)[0] || null;
      if (!best) break;
    }
    best.remaining -= cutLength;
    const prev = planMap[best.lot_id] || { lot_id: best.lot_id, count: 0, cut_length_ft: cutLength };
    prev.count += 1;
    planMap[best.lot_id] = prev;
  }
  return Object.values(planMap);
};

const renderCuttablePanel = (tr) => {
  const panel = tr.querySelector('.cuttable-panel');
  if (!panel) return;
  const comp = tr.querySelector('.component-select');
  const variant = tr.querySelector('.variant-select');
  const pieces = Number(tr.querySelector('.pieces-input')?.value || 0);
  const cutLen = Number(tr.querySelector('.length-input')?.value || 0);
  const c = COMPONENTS[comp.value] || null;
  if (!c || !c.is_cuttable) { panel.innerHTML = ''; return; }
  const bucket = CUTTABLE_STOCK[stockKey(comp.value, c.has_variants ? variant.value : '')] || { lots: [], total_remaining_ft: 0 };
  const lots = Array.isArray(bucket.lots) ? bucket.lots : [];
  const total = Number(bucket.total_remaining_ft || 0);
  const cutsInput = tr.querySelector('.line-lot-cuts-input');
  const selectedLotsInput = tr.querySelector('.line-selected-lot-ids');
  const existingCuts = readJsonArray(cutsInput?.value || '[]');
  if (selectedLotsInput && !selectedLotsInput.value) {
    selectedLotsInput.value = JSON.stringify(existingCuts.map(cut => cut.lot_id));
  }
  panel.innerHTML = `<div><strong>Available pieces</strong> · Total: ${total.toFixed(2)} ft</div>${lots.map(l => `<div>Lot ${l.lot_id} · ${Number(l.remaining_length_ft||0).toFixed(2)} ft · ${l.location_name || '-'}</div>`).join('') || '<div class="muted">No lots available.</div>'}<button type="button" class="btn secondary suggest-cuts" style="margin-top:6px">Suggest from stock</button><div class="small suggested-cuts" style="margin-top:6px"></div>`;
  const suggestBtn = panel.querySelector('.suggest-cuts');
  const suggested = panel.querySelector('.suggested-cuts');
  const drawCuts = (cuts) => {
    if (cutsInput) cutsInput.value = JSON.stringify(cuts);
    if (selectedLotsInput) selectedLotsInput.value = JSON.stringify(cuts.map(cut => cut.lot_id));
    suggested.textContent = cuts.length ? cuts.map(cut => `${cut.lot_id}: ${cut.count} × ${Number(cut.cut_length_ft).toFixed(2)}ft`).join(' | ') : 'No lot cuts selected.';
  };
  drawCuts(existingCuts);
  suggestBtn?.addEventListener('click', () => {
    const plan = buildCuttablePlan(lots, Math.max(0, Math.floor(pieces)), Math.max(0, cutLen));
    drawCuts(plan);
  });
};

const wireRow = (tr) => {
  const comp = tr.querySelector('.component-select');
  const variant = tr.querySelector('.variant-select');
  const qty = tr.querySelector('.qty-input');
  const length = tr.querySelector('.length-input');
  const pieces = tr.querySelector('.pieces-input');
  const hsn = tr.querySelector('.hsn-display');
  const stockHint = tr.querySelector('.stock-hint');

  const refresh = () => {
    const cid = comp.value;
    const c = COMPONENTS[cid] || null;
    const isCuttable = !!(c && c.is_cuttable);
    const hasVariants = !!(c && c.has_variants);
    const currentVariant = variant.value;
    variant.innerHTML = '<option value="">N/A</option>';
    if (hasVariants) {
      (VARIANTS[cid] || []).forEach(v => {
        const o = document.createElement('option');
        o.value = v.id;
        o.textContent = `${v.name}${v.archived ? ' (archived)' : ''} — Stock: ${Number(v.stock||0).toFixed(2)}`;
        variant.appendChild(o);
      });
      variant.disabled = false;
      if (currentVariant) variant.value = currentVariant;
    } else {
      variant.value = '';
      variant.disabled = true;
    }
    qty.disabled = isCuttable;
    length.disabled = !isCuttable;
    pieces.disabled = !isCuttable;
    hsn.value = c ? (c.hsn || '') : '';
    renderCuttablePanel(tr);
  };

  const refreshStock = () => {
    const cid = comp.value;
    const c = COMPONENTS[cid] || null;
    if (!c) return;
    let stock = 0;
    if (c.has_variants && variant.value) {
      const found = (VARIANTS[cid] || []).find(v => v.id === variant.value);
      stock = Number(found?.stock || 0);
    }
    if (!c.has_variants && c.is_cuttable) {
      stock = Number((CUTTABLE_STOCK[stockKey(cid, '')] || {}).total_remaining_ft || 0);
    }
    stockHint.textContent = `Stock: ${stock.toFixed(2)}${c.is_cuttable ? ' ft' : ''}${stock <= 0 ? ' (will go negative)' : ''}`;
    renderCuttablePanel(tr);
  };

  comp.addEventListener('change', () => { refresh(); refreshStock(); });
  variant.addEventListener('change', refreshStock);
  pieces.addEventListener('input', () => renderCuttablePanel(tr));
  length.addEventListener('input', () => renderCuttablePanel(tr));
  tr.querySelector('.remove-line')?.addEventListener('click', () => { tr.remove(); refreshSr(); });
  refresh(); refreshStock(); refreshSr();
};

if (editable) {
  const tbody = document.querySelector('#dc-lines tbody');
  const createLine = () => {
    const tr = document.createElement('tr');
    tr.className = 'dc-line-row';
    tr.innerHTML = `<td class="sr-col"></td><td><input type="hidden" name="line_id[]" value="line_${Math.random().toString(16).slice(2)}"><input type="hidden" name="line_origin[]" class="line-origin" value="extra"><input type="hidden" name="line_packing_line_id[]" class="line-packing-line-id" value=""><select name="line_component_id[]" class="component-select">${componentOptions}</select><select name="line_variant_id[]" class="variant-select" style="margin-top:6px"><option value="">N/A</option></select><input name="line_notes[]" placeholder="Description / notes" style="margin-top:6px"><div class="small muted stock-hint" style="margin-top:6px"></div><div class="small" style="margin-top:6px;color:#b91c1c"></div><div class="cuttable-panel small" style="margin-top:6px"></div></td><td class="mono"><input class="hsn-display" readonly></td><td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="0"><input type="number" step="1" min="0" name="line_pieces[]" class="pieces-input" value="0" style="margin-top:6px"><input type="hidden" name="line_selected_lot_ids[]" class="line-selected-lot-ids" value="[]"><input type="hidden" name="line_lot_cuts[]" class="line-lot-cuts-input" value="[]"></td><td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="0"></td><td class="row-actions"><button type="button" class="btn warn remove-line">×</button></td>`;
    return tr;
  };

  document.querySelectorAll('.dc-line-row').forEach(wireRow);
  document.getElementById('add-extra-line')?.addEventListener('click', () => { const row=createLine(); tbody.appendChild(row); wireRow(row); });

  document.querySelectorAll('.add-quotation-line').forEach(btn => btn.addEventListener('click', () => {
    const payload = JSON.parse(btn.dataset.payload || '{}');
    const row = createLine();
    tbody.appendChild(row);
    row.querySelector('.line-origin').value = 'quotation';
    row.querySelector('.line-packing-line-id').value = payload.packing_line_id || '';
    const comp = row.querySelector('.component-select');
    comp.value = payload.component_id || '';
    wireRow(row);
    comp.dispatchEvent(new Event('change'));
    const qty = row.querySelector('.qty-input');
    const len = row.querySelector('.length-input');
    const pcs = row.querySelector('.pieces-input');
    if (payload.is_cuttable) { len.value = String(Number(payload.pending_ft || 0)); pcs.value = '1'; } else { qty.value = String(Number(payload.pending_qty || (payload.mode === 'rule_fulfillment' ? 1 : 0))); }
  }));

  document.getElementById('show-in-stock-only')?.addEventListener('change', (e) => {
    const only = e.target.checked;
    document.querySelectorAll('.quotation-tree-item').forEach(el => {
      const stock = Number(el.getAttribute('data-stock') || '0');
      el.style.display = (only && stock <= 0) ? 'none' : '';
    });
  });
} else {
  document.querySelectorAll('.dc-line-row').forEach(wireRow);
}
refreshSr();
</script>
</body></html>
