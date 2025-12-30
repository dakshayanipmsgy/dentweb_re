<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/leads.php';

require_admin();
$user = current_user();
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

$counts = admin_overview_counts($db);

$complaints = load_all_complaints();
$complaintCounts = complaint_summary_counts($complaints);
$openComplaints = array_filter($complaints, static function (array $item): bool {
    $status = strtolower((string) ($item['status'] ?? 'open'));
    return $status !== 'closed';
});

usort($openComplaints, static function (array $left, array $right): int {
    $leftTime = (string) ($left['updated_at'] ?? $left['created_at'] ?? '');
    $rightTime = (string) ($right['updated_at'] ?? $right['created_at'] ?? '');

    return strcmp($rightTime, $leftTime);
});

$highlightComplaints = array_slice($openComplaints, 0, 3);
$openComplaintCount = count($openComplaints);

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

$portalClock = portal_current_time();
$portalTimeIso = (string) ($portalClock['iso'] ?? '');
$portalTimeDisplay = (string) ($portalClock['display'] ?? '');
$portalTimeLabel = (string) ($portalClock['label'] ?? 'IST');

$complaintStatusLabels = [
    'open' => 'Open',
    'intake' => 'Intake',
    'triage' => 'Admin triage',
    'work' => 'In progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];

$leadStats = get_lead_stats_for_dashboard();

$cardConfigs = [];

$cardConfigs[] = [
    'key' => 'customer_complaints',
    'link' => $pathFor('complaints-overview.php'),
    'icon' => 'fa-headset',
    'label' => 'Customer Complaints',
    'value' => $complaintCounts['open'],
    'description' => sprintf('Total: %s · Unassigned: %s', number_format((int) $complaintCounts['total']), number_format((int) $complaintCounts['unassigned'])),
];

$cardConfigs[] = [
    'key' => 'leads',
    'link' => $pathFor('leads-dashboard.php'),
    'icon' => 'fa-address-card',
    'label' => 'Leads',
    'value' => $leadStats['total_leads'],
    'description' => sprintf(
        'New: %s · Site visit: %s · Quotes: %s · Today: %s · Overdue: %s',
        number_format((int) $leadStats['new_leads']),
        number_format((int) $leadStats['site_visit_needed']),
        number_format((int) $leadStats['quotation_sent']),
        number_format((int) $leadStats['today_followups']),
        number_format((int) $leadStats['overdue_followups'])
    ),
];

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Overview | Dakshayani Enterprises</title>
  <meta name="description" content="At-a-glance admin overview with live counts and recent activity across Dentweb operations." />
  <link rel="icon" href="<?= htmlspecialchars($pathFor('images/favicon.ico'), ENT_QUOTES) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars($pathFor('style.css'), ENT_QUOTES) ?>" />
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
<body class="admin-overview" data-theme="light">
  <main class="admin-overview__shell">
    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid <?= htmlspecialchars($flashIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <header class="admin-overview__header">
      <div class="admin-overview__identity">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <div>
          <p class="admin-overview__subtitle">Welcome back</p>
          <h1 class="admin-overview__title">Admin Overview</h1>
          <p class="admin-overview__user">Signed in as <strong><?= htmlspecialchars($user['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
        </div>
      </div>
      <div class="admin-overview__actions">
        <div class="dashboard-auth-time admin-overview__clock" role="status" aria-live="polite">
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          <div>
            <small>Current time (Kolkata)</small>
            <time datetime="<?= htmlspecialchars($portalTimeIso, ENT_QUOTES) ?>">
              <?= htmlspecialchars($portalTimeDisplay, ENT_QUOTES) ?> <?= htmlspecialchars($portalTimeLabel, ENT_QUOTES) ?>
            </time>
          </div>
        </div>
        <a href="<?= htmlspecialchars($pathFor('admin-users.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-users-gear" aria-hidden="true"></i>
          Users
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-requests.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-inbox" aria-hidden="true"></i>
          Requests
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-tasks.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-list-check" aria-hidden="true"></i>
          Tasks
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-ai-studio.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
          AI Studio
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-smart-marketing.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
          Smart Marketing
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-handover-templates.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
          Handover Templates
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin/website-settings/'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-palette" aria-hidden="true"></i>
          Website Content &amp; Theme
        </a>
        <a href="<?= htmlspecialchars($pathFor('admin-blog-manager.php'), ENT_QUOTES) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-newspaper" aria-hidden="true"></i>
          Blog Manager
        </a>
        <button type="button" class="btn btn-ghost" data-theme-toggle>
          <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
          Theme
        </button>
        <a href="<?= htmlspecialchars($pathFor('logout.php'), ENT_QUOTES) ?>" class="btn btn-primary">
          <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
          Log out
        </a>
      </div>
    </header>

    <section class="admin-overview__cards" aria-label="Operational summaries">
      <?php foreach ($cardConfigs as $card): ?>
      <?php
        $cardKey = isset($card['key']) ? (string) $card['key'] : '';
        $cardStateKey = isset($card['state_key']) ? (string) $card['state_key'] : '';
      ?>
      <a
        class="overview-card"
        href="<?= htmlspecialchars($card['link'], ENT_QUOTES) ?>"
        <?php if ($cardKey !== ''): ?> data-dashboard-card="<?= htmlspecialchars($cardKey, ENT_QUOTES) ?>"<?php endif; ?>
      >
        <div class="overview-card__icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($card['icon'], ENT_QUOTES) ?>"></i></div>
        <div class="overview-card__body">
          <p class="overview-card__label"><?= htmlspecialchars($card['label'], ENT_QUOTES) ?></p>
          <p
            class="overview-card__value"
            <?php if ($cardKey !== ''): ?> data-dashboard-count="<?= htmlspecialchars($cardKey, ENT_QUOTES) ?>"<?php endif; ?>
            <?php if ($cardStateKey !== ''): ?> data-customer-state-count="<?= htmlspecialchars($cardStateKey, ENT_QUOTES) ?>"<?php endif; ?>
          >
            <?= number_format((int) $card['value']) ?>
          </p>
          <p class="overview-card__meta"><?= htmlspecialchars($card['description'], ENT_QUOTES) ?></p>
        </div>
        <span class="overview-card__cta" aria-hidden="true">View list <i class="fa-solid fa-arrow-right"></i></span>
      </a>
      <?php endforeach; ?>
    </section>

    <section class="admin-overview__highlights" aria-label="Customer complaints requiring attention">
      <div class="admin-overview__highlights-header">
        <h2>Customer complaints</h2>
        <p class="admin-overview__highlights-sub">
          Showing the latest <?= htmlspecialchars((string) count($highlightComplaints), ENT_QUOTES) ?> of <?= htmlspecialchars((string) $openComplaintCount, ENT_QUOTES) ?> active complaints.
          <a href="<?= htmlspecialchars($pathFor('complaints-overview.php'), ENT_QUOTES) ?>">Open service desk</a>
        </p>
      </div>

      <?php if (count($highlightComplaints) === 0): ?>
      <p class="admin-overview__empty">No customer complaints are waiting right now.</p>
      <?php else: ?>
      <ul class="highlight-list">
        <?php foreach ($highlightComplaints as $complaint): ?>
        <?php
          $status = strtolower((string) ($complaint['status'] ?? 'open'));
          $statusLabel = $complaintStatusLabels[$status] ?? ucfirst($status ?: 'Open');
          $title = trim((string) ($complaint['title'] ?? 'Complaint'));
          $summary = trim((string) ($complaint['description'] ?? ''));
          $updatedAt = (string) ($complaint['updated_at'] ?? $complaint['created_at'] ?? '');
          $isoTime = '';
          $displayTime = $updatedAt !== '' ? $updatedAt : '—';

          try {
              $dt = new DateTimeImmutable($updatedAt);
              $dt = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
              $isoTime = $dt->format(DateTimeInterface::ATOM);
              $displayTime = $dt->format('d M Y · h:i A');
          } catch (Throwable $exception) {
          }
        ?>
        <li class="highlight-list__item">
          <div class="highlight-list__icon" aria-hidden="true"><i class="fa-solid fa-headset"></i></div>
          <div>
            <p class="highlight-list__module">Complaint #<?= htmlspecialchars((string) ($complaint['id'] ?? $complaint['reference'] ?? '—'), ENT_QUOTES) ?></p>
            <p class="highlight-list__summary">
              <?= htmlspecialchars($title, ENT_QUOTES) ?> —
              <?= htmlspecialchars($summary !== '' ? $summary : 'No description provided.', ENT_QUOTES) ?>
            </p>
            <p class="highlight-list__summary">
              Customer: <?= htmlspecialchars((string) ($complaint['customer_mobile'] ?? 'Unknown'), ENT_QUOTES) ?> ·
              Status: <?= htmlspecialchars($statusLabel, ENT_QUOTES) ?>
            </p>
          </div>
          <div class="highlight-list__time">
            <?php if ($isoTime !== ''): ?>
            <time datetime="<?= htmlspecialchars($isoTime, ENT_QUOTES) ?>"><?= htmlspecialchars($displayTime, ENT_QUOTES) ?></time>
            <?php else: ?>
            <span><?= htmlspecialchars($displayTime, ENT_QUOTES) ?></span>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

  </main>
  <script src="<?= htmlspecialchars($pathFor('admin-dashboard.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
