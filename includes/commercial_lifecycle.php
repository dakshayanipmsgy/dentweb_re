<?php

declare(strict_types=1);

if (!function_exists('commercial_lifecycle_stages')) {
    /**
     * @return array<string, array{label:string, href:string}>
     */
    function commercial_lifecycle_stages(): array
    {
        return [
            'quotation' => ['label' => 'Quotation', 'href' => 'admin-quotations.php'],
            'agreement' => ['label' => 'Agreement', 'href' => 'admin-agreements.php'],
            'dispatch_advice' => ['label' => 'Dispatch Advice', 'href' => 'admin-dispatch-advices.php'],
            'challan' => ['label' => 'Challan', 'href' => 'admin-challans.php'],
            'invoice' => ['label' => 'Invoice', 'href' => 'admin-invoices.php'],
        ];
    }
}

if (!function_exists('render_commercial_lifecycle')) {
    function render_commercial_lifecycle(string $activeStage = ''): string
    {
        $html = '<nav class="commercial-flow-strip" aria-label="Commercial lifecycle">';
        $index = 0;
        foreach (commercial_lifecycle_stages() as $key => $stage) {
            if ($index > 0) {
                $html .= '<span aria-hidden="true">→</span>';
            }

            $classes = $key === $activeStage ? ' class="active" aria-current="page"' : '';
            $html .= '<a' . $classes . ' href="' . htmlspecialchars($stage['href'], ENT_QUOTES) . '">' . htmlspecialchars($stage['label'], ENT_QUOTES) . '</a>';
            $index++;
        }
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('documents_workspace_tabs')) {
    /**
     * @return array<string, array{label:string, href:string, fetch:bool}>
     */
    function documents_workspace_tabs(): array
    {
        return [
            'company' => ['label' => 'Company Profile & Branding', 'href' => 'admin-documents.php?tab=company', 'fetch' => true],
            'numbering' => ['label' => 'Numbering Rules', 'href' => 'admin-documents.php?tab=numbering', 'fetch' => true],
            'templates' => ['label' => 'Template Sets', 'href' => 'admin-documents.php?tab=templates', 'fetch' => true],
            'accepted_customers' => ['label' => 'Accepted Customers', 'href' => 'admin-documents.php?tab=accepted_customers', 'fetch' => true],
            'completed_customers' => ['label' => 'Completed Customers', 'href' => 'admin-documents.php?tab=completed_customers', 'fetch' => true],
            'items' => ['label' => 'Items', 'href' => 'admin-documents.php?tab=items', 'fetch' => false],
            'archived' => ['label' => 'Archived', 'href' => 'admin-documents.php?tab=archived', 'fetch' => true],
        ];
    }
}

if (!function_exists('render_documents_workspace_tabs')) {
    function render_documents_workspace_tabs(string $activeTab): string
    {
        $html = '<nav class="tabs admin-documents__tabs workspace-tabs" data-workspace-tabs="fetch" aria-label="Document Center tabs">';
        foreach (documents_workspace_tabs() as $key => $tab) {
            $attributes = ' data-workspace-tab';
            if (!$tab['fetch']) {
                $attributes .= ' data-workspace-mode="reload"';
            }
            $attributes .= ' class="tab' . ($key === $activeTab ? ' active' : '') . '"';
            $attributes .= ' href="' . htmlspecialchars($tab['href'], ENT_QUOTES) . '"';
            if ($key === $activeTab) {
                $attributes .= ' aria-current="page"';
            }
            $html .= '<a' . $attributes . '>' . htmlspecialchars($tab['label'], ENT_QUOTES) . '</a>';
        }
        $html .= '<a class="tab" href="admin-templates.php">Template Blocks &amp; Media</a></nav>';

        return $html;
    }
}
