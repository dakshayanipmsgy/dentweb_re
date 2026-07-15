<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/quotation_view_renderer.php';

$store = new CustomerFsStore();
customer_portal_require_login();

$customer = customer_portal_fetch_customer($store);
if ($customer === null) {
    customer_portal_logout();
    header('Location: customer-login.php');
    exit;
}

$complaintErrors = [];
$complaintSuccess = '';
$problemCategories = complaint_problem_categories();
$handoverHtmlPath = trim((string) ($customer['handover_html_path'] ?? ($customer['handover_document_path'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['complaint_action'] ?? '') === 'raise') {
    require_valid_csrf();
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $problemCategory = trim((string) ($_POST['problem_category'] ?? ''));

    if ($title === '' || $description === '' || $problemCategory === '') {
        $complaintErrors[] = 'Title, description, and problem category are required to raise a complaint.';
    }

    if ($complaintErrors === []) {
        try {
            add_complaint([
                'customer_mobile' => (string) ($customer['mobile'] ?? ''),
                'title' => $title,
                'description' => $description,
                'status' => 'open',
                'problem_category' => $problemCategory,
                'assignee' => '',
            ]);
            complaint_sync_customer_flag($store, (string) ($customer['mobile'] ?? ''));
            $customer = customer_portal_fetch_customer($store);
            $complaintSuccess = 'Complaint submitted successfully.';
        } catch (Throwable $exception) {
            $complaintErrors[] = $exception->getMessage();
        }
    }
}

$customerComplaints = get_complaints_by_customer((string) ($customer['mobile'] ?? ''));
$customerMobile = normalize_customer_mobile((string) ($customer['mobile'] ?? ''));
$customerQuotes = array_values(array_filter(documents_list_quotes(), static function (array $quote) use ($customerMobile): bool {
    return normalize_customer_mobile((string) ($quote['customer_mobile'] ?? '')) === $customerMobile
        && documents_quote_normalize_status((string) ($quote['status'] ?? 'draft')) === 'accepted'
        && !empty($quote['is_current_version']);
}));
function customer_dashboard_quote_tax_summary(array $quote): array
{
    $calc = is_array($quote['calc'] ?? null) ? $quote['calc'] : [];
    $taxBreakdown = is_array($calc['tax_breakdown'] ?? null) ? $calc['tax_breakdown'] : (is_array($quote['tax_breakdown'] ?? null) ? $quote['tax_breakdown'] : []);
    $gross = (float) ($taxBreakdown['gross_incl_gst'] ?? $calc['grand_total'] ?? $calc['final_price_incl_gst'] ?? $quote['input_total_gst_inclusive'] ?? 0);
    $taxable = (float) ($taxBreakdown['basic_total'] ?? $calc['basic_total'] ?? $calc['taxable_total'] ?? $calc['basic_value'] ?? 0);
    $gst = (float) ($taxBreakdown['gst_total'] ?? $calc['gst_total'] ?? $calc['total_gst'] ?? 0);
    if ($gst <= 0 && $gross > 0 && $taxable > 0) {
        $gst = max(0, $gross - $taxable);
    }
    return ['taxable' => $taxable, 'gst' => $gst];
}

$customerProjects = [];
foreach ($customerQuotes as $quote) {
    $quoteId = (string) ($quote['id'] ?? '');
    if ($quoteId === '') {
        continue;
    }
    $agreements = array_values(array_filter(documents_list_agreements(), static fn(array $row): bool => (string) ($row['linked_quote_id'] ?? '') === $quoteId && !documents_is_archived($row)));
    $dispatchAdvices = documents_dispatch_advices_for_quote($quoteId);
    $challans = documents_challans_for_quote($quoteId);
    $invoices = documents_invoices_for_quote($quoteId, true);
    $paymentSummary = documents_payment_summary_for_quote($quote);
    $paymentRequests = array_values(array_filter($paymentSummary['requests'], static function (array $request): bool {
        return !empty($request['visibility_to_customer']) && empty($request['archived_flag']) && !in_array(strtolower((string) ($request['status'] ?? '')), ['cancelled'], true);
    }));
    $receipts = documents_final_receipts_for_quote($quoteId);
    $invoiceValue = 0.0;
    foreach (documents_active_invoices_for_quote($quoteId) as $invoice) {
        if (documents_invoice_is_finalized($invoice)) {
            $invoiceValue += documents_invoice_final_total($invoice);
        }
    }
    $taxSummary = customer_dashboard_quote_tax_summary($quote);
    $gstTotal = (float) ($taxSummary['gst'] ?? 0);
    $taxableTotal = (float) ($taxSummary['taxable'] ?? 0);
    $customerProjects[] = [
        'quote' => $quote,
        'agreements' => $agreements,
        'dispatch_advices' => $dispatchAdvices,
        'challans' => $challans,
        'invoices' => $invoices,
        'payment_summary' => $paymentSummary,
        'payment_requests' => $paymentRequests,
        'receipts' => $receipts,
        'invoice_value' => $invoiceValue,
        'taxable_total' => $taxableTotal,
        'gst_total' => $gstTotal,
    ];
}
$customerQuote = $customerProjects[0]['quote'] ?? null;
$customerPaymentSummary = $customerProjects[0]['payment_summary'] ?? ['quotation_amount' => 0, 'total_received' => 0, 'outstanding' => 0, 'requests' => [], 'active_request_count' => 0, 'last_request' => null];
$customerPaymentRequests = $customerProjects[0]['payment_requests'] ?? [];
$customerFinalReceipts = $customerProjects[0]['receipts'] ?? [];
function customer_dashboard_doc_url(string $type, array $doc): string
{
    return 'customer-document-view.php?type=' . urlencode($type) . '&id=' . urlencode((string) ($doc['id'] ?? ''));
}
function customer_dashboard_doc_group_url(string $type, array $docs, string $quoteId): string
{
    $doc = $docs[0] ?? [];
    if (in_array($type, ['dispatch_advice', 'challan', 'receipt'], true) && count($docs) > 1 && $quoteId !== '') {
        return 'customer-document-view.php?' . http_build_query(['type' => $type, 'quote_id' => $quoteId]);
    }
    return customer_dashboard_doc_url($type, is_array($doc) ? $doc : []);
}
function customer_dashboard_doc_description(string $type, array $doc, string $fallback): string
{
    if ($type === 'quotation') {
        return 'Original quotation shared with you.';
    }
    if ($type === 'accepted_quotation') {
        return 'Your accepted project summary and customer-specific details.';
    }
    return (string) ($doc['quote_no'] ?? $doc['agreement_no'] ?? $doc['dispatch_advice_no'] ?? $doc['challan_no'] ?? $doc['invoice_no'] ?? $fallback);
}
function customer_dashboard_doc_action_label(string $type): string
{
    if ($type === 'quotation') {
        return 'View Quotation';
    }
    if ($type === 'accepted_quotation') {
        return 'View Accepted Details';
    }
    return 'View / Open';
}
function customer_dashboard_status_label(string $value): string
{
    $value = trim(str_replace('_', ' ', $value));
    return $value === '' ? 'Pending' : ucwords($value);
}
$customerInr = static fn(float $amount): string => quotation_format_inr_indian($amount, true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Customer Dashboard | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/admin-unified.css" />
  <style>
    .customer-dashboard-layout {
      display: flex;
      gap: 24px;
      align-items: flex-start;
    }
    .customer-dashboard-left,
    .customer-dashboard-center,
    .customer-dashboard-right {
      flex: 1 1 0;
    }
    .customer-dashboard-left {
      max-width: 260px;
    }
    .customer-dashboard-right {
      max-width: 320px;
    }
    .customer-dashboard-center {
      min-width: 0;
    }
    .dashboard-shell {
      min-height: 100vh;
      background: #f5f7fb;
      padding: 2.5rem 1rem;
    }
    .dashboard-card {
      background: #ffffff;
      width: 100%;
      border-radius: 16px;
      box-shadow: 0 20px 45px rgba(17, 24, 39, 0.12);
      padding: 2rem;
      border: 1px solid #e6eaf2;
      box-sizing: border-box;
    }
    .dashboard-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1rem;
      margin-bottom: 1.25rem;
    }
    .dashboard-title {
      margin: 0;
      font-size: 1.65rem;
      color: #1c2330;
    }
    .dashboard-subtitle {
      margin: 0.35rem 0 0;
      color: #4b5565;
    }
    .logout-link {
      color: #d92b2b;
      font-weight: 700;
      text-decoration: none;
      border: 1px solid #f3c0c0;
      padding: 0.5rem 0.85rem;
      border-radius: 10px;
      background: #fff5f5;
      transition: background 0.2s ease, transform 0.1s ease;
    }
    .logout-link:hover {
      background: #ffe8e8;
      transform: translateY(-1px);
    }
    .status-banner {
      background: linear-gradient(135deg, #1f4b99, #2d68d8);
      color: #ffffff;
      padding: 0.9rem 1.15rem;
      border-radius: 12px;
      font-weight: 700;
      margin-bottom: 1rem;
      box-shadow: 0 14px 30px rgba(45, 104, 216, 0.2);
    }
    .details-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1rem;
    }
    .details-tile {
      background: #f8fafc;
      border: 1px solid #e5eaf2;
      border-radius: 12px;
      padding: 1rem;
    }
    .tile-label {
      margin: 0;
      color: #6b7280;
      font-size: 0.9rem;
      font-weight: 600;
    }
    .tile-value {
      margin: 0.35rem 0 0;
      color: #111827;
      font-size: 1.05rem;
      font-weight: 700;
      word-break: break-word;
    }
    .handover-actions {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-top: 0.75rem;
    }
    .handover-card {
      position: static;
      margin-top: 1rem;
      width: 100%;
    }
    .handover-btn {
      display: inline-block;
      padding: 0.75rem 1.2rem;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 700;
    }
    .handover-btn--primary {
      background: #2563eb;
      color: #ffffff;
    }
    .handover-btn--secondary {
      background: #eef2ff;
      color: #1f2937;
      border: 1px solid #c7d2fe;
    }
    .complaints-section {
      margin-top: 2rem;
    }
    .complaints-card {
      background: #ffffff;
      border: 1px solid #e5eaf2;
      border-radius: 12px;
      padding: 1.25rem;
      margin-top: 1rem;
    }
    .complaints-card h2 {
      margin: 0 0 0.75rem;
      font-size: 1.25rem;
      color: #1c2330;
    }
    .complaint-form label {
      display: block;
      font-weight: 600;
      margin-bottom: 0.35rem;
      color: #374151;
    }
    .complaint-form input,
    .complaint-form textarea,
    .complaint-form select {
      width: 100%;
      padding: 0.75rem 0.85rem;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      font-size: 1rem;
      margin-bottom: 0.85rem;
      background: #f9fafb;
    }
    .complaint-form button {
      background: #2563eb;
      color: #ffffff;
      border: none;
      padding: 0.75rem 1.25rem;
      border-radius: 10px;
      font-weight: 700;
      cursor: pointer;
    }
    .complaint-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 0.75rem;
    }
    .complaint-table th,
    .complaint-table td {
      border: 1px solid #e5e7eb;
      padding: 0.65rem 0.75rem;
      text-align: left;
    }
    .complaint-table th {
      background: #f9fafb;
      font-weight: 700;
      color: #1f2937;
    }
    .complaints-table-wrapper {
      width: 100%;
      overflow-x: auto;
    }
    .alert-success {
      background: #ecfdf3;
      border: 1px solid #bbf7d0;
      color: #166534;
      padding: 0.75rem;
      border-radius: 10px;
      margin-bottom: 0.75rem;
    }
    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
      padding: 0.75rem;
      border-radius: 10px;
      margin-bottom: 0.75rem;
    }
    @media (max-width: 768px) {
      .customer-dashboard-layout {
        display: block;
      }
      .customer-dashboard-left,
      .customer-dashboard-center,
      .customer-dashboard-right {
        max-width: 100%;
        width: 100%;
        margin-bottom: 16px;
      }
      .customer-dashboard-left .dashboard-card,
      .customer-dashboard-center .dashboard-card,
      .customer-dashboard-right .dashboard-card {
        width: 100%;
      }
      .dashboard-shell {
        padding: 1.5rem 0.75rem;
      }
      .dashboard-card {
        padding: 1.25rem;
      }
      .dashboard-card + .dashboard-card {
        margin-top: 1rem;
      }
      .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .dashboard-title {
        font-size: 1.4rem;
      }
      .dashboard-subtitle {
        margin-top: 0.25rem;
      }
      .logout-link {
        width: 100%;
        text-align: center;
      }
      .status-banner {
        text-align: center;
      }
      .details-grid {
        grid-template-columns: 1fr;
      }
      .handover-card,
      .customer-status-card,
      .raise-complaint-card,
      .my-complaints-card {
        position: static;
        width: 100%;
        margin: 8px 0;
      }
      .handover-actions {
        flex-direction: column;
        align-items: stretch;
      }
      .handover-btn {
        text-align: center;
      }
      .complaints-section {
        margin-top: 1.5rem;
      }
      .complaints-card {
        padding: 1rem;
      }
      .complaints-table-wrapper {
        margin-top: 0.5rem;
      }
      .complaint-table {
        min-width: 640px;
      }
    }

    .portal-hero{background:linear-gradient(135deg,#0f172a,#2563eb 58%,#06b6d4);color:#fff;border-radius:24px;padding:2rem;box-shadow:0 24px 70px rgba(37,99,235,.25);margin-bottom:1.25rem;display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}.portal-hero h1{margin:0;font-size:clamp(1.8rem,4vw,3rem);letter-spacing:-.04em}.portal-hero p{margin:.5rem 0 0;color:rgba(255,255,255,.86)}.portal-shell{max-width:1280px;margin:0 auto}.portal-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:1.25rem}.portal-stack{display:grid;gap:1rem}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}.kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;box-shadow:0 12px 30px rgba(15,23,42,.06)}.kpi-label{margin:0;color:#64748b;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}.kpi-value{margin:.4rem 0 0;color:#0f172a;font-size:1.35rem;font-weight:900}.project-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:1.25rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}.project-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;border-bottom:1px solid #e2e8f0;padding-bottom:1rem;margin-bottom:1rem}.project-head h2{margin:0;color:#0f172a}.badge{display:inline-flex;border-radius:999px;padding:.35rem .7rem;font-size:.78rem;font-weight:900;background:#dcfce7;color:#166534}.badge.pending{background:#fef3c7;color:#92400e}.timeline{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.5rem;margin:1rem 0}.step{border:1px solid #e2e8f0;border-radius:14px;padding:.75rem;background:#f8fafc}.step.done{background:#ecfdf5;border-color:#bbf7d0}.step strong{display:block;font-size:.85rem}.step span{color:#64748b;font-size:.78rem}.doc-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}.doc-card{border:1px solid #e2e8f0;border-radius:16px;padding:1rem;background:#fbfdff;display:flex;flex-direction:column;gap:.65rem}.doc-card h3{margin:0;font-size:1rem;color:#0f172a}.doc-card p{margin:0;color:#64748b;font-size:.9rem}.doc-action{margin-top:auto;display:inline-flex;justify-content:center;text-decoration:none;border-radius:12px;padding:.65rem .85rem;font-weight:900;background:#2563eb;color:#fff}.doc-action.pending{background:#eef2f7;color:#64748b;pointer-events:none}.finance-table{width:100%;border-collapse:collapse;margin-top:.75rem}.finance-table th,.finance-table td{border-bottom:1px solid #e5e7eb;padding:.7rem;text-align:left}.finance-table th{font-size:.75rem;text-transform:uppercase;color:#64748b}.section-title{margin:0 0 .75rem;color:#0f172a}.support-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:1.25rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}.empty-note{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:14px;padding:1rem;color:#64748b}.legacy-section{margin-top:1rem}
    @media(max-width:1100px){.portal-grid{grid-template-columns:1fr}.kpi-grid{grid-template-columns:repeat(2,1fr)}.doc-grid{grid-template-columns:repeat(2,1fr)}.timeline{grid-template-columns:repeat(3,1fr)}}@media(max-width:640px){.portal-hero{display:block;padding:1.35rem}.kpi-grid,.doc-grid,.timeline{grid-template-columns:1fr}.project-head{display:block}.finance-table{min-width:640px}.portal-table-wrap{overflow-x:auto}}
  </style>
<?php require_once __DIR__ . '/includes/pwa_head.php'; ?></head>
<body><?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
  <div class="dashboard-shell">
    <main class="portal-shell">
      <section class="portal-hero">
        <div>
          <h1>Welcome, <?= customer_portal_safe($customer['name'] ?? 'Customer') ?></h1>
          <p>Your solar project documents, payments, service requests, and account details in one secure place.</p>
        </div>
        <a class="logout-link" href="logout.php">Log out</a>
      </section>

      <div class="portal-grid">
        <div class="portal-stack">
          <section class="kpi-grid" aria-label="Financial summary">
            <?php $portfolioTotal = array_sum(array_map(static fn(array $p): float => (float) ($p['payment_summary']['quotation_amount'] ?? 0), $customerProjects)); ?>
            <?php $portfolioPaid = array_sum(array_map(static fn(array $p): float => (float) ($p['payment_summary']['total_received'] ?? 0), $customerProjects)); ?>
            <?php $portfolioOutstanding = array_sum(array_map(static fn(array $p): float => (float) ($p['payment_summary']['outstanding'] ?? 0), $customerProjects)); ?>
            <?php $portfolioInvoices = array_sum(array_map(static fn(array $p): float => (float) ($p['invoice_value'] ?? 0), $customerProjects)); ?>
            <article class="kpi-card"><p class="kpi-label">Project value</p><p class="kpi-value"><?= customer_portal_safe($customerInr($portfolioTotal)) ?></p></article>
            <article class="kpi-card"><p class="kpi-label">Invoice value</p><p class="kpi-value"><?= customer_portal_safe($customerInr($portfolioInvoices)) ?></p></article>
            <article class="kpi-card"><p class="kpi-label">Paid amount</p><p class="kpi-value"><?= customer_portal_safe($customerInr($portfolioPaid)) ?></p></article>
            <article class="kpi-card"><p class="kpi-label">Outstanding</p><p class="kpi-value"><?= customer_portal_safe($customerInr($portfolioOutstanding)) ?></p></article>
          </section>

          <?php if ($customerProjects === []): ?>
            <section class="project-card"><h2 class="section-title">Projects</h2><div class="empty-note">No accepted quotation is linked to your portal yet. Once your quotation is accepted, your project documents will appear here automatically.</div></section>
          <?php endif; ?>

          <?php foreach ($customerProjects as $project): $quote = $project['quote']; $quoteNo = (string) ($quote['quote_no'] ?? $quote['id'] ?? 'Quotation'); ?>
            <article class="project-card">
              <header class="project-head">
                <div><h2><?= customer_portal_safe($quoteNo) ?></h2><p class="dashboard-subtitle"><?= customer_portal_safe((string) ($quote['capacity_kwp'] ?? '')) ?> kWp <?= customer_portal_safe((string) ($quote['system_type'] ?? 'Solar project')) ?> · Accepted <?= customer_portal_safe(substr((string) ($quote['accepted_at'] ?? ''), 0, 10) ?: '—') ?></p></div>
                <span class="badge"><?= customer_portal_safe(customer_dashboard_status_label((string) ($quote['status'] ?? 'accepted'))) ?></span>
              </header>

              <div class="timeline" aria-label="Project lifecycle">
                <div class="step done"><strong>Quotation</strong><span>Accepted</span></div>
                <div class="step <?= $project['agreements'] !== [] ? 'done' : '' ?>"><strong>Agreement</strong><span><?= $project['agreements'] !== [] ? 'Available' : 'Pending' ?></span></div>
                <div class="step <?= $project['dispatch_advices'] !== [] ? 'done' : '' ?>"><strong>Dispatch Advice</strong><span><?= $project['dispatch_advices'] !== [] ? 'Available' : 'Pending' ?></span></div>
                <div class="step <?= $project['challans'] !== [] ? 'done' : '' ?>"><strong>Delivery Challan</strong><span><?= $project['challans'] !== [] ? 'Available' : 'Pending' ?></span></div>
                <div class="step <?= $project['invoices'] !== [] ? 'done' : '' ?>"><strong>Invoice</strong><span><?= $project['invoices'] !== [] ? 'Available' : 'Pending' ?></span></div>
              </div>

              <h3 id="documents" class="section-title">Documents</h3>
              <div class="doc-grid">
                <?php $docGroups = [
                  ['Quotation', 'quotation', [$quote], $quoteNo],
                  ['Accepted Quotation Details', 'accepted_quotation', [$quote], 'Accepted project summary'],
                  ['Vendor Consumer Agreement', 'agreement', $project['agreements'], 'Vendor-consumer agreement'],
                  ['Payment Receipts', 'receipt', $project['receipts'], 'Finalized payment receipts'],
                  ['Dispatch Advice', 'dispatch_advice', $project['dispatch_advices'], 'Planned material dispatch'],
                  ['Delivery Challan', 'challan', $project['challans'], 'Delivery confirmation document'],
                  ['Invoice', 'invoice', $project['invoices'], 'Tax invoice'],
                ]; ?>
                <?php foreach ($docGroups as [$label, $type, $docs, $fallback]): $doc = $docs[0] ?? null; $docCount = count($docs); ?>
                  <article class="doc-card"><h3><?= customer_portal_safe($label) ?></h3><p><?= customer_portal_safe($doc ? ($docCount > 1 && in_array($type, ['dispatch_advice', 'challan', 'receipt'], true) ? $docCount . ' documents available' : customer_dashboard_doc_description($type, $doc, $fallback)) : 'Not generated yet') ?></p><?php if ($doc): ?><a class="doc-action" target="_blank" rel="noreferrer" href="<?= customer_portal_safe(customer_dashboard_doc_group_url($type, $docs, (string) ($quote['id'] ?? ''))) ?>"><?= customer_portal_safe($docCount > 1 && in_array($type, ['dispatch_advice', 'challan', 'receipt'], true) ? 'View List' : customer_dashboard_doc_action_label($type)) ?></a><?php else: ?><span class="doc-action pending">Pending</span><?php endif; ?></article>
                <?php endforeach; ?>
                <?php if ($handoverHtmlPath !== ''): ?><article class="doc-card"><h3>Handover pack</h3><p>System handover documents</p><a class="doc-action" target="_blank" rel="noreferrer" href="<?= customer_portal_safe('/' . ltrim($handoverHtmlPath, '/')) ?>">View / Print</a></article><?php endif; ?>
              </div>

              <h3 id="financials" class="section-title" style="margin-top:1.25rem">Financial summary</h3>
              <div class="details-grid">
                <div class="details-tile"><p class="tile-label">Total project / quotation value</p><p class="tile-value"><?= customer_portal_safe($customerInr((float) $project['payment_summary']['quotation_amount'])) ?></p></div>
                <div class="details-tile"><p class="tile-label">Invoice value</p><p class="tile-value"><?= customer_portal_safe($customerInr((float) $project['invoice_value'])) ?></p></div>
                <div class="details-tile"><p class="tile-label">Paid amount</p><p class="tile-value"><?= customer_portal_safe($customerInr((float) $project['payment_summary']['total_received'])) ?></p></div>
                <div class="details-tile"><p class="tile-label">Balance outstanding</p><p class="tile-value"><?= customer_portal_safe($customerInr((float) $project['payment_summary']['outstanding'])) ?></p></div>
                <div class="details-tile"><p class="tile-label">Taxable value</p><p class="tile-value"><?= ((float) $project['taxable_total'] > 0) ? customer_portal_safe($customerInr((float) $project['taxable_total'])) : '—' ?></p></div>
                <div class="details-tile"><p class="tile-label">GST / tax total</p><p class="tile-value"><?= ((float) $project['gst_total'] > 0) ? customer_portal_safe($customerInr((float) $project['gst_total'])) : '—' ?></p></div>
                <div class="details-tile"><p class="tile-label">Payment status</p><p class="tile-value"><?= ((float) $project['payment_summary']['outstanding'] <= 0 && (float) $project['payment_summary']['quotation_amount'] > 0) ? 'Paid' : (((float) $project['payment_summary']['total_received'] > 0) ? 'Partially paid' : 'Pending') ?></p></div>
              </div>

              <?php if ($project['payment_requests'] !== []): ?>
                <h3 class="section-title" style="margin-top:1rem">Active payment requests</h3>
                <div class="portal-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Amount</th><th>Reason</th><th>Due</th><th>Status</th></tr></thead><tbody><?php foreach ($project['payment_requests'] as $request): ?><tr><td><?= customer_portal_safe(substr((string) ($request['created_at'] ?? ''), 0, 10)) ?></td><td><?= customer_portal_safe($customerInr((float) ($request['amount_requested'] ?? 0))) ?></td><td><?= customer_portal_safe(documents_payment_request_reason_label($request)) ?></td><td><?= customer_portal_safe((string) ($request['due_date'] ?? '')) ?></td><td><?= customer_portal_safe(customer_dashboard_status_label((string) ($request['status'] ?? 'draft'))) ?></td></tr><?php endforeach; ?></tbody></table></div>
              <?php endif; ?>

              <h3 class="section-title" style="margin-top:1rem">Payment history / receipts</h3>
              <div class="portal-table-wrap">
                <table class="finance-table"><thead><tr><th>Date</th><th>Amount</th><th>Mode / Reference</th><th>Receipt</th></tr></thead><tbody>
                  <?php foreach ($project['receipts'] as $receipt): ?><tr><td><?= customer_portal_safe((string) ($receipt['date_received'] ?? $receipt['receipt_date'] ?? '')) ?></td><td><?= customer_portal_safe($customerInr((float) ($receipt['amount_rs'] ?? $receipt['amount_received'] ?? 0))) ?></td><td><?= customer_portal_safe(trim((string) ($receipt['mode'] ?? '') . ' ' . (string) ($receipt['txn_ref'] ?? $receipt['reference'] ?? ''))) ?></td><td><a target="_blank" rel="noreferrer" href="<?= customer_portal_safe(customer_dashboard_doc_url('receipt', $receipt)) ?>"><?= customer_portal_safe((string) ($receipt['receipt_number'] ?? $receipt['id'] ?? 'Receipt')) ?></a></td></tr><?php endforeach; ?>
                  <?php if ($project['receipts'] === []): ?><tr><td colspan="4">No finalized payment receipts are available yet.</td></tr><?php endif; ?>
                </tbody></table>
              </div>
            </article>
          <?php endforeach; ?>

          <section id="profile" class="project-card legacy-section"><h2 class="section-title">Your account information</h2><div class="status-banner" role="status">Current Status: <?= customer_portal_safe($customer['status'] ?? 'New') ?></div><div class="details-grid">
            <?php foreach ([['Mobile number','mobile'],['Customer type','customer_type'],['Address','address'],['City','city'],['District','district'],['PIN code','pin_code'],['State','state'],['Meter number','meter_number'],['Meter serial number','meter_serial_number'],['JBVNL account number','jbvnl_account_number'],['Application ID','application_id'],['Complaints raised','complaints_raised']] as [$label,$key]): ?><div class="details-tile"><p class="tile-label"><?= customer_portal_safe($label) ?></p><p class="tile-value"><?= customer_portal_safe($customer[$key] ?? '') ?></p></div><?php endforeach; ?>
          </div></section>
        </div>

        <aside class="portal-stack">
          <section class="support-card"><h2 class="section-title">Need help?</h2><p class="dashboard-subtitle">Raise a complaint or contact our team if you need help understanding a document, payment, or project stage.</p></section>
          <section class="complaints-section" aria-labelledby="raise-complaint">
            <div class="complaints-card raise-complaint-card">
              <h2 id="raise-complaint">Raise Complaint</h2>
              <?php if ($complaintSuccess !== ''): ?><div class="alert-success" role="status"><?= customer_portal_safe($complaintSuccess) ?></div><?php endif; ?>
              <?php if ($complaintErrors !== []): ?><div class="alert-error" role="alert"><ul style="padding-left: 1.25rem; margin: 0;"><?php foreach ($complaintErrors as $message): ?><li><?= customer_portal_safe($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
              <form method="post" class="complaint-form"><?= csrf_field() ?><input type="hidden" name="complaint_action" value="raise" /><label for="title">Title *</label><input id="title" name="title" type="text" required /><label for="description">Description *</label><textarea id="description" name="description" rows="4" required></textarea><label for="problem_category">Problem Category *</label><select id="problem_category" name="problem_category" required><option value="">Select a category</option><?php foreach ($problemCategories as $category): ?><option value="<?= customer_portal_safe($category) ?>"><?= customer_portal_safe($category) ?></option><?php endforeach; ?></select><button type="submit">Submit complaint</button></form>
            </div>
            <div class="complaints-card my-complaints-card" style="margin-top: 1.25rem;"><h2>My Complaints</h2><?php if ($customerComplaints === []): ?><p style="margin: 0; color: #4b5563;">No complaints found.</p><?php else: ?><div class="complaints-table-wrapper"><table class="complaint-table"><thead><tr><th>Title</th><th>Status</th><th>Problem Category</th><th>Created</th></tr></thead><tbody><?php foreach ($customerComplaints as $complaint): ?><tr><td><?= customer_portal_safe($complaint['title'] ?? '') ?></td><td><?= customer_portal_safe(ucfirst((string) ($complaint['status'] ?? ''))) ?></td><td><?= customer_portal_safe($complaint['problem_category'] ?? complaint_default_category()) ?></td><td><?= customer_portal_safe($complaint['created_at'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
          </section>
        </aside>
      </div>
    </main>
  </div>
</body>
</html>
