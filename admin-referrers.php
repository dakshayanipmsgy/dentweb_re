<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$admin = current_user();

$csrfToken = $_SESSION['csrf_token'] ?? '';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-referrers.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $redirect = 'admin-referrers.php';
    $redirectId = (int) ($_POST['referrer_id'] ?? 0);

    try {
        switch ($action) {
            case 'create-referrer':
                $created = file_admin_create_referrer($_POST);
                $redirectId = (int) ($created['id'] ?? 0);
                set_flash('success', 'Referrer added successfully.');
                break;
            case 'update-referrer':
                $referrerId = (int) ($_POST['id'] ?? 0);
                if ($referrerId <= 0) {
                    throw new RuntimeException('Referrer reference missing.');
                }
                file_admin_update_referrer($referrerId, $_POST);
                $redirectId = $referrerId;
                set_flash('success', 'Referrer details updated.');
                break;
            case 'assign-lead':
                $referrerId = (int) ($_POST['id'] ?? 0);
                $leadId = (int) ($_POST['lead_id'] ?? 0);
                if ($referrerId <= 0 || $leadId <= 0) {
                    throw new RuntimeException('Select a referrer and lead to assign.');
                }
                file_admin_assign_referrer($leadId, $referrerId, (int) ($admin['id'] ?? 0));
                $redirectId = $referrerId;
                set_flash('success', 'Lead linked to referrer.');
                break;
            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    if ($redirectId > 0) {
        $redirect .= '?id=' . $redirectId;
    }

    header('Location: ' . $redirect);
    exit;
}

$statusOptions = admin_referrer_status_options();
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$validStatuses = array_merge(['all'], array_keys($statusOptions));
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

$referrers = file_admin_list_referrers($statusFilter);
$selectedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$selectedReferrer = null;
$referrerLeads = [];
if ($selectedId > 0) {
    try {
        $selectedReferrer = file_referrer_with_metrics($selectedId);
        $referrerLeads = file_admin_referrer_leads($selectedId);
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
        $selectedReferrer = null;
        $referrerLeads = [];
    }
}

$unassignedLeads = file_admin_unassigned_leads();

function format_admin_date(?string $value): string
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Referrers &amp; Channel Partners | Admin</title>
  <meta name="description" content="Manage referral partners, update their status, and review lead conversions." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
  />
</head>
<body class="admin-referrers" data-theme="light">
  <main class="admin-referrers__shell">
    <header class="admin-referrers__header">
      <div>
        <p class="admin-referrers__subtitle">Admin workspace</p>
        <h1 class="admin-referrers__title">Referrers &amp; Channel Partners</h1>
        <p class="admin-referrers__meta">Signed in as <strong><?= htmlspecialchars($admin['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-referrers__actions">
        <a href="admin-dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to overview</a>
        <a href="logout.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
      </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <section class="admin-panel" aria-labelledby="referrer-create">
      <div class="admin-panel__header">
        <div>
          <h2 id="referrer-create">Add referrer</h2>
          <p>Capture partner contact details and set their onboarding status.</p>
        </div>
      </div>
      <form method="post" class="admin-form">
        <input type="hidden" name="action" value="create-referrer" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <div class="admin-form__grid">
          <label>
            Name
            <input type="text" name="name" required placeholder="Individual or organisation" />
          </label>
          <label>
            Company
            <input type="text" name="company" placeholder="Organisation name" />
          </label>
          <label>
            Email
            <input type="email" name="email" placeholder="partner@example.com" />
          </label>
          <label>
            Phone
            <input type="tel" name="phone" placeholder="10-digit contact" pattern="[0-9]{10}" />
          </label>
          <label>
            Status
            <select name="status">
              <?php foreach ($statusOptions as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label class="admin-form__full">
          Notes
          <textarea name="notes" rows="3" placeholder="Commercial terms, geography, or onboarding notes."></textarea>
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Add referrer</button>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="referrer-list">
      <div class="admin-panel__header">
        <div>
          <h2 id="referrer-list">Referrer directory</h2>
          <p>Filter by activation status and review conversion performance.</p>
        </div>
        <form method="get" class="admin-referrers__filter">
          <label>
            Status
            <select name="status" onchange="this.form.submit()">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All referrers</option>
              <?php foreach ($statusOptions as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($selectedId > 0): ?>
          <input type="hidden" name="id" value="<?= (int) $selectedId ?>" />
          <?php endif; ?>
        </form>
      </div>
      <?php if (empty($referrers)): ?>
      <p class="admin-empty">No referrers found for this filter.</p>
      <?php else: ?>
      <div class="admin-table-wrapper">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Referrer</th>
              <th scope="col">Status</th>
              <th scope="col">Total leads</th>
              <th scope="col">Converted</th>
              <th scope="col">Pipeline</th>
              <th scope="col">Lost</th>
              <th scope="col">Conversion rate</th>
              <th scope="col">Last lead</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($referrers as $referrer): ?>
            <tr <?= $selectedId === (int) $referrer['id'] ? 'class="is-selected"' : '' ?>>
              <td>
                <a href="admin-referrers.php?id=<?= (int) $referrer['id'] ?>&status=<?= urlencode($statusFilter) ?>" class="admin-link">
                  <?= htmlspecialchars($referrer['name'], ENT_QUOTES) ?>
                </a>
                <?php if ($referrer['company']): ?>
                <div class="admin-muted"><?= htmlspecialchars($referrer['company'], ENT_QUOTES) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="admin-badge admin-badge--<?= htmlspecialchars($referrer['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($referrer['statusLabel'], ENT_QUOTES) ?></span></td>
              <td><?= (int) $referrer['metrics']['total'] ?></td>
              <td><?= (int) $referrer['metrics']['converted'] ?></td>
              <td><?= (int) $referrer['metrics']['pipeline'] ?></td>
              <td><?= (int) $referrer['metrics']['lost'] ?></td>
              <td><?= $referrer['metrics']['conversionRate'] !== null ? htmlspecialchars(number_format((float) $referrer['metrics']['conversionRate'], 1) . '%', ENT_QUOTES) : '—' ?></td>
              <td><?= $referrer['metrics']['latestLeadUpdate'] ? htmlspecialchars(format_admin_date($referrer['metrics']['latestLeadUpdate']), ENT_QUOTES) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($selectedReferrer): ?>
    <section class="admin-panel" aria-labelledby="referrer-detail">
      <div class="admin-panel__header">
        <div>
          <h2 id="referrer-detail">Referrer detail</h2>
          <p>Update partner information and review their funnel health.</p>
        </div>
      </div>
      <div class="referrer-detail">
        <div class="referrer-detail__summary">
          <div>
            <h3><?= htmlspecialchars($selectedReferrer['name'], ENT_QUOTES) ?></h3>
            <?php if ($selectedReferrer['company']): ?>
            <p class="admin-muted"><?= htmlspecialchars($selectedReferrer['company'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <ul class="referrer-summary">
              <li>
                <span>Total leads</span>
                <strong><?= (int) $selectedReferrer['metrics']['total'] ?></strong>
              </li>
              <li>
                <span>Converted</span>
                <strong><?= (int) $selectedReferrer['metrics']['converted'] ?></strong>
              </li>
              <li>
                <span>Pipeline</span>
                <strong><?= (int) $selectedReferrer['metrics']['pipeline'] ?></strong>
              </li>
              <li>
                <span>Conversion rate</span>
                <strong><?= $selectedReferrer['metrics']['conversionRate'] !== null ? htmlspecialchars(number_format((float) $selectedReferrer['metrics']['conversionRate'], 1) . '%', ENT_QUOTES) : '—' ?></strong>
              </li>
            </ul>
          </div>
          <div class="referrer-detail__meta">
            <p><i class="fa-solid fa-envelope"></i> <?= $selectedReferrer['email'] ? htmlspecialchars($selectedReferrer['email'], ENT_QUOTES) : '—' ?></p>
            <p><i class="fa-solid fa-phone"></i> <?= $selectedReferrer['phone'] ? htmlspecialchars($selectedReferrer['phone'], ENT_QUOTES) : '—' ?></p>
            <p><i class="fa-solid fa-clock"></i> Last lead <?= $selectedReferrer['lastLeadAt'] ? htmlspecialchars(format_admin_date($selectedReferrer['lastLeadAt']), ENT_QUOTES) : '—' ?></p>
          </div>
        </div>

        <div class="referrer-detail__forms">
          <form method="post" class="admin-form">
            <input type="hidden" name="action" value="update-referrer" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
            <input type="hidden" name="id" value="<?= (int) $selectedReferrer['id'] ?>" />
            <div class="admin-form__grid">
              <label>
                Name
                <input type="text" name="name" required value="<?= htmlspecialchars($selectedReferrer['name'], ENT_QUOTES) ?>" />
              </label>
              <label>
                Company
                <input type="text" name="company" value="<?= htmlspecialchars($selectedReferrer['company'], ENT_QUOTES) ?>" />
              </label>
              <label>
                Email
                <input type="email" name="email" value="<?= htmlspecialchars($selectedReferrer['email'], ENT_QUOTES) ?>" />
              </label>
              <label>
                Phone
                <input type="tel" name="phone" value="<?= htmlspecialchars($selectedReferrer['phone'], ENT_QUOTES) ?>" />
              </label>
              <label>
                Status
                <select name="status">
                  <?php foreach ($statusOptions as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $selectedReferrer['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <label class="admin-form__full">
              Notes
              <textarea name="notes" rows="3"><?= htmlspecialchars($selectedReferrer['notes'], ENT_QUOTES) ?></textarea>
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">Save changes</button>
          </form>

          <div class="referrer-detail__assign">
            <h3>Assign unclaimed lead</h3>
            <?php if (empty($unassignedLeads)): ?>
            <p class="admin-muted">All leads are currently linked to referrers.</p>
            <?php else: ?>
            <form method="post" class="admin-inline-form">
              <input type="hidden" name="action" value="assign-lead" />
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
              <input type="hidden" name="id" value="<?= (int) $selectedReferrer['id'] ?>" />
              <label>
                Lead
                <select name="lead_id" required>
                  <option value="">Select lead</option>
                  <?php foreach ($unassignedLeads as $lead): ?>
                  <option value="<?= (int) $lead['id'] ?>"><?= htmlspecialchars($lead['name'], ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit" class="btn btn-secondary btn-xs">Assign</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="admin-table-wrapper">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Lead</th>
              <th scope="col">Phone</th>
              <th scope="col">Stage</th>
              <th scope="col">Source</th>
              <th scope="col">Assigned</th>
              <th scope="col">Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($referrerLeads)): ?>
            <tr>
              <td colspan="6" class="admin-referrers__empty">No leads linked to this referrer yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($referrerLeads as $lead): ?>
            <tr>
              <td><?= htmlspecialchars($lead['name'], ENT_QUOTES) ?></td>
              <td><?= $lead['phone'] !== '' ? htmlspecialchars($lead['phone'], ENT_QUOTES) : '—' ?></td>
              <td><span class="admin-badge admin-badge--<?= htmlspecialchars($lead['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($lead['statusLabel'], ENT_QUOTES) ?></span></td>
              <td><?= $lead['source'] !== '' ? htmlspecialchars($lead['source'], ENT_QUOTES) : '—' ?></td>
              <td><?= $lead['assignedName'] !== '' ? htmlspecialchars($lead['assignedName'], ENT_QUOTES) : 'Unassigned' ?></td>
              <td><?= htmlspecialchars(format_admin_date($lead['updatedAt'] ?: $lead['createdAt']), ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </main>
</body>
</html>
