<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$user = current_user();
$adminCsrfToken = $_SESSION['csrf_token'] ?? '';

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';

if (is_array($flashData)) {
    if (isset($flashData['message']) && is_string($flashData['message'])) {
        $flashMessage = trim($flashData['message']);
    }
    if (isset($flashData['type']) && is_string($flashData['type'])) {
        $flashTone = strtolower($flashData['type']);
    }
}

$module = 'customers';
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'lead')));
$showInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1';

$store = customer_record_store();
$list = $store->list([
    'state' => $filter,
    'active_status' => $showInactive ? 'all' : 'active',
]);

function format_admin_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Customers | Admin</title>
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
</head>
<body class="admin-records" data-theme="light">
<main class="admin-records__shell">
    <?php if ($flashMessage !== ''): ?>
        <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>">
            <i class="fa-solid fa-circle-info"></i>
            <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
        </div>
    <?php endif; ?>

    <header class="admin-records__header">
        <div class="admin-records__title-group">
            <a href="admin-dashboard.php" class="admin-records__back"><i class="fa-solid fa-arrow-left"></i> Overview</a>
            <h1>Customers</h1>
            <p>Manage all customer records, from lead to installation.</p>
        </div>
        <div class="admin-records__meta">
            <span>Signed in as <?= htmlspecialchars($user['full_name'] ?? 'Administrator', ENT_QUOTES) ?></span>
            <a href="logout.php" class="btn btn-ghost">Log out</a>
        </div>
    </header>

    <div class="admin-records__filter">
        <form method="get">
            <input type="hidden" name="module" value="customers" />
            <label>
                Filter by state
                <select name="filter" onchange="this.form.submit()">
                    <option value="lead" <?= $filter === 'lead' ? 'selected' : '' ?>>Lead</option>
                    <option value="ongoing" <?= $filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                    <option value="installed" <?= $filter === 'installed' ? 'selected' : '' ?>>Installed</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </label>
            <label>
                <input type="checkbox" name="inactive" value="1" onchange="this.form.submit()" <?= $showInactive ? 'checked' : '' ?> />
                Show inactive
            </label>
        </form>
    </div>

    <div class="admin-records__bulk-actions">
        <label for="bulk-action">Bulk Actions:</label>
        <select id="bulk-action" name="bulk_action">
            <option value="">--Select Action--</option>
            <option value="change_state" data-state="ongoing">Move to Ongoing</option>
            <option value="change_state" data-state="installed">Move to Installed</option>
            <option value="assign_employee">Assign Employee</option>
            <option value="assign_installer">Assign Installer</option>
            <option value="delete">Delete</option>
            <option value="deactivate">Deactivate</option>
            <option value="reactivate">Reactivate</option>
            <option value="export">Export Selected</option>
        </select>
        <button id="bulk-apply" class="btn btn-primary">Apply</button>
    </div>

    <section class="admin-panel" aria-labelledby="csv-import">
        <div class="admin-panel__header">
            <h2 id="csv-import">CSV Import / Export</h2>
        </div>
        <div class="admin-form">
            <a href="customer-records-template.php?type=leads" class="btn btn-secondary">Download Sample CSV</a>
            <form id="csv-upload-form" method="post" enctype="multipart/form-data">
                <label for="csv-file">Upload CSV:</label>
                <input type="file" id="csv-file" name="csv_file" accept=".csv" />
                <label>
                    <input type="checkbox" id="csv-dry-run" name="dry_run" value="1" />
                    Dry Run (validate only)
                </label>
                <button type="submit" class="btn btn-primary">Import</button>
            </form>
        </div>
    </section>

    <section class="admin-records__table" aria-live="polite">
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                <tr>
                    <th scope="col"><input type="checkbox" id="select-all" /></th>
                    <th scope="col">Name</th>
                    <th scope="col">Phone</th>
                    <th scope="col">District</th>
                    <th scope="col">State</th>
                    <th scope="col">Status</th>
                    <th scope="col">Updated</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($list['items'])): ?>
                    <tr>
                        <td colspan="8" class="admin-empty">No customers found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list['items'] as $customer): ?>
                        <tr>
                            <td><input type="checkbox" name="customer_ids[]" value="<?= $customer['id'] ?>" /></td>
                            <td><?= htmlspecialchars($customer['full_name'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($customer['phone'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($customer['district'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars(ucfirst($customer['state']), ENT_QUOTES) ?></td>
                            <td><?= $customer['active'] ? 'Active' : 'Inactive' ?></td>
                            <td><?= format_admin_datetime($customer['updated_at']) ?></td>
                            <td>
                                <div class="admin-table__actions">
                                    <?php if ($customer['state'] === 'lead'): ?>
                                        <button class="btn btn-sm" onclick="changeState(<?= $customer['id'] ?>, 'ongoing')">Move to Ongoing</button>
                                    <?php elseif ($customer['state'] === 'ongoing'): ?>
                                        <button class="btn btn-sm" onclick="changeState(<?= $customer['id'] ?>, 'installed')">Move to Installed</button>
                                    <?php endif; ?>
                                    <?php if ($customer['active']): ?>
                                        <button class="btn btn-sm btn-warning" onclick="deactivateCustomer(<?= $customer['id'] ?>)">Deactivate</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-success" onclick="reactivateCustomer(<?= $customer['id'] ?>)">Reactivate</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger" onclick="deleteCustomer(<?= $customer['id'] ?>)">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="admin-dashboard.js" defer></script>
</body>
</html>
