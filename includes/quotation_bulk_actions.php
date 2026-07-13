<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/quotation_view_renderer.php';
require_once __DIR__ . '/quotation_browser_pdf.php';

const QUOTATION_BULK_EXPORT_LIMIT = 25;

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
    $browser = is_array($capabilities['browser'] ?? null) ? $capabilities['browser'] : [];
    if (!empty($capabilities['server_pdf_available'])) {
        return !empty($browser['configured']) ? 'PDF engine ready — configured Chromium' : 'PDF engine ready — ' . ((string) ($browser['name'] ?? 'Chrome')) . ' detected automatically';
    }
    return 'Browser Save as PDF fallback active';
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
    return ['capabilities' => $cap, 'summary' => !empty($cap['server_pdf_available']) && $testOk ? 'Everything is ready.' : 'Server PDF generation is unavailable, but Save as PDF will still work.', 'browser_message' => !empty($browser['available']) ? (((bool) ($browser['configured'] ?? false)) ? 'Chrome/Chromium is configured.' : 'Chrome was found automatically.') : 'Chrome/Chromium was not found.', 'test_ok' => $testOk, 'test_message' => $testMessage];
}

function quotation_bulk_render_pdf_file(array $quote, array $quoteDefaults, array $company, string $path): void
{
    $workDir = quotation_browser_pdf_create_private_temp_dir();
    try {
        $htmlPath = $workDir . DIRECTORY_SEPARATOR . 'quotation.html';
        $html = quotation_render_to_html($quote, $quoteDefaults, $company, false, '', 'admin', 'pdf-export');
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
      window.__quotationPdfReady=true;
      document.documentElement.setAttribute('data-quotation-pdf-ready','true');
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

function quotation_bulk_temp_file(string $suffix): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dentweb-quote-' . bin2hex(random_bytes(12));
    return $base . $suffix;
}

function quotation_bulk_delete_files(array $paths): void
{
    foreach ($paths as $path) { if (is_string($path) && is_file($path)) { @unlink($path); } }
}
