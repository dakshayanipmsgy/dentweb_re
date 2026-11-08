<?php
declare(strict_types=1);

/** Presentation only: keep stored values and all billing calculations unchanged. */
function customer_workspace_profile_groups(array $customer): array
{
    $groups = [
        'Account & contact' => ['name' => 'Customer name', 'mobile' => 'Registered mobile', 'customer_type' => 'Customer type'],
        'Installation address' => ['address' => 'Address', 'city' => 'City', 'district' => 'District', 'state' => 'State', 'pin_code' => 'PIN code'],
        'Electricity & application' => ['jbvnl_account_number' => 'Electricity account number', 'meter_number' => 'Meter number', 'meter_serial_number' => 'Meter serial number', 'application_id' => 'Application ID', 'circle_name' => 'Circle', 'division_name' => 'Division', 'sub_division_name' => 'Sub-division'],
        'Solar installation' => ['installed_pv_module_capacity_kwp' => 'Installed capacity (kWp)', 'sanction_load_kwp' => 'Sanctioned load (kW)', 'solar_plant_installation_date' => 'Installation date'],
    ];
    $result = [];
    foreach ($groups as $title => $fields) {
        $rows = [];
        foreach ($fields as $key => $label) {
            $value = is_scalar($customer[$key] ?? null) ? trim((string) $customer[$key]) : '';
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value, 'display' => $value !== '' ? $value : 'Not recorded yet'];
        }
        $result[] = ['title' => $title, 'rows' => $rows];
    }
    return $result;
}

function customer_workspace_asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return $path . '?v=' . rawurlencode($version);
}


/** First non-empty scalar, preserving identifiers such as "0". */
function customer_workspace_first_text(array $values): string
{
    foreach ($values as $value) {
        if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
    }
    return '';
}

/** Presentation of already-authorized projects; never infer installed capacity or commissioning. */
function customer_workspace_project_references(array $projects, array $standaloneInvoices = []): array
{
    $groups = [];
    foreach ($projects as $project) {
        $quote = is_array($project['quote'] ?? null) ? $project['quote'] : [];
        $rows = [];
        $quoteNo = customer_workspace_first_text([$quote['quote_no'] ?? '', $quote['id'] ?? '']);
        if ($quoteNo !== '') $rows[] = ['label' => 'Accepted quotation', 'value' => $quoteNo];
        $systemType = customer_workspace_first_text([$quote['system_type'] ?? '']);
        if ($systemType !== '') $rows[] = ['label' => 'System type in quotation', 'value' => $systemType];
        foreach ((array) ($project['invoices'] ?? []) as $invoice) {
            if (!is_array($invoice) || !documents_invoice_is_finalized($invoice)) continue;
            $rows = array_merge($rows, customer_workspace_invoice_reference_rows($invoice, $quote));
        }
        if ($rows !== []) $groups[] = ['title' => 'Project document references', 'rows' => $rows];
    }
    foreach ($standaloneInvoices as $invoice) {
        if (!is_array($invoice) || !documents_invoice_is_finalized($invoice)) continue;
        $rows = customer_workspace_invoice_reference_rows($invoice);
        if ($rows !== []) $groups[] = ['title' => 'Direct invoice references', 'rows' => $rows];
    }
    return $groups;
}

function customer_workspace_invoice_reference_rows(array $invoice, array $quote = []): array
{
    $number = customer_workspace_first_text([$invoice['invoice_no'] ?? '', $invoice['id'] ?? '']);
    $snapshot = is_array($invoice['quotation_snapshot'] ?? null) ? $invoice['quotation_snapshot'] : [];
    $chart = is_array($invoice['rate_chart_snapshot'] ?? null) ? $invoice['rate_chart_snapshot'] : [];
    $positive = static function ($value): float {
        return is_scalar($value) && is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : 0;
    };
    // Same DCR/non-DCR precedence as invoice-view.php.
    $main = $positive($invoice['main_solar_kwp'] ?? $chart['dcr_size_kwp'] ?? $snapshot['main_solar_kwp'] ?? $quote['main_solar_kwp'] ?? '');
    $extra = $positive($invoice['complimentary_non_dcr_kwp'] ?? $chart['non_dcr_size_kwp'] ?? $snapshot['complimentary_non_dcr_kwp'] ?? $quote['complimentary_non_dcr_kwp'] ?? '');
    $capacity = $main + $extra;
    if ($capacity <= 0) $capacity = $positive($invoice['capacity_kwp'] ?? $snapshot['capacity_kwp'] ?? $quote['capacity_kwp'] ?? '');
    $rows = [['label' => 'Finalized invoice', 'value' => $number]];
    if ($capacity > 0) $rows[] = ['label' => 'System capacity shown on ' . $number, 'value' => number_format($capacity, 2, '.', '') . ' kWp'];
    return $rows;
}

function customer_workspace_references_html(array $groups): string
{
    if ($groups === []) return '';
    $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<section class="current-project-references"><h2>Current project document references</h2><p class="project-reference-note">From your current accepted quotations and finalized invoices. Document capacity does not confirm commissioning or replace the installation record.</p>';
    foreach ($groups as $group) {
        $html .= '<ul class="reference-list">';
        foreach ($group['rows'] as $row) $html .= '<li><strong>' . $esc($row['label']) . ':</strong> ' . $esc($row['value']) . '</li>';
        $html .= '</ul>';
    }
    return $html . '</section>';
}

/** Mark missing saved-pack values without modifying any recorded value or file. */
function customer_workspace_handover_missing_details(string $html): string
{
    if (!class_exists('DOMDocument')) return $html;
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body><div id="saved-handover-content">' . $html . '</div></body></html>', LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $root = $dom->getElementById('saved-handover-content');
        if (!$root) return $html;
        foreach ($xpath->query('.//tr', $root) as $row) {
            $cells = $xpath->query('./th|./td', $row);
            if ($cells->length !== 2 || trim($cells->item(0)->textContent) === '') continue;
            $value = $cells->item(1);
            $text = trim(str_replace("\xc2\xa0", ' ', $value->textContent));
            // A bare kWp suffix also means the numeric capacity was never saved.
            if (($text === '' || strtolower($text) === 'kwp') && $value->getElementsByTagName('img')->length === 0 && $value->getElementsByTagName('input')->length === 0) {
                $value->textContent = 'Not recorded in saved handover';
                $value->setAttribute('class', trim($value->getAttribute('class') . ' handover-missing'));
            }
        }
        $nodes = [];
        foreach ($xpath->query('.//text()[not(ancestor::script) and not(ancestor::style)]', $root) as $node) $nodes[] = $node;
        foreach ($nodes as $node) {
            $node->nodeValue = preg_replace('/(?:Warranty Period|Performance Warranty):\s*_+\s*years\.?/i', 'Warranty period: Not recorded in saved handover; refer to manufacturer warranty documents.', $node->nodeValue) ?? $node->nodeValue;
        }
        $out = '';
        foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
        return $out;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}
