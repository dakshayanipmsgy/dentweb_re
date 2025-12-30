<?php
declare(strict_types=1);

/**
 * File-based user storage and indexing layer.
 *
 * This module will eventually replace the existing SQL-backed user tables.
 * It provides atomic read/write helpers, an in-memory index representation,
 * and validation utilities while keeping the rest of the application unaware
 * of the underlying persistence change.
 */

final class FileUserStore
{
    private const INDEX_VERSION = 1;
    private string $basePath;
    private string $recordsPath;
    private string $indexPath;
    private string $auditPath;
    private string $lockPath;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (__DIR__ . '/../storage/users');
        $this->recordsPath = $this->basePath . '/records';
        $this->indexPath = $this->basePath . '/index.json';
        $this->auditPath = $this->basePath . '/audit.log';
        $this->lockPath = $this->basePath . '/.lock';

        $this->initialiseFilesystem();
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function recordsPath(): string
    {
        return $this->recordsPath;
    }

    public function indexPath(): string
    {
        return $this->indexPath;
    }

    public function auditLogPath(): string
    {
        return $this->auditPath;
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }

    /**
     * Retrieve the full user record for a given identifier.
     */
    public function get(int $userId): ?array
    {
        $userId = $userId > 0 ? $userId : 0;
        if ($userId <= 0) {
            return null;
        }

        $path = $this->userRecordPath($userId);
        if (!is_file($path)) {
            return null;
        }

        $payload = file_get_contents($path);
        if ($payload === false) {
            return null;
        }

        try {
            $record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log(sprintf('Failed to decode user record %d: %s', $userId, $exception->getMessage()));
            return null;
        }

        if (!is_array($record)) {
            return null;
        }

        return $this->normaliseUserRecord($record);
    }

    /**
     * List all indexed users ordered by most recent creation timestamp.
     */
    public function listAll(): array
    {
        $index = $this->readIndex();
        $users = [];
        foreach ($index['users'] as $userId => $metadata) {
            $userId = (int) $userId;
            $record = $this->get($userId);
            if ($record === null) {
                continue;
            }
            $users[] = $record;
        }

        usort($users, static function (array $left, array $right): int {
            return strcmp($right['created_at'] ?? '', $left['created_at'] ?? '');
        });

        return $users;
    }

    public function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $emailKey = strtolower($identifier);
        $usernameKey = strtolower($identifier);
        $phoneKey = preg_replace('/\D+/', '', $identifier);
        if (!is_string($phoneKey)) {
            $phoneKey = '';
        }

        $index = $this->readIndex();
        $candidates = [];
        $addCandidate = static function ($id) use (&$candidates): void {
            $id = (int) $id;
            if ($id <= 0) {
                return;
            }
            if (!in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        };

        if ($emailKey !== '') {
            $addCandidate($index['email_to_id'][$emailKey] ?? null);
        }
        if ($usernameKey !== '') {
            $addCandidate($index['username_to_id'][$usernameKey] ?? null);
        }
        if ($phoneKey !== '') {
            $addCandidate($index['phone_to_id'][$phoneKey] ?? null);
        }

        foreach ($candidates as $candidateId) {
            $record = $this->get((int) $candidateId);
            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }

    public function findByLoginIdentifier(string $identifier, string $role): ?array
    {
        $role = $this->normaliseRole($role);
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $emailKey = strtolower($identifier);
        $usernameKey = strtolower($identifier);
        $phoneKey = preg_replace('/\D+/', '', $identifier);
        if (!is_string($phoneKey)) {
            $phoneKey = '';
        }
        $phoneKey = trim($phoneKey);
        if ($role === 'customer' && $phoneKey !== '') {
            if (function_exists('normalize_customer_mobile')) {
                $normalized = normalize_customer_mobile($phoneKey);
                if (is_string($normalized) && $normalized !== '') {
                    $phoneKey = $normalized;
                }
            }
            if ($phoneKey !== '' && strlen($phoneKey) > 10) {
                $phoneKey = substr($phoneKey, -10);
            }
        }

        $index = $this->readIndex();
        $candidates = [];
        $register = static function ($key, $id) use (&$candidates): void {
            $id = (int) $id;
            if ($id <= 0) {
                return;
            }
            $compoundKey = $key . ':' . $id;
            if (!isset($candidates[$compoundKey])) {
                $candidates[$compoundKey] = $id;
            }
        };

        if ($emailKey !== '') {
            $register('email', $index['email_to_id'][$emailKey] ?? null);
        }
        if ($usernameKey !== '') {
            $register('username', $index['username_to_id'][$usernameKey] ?? null);
        }
        if ($role === 'customer' && $phoneKey !== '') {
            $register('phone', $index['phone_to_id'][$phoneKey] ?? null);
        }

        foreach ($candidates as $candidateId) {
            $record = $this->get((int) $candidateId);
            if ($record === null) {
                continue;
            }

            if ($this->normaliseRole($record['role']) !== $role) {
                continue;
            }

            if ($role === 'customer' && $phoneKey !== '' && $record['phone'] !== null && $record['phone'] === $phoneKey) {
                return $record;
            }

            if ($emailKey !== '' && $record['email'] !== null && strtolower($record['email']) === $emailKey) {
                return $record;
            }

            if ($usernameKey !== '' && $record['username'] !== null && strtolower($record['username']) === $usernameKey) {
                return $record;
            }

            return $record;
        }

        return null;
    }

    public function recordLogin(int $userId): ?array
    {
        $user = $this->get($userId);
        if ($user === null) {
            return null;
        }

        $user['last_login_at'] = $this->now();

        return $this->save($user);
    }

    /**
     * Insert or update a user record.
     *
     * @param array{ id?: int|null, full_name?: string|null, email?: string|null, username?: string|null,
     *               phone?: string|null, role?: string|null, status?: string|null, password_hash?: string|null,
     *               created_at?: string|null, updated_at?: string|null, last_login_at?: string|null,
     *               password_last_set_at?: string|null, password_reset_token?: string|null,
     *               password_reset_expires_at?: string|null, permissions_note?: string|null,
     *               flags?: array<string, mixed>|null } $input
     */
    public function save(array $input): array
    {
        return $this->withLock(function () use ($input): array {
            $index = $this->readIndex();
            $record = $this->prepareRecordForSave($input, $index);

            $index = $this->updateIndexWithRecord($index, $record);
            $this->writeUserRecord($record);
            $this->writeIndex($index);

            return $record;
        });
    }

    /**
     * Remove a user record and index references.
     */
    public function delete(int $userId): void
    {
        $userId = $userId > 0 ? $userId : 0;
        if ($userId <= 0) {
            return;
        }

        $this->withLock(function () use ($userId): void {
            $index = $this->readIndex();
            if (!isset($index['users'][(string) $userId])) {
                return;
            }

            $metadata = $index['users'][(string) $userId];
            unset($index['users'][(string) $userId]);

            if (isset($metadata['email'])) {
                $email = strtolower((string) $metadata['email']);
                unset($index['email_to_id'][$email]);
            }
            if (isset($metadata['username']) && $metadata['username'] !== null && $metadata['username'] !== '') {
                $username = strtolower((string) $metadata['username']);
                unset($index['username_to_id'][$username]);
            }
            if (isset($metadata['phone'])) {
                $phone = (string) $metadata['phone'];
                unset($index['phone_to_id'][$phone]);
            }

            foreach (['role', 'status'] as $bucketType) {
                if (!isset($metadata[$bucketType])) {
                    continue;
                }
                $bucketKey = (string) $metadata[$bucketType];
                if (isset($index[$bucketType . '_buckets'][$bucketKey])) {
                    $index[$bucketType . '_buckets'][$bucketKey] = array_values(array_filter(
                        array_map('intval', $index[$bucketType . '_buckets'][$bucketKey]),
                        static fn (int $id): bool => $id !== $userId
                    ));
                    if (count($index[$bucketType . '_buckets'][$bucketKey]) === 0) {
                        unset($index[$bucketType . '_buckets'][$bucketKey]);
                    }
                }
            }

            $this->writeIndex($index);

            $path = $this->userRecordPath($userId);
            if (is_file($path)) {
                @unlink($path);
            }
        });
    }

    /**
     * Append a JSON line entry to the audit log.
     */
    public function appendAudit(array $entry): void
    {
        $safeEntry = $entry;
        $safeEntry['logged_at'] = $safeEntry['logged_at'] ?? $this->now();

        $payload = json_encode($safeEntry, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $payload .= "\n";

        $this->withLock(function () use ($payload): void {
            $handle = fopen($this->auditPath, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Unable to append to user audit log.');
            }

            try {
                if (flock($handle, LOCK_EX) !== true) {
                    throw new RuntimeException('Failed to acquire audit log lock.');
                }
                if (fwrite($handle, $payload) === false) {
                    throw new RuntimeException('Failed to write to audit log.');
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });
    }

    /**
     * Perform a health check on the index and optionally rebuild from disk.
     */
    public function verifyIndex(bool $autoRebuild = false): array
    {
        return $this->withLock(function () use ($autoRebuild): array {
            $report = [
                'status' => 'ok',
                'issues' => [],
                'repaired' => false,
            ];

            $index = $this->readIndex();
            $expected = $this->rebuildIndexFromRecords();

            if ($this->indexEquals($index, $expected)) {
                return $report;
            }

            $report['status'] = 'degraded';
            $report['issues'][] = 'Index metadata did not match reconstructed view.';

            if ($autoRebuild) {
                $this->writeIndex($expected);
                $report['repaired'] = true;
            }

            return $report;
        });
    }

    /**
     * Reconstruct the index solely from the record files on disk.
     */
    public function rebuildIndexFromRecords(): array
    {
        $index = $this->defaultIndex();
        $files = glob($this->recordsPath . '/*.json');
        if ($files === false) {
            return $index;
        }

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            try {
                $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                error_log(sprintf('Skipping corrupt user record %s: %s', $file, $exception->getMessage()));
                continue;
            }

            if (!is_array($payload)) {
                continue;
            }

            $record = $this->normaliseUserRecord($payload);
            if (!isset($record['id']) || (int) $record['id'] <= 0) {
                continue;
            }

            $index = $this->updateIndexWithRecord($index, $record, true);
            $index['last_id'] = max($index['last_id'], (int) $record['id']);
        }

        return $index;
    }

    public function generateId(): int
    {
        return $this->withLock(function (): int {
            $index = $this->readIndex();
            $next = (int) $index['last_id'] + 1;
            $index['last_id'] = $next;
            $this->writeIndex($index);

            return $next;
        });
    }

    private function initialiseFilesystem(): void
    {
        foreach ([$this->basePath, $this->recordsPath] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create user storage directory: %s', $directory));
            }
        }

        if (!is_file($this->indexPath)) {
            $this->writeIndex($this->defaultIndex());
        }

        if (!is_file($this->auditPath)) {
            touch($this->auditPath);
        }

        if (!is_file($this->lockPath)) {
            touch($this->lockPath);
        }
    }

    private function userRecordPath(int $userId): string
    {
        $fileName = str_pad((string) $userId, 12, '0', STR_PAD_LEFT) . '.json';
        return $this->recordsPath . '/' . $fileName;
    }

    private function writeUserRecord(array $record): void
    {
        if (!isset($record['id']) || (int) $record['id'] <= 0) {
            throw new InvalidArgumentException('User records require a positive identifier.');
        }

        $path = $this->userRecordPath((int) $record['id']);
        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode user record.');
        }

        $this->atomicWrite($path, $encoded . "\n");
    }

    private function prepareRecordForSave(array $input, array &$index): array
    {
        $record = $this->normaliseUserRecord($input);

        $now = $this->now();
        $isNew = !isset($record['id']) || (int) $record['id'] <= 0;
        if ($isNew) {
            $record['id'] = (int) $index['last_id'] + 1;
            $index['last_id'] = $record['id'];
            $record['created_at'] = $record['created_at'] ?? $now;
        } else {
            $existingMetadata = $index['users'][(string) $record['id']] ?? null;
            if (is_array($existingMetadata)) {
                $priorEmail = isset($existingMetadata['email']) ? $this->normaliseEmail($existingMetadata['email']) : null;
                if ($priorEmail !== null && $priorEmail !== $record['email']) {
                    unset($index['email_to_id'][$priorEmail]);
                }

                $priorUsername = isset($existingMetadata['username']) ? $this->normaliseUsername($existingMetadata['username']) : null;
                if ($priorUsername !== null && $priorUsername !== $record['username']) {
                    unset($index['username_to_id'][$priorUsername]);
                }

                $priorPhone = isset($existingMetadata['phone']) ? $this->normalisePhone($existingMetadata['phone']) : null;
                if ($priorPhone !== null && $priorPhone !== $record['phone']) {
                    unset($index['phone_to_id'][$priorPhone]);
                }
            }
        }

        $record['updated_at'] = $now;

        if (!isset($record['password_hash']) || !is_string($record['password_hash']) || $record['password_hash'] === '') {
            throw new InvalidArgumentException('Password hash is required.');
        }

        $email = $record['email'] ?? null;
        if ($email !== null) {
            $existingId = $index['email_to_id'][$email] ?? null;
            if ($existingId !== null && (int) $existingId !== (int) $record['id']) {
                throw new RuntimeException('Email address already in use.');
            }
        }

        $username = $record['username'] ?? null;
        if ($username !== null && $username !== '') {
            $existingId = $index['username_to_id'][$username] ?? null;
            if ($existingId !== null && (int) $existingId !== (int) $record['id']) {
                throw new RuntimeException('Username already in use.');
            }
        }

        $phone = $record['phone'] ?? null;
        if ($phone !== null && $phone !== '') {
            $existingId = $index['phone_to_id'][$phone] ?? null;
            if ($existingId !== null && (int) $existingId !== (int) $record['id']) {
                throw new RuntimeException('Phone number already in use.');
            }
        }

        return $record;
    }

    private function normaliseUserRecord(array $input): array
    {
        $record = [
            'id' => isset($input['id']) ? (int) $input['id'] : null,
            'full_name' => $this->normaliseName($input['full_name'] ?? ''),
            'email' => $this->normaliseEmail($input['email'] ?? null),
            'username' => $this->normaliseUsername($input['username'] ?? null),
            'phone' => $this->normalisePhone($input['phone'] ?? null),
            'role' => $this->normaliseRole($input['role'] ?? 'employee'),
            'status' => $this->normaliseStatus($input['status'] ?? 'active'),
            'password_hash' => $input['password_hash'] ?? '',
            'created_at' => $input['created_at'] ?? null,
            'updated_at' => $input['updated_at'] ?? null,
            'last_login_at' => $input['last_login_at'] ?? null,
            'password_last_set_at' => $input['password_last_set_at'] ?? null,
            'password_reset_token' => $input['password_reset_token'] ?? null,
            'password_reset_expires_at' => $input['password_reset_expires_at'] ?? null,
            'permissions_note' => $this->normalisePermissionsNote($input['permissions_note'] ?? ''),
            'flags' => $this->normaliseFlags($input['flags'] ?? []),
        ];

        if ($record['full_name'] === '') {
            throw new InvalidArgumentException('Full name is required.');
        }

        if ($record['email'] !== null && !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }

        if ($record['phone'] !== null && strlen($record['phone']) < 10) {
            throw new InvalidArgumentException('Phone number must contain at least 10 digits.');
        }

        if ($record['password_hash'] === '') {
            throw new InvalidArgumentException('Password hash cannot be empty.');
        }

        if ($record['created_at'] !== null) {
            $record['created_at'] = $this->normaliseTimestamp($record['created_at']);
        }
        if ($record['updated_at'] !== null) {
            $record['updated_at'] = $this->normaliseTimestamp($record['updated_at']);
        }
        if ($record['last_login_at'] !== null) {
            $record['last_login_at'] = $this->normaliseTimestamp($record['last_login_at']);
        }
        if ($record['password_last_set_at'] !== null) {
            $record['password_last_set_at'] = $this->normaliseTimestamp($record['password_last_set_at']);
        }
        if ($record['password_reset_expires_at'] !== null) {
            $record['password_reset_expires_at'] = $this->normaliseTimestamp($record['password_reset_expires_at']);
        }

        return $record;
    }

    private function updateIndexWithRecord(array $index, array $record, bool $skipSequence = false): array
    {
        $userId = (int) $record['id'];
        if ($userId <= 0) {
            throw new InvalidArgumentException('Cannot index a record without an identifier.');
        }

        $previous = $index['users'][(string) $userId] ?? null;
        $index['users'][(string) $userId] = [
            'id' => $userId,
            'full_name' => $record['full_name'],
            'email' => $record['email'],
            'username' => $record['username'],
            'phone' => $record['phone'],
            'role' => $record['role'],
            'status' => $record['status'],
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
            'last_login_at' => $record['last_login_at'],
        ];

        if (is_array($previous)) {
            foreach (['role', 'status'] as $bucketType) {
                $previousKey = (string) ($previous[$bucketType] ?? '');
                if ($previousKey === '' || $previousKey === (string) $record[$bucketType]) {
                    continue;
                }

                $bucketName = $bucketType . '_buckets';
                if (isset($index[$bucketName][$previousKey])) {
                    $index[$bucketName][$previousKey] = array_values(array_filter(
                        array_map('intval', $index[$bucketName][$previousKey]),
                        static fn (int $id): bool => $id !== $userId
                    ));
                    if (count($index[$bucketName][$previousKey]) === 0) {
                        unset($index[$bucketName][$previousKey]);
                    }
                }
            }
        }

        if (!$skipSequence) {
            $index['last_id'] = max($index['last_id'], $userId);
        }

        foreach (['email' => 'email_to_id', 'username' => 'username_to_id', 'phone' => 'phone_to_id'] as $field => $mapName) {
            $value = $record[$field];
            if ($value === null || $value === '') {
                continue;
            }
            $index[$mapName][$value] = $userId;
        }

        foreach (['role', 'status'] as $bucket) {
            $key = (string) $record[$bucket];
            if ($key === '') {
                continue;
            }
            $bucketKey = $bucket . '_buckets';
            if (!isset($index[$bucketKey][$key])) {
                $index[$bucketKey][$key] = [];
            }
            if (!in_array($userId, $index[$bucketKey][$key], true)) {
                $index[$bucketKey][$key][] = $userId;
            }
        }

        return $index;
    }

    private function defaultIndex(): array
    {
        return [
            'version' => self::INDEX_VERSION,
            'last_id' => 0,
            'users' => [],
            'email_to_id' => [],
            'username_to_id' => [],
            'phone_to_id' => [],
            'role_buckets' => [],
            'status_buckets' => [],
        ];
    }

    private function readIndex(): array
    {
        $payload = file_get_contents($this->indexPath);
        if ($payload === false || trim($payload) === '') {
            return $this->defaultIndex();
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log(sprintf('User index was corrupt (%s); reconstructing from disk.', $exception->getMessage()));
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return $this->rebuildIndexFromRecords();
        }

        return $this->normaliseIndex($decoded);
    }

    private function writeIndex(array $index): void
    {
        $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode user index.');
        }

        $this->atomicWrite($this->indexPath, $encoded . "\n");
    }

    private function normaliseIndex(array $index): array
    {
        $normalised = $this->defaultIndex();

        if (isset($index['last_id'])) {
            $normalised['last_id'] = (int) $index['last_id'];
        }

        if (isset($index['users']) && is_array($index['users'])) {
            foreach ($index['users'] as $userId => $metadata) {
                $userId = (int) $userId;
                if ($userId <= 0) {
                    continue;
                }

                $normalised['users'][(string) $userId] = [
                    'id' => $userId,
                    'full_name' => isset($metadata['full_name']) ? trim((string) $metadata['full_name']) : '',
                    'email' => isset($metadata['email']) && $metadata['email'] !== null && $metadata['email'] !== ''
                        ? strtolower(trim((string) $metadata['email']))
                        : null,
                    'username' => isset($metadata['username']) && $metadata['username'] !== null && $metadata['username'] !== ''
                        ? $this->normaliseUsername($metadata['username'])
                        : null,
                    'phone' => isset($metadata['phone']) && $metadata['phone'] !== ''
                        ? $this->normalisePhone($metadata['phone'])
                        : null,
                    'role' => isset($metadata['role']) ? $this->normaliseRole((string) $metadata['role']) : 'admin',
                    'status' => isset($metadata['status']) ? $this->normaliseStatus((string) $metadata['status']) : 'active',
                    'created_at' => isset($metadata['created_at']) ? $this->normaliseTimestamp($metadata['created_at']) : null,
                    'updated_at' => isset($metadata['updated_at']) ? $this->normaliseTimestamp($metadata['updated_at']) : null,
                    'last_login_at' => isset($metadata['last_login_at']) ? $this->normaliseTimestamp($metadata['last_login_at']) : null,
                ];
            }
        }

        foreach ([
            'email_to_id' => static fn ($value): string => strtolower(trim((string) $value)),
            'username_to_id' => static fn ($value): string => strtolower(trim((string) $value)),
            'phone_to_id' => function ($value): string {
                $normalised = $this->normalisePhone($value);
                return $normalised ?? '';
            },
        ] as $mapName => $normaliser) {
            if (!isset($index[$mapName]) || !is_array($index[$mapName])) {
                continue;
            }
            foreach ($index[$mapName] as $key => $value) {
                $cleanKey = $normaliser($key);
                if ($cleanKey === '') {
                    continue;
                }
                $normalised[$mapName][$cleanKey] = (int) $value;
            }
        }

        foreach (['role_buckets', 'status_buckets'] as $bucketName) {
            if (!isset($index[$bucketName]) || !is_array($index[$bucketName])) {
                continue;
            }
            foreach ($index[$bucketName] as $bucketKey => $values) {
                if (!is_array($values)) {
                    continue;
                }
                $cleanKey = $bucketName === 'role_buckets'
                    ? $this->normaliseRole((string) $bucketKey)
                    : $this->normaliseStatus((string) $bucketKey);
                $normalised[$bucketName][$cleanKey] = array_values(array_unique(array_map('intval', $values)));
            }
        }

        return $normalised;
    }

    private function normaliseName($name): string
    {
        $name = is_string($name) ? trim($name) : '';
        $name = preg_replace('/\s+/', ' ', $name ?? '');
        return is_string($name) ? trim($name) : '';
    }

    private function normaliseEmail($email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $email = strtolower(trim($email));
        return $email === '' ? null : $email;
    }

    private function normalisePhone($phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        if (!is_string($phone)) {
            $phone = (string) $phone;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits)) {
            return null;
        }

        $digits = trim($digits);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    private function normaliseUsername($username): ?string
    {
        if (!is_string($username)) {
            return null;
        }

        $username = strtolower(trim($username));
        if ($username === '') {
            return null;
        }

        return $username;
    }

    private function normaliseRole($role): string
    {
        $role = is_string($role) ? strtolower(trim($role)) : 'admin';
        if (function_exists('canonical_role_name')) {
            $role = canonical_role_name($role);
        }
        $valid = ['admin'];
        return in_array($role, $valid, true) ? $role : 'admin';
    }

    private function normaliseStatus($status): string
    {
        $status = is_string($status) ? strtolower(trim($status)) : 'active';
        $valid = ['active', 'inactive', 'pending'];
        return in_array($status, $valid, true) ? $status : 'active';
    }

    private function normalisePermissionsNote($note): string
    {
        if (!is_string($note)) {
            return '';
        }

        return trim($note);
    }

    private function normaliseFlags($flags): array
    {
        if (!is_array($flags)) {
            return [];
        }
        return $flags;
    }

    private function normaliseTimestamp($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
            return $date->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function now(): string
    {
        if (function_exists('now_ist')) {
            return now_ist();
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        return $now->format('Y-m-d H:i:s');
    }

    private function indexEquals(array $left, array $right): bool
    {
        $canonical = static function (array $index): array {
            ksort($index['users']);
            foreach (['email_to_id', 'username_to_id', 'phone_to_id'] as $map) {
                if (isset($index[$map])) {
                    ksort($index[$map]);
                }
            }
            foreach (['role_buckets', 'status_buckets'] as $bucket) {
                if (isset($index[$bucket])) {
                    ksort($index[$bucket]);
                    foreach ($index[$bucket] as &$ids) {
                        sort($ids);
                    }
                }
            }
            return $index;
        };

        return $canonical($left) === $canonical($right);
    }

    private function withLock(callable $callback)
    {
        $this->acquireLock();

        try {
            return $callback();
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): void
    {
        if ($this->lockHandle !== null) {
            return;
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open user storage lock file.');
        }

        if (flock($handle, LOCK_EX) !== true) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire user storage lock.');
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

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory for %s', $path));
        }

        $temp = tempnam($directory, 'usr');
        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary file for atomic write.');
        }

        $bytes = file_put_contents($temp, $contents, LOCK_EX);
        if ($bytes === false) {
            @unlink($temp);
            throw new RuntimeException('Failed to write temporary user storage file.');
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Unable to commit atomic write.');
        }
    }
}

function user_store(?string $basePath = null): FileUserStore
{
    static $store = null;

    if ($basePath !== null) {
        return new FileUserStore($basePath);
    }

    if (!$store instanceof FileUserStore) {
        $store = new FileUserStore();
    }

    return $store;
}
