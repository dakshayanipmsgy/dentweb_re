<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../admin/includes/quotation_similar_names.php';
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";};
$quotes=[
 ['id'=>'a','quote_no'=>'Q-1','status'=>'draft','customer_name'=>'Dr Anita Sharma','updated_at'=>'1'],
 ['id'=>'b','quote_no'=>'Q-2','status'=>'approved','customer_name'=>'Anita Sharma','updated_at'=>'2'],
 ['id'=>'c','quote_no'=>'Q-3','status'=>'draft','customer_name'=>'Anita Sharma','updated_at'=>'3','archived_flag'=>true],
 ['id'=>'d','quote_no'=>'Q-4','status'=>'draft','customer_name'=>'Unrelated Person','updated_at'=>'4'],
];
$groups=documents_similar_names_scan($quotes);
$ok(count($groups)===1&&array_column($groups[0]['quotations'],'id')===['a','b'],'only active similar quotations are grouped');
$ok($groups[0]['quotations'][0]['score']===100&&$groups[0]['quotations'][0]['band']==='strong','issue 823 deterministic matcher and confidence band are reused');
$flat=json_encode($groups);$ok(!str_contains($flat,'Q-3')&&!str_contains($flat,'c"'),'archived quotation is not shown, counted, scored, or grouped');
$stage=['groups'=>$groups,'decisions'=>[]];$stage=documents_similar_names_stage_confirm($stage,['a'=>'archive']);
$ok($stage['decisions']['a']==='archive'&&$stage['decisions']['b']==='ignore','archive is never inferred or preselected');
$missingReason=documents_similar_names_apply($stage,'',[], 'batch_829_test');
$ok($missingReason['failed']===['Archive reason is required.'],'archive reason is mandatory before writes');
echo "quotation similar-name cleanup tests passed\n";
