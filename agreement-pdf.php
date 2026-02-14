<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$row = documents_get_agreement($id);
if ($row === null) { http_response_code(404); echo 'Agreement not found.'; exit; }

try {
    $_GET['id'] = (string)$row['id'];
    $_GET['pdf'] = '1';
    ob_start();
    require __DIR__ . '/agreement-print.php';
    $html = (string)ob_get_clean();

    documents_ensure_dir(documents_agreement_pdf_dir());
    $pdfPath = documents_agreement_pdf_dir() . '/' . safe_filename((string)$row['id']) . '.pdf';
    if (!handover_generate_pdf($html, $pdfPath) || !is_file($pdfPath)) {
        throw new RuntimeException('PDF failed');
    }

    $row['pdf_path'] = '/data/documents/agreements/pdfs/' . safe_filename((string)$row['id']) . '.pdf';
    $row['pdf_generated_at'] = date('c');
    $row['updated_at'] = date('c');
    documents_save_agreement($row);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_filename((string)($row['agreement_no'] ?: $row['id'])) . '.pdf"');
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    documents_log('Agreement PDF failed for ' . (string)$row['id'] . ': ' . $e->getMessage());
    header('Location: agreement-view.php?id=' . urlencode((string)$row['id']) . '&err=pdf_failed');
    exit;
}
