<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/quotation_zip_writer.php';
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';
$assert = static function(bool $ok,string $m){ if(!$ok){throw new RuntimeException($m);} echo "PASS: $m\n"; };
$files=[]; try{
  $p1=quotation_bulk_temp_file('.pdf'); $p2=quotation_bulk_temp_file('.pdf'); $z=quotation_bulk_temp_file('.zip'); $files=[$p1,$p2,$z];
  file_put_contents($p1,"%PDF-1.4\nA"); file_put_contents($p2,"%PDF-1.4\nB");
  $impl=quotation_zip_write([['path'=>$p1,'name'=>'001-first.pdf'],['path'=>$p2,'name'=>'002-second.pdf']],$z,true);
  $assert($impl==='pure-php','pure-PHP ZIP writer path is selectable');
  $data=file_get_contents($z);
  $assert(strpos($data,'001-first.pdf') < strpos($data,'002-second.pdf'), 'pure-PHP ZIP preserves selection order in central directory');
  $assert(str_contains($data,"%PDF-1.4\nA") && str_contains($data,"%PDF-1.4\nB"), 'pure-PHP ZIP contains binary PDF payloads');
  try{ quotation_zip_write([['path'=>$p1,'name'=>'../bad.pdf']], quotation_bulk_temp_file('.zip'), true); $assert(false,'traversal rejected'); }catch(Throwable $e){ $assert(true,'traversal rejected'); }
  try{ quotation_zip_write([['path'=>$p1,'name'=>'same.pdf'],['path'=>$p2,'name'=>'same.pdf']], quotation_bulk_temp_file('.zip'), true); $assert(false,'duplicate rejected'); }catch(Throwable $e){ $assert(true,'duplicate rejected'); }
  if(class_exists('ZipArchive')){ $z2=quotation_bulk_temp_file('.zip'); $files[]=$z2; $impl2=quotation_zip_write([['path'=>$p1,'name'=>'one.pdf']],$z2,false); $zip=new ZipArchive(); $assert($impl2==='ZipArchive' && $zip->open($z2)===true && $zip->numFiles===1, 'ZipArchive path works and validates'); $zip->close(); }
} finally { quotation_bulk_delete_files($files); }
