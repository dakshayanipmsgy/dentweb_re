<?php
declare(strict_types=1);
$webRoot=realpath($_SERVER['DOCUMENT_ROOT']??'');
$privateRoot=$webRoot?realpath(dirname($webRoot).'/dakshayani-residential-live'):false;
if(!$webRoot||!$privateRoot||str_starts_with(strtolower(str_replace(chr(92),'/',$privateRoot)).'/',strtolower(str_replace(chr(92),'/',$webRoot)).'/')){http_response_code(503);exit('Enquiry service unavailable. Call 7070278178.');}
putenv('RES_DATA='.$privateRoot.'/runtime');
$release=is_file($privateRoot.'/release-config.php')?require $privateRoot.'/release-config.php':[];
if(empty($release['ready'])){http_response_code(503);header('Content-Type: application/json');exit(json_encode(['error'=>'Enquiry service is being checked. Please call 7070278178.']));}
putenv('RES_MODE=live');putenv('RES_MAIL=0');
require $privateRoot.'/src/api.php';
