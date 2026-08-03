<?php
declare(strict_types=1);

const CANONICAL_WORK_SCHEMA_VERSION = 91701;
const TASK_WORKFLOW_SCHEMA_VERSION = 91202;

function canonical_work_columns(PDO $db, string $table): array
{
    return $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
}

/** Versioned, repeatable canonical employee/task foundation. */
function canonical_work_initialize_schema(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS employee_legacy_ids (source TEXT NOT NULL, legacy_id TEXT NOT NULL, user_id INTEGER NOT NULL, source_fingerprint TEXT, migration_version INTEGER NOT NULL DEFAULT 91100, created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT, PRIMARY KEY(source,legacy_id), UNIQUE(source,user_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT)");
    if (!in_array('source_fingerprint', canonical_work_columns($db, 'employee_legacy_ids'), true)) $db->exec('ALTER TABLE employee_legacy_ids ADD COLUMN source_fingerprint TEXT');
    if (!in_array('migration_version', canonical_work_columns($db, 'employee_legacy_ids'), true)) $db->exec('ALTER TABLE employee_legacy_ids ADD COLUMN migration_version INTEGER NOT NULL DEFAULT 91100');
    if (!in_array('updated_at', canonical_work_columns($db, 'employee_legacy_ids'), true)) $db->exec('ALTER TABLE employee_legacy_ids ADD COLUMN updated_at TEXT');
    $db->exec("CREATE TABLE IF NOT EXISTS employee_profiles (user_id INTEGER PRIMARY KEY, phone TEXT NOT NULL DEFAULT '', designation TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $db->exec("CREATE TABLE IF NOT EXISTS task_legacy_ids (source TEXT NOT NULL, legacy_id TEXT NOT NULL, task_id INTEGER NOT NULL, fingerprint TEXT NOT NULL, imported_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY(source,legacy_id), UNIQUE(source,task_id), FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT)");
    $columns=canonical_work_columns($db,'portal_tasks');
    $add=[
      'frequency_type'=>"TEXT NOT NULL DEFAULT 'once'", 'custom_every_n_days'=>'INTEGER NOT NULL DEFAULT 0', 'start_date'=>'TEXT', 'next_due_date'=>'TEXT',
      'archived_flag'=>'INTEGER NOT NULL DEFAULT 0', 'completion_log'=>"TEXT NOT NULL DEFAULT '[]'", 'last_completed_at'=>'TEXT', 'version'=>'INTEGER NOT NULL DEFAULT 1',
      'expected_outcome'=>'TEXT', 'category'=>'TEXT', 'linked_entity_type'=>'TEXT', 'linked_entity_id'=>'TEXT', 'attention_owner_id'=>'INTEGER',
      'proof_required'=>'INTEGER NOT NULL DEFAULT 0', 'due_time'=>'TEXT', 'due_timezone'=>"TEXT NOT NULL DEFAULT 'Asia/Kolkata'", 'submitted_at'=>'TEXT',
      'approved_at'=>'TEXT', 'cancelled_at'=>'TEXT', 'archived_at'=>'TEXT', 'last_activity_at'=>'TEXT', 'parent_task_id'=>'INTEGER', 'recurrence_series_id'=>'TEXT',
      'workflow_priority'=>"TEXT NOT NULL DEFAULT 'medium' CHECK(workflow_priority IN ('low','medium','high','urgent'))"
      ,'workflow_status'=>"TEXT NOT NULL DEFAULT 'assigned' CHECK(workflow_status IN ('assigned','acknowledged','in_progress','blocked','submitted','correction_required','completed','cancelled'))"
      ,'responsibility'=>"TEXT NOT NULL DEFAULT 'employee' CHECK(responsibility IN ('admin','employee','none'))"
      ,'submission_summary'=>'TEXT', 'approved_by'=>'INTEGER', 'approval_note'=>'TEXT', 'closed_reason'=>'TEXT', 'created_by'=>'INTEGER', 'official_flag'=>'INTEGER NOT NULL DEFAULT 1'
    ];
    $needsPriorityBackfill=!in_array('workflow_priority',$columns,true);
    $needsWorkflowBackfill=!in_array('workflow_status',$columns,true);
    foreach($add as $name=>$definition) if(!in_array($name,$columns,true)) $db->exec("ALTER TABLE portal_tasks ADD COLUMN $name $definition");
    if($needsPriorityBackfill)$db->exec('UPDATE portal_tasks SET workflow_priority=priority');
    if($needsWorkflowBackfill){
        $db->exec("UPDATE portal_tasks SET workflow_status=CASE status WHEN 'done' THEN 'completed' WHEN 'in_progress' THEN 'in_progress' ELSE 'assigned' END");
        $db->exec("UPDATE portal_tasks SET responsibility=CASE WHEN workflow_status='completed' THEN 'none' ELSE 'employee' END, attention_owner_id=CASE WHEN workflow_status='completed' THEN NULL ELSE assignee_id END");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS task_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, author_id INTEGER NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL, edited_at TEXT, FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE CASCADE, FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE RESTRICT)");
    $messageColumns=canonical_work_columns($db,'task_messages');
    if(!in_array('message_type',$messageColumns,true))$db->exec("ALTER TABLE task_messages ADD COLUMN message_type TEXT NOT NULL DEFAULT 'reply'");
    $db->exec("CREATE TABLE IF NOT EXISTS task_events (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, actor_id INTEGER, event_type TEXT NOT NULL, event_data TEXT NOT NULL DEFAULT '{}', task_version INTEGER NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL)");
    $db->exec("CREATE TRIGGER IF NOT EXISTS task_events_immutable_update BEFORE UPDATE ON task_events BEGIN SELECT RAISE(ABORT,'task events are immutable'); END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS task_events_immutable_delete BEFORE DELETE ON task_events BEGIN SELECT RAISE(ABORT,'task events are immutable'); END");
    $db->exec("CREATE TABLE IF NOT EXISTS task_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, message_id INTEGER, uploaded_by INTEGER NOT NULL, storage_key TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, media_type TEXT NOT NULL, byte_size INTEGER NOT NULL CHECK(byte_size>=0), sha256 TEXT NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT, FOREIGN KEY(message_id) REFERENCES task_messages(id) ON DELETE SET NULL, FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE RESTRICT)");
    $db->exec("CREATE TABLE IF NOT EXISTS task_occurrences (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL UNIQUE, series_id TEXT NOT NULL, parent_task_id INTEGER, occurrence_number INTEGER NOT NULL CHECK(occurrence_number>0), scheduled_for TEXT, created_at TEXT NOT NULL, UNIQUE(series_id,occurrence_number), FOREIGN KEY(task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT, FOREIGN KEY(parent_task_id) REFERENCES portal_tasks(id) ON DELETE RESTRICT)");
    foreach(['CREATE INDEX IF NOT EXISTS idx_tasks_assignee_status_due ON portal_tasks(assignee_id,status,due_date)','CREATE INDEX IF NOT EXISTS idx_tasks_activity ON portal_tasks(last_activity_at)','CREATE INDEX IF NOT EXISTS idx_tasks_linked ON portal_tasks(linked_entity_type,linked_entity_id)','CREATE INDEX IF NOT EXISTS idx_task_messages_task ON task_messages(task_id,created_at)','CREATE INDEX IF NOT EXISTS idx_task_events_task ON task_events(task_id,id)','CREATE INDEX IF NOT EXISTS idx_task_attachments_task ON task_attachments(task_id)','CREATE INDEX IF NOT EXISTS idx_task_occurrences_series ON task_occurrences(series_id,occurrence_number)'] as $sql)$db->exec($sql);
    $stmt=$db->prepare('INSERT OR IGNORE INTO schema_migrations(version,name,applied_at) VALUES(:v,:n,datetime(\'now\'))');$stmt->execute([':v'=>CANONICAL_WORK_SCHEMA_VERSION,':n'=>'issue_917_corrective_foundation']);$stmt->execute([':v'=>TASK_WORKFLOW_SCHEMA_VERSION,':n'=>'issue_912_official_task_workflow']);
}

final class CanonicalEmployeeRepository
{
    public function __construct(private PDO $db){canonical_work_initialize_schema($db);}
    private function select(): string{return "SELECT u.id,u.full_name name,u.username login_id,COALESCE(p.phone,'') phone,COALESCE(p.designation,'') designation,u.status,u.created_at,u.updated_at,m.legacy_id FROM users u JOIN roles r ON r.id=u.role_id AND r.name='employee' LEFT JOIN employee_profiles p ON p.user_id=u.id LEFT JOIN employee_legacy_ids m ON m.user_id=u.id AND m.source='employee_json'";}
    public function all(): array{return $this->db->query($this->select().' ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC);}
    public function byId(string $id): ?array{$s=$this->db->prepare($this->select().' WHERE u.id=:n OR m.legacy_id=:l LIMIT 1');$s->execute([':n'=>ctype_digit($id)?(int)$id:-1,':l'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    public function byLogin(string $login): ?array{$s=$this->db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id AND r.name='employee' WHERE lower(u.username)=lower(:v) OR lower(u.email)=lower(:v)");$s->execute([':v'=>trim($login)]);$ids=array_unique($s->fetchAll(PDO::FETCH_COLUMN));return count($ids)===1?$this->byId((string)reset($ids)):null;}
}

final class CanonicalTaskRepository
{
    public function __construct(private PDO $db){canonical_work_initialize_schema($db);}
    public function all(?int $assigneeId=null):array{$q='SELECT t.*,u.full_name assignee_name FROM portal_tasks t LEFT JOIN users u ON u.id=t.assignee_id';$p=[];if($assigneeId!==null){$q.=' WHERE t.assignee_id=:a';$p[':a']=$assigneeId;}$q.=' ORDER BY t.id DESC';$s=$this->db->prepare($q);$s->execute($p);return array_map([$this,'legacyShape'],$s->fetchAll(PDO::FETCH_ASSOC));}
    public function replaceLegacyShape(array $tasks):void{$this->db->beginTransaction();try{foreach($tasks as $t)$this->saveLegacyShape($t);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;}}
    public function saveLegacyShape(array $t):array{json_encode($t['completion_log']??[],JSON_THROW_ON_ERROR);$id=is_numeric($t['id']??null)?(int)$t['id']:0;$priority=strtolower((string)($t['priority']??'medium'));if(!in_array($priority,['low','medium','high','urgent'],true))$priority='medium';$v=[':title'=>(string)($t['title']??''),':description'=>(string)($t['description']??''),':status'=>strcasecmp((string)($t['status']??''),'completed')===0?'done':'todo',':priority'=>$priority,':due'=>($t['due_date']??'')?:null,':assignee'=>is_numeric($t['assigned_to_id']??null)?(int)$t['assigned_to_id']:null,':updated'=>($t['updated_at']??'')?:date('Y-m-d H:i:s')];if($id>0){if(!isset($t['version'])||!is_numeric($t['version'])||(int)$t['version']<1)throw new RuntimeException('Task version is required; reload before saving.');$v+=[':id'=>$id,':version'=>(int)$t['version']];$s=$this->db->prepare("UPDATE portal_tasks SET title=:title,description=:description,status=:status,priority=CASE WHEN :priority='urgent' THEN 'high' ELSE :priority END,workflow_priority=:priority,due_date=:due,assignee_id=:assignee,updated_at=:updated,last_activity_at=:updated,version=version+1 WHERE id=:id AND version=:version");$s->execute($v);if($s->rowCount()!==1)throw new RuntimeException('Task was changed by another request; reload before saving.');}else{$v[':created']=($t['created_at']??'')?:date('Y-m-d H:i:s');$s=$this->db->prepare("INSERT INTO portal_tasks(title,description,status,priority,workflow_priority,due_date,assignee_id,created_at,updated_at,last_activity_at) VALUES(:title,:description,:status,CASE WHEN :priority='urgent' THEN 'high' ELSE :priority END,:priority,:due,:assignee,:created,:updated,:updated)");$s->execute($v);$id=(int)$this->db->lastInsertId();}foreach($this->all() as $r)if((int)$r['id']===$id)return $r;throw new RuntimeException('Task could not be reloaded.');}
    public function legacyShape(array $r):array{return ['id'=>(string)$r['id'],'title'=>$r['title'],'description'=>$r['description']??'','priority'=>ucfirst($r['workflow_priority']??$r['priority']),'assigned_to_id'=>$r['assignee_id']===null?'':(string)$r['assignee_id'],'assigned_to_name'=>$r['assignee_name']??'','frequency_type'=>$r['frequency_type']??'once','custom_every_n_days'=>(int)($r['custom_every_n_days']??0),'start_date'=>$r['start_date']??'','due_date'=>$r['due_date']??'','next_due_date'=>$r['next_due_date']??'','status'=>$r['status']==='done'?'Completed':'Open','archived_flag'=>(bool)($r['archived_flag']??false),'completion_log'=>json_decode((string)($r['completion_log']??'[]'),true)?:[],'last_completed_at'=>$r['last_completed_at']??'','created_at'=>$r['created_at']??'','updated_at'=>$r['updated_at']??'','version'=>(int)($r['version']??1)];}
}
