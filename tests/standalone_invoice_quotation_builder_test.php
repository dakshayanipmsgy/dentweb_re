<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/dentweb_quote_invoice_builder_'.bin2hex(random_bytes(4));
putenv('DOCUMENTS_BASE_DIR='.$root.'/documents');
putenv('LEGACY_BILLING_BASE_DIR='.$root.'/legacy');
require_once __DIR__.'/../includes/standalone_invoice_quotation_builder.php';

$n=0;
function quoteInvoiceBuilderAssert(bool $ok,string $message):void{global $n;$n++;if(!$ok)throw new RuntimeException($message);}

try{
    documents_ensure_structure();
    file_put_contents(documents_settings_dir().'/numbering_rules.json',json_encode([
        'fy_start_month'=>4,
        'rules'=>[[
            'doc_type'=>'invoice_public','segment'=>'RES','active'=>true,'prefix'=>'INV',
            'format'=>'{{prefix}}/{{segment}}/{{fy}}/{{seq}}','seq_start'=>1,'seq_current'=>1,'seq_digits'=>4,
        ]],
    ]));

    $catalog=[
        'components'=>[[
            'id'=>'cmp_panel','name'=>'Solar Module','description'=>'DCR bifacial module','hsn'=>'8541','default_unit'=>'Nos','archived_flag'=>false,
        ]],
        'kits'=>[[
            'id'=>'kit_bos','name'=>'Standard BOS Kit','description'=>'Structure and BOS','hsn'=>'8541','tax_profile_id'=>'tax_solar','archived_flag'=>false,
        ]],
        'variants'=>[[
            'id'=>'var_550','component_id'=>'cmp_panel','display_name'=>'550 Wp','brand'=>'Vikram','technology'=>'TOPCon','wattage_wp'=>550,'model_no'=>'V550','archived_flag'=>false,
        ]],
        'tax_profiles'=>[],
    ];
    $post=[
        'quote_item_type'=>[0=>'component',1=>'kit'],
        'quote_item_kit_id'=>[0=>'',1=>'kit_bos'],
        'quote_item_component_id'=>[0=>'cmp_panel',1=>''],
        'quote_item_variant_id'=>[0=>'var_550',1=>''],
        'quote_item_qty'=>[0=>'6',1=>'1'],
        'quote_item_unit'=>[0=>'Nos',1=>'set'],
        'quote_item_auto_description'=>[0=>'DCR bifacial module',1=>'Structure and BOS'],
        'quote_item_custom_description'=>[0=>'',1=>''],
        'quote_item_description_mode'=>[0=>'auto',1=>'auto'],
    ];
    $parsed=standalone_invoice_quote_builder_parse_items($post,$catalog,['defaults'=>['hsn_solar'=>'8541']]);
    quoteInvoiceBuilderAssert(!empty($parsed['ok']),'mixed component and kit rows parse');
    quoteInvoiceBuilderAssert(count($parsed['structured'])===2,'two structured rows preserved');
    quoteInvoiceBuilderAssert(($parsed['structured'][0]['component_id']??'')==='cmp_panel','component master id preserved');
    quoteInvoiceBuilderAssert(($parsed['structured'][0]['variant_id']??'')==='var_550','variant master id preserved');
    quoteInvoiceBuilderAssert(($parsed['structured'][0]['name_snapshot']??'')==='Solar Module (550 Wp)','component/variant display name comes from master');
    quoteInvoiceBuilderAssert(($parsed['structured'][0]['hsn_snapshot']??'')==='8541','component HSN comes from master');
    quoteInvoiceBuilderAssert(($parsed['structured'][1]['kit_id']??'')==='kit_bos','kit master id preserved');
    quoteInvoiceBuilderAssert(($parsed['structured'][1]['name_snapshot']??'')==='Standard BOS Kit','kit name comes from master');

    $bad=$post;$bad['quote_item_component_id'][0]='does_not_exist';
    $invalid=standalone_invoice_quote_builder_parse_items($bad,$catalog,['defaults'=>['hsn_solar'=>'8541']]);
    quoteInvoiceBuilderAssert(empty($invalid['ok']),'unknown component cannot be submitted as free text');

    $store=new CustomerFsStore($root.'/customers');
    $added=$store->addCustomer([
        'name'=>'Quotation Style Customer','mobile'=>'9876543210','address'=>'Hinoo, Ranchi','city'=>'Ranchi','district'=>'Ranchi','state'=>'Jharkhand','pin_code'=>'834002',
        'meter_number'=>'M-1','application_id'=>'APP-1','sanction_load_kwp'=>'5','installed_pv_module_capacity_kwp'=>'5',
    ]);
    quoteInvoiceBuilderAssert(!empty($added['success']),'customer fixture created');
    $customerRows=standalone_invoice_quote_builder_customers($store);
    quoteInvoiceBuilderAssert(count($customerRows)===1,'customer autocomplete source uses CustomerFsStore');
    quoteInvoiceBuilderAssert(($customerRows[0]['name']??'')==='Quotation Style Customer'&&($customerRows[0]['mobile']??'')==='9876543210','customer autocomplete carries name/mobile');
    quoteInvoiceBuilderAssert(($customerRows[0]['application_id']??'')==='APP-1','customer autocomplete carries quotation-style fields');

    $created=documents_create_standalone_invoice(['segment'=>'RES']);
    quoteInvoiceBuilderAssert(!empty($created['ok']),'standalone invoice still uses existing creation helper');
    $invoice=documents_get_invoice((string)$created['invoice_id']);
    $invoice['standalone_builder']=['mode'=>'quotation_style','version'=>1];
    quoteInvoiceBuilderAssert(standalone_invoice_quote_builder_is_enabled($invoice),'quotation-style mode is explicit');
    quoteInvoiceBuilderAssert(($invoice['linked_quote_id']??'')===''&&($invoice['quotation_id']??'')==='','quotation-style standalone still has no fake quotation');
    quoteInvoiceBuilderAssert((glob(documents_quotations_dir().'/*.json')?:[])===[],'builder initialization creates no quotation file');

    $admin=file_get_contents(__DIR__.'/../admin-invoices.php');
    $builderPage=file_get_contents(__DIR__.'/../admin-invoice-create.php');
    quoteInvoiceBuilderAssert(str_contains($admin,'admin-invoice-create.php?id=')&&str_contains($admin,"'mode'=>'quotation_style'"),'Create Invoice routes new standalone draft to quotation-style builder');
    quoteInvoiceBuilderAssert(str_contains($builderPage,'Find Customer User by name or mobile'),'builder exposes customer autocomplete');
    quoteInvoiceBuilderAssert(str_contains($builderPage,'main_solar_kwp')&&str_contains($builderPage,'complimentary_non_dcr_kwp'),'builder exposes DCR and non-DCR fields');
    quoteInvoiceBuilderAssert(str_contains($builderPage,'quote_item_component_id[')&&str_contains($builderPage,'quote_item_kit_id[')&&str_contains($builderPage,'quote_item_variant_id['),'builder uses indexed quotation item fields');
    quoteInvoiceBuilderAssert(str_contains($builderPage,'Items Master'),'builder identifies Items Master as item source');

    echo "standalone_invoice_quotation_builder_test passed ($n assertions)\n";
} finally {
    $it=is_dir($root)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST):null;
    if($it)foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}
    if(is_dir($root))rmdir($root);
}
