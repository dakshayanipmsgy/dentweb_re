<?php

declare(strict_types=1);

$dataRoots = [__DIR__ . '/../data/documents', __DIR__ . '/../data/inventory'];
$originalFiles = [];
$originalModes = [];
foreach ($dataRoots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $originalFiles[$file->getPathname()] = file_get_contents($file->getPathname());
            $originalModes[$file->getPathname()] = $file->getPerms() & 0777;
        }
    }
}

$restoreData = static function () use ($dataRoots, $originalFiles, $originalModes): void {
    foreach ($dataRoots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && !array_key_exists($path, $originalFiles)) {
                unlink($path);
            } elseif ($file->isDir() && count(scandir($path) ?: []) === 2) {
                rmdir($path);
            }
        }
    }
    foreach ($originalFiles as $path => $contents) {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $contents);
        chmod($path, $originalModes[$path]);
    }
};

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
};

$id = 'TEST-STATUS-' . bin2hex(random_bytes(6));
$path = documents_quotations_dir() . '/' . safe_filename($id) . '.json';
$actor = ['id' => 'test-admin', 'name' => 'Test Admin'];
$quote = documents_quote_defaults();
$quote['id'] = $id;
$quote['quote_no'] = $id;
$quote['quote_series_id'] = $id;
$quote['status'] = 'draft';
$quote['customer_name'] = '';
$quote['customer_mobile'] = '';
$quote['site_address'] = '';
$quote['capacity_kwp'] = '';
$quote['input_total_gst_inclusive'] = 0;

try {
    $invalidAcceptanceData = documents_quote_has_valid_acceptance_data($quote);
    $assert(($invalidAcceptanceData['ok'] ?? true) === false, 'fixture lacks the legacy row-only acceptance data');

    $prematureAccept = documents_quote_apply_admin_status_transition($quote, 'accepted', $actor);
    $assert(($prematureAccept['ok'] ?? true) === false, 'draft quote cannot bypass the shared approved-to-accepted transition');

    $approved = documents_quote_apply_admin_status_transition($quote, 'approved', $actor);
    $assert(($approved['ok'] ?? false) === true, 'shared transition approves a draft quote');
    $approvedQuote = is_array($approved['quote'] ?? null) ? $approved['quote'] : [];
    $assert(($approvedQuote['status'] ?? '') === 'approved', 'approval transition sets approved status');
    $assert(($approvedQuote['approval']['approved_by_id'] ?? '') === 'test-admin', 'approval transition records the administrator');

    $accepted = documents_quote_apply_admin_status_transition($approvedQuote, 'accepted', $actor);
    $assert(($accepted['ok'] ?? false) === true, 'shared transition accepts without the legacy row-only data validation');
    $acceptedQuote = is_array($accepted['quote'] ?? null) ? $accepted['quote'] : [];
    $assert(($acceptedQuote['status'] ?? '') === 'accepted', 'acceptance transition sets accepted status');
    $assert(($acceptedQuote['locked_flag'] ?? false) === true, 'acceptance transition locks the quote');
    $assert(($acceptedQuote['is_current_version'] ?? false) === true, 'acceptance transition keeps the accepted quote current');
    $assert(trim((string) ($acceptedQuote['accepted_at'] ?? '')) !== '', 'acceptance transition records accepted_at');
    $assert(trim((string) ($acceptedQuote['locked_at'] ?? '')) !== '', 'acceptance transition records locked_at');

    $acceptedAgain = documents_quote_apply_admin_status_transition($acceptedQuote, 'accepted', $actor);
    $assert(($acceptedAgain['ok'] ?? false) === true, 'shared transition preserves Bulk Tools accepted-to-accepted behavior');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $failed = true;
} finally {
    $restoreData();
}

if (!empty($failed)) {
    exit(1);
}
