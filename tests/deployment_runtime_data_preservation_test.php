<?php
declare(strict_types=1);

function deployment_preservation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$deploymentPath = dirname(__DIR__) . '/.cpanel.yml';
$deployment = file_get_contents($deploymentPath);
deployment_preservation_assert(is_string($deployment) && $deployment !== '', 'cPanel deployment configuration is readable');

$requiredExclusions = [
    '.env',
    'storage/',
    'data/',
    'handovers/',
    'uploads/',
    'images/hero/',
    'images/documents/branding/',
    'images/documents/backgrounds/',
    'images/documents/watermarks/',
    'images/documents/diagrams/',
    'images/documents/uploads/',
];

foreach ($requiredExclusions as $path) {
    deployment_preservation_assert(
        str_contains($deployment, "--exclude='{$path}'"),
        "deployment protects runtime path {$path}"
    );
}

deployment_preservation_assert(str_contains($deployment, '--delete'), 'stale application code is still removed during deployment');

echo "deployment runtime data preservation tests passed\n";
