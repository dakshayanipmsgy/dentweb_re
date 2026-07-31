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
        if (function_exists('get_db') || is_file(__DIR__ . '/bootstrap.php')) {
            require_once __DIR__ . '/bootstrap.php';
            return (new CanonicalEmployeeRepository(get_db()))->all();
        }
        $data = $this->readData();
        $employees = $data['employees'] ?? [];

        usort($employees, static function (array $left, array $right): int {
            return strcmp($left['name'] ?? '', $right['name'] ?? '');
        });

        return $employees;
    }

    public function findById(string $id): ?array
    {
        require_once __DIR__ . '/bootstrap.php';
        return (new CanonicalEmployeeRepository(get_db()))->byId($id);
        /* legacy reader retained only for rollback reference
        $data = $this->readData();
        foreach ($data['employees'] as $employee) {
            if (($employee['id'] ?? '') === $id) {
                return $employee;
            }
        }

        return null; */
    }

    public function findByLoginId(string $loginId): ?array
    {
        require_once __DIR__ . '/bootstrap.php';
        return (new CanonicalEmployeeRepository(get_db()))->byLogin($loginId);
        /* legacy reader retained only for rollback reference
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

        return null; */
    }

    /** Authenticate internally without returning or logging a password hash. */
    public function authenticate(string $loginId, string $password): ?array
    {
        require_once __DIR__ . '/bootstrap.php';
        $db = get_db();
        $stmt = $db->prepare("SELECT u.id,u.password_hash,u.status FROM users u JOIN roles r ON r.id=u.role_id AND r.name='employee' WHERE lower(u.username)=lower(:v) OR lower(u.email)=lower(:v)");
        $stmt->execute([':v' => trim($loginId)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || ($rows[0]['status'] ?? '') !== 'active' || !password_verify($password, (string) ($rows[0]['password_hash'] ?? ''))) {
            return null;
        }
        return (new CanonicalEmployeeRepository($db))->byId((string) $rows[0]['id']);
    }

    /**
     * @return array{success:bool, errors:array<int, string>, employee:array<string, mixed>|null}
     */
    public function addEmployee(array $input): array
    {
        require_once __DIR__ . '/bootstrap.php';
        $db=get_db(); $login=trim((string)($input['login_id']??'')); $name=trim((string)($input['name']??''));
        if($login===''||$name==='') return ['success'=>false,'errors'=>['Employee name and login ID are required.'],'employee'=>null];
        $hash=(string)($input['password_hash']??'');
        if($hash===''||password_get_info($hash)['algo']===null)return ['success'=>false,'errors'=>['A valid password is required for a new employee.'],'employee'=>null];
        try {
            $role=(int)$db->query("SELECT id FROM roles WHERE name='employee'")->fetchColumn();
            $email=filter_var($login,FILTER_VALIDATE_EMAIL)?$login:'employee+'.substr(hash('sha256',strtolower($login)),0,16).'@local.invalid';
            $db->beginTransaction();
            $stmt=$db->prepare('INSERT INTO users(full_name,email,username,password_hash,role_id,status,permissions_note,created_at,updated_at) VALUES(:n,:e,:u,:p,:r,:s,\'\',datetime(\'now\'),datetime(\'now\'))');
            $stmt->execute([':n'=>$name,':e'=>$email,':u'=>$login,':p'=>$hash,':r'=>$role,':s'=>(string)($input['status']??'active')]);
            $id=(int)$db->lastInsertId();$p=$db->prepare('INSERT INTO employee_profiles(user_id,phone,designation,created_at,updated_at) VALUES(:id,:p,:d,datetime(\'now\'),datetime(\'now\'))');$p->execute([':id'=>$id,':p'=>trim((string)($input['phone']??'')),':d'=>trim((string)($input['designation']??''))]);$db->commit();
            return ['success'=>true,'errors'=>[],'employee'=>(new CanonicalEmployeeRepository($db))->byId((string)$id)];
        } catch(Throwable $e) { if(isset($db)&&$db->inTransaction())$db->rollBack(); return ['success'=>false,'errors'=>['Login ID already exists or employee could not be saved.'],'employee'=>null]; }
        /* legacy implementation retained for rollback reference
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
        }, ['success' => false, 'errors' => ['Could not save employee.'], 'employee' => null]); */
    }

    /**
     * @return array{success:bool, errors:array<int, string>, employee:array<string, mixed>|null}
     */
    public function updateEmployee(string $id, array $input): array
    {
        require_once __DIR__ . '/bootstrap.php'; $db=get_db(); $existing=(new CanonicalEmployeeRepository($db))->byId($id);
        if($existing===null)return ['success'=>false,'errors'=>['Employee not found.'],'employee'=>null];
        $login=trim((string)($input['login_id']??$existing['login_id'])); $name=trim((string)($input['name']??$existing['name']));
        try {
            $email=filter_var($login,FILTER_VALIDATE_EMAIL)?$login:'employee+'.substr(hash('sha256',strtolower($login)),0,16).'@local.invalid';
            $db->beginTransaction();$params=[':n'=>$name,':e'=>$email,':u'=>$login,':s'=>(string)($input['status']??$existing['status']),':id'=>(int)$existing['id']];
            $sql='UPDATE users SET full_name=:n,email=:e,username=:u,status=:s,updated_at=datetime(\'now\')';if(isset($input['password_hash'])){$sql.=',password_hash=:p';$params[':p']=(string)$input['password_hash'];}$sql.=' WHERE id=:id';$db->prepare($sql)->execute($params);
            $p=$db->prepare('INSERT INTO employee_profiles(user_id,phone,designation,created_at,updated_at) VALUES(:id,:p,:d,datetime(\'now\'),datetime(\'now\')) ON CONFLICT(user_id) DO UPDATE SET phone=excluded.phone,designation=excluded.designation,updated_at=datetime(\'now\')');$p->execute([':id'=>(int)$existing['id'],':p'=>trim((string)($input['phone']??$existing['phone'])),':d'=>trim((string)($input['designation']??$existing['designation']))]);$db->commit();
            return ['success'=>true,'errors'=>[],'employee'=>(new CanonicalEmployeeRepository($db))->byId((string)$existing['id'])];
        } catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();return ['success'=>false,'errors'=>['Login ID already exists or employee could not be updated.'],'employee'=>null];}
        /* legacy implementation retained for rollback reference
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
        }, ['success' => false, 'errors' => ['Could not update employee.'], 'employee' => null]); */
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
