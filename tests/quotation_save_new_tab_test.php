<?php
$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');
$employee = file_get_contents(__DIR__ . '/../employee-quotations.php');
$js = file_get_contents(__DIR__ . '/../assets/js/quotation-save-new-tab.js');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert($admin !== false && $employee !== false && $js !== false, 'quotation save sources are readable');
$assert(str_contains($admin, '$isQuotationSaveAjax') && str_contains($employee, '$isQuotationSaveAjax'), 'admin and employee detect quotation save AJAX requests');
$assert(substr_count($admin, 'data-quotation-save-form="admin"') === 1, 'admin editor has one AJAX quotation save form marker');
$assert(substr_count($employee, 'data-quotation-save-form="employee"') === 1, 'employee editor has one AJAX quotation save form marker');
$assert(str_contains($admin, '$respondQuotationSaveSuccess((string) $quote[\'id\']);'), 'admin save returns the resolved quotation id after persistence');
$assert(str_contains($employee, '$respondQuotationSaveSuccess((string) $quote[\'id\']);'), 'employee save returns the resolved quotation id after persistence');
$assert(str_contains($admin, 'quotation-view.php?id=') && str_contains($employee, 'quotation-view.php?id='), 'non-JS fallback still reaches quotation view after successful save');
$assert(str_contains($js, "window.open('', '_blank')"), 'placeholder tab opens synchronously from submit');
$assert(str_contains($js, "'X-Quotation-Save': '1'") && str_contains($js, 'new FormData(form)'), 'quotation form is submitted once via fetch with the save header');
$assert(str_contains($js, 'placeholder.location.href = payload.view_url'), 'placeholder tab navigates only after successful save response');
$assert(str_contains($js, 'closePlaceholder(placeholder)'), 'placeholder tab closes when save fails');
$assert(!str_contains($js, 'target="_blank"'), 'implementation does not rely on form target blank');

echo "PASS: quotation save new-tab behavior is wired for admin and employee editors\n";
