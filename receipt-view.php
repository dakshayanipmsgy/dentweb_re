<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_document_security.php';
protect_customer_document_response();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/customer_receipt_access.php';
$customerView = (string) ($_GET['customer_view'] ?? '') === '1';
$customer = null;
if ($customerView) {
    customer_portal_require_login();
    $customer = customer_portal_fetch_customer(new CustomerFsStore());
    if ($customer === null) { customer_portal_logout(); header('Location: customer-login.php'); exit; }
} else {
    require_login_any_role(['admin', 'employee']);
    $user = current_user();
    if (!in_array((string) ($user['role_name'] ?? ''), ['admin', 'employee'], true)) {
        http_response_code(403); exit('Access denied.');
    }
}
documents_ensure_structure();

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

$quote = documents_get_quote(documents_receipt_quote_id($receipt));
if ($customerView && !customer_receipt_can_view($receipt, $quote, $customer, documents_receipt_ledger(documents_receipt_quote_id($receipt)))) {
    http_response_code(404); exit('Receipt unavailable.');
}
$company = documents_get_company_profile_for_quotes();

$companyName = trim((string) ($company['company_name'] ?? ''));
if ($companyName === '') {
    $companyName = trim((string) ($company['brand_name'] ?? ''));
}

$companyPhone = trim((string) ($company['phone_primary'] ?? ($company['phone'] ?? '')));

$companyAddress = trim((string) ($company['address_line'] ?? ($company['address'] ?? '')));
if ($companyAddress === '') {
    $parts = [
        trim((string) ($company['city'] ?? '')),
        trim((string) ($company['district'] ?? '')),
        trim((string) ($company['state'] ?? '')),
        trim((string) ($company['pin'] ?? '')),
    ];
    $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
    $companyAddress = implode(', ', $parts);
}

$companyLogo = trim((string) ($company['logo_path'] ?? ''));

$amount = documents_receipt_amount_total($receipt);
$dateReceived = (string) ($receipt['date_received'] ?? $receipt['receipt_date'] ?? $receipt['created_at'] ?? date('Y-m-d'));
$receiptNo = (string) ($receipt['receipt_number'] ?? $receipt['id'] ?? '');
$customerName = (string) ($receipt['customer_name_snapshot'] ?? $receipt['customer_name'] ?? $quote['customer_name'] ?? $quote['customer_snapshot']['name'] ?? '');
$mobile = (string) ($receipt['customer_mobile'] ?? $quote['customer_mobile'] ?? $quote['customer_snapshot']['mobile'] ?? '');
$siteAddress = (string) ($receipt['site_address_snapshot'] ?? ($quote['site_address'] ?? ''));
$quoteNo = (string) ($quote['quote_no'] ?? $receipt['quotation_id'] ?? '');

?><!doctype html>
<html lang="en">
<head>
  <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
<meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Payment Receipt <?= htmlspecialchars($receiptNo, ENT_QUOTES) ?></title>
  <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
  <style>
    :root { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; color: var(--ink); background: #f3f4f6; }
    .sheet { max-width: 794px; margin: 16px auto; background: #fff; min-height: 1123px; padding: 28px 34px; }
    .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; display:flex; gap:14px; align-items:center; }
    .header-logo { flex:0 0 auto; }
    .header-logo img { max-height: 56px; width: auto; display:block; }
    .header-text { min-width:0; }
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
      .header { break-inside: avoid; }
      .header-logo img { max-height: 52px; }
      @page { size:A4; margin:0; }
    }
  .print-tools{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px}.print-tools a{color:#115e59}.print-tools button{border:0;background:#0f766e;color:white;padding:12px 18px;border-radius:10px;font-weight:700;cursor:pointer}.sheet{border:1px solid #dbe4ef;border-radius:18px;min-height:0;box-shadow:0 16px 50px #0f172a12}.header{border-color:#0f766e}.card{padding:14px 18px;overflow-wrap:anywhere}.card p{margin:10px 0}.sign{padding-top:18px;gap:24px;margin-top:54px}.sign>div{border-top:1px solid #94a3b8;padding-top:12px;min-width:180px}.row{flex-wrap:wrap}.card:nth-of-type(3){border-left:4px solid #0f766e}@media screen and (max-width:600px){.sheet{margin:10px;padding:20px;border-radius:12px}.sign{flex-wrap:wrap}.header{align-items:flex-start}.title{font-size:20px}}@media print{@page{size:A4 portrait;margin:14mm}.sheet{width:auto;padding:0;border:0;border-radius:0;box-shadow:none}.mobile-app-nav{display:none!important}.card,.sign{break-inside:avoid}body{font-size:11pt;padding:0!important}.sheet{padding:0!important}.print-tools{display:none!important}}
  </style>
</head>
<body><?php require_once __DIR__ . '/includes/mobile_app_nav.php'; ?>
  <div class="print-tools"><?php if ($customerView): ?><a href="customer-dashboard.php">← Back to dashboard</a><?php endif; ?> <button type="button" onclick="window.print()">Print / Save PDF</button><span> A4 · Portrait</span></div>
  <main class="sheet">
    <header class="header">
      <?php if ($companyLogo !== ''): ?>
      <div class="header-logo"><img src="<?= htmlspecialchars($companyLogo, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName !== '' ? $companyName . ' logo' : 'Company logo', ENT_QUOTES) ?>" /></div>
      <?php endif; ?>
      <div class="header-text">
        <?php if ($companyName !== ''): ?><div class="title"><?= htmlspecialchars($companyName, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($companyPhone !== ''): ?><div class="muted"><?= htmlspecialchars($companyPhone, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($companyAddress !== ''): ?><div class="muted"><?= nl2br(htmlspecialchars($companyAddress, ENT_QUOTES)) ?></div><?php endif; ?>
      </div>
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
