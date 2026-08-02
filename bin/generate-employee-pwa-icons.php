#!/usr/bin/env php
<?php
declare(strict_types=1);

const EMPLOYEE_ICON_TEAL = [15, 118, 110];
const EMPLOYEE_ICON_OUTPUTS = [
    'icon-192.png' => ['size' => 192, 'purpose' => 'standard'],
    'icon-512.png' => ['size' => 512, 'purpose' => 'standard'],
    'icon-maskable-192.png' => ['size' => 192, 'purpose' => 'maskable'],
    'icon-maskable-512.png' => ['size' => 512, 'purpose' => 'maskable'],
    'apple-touch-icon.png' => ['size' => 180, 'purpose' => 'apple'],
];
const EMPLOYEE_ICON_SOURCES = [
    'standard' => 'icon-source.svg',
    'maskable' => 'icon-maskable-source.svg',
    'apple' => 'apple-touch-icon-source.svg',
];

function employee_icon_usage(): void
{
    echo "Usage: php bin/generate-employee-pwa-icons.php [--check] [--force] [--output-dir=DIR]\n";
}

function employee_icon_options(array $argv): array
{
    $options = ['check' => false, 'force' => false, 'output' => dirname(__DIR__) . '/assets/icons/employee'];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') $options['check'] = true;
        elseif ($argument === '--force') $options['force'] = true;
        elseif (str_starts_with($argument, '--output-dir=')) $options['output'] = substr($argument, 13);
        elseif ($argument === '--help') { employee_icon_usage(); exit(0); }
        else throw new InvalidArgumentException('Unknown option. Use --help for supported options.');
    }
    if ($options['output'] === '' || str_contains($options['output'], "\0")) throw new InvalidArgumentException('Output directory is invalid.');
    return $options;
}

function employee_icon_verify(string $file, int $expected): void
{
    $info = @getimagesize($file);
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png' || $info[0] !== $expected || $info[1] !== $expected) {
        throw new RuntimeException('Generated employee icon failed PNG MIME or dimension validation.');
    }
    $signature = @file_get_contents($file, false, null, 0, 8);
    if ($signature !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('Generated employee icon has an invalid PNG signature.');
}

function employee_icon_rounded_rect(GdImage $image, float $scale, array $rect, int $radius, int $colour): void
{
    [$x1, $y1, $x2, $y2] = array_map(static fn(int $value): int => (int)round($value * $scale), $rect);
    $r = (int)round($radius * $scale);
    imagefilledrectangle($image, $x1 + $r, $y1, $x2 - $r, $y2, $colour);
    imagefilledrectangle($image, $x1, $y1 + $r, $x2, $y2 - $r, $colour);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$x, $y]) {
        imagefilledellipse($image, $x, $y, $r * 2, $r * 2, $colour);
    }
}

function employee_icon_render(string $file, int $size, string $purpose): void
{
    $image = imagecreatetruecolor($size, $size);
    if (!$image instanceof GdImage) throw new RuntimeException('Unable to allocate an icon canvas.');
    imagealphablending($image, true); imagesavealpha($image, true);
    $teal = imagecolorallocate($image, ...EMPLOYEE_ICON_TEAL); $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $teal); $scale = $size / 512;
    if ($purpose === 'maskable') {
        // Artwork stays inside the central 60% maskable safe zone.
        $box = [144, 202, 368, 366]; $radius = 30; $handle = [212, 146, 300, 222]; $stroke = 27;
        $points = [[194, 286], [228, 320], [316, 228]];
    } else {
        $box = $purpose === 'apple' ? [112, 180, 400, 396] : [104, 174, 408, 396];
        $radius = 38; $handle = $purpose === 'apple' ? [200, 108, 312, 200] : [196, 100, 316, 196];
        $stroke = $purpose === 'apple' ? 32 : 34;
        $points = $purpose === 'apple' ? [[184, 288], [226, 330], [328, 222]] : [[180, 286], [225, 331], [332, 218]];
    }
    employee_icon_rounded_rect($image, $scale, $handle, 24, $white);
    imagefilledrectangle($image, (int)round(($handle[0] + 38) * $scale), (int)round(($handle[1] + 38) * $scale), (int)round(($handle[2] - 38) * $scale), (int)round(($handle[3] + 20) * $scale), $teal);
    employee_icon_rounded_rect($image, $scale, $box, $radius, $white);
    imagesetthickness($image, max(1, (int)round($stroke * $scale)));
    for ($i = 1; $i < count($points); $i++) imageline($image, (int)round($points[$i-1][0]*$scale), (int)round($points[$i-1][1]*$scale), (int)round($points[$i][0]*$scale), (int)round($points[$i][1]*$scale), $teal);
    if (!imagepng($image, $file, 9, PNG_ALL_FILTERS)) throw new RuntimeException('Unable to encode an employee PNG icon.');
    imagedestroy($image);
}

try {
    $options = employee_icon_options($argv);
    if (!extension_loaded('gd') || !function_exists('imagepng')) throw new RuntimeException('PHP GD with PNG support is required. Install/enable php-gd, then rerun this command.');
    $sourceDir = dirname(__DIR__) . '/assets/icons/employee';
    foreach (EMPLOYEE_ICON_SOURCES as $source) {
        $text = @file_get_contents($sourceDir . '/' . $source);
        if (!is_string($text) || !str_contains($text, '<svg') || str_contains($text, 'base64') || preg_match('/<(?:image|script)\b/i', $text)) throw new RuntimeException('Employee SVG icon source is missing or unsafe.');
    }
    if ($options['check']) {
        foreach (EMPLOYEE_ICON_OUTPUTS as $name => $definition) employee_icon_verify(rtrim($options['output'], '/\\') . '/' . $name, $definition['size']);
        echo "Employee PWA icons are present and valid.\n"; exit(0);
    }
    if (!is_dir($options['output']) && !mkdir($options['output'], 0755, true) && !is_dir($options['output'])) throw new RuntimeException('Unable to create the employee icon output directory.');
    foreach (EMPLOYEE_ICON_OUTPUTS as $name => $definition) {
        $target = rtrim($options['output'], '/\\') . '/' . $name;
        if (is_file($target) && !$options['force']) { employee_icon_verify($target, $definition['size']); continue; }
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
        try { employee_icon_render($temporary, $definition['size'], $definition['purpose']); employee_icon_verify($temporary, $definition['size']); if (!rename($temporary, $target)) throw new RuntimeException('Unable to activate a generated employee icon.'); chmod($target, 0644); }
        finally { if (is_file($temporary)) @unlink($temporary); }
    }
    echo "Generated and validated " . count(EMPLOYEE_ICON_OUTPUTS) . " employee PWA icons.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit($error instanceof InvalidArgumentException ? 64 : 2);
}
