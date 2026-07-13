<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/quotation_bulk_actions.php';
$assert = static function(bool $ok,string $m){ if(!$ok){throw new RuntimeException($m);} echo "PASS: $m\n"; };
if(!class_exists('ZipArchive')){ echo "SKIP: ZipArchive required for installer archive fixture\n"; exit(0); }
$tmp=quotation_browser_pdf_create_private_temp_dir('dentweb-installer-test-');
try{
  $det=quotation_browser_managed_detect_platform();
  $root=$tmp.'/pkg/chrome-linux64'; mkdir($root,0700,true); $exe=$root.'/chrome';
  file_put_contents($exe, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then echo 'Chromium 126 test'; exit 0; fi
for arg in "$@"; do
  case "$arg" in --print-to-pdf=*) out="${arg#--print-to-pdf=}" ;; esac
done
printf '%s
' '%PDF-1.4 managed test' > "$out"
SH
); chmod($exe,0700);  $archive=$tmp.'/browser.zip'; $zip=new ZipArchive(); $zip->open($archive,ZipArchive::CREATE); $zip->addFile($exe,'chrome-linux64/chrome'); $zip->close();
  $manifest=['allow_hosts'=>['storage.googleapis.com'],'packages'=>[['platform'=>$det['platform'],'architecture'=>$det['architecture'],'version'=>'fixture-126','url'=>'https://storage.googleapis.com/fixture/chrome.zip','sha256'=>hash_file('sha256',$archive),'executable'=>'chrome-linux64/chrome','max_archive_bytes'=>1000000,'max_extracted_bytes'=>1000000]]];
  $res=quotation_browser_managed_install($manifest,$archive); $assert(($res['ok']??false)===true && $res['version']==='fixture-126','successful managed browser installation using local fixture');
  $bad=$manifest; $bad['packages'][0]['sha256']=str_repeat('0',64); try{ quotation_browser_managed_install($bad,$archive); $assert(false,'checksum mismatch rejected'); }catch(Throwable $e){ $assert(true,'checksum mismatch rejected'); }
  $bad=$manifest; $bad['packages'][0]['url']='https://evil.example/chrome.zip'; try{ quotation_browser_managed_install($bad,$archive); $assert(false,'unapproved host rejected'); }catch(Throwable $e){ $assert(true,'unapproved host rejected'); }
  $trav=$tmp.'/trav.zip'; $zip=new ZipArchive(); $zip->open($trav,ZipArchive::CREATE); $zip->addFromString('../bad','x'); $zip->close(); $bad=$manifest; $bad['packages'][0]['sha256']=hash_file('sha256',$trav); try{ quotation_browser_managed_install($bad,$trav); $assert(false,'archive traversal rejected'); }catch(Throwable $e){ $assert(true,'archive traversal rejected'); }
  $bad=$manifest; $bad['packages'][0]['max_archive_bytes']=1; try{ quotation_browser_managed_install($bad,$archive); $assert(false,'oversized archive rejected'); }catch(Throwable $e){ $assert(true,'oversized archive rejected'); }
} finally { quotation_browser_pdf_remove_tree($tmp); }
