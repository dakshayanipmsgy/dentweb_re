<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/dentweb_979_'.bin2hex(random_bytes(4));
putenv('DOCUMENTS_BASE_DIR='.$root.'/documents');putenv('LEGACY_BILLING_BASE_DIR='.$root.'/legacy');
require_once __DIR__.'/../admin/includes/documents_helpers.php';
$n=0;$ok=static function(bool $condition,string $message)use(&$n):void{$n++;if(!$condition)throw new RuntimeException($message);};
try {
    documents_ensure_structure();
    $invoice=documents_invoice_defaults();$invoice['id']='inv-979';$invoice['commercial_ref']=['type'=>'standalone_invoice','id'=>'inv-979'];
    $invoice['pricing']['final_invoice_total_incl_gst']=500.0;$invoice['calc']['gross_payable']=500.0;
    $set=documents_standalone_set_manual_payment_status($invoice,'paid');$ok($set['ok'],'direct invoice accepts manual paid');$invoice=$set['invoice'];
    $summary=documents_invoice_payment_summary($invoice);$ok($summary['payment_status']==='paid'&&$summary['total_received']===500.0&&$summary['outstanding']===0.0&&$summary['receipt_count']===0,'manual paid changes summary without a receipt');
    $ok(documents_list_sales_documents('receipt')===[],'manual paid creates no receipt');
    $set=documents_standalone_set_manual_payment_status($invoice,'unpaid');$invoice=$set['invoice'];$summary=documents_invoice_payment_summary($invoice);$ok($summary['payment_status']==='unpaid'&&$summary['total_received']===0.0&&$summary['outstanding']===500.0,'manual unpaid restores full balance');
    $receipt=documents_add_standalone_invoice_payment($invoice,['amount'=>100,'date_received'=>'2026-08-11'],['id'=>'admin']);$ok($receipt['ok'],'canonical receipt fixture saved');
    $summary=documents_invoice_payment_summary($invoice);$ok($summary['payment_status']==='partially_paid'&&$summary['total_received']===100.0&&$summary['outstanding']===400.0,'canonical receipt overrides manual status');
    $ok(!documents_standalone_set_manual_payment_status($invoice,'paid')['ok'],'manual override rejected when canonical receipt exists');
    $quotation=documents_invoice_defaults();$quotation['id']='quote-invoice';$ok(!documents_standalone_set_manual_payment_status($quotation,'paid')['ok'],'quotation invoice cannot use direct-only override');
    $view=file_get_contents(__DIR__.'/../invoice-view.php');$admin=file_get_contents(__DIR__.'/../admin-invoices.php');
    $ok(str_contains($view,"\$invoice['main_solar_kwp']")&&str_contains($view,"\$rateChartSnapshot['dcr_size_kwp']"),'view reads direct DCR fields and rate snapshot');
    $ok(str_contains($view,"\$invoice['complimentary_non_dcr_kwp']")&&str_contains($view,"\$rateChartSnapshot['non_dcr_size_kwp']"),'view reads direct Non-DCR fields and rate snapshot');
    $ok(str_contains($view,"\$master['hsn_snapshot']")&&str_contains($view,'$itemHsn($item,$index)'),'both tables resolve HSN from saved item/master snapshots');
    $ok(str_contains($admin,'set_standalone_payment_status')&&str_contains($admin,'Canonical receipts remain authoritative.'),'direct editor exposes guarded Paid/Unpaid control');
    echo "issue_979_standalone_invoice_display_test passed ($n assertions)\n";
} finally {
    $it=is_dir($root)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST):null;
    if($it)foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}if(is_dir($root))rmdir($root);
}
