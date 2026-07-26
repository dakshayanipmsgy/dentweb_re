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

/** Fields that may be reconciled. Commercial, workflow and security fields are intentionally absent. */
function customer_conflict_field_map(): array
{
    return [
        'name'=>['quote'=>'customer_name','customer'=>'name','label'=>'Name','required'=>true],
        'mobile'=>['quote'=>'customer_mobile','customer'=>'mobile','label'=>'Mobile','identity'=>true,'required'=>true],
        'address'=>['quote'=>'site_address','customer'=>'address','label'=>'Address','required'=>true],
        'city'=>['quote'=>'city','customer'=>'city','label'=>'City','required'=>true],
        'district'=>['quote'=>'district','customer'=>'district','label'=>'District'],
        'pin_code'=>['quote'=>'pin','customer'=>'pin_code','label'=>'PIN code','required'=>true],
        'state'=>['quote'=>'state','customer'=>'state','label'=>'State'],
        'customer_type'=>['quote'=>'customer_type','customer'=>'customer_type','label'=>'Customer type'],
        'jbvnl_account_number'=>['quote'=>'consumer_account_no','customer'=>'jbvnl_account_number','label'=>'Consumer account'],
    ];
}

function customer_conflict_version(array $quote, ?array $customer): string
{
    $project=[]; foreach(customer_conflict_field_map() as $key=>$map) $project[$key]=$quote[$map['quote']]??'';
    $user=[]; foreach(customer_conflict_field_map() as $key=>$map) $user[$key]=$customer[$map['customer']]??'';
    return hash('sha256', json_encode(['id'=>$quote['id']??'','updated'=>$quote['updated_at']??$quote['created_at']??'','project'=>$project,'user'=>$user,'user_updated'=>$customer['updated_at']??'','archived'=>$customer['archived']??null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

/** Issue #831 projection: differences, type, and every active record sharing either mobile key. */
function customer_conflict_detect(array $quote, ?CustomerFsStore $store=null, ?array $allQuotes=null): array
{
    $store=$store??new CustomerFsStore();
    if (documents_is_archived($quote)) return ['state'=>'stale','label'=>'Stale','differences'=>[],'customer'=>null,'affected'=>[],'version'=>''];
    $quoteMobile=complaint_normalize_mobile((string)($quote['customer_mobile']??''));
    $linkedMobile=complaint_normalize_mobile((string)(is_array($quote['customer_user_link']??null)?($quote['customer_user_link']['mobile']??''):''));
    $customer=$store->findByMobile($quoteMobile)??($linkedMobile!==''?$store->findByMobile($linkedMobile):null);
    $differences=[];
    if ($customer!==null) foreach(customer_conflict_field_map() as $key=>$map) {
        $q=trim((string)($quote[$map['quote']]??'')); $c=trim((string)($customer[$map['customer']]??''));
        $equal=$key==='mobile'?complaint_normalize_mobile($q)===complaint_normalize_mobile($c):strcasecmp(preg_replace('/\s+/',' ',$q)??$q,preg_replace('/\s+/',' ',$c)??$c)===0;
        if(!$equal) $differences[$key]=['label'=>$map['label'],'quotation'=>$q,'customer_user'=>$c,'identity'=>!empty($map['identity']),'missing_quotation'=>$q==='','missing_customer'=>$c===''];
    }
    $keys=array_filter([$quoteMobile,$linkedMobile,complaint_normalize_mobile((string)($customer['mobile']??''))]);
    $affected=[];
    foreach($allQuotes??documents_list_quotes() as $row) {
        if(documents_is_archived($row)) continue;
        $mobile=complaint_normalize_mobile((string)($row['customer_mobile']??''));
        $linked=complaint_normalize_mobile((string)(is_array($row['customer_user_link']??null)?($row['customer_user_link']['mobile']??''):''));
        if(!in_array($mobile,$keys,true)&&!in_array($linked,$keys,true)) continue;
        $affected[]=['id'=>(string)($row['id']??''),'reference'=>(string)($row['quote_no']??$row['id']??''),'name'=>(string)($row['customer_name']??''),'mobile'=>(string)($row['customer_mobile']??''),'status'=>(string)($row['status']??''),'updated_at'=>(string)($row['updated_at']??$row['created_at']??'')];
    }
    $state=$customer===null?'missing':(!empty($customer['archived'])?'archived':($differences===[]?'resolved':'conflict'));
    $identity=isset($differences['mobile']);
    return ['state'=>$state,'label'=>ucfirst($state),'type'=>$identity?'identity':'details','differences'=>$differences,'customer'=>$customer,'affected'=>$affected,'version'=>customer_conflict_version($quote,$customer),'mobile'=>$quoteMobile];
}

function customer_conflict_lock(array $keys, callable $callback): array
{
    $dir=dirname(__DIR__).'/storage/customer-conflict-locks'; if(!is_dir($dir)) @mkdir($dir,0775,true);
    sort($keys); $handles=[];
    try { foreach(array_unique($keys) as $key) { $h=fopen($dir.'/'.hash('sha256',$key).'.lock','c'); if($h===false||!flock($h,LOCK_EX)) throw new RuntimeException('Could not obtain resolver lock.'); $handles[]=$h; } return $callback(); }
    catch(Throwable $e) { return ['ok'=>false,'state'=>'failed','message'=>$e->getMessage(),'errors'=>[$e->getMessage()]]; }
    finally { foreach(array_reverse($handles) as $h) { flock($h,LOCK_UN); fclose($h); } }
}

/** Apply a reviewed resolution. It edits only allow-listed live contact fields. */
function customer_conflict_apply(string $quoteId, array $input, array $actor=[], ?CustomerFsStore $store=null): array
{
    $reason=trim((string)($input['reason']??'')); if($reason==='') return ['ok'=>false,'state'=>'blocked','message'=>'A resolution reason is required.','errors'=>['A resolution reason is required.']];
    $decision=(string)($input['resolution']??'ignore'); $requestId=preg_replace('/[^A-Za-z0-9_.-]/','',(string)($input['request_id']??''))??'';
    $store=$store??new CustomerFsStore(); $quote=documents_get_quote($quoteId);
    if($quote===null||documents_is_archived($quote)) return ['ok'=>false,'state'=>'stale','message'=>'Quotation is missing or archived. No changes were made.','errors'=>[]];
    $initial=customer_conflict_detect($quote,$store);
    if($initial['state']==='resolved') return ['ok'=>true,'state'=>'resolved','message'=>'Conflict was already resolved.','errors'=>[],'conflict'=>$initial];
    $receiptDir=dirname(__DIR__).'/storage/customer-conflict-receipts'; if(!is_dir($receiptDir)) @mkdir($receiptDir,0775,true);
    $receipt=$requestId!==''?$receiptDir.'/'.hash('sha256',$quoteId.'|'.$requestId).'.json':'';
    if($receipt!==''&&is_file($receipt)) { $prior=json_decode((string)file_get_contents($receipt),true); if(is_array($prior)) { $prior['duplicate']=true; return $prior; } }
    $keys=[$quoteId,(string)($initial['mobile']??'')]; foreach((array)($initial['affected']??[]) as $affected) $keys[]=(string)($affected['id']??'');
    return customer_conflict_lock($keys,function() use($quoteId,$input,$actor,$store,$reason,$decision,$initial,$receipt): array {
        $quote=documents_get_quote($quoteId); if($quote===null||documents_is_archived($quote)) return ['ok'=>false,'state'=>'stale','message'=>'Quotation changed or was archived.','errors'=>[]];
        $fresh=customer_conflict_detect($quote,$store);
        if(!hash_equals((string)$fresh['version'],(string)($input['expected_version']??''))) return ['ok'=>false,'state'=>'stale','message'=>'Records changed after preview. Reload and review again.','errors'=>[],'conflict'=>$fresh];
        $customer=$fresh['customer']; $errors=[]; $changed=[];
        if($decision==='ignore') { /* audited below; warnings deliberately remain */ }
        elseif($decision==='create_missing') { $made=documents_project_create_or_link_customer($quote,$store); if(empty($made['ok'])) $errors[]=(string)($made['error']??'Creation failed.'); else { $customer=$made['customer']; $changed[]='customer_created_or_linked'; } }
        elseif($decision==='restore_archived') { if(!is_array($customer)||empty($customer['archived'])||!$store->restoreCustomer((string)$customer['mobile'])) $errors[]='Archived Customer User could not be restored.'; else $changed[]='customer_restored'; }
        elseif(in_array($decision,['identity_customer','identity_project'],true)) {
            if(empty($input['confirm_identity_migration'])||!is_array($customer)) $errors[]='Explicit identity migration/relink confirmation is required.';
            elseif($decision==='identity_customer') {
                $target=complaint_normalize_mobile((string)($customer['mobile']??''));
                $corrected=documents_correct_quotation_mobile($quoteId,$target,$reason,(string)($quote['updated_at']??$quote['created_at']??''),['id'=>(string)($actor['actor_id']??''),'name'=>(string)($actor['actor_id']??'Admin')],true,(string)($input['request_id']??''));
                if(empty($corrected['ok'])) $errors[]=(string)($corrected['error']??'Mobile correction failed.'); else $changed[]='quotation_mobile_via_issue_811';
            } else {
                $target=complaint_normalize_mobile((string)($quote['customer_mobile']??''));
                $migrated=$store->updateCustomer((string)$customer['mobile'],['mobile'=>$target]);
                if(empty($migrated['success'])) $errors[]=implode(' ',(array)($migrated['errors']??['Identity migration failed.'])); else { $changed[]='customer_identity_migrated'; $quote['customer_user_link']=array_merge((array)($quote['customer_user_link']??[]),['mobile'=>$target,'link_type'=>'relinked','linked_at'=>date('c'),'linked_by'=>['id'=>(string)($actor['actor_id']??'')]]); if(empty(documents_save_quote($quote)['ok']))$errors[]='Identity migrated, but quotation relink metadata failed.'; }
            }
        }
        elseif(in_array($decision,['customer_everywhere','project_everywhere','field_by_field','fill_missing'],true)) {
            if(!is_array($customer)) $errors[]='Customer User is missing. Create it before reconciliation.';
            else {
                $fieldChoices=is_array($input['field_source']??null)?$input['field_source']:[]; $customerPatch=[]; $quotes=documents_list_quotes();
                foreach($quotes as $row) {
                    if(documents_is_archived($row)) continue;
                    $isAffected=false; foreach($fresh['affected'] as $a) if((string)$a['id']===(string)($row['id']??'')){$isAffected=true;break;} if(!$isAffected) continue;
                    $before=$row;
                    foreach(customer_conflict_field_map() as $key=>$map) {
                        if(!empty($map['identity'])) continue; // identity/mobile changes belong to issue #811
                        $source=$decision==='customer_everywhere'?'customer':($decision==='project_everywhere'?'project':(string)($fieldChoices[$key]??''));
                        if($decision==='fill_missing') $source=trim((string)($customer[$map['customer']]??''))===''&&!empty($map['required'])?'project':'';
                        if($source==='customer') $row[$map['quote']]=$customer[$map['customer']]??'';
                        elseif($source==='project') $customerPatch[$map['customer']]=$quote[$map['quote']]??'';
                    }
                    if($row!==$before) { $row['updated_at']=date('c'); $saved=documents_save_quote($row); if(empty($saved['ok']))$errors[]='Failed to update quotation '.($row['quote_no']??$row['id']); else $changed[]='quotation:'.($row['id']??''); }
                }
                if($customerPatch!==[]) { $saved=$store->updateCustomer((string)$customer['mobile'],$customerPatch); if(empty($saved['success']))$errors[]=implode(' ',(array)($saved['errors']??['Customer update failed.'])); else $changed[]='customer_user'; }
            }
        } else $errors[]='Unknown resolution choice.';
        $reloaded=documents_get_quote($quoteId); $after=$reloaded?customer_conflict_detect($reloaded,$store):['state'=>'stale','differences'=>[]];
        $state=$decision==='ignore'?'blocked':(($after['state']==='resolved'?'resolved':($errors!==[]?($changed!==[]?'partially_resolved':'failed'):($changed!==[]?'partially_resolved':'blocked'))));
        $result=['ok'=>!in_array($state,['failed','stale'],true),'state'=>$state,'message'=>ucwords(str_replace('_',' ',$state)).($errors!==[]?': '.implode(' ',$errors):''),'errors'=>$errors,'changed'=>$changed,'conflict'=>$after];
        log_audit_event((string)($actor['actor_type']??'admin'),(string)($actor['actor_id']??'admin'),'quotation',$quoteId,'customer_conflict_resolution',['resolution'=>$decision,'reason'=>$reason,'state'=>$state,'changed'=>$changed,'errors'=>$errors]);
        if($receipt!=='') @file_put_contents($receipt,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
        return $result;
    });
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

function customer_conflict_render_resolver(array $quote, string $returnTab, ?CustomerFsStore $store=null): string
{
    $conflict=customer_conflict_detect($quote,$store); if(!in_array($conflict['state'],['conflict','missing','archived'],true)) return '';
    $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); $qid=(string)($quote['id']??''); $dialog='customer-conflict-'.$qid;
    $customer=is_array($conflict['customer']??null)?$conflict['customer']:[]; $identity=($conflict['type']??'details')==='identity';
    ob_start(); ?>
    <button class="btn" type="button" onclick="document.getElementById('<?= $e($dialog) ?>').showModal()">Resolve conflict</button>
    <dialog id="<?= $e($dialog) ?>" class="customer-conflict-resolver" aria-labelledby="<?= $e($dialog) ?>-title">
      <form method="dialog" class="resolver-close"><button class="btn secondary" aria-label="Close resolver">Close</button></form>
      <h2 id="<?= $e($dialog) ?>-title">Resolve Customer conflict · <?= $e((string)($quote['quote_no']??$qid)) ?></h2>
      <p><span class="pill warn"><?= $e(ucfirst((string)$conflict['state'])) ?></span> Review the exact quotation/project and every active record before applying. Archived quotations are excluded.</p>
      <?php if($conflict['state']==='missing'): ?><div class="banner error">No Customer User exists for this mobile. Use the issue #809 creation/linkage choice below.</div><?php endif; ?>
      <?php if($conflict['state']==='archived'): ?><div class="banner error">The matching Customer User is archived. Restore it; a duplicate will not be created.</div><?php endif; ?>
      <div class="responsive-table"><table><thead><tr><th>Field</th><th>Quotation / project</th><th>Customer User</th></tr></thead><tbody>
      <?php foreach($conflict['differences'] as $key=>$difference): ?><tr><th><?= $e((string)$difference['label']) ?></th><td><?= $e((string)($difference['quotation']?:'—')) ?></td><td><?= $e((string)($difference['customer_user']?:'—')) ?></td></tr><?php endforeach; ?>
      <?php if($conflict['differences']===[]): ?><tr><td colspan="3">Customer User is <?= $e((string)$conflict['state']) ?>; there are no comparable differing values.</td></tr><?php endif; ?>
      </tbody></table></div>
      <h3>Affected active quotations/projects</h3><ul><?php foreach($conflict['affected'] as $a): ?><li><strong><?= $e((string)$a['reference']) ?></strong> · <?= $e((string)$a['name']) ?> · <?= $e((string)$a['mobile']) ?> · <?= $e((string)$a['status']) ?></li><?php endforeach; ?></ul>
      <form method="post" class="resolver-form">
        <input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="resolve_customer_conflict"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><input type="hidden" name="expected_version" value="<?= $e((string)$conflict['version']) ?>"><input type="hidden" name="request_id" value="<?= $e(bin2hex(random_bytes(12))) ?>">
        <fieldset><legend>Resolution and before/after preview</legend>
        <?php if($conflict['state']==='missing'): ?><label><input type="radio" name="resolution" value="create_missing" required> Create missing Customer User and link it (issue #809)</label><?php elseif($conflict['state']==='archived'): ?><label><input type="radio" name="resolution" value="restore_archived" required> Restore archived Customer User instead of duplicating it</label><?php elseif(!$identity): ?>
          <label><input type="radio" name="resolution" value="customer_everywhere" required> Use Customer User details everywhere</label>
          <label><input type="radio" name="resolution" value="project_everywhere"> Use quotation/project details everywhere</label>
          <label><input type="radio" name="resolution" value="field_by_field"> Choose field by field</label>
          <div class="resolver-fields"><?php foreach($conflict['differences'] as $key=>$difference): ?><label><?= $e((string)$difference['label']) ?><select name="field_source[<?= $e((string)$key) ?>]"><option value="">Keep unchanged</option><option value="customer">Customer User → quotations/projects</option><option value="project">Quotation/project → Customer User</option></select></label><?php endforeach; ?></div>
          <label><input type="radio" name="resolution" value="fill_missing"> Fill only missing handover-required fields</label>
        <?php else: ?>
          <label><input type="radio" name="resolution" value="identity_customer" required> Use Customer User identity (applies quotation mobile through issue #811)</label>
          <label><input type="radio" name="resolution" value="identity_project"> Use quotation/project identity (migrate/relink only after confirmation)</label>
          <a class="btn secondary" href="admin-documents.php?<?= $e(http_build_query(['tab'=>$returnTab,'view'=>$qid])) ?>#mobile-correction-<?= $e(rawurlencode($qid)) ?>">Change quotation mobile through issue #811</a>
          <label><input type="checkbox" name="confirm_identity_migration" value="1"> I explicitly confirm a reviewed migrate/relink operation (no automatic identity overwrite)</label>
        <?php endif; ?>
          <label><input type="radio" name="resolution" value="ignore" <?= in_array($conflict['state'],['missing','archived'],true)?'':'required' ?>> Ignore for now (warnings remain visible)</label>
        </fieldset>
        <label><strong>Required reason</strong><textarea name="reason" required minlength="3"></textarea></label>
        <details open><summary>Before/after preview</summary><p>The selected allow-listed contact fields will change on the records listed above. IDs, references, public links, statuses, timestamps, commercial/payment data, finalized snapshots, completion snapshots, passwords and security data are preserved. The resolver reloads and recomputes after apply.</p></details>
        <button class="btn" type="submit">Apply reviewed resolution</button>
      </form>
    </dialog><?php return (string)ob_get_clean();
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
      <?php $last=is_array($_SESSION['customer_conflict_result'][$qid]??null)?$_SESSION['customer_conflict_result'][$qid]:null; if($last!==null): $lastState=(string)($last['state']??'failed'); ?><div class="banner <?= in_array($lastState,['resolved','partially_resolved'],true)?'success':'error' ?>"><strong><?= $e(ucwords(str_replace('_',' ',$lastState))) ?>:</strong> <?= $e((string)($last['message']??'')) ?><?php if(($last['errors']??[])!==[]): ?><ul><?php foreach((array)$last['errors'] as $error): ?><li><?= $e((string)$error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
      <?php if ($c === null): ?><p><span class="pill warn">Customer User: Not linked</span></p><div class="row-action-group"><?= customer_conflict_render_resolver($quote,$returnTab,$store) ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="create_or_link_customer_user"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><button class="btn secondary" type="submit">Create in Customer Users</button></form></div>
      <?php else: $h=$m['handover']; $digits=complaint_normalize_mobile((string)$c['mobile']); ?>
        <p><span class="pill">Customer User: <?= empty($c['archived'])?'Active':'Archived' ?></span> Serial: <strong><?= $e((string)($c['serial_number']??'—')) ?></strong> · Operational status: <strong><?= $e((string)($c['status']??'New')) ?></strong></p>
        <p><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>">Open Customer</a> <a class="btn secondary" href="tel:<?= $e($digits) ?>">Call</a> <a class="btn secondary" target="_blank" rel="noopener" href="https://wa.me/91<?= $e($digits) ?>">WhatsApp</a> <button class="btn secondary" type="button" data-copy-mobile="<?= $e((string)$c['mobile']) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.copyMobile)">Copy mobile</button></p>
        <p>Welcome: <strong><?= $e(ucwords((string)($c['welcome_sent_via']??'none'))) ?></strong> · <a href="admin-customers.php?view=<?= urlencode((string)$c['mobile']) ?>#welcome-actions">WhatsApp/email actions</a></p>
        <p>Handover: <strong><?= !empty($h['ready'])?'Ready':'Not generated' ?></strong><?php if(!empty($h['generated_at'])): ?> · Generated <?= $e((string)$h['generated_at']) ?><?php endif; ?><?php if(!empty($h['needs_regeneration'])): ?> · <span class="pill warn">Needs regeneration</span><?php endif; ?> · Send status: <strong><?= !empty($h['sent']['sent_at'])?'Sent '.$e((string)$h['sent']['sent_at']).' via '.$e((string)($h['sent']['channel']??'')):'Not marked sent' ?></strong></p>
        <p><?php if(!empty($h['ready'])): ?><a class="btn secondary" target="_blank" href="<?= $e((string)$h['path']) ?>">View</a> <a class="btn secondary" target="_blank" href="<?= $e((string)$h['path']) ?>" onclick="window.print()">Print</a><?php endif; ?> <a class="btn secondary" href="generate-handover.php?mobile=<?= urlencode((string)$c['mobile']) ?>"><?= !empty($h['ready'])?'Regenerate':'Generate' ?></a><?php if(!empty($h['ready'])): ?> <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="prepare_handover_whatsapp"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><button class="btn secondary" type="submit">Handover WhatsApp</button></form> <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= $e((string)($_SESSION['csrf_token']??'')) ?>"><input type="hidden" name="action" value="mark_handover_sent"><input type="hidden" name="quotation_id" value="<?= $e($qid) ?>"><input type="hidden" name="return_tab" value="<?= $e($returnTab) ?>"><select name="handover_channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="in_person">In person</option></select><button class="btn" type="submit">Mark Handover Sent</button></form><?php endif; ?></p>
        <p>Open complaints: <strong><?= (int)$m['open_complaints'] ?></strong> · <a href="admin-customers.php?view=<?= urlencode((string)$c['mobile']) ?>#complaints">Add Complaint</a> · <a href="admin-complaints.php?customer_mobile=<?= urlencode((string)$c['mobile']) ?>">View Complaints</a></p>
        <?php if($m['warnings']!==[]||!empty($c['archived'])): ?><p><?= customer_conflict_render_resolver($quote,$returnTab,$store) ?></p><?php endif; ?>
        <?php foreach($m['warnings'] as $warning): ?><div class="banner error"><strong>Important mismatch:</strong> <?= $e($warning) ?><br><span>Quotation: name <strong><?= $e((string)($quote['customer_name']??'—')) ?></strong>, mobile <strong><?= $e((string)($quote['customer_mobile']??'—')) ?></strong> · Customer User: name <strong><?= $e((string)($c['name']??'—')) ?></strong>, mobile <strong><?= $e((string)($c['mobile']??'—')) ?></strong></span><div class="row-action-group"><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>">Open Customer User</a><a class="btn secondary" href="<?= $e($quoteUrl) ?>">Review quotation</a><a class="btn secondary" href="<?= $e($correctionUrl) ?>">Change quotation mobile</a><a class="btn secondary" href="<?= $e($quoteUrl) ?>#customer-details">Review differences</a><a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>#customer-details">Complete customer details</a></div></div><?php endforeach; ?>
        <?php if(($m['missing_details']??[])!==[]): ?><div class="banner error"><strong>Handover blocker:</strong> Customer User is missing <?= $e(implode(', ', $m['missing_details'])) ?>. <a class="btn secondary" href="admin-customers.php?<?= $e(http_build_query(['view'=>(string)$c['mobile'],'return_to'=>$returnTo])) ?>#customer-details">Complete customer details</a></div><?php endif; ?>
        <?php if($detail): ?><details open><summary>Recent customer-operation activity</summary><ul><?php foreach(customer_operations_recent_activity((string)$c['mobile']) as $event): ?><li><?= $e((string)($event['timestamp']??'')) ?> — <?= $e(ucwords(str_replace('_',' ',(string)($event['action']??'')))) ?> (<?= $e((string)($event['actor_id']??'system')) ?>)</li><?php endforeach; ?></ul></details><?php endif; ?>
      <?php endif; ?>
    </section><?php return (string)ob_get_clean();
}
