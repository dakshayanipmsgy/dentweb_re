<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_login();
$user = current_user();
if (!$user) {
    http_response_code(403);
    exit('Access denied.');
}

$reference = isset($_GET['complaint']) ? trim((string) $_GET['complaint']) : '';
$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($reference === '' || $token === '') {
    http_response_code(404);
    exit('File not found.');
}

$db = get_db();

try {
    $complaint = portal_get_complaint($db, $reference);
} catch (Throwable $exception) {
    http_response_code(404);
    exit('File not found.');
}

$role = (string) ($user['role_name'] ?? '');
if ($role === 'employee') {
    $assignedTo = $complaint['assignedTo'] ?? null;
    if ($assignedTo === null || (int) $assignedTo !== (int) ($user['id'] ?? 0)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$attachment = null;
foreach ($complaint['attachments'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (($item['downloadToken'] ?? '') !== $token) {
        continue;
    }
    if (($item['visibility'] ?? 'both') === 'admin' && $role !== 'admin') {
        continue;
    }
    $attachment = $item;
    break;
}

if ($attachment === null) {
    http_response_code(404);
    exit('File not found.');
}

$filename = (string) ($attachment['filename'] ?? 'attachment.txt');
if ($filename === '') {
    $filename = preg_replace('/\s+/', '-', strtolower((string) ($attachment['label'] ?? 'attachment.txt')));
}
$filename = $filename !== '' ? $filename : 'attachment.txt';
$sanitizedFilename = str_replace(['"', '\\'], '', $filename);

$body = "Attachment placeholder for {$attachment['label']} (Ticket {$complaint['reference']}).\n";
$body .= "Uploaded by {$attachment['uploadedBy']} on {$attachment['uploadedAt']}.\n";
$body .= "Download token: {$attachment['downloadToken']}.\n";

portal_log_action(
    $db,
    (int) ($user['id'] ?? 0),
    'download',
    'complaint_attachment',
    (int) $complaint['id'],
    'Secure complaint attachment download'
);

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="' . $sanitizedFilename . '"');
header('Content-Length: ' . strlen($body));

echo $body;
