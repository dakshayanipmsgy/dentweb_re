<?php
declare(strict_types=1);

const TASK_NOTIFICATION_SCHEMA_VERSION = 92401;

function task_notification_config(): array
{
    $int = static function (string $name, int $default, int $min, int $max): int {
        $raw = getenv($name);
        $value = $raw !== false && ctype_digit($raw) ? (int) $raw : $default;
        return max($min, min($max, $value));
    };
    return [
        'unacknowledged_hours' => $int('TASK_NOTIFICATION_UNACKNOWLEDGED_HOURS', 24, 1, 720),
        'blocked_hours' => $int('TASK_NOTIFICATION_BLOCKED_HOURS', 24, 1, 720),
        'due_today_hour' => $int('TASK_NOTIFICATION_DUE_TODAY_HOUR', 7, 0, 23),
        'employee_overdue_hours' => $int('TASK_NOTIFICATION_EMPLOYEE_OVERDUE_HOURS', 24, 1, 168),
        'admin_overdue_hours' => $int('TASK_NOTIFICATION_ADMIN_OVERDUE_HOURS', 24, 1, 168),
        'projection_batch' => $int('TASK_NOTIFICATION_PROJECTION_BATCH', 100, 1, 500),
        'poll_seconds' => $int('TASK_NOTIFICATION_POLL_SECONDS', 45, 30, 300),
        'fallback_seconds' => $int('TASK_NOTIFICATION_FALLBACK_SECONDS', 300, 60, 3600),
        'retention_days' => $int('TASK_NOTIFICATION_RETENTION_DAYS', 365, 30, 3650),
    ];
}

function task_notification_kolkata_to_utc(string $stamp): string
{
    return (new DateTimeImmutable($stamp, new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function task_notification_backfill_assignments(PDO $db): array
{
    $report=['updated'=>0,'ambiguous'=>0];
    foreach($db->query("SELECT id,created_at,assignee_id FROM portal_tasks WHERE assigned_at IS NULL OR assigned_at='' ")->fetchAll(PDO::FETCH_ASSOC) as $task){
        $q=$db->prepare("SELECT created_at,event_data FROM task_events WHERE task_id=? AND event_type IN ('assigned','reassigned','recurrence_created') ORDER BY id DESC");$q->execute([(int)$task['id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);$stamp=null;
        if($events){$payload=json_decode((string)$events[0]['event_data'],true);if(is_array($payload)&&(int)($payload['new_assignee_id']??0)===(int)$task['assignee_id'])$stamp=(string)$events[0]['created_at'];else $report['ambiguous']++;}
        else {$count=$db->prepare('SELECT COUNT(*) FROM task_events WHERE task_id=?');$count->execute([(int)$task['id']]);if((int)$count->fetchColumn()===0)$stamp=(string)$task['created_at'];else $report['ambiguous']++;}
        if($stamp!==''){ $u=$db->prepare("UPDATE portal_tasks SET assigned_at=? WHERE id=? AND (assigned_at IS NULL OR assigned_at='')");$u->execute([$stamp,(int)$task['id']]);$report['updated']+=$u->rowCount();}
    } return $report;
}

function task_notification_initialize_schema(PDO $db): void
{
    $columns = static fn(string $table): array => $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
    $taskColumns = $columns('portal_tasks');
    if (!in_array('assigned_at', $taskColumns, true)) $db->exec('ALTER TABLE portal_tasks ADD COLUMN assigned_at TEXT');
    $db->exec('CREATE TABLE IF NOT EXISTS task_notification_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL)');
    $utcNow=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $cutoff=$db->prepare("INSERT OR IGNORE INTO task_notification_meta(meta_key,meta_value) VALUES('rollout_cutoff_utc',?)");$cutoff->execute([$utcNow]);
    task_notification_backfill_assignments($db);
    $notificationColumns = $columns('portal_notifications');
    $add = [
        'notification_type' => "TEXT NOT NULL DEFAULT 'general'",
        'category' => "TEXT NOT NULL DEFAULT 'task'",
        'source_task_id' => 'INTEGER', 'source_series_id' => 'TEXT', 'source_occurrence' => 'INTEGER',
        'source_task_event_id' => 'INTEGER', 'deduplication_key' => 'TEXT', 'retention_until' => 'TEXT',
    ];
    foreach ($add as $name => $definition) {
        if (!in_array($name, $notificationColumns, true)) $db->exec("ALTER TABLE portal_notifications ADD COLUMN $name $definition");
    }
    $statusColumns = $columns('portal_notification_status');
    foreach (['read_at' => 'TEXT', 'unread_at' => 'TEXT', 'dismissed_at' => 'TEXT'] as $name => $definition) {
        if (!in_array($name, $statusColumns, true)) $db->exec("ALTER TABLE portal_notification_status ADD COLUMN $name $definition");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS task_notification_projection (task_event_id INTEGER PRIMARY KEY, disposition TEXT NOT NULL CHECK(disposition IN ('projected','skipped')), reason TEXT, processed_at TEXT NOT NULL, FOREIGN KEY(task_event_id) REFERENCES task_events(id) ON DELETE RESTRICT)");
    $db->exec("CREATE TABLE IF NOT EXISTS notification_leases (lease_key TEXT PRIMARY KEY, lease_until TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_portal_notifications_dedup ON portal_notifications(deduplication_key) WHERE deduplication_key IS NOT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_source_event ON portal_notifications(source_task_event_id,id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_source_task ON portal_notifications(source_task_id,created_at DESC,id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_status_user ON portal_notification_status(user_id,status,notification_id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_listing ON portal_notification_status(user_id,dismissed_at,notification_id DESC)');
    $stmt = $db->prepare("INSERT OR IGNORE INTO schema_migrations(version,name,applied_at) VALUES(?,?,datetime('now'))");
    $stmt->execute([TASK_NOTIFICATION_SCHEMA_VERSION, 'issue_924_notification_corrections']);
}

function task_notification_safe_text(string $value, int $limit = 140): string
{
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)));
    return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit - 1)) . '…';
}

function task_notification_validate_link(string $link, string $role, int $taskId): bool
{
    if ($link === '' || str_starts_with($link, '/') || preg_match('/(?:^[a-z][a-z0-9+.-]*:|\\\\|%2f%2f)/i', $link)) return false;
    $parts = parse_url($link);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) return false;
    $expected = $role === 'admin' ? 'admin-tasks.php' : 'employee-tasks.php';
    if (($parts['path'] ?? '') !== $expected) return false;
    parse_str((string) ($parts['query'] ?? ''), $query);
    return isset($query['task'], $query['view']) && ctype_digit((string) $query['task']) && (int) $query['task'] === $taskId
        && in_array($query['view'], ['active', 'completed', 'cancelled', 'archived'], true)
        && ($parts['fragment'] ?? '') === 'task-' . $taskId;
}

function task_notification_message(string $eventType, string $actorRole, string $actorName, string $taskTitle): ?array
{
    $task = '“' . task_notification_safe_text($taskTitle, 90) . '”';
    $actor = task_notification_safe_text($actorName ?: ($actorRole === 'admin' ? 'Admin' : 'Employee'), 60);
    return match ($eventType) {
        'assigned' => ['Task assigned', "You were assigned $task.", 'info'],
        'recurrence_created' => ['Next task occurrence', "A new occurrence of $task is ready.", 'info'],
        'reassigned' => ['Task reassigned', "You are now assigned $task.", 'info'],
        'reply' => $actorRole === 'admin' ? ['Admin replied', "Admin replied on $task.", 'info'] : ['Employee replied', "$actor replied on $task.", 'info'],
        'progress' => ['Task progress', "$actor posted progress on $task.", 'info'],
        'blocker_response' => ['Blocker response', "Admin responded to the blocker on $task.", 'warning'],
        'blocker_resolved' => ['Blocker resolved', "The blocker was resolved for $task.", 'success'],
        'schedule_priority_revised' => ['Task schedule updated', "Due date or priority changed for $task.", 'warning'],
        'correction_requested' => ['Corrections requested', "Admin returned $task with corrections.", 'warning'],
        'approved' => ['Task approved', "Task approved: $task.", 'success'],
        'cancelled' => ['Task cancelled', "Task cancelled: $task.", 'danger'],
        'reopened' => ['Task reopened', "Task reopened: $task.", 'warning'],
        'proof_uploaded' => ['Proof uploaded', "$actor uploaded proof for $task.", 'info'],
        'acknowledged' => ['Assignment acknowledged', "$actor acknowledged $task.", 'info'],
        'started' => ['Task started', "$actor started $task.", 'info'],
        'blocker_reported' => ['Task blocked', "$actor reported a blocker on $task.", 'danger'],
        'submitted' => ['Task submitted', "$actor submitted $task for review.", 'warning'],
        'resubmitted' => ['Task resubmitted', "$actor resubmitted $task for review.", 'warning'],
        'correction_resumed' => ['Corrections resumed', "$actor resumed work on corrections for $task.", 'info'],
        default => null,
    };
}

function task_notification_active_users(PDO $db, array $recipient): array
{
    if (($recipient['type'] ?? '') === 'audience' && ($recipient['audience'] ?? '') === 'admin') {
        return $db->query("SELECT u.id,'admin' role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (($recipient['type'] ?? '') !== 'user' || !is_int($recipient['user_id'] ?? null) || $recipient['user_id'] <= 0) return [];
    $stmt = $db->prepare("SELECT u.id,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name='employee' AND u.status='active'");
    $stmt->execute([$recipient['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? [$row] : [];
}

function task_notification_project_event(PDO $db, int $eventId, ?string $rolloutAt = null): array
{
    task_notification_initialize_schema($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $seen = $db->prepare('SELECT disposition FROM task_notification_projection WHERE task_event_id=?'); $seen->execute([$eventId]);
        if ($seen->fetchColumn()) { $db->commit(); return ['created' => 0, 'skipped' => 1]; }
        $stmt = $db->prepare('SELECT e.*,t.title FROM task_events e JOIN portal_tasks t ON t.id=e.task_id WHERE e.id=?'); $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) throw new InvalidArgumentException('Task event not found.');
        if ($rolloutAt === null) $rolloutAt=(string)$db->query("SELECT meta_value FROM task_notification_meta WHERE meta_key='rollout_cutoff_utc'")->fetchColumn();
        $eventUtc=task_notification_kolkata_to_utc((string)$event['created_at']);
        if ($rolloutAt !== '' && strcmp($eventUtc, $rolloutAt) < 0) {
            $db->prepare("INSERT INTO task_notification_projection VALUES(?,'skipped','pre_rollout',?)")->execute([$eventId,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)]);
            $db->commit(); return ['created' => 0, 'skipped' => 1];
        }
        $payload = json_decode((string) $event['event_data'], true, 32, JSON_THROW_ON_ERROR);
        foreach (['contract_version','canonical_task_id','event_type','actor','intended_recipients','occurred_at','task_version'] as $required) if (!array_key_exists($required, $payload)) throw new UnexpectedValueException("Missing $required");
        if ($payload['contract_version'] !== 2 || (int) $payload['canonical_task_id'] !== (int) $event['task_id'] || !is_array($payload['intended_recipients']) || !is_array($payload['actor'])) throw new UnexpectedValueException('Invalid task event contract.');
        $actorId = (int) ($payload['actor']['id'] ?? 0); $actorRole = (string) ($payload['actor']['role'] ?? '');
        if (!in_array($actorRole, ['admin','employee'], true)) throw new UnexpectedValueException('Invalid actor role.');
        $nameStmt = $db->prepare('SELECT full_name FROM users WHERE id=?'); $nameStmt->execute([$actorId]); $actorName = (string) ($nameStmt->fetchColumn() ?: ucfirst($actorRole));
        $copy = task_notification_message((string) $payload['event_type'], $actorRole, $actorName, (string) $event['title']);
        $created = 0;
        if ($copy !== null) foreach ($payload['intended_recipients'] as $recipient) {
            if (!is_array($recipient) || !is_string($recipient['deep_link'] ?? null)) continue;
            foreach (task_notification_active_users($db, $recipient) as $user) {
                $uid = (int) $user['id']; $role = (string) $user['role_name']; $link = (string) $recipient['deep_link'];
                if (!task_notification_validate_link($link, $role, (int) $event['task_id'])) continue;
                $key = "task-event:$eventId:user:$uid";
                $insert = $db->prepare("INSERT OR IGNORE INTO portal_notifications(audience,tone,icon,title,message,link,scope_user_id,created_at,notification_type,category,source_task_id,source_series_id,source_occurrence,source_task_event_id,deduplication_key,retention_until) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(?, '+' || ? || ' days'))");
                $insert->execute([$role,$copy[2],'fa-solid fa-bell',task_notification_safe_text($copy[0],80),task_notification_safe_text($copy[1]),$link,$uid,$payload['occurred_at'],$payload['event_type'],'task',(int)$event['task_id'],(string)($payload['series_id']??''),(int)($payload['occurrence_number']??1),$eventId,$key,$payload['occurred_at'],task_notification_config()['retention_days']]);
                if ($insert->rowCount()) { $nid=(int)$db->lastInsertId(); $db->prepare("INSERT INTO portal_notification_status(notification_id,user_id,status,updated_at,unread_at) VALUES(?,?,'unread',?,?)")->execute([$nid,$uid,$payload['occurred_at'],$payload['occurred_at']]); $created++; }
            }
        }
        $db->prepare("INSERT INTO task_notification_projection VALUES(?,'projected',NULL,?)")->execute([$eventId,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM)]);
        $db->commit(); return ['created'=>$created,'skipped'=>0];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function task_notification_project_pending(PDO $db, ?int $limit = null): array
{
    task_notification_initialize_schema($db); $limit ??= task_notification_config()['projection_batch'];
    $rollout = $db->query("SELECT meta_value FROM task_notification_meta WHERE meta_key='rollout_cutoff_utc'")->fetchColumn();
    $stmt = $db->query('SELECT e.id FROM task_events e LEFT JOIN task_notification_projection p ON p.task_event_id=e.id WHERE p.task_event_id IS NULL ORDER BY e.id LIMIT ' . max(1,min(500,$limit)));
    $report=['events'=>0,'created'=>0,'skipped'=>0,'failed'=>0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { try {$r=task_notification_project_event($db,(int)$id,(string)$rollout);$report['events']++;$report['created']+=$r['created'];$report['skipped']+=$r['skipped'];} catch(Throwable $e){$report['failed']++;} }
    return $report;
}

function task_notification_list(PDO $db, int $userId, string $view='all', int $page=1, int $limit=20): array
{
    task_notification_initialize_schema($db); $limit=max(1,min(50,$limit));$page=max(1,$page);$where=$view==='unread'?" AND s.status='unread'":'';
    $stmt=$db->prepare("SELECT n.id,n.title,n.message,n.tone,n.notification_type,n.link,n.source_task_id,n.created_at,s.status FROM portal_notification_status s JOIN portal_notifications n ON n.id=s.notification_id WHERE s.user_id=? AND s.status!='dismissed'$where ORDER BY n.created_at DESC,n.id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1,$userId,PDO::PARAM_INT);$stmt->bindValue(2,$limit+1,PDO::PARAM_INT);$stmt->bindValue(3,($page-1)*$limit,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);return array_slice($rows,0,$limit);
}

function task_notification_page(PDO $db,int $userId,string $view='all',int $page=1,int $limit=20):array
{
    $items=task_notification_list($db,$userId,$view,$page,$limit);$where=$view==='unread'?" AND s.status='unread'":'';$s=$db->prepare("SELECT COUNT(*) FROM portal_notification_status s WHERE s.user_id=? AND s.status!='dismissed'$where");$s->execute([$userId]);$total=(int)$s->fetchColumn();return ['notifications'=>$items,'page'=>max(1,$page),'view'=>$view,'has_more'=>max(1,$page)*max(1,$limit)<$total,'total'=>$total];
}
function task_notification_unread_count(PDO $db,int $userId):int{$s=$db->prepare("SELECT COUNT(*) FROM portal_notification_status WHERE user_id=? AND status='unread'");$s->execute([$userId]);return (int)$s->fetchColumn();}
function task_notification_mutate(PDO $db,int $userId,string $action,?int $id=null):void
{
    if(!in_array($action,['read','unread','dismiss','read-all'],true))throw new InvalidArgumentException('Invalid action.');
    if($action!=='read-all'&&(!$id||$id<1))throw new InvalidArgumentException('Notification ID is required.');
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');$db->beginTransaction();try{
        if($action==='read-all'){$s=$db->prepare("UPDATE portal_notification_status SET status='read',read_at=?,dismissed_at=NULL,updated_at=? WHERE user_id=? AND status='unread'");$s->execute([$now,$now,$userId]);}
        else {$sets=match($action){'read'=>"status='read',read_at=?,dismissed_at=NULL",'unread'=>"status='unread',unread_at=?,read_at=NULL,dismissed_at=NULL",'dismiss'=>"status='dismissed',dismissed_at=?"};$s=$db->prepare("UPDATE portal_notification_status SET $sets,updated_at=? WHERE notification_id=? AND user_id=?");$s->execute([$now,$now,$id,$userId]);if($s->rowCount()!==1){$owns=$db->prepare('SELECT 1 FROM portal_notification_status WHERE notification_id=? AND user_id=?');$owns->execute([$id,$userId]);if(!$owns->fetchColumn())throw new OutOfBoundsException('Notification not found.');}}
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function task_notification_due_at(array $task): ?DateTimeImmutable
{
    $date=trim((string)($task['due_date']??''));$time=trim((string)($task['due_time']??''))?:'23:59';
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D',$time))return null;
    $dueAt=DateTimeImmutable::createFromFormat(strlen($time)===5?'!Y-m-d H:i':'!Y-m-d H:i:s',"$date $time",new DateTimeZone('Asia/Kolkata'));$errors=DateTimeImmutable::getLastErrors();
    return $dueAt&&(!is_array($errors)||(!$errors['warning_count']&&!$errors['error_count']))?$dueAt:null;
}

/** @return array{window:int,schedule:string}|null */
function task_notification_overdue_identity(DateTimeImmutable $now,DateTimeImmutable $dueAt,int $cadenceHours):?array
{
    $elapsed=$now->getTimestamp()-$dueAt->getTimestamp();if($elapsed<=0)return null;
    return ['window'=>intdiv($elapsed,max(1,$cadenceHours)*3600),'schedule'=>$dueAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')];
}

function task_notification_generate_reminders(PDO $db, ?DateTimeImmutable $now=null, int $budget=500): array
{
    task_notification_initialize_schema($db);$now=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('Asia/Kolkata'));$cfg=task_notification_config();
    $tasks=$db->query("SELECT t.*,o.series_id,o.occurrence_number,u.status assignee_status FROM portal_tasks t LEFT JOIN task_occurrences o ON o.task_id=t.id LEFT JOIN users u ON u.id=t.assignee_id WHERE t.official_flag=1 AND t.archived_flag=0 AND t.workflow_status NOT IN ('completed','cancelled')")->fetchAll(PDO::FETCH_ASSOC);
    $admins=$db->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.status='active'")->fetchAll(PDO::FETCH_COLUMN);$report=['scanned'=>0,'created'=>0,'deduplicated'=>0,'ineligible'=>0];$today=$now->format('Y-m-d');
    foreach($tasks as $t){if(++$report['scanned']>$budget)break;$rules=[];$due=(string)($t['due_date']??'');$dueAt=task_notification_due_at($t);$employeeOverdue=$dueAt?task_notification_overdue_identity($now,$dueAt,$cfg['employee_overdue_hours']):null;$adminOverdue=$dueAt?task_notification_overdue_identity($now,$dueAt,$cfg['admin_overdue_hours']):null;
        if(($t['assignee_status']??'')==='active'&&$due===$today&&$dueAt&&$now->format('G')>=$cfg['due_today_hour']&&$now<=$dueAt)$rules[]=['due_today',(int)$t['assignee_id'],'employee','Due today',"Due today: “{$t['title']}”.",'warning',$today];
        if(($t['assignee_status']??'')==='active'&&$employeeOverdue)$rules[]=['overdue_employee',(int)$t['assignee_id'],'employee','Task overdue',"Overdue: “{$t['title']}”.",'danger',(string)$employeeOverdue['window'],$employeeOverdue['schedule']];
        $assignment=(string)($t['assigned_at']??'');$age=$assignment===''?null:(int)floor(($now->getTimestamp()-(new DateTimeImmutable($assignment,new DateTimeZone('Asia/Kolkata')))->getTimestamp())/3600);
        if(empty($t['acknowledged_at'])&&$age!==null&&$age>=$cfg['unacknowledged_hours']){if(($t['assignee_status']??'')==='active')$rules[]=['unacknowledged_employee',(int)$t['assignee_id'],'employee','Please acknowledge task',"Please acknowledge “{$t['title']}”.",'warning',(string)floor($age/$cfg['unacknowledged_hours'])];foreach($admins as $a)$rules[]=['unacknowledged_admin',(int)$a,'admin','Assignment not acknowledged',"Assignment not acknowledged: “{$t['title']}”.",'warning',(string)floor($age/$cfg['unacknowledged_hours'])];}
        if($adminOverdue&&in_array($t['workflow_priority'],['high','urgent'],true))foreach($admins as $a)$rules[]=['overdue_admin',(int)$a,'admin','Priority task overdue',"Priority task overdue: “{$t['title']}”.",'danger',(string)$adminOverdue['window'],$adminOverdue['schedule']];
        if($t['workflow_status']==='blocked'){$blocked=(int)floor(($now->getTimestamp()-(new DateTimeImmutable((string)$t['last_activity_at'],new DateTimeZone('Asia/Kolkata')))->getTimestamp())/3600);if($blocked>=$cfg['blocked_hours'])foreach($admins as $a)$rules[]=['blocked_admin',(int)$a,'admin','Task remains blocked',"Blocked task needs attention: “{$t['title']}”.",'danger',(string)floor($blocked/$cfg['blocked_hours'])];}
        foreach($rules as $rule){[$type,$uid,$role,$title,$message,$tone,$window]=$rule;$schedule=$rule[7]??null;$series=(string)($t['series_id']??$t['recurrence_series_id']??'');$key="reminder:$type:task:{$t['id']}:series:$series:occurrence:".(int)($t['occurrence_number']??1).":user:$uid".($schedule!==null?":due:$schedule":'').":window:$window";$link=TaskWorkflowService::taskLink($role,$t);$db->beginTransaction();try{$s=$db->prepare("INSERT OR IGNORE INTO portal_notifications(audience,tone,icon,title,message,link,scope_user_id,created_at,notification_type,category,source_task_id,source_series_id,source_occurrence,deduplication_key,retention_until) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(?, '+' || ? || ' days'))");$stamp=$now->format('Y-m-d H:i:s');$s->execute([$role,$tone,'fa-solid fa-clock',$title,task_notification_safe_text($message),$link,$uid,$stamp,$type,'reminder',(int)$t['id'],$series,(int)($t['occurrence_number']??1),$key,$stamp,$cfg['retention_days']]);if($s->rowCount()){$nid=(int)$db->lastInsertId();$db->prepare("INSERT INTO portal_notification_status(notification_id,user_id,status,updated_at,unread_at) VALUES(?,?,'unread',?,?)")->execute([$nid,$uid,$stamp,$stamp]);$report['created']++;}else $report['deduplicated']++;$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
        if(!$rules)$report['ineligible']++;
    }return $report;
}

function task_notification_fallback(PDO $db): bool
{
    $cfg=task_notification_config();$clock=new DateTimeImmutable('now',new DateTimeZone('UTC'));$now=$clock->format('Y-m-d H:i:s');$until=$clock->modify('+'.$cfg['fallback_seconds'].' seconds')->format('Y-m-d H:i:s');
    try {
        // A losing request performs only this short, indexed lease write.
        $db->beginTransaction();
        $s=$db->prepare("INSERT INTO notification_leases(lease_key,lease_until,updated_at) VALUES('fallback',?,?) ON CONFLICT(lease_key) DO UPDATE SET lease_until=excluded.lease_until,updated_at=excluded.updated_at WHERE notification_leases.lease_until<=?");
        $s->execute([$until,$now,$now]);$won=$s->rowCount()===1;$db->commit();
        if(!$won)return false;
        // Recovery and scheduled scans are separate and independently bounded.
        task_notification_project_pending($db,20);
        task_notification_generate_reminders($db,null,20);
        return true;
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        error_log('Task notification fallback worker failed.');
        return false; // A won lease expires naturally, preventing a hot failure loop.
    }
}

/** Trigger the safety net only for the active canonical user represented by the session. */
function task_notification_authenticated_fallback(PDO $db, ?array $sessionUser): bool
{
    $uid=(int)($sessionUser['id']??0);$role=(string)($sessionUser['role_name']??'');
    if($uid<1||!in_array($role,['admin','employee'],true))return false;
    try{$s=$db->prepare("SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'");$s->execute([$uid]);if($s->fetchColumn()!==$role)return false;return task_notification_fallback($db);}catch(Throwable $e){error_log('Task notification fallback eligibility check failed.');return false;}
}
