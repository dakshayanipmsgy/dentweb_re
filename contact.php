<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer_public.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/marketing_campaigns.php';
require_once __DIR__ . '/includes/leads.php';

$consultSuccess = '';
$consultError = '';
$complaintSuccess = '';
$complaintError = '';
$customerData = null;
$lastComplaint = null;
$mobileInput = '';
$incomingCampaignCode = '';
$incomingSourceMedium = '';

if (isset($_GET['campaign'])) {
    $incomingCampaignCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['campaign'] ?? '')) ?? '';
    $incomingCampaignCode = trim($incomingCampaignCode);
}

if (isset($_GET['source'])) {
    $incomingSourceMedium = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['source'] ?? '')) ?? '';
    $incomingSourceMedium = trim($incomingSourceMedium);
}

function contact_register_complaint(array $payload): array
{
    $store = public_customer_store();
    $mobile = public_normalize_mobile((string) ($payload['mobile'] ?? ''));
    if ($mobile === '') {
        throw new RuntimeException('Enter a valid registered mobile number.');
    }

    $customer = $store->findByMobile($mobile);
    if ($customer === null) {
        throw new RuntimeException('Only registered customers can submit complaints. Please contact our team if you have not yet completed installation.');
    }

    $record = customer_complaint_submit([
        'mobile' => $mobile,
        'title' => $payload['title'] ?? '',
        'description' => $payload['description'] ?? '',
        'problem_category' => $payload['problem_category'] ?? '',
    ]);

    $customer = $store->findByMobile($mobile) ?? $customer;

    return [$record, $customer];
}

function build_complaint_message(array $customer, array $record): string
{
    $lines = [];
    $lines[] = 'Solar complaint request';
    $lines[] = 'Customer: ' . trim((string) ($customer['full_name'] ?? $customer['name'] ?? 'Customer'));

    $mobile = (string) ($record['customer_mobile'] ?? '');
    if ($mobile !== '') {
        $lines[] = 'Mobile: +91 ' . $mobile;
    }

    $address = trim((string) ($customer['address_line'] ?? $customer['address'] ?? ''));
    $city = trim((string) ($customer['district'] ?? $customer['city'] ?? ''));
    $state = trim((string) ($customer['state'] ?? ''));
    $meter = trim((string) ($customer['meter_number'] ?? $customer['jbvnl_account_number'] ?? ''));

    if ($address !== '') {
        $lines[] = 'Address: ' . $address;
    }
    if ($city !== '' || $state !== '') {
        $lines[] = 'City/State: ' . implode(', ', array_filter([$city, $state]));
    }
    if ($meter !== '') {
        $lines[] = 'Meter/Account: ' . $meter;
    }

    $problemCategory = (string) ($record['problem_category'] ?? '');
    if ($problemCategory !== '') {
        $lines[] = 'Category: ' . $problemCategory;
    }

    $title = (string) ($record['title'] ?? '');
    if ($title !== '') {
        $lines[] = 'Title: ' . $title;
    }

    $description = (string) ($record['description'] ?? '');
    if ($description !== '') {
        $lines[] = 'Description: ' . $description;
    }

    $createdAt = (string) ($record['created_at'] ?? '');
    if ($createdAt !== '') {
        $lines[] = 'Raised at: ' . $createdAt;
    }

    $id = (string) ($record['id'] ?? '');
    if ($id !== '') {
        $lines[] = 'Reference ID: ' . $id;
    }

    return implode("\n", $lines);
}

$ws = website_settings();
$global = $ws['global'] ?? [];
$hero = $ws['hero'] ?? [];
$sections = $ws['sections'] ?? [];
$testimonials = $ws['testimonials'] ?? [];
$offers = $ws['seasonal_offers'] ?? [];
$theme = $ws['theme'] ?? [];
$primaryColor = $theme['primary_color'] ?? '#333333';
$secondaryColor = $theme['secondary_color'] ?? '#00374a';
$accentColor = $theme['accent_color'] ?? '#f5ec00';
$buttonStyle = $theme['button_style'] ?? 'rounded';
$cardStyle = $theme['card_style'] ?? 'soft';

$buttonTokens = [
    'rounded' => [
        'radius' => '0.85rem',
        'primary_shadow' => '0 12px 24px rgba(251, 191, 36, 0.35)',
        'primary_hover_shadow' => '0 16px 30px rgba(217, 119, 6, 0.45)',
        'secondary_shadow' => '0 12px 26px rgba(30, 58, 138, 0.25)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
    'pill' => [
        'radius' => '999px',
        'primary_shadow' => '0 14px 28px rgba(251, 191, 36, 0.35)',
        'primary_hover_shadow' => '0 18px 32px rgba(217, 119, 6, 0.45)',
        'secondary_shadow' => '0 14px 28px rgba(30, 58, 138, 0.3)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
    'outline' => [
        'radius' => '0.9rem',
        'primary_shadow' => '0 10px 18px rgba(15, 23, 42, 0.08)',
        'primary_hover_shadow' => '0 12px 24px rgba(15, 23, 42, 0.12)',
        'secondary_shadow' => '0 10px 18px rgba(15, 23, 42, 0.08)',
        'primary_border' => '2px solid ' . $primaryColor,
        'secondary_border' => '2px solid ' . $secondaryColor,
        'primary_bg' => 'transparent',
        'primary_hover_bg' => $primaryColor,
        'secondary_bg' => 'transparent',
        'secondary_hover_bg' => $secondaryColor,
        'base_border' => '1px solid rgba(148, 163, 184, 0.35)',
    ],
    'sharp' => [
        'radius' => '0.35rem',
        'primary_shadow' => '0 10px 22px rgba(15, 23, 42, 0.14)',
        'primary_hover_shadow' => '0 14px 26px rgba(15, 23, 42, 0.2)',
        'secondary_shadow' => '0 10px 20px rgba(15, 23, 42, 0.12)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid rgba(148, 163, 184, 0.45)',
    ],
    'solid-heavy' => [
        'radius' => '0.75rem',
        'primary_shadow' => '0 18px 36px rgba(15, 23, 42, 0.22)',
        'primary_hover_shadow' => '0 22px 46px rgba(15, 23, 42, 0.28)',
        'secondary_shadow' => '0 18px 32px rgba(15, 23, 42, 0.2)',
        'primary_border' => '1px solid transparent',
        'secondary_border' => '1px solid transparent',
        'primary_bg' => null,
        'primary_hover_bg' => null,
        'secondary_bg' => null,
        'secondary_hover_bg' => null,
        'base_border' => '1px solid transparent',
    ],
];

$cardTokens = [
    'soft' => [
        'radius' => '1.25rem',
        'shadow' => '0 12px 32px rgba(15, 23, 42, 0.08)',
        'border' => '1px solid rgba(148, 163, 184, 0.16)',
        'surface' => '#ffffff',
    ],
    'strong' => [
        'radius' => '1.1rem',
        'shadow' => '0 20px 45px rgba(15, 23, 42, 0.16)',
        'border' => '1px solid rgba(148, 163, 184, 0.18)',
        'surface' => '#ffffff',
    ],
    'border' => [
        'radius' => '1rem',
        'shadow' => '0 4px 10px rgba(15, 23, 42, 0.06)',
        'border' => '1px solid rgba(15, 23, 42, 0.12)',
        'surface' => '#ffffff',
    ],
    'flat' => [
        'radius' => '0.9rem',
        'shadow' => 'none',
        'border' => '1px solid transparent',
        'surface' => '#f8fafc',
    ],
];

$buttonStyle = array_key_exists($buttonStyle, $buttonTokens) ? $buttonStyle : 'rounded';
$cardStyle = array_key_exists($cardStyle, $cardTokens) ? $cardStyle : 'soft';
$buttonToken = $buttonTokens[$buttonStyle];
$cardToken = $cardTokens[$cardStyle];
$buttonPrimaryText = ($buttonToken['primary_bg'] === 'transparent') ? $primaryColor : '#ffffff';
$buttonSecondaryText = ($buttonToken['secondary_bg'] === 'transparent') ? $secondaryColor : '#ffffff';
$embeddedContent = [
    'theme' => $theme,
    'hero' => $hero,
    'sections' => $sections,
    'offers' => $offers,
    'testimonials' => $testimonials,
    'global' => $global,
];
$embeddedContentJson = htmlspecialchars(json_encode($embeddedContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$heroTitle = $hero['title'] ?? '';
$heroSubtitle = $hero['subtitle'] ?? '';

$schemaGraph = [
    [
        '@type' => 'Article',
        'headline' => $heroTitle,
        'description' => $heroSubtitle,
        'author' => [
            '@type' => 'Organization',
            'name' => 'Dakshayani Enterprises',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Dakshayani Enterprises',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => 'https://dakshayani.co.in/images/logo/New%20dakshayani%20logo%20centered%20small.png',
            ],
        ],
        'image' => 'https://dakshayani.co.in/images/og/dakshayani-hero.jpg',
        'datePublished' => '2024-11-01',
        'mainEntityOfPage' => 'https://dakshayani.co.in/',
    ],
    [
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'How quickly can Dakshayani file PM Surya Ghar subsidies?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'All documentation is pre-verified and filed within 21 days with MNRE/JREDA and the local DISCOM.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Do you offer real-time solar generation monitoring?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Yes. Customers receive dashboards with Solar API and inverter integrations, plus WhatsApp alerts for uptime.',
                ],
            ],
        ],
    ],
    [
        '@type' => 'Review',
        'itemReviewed' => [
            '@type' => 'LocalBusiness',
            'name' => 'Dakshayani Enterprises',
        ],
        'reviewRating' => [
            '@type' => 'Rating',
            'ratingValue' => '5',
            'bestRating' => '5',
        ],
        'author' => [
            '@type' => 'Person',
            'name' => 'Asha Verma',
        ],
        'reviewBody' => 'Seamless 8 kW rooftop installation with real-time monitoring and transparent subsidy support.',
        'datePublished' => '2024-10-02',
    ],
];

$schemaContext = [
    '@context' => 'https://schema.org',
    '@graph' => $schemaGraph,
];

function consultation_storage_path(): string
{
    $dir = __DIR__ . '/storage/consultations';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/requests.json';
}

function save_consultation_request(array $input, string $campaignCode = '', string $sourceMedium = ''): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $mobileRaw = (string) ($input['mobile'] ?? '');
    $mobile = public_normalize_mobile($mobileRaw);
    $city = trim((string) ($input['city'] ?? ''));
    $message = trim((string) ($input['message'] ?? ''));
    $campaignCode = trim((string) ($input['source_campaign_code'] ?? $campaignCode));
    $campaignCode = preg_replace('/[^A-Za-z0-9_-]/', '', $campaignCode) ?? '';
    $sourceMedium = trim((string) ($input['source_medium'] ?? $sourceMedium));

    if ($mobile === '') {
        throw new RuntimeException('Please enter a valid 10-digit mobile number.');
    }

    $linkedCampaign = null;
    if ($campaignCode !== '') {
        $campaignLoadError = null;
        $campaignList = marketing_campaigns_load($campaignLoadError);
        foreach ($campaignList as $campaign) {
            if ((string) ($campaign['tracking_code'] ?? '') === $campaignCode) {
                $linkedCampaign = $campaign;
                break;
            }
        }
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    $entry = [
        'id' => complaint_random_id(),
        'name' => $name === '' ? 'Visitor' : $name,
        'mobile' => $mobile,
        'city' => $city,
        'message' => $message,
        'created_at' => $now,
    ];

    if ($linkedCampaign !== null) {
        $entry['source_campaign_code'] = $campaignCode;
        $entry['source_campaign_id'] = (string) ($linkedCampaign['id'] ?? '');
        $entry['source_campaign_name'] = (string) ($linkedCampaign['name'] ?? '');
        if ($sourceMedium !== '') {
            $entry['source_medium'] = $sourceMedium;
        }
    }

    $path = consultation_storage_path();
    $payload = [];
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $payload[] = $entry;
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Could not save your request right now.');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not log your consultation request.');
    }

    $leadPayload = [
        'name' => $entry['name'],
        'mobile' => $entry['mobile'],
        'city' => $city,
        'lead_source' => 'Website Consultation',
        'interest_type' => 'Solar Enquiry',
        'status' => 'New',
        'rating' => 'Warm',
    ];

    if ($message !== '') {
        $leadPayload['notes'] = $message;
    }

    if ($linkedCampaign !== null) {
        $leadPayload['source_campaign_code'] = $campaignCode;
        $leadPayload['source_campaign_id'] = (string) ($linkedCampaign['id'] ?? '');
        $leadPayload['source_campaign_name'] = (string) ($linkedCampaign['name'] ?? '');
        $leadPayload['source_medium'] = $sourceMedium !== '' ? $sourceMedium : 'online';
    }

    try {
        add_lead($leadPayload);
    } catch (Throwable $exception) {
        error_log('contact: unable to append lead: ' . $exception->getMessage());
    }

    return $entry;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $mobileInput = (string) ($_POST['mobile'] ?? '');

    if ($action === 'consultation') {
        try {
            $record = save_consultation_request($_POST, $incomingCampaignCode, $incomingSourceMedium);
            $consultSuccess = 'Thanks! Our solar specialist will call you shortly at +91 ' . htmlspecialchars($record['mobile'], ENT_QUOTES);
        } catch (Throwable $exception) {
            $consultError = $exception->getMessage();
        }
    }

    if ($action === 'fetch_customer') {
        $normalized = public_normalize_mobile($mobileInput);
        if ($normalized === '') {
            $complaintError = 'Please enter a valid 10-digit registered mobile number.';
        } else {
            $store = public_customer_store();
            $customerData = $store->findByMobile($normalized);
            if ($customerData === null) {
                $complaintError = 'This mobile number is not registered with our system. Please ensure you have installed a system with us or contact support.';
            } else {
                $mobileInput = $normalized;
            }
        }
    }

    if ($action === 'complaint_whatsapp' || $action === 'complaint_email') {
        try {
            [$record, $customerData] = contact_register_complaint([
                'mobile' => $mobileInput,
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'problem_category' => $_POST['problem_category'] ?? '',
            ]);

            $mobileInput = $record['customer_mobile'] ?? $mobileInput;
            $lastComplaint = $record;
            $message = build_complaint_message($customerData ?? [], $record);

            if ($action === 'complaint_whatsapp') {
                $whatsAppUrl = 'https://wa.me/918102401427?text=' . rawurlencode($message);
                header('Location: ' . $whatsAppUrl);
                exit;
            }

            if ($action === 'complaint_email') {
                $subject = 'New Solar Complaint - ' . ($customerData['full_name'] ?? $customerData['name'] ?? '') . ' - ' . $mobileInput;
                $mailTo = 'mailto:connect@dakshayani.co.in?cc=' . rawurlencode('dakshayani.works@hotmail.com') . '&subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message);
                header('Location: ' . $mailTo);
                exit;
            }
        } catch (Throwable $exception) {
            $complaintError = $exception->getMessage();
            $normalized = public_normalize_mobile($mobileInput);
            if ($normalized !== '') {
                $store = public_customer_store();
                $customerData = $store->findByMobile($normalized);
                $mobileInput = $normalized;
            }
        }
    }
}

$problemCategories = complaint_problem_categories();
$customerName = $customerData === null ? '' : (string) ($customerData['full_name'] ?? $customerData['name'] ?? 'Customer');
$customerAddress = $customerData === null ? '' : trim((string) ($customerData['address_line'] ?? $customerData['address'] ?? ''));
$customerCity = $customerData === null ? '' : (string) ($customerData['district'] ?? $customerData['city'] ?? '');
$customerState = $customerData === null ? '' : (string) ($customerData['state'] ?? '');
$customerMeter = $customerData === null ? '' : (string) ($customerData['meter_number'] ?? $customerData['jbvnl_account_number'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Contact | Consult · Complaint · Connect | Dakshayani Enterprises</title>
  <meta name="description" content="Talk to Dakshayani Enterprises for solar consultations, register a service complaint, or connect directly with the owner." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script id="site-settings-json" type="application/json"><?= $embeddedContentJson ?></script>
  <script>
    window.DAKSHAYANI_RECAPTCHA_SITE_KEY = window.DAKSHAYANI_RECAPTCHA_SITE_KEY || 'replace-with-site-key';
    window.DAKSHAYANI_GOOGLE_CLIENT_ID = window.DAKSHAYANI_GOOGLE_CLIENT_ID || 'replace-with-google-client-id.apps.googleusercontent.com';
  </script>
  <script type="application/ld+json">
    <?= json_encode($schemaContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
  </script>
  <style>
    :root {
      --primary: <?= htmlspecialchars($primaryColor) ?>;
      --secondary: <?= htmlspecialchars($secondaryColor) ?>;
      --accent: <?= htmlspecialchars($accentColor) ?>;
      --btn-radius: <?= htmlspecialchars($buttonToken['radius']) ?>;
      --btn-primary-shadow: <?= htmlspecialchars($buttonToken['primary_shadow'] ?? 'none') ?>;
      --btn-primary-hover-shadow: <?= htmlspecialchars($buttonToken['primary_hover_shadow'] ?? 'none') ?>;
      --btn-secondary-shadow: <?= htmlspecialchars($buttonToken['secondary_shadow'] ?? 'none') ?>;
      --btn-primary-border: <?= htmlspecialchars($buttonToken['primary_border'] ?? '1px solid transparent') ?>;
      --btn-secondary-border: <?= htmlspecialchars($buttonToken['secondary_border'] ?? '1px solid transparent') ?>;
      --btn-primary-bg: <?= htmlspecialchars($buttonToken['primary_bg'] ?? $primaryColor) ?>;
      --btn-primary-hover-bg: <?= htmlspecialchars($buttonToken['primary_hover_bg'] ?? $secondaryColor) ?>;
      --btn-secondary-bg: <?= htmlspecialchars($buttonToken['secondary_bg'] ?? $secondaryColor) ?>;
      --btn-secondary-hover-bg: <?= htmlspecialchars($buttonToken['secondary_hover_bg'] ?? $secondaryColor) ?>;
      --btn-base-border: <?= htmlspecialchars($buttonToken['base_border'] ?? '1px solid transparent') ?>;
      --btn-primary-text: <?= htmlspecialchars($buttonPrimaryText) ?>;
      --btn-secondary-text: <?= htmlspecialchars($buttonSecondaryText) ?>;
      --card-shadow: <?= htmlspecialchars($cardToken['shadow']) ?>;
      --card-border: <?= htmlspecialchars($cardToken['border']) ?>;
      --card-surface: <?= htmlspecialchars($cardToken['surface']) ?>;
      --radius-card: <?= htmlspecialchars($cardToken['radius']) ?>;
    }
    .contact-hero {
      background: linear-gradient(120deg, rgba(15, 23, 42, 0.92), rgba(17, 94, 89, 0.88)), url('/images/solar.jpg') center/cover no-repeat;
      color: #fff;
      padding: 4.5rem 1.25rem;
    }
    .contact-hero .badge-inline,
    .contact-hero h1,
    .contact-hero .hero-subtitle {
      color: #fff;
    }
    .contact-hero .container {
      display: grid;
      gap: 1.5rem;
      max-width: none;
      width: 100%;
      margin: 0;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      align-items: center;
    }
    .contact-hero h1 {
      font-size: clamp(2rem, 3vw + 1rem, 2.85rem);
      margin-bottom: 0.75rem;
    }
    .contact-hero p {
      color: rgba(255,255,255,0.88);
      line-height: 1.6;
      margin-bottom: 1rem;
    }
    .contact-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin: 0;
      max-width: none;
      width: 100%;
      padding: 2rem 1.25rem 3rem;
    }
    .contact-card {
      background: #ffffff;
      border: 1px solid rgba(148, 163, 184, 0.2);
      box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
      border-radius: 1.1rem;
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .contact-card h2 {
      margin: 0;
      font-size: 1.4rem;
      color: var(--base-900);
    }
    .contact-card p {
      margin: 0;
      color: var(--base-600);
    }
    .contact-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0.25rem;
    }
    .contact-actions .btn {
      flex: 1;
      min-width: 180px;
      justify-content: center;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 600;
      text-align: center;
    }
    .form-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .form-grid label {
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
      font-weight: 600;
      color: var(--base-800);
    }
    .form-grid input,
    .form-grid textarea,
    .form-grid select {
      border-radius: 0.75rem;
      border: 1px solid rgba(148, 163, 184, 0.6);
      padding: 0.85rem 1rem;
      font-size: 1rem;
      color: var(--base-900);
      background: #f8fafc;
    }
    .form-grid textarea { resize: vertical; min-height: 120px; }
    .badge-inline {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.75rem;
      background: rgba(79, 70, 229, 0.1);
      color: #312e81;
      border-radius: 999px;
      font-weight: 600;
      font-size: 0.9rem;
    }
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.75rem;
      margin-top: 0.5rem;
    }
    .info-pill {
      background: #f8fafc;
      border: 1px solid rgba(148, 163, 184, 0.35);
      border-radius: 0.85rem;
      padding: 0.75rem 0.95rem;
      display: grid;
      gap: 0.25rem;
    }
    .info-pill span:first-child {
      color: var(--base-500);
      font-size: 0.9rem;
    }
    .info-pill span:last-child {
      color: var(--base-900);
      font-weight: 700;
    }
    .alert {
      padding: 0.9rem 1rem;
      border-radius: 0.85rem;
      border: 1px solid transparent;
      font-weight: 600;
    }
    .alert-success { background: #ecfdf3; color: #166534; border-color: #bbf7d0; }
    .alert-error { background: #fef2f2; color: #b91c1c; border-color: #fecdd3; }
    .secondary-note {
      color: var(--base-600);
      font-size: 0.95rem;
    }
    .complaint-share {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-top: 0.5rem;
    }
    .complaint-share .btn { flex: 1; min-width: 190px; justify-content: center; }
  </style>
</head>
<body>
  <header class="site-header"></header>

  <?php
  $siteTagline = trim((string) ($global['site_tagline'] ?? ''));
  $headerCallout = trim((string) ($global['header_callout'] ?? ''));
  $showSubheader = $siteTagline !== '' || $headerCallout !== '';
  ?>

  <?php if ($showSubheader): ?>
    <div class="site-subheader" aria-label="Site announcements">
      <div class="container site-subheader-stack">
        <div class="subheader-marquee" data-subheader-marquee>
          <div class="subheader-marquee__inner" data-marquee-inner>
            <div class="subheader-marquee__track" data-marquee-track>
              <span class="nav-theme-badge nav-tagline" data-site-tagline <?= $siteTagline === '' ? 'hidden' : '' ?>>
                <?= htmlspecialchars($siteTagline) ?>
              </span>
              <div class="nav-theme-badge header-callout" role="status" data-header-callout <?= $headerCallout === '' ? 'hidden' : '' ?>>
                <?= htmlspecialchars($headerCallout) ?>
              </div>
            </div>
            <div class="subheader-marquee__track" data-marquee-track-clone aria-hidden="true"></div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <section class="contact-hero">
    <div class="container">
      <div>
        <span class="badge-inline"><i class="fa-solid fa-handshake-angle"></i> Consult · Complaint · Connect</span>
        <h1>Get help, register a service complaint, or connect directly.</h1>
        <p>New to solar or already installed with us? Request a free consultation, log a service complaint as a registered customer, or speak directly with our founder for urgent needs.</p>
        <div class="contact-actions">
          <a class="btn btn-primary" href="tel:7070278178"><i class="fa-solid fa-phone"></i>Call now</a>
          <a class="btn btn-secondary" href="https://wa.me/7070278178?text=Hi%2C%20I%20want%20to%20discuss%20about%20solar." target="_blank" rel="noreferrer noopener"><i class="fa-brands fa-whatsapp"></i>WhatsApp owner</a>
        </div>
      </div>
      <div class="card" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.25);backdrop-filter:blur(6px);">
        <p class="mb-2" style="color:rgba(255,255,255,0.8);">Quick links</p>
        <ul class="list-check" style="color:#ffffff;">
          <li><i class="fa-solid fa-check"></i>Free solar consultation within 1 business day</li>
          <li><i class="fa-solid fa-check"></i>Complaint registration for existing customers</li>
          <li><i class="fa-solid fa-check"></i>WhatsApp / email escalation to service desk</li>
        </ul>
      </div>
    </div>
  </section>

  <main class="contact-grid">
    <section class="contact-card" id="free-consultation">
      <div>
        <span class="badge-inline"><i class="fa-solid fa-solar-panel"></i> Free Consultation</span>
        <h2>Free Solar Consultation</h2>
        <p>Share your requirements and our specialist will call back with sizing, subsidy, and financing guidance.</p>
      </div>

      <?php if ($consultSuccess !== ''): ?>
        <div class="alert alert-success" role="status"><?php echo $consultSuccess; ?></div>
      <?php endif; ?>
      <?php if ($consultError !== ''): ?>
        <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($consultError, ENT_QUOTES); ?></div>
      <?php endif; ?>

      <form method="post" class="form-grid" id="consultation-form" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
        <input type="hidden" name="action" value="consultation" />
        <input type="hidden" name="source_campaign_code" value="<?php echo htmlspecialchars($incomingCampaignCode, ENT_QUOTES); ?>" />
        <input type="hidden" name="source_medium" value="<?php echo htmlspecialchars($incomingSourceMedium, ENT_QUOTES); ?>" />
        <label>Full Name
          <input type="text" name="name" placeholder="Your name" />
        </label>
        <label>Mobile Number
          <input type="tel" name="mobile" placeholder="10-digit mobile" required />
        </label>
        <label>City / Town
          <input type="text" name="city" placeholder="City" />
        </label>
        <label>Message / Requirements
          <textarea name="message" rows="3" placeholder="Tell us about your solar needs"></textarea>
        </label>
        <div class="contact-actions">
          <button type="submit" class="btn btn-primary" style="flex:2;"><i class="fa-solid fa-paper-plane"></i>Request callback</button>
          <a class="btn btn-secondary" href="tel:7070278178"><i class="fa-solid fa-phone"></i>Call now</a>
          <a class="btn btn-secondary" data-consult-whatsapp href="https://wa.me/7070278178?text=Hi%2C%20I%20want%20a%20free%20solar%20consultation.%20My%20name%20is..." target="_blank" rel="noreferrer noopener"><i class="fa-brands fa-whatsapp"></i>WhatsApp now</a>
        </div>
      </form>
      <p class="secondary-note">Prefer a quick chat? Tap the call or WhatsApp buttons for immediate response.</p>
    </section>

    <section class="contact-card" id="register-complaint">
      <div>
        <span class="badge-inline" style="background:rgba(239,68,68,0.12);color:#991b1b;"><i class="fa-solid fa-triangle-exclamation"></i> Register a Complaint</span>
        <h2>Register a Complaint (registered customers)</h2>
        <p>Use your registered mobile number to fetch your account and log a service issue. Complaints sync instantly with admin, employee, and your customer portal.</p>
      </div>

      <?php if ($complaintSuccess !== ''): ?>
        <div class="alert alert-success" role="status"><?php echo $complaintSuccess; ?></div>
      <?php endif; ?>
      <?php if ($complaintError !== ''): ?>
        <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($complaintError, ENT_QUOTES); ?></div>
      <?php endif; ?>

      <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
        <input type="hidden" name="action" value="fetch_customer" />
        <label>Registered Mobile Number
          <input type="tel" name="mobile" value="<?php echo htmlspecialchars($mobileInput, ENT_QUOTES); ?>" placeholder="Enter 10-digit registered mobile" required />
        </label>
        <div style="display:flex;align-items:flex-end;">
          <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fa-solid fa-magnifying-glass"></i>Fetch details</button>
        </div>
      </form>

      <?php if ($customerData !== null): ?>
        <div class="card" style="background:var(--surface);border:1px solid rgba(79,70,229,0.15);">
          <p class="secondary-note" style="margin-bottom:0.5rem;">Verified customer</p>
          <div class="info-grid" id="customer-profile"
            data-name="<?php echo htmlspecialchars($customerName, ENT_QUOTES); ?>"
            data-address="<?php echo htmlspecialchars($customerAddress, ENT_QUOTES); ?>"
            data-city="<?php echo htmlspecialchars($customerCity, ENT_QUOTES); ?>"
            data-state="<?php echo htmlspecialchars($customerState, ENT_QUOTES); ?>"
            data-meter="<?php echo htmlspecialchars($customerMeter, ENT_QUOTES); ?>"
            data-mobile="<?php echo htmlspecialchars($mobileInput, ENT_QUOTES); ?>">
            <div class="info-pill"><span>Customer</span><span><?php echo htmlspecialchars($customerName, ENT_QUOTES); ?></span></div>
            <div class="info-pill"><span>Mobile</span><span><?php echo htmlspecialchars($mobileInput, ENT_QUOTES); ?></span></div>
            <div class="info-pill"><span>Address</span><span><?php echo htmlspecialchars($customerAddress !== '' ? $customerAddress : 'On file', ENT_QUOTES); ?></span></div>
            <div class="info-pill"><span>City / District</span><span><?php echo htmlspecialchars($customerCity !== '' ? $customerCity : 'N/A', ENT_QUOTES); ?></span></div>
            <div class="info-pill"><span>State</span><span><?php echo htmlspecialchars($customerState !== '' ? $customerState : 'N/A', ENT_QUOTES); ?></span></div>
            <div class="info-pill"><span>Meter / Account</span><span><?php echo htmlspecialchars($customerMeter !== '' ? $customerMeter : 'N/A', ENT_QUOTES); ?></span></div>
          </div>
        </div>

        <form method="post" class="form-grid" id="complaint-form" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
          <input type="hidden" name="mobile" value="<?php echo htmlspecialchars($mobileInput, ENT_QUOTES); ?>" />
          <label>Problem Category
            <select name="problem_category" required>
              <option value="">Select category</option>
              <?php foreach ($problemCategories as $category): ?>
                <option value="<?php echo htmlspecialchars($category, ENT_QUOTES); ?>" <?php echo (isset($_POST['problem_category']) && $_POST['problem_category'] === $category) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category, ENT_QUOTES); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Complaint Title
            <input type="text" name="title" placeholder="Short title" value="<?php echo htmlspecialchars((string) ($_POST['title'] ?? ''), ENT_QUOTES); ?>" required />
          </label>
          <label style="grid-column:1/-1;">Complaint Description
            <textarea name="description" rows="4" placeholder="Describe the issue in detail" required><?php echo htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES); ?></textarea>
          </label>
          <div class="contact-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary" name="action" value="complaint_whatsapp" style="flex:2;"><i class="fa-brands fa-whatsapp"></i>Send via WhatsApp</button>
            <button type="submit" class="btn btn-secondary" name="action" value="complaint_email"><i class="fa-solid fa-envelope"></i>Send via Email</button>
          </div>
        </form>
        <p class="secondary-note">Submitting via WhatsApp or email logs your complaint in our system (admin, employee, and customer portals) before opening a draft to 918102401427 / connect@dakshayani.co.in.</p>
      <?php endif; ?>
    </section>

    <section class="contact-card" id="connect-owner">
      <div>
        <span class="badge-inline" style="background:rgba(34,197,94,0.12);color:#166534;"><i class="fa-solid fa-user-tie"></i> Connect with Owner</span>
        <h2>Connect with Owner</h2>
        <p>Want to talk directly? Call or WhatsApp our founder for urgent or special requests.</p>
      </div>
      <div class="contact-actions">
        <a class="btn btn-primary" href="tel:7070278178"><i class="fa-solid fa-phone"></i>Call Owner</a>
        <a class="btn btn-secondary" href="https://wa.me/7070278178?text=Hi%2C%20I%20want%20to%20discuss%20about%20solar." target="_blank" rel="noreferrer noopener"><i class="fa-brands fa-whatsapp"></i>WhatsApp Owner</a>
      </div>
      <p class="secondary-note">Available for escalations, partnership opportunities, or sensitive project requests.</p>
    </section>
  </main>

  <footer class="site-footer"></footer>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('consultation-form');
      const whatsappLink = document.querySelector('[data-consult-whatsapp]');

      if (!form || !whatsappLink) {
        return;
      }

      const nameField = form.querySelector('input[name="name"]');
      const mobileField = form.querySelector('input[name="mobile"]');
      const cityField = form.querySelector('input[name="city"]');
      const messageField = form.querySelector('textarea[name="message"]');

      function buildConsultationMessage() {
        const name = ((nameField && nameField.value) || '').trim();
        const mobile = ((mobileField && mobileField.value) || '').trim();
        const city = ((cityField && cityField.value) || '').trim();
        const message = ((messageField && messageField.value) || '').trim();

        const parts = [
          'Hi, I want a free solar consultation.',
          name !== '' ? 'My name is ' + name + '.' : '',
          mobile !== '' ? 'Mobile: +91 ' + mobile + '.' : '',
          city !== '' ? 'City/Town: ' + city + '.' : '',
          message !== '' ? 'Requirements: ' + message : '',
        ].filter(Boolean);

        return 'https://wa.me/7070278178?text=' + encodeURIComponent(parts.join(' '));
      }

      whatsappLink.addEventListener('click', function () {
        whatsappLink.href = buildConsultationMessage();
      });
    });
  </script>
  <script src="script.js" defer></script>
  <script src="site-content.js" defer></script>
</body>
</html>
