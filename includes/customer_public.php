<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_records.php';
require_once __DIR__ . '/customer_admin.php';

function public_customer_store(): CustomerFsStore
{
    static $store = null;

    if (!$store instanceof CustomerFsStore) {
        $store = new CustomerFsStore();
    }

    return $store;
}

function public_normalize_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    if ($digits === null) {
        return '';
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) !== 10) {
        return '';
    }

    return $digits;
}

function public_enquiry_submit(array $input): array
{
    $store = customer_record_store();
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

    $fullName = trim((string) ($input['name'] ?? ''));
    $mobileRaw = (string) ($input['mobile'] ?? '');
    $mobileNormalized = public_normalize_mobile($mobileRaw);
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $discom = trim((string) ($input['discom'] ?? ''));
    $projectType = trim((string) ($input['project_type'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    if ($mobileNormalized === '') {
        throw new RuntimeException('Enter a valid 10-digit mobile number.');
    }

    $existing = $store->findByMobile($mobileNormalized);
    if ($existing === null) {
        $payload = [
            'full_name' => $fullName !== '' ? $fullName : 'Lead',
            'phone' => $mobileNormalized,
            'district' => $city,
            'state' => $state,
            'discom' => $discom,
            'lead_source' => $projectType !== '' ? $projectType : 'website enquiry',
            'notes' => $notes,
        ];
        $created = $store->createLead($payload);
        $created['created'] = true;
        $created['updated'] = false;

        return $created;
    }

    $noteLine = $notes !== ''
        ? sprintf('[%s] %s', $now, $notes)
        : sprintf('[%s] Website enquiry recorded', $now);

    $updatedNotes = trim((string) ($existing['notes'] ?? ''));
    $updatedNotes = $updatedNotes === '' ? $noteLine : ($updatedNotes . "\n" . $noteLine);

    $updatePayload = [
        'full_name' => $fullName !== '' ? $fullName : ($existing['full_name'] ?? 'Lead'),
        'district' => $city !== '' ? $city : ($existing['district'] ?? ''),
        'state' => $state !== '' ? $state : ($existing['state'] ?? ''),
        'discom' => $discom !== '' ? $discom : ($existing['discom'] ?? ''),
        'notes' => $updatedNotes,
    ];

    if ($projectType !== '') {
        $updatePayload['lead_source'] = $projectType;
    }

    $updated = $store->updateCustomer((int) $existing['id'], $updatePayload);
    $updated['created'] = false;
    $updated['updated'] = true;

    return $updated;
}

function public_customer_status(string $mobile): ?array
{
    $store = customer_record_store();
    $record = $store->findByMobile($mobile);
    if (!$record) {
        return null;
    }

    return $record;
}
