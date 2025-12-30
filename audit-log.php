<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';

require_admin();

$entries = audit_read_recent(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Audit Log | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    body { background: #f5f7fb; font-family: 'Poppins', 'Segoe UI', sans-serif; }
    .audit-shell { max-width: 1100px; margin: 2rem auto; padding: 1rem; }
    .audit-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; box-shadow: 0 14px 36px rgba(0,0,0,0.06); }
    .audit-table { width: 100%; border-collapse: collapse; }
    .audit-table th, .audit-table td { padding: 0.65rem 0.75rem; border: 1px solid #e5e7eb; text-align: left; font-size: 0.95rem; }
    .audit-table th { background: #f9fafb; font-weight: 700; }
    .muted { color: #6b7280; }
    .back-link { display: inline-flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; text-decoration: none; color: #1f4b99; font-weight: 700; }
    .audit-empty { padding: 1rem; text-align: center; color: #6b7280; }
  </style>
</head>
<body>
  <div class="audit-shell">
    <a class="back-link" href="admin-dashboard.php">← Back to dashboard</a>
    <div class="audit-card">
      <h1 style="margin-top:0;">Audit Log</h1>
      <p class="muted" style="margin-top:0;">Recent customer and complaint activity across admin and employee portals.</p>
      <?php if ($entries === []): ?>
        <div class="audit-empty">No audit entries recorded yet.</div>
      <?php else: ?>
      <div class="admin-table-wrapper">
        <table class="audit-table" aria-label="Audit log entries">
          <thead>
            <tr>
              <th scope="col">Timestamp</th>
              <th scope="col">Actor</th>
              <th scope="col">Entity</th>
              <th scope="col">Action</th>
              <th scope="col">Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $entry): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($entry['timestamp'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(($entry['actor_type'] ?? 'unknown') . ' · ' . ($entry['actor_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($entry['entity_type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($entry['entity_key']) ? ' #' . htmlspecialchars((string) $entry['entity_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></td>
              <td><?= htmlspecialchars((string) ($entry['action'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              <td><code style="white-space:pre-wrap; font-size:0.85rem;"><?= htmlspecialchars(json_encode($entry['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
