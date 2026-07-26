<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_bulk_import.php';

function header_normalization_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

$aliases=['mobile','Mobile Number','mobile.no','mobile-no','PHONE','Phone Number','Customer Mobile','Contact Number'];
foreach ($aliases as $alias) header_normalization_assert(customer_bulk_normalise_header($alias)==='mobile', $alias.' maps to mobile');
$parsed=customer_bulk_parse_csv("\xEF\xBB\xBF\n\nMobile Number;Customer Name;Pin Code\n9876543210;A Customer;834001\n");
header_normalization_assert($parsed['headers']===['mobile','customer_name','pin_code'],'BOM, blank lines, case, spaces and semicolon are handled');
header_normalization_assert($parsed['delimiter']===';','semicolon detected');
$tab=customer_bulk_parse_csv("Phone\tName\n9876543210\tTab Customer\n");
header_normalization_assert($tab['delimiter']==="\t" && $tab['headers'][0]==='mobile','Google Sheets tab export detected');
$excel=customer_bulk_parse_csv("sep=;\nphone_number;name\n9876543210;Excel Customer\n");
header_normalization_assert($excel['delimiter']===';' && $excel['headers'][0]==='mobile','Excel separator directive supported');

$dir=sys_get_temp_dir().'/dentweb-header-'.bin2hex(random_bytes(4));
$store=new CustomerFsStore($dir);
$duplicate=customer_bulk_mobile_sync_preview($store,"phone,mobile-number,name\n9876543210,9876543210,A\n",[]);
header_normalization_assert(str_contains($duplicate['error'],'duplicate canonical'),'aliases cannot create duplicate canonical headers');
$missing=customer_bulk_mobile_sync_preview($store,"Name;City\nA;Ranchi\n",[]);
header_normalization_assert(str_contains($missing['error'],'Name, City') && str_contains($missing['error'],'semicolon'),'missing-mobile error reports detected headers and delimiter');
@unlink($dir.'/customers.json'); @unlink($dir.'/customers.lock'); @rmdir($dir);
echo "customer_csv_header_normalization_test: ok\n";
