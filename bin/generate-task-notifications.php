#!/usr/bin/env php
<?php
declare(strict_types=1);if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}require_once __DIR__.'/../includes/bootstrap.php';require_once __DIR__.'/../includes/task_workflow.php';try{$r=task_notification_generate_reminders(get_db());fwrite(STDOUT,sprintf("task-notifications: scanned=%d created=%d deduplicated=%d ineligible=%d\n",$r['scanned'],$r['created'],$r['deduplicated'],$r['ineligible']));exit(0);}catch(Throwable $e){fwrite(STDERR,"task-notifications: failed (see server log)\n");error_log('Task notification cron worker failed.');exit(1);}
