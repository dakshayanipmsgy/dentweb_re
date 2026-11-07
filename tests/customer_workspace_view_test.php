<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_workspace_view.php';
$customer = ['name' => 'Example <Customer>', 'mobile' => '9000000000', 'address' => "A long address\nSecond line, Ranchi", 'installed_pv_module_capacity_kwp' => '3.00', 'pin_code' => '000001', 'application_id' => '0', 'meter_number' => ['invalid']];
$before = $customer;
$groups = customer_workspace_profile_groups($customer);
if (count($groups) !== 4 || $before !== $customer) throw new RuntimeException('Profile grouping must be read only');
$fields = [];
foreach ($groups as $group) foreach ($group['rows'] as $row) $fields[$row['key']] = $row;
if ($fields['pin_code']['value'] !== '000001' || $fields['application_id']['value'] !== '0') throw new RuntimeException('Identifiers must remain exact');
if ($fields['meter_number']['display'] !== 'Not recorded yet' || $fields['name']['value'] !== 'Example <Customer>') throw new RuntimeException('Unexpected missing/scalar presentation');
$asset = customer_workspace_asset('assets/css/customer-portal.css');
if (!str_starts_with($asset, 'assets/css/customer-portal.css?v=')) throw new RuntimeException('Asset version missing');
echo "customer_workspace_view_test passed\n";
