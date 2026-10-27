<?php
declare(strict_types=1);
$webRoot=realpath($_SERVER['DOCUMENT_ROOT']??'');
$privateRoot=$webRoot?realpath(dirname($webRoot).'/dakshayani-residential-live'):false;
if(!$webRoot||!$privateRoot||str_starts_with(strtolower(str_replace(chr(92),'/',$privateRoot)).'/',strtolower(str_replace(chr(92),'/',$webRoot)).'/')){http_response_code(503);exit('Enquiry service unavailable. Call 7070278178.');}
putenv('RES_DATA='.$privateRoot.'/runtime');
require_once $webRoot.'/includes/auth.php';
start_session();
if(($_SESSION['user']['role_name']??'')!=='admin'){header('Location: /login.php');exit;}
require_admin();
putenv('RES_MODE=live');
putenv('RES_MAIL=0');
define('RES_STAFF_AUTHENTICATED',true);
require $privateRoot.'/src/staff.php';
