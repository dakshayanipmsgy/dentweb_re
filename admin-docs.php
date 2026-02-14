<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$user = current_user() ?? [];

function docs_data_dir(): string
{
    return __DIR__ . '/data/docs';
}

function docs_now_iso(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return date(DATE_ATOM);
    }
}

function docs_default_company_profile(): array
{
    return [
        'company_name' => 'Dakshayani Enterprises',
        'tagline' => '',
        'logo_path' => '',
        'address_line' => 'Maa Tara, Kilburn Colony, Hinoo, Ranchi, JH 834002',
        'phone_1' => '',
        'phone_2' => '',
        'email_1' => '',
        'email_2' => '',
        'website' => 'https://dakshayani.co.in',
        'gstin' => '',
        'udyam' => 'UDYAM-JH-20-0005867',
        'jreda_license' => '',
        'dwsd_license' => '',
        'other_registrations' => [],
        'bank_details' => [
            'account_name' => '',
            'bank_name' => '',
            'account_no' => '',
            'ifsc' => '',
            'branch' => '',
            'upi_id' => '',
            'qr_image_path' => '',
        ],
        'signatory' => [
            'name' => '',
            'designation' => '',
            'signature_image_path' => '',
            'stamp_image_path' => '',
        ],
        'updated_at' => '',
    ];
}

function docs_default_numbering(): array
{
    return [
        'financial_year_mode' => 'FY',
        'fy_format' => 'YY-YY',
        'doc_types' => [
            'quotation' => ['enabled' => true, 'prefix' => 'DE/QTN', 'use_segment' => true, 'digits' => 4],
            'proforma' => ['enabled' => true, 'prefix' => 'DE/PI', 'use_segment' => true, 'digits' => 4],
            'invoice' => ['enabled' => true, 'prefix' => 'DE/INV', 'use_segment' => false, 'digits' => 4],
            'delivery_challan' => ['enabled' => true, 'prefix' => 'DE/DC', 'use_segment' => false, 'digits' => 4],
            'agreement' => ['enabled' => true, 'prefix' => 'DE/AGR', 'use_segment' => true, 'digits' => 4],
        ],
        'segments' => [
            'RES' => 'Residential',
            'COM' => 'Commercial',
            'IND' => 'Industrial',
            'INST' => 'Institutional',
            'PROD' => 'Product',
        ],
        'updated_at' => '',
    ];
}

function docs_default_counters(): array
{
    return ['counters' => [], 'updated_at' => ''];
}

function docs_make_template_set(
    string $id,
    string $name,
    string $segment,
    array $types,
    string $intro,
    string $scope,
    string $terms,
    string $subsidy,
    string $explainer
): array {
    return [
        'id' => $id,
        'name' => $name,
        'segment' => $segment,
        'system_types_allowed' => $types,
        'language_default' => 'en',
        'cover' => [
            'enabled' => true,
            'cover_image_path' => '',
            'title' => 'Solar Power Proposal',
            'subtitle' => '',
            'show_customer_block' => true,
            'show_prepared_by_block' => true,
        ],
        'blocks' => [
            'intro_text' => $intro,
            'scope_of_work' => $scope,
            'inclusions' => 'Supply of approved components, installation, and commissioning support as per agreed design.',
            'warranty' => 'Product and workmanship warranty terms will follow applicable OEM and project scope commitments.',
            'payment_terms' => 'Payment milestones are to be finalized in the commercial section before work order confirmation.',
            'validity_text' => 'This proposal is subject to technical feasibility and valid for a limited period from issue date.',
            'subsidy_info_block' => $subsidy,
            'system_type_explainer_block' => $explainer,
            'transportation_charges_block' => 'Transportation and handling terms will be defined based on project location and delivery scope.',
            'terms_conditions' => $terms,
        ],
        'defaults' => [
            'quotation_valid_days' => 15,
            'payment_milestones' => [],
            'gst_mode_default' => 'SPLIT_70_30',
        ],
        'media_library_refs' => [],
        'updated_at' => docs_now_iso(),
    ];
}

function docs_default_template_sets(): array
{
    return [
        'template_sets' => [
            docs_make_template_set(
                'tpl_pm_surya_ghar_residential_ongrid',
                'PM Surya Ghar – Residential Ongrid',
                'RES',
                ['ongrid', 'hybrid'],
                'This proposal is tailored for residential rooftop solar installations under applicable PM Surya Ghar guidelines.',
                'Site survey, system sizing, material supply, rooftop mounting, electrical integration, testing, and handover documentation.',
                'Final execution remains subject to DISCOM approvals, structural suitability, and prevailing policy requirements.',
                "Indicative subsidy guidance: 2kW systems may receive support of ₹60,000+ and 3kW or above may be capped around ₹78,000. Customer pays full project amount; subsidy is credited after successful installation and portal processing.",
                'PM-focused options generally support ongrid and hybrid configurations. Offgrid-only systems are typically outside PM subsidy scope.'
            ),
            docs_make_template_set(
                'tpl_residential_hybrid',
                'Residential Hybrid',
                'RES',
                ['hybrid', 'offgrid', 'ongrid'],
                'A flexible residential template for customers seeking backup-ready solar with or without net metering.',
                'Detailed load assessment, battery-ready architecture (if selected), installation, testing, and user guidance.',
                'Battery performance and backup behavior depend on usage profile, charging conditions, and selected system design.',
                '',
                'Hybrid combines grid usage with storage options; offgrid is suitable for independent backup-oriented needs.'
            ),
            docs_make_template_set(
                'tpl_commercial_ongrid',
                'Commercial Ongrid',
                'COM',
                ['ongrid'],
                'This commercial proposal is structured for energy offset, operating-cost reduction, and standards-based execution.',
                'Engineering assessment, single-line design, equipment supply, installation supervision, quality checks, and commissioning support.',
                'Execution milestones, taxes, and statutory approvals shall be governed by signed commercial and technical annexures.',
                '',
                'Ongrid systems prioritize daytime self-consumption and export policy compliance as permitted by utility norms.'
            ),
            docs_make_template_set(
                'tpl_industrial_ongrid',
                'Industrial Ongrid',
                'IND',
                ['ongrid'],
                'Industrial format focused on reliability, safety, and measurable long-term energy performance outcomes.',
                'Site engineering review, capacity planning, electrical integration, commissioning, and operations handover with compliance notes.',
                'Compliance placeholders: statutory approvals, plant safety requirements, and client EHS processes to be confirmed before execution.',
                '',
                'System architecture and protections are aligned with industrial operational continuity and utility interface standards.'
            ),
            docs_make_template_set(
                'tpl_institutional',
                'Institutional',
                'INST',
                ['ongrid', 'hybrid'],
                'Institutional proposal format for campuses and public-interest facilities requiring structured documentation.',
                'Needs assessment, technical recommendation, installation workflow, testing protocol, and formal project handover.',
                'Institutional procurement, compliance, and approval workflows must be completed before final implementation.',
                '',
                'Configuration can be optimized for daytime demand, backup objectives, and institutional policy constraints.'
            ),
            docs_make_template_set(
                'tpl_product_quotation',
                'Product Quotation',
                'PROD',
                ['product'],
                'Use this template for product-led quotations such as street lights, high mast, and related solar products.',
                'Itemized component listing, quantity-wise scope, delivery intent, and optional installation support where applicable.',
                'Product supply, freight, and installation responsibilities should be finalized in confirmed purchase terms.',
                '',
                'Product quotations are item-centric and suitable where project EPC scope is limited or not required.'
            ),
        ],
    ];
}

function load_json_file(string $path, $default)
{
    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return $default;
    }

    return $decoded;
}

function save_json_file(string $path, $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    return file_put_contents($path, $encoded . "\n", LOCK_EX) !== false;
}

function ensure_docs_files_exist(): void
{
    $dir = docs_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $defaults = [
        $dir . '/company_profile.json' => docs_default_company_profile(),
        $dir . '/numbering.json' => docs_default_numbering(),
        $dir . '/counters.json' => docs_default_counters(),
        $dir . '/template_sets.json' => docs_default_template_sets(),
    ];

    foreach ($defaults as $path => $defaultData) {
        if (!is_file($path)) {
            save_json_file($path, $defaultData);
            continue;
        }

        if (basename($path) === 'template_sets.json') {
            $existing = load_json_file($path, ['template_sets' => []]);
            $sets = $existing['template_sets'] ?? [];
            if (!is_array($sets) || count($sets) === 0) {
                save_json_file($path, docs_default_template_sets());
            }
        }
    }
}

function docs_company_image_dir(): string
{
    return __DIR__ . '/images/company';
}

function docs_covers_image_dir(): string
{
    return __DIR__ . '/images/docs/covers';
}

function docs_is_valid_image_upload(array $file): bool
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }

    $maxBytes = 5 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return false;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }

    $mime = mime_content_type($tmp);
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
}

function docs_store_uploaded_image(array $file, string $targetDirAbs, string $targetDirRel, string $baseName): ?string
{
    if (!docs_is_valid_image_upload($file)) {
        return null;
    }

    if (!is_dir($targetDirAbs) && !mkdir($targetDirAbs, 0775, true) && !is_dir($targetDirAbs)) {
        return null;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
    $safeBase = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($baseName)) ?: 'image';
    $fileName = sprintf('%s_%s_%s.%s', $safeBase, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
    $destAbs = rtrim($targetDirAbs, '/\\') . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmp, $destAbs)) {
        return null;
    }

    return rtrim($targetDirRel, '/') . '/' . $fileName;
}

function current_financial_year_label(array $numbering, bool $withMode = true): string
{
    $month = (int) date('n');
    $year = (int) date('Y');
    $fyStart = $month >= 4 ? $year : $year - 1;
    $fyEnd = $fyStart + 1;

    $format = strtoupper((string) ($numbering['fy_format'] ?? 'YY-YY'));
    $startYY = substr((string) $fyStart, -2);
    $endYY = substr((string) $fyEnd, -2);

    $label = $startYY . '-' . $endYY;
    if ($format === 'YYYY-YY') {
        $label = $fyStart . '-' . $endYY;
    } elseif ($format === 'YYYY-YYYY') {
        $label = $fyStart . '-' . $fyEnd;
    }

    if (!$withMode) {
        return $label;
    }

    $mode = strtoupper(trim((string) ($numbering['financial_year_mode'] ?? 'FY')));
    return $mode !== '' ? $mode . $label : $label;
}

function docs_counter_key(string $docType, string $fyLabel, string $segment): string
{
    return $docType . '|' . $fyLabel . '|' . $segment;
}

function preview_next_doc_number(string $docType, string $segment): string
{
    $numbering = load_json_file(docs_data_dir() . '/numbering.json', docs_default_numbering());
    $counters = load_json_file(docs_data_dir() . '/counters.json', docs_default_counters());
    $docConfig = $numbering['doc_types'][$docType] ?? null;
    if (!is_array($docConfig) || !($docConfig['enabled'] ?? false)) {
        return 'Document type disabled or unavailable.';
    }

    $useSegment = !empty($docConfig['use_segment']);
    $segmentCode = $useSegment ? strtoupper(trim($segment)) : '_';
    if ($useSegment && $segmentCode === '') {
        $segmentCode = 'RES';
    }

    $fyNumber = current_financial_year_label($numbering, false);
    $fyWithMode = current_financial_year_label($numbering, true);
    $key = docs_counter_key($docType, $fyWithMode, $segmentCode);
    $current = (int) (($counters['counters'][$key] ?? 0));
    $next = $current + 1;

    $digits = (int) ($docConfig['digits'] ?? 4);
    if ($digits < 2) {
        $digits = 2;
    }
    if ($digits > 6) {
        $digits = 6;
    }

    $numPart = str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    $prefix = trim((string) ($docConfig['prefix'] ?? strtoupper($docType)));

    if ($useSegment) {
        return $prefix . '/' . $segmentCode . '/' . $fyNumber . '/' . $numPart;
    }

    return $prefix . '/' . $fyNumber . '/' . $numPart;
}

function docs_list_cover_images(): array
{
    $dir = docs_covers_image_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
    if (!is_array($files)) {
        return [];
    }

    $results = [];
    foreach ($files as $path) {
        $base = basename($path);
        if ($base === '' || str_contains($base, '..')) {
            continue;
        }
        $results[] = '/images/docs/covers/' . $base;
    }

    sort($results);
    return $results;
}

function docs_new_template_id(): string
{
    return 'tpl_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
}

ensure_docs_files_exist();

$tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'company';
$allowedTabs = ['company', 'numbering', 'templates'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'company';
}

$messages = [];
$errors = [];
$previewNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTab = (string) ($_POST['tab'] ?? $tab);
    if (in_array($postedTab, $allowedTabs, true)) {
        $tab = $postedTab;
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Invalid request token. Please refresh and try again.';
    } else {
        if ($tab === 'company') {
            $companyPath = docs_data_dir() . '/company_profile.json';
            $company = load_json_file($companyPath, docs_default_company_profile());
            $bank = is_array($company['bank_details'] ?? null) ? $company['bank_details'] : [];
            $signatory = is_array($company['signatory'] ?? null) ? $company['signatory'] : [];

            $company['company_name'] = trim((string) ($_POST['company_name'] ?? ''));
            $company['tagline'] = trim((string) ($_POST['tagline'] ?? ''));
            $company['address_line'] = trim((string) ($_POST['address_line'] ?? ''));
            $company['phone_1'] = trim((string) ($_POST['phone_1'] ?? ''));
            $company['phone_2'] = trim((string) ($_POST['phone_2'] ?? ''));
            $company['email_1'] = trim((string) ($_POST['email_1'] ?? ''));
            $company['email_2'] = trim((string) ($_POST['email_2'] ?? ''));
            $company['website'] = trim((string) ($_POST['website'] ?? ''));
            $company['gstin'] = trim((string) ($_POST['gstin'] ?? ''));
            $company['udyam'] = trim((string) ($_POST['udyam'] ?? ''));
            $company['jreda_license'] = trim((string) ($_POST['jreda_license'] ?? ''));
            $company['dwsd_license'] = trim((string) ($_POST['dwsd_license'] ?? ''));

            $registrationsRaw = (string) ($_POST['other_registrations'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', $registrationsRaw) ?: [];
            $company['other_registrations'] = array_values(array_filter(array_map(static function (string $line): string {
                return trim($line);
            }, $lines), static function (string $line): bool {
                return $line !== '';
            }));

            $bank['account_name'] = trim((string) ($_POST['bank_account_name'] ?? ''));
            $bank['bank_name'] = trim((string) ($_POST['bank_name'] ?? ''));
            $bank['account_no'] = trim((string) ($_POST['bank_account_no'] ?? ''));
            $bank['ifsc'] = trim((string) ($_POST['bank_ifsc'] ?? ''));
            $bank['branch'] = trim((string) ($_POST['bank_branch'] ?? ''));
            $bank['upi_id'] = trim((string) ($_POST['bank_upi_id'] ?? ''));

            $signatory['name'] = trim((string) ($_POST['sign_name'] ?? ''));
            $signatory['designation'] = trim((string) ($_POST['sign_designation'] ?? ''));

            $logoUpload = $_FILES['logo_file'] ?? null;
            if (is_array($logoUpload) && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $storedLogo = docs_store_uploaded_image($logoUpload, docs_company_image_dir(), '/images/company', 'logo');
                if ($storedLogo === null) {
                    $errors[] = 'Logo upload failed. Allowed types: jpg/png/webp, max 5MB.';
                } else {
                    $company['logo_path'] = $storedLogo;
                }
            }

            $qrUpload = $_FILES['bank_qr_file'] ?? null;
            if (is_array($qrUpload) && ($qrUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $storedQr = docs_store_uploaded_image($qrUpload, docs_company_image_dir(), '/images/company', 'bank_qr');
                if ($storedQr === null) {
                    $errors[] = 'QR upload failed. Allowed types: jpg/png/webp, max 5MB.';
                } else {
                    $bank['qr_image_path'] = $storedQr;
                }
            }

            $signatureUpload = $_FILES['signature_file'] ?? null;
            if (is_array($signatureUpload) && ($signatureUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $storedSignature = docs_store_uploaded_image($signatureUpload, docs_company_image_dir(), '/images/company', 'signature');
                if ($storedSignature === null) {
                    $errors[] = 'Signature upload failed. Allowed types: jpg/png/webp, max 5MB.';
                } else {
                    $signatory['signature_image_path'] = $storedSignature;
                }
            }

            $stampUpload = $_FILES['stamp_file'] ?? null;
            if (is_array($stampUpload) && ($stampUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $storedStamp = docs_store_uploaded_image($stampUpload, docs_company_image_dir(), '/images/company', 'stamp');
                if ($storedStamp === null) {
                    $errors[] = 'Stamp upload failed. Allowed types: jpg/png/webp, max 5MB.';
                } else {
                    $signatory['stamp_image_path'] = $storedStamp;
                }
            }

            $company['bank_details'] = $bank;
            $company['signatory'] = $signatory;
            $company['updated_at'] = docs_now_iso();

            if (count($errors) === 0) {
                if (save_json_file($companyPath, $company)) {
                    $messages[] = 'Company profile updated successfully.';
                } else {
                    $errors[] = 'Unable to save company profile.';
                }
            }
        }

        if ($tab === 'numbering') {
            $numberingPath = docs_data_dir() . '/numbering.json';
            $numbering = load_json_file($numberingPath, docs_default_numbering());
            $action = (string) ($_POST['numbering_action'] ?? 'save');

            if ($action === 'preview') {
                $docType = trim((string) ($_POST['preview_doc_type'] ?? 'quotation'));
                $segment = strtoupper(trim((string) ($_POST['preview_segment'] ?? 'RES')));
                $previewNumber = preview_next_doc_number($docType, $segment);
            } else {
                $numbering['financial_year_mode'] = strtoupper(trim((string) ($_POST['financial_year_mode'] ?? 'FY')));
                $numbering['fy_format'] = strtoupper(trim((string) ($_POST['fy_format'] ?? 'YY-YY')));

                $docTypesInput = $_POST['doc_types'] ?? [];
                if (is_array($docTypesInput)) {
                    $updatedTypes = [];
                    foreach ($docTypesInput as $key => $docTypeRow) {
                        if (!is_array($docTypeRow)) {
                            continue;
                        }

                        $docKey = preg_replace('/[^a-z_]/', '', strtolower((string) $key));
                        if ($docKey === '') {
                            continue;
                        }

                        $digits = (int) ($docTypeRow['digits'] ?? 4);
                        if ($digits < 2) {
                            $digits = 2;
                        }
                        if ($digits > 6) {
                            $digits = 6;
                        }

                        $updatedTypes[$docKey] = [
                            'enabled' => !empty($docTypeRow['enabled']),
                            'prefix' => trim((string) ($docTypeRow['prefix'] ?? '')),
                            'use_segment' => !empty($docTypeRow['use_segment']),
                            'digits' => $digits,
                        ];
                    }
                    $numbering['doc_types'] = $updatedTypes;
                }

                $segmentCodes = $_POST['segment_code'] ?? [];
                $segmentLabels = $_POST['segment_label'] ?? [];
                $segments = [];
                if (is_array($segmentCodes) && is_array($segmentLabels)) {
                    $countRows = min(count($segmentCodes), count($segmentLabels));
                    for ($i = 0; $i < $countRows; $i++) {
                        $code = strtoupper(trim((string) ($segmentCodes[$i] ?? '')));
                        $label = trim((string) ($segmentLabels[$i] ?? ''));
                        if ($code === '' || $label === '') {
                            continue;
                        }
                        if (!preg_match('/^[A-Z0-9]{2,6}$/', $code)) {
                            $errors[] = 'Segment code "' . htmlspecialchars($code, ENT_QUOTES) . '" is invalid. Use 2-6 chars, A-Z/0-9.';
                            continue;
                        }
                        $segments[$code] = $label;
                    }
                }
                if (count($segments) === 0) {
                    $errors[] = 'At least one valid segment is required.';
                } else {
                    $numbering['segments'] = $segments;
                }

                $numbering['updated_at'] = docs_now_iso();

                if (count($errors) === 0) {
                    if (save_json_file($numberingPath, $numbering)) {
                        $messages[] = 'Numbering settings saved.';
                    } else {
                        $errors[] = 'Unable to save numbering settings.';
                    }
                }
            }
        }

        if ($tab === 'templates') {
            $templatePath = docs_data_dir() . '/template_sets.json';
            $payload = load_json_file($templatePath, ['template_sets' => []]);
            $templateSets = is_array($payload['template_sets'] ?? null) ? $payload['template_sets'] : [];
            $action = (string) ($_POST['templates_action'] ?? 'save');
            $editingId = trim((string) ($_POST['template_id'] ?? ''));

            if ($action === 'delete') {
                $filtered = [];
                foreach ($templateSets as $set) {
                    if ((string) ($set['id'] ?? '') !== $editingId) {
                        $filtered[] = $set;
                    }
                }
                $payload['template_sets'] = $filtered;
                if (save_json_file($templatePath, $payload)) {
                    $messages[] = 'Template set deleted.';
                } else {
                    $errors[] = 'Unable to delete template set.';
                }
            } else {
                $coverPath = trim((string) ($_POST['cover_image_path'] ?? ''));
                $coverUpload = $_FILES['cover_image_file'] ?? null;
                if (is_array($coverUpload) && ($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $storedCover = docs_store_uploaded_image($coverUpload, docs_covers_image_dir(), '/images/docs/covers', 'cover');
                    if ($storedCover === null) {
                        $errors[] = 'Cover image upload failed. Allowed types: jpg/png/webp, max 5MB.';
                    } else {
                        $coverPath = $storedCover;
                    }
                }

                if ($coverPath !== '' && !preg_match('#^/images/docs/covers/[a-zA-Z0-9._\-]+$#', $coverPath)) {
                    $errors[] = 'Selected cover path is invalid.';
                }

                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    $errors[] = 'Template set name is required.';
                }

                $segment = strtoupper(trim((string) ($_POST['segment'] ?? 'RES')));
                if (!preg_match('/^[A-Z0-9]{2,6}$/', $segment)) {
                    $segment = 'RES';
                }

                $allowedTypes = $_POST['system_types_allowed'] ?? [];
                if (!is_array($allowedTypes)) {
                    $allowedTypes = [];
                }
                $allowedTypes = array_values(array_intersect(['ongrid', 'hybrid', 'offgrid', 'product'], array_map('strval', $allowedTypes)));

                $milestonesText = (string) ($_POST['payment_milestones'] ?? '');
                $milestoneLines = preg_split('/\r\n|\r|\n/', $milestonesText) ?: [];
                $milestones = [];
                foreach ($milestoneLines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $milestones[] = $line;
                    }
                }

                $setData = [
                    'id' => $editingId !== '' ? $editingId : docs_new_template_id(),
                    'name' => $name,
                    'segment' => $segment,
                    'system_types_allowed' => $allowedTypes,
                    'language_default' => in_array((string) ($_POST['language_default'] ?? 'en'), ['en', 'hi'], true) ? (string) $_POST['language_default'] : 'en',
                    'cover' => [
                        'enabled' => !empty($_POST['cover_enabled']),
                        'cover_image_path' => $coverPath,
                        'title' => trim((string) ($_POST['cover_title'] ?? 'Solar Power Proposal')),
                        'subtitle' => trim((string) ($_POST['cover_subtitle'] ?? '')),
                        'show_customer_block' => !empty($_POST['cover_show_customer_block']),
                        'show_prepared_by_block' => !empty($_POST['cover_show_prepared_by_block']),
                    ],
                    'blocks' => [
                        'intro_text' => trim((string) ($_POST['intro_text'] ?? '')),
                        'scope_of_work' => trim((string) ($_POST['scope_of_work'] ?? '')),
                        'inclusions' => trim((string) ($_POST['inclusions'] ?? '')),
                        'warranty' => trim((string) ($_POST['warranty'] ?? '')),
                        'payment_terms' => trim((string) ($_POST['payment_terms'] ?? '')),
                        'validity_text' => trim((string) ($_POST['validity_text'] ?? '')),
                        'subsidy_info_block' => trim((string) ($_POST['subsidy_info_block'] ?? '')),
                        'system_type_explainer_block' => trim((string) ($_POST['system_type_explainer_block'] ?? '')),
                        'transportation_charges_block' => trim((string) ($_POST['transportation_charges_block'] ?? '')),
                        'terms_conditions' => trim((string) ($_POST['terms_conditions'] ?? '')),
                    ],
                    'defaults' => [
                        'quotation_valid_days' => max(1, (int) ($_POST['quotation_valid_days'] ?? 15)),
                        'payment_milestones' => $milestones,
                        'gst_mode_default' => in_array((string) ($_POST['gst_mode_default'] ?? 'SPLIT_70_30'), ['SPLIT_70_30', 'FLAT_5', 'ITEMIZED'], true)
                            ? (string) $_POST['gst_mode_default']
                            : 'SPLIT_70_30',
                    ],
                    'media_library_refs' => [],
                    'updated_at' => docs_now_iso(),
                ];

                if (count($errors) === 0) {
                    $updated = false;
                    foreach ($templateSets as $index => $set) {
                        if ((string) ($set['id'] ?? '') === $setData['id']) {
                            $templateSets[$index] = $setData;
                            $updated = true;
                            break;
                        }
                    }
                    if (!$updated) {
                        $templateSets[] = $setData;
                    }

                    $payload['template_sets'] = array_values($templateSets);
                    if (save_json_file($templatePath, $payload)) {
                        $messages[] = $updated ? 'Template set updated.' : 'Template set created.';
                    } else {
                        $errors[] = 'Unable to save template set.';
                    }
                }
            }
        }
    }
}

$company = load_json_file(docs_data_dir() . '/company_profile.json', docs_default_company_profile());
$numbering = load_json_file(docs_data_dir() . '/numbering.json', docs_default_numbering());
$templatePayload = load_json_file(docs_data_dir() . '/template_sets.json', ['template_sets' => []]);
$templateSets = is_array($templatePayload['template_sets'] ?? null) ? $templatePayload['template_sets'] : [];
$coverChoices = docs_list_cover_images();

$editingTemplateId = isset($_GET['edit_template']) ? (string) $_GET['edit_template'] : '';
$activeTemplate = null;
if ($editingTemplateId !== '') {
    foreach ($templateSets as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $editingTemplateId) {
            $activeTemplate = $candidate;
            break;
        }
    }
}
if (!is_array($activeTemplate)) {
    $activeTemplate = [
        'id' => '',
        'name' => '',
        'segment' => 'RES',
        'system_types_allowed' => [],
        'language_default' => 'en',
        'cover' => [
            'enabled' => true,
            'cover_image_path' => '',
            'title' => 'Solar Power Proposal',
            'subtitle' => '',
            'show_customer_block' => true,
            'show_prepared_by_block' => true,
        ],
        'blocks' => [
            'intro_text' => '',
            'scope_of_work' => '',
            'inclusions' => '',
            'warranty' => '',
            'payment_terms' => '',
            'validity_text' => '',
            'subsidy_info_block' => '',
            'system_type_explainer_block' => '',
            'transportation_charges_block' => '',
            'terms_conditions' => '',
        ],
        'defaults' => [
            'quotation_valid_days' => 15,
            'payment_milestones' => [],
            'gst_mode_default' => 'SPLIT_70_30',
        ],
    ];
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
$prefix = $basePath === '' ? '' : $basePath;
$pathFor = static function (string $path) use ($prefix): string {
    $clean = ltrim($path, '/');
    return ($prefix === '' ? '' : $prefix) . '/' . $clean;
};

$docTypeOptions = $numbering['doc_types'] ?? [];
$segmentOptions = $numbering['segments'] ?? [];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Document Manager · Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWix+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkR4j8E4CGk6WAtx0QYQ8N6q2w1L0XbgcKxA==" crossorigin="anonymous" referrerpolicy="no-referrer">
  <style>
    body { font-family: Inter, Arial, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    .shell { max-width: 1200px; margin: 0 auto; padding: 1rem; }
    .head { display:flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
    .head h1 { margin: 0; }
    .btn { display:inline-flex; align-items:center; gap:.45rem; border:1px solid #cbd5e1; padding:.55rem .8rem; border-radius:8px; background:#fff; text-decoration:none; color:#0f172a; font-weight:600; cursor:pointer; }
    .btn-primary { background:#0f766e; border-color:#0f766e; color:#fff; }
    .tabs { display:flex; gap:.5rem; margin-bottom:1rem; flex-wrap: wrap; }
    .tab { padding:.55rem .9rem; border-radius:8px; border:1px solid #cbd5e1; text-decoration:none; color:#0f172a; font-weight:600; background:#fff; }
    .tab.active { background:#0f766e; color:#fff; border-color:#0f766e; }
    .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem; margin-bottom:1rem; }
    .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(230px,1fr)); gap:.75rem; }
    label { font-weight:600; display:block; margin-bottom:.3rem; }
    input[type="text"], input[type="email"], input[type="url"], input[type="number"], select, textarea { width:100%; padding:.55rem .6rem; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:90px; }
    .alert { border-radius:10px; padding:.7rem .9rem; margin:.6rem 0; }
    .ok { background:#ecfdf5; border:1px solid #10b98166; }
    .bad { background:#fef2f2; border:1px solid #ef444466; }
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { border-bottom:1px solid #e2e8f0; padding:.5rem; text-align:left; vertical-align: top; }
    .template-list { width:100%; border-collapse: collapse; }
    .template-list th, .template-list td { border-bottom:1px solid #e2e8f0; padding:.55rem; }
    .muted { color:#64748b; font-size:.92rem; }
    .preview-img { max-width: 180px; max-height: 120px; border:1px solid #cbd5e1; border-radius:8px; }
    .split { display:grid; grid-template-columns: 340px 1fr; gap:1rem; }
    @media (max-width: 900px) { .split { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <main class="shell">
    <header class="head">
      <div>
        <p class="muted" style="margin:0;">Admin workspace</p>
        <h1>Document Manager (Foundation)</h1>
        <p class="muted" style="margin:0;">Signed in as <strong><?= htmlspecialchars((string) ($user['full_name'] ?? 'Administrator'), ENT_QUOTES) ?></strong></p>
      </div>
      <div style="display:flex; gap:.5rem; flex-wrap: wrap;">
        <a class="btn" href="<?= htmlspecialchars($pathFor('admin-dashboard.php'), ENT_QUOTES) ?>"><i class="fa-solid fa-arrow-left"></i> Back to dashboard</a>
      </div>
    </header>

    <?php foreach ($messages as $message): ?>
      <div class="alert ok"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
      <div class="alert bad"><?= $error ?></div>
    <?php endforeach; ?>

    <nav class="tabs" aria-label="Document manager sections">
      <a class="tab <?= $tab === 'company' ? 'active' : '' ?>" href="<?= htmlspecialchars($pathFor('admin-docs.php?tab=company'), ENT_QUOTES) ?>">Company Profile</a>
      <a class="tab <?= $tab === 'numbering' ? 'active' : '' ?>" href="<?= htmlspecialchars($pathFor('admin-docs.php?tab=numbering'), ENT_QUOTES) ?>">Numbering</a>
      <a class="tab <?= $tab === 'templates' ? 'active' : '' ?>" href="<?= htmlspecialchars($pathFor('admin-docs.php?tab=templates'), ENT_QUOTES) ?>">Template Sets</a>
    </nav>

    <?php if ($tab === 'company'): ?>
      <?php $bank = is_array($company['bank_details'] ?? null) ? $company['bank_details'] : []; ?>
      <?php $sign = is_array($company['signatory'] ?? null) ? $company['signatory'] : []; ?>
      <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
        <input type="hidden" name="tab" value="company">

        <h2 style="margin-top:0;">Company Profile</h2>
        <div class="grid">
          <div><label>Company Name</label><input type="text" name="company_name" value="<?= htmlspecialchars((string) ($company['company_name'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Tagline</label><input type="text" name="tagline" value="<?= htmlspecialchars((string) ($company['tagline'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Website</label><input type="url" name="website" value="<?= htmlspecialchars((string) ($company['website'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Udyam</label><input type="text" name="udyam" value="<?= htmlspecialchars((string) ($company['udyam'] ?? ''), ENT_QUOTES) ?>"></div>
          <div style="grid-column:1/-1;"><label>Address</label><textarea name="address_line"><?= htmlspecialchars((string) ($company['address_line'] ?? ''), ENT_QUOTES) ?></textarea></div>
          <div><label>Phone 1</label><input type="text" name="phone_1" value="<?= htmlspecialchars((string) ($company['phone_1'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Phone 2</label><input type="text" name="phone_2" value="<?= htmlspecialchars((string) ($company['phone_2'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Email 1</label><input type="email" name="email_1" value="<?= htmlspecialchars((string) ($company['email_1'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Email 2</label><input type="email" name="email_2" value="<?= htmlspecialchars((string) ($company['email_2'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>GSTIN</label><input type="text" name="gstin" value="<?= htmlspecialchars((string) ($company['gstin'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>JREDA License</label><input type="text" name="jreda_license" value="<?= htmlspecialchars((string) ($company['jreda_license'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>DWSD License</label><input type="text" name="dwsd_license" value="<?= htmlspecialchars((string) ($company['dwsd_license'] ?? ''), ENT_QUOTES) ?>"></div>
          <div style="grid-column:1/-1;"><label>Other Registrations (one per line)</label><textarea name="other_registrations"><?= htmlspecialchars(implode("\n", is_array($company['other_registrations'] ?? null) ? $company['other_registrations'] : []), ENT_QUOTES) ?></textarea></div>
          <div>
            <label>Logo Upload (jpg/png/webp, max 5MB)</label>
            <input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp">
            <?php if (!empty($company['logo_path'])): ?>
              <p class="muted">Current: <?= htmlspecialchars((string) $company['logo_path'], ENT_QUOTES) ?></p>
              <img class="preview-img" src="<?= htmlspecialchars($pathFor((string) $company['logo_path']), ENT_QUOTES) ?>" alt="Company logo">
            <?php endif; ?>
          </div>
        </div>

        <h3>Bank Details</h3>
        <div class="grid">
          <div><label>Account Name</label><input type="text" name="bank_account_name" value="<?= htmlspecialchars((string) ($bank['account_name'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Bank Name</label><input type="text" name="bank_name" value="<?= htmlspecialchars((string) ($bank['bank_name'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Account No</label><input type="text" name="bank_account_no" value="<?= htmlspecialchars((string) ($bank['account_no'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>IFSC</label><input type="text" name="bank_ifsc" value="<?= htmlspecialchars((string) ($bank['ifsc'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Branch</label><input type="text" name="bank_branch" value="<?= htmlspecialchars((string) ($bank['branch'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>UPI ID</label><input type="text" name="bank_upi_id" value="<?= htmlspecialchars((string) ($bank['upi_id'] ?? ''), ENT_QUOTES) ?>"></div>
          <div>
            <label>QR Image</label>
            <input type="file" name="bank_qr_file" accept="image/jpeg,image/png,image/webp">
            <?php if (!empty($bank['qr_image_path'])): ?>
              <p class="muted">Current: <?= htmlspecialchars((string) $bank['qr_image_path'], ENT_QUOTES) ?></p>
              <img class="preview-img" src="<?= htmlspecialchars($pathFor((string) $bank['qr_image_path']), ENT_QUOTES) ?>" alt="QR image">
            <?php endif; ?>
          </div>
        </div>

        <h3>Signatory</h3>
        <div class="grid">
          <div><label>Name</label><input type="text" name="sign_name" value="<?= htmlspecialchars((string) ($sign['name'] ?? ''), ENT_QUOTES) ?>"></div>
          <div><label>Designation</label><input type="text" name="sign_designation" value="<?= htmlspecialchars((string) ($sign['designation'] ?? ''), ENT_QUOTES) ?>"></div>
          <div>
            <label>Signature Image</label><input type="file" name="signature_file" accept="image/jpeg,image/png,image/webp">
            <?php if (!empty($sign['signature_image_path'])): ?><img class="preview-img" src="<?= htmlspecialchars($pathFor((string) $sign['signature_image_path']), ENT_QUOTES) ?>" alt="Signature"><?php endif; ?>
          </div>
          <div>
            <label>Stamp Image</label><input type="file" name="stamp_file" accept="image/jpeg,image/png,image/webp">
            <?php if (!empty($sign['stamp_image_path'])): ?><img class="preview-img" src="<?= htmlspecialchars($pathFor((string) $sign['stamp_image_path']), ENT_QUOTES) ?>" alt="Stamp"><?php endif; ?>
          </div>
        </div>

        <p style="margin-top:1rem;"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Company Profile</button></p>
      </form>
    <?php endif; ?>

    <?php if ($tab === 'numbering'): ?>
      <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
        <input type="hidden" name="tab" value="numbering">
        <input type="hidden" name="numbering_action" value="save">
        <h2 style="margin-top:0;">Numbering Settings</h2>
        <div class="grid">
          <div><label>FY Mode</label><input type="text" name="financial_year_mode" value="<?= htmlspecialchars((string) ($numbering['financial_year_mode'] ?? 'FY'), ENT_QUOTES) ?>"></div>
          <div><label>FY Format</label><input type="text" name="fy_format" value="<?= htmlspecialchars((string) ($numbering['fy_format'] ?? 'YY-YY'), ENT_QUOTES) ?>"></div>
        </div>

        <h3>Document Types</h3>
        <table class="table">
          <thead><tr><th>Type</th><th>Enabled</th><th>Prefix</th><th>Use Segment</th><th>Digits (2-6)</th></tr></thead>
          <tbody>
          <?php foreach (($numbering['doc_types'] ?? []) as $docType => $cfg): ?>
            <tr>
              <td><?= htmlspecialchars((string) $docType, ENT_QUOTES) ?></td>
              <td><input type="checkbox" name="doc_types[<?= htmlspecialchars((string) $docType, ENT_QUOTES) ?>][enabled]" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>></td>
              <td><input type="text" name="doc_types[<?= htmlspecialchars((string) $docType, ENT_QUOTES) ?>][prefix]" value="<?= htmlspecialchars((string) ($cfg['prefix'] ?? ''), ENT_QUOTES) ?>"></td>
              <td><input type="checkbox" name="doc_types[<?= htmlspecialchars((string) $docType, ENT_QUOTES) ?>][use_segment]" <?= !empty($cfg['use_segment']) ? 'checked' : '' ?>></td>
              <td><input type="number" min="2" max="6" name="doc_types[<?= htmlspecialchars((string) $docType, ENT_QUOTES) ?>][digits]" value="<?= (int) ($cfg['digits'] ?? 4) ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <h3>Segments</h3>
        <table class="table">
          <thead><tr><th>Code</th><th>Label</th></tr></thead>
          <tbody>
          <?php foreach (($numbering['segments'] ?? []) as $code => $label): ?>
            <tr>
              <td><input type="text" name="segment_code[]" value="<?= htmlspecialchars((string) $code, ENT_QUOTES) ?>"></td>
              <td><input type="text" name="segment_label[]" value="<?= htmlspecialchars((string) $label, ENT_QUOTES) ?>"></td>
            </tr>
          <?php endforeach; ?>
          <?php for ($i = 0; $i < 3; $i++): ?>
            <tr><td><input type="text" name="segment_code[]" value=""></td><td><input type="text" name="segment_label[]" value=""></td></tr>
          <?php endfor; ?>
          </tbody>
        </table>

        <p><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Numbering Settings</button></p>
      </form>

      <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
        <input type="hidden" name="tab" value="numbering">
        <input type="hidden" name="numbering_action" value="preview">
        <h3 style="margin-top:0;">Preview Next Number</h3>
        <div class="grid">
          <div>
            <label>Document Type</label>
            <select name="preview_doc_type">
              <?php foreach ($docTypeOptions as $docType => $cfg): ?>
                <option value="<?= htmlspecialchars((string) $docType, ENT_QUOTES) ?>"><?= htmlspecialchars((string) $docType, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Segment</label>
            <select name="preview_segment">
              <?php foreach ($segmentOptions as $code => $label): ?>
                <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES) ?>"><?= htmlspecialchars((string) $code . ' - ' . (string) $label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p><button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Preview Next Number</button></p>
        <?php if ($previewNumber !== ''): ?><p><strong><?= htmlspecialchars($previewNumber, ENT_QUOTES) ?></strong></p><?php endif; ?>
      </form>
    <?php endif; ?>

    <?php if ($tab === 'templates'): ?>
      <div class="split">
        <section class="card">
          <h2 style="margin-top:0;">Template Sets</h2>
          <p><a class="btn" href="<?= htmlspecialchars($pathFor('admin-docs.php?tab=templates'), ENT_QUOTES) ?>"><i class="fa-solid fa-plus"></i> New Template Set</a></p>
          <table class="template-list">
            <thead><tr><th>Name</th><th>Segment</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($templateSets as $set): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($set['name'] ?? ''), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($set['segment'] ?? ''), ENT_QUOTES) ?></td>
                <td>
                  <a class="btn" href="<?= htmlspecialchars($pathFor('admin-docs.php?tab=templates&edit_template=' . rawurlencode((string) ($set['id'] ?? ''))), ENT_QUOTES) ?>">Edit</a>
                  <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this template set?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
                    <input type="hidden" name="tab" value="templates">
                    <input type="hidden" name="templates_action" value="delete">
                    <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($set['id'] ?? ''), ENT_QUOTES) ?>">
                    <button type="submit" class="btn">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </section>

        <form method="post" enctype="multipart/form-data" class="card">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES) ?>">
          <input type="hidden" name="tab" value="templates">
          <input type="hidden" name="templates_action" value="save">
          <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) ($activeTemplate['id'] ?? ''), ENT_QUOTES) ?>">

          <h2 style="margin-top:0;"><?= ($activeTemplate['id'] ?? '') !== '' ? 'Edit Template Set' : 'Create Template Set' ?></h2>
          <div class="grid">
            <div><label>Name</label><input type="text" name="name" value="<?= htmlspecialchars((string) ($activeTemplate['name'] ?? ''), ENT_QUOTES) ?>"></div>
            <div>
              <label>Segment</label>
              <select name="segment">
                <?php foreach (($numbering['segments'] ?? []) as $code => $label): ?>
                  <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES) ?>" <?= (string) ($activeTemplate['segment'] ?? '') === (string) $code ? 'selected' : '' ?>><?= htmlspecialchars((string) $code . ' - ' . (string) $label, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Language</label>
              <select name="language_default">
                <option value="en" <?= (string) ($activeTemplate['language_default'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                <option value="hi" <?= (string) ($activeTemplate['language_default'] ?? 'en') === 'hi' ? 'selected' : '' ?>>Hindi</option>
              </select>
            </div>
          </div>

          <h3>Allowed System Types</h3>
          <?php $selectedTypes = is_array($activeTemplate['system_types_allowed'] ?? null) ? $activeTemplate['system_types_allowed'] : []; ?>
          <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <?php foreach (['ongrid', 'hybrid', 'offgrid', 'product'] as $type): ?>
              <label style="font-weight:500;"><input type="checkbox" name="system_types_allowed[]" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>" <?= in_array($type, $selectedTypes, true) ? 'checked' : '' ?>> <?= htmlspecialchars(ucfirst($type), ENT_QUOTES) ?></label>
            <?php endforeach; ?>
          </div>

          <?php $cover = is_array($activeTemplate['cover'] ?? null) ? $activeTemplate['cover'] : []; ?>
          <h3>Cover Settings</h3>
          <div class="grid">
            <div><label><input type="checkbox" name="cover_enabled" <?= !empty($cover['enabled']) ? 'checked' : '' ?>> Cover Enabled</label></div>
            <div><label><input type="checkbox" name="cover_show_customer_block" <?= !empty($cover['show_customer_block']) ? 'checked' : '' ?>> Show Customer Block</label></div>
            <div><label><input type="checkbox" name="cover_show_prepared_by_block" <?= !empty($cover['show_prepared_by_block']) ? 'checked' : '' ?>> Show Prepared By Block</label></div>
            <div><label>Cover Title</label><input type="text" name="cover_title" value="<?= htmlspecialchars((string) ($cover['title'] ?? ''), ENT_QUOTES) ?>"></div>
            <div><label>Cover Subtitle</label><input type="text" name="cover_subtitle" value="<?= htmlspecialchars((string) ($cover['subtitle'] ?? ''), ENT_QUOTES) ?>"></div>
            <div>
              <label>Choose Existing Cover</label>
              <select name="cover_image_path">
                <option value="">-- none --</option>
                <?php $currentCover = (string) ($cover['cover_image_path'] ?? ''); ?>
                <?php foreach ($coverChoices as $choice): ?>
                  <option value="<?= htmlspecialchars($choice, ENT_QUOTES) ?>" <?= $currentCover === $choice ? 'selected' : '' ?>><?= htmlspecialchars($choice, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Upload New Cover</label>
              <input type="file" name="cover_image_file" accept="image/jpeg,image/png,image/webp">
            </div>
            <?php if ((string) ($cover['cover_image_path'] ?? '') !== ''): ?>
              <div>
                <label>Current Cover</label>
                <img class="preview-img" src="<?= htmlspecialchars($pathFor((string) $cover['cover_image_path']), ENT_QUOTES) ?>" alt="Template cover">
              </div>
            <?php endif; ?>
          </div>

          <?php $blocks = is_array($activeTemplate['blocks'] ?? null) ? $activeTemplate['blocks'] : []; ?>
          <h3>Blocks</h3>
          <div class="grid">
            <?php foreach (['intro_text','scope_of_work','inclusions','warranty','payment_terms','validity_text','subsidy_info_block','system_type_explainer_block','transportation_charges_block','terms_conditions'] as $blockKey): ?>
              <div style="grid-column:1/-1;">
                <label><?= htmlspecialchars(ucwords(str_replace('_', ' ', $blockKey)), ENT_QUOTES) ?></label>
                <textarea name="<?= htmlspecialchars($blockKey, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($blocks[$blockKey] ?? ''), ENT_QUOTES) ?></textarea>
              </div>
            <?php endforeach; ?>
          </div>

          <?php $defaults = is_array($activeTemplate['defaults'] ?? null) ? $activeTemplate['defaults'] : []; ?>
          <h3>Defaults</h3>
          <div class="grid">
            <div><label>Quotation Valid Days</label><input type="number" min="1" name="quotation_valid_days" value="<?= (int) ($defaults['quotation_valid_days'] ?? 15) ?>"></div>
            <div>
              <label>GST Mode</label>
              <select name="gst_mode_default">
                <?php $currentGst = (string) ($defaults['gst_mode_default'] ?? 'SPLIT_70_30'); ?>
                <?php foreach (['SPLIT_70_30', 'FLAT_5', 'ITEMIZED'] as $mode): ?>
                  <option value="<?= htmlspecialchars($mode, ENT_QUOTES) ?>" <?= $currentGst === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="grid-column:1/-1;">
              <label>Payment Milestones (one per line, e.g. Booking|20)</label>
              <textarea name="payment_milestones"><?= htmlspecialchars(implode("\n", is_array($defaults['payment_milestones'] ?? null) ? $defaults['payment_milestones'] : []), ENT_QUOTES) ?></textarea>
            </div>
          </div>

          <p><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Template Set</button></p>
        </form>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
