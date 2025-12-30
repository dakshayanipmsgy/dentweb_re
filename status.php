<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_complaints.php';

$statusRecord = null;
$complaints = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobile = public_normalize_mobile((string) ($_POST['mobile'] ?? ''));
    if ($mobile === '') {
        $message = 'Enter a valid mobile number to view your status.';
    } else {
        $statusRecord = public_customer_status($mobile);
        if ($statusRecord === null) {
            $message = 'No project found for this mobile number.';
        } else {
            $complaints = customer_complaint_by_mobile($mobile);
        }
    }
}
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
  <main class="admin-form" style="max-width:960px;margin:2rem auto;">
    <h1>Project & Complaint Status</h1>
    <p>Check your installation progress and complaint updates using your mobile number.</p>

    <?php if ($message !== ''): ?>
      <div class="admin-alert admin-alert--warning" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form" style="margin-bottom:2rem;">
      <label>Mobile number
        <input type="tel" name="mobile" value="<?php echo htmlspecialchars($_POST['mobile'] ?? '', ENT_QUOTES); ?>" required />
      </label>
      <button type="submit" class="btn btn-primary">View status</button>
    </form>

    <?php if ($statusRecord !== null): ?>
      <section class="admin-panel">
        <div class="admin-panel__header"><h2>Project Summary</h2></div>
        <div class="admin-grid">
          <div><strong>Name</strong><p><?php echo htmlspecialchars($statusRecord['full_name'] ?? '', ENT_QUOTES); ?></p></div>
          <div><strong>Project type</strong><p><?php echo htmlspecialchars($statusRecord['lead_source'] ?? ($statusRecord['pm_surya_ghar'] ?? ''), ENT_QUOTES); ?></p></div>
          <div><strong>CRM stage</strong><p><?php echo htmlspecialchars($statusRecord['crm_stage'] ?? 'Lead Received', ENT_QUOTES); ?></p></div>
          <div><strong>Progress</strong><p><?php echo (int) ($statusRecord['progress_percent'] ?? 0); ?>%</p></div>
          <div><strong>Discom stage</strong><p><?php echo htmlspecialchars($statusRecord['discom'] ?? '—', ENT_QUOTES); ?></p></div>
          <div><strong>Installation status</strong><p><?php echo htmlspecialchars($statusRecord['installation_status'] ?? '—', ENT_QUOTES); ?></p></div>
          <div><strong>Meter</strong><p><?php echo htmlspecialchars(($statusRecord['meter_brand'] ?? '') . ' ' . ($statusRecord['meter_serial'] ?? ''), ENT_QUOTES); ?></p></div>
          <div><strong>Loan</strong><p><?php echo htmlspecialchars(($statusRecord['loan_taken'] ?? 'No') === 'Yes' ? (($statusRecord['loan_bank_name'] ?? 'Bank') . ' · ' . ($statusRecord['loan_amount'] ?? '')) : 'No loan', ENT_QUOTES); ?></p></div>
        </div>
      </section>

      <section class="admin-panel" style="margin-top:1.5rem;">
        <div class="admin-panel__header"><h2>Complaints</h2></div>
        <?php if ($complaints === []): ?>
          <p>No complaints found for this mobile number.</p>
        <?php else: ?>
          <table class="admin-table">
            <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Problem Category</th><th>Assignee</th><th>Created</th><th>Updated</th></tr></thead>
            <tbody>
              <?php foreach ($complaints as $complaint): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string) ($complaint['id'] ?? ''), ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($complaint['title'] ?? '', ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($complaint['status'] ?? '', ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($complaint['problem_category'] ?? complaint_default_category(), ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars(complaint_display_assignee($complaint['assignee'] ?? ''), ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($complaint['created_at'] ?? '', ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($complaint['updated_at'] ?? '', ENT_QUOTES); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
