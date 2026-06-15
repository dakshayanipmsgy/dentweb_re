<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public_document_security.php';
protect_customer_document_response();
$token=(string)($_GET['token']??'');
header('Location: customer-document-acceptance.php?'.http_build_query(['type'=>'dispatch_advice','token'=>$token]));
exit;
