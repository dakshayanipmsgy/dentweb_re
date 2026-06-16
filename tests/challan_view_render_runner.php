<?php
declare(strict_types=1);

$id = (string)($argv[1] ?? '');
$mode = (string)($argv[2] ?? 'get');
$csrf = (string)($argv[3] ?? 'test_csrf');

require_once __DIR__ . '/../includes/auth.php';
start_session();
$_SESSION['user'] = ['id' => 'test_admin', 'full_name' => 'Test Admin', 'role_name' => 'admin'];
$_SESSION['csrf_token'] = $csrf;

$_SERVER['REQUEST_METHOD'] = $mode === 'get' ? 'GET' : 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = ['id' => $id];
$_POST = [];
if ($mode === 'save') {
    $_POST = [
        'csrf_token' => $csrf,
        'action' => 'save_operational',
        'delivery_date' => '2026-06-17',
        'dispatch_time' => '10:30',
        'vehicle_no' => 'JH01AB1234',
        'driver_name' => 'Test Driver',
        'driver_mobile' => '9876500000',
        'eway_bill_ref' => 'EWB-610',
        'delivery_notes' => 'Saved by render test',
    ];
} elseif ($mode === 'dispatch') {
    $_POST = [
        'csrf_token' => $csrf,
        'action' => 'mark_dispatched',
        'delivery_date' => '2026-06-17',
        'dispatch_time' => '10:30',
        'vehicle_no' => 'JH01AB1234',
        'driver_name' => 'Test Driver',
        'driver_mobile' => '9876500000',
        'eway_bill_ref' => 'EWB-610',
        'delivery_notes' => 'Dispatched by render test',
    ];
}

include __DIR__ . '/../challan-view.php';
