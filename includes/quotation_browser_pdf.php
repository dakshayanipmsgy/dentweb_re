<?php
declare(strict_types=1);

const QUOTATION_BROWSER_PDF_DEFAULT_TIMEOUT = 45;
const QUOTATION_BROWSER_PDF_VERSION_TIMEOUT = 3;

class QuotationBrowserPdfException extends RuntimeException
{
    public string $quotationPdfCode;
    public function __construct(string $message, string $code = 'runtime_failure')
    {
        parent::__construct($message);
        $this->quotationPdfCode = $code;
    }
}

function quotation_browser_pdf_node_path(): string
{
    return trim((string) (getenv('QUOTATION_NODE_PATH') ?: ''));
}

function quotation_browser_pdf_chromium_path(): string
{
    $result = quotation_browser_pdf_discover();
    return $result['available'] ? (string) $result['path'] : '';
}

function quotation_browser_pdf_timeout_seconds(): int
{
    $raw = (int) (getenv('QUOTATION_PDF_TIMEOUT_SECONDS') ?: QUOTATION_BROWSER_PDF_DEFAULT_TIMEOUT);
    return max(5, min(180, $raw));
}

function quotation_browser_pdf_managed_browser_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'browsers';
}

function quotation_browser_pdf_candidate_names(): array
{
    return ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome', 'chrome-headless-shell'];
}

function quotation_browser_pdf_candidate_paths(?array $overrides = null): array
{
    if (is_array($overrides)) { return $overrides; }
    $candidates = [];
    foreach (['QUOTATION_CHROMIUM_PATH', 'CHROME_PATH', 'CHROMIUM_PATH'] as $env) {
        $path = trim((string) (getenv($env) ?: ''));
        if ($path !== '') { $candidates[] = ['path' => $path, 'source' => 'configured', 'label' => $env, 'configured' => true]; }
    }
    $managed = quotation_browser_pdf_managed_browser_dir();
    foreach (quotation_browser_pdf_candidate_names() as $name) {
        $candidates[] = ['path' => $managed . DIRECTORY_SEPARATOR . $name, 'source' => 'repository-managed', 'label' => 'managed browser', 'configured' => false];
    }
    $pathEnv = (string) (getenv('PATH') ?: '');
    foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
        $dir = trim($dir);
        if ($dir === '' || !str_starts_with($dir, DIRECTORY_SEPARATOR)) { continue; }
        foreach (quotation_browser_pdf_candidate_names() as $name) {
            $candidates[] = ['path' => rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name, 'source' => 'path', 'label' => 'PATH', 'configured' => false];
        }
    }
    foreach (['/usr/bin/google-chrome','/usr/bin/google-chrome-stable','/usr/bin/chromium','/usr/bin/chromium-browser','/snap/bin/chromium','/opt/google/chrome/chrome','/usr/local/bin/google-chrome','/usr/local/bin/chromium'] as $path) {
        $candidates[] = ['path' => $path, 'source' => 'common', 'label' => 'common location', 'configured' => false];
    }
    $home = (string) (getenv('HOME') ?: '');
    if ($home !== '' && str_starts_with($home, DIRECTORY_SEPARATOR)) {
        foreach (glob($home . '/.cache/ms-playwright/chromium-*/chrome-linux/chrome') ?: [] as $path) {
            $candidates[] = ['path' => $path, 'source' => 'playwright-cache', 'label' => 'Playwright cache', 'configured' => false];
        }
        foreach (glob($home . '/.cache/ms-playwright/chrome-*/chrome-linux/chrome') ?: [] as $path) {
            $candidates[] = ['path' => $path, 'source' => 'playwright-cache', 'label' => 'Playwright cache', 'configured' => false];
        }
    }
    return $candidates;
}

function quotation_browser_pdf_empty_result(string $status = 'not_found', string $warning = ''): array
{
    return ['available' => false, 'path' => '', 'name' => '', 'version' => '', 'source' => '', 'source_label' => '', 'configured' => false, 'status' => $status, 'warning' => $warning, 'diagnostics' => []];
}

function quotation_browser_pdf_is_absolute_path(string $path): bool
{
    return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\/]/', $path) === 1;
}

function quotation_browser_pdf_probe_executable(string $path, int $timeout = QUOTATION_BROWSER_PDF_VERSION_TIMEOUT): array
{
    $path = trim($path);
    if ($path === '' || !quotation_browser_pdf_is_absolute_path($path)) { return ['ok' => false, 'reason' => 'Browser path must be absolute.']; }
    if (!is_file($path)) { return ['ok' => false, 'reason' => 'Browser path is not a file.']; }
    if (!is_executable($path)) { return ['ok' => false, 'reason' => 'Browser path is not executable.']; }
    if (!function_exists('proc_open')) { return ['ok' => false, 'reason' => 'PHP proc_open is unavailable.']; }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$path, '--version'], $desc, $pipes, dirname($path));
    if (!is_resource($proc)) { return ['ok' => false, 'reason' => 'Browser could not be launched for version check.']; }
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $start = microtime(true);
    do {
        $out .= (string) stream_get_contents($pipes[1]); $out .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) { break; }
        if ((microtime(true) - $start) > $timeout) {
            proc_terminate($proc, 15); usleep(100000); $status = proc_get_status($proc); if ($status['running']) { proc_terminate($proc, 9); }
            foreach ([1,2] as $i) { if (isset($pipes[$i]) && is_resource($pipes[$i])) { fclose($pipes[$i]); } }
            proc_close($proc);
            return ['ok' => false, 'reason' => 'Browser version check timed out.'];
        }
        usleep(50000);
    } while (true);
    $out .= (string) stream_get_contents($pipes[1]); $out .= (string) stream_get_contents($pipes[2]);
    foreach ([1,2] as $i) { if (isset($pipes[$i]) && is_resource($pipes[$i])) { fclose($pipes[$i]); } }
    $exit = proc_close($proc);
    $version = trim(substr(preg_replace('/[\r\n\t]+/', ' ', $out) ?? '', 0, 200));
    if ($exit !== 0 || $version === '') { return ['ok' => false, 'reason' => 'Browser version check failed.']; }
    if (!preg_match('/(Google Chrome|Chromium|Chrome Headless Shell|Chrome)\s+([0-9][^\s]*)?/i', $version, $m)) { return ['ok' => false, 'reason' => 'Executable is not a Chrome or Chromium browser.']; }
    return ['ok' => true, 'name' => $m[1], 'version' => $version];
}

function quotation_browser_pdf_discover(?array $candidateFixtures = null, bool $reset = false): array
{
    static $cached = null;
    if ($reset || $candidateFixtures !== null) { $cached = null; }
    if ($cached !== null && $candidateFixtures === null) { return $cached; }
    $warnings = []; $diagnostics = []; $seen = [];
    foreach (quotation_browser_pdf_candidate_paths($candidateFixtures) as $candidate) {
        $path = is_array($candidate) ? (string) ($candidate['path'] ?? '') : (string) $candidate;
        $source = is_array($candidate) ? (string) ($candidate['source'] ?? 'candidate') : 'candidate';
        $label = is_array($candidate) ? (string) ($candidate['label'] ?? $source) : $source;
        $configured = is_array($candidate) && !empty($candidate['configured']);
        $key = $path;
        if ($path === '' || isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $probe = quotation_browser_pdf_probe_executable($path);
        if (($probe['ok'] ?? false) === true) {
            $result = ['available' => true, 'path' => $path, 'name' => (string) $probe['name'], 'version' => (string) $probe['version'], 'source' => $source, 'source_label' => $label, 'configured' => $configured, 'status' => 'available', 'warning' => implode(' ', $warnings), 'diagnostics' => $diagnostics];
            if ($candidateFixtures === null) { $cached = $result; }
            return $result;
        }
        $safeReason = (string) ($probe['reason'] ?? 'Unavailable browser candidate.');
        $diagnostics[] = ['source' => $source, 'label' => $label, 'configured' => $configured, 'reason' => $safeReason];
        if ($configured) { $warnings[] = $label . ' was not usable; automatic browser discovery continued.'; }
    }
    $result = quotation_browser_pdf_empty_result('not_found', implode(' ', $warnings));
    $result['diagnostics'] = $diagnostics;
    if ($candidateFixtures === null) { $cached = $result; }
    return $result;
}

function quotation_browser_pdf_capabilities(): array
{
    $discovery = quotation_browser_pdf_discover();
    return ['proc_open' => function_exists('proc_open'), 'temp_writable' => is_writable(sys_get_temp_dir()), 'zip' => class_exists('ZipArchive'), 'browser' => $discovery, 'server_pdf_available' => $discovery['available'] && function_exists('proc_open') && is_writable(sys_get_temp_dir()), 'fallback_available' => true];
}

function quotation_browser_pdf_is_available(): bool { return quotation_browser_pdf_capabilities()['server_pdf_available']; }

function quotation_browser_pdf_validate_executable(string $path, string $label): string
{
    $probe = quotation_browser_pdf_probe_executable($path);
    if (($probe['ok'] ?? false) !== true) { throw new QuotationBrowserPdfException($label . ' is not available. ' . (string) ($probe['reason'] ?? ''), 'not_installed'); }
    return $path;
}

function quotation_browser_pdf_create_private_temp_dir(string $prefix = 'dentweb-quote-export-'): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    for ($i = 0; $i < 20; $i++) { $path = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12)); if (@mkdir($path, 0700)) { return $path; } }
    throw new QuotationBrowserPdfException('Unable to create a private temporary directory for quotation PDF export.', 'temp_unavailable');
}

function quotation_browser_pdf_remove_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    $items = scandir($path);
    if ($items !== false) { foreach ($items as $item) { if ($item === '.' || $item === '..') { continue; } quotation_browser_pdf_remove_tree($path . DIRECTORY_SEPARATOR . $item); } }
    @rmdir($path);
}

function quotation_browser_pdf_render_html_file(string $htmlPath, string $pdfPath, ?string $workDir = null): void
{
    $discovery = quotation_browser_pdf_discover();
    if (empty($discovery['available'])) { throw new QuotationBrowserPdfException('Server PDF generation is unavailable because Chrome or Chromium was not found.', 'not_installed'); }
    $chromium = (string) $discovery['path'];
    $profileDir = ($workDir ?: dirname($htmlPath)) . DIRECTORY_SEPARATOR . 'chrome-profile-' . bin2hex(random_bytes(8));
    if (!@mkdir($profileDir, 0700, true) && !is_dir($profileDir)) { throw new QuotationBrowserPdfException('Unable to create an isolated Chromium profile directory.', 'temp_unavailable'); }
    $timeout = quotation_browser_pdf_timeout_seconds();
    $logPath = ($workDir ?: dirname($htmlPath)) . DIRECTORY_SEPARATOR . 'chromium-' . bin2hex(random_bytes(6)) . '.log';
    $url = 'file://' . str_replace('%2F', '/', rawurlencode($htmlPath));
    $cmd = [$chromium,'--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-dev-shm-usage','--allow-file-access-from-files','--run-all-compositor-stages-before-draw','--virtual-time-budget=' . (string) ($timeout * 1000),'--user-data-dir=' . $profileDir,'--print-to-pdf=' . $pdfPath,'--print-to-pdf-no-header','--no-pdf-header-footer',$url];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];
    $proc = @proc_open($cmd, $descriptors, $pipes, $workDir ?: dirname($htmlPath));
    if (!is_resource($proc)) { quotation_browser_pdf_remove_tree($profileDir); throw new QuotationBrowserPdfException('Unable to launch Chromium for quotation PDF export.', 'launch_failure'); }
    fclose($pipes[0]); $start = time();
    do { $status = proc_get_status($proc); if (!$status['running']) { break; } if (time() - $start > $timeout) { proc_terminate($proc, 15); usleep(250000); $status = proc_get_status($proc); if ($status['running']) { proc_terminate($proc, 9); } proc_close($proc); quotation_browser_pdf_remove_tree($profileDir); throw new QuotationBrowserPdfException('Quotation PDF export timed out before Chromium finished rendering.', 'timeout'); } usleep(100000); } while (true);
    $exit = proc_close($proc); quotation_browser_pdf_remove_tree($profileDir);
    if ($exit !== 0) { throw new QuotationBrowserPdfException('Chromium failed to generate the quotation PDF.', 'launch_failure'); }
    $signature = is_file($pdfPath) ? (string) file_get_contents($pdfPath, false, null, 0, 5) : '';
    if ($signature !== '%PDF-') { throw new QuotationBrowserPdfException('Chromium did not produce a valid PDF for the quotation export.', 'invalid_output'); }
}
