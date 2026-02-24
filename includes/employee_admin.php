<?php
declare(strict_types=1);

final class EmployeeFsStore
{
    private string $basePath;
    private string $dataPath;
    private string $lockPath;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (__DIR__ . '/../storage/employee-users');
        $this->dataPath = $this->basePath . '/employees.json';
        $this->lockPath = $this->basePath . '/employees.lock';
        $this->initialiseFilesystem();
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEmployees(): array
    {
        $data = $this->readData();
        $employees = $data['employees'] ?? [];

        usort($employees, static function (array $left, array $right): int {
            return strcmp($left['name'] ?? '', $right['name'] ?? '');
        });

        return $employees;
    }

    public function findById(string $id): ?array
    {
        $data = $this->readData();
        foreach ($data['employees'] as $employee) {
            if (($employee['id'] ?? '') === $id) {
                return $employee;
            }
        }

        return null;
    }

    public function findByLoginId(string $loginId): ?array
    {
        $normalized = trim($loginId);
        if ($normalized === '') {
            return null;
        }

        $data = $this->readData();
        foreach ($data['employees'] as $employee) {
            if (trim((string) ($employee['login_id'] ?? '')) === $normalized) {
                return $employee;
            }
        }

        return null;
    }

    /**
     * @return array{success:bool, errors:array<int, string>, employee:array<string, mixed>|null}
     */
    public function addEmployee(array $input): array
    {
        $payload = $this->normaliseInput($input);
        $errors = $this->validate($payload, null);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'employee' => null];
        }

        return $this->writeThrough(function (array $data) use ($payload): array {
            foreach ($data['employees'] as $employee) {
                if (($employee['login_id'] ?? '') === $payload['login_id']) {
                    throw new RuntimeException('Login ID already exists.');
                }
            }

            $payload['id'] = $this->generateId();
            $payload['created_at'] = $payload['created_at'] ?? $this->now();
            $payload['updated_at'] = $this->now();
            $data['employees'][] = $payload;

            return [$data, ['success' => true, 'errors' => [], 'employee' => $payload]];
        }, ['success' => false, 'errors' => ['Could not save employee.'], 'employee' => null]);
    }

    /**
     * @return array{success:bool, errors:array<int, string>, employee:array<string, mixed>|null}
     */
    public function updateEmployee(string $id, array $input): array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return ['success' => false, 'errors' => ['Employee not found.'], 'employee' => null];
        }

        $payload = $this->normaliseInput($input, $existing);
        $errors = $this->validate($payload, $existing);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'employee' => null];
        }

        return $this->writeThrough(function (array $data) use ($payload, $existing): array {
            foreach ($data['employees'] as $index => $employee) {
                if (($employee['id'] ?? '') !== ($existing['id'] ?? '')) {
                    continue;
                }

                $payload['id'] = $employee['id'];
                $payload['created_at'] = $employee['created_at'] ?? $this->now();
                $payload['updated_at'] = $this->now();
                $data['employees'][$index] = $payload;
                break;
            }

            return [$data, ['success' => true, 'errors' => [], 'employee' => $payload]];
        }, ['success' => false, 'errors' => ['Could not update employee.'], 'employee' => null]);
    }

    private function normaliseInput(array $input, ?array $existing = null): array
    {
        $status = strtolower(trim((string) ($input['status'] ?? ($existing['status'] ?? 'active'))));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        return [
            'id' => $existing['id'] ?? '',
            'name' => trim((string) ($input['name'] ?? ($existing['name'] ?? ''))),
            'login_id' => trim((string) ($input['login_id'] ?? ($existing['login_id'] ?? ''))),
            'phone' => trim((string) ($input['phone'] ?? ($existing['phone'] ?? ''))),
            'designation' => trim((string) ($input['designation'] ?? ($existing['designation'] ?? ''))),
            'status' => $status,
            'can_access_admin_created_dcs' => !empty($input['can_access_admin_created_dcs'] ?? ($existing['can_access_admin_created_dcs'] ?? false)),
            'password_hash' => is_string($input['password_hash'] ?? null)
                ? (string) $input['password_hash']
                : (string) ($existing['password_hash'] ?? ''),
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
        if ($payload['name'] === '') {
            $errors[] = 'Employee name is required.';
        }
        if ($payload['login_id'] === '') {
            $errors[] = 'Login ID is required.';
        }

        $data = $this->readData();
        foreach ($data['employees'] as $employee) {
            if (($employee['login_id'] ?? '') !== $payload['login_id']) {
                continue;
            }
            if ($existing !== null && ($employee['id'] ?? '') === ($existing['id'] ?? '')) {
                continue;
            }
            $errors[] = 'Login ID already exists.';
            break;
        }

        return $errors;
    }

    /**
     * @return array{employees: array<int, array<string, mixed>>}
     */
    private function readData(): array
    {
        if (!is_file($this->dataPath)) {
            return ['employees' => []];
        }

        $contents = file_get_contents($this->dataPath);
        if ($contents === false || trim($contents) === '') {
            return ['employees' => []];
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('Failed to decode employee storage: ' . $exception->getMessage());
            return ['employees' => []];
        }

        if (!is_array($data) || !isset($data['employees']) || !is_array($data['employees'])) {
            return ['employees' => []];
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
                throw new RuntimeException('Could not encode employee data.');
            }
            if (file_put_contents($this->dataPath, $encoded) === false) {
                throw new RuntimeException('Could not write employee data.');
            }
            return $result;
        } catch (Throwable $exception) {
            error_log('Employee storage write failed: ' . $exception->getMessage());
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
            file_put_contents($this->dataPath, json_encode(['employees' => []], JSON_PRETTY_PRINT));
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

    private function generateId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (Throwable $exception) {
            error_log('Employee ID generation fallback: ' . $exception->getMessage());
            return uniqid('emp_', true);
        }
    }

    private function now(): string
    {
        return gmdate('c');
    }
}
