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
    ];
}

$redirectWith = static function (string $status, string $message) use ($id): void {
    header('Location: challan-view.php?id=' . urlencode($id) . '&status=' . urlencode($status) . '&message=' . urlencode($message));
    exit;
};

$consumeFromSpecificLots = static function (array $lots, float $requiredFt, array $selectedLotIds): array {
    if ($requiredFt <= 0) {
        return ['ok' => true, 'lots' => $lots, 'lot_consumption' => []];
    }
    $remaining = $requiredFt;
    $consumption = [];
    foreach ($lots as $idx => $lot) {
        if (!is_array($lot)) {
            continue;
        }
        $lotId = (string) ($lot['lot_id'] ?? '');
        if ($lotId === '' || !in_array($lotId, $selectedLotIds, true)) {
            continue;
        }
        $available = max(0, (float) ($lot['remaining_ft'] ?? 0));
        if ($available <= 0) {
            continue;
        }
        $take = min($available, $remaining);
        if ($take <= 0) {
            continue;
        }
        $lots[$idx]['remaining_ft'] = max(0, $available - $take);
        $remaining -= $take;
        $consumption[] = [
            'lot_id' => $lotId,
            'consumed_ft' => $take,
            'location_id' => (string) ($lot['location_id'] ?? ''),
        ];
        if ($remaining <= 0.00001) {
            break;
        }
    }
    if ($remaining > 0.00001) {
        return ['ok' => false, 'error' => 'Selected lots do not have enough balance.', 'lots' => $lots, 'lot_consumption' => $consumption];
    }
    return ['ok' => true, 'lots' => $lots, 'lot_consumption' => $consumption];
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
                if ($isCuttable && $lengthFt <= 0) {
                    continue;
                }
                if (!$isCuttable && $qty <= 0) {
                    continue;
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
                $variantName = '';
                if ($variantId !== '') {
                    $variantName = (string) (($variantMap[$variantId]['display_name'] ?? $variantId));
                }
                $lines[] = [
                    'line_id' => safe_text((string) ($lineIds[$idx] ?? '')) ?: ('line_' . bin2hex(random_bytes(4))),
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

            // backward-compatible items snapshot
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

                if (!empty($line['is_cuttable_snapshot'])) {
                    $needFt = max(0, (float) ($line['length_ft'] ?? 0));
                    if ($needFt <= 0) {
                        continue;
                    }
                    if ($needFt > documents_inventory_total_remaining_ft($entry) + 0.00001) {
                        $redirectWith('error', 'Insufficient cuttable stock for ' . (string) ($line['component_name_snapshot'] ?? 'component') . '.');
                    }
                    $selectedLotIds = is_array($line['lot_ids'] ?? null) ? $line['lot_ids'] : [];
                    if ($selectedLotIds !== []) {
                        $consume = $consumeFromSpecificLots((array) ($entry['lots'] ?? []), $needFt, $selectedLotIds);
                    } else {
                        $consume = documents_inventory_consume_fifo_lots((array) ($entry['lots'] ?? []), $needFt);
                    }
                    if (!($consume['ok'] ?? false)) {
                        $redirectWith('error', (string) ($consume['error'] ?? 'Unable to consume lots for cuttable stock.'));
                    }
                    $entry['lots'] = (array) ($consume['lots'] ?? []);
                    $tx['length_ft'] = $needFt;
                    $tx['lot_consumption'] = (array) ($consume['lot_consumption'] ?? []);
                } else {
                    $needQty = max(0, (float) ($line['qty'] ?? 0));
                    if ($needQty <= 0) {
                        continue;
                    }
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
                }

                $entry['updated_at'] = date('c');
                documents_inventory_set_component_stock($stock, $componentId, $variantId, $entry);
                documents_inventory_append_transaction($tx);
                $txnIds[] = $txId;
            }
            $savedStock = documents_inventory_save_stock($stock);
            if (!($savedStock['ok'] ?? false)) {
                $redirectWith('error', 'Failed to update inventory stock.');
            }
            $challan['inventory_txn_ids'] = $txnIds;
            $challan['status'] = 'final';
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
            documents_log('Challan update failed for ' . (string) ($challan['id'] ?? '') . ': ' . (string) ($saved['error'] ?? 'Unknown error'));
            $redirectWith('error', 'Unable to save delivery challan.');
        }

        $successMessage = 'DC updated.';
        if ($action === 'save_draft') {
            $successMessage = 'DC draft saved.';
        } elseif ($action === 'finalize') {
            $successMessage = 'DC finalized and inventory updated.';
        } elseif ($action === 'archive') {
            $successMessage = 'DC archived.';
        }
        $redirectWith('success', $successMessage);
    }
}

$backLink = (is_array($user) && (($user['role_name'] ?? '') === 'admin')) ? 'admin-documents.php?tab=accepted_customers&view=' . urlencode((string) ($challan['quote_id'] ?? $challan['linked_quote_id'] ?? '')) : 'employee-challans.php';
$statusParam = safe_text($_GET['status'] ?? '');
$messageParam = safe_text($_GET['message'] ?? '');
$editable = (string) ($challan['status'] ?? 'draft') === 'draft';
$stockSnapshot = documents_inventory_load_stock();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Delivery Challan Builder</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f7fb;color:#111;margin:0}.wrap{max-width:1200px;margin:20px auto;padding:0 14px}.card{background:#fff;border:1px solid #d9e1ec;border-radius:12px;padding:14px;margin-bottom:12px}.btn{display:inline-block;background:#0b57d0;color:#fff;border:1px solid #0b57d0;padding:8px 12px;border-radius:8px;text-decoration:none;cursor:pointer}.btn.secondary{background:#fff;color:#0b57d0}.btn.warn{background:#b91c1c;border-color:#b91c1c}.muted{color:#555}label{display:block;font-size:12px;margin-bottom:4px}input,select,textarea{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ee;padding:8px;vertical-align:top}thead th{background:#f1f5f9}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.badge{padding:2px 8px;border-radius:999px;font-size:12px}.draft{background:#fff7ed;color:#9a3412}.final{background:#dcfce7;color:#166534}.archived{background:#e2e8f0;color:#334155}.row-actions{display:flex;gap:6px;align-items:center}
</style></head>
<body><main class="wrap">
<div class="card">
  <h1 style="margin:0 0 8px">Delivery Challan</h1>
  <p><strong><?= htmlspecialchars((string) ($challan['dc_number'] ?: $challan['challan_no']), ENT_QUOTES) ?></strong> · Status:
  <span class="badge <?= htmlspecialchars((string) ($challan['status'] ?? 'draft'), ENT_QUOTES) ?>"><?= htmlspecialchars(strtoupper((string) ($challan['status'] ?? 'draft')), ENT_QUOTES) ?></span></p>
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

<h3>DC Lines</h3>
<table id="dc-lines"><thead><tr><th style="width:18%">Component</th><th style="width:14%">Variant</th><th style="width:8%">Qty</th><th style="width:10%">Length(ft)</th><th style="width:9%">Pieces</th><th style="width:17%">Lot Selection</th><th>Notes</th><th style="width:10%">Actions</th></tr></thead><tbody>
<?php foreach ((array) ($challan['lines'] ?? []) as $lineIdx => $line): if (!is_array($line)) { continue; } $componentId=(string)($line['component_id']??''); $variantId=(string)($line['variant_id']??''); $entry=documents_inventory_component_stock($stockSnapshot,$componentId,$variantId); ?>
<tr class="dc-line-row">
<td>
<input type="hidden" name="line_id[]" value="<?= htmlspecialchars((string) ($line['line_id'] ?? ''), ENT_QUOTES) ?>" />
<select name="line_component_id[]" class="component-select" <?= $editable ? '' : 'disabled' ?>><option value="">Select</option><?php foreach ($componentMap as $cmpId => $cmp): ?><option value="<?= htmlspecialchars($cmpId, ENT_QUOTES) ?>" <?= $cmpId===$componentId?'selected':'' ?>><?= htmlspecialchars((string) ($cmp['name'] ?? $cmpId), ENT_QUOTES) ?></option><?php endforeach; ?></select>
</td>
<td><select name="line_variant_id[]" class="variant-select" <?= $editable ? '' : 'disabled' ?>><option value="">N/A</option><?php foreach ((array) ($variantsByComponent[$componentId] ?? []) as $variant): ?><option value="<?= htmlspecialchars((string) ($variant['id'] ?? ''), ENT_QUOTES) ?>" <?= ((string) ($variant['id'] ?? ''))===$variantId?'selected':'' ?>><?= htmlspecialchars((string) ($variant['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
<td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="<?= htmlspecialchars((string) ((float) ($line['qty'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="<?= htmlspecialchars((string) ((float) ($line['length_ft'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td><input type="number" step="1" min="0" name="line_pieces[]" value="<?= htmlspecialchars((string) ((int) ($line['pieces'] ?? 0)), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td>
<?php foreach ((array) ($entry['lots'] ?? []) as $lot): $lotId=(string)($lot['lot_id']??''); if ($lotId==='') { continue; } ?>
<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="line_lot_ids[<?= (int) $lineIdx ?>][]" value="<?= htmlspecialchars($lotId, ENT_QUOTES) ?>" <?= in_array($lotId, (array) ($line['lot_ids'] ?? []), true) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>><?= htmlspecialchars($lotId . ' (' . (string) ($lot['remaining_ft'] ?? 0) . 'ft)', ENT_QUOTES) ?></label>
<?php endforeach; ?>
</td>
<td><input name="line_notes[]" value="<?= htmlspecialchars((string) ($line['notes'] ?? ''), ENT_QUOTES) ?>" <?= $editable ? '' : 'disabled' ?>></td>
<td class="row-actions"><?php if ($editable): ?><button type="button" class="btn secondary duplicate-line">+</button><button type="button" class="btn warn remove-line">×</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if ($editable): ?><p style="margin-top:8px"><button type="button" id="add-line" class="btn secondary">+ Add Line</button></p><?php endif; ?>

<div class="row-actions" style="margin-top:12px">
<?php if ($editable): ?><button class="btn secondary" type="submit" name="action" value="save_draft">Save Draft</button><button class="btn" type="submit" name="action" value="finalize">Finalize DC</button><?php endif; ?>
<?php if ((string) ($challan['status'] ?? '') !== 'archived'): ?><button class="btn warn" type="submit" name="action" value="archive">Archive</button><?php endif; ?>
</div>
</form>
</main>
<script>
const COMPONENTS = <?= json_encode($componentClientMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const VARIANTS = <?= json_encode($variantsByComponent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const editable = <?= $editable ? 'true' : 'false' ?>;
if (editable) {
  const tbody = document.querySelector('#dc-lines tbody');
  const templateRow = () => {
    const tr = document.createElement('tr');
    tr.className = 'dc-line-row';
    tr.innerHTML = `<td><input type="hidden" name="line_id[]" value="line_${Math.random().toString(16).slice(2)}"/><select name="line_component_id[]" class="component-select"><option value="">Select</option>${Object.values(COMPONENTS).map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}</select></td><td><select name="line_variant_id[]" class="variant-select"><option value="">N/A</option></select></td><td><input type="number" step="0.01" min="0" name="line_qty[]" class="qty-input" value="0"></td><td><input type="number" step="0.01" min="0" name="line_length_ft[]" class="length-input" value="0"></td><td><input type="number" step="1" min="0" name="line_pieces[]" value="0"></td><td class="lot-cell"></td><td><input name="line_notes[]" value=""></td><td class="row-actions"><button type="button" class="btn secondary duplicate-line">+</button><button type="button" class="btn warn remove-line">×</button></td>`;
    return tr;
  };

  const wireRow = (tr) => {
    const componentSelect = tr.querySelector('.component-select');
    const variantSelect = tr.querySelector('.variant-select');
    const qtyInput = tr.querySelector('.qty-input');
    const lengthInput = tr.querySelector('.length-input');

    const refreshForComponent = () => {
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
      if (isCuttable) { qtyInput.value = '0'; }
      if (!isCuttable) { lengthInput.value = '0'; }
    };

    componentSelect.addEventListener('change', refreshForComponent);
    tr.querySelector('.duplicate-line')?.addEventListener('click', () => {
      const clone = templateRow();
      tbody.appendChild(clone);
      clone.querySelector('.component-select').value = componentSelect.value;
      wireRow(clone);
      clone.querySelector('.component-select').dispatchEvent(new Event('change'));
      clone.querySelector('.variant-select').value = variantSelect.value;
      clone.querySelector('.qty-input').value = '0';
      clone.querySelector('.length-input').value = '0';
    });
    tr.querySelector('.remove-line')?.addEventListener('click', () => tr.remove());
    refreshForComponent();
  };

  document.querySelectorAll('.dc-line-row').forEach(wireRow);
  document.getElementById('add-line')?.addEventListener('click', () => {
    const row = templateRow();
    tbody.appendChild(row);
    wireRow(row);
  });
}
</script>
</body></html>
