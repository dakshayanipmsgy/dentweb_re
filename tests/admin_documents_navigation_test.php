<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/commercial_lifecycle.php';

function navigation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$destinations = [
    'accepted_customers' => 'admin-documents.php?tab=accepted_customers',
    'completed_customers' => 'admin-documents.php?tab=completed_customers',
];

foreach (array_keys(documents_workspace_tabs()) as $activeTab) {
    $html = render_documents_workspace_tabs($activeTab);
    foreach ($destinations as $tab => $destination) {
        navigation_assert(substr_count($html, 'href="' . $destination . '"') === 1, "{$tab} has one clean destination on {$activeTab}");
        $activePattern = '/class="tab active" href="' . preg_quote($destination, '/') . '" aria-current="page"/';
        navigation_assert((bool) preg_match($activePattern, $html) === ($activeTab === $tab), "{$tab} active state is correct on {$activeTab}");
    }
}

$detailHtml = render_documents_workspace_tabs('accepted_customers');
navigation_assert(!str_contains($detailHtml, 'view='), 'shared navigation does not retain a detail view');
navigation_assert(!str_contains($detailHtml, 'status='), 'shared navigation does not retain status state');
navigation_assert(!str_contains($detailHtml, 'filter='), 'shared navigation does not retain filters');

$documentsPage = file_get_contents(__DIR__ . '/../admin-documents.php');
navigation_assert(is_string($documentsPage), 'Documents & Billing page exists');
navigation_assert(str_contains($documentsPage, 'render_documents_workspace_tabs($activeTab)'), 'Documents & Billing uses the shared tab renderer');

echo "admin_documents_navigation_test passed\n";
