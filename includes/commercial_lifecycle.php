<?php

declare(strict_types=1);

$commercialLifecycleScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
if ($commercialLifecycleScript === 'admin-invoices.php') {
    require_once __DIR__ . '/standalone_invoice_quote_parity.php';
}

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

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if ($script === 'admin-invoices.php' && $activeStage === 'invoice') {
            // The standalone Items Master UI hides irrelevant selects with disabled state.
            // Re-enable them immediately before submit so every [] array keeps the same row index.
            $html .= <<<'HTML'
<script>
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.querySelector('.si-builder-table')) return;
    form.querySelectorAll('.si-builder-table select:disabled').forEach(function (select) {
        select.disabled = false;
    });
}, true);
</script>
HTML;
        }

        return $html;
    }
}
