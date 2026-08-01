<?php
declare(strict_types=1);
function browser_accept(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$bell=file_get_contents(__DIR__.'/../includes/notification_bell.php');$centre=file_get_contents(__DIR__.'/../notifications.php');
browser_accept(str_contains($bell,'if(running||document.hidden)return')&&str_contains($bell,'running=true')&&str_contains($bell,'finally{clearTimeout(timeout);running=false;}'),'polls cannot overlap');
browser_accept(str_contains($bell,'if(!document.hidden)timer=setInterval')&&str_contains($bell,"if(!document.hidden)refresh()"),'hidden polling stops and foreground refreshes immediately');
browser_accept(str_contains($bell,'c.hidden=n===0')&&str_contains($bell,"n>99?'99+':String(n)"),'badge handles zero, 1-99, and 99+');
browser_accept(str_contains($bell,'catch(e){}')&&str_contains($bell,'href="notifications.php"'),'temporary count failure does not replace navigation');
foreach(["action==='read'","?'read':'unread'","action==='dismiss'","action==='read-all'"] as $marker)browser_accept(str_contains($centre,$marker),$marker.' mutation hook');
browser_accept(str_contains($bell,"aria-label")&&str_contains($centre,'aria-live="polite"')&&str_contains($centre,"live.textContent"),'accessible labels and live announcements');
browser_accept(str_contains($centre,'overflow-x:hidden')&&str_contains($centre,'@media(max-width:480px)'),'narrow layout guards horizontal overflow');
echo "task notification browser acceptance source checks passed\n";
