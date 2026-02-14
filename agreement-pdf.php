<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();

$id = safe_text($_GET['id'] ?? '');
$agreement = documents_get_agreement($id);
if ($agreement === null) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

try {
    $_GET['id'] = (string) ($agreement['id'] ?? '');
    $_GET['pdf'] = '1';
    ob_start();
    require __DIR__ . '/agreement-print.php';
    $html = (string) ob_get_clean();

    documents_ensure_dir(documents_agreement_pdf_dir());
    $filename = safe_filename((string) $agreement['id']) . '.pdf';
    $pdfPath = documents_agreement_pdf_dir() . '/' . $filename;

    $ok = handover_generate_pdf($html, $pdfPath);
    if (!$ok || !is_file($pdfPath)) {
        throw new RuntimeException('PDF generator failed.');
    }

    $agreement['pdf_path'] = '/data/documents/agreements/pdfs/' . $filename;
    $agreement['pdf_generated_at'] = date('c');
    $agreement['updated_at'] = date('c');
    documents_save_agreement($agreement);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_filename((string) ($agreement['agreement_no'] ?: $agreement['id'])) . '.pdf"');
    header('Content-Length: ' . (string) filesize($pdfPath));
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    documents_log('Agreement PDF generation failed for ' . (string) ($agreement['id'] ?? '') . ': ' . $e->getMessage());
    header('Location: agreement-view.php?id=' . urlencode((string) ($agreement['id'] ?? '')) . '&status=error&message=' . urlencode('PDF generation failed. Please retry.'));
    exit;
}
