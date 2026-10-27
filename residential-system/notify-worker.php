<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/src/core.php';
$release=is_file(__DIR__.'/release-config.php')?require __DIR__.'/release-config.php':[];
if(empty($release['ready']))exit("Release not ready\n");
putenv('RES_MODE=live');putenv('RES_MAIL='.(!empty($release['mail_enabled'])?'1':'0'));
echo json_encode(r_notify(fn($to,$subject,$body)=>mail($to,$subject,$body,['From'=>'Dakshayani Solar <connect@dakshayani.co.in>','Reply-To'=>'d.entranchi@gmail.com','Content-Type'=>'text/plain; charset=UTF-8'],'-fconnect@dakshayani.co.in')),JSON_THROW_ON_ERROR).PHP_EOL;
