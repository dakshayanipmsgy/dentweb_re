<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/documents_helpers.php';

require_admin();

$isAdmin = true;

$docTypes = ['quotation', 'proforma', 'agreement', 'challan', 'invoice_public', 'invoice_internal', 'receipt', 'sales_return'];
$segments = ['RES', 'COM', 'IND', 'INST', 'PROD'];

$companyPath = documents_company_profile_path();
$numberingPath = documents_settings_dir() . '/numbering_rules.json';
$templatePath = documents_templates_dir() . '/template_sets.json';

documents_ensure_structure();
documents_seed_template_sets_if_empty();

$user = current_user();

$redirectWith = static function (string $tab, string $type, string $msg): void {
    $query = http_build_query([
        'tab' => $tab,
        'status' => $type,
        'message' => $msg,
    ]);
    header('Location: admin-documents.php?' . $query);
    exit;
};

$redirectDocuments = static function (string $tab, string $type, string $msg, array $extra = []): void {
    $query = array_merge([
        'tab' => $tab,
        'status' => $type,
        'message' => $msg,
    ], $extra);
    header('Location: admin-documents.php?' . http_build_query($query));
    exit;
};

$isArchivedRecord = static function (array $row): bool {
    return documents_is_archived($row);
};

$inr = static function (float $amount): string {
    $negative = $amount < 0;
    $amount = abs($amount);
    $parts = explode('.', number_format($amount, 2, '.', ''));
    $int = $parts[0];
    $decimal = $parts[1] ?? '00';
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
        $int = $rest . ',' . $last3;
    }
    return ($negative ? '-₹' : '₹') . $int . '.' . $decimal;
};

$documentTypeMap = [
    'agreement' => 'agreement',
    'receipt' => 'receipt',
    'delivery_challan' => 'delivery_challan',
    'proforma' => 'proforma',
    'invoice' => 'invoice',
];

$documentTypeLabel = [
    'quotation' => 'Quotation',
    'agreement' => 'Agreement',
    'receipt' => 'Receipt',
    'delivery_challan' => 'Delivery Challan',
    'proforma' => 'Proforma Invoice',
    'invoice' => 'Invoice',
];

$resolveAgreementTemplateId = static function (array $quote, array $templates): string {
    $activeTemplates = array_filter($templates, static fn($row): bool => is_array($row) && !documents_is_archived($row));
    if ($activeTemplates === []) {
        $activeTemplates = documents_agreement_template_defaults();
    }

    foreach ($activeTemplates as $row) {
        if (is_array($row) && !empty($row['is_default'])) {
            return (string) ($row['id'] ?? 'default_pm_surya_ghar_agreement');
        }
    }

    $schemeSignals = [
        (string) ($quote['project_type'] ?? ''),
        (string) ($quote['scheme_type'] ?? ''),
        (string) ($quote['customer_type'] ?? ''),
        (string) ($quote['project_summary_line'] ?? ''),
    ];
    $isPm = false;
    foreach ($schemeSignals as $signal) {
        if (str_contains(strtolower($signal), 'pm surya')) {
            $isPm = true;
            break;
        }
    }

    if ($isPm && isset($activeTemplates['default_pm_surya_ghar_agreement'])) {
        return 'default_pm_surya_ghar_agreement';
    }

    if (isset($activeTemplates['default_agreement'])) {
        return 'default_agreement';
    }

    if (isset($activeTemplates['default_pm_surya_ghar_agreement'])) {
        return 'default_pm_surya_ghar_agreement';
    }

    $first = reset($activeTemplates);
    return is_array($first) ? (string) ($first['id'] ?? 'default_pm_surya_ghar_agreement') : 'default_pm_surya_ghar_agreement';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirectWith('company', 'error', 'Security validation failed. Please retry.');
    }

    $action = safe_text($_POST['action'] ?? '');

    if ($action === 'save_company_profile') {
        $profile = load_company_profile();

        $fields = array_keys(documents_company_profile_defaults());
        foreach ($fields as $field) {
            if (in_array($field, ['logo_path', 'updated_at', 'bank'], true)) {
                continue;
            }
            $profile[$field] = safe_text($_POST[$field] ?? '');
        }

        $profile['bank'] = documents_normalize_company_bank_details([
            'bank_name' => safe_text($_POST['bank_name'] ?? ''),
            'account_name' => safe_text($_POST['bank_account_name'] ?? ''),
            'account_no' => safe_text($_POST['bank_account_no'] ?? ''),
            'ifsc' => safe_text($_POST['bank_ifsc'] ?? ''),
            'branch' => safe_text($_POST['bank_branch'] ?? ''),
            'upi_id' => safe_text($_POST['upi_id'] ?? ''),
        ]);

        if (isset($_FILES['company_logo_upload']) && (int) ($_FILES['company_logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = documents_handle_image_upload($_FILES['company_logo_upload'], documents_public_branding_dir(), 'logo_');
            if (!$upload['ok']) {
                $redirectWith('company', 'error', (string) $upload['error']);
            }
            $profile['logo_path'] = '/images/documents/branding/' . $upload['filename'];
        }

        $waDigits = preg_replace('/\D+/', '', (string) ($profile['whatsapp_number'] ?? '')) ?? '';
        if ($waDigits !== '' && (strlen($waDigits) < 10 || strlen($waDigits) > 12)) {
            $redirectWith('company', 'error', 'WhatsApp number must contain 10 to 12 digits.');
        }

        $pan = strtoupper((string) ($profile['pan'] ?? ''));
        $panWarning = ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan));

        $saved = save_company_profile($profile);
        if (!$saved['ok']) {
            $redirectWith('company', 'error', 'Unable to save company profile.');
        }

        $msg = $panWarning
            ? 'Company profile saved. Warning: PAN format looks unusual (expected ABCDE1234F).'
            : 'Company profile saved successfully.';
        $redirectWith('company', 'success', $msg);
    }

    if (in_array($action, ['create_agreement', 'create_receipt', 'create_delivery_challan', 'create_pi', 'create_invoice'], true)) {
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['quotation_id'] ?? safe_text($_POST['return_view'] ?? ''));
        if ($view === '') {
            $redirectDocuments($tab, 'error', 'Quotation is required.');
        }

        $quote = documents_get_quote($view);
        if ($quote === null) {
            $redirectDocuments($tab, 'error', 'Quotation not found.', ['view' => $view]);
        }
        $quote = documents_quote_prepare($quote);
        $snapshot = documents_quote_resolve_snapshot($quote);
        $companyProfile = load_company_profile();
        $viewer = [
            'type' => 'admin',
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['full_name'] ?? 'Admin'),
        ];

        if ($action === 'create_agreement') {
            $existingAgreementId = safe_text((string) ($quote['workflow']['agreement_id'] ?? ''));
            if ($existingAgreementId !== '') {
                $existingAgreement = documents_get_sales_document('agreement', $existingAgreementId);
                if ($existingAgreement !== null && !documents_is_archived($existingAgreement)) {
                    header('Location: agreement-view.php?id=' . urlencode($existingAgreementId) . '&status=success&message=' . urlencode('Agreement already exists.'));
                    exit;
                }
            }

            $number = documents_generate_agreement_number(safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES');
            if (!$number['ok']) {
                $redirectDocuments($tab, 'error', (string) ($number['error'] ?? 'Unable to generate agreement number.'), ['view' => $view]);
            }

            $templates = documents_get_agreement_templates();
            $templateId = $resolveAgreementTemplateId($quote, $templates);
            $templateRow = $templates[$templateId] ?? null;
            if (!is_array($templateRow)) {
                $defaults = documents_agreement_template_defaults();
                $templateRow = $defaults['default_pm_surya_ghar_agreement'] ?? [];
                $templateId = (string) ($templateRow['id'] ?? 'default_pm_surya_ghar_agreement');
            }

            $agreement = documents_agreement_defaults();
            $agreement['id'] = 'agr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $agreement['agreement_no'] = (string) ($number['agreement_no'] ?? '');
            $agreement['status'] = 'Draft';
            $agreement['template_id'] = $templateId;
            $agreement['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $agreement['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $agreement['consumer_account_no'] = safe_text((string) ($quote['consumer_account_no'] ?? $snapshot['consumer_account_no'] ?? ''));
            $agreement['consumer_address'] = safe_text((string) ($snapshot['address'] ?? ''));
            $agreement['site_address'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $agreement['execution_date'] = date('Y-m-d');
            $agreement['system_capacity_kwp'] = safe_text((string) ($quote['capacity_kwp'] ?? ''));
            $agreement['total_cost'] = documents_format_money_indian((float) ($quote['calc']['gross_payable'] ?? $quote['input_total_gst_inclusive'] ?? 0));
            $agreement['linked_quote_id'] = (string) ($quote['id'] ?? '');
            $agreement['linked_quote_no'] = (string) ($quote['quote_no'] ?? '');
            $agreement['district'] = safe_text((string) ($quote['district'] ?? $snapshot['district'] ?? ''));
            $agreement['city'] = safe_text((string) ($quote['city'] ?? $snapshot['city'] ?? ''));
            $agreement['state'] = safe_text((string) ($quote['state'] ?? $snapshot['state'] ?? ''));
            $agreement['pin_code'] = safe_text((string) ($quote['pin'] ?? $snapshot['pin_code'] ?? ''));
            $agreement['created_by_type'] = 'admin';
            $agreement['created_by_id'] = $viewer['id'];
            $agreement['created_by_name'] = $viewer['name'];
            $agreement['created_at'] = date('c');
            $agreement['updated_at'] = date('c');

            $savedAgreement = documents_save_agreement($agreement);
            if (!$savedAgreement['ok']) {
                $redirectDocuments($tab, 'error', 'Failed to create agreement draft.', ['view' => $view]);
            }

            $renderedAgreementHtml = documents_render_agreement_body_html($agreement, $companyProfile);

            $salesAgreement = documents_sales_document_defaults('agreement');
            $salesAgreement['id'] = (string) $agreement['id'];
            $salesAgreement['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesAgreement['customer_mobile'] = (string) $agreement['customer_mobile'];
            $salesAgreement['customer_name'] = (string) $agreement['customer_name'];
            $salesAgreement['execution_date'] = (string) $agreement['execution_date'];
            $salesAgreement['agreement_no'] = (string) $agreement['agreement_no'];
            $salesAgreement['template_id'] = $templateId;
            $salesAgreement['template_name'] = (string) ($templateRow['name'] ?? 'Agreement Template');
            $salesAgreement['html_rendered'] = $renderedAgreementHtml;
            $salesAgreement['status'] = 'draft';
            $salesAgreement['created_by'] = $viewer;
            $salesAgreement['created_at'] = (string) $agreement['created_at'];
            $savedSalesAgreement = documents_save_sales_document('agreement', $salesAgreement);
            if (!$savedSalesAgreement['ok']) {
                $redirectDocuments($tab, 'error', 'Failed to update agreement workflow.', ['view' => $view]);
            }

            documents_quote_link_workflow_doc($quote, 'agreement', (string) $agreement['id']);
            $quote['updated_at'] = date('c');
            $savedQuote = documents_save_quote($quote);
            if (!$savedQuote['ok']) {
                $redirectDocuments($tab, 'error', 'Agreement created, but quotation workflow update failed.', ['view' => $view]);
            }

            header('Location: agreement-view.php?id=' . urlencode((string) $agreement['id']) . '&status=success&message=' . urlencode('Agreement created from default template.'));
            exit;
        }

        if ($action === 'create_receipt') {
            $receipt = documents_sales_document_defaults('receipt');
            $receipt['id'] = documents_generate_simple_document_id('rcpt');
            $receipt['quotation_id'] = (string) ($quote['id'] ?? '');
            $receipt['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $receipt['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $receipt['receipt_date'] = date('Y-m-d');
            $receipt['amount_received'] = '';
            $receipt['mode'] = '';
            $receipt['reference'] = '';
            $receipt['status'] = 'draft';
            $receipt['created_by'] = $viewer;
            $receipt['created_at'] = date('c');

            $saved = documents_save_sales_document('receipt', $receipt);
            if (!$saved['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to create receipt draft.', ['view' => $view]);
            }
            documents_quote_link_workflow_doc($quote, 'receipt', (string) $receipt['id']);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            $redirectDocuments($tab, 'success', 'Receipt draft created.', ['view' => $view]);
        }

        if ($action === 'create_delivery_challan') {
            $challan = documents_challan_defaults();
            $number = documents_generate_challan_number(safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES');
            if (!$number['ok']) {
                $redirectDocuments($tab, 'error', (string) ($number['error'] ?? 'Unable to create challan number.'), ['view' => $view]);
            }
            $challan['id'] = 'dc_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            $challan['challan_no'] = (string) ($number['challan_no'] ?? '');
            $challan['status'] = 'Draft';
            $challan['linked_quote_id'] = (string) ($quote['id'] ?? '');
            $challan['linked_quote_no'] = (string) ($quote['quote_no'] ?? '');
            $challan['segment'] = safe_text((string) ($quote['segment'] ?? 'RES')) ?: 'RES';
            $challan['customer_snapshot'] = $snapshot;
            $challan['site_address'] = safe_text((string) ($quote['site_address'] ?? $snapshot['address'] ?? ''));
            $challan['delivery_address'] = $challan['site_address'];
            $challan['delivery_date'] = date('Y-m-d');
            $challan['items'] = [];
            $challan['created_by_type'] = 'admin';
            $challan['created_by_id'] = $viewer['id'];
            $challan['created_by_name'] = $viewer['name'];
            $challan['created_at'] = date('c');
            $challan['updated_at'] = date('c');

            $savedChallan = documents_save_challan($challan);
            if (!$savedChallan['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to create delivery challan draft.', ['view' => $view]);
            }

            $salesChallan = documents_sales_document_defaults('delivery_challan');
            $salesChallan['id'] = (string) $challan['id'];
            $salesChallan['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesChallan['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesChallan['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesChallan['challan_no'] = (string) $challan['challan_no'];
            $salesChallan['challan_date'] = (string) $challan['delivery_date'];
            $salesChallan['items'] = [];
            $salesChallan['status'] = 'draft';
            $salesChallan['created_by'] = $viewer;
            $salesChallan['created_at'] = (string) $challan['created_at'];
            $savedSales = documents_save_sales_document('delivery_challan', $salesChallan);
            if (!$savedSales['ok']) {
                $redirectDocuments($tab, 'error', 'Delivery challan created, but pack workflow update failed.', ['view' => $view]);
            }

            documents_quote_link_workflow_doc($quote, 'delivery_challan', (string) $challan['id']);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            header('Location: challan-view.php?id=' . urlencode((string) $challan['id']) . '&status=success&message=' . urlencode('Delivery challan draft created.'));
            exit;
        }

        if ($action === 'create_pi') {
            $existingId = safe_text((string) ($quote['workflow']['proforma_invoice_id'] ?? ''));
            if ($existingId !== '') {
                $existing = documents_get_sales_document('proforma', $existingId);
                if ($existing !== null && !documents_is_archived($existing)) {
                    header('Location: admin-proformas.php?id=' . urlencode($existingId));
                    exit;
                }
            }

            $created = documents_create_proforma_from_quote($quote);
            if (!($created['ok'] ?? false)) {
                $redirectDocuments($tab, 'error', (string) ($created['error'] ?? 'Unable to create PI.'), ['view' => $view]);
            }
            $piId = (string) ($created['proforma_id'] ?? '');
            $piDoc = $piId !== '' ? documents_get_proforma($piId) : null;
            if ($piDoc === null || $piId === '') {
                $redirectDocuments($tab, 'error', 'PI created but could not be loaded.', ['view' => $view]);
            }

            $salesPi = documents_sales_document_defaults('proforma');
            $salesPi['id'] = $piId;
            $salesPi['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesPi['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesPi['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesPi['proforma_no'] = (string) ($piDoc['proforma_no'] ?? '');
            $salesPi['pi_date'] = date('Y-m-d');
            $salesPi['amount'] = (float) ($piDoc['input_total_gst_inclusive'] ?? 0);
            $salesPi['status'] = 'draft';
            $salesPi['created_by'] = $viewer;
            $salesPi['created_at'] = (string) ($piDoc['created_at'] ?? date('c'));
            $savedSalesPi = documents_save_sales_document('proforma', $salesPi);
            if (!$savedSalesPi['ok']) {
                $redirectDocuments($tab, 'error', 'PI created, but workflow update failed.', ['view' => $view]);
            }

            documents_quote_link_workflow_doc($quote, 'proforma', $piId);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            header('Location: admin-proformas.php?id=' . urlencode($piId));
            exit;
        }

        if ($action === 'create_invoice') {
            $existingId = safe_text((string) ($quote['workflow']['invoice_id'] ?? ''));
            if ($existingId !== '') {
                $existing = documents_get_sales_document('invoice', $existingId);
                if ($existing !== null && !documents_is_archived($existing)) {
                    header('Location: admin-invoices.php?id=' . urlencode($existingId));
                    exit;
                }
            }

            $created = documents_create_invoice_from_quote($quote);
            if (!($created['ok'] ?? false)) {
                $redirectDocuments($tab, 'error', (string) ($created['error'] ?? 'Unable to create invoice.'), ['view' => $view]);
            }
            $invoiceId = (string) ($created['invoice_id'] ?? '');
            $invoiceDoc = $invoiceId !== '' ? documents_get_invoice($invoiceId) : null;
            if ($invoiceDoc === null || $invoiceId === '') {
                $redirectDocuments($tab, 'error', 'Invoice created but could not be loaded.', ['view' => $view]);
            }

            $salesInvoice = documents_sales_document_defaults('invoice');
            $salesInvoice['id'] = $invoiceId;
            $salesInvoice['quotation_id'] = (string) ($quote['id'] ?? '');
            $salesInvoice['customer_mobile'] = normalize_customer_mobile((string) ($snapshot['mobile'] ?? $quote['customer_mobile'] ?? ''));
            $salesInvoice['customer_name'] = safe_text((string) ($snapshot['name'] ?? $quote['customer_name'] ?? ''));
            $salesInvoice['invoice_no'] = (string) ($invoiceDoc['invoice_no'] ?? '');
            $salesInvoice['invoice_date'] = date('Y-m-d');
            $salesInvoice['amount'] = (float) ($invoiceDoc['input_total_gst_inclusive'] ?? 0);
            $salesInvoice['status'] = 'draft';
            $salesInvoice['created_by'] = $viewer;
            $salesInvoice['created_at'] = (string) ($invoiceDoc['created_at'] ?? date('c'));
            $savedSalesInvoice = documents_save_sales_document('invoice', $salesInvoice);
            if (!$savedSalesInvoice['ok']) {
                $redirectDocuments($tab, 'error', 'Invoice created, but workflow update failed.', ['view' => $view]);
            }

            documents_quote_link_workflow_doc($quote, 'invoice', $invoiceId);
            $quote['updated_at'] = date('c');
            documents_save_quote($quote);
            header('Location: admin-invoices.php?id=' . urlencode($invoiceId));
            exit;
        }
    }

    if ($action === 'save_numbering_rule') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $docType = safe_text($_POST['doc_type'] ?? '');
        $segment = safe_text($_POST['segment'] ?? '');
        if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
            $redirectWith('numbering', 'error', 'Invalid document type or segment.');
        }

        $seqDigits = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
        $seqStart = max(1, (int) ($_POST['seq_start'] ?? 1));
        $seqCurrent = max(1, (int) ($_POST['seq_current'] ?? $seqStart));

        $payload['rules'][] = [
            'id' => safe_slug($docType . '-' . $segment . '-' . bin2hex(random_bytes(3))),
            'doc_type' => $docType,
            'segment' => $segment,
            'prefix' => safe_text($_POST['prefix'] ?? ''),
            'format' => safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}'),
            'seq_digits' => $seqDigits,
            'seq_start' => $seqStart,
            'seq_current' => $seqCurrent,
            'active' => isset($_POST['active']),
            'archived_flag' => false,
        ];
        $payload['updated_at'] = date('c');

        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering rule.');
        }

        $redirectWith('numbering', 'success', 'Numbering rule added.');
    }

    if ($action === 'update_numbering_rule' || $action === 'archive_numbering_rule' || $action === 'reset_counter') {
        $payload = json_load($numberingPath, documents_numbering_defaults());
        $payload = array_merge(documents_numbering_defaults(), is_array($payload) ? $payload : []);
        $payload['rules'] = is_array($payload['rules']) ? $payload['rules'] : [];

        $ruleId = safe_text($_POST['rule_id'] ?? '');
        $found = false;
        foreach ($payload['rules'] as &$rule) {
            if ((string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $found = true;

            if ($action === 'archive_numbering_rule') {
                $rule['archived_flag'] = true;
                $rule['active'] = false;
                break;
            }

            if ($action === 'reset_counter') {
                $rule['seq_current'] = (int) ($rule['seq_start'] ?? 1);
                break;
            }

            $docType = safe_text($_POST['doc_type'] ?? '');
            $segment = safe_text($_POST['segment'] ?? '');
            if (!in_array($docType, $docTypes, true) || !in_array($segment, $segments, true)) {
                unset($rule);
                $redirectWith('numbering', 'error', 'Invalid rule update values.');
            }

            $rule['doc_type'] = $docType;
            $rule['segment'] = $segment;
            $rule['prefix'] = safe_text($_POST['prefix'] ?? '');
            $rule['format'] = safe_text($_POST['format'] ?? '{{prefix}}/{{segment}}/{{fy}}/{{seq}}');
            $rule['seq_digits'] = max(2, min(6, (int) ($_POST['seq_digits'] ?? 4)));
            $rule['seq_start'] = max(1, (int) ($_POST['seq_start'] ?? 1));
            $rule['seq_current'] = max(1, (int) ($_POST['seq_current'] ?? 1));
            $rule['active'] = isset($_POST['active']);
        }
        unset($rule);

        if (!$found) {
            $redirectWith('numbering', 'error', 'Rule not found.');
        }

        $payload['updated_at'] = date('c');
        $saved = json_save($numberingPath, $payload);
        if (!$saved['ok']) {
            $redirectWith('numbering', 'error', 'Unable to save numbering updates.');
        }

        $msg = 'Numbering rule updated.';
        if ($action === 'archive_numbering_rule') {
            $msg = 'Numbering rule archived.';
        } elseif ($action === 'reset_counter') {
            $msg = 'Counter reset to start value.';
        }
        $redirectWith('numbering', 'success', $msg);
    }

    if ($action === 'save_template_set' || $action === 'archive_template_set' || $action === 'unarchive_template_set') {
        $rows = json_load($templatePath, []);
        $rows = is_array($rows) ? $rows : [];

        if ($action === 'save_template_set') {
            $templateId = safe_text($_POST['template_id'] ?? '');
            $name = safe_text($_POST['name'] ?? '');
            $segment = safe_text($_POST['segment'] ?? 'RES');
            $notes = safe_text($_POST['notes'] ?? '');
            $opacity = (float) ($_POST['page_background_opacity'] ?? 1);
            $opacity = max(0.1, min(1.0, $opacity));

            if ($name === '' || !in_array($segment, $segments, true)) {
                $redirectWith('templates', 'error', 'Template name and segment are required.');
            }

            $newBgPath = '';
            if (isset($_FILES['template_background_upload']) && (int) ($_FILES['template_background_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = documents_handle_image_upload($_FILES['template_background_upload'], documents_public_backgrounds_dir(), 'background_');
                if (!$upload['ok']) {
                    $redirectWith('templates', 'error', (string) $upload['error']);
                }
                $newBgPath = '/images/documents/backgrounds/' . $upload['filename'];
            }

            $matched = false;
            foreach ($rows as &$row) {
                if ((string) ($row['id'] ?? '') !== $templateId || $templateId === '') {
                    continue;
                }
                $matched = true;
                $row['name'] = $name;
                $row['segment'] = $segment;
                $row['notes'] = $notes;
                $theme = is_array($row['default_doc_theme'] ?? null) ? $row['default_doc_theme'] : [];
                if ($newBgPath !== '') {
                    $theme['page_background_image'] = $newBgPath;
                }
                $theme['page_background_opacity'] = $opacity;
                $row['default_doc_theme'] = $theme;
                $row['updated_at'] = date('c');
            }
            unset($row);

            if (!$matched) {
                $idBase = safe_slug($name);
                if ($idBase === '') {
                    $idBase = 'template-' . time();
                }
                $id = $idBase;
                $counter = 1;
                $existingIds = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $rows);
                while (in_array($id, $existingIds, true)) {
                    $id = $idBase . '-' . $counter;
                    $counter++;
                }

                $now = date('c');
                $rows[] = [
                    'id' => $id,
                    'name' => $name,
                    'segment' => $segment,
                    'default_doc_theme' => [
                        'page_background_image' => $newBgPath,
                        'page_background_opacity' => $opacity,
                    ],
                    'notes' => $notes,
                    'archived_flag' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $saved = json_save($templatePath, $rows);
            if (!$saved['ok']) {
                $redirectWith('templates', 'error', 'Unable to save template set.');
            }

            $redirectWith('templates', 'success', 'Template set saved successfully.');
        }

        $templateId = safe_text($_POST['template_id'] ?? '');
        $found = false;
        foreach ($rows as &$row) {
            if ((string) ($row['id'] ?? '') !== $templateId) {
                continue;
            }
            $found = true;
            $row['archived_flag'] = ($action === 'archive_template_set');
            $row['updated_at'] = date('c');
        }
        unset($row);

        if (!$found) {
            $redirectWith('templates', 'error', 'Template set not found.');
        }

        $saved = json_save($templatePath, $rows);
        if (!$saved['ok']) {
            $redirectWith('templates', 'error', 'Unable to update template archive status.');
        }

        $redirectWith('templates', 'success', $action === 'archive_template_set' ? 'Template archived.' : 'Template unarchived.');
    }


    if ($action === 'set_archive_state') {
        $tab = safe_text($_POST['return_tab'] ?? 'accepted_customers');
        $view = safe_text($_POST['return_view'] ?? '');
        $docType = safe_text($_POST['doc_type'] ?? '');
        $docId = safe_text($_POST['doc_id'] ?? '');
        $archiveState = safe_text($_POST['archive_state'] ?? 'archive');
        $shouldArchive = $archiveState !== 'unarchive';

        if ($docType === 'quotation') {
            $quote = documents_get_quote($docId);
            if ($quote === null) {
                $redirectDocuments($tab, 'error', 'Quotation not found.', $view !== '' ? ['view' => $view] : []);
            }
            if ($shouldArchive) {
                $quote = documents_set_archived($quote, [
                    'type' => 'admin',
                    'id' => (string) ($user['id'] ?? ''),
                    'name' => (string) ($user['full_name'] ?? 'Admin'),
                ]);
            } else {
                $quote = documents_set_unarchived($quote);
                $quote['status'] = !empty($quote['accepted_at']) ? 'accepted' : 'approved';
            }
            $quote['updated_at'] = date('c');
            $saved = documents_save_quote($quote);
            if (!$saved['ok']) {
                $redirectDocuments($tab, 'error', 'Unable to update quotation archive state.', $view !== '' ? ['view' => $view] : []);
            }
            $redirectDocuments($tab, 'success', $shouldArchive ? 'Quotation archived.' : 'Quotation unarchived.', $view !== '' ? ['view' => $view] : []);
        }

        if ($docType === 'agreement') {
            $agreement = documents_get_agreement($docId);
            if ($agreement !== null) {
                if ($shouldArchive) {
                    $agreement = documents_set_archived($agreement, [
                        'type' => 'admin',
                        'id' => (string) ($user['id'] ?? ''),
                        'name' => (string) ($user['full_name'] ?? 'Admin'),
                    ]);
                    $agreement['status'] = 'Archived';
                } else {
                    $agreement = documents_set_unarchived($agreement);
                    $agreement['status'] = 'Draft';
                }
                $agreement['updated_at'] = date('c');
                $savedAgreement = documents_save_agreement($agreement);
                if (!$savedAgreement['ok']) {
                    $redirectDocuments($tab, 'error', 'Unable to update agreement archive state.', $view !== '' ? ['view' => $view] : []);
                }
                $redirectDocuments($tab, 'success', $shouldArchive ? 'Agreement archived.' : 'Agreement unarchived.', $view !== '' ? ['view' => $view] : []);
            }
        }

        $mappedType = $documentTypeMap[$docType] ?? '';
        if ($mappedType === '') {
            $redirectDocuments($tab, 'error', 'Invalid document type.', $view !== '' ? ['view' => $view] : []);
        }

        $document = documents_get_sales_document($mappedType, $docId);
        if ($document === null) {
            $redirectDocuments($tab, 'error', 'Document not found.', $view !== '' ? ['view' => $view] : []);
        }

        if ($shouldArchive) {
            $document = documents_set_archived($document, [
                'type' => 'admin',
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['full_name'] ?? 'Admin'),
            ]);
        } else {
            $document = documents_set_unarchived($document);
        }
        $document['updated_at'] = date('c');
        $saved = documents_save_sales_document($mappedType, $document);
        if (!$saved['ok']) {
            $redirectDocuments($tab, 'error', 'Unable to update archive state.', $view !== '' ? ['view' => $view] : []);
        }
        $redirectDocuments($tab, 'success', $shouldArchive ? 'Document archived.' : 'Document unarchived.', $view !== '' ? ['view' => $view] : []);
    }

}

$activeTab = safe_text($_GET['tab'] ?? 'company');
if (!in_array($activeTab, ['company', 'numbering', 'templates', 'accepted_customers', 'archived'], true)) {
    $activeTab = 'company';
}

$status = safe_text($_GET['status'] ?? '');
$message = safe_text($_GET['message'] ?? '');

$company = load_company_profile();

$numbering = json_load($numberingPath, documents_numbering_defaults());
$numbering = array_merge(documents_numbering_defaults(), is_array($numbering) ? $numbering : []);
$numbering['rules'] = is_array($numbering['rules']) ? $numbering['rules'] : [];

$templates = json_load($templatePath, []);
$templates = is_array($templates) ? $templates : [];

$includeArchivedAccepted = isset($_GET['include_archived_accepted']) && $_GET['include_archived_accepted'] === '1';
$acceptedSearch = strtolower(trim(safe_text($_GET['accepted_q'] ?? '')));
$packViewId = safe_text($_GET['view'] ?? '');
$includeArchivedPack = isset($_GET['include_archived_pack']) && $_GET['include_archived_pack'] === '1';
$archiveTypeFilter = safe_text($_GET['archive_type'] ?? 'all');
$archiveSearch = strtolower(trim(safe_text($_GET['archive_q'] ?? '')));

$quotes = documents_list_quotes();
$salesAgreements = documents_list_sales_documents('agreement');
$salesReceipts = documents_list_sales_documents('receipt');
$salesChallans = documents_list_sales_documents('delivery_challan');
$salesProformas = documents_list_sales_documents('proforma');
$salesInvoices = documents_list_sales_documents('invoice');

$receiptsByQuote = [];
foreach ($salesReceipts as $receipt) {
    if (!is_array($receipt)) {
        continue;
    }
    $qid = (string) ($receipt['quotation_id'] ?? '');
    if ($qid === '') {
        continue;
    }
    $receiptsByQuote[$qid][] = $receipt;
}

$acceptedRows = [];
foreach ($quotes as $quote) {
    if (!is_array($quote)) {
        continue;
    }
    $statusNormalized = documents_quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $isArchived = $isArchivedRecord($quote);
    if ($isArchived) {
        if (!$includeArchivedAccepted) {
            continue;
        }
    } elseif ($statusNormalized !== 'accepted') {
        continue;
    }
    $mobile = normalize_customer_mobile((string) ($quote['customer_mobile'] ?? ''));
    $name = (string) ($quote['customer_name'] ?? '');
    $hay = strtolower($name . ' ' . $mobile);
    if ($acceptedSearch !== '' && !str_contains($hay, $acceptedSearch)) {
        continue;
    }
    $quotationAmount = (float) ($quote['calc']['gross_payable'] ?? $quote['calc']['final_price_incl_gst'] ?? $quote['calc']['grand_total'] ?? 0);
    $received = 0.0;
    foreach (($receiptsByQuote[(string) ($quote['id'] ?? '')] ?? []) as $receipt) {
        if ($isArchivedRecord($receipt) && !$includeArchivedAccepted) {
            continue;
        }
        $receiptStatus = strtolower(trim((string) ($receipt['status'] ?? '')));
        if (in_array($receiptStatus, ['void', 'cancelled', 'canceled'], true)) {
            continue;
        }
        $received += (float) ($receipt['amount_received'] ?? $receipt['amount'] ?? 0);
    }
    $receivable = max(0, $quotationAmount - $received);
    $acceptedRows[] = [
        'quote' => $quote,
        'quotation_amount' => $quotationAmount,
        'payment_received' => $received,
        'receivables' => $receivable,
        'advance' => $received > $quotationAmount,
        'is_archived' => $isArchived,
    ];
}

$packQuote = null;
if ($packViewId !== '') {
    $packQuote = documents_get_quote($packViewId);
}

$collectByQuote = static function (array $rows, string $quoteId, bool $includeArchived) use ($isArchivedRecord): array {
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['quotation_id'] ?? '') !== $quoteId) {
            continue;
        }
        if (!$includeArchived && $isArchivedRecord($row)) {
            continue;
        }
        $list[] = $row;
    }
    return $list;
};

$archivedRows = [];
foreach ($quotes as $quote) {
    if (!is_array($quote) || !$isArchivedRecord($quote)) {
        continue;
    }
    $archivedRows[] = [
        'type' => 'quotation',
        'doc_id' => (string) ($quote['id'] ?? ''),
        'customer' => (string) ($quote['customer_name'] ?? ''),
        'mobile' => (string) ($quote['customer_mobile'] ?? ''),
        'quotation_id' => (string) ($quote['id'] ?? ''),
        'amount' => (float) ($quote['calc']['gross_payable'] ?? 0),
        'archived_at' => (string) ($quote['archived_at'] ?? ''),
    ];
}

$agreements = documents_list_agreements();
foreach ($agreements as $agreement) {
    if (!is_array($agreement) || !$isArchivedRecord($agreement)) {
        continue;
    }
    $archivedRows[] = [
        'type' => 'agreement',
        'doc_id' => (string) ($agreement['id'] ?? ''),
        'customer' => (string) ($agreement['customer_name'] ?? ''),
        'mobile' => (string) ($agreement['customer_mobile'] ?? ''),
        'quotation_id' => (string) ($agreement['linked_quote_id'] ?? ''),
        'amount' => (float) preg_replace('/[^0-9.]/', '', (string) ($agreement['total_cost'] ?? '')),
        'archived_at' => (string) ($agreement['archived_at'] ?? $agreement['updated_at'] ?? ''),
    ];
}

foreach (['agreement' => $salesAgreements, 'receipt' => $salesReceipts, 'delivery_challan' => $salesChallans, 'proforma' => $salesProformas, 'invoice' => $salesInvoices] as $type => $rows) {
    foreach ($rows as $row) {
        if (!is_array($row) || !$isArchivedRecord($row)) {
            continue;
        }
        $amount = (float) ($row['amount_received'] ?? $row['amount'] ?? $row['pricing_snapshot']['gross_payable'] ?? 0);
        $archivedRows[] = [
            'type' => $type,
            'doc_id' => (string) ($row['id'] ?? ''),
            'customer' => (string) ($row['customer_name'] ?? ''),
            'mobile' => (string) ($row['customer_mobile'] ?? ''),
            'quotation_id' => (string) ($row['quotation_id'] ?? ''),
            'amount' => $amount,
            'archived_at' => (string) ($row['archived_at'] ?? ''),
        ];
    }
}

$archivedRows = array_values(array_filter($archivedRows, static function (array $row) use ($archiveTypeFilter, $archiveSearch): bool {
    if ($archiveTypeFilter !== 'all' && $archiveTypeFilter !== (string) ($row['type'] ?? '')) {
        return false;
    }
    if ($archiveSearch === '') {
        return true;
    }
    $hay = strtolower((string) ($row['type'] ?? '') . ' ' . (string) ($row['doc_id'] ?? '') . ' ' . (string) ($row['customer'] ?? '') . ' ' . (string) ($row['mobile'] ?? '') . ' ' . (string) ($row['quotation_id'] ?? ''));
    return str_contains($hay, $archiveSearch);
}));
usort($archivedRows, static function (array $a, array $b): int {
    $typeCmp = strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? ''));
    if ($typeCmp !== 0) {
        return $typeCmp;
    }
    return strcmp((string) ($b['archived_at'] ?? ''), (string) ($a['archived_at'] ?? ''));
});


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Documents &amp; Billing Control Center</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6fa; color: #111827; }
    .page { width: 100%; max-width: none; padding: 1.25rem; box-sizing: border-box; }
    .top { display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:space-between; align-items:center; margin-bottom:1rem; }
    .top h1 { margin:0; font-size:1.45rem; }
    .btn { display:inline-block; background:#1d4ed8; color:#fff; text-decoration:none; padding:0.55rem 0.8rem; border-radius:8px; border:none; cursor:pointer; font-size:0.92rem; }
    .btn.secondary { background:#fff; color:#1f2937; border:1px solid #cbd5e1; }
    .btn.warn { background:#b91c1c; }
    .banner { margin-bottom:1rem; padding:0.75rem 0.9rem; border-radius:8px; }
    .banner.success { background:#ecfdf5; border:1px solid #34d399; color:#065f46; }
    .banner.error { background:#fef2f2; border:1px solid #f87171; color:#991b1b; }
    .tabs { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; }
    .tab { text-decoration:none; background:#e2e8f0; color:#1f2937; padding:0.55rem 0.8rem; border-radius:8px; font-weight:600; }
    .tab.active { background:#1d4ed8; color:#fff; }
    .tab.disabled { opacity:0.55; pointer-events:none; }
    .panel { background:#fff; border:1px solid #dbe1ea; border-radius:12px; padding:1rem; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0.8rem; }
    label { display:block; font-size:0.84rem; margin-bottom:0.3rem; font-weight:600; }
    input, select, textarea { width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:80px; }
    table { width:100%; border-collapse: collapse; margin-bottom:1rem; }
    th, td { border:1px solid #dbe1ea; padding:0.5rem; text-align:left; vertical-align:top; }
    th { background:#f8fafc; }
    .logo-preview { max-height:90px; display:block; margin-top:0.5rem; }
    .muted { color:#64748b; font-size:0.86rem; }
    .pill { display:inline-block; padding:0.15rem 0.45rem; border-radius:999px; font-size:0.72rem; font-weight:700; }
    .pill.archived { background:#fee2e2; color:#991b1b; }
    .pill.warn { background:#fef3c7; color:#92400e; }
    .row-actions { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; }
    .inline-form { display:inline-block; margin:0; }
  </style>
</head>
<body>
  <main class="page">
    <div class="top">
      <div>
        <h1>Documents &amp; Billing Control Center</h1>
        <p class="muted">Admin: <?= htmlspecialchars((string) ($user['full_name'] ?? 'Administrator'), ENT_QUOTES) ?></p>
      </div>
      <div>
        <a class="btn" href="admin-quotations.php">Quotations</a>
        <a class="btn" href="admin-challans.php">Challans</a>
        <a class="btn" href="admin-agreements.php">Agreements</a>
        <a class="btn secondary" href="admin-templates.php">Template Blocks &amp; Media</a>
        <a class="btn secondary" href="admin-dashboard.php">Back to Admin Dashboard</a>
      </div>
    </div>

    <?php if ($message !== '' && ($status === 'success' || $status === 'error')): ?>
      <div class="banner <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <nav class="tabs">
      <a class="tab <?= $activeTab === 'company' ? 'active' : '' ?>" href="?tab=company">Company Profile &amp; Branding</a>
      <a class="tab <?= $activeTab === 'numbering' ? 'active' : '' ?>" href="?tab=numbering">Numbering Rules</a>
      <a class="tab <?= $activeTab === 'templates' ? 'active' : '' ?>" href="?tab=templates">Template Sets</a>
      <a class="tab <?= $activeTab === 'accepted_customers' ? 'active' : '' ?>" href="?tab=accepted_customers">Accepted Customers</a>
      <a class="tab <?= $activeTab === 'archived' ? 'active' : '' ?>" href="?tab=archived">Archived</a>
      <a class="tab" href="admin-templates.php">Template Blocks &amp; Media</a>
      <a class="tab" href="admin-quotations.php">Quotation Manager</a>
      <a class="tab" href="admin-challans.php">Challans</a>
      <a class="tab" href="admin-agreements.php">Agreements</a>
      <span class="tab disabled">CSV Import (Phase 2+)</span>
    </nav>

    <?php if ($activeTab === 'accepted_customers'): ?>
      <section class="panel">
        <?php if ($packQuote !== null): ?>
          <?php
            $packQuoteId = (string) ($packQuote['id'] ?? '');
            $packAgreements = $collectByQuote($salesAgreements, $packQuoteId, $includeArchivedPack);
            $packReceipts = $collectByQuote($salesReceipts, $packQuoteId, $includeArchivedPack);
            $packChallans = $collectByQuote($salesChallans, $packQuoteId, $includeArchivedPack);
            $packProformas = $collectByQuote($salesProformas, $packQuoteId, $includeArchivedPack);
            $packInvoices = $collectByQuote($salesInvoices, $packQuoteId, $includeArchivedPack);
          ?>
          <p><a class="btn secondary" href="?tab=accepted_customers">&larr; Back to Accepted Customers</a></p>
          <h2 style="margin-top:0;">Document Pack: <?= htmlspecialchars((string) ($packQuote['customer_name'] ?? ''), ENT_QUOTES) ?></h2>
          <form method="get" style="margin-bottom:1rem;">
            <input type="hidden" name="tab" value="accepted_customers" />
            <input type="hidden" name="view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <label><input type="checkbox" name="include_archived_pack" value="1" <?= $includeArchivedPack ? 'checked' : '' ?> onchange="this.form.submit()" /> Include Archived in Pack</label>
          </form>

          <h3>A) Quotation</h3>
          <p>
            <a class="btn secondary" href="quotation-view.php?id=<?= urlencode($packQuoteId) ?>" target="_blank" rel="noopener">View Quotation</a>
            <?php if ($isArchivedRecord($packQuote)): ?><span class="pill archived">ARCHIVED</span><?php endif; ?>
          </p>

          <h3>B) Vendor Consumer Agreement</h3>
          <?php if ($packAgreements === []): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
              <input type="hidden" name="action" value="create_agreement" />
              <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <input type="hidden" name="return_tab" value="accepted_customers" />
              <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
              <p class="muted">No agreement found. <button class="btn" type="submit">Create Agreement</button></p>
            </form>
          <?php else: ?>
            <table>
              <thead><tr><th>ID</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($packAgreements as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
                    <td><?= htmlspecialchars((string) ($row['execution_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($row['status'] ?? 'active'), ENT_QUOTES) ?></td>
                    <td class="row-actions">
                      <a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View / Edit</a>
                      <?php if ($isAdmin): ?>
                        <form class="inline-form" method="post">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                          <input type="hidden" name="action" value="set_archive_state" />
                          <input type="hidden" name="doc_type" value="agreement" />
                          <input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" />
                          <input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" />
                          <input type="hidden" name="return_tab" value="accepted_customers" />
                          <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
                          <button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h3>C) Payment Receipts</h3>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_receipt" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Add Receipt</button>
          </form>
          <table>
            <thead><tr><th>ID</th><th>Date</th><th>Amount</th><th>Mode/Ref</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($packReceipts as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
                  <td><?= htmlspecialchars((string) ($row['receipt_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) ($row['amount_received'] ?? $row['amount'] ?? 0)), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars((string) ($row['mode'] ?? ''), ENT_QUOTES) ?> <?= htmlspecialchars((string) ($row['reference'] ?? ''), ENT_QUOTES) ?></td>
                  <td class="row-actions">
                    <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => $packQuoteId, 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">View/Edit</a>
                    <?php if ($isAdmin): ?>
                      <form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="receipt" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if ($packReceipts === []): ?><tr><td colspan="5" class="muted">No receipts available.</td></tr><?php endif; ?>
            </tbody>
          </table>

          <h3>D) Delivery Challans</h3>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_delivery_challan" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Create DC</button>
          </form>
          <table><thead><tr><th>ID</th><th>Date</th><th>Items</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packChallans as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td>
              <td><?= htmlspecialchars((string) ($row['challan_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td>
              <td><?= count(is_array($row['items'] ?? null) ? $row['items'] : []) ?></td>
              <td class="row-actions"><a class="btn secondary" href="challan-view.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View/Edit</a><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="delivery_challan" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($packChallans === []): ?><tr><td colspan="4" class="muted">No delivery challans available.</td></tr><?php endif; ?>
          </tbody></table>

          <h3>E) Proforma Invoice (PI)</h3>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_pi" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Create PI</button>
          </form>
          <table><thead><tr><th>ID</th><th>Date</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packProformas as $row): ?><tr><td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td><td><?= htmlspecialchars((string) ($row['pi_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td><td class="row-actions"><a class="btn secondary" href="admin-proformas.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View/Edit</a><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="proforma" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if ($packProformas === []): ?><tr><td colspan="3" class="muted">No PI found.</td></tr><?php endif; ?>
          </tbody></table>

          <h3>F) Invoice</h3>
          <form method="post" class="inline-form" style="margin-bottom:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
            <input type="hidden" name="action" value="create_invoice" />
            <input type="hidden" name="quotation_id" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <input type="hidden" name="return_tab" value="accepted_customers" />
            <input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" />
            <button class="btn" type="submit">Create Invoice</button>
          </form>
          <table><thead><tr><th>ID</th><th>Date</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($packInvoices as $row): ?><tr><td><?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?> <?= $isArchivedRecord($row) ? '<span class="pill archived">ARCHIVED</span>' : '' ?></td><td><?= htmlspecialchars((string) ($row['invoice_date'] ?? $row['created_at'] ?? ''), ENT_QUOTES) ?></td><td class="row-actions"><a class="btn secondary" href="admin-invoices.php?id=<?= urlencode((string) ($row['id'] ?? '')) ?>" target="_blank" rel="noopener">View/Edit</a><?php if ($isAdmin): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="action" value="set_archive_state" /><input type="hidden" name="doc_type" value="invoice" /><input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES) ?>" /><input type="hidden" name="archive_state" value="<?= $isArchivedRecord($row) ? 'unarchive' : 'archive' ?>" /><input type="hidden" name="return_tab" value="accepted_customers" /><input type="hidden" name="return_view" value="<?= htmlspecialchars($packQuoteId, ENT_QUOTES) ?>" /><button class="btn <?= $isArchivedRecord($row) ? 'secondary' : 'warn' ?>" type="submit"><?= $isArchivedRecord($row) ? 'Unarchive' : 'Archive' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if ($packInvoices === []): ?><tr><td colspan="3" class="muted">No invoice found.</td></tr><?php endif; ?>
          </tbody></table>
        <?php else: ?>
          <h2 style="margin-top:0;">Accepted Customers</h2>
          <form method="get" class="grid" style="margin-bottom:1rem;">
            <input type="hidden" name="tab" value="accepted_customers" />
            <div><label>Search (name/mobile)</label><input type="text" name="accepted_q" value="<?= htmlspecialchars((string) ($_GET['accepted_q'] ?? ''), ENT_QUOTES) ?>" /></div>
            <div><label>&nbsp;</label><label><input type="checkbox" name="include_archived_accepted" value="1" <?= $includeArchivedAccepted ? 'checked' : '' ?> /> Include archived quotations</label></div>
            <div><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
          </form>
          <table>
            <thead><tr><th>Sr No</th><th>Customer Name</th><th>View</th><th>Quotation Amount</th><th>Payment Received</th><th>Receivables</th></tr></thead>
            <tbody>
              <?php foreach ($acceptedRows as $index => $row): ?>
                <?php $quote = $row['quote']; ?>
                <tr>
                  <td><?= $index + 1 ?></td>
                  <td><?= htmlspecialchars((string) ($quote['customer_name'] ?? ''), ENT_QUOTES) ?><?php if (!empty($row['is_archived'])): ?> <span class="pill archived">ARCHIVED</span><?php endif; ?><br><span class="muted"><?= htmlspecialchars((string) ($quote['customer_mobile'] ?? ''), ENT_QUOTES) ?></span></td>
                  <td><a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['tab' => 'accepted_customers', 'view' => (string) ($quote['id'] ?? ''), 'include_archived_pack' => $includeArchivedPack ? '1' : '0']), ENT_QUOTES) ?>">View</a></td>
                  <td><?= htmlspecialchars($inr((float) $row['quotation_amount']), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) $row['payment_received']), ENT_QUOTES) ?></td>
                  <td><?= htmlspecialchars($inr((float) $row['receivables']), ENT_QUOTES) ?><?php if (!empty($row['advance'])): ?><br><span class="muted">(Advance)</span><?php endif; ?><?php if (($row['receivables'] ?? 0) > 0): ?> <span class="pill warn">Due</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($acceptedRows === []): ?><tr><td colspan="6" class="muted">No accepted customers found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'archived'): ?>
      <section class="panel">
        <h2 style="margin-top:0;">Archived Documents</h2>
        <form method="get" class="grid" style="margin-bottom:1rem;">
          <input type="hidden" name="tab" value="archived" />
          <div>
            <label>Type</label>
            <select name="archive_type">
              <option value="all" <?= $archiveTypeFilter === 'all' ? 'selected' : '' ?>>All</option>
              <option value="quotation" <?= $archiveTypeFilter === 'quotation' ? 'selected' : '' ?>>Quotations</option>
              <option value="agreement" <?= $archiveTypeFilter === 'agreement' ? 'selected' : '' ?>>Agreements</option>
              <option value="receipt" <?= $archiveTypeFilter === 'receipt' ? 'selected' : '' ?>>Receipts</option>
              <option value="delivery_challan" <?= $archiveTypeFilter === 'delivery_challan' ? 'selected' : '' ?>>DC</option>
              <option value="proforma" <?= $archiveTypeFilter === 'proforma' ? 'selected' : '' ?>>PI</option>
              <option value="invoice" <?= $archiveTypeFilter === 'invoice' ? 'selected' : '' ?>>Invoice</option>
            </select>
          </div>
          <div><label>Search</label><input type="text" name="archive_q" value="<?= htmlspecialchars((string) ($_GET['archive_q'] ?? ''), ENT_QUOTES) ?>" placeholder="Customer / mobile / doc id / quote id" /></div>
          <div><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
        </form>
        <table>
          <thead><tr><th>Type</th><th>Doc ID</th><th>Customer</th><th>Linked quotation id</th><th>Amount</th><th>Archived at</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($archivedRows as $row): ?>
              <tr>
                <td><?= htmlspecialchars($documentTypeLabel[(string) ($row['type'] ?? '')] ?? (string) ($row['type'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($row['doc_id'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($row['customer'] ?? ''), ENT_QUOTES) ?><br><span class="muted"><?= htmlspecialchars((string) ($row['mobile'] ?? ''), ENT_QUOTES) ?></span></td>
                <td><?= htmlspecialchars((string) ($row['quotation_id'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($inr((float) ($row['amount'] ?? 0)), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($row['archived_at'] ?? ''), ENT_QUOTES) ?></td>
                <td class="row-actions">
                  <?php if ((string) ($row['type'] ?? '') === 'agreement'): ?><a class="btn secondary" href="agreement-view.php?id=<?= urlencode((string) ($row['doc_id'] ?? '')) ?>" target="_blank" rel="noopener">View</a><?php elseif ((string) ($row['quotation_id'] ?? '') !== ''): ?><a class="btn secondary" href="quotation-view.php?id=<?= urlencode((string) ($row['quotation_id'] ?? '')) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
                  <?php if ($isAdmin): ?>
                    <form class="inline-form" method="post">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="action" value="set_archive_state" />
                      <input type="hidden" name="doc_type" value="<?= htmlspecialchars((string) ($row['type'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="doc_id" value="<?= htmlspecialchars((string) ($row['doc_id'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="archive_state" value="unarchive" />
                      <input type="hidden" name="return_tab" value="archived" />
                      <button class="btn secondary" type="submit">Unarchive</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($archivedRows === []): ?><tr><td colspan="7" class="muted">No archived documents found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'company'): ?>
      <section class="panel">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_company_profile" />
          <div class="grid">
            <?php
              $companyTextFields = [
                'company_name', 'brand_name', 'address_line', 'city', 'district', 'state', 'pin',
                'phone_primary', 'phone_secondary', 'whatsapp_number', 'email_primary', 'email_secondary',
                'website', 'gstin', 'udyam', 'pan', 'jreda_license', 'dwsd_license', 'default_cta_line'
              ];
              foreach ($companyTextFields as $key):
            ?>
              <div>
                <label for="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key)), ENT_QUOTES) ?></label>
                <input id="<?= htmlspecialchars($key, ENT_QUOTES) ?>" type="text" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($company[$key] ?? ''), ENT_QUOTES) ?>" />
              </div>
            <?php endforeach; ?>

            <?php $bank = is_array($company['bank'] ?? null) ? $company['bank'] : documents_company_bank_defaults(); ?>
            <div>
              <label for="bank_name">Bank Name</label>
              <input id="bank_name" type="text" name="bank_name" value="<?= htmlspecialchars((string) ($bank['bank_name'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="bank_account_name">Bank Account Name</label>
              <input id="bank_account_name" type="text" name="bank_account_name" value="<?= htmlspecialchars((string) ($bank['account_name'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="bank_account_no">Bank Account Number</label>
              <input id="bank_account_no" type="text" name="bank_account_no" value="<?= htmlspecialchars((string) ($bank['account_no'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="bank_ifsc">IFSC</label>
              <input id="bank_ifsc" type="text" name="bank_ifsc" value="<?= htmlspecialchars((string) ($bank['ifsc'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="bank_branch">Branch</label>
              <input id="bank_branch" type="text" name="bank_branch" value="<?= htmlspecialchars((string) ($bank['branch'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="upi_id">UPI ID</label>
              <input id="upi_id" type="text" name="upi_id" value="<?= htmlspecialchars((string) ($bank['upi_id'] ?? ''), ENT_QUOTES) ?>" />
            </div>
            <div>
              <label for="company_logo_upload">Company Logo Upload</label>
              <input id="company_logo_upload" type="file" name="company_logo_upload" accept="image/*" />
              <?php if ((string) $company['logo_path'] !== ''): ?>
                <img class="logo-preview" src="<?= htmlspecialchars((string) $company['logo_path'], ENT_QUOTES) ?>" alt="Current logo" />
              <?php endif; ?>
            </div>
          </div>
          <p class="muted">WhatsApp accepts 10-12 digits. PAN is optional but should look like ABCDE1234F.</p>
          <p class="muted">Last updated: <?= htmlspecialchars((string) ($company['updated_at'] ?: 'Never'), ENT_QUOTES) ?></p>
          <button class="btn" type="submit">Save Company Profile</button>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'numbering'): ?>
      <section class="panel">
        <p class="muted">Financial year mode: <?= htmlspecialchars((string) $numbering['financial_year_mode'], ENT_QUOTES) ?> · FY Start Month: <?= (int) $numbering['fy_start_month'] ?> · Current FY: <?= htmlspecialchars(current_fy_string((int) $numbering['fy_start_month']), ENT_QUOTES) ?></p>
        <table>
          <thead>
            <tr><th>Type</th><th>Segment</th><th>Prefix</th><th>Format</th><th>Digits</th><th>Start</th><th>Current</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php if ($numbering['rules'] === []): ?>
              <tr><td colspan="9">No numbering rules added yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($numbering['rules'] as $rule): ?>
              <?php if (!is_array($rule) || !empty($rule['archived_flag'])) { continue; } ?>
              <tr>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                  <input type="hidden" name="rule_id" value="<?= htmlspecialchars((string) ($rule['id'] ?? ''), ENT_QUOTES) ?>" />
                  <td><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>" <?= ($docType === (string) ($rule['doc_type'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ($segment === (string) ($rule['segment'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></td>
                  <td><input type="text" name="prefix" value="<?= htmlspecialchars((string) ($rule['prefix'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="text" name="format" value="<?= htmlspecialchars((string) ($rule['format'] ?? ''), ENT_QUOTES) ?>" /></td>
                  <td><input type="number" name="seq_digits" min="2" max="6" value="<?= (int) ($rule['seq_digits'] ?? 4) ?>" /></td>
                  <td><input type="number" name="seq_start" min="1" value="<?= (int) ($rule['seq_start'] ?? 1) ?>" /></td>
                  <td><input type="number" name="seq_current" min="1" value="<?= (int) ($rule['seq_current'] ?? 1) ?>" /></td>
                  <td><label><input type="checkbox" name="active" <?= !empty($rule['active']) ? 'checked' : '' ?> /> Active</label></td>
                  <td>
                    <button class="btn" type="submit" name="action" value="update_numbering_rule">Save</button>
                    <button class="btn secondary" type="submit" name="action" value="reset_counter">Reset Counter</button>
                    <button class="btn warn" type="submit" name="action" value="archive_numbering_rule">Archive</button>
                  </td>
                </form>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new rule</h3>
        <form method="post" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_numbering_rule" />
          <div><label>Document Type</label><select name="doc_type"><?php foreach ($docTypes as $docType): ?><option value="<?= htmlspecialchars($docType, ENT_QUOTES) ?>"><?= htmlspecialchars($docType, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Prefix</label><input type="text" name="prefix" value="DE/QTN" /></div>
          <div><label>Format</label><input type="text" name="format" value="{{prefix}}/{{segment}}/{{fy}}/{{seq}}" /></div>
          <div><label>Sequence Digits</label><input type="number" name="seq_digits" min="2" max="6" value="4" /></div>
          <div><label>Starting Number</label><input type="number" name="seq_start" min="1" value="1" /></div>
          <div><label>Current Number</label><input type="number" name="seq_current" min="1" value="1" /></div>
          <div><label>Status</label><label><input type="checkbox" name="active" checked /> Active</label></div>
          <div><button class="btn" type="submit">Save Numbering Rule</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'templates'): ?>
      <section class="panel">
        <table>
          <thead><tr><th>Name</th><th>Segment</th><th>Archived</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($templates as $template): ?>
              <?php if (!is_array($template)) { continue; } ?>
              <?php $theme = is_array($template['default_doc_theme'] ?? null) ? $template['default_doc_theme'] : ['page_background_image' => '', 'page_background_opacity' => 1]; ?>
              <tr>
                <td><?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($template['segment'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= !empty($template['archived_flag']) ? 'Yes' : 'No' ?></td>
                <td>
                  <details>
                    <summary>Edit</summary>
                    <form method="post" enctype="multipart/form-data" class="grid">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="action" value="save_template_set" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <div><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars((string) ($template['name'] ?? ''), ENT_QUOTES) ?>" /></div>
                      <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>" <?= ((string) ($template['segment'] ?? '') === $segment) ? 'selected' : '' ?>><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
                      <div><label>Notes</label><textarea name="notes"><?= htmlspecialchars((string) ($template['notes'] ?? ''), ENT_QUOTES) ?></textarea></div>
                      <div><button class="btn" type="submit">Save</button></div>
                    </form>
                    <form method="post" style="margin-top:0.5rem;">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
                      <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($template['id'] ?? ''), ENT_QUOTES) ?>" />
                      <button class="btn <?= !empty($template['archived_flag']) ? 'secondary' : 'warn' ?>" type="submit" name="action" value="<?= !empty($template['archived_flag']) ? 'unarchive_template_set' : 'archive_template_set' ?>"><?= !empty($template['archived_flag']) ? 'Unarchive' : 'Archive' ?></button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Add new template set</h3>
        <form method="post" enctype="multipart/form-data" class="grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>" />
          <input type="hidden" name="action" value="save_template_set" />
          <div><label>Name</label><input type="text" name="name" required /></div>
          <div><label>Segment</label><select name="segment"><?php foreach ($segments as $segment): ?><option value="<?= htmlspecialchars($segment, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><textarea name="notes"></textarea></div>
          <div><button class="btn" type="submit">Save Template Set</button></div>
        </form>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
