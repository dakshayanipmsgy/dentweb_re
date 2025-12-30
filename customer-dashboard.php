<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/customer_complaints.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Customer Dashboard | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
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
  </style>
</head>
<body>
  <div class="dashboard-shell">
    <div class="customer-dashboard-layout">
      <div class="customer-dashboard-left">
        <div class="dashboard-card">
          <div class="dashboard-header">
            <div>
              <h1 class="dashboard-title">Welcome, <?= customer_portal_safe($customer['name'] ?? 'Customer') ?></h1>
              <p class="dashboard-subtitle">Here are your account details as registered with us.</p>
            </div>
            <a class="logout-link" href="logout.php">Log out</a>
          </div>
        </div>
      </div>

      <div class="customer-dashboard-center">
        <div class="dashboard-card customer-status-card">
          <div class="status-banner" role="status">Current Status: <?= customer_portal_safe($customer['status'] ?? 'New') ?></div>
          <div class="details-grid">
            <div class="details-tile">
              <p class="tile-label">Mobile number</p>
              <p class="tile-value"><?= customer_portal_safe($customer['mobile'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Customer type</p>
              <p class="tile-value"><?= customer_portal_safe($customer['customer_type'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Address</p>
              <p class="tile-value"><?= customer_portal_safe($customer['address'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">City</p>
              <p class="tile-value"><?= customer_portal_safe($customer['city'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">District</p>
              <p class="tile-value"><?= customer_portal_safe($customer['district'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">PIN code</p>
              <p class="tile-value"><?= customer_portal_safe($customer['pin_code'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">State</p>
              <p class="tile-value"><?= customer_portal_safe($customer['state'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Meter number</p>
              <p class="tile-value"><?= customer_portal_safe($customer['meter_number'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Meter serial number</p>
              <p class="tile-value"><?= customer_portal_safe($customer['meter_serial_number'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">JBVNL account number</p>
              <p class="tile-value"><?= customer_portal_safe($customer['jbvnl_account_number'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Application ID</p>
              <p class="tile-value"><?= customer_portal_safe($customer['application_id'] ?? '') ?></p>
            </div>
            <div class="details-tile">
              <p class="tile-label">Complaints raised</p>
              <p class="tile-value"><?= customer_portal_safe($customer['complaints_raised'] ?? '') ?></p>
            </div>
          </div>
        </div>

        <div class="dashboard-card handover-card">
          <h2 style="margin-top: 0; margin-bottom: 0.25rem;">Handover Documents</h2>
          <?php if ($handoverHtmlPath !== ''): ?>
            <p style="margin: 0; color: #4b5563;">Your handover pack is ready to view or print.</p>
            <div class="handover-actions">
              <a class="handover-btn handover-btn--primary" target="_blank" rel="noreferrer" href="<?= customer_portal_safe('/' . ltrim($handoverHtmlPath, '/')) ?>">Print Handover Pack</a>
              <a class="handover-btn handover-btn--secondary" target="_blank" rel="noreferrer" href="<?= customer_portal_safe('/' . ltrim($handoverHtmlPath, '/')) ?>">View as HTML</a>
            </div>
            <?php if (($customer['handover_generated_at'] ?? '') !== ''): ?>
              <p style="margin: 0.5rem 0 0; color: #6b7280;">Generated on <?= customer_portal_safe((string) $customer['handover_generated_at']) ?></p>
            <?php endif; ?>
          <?php else: ?>
            <p style="margin: 0; color: #4b5563;">Your handover documents are not yet generated. Please contact support if you think this is an error.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="customer-dashboard-right">
        <section class="complaints-section" aria-labelledby="raise-complaint">
          <div class="complaints-card raise-complaint-card">
            <h2 id="raise-complaint">Raise Complaint</h2>
            <?php if ($complaintSuccess !== ''): ?>
              <div class="alert-success" role="status"><?= customer_portal_safe($complaintSuccess) ?></div>
            <?php endif; ?>
            <?php if ($complaintErrors !== []): ?>
              <div class="alert-error" role="alert">
                <ul style="padding-left: 1.25rem; margin: 0;">
                  <?php foreach ($complaintErrors as $message): ?>
                    <li><?= customer_portal_safe($message) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <form method="post" class="complaint-form">
              <input type="hidden" name="complaint_action" value="raise" />
              <label for="title">Title *</label>
              <input id="title" name="title" type="text" required />
              <label for="description">Description *</label>
              <textarea id="description" name="description" rows="4" required></textarea>
              <label for="problem_category">Problem Category *</label>
              <select id="problem_category" name="problem_category" required>
                <option value="">Select a category</option>
                <?php foreach ($problemCategories as $category): ?>
                  <option value="<?= customer_portal_safe($category) ?>"><?= customer_portal_safe($category) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit">Submit complaint</button>
            </form>
          </div>

          <div class="complaints-card my-complaints-card" style="margin-top: 1.25rem;">
            <h2>My Complaints</h2>
            <?php if ($customerComplaints === []): ?>
              <p style="margin: 0; color: #4b5563;">No complaints found.</p>
            <?php else: ?>
              <div class="complaints-table-wrapper">
                <table class="complaint-table">
                  <thead>
                    <tr>
                      <th scope="col">Title</th>
                      <th scope="col">Status</th>
                      <th scope="col">Problem Category</th>
                      <th scope="col">Assignee</th>
                      <th scope="col">Created</th>
                      <th scope="col">Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($customerComplaints as $complaint): ?>
                      <tr>
                        <td><?= customer_portal_safe($complaint['title'] ?? '') ?></td>
                        <td><?= customer_portal_safe(ucfirst((string) ($complaint['status'] ?? ''))) ?></td>
                        <td><?= customer_portal_safe($complaint['problem_category'] ?? complaint_default_category()) ?></td>
                        <td><?= customer_portal_safe(complaint_display_assignee($complaint['assignee'] ?? '')) ?></td>
                        <td><?= customer_portal_safe($complaint['created_at'] ?? '') ?></td>
                        <td><?= customer_portal_safe(substr((string) ($complaint['description'] ?? ''), 0, 120)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</body>
</html>
