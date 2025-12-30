<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/customer_complaints.php';

$success = '';
$error = '';
$problemCategories = complaint_problem_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $record = customer_complaint_submit($_POST);
        $reference = htmlspecialchars((string) ($record['id'] ?? ''), ENT_QUOTES);
        $success = sprintf('Complaint %s submitted. Our service team will contact you shortly.', $reference !== '' ? $reference : 'successfully');
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
  <title>Service Complaint | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <main class="admin-form" style="max-width:640px;margin:2rem auto;">
    <h1>Submit a Service Complaint</h1>
    <p>Only registered customers can log complaints using their mobile number.</p>

    <?php if ($error !== ''): ?>
      <div class="admin-alert admin-alert--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="admin-alert admin-alert--success" role="status"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
      <label>Mobile number
        <input type="tel" name="mobile" required />
      </label>
      <label>Problem category
        <select name="problem_category" required>
          <option value="">Select a category</option>
          <?php foreach ($problemCategories as $category): ?>
            <option value="<?php echo htmlspecialchars($category, ENT_QUOTES); ?>"><?php echo htmlspecialchars($category, ENT_QUOTES); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Description
        <textarea name="description" rows="4" required></textarea>
      </label>
      <button type="submit" class="btn btn-primary">Submit complaint</button>
    </form>
  </main>
</body>
</html>
