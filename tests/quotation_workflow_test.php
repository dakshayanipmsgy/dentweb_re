<?php
declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
require_once __DIR__.'/../includes/quotation_view_renderer.php';
function workflow_assert(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n"; }
foreach([100000=>[90000,10000],294900=>[200000,94900],500890=>[200000,300890]] as $price=>$expected){
 $loan=quotation_workflow_loan((float)$price);workflow_assert($loan['loan_amount_rs']==$expected[0]&&$loan['margin_money_rs']==$expected[1],"Capped loan and complementary margin at $price");
}
$breakdown=quotation_workflow_breakdown(['breakdown_base_price'=>'281900','breakdown_label'=>['High structure'],'breakdown_amount'=>['13000']],294900);
workflow_assert($breakdown['total']==294900.0,'Additional charge included exactly once');
try{quotation_workflow_breakdown(['breakdown_base_price'=>'281900','breakdown_label'=>['High structure'],'breakdown_amount'=>['13000']],300000);throw new RuntimeException('Mismatch accepted');}catch(InvalidArgumentException $e){workflow_assert(true,'Mismatched breakdown rejected');}
foreach(['0','-1','NaN','100.001','1e9','999999999'] as $bad){try{quotation_workflow_amount($bad);throw new RuntimeException('Invalid payment accepted');}catch(InvalidArgumentException $e){}}
$upi=quotation_workflow_upi('5000','QTN/TEST/1');parse_str((string)parse_url($upi,PHP_URL_QUERY),$params);
workflow_assert($params['pa']==='d.entranchi@ybl'&&$params['am']==='5000.00'&&$params['tn']==='Quotation QTN/TEST/1','UPI amount, recipient and reference are exact');
$clean=quotation_workflow_clean_html('<p onclick="alert(1)"><strong>Safe</strong><script>alert(1)</script><a href="javascript:alert(1)">link</a></p>');
workflow_assert(str_contains($clean,'<strong>Safe</strong>')&&!str_contains($clean,'onclick')&&!str_contains($clean,'script'),'Rich text retains formatting and removes active content');
$q=documents_quote_defaults();$q['id']='WORKFLOW-TEST';$q['quote_no']='WORKFLOW-TEST';$q['workflow_version']=2;
$q['capacity_kwp']='5';$q['main_solar_kwp']='3';$q['complimentary_non_dcr_kwp']='2';$q['system_capacity_kwp']=5;
$q['customer_name']='Test Customer';$q['customer_mobile']='9876543210';$q['project_summary_line']='5 kW on-grid with high structure';$q['price_breakdown']=$breakdown;
$q['input_total_gst_inclusive']=294900;$q['calc']=['gross_payable'=>294900,'grand_total'=>294900,'subsidy_expected_rs'=>78000];
foreach(['self_funded','loan_upto_2_lacs','loan_above_2_lacs'] as $key)$q['scenario_prices'][$key]=['price'=>294900];
$q['finance_scenarios']['loan_upto_2_lacs']=['price'=>294900,'loan_amount_rs'=>117753.57,'margin_money_rs'=>177146.43,'tenure_years'=>0,'emi_rs'=>99999,'applicable'=>true];
$q['finance_scenarios']['loan_above_2_lacs']=['price'=>294900,'tenure_years'=>0,'applicable'=>true];
$snapshot=['monthly_bill_before_rs'=>5400,'unit_rate_rs_per_kwh'=>8,'annual_generation_kwh_per_kw'=>1620];
$before=$q;$f=solar_finance_normalize_for_quote_render($q,$q['calc'],$snapshot);
$up=$f['finance_scenarios']['loan_upto_2_lacs_subsidy_to_loan'];$above=$f['finance_scenarios']['loan_above_2_lacs_subsidy_to_loan'];
workflow_assert($up['loan_amount_rs']===200000.0&&$up['margin_money_rs']===94900.0,'Saved stale ratios cannot change standard financing');
workflow_assert($up['tenure_months']===120&&$above['tenure_months']===120,'Both bands use 120 months');
workflow_assert($up['emi_rs']<3000&&$above['emi_rs']<3000,'Stale EMI and zero tenure do not leak into new workflow');
workflow_assert(!isset($f['finance_scenarios']['loan_upto_2_lacs_subsidy_not_to_loan']),'Unsupported subsidy-self-kept scenario excluded');
$small=$q;$small['finance_scenarios']['loan_above_2_lacs']['price']=225000;
$smallFinance=solar_finance_normalize_for_quote_render($small,$small['calc'],$snapshot);
workflow_assert(!$smallFinance['finance_scenarios']['loan_above_2_lacs_subsidy_to_loan']['applicable'],'Above-cap eligibility uses the actual 80 percent loan');
workflow_assert($q===$before,'Rendering does not mutate historical records');
$legacy=$q;unset($legacy['workflow_version']);$lf=solar_finance_normalize_for_quote_render($legacy,$legacy['calc'],$snapshot);
workflow_assert(isset($lf['finance_scenarios']['loan_upto_2_lacs_subsidy_not_to_loan']),'Legacy quotations retain their historical scenario interpretation');
$q=quotation_workflow_apply_annexures($q,['ann_mode'=>['cover_notes'=>'inherit','payment_terms'=>'hide']],['cover_notes'=>'<p>Generic saved note</p>','payment_terms'=>'Some terms']);
workflow_assert($q['cover_notes_html_snapshot']==='<p>Generic saved note</p>'&&$q['annexures_overrides']['payment_terms']==='','Template inheritance and explicit hiding remain distinct');
$html=quotation_render_to_html($q,documents_quote_defaults_settings(),documents_get_company_profile_for_quotes());
workflow_assert(str_contains($html,'5 kW on-grid with high structure')&&str_contains($html,'High structure (included)'),'Project summary and named price addition render');
workflow_assert(str_contains($html,'quotation-payment.php')&&str_contains($html,'Amount to pay'),'Quotation exposes customer-entered payment path');
$shared=$q;$shared['public_share_enabled']=true;$shared['public_share_token']='test-public-payment-token';
$exported=quotation_render_to_html($shared,documents_quote_defaults_settings(),documents_get_company_profile_for_quotes(),false,'https://example.test/quotation-public.php?t=test-public-payment-token','browser-client-export');
workflow_assert(str_contains($exported,'https://example.test/quotation-payment.php?t=test-public-payment-token'),'Customer PDF payment link uses the public token rather than an admin login');
workflow_assert(!str_contains($exported,'name="sales_assigned_to"'),'Internal follow-up controls stay out of customer output');
$quotes=[['id'=>'1','quote_no'=>'TEST-101','customer_name'=>'Animesh','customer_mobile'=>'8002305953','sales_followup'=>['assigned_to_name'=>'Team A','next_followup_date'=>date('Y-m-d')]],['id'=>'2','quote_no'=>'TEST-102','customer_name'=>'Another','customer_mobile'=>'9999999999']];
workflow_assert(count(quotation_workflow_filter_quotes($quotes,'800230','Team A','today'))===1,'Combined search, salesperson and follow-up filters work before pagination');
echo "Quotation workflow behavior tests passed\n";
