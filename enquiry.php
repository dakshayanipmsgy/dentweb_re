<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_public.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = public_enquiry_submit($_POST);
        $success = $result['created']
            ? 'Thank you! We have received your enquiry and will contact you shortly.'
            : 'We have updated your enquiry details. Our team will reach out soon.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enquiry | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <main class="admin-form" style="max-width:640px;margin:2rem auto;">
    <h1>Solar Enquiry</h1>
    <p>Share your details and our team will guide you.</p>

    <?php if ($error !== ''): ?>
      <div class="admin-alert admin-alert--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="admin-alert admin-alert--success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
      <label>Customer name
        <input type="text" name="name" required />
      </label>
      <label>Mobile number
        <input type="tel" name="mobile" required />
      </label>
      <label>City
        <input type="text" name="city" />
      </label>
      <label>State
        <input type="text" name="state" />
      </label>
      <label>Discom (optional)
        <input type="text" name="discom" />
      </label>
      <label>Preferred project type
        <input type="text" name="project_type" />
      </label>
      <label>Notes (optional)
        <textarea name="notes" rows="3"></textarea>
      </label>
      <button type="submit" class="btn btn-primary">Submit enquiry</button>
    </form>
  </main>
</body>
</html>
