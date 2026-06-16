<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/documents_helpers.php';
require_once __DIR__ . '/customer_document_acceptance.php';

function render_challan_document(array $challan, array $company = [], bool $public = false): void
{
    $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $companyName = trim((string)($company['brand_name'] ?? $company['company_name'] ?? '')) ?: 'Dakshayani Enterprises';
    $logo = (string)($company['logo_path'] ?? $company['logo'] ?? '');
    $items = documents_normalize_challan_items((array)($challan['items'] ?? []));
    $customer = (array)($challan['customer_snapshot'] ?? []);
    echo '<article class="document challan-document">';
    echo '<header class="document-header"><div>';
    if ($logo !== '') echo '<img class="company-logo" src="'.$e($logo).'" alt="Company logo">';
    echo '<h1>'.$e($companyName).'</h1><p>'.$e($company['address'] ?? $company['registered_address'] ?? '').'</p><p>'.$e($company['phone_primary'] ?? $company['phone'] ?? '').' · '.$e($company['email'] ?? '').'</p>';
    if (!empty($company['gstin'])) echo '<p>GSTIN: '.$e($company['gstin']).'</p>';
    echo '</div><div class="document-title"><h2>Delivery Challan</h2><p><b>'.$e($challan['challan_no'] ?? $challan['dc_number'] ?? '').'</b></p><p>Date: '.$e($challan['delivery_date'] ?? '').'</p></div></header>';
    echo '<section class="meta"><div><b>Customer</b><br>'.$e($customer['name'] ?? $challan['customer_name_snapshot'] ?? '').'</div><div><b>Mobile</b><br>'.$e($public ? customer_acceptance_mask_mobile((string)($challan['customer_mobile'] ?? $customer['mobile'] ?? '')) : ($challan['customer_mobile'] ?? $customer['mobile'] ?? '')).'</div><div><b>Quotation</b><br>'.$e($challan['linked_quote_no'] ?? $challan['quotation_no'] ?? '').'</div><div><b>Dispatch Advice</b><br>'.$e($challan['dispatch_advice_no'] ?? '').'</div></section>';
    echo '<section><h3>Delivery details</h3><p>'.$e($challan['delivery_address'] ?? $challan['site_address'] ?? '').'</p>';
    $vehicle = trim((string)($challan['vehicle_no'] ?? '')); $driver = trim((string)($challan['driver_name'] ?? ''));
    if ($vehicle !== '' || $driver !== '') echo '<p>Vehicle: '.$e($vehicle).' &nbsp; Driver: '.$e($driver).'</p>';
    echo '</section><table><thead><tr><th>#</th><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Remarks</th></tr></thead><tbody>';
    $i=1; foreach ($items as $item) { echo '<tr><td>'.$i++.'</td><td>'.$e($item['name'] ?? '').'</td><td>'.$e($item['description'] ?? '').'</td><td>'.$e($item['qty'] ?? '').'</td><td>'.$e($item['unit'] ?? '').'</td><td>'.$e($item['remarks'] ?? '').'</td></tr>'; }
    if ($items === []) echo '<tr><td colspan="6">No customer-facing items listed.</td></tr>';
    echo '</tbody></table><footer>Authorised Signatory</footer></article>';
}
