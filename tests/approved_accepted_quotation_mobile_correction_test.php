<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
};
$id = 'TEST-MOBILE-CORRECTION-' . bin2hex(random_bytes(5));
$path = documents_quotations_dir() . '/' . safe_filename($id) . '.json';
$quote = documents_quote_defaults();
$quote['id']=$id; $quote['quote_no']='QT-811'; $quote['status']='approved';
$quote['created_at']='2026-01-01T00:00:00+00:00'; $quote['updated_at']='2026-01-02T00:00:00+00:00';
$quote['customer_mobile']='9876543210'; $quote['customer_snapshot']['mobile']='9876543210';
$quote['source']=['type'=>'lead','lead_id'=>'lead-1','lead_mobile'=>'9876543210'];
$quote['import_metadata']=['batch_id'=>'batch-1']; $quote['public_share_token']='stable-token';
$quote['public_share_enabled']=true; $quote['calc']=['gross_payable'=>123456];
$quote['items']=[['name'=>'Panel','qty'=>1]]; $quote['workflow_docs']=['invoice'=>['INV-1']];
$quote['customer_acceptance']=['confirmed_at'=>'2026-01-03T00:00:00+00:00'];
$quote['project_completion']=['state'=>'completed','snapshot'=>['mobile'=>'9876543210']];
$before=$quote;
try {
    $assert((documents_save_quote($quote)['ok']??false)===true,'fixture saved');
    $before=documents_get_quote($id);
    $bad=documents_correct_quotation_mobile($id,'123','reason',$quote['updated_at'],['id'=>'1','name'=>'Admin']);
    $assert(empty($bad['ok']),'invalid mobile rejected');
    $noReason=documents_correct_quotation_mobile($id,'9123456789','',$quote['updated_at'],['id'=>'1','name'=>'Admin']);
    $assert(empty($noReason['ok']),'reason is mandatory');
    $stale=documents_correct_quotation_mobile($id,'9123456789','Typo','stale',['id'=>'1','name'=>'Admin']);
    $assert(empty($stale['ok']) && str_contains($stale['error'],'changed'),'stale edit rejected');
    $result=documents_correct_quotation_mobile($id,'+91 91234 56789','Customer reported typo',$quote['updated_at'],['id'=>'1','name'=>'Admin'],false,'request-811');
    $assert(!empty($result['ok']),'approved quotation mobile corrected');
    $after=documents_get_quote($id);
    $assert($after['customer_mobile']==='9123456789' && $after['customer_snapshot']['mobile']==='9123456789','only canonical current contact normalized');
    foreach (['id','quote_no','status','source','import_metadata','public_share_token','public_share_enabled','calc','items','workflow_docs','customer_acceptance','project_completion'] as $key) {
        $assert($after[$key]===$before[$key],$key.' preserved');
    }
    $assert(count($after['mobile_correction_audit'])===1 && $after['mobile_correction_audit'][0]['reason']==='Customer reported typo','append-only correction audit recorded');
    $duplicate=documents_correct_quotation_mobile($id,'9123456789','Customer reported typo',$after['updated_at'],['id'=>'1','name'=>'Admin'],false,'request-811');
    $assert(!empty($duplicate['ok']) && !empty($duplicate['duplicate']),'duplicate submit is idempotent');
    $draft=$after; $draft['status']='draft'; $draft['updated_at']='2026-01-04T00:00:00+00:00'; documents_save_quote($draft);
    $blocked=documents_correct_quotation_mobile($id,'9234567890','No draft edits',$draft['updated_at'],['id'=>'1','name'=>'Admin']);
    $assert(empty($blocked['ok']),'draft editing behavior is unchanged');
} finally {
    @unlink($path); @unlink(documents_quotations_dir().'/'.$id.'.mobile-correction.lock');
}
echo "approved/accepted quotation mobile correction tests passed\n";
