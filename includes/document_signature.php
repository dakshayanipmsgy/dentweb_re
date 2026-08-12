<?php
declare(strict_types=1);

/** Shared, filesystem-only support for per-document handwritten signature images. */
function document_signature_root(): string
{
    return dirname(__DIR__) . '/data/document-signatures';
}

function document_signature_types(): array
{
    return ['quotation', 'dispatch_advice', 'challan', 'invoice', 'universal'];
}

function document_signature_settings_path(): string
{
    return dirname(__DIR__) . '/data/settings/document-signature.json';
}

function document_signature_universal_reference(): array
{
    $path = document_signature_settings_path();
    if (!is_file($path)) return [];
    $contents = file_get_contents($path);
    $settings = is_string($contents) ? json_decode($contents, true) : [];
    return is_array($settings['signature'] ?? null) ? $settings['signature'] : [];
}

function document_signature_reference(array $document): array
{
    $reference = $document['signature'] ?? [];
    return is_array($reference) ? $reference : [];
}

function document_signature_store(array $upload, string $type, string $documentId): array
{
    if (!in_array($type, document_signature_types(), true) || !preg_match('/^[A-Za-z0-9_-]+$/', $documentId)) {
        return ['ok' => false, 'error' => 'Invalid signature destination.'];
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Choose a PNG or JPG signature image.'];
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Signature images must be no larger than 5 MB.'];
    }
    $tmp = (string)$upload['tmp_name'];
    $imageInfo = @getimagesize($tmp);
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/png' => ['png', IMAGETYPE_PNG], 'image/jpeg' => ['jpg', IMAGETYPE_JPEG]];
    if (!is_array($imageInfo) || !isset($allowed[$detected]) || (int)($imageInfo[2] ?? 0) !== $allowed[$detected][1]) {
        return ['ok' => false, 'error' => 'Only validated PNG, JPG, or JPEG images are accepted.'];
    }
    $directory = document_signature_root() . '/' . $type . '/' . $documentId;
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return ['ok' => false, 'error' => 'Unable to create secure signature storage.'];
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$detected][0];
    $absolute = $directory . '/' . $filename;
    if (!@move_uploaded_file($tmp, $absolute)) {
        return ['ok' => false, 'error' => 'Unable to store the signature image.'];
    }
    @chmod($absolute, 0640);
    return ['ok' => true, 'reference' => [
        'path' => $type . '/' . $documentId . '/' . $filename,
        'mime' => $detected,
        'size' => $size,
        'sha256' => hash_file('sha256', $absolute),
        'uploaded_at' => date('c'),
    ]];
}

function document_signature_delete(array $reference): void
{
    $path = (string)($reference['path'] ?? '');
    if ($path === '' || str_contains($path, '..')) return;
    $root = realpath(document_signature_root());
    $file = realpath(document_signature_root() . '/' . ltrim($path, '/'));
    if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

function document_signature_effective_reference(array $document): array
{
    $reference = document_signature_reference($document);
    return $reference !== [] ? $reference : document_signature_universal_reference();
}

function document_signature_file(array $document): array
{
    $reference = document_signature_effective_reference($document);
    $path = (string)($reference['path'] ?? '');
    $mime = (string)($reference['mime'] ?? '');
    if ($path === '' || str_contains($path, '..') || !in_array($mime, ['image/png', 'image/jpeg'], true)) return [];
    $root = realpath(document_signature_root());
    $file = realpath(document_signature_root() . '/' . ltrim($path, '/'));
    if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) return [];
    if (filesize($file) > 5 * 1024 * 1024 || hash_file('sha256', $file) !== (string)($reference['sha256'] ?? '')) return [];
    return ['path' => $file, 'mime' => $mime, 'reference' => $reference];
}

function document_signature_image_url(array $document): string
{
    $file = document_signature_file($document);
    if ($file === []) return '';
    $reference = $file['reference'];
    return 'document-signature-image.php?' . http_build_query(['path' => $reference['path'], 'hash' => $reference['sha256']]);
}

function document_signature_render(array $document, string $label = 'Authorized Signatory'): string
{
    // Use a streamed image response instead of a multi-megabyte data URI. Large
    // data URIs exhausted memory on otherwise valid invoice/quotation views.
    $src = document_signature_image_url($document);
    if ($src === '') return '';
    return '<div class="document-signature" style="text-align:right;break-inside:avoid;page-break-inside:avoid"><img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Signature" style="display:inline-block;max-width:220px;max-height:100px;object-fit:contain"><div style="font-weight:700">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

function document_signature_admin_controls(array $document, string $type, string $returnUrl, bool $editable): string
{
    $id = (string)($document['id'] ?? '');
    if ($id === '') return '<p class="muted-helper">Save this document before adding a signature.</p>';
    $ownReference = document_signature_reference($document);
    $hasOwnSignature = $ownReference !== [];
    $preview = $hasOwnSignature || $type === 'universal' ? document_signature_render($document) : '';
    $universalPreview = !$hasOwnSignature && $type !== 'universal' ? document_signature_render([]) : '';
    $csrf = function_exists('csrf_token') ? csrf_token() : (string)($_SESSION['csrf_token'] ?? '');
    $disabled = $editable ? '' : ' disabled';
    return '<section class="form-section-card document-signature-controls"><h3>Authorized Signatory signature</h3>'
        . ($preview !== '' ? $preview : ($universalPreview !== '' ? $universalPreview . '<p class="muted-helper">Using the universal signature. Upload here to override it only for this document.</p>' : '<p class="muted-helper">No signature uploaded. The document will render as before.</p>'))
        . '<form method="post" enctype="multipart/form-data" action="document-signature-admin.php">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '"><input type="hidden" name="document_type" value="' . htmlspecialchars($type, ENT_QUOTES) . '"><input type="hidden" name="document_id" value="' . htmlspecialchars($id, ENT_QUOTES) . '"><input type="hidden" name="return_url" value="' . htmlspecialchars($returnUrl, ENT_QUOTES) . '">'
        . '<label>PNG/JPG image (maximum 5 MB)<input type="file" name="signature_image" accept="image/png,image/jpeg,.png,.jpg,.jpeg"' . $disabled . '></label> '
        . '<button class="btn" name="signature_action" value="upload"' . $disabled . '>' . ($hasOwnSignature ? 'Replace signature' : ($universalPreview !== '' ? 'Override for this document' : 'Upload signature')) . '</button> '
        . ($hasOwnSignature ? '<button class="btn secondary" name="signature_action" value="remove"' . $disabled . ' onclick="return confirm(\'Remove this signature?\')">Remove signature</button>' : '')
        . (!$editable ? '<p class="muted-helper">Create or open an editable draft/revision to change this signature.</p>' : '') . '</form></section>';
}
