<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_login_any_role(['admin', 'employee']);
documents_ensure_structure();

$user = current_user();
$role = (string) ($user['role_name'] ?? '');
if (!in_array($role, ['admin', 'employee'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$rid = safe_text($_GET['rid'] ?? $_GET['id'] ?? '');
if ($rid === '') {
    http_response_code(400);
    exit('Receipt ID is required.');
}

$receipt = documents_get_sales_document('receipt', $rid);
if ($receipt === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

$quote = documents_get_quote((string) ($receipt['quotation_id'] ?? $receipt['quote_id'] ?? ''));
$company = load_company_profile();

$amount = (float) ($receipt['amount_rs'] ?? $receipt['amount_received'] ?? $receipt['amount'] ?? 0);
$dateReceived = (string) ($receipt['date_received'] ?? $receipt['receipt_date'] ?? $receipt['created_at'] ?? date('Y-m-d'));
$receiptNo = (string) ($receipt['receipt_number'] ?? $receipt['id'] ?? '');
$customerName = (string) ($receipt['customer_name_snapshot'] ?? $receipt['customer_name'] ?? '');
$mobile = (string) ($receipt['customer_mobile'] ?? '');
$siteAddress = (string) ($receipt['site_address_snapshot'] ?? ($quote['site_address'] ?? ''));
$quoteNo = (string) ($quote['quote_no'] ?? $receipt['quotation_id'] ?? '');

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Receipt <?= htmlspecialchars($receiptNo, ENT_QUOTES) ?></title>
  <style>
    :root { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; color: var(--ink); background: #f3f4f6; }
    .sheet { max-width: 794px; margin: 16px auto; background: #fff; min-height: 1123px; padding: 28px 34px; }
    .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
    .title { font-size: 24px; font-weight: 700; }
    .muted { color: var(--muted); }
    .row { display:flex; justify-content:space-between; gap:16px; margin: 8px 0; }
    .card { border:1px solid var(--line); border-radius:8px; padding:10px; margin-top:10px; }
    .sign { display:flex; justify-content:space-between; margin-top:70px; }
    .print-tools { margin: 10px auto; max-width:794px; }
    @media print {
      body { background: #fff; }
      .print-tools { display:none !important; }
      .sheet { margin:0; max-width:none; width:210mm; min-height:auto; padding:12mm 14mm; }
      @page { size:A4; margin:0; }
    }
  </style>
</head>
<body>
  <div class="print-tools"><button onclick="window.print()">Print</button></div>
  <main class="sheet">
    <header class="header">
      <div class="title"><?= htmlspecialchars((string) ($company['legal_name'] ?? 'Company'), ENT_QUOTES) ?></div>
      <div class="muted"><?= nl2br(htmlspecialchars((string) ($company['address'] ?? ''), ENT_QUOTES)) ?></div>
      <div class="muted">Phone: <?= htmlspecialchars((string) ($company['phone'] ?? ''), ENT_QUOTES) ?> | Email: <?= htmlspecialchars((string) ($company['email'] ?? ''), ENT_QUOTES) ?></div>
    </header>

    <h2 style="margin:8px 0 0;">Payment Receipt</h2>
    <div class="row"><div><strong>Receipt No:</strong> <?= htmlspecialchars($receiptNo, ENT_QUOTES) ?></div><div><strong>Date:</strong> <?= htmlspecialchars(substr($dateReceived,0,10), ENT_QUOTES) ?></div></div>
    <div class="card">
      <p><strong>Received From:</strong> <?= htmlspecialchars($customerName, ENT_QUOTES) ?></p>
      <p><strong>Mobile:</strong> <?= htmlspecialchars($mobile, ENT_QUOTES) ?></p>
      <p><strong>Site Address:</strong> <?= nl2br(htmlspecialchars($siteAddress, ENT_QUOTES)) ?></p>
    </div>

    <div class="card">
      <p><strong>Amount Received:</strong> ₹<?= htmlspecialchars(number_format($amount, 2), ENT_QUOTES) ?></p>
      <p><strong>Mode:</strong> <?= htmlspecialchars((string) ($receipt['mode'] ?? ''), ENT_QUOTES) ?></p>
      <p><strong>Transaction Ref:</strong> <?= htmlspecialchars((string) ($receipt['txn_ref'] ?? $receipt['reference'] ?? ''), ENT_QUOTES) ?></p>
      <p><strong>Status:</strong> <?= htmlspecialchars((string) ($receipt['status'] ?? 'draft'), ENT_QUOTES) ?></p>
    </div>

    <div class="card">
      <p><strong>Against Quotation:</strong> <?= htmlspecialchars($quoteNo, ENT_QUOTES) ?></p>
      <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars((string) ($receipt['notes'] ?? ''), ENT_QUOTES)) ?></p>
    </div>

    <div class="sign">
      <div>Customer Signature</div>
      <div>Authorized Signature</div>
    </div>
  </main>
</body>
</html>
