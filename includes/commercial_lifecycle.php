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
            'agreement' => ['label' => 'Vendor Consumer Agreement', 'href' => 'admin-agreements.php'],
            'dispatch_advice' => ['label' => 'Dispatch Advice', 'href' => 'admin-dispatch-advices.php'],
            'challan' => ['label' => 'Delivery Challan', 'href' => 'admin-challans.php'],
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
