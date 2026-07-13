<?php
declare(strict_types=1);

class QuotationZipException extends RuntimeException {}

function quotation_zip_entry_name_is_safe(string $name): bool
{
    return $name !== '' && strlen($name) <= 180 && !str_starts_with($name, '/') && !preg_match('/(^|\/)(\.\.?)(\/|$)/', $name) && !preg_match('/[\\\x00-\x1f]/', $name);
}

function quotation_zip_write(array $entries, string $zipPath, bool $forcePurePhp = false): string
{
    $seen = [];
    foreach ($entries as $entry) {
        $name = (string)($entry['name'] ?? '');
        $path = (string)($entry['path'] ?? '');
        if (!quotation_zip_entry_name_is_safe($name) || isset($seen[strtolower($name)])) {
            throw new QuotationZipException('Unsafe or duplicate ZIP entry name.', 0);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new QuotationZipException('ZIP entry source file is not readable.', 0);
        }
        $seen[strtolower($name)] = true;
    }
    if (!$forcePurePhp && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new QuotationZipException('Unable to create ZIP archive.', 0);
        }
        foreach ($entries as $entry) {
            if (!$zip->addFile((string)$entry['path'], (string)$entry['name'])) {
                $zip->close(); @unlink($zipPath); throw new QuotationZipException('Unable to add PDF to ZIP archive.', 0);
            }
        }
        if (!$zip->close()) { @unlink($zipPath); throw new QuotationZipException('Unable to finalize ZIP archive.', 0); }
        return 'ZipArchive';
    }
    quotation_zip_write_pure_php($entries, $zipPath);
    return 'pure-php';
}

function quotation_zip_write_pure_php(array $entries, string $zipPath): void
{
    $out = @fopen($zipPath, 'wb');
    if (!is_resource($out)) { throw new QuotationZipException('Unable to open ZIP output file.', 0); }
    $central = [];
    $offset = 0;
    try {
        foreach ($entries as $entry) {
            $name = (string)$entry['name']; $path = (string)$entry['path'];
            $data = file_get_contents($path);
            if (!is_string($data)) { throw new QuotationZipException('Unable to read PDF for ZIP archive.', 0); }
            $crc = crc32($data); $size = strlen($data); $nameLen = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0) . $name;
            fwrite($out, $local); fwrite($out, $data);
            $central[] = ['name'=>$name,'crc'=>$crc,'size'=>$size,'offset'=>$offset];
            $offset += strlen($local) + $size;
        }
        $centralStart = $offset;
        foreach ($central as $c) {
            $name = $c['name']; $nameLen = strlen($name);
            $record = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $c['crc'], $c['size'], $c['size'], $nameLen, 0, 0, 0, 0, 0, $c['offset']) . $name;
            fwrite($out, $record); $offset += strlen($record);
        }
        $centralSize = $offset - $centralStart;
        fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, count($central), count($central), $centralSize, $centralStart, 0));
    } catch (Throwable $e) {
        fclose($out); @unlink($zipPath); throw $e;
    }
    fclose($out);
}
