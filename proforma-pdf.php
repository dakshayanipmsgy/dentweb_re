<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();
documents_ensure_structure();
$id = safe_text($_GET['id'] ?? '');
$row = documents_get_proforma($id);
if ($row === null) { http_response_code(404); echo 'Proforma not found.'; exit; }

try {
    $_GET['id'] = (string) $row['id'];
    $_GET['pdf'] = '1';
    ob_start();
    require __DIR__ . '/proforma-print.php';
    $html = (string) ob_get_clean();

    documents_ensure_dir(documents_proforma_pdf_dir());
    $pdfPath = documents_proforma_pdf_dir() . '/' . safe_filename((string)$row['id']) . '.pdf';
    if (!handover_generate_pdf($html, $pdfPath) || !is_file($pdfPath)) {
        throw new RuntimeException('PDF failed');
    }

    $row['pdf_path'] = '/data/documents/proformas/pdfs/' . safe_filename((string)$row['id']) . '.pdf';
    $row['pdf_generated_at'] = date('c');
    $row['updated_at'] = date('c');
    documents_save_proforma($row);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_filename((string)($row['proforma_no'] ?: $row['id'])) . '.pdf"');
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    documents_log('Proforma PDF failed for ' . (string)$row['id'] . ': ' . $e->getMessage());
    header('Location: proforma-view.php?id=' . urlencode((string)$row['id']) . '&err=pdf_failed');
    exit;
}
