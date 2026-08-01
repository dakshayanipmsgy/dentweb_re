<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/task_workflow.php';
require_login_any_role(['admin','employee']);
try {
    $file=(new TaskWorkflowService(get_db(),current_user()??[]))->attachment((int)($_GET['id']??0));
    header('Content-Type: '.$file['media_type']);
    header('Content-Length: '.(string)$file['byte_size']);
    header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['original_name']));
    header('X-Content-Type-Options: nosniff');
    readfile($file['path']);
} catch(Throwable $e) { http_response_code(404); echo 'Attachment unavailable.'; }
