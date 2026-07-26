<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_admin.php';
require_once __DIR__ . '/customer_complaints.php';
require_once __DIR__ . '/handover.php';
require_once __DIR__ . '/audit_log.php';

/** Shared, deliberately compact projection of Customer Management operations. */
function customer_operations_source_hash(array $customer): string
{
    $source = [];
    foreach (['name','mobile','address','city','district','pin_code','state','customer_type','jbvnl_account_number','application_id','installed_pv_module_capacity_kwp','solar_plant_installation_date','handover_overrides'] as $key) {
        $source[$key] = $customer[$key] ?? null;
    }
    return hash('sha256', (string) json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function customer_operations_handover_state(array $customer): array
{
    $path = trim((string) ($customer['handover_html_path'] ?? $customer['handover_document_path'] ?? ''));
    $generated = trim((string) ($customer['handover_generated_at'] ?? ''));
    $storedHash = trim((string) ($customer['handover_source_hash'] ?? ''));
    return [
        'ready' => $path !== '' && $generated !== '',
        'path' => $path,
        'generated_at' => $generated,
        'version' => max(0, (int) ($customer['handover_version'] ?? 0)),
        'hash' => $storedHash,
        'needs_regeneration' => $path !== '' && ($storedHash === '' || !hash_equals($storedHash, customer_operations_source_hash($customer))),
        'sent' => is_array($customer['handover_sent'] ?? null) ? $customer['handover_sent'] : [],
    ];
}

function customer_operations_quote_warnings(array $quote, ?array $customer): array
{
    if ($customer === null) return [];
    $warnings = [];
    $quoteMobile = complaint_normalize_mobile((string) ($quote['customer_mobile'] ?? ''));
    $customerMobile = complaint_normalize_mobile((string) ($customer['mobile'] ?? ''));
    if ($quoteMobile !== '' && $customerMobile !== '' && $quoteMobile !== $customerMobile) $warnings[] = 'Quotation mobile differs from the linked Customer User. Data was not overwritten.';
    $quoteName = trim((string) ($quote['customer_name'] ?? ''));
    $customerName = trim((string) ($customer['name'] ?? ''));
    if ($quoteName !== '' && $customerName !== '' && strcasecmp($quoteName, $customerName) !== 0) $warnings[] = 'Quotation customer name differs from the Customer User record. Review both records; neither was overwritten.';
    return $warnings;
}

/** Accept only a local project-workspace URL; never reflect hosts or arbitrary paths. */
function customer_operations_valid_return_to(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r") || str_starts_with($value, '//')) return '';
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) return '';
    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($path !== 'admin-documents.php') return '';
    parse_str((string)($parts['query'] ?? ''), $query);
    if (!in_array((string)($query['tab'] ?? ''), ['accepted_customers','completed_customers'], true)) return '';
    return $value;
}

function customer_operations_return_url(array $quote, string $returnTab, bool $detail): string
{
    $query = ['tab' => in_array($returnTab, ['accepted_customers','completed_customers'], true) ? $returnTab : 'accepted_customers'];
    if ($detail && (string)($quote['id'] ?? '') !== '') $query['view'] = (string)$quote['id'];
    return 'admin-documents.php?' . http_build_query($query);
}

function customer_operations_recent_activity(string $mobile, int $limit = 8): array
{
    $key = complaint_normalize_mobile($mobile);
    $matches = [];
    foreach (array_reverse(audit_read_recent(500)) as $event) {
        $entityKey = complaint_normalize_mobile((string) ($event['entity_key'] ?? ''));
        $details = is_array($event['details'] ?? null) ? $event['details'] : [];
        $detailMobile = complaint_normalize_mobile((string) ($details['mobile'] ?? $details['customer_mobile'] ?? ''));
        if (($event['entity_type'] ?? '') === 'customer' && $entityKey === $key || $detailMobile === $key) {
            $matches[] = $event;
            if (count($matches) >= $limit) break;
        }
    }
    return $matches;
}

function customer_operations_view_model(array $quote, ?CustomerFsStore $store = null): array
{
    $store = $store ?? new CustomerFsStore();
    $mobile = (string) ($quote['customer_mobile'] ?? '');
    $customer = $store->findByMobile($mobile);
    $linkedMobile = complaint_normalize_mobile((string)(is_array($quote['customer_user_link'] ?? null) ? ($quote['customer_user_link']['mobile'] ?? '') : ''));
    if ($customer === null && $linkedMobile !== '') $customer = $store->findByMobile($linkedMobile);
    $complaints = $customer ? get_complaints_by_customer((string) $customer['mobile']) : [];
    $open = array_values(array_filter($complaints, static fn(array $c): bool => strtolower((string) ($c['status'] ?? 'open')) !== 'closed'));
    $missing = [];
    if ($customer !== null) foreach (['name'=>'name','mobile'=>'mobile','address'=>'address','city'=>'city','pin_code'=>'PIN code'] as $field=>$label) {
        if (trim((string)($customer[$field] ?? '')) === '') $missing[] = $label;
    }
    return ['customer'=>$customer, 'mobile'=>$mobile, 'open_complaints'=>count($open), 'warnings'=>customer_operations_quote_warnings($quote, $customer), 'missing_details'=>$missing, 'handover'=>$customer ? customer_operations_handover_state($customer) : null];
}

function customer_operations_render(array $quote, string $returnTab, bool $detail = false, ?CustomerFsStore $store = null): string
{
    $m = customer_operations_view_model($quote, $store); $c = $m['customer'];
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $qid = (string) ($quote['id'] ?? ''); $mobile = (string) ($m['mobile'] ?? '');
    $returnTo = customer_operations_return_url($quote, $returnTab, $detail);
    $quoteUrl = 'quotation-view.php?' . http_build_query(['id'=>$qid, 'return_to'=>$returnTo]);
    $correctionUrl = 'admin-documents.php?' . http_build_query(['tab'=>$returnTab, 'view'=>$qid]) . '#mobile-correction-' . rawurlencode($qid);
    ob_start(); ?>
    <section class="customer-operations" aria-label="Customer Operations">
      <strong>Customer Operations</strong>
      <?php if ($c === null): ?><p><span class="pill warn">Customer User: Not linked</span></p><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="create_or_link_customer_user"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><button class="btn secondary" type="submit">Create in Customer Users</button></form>
      <?php else: $h=$m['handover']; $digits=complaint_normalize_mobile((string)$c['mobile']); ?>
        <p><span class="pill">Customer User: <?= empty($c['archived'])?'Active':'Archived' ?></span> Serial: <strong><?= $e((string)($c['serial_number']??'—')) ?></strong> · Operational status: <strong><?= $e((string)($c['status']??'New')) ?></strong></p>
        <p><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>">Open Customer</a> <a class="btn secondary" href="tel:<?= $e($digits) ?>">Call</a> <a class="btn secondary" target="_blank" rel="noopener" href="https://wa.me/91<?= $e($digits) ?>">WhatsApp</a> <button class="btn secondary" type="button" data-copy-mobile="<?= $e((string)$c['mobile']) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.copyMobile)">Copy mobile</button></p>
        <p>Welcome: <strong><?= $e(ucwords((string)($c['welcome_sent_via']??'none'))) ?></strong> · <a href="admin-customers.php?view=<?= urlencode((string)$c['mobile']) ?>#welcome-actions">WhatsApp/email actions</a></p>
        <p>Handover: <strong><?= !empty($h['ready'])?'Ready':'Not generated' ?></strong><?php if(!empty($h['generated_at'])): ?> · Generated <?= $e((string)$h['generated_at']) ?><?php endif; ?><?php if(!empty($h['needs_regeneration'])): ?> · <span class="pill warn">Needs regeneration</span><?php endif; ?> · Send status: <strong><?= !empty($h['sent']['sent_at'])?'Sent '.$e((string)$h['sent']['sent_at']).' via '.$e((string)($h['sent']['channel']??'')):'Not marked sent' ?></strong></p>
        <p><?php if(!empty($h['ready'])): ?><a class="btn secondary" target="_blank" href="<?= $e((string)$h['path']) ?>">View</a> <a class="btn secondary" target="_blank" href="<?= $e((string)$h['path']) ?>" onclick="window.print()">Print</a><?php endif; ?> <a class="btn secondary" href="generate-handover.php?mobile=<?= urlencode((string)$c['mobile']) ?>"><?= !empty($h['ready'])?'Regenerate':'Generate' ?></a><?php if(!empty($h['ready'])): ?> <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="prepare_handover_whatsapp"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><button class="btn secondary" type="submit">Handover WhatsApp</button></form> <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="mark_handover_sent"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><select name="handover_channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="in_person">In person</option></select><button class="btn" type="submit">Mark Handover Sent</button></form><?php endif; ?></p>
        <p>Open complaints: <strong><?= (int)$m['open_complaints'] ?></strong> · <a href="admin-customers.php?view=<?= urlencode((string)$c['mobile']) ?>#complaints">Add Complaint</a> · <a href="admin-complaints.php?customer_mobile=<?= urlencode((string)$c['mobile']) ?>">View Complaints</a></p>
        <?php foreach($m['warnings'] as $warning): ?><div class="banner error"><strong>Important mismatch:</strong> <?= $e($warning) ?><br><span>Quotation: name <strong><?= $e((string)($quote['customer_name']??'—')) ?></strong>, mobile <strong><?= $e((string)($quote['customer_mobile']??'—')) ?></strong> · Customer User: name <strong><?= $e((string)($c['name']??'—')) ?></strong>, mobile <strong><?= $e((string)($c['mobile']??'—')) ?></strong></span><div class="row-action-group"><a class="btn" href="<?= $e($correctionUrl) ?>">Resolve conflict</a><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>">Open Customer User</a><a class="btn secondary" href="<?= $e($quoteUrl) ?>">Review quotation</a><a class="btn secondary" href="<?= $e($correctionUrl) ?>">Change quotation mobile</a><a class="btn secondary" href="<?= $e($quoteUrl) ?>#customer-details">Review differences</a><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>#customer-details">Complete customer details</a></div></div><?php endforeach; ?>
        <?php if(($m['missing_details']??[])!==[]): ?><div class="banner error"><strong>Handover blocker:</strong> Customer User is missing <?= $e(implode(', ', $m['missing_details'])) ?>. <a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>#customer-details">Complete customer details</a></div><?php endif; ?>
        <?php if($detail): ?><details open><summary>Recent customer-operation activity</summary><ul><?php foreach(customer_operations_recent_activity((string)$c['mobile']) as $event): ?><li><?= $e((string)($event['timestamp']??'')) ?> — <?= $e(ucwords(str_replace('_',' ',(string)($event['action']??'')))) ?> (<?= $e((string)($event['actor_id']??'system')) ?>)</li><?php endforeach; ?></ul></details><?php endif; ?>
      <?php endif; ?>
    </section><?php return (string)ob_get_clean();
}
