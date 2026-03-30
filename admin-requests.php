<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$db = get_db();
$admin = current_user();
$csrfToken = $_SESSION['csrf_token'] ?? '';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
$prefix = $basePath === '' ? '' : $basePath;
$pathFor = static function (string $path) use ($prefix): string {
    $clean = ltrim($path, '/');
    return ($prefix === '' ? '' : $prefix) . '/' . $clean;
};
$requestsPath = $pathFor('admin-requests.php');

$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
$flashIcons = [
    'success' => 'fa-circle-check',
    'warning' => 'fa-triangle-exclamation',
    'error' => 'fa-circle-exclamation',
    'info' => 'fa-circle-info',
];
$flashIcon = $flashIcons[$flashTone];

if (is_array($flashData)) {
    if (isset($flashData['message']) && is_string($flashData['message'])) {
        $flashMessage = trim($flashData['message']);
    }
    if (isset($flashData['type']) && is_string($flashData['type'])) {
        $candidateTone = strtolower($flashData['type']);
        if (isset($flashIcons[$candidateTone])) {
            $flashTone = $candidateTone;
            $flashIcon = $flashIcons[$candidateTone];
        }
    }
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
$validStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'pending';
}

$typeFilter = strtolower(trim((string) ($_GET['type'] ?? 'all')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: ' . $requestsPath . '?status=' . urlencode($statusFilter) . '&type=' . urlencode($typeFilter));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $actorId = (int) ($admin['id'] ?? 0);
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

    try {
        if ($requestId <= 0) {
            throw new RuntimeException('Request reference missing.');
        }

        if ($action === 'approve') {
            admin_decide_request($db, $requestId, 'approve', $actorId, $note);
            set_flash('success', 'Request approved successfully.');
        } elseif ($action === 'reject') {
            admin_decide_request($db, $requestId, 'reject', $actorId, $note);
            set_flash('success', 'Request rejected.');
        } else {
            throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    header('Location: ' . $requestsPath . '?status=' . urlencode($statusFilter) . '&type=' . urlencode($typeFilter));
    exit;
}

$allRequests = admin_list_requests($db, 'all');

$filteredRequests = array_values(array_filter($allRequests, static function (array $request) use ($statusFilter, $typeFilter): bool {
    $status = strtolower((string) ($request['status'] ?? 'pending'));
    $type = strtolower((string) ($request['type'] ?? 'general'));

    if ($statusFilter !== 'all' && $status !== $statusFilter) {
        return false;
    }
    if ($typeFilter !== 'all' && $type !== $typeFilter) {
        return false;
    }
    return true;
}));

$pendingByType = [];
foreach (admin_list_requests($db, 'pending') as $pendingRequest) {
    $typeKey = strtolower((string) ($pendingRequest['type'] ?? 'general'));
    $pendingByType[$typeKey] = ($pendingByType[$typeKey] ?? 0) + 1;
}
ksort($pendingByType);

$availableTypes = array_values(array_unique(array_map(static fn (array $request): string => strtolower((string) ($request['type'] ?? 'general')), $allRequests)));
sort($availableTypes);

$requestCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($allRequests as $requestCounter) {
    $statusKey = strtolower((string) ($requestCounter['status'] ?? 'pending'));
    if (isset($requestCounts[$statusKey])) {
        $requestCounts[$statusKey]++;
    }
}

function admin_requests_format_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('d M Y · H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Requests Center | Dakshayani Enterprises</title>
  <meta name="description" content="Approve or reject employee requests spanning profile changes, reminders, leads, and field operations." />
  <link rel="icon" href="<?= htmlspecialchars($pathFor('images/favicon.ico'), ENT_QUOTES) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars($pathFor('style.css'), ENT_QUOTES) ?>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="admin-records" data-theme="light">
  <main class="admin-records__shell">
    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <header class="admin-records__header">
      <div>
        <h1>Requests Approval Queue</h1>
        <p class="admin-muted">Fast triage for employee requests. Filter by status and type, review payload, then approve or reject with context.</p>
      </div>
      <div class="admin-records__meta">
        <a class="admin-link" href="<?= htmlspecialchars($pathFor('admin-dashboard.php'), ENT_QUOTES) ?>"><i class="fa-solid fa-gauge-high"></i> Back to overview</a>
      </div>
    </header>

    <section class="admin-overview__cards admin-overview__cards--compact">
      <article class="overview-card">
        <div class="overview-card__label">Pending</div>
        <div class="overview-card__value"><?= (int) $requestCounts['pending'] ?></div>
      </article>
      <article class="overview-card">
        <div class="overview-card__label">Approved</div>
        <div class="overview-card__value"><?= (int) $requestCounts['approved'] ?></div>
      </article>
      <article class="overview-card">
        <div class="overview-card__label">Rejected</div>
        <div class="overview-card__value"><?= (int) $requestCounts['rejected'] ?></div>
      </article>
      <article class="overview-card">
        <div class="overview-card__label">Request types</div>
        <div class="overview-card__value"><?= count($availableTypes) ?></div>
      </article>
    </section>

    <section class="admin-users__roles">
      <h2>Pending by type</h2>
      <?php if (empty($pendingByType)): ?>
      <p class="admin-muted">No pending approvals.</p>
      <?php else: ?>
      <ul class="admin-users__role-list">
        <?php foreach ($pendingByType as $type => $count): ?>
        <li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type)), ENT_QUOTES) ?>:</strong> <?= (int) $count ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="admin-records__filter admin-records__filter--panel">
      <form method="get" action="<?= htmlspecialchars($requestsPath, ENT_QUOTES) ?>" class="admin-filter-form admin-filter-form--gap">
        <label>
          Status
          <select name="status">
            <?php foreach ($validStatuses as $statusOption): ?>
            <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($statusOption), ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Type
          <select name="type">
            <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All</option>
            <?php foreach ($availableTypes as $typeOption): ?>
            <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES) ?>" <?= $typeFilter === $typeOption ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $typeOption)), ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Apply filters</button>
      </form>
    </section>

    <div class="admin-table-wrapper">
      <table class="admin-table admin-table--requests">
        <thead>
          <tr>
            <th scope="col">Request</th>
            <th scope="col">Requested by</th>
            <th scope="col">Submitted</th>
            <th scope="col">Updated</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($filteredRequests)): ?>
          <tr>
            <td colspan="6" class="admin-records__empty">No requests match this filter.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($filteredRequests as $request): ?>
          <?php
          $requestId = (int) ($request['id'] ?? 0);
          $status = strtolower((string) ($request['status'] ?? 'pending'));
          $typeLabel = ucwords(str_replace('_', ' ', (string) ($request['type'] ?? 'general')));
          $isPending = $status === 'pending';
          $payload = $request['payload'] ?? [];
          ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($request['subject'] ?? 'Request', ENT_QUOTES) ?></strong>
              <p class="admin-muted text-sm">Type: <?= htmlspecialchars($typeLabel, ENT_QUOTES) ?></p>
              <?php if (!empty($payload) && is_array($payload)): ?>
              <details class="admin-payload">
                <summary>View request payload</summary>
                <ul>
                  <?php foreach ($payload as $key => $value): ?>
                  <li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key)), ENT_QUOTES) ?>:</strong> <?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT), ENT_QUOTES) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <?php endif; ?>
              <?php if (!empty($request['notes'])): ?>
              <p class="text-sm admin-muted">Note: <?= nl2br(htmlspecialchars($request['notes'], ENT_QUOTES)) ?></p>
              <?php endif; ?>
            </td>
            <td>
              <div class="dashboard-user">
                <strong><?= htmlspecialchars($request['requestedByName'] ?? '—', ENT_QUOTES) ?></strong>
                <span>ID <?= (int) ($request['requestedBy'] ?? 0) ?></span>
              </div>
            </td>
            <td><?= htmlspecialchars(admin_requests_format_time($request['createdAt'] ?? null), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(admin_requests_format_time($request['updatedAt'] ?? null), ENT_QUOTES) ?></td>
            <td><span class="dashboard-status dashboard-status--<?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?></span></td>
            <td class="admin-table__actions">
              <?php if ($isPending): ?>
              <div class="admin-row-actions">
                <form method="post" action="<?= htmlspecialchars($requestsPath, ENT_QUOTES) ?>" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
                  <input type="hidden" name="action" value="approve" />
                  <input type="hidden" name="request_id" value="<?= $requestId ?>" />
                  <button type="submit" class="btn btn-primary btn-xs">Approve</button>
                </form>
                <button type="button" class="btn btn-secondary btn-xs js-open-reject" data-request-id="<?= $requestId ?>">Reject</button>
              </div>
              <?php else: ?>
                <?php if (!empty($request['decidedByName'])): ?>
                <p class="text-sm admin-muted">By <?= htmlspecialchars($request['decidedByName'], ENT_QUOTES) ?></p>
                <?php endif; ?>
                <?php if (!empty($request['decisionNote'])): ?>
                <p class="text-sm admin-muted">Note: <?= nl2br(htmlspecialchars($request['decisionNote'], ENT_QUOTES)) ?></p>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <dialog id="reject-dialog" class="admin-dialog">
      <form method="dialog" class="admin-dialog__frame js-reject-cancel">
        <header class="admin-dialog__header">
          <h2>Reject request</h2>
          <button class="btn btn-secondary btn-xs" value="cancel">Close</button>
        </header>
      </form>
      <form method="post" action="<?= htmlspecialchars($requestsPath, ENT_QUOTES) ?>" class="admin-dialog__body">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <input type="hidden" name="action" value="reject" />
        <input type="hidden" name="request_id" id="reject-request-id" value="" />
        <label for="reject-note">Rejection note (optional)</label>
        <textarea id="reject-note" name="note" rows="4" class="admin-textarea" placeholder="Provide context for rejection"></textarea>
        <div class="admin-dialog__actions">
          <button type="submit" class="btn btn-secondary">Reject request</button>
        </div>
      </form>
    </dialog>
  </main>
  <script>
    (function () {
      const dialog = document.getElementById('reject-dialog');
      if (!dialog) return;
      const requestInput = document.getElementById('reject-request-id');
      const openButtons = document.querySelectorAll('.js-open-reject');

      openButtons.forEach((button) => {
        button.addEventListener('click', () => {
          requestInput.value = button.getAttribute('data-request-id') || '';
          if (typeof dialog.showModal === 'function') {
            dialog.showModal();
          }
        });
      });
    })();
  </script>
</body>
</html>
