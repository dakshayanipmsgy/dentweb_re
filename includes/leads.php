<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_log.php';

function leads_storage_path(): string
{
    $dir = __DIR__ . '/../storage/leads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/leads.json';
}

/**
 * @return array<int, array<string, mixed>>
 */
function load_all_leads(): array
{
    $path = leads_storage_path();
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }

    return array_map('lead_normalize_record', $data);
}

function save_all_leads(array $leads_array): void
{
    $path = leads_storage_path();
    $encoded = json_encode(array_values(array_map('lead_normalize_record', $leads_array)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }

    file_put_contents($path, $encoded, LOCK_EX);
}

function lead_normalize_record(array $lead): array
{
    $defaults = [
        'id' => '',
        'created_at' => '',
        'updated_at' => '',
        'name' => '',
        'mobile' => '',
        'alt_mobile' => '',
        'city' => '',
        'area_or_locality' => '',
        'state' => '',
        'interest_type' => '',
        'lead_source' => '',
        'status' => '',
        'rating' => '',
        'next_followup_date' => '',
        'next_followup_time' => '',
        'last_contacted_at' => '',
        'assigned_to_type' => '',
        'assigned_to_id' => '',
        'assigned_to_name' => '',
        'converted_flag' => '',
        'converted_date' => '',
        'not_interested_reason' => '',
        'notes' => '',
        'activity_log' => [],
        'tags' => '',
        'source_campaign_id' => '',
        'source_campaign_name' => '',
        'source_campaign_code' => '',
        'source_medium' => '',
        'archived_flag' => false,
        'archived_at' => '',
        'customer_created_flag' => false,
        'customer_mobile_link' => '',
        'customer_id_link' => '',
    ];

    $normalized = array_merge($defaults, $lead);
    if (!is_array($normalized['activity_log'])) {
        $normalized['activity_log'] = [];
    }

    return $normalized;
}

function find_lead_by_id(string $id): ?array
{
    foreach (load_all_leads() as $lead) {
        if (($lead['id'] ?? '') === $id) {
            return $lead;
        }
    }

    return null;
}

function leads_generate_id(): string
{
    return 'lead_' . bin2hex(random_bytes(6));
}

function add_lead(array $data): array
{
    $leads = load_all_leads();
    $record = lead_normalize_record($data);

    if ($record['id'] === '') {
        $record['id'] = leads_generate_id();
    }

    $now = date('Y-m-d H:i:s');
    $record['created_at'] = $record['created_at'] ?: $now;
    $record['updated_at'] = $now;

    $leads[] = $record;
    save_all_leads($leads);

    $actor = audit_current_actor();
    log_audit_event($actor['actor_type'], (string) $actor['actor_id'], 'lead', (string) $record['id'], 'lead_create', [
        'name' => $record['name'],
        'mobile' => $record['mobile'],
    ]);

    return $record;
}

/**
 * @return array{before: array<string, mixed>, after: array<string, mixed>}|null
 */
function update_lead(string $id, array $data): ?array
{
    $leads = load_all_leads();
    foreach ($leads as $index => $lead) {
        if (($lead['id'] ?? '') !== $id) {
            continue;
        }

        $before = lead_normalize_record($lead);
        $updated = lead_normalize_record(array_merge($before, $data));
        $updated['updated_at'] = date('Y-m-d H:i:s');

        $leads[$index] = $updated;
        save_all_leads($leads);

        $actor = audit_current_actor();
        $changes = audit_changed_fields($before, $updated, ['updated_at']);
        log_audit_event($actor['actor_type'], (string) $actor['actor_id'], 'lead', (string) $id, 'lead_update', $changes);

        return ['before' => $before, 'after' => $updated];
    }

    return null;
}

function get_lead_stats_for_dashboard(): array
{
    $leads = load_all_leads();
    $today = date('Y-m-d');

    $stats = [
        'total_leads' => count($leads),
        'new_leads' => 0,
        'site_visit_needed' => 0,
        'quotation_sent' => 0,
        'today_followups' => 0,
        'overdue_followups' => 0,
    ];

    foreach ($leads as $lead) {
        $status = strtolower(trim((string) ($lead['status'] ?? '')));
        $nextFollowup = trim((string) ($lead['next_followup_date'] ?? ''));

        if ($status === 'new') {
            $stats['new_leads']++;
        }

        if ($status === 'site visit needed') {
            $stats['site_visit_needed']++;
        }

        if ($status === 'quotation sent') {
            $stats['quotation_sent']++;
        }

        $isClosed = in_array($status, ['converted', 'not interested'], true);
        if ($nextFollowup !== '' && !$isClosed) {
            if ($nextFollowup === $today) {
                $stats['today_followups']++;
            } elseif ($nextFollowup < $today) {
                $stats['overdue_followups']++;
            }
        }
    }

    return $stats;
}
