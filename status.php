<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

send_private_workspace_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Project Status | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <main class="admin-form" style="max-width:640px;margin:2rem auto;">
    <h1>Project &amp; Complaint Status</h1>
    <div class="admin-alert admin-alert--info" role="status">
      For your privacy, project, document, and complaint details are available only after signing in to the customer portal.
    </div>
    <p>The customer portal shows your current project status, documents, support requests, and next steps.</p>
    <p><a href="customer-login.php" class="btn btn-primary">Sign in to view status</a></p>
    <p><a href="contact.html" class="btn btn-secondary">Contact support</a></p>
  </main>
</body>
</html>
