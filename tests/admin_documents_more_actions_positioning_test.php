<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../admin-documents.php');
if ($source === false) {
    throw new RuntimeException('Unable to read admin-documents.php');
}

$assertions = 0;
$assertContains = static function (string $needle, string $message) use ($source, &$assertions): void {
    $assertions++;
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message);
    }
};

// The live menu is portalled to body and fixed, so table/card overflow and stacking
// contexts cannot clip it. Its position always derives from the clicked summary.
$assertContains("document.body.appendChild(flyout)", 'Row action menu must be portalled to the page body.');
$assertContains("summary.getBoundingClientRect()", 'Menu must be anchored to the exact More summary.');
$assertContains("more-actions__menu--portal { position:fixed", 'Portalled menu must use viewport-fixed positioning.');
$assertContains("const openUpward = spaceBelow < flyoutHeight", 'Menu must choose upward placement at the bottom edge.');
$assertContains("summaryRect.right - flyoutWidth", 'Menu must align and clamp from the clicked button edge.');
$assertContains("viewportWidth - flyoutWidth - viewportPad", 'Menu must remain inside the right viewport edge.');
$assertContains("max-height:calc(100vh - 24px)", 'Tall menus must remain inside the viewport.');

// Closing and accessibility behavior remains explicit even after the menu leaves
// its original details element in the DOM.
$assertContains("closeOtherMenus(menu)", 'Opening a row menu must close every other row menu.');
$assertContains("event.key === 'Escape'", 'Escape must close the active row menu.');
$assertContains("event.target.closest('a, button')", 'Action forms must be restored before their existing delegated handlers run.');
$assertContains("window.addEventListener('scroll', schedulePosition, true)", 'Nested and page scrolling must reposition the menu.');
$assertContains("summary.setAttribute('aria-expanded', 'true')", 'Opening must expose expanded state.');
$assertContains("summary.setAttribute('aria-expanded', 'false')", 'Closing must expose collapsed state.');

// Preserve the existing accepted-customer row actions and their server behavior.
foreach (['Edit quotation', 'Create/Open Agreement', 'Create/Open Dispatch Advice', 'Create/Open Challan', 'Create/Open Invoice', 'archive_accepted_customer'] as $action) {
    $assertContains($action, 'Existing row action missing: ' . $action);
}
$assertContains('action="<?= htmlspecialchars($documentActionEndpoint, ENT_QUOTES) ?>"', 'Document actions must retain their endpoint.');
$assertContains("name=\"return_tab\" value=\"accepted_customers\"", 'Document actions must retain accepted-customer return behavior.');

echo "admin_documents_more_actions_positioning_test passed ($assertions assertions)\n";
