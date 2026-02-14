<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

documents_ensure_structure();
$employeeStore = new EmployeeFsStore();

$viewerType = '';
$viewerId = '';
$user = current_user();
if (is_array($user) && (($user['role_name'] ?? '') === 'admin')) {
    $viewerType = 'admin';
    $viewerId = (string) ($user['id'] ?? '');
} else {
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee !== null) {
        $viewerType = 'employee';
        $viewerId = (string) ($employee['id'] ?? '');
    }
}
if ($viewerType === '') {
    header('Location: login.php');
    exit;
}

$id = safe_text($_GET['id'] ?? '');
$quote = documents_get_quote($id);
if ($quote === null) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($quote['created_by_type'] ?? '') !== 'employee' || (string) ($quote['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

try {
    $_GET['id'] = (string) ($quote['id'] ?? '');
    $_GET['pdf'] = '1';
    ob_start();
    require __DIR__ . '/quotation-print.php';
    $html = (string) ob_get_clean();

    documents_ensure_dir(documents_quote_pdf_dir());
    $pdfPath = documents_quote_pdf_dir() . '/' . safe_filename((string) $quote['id']) . '.pdf';
    $ok = handover_generate_pdf($html, $pdfPath);
    if (!$ok || !is_file($pdfPath)) {
        throw new RuntimeException('PDF generator failed.');
    }

    $quote['pdf_path'] = '/data/documents/quotations/pdfs/' . safe_filename((string) $quote['id']) . '.pdf';
    $quote['pdf_generated_at'] = date('c');
    $quote['updated_at'] = date('c');
    documents_save_quote($quote);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_filename((string) ($quote['quote_no'] ?: $quote['id'])) . '.pdf"');
    header('Content-Length: ' . (string) filesize($pdfPath));
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    documents_log('Quotation PDF generation failed for ' . (string) ($quote['id'] ?? '') . ': ' . $e->getMessage());
    header('Location: quotation-view.php?id=' . urlencode((string) ($quote['id'] ?? '')) . '&err=pdf_failed');
    exit;
}
