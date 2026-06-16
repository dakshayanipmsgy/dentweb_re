<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_document_acceptance.php';
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
function link_ok($cond, $msg) { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }

$staleAdvice = documents_get_dispatch_advice('da_issue_608');
if ($staleAdvice && !empty($staleAdvice['generated_challan_id'])) { @unlink(documents_challans_dir() . '/' . safe_filename((string) $staleAdvice['generated_challan_id']) . '.json'); }
@unlink(documents_challans_dir() . '/dc_issue_608_broken.json');
@unlink(documents_dispatch_advices_dir() . '/da_issue_608.json');
@unlink(documents_quotations_dir() . '/q_issue_608.json');

$numberingPath = documents_settings_dir() . '/numbering_rules.json';
$numberingBackup = is_file($numberingPath) ? file_get_contents($numberingPath) : null;
json_save($numberingPath, ['financial_year_mode' => 'FY', 'fy_start_month' => 4, 'rules' => [[
    'doc_type' => 'challan', 'segment' => 'RES', 'prefix' => 'DE/DC', 'format' => '{{prefix}}/{{segment}}/{{fy}}/{{seq}}', 'seq_start' => 608, 'seq_current' => 608, 'seq_digits' => 4, 'active' => true, 'is_active' => true, 'archived_flag' => false,
]], 'updated_at' => date('c')]);

$quote = documents_quote_defaults();
$quote['id'] = 'q_issue_608';
$quote['quote_no'] = 'Q-608';
$quote['status'] = 'accepted';
$quote['is_current_version'] = true;
$quote['customer_name'] = 'Issue 608 Customer';
$quote['customer_mobile'] = '9876543210';
$quote['customer_snapshot'] = ['name' => 'Issue 608 Customer', 'mobile' => '9876543210'];
$quote['created_at'] = $quote['updated_at'] = date('c');
documents_save_quote($quote);

$advice = documents_dispatch_advice_defaults();
$advice['id'] = 'da_issue_608';
$advice['dispatch_advice_no'] = 'DE/DA/RES/26-27/0608';
$advice['quotation_id'] = $quote['id'];
$advice['quotation_no'] = $quote['quote_no'];
$advice['status'] = 'acknowledged';
$advice['customer_name'] = 'Issue 608 Customer';
$advice['customer_mobile'] = '9876543210';
$advice['delivery_address'] = 'Issue 608 Site';
$advice['planned_dispatch_date'] = '2026-06-16';
$advice['items'] = [['line_id' => 'dal_issue_608_1', 'name' => 'Panel', 'brand_model' => 'ABC', 'qty' => 2, 'unit' => 'Nos']];
customer_acceptance_record($advice, 'dispatch_advice', ['mobile_first6' => '987654', 'confirmed' => '1'], ['salt' => 'issue-608']);
documents_save_dispatch_advice($advice);

$created = documents_create_challan_from_accepted_dispatch_advice($advice, ['role' => 'admin', 'name' => 'Admin']);
link_ok(!empty($created['ok']) && !empty($created['created']), 'created challan from accepted dispatch advice');
$challan = documents_get_challan((string) $created['challan']['id']);
foreach (['workflow_source_type', 'dispatch_advice_id', 'dispatch_advice_no', 'dispatch_advice_acceptance_ref', 'linked_quote_id', 'linked_quote_no', 'workflow_status', 'dispatch_status'] as $field) {
    link_ok(trim((string) ($challan[$field] ?? '')) !== '', "$field persisted after reload");
}
link_ok((string) $challan['workflow_status'] === 'created', 'workflow status starts created');
link_ok((string) $challan['dispatch_status'] === 'not_dispatched', 'dispatch status starts not_dispatched');

$broken = $challan;
$broken['id'] = 'dc_issue_608_broken';
$broken['dispatch_advice_id'] = '';
$broken['workflow_source_type'] = '';
$broken['source_type'] = '';
$broken['dispatch_status'] = '';
$broken['workflow_status'] = '';
documents_save_challan($broken);
$loadedBroken = documents_get_challan('dc_issue_608_broken');
link_ok((string) ($loadedBroken['dispatch_advice_id'] ?? '') === 'da_issue_608', 'broken challan source link repaired on load');
link_ok(documents_challan_is_from_dispatch_advice($loadedBroken), 'robust helper identifies repaired challan');

$mark = documents_mark_challan_dispatched($loadedBroken, ['role' => 'admin', 'name' => 'Admin'], ['delivery_date' => '2026-06-17']);
link_ok(!empty($mark['ok']), 'mark dispatched only requires dispatch date');
link_ok((string) $mark['challan']['workflow_status'] === 'dispatched', 'workflow status becomes dispatched');

@unlink(documents_challans_dir() . '/' . $created['challan']['id'] . '.json');
@unlink(documents_challans_dir() . '/dc_issue_608_broken.json');
@unlink(documents_dispatch_advices_dir() . '/da_issue_608.json');
@unlink(documents_quotations_dir() . '/q_issue_608.json');
if ($numberingBackup === null) { @unlink($numberingPath); } else { file_put_contents($numberingPath, $numberingBackup); }
echo "challan dispatch advice link repair tests passed\n";
