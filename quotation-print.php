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
$quote = documents_get_quote($id);
if ($quote === null) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($quote['created_by_id'] ?? '') !== $viewerId || (string) ($quote['created_by_type'] ?? '') !== 'employee')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$company = array_merge(documents_company_profile_defaults(), json_load(documents_settings_dir() . '/company_profile.json', []));
$docTheme = documents_get_doc_theme();
$resolvedTheme = documents_resolve_rendering_theme(is_array($quote['rendering'] ?? null) ? $quote['rendering'] : []);
$qDefaults = $docTheme['quotation_defaults'];
$calcDefaults = $docTheme['calc_defaults'];

$snapshot = documents_quote_resolve_snapshot($quote);
$capacity = max(0.0, (float) ($quote['capacity_kwp'] ?? 0));
$projectCost = max(0.0, (float) (($quote['calc']['grand_total'] ?? 0)));
$isPmTemplate = str_contains(strtolower((string) ($quote['template_set_id'] ?? '')), 'pm') && (string) ($quote['segment'] ?? '') === 'RES';
$subsidy = 0.0;
if ($isPmTemplate) {
    if ($capacity >= 3) {
        $subsidy = 78000;
    } elseif ($capacity >= 2) {
        $subsidy = 60000;
    }
}

$finance = is_array($quote['finance'] ?? null) ? $quote['finance'] : [];
$val = static function (string $k) use ($finance, $calcDefaults): float {
    $v = $finance[$k] ?? null;
    if ($v === null || $v === '') {
        return (float) ($calcDefaults[$k] ?? 0);
    }
    return (float) $v;
};
$financeMode = in_array((string) ($finance['finance_mode'] ?? ''), ['loan', 'cash'], true) ? (string) $finance['finance_mode'] : (string) ($calcDefaults['finance_mode'] ?? 'loan');
$analysisYears = max(1, (int) $val('analysis_years'));
$unitRate = $val('unit_rate_rs');
$annualGenPerKw = $val('annual_gen_per_kw');
$billEscalation = $val('bill_escalation_pct') / 100;
$loanInterest = $val('loan_interest_pct') / 1200;
$loanYears = max(1, (int) $val('loan_tenure_years'));
$downPaymentPct = $val('down_payment_pct') / 100;
$residualPct = $val('residual_bill_pct') / 100;
$monthlyBill = (float) ($finance['current_monthly_bill_rs'] ?? 0);
$annualGen = $capacity * $annualGenPerKw;

$without = [];
$with = [];
$cumWithout = 0.0;
$cumWith = 0.0;
$yearlyBase = $monthlyBill * 12;
$loanPrincipal = max(0.0, ($projectCost - $subsidy) * (1 - $downPaymentPct));
$months = $loanYears * 12;
$emi = 0.0;
if ($financeMode === 'loan' && $loanPrincipal > 0) {
    if ($loanInterest > 0) {
        $emi = $loanPrincipal * $loanInterest * pow(1 + $loanInterest, $months) / (pow(1 + $loanInterest, $months) - 1);
    } else {
        $emi = $loanPrincipal / max(1, $months);
    }
}

for ($y = 1; $y <= $analysisYears; $y++) {
    $withoutYear = $yearlyBase * pow(1 + $billEscalation, $y - 1);
    $cumWithout += $withoutYear;
    $without[] = $cumWithout;

    $residual = ($yearlyBase * $residualPct) * pow(1 + $billEscalation, $y - 1);
    if ($financeMode === 'loan') {
        $emiYear = $y <= $loanYears ? ($emi * 12) : 0;
        $withYear = $emiYear + $residual;
    } else {
        $withYear = ($y === 1 ? max(0.0, $projectCost - $subsidy) : 0.0) + $residual;
    }
    $cumWith += $withYear;
    $with[] = $cumWith;
}
$paybackYear = null;
for ($i = 0; $i < count($without); $i++) {
    if ($with[$i] < $without[$i]) { $paybackYear = $i + 1; break; }
}

$ef = $val('ef_kg_per_kwh');
$treeKg = max(0.1, $val('kg_co2_per_tree_per_year'));
$co2KgYear = $annualGen * $ef;
$co2TonYear = $co2KgYear / 1000;
$co2Ton25 = $co2TonYear * 25;
$treesYear = $co2KgYear / $treeKg;
$trees25 = $treesYear * 25;

$lineChart = static function (array $seriesA, array $seriesB, string $aColor, string $bColor): string {
    $w = 700; $h = 260; $pad = 35;
    $max = max(max($seriesA ?: [0]), max($seriesB ?: [0]), 1);
    $count = max(count($seriesA), 2);
    $pts = static function (array $vals) use ($w, $h, $pad, $max, $count): string {
        $out = [];
        foreach ($vals as $i => $v) {
            $x = $pad + (($w - 2*$pad) * ($i / ($count - 1)));
            $y = $h - $pad - (($h - 2*$pad) * ((float)$v / $max));
            $out[] = round($x, 2) . ',' . round($y, 2);
        }
        return implode(' ', $out);
    };
    $a = $pts($seriesA); $b = $pts($seriesB);
    return '<svg viewBox="0 0 '.$w.' '.$h.'" width="100%" height="260" role="img">'
        .'<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#fff"/>'
        .'<line x1="'.$pad.'" y1="'.($h-$pad).'" x2="'.($w-$pad).'" y2="'.($h-$pad).'" stroke="#cbd5e1"/>'
        .'<line x1="'.$pad.'" y1="'.$pad.'" x2="'.$pad.'" y2="'.($h-$pad).'" stroke="#cbd5e1"/>'
        .'<polyline fill="none" stroke="'.$aColor.'" stroke-width="3" points="'.$a.'"/>'
        .'<polyline fill="none" stroke="'.$bColor.'" stroke-width="3" points="'.$b.'"/>'
        .'<text x="'.($pad+6).'" y="'.($pad+12).'" font-size="12" fill="'.$aColor.'">Without Solar</text>'
        .'<text x="'.($pad+150).'" y="'.($pad+12).'" font-size="12" fill="'.$bColor.'">With Solar</text>'
        .'</svg>';
};

$library = documents_get_media_library();
$mediaMap=[]; foreach($library as $m){ if(is_array($m)) $mediaMap[(string)($m['id']??'')]=$m; }
$coverMediaId = (string)($quote['media']['cover_hero_media_id'] ?? '');
$coverHero = isset($mediaMap[$coverMediaId]) ? (string)($mediaMap[$coverMediaId]['file_path'] ?? '') : '';

$safeHtml = static function (string $value): string {
    $clean = strip_tags($value, '<p><br><ul><ol><li><strong><em><b><i><u><table><thead><tbody><tr><td><th>');
    return $clean !== '' ? $clean : '<span style="color:#666">—</span>';
};
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>
@page{size:A4;margin:9mm}.doc{font-family:<?= htmlspecialchars((string)$resolvedTheme['font_family'], ENT_QUOTES) ?>;font-size:<?= (int)$resolvedTheme['base_font_px'] ?>px;color:#0f172a}.page{position:relative;min-height:276mm;page-break-after:always;padding:10mm 8mm;box-sizing:border-box}.page:last-child{page-break-after:auto}.bg{position:absolute;inset:0;z-index:-1;opacity:<?= htmlspecialchars((string)$resolvedTheme['background_opacity'], ENT_QUOTES) ?>;background:<?= ($resolvedTheme['background_enabled'] && $resolvedTheme['background_image']!=='') ? 'url(' . htmlspecialchars((string)$resolvedTheme['background_image'], ENT_QUOTES) . ') center/cover no-repeat' : 'none' ?>}.hero{display:grid;grid-template-columns:1.3fr .7fr;gap:12px;align-items:center}.brand{font-size:28px;font-weight:800;color:<?= htmlspecialchars((string)$resolvedTheme['primary_color'], ENT_QUOTES) ?>}.card{background:#ffffffea;border:1px solid <?= htmlspecialchars((string)$resolvedTheme['table_border_color'], ENT_QUOTES) ?>;border-radius:<?= (int)$resolvedTheme['card_radius_px'] ?>px;padding:12px;margin-bottom:10px}.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.tile{border:1px solid <?= htmlspecialchars((string)$resolvedTheme['table_border_color'], ENT_QUOTES) ?>;border-radius:12px;padding:10px;background:#fff}.muted{color:<?= htmlspecialchars((string)$resolvedTheme['muted_color'], ENT_QUOTES) ?>}table{width:100%;border-collapse:collapse}th,td{border:1px solid <?= htmlspecialchars((string)$resolvedTheme['table_border_color'], ENT_QUOTES) ?>;padding:8px;text-align:left}th{background:#f8fafc}.steps{display:flex;gap:8px}.step{flex:1;padding:8px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;text-align:center}
</style></head><body><div class="doc">
<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <div class="hero">
    <div>
      <div class="brand"><?= htmlspecialchars((string)($company['brand_name'] ?: 'Dakshayani'), ENT_QUOTES) ?></div>
      <h1 style="margin:6px 0">Solar Energy Proposal</h1>
      <p class="muted">Prepared for <?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)($snapshot['mobile'] ?: $quote['customer_mobile']), ENT_QUOTES) ?></p>
      <p><strong>Quotation:</strong> <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?><br><strong>Date:</strong> <?= htmlspecialchars((string)($quote['created_at'] ?: date('Y-m-d')), ENT_QUOTES) ?></p>
    </div>
    <div><?php if ($coverHero !== ''): ?><img src="<?= htmlspecialchars($coverHero, ENT_QUOTES) ?>" alt="cover" style="width:100%;border-radius:14px"><?php endif; ?></div>
  </div>
  <div class="card">Website: <?= htmlspecialchars((string)$company['website'], ENT_QUOTES) ?> · Email: <?= htmlspecialchars((string)$company['email'], ENT_QUOTES) ?> · Phone: <?= htmlspecialchars((string)$company['phone_primary'], ENT_QUOTES) ?></div>
</div>

<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <h2>System Overview <?= !empty($qDefaults['show_emojis']) ? '🌞' : '' ?></h2>
  <div class="tiles">
    <div class="tile"><strong>Capacity</strong><br><?= number_format($capacity,2) ?> kWp</div>
    <div class="tile"><strong>System Type</strong><br><?= htmlspecialchars((string)$quote['system_type'], ENT_QUOTES) ?></div>
    <div class="tile"><strong>Annual Generation</strong><br><?= number_format($annualGen,0) ?> kWh</div>
    <div class="tile"><strong>Subsidy Estimate</strong><br><?= $isPmTemplate ? ('₹'.number_format($subsidy,0)) : 'Not applicable' ?></div>
    <div class="tile"><strong>Total Project Cost</strong><br>₹<?= number_format($projectCost,2) ?></div>
    <div class="tile"><strong>Validity</strong><br><?= htmlspecialchars((string)$quote['valid_until'], ENT_QUOTES) ?></div>
  </div>
  <div class="card"><strong>Warranty & Support</strong><div><?= $safeHtml((string)($quote['annexures_overrides']['warranty'] ?? '')) ?></div></div>
  <div class="steps"><div class="step">Agreement & Application</div><div class="step">Installation</div><div class="step">Inspection / Net meter</div><div class="step">Activation / Subsidy</div></div>
</div>

<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <h2>Savings & Environmental Impact <?= !empty($qDefaults['show_emojis']) ? '⚡🌍' : '' ?></h2>
  <?php if ($monthlyBill > 0 && !empty($qDefaults['charts_enabled'])): ?>
    <div class="card"><?= $lineChart($without, $with, '#ef4444', (string)$resolvedTheme['accent_color']) ?><p><strong>Payback year:</strong> <?= $paybackYear !== null ? $paybackYear : 'Beyond analysis period' ?></p></div>
  <?php else: ?>
    <div class="card muted">Add monthly bill to see savings chart.</div>
  <?php endif; ?>
  <div class="tiles">
    <div class="tile">🌍 <strong>CO₂ offset / year</strong><br><?= number_format($co2TonYear,2) ?> ton</div>
    <div class="tile">🌳 <strong>Trees equivalent / year</strong><br><?= number_format($treesYear,0) ?></div>
    <div class="tile"><strong>25-year CO₂ offset</strong><br><?= number_format($co2Ton25,1) ?> ton</div>
  </div>
</div>

<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <h2>Pricing & Investment Breakdown</h2>
  <div class="card"><table><tr><th>Item</th><th>Amount (₹)</th></tr>
  <tr><td>Project cost (GST inclusive)</td><td><?= number_format($projectCost,2) ?></td></tr>
  <tr><td>Subsidy expected</td><td><?= number_format($subsidy,2) ?></td></tr>
  <tr><td>Net effective cost</td><td><?= number_format(max(0,$projectCost-$subsidy),2) ?></td></tr>
  <?php if ($financeMode === 'loan'): ?><tr><td>Down payment</td><td><?= number_format(max(0,($projectCost-$subsidy)*$downPaymentPct),2) ?></td></tr><tr><td>Estimated EMI</td><td><?= number_format($emi,2) ?>/month</td></tr><?php endif; ?>
  </table></div>
  <div class="card"><strong>Payment Terms</strong><?= $safeHtml((string)($quote['annexures_overrides']['payment_terms'] ?? '')) ?></div>
  <div class="card"><strong>Important notes</strong><?= $safeHtml((string)($quote['annexures_overrides']['cover_notes'] ?? '')) ?></div>
</div>

<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <h2>Inclusions, Warranty & Terms</h2>
  <?php if (trim((string)$quote['special_requests_inclusive']) !== ''): ?><div class="card"><h3>Special Requests From Customer (Inclusive in rate)</h3><div><?= nl2br(htmlspecialchars((string)$quote['special_requests_inclusive'], ENT_QUOTES)) ?></div><p><em>This section overrides annexure conflicts.</em></p></div><?php endif; ?>
  <div class="card"><h3>System Inclusions</h3><?= $safeHtml((string)($quote['annexures_overrides']['system_inclusions'] ?? '')) ?></div>
  <div class="card"><h3>Terms & Conditions</h3><?= $safeHtml((string)($quote['annexures_overrides']['terms_conditions'] ?? '')) ?></div>
</div>

<div class="page"><?php if ($resolvedTheme['background_enabled']): ?><div class="bg"></div><?php endif; ?>
  <h2>FAQ & Contact</h2>
  <div class="card"><h3>FAQ</h3><?= $safeHtml((string)($quote['annexures_overrides']['system_type_explainer'] ?? '')) ?></div>
  <div class="card"><h3>Contact & Support</h3>
  <p>📞 <?= htmlspecialchars((string)$company['phone_primary'], ENT_QUOTES) ?><br>✉️ <?= htmlspecialchars((string)$company['email'], ENT_QUOTES) ?><br>📍 <?= htmlspecialchars((string)$company['address_line'], ENT_QUOTES) ?>, <?= htmlspecialchars((string)$company['city'], ENT_QUOTES) ?></p>
  </div>
</div>
</div>
<script>window.onload=function(){if(location.search.indexOf('autoprint=1')!==-1){window.print();}}</script>
</body></html>
