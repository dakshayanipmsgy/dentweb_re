<?php
declare(strict_types=1);

/** Presentation only: keep stored values and all billing calculations unchanged. */
function customer_workspace_profile_groups(array $customer): array
{
    $groups = [
        'Account & contact' => ['name' => 'Customer name', 'mobile' => 'Registered mobile', 'customer_type' => 'Customer type'],
        'Installation address' => ['address' => 'Address', 'city' => 'City', 'district' => 'District', 'state' => 'State', 'pin_code' => 'PIN code'],
        'Electricity & application' => ['jbvnl_account_number' => 'Electricity account number', 'meter_number' => 'Meter number', 'meter_serial_number' => 'Meter serial number', 'application_id' => 'Application ID', 'circle_name' => 'Circle', 'division_name' => 'Division', 'sub_division_name' => 'Sub-division'],
        'Solar installation' => ['installed_pv_module_capacity_kwp' => 'Installed capacity (kWp)', 'sanction_load_kwp' => 'Sanctioned load (kW)', 'solar_plant_installation_date' => 'Installation date'],
    ];
    $result = [];
    foreach ($groups as $title => $fields) {
        $rows = [];
        foreach ($fields as $key => $label) {
            $value = is_scalar($customer[$key] ?? null) ? trim((string) $customer[$key]) : '';
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value, 'display' => $value !== '' ? $value : 'Not recorded yet'];
        }
        $result[] = ['title' => $title, 'rows' => $rows];
    }
    return $result;
}

function customer_workspace_asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return $path . '?v=' . rawurlencode($version);
}
