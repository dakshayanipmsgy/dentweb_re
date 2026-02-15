<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/documents_helpers.php';
$publicMode = defined('QUOTE_PUBLIC_MODE') && QUOTE_PUBLIC_MODE === true;
if (!$publicMode) {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/employee_portal.php';
    require_once __DIR__ . '/includes/employee_admin.php';
}

documents_ensure_structure();
$employeeStore = !$publicMode ? new EmployeeFsStore() : null;

$viewerType = '';
$viewerId = '';
$user = !$publicMode ? current_user() : null;
if (!$publicMode && is_array($user) && (($user['role_name'] ?? '') === 'admin')) {    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
} else {
    $employee = !$publicMode ? employee_portal_current_employee($employeeStore) : null;
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
    }
}
if ($publicMode) {
    $viewerType = 'public';
} elseif ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$redirect = static function (string $id, string $type, string $message): void {
    header('Location: quotation-view.php?' . http_build_query(['id' => $id, 'status' => $type, 'message' => $message]));
    exit;
};

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}
if (!$publicMode && $viewerType === 'employee' && ((string) ($quote['created_by_type'] ?? '') !== 'employee' || (string) ($quote['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!$publicMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect($id, 'error', 'Security validation failed.');
    }

    $action = safe_text($_POST['action'] ?? '');
    if ($viewerType !== 'admin' && in_array($action, ['approve_quote', 'accept_quote', 'enable_share', 'disable_share'], true)) {
        $redirect($id, 'error', 'Only admin can perform this action.');
    }

    if ($action === 'archive_quote') {
        $quote['status'] = 'Archived';
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect($id, 'success', 'Quotation archived.');
    }

    if ($action === 'enable_share') {
        $share = is_array($quote['share'] ?? null) ? $quote['share'] : [];
        $share['enabled'] = true;
        $share['token'] = safe_text((string) ($share['token'] ?? '')) ?: documents_generate_share_token(24);
        $share['enabled_at'] = date('c');
        $quote['share'] = $share;
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect($id, 'success', 'Share link enabled.');
    }

    if ($action === 'disable_share') {
        $share = is_array($quote['share'] ?? null) ? $quote['share'] : [];
        $share['enabled'] = false;
        $share['disabled_at'] = date('c');
        $quote['share'] = $share;
        $quote['updated_at'] = date('c');
        documents_save_quote($quote);
        $redirect($id, 'success', 'Share link disabled.');
    }

    if ($action === 'approve_quote') {
        $status = (string) ($quote['status'] ?? 'Draft');
        if (in_array($status, ['Approved', 'Accepted'], true)) {
            $redirect($id, 'success', 'Quotation already approved.');
        }

        $quote['status'] = 'Approved';
        $quote['approval']['approved_by_id'] = (string) ($user['id'] ?? '');
        $quote['approval']['approved_by_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['approval']['approved_at'] = date('c');
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            documents_log('file save failed during approve quote ' . (string) ($quote['id'] ?? ''));
            $redirect($id, 'error', 'Failed to approve quotation.');
        }
        $redirect($id, 'success', 'Quotation approved.');
    }

    if ($action === 'accept_quote') {
        if ((string) ($quote['status'] ?? '') !== 'Approved' && (string) ($quote['status'] ?? '') !== 'Accepted') {
            $redirect($id, 'error', 'Quotation must be Approved before acceptance.');
        }

        $valid = documents_quote_has_valid_acceptance_data($quote);
        if (!($valid['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($valid['error'] ?? 'Validation failed.'));
        }

        $customer = documents_upsert_customer_from_quote($quote);
        if (!($customer['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($customer['error'] ?? 'Customer creation failed.'));
        }

        $agreement = documents_create_agreement_from_quote($quote, is_array($user) ? $user : []);
        if (!($agreement['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($agreement['error'] ?? 'Agreement creation failed.'));
        }

        $proforma = documents_create_proforma_from_quote($quote);
        if (!($proforma['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($proforma['error'] ?? 'Proforma creation failed.'));
        }

        $invoice = documents_create_invoice_from_quote($quote);
        if (!($invoice['ok'] ?? false)) {
            $redirect($id, 'error', (string) ($invoice['error'] ?? 'Invoice creation failed.'));
        }

        $snapshot = documents_quote_resolve_snapshot($quote);
        $quote['links']['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
        $quote['links']['agreement_id'] = (string) ($agreement['agreement_id'] ?? ($quote['links']['agreement_id'] ?? ''));
        $quote['links']['proforma_id'] = (string) ($proforma['proforma_id'] ?? ($quote['links']['proforma_id'] ?? ''));
        $quote['links']['invoice_id'] = (string) ($invoice['invoice_id'] ?? ($quote['links']['invoice_id'] ?? ''));
        $quote['status'] = 'Accepted';
        $quote['acceptance']['accepted_by_admin_id'] = (string) ($user['id'] ?? '');
        $quote['acceptance']['accepted_by_admin_name'] = (string) ($user['full_name'] ?? 'Admin');
        $quote['acceptance']['accepted_at'] = date('c');
        $quote['acceptance']['accepted_note'] = safe_text($_POST['accepted_note'] ?? (string) ($quote['acceptance']['accepted_note'] ?? ''));
        $quote['updated_at'] = date('c');
        $saved = documents_save_quote($quote);
        if (!$saved['ok']) {
            documents_log('file save failed during accept quote ' . (string) ($quote['id'] ?? ''));
            $redirect($id, 'error', 'Generated documents but failed to update quotation.');
        }

        $redirect($id, 'success', 'Quotation accepted and linked drafts generated.');
    }
}

$editable = !$publicMode && documents_quote_can_edit($quote, $viewerType, $viewerId);
$editLink = ($viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php') . '?edit=' . urlencode((string) $quote['id']);
$backLink = $viewerType === 'admin' ? 'admin-quotations.php' : 'employee-quotations.php';
$statusMsg = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');
$snapshot = documents_quote_resolve_snapshot($quote);
$links = is_array($quote['links'] ?? null) ? $quote['links'] : [];
$visualDefaults = documents_get_quote_visual_defaults();
$watermark = is_array($visualDefaults['watermark'] ?? null) ? $visualDefaults['watermark'] : [];
$share = is_array($quote['share'] ?? null) ? $quote['share'] : ['enabled' => false, 'token' => ''];
$shareUrl = (isset($share['token']) && safe_text((string) $share['token']) !== '') ? ('quote.php?t=' . urlencode((string) $share['token'])) : '';
$proposal = documents_quote_resolve_proposal_inputs($quote);

$capacity = (float) ($quote['capacity_kwp'] ?: 0);
$total = (float) ($quote['calc']['grand_total'] ?? 0);
$subsidy = 0.0;
if ($capacity > 0 && $capacity <= 2) {
    $subsidy = 30000 * $capacity;
} elseif ($capacity > 2 && $capacity < 3) {
    $subsidy = 60000 + (($capacity - 2) * 18000);
} elseif ($capacity >= 3) {
    $subsidy = 78000;
}
$annualGen = $capacity * (float) $proposal['annual_generation_kwh_per_kw'];
$annualSaving = $annualGen * (float) $proposal['unit_rate_rs'];
$transport = (float) $proposal['transportation_rs'];
$netCost = max(0, $total + $transport - $subsidy);
$paybackYears = $annualSaving > 0 ? $netCost / $annualSaving : 0;

$remainingBill = (float) $proposal['monthly_bill_rs'] * ((float) $proposal['remaining_bill_percent_after_solar'] / 100);
$principal = $total * (1 - ((float) $proposal['down_payment_percent'] / 100));
$r = (float) $proposal['interest_rate_percent'] / 1200;
$n = max(1, (int) $proposal['tenure_years'] * 12);
$emi = 0;
if (!empty($proposal['financing_enabled']) && $principal > 0) {
    if ($r == 0.0) {
        $emi = $principal / $n;
    } else {
        $pow = pow(1 + $r, $n);
        $emi = ($principal * $r * $pow) / ($pow - 1);
    }
}

$withSolarMonthly = $emi + $remainingBill;
$withoutSolarMonthly = (float) $proposal['monthly_bill_rs'];
$co2Yr = $annualGen * (float) $proposal['emission_factor_kg_per_kwh'];
$treesYr = ((float) $proposal['tree_absorption_kg_per_year'] > 0) ? ($co2Yr / (float) $proposal['tree_absorption_kg_per_year']) : 0;
$years = max(1, (int) $proposal['analysis_years']);
$cumulativeWithout = [];
$cumulativeWith = [];
for ($i = 1; $i <= $years; $i++) {
    $cumulativeWithout[] = $withoutSolarMonthly * 12 * $i;
    $tenureMonthsInWindow = min($n, $i * 12);
    $withTotal = ($remainingBill * 12 * $i) + ($emi * $tenureMonthsInWindow) + ($total * ((float) $proposal['down_payment_percent'] / 100));
    if ($i >= 1) {
        $withTotal -= $subsidy;
    }
    $cumulativeWith[] = max(0, $withTotal);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Solar Proposal <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
:root{--brand:#0ea5a4;--brand2:#2563eb;--muted:#64748b;--bg:#f8fafc;--card:#fff}
body{font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#f0fdf4,#eff6ff);margin:0;color:#0f172a}.wrap{max-width:1100px;margin:auto;padding:18px}.card{background:var(--card);border-radius:16px;padding:16px;margin:0 0 14px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
.hero{background:linear-gradient(120deg,var(--brand),var(--brand2));color:#fff}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.kpi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.badge{display:inline-block;background:#ecfeff;color:#0f766e;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:700}
.btn{display:inline-block;background:#0f766e;color:#fff;border:none;border-radius:10px;padding:8px 12px;text-decoration:none;cursor:pointer}.btn.secondary{background:#fff;color:#334155;border:1px solid #cbd5e1}.muted{color:var(--muted);font-size:13px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left}
canvas{width:100%;height:240px;background:#fff;border:1px solid #e2e8f0;border-radius:12px}details{border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:8px;background:#fff}summary{font-weight:700;cursor:pointer}.timeline{display:flex;gap:8px;flex-wrap:wrap}.step{flex:1;min-width:150px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px}
.print-watermark{display:none}
@media print{.actions,.no-print{display:none!important}details{display:block}details>*{display:block!important}.print-watermark{display:block;position:fixed;top:15%;left:10%;right:10%;bottom:15%;z-index:0;background-repeat:no-repeat;background-position:center;background-size:contain;opacity:<?= (float) ($watermark['opacity'] ?? 0.12) ?>}main,*{position:relative;z-index:1}body{background:#fff}}
</style></head>
<body>
<div class="print-watermark" style="background-image:url('<?= htmlspecialchars((string) ($watermark['image_path'] ?? ''), ENT_QUOTES) ?>')"></div>
<main class="wrap">
<?php if ($message !== ''): ?><div class="card <?= $statusMsg === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>
<?php if (!$publicMode): ?><div class="card actions">
<a class="btn secondary" href="<?= htmlspecialchars($backLink, ENT_QUOTES) ?>">Back</a>
<?php if ($editable): ?><a class="btn secondary" href="<?= htmlspecialchars($editLink, ENT_QUOTES) ?>">Edit</a><?php endif; ?>
<?php if ($viewerType==='admin'): ?>
<form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="<?= !empty($share['enabled'])?'disable_share':'enable_share' ?>"><button class="btn secondary" type="submit"><?= !empty($share['enabled'])?'Disable Share Link':'Open Share Link' ?></button></form>
<?php endif; ?>
<?php if ($shareUrl !== ''): ?><button class="btn" onclick="navigator.clipboard.writeText(location.origin + '/<?= $shareUrl ?>')">Copy Share Link</button><?php endif; ?>
</div><?php endif; ?>

<section class="card hero">
<h1>☀️ Solar Proposal – <?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</h1>
<p><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?> · Quote <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></p>
<span class="badge">System: <?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></span>
</section>

<section class="card"><h2>💸 Your savings snapshot</h2><div class="grid">
<div class="kpi"><div class="muted">Current monthly bill</div><strong>₹<?= number_format($proposal['monthly_bill_rs'],0) ?></strong></div>
<div class="kpi"><div class="muted">Expected monthly saving</div><strong>₹<?= number_format(max(0,$withoutSolarMonthly-$withSolarMonthly),0) ?></strong></div>
<div class="kpi"><div class="muted">Subsidy expected</div><strong>₹<?= number_format($subsidy,0) ?></strong></div>
<div class="kpi"><div class="muted">Net cost after subsidy</div><strong>₹<?= number_format($netCost,0) ?></strong></div>
<div class="kpi"><div class="muted">Payback estimate</div><strong><?= number_format($paybackYears,1) ?> yrs</strong></div>
</div></section>

<section class="card"><h2>🔋 Your System at a Glance</h2><div class="grid"><div class="kpi">Capacity: <strong><?= htmlspecialchars((string)$quote['capacity_kwp'], ENT_QUOTES) ?> kWp</strong></div><div class="kpi">Expected annual generation: <strong><?= number_format($annualGen,0) ?> kWh</strong></div><div class="kpi">Warranty: <strong><?= htmlspecialchars((string)($quote['annexures_overrides']['warranty'] ?? 'As per quote'), ENT_QUOTES) ?></strong></div></div></section>

<section class="card"><h2>🧾 Pricing Summary</h2><table><tr><th>Total system price (GST incl.)</th><td>₹<?= number_format($total,2) ?></td></tr><tr><th>GST 70/30 split (5%/18%)</th><td>₹<?= number_format((float)$quote['calc']['bucket_5_gst'],2) ?> / ₹<?= number_format((float)$quote['calc']['bucket_18_gst'],2) ?></td></tr><tr><th>Transportation</th><td>₹<?= number_format($transport,2) ?></td></tr><tr><th>Subsidy expected</th><td>- ₹<?= number_format($subsidy,2) ?></td></tr><tr><th>Net out-of-pocket</th><td><strong>₹<?= number_format($netCost,2) ?></strong></td></tr></table></section>

<?php if (!empty($proposal['financing_enabled'])): ?>
<section class="card"><h2>📊 EMI feels like a bill replacement</h2><div class="grid"><div class="kpi">EMI estimate: <strong>₹<?= number_format($emi,0) ?>/mo</strong></div><div class="kpi">Remaining bill after solar: <strong>₹<?= number_format($remainingBill,0) ?>/mo</strong></div><div class="kpi">What you pay today vs after solar: <strong>₹<?= number_format($withoutSolarMonthly,0) ?> → ₹<?= number_format($withSolarMonthly,0) ?></strong></div></div></section>
<?php endif; ?>

<section class="card"><h2>📈 Savings Graphs</h2><div class="grid"><canvas id="bar"></canvas><canvas id="line"></canvas><canvas id="gauge"></canvas></div><p class="muted">Assumption: subsidy deducted once from first-year cumulative with-solar spend.</p></section>

<section class="card"><h2>🌱 Environmental Impact</h2><div class="grid"><div class="kpi">CO₂ saved / year: <strong><?= number_format($co2Yr/1000,2) ?> tons</strong></div><div class="kpi">CO₂ saved / 25 years: <strong><?= number_format(($co2Yr*25)/1000,2) ?> tons</strong></div><div class="kpi">Trees equivalent / year: <strong><?= number_format($treesYr,0) ?></strong></div><div class="kpi">Trees equivalent / 25 years: <strong><?= number_format($treesYr*25,0) ?></strong></div></div></section>

<section class="card"><h2>✅ Technical Credibility / Inclusions / PM Subsidy Info</h2>
<details open><summary>Inclusions</summary><p><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['system_inclusions'] ?? ''), ENT_QUOTES)) ?></p></details>
<details open><summary>PM Subsidy Info</summary><p><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['pm_subsidy_info'] ?? ''), ENT_QUOTES)) ?></p></details>
</section>

<section class="card"><h2>🗓️ Payment Terms + Timeline + Bank clarity</h2><div class="timeline"><div class="step">1️⃣ Booking<br><small><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['payment_terms'] ?? ''), ENT_QUOTES)) ?></small></div><div class="step">2️⃣ Installation<br><small>Fast execution with verified team.</small></div><div class="step">3️⃣ Net-metering & handover<br><small>Support till commissioning.</small></div></div></section>
<section class="card"><h2>🛡️ Warranty</h2><p><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['warranty'] ?? ''), ENT_QUOTES)) ?></p></section>
<section class="card"><h2>📜 Terms & Conditions</h2><p><?= nl2br(htmlspecialchars((string)($quote['annexures_overrides']['terms_conditions'] ?? ''), ENT_QUOTES)) ?></p></section>
<section class="card no-print"><h2>👉 Next Steps</h2><p>Review your proposal, discuss any changes, and confirm when ready. We’ll take care of the rest 🚀</p></section>

</main>
<script>
const withoutSolar = <?= json_encode($withoutSolarMonthly) ?>;
const withSolar = <?= json_encode($withSolarMonthly) ?>;
const cumWout = <?= json_encode($cumulativeWithout) ?>;
const cumWith = <?= json_encode($cumulativeWith) ?>;
const payback = <?= json_encode($paybackYears) ?>;
function drawBar(){const c=document.getElementById('bar'),x=c.getContext('2d');c.width=420;c.height=240;x.clearRect(0,0,c.width,c.height);const max=Math.max(withoutSolar,withSolar,1);const vals=[withoutSolar,withSolar],labs=['Without solar','With solar'];vals.forEach((v,i)=>{const h=(v/max)*150;x.fillStyle=i===0?'#ef4444':'#10b981';x.fillRect(70+i*160,200-h,80,h);x.fillStyle='#0f172a';x.fillText('₹'+Math.round(v),70+i*160,195-h);x.fillText(labs[i],58+i*160,220);});x.fillText('Monthly spend comparison',120,20);} 
function drawLine(){const c=document.getElementById('line'),x=c.getContext('2d');c.width=420;c.height=240;x.clearRect(0,0,c.width,c.height);const max=Math.max(...cumWout,...cumWith,1),n=cumWout.length;function pl(arr,color){x.beginPath();x.strokeStyle=color;x.lineWidth=3;arr.forEach((v,i)=>{const px=40+(i/(n-1||1))*340,py=200-(v/max)*150;i?x.lineTo(px,py):x.moveTo(px,py);});x.stroke();}pl(cumWout,'#ef4444');pl(cumWith,'#0ea5a4');x.fillStyle='#0f172a';x.fillText('Cumulative spend over years',120,20);} 
function drawGauge(){const c=document.getElementById('gauge'),x=c.getContext('2d');c.width=420;c.height=240;x.clearRect(0,0,c.width,c.height);const cx=210,cy=190,r=120,max=12,val=Math.min(payback,max);x.lineWidth=16;x.strokeStyle='#e2e8f0';x.beginPath();x.arc(cx,cy,r,Math.PI,0);x.stroke();x.strokeStyle='#0ea5a4';x.beginPath();x.arc(cx,cy,r,Math.PI,Math.PI+(val/max)*Math.PI);x.stroke();x.fillStyle='#0f172a';x.font='20px Arial';x.fillText((payback||0).toFixed(1)+' yrs',170,170);x.font='14px Arial';x.fillText('Self-financed payback',145,195);} 
drawBar();drawLine();drawGauge();
</script>
</body></html>
