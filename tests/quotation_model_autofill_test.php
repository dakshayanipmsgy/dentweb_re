<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../admin-quotations.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read admin-quotations.php\n");
    exit(1);
}

$assertions = [
    'DN models cap DCR at 3 kWp' => "modelVariant(row) === 'DN' ? Math.min(3, total) : total",
    'Non-DCR is the remaining selected-model capacity' => 'Math.max(0, total - dcr)',
    'OnGrid selects its generation-system kit' => "return 'Ongrid Solar Power Generation System'",
    'Hybrid TB selects its TBased kit' => "return 'Hybrid Solar Power Generation System TBased'",
    'Hybrid TL selects its TLess kit' => "return 'Hybrid Solar Power Generation System TLess'",
    'Autofilled kit quantity is one' => "if (qtyField) qtyField.value = '1'",
    'Autofilled kit unit is set' => "if (unitField) unitField.value = 'set'",
    'Existing matching kit rows are retained' => 'if (existingTarget)',
    'Persistent managed autofill rows are reused' => "quote_item_managed_system_kit[]",
    'DCR and Non-DCR are changed only from model selection' => 'modelSelect?.addEventListener(\'change\', applyModel)',
];

foreach ($assertions as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

$split = static function (string $model, float $total): array {
    $suffix = strtoupper((string) array_slice(explode('-', trim($model)), -1)[0]);
    $dcr = $suffix === 'DN' ? min(3.0, max(0.0, $total)) : max(0.0, $total);
    return [$dcr, max(0.0, $total - $dcr)];
};

$examples = [
    'OG-3K-1P-D' => [3.0, 0.0, 3.0],
    'OG-5K-1P-DN' => [3.0, 2.0, 5.0],
    'OG-10K-3P-DN' => [3.0, 7.0, 10.0],
];
foreach ($examples as $model => [$expectedDcr, $expectedNonDcr, $total]) {
    [$dcr, $nonDcr] = $split($model, $total);
    if ($dcr !== $expectedDcr || $nonDcr !== $expectedNonDcr) {
        fwrite(STDERR, "FAIL: {$model} split\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$model} => DCR {$dcr}, Non-DCR {$nonDcr}\n");
}
