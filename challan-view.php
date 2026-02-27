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
$employeeCanAccessAdminChallans = false;
if ($viewerType === 'employee') {
    $employeeRecord = $employeeStore->findById($viewerId);
    $employeeCanAccessAdminChallans = !empty($employeeRecord['can_access_admin_created_dcs']);
    $creatorRole = (string) ($challan['created_by']['role'] ?? $challan['created_by_type'] ?? '');
    $creatorId = (string) ($challan['created_by']['id'] ?? $challan['created_by_id'] ?? '');
    $isOwnEmployeeChallan = ($creatorRole === 'employee' && $creatorId === $viewerId);
    $isAdminChallanVisible = ($creatorRole === 'admin' && $employeeCanAccessAdminChallans);
    if (!$isOwnEmployeeChallan && !$isAdminChallanVisible) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

$quote = documents_get_quote((string) ($challan['quote_id'] ?: $challan['linked_quote_id']));
$challan['lines'] = documents_migrate_challan_items_to_lines($challan);
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

$selectedComponentIds = [];
foreach ((array) ($challan['lines'] ?? []) as $existingLine) {
    if (is_array($existingLine)) {
        $existingComponentId = safe_text((string) ($existingLine['component_id'] ?? ''));
        if ($existingComponentId !== '') {
            $selectedComponentIds[$existingComponentId] = true;
        }
    }
}
$components = documents_inventory_components(true);
$componentMap = [];
$componentClientMap = [];
foreach ($components as $component) {
    if (!is_array($component)) { continue; }
    $componentId = (string) ($component['id'] ?? '');
    if ($componentId === '') { continue; }
    $isArchivedComponent = !empty($component['archived_flag']);
    if ($isArchivedComponent && !isset($selectedComponentIds[$componentId])) {
        continue;
    }
    $componentMap[$componentId] = $component;
    $componentClientMap[$componentId] = [
        'id' => $componentId,
        'name' => (string) ($component['name'] ?? $componentId),
        'description' => (string) ($component['description'] ?? ''),
        'unit' => (string) ($component['unit'] ?? 'Nos'),
        'hsn' => (string) ($component['hsn'] ?? ''),
        'is_cuttable' => !empty($component['is_cuttable']),
        'has_variants' => !empty($component['has_variants']),
        'archived' => $isArchivedComponent,
    ];
}

$variantMap = [];
$variantsByComponent = [];
$variantStockById = [];
$stockSnapshot = documents_inventory_load_stock();
$selectedVariantIds = [];
foreach ((array) ($challan['lines'] ?? []) as $existingLine) {
    if (is_array($existingLine)) {
        $existingVariantId = safe_text((string) ($existingLine['variant_id'] ?? ''));
        if ($existingVariantId !== '') {
            $selectedVariantIds[$existingVariantId] = true;
        }
    }
}
foreach (documents_inventory_component_variants(true) as $variant) {
    if (!is_array($variant)) { continue; }
    $variantId = (string) ($variant['id'] ?? '');
    $componentId = (string) ($variant['component_id'] ?? '');
    if ($variantId === '' || $componentId === '') { continue; }
    $variantArchived = !empty($variant['archived_flag']);
    if ($variantArchived && !isset($selectedVariantIds[$variantId])) {
        continue;
    }
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

$activeInventoryLocations = get_active_locations();
$allInventoryLocations = documents_inventory_locations(true);
$locationsMap = [];
foreach ($allInventoryLocations as $locationRow) {
    if (!is_array($locationRow)) {
        continue;
    }
    $locId = (string) ($locationRow['id'] ?? '');
    if ($locId === '') {
        continue;
    }
    $locationsMap[$locId] = (string) ($locationRow['name'] ?? $locId);
}


$locationStockByComponentVariant = [];
foreach ($componentMap as $componentId => $component) {
    $isCuttableComponent = !empty($component['is_cuttable']);
    $variantEntries = !empty($component['has_variants']) ? ($variantsByComponent[$componentId] ?? []) : [['id' => '']];
    if ($variantEntries === []) {
        $variantEntries = [['id' => '']];
    }
    foreach ($variantEntries as $variantEntry) {
        $variantId = (string) ($variantEntry['id'] ?? '');
        $entry = documents_inventory_component_stock($stockSnapshot, $componentId, $variantId);
        $byLocation = [];
        if ($isCuttableComponent) {
            foreach ((array) ($entry['lots'] ?? []) as $lot) {
                if (!is_array($lot)) { continue; }
                $locId = safe_text((string) ($lot['location_id'] ?? ''));
                $rem = max(0, (float) ($lot['remaining_length_ft'] ?? 0));
                if (!isset($byLocation[$locId])) { $byLocation[$locId] = 0.0; }
                $byLocation[$locId] += $rem;
            }
        } else {
            foreach ((array) ($entry['location_breakdown'] ?? []) as $locRow) {
                if (!is_array($locRow)) { continue; }
                $locId = safe_text((string) ($locRow['location_id'] ?? ''));
                $qty = max(0, (float) ($locRow['qty'] ?? 0));
                if (!isset($byLocation[$locId])) { $byLocation[$locId] = 0.0; }
                $byLocation[$locId] += $qty;
            }
        }
        $rows = [];
        foreach ($byLocation as $locId => $available) {
            $rows[] = ['location_id' => $locId, 'location_name' => $locationsMap[$locId] ?? ($locId === '' ? 'Unassigned' : $locId), 'available' => round($available, 4)];
        }
        $locationStockByComponentVariant[$componentId . '|' . $variantId] = $rows;
    }
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

$quoteItems = is_array($quote)
    ? documents_normalize_quote_structured_items(is_array($quote['quote_items'] ?? null) ? $quote['quote_items'] : [])
    : [];
$quoteDirectComponentIds = [];
$kitIds = [];
foreach ($quoteItems as $quoteItem) {
    if (!is_array($quoteItem)) {
        continue;
    }
    if ((string) ($quoteItem['type'] ?? '') === 'kit') {
        $kitId = safe_text((string) ($quoteItem['kit_id'] ?? ''));
        if ($kitId !== '') {
            $kitIds[$kitId] = true;
        }
        continue;
    }
    if ((string) ($quoteItem['type'] ?? '') === 'component') {
        $componentId = safe_text((string) ($quoteItem['component_id'] ?? ''));
        if ($componentId !== '') {
            $quoteDirectComponentIds[$componentId] = true;
        }
    }
}

if ($kitIds === [] && is_array($packingList)) {
    foreach ((array) ($packingList['required_items'] ?? []) as $requiredLine) {
        if (!is_array($requiredLine)) { continue; }
        $kitId = safe_text((string) ($requiredLine['source_kit_id'] ?? ''));
        if ($kitId !== '') {
            $kitIds[$kitId] = true;
        }
    }
}

$currentKitComponentIdsByKit = [];
$currentKitComponentIds = [];
$kitReferencePanels = [];
$kitReferenceChanged = [];
foreach (array_keys($kitIds) as $kitId) {
    $kit = documents_inventory_get_kit($kitId);
    if (!is_array($kit)) {
        continue;
    }
    $componentRows = [];
    $kitComponentIds = [];
    foreach ((array) ($kit['items'] ?? []) as $kitLine) {
        if (!is_array($kitLine)) { continue; }
        $componentId = safe_text((string) ($kitLine['component_id'] ?? ''));
        if ($componentId === '') { continue; }
        $component = documents_inventory_get_component($componentId);
        if (!is_array($component) || !empty($component['archived_flag'])) {
            continue;
        }
        $kitComponentIds[$componentId] = true;
        $currentKitComponentIds[$componentId] = true;
        $componentRows[] = [
            'id' => $componentId,
            'name' => (string) ($component['name'] ?? $componentId),
        ];
    }
    $currentKitComponentIdsByKit[$kitId] = $kitComponentIds;

    $packingComponentIds = [];
    if (is_array($packingList)) {
        foreach ((array) ($packingList['required_items'] ?? []) as $requiredLine) {
            if (!is_array($requiredLine)) { continue; }
            if ((string) ($requiredLine['source_kit_id'] ?? '') !== $kitId) { continue; }
            $componentId = safe_text((string) ($requiredLine['component_id'] ?? ''));
            if ($componentId !== '') {
                $packingComponentIds[$componentId] = true;
            }
        }
    }
    $added = count(array_diff(array_keys($kitComponentIds), array_keys($packingComponentIds)));
    $removed = count(array_diff(array_keys($packingComponentIds), array_keys($kitComponentIds)));
    $kitReferenceChanged[$kitId] = ($added > 0 || $removed > 0)
        ? ['added' => $added, 'removed' => $removed]
        : null;

    $kitReferencePanels[] = [
        'id' => $kitId,
        'name' => (string) ($kit['name'] ?? $kitId),
        'components' => $componentRows,
    ];
}

$quotationGroups = [];
$quotationItemsFlat = [];
$quotationSeenComponentIds = [];
foreach ($quoteItems as $quoteItem) {
    if (!is_array($quoteItem)) {
        continue;
    }
    $itemType = (string) ($quoteItem['type'] ?? '');
    if ($itemType === 'kit') {
        $kitId = safe_text((string) ($quoteItem['kit_id'] ?? ''));
        if ($kitId === '') {
            continue;
        }
        $kit = documents_inventory_get_kit($kitId);
        if (!is_array($kit)) {
            continue;
        }
        $groupKey = 'kit_' . $kitId;
        if (!isset($quotationGroups[$groupKey])) {
            $quotationGroups[$groupKey] = [
                'name' => '🧩 Kit: ' . ((string) ($kit['name'] ?? $kitId)),
                'items' => [],
            ];
        }
        foreach ((array) ($kit['items'] ?? []) as $kitLine) {
            if (!is_array($kitLine)) {
                continue;
            }
            $componentId = safe_text((string) ($kitLine['component_id'] ?? ''));
            if ($componentId === '' || isset($quotationSeenComponentIds[$componentId])) {
                continue;
            }
            $component = $componentMap[$componentId] ?? null;
            if (!is_array($component) || !empty($component['archived_flag'])) {
                continue;
            }
            $quotationSeenComponentIds[$componentId] = true;
            $isCuttable = !empty($component['is_cuttable']);
            $onHand = documents_inventory_compute_on_hand($stockSnapshot, $componentId, '', $isCuttable);
            $item = [
                'packing_line_id' => '',
                'component_id' => $componentId,
                'component_name' => (string) ($component['name'] ?? $componentId),
                'notes' => '',
                'mode' => 'fixed_qty',
                'pending_qty' => 0,
                'pending_ft' => 0,
                'pending_wp' => 0,
                'fulfilled' => false,
                'status' => $onHand > 0 ? 'Ready (in stock)' : 'Low/0 stock',
                'on_hand' => $onHand,
                'has_variants' => !empty($component['has_variants']),
                'is_cuttable' => $isCuttable,
                'pending_text' => $isCuttable ? 'Current kit component (length by lot)' : 'Current kit component',
                'hint' => '',
            ];
            $quotationGroups[$groupKey]['items'][] = $item;
            $quotationItemsFlat[] = $item;
        }
        continue;
    }

    if ($itemType !== 'component') {
        continue;
    }
    $componentId = safe_text((string) ($quoteItem['component_id'] ?? ''));
    if ($componentId === '' || isset($quotationSeenComponentIds[$componentId])) {
        continue;
    }
    $component = $componentMap[$componentId] ?? null;
    if (!is_array($component) || !empty($component['archived_flag'])) {
        continue;
    }
    $groupKey = 'direct_components';
    if (!isset($quotationGroups[$groupKey])) {
        $quotationGroups[$groupKey] = ['name' => '📦 Components (direct in quotation)', 'items' => []];
    }
    $quotationSeenComponentIds[$componentId] = true;
    $isCuttable = !empty($component['is_cuttable']);
    $onHand = documents_inventory_compute_on_hand($stockSnapshot, $componentId, '', $isCuttable);
    $item = [
        'packing_line_id' => '',
        'component_id' => $componentId,
        'component_name' => (string) ($component['name'] ?? $componentId),
        'notes' => '',
        'mode' => 'fixed_qty',
        'pending_qty' => 0,
        'pending_ft' => 0,
        'pending_wp' => 0,
        'fulfilled' => false,
        'status' => $onHand > 0 ? 'Ready (in stock)' : 'Low/0 stock',
        'on_hand' => $onHand,
        'has_variants' => !empty($component['has_variants']),
        'is_cuttable' => $isCuttable,
        'pending_text' => 'Direct quotation component',
        'hint' => '',
    ];
    $quotationGroups[$groupKey]['items'][] = $item;
    $quotationItemsFlat[] = $item;
}
$validQuotationPackingLineIds = [];
$validQuotationComponentIds = [];
foreach ($quotationItemsFlat as $quotationItem) {
    $componentId = safe_text((string) ($quotationItem['component_id'] ?? ''));
    if ($componentId !== '') {
        $validQuotationComponentIds[$componentId] = true;
    }
}

$isDraftChallan = (string) ($challan['status'] ?? 'draft') === 'draft';
$legacyLines = [];
$activeLines = [];
$removedAutoPlaceholderRows = false;
foreach ((array) ($challan['lines'] ?? []) as $line) {
    if (!is_array($line)) {
        continue;
    }
    $isQuotationOrigin = (string) ($line['line_origin'] ?? 'extra') === 'quotation';
    if (!$isQuotationOrigin) {
        $activeLines[] = $line;
        continue;
    }
    $lineComponentId = safe_text((string) ($line['component_id'] ?? ''));
    $linePackingId = safe_text((string) ($line['packing_line_id'] ?? ''));
    $qty = max(0, (float) ($line['qty'] ?? 0));
    $lengthFt = max(0, (float) ($line['length_ft'] ?? 0));
    $totalLengthFt = max(0, (float) ($line['total_length_ft'] ?? 0));
    $hasLotData = !empty((array) ($line['lot_allocations'] ?? [])) || !empty((array) ($line['selected_lot_ids'] ?? [])) || !empty((array) ($line['lot_ids'] ?? []));
    $hasVariantData = safe_text((string) ($line['variant_id'] ?? '')) !== '';
    $pieceCount = max(0, (int) ($line['pieces'] ?? 0));
    $notes = safe_text((string) ($line['notes'] ?? ''));
    $hasUserData = $qty > 0.00001 || $lengthFt > 0.00001 || $totalLengthFt > 0.00001 || $pieceCount > 0 || $hasLotData || $hasVariantData || $notes !== '';
    $isStillValid = isset($validQuotationComponentIds[$lineComponentId]) || ($linePackingId !== '' && isset($validQuotationPackingLineIds[$linePackingId]));

    if (!$isDraftChallan) {
        if ($isStillValid) {
            $activeLines[] = $line;
        } elseif ($hasUserData) {
            $line['legacy_reason'] = 'Item removed from current kit definition';
            $legacyLines[] = $line;
        }
        continue;
    }

    if (!$isStillValid) {
        if ($hasUserData) {
            $line['legacy_reason'] = 'Item removed from current kit definition';
            $legacyLines[] = $line;
        }
        continue;
    }

    if (!$hasUserData) {
        $removedAutoPlaceholderRows = true;
        continue;
    }

    $activeLines[] = $line;
}

$challan['lines'] = $activeLines;
if ($isDraftChallan && $removedAutoPlaceholderRows) {
    $challan['updated_at'] = date('c');
    documents_save_challan($challan);
}
$quotationBuilderLines = [];
$extraBuilderLines = [];
foreach ((array) ($challan['lines'] ?? []) as $line) {
    if (!is_array($line)) {
        continue;
    }
    if ((string) ($line['line_origin'] ?? 'extra') === 'quotation') {
        $quotationBuilderLines[] = $line;
        continue;
    }
    $extraBuilderLines[] = $line;
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
    if ($action === 'repair_dc_inventory') {
        if ($viewerType !== 'admin') {
            $redirectWith('error', 'Only admin can run DC inventory repair.');
        }
        if ((string) ($challan['status'] ?? '') !== 'final') {
            $redirectWith('error', 'Only finalized DC can be repaired.');
        }
        if (!empty($challan['finalized_inventory_applied']) && !empty($challan['inventory_repair_done'])) {
            $redirectWith('success', 'No repair needed. DC inventory was already applied/repaired.');
        }
        $stock = documents_inventory_load_stock();
        $repairTxnIds = [];
        $repairRows = [];
        foreach ((array) ($challan['lines'] ?? []) as $line) {
            if (!is_array($line) || !empty($line['is_cuttable_snapshot'])) {
                continue;
            }
            $componentId = (string) ($line['component_id'] ?? '');
            $variantId = (string) ($line['variant_id'] ?? '');
            $needQty = max(0, (float) ($line['qty'] ?? 0));
            if ($componentId === '' || $needQty <= 0) {
                continue;
            }
            $component = $componentMap[$componentId] ?? null;
            if (!is_array($component)) {
                continue;
            }
            if (!empty($component['has_variants']) && $variantId === '') {
                $repairRows[] = ['line_id' => (string) ($line['line_id'] ?? ''), 'status' => 'skipped', 'reason' => 'missing_variant'];
                continue;
            }

            $alreadyTx = false;
            $allTx = documents_inventory_load_transactions();
            foreach ($allTx as $row) {
                if (!is_array($row)) { continue; }
                $tx = array_merge(documents_inventory_transaction_defaults(), $row);
                if (
                    strtoupper((string) ($tx['type'] ?? '')) === 'OUT'
                    && (string) ($tx['ref_type'] ?? '') === 'delivery_challan'
                    && (string) ($tx['ref_id'] ?? '') === (string) ($challan['id'] ?? '')
                    && (string) ($tx['component_id'] ?? '') === $componentId
                    && (string) ($tx['variant_id'] ?? '') === $variantId
                    && abs((float) ($tx['qty'] ?? 0) - $needQty) <= 0.00001
                ) {
                    $alreadyTx = true;
                    break;
                }
            }
            if ($alreadyTx) {
                $repairRows[] = ['line_id' => (string) ($line['line_id'] ?? ''), 'status' => 'skipped', 'reason' => 'transaction_exists'];
                continue;
            }

            $sourceLocationId = safe_text((string) ($line['source_location_id'] ?? ''));
            $entry = documents_inventory_component_stock($stock, $componentId, $variantId);
            $applied = documents_inventory_apply_non_cuttable_dispatch($entry, $needQty, $sourceLocationId, true);
            if (!($applied['ok'] ?? false)) {
                $repairRows[] = ['line_id' => (string) ($line['line_id'] ?? ''), 'status' => 'failed', 'reason' => (string) ($applied['error'] ?? 'apply_failed')];
                continue;
            }
            $entry = (array) ($applied['entry'] ?? $entry);
            $entry['updated_at'] = date('c');
            documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);

            $txId = 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $tx = [
                'id' => $txId,
                'type' => 'OUT',
                'component_id' => $componentId,
                'variant_id' => $variantId,
                'variant_name_snapshot' => (string) ($line['variant_name_snapshot'] ?? ''),
                'unit' => (string) ($line['unit_snapshot'] ?? ''),
                'qty' => $needQty,
                'length_ft' => 0,
                'lot_consumption' => [],
                'batch_consumption' => (array) ($applied['batch_consumption'] ?? []),
                'location_consumption' => (array) ($applied['location_consumption'] ?? []),
                'source_location_id' => $sourceLocationId,
                'ref_type' => 'delivery_challan',
                'ref_id' => (string) ($challan['id'] ?? ''),
                'reason' => 'DC Inventory Repair',
                'notes' => (string) ($line['notes'] ?? ''),
                'created_at' => date('c'),
                'created_by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                'allow_negative' => true,
            ];
            documents_inventory_append_transaction($tx);
            $repairTxnIds[] = $txId;
            $repairRows[] = ['line_id' => (string) ($line['line_id'] ?? ''), 'status' => 'repaired', 'txn_id' => $txId];
        }

        $savedStock = documents_inventory_save_stock($stock);
        if (!($savedStock['ok'] ?? false)) {
            $redirectWith('error', 'Repair failed while saving stock.');
        }

        $challan['inventory_txn_ids'] = array_values(array_unique(array_merge((array) ($challan['inventory_txn_ids'] ?? []), $repairTxnIds)));
        $challan['inventory_repair_done'] = true;
        $challan['inventory_repair_at'] = date('c');
        $challan['inventory_repair_log'] = array_values(array_merge((array) ($challan['inventory_repair_log'] ?? []), [[
            'at' => date('c'),
            'by' => ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
            'rows' => $repairRows,
        ]]));
        $challan['updated_at'] = date('c');
        $saved = documents_save_challan($challan);
        if (!($saved['ok'] ?? false)) {
            $redirectWith('error', 'Repair applied but failed to persist DC marker.');
        }
        $repairedCount = 0;
        foreach ($repairRows as $row) {
            if (($row['status'] ?? '') === 'repaired') {
                $repairedCount++;
            }
        }
        $redirectWith('success', $repairedCount > 0 ? ('DC inventory repair completed. Repaired lines: ' . $repairedCount . '.') : 'No repair needed.');
    }

    if ($action === 'repair_archive_restore') {
        if ($viewerType !== 'admin') {
            $redirectWith('error', 'Only admin can run archive inventory repair.');
        }
        $repairResult = documents_inventory_restore_cuttable_dc_archive($challan, ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName], true);
        if (!($repairResult['ok'] ?? false)) {
            $redirectWith('error', (string) ($repairResult['error'] ?? 'Failed to repair archive inventory restore.'));
        }
        if (!empty($repairResult['restore_txn_id'])) {
            $challan['archive_restore_done'] = true;
            $challan['archive_restore_at'] = date('c');
            $challan['archive_restore_by'] = ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName];
            $challan['archive_restore_txn_ids'] = array_values(array_unique(array_merge(
                (array) ($challan['archive_restore_txn_ids'] ?? []),
                [(string) $repairResult['restore_txn_id']]
            )));
            documents_append_document_action_log(
                ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                'archive_restore',
                'dc',
                (string) ($challan['id'] ?? ''),
                (string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? ''),
                'DC archive restore repair completed for cuttable lots. Restore txn: ' . (string) $repairResult['restore_txn_id']
            );
        }
        $challan['updated_at'] = date('c');
        $saved = documents_save_challan($challan);
        if (!($saved['ok'] ?? false)) {
            $redirectWith('error', 'Inventory repaired but failed to persist DC restore marker.');
        }
        $redirectWith('success', !empty($repairResult['restore_txn_id'])
            ? 'Archive inventory repair completed. Lots restored: ' . count((array) ($repairResult['lot_restored'] ?? [])) . ', ft restored: ' . documents_inventory_format_number((float) ($repairResult['total_ft'] ?? 0), 4)
            : 'No repair needed. Archive restore already complete.');
    }

    if (in_array($action, ['save_draft', 'finalize', 'archive', 'repair_archive_restore', 'repair_dc_inventory'], true)) {
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

            $structuredLines = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
            if ($structuredLines !== []) {
                $lineIds = [];
                $componentIds = [];
                $variantIds = [];
                $qtys = [];
                $lengths = [];
                $pieces = [];
                $notes = [];
                $origins = [];
                $packingLineIds = [];
                $lineCutPlanModes = [];
                $lineSelectedLotIds = [];
                $lineLotCuts = [];
                $lineLotAllocations = [];
                $lineSourceLocations = [];
                foreach ($structuredLines as $lineIdKey => $linePayload) {
                    if (!is_array($linePayload)) {
                        continue;
                    }
                    $lineIds[] = safe_text((string) $lineIdKey);
                    $componentIds[] = (string) ($linePayload['component_id'] ?? '');
                    $variantIds[] = (string) ($linePayload['variant_id'] ?? '');
                    $qtys[] = (float) ($linePayload['qty'] ?? 0);
                    $lengths[] = (float) ($linePayload['piece_length_ft'] ?? $linePayload['length_ft'] ?? 0);
                    $pieces[] = (int) ($linePayload['pieces'] ?? 0);
                    $notes[] = (string) ($linePayload['notes'] ?? '');
                    $origins[] = (string) ($linePayload['line_origin'] ?? 'extra');
                    $packingLineIds[] = (string) ($linePayload['packing_line_id'] ?? '');
                    $lineCutPlanModes[] = (string) ($linePayload['cut_plan_mode'] ?? 'suggested');
                    $lineSelectedLotIds[] = (array) ($linePayload['selected_lot_ids'] ?? []);
                    $lineLotCuts[] = (array) ($linePayload['lot_cuts'] ?? []);
                    $lineLotAllocations[] = (array) ($linePayload['lot_allocations'] ?? []);
                    $lineSourceLocations[] = (string) ($linePayload['source_location_id'] ?? '');
                }
            } else {
                $lineIds = is_array($_POST['line_id'] ?? null) ? $_POST['line_id'] : [];
                $componentIds = is_array($_POST['line_component_id'] ?? null) ? $_POST['line_component_id'] : [];
                $variantIds = is_array($_POST['line_variant_id'] ?? null) ? $_POST['line_variant_id'] : [];
                $qtys = is_array($_POST['line_qty'] ?? null) ? $_POST['line_qty'] : [];
                $lengths = is_array($_POST['line_length_ft'] ?? null) ? $_POST['line_length_ft'] : [];
                $pieces = is_array($_POST['line_pieces'] ?? null) ? $_POST['line_pieces'] : [];
                $notes = is_array($_POST['line_notes'] ?? null) ? $_POST['line_notes'] : [];
                $origins = is_array($_POST['line_origin'] ?? null) ? $_POST['line_origin'] : [];
                $packingLineIds = is_array($_POST['line_packing_line_id'] ?? null) ? $_POST['line_packing_line_id'] : [];
                $lineCutPlanModes = is_array($_POST['line_cut_plan_mode'] ?? null) ? $_POST['line_cut_plan_mode'] : [];
                $lineSelectedLotIds = is_array($_POST['line_selected_lot_ids'] ?? null) ? $_POST['line_selected_lot_ids'] : [];
                $lineLotCuts = is_array($_POST['line_lot_cuts'] ?? null) ? $_POST['line_lot_cuts'] : [];
                $lineLotAllocations = is_array($_POST['line_lot_allocations'] ?? null) ? $_POST['line_lot_allocations'] : [];
                $lineSourceLocations = is_array($_POST['line_source_location_id'] ?? null) ? $_POST['line_source_location_id'] : [];
            }

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
                $sourceLocationId = safe_text((string) ($lineSourceLocations[$idx] ?? ''));
                if ($isCuttable) {
                    // validation handled after reading lot allocations
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
                $cutPlanMode = strtolower(safe_text((string) ($lineCutPlanModes[$idx] ?? 'suggested')));
                $cutPlanMode = in_array($cutPlanMode, ['suggested', 'manual'], true) ? $cutPlanMode : 'suggested';
                $lotAllocationsRaw = $lineLotAllocations[$idx] ?? [];
                if (!is_array($lotAllocationsRaw)) {
                    $decodedAlloc = json_decode((string) $lotAllocationsRaw, true);
                    $lotAllocationsRaw = is_array($decodedAlloc) ? $decodedAlloc : [];
                }
                $lotAllocations = [];
                $lineTotalLengthFt = 0.0;
                foreach ($lotAllocationsRaw as $allocationRaw) {
                    if (!is_array($allocationRaw)) {
                        continue;
                    }
                    $allocLotId = safe_text((string) ($allocationRaw['lot_id'] ?? ''));
                    $allocVariantId = safe_text((string) ($allocationRaw['variant_id'] ?? $variantId));
                    $allocPieceLength = max(0, (float) ($allocationRaw['piece_length_ft'] ?? $allocationRaw['cut_length_ft'] ?? 0));
                    $allocPieces = max(1, (int) ($allocationRaw['pieces'] ?? $allocationRaw['cut_pieces'] ?? 1));
                    $allocLocation = safe_text((string) ($allocationRaw['location_id_snapshot'] ?? ''));
                    $allocCutLength = round($allocPieceLength * $allocPieces, 4);
                    if ($allocLotId === '' || $allocPieceLength <= 0 || $allocCutLength <= 0) {
                        continue;
                    }
                    $lotAllocations[] = [
                        'lot_id' => $allocLotId,
                        'variant_id' => $allocVariantId,
                        'piece_length_ft' => $allocPieceLength,
                        'pieces' => $allocPieces,
                        'cut_length_ft' => $allocCutLength,
                        'location_id_snapshot' => $allocLocation,
                    ];
                    $lineTotalLengthFt += $allocCutLength;
                }


                if ($isCuttable) {
                    $hasPiecesMode = ($pieceCount >= 1 && $lengthFt > 0);
                    $hasLotMode = ($lotAllocations !== []);
                    if (!$hasPiecesMode && !$hasLotMode) {
                        $lineErrors[] = 'For cuttable items, enter either Pieces + Length OR select lot cuts.';
                    }
                    if ($hasLotMode) {
                        $lengthFt = round($lineTotalLengthFt, 4);
                    } elseif ($hasPiecesMode) {
                        $lengthFt = round($pieceCount * $lengthFt, 4);
                    }
                    if (!$hasLotMode && $sourceLocationId === '') {
                        $lineErrors[] = 'Source location is required for cuttable items in pieces mode.';
                    }
                } elseif ($sourceLocationId === '') {
                    $lineErrors[] = 'Source location is required.';
                }

                if ($isCuttable && $cutPlanMode === 'manual') {
                    if ($lotAllocations === []) {
                        $lineErrors[] = 'Add at least one lot allocation in manual cutting mode.';
                    }
                    $stockEntryForLine = documents_inventory_component_stock($stockSnapshot, $componentId, $hasVariants ? $variantId : '');
                    $lotBucket = ['lots' => (array) ($stockEntryForLine['lots'] ?? [])];
                    $lotMeta = [];
                    foreach ((array) ($lotBucket['lots'] ?? []) as $lotEntry) {
                        if (!is_array($lotEntry)) {
                            continue;
                        }
                        $lotMeta[(string) ($lotEntry['lot_id'] ?? '')] = [
                            'remaining' => max(0, (float) ($lotEntry['remaining_length_ft'] ?? 0)),
                            'location_id' => safe_text((string) ($lotEntry['location_id'] ?? '')),
                        ];
                    }
                    $requestedByLot = [];
                    foreach ($lotAllocations as &$allocation) {
                        $allocLotId = (string) ($allocation['lot_id'] ?? '');
                        if (!isset($lotMeta[$allocLotId])) {
                            $lineErrors[] = 'Selected lot ' . $allocLotId . ' is not valid for this component/variant.';
                            continue;
                        }
                        if ($hasVariants && (string) ($allocation['variant_id'] ?? '') !== $variantId) {
                            $lineErrors[] = 'Lot ' . $allocLotId . ' allocation variant mismatch.';
                        }
                        $allocation['location_id_snapshot'] = (string) ($lotMeta[$allocLotId]['location_id'] ?? '');
                        $requestedByLot[$allocLotId] = ($requestedByLot[$allocLotId] ?? 0) + max(0, (float) ($allocation['cut_length_ft'] ?? 0));
                    }
                    unset($allocation);
                    if ($action === 'finalize') {
                        foreach ($requestedByLot as $allocLotId => $requestedFt) {
                            if ($requestedFt > ((float) ($lotMeta[$allocLotId]['remaining'] ?? 0) + 0.00001)) {
                                $lineErrors[] = 'Lot ' . $allocLotId . ' does not have sufficient remaining length. Please revise allocations.';
                            }
                        }
                    }
                    if ($lineTotalLengthFt > 0) {
                        $lengthFt = $lineTotalLengthFt;
                    }
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
                    'piece_length_ft' => max(0, (float) ($lengths[$idx] ?? 0)),
                    'source_location_id' => $sourceLocationId,
                    'selected_lot_ids' => $selectedLotIds,
                    'lot_ids' => $selectedLotIds,
                    'lot_cuts' => $lotCuts,
                    'cut_plan_mode' => $cutPlanMode,
                    'lot_allocations' => $lotAllocations,
                    'total_length_ft' => $lineTotalLengthFt,
                    'stock_changed_warning' => false,
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
            foreach ($challan['lines'] as &$line) {
                if (!empty($line['is_cuttable_snapshot']) && (string) ($line['cut_plan_mode'] ?? 'suggested') === 'manual') {
                    $componentId = (string) ($line['component_id'] ?? '');
                    $variantId = (string) ($line['variant_id'] ?? '');
                    $bucket = $cuttableStockByComponentVariant[$componentId . '|' . $variantId] ?? ['lots' => []];
                    $remainingByLotId = [];
                    foreach ((array) ($bucket['lots'] ?? []) as $lotRow) {
                        if (!is_array($lotRow)) {
                            continue;
                        }
                        $remainingByLotId[(string) ($lotRow['lot_id'] ?? '')] = max(0, (float) ($lotRow['remaining_length_ft'] ?? 0));
                    }
                    $line['stock_changed_warning'] = false;
                    $requestedByLot = [];
                    foreach ((array) ($line['lot_allocations'] ?? []) as $allocation) {
                        if (!is_array($allocation)) {
                            continue;
                        }
                        $allocLotId = (string) ($allocation['lot_id'] ?? '');
                        $requestedByLot[$allocLotId] = ($requestedByLot[$allocLotId] ?? 0) + max(0, (float) ($allocation['cut_length_ft'] ?? 0));
                    }
                    foreach ($requestedByLot as $allocLotId => $requested) {
                        $available = $remainingByLotId[$allocLotId] ?? 0;
                        if ($requested > $available + 0.00001) {
                            $line['stock_changed_warning'] = true;
                            break;
                        }
                    }
                }
                if (!empty($line['line_errors'])) {
                    $lineErrorsFound = true;
                    break;
                }
            }
            unset($line);
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
            if (!empty($challan['finalized_inventory_applied'])) {
                $redirectWith('error', 'Inventory already applied for this DC. Use amendment DC for changes.');
            }
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
                    'source_location_id' => (string) ($line['source_location_id'] ?? ''),
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
                    $cutPlanMode = (string) ($line['cut_plan_mode'] ?? 'suggested');
                    $lotAllocations = is_array($line['lot_allocations'] ?? null) ? $line['lot_allocations'] : [];
                    if ($cutPlanMode === 'manual' && $lotAllocations !== []) {
                        $needFt = max(0, (float) ($line['total_length_ft'] ?? 0));
                    }
                    $plannedCuts = is_array($line['lot_cuts'] ?? null) ? $line['lot_cuts'] : [];
                    $consume = ['lots' => (array) ($entry['lots'] ?? []), 'lot_consumption' => []];
                    if ($cutPlanMode === 'manual') {
                        $lotIndexById = [];
                        foreach ((array) ($entry['lots'] ?? []) as $lotIdx => $lotRow) {
                            if (!is_array($lotRow)) {
                                continue;
                            }
                            $lotIndexById[(string) ($lotRow['lot_id'] ?? '')] = $lotIdx;
                        }
                        $lotUseByLotId = [];
                        foreach ($lotAllocations as $allocation) {
                            if (!is_array($allocation)) {
                                continue;
                            }
                            $allocLotId = safe_text((string) ($allocation['lot_id'] ?? ''));
                            $allocFt = max(0, (float) ($allocation['cut_length_ft'] ?? 0));
                            if ($allocLotId === '' || $allocFt <= 0) {
                                continue;
                            }
                            $lotUseByLotId[$allocLotId] = ($lotUseByLotId[$allocLotId] ?? 0) + $allocFt;
                        }
                        foreach ($lotUseByLotId as $allocLotId => $useFt) {
                            if (!isset($lotIndexById[$allocLotId])) {
                                $redirectWith('error', 'Lot ' . $allocLotId . ' does not exist anymore. Please revise allocations.');
                            }
                            $lotIdx = $lotIndexById[$allocLotId];
                            $lotRemaining = max(0, (float) ($entry['lots'][$lotIdx]['remaining_length_ft'] ?? 0));
                            if ($useFt > $lotRemaining + 0.00001) {
                                $redirectWith('error', 'Lot ' . $allocLotId . ' does not have sufficient remaining length. Please revise allocations.');
                            }
                            $entry['lots'][$lotIdx]['remaining_length_ft'] = round($lotRemaining - $useFt, 4);
                            $consume['lot_consumption'][] = ['lot_id' => $allocLotId, 'used_ft' => round($useFt, 4)];
                        }
                        $consume['lots'] = (array) ($entry['lots'] ?? []);
                    } elseif ($plannedCuts !== []) {
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
                        $sourceLocationId = safe_text((string) ($line['source_location_id'] ?? ''));
                        $candidateLots = [];
                        foreach ((array) ($entry['lots'] ?? []) as $lotRow) {
                            if (!is_array($lotRow)) { continue; }
                            if ($sourceLocationId !== '' && safe_text((string) ($lotRow['location_id'] ?? '')) !== $sourceLocationId) { continue; }
                            $candidateLots[] = $lotRow;
                        }
                        if ($sourceLocationId !== '' && $candidateLots === []) {
                            $redirectWith('error', 'No available lots in selected source location for cuttable line.');
                        }
                        $consume = documents_inventory_consume_fifo_lots($candidateLots, min($needFt, documents_inventory_total_remaining_ft(['lots' => $candidateLots])));
                        if ($sourceLocationId !== '') {
                            $lotById = [];
                            foreach ((array) ($consume['lots'] ?? []) as $lotRow) {
                                if (!is_array($lotRow)) { continue; }
                                $lotById[(string) ($lotRow['lot_id'] ?? '')] = $lotRow;
                            }
                            foreach ((array) ($entry['lots'] ?? []) as $lotIndex => $lotRow) {
                                $lotId = (string) ($lotRow['lot_id'] ?? '');
                                if ($lotId !== '' && isset($lotById[$lotId])) {
                                    $entry['lots'][$lotIndex] = $lotById[$lotId];
                                }
                            }
                        }
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
                    if ($shortFt > 0.00001 && $cutPlanMode !== 'manual') {
                        $entry['lots'][] = [
                            'lot_id' => 'NEG-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)),
                            'remaining_length_ft' => -round($shortFt, 4),
                            'location_id' => '',
                            'note' => 'Negative stock via DC finalize',
                        ];
                    }
                    $tx['length_ft'] = $needFt;
                    $tx['lot_consumption'] = (array) ($consume['lot_consumption'] ?? []);
                    $tx['allow_negative'] = $cutPlanMode !== 'manual';
                } else {
                    $needQty = max(0, (float) ($line['qty'] ?? 0));
                    if ($needQty <= 0) { continue; }
                    $sourceLocationId = safe_text((string) ($line['source_location_id'] ?? ''));
                    if (!empty($component['has_variants']) && $variantId === '') {
                        $redirectWith('error', 'Variant is required for non-cuttable variant-enabled component line.');
                    }
                    $consumed = documents_inventory_apply_non_cuttable_dispatch($entry, $needQty, $sourceLocationId, true);
                    if (!($consumed['ok'] ?? false)) {
                        $redirectWith('error', (string) ($consumed['error'] ?? 'Unable to consume stock for line.'));
                    }
                    $entry = (array) ($consumed['entry'] ?? $entry);
                    $tx['qty'] = $needQty;
                    $tx['batch_consumption'] = (array) ($consumed['batch_consumption'] ?? []);
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
            $challan['inventory_txn_ids'] = array_values(array_unique(array_merge((array) ($challan['inventory_txn_ids'] ?? []), $txnIds)));
            $challan['finalized_inventory_applied'] = true;
            $challan['finalized_inventory_applied_at'] = date('c');
            $challan['status'] = 'final';
        }

        if ($action === 'save_draft') {
            $challan['status'] = 'draft';
        }
        if ($action === 'archive') {
            $restoreResult = documents_inventory_restore_cuttable_dc_archive($challan, ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName], false);
            if (!($restoreResult['ok'] ?? false)) {
                $redirectWith('error', (string) ($restoreResult['error'] ?? 'Failed to restore cuttable lot inventory while archiving DC.'));
            }
            if (!empty($restoreResult['restore_txn_id'])) {
                $challan['archive_restore_done'] = true;
                $challan['archive_restore_at'] = date('c');
                $challan['archive_restore_by'] = ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName];
                $challan['archive_restore_txn_ids'] = array_values(array_unique(array_merge(
                    (array) ($challan['archive_restore_txn_ids'] ?? []),
                    [(string) $restoreResult['restore_txn_id']]
                )));
                documents_append_document_action_log(
                    ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
                    'archive_restore',
                    'dc',
                    (string) ($challan['id'] ?? ''),
                    (string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? ''),
                    'DC archive restore completed for cuttable lots. Restore txn: ' . (string) $restoreResult['restore_txn_id']
                );
            }
            if (is_array($packingList)) {
                $updatedPack = documents_reverse_dispatch_from_packing_list($packingList, (string) ($challan['id'] ?? ''));
                $savedPack = documents_save_packing_list($updatedPack);
                if (!($savedPack['ok'] ?? false)) {
                    $redirectWith('error', 'Inventory restored, but packing list rollback failed.');
                }
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
        documents_append_document_action_log(
            ['role' => $viewerType, 'id' => $viewerId, 'name' => $viewerName],
            $action === 'finalize' ? 'finalize' : ($action === 'archive' ? 'archive' : 'update_draft'),
            'dc',
            (string) ($challan['id'] ?? ''),
            (string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? ''),
            $action === 'finalize' ? 'Delivery challan finalized.' : ($action === 'archive' ? 'Delivery challan archived.' : 'Delivery challan draft updated.')
        );
        $redirectWith('success', $action === 'finalize' ? 'DC finalized and inventory updated.' : ($action === 'save_draft' ? 'DC draft saved.' : 'DC archived.'));
    }
}

$backLink = (is_array($user) && (($user['role_name'] ?? '') === 'admin')) ? 'admin-documents.php?tab=accepted_customers&view=' . urlencode((string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? '')) : 'employee-challans.php';
$statusParam = safe_text($_GET['status'] ?? '');
$messageParam = safe_text($_GET['message'] ?? '');
$editable = (string) ($challan['status'] ?? 'draft') === 'draft' && !($viewerType === 'employee' && (string) ($challan['created_by']['role'] ?? $challan['created_by_type'] ?? '') === 'admin');
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

<h3>Kit Reference (current definition)</h3>
<div>
<?php foreach ($kitReferencePanels as $kitRef): ?>
  <div class="tree-item">
    <div><strong>Kit: <?= htmlspecialchars((string) ($kitRef['name'] ?? ''), ENT_QUOTES) ?></strong> <span class="small muted">(<?= htmlspecialchars((string) ($kitRef['id'] ?? ''), ENT_QUOTES) ?>)</span></div>
    <?php $delta = $kitReferenceChanged[(string) ($kitRef['id'] ?? '')] ?? null; ?>
    <?php if (is_array($delta)): ?><div class="small" style="color:#92400e;margin-top:4px;">Kit definition has changed since acceptance. Packing list remains based on accepted quotation. (+<?= (int) ($delta['added'] ?? 0) ?> / -<?= (int) ($delta['removed'] ?? 0) ?>)</div><?php endif; ?>
    <ul class="small" style="margin:6px 0 0 16px;">
      <?php foreach ((array) ($kitRef['components'] ?? []) as $cmp): ?>
        <li><?= htmlspecialchars((string) ($cmp['name'] ?? ''), ENT_QUOTES) ?> <span class="muted">(<?= htmlspecialchars((string) ($cmp['id'] ?? ''), ENT_QUOTES) ?>)</span></li>
      <?php endforeach; ?>
      <?php if ((array) ($kitRef['components'] ?? []) === []): ?><li class="muted">No active components currently in this kit.</li><?php endif; ?>
    </ul>
  </div>
<?php endforeach; ?>
<?php if ($kitReferencePanels === []): ?><p class="muted">No kits linked to this quotation/packing list.</p><?php endif; ?>
</div>

<h3>Quotation Items (Builder)</h3>

<table id="dc-lines-quotation"><thead><tr><th style="width:5%">Sr.</th><th style="width:44%">Components</th><th style="width:10%">HSN</th><th style="width:16%">Quantity / pieces</th><th style="width:15%">length</th><th style="width:10%">Actions</th></tr></thead><tbody>
<?php foreach ((array) $quotationBuilderLines as $line): if (!is_array($line)) { continue; } $componentId=(string)($line['component_id']??''); $variantId=(string)($line['variant_id']??''); $isCuttable=!empty($line['is_cuttable_snapshot']); $lineStock=documents_inventory_compute_on_hand($stockSnapshot,$componentId,$variantId,$isCuttable); ?>
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
<select name="line_source_location_id[]" class="source-location-select" style="margin-top:6px" <?= $editable ? '' : 'disabled' ?>><option value="">Consume from location</option><?php foreach ($activeInventoryLocations as $loc): if (!is_array($loc)) { continue; } ?><option value="<?= htmlspecialchars((string) ($loc['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($loc['id'] ?? '')) === (string) ($line['source_location_id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($loc['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?><?php $selectedSourceLocationId = (string) ($line['source_location_id'] ?? ''); if ($selectedSourceLocationId !== ''): $selectedSourceLocation = null; foreach ($allInventoryLocations as $locRow) { if (is_array($locRow) && (string) ($locRow['id'] ?? '') === $selectedSourceLocationId) { $selectedSourceLocation = $locRow; break; } } if (is_array($selectedSourceLocation) && !empty($selectedSourceLocation['archived_flag'])): ?><option value="<?= htmlspecialchars($selectedSourceLocationId, ENT_QUOTES) ?>" selected><?= htmlspecialchars((string) ($selectedSourceLocation['name'] ?? $selectedSourceLocationId), ENT_QUOTES) ?> (archived)</option><?php endif; endif; ?></select>
<input type="hidden" name="line_selected_lot_ids[]" class="line-selected-lot-ids" value="<?= htmlspecialchars(json_encode((array) ($line['selected_lot_ids'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<input type="hidden" name="line_lot_cuts[]" class="line-lot-cuts-input" value="<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<input type="hidden" name="line_cut_plan_mode[]" class="line-cut-plan-mode" value="<?= htmlspecialchars((string) ($line['cut_plan_mode'] ?? 'suggested'), ENT_QUOTES) ?>">
<input type="hidden" name="line_lot_allocations[]" class="line-lot-allocations-input" value="<?= htmlspecialchars(json_encode((array) ($line['lot_allocations'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<div class="line-lot-cuts" data-line-id="<?= htmlspecialchars((string) ($line['line_id'] ?? ''), ENT_QUOTES) ?>" data-lot-cuts='<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'></div>
<?php if (!empty($line['stock_changed_warning'])): ?><div class="small" style="margin-top:6px;color:#b45309">Stock changed warning: one or more manual lot cuts may now exceed available lot length.</div><?php endif; ?>
</td>
<td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="<?= htmlspecialchars((string) ((float) ($line['length_ft'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td class="row-actions"><?php if ($editable): ?><button type="button" class="btn warn remove-line">×</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<h3>Extra items not present in quotation</h3>
<?php if ($editable): ?><p><button type="button" id="add-extra-line" class="btn secondary">+ Add Line</button></p><?php endif; ?>

<table id="dc-lines-extra"><thead><tr><th style="width:5%">Sr.</th><th style="width:44%">Components</th><th style="width:10%">HSN</th><th style="width:16%">Quantity / pieces</th><th style="width:15%">length</th><th style="width:10%">Actions</th></tr></thead><tbody>
<?php foreach ((array) $extraBuilderLines as $line): if (!is_array($line)) { continue; } $componentId=(string)($line['component_id']??''); $variantId=(string)($line['variant_id']??''); $isCuttable=!empty($line['is_cuttable_snapshot']); $lineStock=documents_inventory_compute_on_hand($stockSnapshot,$componentId,$variantId,$isCuttable); ?>
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
<select name="line_source_location_id[]" class="source-location-select" style="margin-top:6px" <?= $editable ? '' : 'disabled' ?>><option value="">Consume from location</option><?php foreach ($activeInventoryLocations as $loc): if (!is_array($loc)) { continue; } ?><option value="<?= htmlspecialchars((string) ($loc['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($loc['id'] ?? '')) === (string) ($line['source_location_id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($loc['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?><?php $selectedSourceLocationId = (string) ($line['source_location_id'] ?? ''); if ($selectedSourceLocationId !== ''): $selectedSourceLocation = null; foreach ($allInventoryLocations as $locRow) { if (is_array($locRow) && (string) ($locRow['id'] ?? '') === $selectedSourceLocationId) { $selectedSourceLocation = $locRow; break; } } if (is_array($selectedSourceLocation) && !empty($selectedSourceLocation['archived_flag'])): ?><option value="<?= htmlspecialchars($selectedSourceLocationId, ENT_QUOTES) ?>" selected><?= htmlspecialchars((string) ($selectedSourceLocation['name'] ?? $selectedSourceLocationId), ENT_QUOTES) ?> (archived)</option><?php endif; endif; ?></select>
<input type="hidden" name="line_selected_lot_ids[]" class="line-selected-lot-ids" value="<?= htmlspecialchars(json_encode((array) ($line['selected_lot_ids'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<input type="hidden" name="line_lot_cuts[]" class="line-lot-cuts-input" value="<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<input type="hidden" name="line_cut_plan_mode[]" class="line-cut-plan-mode" value="<?= htmlspecialchars((string) ($line['cut_plan_mode'] ?? 'suggested'), ENT_QUOTES) ?>">
<input type="hidden" name="line_lot_allocations[]" class="line-lot-allocations-input" value="<?= htmlspecialchars(json_encode((array) ($line['lot_allocations'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<div class="line-lot-cuts" data-line-id="<?= htmlspecialchars((string) ($line['line_id'] ?? ''), ENT_QUOTES) ?>" data-lot-cuts='<?= htmlspecialchars(json_encode((array) ($line['lot_cuts'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'></div>
<?php if (!empty($line['stock_changed_warning'])): ?><div class="small" style="margin-top:6px;color:#b45309">Stock changed warning: one or more manual lot cuts may now exceed available lot length.</div><?php endif; ?>
</td>
<td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="<?= htmlspecialchars((string) ((float) ($line['length_ft'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td class="row-actions"><?php if ($editable): ?><button type="button" class="btn warn remove-line">×</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<?php if ($legacyLines !== []): ?>
<div class="card" style="margin-top:12px;background:#fff7ed;border-color:#fed7aa;">
  <h3 style="margin:0 0 8px">Legacy lines (item removed from kit)</h3>
  <p class="small muted" style="margin:0 0 8px">These lines are retained because they have usage/allocation data and cannot be auto-removed safely.</p>
  <ul class="small" style="margin:0 0 0 16px;">
    <?php foreach ($legacyLines as $legacyLine): if (!is_array($legacyLine)) { continue; } ?>
      <li><?= htmlspecialchars((string) ($legacyLine['component_name_snapshot'] ?? $legacyLine['component_id'] ?? ''), ENT_QUOTES) ?> — Qty <?= htmlspecialchars((string) ((float) ($legacyLine['qty'] ?? 0)), ENT_QUOTES) ?>, Ft <?= htmlspecialchars((string) ((float) ($legacyLine['length_ft'] ?? 0)), ENT_QUOTES) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="row-actions" style="margin-top:12px"><?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button><button class="btn" type="submit" name="action" value="finalize">Finalize DC</button><?php endif; ?><?php if ((string) ($challan['status'] ?? '') !== 'archived'): ?><button class="btn warn" type="submit" name="action" value="archive">Archive</button><?php endif; ?><?php if ($viewerType === 'admin' && (string) ($challan['status'] ?? '') === 'final' && (empty($challan['finalized_inventory_applied']) || empty($challan['inventory_repair_done']))): ?><button class="btn secondary" type="submit" name="action" value="repair_dc_inventory">Repair DC inventory</button><?php endif; ?><?php if ($viewerType === 'admin' && !empty($challan['archived_flag']) && in_array((string) ($challan['status'] ?? ''), ['final', 'archived'], true) && empty($challan['archive_restore_done'])): ?><button class="btn secondary" type="submit" name="action" value="repair_archive_restore">Repair archive inventory</button><?php endif; ?></div>
</form>
</main>
<script>
const COMPONENTS = <?= json_encode($componentClientMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const VARIANTS = <?= json_encode($variantsByComponent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const LOCATION_STOCK = <?= json_encode($locationStockByComponentVariant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const CUTTABLE_STOCK = <?= json_encode($cuttableStockByComponentVariant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const editable = <?= $editable ? 'true' : 'false' ?>;

const componentOptions = `<option value="">Select</option>${Object.values(COMPONENTS).map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}`;
const LOCATION_OPTIONS = <?= json_encode(array_map(static fn($l): array => ['id' => (string) ($l['id'] ?? ''), 'name' => (string) ($l['name'] ?? '')], documents_inventory_locations(false)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const refreshSr = () => {
  document.querySelectorAll('#dc-lines-quotation tbody tr').forEach((tr, idx) => { const el=tr.querySelector('.sr-col'); if (el) el.textContent = String(idx+1); });
  document.querySelectorAll('#dc-lines-extra tbody tr').forEach((tr, idx) => { const el=tr.querySelector('.sr-col'); if (el) el.textContent = String(idx+1); });
};
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

const fmtFt = (n) => `${Number(n || 0).toFixed(2)} ft`;

const getLotMeta = (lots) => {
  const map = {};
  (lots || []).forEach((l) => { map[l.lot_id] = l; });
  return map;
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
  const modeInput = tr.querySelector('.line-cut-plan-mode');
  const allocationsInput = tr.querySelector('.line-lot-allocations-input');
  const existingCuts = readJsonArray(cutsInput?.value || '[]');
  const existingAllocations = readJsonArray(allocationsInput?.value || '[]');
  const lotMeta = getLotMeta(lots);
  const isVariantPending = !!(c.has_variants && !variant.value);
  const pendingRequired = Math.max(0, pieces) * Math.max(0, cutLen);
  if (selectedLotsInput && !selectedLotsInput.value) {
    selectedLotsInput.value = JSON.stringify(existingCuts.map(cut => cut.lot_id));
  }
  panel.innerHTML = `
    <div><strong>Available pieces</strong> · Total: ${fmtFt(total)}</div>
    ${(lots.map(l => `<div>Lot ${l.lot_id} · ${fmtFt(l.remaining_length_ft)} · ${l.location_name || '-'}</div>`).join('')) || '<div class="muted">No lots available.</div>'}
    <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
      <button type="button" class="btn secondary suggest-cuts">Suggest from stock</button>
      <button type="button" class="btn secondary manual-toggle">Manual lot cutting</button>
    </div>
    <div class="small suggested-cuts" style="margin-top:6px"></div>
    <div class="small manual-wrap" style="margin-top:8px"></div>
  `;
  const suggestBtn = panel.querySelector('.suggest-cuts');
  const manualToggle = panel.querySelector('.manual-toggle');
  const suggested = panel.querySelector('.suggested-cuts');
  const manualWrap = panel.querySelector('.manual-wrap');
  const drawCuts = (cuts) => {
    if (cutsInput) cutsInput.value = JSON.stringify(cuts);
    if (selectedLotsInput) selectedLotsInput.value = JSON.stringify(cuts.map(cut => cut.lot_id));
    suggested.textContent = cuts.length ? cuts.map(cut => `${cut.lot_id}: ${cut.count} × ${Number(cut.cut_length_ft).toFixed(2)}ft`).join(' | ') : 'No lot cuts selected.';
  };
  const drawManual = (allocs) => {
    if (!allocationsInput) return;
    const normalized = (allocs || []).map((a) => {
      const pieceLength = Number(a?.piece_length_ft ?? a?.cut_length_ft ?? 0);
      const piecesCount = Math.max(1, Number(a?.pieces ?? a?.cut_pieces ?? 1));
      return {
        lot_id: a?.lot_id || '',
        variant_id: a?.variant_id || variant.value || '',
        piece_length_ft: pieceLength,
        pieces: piecesCount,
        cut_length_ft: Number((pieceLength * piecesCount).toFixed(4)),
        location_id_snapshot: a?.location_id_snapshot || '',
      };
    });
    const clean = normalized.filter((a) => a && a.lot_id);
    allocationsInput.value = JSON.stringify(clean);
    if (selectedLotsInput) {
      selectedLotsInput.value = JSON.stringify([...new Set(clean.map(a => a.lot_id))]);
    }
    if (modeInput && modeInput.value === 'manual') {
      const totalSel = clean.reduce((sum, a) => sum + Number(a.cut_length_ft || 0), 0);
      let html = `<div><strong>Pending required:</strong> ${fmtFt(pendingRequired)} · <strong>Total selected length:</strong> ${fmtFt(totalSel)}</div>`;
      if (isVariantPending) {
        manualWrap.innerHTML = '<div style="color:#b91c1c">Select variant first to use manual lot cutting.</div>';
        return;
      }
      html += `<table style="margin-top:6px"><thead><tr><th>Lot</th><th>Available remaining ft</th><th>Piece length (ft)</th><th>Pieces</th><th>Total cut ft</th><th></th></tr></thead><tbody>`;
      clean.forEach((a, i) => {
        const rem = Number((lotMeta[a.lot_id] || {}).remaining_length_ft || 0);
        const rowTotal = Number((Number(a.piece_length_ft || 0) * Number(a.pieces || 1)).toFixed(4));
        const err = Number(a.piece_length_ft || 0) <= 0 || Number(a.pieces || 0) < 1 || rowTotal > rem;
        html += `<tr data-idx="${i}"><td><select class="manual-lot-id"><option value="">Select</option>${lots.map(l => `<option value="${l.lot_id}" ${l.lot_id===a.lot_id?'selected':''}>${l.lot_id} (rem: ${fmtFt(l.remaining_length_ft)}, loc: ${l.location_name || '-'})</option>`).join('')}</select></td><td>${fmtFt(rem)}</td><td><input type="number" min="0" step="0.01" class="manual-piece-ft" value="${Number(a.piece_length_ft||0)}"></td><td><input type="number" min="1" step="1" class="manual-pieces" value="${Math.max(1, Number(a.pieces||1))}"></td><td>${fmtFt(rowTotal)}</td><td><button type="button" class="btn warn manual-remove">×</button></td></tr>${err?`<tr><td colspan="6" style="color:#b91c1c">Piece length must be > 0, pieces must be >= 1, and total cut must be ≤ lot remaining length.</td></tr>`:''}`;
      });
      html += `</tbody></table><div style="margin-top:6px;display:flex;gap:6px"><button type="button" class="btn secondary manual-add">+ Add lot allocation</button><button type="button" class="btn secondary manual-autofill">Auto-fill remaining requirement</button></div>`;
      const perLot = {};
      clean.forEach((a) => {
        if (!a.lot_id) return;
        perLot[a.lot_id] = perLot[a.lot_id] || { used: 0, cuts: [] };
        perLot[a.lot_id].used += Number(a.cut_length_ft || 0);
        perLot[a.lot_id].cuts.push(`${Number(a.piece_length_ft || 0)}ft × ${Math.max(1, Number(a.pieces || 1))}`);
      });
      const lotSummary = Object.entries(perLot).map(([lotId, info]) => {
        const rem = Number((lotMeta[lotId] || {}).remaining_length_ft || 0);
        return `<div>Lot ${lotId}: ${info.cuts.join(', ')} (${fmtFt(info.used)}), remaining after cut: ${fmtFt(Math.max(0, rem - info.used))}</div>`;
      }).join('');
      if (lotSummary) {
        html += `<div style="margin-top:6px">${lotSummary}</div>`;
      }
      manualWrap.innerHTML = html;
      manualWrap.querySelectorAll('.manual-remove').forEach((btn) => btn.addEventListener('click', (e) => {
        const idx = Number(e.target.closest('tr')?.dataset?.idx || -1);
        const next = clean.filter((_, i) => i !== idx);
        drawManual(next);
      }));
      manualWrap.querySelectorAll('.manual-lot-id').forEach((sel) => sel.addEventListener('change', () => {
        const idx = Number(sel.closest('tr')?.dataset?.idx || -1);
        if (idx < 0) return;
        clean[idx].lot_id = sel.value;
        clean[idx].location_id_snapshot = (lotMeta[sel.value] || {}).location_id || '';
        drawManual(clean);
      }));
      manualWrap.querySelectorAll('.manual-piece-ft').forEach((inp) => inp.addEventListener('input', () => {
        const idx = Number(inp.closest('tr')?.dataset?.idx || -1);
        if (idx < 0) return;
        clean[idx].piece_length_ft = Number(inp.value || 0);
        drawManual(clean);
      }));
      manualWrap.querySelectorAll('.manual-pieces').forEach((inp) => inp.addEventListener('input', () => {
        const idx = Number(inp.closest('tr')?.dataset?.idx || -1);
        if (idx < 0) return;
        clean[idx].pieces = Math.max(1, Number(inp.value || 1));
        drawManual(clean);
      }));
      manualWrap.querySelector('.manual-add')?.addEventListener('click', () => drawManual([...clean, {lot_id: '', variant_id: variant.value || '', piece_length_ft: 0, pieces: 1, cut_length_ft: 0, location_id_snapshot: ''}]));
      manualWrap.querySelector('.manual-autofill')?.addEventListener('click', () => {
        let remaining = Math.max(0, pendingRequired - totalSel);
        if (remaining <= 0 || cutLen <= 0) return;
        const next = [...clean];
        lots.forEach((l) => {
          if (remaining <= 0) return;
          const maxPieces = Math.floor(Number(l.remaining_length_ft || 0) / cutLen);
          const takePieces = Math.min(maxPieces, Math.floor(remaining / cutLen));
          if (takePieces > 0) {
            next.push({lot_id: l.lot_id, variant_id: variant.value || '', piece_length_ft: Number(cutLen.toFixed(4)), pieces: takePieces, cut_length_ft: Number((cutLen * takePieces).toFixed(4)), location_id_snapshot: l.location_id || ''});
            remaining -= cutLen * takePieces;
          }
        });
        drawManual(next);
      });
      return;
    }
    manualWrap.innerHTML = '';
  };
  drawCuts(existingCuts);
  drawManual(existingAllocations);
  suggestBtn?.addEventListener('click', () => {
    const plan = buildCuttablePlan(lots, Math.max(0, Math.floor(pieces)), Math.max(0, cutLen));
    if (modeInput) modeInput.value = 'suggested';
    drawCuts(plan);
    drawManual(existingAllocations);
  });
  manualToggle?.addEventListener('click', () => {
    if (!modeInput) return;
    modeInput.value = modeInput.value === 'manual' ? 'suggested' : 'manual';
    manualToggle.textContent = modeInput.value === 'manual' ? 'Use suggested cutting' : 'Manual lot cutting';
    renderCuttablePanel(tr);
  });
  manualToggle.textContent = (modeInput?.value || 'suggested') === 'manual' ? 'Use suggested cutting' : 'Manual lot cutting';
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
    const sourceSelect = tr.querySelector('.source-location-select');
    if (sourceSelect) {
      const existing = sourceSelect.value || '';
      sourceSelect.innerHTML = '<option value="">Consume from location</option>' + LOCATION_OPTIONS.map(loc => `<option value="${loc.id}">${loc.name}</option>`).join('');
      if (existing) sourceSelect.value = existing;
    }
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
    const sourceSelect = tr.querySelector('.source-location-select');
    if (sourceSelect) {
      const existing = sourceSelect.value || '';
      sourceSelect.innerHTML = '<option value="">Consume from location</option>' + LOCATION_OPTIONS.map(loc => `<option value="${loc.id}">${loc.name}</option>`).join('');
      if (existing) sourceSelect.value = existing;
    }
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
  const quotationTbody = document.querySelector('#dc-lines-quotation tbody');
  const extraTbody = document.querySelector('#dc-lines-extra tbody');
  const createLine = () => {
    const tr = document.createElement('tr');
    tr.className = 'dc-line-row';
    tr.innerHTML = `<td class="sr-col"></td><td><input type="hidden" name="line_id[]" value="line_${Math.random().toString(16).slice(2)}"><input type="hidden" name="line_origin[]" class="line-origin" value="extra"><input type="hidden" name="line_packing_line_id[]" class="line-packing-line-id" value=""><select name="line_component_id[]" class="component-select">${componentOptions}</select><select name="line_variant_id[]" class="variant-select" style="margin-top:6px"><option value="">N/A</option></select><input name="line_notes[]" placeholder="Description / notes" style="margin-top:6px"><div class="small muted stock-hint" style="margin-top:6px"></div><div class="small" style="margin-top:6px;color:#b91c1c"></div><div class="cuttable-panel small" style="margin-top:6px"></div></td><td class="mono"><input class="hsn-display" readonly></td><td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="0"><input type="number" step="1" min="0" name="line_pieces[]" class="pieces-input" value="0" style="margin-top:6px"><select name="line_source_location_id[]" class="source-location-select" style="margin-top:6px"><option value="">Consume from location</option></select><input type="hidden" name="line_selected_lot_ids[]" class="line-selected-lot-ids" value="[]"><input type="hidden" name="line_lot_cuts[]" class="line-lot-cuts-input" value="[]"><input type="hidden" name="line_cut_plan_mode[]" class="line-cut-plan-mode" value="suggested"><input type="hidden" name="line_lot_allocations[]" class="line-lot-allocations-input" value="[]"></td><td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="0"></td><td class="row-actions"><button type="button" class="btn warn remove-line">×</button></td>`;
    return tr;
  };

  document.querySelectorAll('.dc-line-row').forEach(wireRow);
  document.getElementById('add-extra-line')?.addEventListener('click', () => { const row=createLine(); extraTbody.appendChild(row); wireRow(row); });

  document.querySelectorAll('.add-quotation-line').forEach(btn => btn.addEventListener('click', () => {
    const payload = JSON.parse(btn.dataset.payload || '{}');
    const row = createLine();
    quotationTbody.appendChild(row);
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

  document.querySelector('form.card')?.addEventListener('submit', (e) => {
    let blocked = false;
    document.querySelectorAll('.dc-line-row').forEach((tr) => {
      const comp = tr.querySelector('.component-select');
      const c = COMPONENTS[comp?.value || ''] || null;
      if (!c || !c.is_cuttable) return;
      const mode = tr.querySelector('.line-cut-plan-mode')?.value || 'suggested';
      if (mode !== 'manual') return;
      const variant = tr.querySelector('.variant-select');
      const bucket = CUTTABLE_STOCK[stockKey(comp?.value || '', c.has_variants ? (variant?.value || '') : '')] || { lots: [] };
      const lotMeta = getLotMeta(bucket.lots || []);
      const allocations = readJsonArray(tr.querySelector('.line-lot-allocations-input')?.value || '[]');
      allocations.forEach((a) => {
        const rem = Number((lotMeta[a.lot_id] || {}).remaining_length_ft || 0);
        const pieceLength = Number(a.piece_length_ft || a.cut_length_ft || 0);
        const pcs = Math.max(1, Number(a.pieces || a.cut_pieces || 1));
        const val = pieceLength * pcs;
        if (!a.lot_id || pieceLength <= 0 || val <= 0 || val > rem) {
          blocked = true;
        }
      });
      if (allocations.length === 0) blocked = true;
    });
    if (blocked) {
      e.preventDefault();
      alert('Manual lot cuts have invalid values. Ensure each row has Piece length > 0, Pieces >= 1, and total cut <= that lot remaining length.');
    }
  });
} else {
  document.querySelectorAll('.dc-line-row').forEach(wireRow);
}
refreshSr();
</script>
</body></html>
