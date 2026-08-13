<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';
require_once __DIR__ . '/includes/document_signature.php';

require_admin();
$signature = document_signature_universal_reference();
$status = safe_text((string)($_GET['status'] ?? ''));
$message = safe_text((string)($_GET['message'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Universal Document Signature</title>
  <link rel="stylesheet" href="assets/css/admin-unified.css">
</head>
<body class="admin-shell commercial-admin">
<main class="commercial-shell">
  <header class="card commercial-header">
    <div>
      <p class="admin-kicker">Admin · Document settings</p>
      <h1>Universal Document Signature</h1>
      <p>Set the default Authorized Signatory image for quotations, Dispatch Advices, Challans, and invoices.</p>
    </div>
    <nav class="commercial-header__actions"><a class="btn secondary" href="admin-dashboard.php">Back to Dashboard</a></nav>
  </header>

  <?php if ($message !== ''): ?>
    <div class="flash <?= $status === 'error' ? 'error' : 'success' ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <section class="card">
    <h2>Default signature</h2>
    <p class="muted-helper">This signature is automatically used when a document has no individual signature. An individual document signature always takes priority.</p>
    <?= document_signature_admin_controls(['id' => 'global', 'signature' => $signature], 'universal', 'admin-document-signature-settings.php', true) ?>
  </section>

  <section class="card">
    <h2>How overrides work</h2>
    <ul>
      <li>Upload here once to use the image on all supported documents.</li>
      <li>Upload from a draft document editor to override this default for only that document.</li>
      <li>Remove a document override to make it use this universal signature again.</li>
    </ul>
  </section>
</main>
</body>
</html>
