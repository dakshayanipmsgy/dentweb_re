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
$challan = documents_get_challan($id);
if ($challan === null) {
    http_response_code(404);
    echo 'Challan not found.';
    exit;
}
if ($viewerType === 'employee' && ((string) ($challan['created_by_type'] ?? '') !== 'employee' || (string) ($challan['created_by_id'] ?? '') !== $viewerId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

try {
    $_GET['id'] = (string) ($challan['id'] ?? '');
    $_GET['pdf'] = '1';
    ob_start();
    require __DIR__ . '/challan-print.php';
    $html = (string) ob_get_clean();

    documents_ensure_dir(documents_challan_pdf_dir());
    $filename = safe_filename((string) $challan['id']) . '.pdf';
    $pdfPath = documents_challan_pdf_dir() . '/' . $filename;

    $ok = handover_generate_pdf($html, $pdfPath);
    if (!$ok || !is_file($pdfPath)) {
        throw new RuntimeException('PDF generator failed.');
    }

    $challan['pdf_path'] = '/data/documents/challans/pdfs/' . $filename;
    $challan['pdf_generated_at'] = date('c');
    $challan['updated_at'] = date('c');
    documents_save_challan($challan);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_filename((string) ($challan['challan_no'] ?: $challan['id'])) . '.pdf"');
    header('Content-Length: ' . (string) filesize($pdfPath));
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    documents_log('Challan PDF generation failed for ' . (string) ($challan['id'] ?? '') . ': ' . $e->getMessage());
    header('Location: challan-view.php?id=' . urlencode((string) ($challan['id'] ?? '')) . '&status=error&message=' . urlencode('PDF generation failed. Please retry.'));
    exit;
}
