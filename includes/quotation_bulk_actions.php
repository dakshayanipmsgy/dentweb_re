<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/quotation_view_renderer.php';
require_once __DIR__ . '/quotation_browser_pdf.php';
require_once __DIR__ . '/quotation_zip_writer.php';

const QUOTATION_BULK_EXPORT_LIMIT = 25;
const QUOTATION_BROWSER_EXPORT_LIMIT = 10;
const QUOTATION_BROWSER_EXPORT_TOKEN_TTL = 600;

function quotation_bulk_normalize_selected_ids($selected): array
{
    $raw = is_array($selected) ? $selected : [];
    $seen = [];
    $ids = [];
    foreach ($raw as $value) {
        $id = safe_text((string) $value);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ids[] = $id;
    }
    return $ids;
}

function quotation_bulk_resolve_quotes(array $ids): array
{
    if ($ids === []) {
        throw new RuntimeException('No valid quotation IDs were submitted. Select at least one quotation and try again.');
    }
    if (count($ids) > QUOTATION_BULK_EXPORT_LIMIT) {
        throw new RuntimeException('Too many quotations selected. Select ' . QUOTATION_BULK_EXPORT_LIMIT . ' or fewer quotations.');
    }
    $quotes = [];
    foreach ($ids as $id) {
        $quote = documents_get_quote($id);
        if ($quote === null) {
            throw new RuntimeException('Quotation not found or no longer available: ' . $id);
        }
        $quotes[] = $quote;
    }
    return $quotes;
}

function quotation_bulk_safe_filename_part(string $value, string $fallback = 'quotation'): string
{
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9._-]+/i', '-', $value) ?? '';
    $value = trim($value, '.-_');
    if ($value === '') {
        $value = $fallback;
    }
    return substr($value, 0, 80);
}

function quotation_bulk_pdf_filename(array $quote, array &$used = []): string
{
    $quoteNo = quotation_bulk_safe_filename_part((string) ($quote['quote_no'] ?? ''), 'quote');
    $customer = quotation_bulk_safe_filename_part((string) ($quote['customer_name'] ?? ''), 'customer');
    $id = quotation_bulk_safe_filename_part((string) ($quote['id'] ?? ''), bin2hex(random_bytes(4)));
    $base = 'quotation-' . $quoteNo . '-' . $customer . '-' . $id;
    $name = $base . '.pdf';
    $i = 2;
    while (isset($used[$name])) {
        $name = $base . '-' . $i . '.pdf';
        $i++;
    }
    $used[$name] = true;
    return $name;
}

function quotation_bulk_extract_head_assets(string $html): string
{
    if (!class_exists('DOMDocument')) {
        return '';
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_use_internal_errors($old);
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) { return ''; }
    $out = '';
    foreach ($head->childNodes as $child) {
        if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['script', 'title', 'meta'], true)) { continue; }
        $out .= $dom->saveHTML($child);
    }
    return $out;
}

function quotation_bulk_extract_body_fragment(string $html): string
{
    if (!class_exists('DOMDocument')) {
        return preg_replace('#<!doctype.*?<body[^>]*>|</body>.*#is', '', $html) ?? $html;
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_use_internal_errors($old);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) { return $html; }
    $out = '';
    foreach ($body->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->tagName) === 'script') { continue; }
        $out .= $dom->saveHTML($child);
    }
    return $out;
}

function quotation_bulk_combined_print_html(array $quotes, array $quoteDefaults, array $company, string $bannerHtml = ''): string
{
    $parts = [];
    $headAssets = '';
    foreach ($quotes as $quote) {
        $html = quotation_render_to_html($quote, $quoteDefaults, $company, false, '', 'admin', 'bulk-print');
        if ($headAssets === '') {
            $headAssets = quotation_bulk_extract_head_assets($html);
        }
        $parts[] = '<section class="bulk-print-quotation">' . quotation_bulk_extract_body_fragment($html) . '</section>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print Selected Quotations</title>' . $headAssets . '<style>.bulk-print-toolbar{position:sticky;top:0;z-index:9999;padding:10px;background:#fff;border-bottom:1px solid #ddd}.bulk-print-fallback-banner{padding:12px 14px;background:#fff7ed;border:1px solid #fdba74;border-radius:10px;margin:10px;color:#9a3412}.bulk-print-quotation{break-after:page;page-break-after:always}.bulk-print-quotation:last-child{break-after:auto;page-break-after:auto}@media print{.bulk-print-toolbar,.bulk-print-fallback-banner{display:none!important}}</style></head><body>' . $bannerHtml . '<div class="bulk-print-toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>' . implode('', $parts) . '<script>(function(){const go=()=>setTimeout(()=>window.print(),400); if(window.__quotationPdfReady){go();}else{window.addEventListener("load",go); setTimeout(go,1600);}})();</script></body></html>';
}


function quotation_bulk_browser_print_fallback_html(array $quotes, array $quoteDefaults, array $company, string $reason = ''): string
{
    $multiple = count($quotes) > 1;
    $message = 'Server PDF download is not available on this hosting environment. Choose Save as PDF in the print window.';
    $extra = $multiple ? ' The browser will save one combined PDF for the selected quotations; no ZIP was generated.' : '';
    $safeReason = trim($reason) !== '' ? '<br><small>' . htmlspecialchars($reason, ENT_QUOTES) . '</small>' : '';
    $banner = '<div class="bulk-print-fallback-banner"><strong>' . htmlspecialchars($message, ENT_QUOTES) . '</strong>' . htmlspecialchars($extra, ENT_QUOTES) . $safeReason . '<div style="margin-top:8px"><button onclick="window.print()">Print / Save as PDF</button></div></div>';
    return quotation_bulk_combined_print_html($quotes, $quoteDefaults, $company, $banner);
}

function quotation_bulk_pdf_engine_status_text(array $capabilities): string
{
    if (!empty($capabilities['server_pdf_available'])) {
        return 'Separate PDF/ZIP export ready';
    }
    if (empty($capabilities['proc_open'])) { return 'This hosting platform cannot run the server PDF engine'; }
    return quotation_browser_managed_install_available() ? 'PDF engine repair required — one-click repair available' : 'Using browser PDF/ZIP export — no server setup required.';
}

function quotation_bulk_pdf_diagnostics(): array
{
    $cap = quotation_browser_pdf_capabilities();
    $browser = is_array($cap['browser'] ?? null) ? $cap['browser'] : [];
    $testOk = false;
    $testMessage = 'Test PDF was not generated because server PDF generation is unavailable.';
    if (!empty($cap['server_pdf_available'])) {
        $dir = '';
        try {
            $dir = quotation_browser_pdf_create_private_temp_dir('dentweb-quote-diagnostic-');
            $html = $dir . DIRECTORY_SEPARATOR . 'diagnostic.html';
            $pdf = $dir . DIRECTORY_SEPARATOR . 'diagnostic.pdf';
            file_put_contents($html, '<!doctype html><meta charset="utf-8"><title>PDF diagnostic</title><h1>PDF diagnostic</h1>');
            quotation_browser_pdf_render_html_file($html, $pdf, $dir);
            $testOk = is_file($pdf) && (string) file_get_contents($pdf, false, null, 0, 5) === '%PDF-';
            $testMessage = $testOk ? 'A small test PDF was generated successfully.' : 'The test PDF was not valid.';
        } catch (Throwable $e) {
            $testMessage = 'The test PDF could not be generated; Save as PDF fallback remains available.';
        } finally {
            if ($dir !== '') { quotation_browser_pdf_remove_tree($dir); }
        }
    }
    return ['capabilities' => $cap, 'summary' => !empty($cap['server_pdf_available']) && $testOk ? 'Separate PDF/ZIP export ready.' : 'PDF engine repair may be required before separate PDFs can be generated.', 'browser_message' => !empty($browser['available']) ? (((bool) ($browser['configured'] ?? false)) ? 'Chrome/Chromium is configured.' : 'Chrome was found automatically.') : 'Chrome/Chromium was not found.', 'test_ok' => $testOk, 'test_message' => $testMessage];
}

function quotation_bulk_render_pdf_file(array $quote, array $quoteDefaults, array $company, string $path): void
{
    $workDir = quotation_browser_pdf_create_private_temp_dir();
    try {
        $htmlPath = $workDir . DIRECTORY_SEPARATOR . 'quotation.html';
        try {
            $html = quotation_render_to_html($quote, $quoteDefaults, $company, false, '', 'admin', 'pdf-export');
        } catch (Throwable $e) {
            throw new QuotationBrowserPdfException('Quotation HTML rendering failed before PDF export.', 'quotation_render_failure');
        }
        $html = quotation_bulk_prepare_browser_pdf_html($html);
        if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary quotation HTML for PDF export.');
        }
        quotation_browser_pdf_render_html_file($htmlPath, $path, $workDir);
    } finally {
        quotation_browser_pdf_remove_tree($workDir);
    }
}

function quotation_bulk_prepare_browser_pdf_html(string $html): string
{
    $base = quotation_bulk_base_href();
    $readiness = <<<'HTML'
<script>
(function(){
  window.__quotationPdfReady=false;
  const frame=()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
  const waitImages=()=>Promise.all(Array.from(document.images||[]).map(img=>{
    if(img.complete) return Promise.resolve();
    return new Promise(resolve=>{img.addEventListener('load',resolve,{once:true});img.addEventListener('error',resolve,{once:true});});
  }));
  const waitCharts=async()=>{
    if(typeof window.buildChartPrintImages==='function'){window.buildChartPrintImages();}
    await frame();
    if(typeof window.buildChartPrintImages==='function'){window.buildChartPrintImages();}
    const chartImgs=Array.from(document.querySelectorAll('.chart-print-img'));
    await Promise.all(chartImgs.map(img=>img.complete?Promise.resolve():new Promise(resolve=>{img.addEventListener('load',resolve,{once:true});img.addEventListener('error',resolve,{once:true});})));
  };
  const ready=async()=>{
    try{
      if(document.fonts&&document.fonts.ready){await document.fonts.ready;}
      await waitImages();
      await waitCharts();
      await waitImages();
      await frame();
      if(!window.__quotationBrowserPagedExport){
        window.__quotationPdfReady=true;
        document.documentElement.setAttribute('data-quotation-pdf-ready','true');
      }
    }catch(e){window.__quotationPdfReady=false;window.__quotationPdfReadyError=String(e&&e.message?e.message:e);}
  };
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',ready,{once:true});}else{ready();}
})();
</script>
HTML;
    if (stripos($html, '<head') !== false) {
        $html = preg_replace('/<head([^>]*)>/i', '<head$1><base href="' . htmlspecialchars($base, ENT_QUOTES) . '">', $html, 1) ?? $html;
    }
    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $readiness . '</body>', $html);
    } else {
        $html .= $readiness;
    }
    return $html;
}

function quotation_bulk_base_href(): string
{
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $root), '/') . '/';
    return 'file://' . $root;
}


function quotation_bulk_failure_code(Throwable $e): string
{
    if ($e instanceof QuotationBrowserPdfException) { return $e->quotationPdfCode; }
    if ($e instanceof QuotationZipException) { return 'zip_creation_failure'; }
    return 'zip_creation_failure';
}

function quotation_bulk_failure_message(string $code): string
{
    $messages = [
        'browser_not_found' => 'Chrome/Chromium was not found for high-quality PDF export.',
        'proc_open_unavailable' => 'This hosting platform blocks PHP process execution, so the server PDF engine cannot run.',
        'temp_unavailable' => 'Temporary private storage is not available for PDF export.',
        'browser_launch_failure' => 'The PDF browser could not be launched successfully.',
        'browser_timeout' => 'The PDF browser timed out while rendering.',
        'invalid_pdf_output' => 'The browser did not produce a valid PDF.',
        'quotation_render_failure' => 'One quotation could not be rendered to HTML for PDF export.',
        'zip_unavailable' => 'No ZIP implementation is available.',
        'zip_creation_failure' => 'The ZIP archive could not be created.',
        'managed_browser_install_failure' => 'The managed browser could not be installed or repaired.',
    ];
    return $messages[$code] ?? 'The quotation PDF export could not be completed.';
}

function quotation_bulk_preflight(int $count): void
{
    if ($count < 1 || $count > QUOTATION_BULK_EXPORT_LIMIT) { throw new QuotationBrowserPdfException('Invalid quotation export selection size.', 'quotation_render_failure'); }
    if (!function_exists('proc_open')) { throw new QuotationBrowserPdfException('PHP process execution is unavailable.', 'proc_open_unavailable'); }
    if (!is_writable(sys_get_temp_dir())) { throw new QuotationBrowserPdfException('Temporary storage is unavailable.', 'temp_unavailable'); }
    if ($count > 1 && !function_exists('quotation_zip_write')) { throw new QuotationBrowserPdfException('ZIP creation is unavailable.', 'zip_unavailable'); }
    $disc = quotation_browser_pdf_discover();
    if (empty($disc['available'])) { throw new QuotationBrowserPdfException('Chrome/Chromium was not found.', 'browser_not_found'); }
}

function quotation_bulk_create_zip(array $pdfFiles, string $zipPath, bool $forcePurePhp = false): string
{
    try { return quotation_zip_write($pdfFiles, $zipPath, $forcePurePhp); }
    catch (Throwable $e) { @unlink($zipPath); throw new QuotationBrowserPdfException('Unable to create quotations ZIP archive.', 'zip_creation_failure'); }
}

function quotation_bulk_retry_store(array $ids): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $token = bin2hex(random_bytes(16));
    $_SESSION['quotation_pdf_retry'][$token] = ['ids'=>array_values($ids),'created'=>time(),'user'=>(string)((current_user()['id'] ?? current_user()['username'] ?? current_user()['role_name'] ?? 'admin')),'csrf'=>(string)($_SESSION['csrf_token'] ?? '')];
    return $token;
}

function quotation_bulk_retry_consume(string $token, bool $consume = false): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $state = $_SESSION['quotation_pdf_retry'][$token] ?? null;
    if (!is_array($state) || time() - (int)($state['created'] ?? 0) > 900 || !hash_equals((string)($state['csrf'] ?? ''), (string)($_SESSION['csrf_token'] ?? ''))) { unset($_SESSION['quotation_pdf_retry'][$token]); return []; }
    if ($consume) { unset($_SESSION['quotation_pdf_retry'][$token]); }
    return quotation_bulk_normalize_selected_ids($state['ids'] ?? []);
}

function quotation_bulk_repair_html(array $quotes, array $quoteDefaults, array $company, string $code, string $token): string
{
    $safe = htmlspecialchars(quotation_bulk_failure_message($code), ENT_QUOTES);
    $csrf = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES);
    $tok = htmlspecialchars($token, ENT_QUOTES);
    $n = count($quotes);
    $emergency = quotation_bulk_combined_print_html($quotes, $quoteDefaults, $company, '<div class="bulk-print-fallback-banner"><strong>Emergency combined Save as PDF.</strong> This is not equivalent to separate PDFs in a ZIP.</div>');
    $repairForm = quotation_browser_managed_install_available() ? '<form method="post"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="quotation_pdf_engine_repair"><input type="hidden" name="retry_token" value="'.$tok.'"><button class="btn" type="submit">Repair PDF engine and retry</button></form>' : '<p class="muted">Managed server-browser repair is disabled until a real pinned checksum is committed. Use browser PDF/ZIP export instead.</p>';
    return '<!doctype html><html><head><meta charset="utf-8"><title>Quotation PDF export fallback</title><style>body{font-family:system-ui,sans-serif;margin:24px;color:#0f172a}.card{max-width:820px;border:1px solid #e2e8f0;border-radius:14px;padding:20px}.btn{display:inline-block;margin:6px 6px 6px 0;padding:10px 14px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;text-decoration:none}.secondary{background:#fff;color:#0f172a;border-color:#94a3b8}.muted{color:#64748b}</style></head><body><div class="card"><h1>Use browser PDF/ZIP export</h1><p><strong>' . $safe . '</strong></p><p>The original selection of ' . (int)$n . ' unique quotation(s) is securely preserved. Return to Bulk Tools and choose Download using browser to create one PDF per quotation without Chrome, proc_open, Node.js, or ZipArchive on the server.</p>'.$repairForm.'<form method="post"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="bulk_download_quotation_pdfs"><input type="hidden" name="retry_token" value="'.$tok.'"><button class="btn secondary" type="submit">Retry server download</button></form><details><summary>Secondary emergency action: Open combined Save as PDF</summary><p class="muted">This produces one combined browser print document only; it is not the promised separate-PDF ZIP.</p>'.$emergency.'</details></div></body></html>';
}

function quotation_browser_export_user_key(): string
{
    $user = current_user();
    return (string)($user['id'] ?? $user['username'] ?? $user['role_name'] ?? 'admin');
}

function quotation_browser_export_create_token(array $ids): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $ids = quotation_bulk_normalize_selected_ids($ids);
    if ($ids === [] || count($ids) > QUOTATION_BROWSER_EXPORT_LIMIT) {
        throw new RuntimeException('Select between 1 and ' . QUOTATION_BROWSER_EXPORT_LIMIT . ' quotations for browser export.');
    }
    $quotes = quotation_bulk_resolve_quotes($ids);
    $used = [];
    $items = [];
    foreach ($quotes as $quote) {
        $items[] = [
            'id' => (string)($quote['id'] ?? ''),
            'label' => (string)($quote['quote_no'] ?? $quote['id'] ?? 'quotation'),
            'filename' => quotation_bulk_pdf_filename($quote, $used),
        ];
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['quotation_browser_export'][$token] = ['ids'=>$ids,'created'=>time(),'user'=>quotation_browser_export_user_key(),'csrf'=>(string)($_SESSION['csrf_token'] ?? '')];
    return ['token'=>$token,'expires_in'=>QUOTATION_BROWSER_EXPORT_TOKEN_TTL,'limit'=>QUOTATION_BROWSER_EXPORT_LIMIT,'items'=>$items];
}

function quotation_browser_export_token_ids(string $token): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $state = $_SESSION['quotation_browser_export'][$token] ?? null;
    if (!is_array($state) || time() - (int)($state['created'] ?? 0) > QUOTATION_BROWSER_EXPORT_TOKEN_TTL || !hash_equals((string)($state['csrf'] ?? ''), (string)($_SESSION['csrf_token'] ?? '')) || !hash_equals((string)($state['user'] ?? ''), quotation_browser_export_user_key())) {
        unset($_SESSION['quotation_browser_export'][$token]);
        return [];
    }
    return quotation_bulk_normalize_selected_ids($state['ids'] ?? []);
}

function quotation_browser_export_render_html(array $quote, array $quoteDefaults, array $company): string
{
    $html = quotation_render_to_html($quote, $quoteDefaults, $company, false, '', 'admin', 'browser-client-export');
    $html = quotation_bulk_prepare_browser_pdf_html($html);
    $paged = <<<'HTML'
<script src="assets/vendor/browser-export/paged.polyfill.min.js"></script>
<script>
(function(){
  window.__quotationBrowserPagedExport=true;
  window.__quotationPdfReady=false;
  window.__quotationPdfDiagnostics={paginatorScriptLoaded:false,paginatorApiFound:false,previewResolved:false,previewRejected:false,sourceDocumentHeight:0,resultingPageCount:0,firstPageDimensions:null};
  const frame=()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
  const waitImages=()=>Promise.all(Array.from(document.images||[]).map(img=>img.complete?Promise.resolve():new Promise(resolve=>{img.addEventListener('load',resolve,{once:true});img.addEventListener('error',resolve,{once:true});})));
  const waitAssets=async()=>{
    if(document.fonts&&document.fonts.ready){await document.fonts.ready;}
    if(typeof window.buildChartPrintImages==='function'){window.buildChartPrintImages();}
    await frame();
    if(typeof window.buildChartPrintImages==='function'){window.buildChartPrintImages();}
    await waitImages();
    await frame();
  };
  const fail=(message)=>{
    const d=window.__quotationPdfDiagnostics||{};
    window.__quotationPdfError='pagination_failed: '+message+' (script='+(d.paginatorScriptLoaded?'1':'0')+', api='+(d.paginatorApiFound?'1':'0')+', resolved='+(d.previewResolved?'1':'0')+', rejected='+(d.previewRejected?'1':'0')+', height='+(d.sourceDocumentHeight||0)+', pages='+(d.resultingPageCount||0)+', first='+(d.firstPageDimensions?Math.round(d.firstPageDimensions.width)+'x'+Math.round(d.firstPageDimensions.height):'n/a')+')';
  };
  const run=async()=>{
    try{
      window.__quotationPdfReady=false;
      await waitAssets();
      const d=window.__quotationPdfDiagnostics;
      d.paginatorScriptLoaded=!!(window.Paged||window.PagedPolyfill);
      d.paginatorApiFound=!!(window.Paged&&window.Paged.Previewer);
      d.sourceDocumentHeight=Math.max(document.documentElement.scrollHeight||0,document.body.scrollHeight||0);
      if(!d.paginatorApiFound){fail('Paged.Previewer API was not found');return;}
      const previewer=new window.Paged.Previewer();
      try{await previewer.preview(document.body, [], document.body);d.previewResolved=true;}catch(e){d.previewRejected=true;fail(e&&e.message?e.message:String(e));return;}
      await waitAssets();
      const pages=Array.from(document.querySelectorAll('.pagedjs_page')).filter(el=>el.getBoundingClientRect().width>0&&el.getBoundingClientRect().height>0);
      d.resultingPageCount=pages.length;
      const first=pages[0]?pages[0].getBoundingClientRect():null;
      d.firstPageDimensions=first?{width:first.width,height:first.height}:null;
      if(!pages.length){fail('Paged.js did not create page elements');return;}
      if(!first||first.width<760||first.width>830||first.height<1080||first.height>1165){fail('First page is not A4-sized');return;}
      if(d.sourceDocumentHeight>1300&&pages.length<2){fail('Long quotation produced only one page');return;}
      window.__quotationPdfReady=true;
      document.documentElement.setAttribute('data-quotation-pdf-ready','true');
    }catch(e){fail(e&&e.message?e.message:String(e));}
  };
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run,{once:true});}else{run();}
})();
</script>
HTML;
    $extra = '<style>@page{size:A4;margin:0}html,body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.admin-toolbar,.sticky-toolbar,.bulk-print-toolbar{display:none!important}.pagedjs_page{background:#fff;box-sizing:border-box}.quote-card,.customer-card,.solar-plan-hero,.annexure,.pricing-table,.tax-table,tr{break-inside:avoid;page-break-inside:avoid}thead{display:table-header-group}</style>' . $paged;
    return str_ireplace('</head>', $extra . '</head>', $html);
}

function quotation_browser_export_asset_status(?string $dir = null): array
{
    $dir = $dir ?: dirname(__DIR__) . '/assets/vendor/browser-export';
    $manifestPath = $dir . '/integrity-manifest.json';
    $fail = static fn(string $message): array => ['ok'=>false,'message'=>$message,'assets'=>[]];
    if (!is_file($manifestPath) || !is_readable($manifestPath)) { return $fail('Browser export assets are missing from this deployment'); }
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !is_array($manifest['assets'] ?? null)) { return $fail('Browser export assets are missing from this deployment'); }
    $assets = [];
    foreach ($manifest['assets'] as $asset) {
        $file = basename((string)($asset['file'] ?? ''));
        $path = $dir . '/' . $file;
        $min = (int)($asset['min_bytes'] ?? 10000);
        if ($file === '' || !is_file($path) || !is_readable($path) || filesize($path) < $min) { return $fail('Browser export assets are missing from this deployment'); }
        $bytes = filesize($path);
        $sha = hash_file('sha256', $path);
        if ($bytes !== (int)($asset['bytes'] ?? -1) || !hash_equals(strtolower((string)($asset['sha256'] ?? '')), $sha)) { return $fail('Browser export assets are missing from this deployment'); }
        $assets[] = ['file'=>$file,'bytes'=>$bytes,'sha256'=>$sha,'version'=>(string)($asset['version'] ?? '')];
    }
    return ['ok'=>true,'message'=>'Browser PDF/ZIP exporter ready','assets'=>$assets];
}

function quotation_browser_managed_install_available(?array $manifest = null): bool
{
    $manifest = $manifest ?: quotation_browser_managed_manifest();
    foreach ((array)($manifest['packages'] ?? []) as $pkg) {
        $sha = strtolower((string)($pkg['sha256'] ?? ''));
        if ($sha === '' || $sha === str_repeat('0', 64) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return false;
        }
    }
    return !empty($manifest['packages']);
}

function quotation_bulk_temp_file(string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dentweb-quote-' . bin2hex(random_bytes(12));
    return $base . $suffix;
}

function quotation_bulk_delete_files(array $paths): void
{
    foreach ($paths as $path) { if (is_string($path) && is_file($path)) { @unlink($path); } }
}

function quotation_browser_managed_detect_platform(): array
{
    $os = PHP_OS_FAMILY === 'Linux' ? 'linux' : strtolower(PHP_OS_FAMILY);
    $machine = strtolower(php_uname('m'));
    $arch = in_array($machine, ['x86_64','amd64'], true) ? 'x86_64' : ($machine === 'aarch64' || $machine === 'arm64' ? 'arm64' : $machine);
    return ['platform'=>$os,'architecture'=>$arch];
}

function quotation_browser_managed_manifest(?string $path = null): array
{
    $manifest = require ($path ?: __DIR__ . '/quotation_browser_manifest.php');
    return is_array($manifest) ? $manifest : ['allow_hosts'=>[], 'packages'=>[]];
}

function quotation_browser_managed_package(?array $manifest = null): array
{
    $manifest = $manifest ?: quotation_browser_managed_manifest();
    $det = quotation_browser_managed_detect_platform();
    foreach ((array)($manifest['packages'] ?? []) as $pkg) {
        if (($pkg['platform'] ?? '') === $det['platform'] && ($pkg['architecture'] ?? '') === $det['architecture']) { return $pkg; }
    }
    throw new QuotationBrowserPdfException('This platform is not supported by the managed browser manifest.', 'managed_browser_install_failure');
}

function quotation_browser_managed_install(?array $manifest = null, ?string $fixtureArchive = null): array
{
    $manifest = $manifest ?: quotation_browser_managed_manifest();
    if (!quotation_browser_managed_install_available($manifest)) {
        throw new QuotationBrowserPdfException('Managed browser installation is disabled until a real pinned checksum is committed.', 'managed_browser_install_failure');
    }
    $pkg = quotation_browser_managed_package($manifest);
    $url = (string)($pkg['url'] ?? ''); $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || !in_array($host, (array)($manifest['allow_hosts'] ?? []), true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new QuotationBrowserPdfException('The managed browser package host is not approved.', 'managed_browser_install_failure');
    }
    $root = quotation_browser_pdf_managed_browser_dir(); @mkdir($root, 0700, true);
    $lockPath = $root . DIRECTORY_SEPARATOR . 'install.lock'; $lock = fopen($lockPath, 'c');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) { throw new QuotationBrowserPdfException('Another PDF engine installation is already running.', 'managed_browser_install_failure'); }
    $tmp = quotation_browser_pdf_create_private_temp_dir('dentweb-browser-install-');
    $download = $tmp . DIRECTORY_SEPARATOR . 'browser.zip'; $stage = $tmp . DIRECTORY_SEPARATOR . 'stage'; @mkdir($stage, 0700, true);
    try {
        if ($fixtureArchive !== null) { copy($fixtureArchive, $download); }
        else {
            $ctx = stream_context_create(['http'=>['timeout'=>30,'follow_location'=>0]]);
            $in = @fopen($url, 'rb', false, $ctx); if (!is_resource($in)) { throw new RuntimeException('download failed'); }
            $out = fopen($download, 'wb'); $max = (int)($pkg['max_archive_bytes'] ?? 0); $bytes = 0;
            while (!feof($in)) { $chunk = fread($in, 1048576); $bytes += strlen($chunk); if ($max > 0 && $bytes > $max) { throw new RuntimeException('archive too large'); } fwrite($out, $chunk); }
            fclose($in); fclose($out);
        }
        if (filesize($download) > (int)($pkg['max_archive_bytes'] ?? PHP_INT_MAX)) { throw new RuntimeException('archive too large'); }
        if (!hash_equals(strtolower((string)$pkg['sha256']), hash_file('sha256', $download))) { throw new RuntimeException('checksum mismatch'); }
        $zip = new ZipArchive(); if ($zip->open($download) !== true) { throw new RuntimeException('archive open failed'); }
        $total = 0;
        for ($i=0; $i<$zip->numFiles; $i++) { $st = $zip->statIndex($i); $name = (string)($st['name'] ?? ''); if (!quotation_zip_entry_name_is_safe($name)) { $zip->close(); throw new RuntimeException('archive traversal rejected'); } $total += (int)($st['size'] ?? 0); }
        if ($total > (int)($pkg['max_extracted_bytes'] ?? PHP_INT_MAX)) { $zip->close(); throw new RuntimeException('extraction too large'); }
        if (!$zip->extractTo($stage)) { $zip->close(); throw new RuntimeException('extract failed'); } $zip->close();
        $exe = $stage . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$pkg['executable']); @chmod($exe, 0700);
        quotation_browser_pdf_validate_executable($exe, 'Managed browser');
        $testHtml = $tmp . DIRECTORY_SEPARATOR . 'test.html'; $testPdf = $tmp . DIRECTORY_SEPARATOR . 'test.pdf'; file_put_contents($testHtml, '<!doctype html><meta charset="utf-8"><h1>PDF test</h1>');
        $old = getenv('QUOTATION_CHROMIUM_PATH'); putenv('QUOTATION_CHROMIUM_PATH=' . $exe); quotation_browser_pdf_discover(null, true); quotation_browser_pdf_render_html_file($testHtml, $testPdf, $tmp); if ($old === false) { putenv('QUOTATION_CHROMIUM_PATH'); } else { putenv('QUOTATION_CHROMIUM_PATH=' . $old); } quotation_browser_pdf_discover(null, true);
        $new = $root . DIRECTORY_SEPARATOR . 'managed-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$pkg['version']); $prev = $root . DIRECTORY_SEPARATOR . 'previous';
        if (is_dir($new)) { quotation_browser_pdf_remove_tree($new); }
        rename($stage, $new);
        foreach (quotation_browser_pdf_candidate_names() as $name) { @unlink($root . DIRECTORY_SEPARATOR . $name); @symlink($new . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$pkg['executable']), $root . DIRECTORY_SEPARATOR . $name); }
        return ['ok'=>true,'version'=>(string)$pkg['version'],'platform'=>(string)$pkg['platform'],'architecture'=>(string)$pkg['architecture']];
    } catch (Throwable $e) { throw new QuotationBrowserPdfException('Managed browser installation failed validation.', 'managed_browser_install_failure'); }
    finally { quotation_browser_pdf_remove_tree($tmp); if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
}
