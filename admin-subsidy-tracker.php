<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();

$admin = current_user();
$db = get_db();

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

$stageOptions = subsidy_stage_options();
$stageFilter = strtolower(trim((string) ($_GET['stage'] ?? 'all')));
if ($stageFilter === '' || ($stageFilter !== 'all' && $stageFilter !== 'pending' && !isset($stageOptions[$stageFilter]))) {
    $stageFilter = 'all';
}

$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-subsidy-tracker.php');
        exit;
    }

    try {
        if ($action !== 'record_stage') {
            throw new RuntimeException('Unsupported action.');
        }

        admin_subsidy_tracker_record_stage($db, $_POST);
        set_flash('success', 'Subsidy stage recorded.');
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    $redirectUrl = 'admin-subsidy-tracker.php';
    $query = [];
    if ($stageFilter !== 'all') {
        $query['stage'] = $stageFilter;
    }
    if ($fromDate !== '') {
        $query['from'] = $fromDate;
    }
    if ($toDate !== '') {
        $query['to'] = $toDate;
    }
    if (count($query) > 0) {
        $redirectUrl .= '?' . http_build_query($query);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$summary = admin_subsidy_tracker_summary($db, $stageFilter, $fromDate !== '' ? $fromDate : null, $toDate !== '' ? $toDate : null);
$cases = $summary['cases'];
$overallTotals = $summary['totals'];
$overallPending = $summary['pendingTotal'];
$visibleTotals = $summary['visibleTotals'];
$visiblePending = $summary['visiblePending'];
$csrfToken = $_SESSION['csrf_token'] ?? '';

function tracker_format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d M Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function tracker_format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function tracker_stage_badge_class(string $stage): string
{
    $normalized = preg_replace('/[^a-z0-9_]+/', '', strtolower($stage));
    return 'stage-badge--' . ($normalized !== '' ? $normalized : 'applied');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Subsidy Tracker | Admin</title>
  <meta name="description" content="Track subsidy applications from application to disbursal with stage dates and notes." />
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
<body class="admin-subsidy" data-theme="light">
  <main class="admin-subsidy__shell">
    <header class="admin-subsidy__header">
      <div>
        <p class="admin-subsidy__subtitle">Admin workspace</p>
        <h1 class="admin-subsidy__title">Subsidy Tracker</h1>
        <p class="admin-subsidy__meta">Signed in as <strong><?= htmlspecialchars($admin['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-subsidy__actions">
        <a href="admin-dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to overview</a>
        <button type="button" class="btn btn-ghost" data-theme-toggle>
          <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
          Theme
        </button>
        <a href="logout.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
      </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <section class="tracker-summary" aria-label="Pipeline summary">
      <div class="tracker-summary__grid">
        <?php foreach ($stageOptions as $key => $label): ?>
        <article class="tracker-summary__card">
          <span class="tracker-summary__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
          <strong><?= (int) ($overallTotals[$key] ?? 0) ?></strong>
          <small><?= (int) ($visibleTotals[$key] ?? 0) ?> showing</small>
        </article>
        <?php endforeach; ?>
        <article class="tracker-summary__card tracker-summary__card--pending">
          <span class="tracker-summary__label">Pending</span>
          <strong><?= (int) $overallPending ?></strong>
          <small><?= (int) $visiblePending ?> showing</small>
        </article>
      </div>
    </section>

    <section class="tracker-controls" aria-label="Filters">
      <form method="get" class="tracker-filters">
        <label>
          Stage
          <select name="stage">
            <option value="all" <?= $stageFilter === 'all' ? 'selected' : '' ?>>All stages</option>
            <option value="pending" <?= $stageFilter === 'pending' ? 'selected' : '' ?>>Pending (not disbursed)</option>
            <?php foreach ($stageOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $stageFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          From date
          <input type="date" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES) ?>" />
        </label>
        <label>
          To date
          <input type="date" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES) ?>" />
        </label>
        <button type="submit" class="btn btn-primary tracker-filters__submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
        <a href="admin-subsidy-tracker.php" class="btn btn-ghost">Reset</a>
      </form>
      <p class="tracker-controls__meta">Showing <strong><?= count($cases) ?></strong> applications.</p>
    </section>

    <section class="tracker-panel" aria-labelledby="record-stage">
      <div class="tracker-panel__header">
        <div>
          <h2 id="record-stage">Record a subsidy stage</h2>
          <p>Log when an application moves between stages. Link at least a lead or installation.</p>
        </div>
      </div>
      <form method="post" class="tracker-form">
        <input type="hidden" name="action" value="record_stage" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <div class="tracker-form__grid">
          <label>
            Application reference
            <input type="text" name="reference" required placeholder="Customer or subsidy reference" />
          </label>
          <label>
            Lead ID
            <input type="number" name="lead_id" min="1" placeholder="e.g. 42" />
          </label>
          <label>
            Installation ID
            <input type="number" name="installation_id" min="1" placeholder="e.g. 105" />
          </label>
          <label>
            Stage
            <select name="stage" required>
              <?php foreach ($stageOptions as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Stage date
            <input type="date" name="stage_date" />
          </label>
          <label class="tracker-form__wide">
            Notes
            <textarea name="note" rows="3" placeholder="Add any reviewer remarks or payment notes"></textarea>
          </label>
        </div>
        <div class="tracker-form__actions">
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Record stage</button>
        </div>
      </form>
    </section>

    <section class="tracker-table-section" aria-label="Subsidy timeline">
      <div class="tracker-table__wrapper">
        <table class="tracker-table">
          <thead>
            <tr>
              <th scope="col">Application</th>
              <th scope="col">Linked to</th>
              <th scope="col">Current stage</th>
              <th scope="col">Applied</th>
              <th scope="col">Under Review</th>
              <th scope="col">Approved</th>
              <th scope="col">Disbursed</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($cases) === 0): ?>
            <tr>
              <td colspan="7" class="tracker-table__empty-row">No subsidy records match the current filters.</td>
            </tr>
            <?php endif; ?>
            <?php foreach ($cases as $case): ?>
            <?php
                $latestStage = $case['latest_stage'] ?? 'applied';
                $latestLabel = subsidy_stage_label($latestStage);
                $latestDate = $case['latest_date'] ?? null;
                $latestNote = $case['latest_note'] ?? '';
                $linked = [];
                if (!empty($case['installation']['id'])) {
                    $installationLabel = 'Installation #' . $case['installation']['id'];
                    if (($case['installation']['name'] ?? '') !== '') {
                        $installationLabel .= ' · ' . $case['installation']['name'];
                    }
                    $linked[] = $installationLabel;
                }
                if (!empty($case['lead']['id'])) {
                    $leadLabel = 'Lead #' . $case['lead']['id'];
                    if (($case['lead']['name'] ?? '') !== '') {
                        $leadLabel .= ' · ' . $case['lead']['name'];
                    }
                    $linked[] = $leadLabel;
                }
                $linkedHtml = '';
                if (count($linked) > 0) {
                    $linkedHtml = implode('<br />', array_map(static fn ($item) => htmlspecialchars($item, ENT_QUOTES), $linked));
                }
            ?>
            <tr>
              <th scope="row">
                <div class="tracker-table__application">
                  <strong><?= htmlspecialchars($case['reference'], ENT_QUOTES) ?></strong>
                  <?php if ($latestNote !== ''): ?>
                  <p class="tracker-table__note"><?= nl2br(htmlspecialchars($latestNote, ENT_QUOTES)) ?></p>
                  <?php endif; ?>
                </div>
              </th>
              <td>
                <?php if ($linkedHtml === ''): ?>
                <span class="tracker-table__empty">—</span>
                <?php else: ?>
                <?= $linkedHtml ?>
                <?php endif; ?>
              </td>
              <td>
                <span class="stage-badge <?= htmlspecialchars(tracker_stage_badge_class($latestStage), ENT_QUOTES) ?>"><?= htmlspecialchars($latestLabel, ENT_QUOTES) ?></span>
                <?php if ($latestDate): ?>
                <p class="tracker-table__meta">Updated <?= htmlspecialchars(tracker_format_datetime($latestDate), ENT_QUOTES) ?></p>
                <?php endif; ?>
              </td>
              <?php foreach (['applied', 'under_review', 'approved', 'disbursed'] as $stageKey): ?>
              <?php $stageInfo = $case['stages'][$stageKey] ?? null; ?>
              <td>
                <?php if ($stageInfo && ($stageInfo['date'] ?? null)): ?>
                <time datetime="<?= htmlspecialchars($stageInfo['date'], ENT_QUOTES) ?>"><?= htmlspecialchars(tracker_format_date($stageInfo['date']), ENT_QUOTES) ?></time>
                <?php if (($stageInfo['note'] ?? '') !== ''): ?>
                <p class="tracker-table__note"><?= nl2br(htmlspecialchars((string) $stageInfo['note'], ENT_QUOTES)) ?></p>
                <?php endif; ?>
                <?php else: ?>
                <span class="tracker-table__empty">—</span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script src="site-content.js" defer></script>
</body>
</html>
