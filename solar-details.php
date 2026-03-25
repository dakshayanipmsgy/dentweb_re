<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

function solar_details_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function solar_details_ensure_utf8(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    if (function_exists('mb_convert_encoding')) {
        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    return $value;
}

function solar_details_is_html(string $value): bool
{
    return $value !== strip_tags($value);
}

function solar_details_decode_html_entities(string $value): string
{
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function solar_details_format_rich_text(string $value): string
{
    $value = solar_details_ensure_utf8(trim($value));
    if ($value === '') {
        return '';
    }

    $decodedValue = solar_details_decode_html_entities($value);
    if (solar_details_is_html($decodedValue)) {
        return $decodedValue;
    }

    $decodedValue = str_replace(["\r\n", "\r"], "\n", $decodedValue);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $decodedValue)), static fn(string $line): bool => $line !== ''));
    if ($lines === []) {
        return '';
    }

    $html = [];
    $currentListType = '';
    foreach ($lines as $line) {
        $unorderedLine = preg_match('/^[\-\*\x{2022}\x{25CF}\x{25E6}\x{2043}]\s+/u', $line) === 1;
        $emojiBulletLine = preg_match('/^\p{So}\s+/u', $line) === 1;
        $orderedLine = preg_match('/^\d+[\.\)]\s+/u', $line) === 1;

        if ($unorderedLine || $emojiBulletLine) {
            $text = preg_replace('/^[\-\*\x{2022}\x{25CF}\x{25E6}\x{2043}\p{So}]\s+/u', '', $line) ?? $line;
            if ($currentListType !== 'ul') {
                if ($currentListType === 'ol') {
                    $html[] = '</ol>';
                }
                $html[] = '<ul>';
                $currentListType = 'ul';
            }
            $html[] = '<li>' . solar_details_safe($text) . '</li>';
            continue;
        }

        if ($orderedLine) {
            $text = preg_replace('/^\d+[\.\)]\s+/u', '', $line) ?? $line;
            if ($currentListType !== 'ol') {
                if ($currentListType === 'ul') {
                    $html[] = '</ul>';
                }
                $html[] = '<ol>';
                $currentListType = 'ol';
            }
            $html[] = '<li>' . solar_details_safe($text) . '</li>';
            continue;
        }

        if ($currentListType === 'ul') {
            $html[] = '</ul>';
            $currentListType = '';
        } elseif ($currentListType === 'ol') {
            $html[] = '</ol>';
            $currentListType = '';
        }

        $html[] = '<p>' . solar_details_safe($line) . '</p>';
    }

    if ($currentListType === 'ul') {
        $html[] = '</ul>';
    } elseif ($currentListType === 'ol') {
        $html[] = '</ol>';
    }

    return implode("\n", $html);
}

function solar_details_message_settings(): array
{
    $path = __DIR__ . '/data/leads/lead_message_settings.json';
    $defaults = [
        'company_name' => 'Dakshayani Enterprises',
        'company_phone' => '',
    ];
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'company_name' => trim((string) ($decoded['company_name'] ?? $defaults['company_name'])),
        'company_phone' => trim((string) ($decoded['company_phone'] ?? '')),
    ];
}

function solar_details_defaults(): array
{
    return [
        'page_title' => 'Understand Your Solar Requirement',
        'hero_intro' => 'Use this decision tool to compare on-grid vs hybrid, estimate bills, evaluate financing, and request your quotation in minutes.',
        'what_is_solar_rooftop' => '',
        'pm_surya_ghar_text' => 'PM Surya Ghar: Muft Bijli Yojana is a residential-focused rooftop solar program with subsidy support for eligible homes as per current policy.',
        'who_is_eligible' => '',
        'on_grid_text' => 'On-grid systems connect directly to the utility grid. They reduce your monthly bill effectively where grid availability is good.',
        'hybrid_text' => 'Hybrid systems add battery support so selected loads can run during outages. They cost more than on-grid but add backup comfort.',
        'which_one_is_suitable_for_whom' => '',
        'benefits' => '',
        'important_expectations' => '',
        'process_text' => "1) Site survey\n2) Usage + roof study\n3) Proposal and finance option\n4) Installation\n5) Testing / net-metering\n6) Handover and support",
        'faq_text' => '',
        'cta_text' => 'Ready to move ahead? Share your requirement and get a detailed quotation over WhatsApp.',
        'on_grid_image' => '',
        'hybrid_image' => '',
        'process_flow_image' => '',
        'benefits_image' => '',
    ];
}

function solar_details_load_content(): array
{
    $defaults = solar_details_defaults();
    $path = __DIR__ . '/data/leads/lead_explainer_content.json';
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $merged = array_merge($defaults, $decoded);
    foreach ($merged as $key => $value) {
        if (is_string($value)) {
            $merged[$key] = solar_details_ensure_utf8($value);
        }
    }

    return $merged;
}

function solar_details_resolve_text(array $content, string $key, string $fallback): string
{
    $value = trim(solar_details_ensure_utf8((string) ($content[$key] ?? '')));
    return $value !== '' ? $value : $fallback;
}

$content = solar_details_load_content();
$settings = solar_details_message_settings();
$companyName = trim((string) ($settings['company_name'] ?? 'Dakshayani Enterprises')) ?: 'Dakshayani Enterprises';
$companyPhone = trim((string) ($settings['company_phone'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo solar_details_safe((string) $content['page_title']); ?> | <?php echo solar_details_safe($companyName); ?></title>
  <style>
    :root {
      --bg: #f8fafc;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #475569;
      --line: #dbe3ef;
      --primary: #0f766e;
      --primary-dark: #115e59;
      --secondary: #1d4ed8;
      --accent: #f59e0b;
      --success: #15803d;
      --danger: #dc2626;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; color: var(--text); background: var(--bg); line-height: 1.5; }
    .wrap { width: min(1340px, 100%); margin: 0 auto; padding: 1rem; }
    .hero { border-radius: 1.1rem; padding: 1.4rem; color: #fff; background: linear-gradient(130deg, #0f766e 0%, #1d4ed8 45%, #0f172a 100%); }
    .hero h1 { margin: 0 0 0.45rem; font-size: clamp(1.4rem, 2.8vw, 2rem); }
    .hero p { margin: 0; max-width: 70ch; }
    .chips { display: flex; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.75rem; }
    .chip { border: 1px solid rgba(255,255,255,0.4); border-radius: 999px; padding: 0.25rem 0.7rem; font-size: 0.85rem; }
    .grid { display: grid; gap: 1rem; }
    .grid-2 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 1rem; padding: 1rem; box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04); }
    .card h2, .card h3 { margin-top: 0; line-height: 1.3; }
    .text-block { color: #334155; }
    .text-block ul, .text-block ol { margin: 0.25rem 0 0.75rem 1.1rem; }
    .calculator-layout { display: grid; grid-template-columns: 1.1fr 1fr; gap: 1rem; margin-top: 1rem; align-items: start; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.7rem; }
    label { display: block; font-size: 0.82rem; color: var(--muted); margin-bottom: 0.25rem; font-weight: 600; }
    input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 0.65rem; padding: 0.58rem 0.62rem; font: inherit; }
    .btn { border: 0; border-radius: 0.8rem; padding: 0.72rem 1rem; font-weight: 700; cursor: pointer; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-wa { background: #16a34a; color: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .muted { color: var(--muted); font-size: 0.9rem; }
    .results { display: none; margin-top: 1rem; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.6rem; }
    .metric { background: #f8fafc; border: 1px solid #dbeafe; border-radius: 0.8rem; padding: 0.7rem; }
    .metric .label { font-size: 0.78rem; color: #475569; }
    .metric .value { font-size: 1.1rem; font-weight: 700; }
    .bars { display: grid; grid-template-columns: repeat(3, minmax(80px, 1fr)); gap: 0.75rem; align-items: end; min-height: 220px; }
    .bar-wrap { text-align: center; }
    .bar { height: 20px; border-radius: 0.7rem 0.7rem 0.3rem 0.3rem; transition: height .4s; }
    .bar1 { background: #64748b; }
    .bar2 { background: #1d4ed8; }
    .bar3 { background: #16a34a; }
    .bar-val { font-weight: 700; font-size: 0.9rem; }
    .line-chart { width: 100%; height: 260px; background: #fff; border: 1px solid var(--line); border-radius: 0.9rem; padding: 0.5rem; }
    .legend { display: flex; flex-wrap: wrap; gap: 0.75rem; font-size: 0.85rem; color: var(--muted); margin-top: 0.5rem; }
    .legend span::before { content: ''; display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; }
    .l1::before { background: #64748b; }
    .l2::before { background: #1d4ed8; }
    .l3::before { background: #16a34a; }
    .meter { height: 14px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .meter > i { display: block; height: 100%; background: linear-gradient(90deg, #2563eb, #16a34a); }
    .two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
    .assumption { border-left: 4px solid #f59e0b; background: #fffbeb; padding: 0.75rem; border-radius: 0.7rem; color: #78350f; }
    .image-slot { max-width: 100%; border-radius: 0.8rem; border: 1px solid #cbd5e1; margin-top: 0.7rem; }
    @media (max-width: 980px) { .calculator-layout { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1><?php echo solar_details_safe((string) $content['page_title']); ?></h1>
      <p><?php echo solar_details_safe((string) $content['hero_intro']); ?></p>
      <div class="chips">
        <span class="chip">⚡ Requirement Helper</span>
        <span class="chip">💰 25-Year Financial View</span>
        <span class="chip">🌿 Green Impact</span>
      </div>
    </section>

    <section class="calculator-layout">
      <article class="card">
        <h2>Solar Requirement + Adoption Planner</h2>
        <p class="muted">Fill your details and click <strong>How solar adoption would look like</strong>.</p>
        <div class="form-grid" id="solar-form">
          <div><label for="solarSize">Solar size (kW / kWp)</label><input id="solarSize" type="number" step="0.1" min="0" value="3" /></div>
          <div><label for="dailyGen">Daily generation per kW (kWh)</label><input id="dailyGen" type="number" step="0.1" min="0" value="5" /></div>
          <div><label for="unitRate">Unit rate (₹/unit)</label><input id="unitRate" type="number" step="0.1" min="0" value="7" /></div>
          <div><label for="monthlyBill">Current monthly bill (₹)</label><input id="monthlyBill" type="number" step="1" min="0" placeholder="Optional" /></div>
          <div><label for="systemCost">Solar system cost (₹)</label><input id="systemCost" type="number" step="1" min="0" value="240000" /></div>
          <div><label for="subsidy">Subsidy (₹)</label><input id="subsidy" type="number" step="1" min="0" placeholder="Optional" value="78000" /></div>
          <div><label for="loanAmount">Loan amount (₹)</label><input id="loanAmount" type="number" step="1" min="0" value="120000" /></div>
          <div><label for="marginMoney">Margin money (₹)</label><input id="marginMoney" type="number" step="1" min="0" value="42000" /></div>
          <div><label for="interestRate">Loan interest rate (%)</label><input id="interestRate" type="number" step="0.1" min="0" value="10" /></div>
          <div><label for="loanTenure">Loan tenure (months)</label><input id="loanTenure" type="number" step="1" min="1" value="60" /></div>

          <div><label for="propertyType">Property type</label><select id="propertyType"><option>home</option><option>apartment</option><option>shop</option><option>school</option><option>hospital</option><option>office</option><option>industrial</option><option>other</option></select></div>
          <div><label for="roofType">Roof type</label><select id="roofType"><option>RCC</option><option>sheet</option><option>other</option></select></div>
          <div><label for="roofArea">Shadow-free roof area</label><input id="roofArea" type="number" step="0.1" min="0" value="500" /></div>
          <div><label for="roofUnit">Roof area unit</label><select id="roofUnit"><option value="sqft">sqft</option><option value="sqm">sqm</option></select></div>
          <div><label for="phase">Connection phase</label><select id="phase"><option>single phase</option><option>three phase</option></select></div>
          <div><label for="city">City / location</label><input id="city" type="text" placeholder="Ranchi" /></div>
          <div><label for="dayUsage">Daytime usage level</label><select id="dayUsage"><option>low</option><option selected>medium</option><option>high</option></select></div>

          <div><label for="tariffEsc">Annual tariff escalation (%)</label><input id="tariffEsc" type="number" step="0.1" min="0" value="3" /></div>
          <div><label for="degradation">Annual generation degradation (%)</label><input id="degradation" type="number" step="0.1" min="0" value="0.7" /></div>
          <div><label for="omAnnual">Annual O&M allowance (₹)</label><input id="omAnnual" type="number" step="1" min="0" value="3000" /></div>
          <div><label for="roofFactor">Roof area needed per kW (sqft)</label><input id="roofFactor" type="number" step="1" min="50" value="100" /></div>
        </div>
        <p style="margin-top:0.8rem;"><button class="btn btn-primary" id="runCalc">How solar adoption would look like</button></p>
      </article>

      <article class="card">
        <h3>What this page explains</h3>
        <ul>
          <li>What solar size may suit your usage.</li>
          <li>How on-grid and hybrid differ in practical terms.</li>
          <li>How PM Surya Ghar can influence net investment.</li>
          <li>Monthly outflow and 25-year financial projection.</li>
          <li>Green impact and roof feasibility guidance.</li>
        </ul>
        <div class="assumption">Estimates are indicative and should be validated by site survey, DISCOM rules, sanctioned load, and final engineering design.</div>
      </article>
    </section>

    <section class="results" id="results">
      <article class="card">
        <h2>A) At-a-glance Summary</h2>
        <div class="summary-grid" id="summaryGrid"></div>
        <p class="muted" id="billEstimateNote" hidden>Since monthly bill was not entered, this estimate is based on system size, assumed daily generation, and tariff.</p>
      </article>

      <div class="grid grid-2">
        <article class="card">
          <h3>B) Monthly Outflow Comparison</h3>
          <div class="bars">
            <div class="bar-wrap"><div class="bar bar1" id="barNoSolar"></div><div class="bar-val" id="barNoSolarVal"></div><small>No Solar</small></div>
            <div class="bar-wrap"><div class="bar bar2" id="barLoan"></div><div class="bar-val" id="barLoanVal"></div><small>With Solar (Loan)</small></div>
            <div class="bar-wrap"><div class="bar bar3" id="barSelf"></div><div class="bar-val" id="barSelfVal"></div><small>With Solar (Self Funded)</small></div>
          </div>
        </article>

        <article class="card">
          <h3>D) Two Payback Meters</h3>
          <p><strong>Payback meter (Self Funded)</strong>: <span id="paybackSelfText">-</span></p>
          <div class="meter"><i id="paybackSelfBar" style="width:0%"></i></div>
          <p><strong>Payback meter (Own Upfront Recovery / Loan Case)</strong>: <span id="paybackLoanText">-</span></p>
          <div class="meter"><i id="paybackLoanBar" style="width:0%"></i></div>
        </article>
      </div>

      <article class="card">
        <h3>C) Cumulative Expense Over 25 Years</h3>
        <svg class="line-chart" id="lineChart" viewBox="0 0 760 260" preserveAspectRatio="none"></svg>
        <div class="legend"><span class="l1">No Solar</span><span class="l2">With Solar (Loan)</span><span class="l3">With Solar (Self Funded)</span></div>
      </article>

      <div class="two-col">
        <article class="card" id="withLoanCard"></article>
        <article class="card" id="withoutLoanCard"></article>
      </div>

      <div class="two-col">
        <article class="card" id="generationCard"></article>
        <article class="card" id="greenCard"></article>
      </div>

      <div class="two-col">
        <article class="card" id="roofCard"></article>
        <article class="card" id="recommendationCard"></article>
      </div>

      <article class="card">
        <h3>Request a Quotation</h3>
        <p class="muted">Share your entered details instantly on WhatsApp.</p>
        <a href="#" class="btn btn-wa" id="waBtn">Request a Quotation</a>
      </article>

      <article class="card">
        <h3>K) Assumptions / Disclaimer</h3>
        <p class="muted">All estimates are indicative. Actual savings and system suitability depend on shadow-free area, roof condition, local tariff, day-time usage pattern, electrical approvals, meter/billing structure, and site conditions.</p>
      </article>
    </section>

    <section class="grid grid-2" style="margin-top:1rem;">
      <article class="card">
        <h2>What is Solar Rooftop?</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'what_is_solar_rooftop', 'Rooftop solar means installing PV modules on your roof to generate your own electricity and reduce grid dependence.')); ?></div>
      </article>
      <article class="card">
        <h2>PM Surya Ghar: Muft Bijli Yojana</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['pm_surya_ghar_text']); ?></div>
      </article>
      <article class="card">
        <h2>Who is eligible?</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'who_is_eligible', 'Residential applicants with eligible roof, DISCOM feasibility, and valid documents as per current policy.')); ?></div>
      </article>
      <article class="card">
        <h2>On-Grid</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['on_grid_text']); ?></div>
        <?php if (trim((string) $content['on_grid_image']) !== ''): ?><img class="image-slot" src="<?php echo solar_details_safe((string) $content['on_grid_image']); ?>" alt="On-grid" /><?php endif; ?>
      </article>
      <article class="card">
        <h2>Hybrid</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['hybrid_text']); ?></div>
        <?php if (trim((string) $content['hybrid_image']) !== ''): ?><img class="image-slot" src="<?php echo solar_details_safe((string) $content['hybrid_image']); ?>" alt="Hybrid" /><?php endif; ?>
      </article>
      <article class="card">
        <h2>Which one is suitable for whom?</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'which_one_is_suitable_for_whom', 'Choose on-grid for lower cost and strong grid. Choose hybrid if backup during outages is important.')); ?></div>
      </article>
      <article class="card">
        <h2>Benefits</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'benefits', 'Lower electricity bills\nLong-term savings\nCleaner energy\nImproved energy independence')); ?></div>
        <?php if (trim((string) $content['benefits_image']) !== ''): ?><img class="image-slot" src="<?php echo solar_details_safe((string) $content['benefits_image']); ?>" alt="Benefits" /><?php endif; ?>
      </article>
      <article class="card">
        <h2>Important Expectations</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text(solar_details_resolve_text($content, 'important_expectations', 'Actual generation varies by location, tilt, season, and shading.\nPolicy and tariff terms can change over time.')); ?></div>
      </article>
      <article class="card">
        <h2>Process / Installation Flow</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['process_text']); ?></div>
        <?php if (trim((string) $content['process_flow_image']) !== ''): ?><img class="image-slot" src="<?php echo solar_details_safe((string) $content['process_flow_image']); ?>" alt="Process" /><?php endif; ?>
      </article>
      <article class="card">
        <h2>FAQ</h2>
        <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['faq_text']); ?></div>
      </article>
    </section>

    <section class="card" style="margin-top:1rem;">
      <h2>Need human support?</h2>
      <div class="text-block"><?php echo solar_details_format_rich_text((string) $content['cta_text']); ?></div>
      <p><strong><?php echo solar_details_safe($companyName); ?></strong>
      <?php if ($companyPhone !== ''): ?> | <a href="tel:<?php echo solar_details_safe($companyPhone); ?>">Call <?php echo solar_details_safe($companyPhone); ?></a><?php endif; ?></p>
    </section>
  </div>

  <script>
    (function () {
      const INR = (num) => '₹' + Math.round(num).toLocaleString('en-IN');
      const read = (id) => document.getElementById(id);
      const safeNum = (id) => Math.max(parseFloat(read(id).value) || 0, 0);
      const summaryGrid = read('summaryGrid');
      const results = read('results');
      let lastPayload = null;

      function emiFrom(loan, annualRate, months) {
        if (loan <= 0 || months <= 0) return 0;
        const r = annualRate / 12 / 100;
        if (r <= 0) return loan / months;
        const factor = Math.pow(1 + r, months);
        return loan * r * factor / (factor - 1);
      }

      function calc() {
        const solarSize = safeNum('solarSize');
        const dailyGen = safeNum('dailyGen');
        const unitRate = safeNum('unitRate');
        const enteredMonthlyBill = parseFloat(read('monthlyBill').value || '');
        const monthlyBillProvided = Number.isFinite(enteredMonthlyBill) && enteredMonthlyBill > 0;
        const monthlyBill = monthlyBillProvided ? enteredMonthlyBill : solarSize * dailyGen * 30 * unitRate;

        const systemCost = safeNum('systemCost');
        const subsidy = safeNum('subsidy');
        const loanAmount = safeNum('loanAmount');
        const marginMoney = safeNum('marginMoney');
        const interestRate = safeNum('interestRate');
        const loanTenure = Math.max(Math.round(safeNum('loanTenure')), 1);
        const tariffEsc = safeNum('tariffEsc') / 100;
        const degradation = safeNum('degradation') / 100;
        const omAnnual = safeNum('omAnnual');
        const roofFactor = Math.max(safeNum('roofFactor'), 50);

        const monthlyGeneration = solarSize * dailyGen * 30;
        const monthlySolarValue = monthlyGeneration * unitRate;
        const residualBill = Math.max(monthlyBill - monthlySolarValue, 0);
        const billOffset = monthlyBill > 0 ? Math.min((monthlySolarValue / monthlyBill) * 100, 100) : 0;

        const emi = emiFrom(loanAmount, interestRate, loanTenure);
        const monthlyLoanOutflow = emi + residualBill;
        const monthlySelfOutflow = residualBill;
        const annualSavings = (monthlyBill - monthlySelfOutflow) * 12;

        const netInvestment = Math.max(systemCost - subsidy, 0);
        const grossPayable = netInvestment;
        const ownUpfront = Math.max(marginMoney, 0);
        const selfPaybackYears = annualSavings > 0 ? netInvestment / annualSavings : 0;
        const loanOwnRecoveryYears = (monthlyBill - monthlyLoanOutflow) > 0 ? ownUpfront / ((monthlyBill - monthlyLoanOutflow) * 12) : 0;

        const years = 25;
        const noSolar = [0];
        const withLoan = [ownUpfront];
        const withSelf = [netInvestment];
        let tariffMultiplier = 1;
        let generationMultiplier = 1;

        for (let y = 1; y <= years; y++) {
          tariffMultiplier *= (1 + tariffEsc);
          generationMultiplier *= (1 - degradation);
          const yBill = monthlyBill * tariffMultiplier;
          const ySolarValue = monthlySolarValue * tariffMultiplier * generationMultiplier;
          const yResidualMonthly = Math.max(yBill - ySolarValue, 0);

          const yNoSolarExpense = yBill * 12;
          const yLoanExpense = (y <= Math.ceil(loanTenure / 12) ? emi * 12 : 0) + (yResidualMonthly * 12) + omAnnual;
          const ySelfExpense = (yResidualMonthly * 12) + omAnnual;

          noSolar.push(noSolar[noSolar.length - 1] + yNoSolarExpense);
          withLoan.push(withLoan[withLoan.length - 1] + yLoanExpense);
          withSelf.push(withSelf[withSelf.length - 1] + ySelfExpense);
        }

        const annualGeneration = monthlyGeneration * 12;
        const generation25 = annualGeneration * years * (1 - (degradation * (years / 2)));
        const co2Factor = 0.82;
        const treesPerTon = 45;
        const co2AnnualKg = annualGeneration * co2Factor;
        const co225Kg = Math.max(generation25, 0) * co2Factor;

        const roofAreaInput = safeNum('roofArea');
        const roofUnit = read('roofUnit').value;
        const roofAreaSqft = roofUnit === 'sqm' ? roofAreaInput * 10.7639 : roofAreaInput;
        const roofNeedSqft = solarSize * roofFactor;

        const recommendedSize = monthlyBill > 0 && unitRate > 0 && dailyGen > 0 ? monthlyBill / (dailyGen * 30 * unitRate) : solarSize;
        const property = read('propertyType').value;
        const dayUsage = read('dayUsage').value;
        const sizeDiff = solarSize - recommendedSize;

        let simpleRecommendation = 'This solar size may be suitable for your current usage.';
        if (roofAreaSqft > 0 && roofAreaSqft < roofNeedSqft) {
          simpleRecommendation = 'You may want to consider a smaller system or increase usable shadow-free roof area.';
        } else if (sizeDiff > 0.8) {
          simpleRecommendation = 'You may want to consider a slightly smaller system based on current bill pattern.';
        } else if (sizeDiff < -0.8) {
          simpleRecommendation = 'You may want to consider a slightly larger system for better bill offset.';
        }

        read('billEstimateNote').hidden = monthlyBillProvided;
        summaryGrid.innerHTML = [
          ['Entered / recommended size', `${solarSize.toFixed(1)} kW / ${recommendedSize.toFixed(1)} kW`],
          ['Estimated monthly generation', `${Math.round(monthlyGeneration).toLocaleString('en-IN')} kWh`],
          ['Current bill', INR(monthlyBill)],
          ['Monthly outflow with loan', INR(monthlyLoanOutflow)],
          ['Monthly outflow without loan', INR(monthlySelfOutflow)],
          ['Estimated annual savings', INR(annualSavings)],
          ['Bill offset', `${billOffset.toFixed(1)}%`]
        ].map(([k, v]) => `<div class="metric"><div class="label">${k}</div><div class="value">${v}</div></div>`).join('');

        const maxOutflow = Math.max(monthlyBill, monthlyLoanOutflow, monthlySelfOutflow, 1);
        const toBar = (value) => Math.max(20, (value / maxOutflow) * 190);
        read('barNoSolar').style.height = toBar(monthlyBill) + 'px';
        read('barLoan').style.height = toBar(monthlyLoanOutflow) + 'px';
        read('barSelf').style.height = toBar(monthlySelfOutflow) + 'px';
        read('barNoSolarVal').textContent = INR(monthlyBill);
        read('barLoanVal').textContent = INR(monthlyLoanOutflow);
        read('barSelfVal').textContent = INR(monthlySelfOutflow);

        const paybackSelf = selfPaybackYears > 0 ? selfPaybackYears : 0;
        const paybackLoan = loanOwnRecoveryYears > 0 ? loanOwnRecoveryYears : 0;
        read('paybackSelfText').textContent = paybackSelf > 0 ? `${paybackSelf.toFixed(1)} years` : 'Not achievable with current inputs';
        read('paybackLoanText').textContent = paybackLoan > 0 ? `${paybackLoan.toFixed(1)} years` : 'Not achievable with current inputs';
        read('paybackSelfBar').style.width = Math.min((paybackSelf / 12) * 100, 100) + '%';
        read('paybackLoanBar').style.width = Math.min((paybackLoan / 12) * 100, 100) + '%';

        drawLineChart(noSolar, withLoan, withSelf);

        read('withLoanCard').innerHTML = `<h3>E) Financial Clarity — With Loan</h3><ul>
          <li>System cost: <strong>${INR(systemCost)}</strong></li>
          <li>Subsidy: <strong>${INR(subsidy)}</strong></li>
          <li>Gross payable: <strong>${INR(grossPayable)}</strong></li>
          <li>Margin money: <strong>${INR(marginMoney)}</strong></li>
          <li>Loan amount: <strong>${INR(loanAmount)}</strong></li>
          <li>Interest rate: <strong>${interestRate.toFixed(2)}%</strong></li>
          <li>Tenure: <strong>${loanTenure} months</strong></li>
          <li>EMI: <strong>${INR(emi)}</strong></li>
          <li>Residual bill: <strong>${INR(residualBill)}</strong></li>
          <li>Total monthly outflow: <strong>${INR(monthlyLoanOutflow)}</strong></li></ul>`;

        const monthlySavingSelf = monthlyBill - monthlySelfOutflow;
        read('withoutLoanCard').innerHTML = `<h3>E) Financial Clarity — Without Loan</h3><ul>
          <li>System cost: <strong>${INR(systemCost)}</strong></li>
          <li>Subsidy: <strong>${INR(subsidy)}</strong></li>
          <li>Net investment: <strong>${INR(netInvestment)}</strong></li>
          <li>Residual bill: <strong>${INR(residualBill)}</strong></li>
          <li>Estimated monthly saving: <strong>${INR(monthlySavingSelf)}</strong></li>
          <li>Estimated annual saving: <strong>${INR(annualSavings)}</strong></li></ul>`;

        read('generationCard').innerHTML = `<h3>F) Generation Estimate</h3><ul>
          <li>Expected monthly generation: <strong>${Math.round(monthlyGeneration).toLocaleString('en-IN')} kWh</strong></li>
          <li>Expected annual generation: <strong>${Math.round(annualGeneration).toLocaleString('en-IN')} kWh</strong></li>
          <li>Generation over 25 years: <strong>${Math.round(generation25).toLocaleString('en-IN')} kWh</strong></li>
          <li>Estimated annual savings: <strong>${INR(annualSavings)}</strong></li>
          <li>Estimated payback (self funded): <strong>${paybackSelf > 0 ? paybackSelf.toFixed(1) + ' years' : 'N/A'}</strong></li></ul>`;

        read('greenCard').innerHTML = `<h3>G) Green Impact</h3><ul>
          <li>CO₂ avoided annually: <strong>${(co2AnnualKg / 1000).toFixed(2)} tons</strong></li>
          <li>CO₂ avoided over 25 years: <strong>${(co225Kg / 1000).toFixed(2)} tons</strong></li>
          <li>Equivalent trees: <strong>${Math.round((co225Kg / 1000) * treesPerTon).toLocaleString('en-IN')}</strong></li>
          <li>Approx. petrol offset: <strong>${Math.round(annualGeneration / 9).toLocaleString('en-IN')} litres/year</strong></li></ul>`;

        read('roofCard').innerHTML = `<h3>H + I) Roof Area Needed & Bill Offset</h3><ul>
          <li>Approx roof area required: <strong>${Math.round(roofNeedSqft).toLocaleString('en-IN')} sqft</strong></li>
          <li>Your entered area: <strong>${roofAreaInput} ${roofUnit}</strong> (${Math.round(roofAreaSqft).toLocaleString('en-IN')} sqft)</li>
          <li>Approximate bill/usage offset: <strong>${billOffset.toFixed(1)}%</strong></li></ul>`;

        read('recommendationCard').innerHTML = `<h3>J) Recommendation Summary</h3>
          <ul>
            <li><strong>Best for lowest monthly outflow:</strong> With Solar (Self Funded)</li>
            <li><strong>Best for highest lifetime savings:</strong> With Solar (Self Funded)</li>
            <li><strong>Best for lowest initial investment:</strong> With Solar (Loan)</li>
          </ul>
          <p><strong>Guidance:</strong> ${simpleRecommendation}</p>
          <p class="muted">Based on property: ${property}, daytime usage: ${dayUsage}, and roof feasibility.</p>`;

        lastPayload = {
          solarSize, dailyGen, unitRate, monthlyBill, systemCost, subsidy, loanAmount, marginMoney, interestRate,
          loanTenure, propertyType: property, roofType: read('roofType').value, roofArea: `${roofAreaInput} ${roofUnit}`,
          phase: read('phase').value, city: read('city').value || '-', dayUsage
        };

        results.style.display = 'block';
      }

      function drawLineChart(noSolar, withLoan, withSelf) {
        const svg = read('lineChart');
        const w = 760;
        const h = 260;
        const pad = 24;
        const max = Math.max(...noSolar, ...withLoan, ...withSelf, 1);
        const xScale = (i) => pad + (i / 25) * (w - 2 * pad);
        const yScale = (v) => h - pad - (v / max) * (h - 2 * pad);
        const path = (arr) => arr.map((v, i) => `${i === 0 ? 'M' : 'L'}${xScale(i)},${yScale(v)}`).join(' ');
        svg.innerHTML = `
          <line x1="${pad}" y1="${h-pad}" x2="${w-pad}" y2="${h-pad}" stroke="#cbd5e1" />
          <line x1="${pad}" y1="${pad}" x2="${pad}" y2="${h-pad}" stroke="#cbd5e1" />
          <path d="${path(noSolar)}" fill="none" stroke="#64748b" stroke-width="3" />
          <path d="${path(withLoan)}" fill="none" stroke="#1d4ed8" stroke-width="3" />
          <path d="${path(withSelf)}" fill="none" stroke="#16a34a" stroke-width="3" />
          <text x="${w - 94}" y="${h - 8}" fill="#64748b" font-size="11">Year 25</text>
        `;
      }

      read('runCalc').addEventListener('click', calc);
      read('waBtn').addEventListener('click', function (event) {
        event.preventDefault();
        if (!lastPayload) calc();
        const p = lastPayload;
        const msg = [
          'Hello, I am requesting a solar quotation.',
          '',
          `Solar size: ${p.solarSize} kW`,
          `Daily generation per kW: ${p.dailyGen} kWh`,
          `Unit rate: ₹${p.unitRate}/unit`,
          `Monthly bill: ₹${Math.round(p.monthlyBill)}`,
          `System cost: ₹${Math.round(p.systemCost)}`,
          `Subsidy: ₹${Math.round(p.subsidy)}`,
          `Loan amount: ₹${Math.round(p.loanAmount)}`,
          `Margin money: ₹${Math.round(p.marginMoney)}`,
          `Interest rate: ${p.interestRate}%`,
          `Loan tenure: ${p.loanTenure} months`,
          `Property type: ${p.propertyType}`,
          `Roof type: ${p.roofType}`,
          `Roof area: ${p.roofArea}`,
          `Phase: ${p.phase}`,
          `City/location: ${p.city}`,
          `Daytime usage: ${p.dayUsage}`
        ].join('\n');

        window.open(`https://wa.me/917070278178?text=${encodeURIComponent(msg)}`, '_blank', 'noopener');
      });
    })();
  </script>
</body>
</html>
