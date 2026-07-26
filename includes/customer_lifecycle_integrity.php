<?php
declare(strict_types=1);

/**
 * Read-only customer lifecycle audit.  This module deliberately accepts arrays
 * rather than stores so callers and tests can prove that it cannot persist data.
 */
function lifecycle_normalize_mobile(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
    return preg_match('/^[6-9][0-9]{9}$/', $digits) ? $digits : '';
}

function lifecycle_is_archived(array $row): bool
{
    return !empty($row['archived']) || !empty($row['archived_flag']) || strtolower((string)($row['status'] ?? '')) === 'archived';
}

/** @return array{summary:array<string,int>,findings:array<int,array<string,mixed>>} */
function customer_lifecycle_integrity_check(array $leads, array $quotes, array $customers, array $views = []): array
{
    $findings = [];
    $add = static function (string $code, string $severity, string $type, string $id, string $message, array $context = []) use (&$findings): void {
        $findings[] = compact('code', 'severity', 'type', 'id', 'message', 'context');
    };
    $leadById = []; $quoteById = []; $activeCustomers = []; $allCustomers = [];
    foreach ($leads as $lead) if (($id = trim((string)($lead['id'] ?? ''))) !== '') $leadById[$id] = $lead;
    foreach ($customers as $customer) {
        $raw = (string)($customer['mobile'] ?? $customer['mobile_key'] ?? ''); $mobile = lifecycle_normalize_mobile($raw);
        if ($mobile === '') { $add('invalid_mobile', 'high', 'customer_user', (string)($customer['serial_number'] ?? $raw), 'Customer User has an invalid Indian mobile.', ['mobile'=>$raw]); continue; }
        $allCustomers[$mobile][] = $customer;
        if (!lifecycle_is_archived($customer)) $activeCustomers[$mobile][] = $customer;
    }
    foreach ($activeCustomers as $mobile => $rows) if (count($rows) > 1) $add('duplicate_active_customer_users', 'critical', 'customer_user', $mobile, 'More than one active Customer User has this normalized mobile.', ['count'=>count($rows)]);

    foreach ($quotes as $quote) {
        $id = trim((string)($quote['id'] ?? '')); if ($id !== '') $quoteById[$id] = $quote;
        $rawMobile = (string)($quote['customer_mobile'] ?? ($quote['customer_snapshot']['mobile'] ?? ''));
        $mobile = lifecycle_normalize_mobile($rawMobile);
        if ($mobile === '') $add('invalid_mobile', 'high', 'quotation', $id, 'Quotation has an invalid customer mobile.', ['mobile'=>$rawMobile]);
        $status = strtolower(trim((string)($quote['status'] ?? 'draft')));
        $active = !lifecycle_is_archived($quote) && (!array_key_exists('is_current_version', $quote) || !empty($quote['is_current_version']));
        $completion = strtolower((string)($quote['project_completion']['state'] ?? 'pending'));
        $acceptedExpected = $active && $status === 'accepted' && $completion !== 'completed';
        $completedExpected = $active && $status === 'accepted' && $completion === 'completed';
        if (isset($views['accepted'][$id]) && (bool)$views['accepted'][$id] !== $acceptedExpected) $add('incorrect_accepted_visibility', 'high', 'quotation', $id, 'Accepted Customer derived-view membership is incorrect.');
        if (isset($views['completed'][$id]) && (bool)$views['completed'][$id] !== $completedExpected) $add('incorrect_completed_visibility', 'high', 'quotation', $id, 'Completed Customer derived-view membership is incorrect.');
        if ($completion === 'completed' && ($status !== 'accepted' || !$active || empty($quote['project_completion']['completed_at']) || empty($quote['project_completion']['snapshot'])))
            $add('contradictory_completion_metadata', 'critical', 'quotation', $id, 'Completion state contradicts quotation status, activity, timestamp, or snapshot.');
        if ($completion === 'reopened' && (empty($quote['project_completion']['reopened_at']) || empty($quote['project_completion']['reopen_reason'])))
            $add('contradictory_completion_metadata', 'high', 'quotation', $id, 'Reopened project lacks reopening timestamp or reason.');
        $sourceLead = trim((string)($quote['source']['lead_id'] ?? $quote['source_lead_id'] ?? ''));
        if ($sourceLead !== '' && !isset($leadById[$sourceLead])) $add('missing_lead_reference', 'high', 'quotation', $id, 'Quotation source points to a missing lead.', ['lead_id'=>$sourceLead]);
        if ($sourceLead !== '' && isset($leadById[$sourceLead])) {
            $back = array_map('strval', (array)($leadById[$sourceLead]['quotation_ids'] ?? []));
            $single = (string)($leadById[$sourceLead]['quotation_id'] ?? '');
            if (!in_array($id, $back, true) && $single !== $id) $add('missing_lead_quotation_back_reference', 'medium', 'quotation', $id, 'Lead does not contain a reverse reference to its quotation.', ['lead_id'=>$sourceLead]);
            $sourceMobile = lifecycle_normalize_mobile((string)($quote['source']['lead_mobile'] ?? ''));
            $leadMobile = lifecycle_normalize_mobile((string)($leadById[$sourceLead]['mobile'] ?? ''));
            if ($sourceMobile !== '' && $leadMobile !== '' && $sourceMobile !== $leadMobile) $add('contradictory_source_metadata', 'high', 'quotation', $id, 'Quotation source mobile no longer agrees with its source lead.', ['lead_id'=>$sourceLead]);
        }
        $linkMobile = lifecycle_normalize_mobile((string)($quote['customer_user_link']['mobile'] ?? ''));
        if ($linkMobile !== '' && $linkMobile !== $mobile) $add('stale_customer_user_linkage', 'critical', 'quotation', $id, 'Customer User link mobile differs from quotation mobile.', ['quotation_mobile'=>$mobile,'linked_mobile'=>$linkMobile]);
        if ($active && in_array($status, ['accepted'], true)) {
            if ($mobile === '' || empty($activeCustomers[$mobile])) {
                if ($mobile !== '' && !empty($allCustomers[$mobile])) $add('archived_customer_in_active_matching', 'critical', 'quotation', $id, 'Only archived Customer Users match this active accepted quotation.', ['mobile'=>$mobile]);
                $add('missing_valid_customer_user', 'critical', 'quotation', $id, 'Accepted quotation has no valid active Customer User.', ['mobile'=>$mobile]);
            } elseif (count($activeCustomers[$mobile]) === 1) {
                $customer = $activeCustomers[$mobile][0];
                $qn = strtolower(trim(preg_replace('/\s+/', ' ', (string)($quote['customer_name'] ?? '')) ?? ''));
                $cn = strtolower(trim(preg_replace('/\s+/', ' ', (string)($customer['name'] ?? '')) ?? ''));
                if ($qn !== '' && $cn !== '' && $qn !== $cn) $add('mobile_name_conflict', 'high', 'quotation', $id, 'Quotation and Customer User names conflict for the same mobile.', ['quotation_name'=>$qn,'customer_name'=>$cn]);
            }
        }
    }
    foreach ($leads as $lead) {
        $id=(string)($lead['id']??''); $converted=strtolower((string)($lead['status']??''))==='converted'||strcasecmp((string)($lead['converted_flag']??''),'yes')===0;
        $mobile=lifecycle_normalize_mobile((string)($lead['customer_mobile_link']??$lead['mobile']??$lead['alt_mobile']??''));
        if ($converted && ($mobile==='' || empty($activeCustomers[$mobile]))) $add('converted_lead_without_customer_user','critical','lead',$id,'Converted lead has no valid active Customer User.',['mobile'=>$mobile]);
        foreach ((array)($lead['quotation_ids']??[]) as $qid) if (!isset($quoteById[(string)$qid])) $add('missing_quotation_reference','high','lead',$id,'Lead points to a missing quotation.',['quotation_id'=>(string)$qid]);
    }
    $summary=[]; foreach($findings as $finding) $summary[$finding['severity']]=($summary[$finding['severity']]??0)+1;
    return ['summary'=>$summary,'findings'=>$findings];
}
