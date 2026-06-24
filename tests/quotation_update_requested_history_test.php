<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
$ok = static function(bool $condition, string $label): void { if (!$condition) { throw new RuntimeException($label); } echo "PASS: $label\n"; };
$id = 'TEST-UPD-' . bin2hex(random_bytes(4));
$created = [];
$numberingPath = documents_settings_dir() . '/numbering_rules.json';
$numberingOriginal = is_file($numberingPath) ? file_get_contents($numberingPath) : '';
$numberingMode = is_file($numberingPath) ? (fileperms($numberingPath) & 0777) : 0644;
$numbering = is_file($numberingPath) ? (json_decode((string) file_get_contents($numberingPath), true) ?: []) : [];
$numbering['fy_start_month'] = $numbering['fy_start_month'] ?? 4;
$numbering['rules'] = is_array($numbering['rules'] ?? null) ? $numbering['rules'] : [];
$numbering['rules'][] = ['id' => 'test-quotation-res', 'doc_type' => 'quotation', 'segment' => 'RES', 'prefix' => 'DE/QTN', 'format' => '{{prefix}}/{{segment}}/{{fy}}/{{seq}}', 'seq_start' => 9000, 'seq_current' => 9000, 'seq_digits' => 4, 'active' => true, 'is_active' => true, 'archived_flag' => false];
file_put_contents($numberingPath, json_encode($numbering, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
try {
    $q = documents_quote_defaults();
    $q['id'] = $id;
    $q['quote_no'] = $id;
    $q['quote_series_id'] = $id;
    $q['status'] = 'approved';
    $q['public_share_enabled'] = true;
    $q['public_share_token'] = 'tok-' . $id;
    $q['customer_mobile'] = '9876543210';
    $save = documents_save_quote($q);
    $ok(!empty($save['ok']), 'fixture quote saved');
    $created[] = documents_quotations_dir() . '/' . safe_filename($id) . '.json';

    $result = documents_create_quote_revision($q, 'Customer requested changes', 'CR-TEST-1');
    $ok(!empty($result['ok']), 'customer request creates a revision');
    $source = (array) ($result['source'] ?? []);
    $draft = (array) ($result['quote'] ?? []);
    $created[] = documents_quotations_dir() . '/' . safe_filename((string) $draft['id']) . '.json';
    $ok(documents_quote_normalize_status((string) ($source['status'] ?? '')) === 'update_requested', 'source quote moves to Update Requested');
    $ok(documents_status_label($source, 'public') === 'Update Requested', 'Update Requested has a customer readable label');
    $sourceHistory = documents_quote_customer_visible_history($source);
    $draftHistory = documents_quote_customer_visible_history($draft);
    $ok($sourceHistory !== [] && ($sourceHistory[0]['event'] ?? '') === 'update_requested', 'source records customer-visible update history');
    $ok($draftHistory !== [] && ($draftHistory[0]['event'] ?? '') === 'revision_created', 'revision records customer-visible draft history');

    $approved = documents_quote_apply_admin_status_transition($draft, 'approved', ['id' => 'admin', 'name' => 'Admin']);
    $ok(!empty($approved['ok']), 'draft from requested update can be approved');
    $accepted = documents_quote_apply_admin_status_transition((array) $approved['quote'], 'accepted', ['id' => 'admin', 'name' => 'Admin']);
    $ok(!empty($accepted['ok']), 'draft from requested update can be accepted');
} finally {
    foreach ($created as $path) { if (is_file($path)) { unlink($path); } }
    file_put_contents($numberingPath, (string) $numberingOriginal);
    chmod($numberingPath, $numberingMode);
}
