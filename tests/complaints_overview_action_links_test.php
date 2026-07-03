<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../complaints-overview.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read complaints overview.\n");
    exit(1);
}

$expectations = [
    'WhatsApp action has server fallback' => 'action=notify_whatsapp',
    'SMS action has server fallback' => 'action=notify_sms',
    'Close action has server fallback' => 'action=close',
    'WhatsApp JS hook remains available' => 'js-complaint-notify',
    'Close JS hook remains available' => 'js-complaint-close',
];

foreach ($expectations as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Complaint overview action link tests passed\n";
