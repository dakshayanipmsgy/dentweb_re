<?php
declare(strict_types=1);

/** Canonical SQLite identity/task schema introduced by issue #911. */
function canonical_work_initialize_schema(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS employee_legacy_ids (
    source TEXT NOT NULL,
    legacy_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY(source, legacy_id),
    UNIQUE(source, user_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS task_legacy_ids (
    source TEXT NOT NULL,
    legacy_id TEXT NOT NULL,
    task_id INTEGER NOT NULL,
    fingerprint TEXT NOT NULL,
    imported_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY(source, legacy_id),
    UNIQUE(source, task_id),
    FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT
)
SQL);

    $columns = $db->query('PRAGMA table_info(portal_tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $additions = [
        'frequency_type' => "TEXT NOT NULL DEFAULT 'once' CHECK(frequency_type IN ('once','daily','weekly','monthly','custom'))",
        'custom_every_n_days' => 'INTEGER NOT NULL DEFAULT 0',
        'start_date' => 'TEXT', 'next_due_date' => 'TEXT',
        'archived_flag' => 'INTEGER NOT NULL DEFAULT 0 CHECK(archived_flag IN (0,1))',
        'completion_log' => "TEXT NOT NULL DEFAULT '[]'", 'last_completed_at' => 'TEXT',
        'version' => 'INTEGER NOT NULL DEFAULT 1',
    ];
    foreach ($additions as $name => $definition) {
        if (!in_array($name, $columns, true)) {
            $db->exec("ALTER TABLE portal_tasks ADD COLUMN {$name} {$definition}");
        }
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_portal_tasks_assignee_status_due ON portal_tasks(assignee_id, status, due_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_portal_tasks_active_due ON portal_tasks(archived_flag, status, due_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_employee_legacy_user ON employee_legacy_ids(user_id)');
}

final class CanonicalEmployeeRepository
{
    public function __construct(private PDO $db) { canonical_work_initialize_schema($db); }

    public function all(): array
    {
        $sql = "SELECT u.id, u.full_name AS name, u.username AS login_id, '' AS phone,
                       COALESCE(u.permissions_note, '') AS designation, u.status, u.password_hash,
                       u.created_at, u.updated_at, m.legacy_id
                FROM users u JOIN roles r ON r.id=u.role_id AND r.name='employee'
                LEFT JOIN employee_legacy_ids m ON m.user_id=u.id AND m.source='employee_json'
                ORDER BY u.full_name";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function byId(string $id): ?array
    {
        $sql = "SELECT u.id, u.full_name AS name, u.username AS login_id, '' AS phone,
                       COALESCE(u.permissions_note, '') AS designation, u.status, u.password_hash,
                       u.created_at, u.updated_at, m.legacy_id
                FROM users u JOIN roles r ON r.id=u.role_id AND r.name='employee'
                LEFT JOIN employee_legacy_ids m ON m.user_id=u.id AND m.source='employee_json'
                WHERE u.id=:numeric OR m.legacy_id=:legacy LIMIT 1";
        $stmt=$this->db->prepare($sql); $stmt->execute([':numeric'=>ctype_digit($id)?(int)$id:-1, ':legacy'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null;
    }

    public function byLogin(string $login): ?array
    {
        $stmt=$this->db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='employee' AND (lower(u.username)=lower(:v) OR lower(u.email)=lower(:v)) LIMIT 1");
        $stmt->execute([':v'=>trim($login)]); $id=$stmt->fetchColumn();
        return $id === false ? null : $this->byId((string)$id);
    }
}

final class CanonicalTaskRepository
{
    public function __construct(private PDO $db) { canonical_work_initialize_schema($db); }

    public function all(?int $assigneeId=null): array
    {
        $sql='SELECT t.*, u.full_name assignee_name FROM portal_tasks t LEFT JOIN users u ON u.id=t.assignee_id';
        $params=[]; if ($assigneeId!==null) { $sql.=' WHERE t.assignee_id=:a'; $params[':a']=$assigneeId; }
        $sql.=' ORDER BY t.id DESC'; $stmt=$this->db->prepare($sql); $stmt->execute($params);
        return array_map([$this,'legacyShape'],$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function replaceLegacyShape(array $tasks): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($tasks as $task) $this->saveLegacyShape($task);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function saveLegacyShape(array $task): array
    {
        $id=is_numeric($task['id']??null)?(int)$task['id']:0;
        $status=strcasecmp((string)($task['status']??''),'completed')===0?'done':'todo';
        $priority=strtolower((string)($task['priority']??'medium'));
        if(!in_array($priority,['low','medium','high'],true))$priority='medium';
        $log=json_encode($task['completion_log']??[], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $values=[':title'=>(string)($task['title']??''),':description'=>(string)($task['description']??''),':status'=>$status,':priority'=>$priority,
          ':due'=>($task['due_date']??'')?:null,':assignee'=>is_numeric($task['assigned_to_id']??null)?(int)$task['assigned_to_id']:null,
          ':frequency'=>(string)($task['frequency_type']??'once'),':custom'=>(int)($task['custom_every_n_days']??0),':start'=>($task['start_date']??'')?:null,
          ':next'=>($task['next_due_date']??'')?:null,':archived'=>!empty($task['archived_flag'])?1:0,':log'=>$log,':last'=>($task['last_completed_at']??'')?:null,
          ':created'=>($task['created_at']??'')?:date('Y-m-d H:i:s'),':updated'=>($task['updated_at']??'')?:date('Y-m-d H:i:s')];
        if($id>0){
          unset($values[':created']);
          $values[':id']=$id; $values[':version']=(int)($task['version']??1);
          $sql='UPDATE portal_tasks SET title=:title,description=:description,status=:status,priority=:priority,due_date=:due,assignee_id=:assignee,frequency_type=:frequency,custom_every_n_days=:custom,start_date=:start,next_due_date=:next,archived_flag=:archived,completion_log=:log,last_completed_at=:last,updated_at=:updated,version=version+1 WHERE id=:id AND version=:version';
          $stmt=$this->db->prepare($sql);$stmt->execute($values); if($stmt->rowCount()!==1)throw new RuntimeException('Task was changed by another request; reload before saving.');
        }else{
          $sql='INSERT INTO portal_tasks(title,description,status,priority,due_date,assignee_id,frequency_type,custom_every_n_days,start_date,next_due_date,archived_flag,completion_log,last_completed_at,created_at,updated_at) VALUES(:title,:description,:status,:priority,:due,:assignee,:frequency,:custom,:start,:next,:archived,:log,:last,:created,:updated)';
          $stmt=$this->db->prepare($sql);$stmt->execute($values);$id=(int)$this->db->lastInsertId();
        }
        foreach($this->all() as $row)if((int)$row['id']===$id)return $row;
        throw new RuntimeException('Task could not be reloaded.');
    }

    private function legacyShape(array $r): array
    {
        $log=json_decode((string)($r['completion_log']??'[]'),true); if(!is_array($log))$log=[];
        return ['id'=>(string)$r['id'],'title'=>$r['title'],'description'=>$r['description']??'','priority'=>ucfirst($r['priority']),
          'assigned_to_id'=>$r['assignee_id']===null?'':(string)$r['assignee_id'],'assigned_to_name'=>$r['assignee_name']??'',
          'frequency_type'=>$r['frequency_type']??'once','custom_every_n_days'=>(int)($r['custom_every_n_days']??0),'start_date'=>$r['start_date']??'',
          'due_date'=>$r['due_date']??'','next_due_date'=>$r['next_due_date']??'','status'=>$r['status']==='done'?'Completed':'Open',
          'archived_flag'=>(bool)($r['archived_flag']??false),'completion_log'=>$log,'last_completed_at'=>$r['last_completed_at']??'',
          'created_at'=>$r['created_at']??'','updated_at'=>$r['updated_at']??'','version'=>(int)($r['version']??1)];
    }
}
