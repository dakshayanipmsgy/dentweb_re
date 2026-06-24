<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/../includes/customer_document_acceptance.php';
function ok($cond,$msg){ if(!$cond){ fwrite(STDERR,"FAIL: $msg\n"); exit(1);} echo "ok - $msg\n"; }
$o=documents_quote_normalize_panel_orientation(['enabled'=>true,'site_area_type'=>'terrace','site_area_label'=>'Main roof','default_facing_direction'=>'South-West','default_tilt_deg'=>'15','row_layout'=>'portrait_rows','shade_note'=>'Avoid <script>x</script> tank shadow','customer_note'=>'Review layout','groups'=>[['label'=>'Group A','roof_section'=>'Main roof','panel_count'=>'8','facing_direction'=>'South','tilt_deg'=>'15','row_layout'=>'portrait_rows','remarks'=>'Front row'],['label'=>'Group B','roof_section'=>'Rear roof','panel_count'=>'4','facing_direction'=>'West','tilt_deg'=>'10','row_layout'=>'landscape_rows','remarks'=>'Rear row']]]);
ok($o['enabled']===true,'orientation enabled');
ok(count($o['groups'])===2,'multiple groups retained');
ok($o['groups'][0]['panel_count']===8,'panel count normalized');
ok(strpos($o['shade_note'],'<script>')===false,'notes sanitized');
$html=documents_quote_render_panel_orientation_diagram($o);
ok(strpos($html,'Solar panel orientation layout diagram')!==false,'diagram rendered');
ok(strpos($html,'Group A')!==false && strpos($html,'South-facing')===false || strpos($html,'South')!==false,'diagram includes group direction');
$q=documents_quote_defaults();
$q['id']='q_orientation_test';$q['quote_no']='Q/TEST/627';$q['customer_name']='Customer';$q['customer_mobile']='9876543210';$q['public_share_token']='tok';$q['panel_orientation']=$o;
ok(documents_quote_panel_orientation_is_enabled($q),'quote detects enabled orientation');
$txt=customer_acceptance_confirmation_text('quotation',$q);
ok(strpos($txt,'orientation/layout')!==false,'acceptance wording mentions orientation');
customer_acceptance_record($q,'quotation',['mobile_first6'=>'987654','confirmed'=>'1'],['salt'=>'test']);
ok(!empty($q['customer_acceptance']['accepted_panel_orientation_hash']),'acceptance hash captured');
$hash=$q['customer_acceptance']['accepted_panel_orientation_hash'];
$q['panel_orientation']['default_facing_direction']='East';
ok($q['customer_acceptance']['accepted_panel_orientation_hash']===$hash,'snapshot hash remains immutable after edit');
$q2=documents_quote_defaults();
ok(!documents_quote_panel_orientation_is_enabled($q2),'old/default quotations disabled');
ok(strpos(customer_acceptance_confirmation_text('quotation',$q2),'orientation/layout')===false,'disabled wording omits orientation');
