<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_admin.php';
require_once __DIR__ . '/includes/employee_admin.php';
require_once __DIR__ . '/includes/leads.php';

require_admin();
$user = current_user() ?? [];

function docs2_safe(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function docs2_now(): string { return date(DATE_ATOM); }
function docs2_data_dir(): string { return __DIR__ . '/data/docs'; }
function docs2_read_json(string $p, array $d): array { if (!is_file($p)) return $d; $raw=file_get_contents($p); $j=json_decode((string)$raw,true); return is_array($j)?$j:$d; }
function docs2_write_json(string $p, array $data): bool { $dir=dirname($p); if(!is_dir($dir)){mkdir($dir,0775,true);} $enc=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); return is_string($enc) && file_put_contents($p,$enc."\n",LOCK_EX)!==false; }
function docs2_default_deal(): array { return ['id'=>'','source_type'=>'customer','source_id'=>'','customer_mobile'=>'','lead_id'=>'','party'=>['name'=>'','mobile'=>'','email'=>'','address'=>'','city'=>'','district'=>'','state'=>'Jharkhand','pin_code'=>''],'segment'=>'RES','system_type'=>'ongrid','capacity_kwp'=>'','title'=>'','notes'=>'','template_set_id'=>'','status'=>'Open','assigned_to_employee_id'=>'','assigned_to_employee_name'=>'','created_by_type'=>'admin','created_by_id'=>'','created_at'=>'','updated_at'=>'']; }
function docs2_load_deals(): array { $p=docs2_data_dir().'/deals.json'; $data=docs2_read_json($p,['deals'=>[]]); return is_array($data['deals']??null)?$data['deals']:[]; }
function docs2_save_deals(array $deals): bool { return docs2_write_json(docs2_data_dir().'/deals.json',['deals'=>array_values($deals)]); }
function docs2_load_template_sets(): array { $d=docs2_read_json(docs2_data_dir().'/template_sets.json',['template_sets'=>[]]); return is_array($d['template_sets']??null)?$d['template_sets']:[]; }
function docs2_load_quotations(): array { $d=docs2_read_json(docs2_data_dir().'/quotations.json',['quotations'=>[]]); return is_array($d['quotations']??null)?$d['quotations']:[]; }
function docs2_employee_name(array $employees, string $id): string { foreach($employees as $e){ if((string)($e['id']??'')===$id){ return (string)($e['name']??''); } } return ''; }
function docs2_generate_id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(6)); }

if (!is_file(docs2_data_dir() . '/deals.json')) { docs2_write_json(docs2_data_dir() . '/deals.json', ['deals' => []]); }
if (!is_file(docs2_data_dir() . '/quotations.json')) { docs2_write_json(docs2_data_dir() . '/quotations.json', ['quotations' => []]); }

$customerStore = new CustomerFsStore();
$employeeStore = new EmployeeFsStore();
$customers = $customerStore->listCustomers();
$employees = $employeeStore->listEmployees();
$leads = load_all_leads();
$templateSets = docs2_load_template_sets();
$messages=[];$errors=[];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $csrf=$_POST['csrf_token']??null;
    if(!verify_csrf_token(is_string($csrf)?$csrf:null)){
        $errors[]='Invalid request token.';
    } else {
        $action=(string)($_POST['action']??'');
        if($action==='create_deal'){
            $deal=docs2_default_deal();
            $deal['id']=docs2_generate_id('deal');
            $deal['source_type']=(string)($_POST['source_type']??'manual');
            $deal['source_id']=trim((string)($_POST['source_id']??''));
            $deal['customer_mobile']=trim((string)($_POST['customer_mobile']??''));
            $deal['lead_id']=trim((string)($_POST['lead_id']??''));
            $deal['segment']=strtoupper(trim((string)($_POST['segment']??'RES')));
            $deal['system_type']=trim((string)($_POST['system_type']??'ongrid'));
            $deal['capacity_kwp']=trim((string)($_POST['capacity_kwp']??''));
            $deal['title']=trim((string)($_POST['title']??''));
            $deal['notes']=trim((string)($_POST['notes']??''));
            $deal['template_set_id']=trim((string)($_POST['template_set_id']??''));
            $deal['status']='Open';
            $deal['assigned_to_employee_id']=trim((string)($_POST['assigned_to_employee_id']??''));
            $deal['assigned_to_employee_name']=docs2_employee_name($employees,$deal['assigned_to_employee_id']);
            $deal['created_by_type']='admin';
            $deal['created_by_id']=(string)($user['id']??'admin');
            $deal['created_at']=docs2_now();
            $deal['updated_at']=docs2_now();

            $party=[
                'name'=>trim((string)($_POST['party_name']??'')),
                'mobile'=>trim((string)($_POST['party_mobile']??'')),
                'email'=>trim((string)($_POST['party_email']??'')),
                'address'=>trim((string)($_POST['party_address']??'')),
                'city'=>trim((string)($_POST['party_city']??'')),
                'district'=>trim((string)($_POST['party_district']??'')),
                'state'=>trim((string)($_POST['party_state']??'Jharkhand')),
                'pin_code'=>trim((string)($_POST['party_pin_code']??'')),
            ];

            if($deal['source_type']==='customer'){
                foreach($customers as $c){
                    if((string)($c['mobile']??'')===$deal['customer_mobile']){
                        $party['name']=(string)($c['name']??$party['name']);
                        $party['mobile']=(string)($c['mobile']??$party['mobile']);
                        $party['address']=(string)($c['address']??$party['address']);
                        $party['city']=(string)($c['city']??$party['city']);
                        $party['district']=(string)($c['district']??$party['district']);
                        $party['state']=(string)($c['state']??$party['state']);
                        $party['pin_code']=(string)($c['pin_code']??$party['pin_code']);
                        break;
                    }
                }
            } elseif($deal['source_type']==='lead'){
                foreach($leads as $l){
                    if((string)($l['id']??'')===$deal['lead_id']){
                        $party['name']=(string)($l['name']??$party['name']);
                        $party['mobile']=(string)($l['mobile']??$party['mobile']);
                        $party['address']=(string)($l['area_or_locality']??$party['address']);
                        $party['city']=(string)($l['city']??$party['city']);
                        $party['state']=(string)($l['state']??$party['state']);
                        break;
                    }
                }
            }
            $deal['party']=$party;

            if($party['name']===''){$errors[]='Party name is required.';}
            if($deal['assigned_to_employee_id']===''){$errors[]='Assigned employee is required.';}
            if($errors===[]){
                $deals=docs2_load_deals();
                $deals[]=$deal;
                if(docs2_save_deals($deals)){$messages[]='Deal created successfully.';} else {$errors[]='Failed to save deal.';}
            }
        }
    }
}

$deals=docs2_load_deals();
$qtns=docs2_load_quotations();
$qCount=[];
foreach($qtns as $q){$d=(string)($q['deal_id']??''); if($d!==''){ $qCount[$d]=($qCount[$d]??0)+1; }}

$fltEmp=trim((string)($_GET['assigned_to']??''));
$fltSeg=trim((string)($_GET['segment']??''));
$fltStatus=trim((string)($_GET['status']??''));
$fltSearch=strtolower(trim((string)($_GET['search']??'')));
$filtered=array_values(array_filter($deals, static function(array $d) use($fltEmp,$fltSeg,$fltStatus,$fltSearch): bool {
    if($fltEmp!=='' && (string)($d['assigned_to_employee_id']??'')!==$fltEmp) return false;
    if($fltSeg!=='' && (string)($d['segment']??'')!==$fltSeg) return false;
    if($fltStatus!=='' && (string)($d['status']??'')!==$fltStatus) return false;
    if($fltSearch!==''){
        $hay=strtolower((string)($d['id']??'').' '.(string)($d['title']??'').' '.(string)($d['party']['name']??'').' '.(string)($d['party']['mobile']??''));
        if(!str_contains($hay,$fltSearch)) return false;
    }
    return true;
}));

?>
<!doctype html><html><head><meta charset="utf-8"><title>Docs Deals Admin</title><link rel="stylesheet" href="style.css"><style>body{background:#f5f7fb;font-family:Arial,sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:16px}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:14px;margin-bottom:16px}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.msg{padding:8px;border-radius:8px;margin:6px 0}.ok{background:#ecfdf3}.err{background:#fef2f2}</style></head><body><div class="wrap">
<h1>Docs Deals (Admin)</h1><p><a href="admin-dashboard.php">&larr; Dashboard</a></p>
<?php foreach($messages as $m):?><div class="msg ok"><?=docs2_safe($m)?></div><?php endforeach;?>
<?php foreach($errors as $e):?><div class="msg err"><?=docs2_safe($e)?></div><?php endforeach;?>
<div class="card"><h3>Create Deal</h3><form method="post"><input type="hidden" name="csrf_token" value="<?=docs2_safe($_SESSION['csrf_token']??'')?>"><input type="hidden" name="action" value="create_deal">
<div class="grid">
<label>Source type<select name="source_type"><option value="customer">Customer</option><option value="lead">Lead</option><option value="manual">Manual</option></select></label>
<label>Customer<select name="customer_mobile"><option value="">--</option><?php foreach($customers as $c):?><option value="<?=docs2_safe((string)($c['mobile']??''))?>"><?=docs2_safe((string)($c['name']??''))?> (<?=docs2_safe((string)($c['mobile']??''))?>)</option><?php endforeach;?></select></label>
<label>Lead<select name="lead_id"><option value="">--</option><?php foreach($leads as $l):?><option value="<?=docs2_safe((string)($l['id']??''))?>"><?=docs2_safe((string)($l['name']??''))?> (<?=docs2_safe((string)($l['mobile']??''))?>)</option><?php endforeach;?></select></label>
<label>Assigned employee<select name="assigned_to_employee_id" required><option value="">Select</option><?php foreach($employees as $e):?><option value="<?=docs2_safe((string)($e['id']??''))?>"><?=docs2_safe((string)($e['name']??''))?></option><?php endforeach;?></select></label>
<label>Party name<input name="party_name"></label><label>Party mobile<input name="party_mobile"></label><label>Party email<input name="party_email"></label><label>Address<input name="party_address"></label>
<label>City<input name="party_city"></label><label>District<input name="party_district"></label><label>State<input name="party_state" value="Jharkhand"></label><label>PIN<input name="party_pin_code"></label>
<label>Segment<select name="segment"><option>RES</option><option>COM</option><option>IND</option><option>INST</option><option>PROD</option></select></label>
<label>System<select name="system_type"><option>ongrid</option><option>hybrid</option><option>offgrid</option><option>product</option></select></label>
<label>Capacity kWp<input name="capacity_kwp"></label><label>Template set<select name="template_set_id"><option value="">--</option><?php foreach($templateSets as $t):?><option value="<?=docs2_safe((string)($t['id']??''))?>"><?=docs2_safe((string)($t['name']??''))?></option><?php endforeach;?></select></label>
<label>Title<input name="title"></label><label>Notes<input name="notes"></label>
</div><p><button type="submit">Save Deal</button></p></form></div>

<div class="card"><h3>Deals</h3><form method="get" class="grid"><label>Employee<select name="assigned_to"><option value="">All</option><?php foreach($employees as $e):?><option value="<?=docs2_safe((string)($e['id']??''))?>" <?=((string)($e['id']??'')===$fltEmp)?'selected':''?>><?=docs2_safe((string)($e['name']??''))?></option><?php endforeach;?></select></label><label>Segment<input name="segment" value="<?=docs2_safe($fltSeg)?>"></label><label>Status<input name="status" value="<?=docs2_safe($fltStatus)?>"></label><label>Search<input name="search" value="<?=docs2_safe($fltSearch)?>"></label><div><button type="submit">Filter</button></div></form>
<table><thead><tr><th>ID</th><th>Party</th><th>Segment</th><th>Status</th><th>Assigned</th><th>Quotations</th><th>Actions</th></tr></thead><tbody><?php foreach($filtered as $d):?><tr><td><?=docs2_safe((string)($d['id']??''))?></td><td><?=docs2_safe((string)($d['party']['name']??''))?><br><small><?=docs2_safe((string)($d['party']['mobile']??''))?></small></td><td><?=docs2_safe((string)($d['segment']??''))?> / <?=docs2_safe((string)($d['system_type']??''))?></td><td><?=docs2_safe((string)($d['status']??''))?></td><td><?=docs2_safe((string)($d['assigned_to_employee_name']??''))?></td><td><?=docs2_safe((string)($qCount[(string)($d['id']??'')]??0))?></td><td><a href="doc-quotation-edit.php?deal_id=<?=urlencode((string)($d['id']??''))?>">Create Quotation</a> | <a href="employee-docs-deals.php?deal_id=<?=urlencode((string)($d['id']??''))?>">View Quotes</a></td></tr><?php endforeach;?></tbody></table>
</div>
</div></body></html>
