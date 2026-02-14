<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$rows = documents_list_agreements();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Agreements</title><style>body{font-family:Arial;background:#f4f6fa;margin:0}.wrap{padding:16px}.card{background:#fff;border:1px solid #dbe1ea;border-radius:12px;padding:14px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe1ea;padding:8px;text-align:left}.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:8px;padding:8px 12px}.btn.secondary{background:#fff;color:#1f2937;border:1px solid #cbd5e1}</style></head><body><main class="wrap"><div class="card"><h1>Agreements</h1><a class="btn secondary" href="admin-documents.php">Back</a></div><div class="card"><table><thead><tr><th>Agreement No</th><th>Customer</th><th>Status</th><th>Quote</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= htmlspecialchars((string)$row['agreement_no'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$row['customer_name'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES) ?></td><td><?= htmlspecialchars((string)$row['source_quote_no'], ENT_QUOTES) ?></td><td><a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string)$row['id']) ?>">Open</a></td></tr><?php endforeach; if($rows===[]): ?><tr><td colspan="5">No agreements yet.</td></tr><?php endif; ?></tbody></table></div></main></body></html>
