<?php declare(strict_types=1);
require_once __DIR__.'/../admin/includes/documents_helpers.php';
function finance_ok($v,$m){if(!$v)throw new RuntimeException($m);}
finance_ok(quotation_number_input_value(23.456789012345)==='23.46','percentage rounds to two decimals');
finance_ok(quotation_number_input_value('47,890.1234567')==='47890.12','comma money normalizes');
finance_ok(quotation_number_input_value(-0.0000001)==='0','negative zero normalizes');
$q=[
 'id'=>'legacy','finance_inputs'=>['monthly_bill_rs'=>'1,234.567','loan'=>['interest_pct'=>'5.755','tenure_years'=>'10.000000']],
 'scenario_prices'=>['loan_upto_2_lacs'=>['price'=>'298890.75']],
 'finance_scenarios'=>[
   'loan_upto_2_lacs'=>['price'=>'298890.75','margin_ratio_pct'=>23.456789012345,'loan_ratio_pct'=>76.543210987655,'margin_money_rs'=>47890.1234567,'loan_amount_rs'=>251000.999,'interest_pct'=>'5.755','tenure_years'=>'10.000000'],
   'loan_above_2_lacs'=>['price'=>'298890.75','margin_ratio_pct'=>20.123456,'loan_ratio_pct'=>79.876544,'margin_money_rs'=>60123.4567,'loan_amount_rs'=>238767.2933,'interest_pct'=>'8.155','tenure_years'=>'12.000000']
 ]
];
$n=documents_quote_normalize_editable_finance_values($q);
$up2=$n['finance_scenarios']['loan_upto_2_lacs'];
finance_ok($up2['loan_amount_rs']===200000.0,'up-to-2-lacs loan is capped at 200000');
finance_ok($up2['margin_money_rs']===98890.75,'up-to-2-lacs margin complements capped loan');
finance_ok($up2['margin_ratio_pct']===33.09,'up-to-2-lacs margin ratio is finite and canonical');
finance_ok($up2['loan_ratio_pct']===66.91,'up-to-2-lacs loan ratio is finite and canonical');
$clone=documents_quote_reset_clone_state($q,'new-finance');
finance_ok($clone['finance_scenarios']['loan_upto_2_lacs']['loan_amount_rs']===200000.0,'clone normalizes editable up-to-2-lacs finance values');
finance_ok($clone['finance_inputs']['monthly_bill_rs']==='1234.57','finance input string canonicalized for rendering');
echo "quotation finance numeric normalization tests passed\n";
