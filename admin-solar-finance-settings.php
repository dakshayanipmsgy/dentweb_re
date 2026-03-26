<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/solar_finance_settings.php';

require_admin();
start_session();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$settings = solar_finance_settings();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($token)) {
        $message = 'Invalid CSRF token.';
    } else {
        $json = (string) ($_POST['settings_json'] ?? '');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $message = 'Invalid JSON payload.';
        } else {
            solar_finance_settings_save($decoded);
            $settings = solar_finance_settings();
            $message = 'Solar and Finance settings saved.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin | Solar and Finance Settings</title>
  <style>
    body { font-family: Inter, Arial, sans-serif; background:#f8fafc; margin:0; color:#0f172a; }
    .wrap { max-width: 1100px; margin: 1rem auto; padding: 0 1rem; }
    .card { background:#fff; border:1px solid #dbe5f0; border-radius:12px; padding:1rem; }
    textarea { width:100%; min-height:65vh; border:1px solid #cbd5e1; border-radius:10px; padding:.8rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.84rem; }
    .btn { background:#0f766e; color:#fff; border:none; border-radius:10px; padding:.6rem .9rem; font-weight:700; cursor:pointer; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Solar and Finance Settings</h1>
    <p>Edit defaults, explainer text, and both pricing tables as JSON. This file is stored in filesystem only.</p>
    <?php if ($message !== ''): ?><p><b><?php echo htmlspecialchars($message, ENT_QUOTES); ?></b></p><?php endif; ?>
    <form method="post" class="card">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
      <textarea name="settings_json"><?php echo htmlspecialchars((string) json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?></textarea>
      <p><button class="btn" type="submit">Save settings</button> <a href="/solar-and-finance.php">Open public page</a></p>
    </form>
  </div>
</body>
</html>
