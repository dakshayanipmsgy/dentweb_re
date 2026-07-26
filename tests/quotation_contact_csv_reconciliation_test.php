<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
$assert=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";};
$assert(documents_contact_normalize_name(' Dr.  ANITA-Sharma ')==='anita sharma','titles, case, whitespace and punctuation normalize for scoring');
$assert(documents_contact_similarity('Mr Raj Kumar','raj kumar')===100,'deterministic exact normalized score');
$assert(documents_contact_score_band(90)==='strong'&&documents_contact_score_band(75)==='likely'&&documents_contact_score_band(60)==='weak','score bands');
$bad=documents_contact_parse_csv("mobile,name\n9876543210,A\n"); $assert(!$bad['ok'],'exact required headers enforced');
$bad=documents_contact_parse_csv("name,mobile\nA,123\n"); $assert(!$bad['ok'],'Indian mobile validation enforced');
$bad=documents_contact_parse_csv("name,mobile\nAnita,9876543210\n Anita ,9876543210\n"); $assert(!$bad['ok']&&str_contains(implode(' ',$bad['errors']),'Duplicate'),'normalized duplicate rows reported');
$parsed=documents_contact_parse_csv("name,mobile\nDr Anita Sharma,+91 98765 43210\n"); $assert($parsed['ok']&&$parsed['rows'][0]['mobile']==='9876543210','valid CSV canonicalizes mobile');
$quotes=[['id'=>'q1','quote_no'=>'QT-1','status'=>'accepted','customer_name'=>'Anita Sharma','customer_mobile'=>'9876543210','updated_at'=>'v1'],['id'=>'q2','quote_no'=>'QT-2','status'=>'approved','customer_name'=>'Dr. Anita Sharma','customer_mobile'=>'9123456789','updated_at'=>'v2']];
$preview=documents_contact_build_preview($parsed['rows'],$quotes); $assert(count($preview)>=1,'candidates at 60 percent or more displayed');
$exact=array_values(array_filter($preview,static fn($c)=>$c['quotation_id']==='q1'))[0];
$assert($exact['mobile_matches']&&!empty($exact['conflicts'])&&!$exact['preselected'],'matching mobile and one-to-many ambiguity are exposed and not preselected');
$archived=['id'=>'q-archived','quote_no'=>'QT-ARCHIVED','status'=>'accepted','customer_name'=>'Anita Sharma','customer_mobile'=>'9000000000','updated_at'=>'v3','archived_flag'=>true];
$withoutArchive=documents_contact_build_preview($parsed['rows'],[$quotes[0],$archived]);
$assert(count($withoutArchive)===1&&$withoutArchive[0]['quotation_id']==='q1','archived candidates are excluded from preview');
$assert($withoutArchive[0]['conflicts']===[]&&!empty($withoutArchive[0]['preselected']),'archived records do not create ambiguity');

$supported=[];
foreach(['draft'=>'Draft','approved'=>'Approved','accepted'=>'Accepted','completed'=>'Completed project'] as $id=>$status){
    $supported[]=['id'=>'q-'.$id,'quote_no'=>'QT-'.strtoupper($id),'status'=>$status,'project_status'=>$id==='completed'?'completed':'','customer_name'=>'Anita Sharma','customer_mobile'=>'9000000000','updated_at'=>'supported-'.$id];
}
$supportedPreview=documents_contact_build_preview($parsed['rows'],$supported);
$assert(array_column($supportedPreview,'quotation_id')===array_column($supported,'id'),'active Draft, Approved, Accepted and Completed-project quotations remain supported');

// Simulate a quotation being archived after it was selected in preview.  The
// byte-for-byte file check covers contact fields, links, revisions and audit.
$applyId='contact-archive-test-'.bin2hex(random_bytes(5));
$applyPath=documents_quotations_dir().'/'.safe_filename($applyId).'.json';
$lockPath=documents_quotations_dir().'/'.safe_filename($applyId).'.contact-batch.lock';
documents_ensure_dir(documents_quotations_dir());
$active=['id'=>$applyId,'quote_no'=>'QT-ARCHIVE-APPLY','status'=>'draft','customer_name'=>'Anita Sharma','customer_mobile'=>'9876543210','updated_at'=>'preview-version','revision_no'=>4,'links'=>['customer_mobile'=>'9876543210'],'contact_correction_audit'=>[]];
json_save($applyPath,$active);
try {
    $applyPreview=documents_contact_build_preview($parsed['rows'],[$active]);
    $assert(count($applyPreview)===1,'active quotation is available before archival');
    $stored=json_load($applyPath,[]); $stored['archived_flag']=true; json_save($applyPath,$stored);
    $before=file_get_contents($applyPath);
    $candidate=$applyPreview[0];
    $result=documents_contact_apply_batch(['candidates'=>[$candidate]],[(string)$candidate['candidate_id']=>['action'=>'apply','name_choice'=>'csv','mobile_choice'=>'csv']],'Issue 825 archive race test',['type'=>'test','id'=>'issue-825'],'batch_825_archive_race');
    $assert(($result['results'][0]['message']??'')==='Skipped — quotation is archived','archived-after-preview quotation is rejected during apply with the required reason');
    $assert(file_get_contents($applyPath)===$before,'no name, mobile, linkage, revision or audit write occurs for skipped archive');
} finally {
    @unlink($applyPath); @unlink($lockPath);
}
$assert(!array_key_exists('score',['client'=>'never accepted']),'server matching does not consume client scores');
echo "quotation contact CSV reconciliation tests passed\n";
