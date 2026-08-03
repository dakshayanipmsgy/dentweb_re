<?php
declare(strict_types=1);

/** Optional push transport. Canonical notifications continue to live in SQLite. */
function push_env(string $name): string
{
    foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function push_config(): array
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $key = push_env('PUSH_SUBSCRIPTION_ENCRYPTION_KEY');
    $decoded = $key === '' ? false : base64_decode($key, true);
    $encryptionReady = function_exists('sodium_crypto_secretbox') && is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    return [
        'enabled' => filter_var(push_env('PUSH_ENABLED'), FILTER_VALIDATE_BOOL) === true,
        'encryption_ready' => $encryptionReady,
        'dependency_ready' => is_file($autoload),
        'vapid_public' => push_env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'vapid_private' => push_env('WEB_PUSH_VAPID_PRIVATE_KEY'),
        'vapid_subject' => push_env('WEB_PUSH_VAPID_SUBJECT'),
        'key' => $decoded,
        'store' => __DIR__ . '/../storage/push',
    ];
}

function push_encrypt(string $plaintext): string
{
    $cfg = push_config();
    if (!$cfg['encryption_ready']) throw new RuntimeException('Push subscription encryption is unavailable.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1.' . rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $cfg['key'])), '+/', '-_'), '=');
}

function push_decrypt(string $envelope): string
{
    $cfg = push_config();
    if (!$cfg['encryption_ready'] || !str_starts_with($envelope, 'v1.')) throw new RuntimeException('Push subscription encryption is unavailable.');
    $raw = base64_decode(strtr(substr($envelope, 3), '-_', '+/'), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Invalid encrypted subscription.');
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $cfg['key']);
    if (!is_string($plain)) throw new RuntimeException('Invalid encrypted subscription.');
    return $plain;
}

function push_endpoint_valid(string $endpoint): bool
{
    if (strlen($endpoint) < 12 || strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($endpoint);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    $host = (string)($parts['host'] ?? '');
    if ($host === '' || $host === 'localhost' || str_ends_with(strtolower($host), '.local')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || $records === []) return false;
    foreach ($records as $record) {
        $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    }
    return true;
}

function push_base64url_key_valid(string $value, int $min, int $max): bool
{
    return strlen($value) >= $min && strlen($value) <= $max && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function push_with_store(callable $callback): mixed
{
    $dir = push_config()['store'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Push storage is unavailable.');
    $file = $dir . '/subscriptions.json'; $lock = fopen($dir . '/subscriptions.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Push storage is busy.');
    try {
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $result = $callback($data);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); chmod($tmp, 0600); rename($tmp, $file);
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function push_devices(int $userId): array
{
    return push_with_store(static function(array &$data) use ($userId): array {
        $out=[]; foreach($data as $row) if((int)($row['user_id']??0)===$userId) $out[]=['id'=>$row['id'],'label'=>$row['label'],'status'=>$row['status'],'created_at'=>$row['created_at'],'last_used_at'=>$row['last_used_at']]; return $out;
    });
}

function push_register(int $userId, array $subscription, string $label, string $agent): array
{
    $endpoint=(string)($subscription['endpoint']??'');$keys=$subscription['keys']??null;
    if(!push_endpoint_valid($endpoint)||!is_array($keys)||!push_base64url_key_valid((string)($keys['p256dh']??''),40,200)||!push_base64url_key_valid((string)($keys['auth']??''),16,100)) throw new InvalidArgumentException('Invalid push subscription.');
    $label=trim(preg_replace('/[^\pL\pN ._()-]/u','',mb_substr($label,0,60))??''); if($label==='')$label='This browser';
    $hash=hash('sha256',$endpoint);$now=gmdate(DATE_ATOM);
    return push_with_store(static function(array &$data)use($userId,$endpoint,$keys,$label,$agent,$hash,$now):array{
        foreach($data as &$row)if(hash_equals((string)($row['endpoint_hash']??''),$hash)){
            if((int)$row['user_id']!==$userId)throw new RuntimeException('Subscription is already registered.');
            $row['endpoint']=push_encrypt($endpoint);$row['p256dh']=push_encrypt((string)$keys['p256dh']);$row['auth']=push_encrypt((string)$keys['auth']);$row['label']=$label;$row['status']='active';$row['updated_at']=$now;$row['last_used_at']=$now;$row['revoked_at']=null;return ['id'=>$row['id'],'label'=>$label,'status'=>'active'];
        }
        $id=bin2hex(random_bytes(12));$data[]=['id'=>$id,'user_id'=>$userId,'endpoint_hash'=>$hash,'endpoint'=>push_encrypt($endpoint),'p256dh'=>push_encrypt((string)$keys['p256dh']),'auth'=>push_encrypt((string)$keys['auth']),'label'=>$label,'user_agent'=>mb_substr(preg_replace('/[^\x20-\x7E]/','',$agent)??'',0,160),'status'=>'active','created_at'=>$now,'updated_at'=>$now,'last_used_at'=>$now,'revoked_at'=>null,'failure_count'=>0,'last_failure_code'=>null];return ['id'=>$id,'label'=>$label,'status'=>'active'];
    });
}

function push_revoke(int $userId, ?string $id): int
{
    return push_with_store(static function(array &$data)use($userId,$id):int{$n=0;$now=gmdate(DATE_ATOM);foreach($data as &$r)if((int)($r['user_id']??0)===$userId&&($id===null||hash_equals((string)$r['id'],$id))&&$r['status']==='active'){$r['status']='revoked';$r['revoked_at']=$now;$r['updated_at']=$now;$n++;}return $n;});
}
