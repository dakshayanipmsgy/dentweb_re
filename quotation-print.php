<?php
declare(strict_types=1);
// Legacy endpoint retained for compatibility. Quotation is now HTML-only modern proposal.
$id = urlencode((string)($_GET['id'] ?? ''));
header('Location: quotation-view.php?id=' . $id);
exit;
