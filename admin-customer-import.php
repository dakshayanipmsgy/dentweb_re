<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_importer.php';

require_admin();

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $mode = isset($_POST['update_mode']) && $_POST['update_mode'] === 'update' ? 'update' : 'skip';
    $file = $_FILES['csv_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Upload a valid CSV file.';
    } else {
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false) {
            $error = 'Unable to read uploaded file.';
        } else {
            $result = customer_import_process($contents, $mode);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulk Customer Import</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <main class="admin-form" style="max-width:720px;margin:2rem auto;">
    <h1>Bulk Customer Import</h1>
    <p>Upload a CSV file to create or update customer records.</p>

    <?php if ($error !== ''): ?>
      <div class="admin-alert admin-alert--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
      <div class="admin-alert admin-alert--success" role="status">
        Processed <?php echo (int) $result['processed']; ?> rows. Created <?php echo (int) $result['created']; ?>, updated <?php echo (int) $result['updated']; ?>.
      </div>
      <?php if (!empty($result['errors'])): ?>
        <div class="admin-alert admin-alert--warning" role="status">
          <ul>
            <?php foreach ($result['errors'] as $issue): ?>
              <li>Row <?php echo (int) $issue['line']; ?>: <?php echo htmlspecialchars($issue['message'], ENT_QUOTES); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="admin-form">
      <label>CSV file
        <input type="file" name="csv_file" accept=".csv" required />
      </label>
      <label>Existing mobiles
        <select name="update_mode">
          <option value="skip">Skip existing</option>
          <option value="update">Update existing</option>
        </select>
      </label>
      <button type="submit" class="btn btn-primary">Import</button>
    </form>

    <section style="margin-top:1.5rem;">
      <h2>CSV Template</h2>
      <p>Headers: Mobile, Full name, Email, Address, City, State, Discom, Project type, PM Surya Ghar application number, Has loan?, Loan bank, Loan amount, Meter brand, Meter serial number, Initial CRM stage, Initial progress percentage, Customer type, Portal enabled</p>
    </section>
  </main>
</body>
</html>
