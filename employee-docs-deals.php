<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
start_session();
$u=$_SESSION['user']??[];
$isAdmin=(($u['role_name']??'')==='admin');
$isEmployee=!$isAdmin && (!empty($_SESSION['employee_logged_in']) || (($u['role_name']??'')==='employee'));
if(!$isAdmin && !$isEmployee){ header('Location: login.php'); exit; }
$employeeId=(string)($_SESSION['employee_id'] ?? ($u['id']??''));
function e_safe(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function e_read(string $p,array $d): array { if(!is_file($p)) return $d; $j=json_decode((string)file_get_contents($p),true); return is_array($j)?$j:$d; }
$deals=e_read(__DIR__.'/data/docs/deals.json',['deals'=>[]])['deals']??[];
$quotes=e_read(__DIR__.'/data/docs/quotations.json',['quotations'=>[]])['quotations']??[];
$dealMap=[]; foreach($deals as $d){$dealMap[(string)($d['id']??'')]=$d;}
$myDeals=array_values(array_filter($deals, static function(array $d) use($isAdmin,$employeeId): bool {
  if($isAdmin) return true;
  return (string)($d['assigned_to_employee_id']??'')===$employeeId || ((string)($d['created_by_type']??'')==='employee' && (string)($d['created_by_id']??'')===$employeeId);
}));
$myDealIds=array_flip(array_map(static fn(array $d): string => (string)($d['id']??''),$myDeals));
$myQuotes=array_values(array_filter($quotes, static function(array $q) use($isAdmin,$myDealIds): bool { if($isAdmin) return true; return isset($myDealIds[(string)($q['deal_id']??'')]); }));
?>
<!doctype html><html><head><meta charset="utf-8"><title>Docs Deals</title><link rel="stylesheet" href="style.css"><style>body{background:#f6f8fc}.wrap{max-width:1100px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}</style></head><body><div class="wrap">
<h1>Docs Deals</h1><p><a href="<?= $isAdmin ? 'admin-dashboard.php':'employee-dashboard.php' ?>">&larr; Dashboard</a></p>
<div class="card"><h3>My Deals</h3><table><thead><tr><th>ID</th><th>Party</th><th>Segment</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($myDeals as $d):?><tr><td><?=e_safe((string)($d['id']??''))?></td><td><?=e_safe((string)($d['party']['name']??''))?></td><td><?=e_safe((string)($d['segment']??''))?>/<?=e_safe((string)($d['system_type']??''))?></td><td><?=e_safe((string)($d['status']??''))?></td><td><a href="doc-quotation-edit.php?deal_id=<?=urlencode((string)($d['id']??''))?>">Create Quotation</a></td></tr><?php endforeach;?></tbody></table></div>
<div class="card"><h3>Quotations</h3><table><thead><tr><th>Quote No</th><th>Deal</th><th>Status</th><th>Revision</th><th>Actions</th></tr></thead><tbody><?php foreach($myQuotes as $q):?><tr><td><?=e_safe((string)($q['quote_no']??''))?></td><td><?=e_safe((string)($q['deal_id']??''))?></td><td><?=e_safe((string)($q['status']??''))?></td><td><?=e_safe((string)($q['revision']??0))?></td><td><a href="doc-quotation-edit.php?id=<?=urlencode((string)($q['id']??''))?>">Edit</a> | <a href="doc-view.php?type=quotation&id=<?=urlencode((string)($q['id']??''))?>">View</a> | <a href="doc-pdf-quotation.php?id=<?=urlencode((string)($q['id']??''))?>">Generate PDF</a></td></tr><?php endforeach;?></tbody></table></div>
</div></body></html>
