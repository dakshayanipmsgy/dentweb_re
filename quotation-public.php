<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/includes/documents_helpers.php';
documents_ensure_structure();
$token = safe_text($_GET['token'] ?? '');
$quote = null;
foreach (documents_list_quotes() as $q) {
    if ((string) ($q['share']['public_token'] ?? '') === $token && !empty($q['share']['public_enabled'])) { $quote = $q; break; }
}
if ($token === '' || $quote === null) { http_response_code(404); echo '<h1>Link invalid or expired.</h1>'; exit; }
$quoteDefaults = documents_get_quote_defaults_settings();
$segment = (string) ($quote['segment'] ?? 'RES');
$segmentDefaults = is_array($quoteDefaults['segments'][$segment] ?? null) ? $quoteDefaults['segments'][$segment] : [];
$snapshot = documents_quote_resolve_snapshot($quote);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quotation <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></title>
<style>body{margin:0;font-family:Arial,sans-serif;background:#f4f8ff}.wrap{max-width:1050px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #dbeafe;border-radius:16px;padding:14px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.metric{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px}.h{font-weight:800}</style></head><body><main class="wrap">
<div class="card"><div class="h">🌞 Solar Proposal <?= htmlspecialchars((string)$quote['quote_no'], ENT_QUOTES) ?></div><p><?= htmlspecialchars((string)($snapshot['name'] ?: $quote['customer_name']), ENT_QUOTES) ?> · <?= htmlspecialchars((string)$quote['city'], ENT_QUOTES) ?></p></div>
<div class="card grid" id="metrics"></div>
<div class="card"><div class="h">Pricing</div><div class="grid"><div class="metric">Grand Total<br><b>₹<?= number_format((float)$quote['calc']['grand_total'],2) ?></b></div><div class="metric">Expected Subsidy<br><b id="subsidyCard"></b></div><div class="metric">Net Cost<br><b id="netCostCard"></b></div></div></div>
<div class="card"><div class="h">Impact</div><div class="grid"><div class="metric">CO₂/year <b id="co2Y"></b></div><div class="metric">Trees/year <b id="treesY"></b></div></div></div>
<div class="card"><div class="h">📞 Contact us on WhatsApp for next steps</div></div>
</main>
<script>
const q={grand:<?= json_encode((float)$quote['calc']['grand_total']) ?>,cap:<?= json_encode((float)$quote['capacity_kwp']) ?>,unit:<?= json_encode((float)(($quote['finance_inputs']['unit_rate_rs_per_kwh'] ?: ($segmentDefaults['unit_rate_rs_per_kwh'] ?? 8)))) ?>,ann:<?= json_encode((float)(($quote['finance_inputs']['annual_generation_per_kw'] ?: ($quoteDefaults['global']['energy_defaults']['annual_generation_per_kw'] ?? 1450)))) ?>,em:<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['emission_factor_kg_per_kwh'] ?? 0.82)) ?>,tree:<?= json_encode((float)($quoteDefaults['global']['energy_defaults']['tree_absorption_kg_per_tree_per_year'] ?? 20)) ?>,seg:<?= json_encode($segment) ?>};
const sub=(q.seg==='RES'?(q.cap>=3?78000:(q.cap>=2?60000:0)):0);const yearly=q.cap*q.ann;document.getElementById('subsidyCard').textContent='₹'+sub.toLocaleString('en-IN');document.getElementById('netCostCard').textContent='₹'+(q.grand-sub).toLocaleString('en-IN');document.getElementById('co2Y').textContent=(yearly*q.em).toFixed(0)+' kg';document.getElementById('treesY').textContent=((yearly*q.em)/q.tree).toFixed(0);document.getElementById('metrics').innerHTML=[['System',q.cap.toFixed(2)+' kWp'],['Yearly Generation',yearly.toFixed(0)+' kWh'],['Monthly Saving','₹'+Math.round((yearly/12)*q.unit).toLocaleString('en-IN')],['Status',<?= json_encode((string)$quote['status']) ?>]].map(a=>`<div class='metric'><div>${a[0]}</div><b>${a[1]}</b></div>`).join('');
</script></body></html>
