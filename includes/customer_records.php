<?php
declare(strict_types=1);

final class CustomerRecordStore
{
    public const DATA_VERSION = 2;

    public const STATE_LEAD = 'lead';
    public const STATE_ONGOING = 'ongoing';
    public const STATE_INSTALLED = 'installed';

    private const STATE_SEQUENCE = [
        self::STATE_LEAD => 1,
        self::STATE_ONGOING => 2,
        self::STATE_INSTALLED => 3,
    ];

    private const REQUIRED_IMPORT_COLUMNS = [
        'full_name',
        'phone',
        'district',
        'lead_source',
        'notes',
    ];

    private string $basePath;
    private string $dataPath;
    private string $lockPath;

    private string $enquiryLogPath;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (__DIR__ . '/../storage/customer-records');
        $this->dataPath = $this->basePath . '/records.json';
        $this->lockPath = $this->basePath . '/records.lock';
        $this->enquiryLogPath = $this->basePath . '/enquiries.log';

        $this->initialiseFilesystem();
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    public function createLead(array $input): array
    {
        $payload = $this->normaliseLeadInput($input);

        return $this->writeThrough(function (array $data) use ($payload): array {
            $normalizedPhone = $payload['phone_normalized'];
            if ($normalizedPhone !== '' && isset($data['mobile_index'][$normalizedPhone])) {
                throw new RuntimeException('A customer with this phone number already exists.');
            }

            $data['last_id']++;
            $id = $data['last_id'];
            $now = $this->now();

            $record = [
                'id' => $id,
                'state' => self::STATE_LEAD,
                'active' => true,
                'full_name' => $payload['full_name'],
                'phone' => $payload['phone'],
                'phone_normalized' => $payload['phone_normalized'],
                'mobile_number' => $payload['phone'],
                'email' => $payload['email'],
                'address_line' => $payload['address_line'],
                'district' => $payload['district'],
                'pin_code' => $payload['pin_code'],
                'discom' => $payload['discom'],
                'sanctioned_load' => $payload['sanctioned_load'],
                'lead_source' => $payload['lead_source'],
                'notes' => $payload['notes'],
                'assigned_employee_id' => null,
                'assigned_installer_id' => null,
                'system_type' => null,
                'system_kwp' => null,
                'quote_number' => null,
                'quote_date' => null,
                'installation_status' => null,
                'subsidy_application_id' => null,
                'handover_date' => null,
                'warranty_until' => null,
                'complaint_allowed' => false,
                'created_at' => $now,
                'updated_at' => $now,
                'last_state_change_at' => $now,
                'state_history' => [
                    [
                        'from' => null,
                        'to' => self::STATE_LEAD,
                        'changed_at' => $now,
                    ],
                ],
            ];

            $data['records'][(string) $id] = $record;
            if ($normalizedPhone !== '') {
                $data['mobile_index'][$normalizedPhone] = $id;
            }

            return [$data, $this->prepareRecordForOutput($record)];
        });
    }

    public function updateCustomer(int $id, array $input): array
    {
        return $this->writeThrough(function (array $data) use ($id, $input): array {
            $record = $this->requireRecord($data, $id);

            $updated = $this->applyCustomerUpdate($record, $input, $data);
            $data['records'][(string) $id] = $updated;

            return [$data, $this->prepareRecordForOutput($updated)];
        });
    }

    public function delete(int $id): array
    {
        return $this->writeThrough(function (array $data) use ($id): array {
            $record = $this->requireRecord($data, $id);

            $normalizedPhone = $record['phone_normalized'] ?? null;
            if ($normalizedPhone !== null && isset($data['mobile_index'][$normalizedPhone])) {
                unset($data['mobile_index'][$normalizedPhone]);
            }

            unset($data['records'][(string) $id]);

            return [$data, ['deleted' => true]];
        });
    }

    public function deactivate(int $id): array
    {
        return $this->writeThrough(function (array $data) use ($id): array {
            $record = $this->requireRecord($data, $id);
            $record['active'] = false;
            $record['updated_at'] = $this->now();
            $data['records'][(string) $id] = $record;

            return [$data, $this->prepareRecordForOutput($record)];
        });
    }

    public function reactivate(int $id): array
    {
        return $this->writeThrough(function (array $data) use ($id): array {
            $record = $this->requireRecord($data, $id);
            $record['active'] = true;
            $record['updated_at'] = $this->now();
            $data['records'][(string) $id] = $record;

            return [$data, $this->prepareRecordForOutput($record)];
        });
    }

    public function changeState(int $id, string $targetState, array $payload = []): array
    {
        $target = $this->normaliseState($targetState);

        return $this->writeThrough(function (array $data) use ($id, $target, $payload): array {
            $record = $this->requireRecord($data, $id);
            $current = $this->normaliseState((string) ($record['state'] ?? self::STATE_LEAD));

            if ($current === $target) {
                return [$data, $this->prepareRecordForOutput($record)];
            }

            if ($this->stateRank($target) < $this->stateRank($current)) {
                throw new RuntimeException('State cannot move backwards from ' . ucfirst($current) . '.');
            }

            if ($current === self::STATE_LEAD && $target === self::STATE_ONGOING) {
                $record = $this->applyLeadToOngoing($record, $payload, $data);
            } elseif ($current === self::STATE_ONGOING && $target === self::STATE_INSTALLED) {
                $record = $this->applyOngoingToInstalled($record, $payload);
            } elseif ($current === self::STATE_LEAD && $target === self::STATE_INSTALLED) {
                throw new RuntimeException('Assign the project and move to ongoing before marking it installed.');
            } else {
                $record['state'] = $target;
            }

            if ($record['state'] !== $current) {
                $now = $this->now();
                $record['state'] = $target;
                $record['last_state_change_at'] = $now;
                $record['state_history'][] = [
                    'from' => $current,
                    'to' => $target,
                    'changed_at' => $now,
                ];
                if ($target === self::STATE_INSTALLED) {
                    $record['complaint_allowed'] = true;
                }
            }

            $record['record_type'] = $record['state'] === self::STATE_LEAD ? 'lead' : 'customer';
            $record['updated_at'] = $this->now();

            $data['records'][(string) $id] = $record;

            return [$data, $this->prepareRecordForOutput($record)];
        });
    }

    public function list(array $filters = []): array
    {
        $stateFilter = isset($filters['state']) ? $this->normaliseStateOrAll((string) $filters['state']) : 'all';
        $activeStatus = isset($filters['active_status']) ? strtolower((string) $filters['active_status']) : 'active';
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : '';
        $perPage = isset($filters['per_page']) ? max(5, min(100, (int) $filters['per_page'])) : 20;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $data = $this->readData();

        $records = [];
        foreach ($data['records'] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $record = $this->prepareRecordForOutput($raw);
            if ($stateFilter !== 'all' && $record['state'] !== $stateFilter) {
                continue;
            }
            if ($activeStatus !== 'all') {
                $isActive = (bool) ($record['active'] ?? true);
                if ($activeStatus === 'active' && !$isActive) {
                    continue;
                }
                if ($activeStatus === 'inactive' && $isActive) {
                    continue;
                }
            }
            if ($search !== '') {
                $haystack = strtolower(
                    implode(' ', [
                        (string) ($record['full_name'] ?? ''),
                        (string) ($record['phone'] ?? ''),
                        (string) ($record['district'] ?? ''),
                        (string) ($record['quote_number'] ?? ''),
                    ])
                );
                if (strpos($haystack, $search) === false) {
                    continue;
                }
            }
            $records[] = $record;
        }

        usort($records, static function (array $left, array $right): int {
            $leftUpdated = (string) ($left['updated_at'] ?? '');
            $rightUpdated = (string) ($right['updated_at'] ?? '');
            return strcmp($rightUpdated, $leftUpdated);
        });

        $total = count($records);
        $pages = (int) max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($records, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    public function leads(): array
    {
        return $this->list(['state' => self::STATE_LEAD, 'per_page' => 1000])['items'];
    }

    public function customers(): array
    {
        $data = $this->list(['state' => 'all', 'per_page' => 1000]);
        return array_values(array_filter(
            $data['items'],
            static fn (array $record): bool => $record['state'] !== self::STATE_LEAD
        ));
    }

    public function all(): array
    {
        return $this->list(['state' => 'all', 'per_page' => 2000])['items'];
    }

    public function stateSummary(): array
    {
        $data = $this->readData();

        $counts = [
            self::STATE_LEAD => 0,
            self::STATE_ONGOING => 0,
            self::STATE_INSTALLED => 0,
            'active' => 0,
            'inactive' => 0,
            'total' => 0,
        ];

        foreach ($data['records'] as $record) {
            if (!is_array($record)) {
                continue;
            }

            try {
                $state = $this->normaliseState((string) ($record['state'] ?? self::STATE_LEAD));
            } catch (Throwable $exception) {
                $state = self::STATE_LEAD;
            }

            if (isset($counts[$state])) {
                $counts[$state]++;
            }

            $isActive = (bool) ($record['active'] ?? true);
            if ($isActive) {
                $counts['active']++;
            } else {
                $counts['inactive']++;
            }

            $counts['total']++;
        }

        return $counts;
    }

    public function find(int $id): ?array
    {
        $data = $this->readData();
        $record = $data['records'][(string) $id] ?? null;

        return is_array($record) ? $this->prepareRecordForOutput($record) : null;
    }

    public function findByMobile(string $mobile): ?array
    {
        $normalized = $this->normalizePhone($mobile);
        if ($normalized === '') {
            return null;
        }

        $data = $this->readData();
        $id = $data['mobile_index'][$normalized] ?? null;
        if ($id === null) {
            return null;
        }

        $record = $data['records'][(string) $id] ?? null;
        return is_array($record) ? $this->prepareRecordForOutput($record) : null;
    }

    public function updateInstallationStatus(int $id, string $installationStatus): array
    {
        $status = $this->sanitizeNullableString($installationStatus);

        return $this->writeThrough(function (array $data) use ($id, $status): array {
            $record = $this->requireRecord($data, $id);
            $record['installation_status'] = $status;
            $record['updated_at'] = $this->now();

            $data['records'][(string) $id] = $record;

            return [$data, $this->prepareRecordForOutput($record)];
        });
    }

    public function updateInstallationStatuses(array $ids, string $installationStatus): array
    {
        $status = $this->sanitizeNullableString($installationStatus);
        $targetIds = array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, $ids),
            static fn (int $value): bool => $value > 0
        )));

        if ($targetIds === []) {
            throw new RuntimeException('Select at least one record to update.');
        }

        return $this->writeThrough(function (array $data) use ($targetIds, $status): array {
            $updated = 0;
            $missing = [];

            foreach ($targetIds as $id) {
                $key = (string) $id;
                if (!isset($data['records'][$key]) || !is_array($data['records'][$key])) {
                    $missing[] = $id;
                    continue;
                }

                $data['records'][$key]['installation_status'] = $status;
                $data['records'][$key]['updated_at'] = $this->now();
                $updated++;
            }

            return [$data, [
                'updated' => $updated,
                'missing' => $missing,
                'moved_to_customers' => 0,
                'moved_to_leads' => 0,
            ]];
        });
    }

    public function templateColumns(string $type): array
    {
        $type = strtolower(trim($type));
        if ($type === 'customers' || $type === 'customer') {
            return [
                'Full Name',
                'Phone',
                'Email',
                'District',
                'State',
                'Assigned Employee',
                'Assigned Installer',
                'System Type',
                'System kWp',
                'Quote Number',
                'Quote Date',
                'Installation Status',
                'Handover Date',
                'Warranty Until',
                'Lead Source',
                'Notes',
            ];
        }

        return [
            'Full Name',
            'Phone',
            'District',
            'Lead Source',
            'Notes',
        ];
    }

    public function importLeadCsv(string $csvContents): array
    {
        $rows = $this->parseLeadCsv($csvContents);

        return $this->writeThrough(function (array $data) use ($rows): array {
            $processed = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $processed++;
                $payload = $this->normaliseLeadInput($row);
                $normalizedPhone = $payload['phone_normalized'];
                $existingId = $normalizedPhone !== '' ? ($data['mobile_index'][$normalizedPhone] ?? null) : null;
                if ($existingId !== null) {
                    $record = $this->requireRecord($data, (int) $existingId);
                    if ($record['state'] !== self::STATE_LEAD) {
                        $skipped++;
                        continue;
                    }

                    $record['full_name'] = $payload['full_name'];
                    $record['phone'] = $payload['phone'];
                    $record['phone_normalized'] = $payload['phone_normalized'];
                    $record['mobile_number'] = $payload['phone'];
                    $record['district'] = $payload['district'];
                    $record['lead_source'] = $payload['lead_source'];
                    $record['notes'] = $payload['notes'];
                    $record['updated_at'] = $this->now();
                    $data['records'][(string) $existingId] = $record;
                    $updated++;
                    continue;
                }

                $data['last_id']++;
                $id = $data['last_id'];
                $now = $this->now();

                $record = [
                    'id' => $id,
                    'state' => self::STATE_LEAD,
                    'active' => true,
                    'full_name' => $payload['full_name'],
                    'phone' => $payload['phone'],
                    'phone_normalized' => $payload['phone_normalized'],
                    'mobile_number' => $payload['phone'],
                    'email' => $payload['email'],
                    'address_line' => $payload['address_line'],
                    'district' => $payload['district'],
                    'pin_code' => $payload['pin_code'],
                    'discom' => $payload['discom'],
                    'sanctioned_load' => $payload['sanctioned_load'],
                    'lead_source' => $payload['lead_source'],
                    'notes' => $payload['notes'],
                    'assigned_employee_id' => null,
                    'assigned_installer_id' => null,
                    'system_type' => null,
                    'system_kwp' => null,
                    'quote_number' => null,
                    'quote_date' => null,
                    'installation_status' => null,
                    'subsidy_application_id' => null,
                    'handover_date' => null,
                    'warranty_until' => null,
                    'complaint_allowed' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_state_change_at' => $now,
                    'state_history' => [
                        [
                            'from' => null,
                            'to' => self::STATE_LEAD,
                            'changed_at' => $now,
                        ],
                    ],
                ];

                $data['records'][(string) $id] = $record;
                if ($payload['phone_normalized'] !== '') {
                    $data['mobile_index'][$payload['phone_normalized']] = $id;
                }
                $created++;
            }

            return [$data, [
                'processed' => $processed,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]];
        });
    }

    public function exportCsv(?string $stateFilter = null): string
    {
        $list = $this->list([
            'state' => $stateFilter ?? 'all',
            'per_page' => 5000,
        ]);

        $rows = [];
        $rows[] = [
            'Full Name',
            'Phone',
            'Email',
            'District',
            'State',
            'Assigned Employee',
            'Assigned Installer',
            'System Type',
            'System kWp',
            'Quote Number',
            'Quote Date',
            'Installation Status',
            'Handover Date',
            'Warranty Until',
            'Lead Source',
            'Notes',
            'Updated At',
        ];

        foreach ($list['items'] as $record) {
            $rows[] = [
                (string) ($record['full_name'] ?? ''),
                (string) ($record['phone'] ?? ''),
                (string) ($record['email'] ?? ''),
                (string) ($record['district'] ?? ''),
                (string) ($record['state'] ?? ''),
                (string) ($record['assigned_employee_id'] ?? ''),
                (string) ($record['assigned_installer_id'] ?? ''),
                (string) ($record['system_type'] ?? ''),
                $record['system_kwp'] !== null ? (string) $record['system_kwp'] : '',
                (string) ($record['quote_number'] ?? ''),
                (string) ($record['quote_date'] ?? ''),
                (string) ($record['installation_status'] ?? ''),
                (string) ($record['handover_date'] ?? ''),
                (string) ($record['warranty_until'] ?? ''),
                (string) ($record['lead_source'] ?? ''),
                (string) ($record['notes'] ?? ''),
                (string) ($record['updated_at'] ?? ''),
            ];
        }

        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary stream for CSV export.');
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    private function applyLeadToOngoing(array $record, array $payload, array &$data): array
    {
        $employeeId = isset($payload['assigned_employee_id']) ? (int) $payload['assigned_employee_id'] : 0;
        if ($employeeId <= 0) {
            throw new RuntimeException('Assign a responsible employee before moving the lead to ongoing.');
        }

        $record['assigned_employee_id'] = $employeeId;
        $record['assigned_installer_id'] = isset($payload['assigned_installer_id']) && $payload['assigned_installer_id'] !== ''
            ? max(0, (int) $payload['assigned_installer_id'])
            : null;

        $systemType = $this->sanitizeNullableString($payload['system_type'] ?? '');
        if ($systemType === null) {
            throw new RuntimeException('Specify the proposed system type.');
        }
        $systemKwp = $this->normaliseSystemCapacity($payload['system_kwp'] ?? null);
        if ($systemKwp === null) {
            throw new RuntimeException('Provide the tentative system size in kWp.');
        }

        $record['system_type'] = $systemType;
        $record['system_kwp'] = $systemKwp;
        $record['quote_number'] = $this->sanitizeNullableString($payload['quote_number'] ?? '');
        $record['quote_date'] = $this->sanitizeNullableString($payload['quote_date'] ?? '');
        $record['installation_status'] = $this->sanitizeNullableString($payload['installation_status'] ?? 'structure');
        $record['notes'] = $payload['notes'] ?? $record['notes'];
        $record['state'] = self::STATE_ONGOING;
        $record['record_type'] = 'customer';

        if ($record['phone_normalized'] !== '') {
            $data['mobile_index'][$record['phone_normalized']] = $record['id'];
        }

        return $record;
    }

    private function applyOngoingToInstalled(array $record, array $payload): array
    {
        $handover = $this->sanitizeNullableString($payload['handover_date'] ?? '');
        if ($handover === null) {
            throw new RuntimeException('Enter the project handover date.');
        }

        $record['handover_date'] = $handover;
        $record['warranty_until'] = $this->sanitizeNullableString($payload['warranty_until'] ?? '') ?? $record['warranty_until'];
        $record['installation_status'] = $this->sanitizeNullableString($payload['installation_status'] ?? 'installed');
        $record['complaint_allowed'] = true;
        $record['state'] = self::STATE_INSTALLED;
        $record['record_type'] = 'customer';

        return $record;
    }

    private function applyCustomerUpdate(array $record, array $input, array &$data): array
    {
        $fullName = $this->sanitizeName($input['full_name'] ?? ($record['full_name'] ?? ''));
        if ($fullName === '') {
            throw new RuntimeException('Full name is required.');
        }

        $record['full_name'] = $fullName;

        $email = $this->sanitizeNullableString($input['email'] ?? ($record['email'] ?? ''));
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Provide a valid email address.');
        }
        $record['email'] = $email !== '' ? $email : null;

        $phone = $this->sanitizePhoneForStorage($input['phone'] ?? ($record['phone'] ?? ''));
        $normalizedPhone = $this->normalizePhone($phone);
        if ($phone === '') {
            throw new RuntimeException('Phone number is required.');
        }

        if ($normalizedPhone !== $record['phone_normalized']) {
            if ($normalizedPhone !== '' && isset($data['mobile_index'][$normalizedPhone])) {
                throw new RuntimeException('Another customer already uses this phone number.');
            }

            if ($record['phone_normalized'] !== '' && isset($data['mobile_index'][$record['phone_normalized']])) {
                unset($data['mobile_index'][$record['phone_normalized']]);
            }

            if ($normalizedPhone !== '') {
                $data['mobile_index'][$normalizedPhone] = $record['id'];
            }
        }

        $record['phone'] = $phone;
        $record['phone_normalized'] = $normalizedPhone;
        $record['mobile_number'] = $phone;

        $record['address_line'] = $this->sanitizeNullableString($input['address_line'] ?? ($record['address_line'] ?? ''));
        $record['district'] = $this->sanitizeNullableString($input['district'] ?? ($record['district'] ?? '')) ?? '';
        $record['pin_code'] = $this->sanitizeNullableString($input['pin_code'] ?? ($record['pin_code'] ?? ''));
        $record['discom'] = $this->sanitizeNullableString($input['discom'] ?? ($record['discom'] ?? ''));
        $record['sanctioned_load'] = $this->sanitizeNullableString($input['sanctioned_load'] ?? ($record['sanctioned_load'] ?? ''));
        $record['lead_source'] = $this->sanitizeNullableString($input['lead_source'] ?? ($record['lead_source'] ?? ''));
        $record['notes'] = $this->sanitizeNullableString($input['notes'] ?? ($record['notes'] ?? ''));
        $record['quote_number'] = $this->sanitizeNullableString($input['quote_number'] ?? ($record['quote_number'] ?? ''));
        $record['quote_date'] = $this->sanitizeNullableString($input['quote_date'] ?? ($record['quote_date'] ?? ''));
        $record['installation_status'] = $this->sanitizeNullableString($input['installation_status'] ?? ($record['installation_status'] ?? ''));
        $record['subsidy_application_id'] = $this->sanitizeNullableString($input['subsidy_application_id'] ?? ($record['subsidy_application_id'] ?? ''));
        $record['handover_date'] = $this->sanitizeNullableString($input['handover_date'] ?? ($record['handover_date'] ?? ''));
        $record['warranty_until'] = $this->sanitizeNullableString($input['warranty_until'] ?? ($record['warranty_until'] ?? ''));

        $employeeId = isset($input['assigned_employee_id']) && $input['assigned_employee_id'] !== ''
            ? max(0, (int) $input['assigned_employee_id'])
            : null;
        $installerId = isset($input['assigned_installer_id']) && $input['assigned_installer_id'] !== ''
            ? max(0, (int) $input['assigned_installer_id'])
            : null;

        $record['assigned_employee_id'] = $employeeId;
        $record['assigned_installer_id'] = $installerId;
        $record['system_type'] = $this->sanitizeNullableString($input['system_type'] ?? ($record['system_type'] ?? ''));
        $record['system_kwp'] = $this->normaliseSystemCapacity($input['system_kwp'] ?? ($record['system_kwp'] ?? null));

        $complaintAllowed = isset($input['complaint_allowed']) ? (bool) $input['complaint_allowed'] : $record['complaint_allowed'];
        if ($record['state'] !== self::STATE_INSTALLED) {
            $complaintAllowed = false;
        }
        $record['complaint_allowed'] = $complaintAllowed;

        $record['updated_at'] = $this->now();

        return $record;
    }

    private function normaliseLeadInput(array $input): array
    {
        $fullName = $this->sanitizeName($input['full_name'] ?? '');
        if ($fullName === '') {
            throw new RuntimeException('Full name is required.');
        }

        $phone = $this->sanitizePhoneForStorage($input['phone'] ?? '');
        $normalizedPhone = $this->normalizePhone($phone);
        if ($phone === '' || strlen($normalizedPhone) < 10) {
            throw new RuntimeException('Enter a 10-digit phone number.');
        }

        $district = $this->sanitizeNullableString($input['district'] ?? '') ?? '';
        if ($district === '') {
            throw new RuntimeException('District is required for lead intake.');
        }

        return [
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_normalized' => $normalizedPhone,
            'email' => $this->sanitizeNullableString($input['email'] ?? '') ?? null,
            'address_line' => $this->sanitizeNullableString($input['address_line'] ?? '') ?? null,
            'district' => $district,
            'pin_code' => $this->sanitizeNullableString($input['pin_code'] ?? '') ?? null,
            'discom' => $this->sanitizeNullableString($input['discom'] ?? '') ?? null,
            'sanctioned_load' => $this->sanitizeNullableString($input['sanctioned_load'] ?? '') ?? null,
            'lead_source' => $this->sanitizeNullableString($input['lead_source'] ?? '') ?? null,
            'notes' => $this->sanitizeNullableString($input['notes'] ?? '') ?? null,
        ];
    }

    private function parseLeadCsv(string $contents): array
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            throw new RuntimeException('Unable to buffer CSV import.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $header = null;
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(static fn ($value) => strtolower(trim((string) $value)), $row);
                if ($header !== self::REQUIRED_IMPORT_COLUMNS) {
                    fclose($handle);
                    throw new RuntimeException('CSV header must match: ' . implode(', ', self::REQUIRED_IMPORT_COLUMNS) . '.');
                }
                continue;
            }

            if ($row === [null] || $row === []) {
                continue;
            }

            $mapped = [];
            foreach ($header as $index => $column) {
                $mapped[$column] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }
            $rows[] = $mapped;
        }

        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('The uploaded CSV file did not contain any data rows.');
        }

        return $rows;
    }

    private function prepareRecordForOutput(array $record): array
    {
        $state = $this->normaliseState((string) ($record['state'] ?? self::STATE_LEAD));
        $record['state'] = $state;
        $record['active'] = (bool) ($record['active'] ?? true);
        $record['record_type'] = $state === self::STATE_LEAD ? 'lead' : 'customer';
        $record['complaint_allowed'] = (bool) ($record['complaint_allowed'] ?? false);
        $record['system_kwp'] = isset($record['system_kwp']) && $record['system_kwp'] !== null
            ? (float) $record['system_kwp']
            : null;
        $record['assigned_employee_id'] = isset($record['assigned_employee_id']) && $record['assigned_employee_id'] !== ''
            ? (int) $record['assigned_employee_id']
            : null;
        $record['assigned_installer_id'] = isset($record['assigned_installer_id']) && $record['assigned_installer_id'] !== ''
            ? (int) $record['assigned_installer_id']
            : null;
        $record['phone'] = $this->sanitizePhoneForStorage($record['phone'] ?? ($record['mobile_number'] ?? ''));
        $record['mobile_number'] = $record['phone'];
        $record['phone_normalized'] = $this->normalizePhone($record['phone']);
        $record['full_name'] = $this->sanitizeName($record['full_name'] ?? '');
        $record['district'] = $this->sanitizeNullableString($record['district'] ?? '') ?? '';
        $record['quote_number'] = $this->sanitizeNullableString($record['quote_number'] ?? '') ?? null;
        $record['quote_date'] = $this->sanitizeNullableString($record['quote_date'] ?? '') ?? null;
        $record['installation_status'] = $this->sanitizeNullableString($record['installation_status'] ?? '') ?? null;
        $record['lead_source'] = $this->sanitizeNullableString($record['lead_source'] ?? '') ?? null;
        $record['notes'] = $this->sanitizeNullableString($record['notes'] ?? '') ?? null;
        $record['last_state_change_at'] = $this->sanitizeNullableString($record['last_state_change_at'] ?? '') ?? null;

        return $record;
    }

    private function requireRecord(array $data, int $id): array
    {
        $record = $data['records'][(string) $id] ?? null;
        if (!is_array($record)) {
            throw new RuntimeException('Customer record not found.');
        }

        return $record;
    }

    private function normaliseState(string $state): string
    {
        $normalized = strtolower(trim($state));
        if (!isset(self::STATE_SEQUENCE[$normalized])) {
            throw new RuntimeException('Unsupported customer state.');
        }

        return $normalized;
    }

    private function normaliseStateOrAll(string $state): string
    {
        $normalized = strtolower(trim($state));
        if ($normalized === '' || $normalized === 'all') {
            return 'all';
        }

        return $this->normaliseState($normalized);
    }

    private function stateRank(string $state): int
    {
        return self::STATE_SEQUENCE[$state] ?? 0;
    }

    private function normaliseSystemCapacity($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('System kWp must be a number.');
        }

        $number = (float) $value;
        if ($number <= 0) {
            throw new RuntimeException('System kWp must be greater than zero.');
        }

        return round($number, 2);
    }

    private function sanitizeName(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function sanitizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function sanitizePhoneForStorage($value): string
    {
        $digits = $this->normalizePhone($value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) > 10 && str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    private function normalizePhone($value): string
    {
        if ($value === null) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits ?? '';
    }

    private function readData(): array
    {
        if (!is_file($this->dataPath)) {
            return $this->defaultData();
        }

        $contents = file_get_contents($this->dataPath);
        if ($contents === false || trim($contents) === '') {
            return $this->defaultData();
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('CustomerRecordStore: failed to decode storage, recreating. ' . $exception->getMessage());
            return $this->defaultData();
        }

        if (!is_array($decoded)) {
            return $this->defaultData();
        }

        $decoded = $this->upgradeData($decoded);

        return $decoded;
    }

    private function upgradeData(array $data): array
    {
        $defaults = $this->defaultData();
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $default;
            }
        }

        $version = (int) ($data['version'] ?? 1);
        if ($version < self::DATA_VERSION) {
            foreach ($data['records'] as $key => $record) {
                if (!is_array($record)) {
                    unset($data['records'][$key]);
                    continue;
                }

                $record['id'] = isset($record['id']) ? (int) $record['id'] : (int) $key;
                $record['active'] = (bool) ($record['active'] ?? true);
                $record['full_name'] = $this->sanitizeName($record['full_name'] ?? '');
                $record['phone'] = $this->sanitizePhoneForStorage($record['phone'] ?? ($record['mobile_number'] ?? ''));
                $record['phone_normalized'] = $this->normalizePhone($record['phone']);
                $record['mobile_number'] = $record['phone'];
                $record['district'] = $this->sanitizeNullableString($record['district'] ?? '') ?? '';
                $record['state'] = $this->deriveStateFromLegacy($record);
                $record['record_type'] = $record['state'] === self::STATE_LEAD ? 'lead' : 'customer';
                $record['complaint_allowed'] = $record['state'] === self::STATE_INSTALLED;
                $record['last_state_change_at'] = $record['last_state_change_at'] ?? ($record['updated_at'] ?? ($record['created_at'] ?? $this->now()));
                if (!isset($record['state_history']) || !is_array($record['state_history'])) {
                    $record['state_history'] = [[
                        'from' => null,
                        'to' => $record['state'],
                        'changed_at' => $record['last_state_change_at'],
                    ]];
                }

                $data['records'][(string) $record['id']] = $record;
            }

            $version = self::DATA_VERSION;
        }

        $data['version'] = $version;

        $mobileIndex = [];
        $maxId = 0;
        foreach ($data['records'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $maxId = max($maxId, (int) ($record['id'] ?? 0));
            $phone = $this->normalizePhone($record['phone'] ?? ($record['mobile_number'] ?? ''));
            if ($phone !== '') {
                $mobileIndex[$phone] = (int) ($record['id'] ?? 0);
            }
        }

        $data['mobile_index'] = $mobileIndex;
        $data['last_id'] = max($data['last_id'], $maxId);

        return $data;
    }

    private function deriveStateFromLegacy(array $record): string
    {
        if (isset($record['state'])) {
            try {
                return $this->normaliseState((string) $record['state']);
            } catch (Throwable $exception) {
                // fall through to legacy detection
            }
        }

        $handover = $this->sanitizeNullableString($record['handover_date'] ?? '') ?? '';
        if ($handover !== '') {
            return self::STATE_INSTALLED;
        }

        $installation = strtolower((string) ($record['installation_status'] ?? ''));
        if ($installation !== '' && $installation !== 'new' && $installation !== 'pending') {
            return self::STATE_ONGOING;
        }

        $recordType = strtolower((string) ($record['record_type'] ?? ''));
        if ($recordType === 'customer') {
            return self::STATE_ONGOING;
        }

        return self::STATE_LEAD;
    }

    private function writeThrough(callable $callback)
    {
        $this->acquireLock();

        try {
            $data = $this->readData();
            [$newData, $result] = $callback($data);
            if (!is_array($newData)) {
                throw new RuntimeException('CustomerRecordStore callback must return updated data.');
            }
            $this->writeData($newData);

            return $result;
        } finally {
            $this->releaseLock();
        }
    }

    private function writeData(array $data): void
    {
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode customer records.');
        }

        $temp = tempnam($this->basePath, 'cust');
        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary file for customer records.');
        }

        if (file_put_contents($temp, $payload, LOCK_EX) === false) {
            @unlink($temp);
            throw new RuntimeException('Unable to persist customer records.');
        }

        if (!@rename($temp, $this->dataPath)) {
            @unlink($temp);
            throw new RuntimeException('Failed to commit customer records update.');
        }
    }

    private function initialiseFilesystem(): void
    {
        if (!is_dir($this->basePath) && !@mkdir($this->basePath, 0775, true) && !is_dir($this->basePath)) {
            throw new RuntimeException('Unable to create customer storage directory.');
        }

        if (!is_file($this->dataPath)) {
            $this->writeData($this->defaultData());
        }
    }

    private function defaultData(): array
    {
        return [
            'version' => self::DATA_VERSION,
            'last_id' => 0,
            'records' => [],
            'mobile_index' => [],
        ];
    }

    private function acquireLock(): void
    {
        if ($this->lockHandle !== null) {
            return;
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open customer record lock.');
        }

        if (flock($handle, LOCK_EX) !== true) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire customer record lock.');
        }

        $this->lockHandle = $handle;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    private function now(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        return $now->format('Y-m-d H:i:s');
    }
}

function customer_record_store(?string $basePath = null): CustomerRecordStore
{
    static $store = null;

    if ($basePath !== null) {
        return new CustomerRecordStore($basePath);
    }

    if (!$store instanceof CustomerRecordStore) {
        $store = new CustomerRecordStore();
    }

    return $store;
}

function customer_records_leads(): array
{
    return customer_record_store()->leads();
}

function customer_records_customers(): array
{
    return customer_record_store()->customers();
}

function customer_records_can_login(string $identifier): bool
{
    $record = customer_record_store()->findByMobile($identifier);
    return $record !== null && $record['state'] !== CustomerRecordStore::STATE_LEAD;
}

function customer_records_template_columns(string $type): array
{
    return customer_record_store()->templateColumns($type);
}
