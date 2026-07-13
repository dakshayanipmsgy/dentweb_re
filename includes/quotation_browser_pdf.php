<?php
declare(strict_types=1);

const QUOTATION_BROWSER_PDF_DEFAULT_TIMEOUT = 45;

function quotation_browser_pdf_node_path(): string
{
    return trim((string) (getenv('QUOTATION_NODE_PATH') ?: ''));
}

function quotation_browser_pdf_chromium_path(): string
{
    return trim((string) (getenv('QUOTATION_CHROMIUM_PATH') ?: getenv('CHROME_PATH') ?: getenv('CHROMIUM_PATH') ?: ''));
}

function quotation_browser_pdf_timeout_seconds(): int
{
    $raw = (int) (getenv('QUOTATION_PDF_TIMEOUT_SECONDS') ?: QUOTATION_BROWSER_PDF_DEFAULT_TIMEOUT);
    return max(5, min(180, $raw));
}

function quotation_browser_pdf_validate_executable(string $path, string $label): string
{
    if ($path === '') {
        throw new RuntimeException($label . ' is not configured. Set QUOTATION_CHROMIUM_PATH to a Chromium or Chrome executable path.');
    }
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException($label . ' must be an absolute executable path.');
    }
    if (!is_file($path) || !is_executable($path)) {
        throw new RuntimeException($label . ' is not executable: ' . $path);
    }
    return $path;
}

function quotation_browser_pdf_create_private_temp_dir(string $prefix = 'dentweb-quote-export-'): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    for ($i = 0; $i < 20; $i++) {
        $path = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }
    throw new RuntimeException('Unable to create a private temporary directory for quotation PDF export.');
}

function quotation_browser_pdf_remove_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            quotation_browser_pdf_remove_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function quotation_browser_pdf_render_html_file(string $htmlPath, string $pdfPath, ?string $workDir = null): void
{
    $chromium = quotation_browser_pdf_validate_executable(quotation_browser_pdf_chromium_path(), 'Chromium/Chrome');
    $profileDir = ($workDir ?: dirname($htmlPath)) . DIRECTORY_SEPARATOR . 'chrome-profile-' . bin2hex(random_bytes(8));
    if (!@mkdir($profileDir, 0700, true) && !is_dir($profileDir)) {
        throw new RuntimeException('Unable to create an isolated Chromium profile directory.');
    }
    $timeout = quotation_browser_pdf_timeout_seconds();
    $logPath = ($workDir ?: dirname($htmlPath)) . DIRECTORY_SEPARATOR . 'chromium-' . bin2hex(random_bytes(6)) . '.log';
    $url = 'file://' . str_replace('%2F', '/', rawurlencode($htmlPath));
    $cmd = [
        $chromium,
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-dev-shm-usage',
        '--allow-file-access-from-files',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=' . (string) ($timeout * 1000),
        '--user-data-dir=' . $profileDir,
        '--print-to-pdf=' . $pdfPath,
        '--print-to-pdf-no-header',
        '--no-pdf-header-footer',
        $url,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];
    $proc = proc_open($cmd, $descriptors, $pipes, $workDir ?: dirname($htmlPath));
    if (!is_resource($proc)) {
        quotation_browser_pdf_remove_tree($profileDir);
        throw new RuntimeException('Unable to launch Chromium for quotation PDF export.');
    }
    fclose($pipes[0]);
    $start = time();
    do {
        $status = proc_get_status($proc);
        if (!$status['running']) { break; }
        if (time() - $start > $timeout) {
            proc_terminate($proc, 15);
            usleep(250000);
            $status = proc_get_status($proc);
            if ($status['running']) { proc_terminate($proc, 9); }
            proc_close($proc);
            quotation_browser_pdf_remove_tree($profileDir);
            throw new RuntimeException('Quotation PDF export timed out before Chromium finished rendering.');
        }
        usleep(100000);
    } while (true);
    $exit = proc_close($proc);
    quotation_browser_pdf_remove_tree($profileDir);
    if ($exit !== 0) {
        $log = is_file($logPath) ? trim((string) file_get_contents($logPath)) : '';
        throw new RuntimeException('Chromium failed to generate the quotation PDF.' . ($log !== '' ? ' ' . substr($log, 0, 500) : ''));
    }
    $signature = is_file($pdfPath) ? (string) file_get_contents($pdfPath, false, null, 0, 5) : '';
    if ($signature !== '%PDF-') {
        throw new RuntimeException('Chromium did not produce a valid PDF for the quotation export.');
    }
}
