<?php
declare(strict_types=1);

/**
 * Lightweight file-based storage for admin-managed customers.
 */
final class CustomerFsStore
{
    private const DEFAULT_STATUS = 'New';
    private const STATUSES = [
        'New',
        'Survey Pending',
        'Survey Done',
        'Installation Pending',
        'Installation In Progress',
        'Completed',
    ];

    private string $basePath;
    private string $dataPath;
    private string $lockPath;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (__DIR__ . '/../storage/customer-users');
        $this->dataPath = $this->basePath . '/customers.json';
        $this->lockPath = $this->basePath . '/customers.lock';
        $this->initialiseFilesystem();
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(): array
    {
        $data = $this->readData();
        $customers = array_map([$this, 'hydrateCustomer'], $data['customers'] ?? []);
        usort($customers, static function (array $left, array $right): int {
            return strcmp($left['name'] ?? '', $right['name'] ?? '');
        });

        return $customers;
    }

    public function findByMobile(string $mobile): ?array
    {
        $mobileKey = $this->normaliseMobile($mobile);
        if ($mobileKey === '') {
            return null;
        }

        $data = $this->readData();
        foreach ($data['customers'] as $customer) {
            if (($customer['mobile_key'] ?? '') === $mobileKey) {
                return $this->hydrateCustomer($customer);
            }
        }

        return null;
    }

    /**
     * @return array{success:bool, errors:array<int, string>, customer:array<string, mixed>|null}
     */
    public function addCustomer(array $input): array
    {
        $payload = $this->normaliseInput($input);
        $errors = $this->validate($payload, null);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'customer' => null];
        }

        return $this->writeThrough(function (array $data) use ($payload): array {
            foreach ($data['customers'] as $customer) {
                if (($customer['mobile_key'] ?? '') === $payload['mobile_key']) {
                    throw new RuntimeException('Mobile number already exists.');
                }
            }

            $payload['created_at'] = $payload['created_at'] ?? $this->now();
            $payload['updated_at'] = $this->now();
            $payload = $this->ensureSerialNumber($payload, $data);
            $data['customers'][] = $payload;

            return [$data, ['success' => true, 'errors' => [], 'customer' => $payload]];
        }, ['success' => false, 'errors' => ['Could not write customer data.'], 'customer' => null]);
    }

    /**
     * @return array{success:bool, errors:array<int, string>, customer:array<string, mixed>|null}
     */
    public function updateCustomer(string $mobile, array $input): array
    {
        $existing = $this->findByMobile($mobile);
        if ($existing === null) {
            return ['success' => false, 'errors' => ['Customer not found.'], 'customer' => null];
        }

        $payload = $this->normaliseInput($input, $existing);
        $errors = $this->validate($payload, $existing);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'customer' => null];
        }

        $mobileKey = $existing['mobile_key'];

        return $this->writeThrough(function (array $data) use ($mobileKey, $payload): array {
            foreach ($data['customers'] as $index => $customer) {
                if (($customer['mobile_key'] ?? '') === $mobileKey) {
                    $payload['created_at'] = $customer['created_at'] ?? $this->now();
                    $payload['updated_at'] = $this->now();
                    $payload = $this->ensureSerialNumber($payload, $data);
                    $data['customers'][$index] = $payload;
                    break;
                }
            }

            return [$data, ['success' => true, 'errors' => [], 'customer' => $payload]];
        }, ['success' => false, 'errors' => ['Could not update customer.'], 'customer' => null]);
    }

    private function normaliseInput(array $input, ?array $existing = null): array
    {
        $mobile = trim((string) ($input['mobile'] ?? ($existing['mobile'] ?? '')));
        $mobileKey = $this->normaliseMobile($mobile);

        $overrides = $this->normaliseOverrides($input['handover_overrides'] ?? ($existing['handover_overrides'] ?? []));
        $handoverDocumentPath = trim((string) ($input['handover_document_path'] ?? ($existing['handover_document_path'] ?? '')));
        $handoverHtmlPath = trim((string) ($input['handover_html_path'] ?? ($existing['handover_html_path'] ?? $handoverDocumentPath)));
        $handoverPdfPath = trim((string) ($input['handover_pdf_path'] ?? ($existing['handover_pdf_path'] ?? '')));
        $handoverGeneratedAt = trim((string) ($input['handover_generated_at'] ?? ($existing['handover_generated_at'] ?? '')));

        return [
            'mobile' => $mobile,
            'mobile_key' => $mobileKey,
            'name' => trim((string) ($input['name'] ?? ($existing['name'] ?? ''))),
            'serial_number' => trim((string) ($input['serial_number'] ?? ($existing['serial_number'] ?? ''))),
            'customer_type' => trim((string) ($input['customer_type'] ?? ($existing['customer_type'] ?? ''))),
            'address' => trim((string) ($input['address'] ?? ($existing['address'] ?? ''))),
            'city' => trim((string) ($input['city'] ?? ($existing['city'] ?? ''))),
            'district' => trim((string) ($input['district'] ?? ($existing['district'] ?? ''))),
            'pin_code' => trim((string) ($input['pin_code'] ?? ($existing['pin_code'] ?? ''))),
            'state' => trim((string) ($input['state'] ?? ($existing['state'] ?? ''))),
            'meter_number' => trim((string) ($input['meter_number'] ?? ($existing['meter_number'] ?? ''))),
            'meter_serial_number' => trim((string) ($input['meter_serial_number'] ?? ($existing['meter_serial_number'] ?? ''))),
            'jbvnl_account_number' => trim((string) ($input['jbvnl_account_number'] ?? ($existing['jbvnl_account_number'] ?? ''))),
            'application_id' => trim((string) ($input['application_id'] ?? ($existing['application_id'] ?? ''))),
            'application_submitted_date' => trim((string) ($input['application_submitted_date'] ?? ($existing['application_submitted_date'] ?? ''))),
            'sanction_load_kwp' => trim((string) ($input['sanction_load_kwp'] ?? ($existing['sanction_load_kwp'] ?? ''))),
            'installed_pv_module_capacity_kwp' => trim((string) ($input['installed_pv_module_capacity_kwp'] ?? ($existing['installed_pv_module_capacity_kwp'] ?? ''))),
            'circle_name' => trim((string) ($input['circle_name'] ?? ($existing['circle_name'] ?? ''))),
            'division_name' => trim((string) ($input['division_name'] ?? ($existing['division_name'] ?? ''))),
            'sub_division_name' => trim((string) ($input['sub_division_name'] ?? ($existing['sub_division_name'] ?? ''))),
            'loan_taken' => trim((string) ($input['loan_taken'] ?? ($existing['loan_taken'] ?? ''))),
            'loan_application_date' => trim((string) ($input['loan_application_date'] ?? ($existing['loan_application_date'] ?? ''))),
            'solar_plant_installation_date' => trim((string) ($input['solar_plant_installation_date'] ?? ($existing['solar_plant_installation_date'] ?? ''))),
            'subsidy_amount_rs' => trim((string) ($input['subsidy_amount_rs'] ?? ($existing['subsidy_amount_rs'] ?? ''))),
            'subsidy_disbursed_date' => trim((string) ($input['subsidy_disbursed_date'] ?? ($existing['subsidy_disbursed_date'] ?? ''))),
            'complaints_raised' => trim((string) ($input['complaints_raised'] ?? ($existing['complaints_raised'] ?? 'No'))),
            'status' => $this->normaliseStatus((string) ($input['status'] ?? ($existing['status'] ?? ''))),
            'welcome_sent_via' => $this->normaliseWelcomeChannel((string) ($input['welcome_sent_via'] ?? ($existing['welcome_sent_via'] ?? 'none'))),
            'handover_overrides' => $overrides,
            'handover_document_path' => $handoverDocumentPath,
            'handover_html_path' => $handoverHtmlPath,
            'handover_pdf_path' => $handoverPdfPath,
            'handover_generated_at' => $handoverGeneratedAt,
            'password_hash' => isset($input['password_hash']) && is_string($input['password_hash'])
                ? trim($input['password_hash'])
                : ($existing['password_hash'] ?? null),
            'created_from_quote_id' => trim((string) ($input['created_from_quote_id'] ?? ($existing['created_from_quote_id'] ?? ''))),
            'created_from_quote_no' => trim((string) ($input['created_from_quote_no'] ?? ($existing['created_from_quote_no'] ?? ''))),
            'created_at' => $existing['created_at'] ?? null,
            'updated_at' => $existing['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function validate(array $payload, ?array $existing): array
    {
        $errors = [];
        if ($payload['mobile'] === '') {
            $errors[] = 'Mobile number is required.';
        }
        if ($payload['mobile_key'] === '') {
            $errors[] = 'Mobile number is invalid.';
        }
        if ($payload['name'] === '') {
            $errors[] = 'Customer name is required.';
        }
        if (!in_array($payload['status'], self::STATUSES, true)) {
            $errors[] = 'Invalid status selected.';
        }

        $incomingKey = $payload['mobile_key'];
        $data = $this->readData();
        foreach ($data['customers'] as $customer) {
            if (($customer['mobile_key'] ?? '') !== $incomingKey) {
                continue;
            }
            if ($existing !== null && ($customer['mobile_key'] ?? '') === ($existing['mobile_key'] ?? '')) {
                continue;
            }
            $errors[] = 'Mobile number already exists.';
            break;
        }

        return $errors;
    }

    /**
     * @return array{customers: array<int, array<string, mixed>>}
     */
    private function readData(): array
    {
        if (!is_file($this->dataPath)) {
            return ['customers' => []];
        }

        $contents = file_get_contents($this->dataPath);
        if ($contents === false || trim($contents) === '') {
            return ['customers' => []];
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('Failed to decode customer storage: ' . $exception->getMessage());
            return ['customers' => []];
        }

        if (!is_array($data) || !isset($data['customers']) || !is_array($data['customers'])) {
            return ['customers' => []];
        }

        return $data;
    }

    /**
     * @template T
     * @param callable(array): array{array, T} $callback
     * @param T $onError
     * @return T
     */
    private function writeThrough(callable $callback, $onError)
    {
        $this->acquireLock();
        $data = $this->readData();

        try {
            [$nextData, $result] = $callback($data);
            $encoded = json_encode($nextData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Could not encode customer data.');
            }
            if (file_put_contents($this->dataPath, $encoded) === false) {
                throw new RuntimeException('Could not write customer data.');
            }
            return $result;
        } catch (Throwable $exception) {
            error_log('Customer storage write failed: ' . $exception->getMessage());
            return $onError;
        } finally {
            $this->releaseLock();
        }
    }

    private function initialiseFilesystem(): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
        if (!is_file($this->dataPath)) {
            file_put_contents($this->dataPath, json_encode(['customers' => []], JSON_PRETTY_PRINT));
        }
    }

    private function acquireLock(): void
    {
        if ($this->lockHandle !== null) {
            return;
        }

        $this->lockHandle = fopen($this->lockPath, 'c');
        if ($this->lockHandle !== false) {
            flock($this->lockHandle, LOCK_EX);
        }
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

    private function normaliseMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile);
        if (!is_string($digits)) {
            return '';
        }

        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    private function now(): string
    {
        return gmdate('c');
    }

    private function ensureSerialNumber(array $payload, array $data): array
    {
        $serial = trim((string) ($payload['serial_number'] ?? ''));
        if ($serial === '') {
            $serial = (string) ($this->maxSerialNumber($data) + 1);
        }

        $payload['serial_number'] = $serial;

        return $payload;
    }

    private function maxSerialNumber(array $data): int
    {
        $max = 0;
        foreach ($data['customers'] ?? [] as $customer) {
            $value = $customer['serial_number'] ?? null;
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $numeric = is_int($value) ? $value : (int) preg_replace('/\D+/', '', (string) $value);
            if ($numeric > $max) {
                $max = $numeric;
            }
        }

        return $max;
    }

    public function customerStatuses(): array
    {
        return self::STATUSES;
    }

    public function ensureStatusValue(string $value): string
    {
        return $this->normaliseStatus($value);
    }

    private function normaliseWelcomeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '' || $channel === 'none') {
            return 'none';
        }

        if (in_array($channel, ['whatsapp', 'email', 'both'], true)) {
            return $channel;
        }

        return 'none';
    }

    private function hydrateCustomer(array $customer): array
    {
        if (!isset($customer['status']) || !in_array($customer['status'], self::STATUSES, true)) {
            $customer['status'] = self::DEFAULT_STATUS;
        }

        $defaults = [
            'mobile' => '',
            'mobile_key' => '',
            'name' => '',
            'serial_number' => '',
            'customer_type' => '',
            'address' => '',
            'city' => '',
            'district' => '',
            'pin_code' => '',
            'state' => '',
            'meter_number' => '',
            'meter_serial_number' => '',
            'jbvnl_account_number' => '',
            'application_id' => '',
            'application_submitted_date' => '',
            'sanction_load_kwp' => '',
            'installed_pv_module_capacity_kwp' => '',
            'circle_name' => '',
            'division_name' => '',
            'sub_division_name' => '',
            'loan_taken' => '',
            'loan_application_date' => '',
            'solar_plant_installation_date' => '',
            'subsidy_amount_rs' => '',
            'subsidy_disbursed_date' => '',
            'complaints_raised' => 'No',
            'status' => $customer['status'],
            'welcome_sent_via' => $this->normaliseWelcomeChannel((string) ($customer['welcome_sent_via'] ?? 'none')),
            'handover_overrides' => $this->normaliseOverrides($customer['handover_overrides'] ?? []),
            'handover_document_path' => trim((string) ($customer['handover_document_path'] ?? '')),
            'handover_html_path' => trim((string) ($customer['handover_html_path'] ?? '')),
            'handover_pdf_path' => trim((string) ($customer['handover_pdf_path'] ?? '')),
            'handover_generated_at' => trim((string) ($customer['handover_generated_at'] ?? '')),
            'password_hash' => $customer['password_hash'] ?? null,
            'created_from_quote_id' => trim((string) ($customer['created_from_quote_id'] ?? '')),
            'created_from_quote_no' => trim((string) ($customer['created_from_quote_no'] ?? '')),
            'created_at' => $customer['created_at'] ?? null,
            'updated_at' => $customer['updated_at'] ?? null,
        ];

        $customer = array_merge($defaults, $customer);

        if ($customer['loan_taken'] === '') {
            $customer['loan_taken'] = 'No';
        }

        if ($customer['handover_html_path'] === '' && $customer['handover_document_path'] !== '') {
            $customer['handover_html_path'] = $customer['handover_document_path'];
        }

        return $customer;
    }

    private function normaliseStatus(string $status): string
    {
        $status = trim($status);
        foreach (self::STATUSES as $allowedStatus) {
            if (strcasecmp($status, $allowedStatus) === 0) {
                return $allowedStatus;
            }
        }

        return self::DEFAULT_STATUS;
    }

    private function normaliseOverrides($value): array
    {
        $defaults = [
            'welcome_note' => '',
            'user_manual' => '',
            'system_details' => '',
            'operation_maintenance' => '',
            'warranty_details' => '',
            'consumer_engagement' => '',
            'education_best_practices' => '',
            'final_notes' => '',
            'handover_acknowledgment' => '',
        ];

        if (!is_array($value)) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            $defaults[$key] = trim((string) ($value[$key] ?? $default));
        }

        return $defaults;
    }
}
