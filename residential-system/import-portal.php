<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/src/import.php';
if(count($argv)!==2)exit("Usage: php import-portal.php private-source.json\n");
$rows=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(!is_array($rows))throw new RuntimeException('Expected source array.');
echo json_encode(r_import($rows),JSON_THROW_ON_ERROR).PHP_EOL;
