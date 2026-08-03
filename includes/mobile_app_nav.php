<?php
declare(strict_types=1);

$user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
$role = is_array($user) ? (string)($user['role_name'] ?? '') : '';
$currentPage = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$links = [];

// Role-specific links are derived from the authenticated session only; this keeps
// customer and employee PWAs from exposing admin shortcuts in the mobile shell.
if ($role === 'admin') {
    $links = [['Dashboard','admin-dashboard.php'],['Customers','admin-records.php'],['Quotations','admin-quotations.php'],['Agreements','admin-agreements.php'],['Dispatch','admin-dispatch-advices.php'],['Challans','admin-challans.php'],['Invoices','admin-invoices.php'],['Complaints','admin-complaints.php'],['Tasks','admin-tasks.php'],['Leads','admin-leads.php']];
} elseif ($role === 'customer') {
    $links = [['Dashboard','customer-dashboard.php'],['Documents','customer-dashboard.php#documents'],['Financials','customer-dashboard.php#financials'],['Complaints','complaint.php'],['Profile','customer-dashboard.php#profile']];
} elseif ($role === 'employee') {
    $links = [['Dashboard','employee-dashboard.php'],['Tasks','employee-tasks.php'],['Documents','employee-documents.php'],['Quotations','employee-quotations.php'],['Leads','leads-dashboard.php'],['Complaints','complaints-overview.php']];
}

$existing = [];
foreach ($links as $link) {
    $file = explode('#', explode('?', $link[1])[0])[0];
    if (is_file(__DIR__ . '/../' . $file)) {
        $existing[] = $link;
    }
}
if ($existing) {
    $existing[] = ['Logout', 'logout.php'];
}
if ($existing): ?>
<nav class="mobile-app-nav" aria-label="Mobile app navigation">
  <?php foreach ($existing as [$label,$href]):
      $targetPage = basename(explode('#', explode('?', $href)[0])[0]);
      $isActive = $targetPage !== '' && $targetPage === $currentPage;
  ?><a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="<?= $isActive ? 'is-active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
