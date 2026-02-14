<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_records.php';
require_once __DIR__ . '/includes/documents_quotations.php';

require_admin();
documents_ensure_structure();
documents_seed_template_sets_if_empty();
documents_seed_template_blocks_if_missing();
documents_ensure_dir(documents_quotations_dir());

$redirect = static function (string $type, string $message): void {
    header('Location: admin-quotations.php?' . http_build_query(['status' => $type, 'message' => $message]));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($action === 'create_quote') {
        $templateId = safe_text($_POST['template_set_id'] ?? '');
        $templates = documents_load_template_sets();
        $template = null;
        foreach ($templates as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $templateId) {
                $template = $row;
                break;
            }
        }
        if (!$template || !empty($template['archived_flag'])) {
            $redirect('error', 'Please choose a valid template set.');
        }

        $segment = safe_text($template['segment'] ?? 'RES');
        $capacity = safe_text($_POST['capacity_kwp'] ?? '');
        $totalInclusive = (float) ($_POST['input_total_gst_inclusive'] ?? 0);
        if ($capacity === '' || $totalInclusive <= 0) {
            $redirect('error', 'Capacity kWp and GST-inclusive total are required.');
        }

        $company = json_load(documents_settings_dir() . '/company_profile.json', documents_company_profile_defaults());
        $company = array_merge(documents_company_profile_defaults(), is_array($company) ? $company : []);
        $placeOfSupply = safe_text($_POST['place_of_supply_state'] ?? 'Jharkhand');
        $taxType = documents_quote_default_tax_type($placeOfSupply, (string) ($company['state'] ?: 'Jharkhand'));
        if (safe_text($_POST['tax_type'] ?? '') === 'IGST') {
            $taxType = 'IGST';
        }

        $number = documents_next_quote_number($segment);
        if (!$number['ok']) {
            $redirect('error', (string) $number['error']);
        }

        $store = new CustomerRecordStore();
        $mobile = normalize_customer_mobile(safe_text($_POST['customer_mobile'] ?? ''));
        $found = $store->findByMobile($mobile);

        $partyType = safe_text($_POST['party_type'] ?? 'customer');
        $blocks = documents_load_template_blocks();
        $block = is_array($blocks[$templateId] ?? null) ? $blocks[$templateId] : [];
        $annexureKeys = ['system_inclusions', 'payment_terms', 'warranty', 'system_type_explainer', 'transportation', 'terms_conditions'];
        $ann = [];
        foreach ($annexureKeys as $key) {
            $manual = safe_text($_POST['annex_' . $key] ?? '');
            $ann[$key] = $manual !== '' ? $manual : (string) ($block[$key] ?? '');
        }

        $pricingMode = safe_text($_POST['pricing_mode'] ?? 'solar_split_70_30');
        if (!in_array($pricingMode, ['solar_split_70_30', 'flat_5', 'itemized'], true)) {
            $pricingMode = 'solar_split_70_30';
        }
        if ($pricingMode === 'itemized') {
            $pricingMode = 'solar_split_70_30';
        }

        $id = 'qtn_' . bin2hex(random_bytes(6));
        $now = date('c');
        $theme = is_array($template['default_doc_theme'] ?? null) ? $template['default_doc_theme'] : [];

        $quote = [
            'id' => $id,
            'quote_no' => (string) $number['quote_no'],
            'revision' => 0,
            'status' => 'Draft',
            'template_set_id' => $templateId,
            'segment' => $segment,
            'created_by_type' => 'admin',
            'created_by_id' => (string) (current_user()['id'] ?? ''),
            'created_by_name' => (string) (current_user()['full_name'] ?? 'Administrator'),
            'created_at' => $now,
            'updated_at' => $now,
            'party_type' => $partyType,
            'customer_mobile' => $mobile,
            'customer_name' => safe_text($_POST['customer_name'] ?? '') ?: (string) ($found['full_name'] ?? ''),
            'billing_address' => safe_text($_POST['billing_address'] ?? '') ?: (string) ($found['address_line'] ?? ''),
            'site_address' => safe_text($_POST['site_address'] ?? '') ?: (string) ($found['address_line'] ?? ''),
            'district' => safe_text($_POST['district'] ?? '') ?: (string) ($found['district'] ?? ''),
            'city' => safe_text($_POST['city'] ?? ''),
            'state' => safe_text($_POST['state'] ?? 'Jharkhand'),
            'pin' => safe_text($_POST['pin'] ?? '') ?: (string) ($found['pin_code'] ?? ''),
            'system_type' => safe_text($_POST['system_type'] ?? 'Ongrid'),
            'capacity_kwp' => $capacity,
            'project_summary_line' => safe_text($_POST['project_summary_line'] ?? ''),
            'valid_until' => safe_text($_POST['valid_until'] ?? date('Y-m-d', strtotime('+15 days'))),
            'pricing_mode' => $pricingMode,
            'place_of_supply_state' => $placeOfSupply,
            'tax_type' => $taxType,
            'input_total_gst_inclusive' => documents_round2($totalInclusive),
            'calc' => documents_calculate_quote($pricingMode, $totalInclusive, $taxType),
            'special_requests_inclusive' => safe_text($_POST['special_requests_inclusive'] ?? ''),
            'special_requests_override_note' => true,
            'annexures_overrides' => $ann,
            'rendering' => [
                'background_image' => (string) ($theme['page_background_image'] ?? ''),
                'background_opacity' => (float) ($theme['page_background_opacity'] ?? 1),
            ],
        ];

        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            $redirect('error', 'Unable to save quotation.');
        }

        header('Location: quotation-view.php?id=' . urlencode($id));
        exit;
    }
}

$templates = array_values(array_filter(documents_load_template_sets(), static fn(array $row): bool => empty($row['archived_flag'])));
$quotes = documents_list_quotes();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Quotations</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0}.wrap{padding:18px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}input,select,textarea{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}textarea{min-height:80px}.btn{background:#1d4ed8;color:#fff;border:none;padding:8px 12px;border-radius:8px;text-decoration:none;display:inline-block;cursor:pointer}.muted{color:#64748b;font-size:13px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}th{background:#f8fafc}</style>
</head>
<body><main class="wrap">
<div class="card"><h1>Quotations</h1><a class="btn" href="admin-documents.php">Documents Control Center</a></div>
<?php if (!empty($_GET['message'])): ?><div class="card" style="color:<?= safe_text($_GET['status'] ?? '') === 'error' ? '#b91c1c' : '#065f46' ?>"><?= htmlspecialchars(safe_text($_GET['message'] ?? ''), ENT_QUOTES) ?></div><?php endif; ?>
<div class="card"><h2>Create quotation</h2>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>"><input type="hidden" name="action" value="create_quote">
<div class="grid">
<div><label>Template set</label><select name="template_set_id" required><?php foreach ($templates as $t): ?><option value="<?= htmlspecialchars((string) ($t['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
<div><label>Party Type</label><select name="party_type"><option value="customer">Customer</option><option value="lead">Lead</option></select></div>
<div><label>Mobile</label><input name="customer_mobile" required></div>
<div><label>Name</label><input name="customer_name"></div>
<div><label>System Type</label><select name="system_type"><option>Ongrid</option><option>Hybrid</option><option>Offgrid</option><option>Product</option></select></div>
<div><label>Capacity kWp</label><input name="capacity_kwp" required></div>
<div><label>Valid Until</label><input type="date" name="valid_until" value="<?= date('Y-m-d', strtotime('+15 days')) ?>"></div>
<div><label>Pricing Mode</label><select name="pricing_mode"><option value="solar_split_70_30">Solar split 70/30</option><option value="flat_5">Flat 5%</option></select></div>
<div><label>Total (GST inclusive)</label><input type="number" min="0" step="0.01" name="input_total_gst_inclusive" required></div>
<div><label>Place of Supply State</label><input name="place_of_supply_state" value="Jharkhand"></div>
<div><label>Tax Type (optional override)</label><select name="tax_type"><option value="">Auto</option><option value="CGST_SGST">CGST+SGST</option><option value="IGST">IGST</option></select></div>
<div><label>District</label><input name="district"></div>
<div><label>City</label><input name="city"></div>
<div><label>State</label><input name="state" value="Jharkhand"></div>
<div><label>PIN</label><input name="pin"></div>
<div><label>Project Summary</label><input name="project_summary_line"></div>
<div style="grid-column:1/-1"><label>Billing Address</label><textarea name="billing_address"></textarea></div>
<div style="grid-column:1/-1"><label>Site Address</label><textarea name="site_address"></textarea></div>
<div style="grid-column:1/-1"><label>Special Requests From Customer (Inclusive in the rate)</label><textarea name="special_requests_inclusive"></textarea><div class="muted">In case of conflict, Special Requests will be given priority over Annexure inclusions.</div></div>
<?php foreach (['system_inclusions','payment_terms','warranty','system_type_explainer','transportation','terms_conditions'] as $k): ?><div style="grid-column:1/-1"><label>Annexure override: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $k)), ENT_QUOTES) ?></label><textarea name="annex_<?= htmlspecialchars($k, ENT_QUOTES) ?>"></textarea></div><?php endforeach; ?>
</div><p><button class="btn" type="submit">Create Quotation</button></p></form></div>
<div class="card"><h2>Quotation list</h2><table><thead><tr><th>Quote No</th><th>Name</th><th>Status</th><th>Created By</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach ($quotes as $q): ?><tr><td><?= htmlspecialchars((string) ($q['quote_no'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($q['customer_name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($q['status'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($q['created_by_name'] ?? ''), ENT_QUOTES) ?></td><td><?= htmlspecialchars((string) ($q['created_at'] ?? ''), ENT_QUOTES) ?></td><td><a class="btn" href="quotation-view.php?id=<?= urlencode((string) ($q['id'] ?? '')) ?>">View</a></td></tr><?php endforeach; ?>
</tbody></table></div>
</main></body></html>
