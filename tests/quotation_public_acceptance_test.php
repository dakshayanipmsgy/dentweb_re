<?php
declare(strict_types=1);

$public = file_get_contents(__DIR__ . '/../quotation-public.php');
$renderer = file_get_contents(__DIR__ . '/../includes/quotation_view_renderer.php');
$flow = file_get_contents(__DIR__ . '/../customer-document-acceptance.php');
$helpers = file_get_contents(__DIR__ . '/../admin/includes/documents_helpers.php');
$admin = file_get_contents(__DIR__ . '/../admin-quotations.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "PASS: {$message}\n");
};
$assert(!str_contains($public, 'Accept Quotation</a></p>'), 'public page does not append acceptance after quotation_render');
$assert(str_contains($renderer, 'Ready to proceed?') && str_contains($renderer, 'Accept Quotation'), 'renderer contains prominent acceptance card');
$assert(str_contains($renderer, '$normalizedStatus === \'approved\'') && str_contains($renderer, '$isCurrentVersion') && str_contains($renderer, '$isUnavailableVersion') && str_contains($renderer, '$publicShareValid') && str_contains($renderer, '!$hasCustomerAcceptance'), 'renderer checks all acceptance eligibility conditions');
$assert(str_contains($renderer, 'Quotation accepted') && str_contains($renderer, 'Acceptance reference') && str_contains($renderer, 'Open WhatsApp to send confirmation'), 'renderer contains accepted evidence state');
$assert(str_contains($renderer, 'acceptance-btn screen-only'), 'interactive acceptance controls are hidden in print');
$assert(str_contains($flow, "array_key_exists('is_current_version'") && str_contains($flow, "['archived','cancelled','superseded']"), 'shared acceptance flow enforces current active quotation');
$assert(str_contains($helpers, 'click Accept Quotation') && str_contains($admin, 'click Accept Quotation'), 'default WhatsApp templates request explicit acceptance');
