<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/solar-enquiry/intake.php'){require dirname(__DIR__).'/src/api.php';return true;}
if($path==='/residential-enquiries.php'){
 if(getenv('RES_LOCAL_QA')!=='1'||!in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)){http_response_code(403);exit;}
 session_name('RES_LOCAL_TEST');session_start();$_SESSION['user']=['id'=>'SYNTHETIC-LOCAL-REVIEW'];define('RES_STAFF_AUTHENTICATED',true);require dirname(__DIR__).'/src/staff.php';return true;
}
if($path==='/'||$path==='/solar-enquiry/'){readfile(__DIR__.'/solar-enquiry/index.html');return true;}
if(preg_match('~^/(solar-enquiry|residential-assets)/[a-zA-Z0-9_./-]+$~D',$path)&&!str_contains($path,'..')&&is_file(__DIR__.$path)&&!str_ends_with($path,'.php'))return false;
http_response_code(404);echo 'Not found';
