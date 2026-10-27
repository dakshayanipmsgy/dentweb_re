<?php
require dirname(__DIR__).'/src/core.php';
putenv('RES_DATA='.$argv[1]);putenv('RES_MODE=preview');
echo json_encode(r_accept(['name'=>'SYNTHETIC concurrent '.$argv[2],'phone'=>'9000000004','district'=>'Ranchi','bill'=>'0-1000','consent'=>'1','request_id'=>bin2hex(random_bytes(16))],'synthetic-worker-'.$argv[2]));
