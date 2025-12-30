<?php
declare(strict_types=1);

function handover_templates_path(): string
{
    return __DIR__ . '/../data/handover_templates.json';
}

function handover_templates_directory(): string
{
    return dirname(handover_templates_path());
}

function handover_template_defaults(): array
{
    return [
        'handover_style_css' => '',
        'welcome_note_template' => '',
        'user_manual_template' => '',
        'system_details_template' => '',
        'operation_maintenance_template' => '',
        'warranty_details_template' => '',
        'consumer_engagement_template' => '',
        'education_best_practices_template' => '',
        'final_notes_template' => '',
        'handover_acknowledgment_template' => '',
    ];
}

function load_handover_templates(): array
{
    $defaults = handover_template_defaults();
    $path = handover_templates_path();

    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $defaults;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Failed to decode handover templates: ' . $exception->getMessage());
        return $defaults;
    }

    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

function save_handover_templates(array $templates): void
{
    $path = handover_templates_path();
    $dir = handover_templates_directory();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create template storage directory.');
    }

    $payload = array_merge(handover_template_defaults(), $templates);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode templates.');
    }

    $result = file_put_contents($path, $json, LOCK_EX);
    if ($result === false) {
        throw new RuntimeException('Unable to save templates.');
    }
}

function render_handover_template(string $template, array $customer): string
{
    $placeholders = [
        '{{consumer_name}}' => $customer['name'] ?? ($customer['full_name'] ?? ''),
        '{{address}}' => $customer['address'] ?? ($customer['address_line'] ?? ''),
        '{{consumer_no}}' => $customer['jbvnl_account_number'] ?? ($customer['consumer_no'] ?? ''),
        '{{mobile}}' => $customer['mobile'] ?? ($customer['phone'] ?? ''),
        '{{invoice_no}}' => $customer['invoice_no'] ?? '',
        '{{premises_type}}' => $customer['premises_type'] ?? '',
        '{{scheme_type}}' => $customer['customer_type'] ?? ($customer['scheme_type'] ?? ''),
        '{{system_type}}' => $customer['system_type'] ?? '',
        '{{system_capacity_kwp}}' => $customer['installed_pv_module_capacity_kwp'] ?? ($customer['system_kwp'] ?? ''),
        '{{installation_date}}' => $customer['installation_date'] ?? ($customer['solar_plant_installation_date'] ?? ''),
        '{{jbvnl_account_number}}' => $customer['jbvnl_account_number'] ?? '',
        '{{application_id}}' => $customer['application_id'] ?? '',
        '{{city}}' => $customer['city'] ?? '',
        '{{district}}' => $customer['district'] ?? '',
        '{{pin_code}}' => $customer['pin_code'] ?? '',
        '{{state}}' => $customer['state'] ?? '',
        '{{circle_name}}' => $customer['circle_name'] ?? '',
        '{{division_name}}' => $customer['division_name'] ?? '',
        '{{sub_division_name}}' => $customer['sub_division_name'] ?? '',
        '{{solar_plant_installation_date}}' => $customer['solar_plant_installation_date'] ?? '',
    ];

    return strtr($template, $placeholders);
}

function handover_default_css(): string
{
    return <<<CSS
body.handover-doc {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    margin: 0;
    padding: 0;
    background: #f7f9fc;
    color: #111827;
}

.handover-shell {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px;
}

.handover-letterhead {
    display: flex;
    gap: 16px;
    align-items: center;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.06);
}

.handover-letterhead img {
    width: 90px;
    height: auto;
}

.handover-letterhead h1 {
    margin: 0;
    font-size: 1.35rem;
    color: #0f172a;
}

.handover-letterhead p {
    margin: 4px 0;
    color: #334155;
    font-size: 0.95rem;
    line-height: 1.4;
}

.handover-customer-heading {
    margin: 24px 0 12px;
    font-size: 1.2rem;
    font-weight: 700;
    color: #111827;
}

.handover-section {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
}

.handover-section h2 {
    margin-top: 0;
    color: #0f172a;
    font-size: 1.05rem;
}

.handover-section hr {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 12px 0;
}
CSS;
}

function handover_default_overrides(): array
{
    return [
        'welcome_note' => '',
        'user_manual' => '',
        'system_details' => '',
        'operation_maintenance' => '',
        'warranty_details' => '',
        'consumer_engagement' => '',
        'education_best_practices' => '',
        'final_notes' => '',
        'handover_acknowledgment' => '',
    ];
}

function handover_select_template(array $templates, array $overrides, string $key, string $templateKey): string
{
    $overrideValue = trim((string) ($overrides[$key] ?? ''));
    if ($overrideValue !== '') {
        return $overrideValue;
    }

    return (string) ($templates[$templateKey] ?? '');
}

function handover_generate_sections(array $templates, array $customer, array $overrides = []): array
{
    $overrides = array_merge(handover_default_overrides(), $overrides);

    return [
        'welcome_note' => render_handover_template(handover_select_template($templates, $overrides, 'welcome_note', 'welcome_note_template'), $customer),
        'user_manual' => render_handover_template(handover_select_template($templates, $overrides, 'user_manual', 'user_manual_template'), $customer),
        'system_details' => render_handover_template(handover_select_template($templates, $overrides, 'system_details', 'system_details_template'), $customer),
        'operation_maintenance' => render_handover_template(handover_select_template($templates, $overrides, 'operation_maintenance', 'operation_maintenance_template'), $customer),
        'warranty_details' => render_handover_template(handover_select_template($templates, $overrides, 'warranty_details', 'warranty_details_template'), $customer),
        'consumer_engagement' => render_handover_template(handover_select_template($templates, $overrides, 'consumer_engagement', 'consumer_engagement_template'), $customer),
        'education_best_practices' => render_handover_template(handover_select_template($templates, $overrides, 'education_best_practices', 'education_best_practices_template'), $customer),
        'final_notes' => render_handover_template(handover_select_template($templates, $overrides, 'final_notes', 'final_notes_template'), $customer),
        'handover_acknowledgment' => render_handover_template(handover_select_template($templates, $overrides, 'handover_acknowledgment', 'handover_acknowledgment_template'), $customer),
    ];
}

function handover_render_sections_html(array $customer, array $sections): string
{
    $customerName = htmlspecialchars((string) ($customer['name'] ?? $customer['full_name'] ?? 'Valued Customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $sectionBlocks = [
        ['title' => '1. Welcome Note', 'key' => 'welcome_note'],
        ['title' => '2. User Manual', 'key' => 'user_manual'],
        ['title' => '3. System Details', 'key' => 'system_details'],
        ['title' => '4. Operation &amp; Maintenance Guidelines', 'key' => 'operation_maintenance'],
        ['title' => '5. Warranty Details', 'key' => 'warranty_details'],
        ['title' => '6. Consumer Engagement / Additional Benefits', 'key' => 'consumer_engagement'],
        ['title' => '7. Consumer Education &amp; Best Practices', 'key' => 'education_best_practices'],
        ['title' => '8. Final Notes &amp; Customer Commitment', 'key' => 'final_notes'],
        ['title' => '9. Handover Acknowledgment', 'key' => 'handover_acknowledgment'],
    ];

    ob_start();
    ?>
    <div class="handover-shell">
        <div class="handover-letterhead">
            <img src="/images/Logopngsmallest.png" alt="Dakshayani Enterprises logo" />
            <div>
                <h1>Dakshayani Enterprises</h1>
                <p>Maa Tara, Kilburn Colony, Hinoo, Ranchi, Jharkhand – 834002</p>
                <p>GST: 20AMCPV2990G1Z2 · UDYAM: UDYAM-JH-20-0005867</p>
                <p>Phone: +91 7070278178, +91 7992359470</p>
                <p>Email: <a href="mailto:connect@dakshayani.co.in">connect@dakshayani.co.in</a>, <a href="mailto:d.entranchi@gmail.com">d.entranchi@gmail.com</a></p>
                <p>Website: <a href="http://www.dakshayani.co.in" target="_blank" rel="noreferrer">www.dakshayani.co.in</a></p>
            </div>
        </div>

        <div class="handover-customer-heading">Handover Pack for <?= $customerName ?></div>

        <?php foreach ($sectionBlocks as $block): ?>
            <div class="handover-section">
                <h2><?= $block['title'] ?></h2>
                <hr />
                <div><?= $sections[$block['key']] ?? '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function handover_build_full_html(array $customer, string $finalContentHtml, ?string $styleOverride = null): string
{
    $templates = load_handover_templates();
    $customCss = $styleOverride ?? (string) ($templates['handover_style_css'] ?? '');
    $css = trim(handover_default_css() . "\n" . $customCss);
    $customerName = htmlspecialchars((string) ($customer['name'] ?? $customer['full_name'] ?? 'Valued Customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = 'Solar Handover - ' . $customerName;

    $styleBlock = "<style>\n" . $css . "\n</style>";

    return '<!DOCTYPE html>'
        . '<html lang="en">'
        . '<head>'
        . '<meta charset="UTF-8" />'
        . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
        . $styleBlock
        . '</head>'
        . '<body class="handover-doc">'
        . $finalContentHtml
        . '</body>'
        . '</html>';
}

function handover_wrap_document(array $customer, string $bodyHtml, ?string $styleOverride = null): string
{
    return handover_build_full_html($customer, $bodyHtml, $styleOverride);
}

function handover_build_html(array $customer, array $sections, ?string $styleOverride = null): string
{
    $content = handover_render_sections_html($customer, $sections);

    return handover_build_full_html($customer, $content, $styleOverride);
}

function handover_storage_directory(): string
{
    return __DIR__ . '/../handovers';
}

function handover_normalize_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    if ($digits === null) {
        return '';
    }

    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

function handover_extract_body_content(string $html): string
{
    if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }

    return trim($html);
}

function handover_resolve_image_path(string $src): ?string
{
    $trimmed = trim($src);
    if ($trimmed === '') {
        return null;
    }

    if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'file://')) {
        return $trimmed;
    }

    $cleanPath = ltrim($trimmed, '/');
    $localPath = __DIR__ . '/../' . $cleanPath;

    if (is_file($localPath)) {
        return $localPath;
    }

    return null;
}

function handover_render_dom_node(SimplePdfDocument $pdf, DOMNode $node): void
{
    if ($node->nodeType === XML_TEXT_NODE) {
        $text = trim((string) $node->nodeValue);
        if ($text !== '') {
            $pdf->addParagraph($text, 12.0, false, 0.0, 6.0);
        }
        return;
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return;
    }

    /** @var DOMElement $element */
    $element = $node;
    $tag = strtolower($element->tagName);

    switch ($tag) {
        case 'h1':
            $pdf->addParagraph(trim($element->textContent ?? ''), 18.0, true, 4.0, 10.0);
            break;
        case 'h2':
            $pdf->addParagraph(trim($element->textContent ?? ''), 16.0, true, 4.0, 8.0);
            break;
        case 'h3':
            $pdf->addParagraph(trim($element->textContent ?? ''), 14.0, true, 4.0, 8.0);
            break;
        case 'p':
            $pdf->addParagraph(trim($element->textContent ?? ''), 12.0, false, 2.0, 8.0);
            break;
        case 'hr':
            $pdf->addHr(6.0, 10.0);
            break;
        case 'br':
            $pdf->addParagraph('', 12.0, false, 0.0, 4.0);
            break;
        case 'img':
            $src = $element->getAttribute('src');
            $resolved = handover_resolve_image_path($src);
            if ($resolved !== null) {
                $pdf->addImage($resolved, 180.0);
            }
            break;
        case 'ul':
        case 'ol':
            foreach ($element->getElementsByTagName('li') as $li) {
                $text = trim($li->textContent ?? '');
                if ($text !== '') {
                    $pdf->addParagraph('• ' . $text, 12.0, false, 0.0, 6.0);
                }
            }
            break;
        case 'div':
        case 'section':
        case 'article':
            $classes = explode(' ', (string) $element->getAttribute('class'));
            $classList = array_filter($classes, static function (string $value): bool {
                return trim($value) !== '';
            });
            if (in_array('handover-section', $classList, true)) {
                $pdf->addParagraph('', 12.0, false, 2.0, 4.0);
            }
            foreach ($element->childNodes as $child) {
                handover_render_dom_node($pdf, $child);
            }
            if (in_array('handover-section', $classList, true)) {
                $pdf->addHr(6.0, 6.0);
            }
            break;
        default:
            foreach ($element->childNodes as $child) {
                handover_render_dom_node($pdf, $child);
            }
            break;
    }
}

function handover_generate_pdf(string $html, string $outputPath): bool
{
    require_once __DIR__ . '/simple_pdf.php';

    $pdf = new SimplePdfDocument();

    $dom = new DOMDocument('1.0', 'UTF-8');
    $internalErrors = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_use_internal_errors($internalErrors);

    $body = $dom->getElementsByTagName('body')->item(0);
    $root = $body instanceof DOMElement ? $body : $dom->documentElement;

    if ($root !== null) {
        foreach ($root->childNodes as $child) {
            handover_render_dom_node($pdf, $child);
        }
    }

    $binary = $pdf->output();

    return file_put_contents($outputPath, $binary, LOCK_EX) !== false;
}
