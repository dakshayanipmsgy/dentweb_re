<?php
declare(strict_types=1);

require_once __DIR__ . '/blog_service.php';
require_once __DIR__ . '/blog.php';
require_once __DIR__ . '/user_storage.php';
require_once __DIR__ . '/customer_records.php';
require_once __DIR__ . '/portal_file_storage.php';
require_once __DIR__ . '/website_settings.php';

if (date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

function portal_current_time(string $timezone = 'Asia/Kolkata'): array
{
    $result = [
        'timezone' => $timezone,
        'iso' => '',
        'display' => '',
        'abbr' => '',
        'label' => '',
    ];

    try {
        $zone = new DateTimeZone($timezone);
        $result['timezone'] = $zone->getName();
    } catch (Throwable $exception) {
        $zone = null;
    }

    try {
        $now = $zone instanceof DateTimeZone
            ? new DateTimeImmutable('now', $zone)
            : new DateTimeImmutable('now');
    } catch (Throwable $exception) {
        $now = new DateTimeImmutable('now');
    }

    if ($zone instanceof DateTimeZone) {
        $now = $now->setTimezone($zone);
    }

    $result['iso'] = $now->format(DateTimeInterface::ATOM);
    $result['display'] = $now->format('d M Y · h:i A');
    $result['abbr'] = trim($now->format('T'));

    if ($result['abbr'] !== '') {
        $result['label'] = $result['abbr'];
    } else {
        $timezoneName = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $result['timezone']));
        $result['label'] = $timezoneName !== '' ? $timezoneName : 'IST';
    }

    return $result;
}

function safe_get_constant(string $name, $default = null)
{
    if (!defined($name)) {
        return $default;
    }

    try {
        return constant($name);
    } catch (Throwable $exception) {
        error_log(sprintf('Failed to read constant %s: %s', $name, $exception->getMessage()));
        return $default;
    }
}

function resolve_admin_email(): string
{
    static $resolvedEmail = null;
    if ($resolvedEmail !== null) {
        return $resolvedEmail;
    }

    $candidates = [
        safe_get_constant('ADMIN_EMAIL'),
        $_ENV['ADMIN_EMAIL'] ?? null,
        $_SERVER['ADMIN_EMAIL'] ?? null,
        getenv('ADMIN_EMAIL') ?: null,
        $_ENV['FALLBACK_ADMIN_EMAIL'] ?? null,
        $_SERVER['FALLBACK_ADMIN_EMAIL'] ?? null,
        getenv('FALLBACK_ADMIN_EMAIL') ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $resolvedEmail = $candidate;
        break;
    }

    if ($resolvedEmail === null) {
        $resolvedEmail = 'support@dakshayani.in';
    }

    if (!defined('ADMIN_EMAIL')) {
        define('ADMIN_EMAIL', $resolvedEmail);
    }

    return $resolvedEmail;
}

function get_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The SQLite PDO driver is not installed.');
    }

    $storageDir = __DIR__ . '/../storage';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
    }

    $dbPath = $storageDir . '/app.sqlite';

    try {
        $db = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to initialise the application database.', 0, $exception);
    }
    $db->exec('PRAGMA foreign_keys = ON');

    initialize_schema($db);

    // Always ensure the foundational data exists. The helper is idempotent and
    // will only insert missing rows or create the default administrator when no
    // active admin accounts are present, which protects installations that have
    // already been customized while still repairing partially created databases.
    seed_defaults($db);

    return $db;
}

function audit_resolve_actor_id(PDO $db, ?int $actorId): ?int
{
    if ($actorId === null || $actorId <= 0) {
        return null;
    }

    $cacheKey = (int) $actorId;
    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $store = user_store();
        $record = $store->get($cacheKey);
        $cache[$cacheKey] = $record !== null ? (int) ($record['id'] ?? null) : null;
    } catch (Throwable $exception) {
        error_log(sprintf('audit_resolve_actor_id: unable to read user store: %s', $exception->getMessage()));
        $cache[$cacheKey] = null;
    }

    return $cache[$cacheKey];
}

function initialize_schema(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive','pending')),
    permissions_note TEXT,
    last_login_at TEXT,
    password_last_set_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY(role_id) REFERENCES roles(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id INTEGER NOT NULL,
    invitee_name TEXT NOT NULL,
    invitee_email TEXT NOT NULL,
    role_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    token TEXT NOT NULL UNIQUE,
    message TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    approved_at TEXT,
    FOREIGN KEY(inviter_id) REFERENCES users(id),
    FOREIGN KEY(role_id) REFERENCES roles(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS referrers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    company TEXT,
    email TEXT,
    phone TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive','prospect')),
    notes TEXT,
    last_lead_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes'))
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_referrers_status ON referrers(status)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS complaints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    description TEXT,
    customer_name TEXT,
    customer_contact TEXT,
    origin TEXT NOT NULL DEFAULT 'admin' CHECK(origin IN ('admin','customer')),
    priority TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('low','medium','high','urgent')),
    status TEXT NOT NULL DEFAULT 'intake' CHECK(status IN ('intake','triage','work','resolved','closed')),
    assigned_to INTEGER,
    sla_due_at TEXT,
    notes TEXT DEFAULT '[]',
    attachments TEXT DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY(assigned_to) REFERENCES users(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS crm_leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    source TEXT,
    status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','visited','quotation','converted','lost')),
    assigned_to INTEGER,
    created_by INTEGER,
    referrer_id INTEGER,
    site_location TEXT,
    site_details TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(assigned_to) REFERENCES users(id),
    FOREIGN KEY(created_by) REFERENCES users(id),
    FOREIGN KEY(referrer_id) REFERENCES referrers(id) ON DELETE SET NULL
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_status ON crm_leads(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_updated_at ON crm_leads(updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_referrer ON crm_leads(referrer_id)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    note TEXT NOT NULL,
    photo_name TEXT,
    photo_mime TEXT,
    photo_data TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(employee_id) REFERENCES users(id)
)
SQL
    );

    try {
        $visitColumns = $db->query('PRAGMA table_info(lead_visits)')->fetchAll(PDO::FETCH_COLUMN, 1);
    } catch (Throwable $exception) {
        error_log(sprintf('ensure_lead_tables: unable to inspect lead_visits columns: %s', $exception->getMessage()));
        $visitColumns = [];
    }
    if (!is_array($visitColumns)) {
        $visitColumns = [];
    }
    $visitColumnMap = [];
    foreach ($visitColumns as $columnName) {
        $visitColumnMap[strtolower((string) $columnName)] = true;
    }
    $ensureVisitColumn = static function (string $column, string $definition) use ($db, &$visitColumnMap): void {
        if (isset($visitColumnMap[$column])) {
            return;
        }
        $db->exec(sprintf('ALTER TABLE lead_visits ADD COLUMN %s %s', $column, $definition));
        $visitColumnMap[$column] = true;
    };
    $ensureVisitColumn('photo_name', 'TEXT');
    $ensureVisitColumn('photo_mime', 'TEXT');
    $ensureVisitColumn('photo_data', 'TEXT');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_visits_lead ON lead_visits(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_visits_created_at ON lead_visits(created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_stage_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    actor_id INTEGER,
    from_status TEXT,
    to_status TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(actor_id) REFERENCES users(id)
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_stage_logs_lead ON lead_stage_logs(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_stage_logs_created_at ON lead_stage_logs(created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    summary TEXT NOT NULL,
    estimate_amount REAL,
    document_name TEXT,
    document_mime TEXT,
    document_data TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    review_note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    approved_at TEXT,
    approved_by INTEGER,
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(employee_id) REFERENCES users(id),
    FOREIGN KEY(approved_by) REFERENCES users(id)
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_proposals_lead ON lead_proposals(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_proposals_status ON lead_proposals(status)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS subsidy_applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_name TEXT NOT NULL,
    application_number TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','submitted','approved','rejected','disbursed')),
    amount INTEGER,
    submitted_on TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes'))
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_subsidy_applications_status ON subsidy_applications(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_subsidy_applications_updated_at ON subsidy_applications(updated_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS subsidy_tracker (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_reference TEXT NOT NULL,
    lead_id INTEGER,
    installation_id INTEGER,
    stage TEXT NOT NULL CHECK(stage IN ('applied','under_review','approved','disbursed')),
    stage_date TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
    FOREIGN KEY(installation_id) REFERENCES installations(id) ON DELETE SET NULL
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_subsidy_tracker_reference ON subsidy_tracker(application_reference)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_subsidy_tracker_stage_date ON subsidy_tracker(stage_date DESC)');

    $reminderColumns = $db->query("PRAGMA table_info('reminders')")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!empty($reminderColumns) && !in_array('linked_id', $reminderColumns, true)) {
        $db->exec('DROP TABLE reminders');
        $reminderColumns = [];
    }

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    module TEXT NOT NULL CHECK(module IN ('lead','installation','complaint','subsidy','amc')),
    linked_id INTEGER NOT NULL,
    due_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'proposed' CHECK(status IN ('proposed','active','completed','cancelled')),
    notes TEXT,
    decision_note TEXT,
    proposer_id INTEGER NOT NULL,
    approver_id INTEGER,
    completed_at TEXT,
    deleted_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(proposer_id) REFERENCES users(id),
    FOREIGN KEY(approver_id) REFERENCES users(id)
)
SQL
    );

    if (!empty($reminderColumns) && !in_array('deleted_at', $reminderColumns, true)) {
        $db->exec("ALTER TABLE reminders ADD COLUMN deleted_at TEXT");
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminders_status ON reminders(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminders_module ON reminders(module)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminders_due_at ON reminders(due_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminders_updated_at ON reminders(updated_at DESC)');

    $complaintColumns = $db->query("PRAGMA table_info('complaints')")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('notes', $complaintColumns, true)) {
        $db->exec("ALTER TABLE complaints ADD COLUMN notes TEXT DEFAULT '[]'");
    }
    if (!in_array('attachments', $complaintColumns, true)) {
        $db->exec("ALTER TABLE complaints ADD COLUMN attachments TEXT DEFAULT '[]'");
    }
    if (!in_array('customer_name', $complaintColumns, true)) {
        $db->exec("ALTER TABLE complaints ADD COLUMN customer_name TEXT");
    }
    if (!in_array('customer_contact', $complaintColumns, true)) {
        $db->exec("ALTER TABLE complaints ADD COLUMN customer_contact TEXT");
    }
    if (!in_array('origin', $complaintColumns, true)) {
        $db->exec("ALTER TABLE complaints ADD COLUMN origin TEXT NOT NULL DEFAULT 'admin' CHECK(origin IN ('admin','customer'))");
    }
    $db->exec("UPDATE complaints SET origin = 'admin' WHERE origin IS NULL OR origin = ''");
    $db->exec("UPDATE complaints SET status = 'resolved' WHERE status = 'resolution'");

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    description TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY(actor_id) REFERENCES users(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS portal_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','in_progress','done')),
    priority TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('low','medium','high')),
    due_date TEXT,
    linked_reference TEXT,
    notes TEXT,
    assignee_id INTEGER,
    created_by INTEGER,
    completed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(assignee_id) REFERENCES users(id),
    FOREIGN KEY(created_by) REFERENCES users(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS portal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    linked_to TEXT,
    reference TEXT,
    tags TEXT,
    url TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    visibility TEXT NOT NULL DEFAULT 'employee' CHECK(visibility IN ('employee','admin','both')),
    uploaded_by INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(uploaded_by) REFERENCES users(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS portal_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audience TEXT NOT NULL DEFAULT 'employee' CHECK(audience IN ('employee','admin','all')),
    tone TEXT NOT NULL DEFAULT 'info' CHECK(tone IN ('info','success','warning','danger')),
    icon TEXT,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    link TEXT,
    scope_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    expires_at TEXT,
    FOREIGN KEY(scope_user_id) REFERENCES users(id)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reminder_status_banners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    tone TEXT NOT NULL CHECK(tone IN ('info','success','warning','danger')),
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminder_status_banners_user ON reminder_status_banners(user_id, created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS portal_notification_status (
    notification_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'unread' CHECK(status IN ('unread','read','dismissed')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    PRIMARY KEY(notification_id, user_id),
    FOREIGN KEY(notification_id) REFERENCES portal_notifications(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS approval_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    requested_by INTEGER NOT NULL,
    subject TEXT NOT NULL,
    target_type TEXT,
    target_id INTEGER,
    payload TEXT,
    notes TEXT,
    decision_note TEXT,
    decided_by INTEGER,
    decided_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(requested_by) REFERENCES users(id),
    FOREIGN KEY(decided_by) REFERENCES users(id)
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_approval_requests_status ON approval_requests(status, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_approval_requests_target ON approval_requests(request_type, target_type, target_id)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS employee_leaves (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    reason TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    request_id INTEGER,
    approved_by INTEGER,
    approved_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(user_id) REFERENCES users(id),
    FOREIGN KEY(approved_by) REFERENCES users(id),
    FOREIGN KEY(request_id) REFERENCES approval_requests(id)
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_employee_leaves_user ON employee_leaves(user_id, start_date DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS employee_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    category TEXT,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    request_id INTEGER,
    approved_by INTEGER,
    approved_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(user_id) REFERENCES users(id),
    FOREIGN KEY(approved_by) REFERENCES users(id),
    FOREIGN KEY(request_id) REFERENCES approval_requests(id)
)
SQL
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_employee_expenses_user ON employee_expenses(user_id, created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS complaint_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    complaint_id INTEGER NOT NULL,
    actor_id INTEGER,
    entry_type TEXT NOT NULL CHECK(entry_type IN ('status','note','document','assignment')),
    summary TEXT NOT NULL,
    details TEXT,
    document_id INTEGER,
    status_to TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY(actor_id) REFERENCES users(id),
    FOREIGN KEY(document_id) REFERENCES portal_documents(id)
)
SQL
    );

    $db->exec("UPDATE complaint_updates SET status_to = 'resolved' WHERE status_to = 'resolution'");

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS system_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_policies (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    retry_limit INTEGER NOT NULL DEFAULT 5,
    lockout_minutes INTEGER NOT NULL DEFAULT 30,
    twofactor_mode TEXT NOT NULL DEFAULT 'admin',
    session_timeout INTEGER NOT NULL DEFAULT 45,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier_hash TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    last_attempt_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(identifier_hash, ip_hash)
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    excerpt TEXT,
    body_html TEXT NOT NULL,
    body_text TEXT NOT NULL,
    cover_image TEXT,
    cover_image_alt TEXT,
    author_name TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending','published','archived')),
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blog_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blog_post_tags (
    post_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY(post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
    FOREIGN KEY(tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
)
SQL
    );

    blog_ensure_backup_table($db);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_status_published_at ON blog_posts(status, published_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_blog_post_tags_tag ON blog_post_tags(tag_id)');

    apply_schema_patches($db);
}

function seed_defaults(PDO $db): void
{
    $roles = [
        'admin' => 'System administrators with full permissions.',
        'employee' => 'Internal staff managing operations and service.',
        'installer' => 'Field installers responsible for on-site execution.',
        'referrer' => 'Channel partners supplying qualified leads.',
        'customer' => 'End customers with read-only project tracking.',
    ];

    $insertRole = $db->prepare('INSERT OR IGNORE INTO roles(name, description) VALUES(:name, :description)');
    foreach ($roles as $name => $description) {
        $insertRole->execute([
            ':name' => $name,
            ':description' => $description,
        ]);
    }

    ensure_default_user($db, [
        'role' => 'admin',
        'full_name' => 'Primary Administrator',
        'email' => 'd.entranchi@gmail.com',
        'username' => 'admin',
        'password' => 'Dent@2025',
        'permissions_note' => 'Full access',
        'legacy_emails' => ['admin@dakshayani.in', 'd.entranchi@gmail.com'],
        'legacy_usernames' => ['d.entranchi@gmail.com', 'sysadmin'],
    ]);

    ensure_default_user($db, [
        'role' => 'employee',
        'full_name' => 'Operations Coordinator',
        'email' => 'employee@dakshayani.in',
        'username' => 'employee',
        'password' => 'Employee@2025',
        'permissions_note' => 'Employee workspace access',
        'legacy_usernames' => ['employee'],
    ]);

    ensure_default_user($db, [
        'role' => 'installer',
        'full_name' => 'Lead Installer',
        'email' => 'installer@dakshayani.in',
        'username' => 'installer',
        'password' => 'Installer@2025',
        'permissions_note' => 'Installer workspace access',
        'legacy_usernames' => ['installer'],
    ]);

    $defaultMetrics = [
        'last_backup' => 'Not recorded',
        'errors_24h' => '0',
        'disk_usage' => 'Normal',
        'uptime' => 'Unknown',
        'subsidy_pipeline' => '0',
    ];

    $insertMetric = $db->prepare('INSERT OR IGNORE INTO system_metrics(name, value) VALUES(:name, :value)');
    foreach ($defaultMetrics as $name => $value) {
        $insertMetric->execute([
            ':name' => $name,
            ':value' => $value,
        ]);
    }

    $db->exec("INSERT OR IGNORE INTO login_policies(id, retry_limit, lockout_minutes, twofactor_mode, session_timeout) VALUES (1, 5, 30, 'admin', 45)");

    blog_seed_default($db);
    blog_backfill_cover_images($db);

    seed_portal_defaults($db);
    merge_employee_roles($db);
}

function record_system_audit(PDO $db, string $action, string $entityType, int $entityId, string $description): void
{
    $stmt = $db->prepare('INSERT INTO audit_logs(actor_id, action, entity_type, entity_id, description) VALUES(NULL, :action, :entity_type, :entity_id, :description)');
    $stmt->execute([
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':description' => $description,
    ]);
}

function unify_employee_roles(PDO $db): void
{
    $employeeRoleId = (int) $db->query("SELECT id FROM roles WHERE name = 'employee'")->fetchColumn();
    if ($employeeRoleId <= 0) {
        return;
    }

    $legacyStmt = $db->query("SELECT id, name FROM roles WHERE name NOT IN ('admin','employee')");
    $legacyRoles = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$legacyRoles) {
        return;
    }

    $preserve = ['installer', 'referrer', 'customer'];

    foreach ($legacyRoles as $legacyRole) {
        $roleId = (int) $legacyRole['id'];
        $roleName = (string) $legacyRole['name'];

        if (in_array(strtolower($roleName), $preserve, true)) {
            continue;
        }

        $usersStmt = $db->prepare('SELECT id, email, permissions_note FROM users WHERE role_id = :role_id');
        $usersStmt->execute([':role_id' => $roleId]);
        foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $userRow) {
            $noteParts = [];
            $existingNote = trim((string) ($userRow['permissions_note'] ?? ''));
            if ($existingNote !== '') {
                $noteParts[] = $existingNote;
            }
            $noteParts[] = sprintf('Role auto-converted from %s on %s', ucfirst($roleName), now_ist());
            $note = implode("\n", $noteParts);

            $updateUser = $db->prepare('UPDATE users SET role_id = :employee_role, permissions_note = :note, updated_at = datetime(\'now\') WHERE id = :id');
            $updateUser->execute([
                ':employee_role' => $employeeRoleId,
                ':note' => $note,
                ':id' => (int) $userRow['id'],
            ]);

            record_system_audit($db, 'role_unified', 'user', (int) $userRow['id'], sprintf('User %s merged into Employee role (previously %s)', $userRow['email'], $roleName));
        }

        $inviteStmt = $db->prepare('SELECT id, invitee_email FROM invitations WHERE role_id = :role_id');
        $inviteStmt->execute([':role_id' => $roleId]);
        foreach ($inviteStmt->fetchAll(PDO::FETCH_ASSOC) as $inviteRow) {
            $db->prepare('UPDATE invitations SET role_id = :employee_role WHERE id = :id')->execute([
                ':employee_role' => $employeeRoleId,
                ':id' => (int) $inviteRow['id'],
            ]);

            record_system_audit($db, 'role_unified', 'invitation', (int) $inviteRow['id'], sprintf('Invitation for %s reassigned to Employee role (previously %s)', $inviteRow['invitee_email'], $roleName));
        }

        $db->prepare('DELETE FROM roles WHERE id = :role_id')->execute([':role_id' => $roleId]);
        record_system_audit($db, 'role_removed', 'role', $roleId, sprintf('Legacy role %s removed during Employee role unification', $roleName));
    }
}

function ensure_default_user(PDO $db, array $account): void
{
    $roleName = strtolower((string) ($account['role'] ?? ''));
    if ($roleName === '') {
        return;
    }

    try {
        $store = user_store();
    } catch (Throwable $exception) {
        error_log(sprintf('ensure_default_user: unable to initialise user store: %s', $exception->getMessage()));
        return;
    }

    $identifiers = [];
    foreach (['email', 'username'] as $key) {
        if (!empty($account[$key]) && is_string($account[$key])) {
            $identifiers[] = $account[$key];
        }
    }

    foreach (($account['legacy_emails'] ?? []) as $email) {
        if (is_string($email) && $email !== '') {
            $identifiers[] = $email;
        }
    }
    foreach (($account['legacy_usernames'] ?? []) as $username) {
        if (is_string($username) && $username !== '') {
            $identifiers[] = $username;
        }
    }

    $existing = null;
    foreach ($identifiers as $identifier) {
        try {
            $candidate = $store->findByIdentifier($identifier);
        } catch (Throwable $lookupError) {
            error_log(sprintf('ensure_default_user: lookup failed for %s: %s', $identifier, $lookupError->getMessage()));
            continue;
        }

        if (is_array($candidate)) {
            $existing = $candidate;
            break;
        }
    }

    $now = now_ist();
    $password = is_string($account['password'] ?? null) ? trim($account['password']) : '';
    $permissionsNote = trim((string) ($account['permissions_note'] ?? ''));
    $payload = [
        'full_name' => (string) ($account['full_name'] ?? ''),
        'email' => (string) ($account['email'] ?? ''),
        'username' => (string) ($account['username'] ?? ''),
        'role' => $roleName,
        'status' => 'active',
        'permissions_note' => $permissionsNote,
    ];

    if ($existing !== null) {
        $payload['id'] = (int) ($existing['id'] ?? 0);
        $payload['created_at'] = $existing['created_at'] ?? $now;
        $payload['password_hash'] = (string) ($existing['password_hash'] ?? '');
        $payload['password_last_set_at'] = $existing['password_last_set_at'] ?? null;
    }

    if ($payload['full_name'] === '' && $existing !== null) {
        $payload['full_name'] = (string) ($existing['full_name'] ?? '');
    }

    if ($password !== '') {
        $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $payload['password_last_set_at'] = $now;
    } elseif (!isset($payload['password_hash']) || $payload['password_hash'] === '') {
        $payload['password_hash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $payload['password_last_set_at'] = $now;
    }

    if (!isset($payload['created_at'])) {
        $payload['created_at'] = $now;
    }

    try {
        $store->save($payload);
    } catch (Throwable $exception) {
        error_log(sprintf('ensure_default_user: unable to persist default account %s: %s', $payload['email'], $exception->getMessage()));
        return;
    }

    try {
        $store->appendAudit([
            'event' => 'ensure_default_user',
            'user_id' => (int) ($payload['id'] ?? 0),
            'role' => $roleName,
        ]);
    } catch (Throwable $auditError) {
        error_log(sprintf('ensure_default_user: audit append failed: %s', $auditError->getMessage()));
    }
}


function get_setting(string $key, ?PDO $db = null): ?string
{
    $db = $db ?? get_db();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string) $value : null;
}

function set_setting(string $key, string $value, ?PDO $db = null): void
{
    $db = $db ?? get_db();
    $stmt = $db->prepare("INSERT INTO settings(key, value, updated_at) VALUES(:key, :value, datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function apply_schema_patches(PDO $db): void
{
    upgrade_blog_posts_table($db);
    upgrade_installations_table($db);
    ensure_login_policy_row($db);
    ensure_portal_tables($db);
    ensure_lead_tables($db);
    ensure_blog_indexes($db);
}

function upgrade_blog_posts_table(PDO $db): void
{
    $schema = $db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'blog_posts'")->fetchColumn();
    $schema = is_string($schema) ? $schema : '';
    blog_ensure_backup_table($db);

    if ($schema === '') {
        ensure_blog_indexes($db);
        return;
    }

    if (str_contains(strtolower($schema), "'pending'")) {
        ensure_blog_indexes($db);
        return;
    }

    $foreignKeysInitiallyEnabled = (int) $db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysInitiallyEnabled) {
        $db->exec('PRAGMA foreign_keys = OFF');
    }

    $db->beginTransaction();
    try {
        $columns = $db->query('PRAGMA table_info(blog_posts)')->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static function (array $column): string {
            return strtolower((string) ($column['name'] ?? ''));
        }, $columns ?: []);

        $hasBodyText = in_array('body_text', $columnNames, true);
        $hasCoverImageAlt = in_array('cover_image_alt', $columnNames, true);
        $hasStatus = in_array('status', $columnNames, true);
        $hasPublishedAt = in_array('published_at', $columnNames, true);

        // Recreate the backup table on each migration run to guarantee it exists
        // with the expected shape, even on installations that predate the helper.
        $db->exec('DROP TABLE IF EXISTS blog_posts_backup');
        blog_ensure_backup_table($db);

        $db->exec(<<<'SQL'
INSERT INTO blog_posts_backup (id, title, slug, excerpt, body_html, body_text, cover_image, cover_image_alt, author_name, status, published_at, created_at, updated_at)
SELECT
    COALESCE(id, rowid),
    COALESCE(title, ''),
    COALESCE(slug, ''),
    excerpt,
    COALESCE(body_html, ''),
    body_text,
    cover_image,
    cover_image_alt,
    author_name,
    status,
    published_at,
    COALESCE(created_at, datetime('now')),
    COALESCE(updated_at, COALESCE(created_at, datetime('now')))
FROM blog_posts
SQL
        );

        $db->exec("UPDATE blog_posts_backup SET body_html = COALESCE(body_html, '')");
        $db->exec("UPDATE blog_posts_backup SET excerpt = COALESCE(excerpt, '')");
        $db->exec("UPDATE blog_posts_backup SET author_name = NULLIF(trim(COALESCE(author_name, '')), '')");

        $bodyRows = null;
        if ($hasBodyText) {
            $db->exec("UPDATE blog_posts_backup SET body_text = COALESCE(body_text, '')");
            $bodyRows = $db->query("SELECT id, COALESCE(body_html, '') AS body_html FROM blog_posts_backup WHERE trim(body_text) = ''");
        } else {
            $bodyRows = $db->query("SELECT id, COALESCE(body_html, '') AS body_html FROM blog_posts_backup");
        }

        if ($bodyRows instanceof PDOStatement) {
            $updateBody = $db->prepare('UPDATE blog_posts_backup SET body_text = :body_text WHERE id = :id');
            while (($row = $bodyRows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $plain = blog_extract_plain_text((string) ($row['body_html'] ?? ''));
                $updateBody->execute([
                    ':body_text' => $plain,
                    ':id' => (int) ($row['id'] ?? 0),
                ]);
            }
        }

        $db->exec("UPDATE blog_posts_backup SET body_text = COALESCE(body_text, '')");

        if (!$hasCoverImageAlt) {
            $db->exec("UPDATE blog_posts_backup SET cover_image_alt = NULL");
        }

        if (!$hasStatus) {
            $db->exec("UPDATE blog_posts_backup SET status = 'draft' WHERE status IS NULL OR trim(status) = ''");
        }

        if (!$hasPublishedAt) {
            $db->exec("UPDATE blog_posts_backup SET published_at = NULL");
        }

        $db->exec("UPDATE blog_posts_backup SET created_at = COALESCE(created_at, datetime('now'))");
        $db->exec("UPDATE blog_posts_backup SET updated_at = COALESCE(updated_at, created_at)");

        $db->exec("UPDATE blog_posts_backup SET title = CASE WHEN trim(title) = '' THEN 'Untitled Post' ELSE title END");

        $fetchBackupRows = $db->query("SELECT id, COALESCE(title, '') AS title, COALESCE(slug, '') AS slug FROM blog_posts_backup ORDER BY id");
        $updateSlug = $db->prepare('UPDATE blog_posts_backup SET slug = :slug WHERE id = :id');
        $seenSlugs = [];
        while (($row = $fetchBackupRows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) ($row['id'] ?? 0);
            $title = (string) ($row['title'] ?? '');
            $slug = trim((string) ($row['slug'] ?? ''));

            $baseSlug = $slug !== '' ? $slug : blog_slugify($title);
            if ($baseSlug === '') {
                try {
                    $baseSlug = bin2hex(random_bytes(6));
                } catch (Throwable $exception) {
                    $baseSlug = uniqid('post_', true);
                }
            }

            $candidate = $baseSlug;
            $suffix = 2;
            while ($candidate === '' || isset($seenSlugs[$candidate])) {
                $candidate = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            if ($candidate !== $slug) {
                $updateSlug->execute([
                    ':slug' => $candidate,
                    ':id' => $id,
                ]);
            }

            $seenSlugs[$candidate] = true;
        }

        $db->exec("UPDATE blog_posts_backup SET status = lower(status) WHERE status IS NOT NULL");
        $db->exec("UPDATE blog_posts_backup SET status = 'draft' WHERE status IS NULL OR trim(status) = ''");
        $db->exec("UPDATE blog_posts_backup SET status = 'draft' WHERE status NOT IN ('draft','pending','published','archived')");

        $db->exec('DROP TABLE IF EXISTS blog_posts');
        $db->exec(<<<'SQL'
CREATE TABLE blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    excerpt TEXT,
    body_html TEXT NOT NULL,
    body_text TEXT NOT NULL,
    cover_image TEXT,
    cover_image_alt TEXT,
    author_name TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending','published','archived')),
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
        );

        $db->exec(<<<'SQL'
INSERT INTO blog_posts (id, title, slug, excerpt, body_html, body_text, cover_image, cover_image_alt, author_name, status, published_at, created_at, updated_at)
SELECT
    id,
    title,
    slug,
    excerpt,
    body_html,
    body_text,
    cover_image,
    cover_image_alt,
    author_name,
    status,
    published_at,
    created_at,
    updated_at
FROM blog_posts_backup
SQL
        );

        ensure_blog_indexes($db);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    } finally {
        if ($foreignKeysInitiallyEnabled) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function upgrade_installations_table(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS installations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_name TEXT NOT NULL,
    project_reference TEXT,
    capacity_kw REAL,
    status TEXT NOT NULL DEFAULT 'planning' CHECK(status IN ('planning','in_progress','completed','on_hold','cancelled')),
    stage TEXT NOT NULL DEFAULT 'structure' CHECK(stage IN ('structure','wiring','testing','meter','commissioned')),
    stage_entries TEXT NOT NULL DEFAULT '[]',
    requested_stage TEXT,
    requested_by INTEGER,
    requested_at TEXT,
    amc_committed INTEGER NOT NULL DEFAULT 0,
    scheduled_date TEXT,
    handover_date TEXT,
    assigned_to INTEGER,
    installer_id INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(assigned_to) REFERENCES users(id),
    FOREIGN KEY(requested_by) REFERENCES users(id),
    FOREIGN KEY(installer_id) REFERENCES users(id)
)
SQL
    );

    $columns = $db->query("PRAGMA table_info('installations')")->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('stage', $columns, true)) {
        $db->exec("ALTER TABLE installations ADD COLUMN stage TEXT NOT NULL DEFAULT 'structure'");
    }

    if (!in_array('stage_entries', $columns, true)) {
        $db->exec("ALTER TABLE installations ADD COLUMN stage_entries TEXT NOT NULL DEFAULT '[]'");
    }

    if (!in_array('requested_stage', $columns, true)) {
        $db->exec('ALTER TABLE installations ADD COLUMN requested_stage TEXT');
    }

    if (!in_array('requested_by', $columns, true)) {
        $db->exec('ALTER TABLE installations ADD COLUMN requested_by INTEGER');
    }

    if (!in_array('requested_at', $columns, true)) {
        $db->exec('ALTER TABLE installations ADD COLUMN requested_at TEXT');
    }

    if (!in_array('amc_committed', $columns, true)) {
        $db->exec("ALTER TABLE installations ADD COLUMN amc_committed INTEGER NOT NULL DEFAULT 0");
    }

    if (!in_array('installer_id', $columns, true)) {
        $db->exec('ALTER TABLE installations ADD COLUMN installer_id INTEGER');
    }

    // Normalise default values after adding new columns.
    $db->exec("UPDATE installations SET stage = CASE WHEN stage IS NULL OR stage = '' THEN 'structure' ELSE stage END");
    $db->exec("UPDATE installations SET stage_entries = CASE WHEN stage_entries IS NULL OR stage_entries = '' THEN '[]' ELSE stage_entries END");
    $db->exec("UPDATE installations SET amc_committed = CASE WHEN amc_committed IS NULL THEN 0 ELSE amc_committed END");

    // Align legacy status values with the new stage flow.
    $db->exec("UPDATE installations SET stage = 'commissioned' WHERE status = 'completed' AND (stage IS NULL OR stage NOT IN ('structure','wiring','testing','meter','commissioned'))");
    $db->exec("UPDATE installations SET status = 'in_progress' WHERE status NOT IN ('on_hold','cancelled','completed') AND stage != 'commissioned'");
    $db->exec("UPDATE installations SET status = 'completed' WHERE stage = 'commissioned'");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_installations_status ON installations(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_installations_stage ON installations(stage)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_installations_requested_stage ON installations(requested_stage)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_installations_updated_at ON installations(updated_at DESC)');
}

function ensure_login_policy_row(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM login_policies')->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO login_policies(id, retry_limit, lockout_minutes, twofactor_mode, session_timeout) VALUES (1, 5, 30, 'admin', 45)");
    }
}

function get_session_timeout_minutes(PDO $db): int
{
    ensure_login_policy_row($db);
    $timeout = (int) $db->query('SELECT session_timeout FROM login_policies WHERE id = 1')->fetchColumn();
    if ($timeout < 15) {
        return 15;
    }
    if ($timeout > 720) {
        return 720;
    }

    return $timeout;
}

function get_login_policy(PDO $db): array
{
    ensure_login_policy_row($db);
    $row = $db->query('SELECT retry_limit, lockout_minutes, session_timeout FROM login_policies WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];

    $retryLimit = (int) ($row['retry_limit'] ?? 5);
    if ($retryLimit < 3) {
        $retryLimit = 3;
    }

    $lockoutMinutes = (int) ($row['lockout_minutes'] ?? 30);
    if ($lockoutMinutes < 1) {
        $lockoutMinutes = 1;
    }
    if ($lockoutMinutes > 720) {
        $lockoutMinutes = 720;
    }

    $sessionTimeout = get_session_timeout_minutes($db);

    return [
        'retry_limit' => $retryLimit,
        'lockout_minutes' => $lockoutMinutes,
        'session_timeout' => $sessionTimeout,
    ];
}

function normalize_login_identifier(string $identifier): string
{
    $normalized = strtolower(trim($identifier));
    return hash('sha256', $normalized);
}

function normalize_login_ip(string $ip): string
{
    $candidate = trim($ip);
    if ($candidate === '') {
        $candidate = '0.0.0.0';
    }

    return hash('sha256', $candidate);
}

function purge_login_attempts(PDO $db): void
{
    $db->exec("DELETE FROM login_attempts WHERE last_attempt_at < datetime('now', '-2 days')");
}

function login_attempt_snapshot(PDO $db, string $identifierHash, string $ipHash): ?array
{
    $stmt = $db->prepare('SELECT attempts, locked_until, last_attempt_at FROM login_attempts WHERE identifier_hash = :identifier_hash AND ip_hash = :ip_hash LIMIT 1');
    $stmt->execute([
        ':identifier_hash' => $identifierHash,
        ':ip_hash' => $ipHash,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function store_login_attempt(PDO $db, string $identifierHash, string $ipHash, int $attempts, ?string $lockedUntil): void
{
    $stmt = $db->prepare("INSERT INTO login_attempts(identifier_hash, ip_hash, attempts, locked_until, last_attempt_at) VALUES(:identifier_hash, :ip_hash, :attempts, :locked_until, datetime('now'))\n        ON CONFLICT(identifier_hash, ip_hash) DO UPDATE SET attempts = excluded.attempts, locked_until = excluded.locked_until, last_attempt_at = excluded.last_attempt_at");
    $stmt->execute([
        ':identifier_hash' => $identifierHash,
        ':ip_hash' => $ipHash,
        ':attempts' => $attempts,
        ':locked_until' => $lockedUntil,
    ]);
}

function login_rate_limit_status(PDO $db, string $identifier, string $ipAddress, array $policy): array
{
    purge_login_attempts($db);

    $identifierHash = normalize_login_identifier($identifier);
    $ipHash = normalize_login_ip($ipAddress);
    $row = login_attempt_snapshot($db, $identifierHash, $ipHash);

    $attempts = (int) ($row['attempts'] ?? 0);
    $lockedUntilRaw = $row['locked_until'] ?? null;
    $lockedSeconds = 0;

    if (is_string($lockedUntilRaw) && $lockedUntilRaw !== '') {
        try {
            $lockedUntil = new DateTime($lockedUntilRaw, new DateTimeZone('UTC'));
            $lockedSeconds = max(0, $lockedUntil->getTimestamp() - time());
            if ($lockedSeconds <= 0) {
                store_login_attempt($db, $identifierHash, $ipHash, 0, null);
                $attempts = 0;
                $lockedSeconds = 0;
            }
        } catch (Throwable $exception) {
            error_log('Failed to parse locked_until: ' . $exception->getMessage());
            store_login_attempt($db, $identifierHash, $ipHash, 0, null);
            $attempts = 0;
            $lockedSeconds = 0;
        }
    }

    $retryLimit = (int) ($policy['retry_limit'] ?? 5);
    if ($retryLimit < 1) {
        $retryLimit = 5;
    }

    $remainingAttempts = $lockedSeconds > 0 ? 0 : max(0, $retryLimit - $attempts);

    return [
        'attempts' => $attempts,
        'locked' => $lockedSeconds > 0,
        'seconds_until_unlock' => $lockedSeconds,
        'remaining_attempts' => $remainingAttempts,
    ];
}

function login_rate_limit_register_failure(PDO $db, string $identifier, string $ipAddress, array $policy): array
{
    $identifierHash = normalize_login_identifier($identifier);
    $ipHash = normalize_login_ip($ipAddress);
    $row = login_attempt_snapshot($db, $identifierHash, $ipHash) ?: ['attempts' => 0, 'locked_until' => null];

    $attempts = (int) ($row['attempts'] ?? 0);
    $attempts++;

    $retryLimit = (int) ($policy['retry_limit'] ?? 5);
    if ($retryLimit < 1) {
        $retryLimit = 5;
    }

    $lockoutMinutes = (int) ($policy['lockout_minutes'] ?? 30);
    if ($lockoutMinutes < 1) {
        $lockoutMinutes = 1;
    }
    if ($lockoutMinutes > 720) {
        $lockoutMinutes = 720;
    }

    $lockedUntil = null;
    $secondsUntilUnlock = 0;

    if ($attempts >= $retryLimit) {
        $attempts = 0;
        $unlockTime = new DateTime('now', new DateTimeZone('UTC'));
        $unlockTime->modify('+' . $lockoutMinutes . ' minutes');
        $lockedUntil = $unlockTime->format('Y-m-d H:i:s');
        $secondsUntilUnlock = max(60, $lockoutMinutes * 60);
    }

    store_login_attempt($db, $identifierHash, $ipHash, $attempts, $lockedUntil);

    if ($lockedUntil !== null) {
        record_system_audit(
            $db,
            'login_rate_limit',
            'security',
            0,
            sprintf(
                'Login identifier %s locked after repeated failures from %s',
                mask_email_for_log($identifier),
                mask_ip_for_log($ipAddress)
            )
        );
    }

    return [
        'locked' => $lockedUntil !== null,
        'seconds_until_unlock' => $secondsUntilUnlock,
        'remaining_attempts' => $lockedUntil === null ? max(0, $retryLimit - $attempts) : 0,
    ];
}

function login_rate_limit_register_success(PDO $db, string $identifier, string $ipAddress): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE identifier_hash = :identifier_hash AND ip_hash = :ip_hash');
    $stmt->execute([
        ':identifier_hash' => normalize_login_identifier($identifier),
        ':ip_hash' => normalize_login_ip($ipAddress),
    ]);
}

function mask_email_for_log(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return 'unknown-email';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localLength = strlen($local);
    if ($localLength <= 2) {
        $localMasked = substr($local, 0, 1) . '*';
    } else {
        $localMasked = substr($local, 0, 1) . str_repeat('*', $localLength - 2) . substr($local, -1);
    }

    return $localMasked . '@' . $domain;
}

function mask_ip_for_log(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '0.0.0.*';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $segments = explode(':', $ip);
        $segments = array_pad($segments, 8, '0');
        return sprintf('%s:%s:%s:%s::*', $segments[0], $segments[1], $segments[2], $segments[3]);
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return '0.0.0.*';
    }

    $parts = explode('.', $ip);
    $parts[3] = '*';
    return implode('.', $parts);
}

function is_password_hash_valid(?string $hash): bool
{
    if (!is_string($hash) || $hash === '') {
        return false;
    }

    $info = password_get_info($hash);
    return is_array($info) && ($info['algo'] ?? 0) !== 0;
}

function has_active_admin(PDO $db): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM users INNER JOIN roles ON users.role_id = roles.id WHERE roles.name = 'admin' AND users.status = 'active'");
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function count_admin_accounts_with_invalid_hash(PDO $db): int
{
    $stmt = $db->prepare("SELECT users.password_hash FROM users INNER JOIN roles ON users.role_id = roles.id WHERE roles.name = 'admin' AND users.status = 'active'");
    $stmt->execute();
    $invalid = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
        if (!is_password_hash_valid((string) $hash)) {
            $invalid++;
        }
    }

    return $invalid;
}

function admin_recovery_secret(): string
{
    $candidates = [
        $_ENV['ADMIN_RECOVERY_TOKEN'] ?? null,
        $_SERVER['ADMIN_RECOVERY_TOKEN'] ?? null,
        getenv('ADMIN_RECOVERY_TOKEN') ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $candidate = trim($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function admin_recovery_force_enabled(): bool
{
    $candidates = [
        $_ENV['ADMIN_RECOVERY_FORCE'] ?? null,
        $_SERVER['ADMIN_RECOVERY_FORCE'] ?? null,
        getenv('ADMIN_RECOVERY_FORCE') ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $normalized = strtolower(trim($candidate));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
    }

    return false;
}

function admin_recovery_consumed(PDO $db): bool
{
    $consumed = get_setting('admin_recovery_consumed_at', $db);
    if ($consumed === null) {
        return false;
    }

    return trim($consumed) !== '';
}

function is_admin_recovery_available(PDO $db): bool
{
    $secret = admin_recovery_secret();
    if ($secret === '') {
        return false;
    }

    if (admin_recovery_consumed($db)) {
        return false;
    }

    if (admin_recovery_force_enabled()) {
        return true;
    }

    if (!has_active_admin($db)) {
        return true;
    }

    return count_admin_accounts_with_invalid_hash($db) > 0;
}

function perform_admin_recovery(PDO $db, string $email, string $username, string $fullName, string $password, string $permissionsNote = 'Full access'): int
{
    $roleStmt = $db->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('Administrator role is missing.');
    }

    $roleId = (int) $roleId;
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $lookup = $db->prepare('SELECT id FROM users WHERE (LOWER(email) = LOWER(:email) OR LOWER(username) = LOWER(:username)) LIMIT 1');
    $lookup->execute([
        ':email' => $email,
        ':username' => $username,
    ]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existing) {
        $userId = (int) $existing['id'];
        $stmt = $db->prepare("UPDATE users SET full_name = :full_name, email = :email, username = :username, password_hash = :password_hash, role_id = :role_id, status = 'active', permissions_note = :permissions_note, password_last_set_at = datetime('now'), updated_at = datetime('now') WHERE id = :id");
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':username' => $username,
            ':password_hash' => $hash,
            ':role_id' => $roleId,
            ':permissions_note' => $permissionsNote,
            ':id' => $userId,
        ]);
    } else {
        $stmt = $db->prepare("INSERT INTO users(full_name, email, username, password_hash, role_id, status, permissions_note, password_last_set_at, created_at, updated_at) VALUES(:full_name, :email, :username, :password_hash, :role_id, 'active', :permissions_note, datetime('now'), datetime('now'), datetime('now'))");
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':username' => $username,
            ':password_hash' => $hash,
            ':role_id' => $roleId,
            ':permissions_note' => $permissionsNote,
        ]);
        $userId = (int) $db->lastInsertId();
    }

    return $userId;
}

function blog_ensure_backup_table(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blog_posts_backup (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    excerpt TEXT,
    body_html TEXT NOT NULL,
    body_text TEXT NOT NULL,
    cover_image TEXT,
    cover_image_alt TEXT,
    author_name TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending','published','archived')),
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL
    );

    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_blog_posts_backup_slug ON blog_posts_backup(slug)');
}

function ensure_blog_indexes(PDO $db): void
{
    $db->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_status_published_at ON blog_posts(status, published_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_blog_post_tags_tag ON blog_post_tags(tag_id)');
}

function ensure_portal_tables(PDO $db): void
{
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('portal_tasks', $tables, true)) {
        $db->exec(<<<'SQL'
CREATE TABLE portal_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','in_progress','done')),
    priority TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('low','medium','high')),
    due_date TEXT,
    linked_reference TEXT,
    notes TEXT,
    assignee_id INTEGER,
    created_by INTEGER,
    completed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(assignee_id) REFERENCES users(id),
    FOREIGN KEY(created_by) REFERENCES users(id)
)
SQL
        );
    }

    if (!in_array('portal_documents', $tables, true)) {
        $db->exec(<<<'SQL'
CREATE TABLE portal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    linked_to TEXT,
    reference TEXT,
    tags TEXT,
    url TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    visibility TEXT NOT NULL DEFAULT 'employee' CHECK(visibility IN ('employee','admin','both')),
    uploaded_by INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(uploaded_by) REFERENCES users(id)
)
SQL
        );
    }

    if (!in_array('portal_notifications', $tables, true)) {
        $db->exec(<<<'SQL'
CREATE TABLE portal_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audience TEXT NOT NULL DEFAULT 'employee' CHECK(audience IN ('employee','admin','all')),
    tone TEXT NOT NULL DEFAULT 'info' CHECK(tone IN ('info','success','warning','danger')),
    icon TEXT,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    link TEXT,
    scope_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    expires_at TEXT,
    FOREIGN KEY(scope_user_id) REFERENCES users(id)
)
SQL
        );
    } else {
        $columns = $db->query('PRAGMA table_info(portal_notifications)')->fetchAll(PDO::FETCH_ASSOC);
        $hasScope = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'scope_user_id') {
                $hasScope = true;
                break;
            }
        }
        if (!$hasScope) {
            $db->exec('ALTER TABLE portal_notifications ADD COLUMN scope_user_id INTEGER');
        }
    }

    if (!in_array('portal_notification_status', $tables, true)) {
        $db->exec(<<<'SQL'
CREATE TABLE portal_notification_status (
    notification_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'unread' CHECK(status IN ('unread','read','dismissed')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    PRIMARY KEY(notification_id, user_id),
    FOREIGN KEY(notification_id) REFERENCES portal_notifications(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL
        );
    }

    if (!in_array('complaint_updates', $tables, true)) {
        $db->exec(<<<'SQL'
CREATE TABLE complaint_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    complaint_id INTEGER NOT NULL,
    actor_id INTEGER,
    entry_type TEXT NOT NULL CHECK(entry_type IN ('status','note','document','assignment')),
    summary TEXT NOT NULL,
    details TEXT,
    document_id INTEGER,
    status_to TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY(actor_id) REFERENCES users(id),
    FOREIGN KEY(document_id) REFERENCES portal_documents(id)
)
SQL
        );
    }
}

function ensure_lead_tables(PDO $db): void
{
    $schema = (string) $db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'crm_leads'")->fetchColumn();
    if ($schema === '') {
        return;
    }

    $requiresRebuild = str_contains($schema, "'contacted'")
        || str_contains($schema, "'qualified'")
        || !str_contains($schema, 'site_location')
        || !str_contains($schema, 'created_by')
        || !str_contains($schema, 'referrer_id');

    if ($requiresRebuild) {
        $db->beginTransaction();
        try {
            $db->exec('ALTER TABLE crm_leads RENAME TO crm_leads_backup');
            $db->exec(<<<'SQL'
CREATE TABLE crm_leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    source TEXT,
    status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','visited','quotation','converted','lost')),
    assigned_to INTEGER,
    created_by INTEGER,
    referrer_id INTEGER,
    site_location TEXT,
    site_details TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(assigned_to) REFERENCES users(id),
    FOREIGN KEY(created_by) REFERENCES users(id),
    FOREIGN KEY(referrer_id) REFERENCES referrers(id) ON DELETE SET NULL
)
SQL
            );
            $backupExists = (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'crm_leads_backup'")->fetchColumn();
            $hasBackup = false;
            if ($backupExists) {
                try {
                    $db->query('SELECT 1 FROM crm_leads_backup LIMIT 1');
                    $hasBackup = true;
                } catch (Throwable $probeException) {
                    error_log(sprintf('ensure_lead_tables: crm_leads_backup probe failed, dropping stale table: %s', $probeException->getMessage()));
                    $db->exec('DROP TABLE IF EXISTS crm_leads_backup');
                }
            }
            if ($hasBackup) {
                try {
                    $db->exec(<<<'SQL'
INSERT INTO crm_leads (id, name, phone, email, source, status, assigned_to, created_by, referrer_id, site_location, site_details, notes, created_at, updated_at)
SELECT id, name, phone, email, source,
       CASE
           WHEN status IN ('new','visited','quotation','converted','lost') THEN status
           WHEN status = 'contacted' THEN 'visited'
           WHEN status = 'qualified' THEN 'quotation'
           ELSE 'new'
       END AS status,
       assigned_to,
       NULL AS created_by,
       NULL AS referrer_id,
       NULL AS site_location,
       NULL AS site_details,
       notes,
        created_at,
        updated_at
FROM crm_leads_backup
SQL
                    );
                    $db->exec('DROP TABLE crm_leads_backup');
                } catch (Throwable $exception) {
                    if (stripos($exception->getMessage(), 'crm_leads_backup') !== false) {
                        error_log(sprintf('ensure_lead_tables: skipping migration copy because crm_leads_backup is unavailable: %s', $exception->getMessage()));
                        $db->exec('DROP TABLE IF EXISTS crm_leads_backup');
                    } else {
                        throw $exception;
                    }
                }
            } else {
                error_log('ensure_lead_tables: crm_leads_backup missing during migration; continuing with empty crm_leads table');
            }
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
        $db->commit();
    }

    $columns = $db->query('PRAGMA table_info(crm_leads)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('created_by', $columns, true)) {
        $db->exec('ALTER TABLE crm_leads ADD COLUMN created_by INTEGER');
    }
    if (!in_array('site_location', $columns, true)) {
        $db->exec('ALTER TABLE crm_leads ADD COLUMN site_location TEXT');
    }
    if (!in_array('site_details', $columns, true)) {
        $db->exec('ALTER TABLE crm_leads ADD COLUMN site_details TEXT');
    }
    if (!in_array('referrer_id', $columns, true)) {
        $db->exec('ALTER TABLE crm_leads ADD COLUMN referrer_id INTEGER');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_status ON crm_leads(status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_updated_at ON crm_leads(updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_referrer ON crm_leads(referrer_id)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    note TEXT NOT NULL,
    photo_name TEXT,
    photo_mime TEXT,
    photo_data TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(employee_id) REFERENCES users(id)
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_visits_lead ON lead_visits(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_visits_created_at ON lead_visits(created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_stage_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    actor_id INTEGER,
    from_status TEXT,
    to_status TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(actor_id) REFERENCES users(id)
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_stage_logs_lead ON lead_stage_logs(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_stage_logs_created_at ON lead_stage_logs(created_at DESC)');

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    summary TEXT NOT NULL,
    estimate_amount REAL,
    document_name TEXT,
    document_mime TEXT,
    document_data TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    review_note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+330 minutes')),
    approved_at TEXT,
    approved_by INTEGER,
    FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    FOREIGN KEY(employee_id) REFERENCES users(id),
    FOREIGN KEY(approved_by) REFERENCES users(id)
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_proposals_lead ON lead_proposals(lead_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lead_proposals_status ON lead_proposals(status)');
}

/**
 * Returns the lead tables that should be cleaned up when unlinking user
 * relationships. The metadata includes the available columns for each table so
 * callers can safely skip updates that the schema does not support (for
 * example, legacy backups that predate new columns).
 *
 * @return array<string, array<string, bool>>
 */
function crm_lead_cleanup_tables(PDO $db): array
{
    $tables = ['crm_leads'];

    $metadata = [];
    foreach ($tables as $table) {
        try {
            $columns = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        } catch (Throwable $exception) {
            $columns = [];
        }

        if (!is_array($columns)) {
            $columns = [];
        }

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[strtolower((string) $column)] = true;
        }

        $metadata[$table] = $columnMap;
    }

    return $metadata;
}






function seed_portal_defaults(PDO $db): void
{
    $taskCount = (int) $db->query('SELECT COUNT(*) FROM portal_tasks')->fetchColumn();
    if ($taskCount === 0) {
        $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        $tasks = [
            [
                'title' => 'Verify subsidy documents',
                'description' => 'Cross-check uploaded paperwork before subsidy submission.',
                'priority' => 'high',
                'status' => 'todo',
                'due_date' => $now->modify('+2 days')->format('Y-m-d'),
                'linked_reference' => 'SR-101',
            ],
            [
                'title' => 'Schedule AMC visit for Asha Devi',
                'description' => 'Confirm availability and share visit checklist.',
                'priority' => 'medium',
                'status' => 'in_progress',
                'due_date' => $now->modify('+3 days')->format('Y-m-d'),
                'linked_reference' => 'AMC-204',
            ],
        ];
        $insert = $db->prepare("INSERT INTO portal_tasks(title, description, priority, status, due_date, linked_reference, created_at, updated_at) VALUES(:title, :description, :priority, :status, :due_date, :linked_reference, datetime('now', '+330 minutes'), datetime('now', '+330 minutes'))");
        foreach ($tasks as $task) {
            $insert->execute([
                ':title' => $task['title'],
                ':description' => $task['description'],
                ':priority' => $task['priority'],
                ':status' => $task['status'],
                ':due_date' => $task['due_date'],
                ':linked_reference' => $task['linked_reference'],
            ]);
        }
    }

    $documentCount = (int) $db->query('SELECT COUNT(*) FROM portal_documents')->fetchColumn();
    if ($documentCount === 0) {
        $insertDoc = $db->prepare("INSERT INTO portal_documents(name, linked_to, reference, tags, url, version, visibility, uploaded_by, created_at, updated_at) VALUES(:name, :linked_to, :reference, :tags, :url, :version, :visibility, NULL, datetime('now', '+330 minutes'), datetime('now', '+330 minutes'))");
        $insertDoc->execute([
            ':name' => 'Service Playbook',
            ':linked_to' => 'operations',
            ':reference' => 'DOC-OPS-01',
            ':tags' => json_encode(['SOP', 'Service']),
            ':url' => '#',
            ':version' => 1,
            ':visibility' => 'both',
        ]);
        $insertDoc->execute([
            ':name' => 'Subsidy Checklist',
            ':linked_to' => 'subsidy',
            ':reference' => 'DOC-SUB-11',
            ':tags' => json_encode(['Checklist']),
            ':url' => '#',
            ':version' => 1,
            ':visibility' => 'employee',
        ]);
    }

    $notificationCount = (int) $db->query('SELECT COUNT(*) FROM portal_notifications')->fetchColumn();
    if ($notificationCount === 0) {
        $insertNotification = $db->prepare("INSERT INTO portal_notifications(audience, tone, icon, title, message, link, created_at) VALUES(:audience, :tone, :icon, :title, :message, :link, datetime('now', '+330 minutes'))");
        $insertNotification->execute([
            ':audience' => 'employee',
            ':tone' => 'info',
            ':icon' => 'fa-solid fa-ticket',
            ':title' => 'Ticket SR-219 assigned',
            ':message' => 'Admin added SR-219 to your queue with a 24-hour SLA.',
            ':link' => '#complaints',
        ]);
        $insertNotification->execute([
            ':audience' => 'employee',
            ':tone' => 'warning',
            ':icon' => 'fa-solid fa-clock',
            ':title' => 'SLA due soon',
            ':message' => 'SR-205 needs an update before 18:00 IST today.',
            ':link' => '#complaints',
        ]);
    }

    seed_installations($db);
}

function seed_installations(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM installations')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $employeeId = (int) ($db->query("SELECT id FROM users WHERE LOWER(username) = 'employee' LIMIT 1")->fetchColumn() ?: 0);
    $installerId = (int) ($db->query("SELECT id FROM users WHERE LOWER(username) = 'installer' LIMIT 1")->fetchColumn() ?: 0);
    $employeeName = (string) ($db->query("SELECT full_name FROM users WHERE id = $employeeId")->fetchColumn() ?: 'Operations Coordinator');
    $installerName = (string) ($db->query("SELECT full_name FROM users WHERE id = $installerId")->fetchColumn() ?: 'Lead Installer');
    $adminId = (int) ($db->query("SELECT id FROM users WHERE LOWER(username) = 'admin' LIMIT 1")->fetchColumn() ?: 0);
    $adminName = (string) ($db->query("SELECT full_name FROM users WHERE id = $adminId")->fetchColumn() ?: 'Administrator');

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

    $entrySetOne = json_encode([
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'structure',
            'type' => 'stage',
            'remarks' => 'Mounting structure aligned and anchor bolts torqued.',
            'photo' => 'structure-east-wing.jpg',
            'actorId' => $employeeId ?: null,
            'actorName' => $employeeName,
            'actorRole' => 'Employee',
            'timestamp' => $now->modify('-4 days')->format('Y-m-d H:i:s'),
        ],
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'wiring',
            'type' => 'stage',
            'remarks' => 'DC string continuity verified on rooftop.',
            'photo' => 'string-test-report.pdf',
            'actorId' => $installerId ?: null,
            'actorName' => $installerName,
            'actorRole' => 'Installer',
            'timestamp' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
        ],
    ], JSON_THROW_ON_ERROR);

    $entrySetTwo = json_encode([
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'structure',
            'type' => 'stage',
            'remarks' => 'Module mounting completed, fasteners inspected with torque wrench.',
            'photo' => 'mounting-bolts.jpg',
            'actorId' => $installerId ?: null,
            'actorName' => $installerName,
            'actorRole' => 'Installer',
            'timestamp' => $now->modify('-6 days')->format('Y-m-d H:i:s'),
        ],
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'testing',
            'type' => 'stage',
            'remarks' => 'Megger tests logged for DC and AC sides.',
            'photo' => 'megger-log.xlsx',
            'actorId' => $employeeId ?: null,
            'actorName' => $employeeName,
            'actorRole' => 'Employee',
            'timestamp' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        ],
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'meter',
            'type' => 'request',
            'remarks' => 'DISCOM meter installed, commissioning evidence shared.',
            'photo' => 'net-meter.jpg',
            'actorId' => $installerId ?: null,
            'actorName' => $installerName,
            'actorRole' => 'Installer',
            'timestamp' => $now->modify('-1 days')->format('Y-m-d H:i:s'),
        ],
    ], JSON_THROW_ON_ERROR);

    $entrySetThree = json_encode([
        [
            'id' => bin2hex(random_bytes(8)),
            'stage' => 'commissioned',
            'type' => 'stage',
            'remarks' => 'Handover and training completed with customer family.',
            'photo' => 'handover-checklist.pdf',
            'actorId' => $adminId ?: null,
            'actorName' => $adminName,
            'actorRole' => 'Admin',
            'timestamp' => $now->modify('-8 hours')->format('Y-m-d H:i:s'),
        ],
    ], JSON_THROW_ON_ERROR);

    $insert = $db->prepare("INSERT INTO installations(customer_name, project_reference, capacity_kw, status, stage, stage_entries, requested_stage, requested_by, requested_at, amc_committed, scheduled_date, handover_date, assigned_to, installer_id, created_at, updated_at)
        VALUES(:customer_name, :project_reference, :capacity_kw, :status, :stage, :stage_entries, :requested_stage, :requested_by, :requested_at, :amc_committed, :scheduled_date, :handover_date, :assigned_to, :installer_id, datetime('now', '+330 minutes'), datetime('now', '+330 minutes'))");

    $insert->execute([
        ':customer_name' => 'Asha Devi',
        ':project_reference' => 'INST-001',
        ':capacity_kw' => 6.5,
        ':status' => 'in_progress',
        ':stage' => 'testing',
        ':stage_entries' => $entrySetOne,
        ':requested_stage' => null,
        ':requested_by' => null,
        ':requested_at' => null,
        ':amc_committed' => 1,
        ':scheduled_date' => $now->modify('+2 days')->format('Y-m-d'),
        ':handover_date' => $now->modify('+7 days')->format('Y-m-d'),
        ':assigned_to' => $employeeId ?: null,
        ':installer_id' => $installerId ?: null,
    ]);

    $insert->execute([
        ':customer_name' => 'Rajesh Kumar',
        ':project_reference' => 'INST-004',
        ':capacity_kw' => 8.0,
        ':status' => 'in_progress',
        ':stage' => 'meter',
        ':stage_entries' => $entrySetTwo,
        ':requested_stage' => 'commissioned',
        ':requested_by' => $installerId ?: null,
        ':requested_at' => $now->modify('-1 hours')->format('Y-m-d H:i:s'),
        ':amc_committed' => 0,
        ':scheduled_date' => $now->modify('+1 days')->format('Y-m-d'),
        ':handover_date' => $now->modify('+3 days')->format('Y-m-d'),
        ':assigned_to' => $employeeId ?: null,
        ':installer_id' => $installerId ?: null,
    ]);

    $insert->execute([
        ':customer_name' => 'Sangeeta P.',
        ':project_reference' => 'INST-006',
        ':capacity_kw' => 5.2,
        ':status' => 'completed',
        ':stage' => 'commissioned',
        ':stage_entries' => $entrySetThree,
        ':requested_stage' => null,
        ':requested_by' => null,
        ':requested_at' => null,
        ':amc_committed' => 1,
        ':scheduled_date' => $now->modify('-15 days')->format('Y-m-d'),
        ':handover_date' => $now->modify('-8 days')->format('Y-m-d'),
        ':assigned_to' => $employeeId ?: null,
        ':installer_id' => $installerId ?: null,
    ]);
}

function merge_employee_roles(PDO $db): void
{
    $aliases = ['employee', 'staff', 'team', 'field', 'agent', 'technician', 'support'];
    $roleStmt = $db->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');

    $roleStmt->execute([':name' => 'employee']);
    $employeeRoleId = $roleStmt->fetchColumn();
    if ($employeeRoleId === false) {
        $db->prepare('INSERT INTO roles(name, description) VALUES(:name, :description)')->execute([
            ':name' => 'employee',
            ':description' => 'Internal staff managing operations and service.',
        ]);
        $roleStmt->execute([':name' => 'employee']);
        $employeeRoleId = $roleStmt->fetchColumn();
    }

    if ($employeeRoleId === false) {
        return;
    }

    $employeeRoleId = (int) $employeeRoleId;
    foreach ($aliases as $alias) {
        if ($alias === 'employee') {
            continue;
        }
        $roleStmt->execute([':name' => $alias]);
        $legacyRoleId = $roleStmt->fetchColumn();
        if ($legacyRoleId === false) {
            continue;
        }
        $legacyRoleId = (int) $legacyRoleId;
        $db->prepare('UPDATE users SET role_id = :employee_role WHERE role_id = :legacy_role')->execute([
            ':employee_role' => $employeeRoleId,
            ':legacy_role' => $legacyRoleId,
        ]);
        $db->prepare('UPDATE invitations SET role_id = :employee_role WHERE role_id = :legacy_role')->execute([
            ':employee_role' => $employeeRoleId,
            ':legacy_role' => $legacyRoleId,
        ]);
        $db->prepare('DELETE FROM roles WHERE id = :id')->execute([':id' => $legacyRoleId]);
    }
}

function now_ist(): string
{
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return $now->format('Y-m-d H:i:s');
}

function canonical_role_name(string $roleName): string
{
    $normalized = strtolower(trim($roleName));
    $map = [
        'staff' => 'employee',
        'team' => 'employee',
        'field' => 'employee',
        'agent' => 'employee',
        'technician' => 'employee',
        'support' => 'employee',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    return $normalized;
}

function portal_role_label(string $roleName): string
{
    $canonical = canonical_role_name($roleName);
    if ($canonical === 'employee') {
        return 'Employee';
    }

    return ucfirst($canonical);
}

function portal_find_user(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT users.*, roles.name AS role_name FROM users INNER JOIN roles ON users.role_id = roles.id WHERE users.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    $user = $user ?: null;

    $storeRecord = null;
    try {
        $store = user_store();
        $storeRecord = $store->get($id);
    } catch (Throwable $exception) {
        error_log(sprintf('portal_find_user: unable to read user store for %d: %s', $id, $exception->getMessage()));
        $storeRecord = null;
    }

    if (is_array($storeRecord)) {
        $normalized = admin_normalise_user_record($storeRecord);
        $canonicalStatus = strtolower($normalized['status']);
        if (!in_array($canonicalStatus, ['active', 'inactive', 'pending'], true)) {
            $canonicalStatus = 'active';
        }

        $needsSync = $user === null;
        if ($user !== null) {
            $databaseStatus = strtolower((string) ($user['status'] ?? ''));
            $permissionsNote = (string) ($user['permissions_note'] ?? '');
            if ($databaseStatus !== $canonicalStatus) {
                $needsSync = true;
            }
            if ($permissionsNote !== $normalized['permissions_note']) {
                $needsSync = true;
            }
            $databaseEmail = (string) ($user['email'] ?? '');
            if ($normalized['email'] !== '' && $databaseEmail !== $normalized['email']) {
                $needsSync = true;
            }
            $databaseUsername = (string) ($user['username'] ?? '');
            if ($normalized['username'] !== '' && $databaseUsername !== $normalized['username']) {
                $needsSync = true;
            }
        }

        if ($needsSync) {
            try {
                admin_sync_user_record($db, $storeRecord);
                $stmt->execute([':id' => $id]);
                $user = $stmt->fetch() ?: $user;
            } catch (Throwable $exception) {
                error_log(sprintf('portal_find_user: failed to sync user %d: %s', $id, $exception->getMessage()));
            }
        }

        if ($user === null) {
            $user = [
                'id' => $normalized['id'],
                'full_name' => $normalized['full_name'],
                'email' => $normalized['email'],
                'username' => $normalized['username'],
                'status' => $canonicalStatus,
                'permissions_note' => $normalized['permissions_note'],
                'role_name' => $normalized['role'],
            ];
        } else {
            $user['status'] = $canonicalStatus;
            if ($normalized['full_name'] !== '') {
                $user['full_name'] = $normalized['full_name'];
            }
            if ($normalized['email'] !== '') {
                $user['email'] = $normalized['email'];
            }
            if ($normalized['username'] !== '') {
                $user['username'] = $normalized['username'];
            }
            if ($normalized['permissions_note'] !== '') {
                $user['permissions_note'] = $normalized['permissions_note'];
            }
            if ($normalized['role'] !== '') {
                $user['role_name'] = $normalized['role'];
            }
        }
    }

    return $user ?: null;
}

function portal_ensure_employee(PDO $db, int $id): array
{
    $user = portal_find_user($db, $id);
    if (!$user) {
        throw new RuntimeException('Employee not found.');
    }

    if (canonical_role_name($user['role_name'] ?? '') !== 'employee') {
        throw new RuntimeException('Assignee must be an employee.');
    }

    if (($user['status'] ?? 'inactive') !== 'active') {
        throw new RuntimeException('Employee must be active.');
    }

    return $user;
}

function portal_list_team(PDO $db): array
{
    $stmt = $db->query("SELECT users.id, users.full_name, users.email, users.permissions_note, roles.name AS role_name FROM users INNER JOIN roles ON users.role_id = roles.id WHERE roles.name IN ('employee','installer','admin') ORDER BY roles.name, users.full_name");
    $team = [];
    foreach ($stmt->fetchAll() as $row) {
        $team[] = [
            'id' => (int) $row['id'],
            'name' => $row['full_name'],
            'email' => $row['email'],
            'role' => portal_role_label($row['role_name']),
            'note' => $row['permissions_note'] ?? '',
        ];
    }

    return $team;
}

function portal_list_tasks(PDO $db, ?int $assigneeId = null): array
{
    $sql = "SELECT portal_tasks.*, users.full_name AS assignee_name, users.email AS assignee_email, roles.name AS assignee_role FROM portal_tasks LEFT JOIN users ON portal_tasks.assignee_id = users.id LEFT JOIN roles ON users.role_id = roles.id";
    $params = [];
    if ($assigneeId !== null) {
        $sql .= ' WHERE portal_tasks.assignee_id = :assignee_id';
        $params[':assignee_id'] = $assigneeId;
    }
    $sql .= " ORDER BY CASE portal_tasks.status WHEN 'todo' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, COALESCE(portal_tasks.due_date, ''), portal_tasks.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $tasks = [];
    foreach ($stmt->fetchAll() as $row) {
        $tasks[] = portal_normalize_task_row($row);
    }

    return $tasks;
}

function portal_normalize_task_row(array $row): array
{
    $assigneeRole = $row['assignee_role'] ?? '';
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'description' => $row['description'] ?? '',
        'status' => $row['status'],
        'priority' => $row['priority'],
        'dueDate' => $row['due_date'] ?? '',
        'linkedTo' => $row['linked_reference'] ?? '',
        'notes' => $row['notes'] ?? '',
        'assigneeId' => $row['assignee_id'] !== null ? (string) $row['assignee_id'] : '',
        'assigneeName' => $row['assignee_name'] ?? '',
        'assigneeRole' => $assigneeRole ? portal_role_label($assigneeRole) : '',
        'createdAt' => $row['created_at'] ?? '',
        'updatedAt' => $row['updated_at'] ?? '',
        'completedAt' => $row['completed_at'] ?? '',
    ];
}

function portal_save_task(PDO $db, array $input, int $actorId): array
{
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Task title is required.');
    }

    $status = $input['status'] ?? 'todo';
    if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
        $status = 'todo';
    }

    $priority = $input['priority'] ?? 'medium';
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $priority = 'medium';
    }

    $dueDate = trim((string) ($input['dueDate'] ?? ''));
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        throw new RuntimeException('Due date must be in YYYY-MM-DD format.');
    }

    $assigneeId = isset($input['assigneeId']) && $input['assigneeId'] !== '' ? (int) $input['assigneeId'] : null;
    if ($assigneeId !== null) {
        $assignee = portal_find_user($db, $assigneeId);
        if (!$assignee || $assignee['role_name'] !== 'employee') {
            throw new RuntimeException('Select a valid employee for assignment.');
        }
    }

    $now = now_ist();
    $linked = trim((string) ($input['linkedTo'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));

    $taskId = isset($input['id']) ? (int) $input['id'] : 0;
    if ($taskId > 0) {
        $stmt = $db->prepare('UPDATE portal_tasks SET title = :title, description = :description, status = :status, priority = :priority, due_date = :due_date, linked_reference = :linked_reference, notes = :notes, assignee_id = :assignee_id, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':status' => $status,
            ':priority' => $priority,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
            ':linked_reference' => $linked !== '' ? $linked : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':assignee_id' => $assigneeId,
            ':updated_at' => $now,
            ':id' => $taskId,
        ]);
        portal_log_action($db, $actorId, 'update', 'task', $taskId, 'Task updated via admin portal');
    } else {
        $stmt = $db->prepare('INSERT INTO portal_tasks(title, description, status, priority, due_date, linked_reference, notes, assignee_id, created_by, created_at, updated_at) VALUES(:title, :description, :status, :priority, :due_date, :linked_reference, :notes, :assignee_id, :created_by, :created_at, :updated_at)');
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':status' => $status,
            ':priority' => $priority,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
            ':linked_reference' => $linked !== '' ? $linked : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':assignee_id' => $assigneeId,
            ':created_by' => $actorId ?: null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $taskId = (int) $db->lastInsertId();
        portal_log_action($db, $actorId, 'create', 'task', $taskId, 'Task created via admin portal');
    }

    if ($status === 'done') {
        $db->prepare('UPDATE portal_tasks SET completed_at = :completed_at WHERE id = :id')->execute([
            ':completed_at' => $now,
            ':id' => $taskId,
        ]);
    } else {
        $db->prepare('UPDATE portal_tasks SET completed_at = NULL WHERE id = :id')->execute([':id' => $taskId]);
    }

    $stmt = $db->prepare('SELECT portal_tasks.*, users.full_name AS assignee_name, users.email AS assignee_email, roles.name AS assignee_role FROM portal_tasks LEFT JOIN users ON portal_tasks.assignee_id = users.id LEFT JOIN roles ON users.role_id = roles.id WHERE portal_tasks.id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Task not found after save.');
    }

    $normalized = portal_normalize_task_row($row);
    portal_generate_task_notifications($db, $normalized);

    return $normalized;
}

function portal_update_task_status(PDO $db, int $taskId, string $status, int $actorId): array
{
    if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
        throw new RuntimeException('Invalid task status.');
    }
    enforce_task_access($db, $taskId, $actorId);
    $stmt = $db->prepare('SELECT * FROM portal_tasks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Task not found.');
    }

    $now = now_ist();
    $update = $db->prepare('UPDATE portal_tasks SET status = :status, updated_at = :updated_at, completed_at = :completed_at WHERE id = :id');
    $update->execute([
        ':status' => $status,
        ':updated_at' => $now,
        ':completed_at' => $status === 'done' ? $now : null,
        ':id' => $taskId,
    ]);

    portal_log_action($db, $actorId, 'status_change', 'task', $taskId, 'Task status updated to ' . $status);

    $stmt = $db->prepare('SELECT portal_tasks.*, users.full_name AS assignee_name, users.email AS assignee_email, roles.name AS assignee_role FROM portal_tasks LEFT JOIN users ON portal_tasks.assignee_id = users.id LEFT JOIN roles ON users.role_id = roles.id WHERE portal_tasks.id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Unable to load task after update.');
    }

    $normalized = portal_normalize_task_row($row);
    portal_generate_task_notifications($db, $normalized);

    return $normalized;
}

function portal_generate_task_notifications(PDO $db, array $task): void
{
    $assigneeId = isset($task['assigneeId']) && $task['assigneeId'] !== '' ? (int) $task['assigneeId'] : null;
    if ($assigneeId === null || $assigneeId <= 0) {
        return;
    }

    $dueDate = $task['dueDate'] ?? '';
    if ($dueDate === '') {
        return;
    }

    $due = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate, new DateTimeZone('Asia/Kolkata'));
    if (!$due) {
        return;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $diffDays = (int) $now->diff($due)->format('%r%a');
    $link = '#task-' . $task['id'];

    $check = $db->prepare('SELECT id FROM portal_notifications WHERE link = :link AND scope_user_id = :scope_user_id LIMIT 1');
    $check->execute([
        ':link' => $link,
        ':scope_user_id' => $assigneeId,
    ]);
    $existing = $check->fetchColumn();

    if ($diffDays > 1) {
        if ($existing !== false) {
            portal_mark_notification($db, (int) $existing, $assigneeId, 'dismissed');
        }
        return;
    }

    $tone = $diffDays < 0 ? 'danger' : 'warning';
    $title = $diffDays < 0 ? 'Task overdue' : 'Task due soon';
    $message = $diffDays < 0
        ? sprintf('%s is overdue by %d day%s.', $task['title'], abs($diffDays), abs($diffDays) === 1 ? '' : 's')
        : sprintf('%s is due within %d day%s.', $task['title'], max(0, $diffDays), $diffDays === 1 ? '' : 's');

    if ($existing !== false) {
        $db->prepare('UPDATE portal_notifications SET tone = :tone, title = :title, message = :message, created_at = :created_at WHERE id = :id')->execute([
            ':tone' => $tone,
            ':title' => $title,
            ':message' => $message,
            ':created_at' => now_ist(),
            ':id' => (int) $existing,
        ]);
        $notificationId = (int) $existing;
    } else {
        $db->prepare('INSERT INTO portal_notifications(audience, tone, icon, title, message, link, scope_user_id, created_at) VALUES(\'employee\', :tone, :icon, :title, :message, :link, :scope_user_id, :created_at)')->execute([
            ':tone' => $tone,
            ':icon' => 'fa-solid fa-list-check',
            ':title' => $title,
            ':message' => $message,
            ':link' => $link,
            ':scope_user_id' => $assigneeId,
            ':created_at' => now_ist(),
        ]);
        $notificationId = (int) $db->lastInsertId();
    }

    portal_mark_notification($db, $notificationId, $assigneeId, 'unread');
}

function portal_list_documents(PDO $db, string $audience = 'admin', ?int $userId = null): array
{
    if (!in_array($audience, ['employee', 'admin'], true)) {
        $audience = 'admin';
    }

    if ($audience === 'admin') {
        $stmt = $db->query('SELECT portal_documents.*, users.full_name AS uploaded_by_name FROM portal_documents LEFT JOIN users ON portal_documents.uploaded_by = users.id ORDER BY portal_documents.updated_at DESC');
        return portal_normalize_documents($stmt->fetchAll());
    }

    $sql = 'SELECT portal_documents.*, users.full_name AS uploaded_by_name FROM portal_documents LEFT JOIN users ON portal_documents.uploaded_by = users.id WHERE portal_documents.visibility IN (\'employee\', \'both\')';
    $params = [];

    if ($userId !== null) {
        $scope = portal_employee_document_scope($db, $userId);
        $conditions = [];
        $params[':user_id'] = $userId;
        $conditions[] = 'portal_documents.uploaded_by = :user_id';

        $index = 0;
        foreach ($scope['tickets'] as $reference) {
            $key = ':ticket_ref_' . $index++;
            $conditions[] = "(portal_documents.linked_to = 'ticket' AND portal_documents.reference = $key)";
            $params[$key] = $reference;
        }
        foreach ($scope['customers'] as $customer) {
            $key = ':customer_ref_' . $index++;
            $conditions[] = "(portal_documents.linked_to IN ('customer','operations') AND portal_documents.reference = $key)";
            $params[$key] = $customer;
        }
        foreach ($scope['tasks'] as $taskReference) {
            $key = ':task_ref_' . $index++;
            $conditions[] = "(portal_documents.linked_to = 'task' AND portal_documents.reference = $key)";
            $params[$key] = $taskReference;
        }

        if (!empty($conditions)) {
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }
    }

    $sql .= ' ORDER BY portal_documents.updated_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return portal_normalize_documents($stmt->fetchAll());
}

function portal_normalize_documents(array $rows): array
{
    $documents = [];
    foreach ($rows as $row) {
        $tags = [];
        if (!empty($row['tags'])) {
            $decoded = json_decode((string) $row['tags'], true);
            if (is_array($decoded)) {
                $tags = array_values(array_filter(array_map('strval', $decoded)));
            }
        }
        $documents[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'linkedTo' => $row['linked_to'] ?? '',
            'reference' => $row['reference'] ?? '',
            'tags' => $tags,
            'url' => $row['url'] ?? '',
            'version' => (int) ($row['version'] ?? 1),
            'visibility' => $row['visibility'] ?? 'employee',
            'uploadedBy' => $row['uploaded_by_name'] ?? 'Admin',
            'updatedAt' => $row['updated_at'] ?? '',
        ];
    }

    return $documents;
}

function portal_employee_document_scope(PDO $db, int $userId): array
{
    $tickets = [];
    $customers = [];
    $tasks = [];

    $stmt = $db->prepare('SELECT reference FROM complaints WHERE assigned_to = :user_id');
    $stmt->execute([':user_id' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $reference) {
        if ($reference === null) {
            continue;
        }
        $tickets[] = (string) $reference;
        $customers[] = (string) $reference;
    }

    $taskStmt = $db->prepare('SELECT DISTINCT linked_reference FROM portal_tasks WHERE assignee_id = :user_id AND linked_reference IS NOT NULL AND linked_reference != \'\'');
    $taskStmt->execute([':user_id' => $userId]);
    foreach ($taskStmt->fetchAll(PDO::FETCH_COLUMN) as $reference) {
        if ($reference === null) {
            continue;
        }
        $tasks[] = (string) $reference;
        $customers[] = (string) $reference;
    }

    return [
        'tickets' => array_values(array_unique($tickets)),
        'customers' => array_values(array_unique($customers)),
        'tasks' => array_values(array_unique($tasks)),
    ];
}

function portal_save_document(PDO $db, array $input, int $actorId): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $linkedTo = trim((string) ($input['linkedTo'] ?? ''));
    if ($name === '' || $linkedTo === '') {
        throw new RuntimeException('Document name and linked module are required.');
    }

    $reference = trim((string) ($input['reference'] ?? ''));
    $url = trim((string) ($input['url'] ?? ''));
    $tags = $input['tags'] ?? [];
    if (is_string($tags)) {
        $tags = array_filter(array_map('trim', explode(',', $tags)));
    }
    if (!is_array($tags)) {
        $tags = [];
    }
    $visibility = $input['visibility'] ?? 'employee';
    if (!in_array($visibility, ['employee', 'admin', 'both'], true)) {
        $visibility = 'employee';
    }

    $now = now_ist();
    $documentId = isset($input['id']) ? (int) $input['id'] : 0;
    if ($documentId > 0) {
        $stmt = $db->prepare('UPDATE portal_documents SET name = :name, linked_to = :linked_to, reference = :reference, tags = :tags, url = :url, version = version + 1, visibility = :visibility, uploaded_by = :uploaded_by, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':name' => $name,
            ':linked_to' => $linkedTo,
            ':reference' => $reference !== '' ? $reference : null,
            ':tags' => json_encode(array_values($tags)),
            ':url' => $url !== '' ? $url : null,
            ':visibility' => $visibility,
            ':uploaded_by' => $actorId ?: null,
            ':updated_at' => $now,
            ':id' => $documentId,
        ]);
        portal_log_action($db, $actorId, 'update', 'document', $documentId, 'Document metadata updated');
    } else {
        $stmt = $db->prepare('INSERT INTO portal_documents(name, linked_to, reference, tags, url, version, visibility, uploaded_by, created_at, updated_at) VALUES(:name, :linked_to, :reference, :tags, :url, 1, :visibility, :uploaded_by, :created_at, :updated_at)');
        $stmt->execute([
            ':name' => $name,
            ':linked_to' => $linkedTo,
            ':reference' => $reference !== '' ? $reference : null,
            ':tags' => json_encode(array_values($tags)),
            ':url' => $url !== '' ? $url : null,
            ':visibility' => $visibility,
            ':uploaded_by' => $actorId ?: null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $documentId = (int) $db->lastInsertId();
        portal_log_action($db, $actorId, 'create', 'document', $documentId, 'Document added to shared vault');
    }

    $stmt = $db->prepare('SELECT portal_documents.*, users.full_name AS uploaded_by_name FROM portal_documents LEFT JOIN users ON portal_documents.uploaded_by = users.id WHERE portal_documents.id = :id');
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Document not found after save.');
    }

    return portal_normalize_documents([$row])[0];
}

function portal_employee_submit_document(PDO $db, array $input, int $actorId): array
{
    $customer = trim((string) ($input['customer'] ?? ''));
    $type = trim((string) ($input['type'] ?? ''));
    $filename = trim((string) ($input['filename'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    $fileSizeRaw = trim((string) ($input['fileSize'] ?? ($input['file_size'] ?? '')));

    if ($customer === '' || $type === '' || $filename === '') {
        throw new RuntimeException('Customer, document type, and file name are required.');
    }

    $scope = portal_employee_document_scope($db, $actorId);
    $allowedReferences = array_merge($scope['tickets'], $scope['customers']);
    if (!in_array($customer, $allowedReferences, true)) {
        throw new RuntimeException('You can only upload documents for your assigned tickets or customers.');
    }

    $tags = [];
    if ($note !== '') {
        $tags[] = $note;
    }
    if ($fileSizeRaw !== '' && is_numeric($fileSizeRaw)) {
        $size = max(0, (float) $fileSizeRaw);
        $tags[] = sprintf('filesize:%0.1fMB', $size);
    }

    $document = portal_save_document($db, [
        'name' => $type,
        'linkedTo' => 'customer',
        'reference' => $customer,
        'tags' => $tags,
        'url' => $input['url'] ?? '',
        'visibility' => 'employee',
    ], $actorId);

    $complaintId = portal_find_complaint_id($db, $customer);
    if ($complaintId !== null) {
        $summary = sprintf('%s uploaded (%s)', $type, $filename);
        $details = $note !== '' ? $note : null;
        portal_record_complaint_event($db, $complaintId, $actorId, 'document', $summary, $details, $document['id']);
        $db->prepare('UPDATE complaints SET updated_at = :updated_at WHERE id = :id')->execute([
            ':updated_at' => now_ist(),
            ':id' => $complaintId,
        ]);
    }

    return $document;
}

function portal_list_notifications(PDO $db, int $userId, string $audience = 'employee'): array
{
    $stmt = $db->prepare('SELECT n.*, IFNULL(s.status, \'unread\') AS read_status FROM portal_notifications n LEFT JOIN portal_notification_status s ON n.id = s.notification_id AND s.user_id = :user_id WHERE n.audience IN (\'all\', :audience) AND IFNULL(s.status, \'unread\') != \'dismissed\' AND (n.scope_user_id IS NULL OR n.scope_user_id = :user_id) ORDER BY n.created_at DESC');
    $stmt->execute([
        ':user_id' => $userId,
        ':audience' => $audience,
    ]);

    $notifications = [];
    foreach ($stmt->fetchAll() as $row) {
        $notifications[] = [
            'id' => (int) $row['id'],
            'tone' => $row['tone'] ?? 'info',
            'icon' => $row['icon'] ?? 'fa-solid fa-circle-info',
            'title' => $row['title'],
            'message' => $row['message'],
            'link' => $row['link'] ?? '#',
            'time' => $row['created_at'] ?? '',
            'isRead' => ($row['read_status'] ?? 'unread') === 'read',
        ];
    }

    return $notifications;
}

function portal_mark_notification(PDO $db, int $notificationId, int $userId, string $status): void
{
    if (!in_array($status, ['read', 'unread', 'dismissed'], true)) {
        throw new RuntimeException('Invalid notification status.');
    }

    $stmt = $db->prepare('INSERT INTO portal_notification_status(notification_id, user_id, status, updated_at) VALUES(:notification_id, :user_id, :status, :updated_at)
        ON CONFLICT(notification_id, user_id) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at');
    $stmt->execute([
        ':notification_id' => $notificationId,
        ':user_id' => $userId,
        ':status' => $status,
        ':updated_at' => now_ist(),
    ]);
}

function portal_store_reminder_banner(PDO $db, int $userId, string $tone, string $title, string $message): void
{
    if ($userId <= 0) {
        return;
    }

    $allowedTones = ['info', 'success', 'warning', 'danger'];
    if (!in_array($tone, $allowedTones, true)) {
        $tone = 'info';
    }

    try {
        $stmt = $db->prepare('INSERT INTO reminder_status_banners(user_id, tone, title, message, created_at) VALUES(:user_id, :tone, :title, :message, :created_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':tone' => $tone,
            ':title' => $title,
            ':message' => $message,
            ':created_at' => now_ist(),
        ]);
    } catch (Throwable $exception) {
        error_log('Failed to queue reminder banner: ' . $exception->getMessage());
    }
}

function portal_consume_reminder_banners(PDO $db, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = $db->prepare('SELECT id, tone, title, message FROM reminder_status_banners WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $db->prepare("DELETE FROM reminder_status_banners WHERE id IN ($placeholders)");
        foreach ($ids as $index => $id) {
            $delete->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $delete->execute();

        return array_map(static function (array $row): array {
            return [
                'tone' => $row['tone'] ?? 'info',
                'title' => (string) ($row['title'] ?? 'Reminder update'),
                'message' => (string) ($row['message'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable $exception) {
        error_log('Failed to load reminder banners: ' . $exception->getMessage());
        return [];
    }
}

function portal_latest_sync(PDO $db, ?int $userId = null): ?string
{
    $parts = [];
    $tasksQuery = 'SELECT MAX(updated_at) FROM portal_tasks';
    $complaintsQuery = 'SELECT MAX(updated_at) FROM complaints';
    $documentsQuery = 'SELECT MAX(updated_at) FROM portal_documents';
    $notificationsQuery = 'SELECT MAX(created_at) FROM portal_notifications';

    $parts[] = $db->query($tasksQuery)->fetchColumn();
    $parts[] = $db->query($complaintsQuery)->fetchColumn();
    $parts[] = $db->query($documentsQuery)->fetchColumn();
    $parts[] = $db->query($notificationsQuery)->fetchColumn();

    if ($userId !== null) {
        $stmt = $db->prepare('SELECT MAX(updated_at) FROM portal_notification_status WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $parts[] = $stmt->fetchColumn();
    }

    $parts = array_filter(array_map(static fn ($value) => $value !== false ? $value : null, $parts));
    if (empty($parts)) {
        return null;
    }

    rsort($parts);
    return $parts[0] ?: null;
}

function portal_log_action(PDO $db, int $actorId, string $action, string $entityType, int $entityId, string $description): void
{
    $stmt = $db->prepare('INSERT INTO audit_logs(actor_id, action, entity_type, entity_id, description, created_at) VALUES(:actor_id, :action, :entity_type, :entity_id, :description, :created_at)');
    $stmt->execute([
        ':actor_id' => audit_resolve_actor_id($db, $actorId),
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':description' => $description,
        ':created_at' => now_ist(),
    ]);
}

function admin_resolve_role_id(?PDO $db, string $roleName): int
{
    unset($db);

    $normalized = strtolower(trim($roleName));
    if ($normalized === '') {
        throw new RuntimeException('Role is required.');
    }

    if (function_exists('canonical_role_name')) {
        $normalized = canonical_role_name($normalized);
    }

    $roleCatalog = [
        'admin' => 1,
    ];

    if (!isset($roleCatalog[$normalized])) {
        throw new RuntimeException('Unsupported role selected.');
    }

    return $roleCatalog[$normalized];
}

function admin_normalise_user_record(array $record): array
{
    $flags = $record['flags'] ?? [];
    if (!is_array($flags)) {
        $flags = [];
    }

    return [
        'id' => (int) ($record['id'] ?? 0),
        'full_name' => (string) ($record['full_name'] ?? ''),
        'email' => (string) ($record['email'] ?? ''),
        'username' => (string) ($record['username'] ?? ''),
        'phone' => $record['phone'] ?? null,
        'role' => (string) ($record['role'] ?? ''),
        'role_name' => (string) ($record['role'] ?? ''),
        'status' => (string) ($record['status'] ?? ''),
        'permissions_note' => (string) ($record['permissions_note'] ?? ''),
        'created_at' => (string) ($record['created_at'] ?? ''),
        'updated_at' => (string) ($record['updated_at'] ?? ''),
        'last_login_at' => $record['last_login_at'] ?? null,
        'password_last_set_at' => $record['password_last_set_at'] ?? null,
        'password_hash' => (string) ($record['password_hash'] ?? ''),
        'flags' => $flags,
    ];
}

function admin_sync_user_record(?PDO $db, array $record): void
{
    unset($db);

    admin_normalise_user_record($record);
}

function admin_fetch_user(?PDO $db, int $userId): array
{
    unset($db);

    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    return admin_normalise_user_record($record);
}

function admin_list_accounts(?PDO $db, array $filters = []): array
{
    unset($db);

    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    if ($status !== '' && $status !== 'all' && !in_array($status, ['active', 'inactive', 'pending'], true)) {
        throw new RuntimeException('Unsupported status filter.');
    }

    $store = user_store();
    $records = $store->listAll();

    $results = [];
    foreach ($records as $record) {
        $normalized = admin_normalise_user_record($record);
        if ($status !== '' && $status !== 'all' && strtolower($normalized['status']) !== $status) {
            continue;
        }

        $results[] = [
            'id' => $normalized['id'],
            'full_name' => $normalized['full_name'],
            'email' => $normalized['email'],
            'username' => $normalized['username'],
            'phone' => $normalized['phone'],
            'role' => $normalized['role'],
            'status' => $normalized['status'],
            'permissions_note' => $normalized['permissions_note'],
            'created_at' => $normalized['created_at'],
            'updated_at' => $normalized['updated_at'],
            'last_login_at' => $normalized['last_login_at'],
            'password_last_set_at' => $normalized['password_last_set_at'],
        ];
    }

    return $results;
}

function admin_create_user(?PDO $db, array $input, int $actorId): array
{
    $fullName = trim((string) ($input['full_name'] ?? ''));
    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }

    $roleName = strtolower(trim((string) ($input['role'] ?? 'admin')));
    admin_resolve_role_id($db, $roleName);

    if ($roleName !== 'admin') {
        throw new RuntimeException('Select a supported integral user role.');
    }

    $emailInput = trim((string) ($input['email'] ?? ''));
    $email = $emailInput !== '' ? strtolower($emailInput) : '';

    $username = strtolower(trim((string) ($input['username'] ?? '')));
    if ($username === '' || !preg_match('/^[a-z0-9._-]{3,}$/', $username)) {
        throw new RuntimeException('Username must be at least 3 characters (letters, numbers, dot, underscore, or dash).');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required for this role.');
    }

    $phoneDigits = preg_replace('/\D+/', '', (string) ($input['mobile'] ?? ''));
    if (!is_string($phoneDigits)) {
        $phoneDigits = '';
    }
    if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
        throw new RuntimeException('Enter a contact number with at least 10 digits.');
    }
    if (strlen($phoneDigits) > 10) {
        $phoneDigits = substr($phoneDigits, -10);
    }

    $password = (string) ($input['password'] ?? '');
    if (strlen($password) < 8) {
        throw new RuntimeException('Passwords must be at least 8 characters long.');
    }

    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $permissionsNote = trim((string) ($input['permissions_note'] ?? ''));

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = now_ist();

    $store = user_store();
    $record = $store->save([
        'full_name' => $fullName,
        'email' => $email !== '' ? $email : null,
        'username' => $username,
        'phone' => $phoneDigits,
        'role' => $roleName,
        'status' => $status,
        'permissions_note' => $permissionsNote,
        'password_hash' => $hash,
        'password_last_set_at' => $now,
        'created_at' => $now,
    ]);

    $store->appendAudit([
        'event' => 'admin_create_user',
        'actor_id' => $actorId,
        'user_id' => (int) ($record['id'] ?? 0),
        'role' => $roleName,
        'status' => $status,
    ]);

    return admin_list_accounts($db, ['status' => 'all']);
}

function admin_update_integral_user(?PDO $db, int $userId, array $input, int $actorId): array
{
    unset($db);

    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $fullName = trim((string) ($input['full_name'] ?? ($record['full_name'] ?? '')));
    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }

    $roleName = strtolower(trim((string) ($input['role'] ?? ($record['role'] ?? 'admin'))));
    admin_resolve_role_id(null, $roleName);
    if ($roleName !== 'admin') {
        throw new RuntimeException('Select a supported integral user role.');
    }

    $emailInput = trim((string) ($input['email'] ?? ($record['email'] ?? '')));
    $email = $emailInput !== '' ? strtolower($emailInput) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required for this role.');
    }

    $phoneDigits = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ($record['phone'] ?? '')));
    if (!is_string($phoneDigits)) {
        $phoneDigits = '';
    }
    if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
        throw new RuntimeException('Enter a contact number with at least 10 digits.');
    }
    if (strlen($phoneDigits) > 10) {
        $phoneDigits = substr($phoneDigits, -10);
    }

    $record['full_name'] = $fullName;
    $record['email'] = $email !== '' ? $email : null;
    $record['phone'] = $phoneDigits;
    $record['role'] = $roleName;
    $record['updated_at'] = now_ist();

    $updated = $store->save($record);

    $store->appendAudit([
        'event' => 'admin_update_user_profile',
        'actor_id' => $actorId,
        'user_id' => (int) ($updated['id'] ?? $userId),
        'role' => $roleName,
    ]);

    return admin_list_accounts(null, ['status' => 'all']);
}

function admin_update_user_status(?PDO $db, int $userId, string $status, int $actorId): array
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new RuntimeException('Unsupported status.');
    }

    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $record['status'] = $status;
    $updated = $store->save($record);

    $store->appendAudit([
        'event' => 'admin_update_user_status',
        'actor_id' => $actorId,
        'user_id' => $userId,
        'status' => $status,
    ]);

    return admin_list_accounts($db, ['status' => 'all']);
}

function admin_reset_user_password(?PDO $db, int $userId, string $password, int $actorId): array
{
    if (strlen($password) < 8) {
        throw new RuntimeException('Passwords must be at least 8 characters long.');
    }

    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = now_ist();

    $record['password_hash'] = $hash;
    $record['password_last_set_at'] = $now;

    $updated = $store->save($record);

    $store->appendAudit([
        'event' => 'admin_reset_user_password',
        'actor_id' => $actorId,
        'user_id' => $userId,
    ]);

    return admin_list_accounts($db, ['status' => 'all']);
}

function admin_delete_user(?PDO $db, int $userId, int $actorId): void
{
    unset($db);

    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $user = admin_normalise_user_record($record);
    $status = strtolower((string) ($user['status'] ?? ''));
    if ($status !== 'inactive') {
        throw new RuntimeException('Only inactive accounts can be deleted.');
    }

    if ($actorId === $userId) {
        throw new RuntimeException('You cannot delete your own account.');
    }

    $store->delete($userId);
    $store->appendAudit([
        'event' => 'admin_delete_user',
        'actor_id' => $actorId,
        'user_id' => $userId,
    ]);
}

function approval_request_normalize(array $row): array
{
    $payload = [];
    if (isset($row['payload']) && $row['payload'] !== null && $row['payload'] !== '') {
        try {
            $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (Throwable $exception) {
            $payload = [];
        }
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'type' => (string) ($row['request_type'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'targetType' => (string) ($row['target_type'] ?? ''),
        'targetId' => isset($row['target_id']) && $row['target_id'] !== null ? (int) $row['target_id'] : null,
        'payload' => $payload,
        'notes' => (string) ($row['notes'] ?? ''),
        'decisionNote' => (string) ($row['decision_note'] ?? ''),
        'requestedBy' => isset($row['requested_by']) ? (int) $row['requested_by'] : null,
        'requestedByName' => (string) ($row['requested_by_name'] ?? ''),
        'decidedBy' => isset($row['decided_by']) ? (int) $row['decided_by'] : null,
        'decidedByName' => (string) ($row['decided_by_name'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'decidedAt' => (string) ($row['decided_at'] ?? ''),
    ];
}

function approval_request_fetch(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT ar.*, requester.full_name AS requested_by_name, decider.full_name AS decided_by_name FROM approval_requests ar LEFT JOIN users requester ON ar.requested_by = requester.id LEFT JOIN users decider ON ar.decided_by = decider.id WHERE ar.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Approval request not found.');
    }

    return approval_request_normalize($row);
}

function approval_request_register(
    PDO $db,
    string $type,
    int $userId,
    string $subject,
    array $payload = [],
    ?string $targetType = null,
    ?int $targetId = null,
    string $notes = ''
): array {
    $type = strtolower(trim($type));
    if ($type === '') {
        throw new RuntimeException('Request type is required.');
    }

    $subject = trim($subject);
    if ($subject === '') {
        throw new RuntimeException('Request subject cannot be empty.');
    }

    $targetType = $targetType !== null ? strtolower(trim($targetType)) : null;
    $targetId = $targetId !== null ? (int) $targetId : null;
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $now = now_ist();

    $existingId = null;
    if ($targetType !== null && $targetType !== '' && $targetId !== null && $targetId > 0) {
        $stmt = $db->prepare('SELECT id FROM approval_requests WHERE request_type = :type AND target_type = :target_type AND target_id = :target_id AND status = "pending" LIMIT 1');
        $stmt->execute([
            ':type' => $type,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
        ]);
        $existingId = $stmt->fetchColumn();
    }

    if ($existingId !== false && $existingId !== null) {
        $requestId = (int) $existingId;
        $update = $db->prepare('UPDATE approval_requests SET subject = :subject, payload = :payload, notes = :notes, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':subject' => $subject,
            ':payload' => $payloadJson,
            ':notes' => $notes !== '' ? $notes : null,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]);
    } else {
        $insert = $db->prepare('INSERT INTO approval_requests(request_type, status, requested_by, subject, target_type, target_id, payload, notes, created_at, updated_at) VALUES(:request_type, "pending", :requested_by, :subject, :target_type, :target_id, :payload, :notes, :created_at, :updated_at)');
        $insert->execute([
            ':request_type' => $type,
            ':requested_by' => $userId,
            ':subject' => $subject,
            ':target_type' => $targetType !== null && $targetType !== '' ? $targetType : null,
            ':target_id' => $targetId,
            ':payload' => $payloadJson,
            ':notes' => $notes !== '' ? $notes : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $requestId = (int) $db->lastInsertId();
    }

    return approval_request_fetch($db, $requestId);
}

function approval_request_finalize(PDO $db, int $id, string $status, int $actorId, ?string $decisionNote = null): array
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['approved', 'rejected'], true)) {
        throw new RuntimeException('Unsupported decision.');
    }

    $now = now_ist();
    $stmt = $db->prepare('UPDATE approval_requests SET status = :status, decided_by = :decided_by, decided_at = :decided_at, decision_note = :decision_note, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':decided_by' => $actorId,
        ':decided_at' => $now,
        ':decision_note' => $decisionNote !== null && trim($decisionNote) !== '' ? trim($decisionNote) : null,
        ':updated_at' => $now,
        ':id' => $id,
    ]);

    return approval_request_fetch($db, $id);
}

function approval_request_sync_by_target(PDO $db, string $type, string $targetType, int $targetId, string $status, int $actorId, ?string $note = null): void
{
    $stmt = $db->prepare('SELECT id FROM approval_requests WHERE request_type = :type AND target_type = :target_type AND target_id = :target_id AND status = "pending" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([
        ':type' => strtolower(trim($type)),
        ':target_type' => strtolower(trim($targetType)),
        ':target_id' => $targetId,
    ]);
    $requestId = $stmt->fetchColumn();
    if ($requestId === false) {
        return;
    }

    approval_request_finalize($db, (int) $requestId, $status, $actorId, $note);
}

function employee_submit_request(PDO $db, int $userId, string $type, array $payload): array
{
    $type = strtolower(trim($type));
    switch ($type) {
        case 'leave':
            $startRaw = trim((string) ($payload['start_date'] ?? ''));
            $endRaw = trim((string) ($payload['end_date'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                throw new RuntimeException('Provide both start and end dates.');
            }
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $startRaw, new DateTimeZone('Asia/Kolkata'));
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $endRaw, new DateTimeZone('Asia/Kolkata'));
            if (!$start || !$end) {
                throw new RuntimeException('Dates must use the YYYY-MM-DD format.');
            }
            if ($end < $start) {
                throw new RuntimeException('End date cannot be before start date.');
            }
            $reason = trim((string) ($payload['reason'] ?? ''));
            $subject = sprintf('Leave from %s to %s', $start->format('d M'), $end->format('d M'));
            return approval_request_register($db, 'leave', $userId, $subject, [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'reason' => $reason,
            ], 'user', $userId, $reason);
        case 'data_correction':
            $module = strtolower(trim((string) ($payload['module'] ?? '')));
            $recordId = (int) ($payload['record_id'] ?? 0);
            $field = trim((string) ($payload['field'] ?? ''));
            $value = trim((string) ($payload['value'] ?? ''));
            $details = trim((string) ($payload['details'] ?? ''));

            if ($module === '' || !in_array($module, ['lead', 'installation', 'complaint', 'other'], true)) {
                throw new RuntimeException('Select a supported module for correction.');
            }
            if ($module !== 'other' && $recordId <= 0) {
                throw new RuntimeException('Provide a valid record reference.');
            }
            if ($field === '' && $details === '') {
                throw new RuntimeException('Describe the correction needed.');
            }

            $subject = 'Data correction for ' . ucfirst($module) . ($recordId > 0 ? ' #' . $recordId : '');
            return approval_request_register($db, 'data_correction', $userId, $subject, [
                'module' => $module,
                'record_id' => $recordId,
                'field' => $field,
                'value' => $value,
                'details' => $details,
            ], $module !== 'other' ? $module : null, $recordId > 0 ? $recordId : null, $details !== '' ? $details : $field);
        case 'reassignment':
            $module = strtolower(trim((string) ($payload['module'] ?? '')));
            $recordId = (int) ($payload['record_id'] ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($module === '' || !in_array($module, ['lead', 'installation', 'complaint'], true)) {
                throw new RuntimeException('Select a supported module for reassignment.');
            }
            if ($recordId <= 0) {
                throw new RuntimeException('Provide a valid record reference.');
            }
            if ($reason === '') {
                throw new RuntimeException('Provide a reason for reassignment.');
            }
            $subject = 'Reassignment request for ' . ucfirst($module) . ' #' . $recordId;
            return approval_request_register($db, 'reassignment', $userId, $subject, [
                'module' => $module,
                'record_id' => $recordId,
                'reason' => $reason,
            ], $module, $recordId, $reason);
        case 'profile_edit':
            $fullName = trim((string) ($payload['full_name'] ?? ''));
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $username = strtolower(trim((string) ($payload['username'] ?? '')));
            $notes = trim((string) ($payload['notes'] ?? ''));

            $updates = [];
            if ($fullName !== '') {
                $updates['full_name'] = $fullName;
            }
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Provide a valid email address.');
                }
                $updates['email'] = $email;
            }
            if ($username !== '') {
                if (!preg_match('/^[a-z0-9._-]{3,}$/', $username)) {
                    throw new RuntimeException('Username must be at least 3 characters (letters, numbers, dot, underscore, or dash).');
                }
                $updates['username'] = $username;
            }

            if (empty($updates)) {
                throw new RuntimeException('Specify at least one field to update.');
            }

            $subject = 'Profile update request';
            return approval_request_register($db, 'profile_edit', $userId, $subject, ['updates' => $updates], 'user', $userId, $notes);

        case 'leave':
            $startRaw = trim((string) ($payload['start_date'] ?? ''));
            $endRaw = trim((string) ($payload['end_date'] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                throw new RuntimeException('Provide both start and end dates.');
            }
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $startRaw, new DateTimeZone('Asia/Kolkata'));
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $endRaw, new DateTimeZone('Asia/Kolkata'));
            if (!$start || !$end) {
                throw new RuntimeException('Dates must use the YYYY-MM-DD format.');
            }
            if ($end < $start) {
                throw new RuntimeException('End date cannot be before start date.');
            }
            $reason = trim((string) ($payload['reason'] ?? ''));
            $subject = sprintf('Leave from %s to %s', $start->format('d M'), $end->format('d M'));
            return approval_request_register($db, 'leave', $userId, $subject, [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'reason' => $reason,
            ], 'user', $userId, $reason);

        case 'expense':
            $amount = (float) ($payload['amount'] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('Expense amount must be greater than zero.');
            }
            $category = trim((string) ($payload['category'] ?? 'General'));
            $description = trim((string) ($payload['description'] ?? ''));
            $subject = sprintf('Expense ₹%s - %s', number_format($amount, 2), $category !== '' ? $category : 'General');
            return approval_request_register($db, 'expense', $userId, $subject, [
                'amount' => $amount,
                'category' => $category,
                'description' => $description,
            ], 'user', $userId, $description);

        case 'data_correction':
            $module = strtolower(trim((string) ($payload['module'] ?? '')));
            $recordId = (int) ($payload['record_id'] ?? 0);
            $field = trim((string) ($payload['field'] ?? ''));
            $value = trim((string) ($payload['value'] ?? ''));
            $details = trim((string) ($payload['details'] ?? ''));

            if ($module === '' || !in_array($module, ['lead', 'installation', 'complaint', 'other'], true)) {
                throw new RuntimeException('Select a supported module for correction.');
            }
            if ($module !== 'other' && $recordId <= 0) {
                throw new RuntimeException('Provide a valid record reference.');
            }
            if ($field === '' && $details === '') {
                throw new RuntimeException('Describe the correction needed.');
            }

            $subject = 'Data correction for ' . ucfirst($module) . ($recordId > 0 ? ' #' . $recordId : '');
            return approval_request_register($db, 'data_correction', $userId, $subject, [
                'module' => $module,
                'record_id' => $recordId,
                'field' => $field,
                'value' => $value,
                'details' => $details,
            ], $module !== 'other' ? $module : null, $recordId > 0 ? $recordId : null, $details !== '' ? $details : $field);

        default:
            throw new RuntimeException('Unsupported request type.');
    }
}

function employee_list_requests(PDO $db, int $userId): array
{
    $stmt = $db->prepare('SELECT ar.*, users.full_name AS requested_by_name FROM approval_requests ar LEFT JOIN users ON ar.requested_by = users.id WHERE ar.requested_by = :user_id ORDER BY ar.created_at DESC');
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('approval_request_normalize', $rows);
}

function admin_list_requests(PDO $db, string $status = 'pending'): array
{
    $status = strtolower(trim($status));
    $conditions = [];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new RuntimeException('Unsupported status filter.');
        }
        $conditions[] = 'ar.status = :status';
        $params[':status'] = $status;
    }

    $sql = 'SELECT ar.*, requester.full_name AS requested_by_name, decider.full_name AS decided_by_name FROM approval_requests ar LEFT JOIN users requester ON ar.requested_by = requester.id LEFT JOIN users decider ON ar.decided_by = decider.id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY ar.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('approval_request_normalize', $rows);
}

function admin_apply_profile_updates(PDO $db, array $updates, int $userId, int $actorId): void
{
    if (empty($updates)) {
        return;
    }

    $allowed = ['full_name', 'email', 'username'];
    $store = user_store();
    $record = $store->get($userId);
    if (!$record) {
        throw new RuntimeException('User not found.');
    }

    $changed = false;
    foreach ($updates as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }

        if ($key === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Approval failed: invalid email address.');
            }
            $record['email'] = strtolower(trim((string) $value));
            $changed = true;
        } elseif ($key === 'username') {
            $candidate = strtolower(trim((string) $value));
            if (!preg_match('/^[a-z0-9._-]{3,}$/', $candidate)) {
                throw new RuntimeException('Approval failed: invalid username.');
            }
            $record['username'] = $candidate;
            $changed = true;
        } else {
            $record['full_name'] = trim((string) $value);
            $changed = true;
        }
    }

    if (!$changed) {
        return;
    }

    $updated = $store->save($record);

    $store->appendAudit([
        'event' => 'admin_apply_profile_updates',
        'actor_id' => $actorId,
        'user_id' => $userId,
        'fields' => array_values(array_intersect($allowed, array_keys($updates))),
    ]);

    portal_log_action($db, $actorId, 'update', 'user', $userId, 'Profile updates applied via approval');
}

function admin_record_leave(PDO $db, array $payload, int $userId, int $actorId, int $requestId, string $status): void
{
    $start = $payload['start_date'] ?? '';
    $end = $payload['end_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $end)) {
        throw new RuntimeException('Leave dates are invalid.');
    }

    $reason = trim((string) ($payload['reason'] ?? ''));
    $stmt = $db->prepare('INSERT INTO employee_leaves(user_id, start_date, end_date, reason, status, request_id, approved_by, approved_at, created_at, updated_at) VALUES(:user_id, :start_date, :end_date, :reason, :status, :request_id, :approved_by, :approved_at, :created_at, :updated_at)');
    $now = now_ist();
    $stmt->execute([
        ':user_id' => $userId,
        ':start_date' => $start,
        ':end_date' => $end,
        ':reason' => $reason !== '' ? $reason : null,
        ':status' => $status,
        ':request_id' => $requestId,
        ':approved_by' => $actorId,
        ':approved_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ($status === 'approved') {
        portal_log_action($db, $actorId, 'create', 'leave', (int) $db->lastInsertId(), 'Leave request approved');
    }
}

function admin_record_expense(PDO $db, array $payload, int $userId, int $actorId, int $requestId, string $status): void
{
    $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
    if ($amount <= 0) {
        throw new RuntimeException('Expense amount is invalid.');
    }
    $category = trim((string) ($payload['category'] ?? 'General'));
    $description = trim((string) ($payload['description'] ?? ''));
    $now = now_ist();

    $stmt = $db->prepare('INSERT INTO employee_expenses(user_id, amount, category, description, status, request_id, approved_by, approved_at, created_at, updated_at) VALUES(:user_id, :amount, :category, :description, :status, :request_id, :approved_by, :approved_at, :created_at, :updated_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':amount' => $amount,
        ':category' => $category !== '' ? $category : null,
        ':description' => $description !== '' ? $description : null,
        ':status' => $status,
        ':request_id' => $requestId,
        ':approved_by' => $actorId,
        ':approved_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ($status === 'approved') {
        portal_log_action($db, $actorId, 'create', 'expense', (int) $db->lastInsertId(), 'Expense approved via request');
    }
}

function admin_apply_data_correction(PDO $db, array $payload, int $actorId): void
{
    $module = strtolower(trim((string) ($payload['module'] ?? '')));
    $recordId = (int) ($payload['record_id'] ?? 0);
    $field = trim((string) ($payload['field'] ?? ''));
    $value = trim((string) ($payload['value'] ?? ''));
    $details = trim((string) ($payload['details'] ?? ''));

    if ($module === 'lead' && $recordId > 0) {
        $allowed = ['name', 'phone', 'email', 'source', 'site_location', 'notes'];
        if ($field !== '' && in_array($field, $allowed, true)) {
            $sql = 'UPDATE crm_leads SET ' . $field . ' = :value, updated_at = :updated_at WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':value' => $value !== '' ? $value : null,
                ':updated_at' => now_ist(),
                ':id' => $recordId,
            ]);
            portal_log_action($db, $actorId, 'update', 'lead', $recordId, 'Data correction applied via approval');
            return;
        }
    }

    if ($module === 'installation' && $recordId > 0) {
        $allowed = ['customer_name', 'project_reference', 'scheduled_date', 'handover_date'];
        if ($field !== '' && in_array($field, $allowed, true)) {
            $sql = 'UPDATE installations SET ' . $field . ' = :value, updated_at = :updated_at WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':value' => $value !== '' ? $value : null,
                ':updated_at' => now_ist(),
                ':id' => $recordId,
            ]);
            portal_log_action($db, $actorId, 'update', 'installation', $recordId, 'Data correction applied via approval');
            return;
        }
    }

    if ($module === 'complaint' && $recordId > 0) {
        $allowed = ['title', 'description', 'customer_name', 'customer_contact'];
        if ($field !== '' && in_array($field, $allowed, true)) {
            $sql = 'UPDATE complaints SET ' . $field . ' = :value, updated_at = :updated_at WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':value' => $value !== '' ? $value : null,
                ':updated_at' => now_ist(),
                ':id' => $recordId,
            ]);
            portal_log_action($db, $actorId, 'update', 'complaint', $recordId, 'Data correction applied via approval');
            return;
        }
    }

    if ($details === '') {
        throw new RuntimeException('Unable to auto-apply this correction. Please update the record manually.');
    }
}

function admin_decide_request(PDO $db, int $requestId, string $decision, int $actorId, string $note = ''): array
{
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new RuntimeException('Unsupported decision.');
    }

    $request = approval_request_fetch($db, $requestId);
    if ($request['status'] !== 'pending') {
        throw new RuntimeException('Only pending requests can be actioned.');
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $payload = $request['payload'] ?? [];
    $result = null;

    $db->beginTransaction();
    try {
        switch ($request['type']) {
            case 'profile_edit':
                if ($decision === 'approve') {
                    $updates = is_array($payload['updates'] ?? null) ? $payload['updates'] : [];
                    admin_apply_profile_updates($db, $updates, (int) $request['requestedBy'], $actorId);
                }
                $result = admin_fetch_user($db, (int) $request['requestedBy']);
                break;
            case 'leave':
                admin_record_leave($db, is_array($payload) ? $payload : [], (int) $request['requestedBy'], $actorId, $requestId, $status);
                $result = employee_list_requests($db, (int) $request['requestedBy']);
                break;
            case 'expense':
                admin_record_expense($db, is_array($payload) ? $payload : [], (int) $request['requestedBy'], $actorId, $requestId, $status);
                $result = employee_list_requests($db, (int) $request['requestedBy']);
                break;
            case 'data_correction':
                if ($decision === 'approve') {
                    admin_apply_data_correction($db, is_array($payload) ? $payload : [], $actorId);
                }
                break;
            case 'lead_conversion':
                $proposalId = isset($payload['proposal_id']) ? (int) $payload['proposal_id'] : 0;
                if ($proposalId <= 0) {
                    throw new RuntimeException('Proposal reference missing.');
                }
                if ($decision === 'approve') {
                    $result = admin_approve_lead_proposal($db, $proposalId, $actorId);
                } else {
                    $result = admin_reject_lead_proposal($db, $proposalId, $actorId, $note);
                }
                break;
            case 'installation_commissioning':
                $installationId = $request['targetId'] ?? ($payload['installation_id'] ?? 0);
                $installationId = (int) $installationId;
                if ($installationId <= 0) {
                    throw new RuntimeException('Installation reference missing.');
                }
                if ($decision === 'approve') {
                    $result = installation_approve_commissioning($db, $installationId, $actorId, $note);
                } else {
                    $result = installation_reject_commissioning_request($db, $installationId, $actorId, $note);
                }
                break;
            case 'complaint_resolved':
                $reference = (string) ($payload['reference'] ?? '');
                if ($reference === '') {
                    throw new RuntimeException('Complaint reference missing.');
                }
                if ($decision === 'approve') {
                    $result = portal_admin_update_complaint_status($db, $reference, 'resolved', $actorId);
                }
                break;
            case 'reminder_proposal':
                $reminderId = $request['targetId'] ?? ($payload['reminder_id'] ?? 0);
                $reminderId = (int) $reminderId;
                if ($reminderId <= 0) {
                    throw new RuntimeException('Reminder reference missing.');
                }
                if ($decision === 'approve') {
                    $result = admin_update_reminder_status($db, $reminderId, 'active', $actorId);
                } else {
                    $result = admin_update_reminder_status($db, $reminderId, 'cancelled', $actorId, $note);
                }
                break;
            default:
                throw new RuntimeException('Unsupported request type.');
        }

        $updated = approval_request_finalize($db, $requestId, $status, $actorId, $note);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return [
        'request' => $updated,
        'result' => $result,
        'counts' => admin_overview_counts($db),
    ];
}

function employee_bootstrap_payload(PDO $db, int $userId): array
{
    return [
        'tasks' => portal_list_tasks($db, $userId),
        'complaints' => portal_employee_complaints($db, $userId),
        'documents' => portal_list_documents($db, 'employee', $userId),
        'notifications' => portal_list_notifications($db, $userId, 'employee'),
        'reminders' => employee_list_reminders($db, $userId),
        'requests' => employee_list_requests($db, $userId),
        'sync' => portal_latest_sync($db, $userId),
    ];
}

function generate_complaint_reference(PDO $db): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $year = $now->format('Y');
    $prefix = sprintf('CMP-%s-', $year);

    $stmt = $db->prepare('SELECT reference FROM complaints WHERE reference LIKE :prefix ORDER BY reference DESC LIMIT 1');
    $stmt->execute([':prefix' => $prefix . '%']);
    $lastReference = (string) $stmt->fetchColumn();

    $sequence = 0;
    if ($lastReference !== '') {
        $parts = explode('-', $lastReference);
        $candidate = end($parts);
        if (is_string($candidate) && ctype_digit($candidate)) {
            $sequence = (int) $candidate;
        }
    }

    do {
        $sequence++;
        $reference = sprintf('%s%04d', $prefix, $sequence);
        $check = $db->prepare('SELECT 1 FROM complaints WHERE reference = :reference LIMIT 1');
        $check->execute([':reference' => $reference]);
    } while ($check->fetchColumn());

    return $reference;
}

function portal_record_complaint_event(
    PDO $db,
    int $complaintId,
    ?int $actorId,
    string $entryType,
    string $summary,
    ?string $details = null,
    ?int $documentId = null,
    ?string $statusTo = null
): void {
    $type = in_array($entryType, ['status', 'note', 'document', 'assignment'], true) ? $entryType : 'note';
    $stmt = $db->prepare('INSERT INTO complaint_updates(complaint_id, actor_id, entry_type, summary, details, document_id, status_to, created_at) VALUES(:complaint_id, :actor_id, :entry_type, :summary, :details, :document_id, :status_to, :created_at)');
    $stmt->execute([
        ':complaint_id' => $complaintId,
        ':actor_id' => $actorId ?: null,
        ':entry_type' => $type,
        ':summary' => $summary,
        ':details' => $details,
        ':document_id' => $documentId,
        ':status_to' => $statusTo,
        ':created_at' => now_ist(),
    ]);
}

function portal_fetch_complaint_updates(PDO $db, array $complaintIds): array
{
    if (empty($complaintIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($complaintIds), '?'));
    $stmt = $db->prepare("SELECT cu.*, users.full_name AS actor_name, portal_documents.name AS document_name, portal_documents.reference AS document_reference FROM complaint_updates cu LEFT JOIN users ON cu.actor_id = users.id LEFT JOIN portal_documents ON cu.document_id = portal_documents.id WHERE cu.complaint_id IN ($placeholders) ORDER BY cu.created_at ASC");
    $stmt->execute(array_map('intval', $complaintIds));

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $complaintId = (int) $row['complaint_id'];
        $entry = [
            'type' => $row['entry_type'] ?? 'note',
            'summary' => $row['summary'] ?? '',
            'details' => $row['details'] ?? '',
            'status' => $row['status_to'] ?? '',
            'actor' => $row['actor_name'] ?? 'System',
            'time' => $row['created_at'] ?? '',
        ];
        if (!empty($row['document_id'])) {
            $entry['document'] = [
                'id' => (int) $row['document_id'],
                'name' => $row['document_name'] ?? '',
                'reference' => $row['document_reference'] ?? '',
            ];
        }
        $grouped[$complaintId][] = $entry;
    }

    return $grouped;
}

function portal_normalize_complaint_rows(PDO $db, array $rows): array
{
    if (empty($rows)) {
        return [];
    }

    $complaintIds = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $complaintIds[] = (int) $row['id'];
        }
    }

    $timelines = portal_fetch_complaint_updates($db, $complaintIds);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $results = [];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $slaDueRaw = $row['sla_due_at'] ?? '';
        $slaStatus = 'unset';
        $slaLabel = 'SLA not set';
        $slaDueFormatted = $slaDueRaw ?: '';
        $originRaw = strtolower(trim((string) ($row['origin'] ?? 'admin')));
        if (!in_array($originRaw, ['admin', 'customer'], true)) {
            $originRaw = 'admin';
        }
        if ($slaDueRaw) {
            try {
                $due = new DateTimeImmutable($slaDueRaw, new DateTimeZone('Asia/Kolkata'));
                $diffDays = (int) $now->diff($due)->format('%r%a');
                if ($diffDays < 0) {
                    $slaStatus = 'overdue';
                    $slaLabel = sprintf('Overdue by %d day%s', abs($diffDays), abs($diffDays) === 1 ? '' : 's');
                } elseif ($diffDays <= 1) {
                    $slaStatus = 'due_soon';
                    $slaLabel = sprintf('Due in %d day%s', $diffDays, $diffDays === 1 ? '' : 's');
                } else {
                    $slaStatus = 'on_track';
                    $slaLabel = sprintf('Due in %d days', $diffDays);
                }
                $slaDueFormatted = $due->format('Y-m-d');
            } catch (Throwable $exception) {
                unset($exception);
            }
        }

        $createdAtRaw = $row['created_at'] ?? '';
        $ageDays = null;
        if ($createdAtRaw) {
            try {
                $created = new DateTimeImmutable($createdAtRaw, new DateTimeZone('Asia/Kolkata'));
                $ageDays = (int) $created->diff($now)->format('%a');
            } catch (Throwable $exception) {
                unset($exception);
            }
        }

        $results[] = [
            'id' => $id,
            'reference' => (string) ($row['reference'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => $row['description'] ?? '',
            'customerName' => (string) ($row['customer_name'] ?? ''),
            'customerContact' => (string) ($row['customer_contact'] ?? ''),
            'origin' => $originRaw,
            'priority' => $row['priority'] ?? 'medium',
            'status' => $row['status'] ?? 'intake',
            'statusLabel' => complaint_status_label($row['status'] ?? ''),
            'assignedTo' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
            'assigneeName' => $row['assigned_to_name'] ?? '',
            'assigneeRole' => isset($row['assigned_role']) && $row['assigned_role'] !== null ? portal_role_label((string) $row['assigned_role']) : '',
            'slaDue' => $slaDueFormatted,
            'slaStatus' => $slaStatus,
            'slaLabel' => $slaLabel,
            'createdAt' => $row['created_at'] ?? '',
            'updatedAt' => $row['updated_at'] ?? '',
            'timeline' => $timelines[$id] ?? [],
            'ageDays' => $ageDays,
        ];
    }

    return $results;
}

function portal_normalize_complaint_row(PDO $db, array $row): array
{
    $normalized = portal_normalize_complaint_rows($db, [$row]);
    return $normalized[0] ?? [];
}

function portal_employee_complaints(PDO $db, int $userId): array
{
    $complaints = portal_all_complaints($db);
    foreach ($complaints as &$complaint) {
        $assignee = $complaint['assignedTo'] ?? null;
        $complaint['assignedToMe'] = $assignee !== null && (int) $assignee === $userId;
    }
    unset($complaint);

    return $complaints;
}

function portal_all_complaints(PDO $db): array
{
    $stmt = $db->query('SELECT complaints.*, users.full_name AS assigned_to_name, roles.name AS assigned_role FROM complaints LEFT JOIN users ON complaints.assigned_to = users.id LEFT JOIN roles ON users.role_id = roles.id ORDER BY complaints.created_at DESC');
    return portal_normalize_complaint_rows($db, $stmt->fetchAll());
}

function portal_save_complaint(PDO $db, array $input, int $actorId): array
{
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Complaint title is required.');
    }

    $description = trim((string) ($input['description'] ?? ''));
    $priority = strtolower(trim((string) ($input['priority'] ?? 'medium')));
    $validPriorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $validPriorities, true)) {
        $priority = 'medium';
    }

    $origin = strtolower(trim((string) ($input['origin'] ?? 'admin')));
    if (!in_array($origin, ['admin', 'customer'], true)) {
        $origin = 'admin';
    }

    $customerName = trim((string) ($input['customerName'] ?? ($input['customer_name'] ?? '')));
    $customerContact = trim((string) ($input['customerContact'] ?? ($input['customer_contact'] ?? '')));

    $status = strtolower(trim((string) ($input['status'] ?? 'intake')));
    $validStatuses = ['intake', 'triage', 'work', 'resolved', 'closed'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'intake';
    }

    if ($status === 'closed') {
        $actor = $actorId > 0 ? portal_find_user($db, $actorId) : null;
        if (!$actor || canonical_role_name($actor['role_name'] ?? '') !== 'admin') {
            $status = 'resolved';
        }
    }

    $assignedCandidate = $input['assignedTo'] ?? ($input['assigned_to'] ?? null);
    $assignedTo = null;
    if ($assignedCandidate !== null && $assignedCandidate !== '') {
        $assignedTo = (int) $assignedCandidate;
        if ($assignedTo > 0) {
            portal_ensure_employee($db, $assignedTo);
        } else {
            $assignedTo = null;
        }
    }

    $slaDue = trim((string) ($input['slaDue'] ?? ($input['sla_due'] ?? '')));
    if ($slaDue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slaDue)) {
        throw new RuntimeException('SLA due date must follow the YYYY-MM-DD format.');
    }
    $slaValue = $slaDue !== '' ? $slaDue : null;

    $reference = trim((string) ($input['reference'] ?? ''));
    $reference = $reference !== '' ? strtoupper($reference) : '';

    $existing = null;
    if ($reference !== '') {
        try {
            $existing = portal_fetch_complaint_row($db, $reference);
        } catch (Throwable $exception) {
            $existing = null;
        }
    }

    $now = now_ist();

    if ($existing === null) {
        if ($reference === '') {
            $reference = generate_complaint_reference($db);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM complaints WHERE reference = :reference LIMIT 1');
            $stmt->execute([':reference' => $reference]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Complaint reference already exists.');
            }
        }

        $stmt = $db->prepare('INSERT INTO complaints(reference, title, description, customer_name, customer_contact, origin, priority, status, assigned_to, sla_due_at, created_at, updated_at) VALUES(:reference, :title, :description, :customer_name, :customer_contact, :origin, :priority, :status, :assigned_to, :sla_due_at, :created_at, :updated_at)');
        $stmt->execute([
            ':reference' => $reference,
            ':title' => $title,
            ':description' => $description !== '' ? $description : null,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':customer_contact' => $customerContact !== '' ? $customerContact : null,
            ':origin' => $origin,
            ':priority' => $priority,
            ':status' => $status,
            ':assigned_to' => $assignedTo,
            ':sla_due_at' => $slaValue,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $complaintId = (int) $db->lastInsertId();
        portal_log_action($db, $actorId, 'create', 'complaint', $complaintId, 'Complaint logged');
        $summary = 'Complaint created';
        $details = $description !== '' ? $description : null;
        portal_record_complaint_event($db, $complaintId, $actorId, 'status', $summary, $details, null, $status);

        if ($assignedTo !== null) {
            $assignee = portal_find_user($db, $assignedTo);
            $assigneeLabel = $assignee['full_name'] ?? ('user #' . $assignedTo);
            $assignmentNote = $slaValue !== null ? sprintf('SLA due on %s', $slaValue) : null;
            portal_record_complaint_event($db, $complaintId, $actorId, 'assignment', 'Assigned to ' . $assigneeLabel, $assignmentNote);
        }

        return portal_get_complaint($db, $reference);
    }

    $complaintId = (int) ($existing['id'] ?? 0);
    $previousStatus = (string) ($existing['status'] ?? 'intake');
    $previousAssignee = $existing['assigned_to'] ?? null;
    $previousSla = $existing['sla_due_at'] ?? null;

    $stmt = $db->prepare('UPDATE complaints SET title = :title, description = :description, customer_name = :customer_name, customer_contact = :customer_contact, origin = :origin, priority = :priority, status = :status, assigned_to = :assigned_to, sla_due_at = :sla_due_at, updated_at = :updated_at WHERE reference = :reference');
    $stmt->execute([
        ':title' => $title,
        ':description' => $description !== '' ? $description : null,
        ':customer_name' => $customerName !== '' ? $customerName : null,
        ':customer_contact' => $customerContact !== '' ? $customerContact : null,
        ':origin' => $origin,
        ':priority' => $priority,
        ':status' => $status,
        ':assigned_to' => $assignedTo,
        ':sla_due_at' => $slaValue,
        ':updated_at' => $now,
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'update', 'complaint', $complaintId, 'Complaint updated');

    if ($previousStatus !== $status) {
        portal_record_complaint_event(
            $db,
            $complaintId,
            $actorId,
            'status',
            sprintf('Status updated to %s', strtoupper($status)),
            null,
            null,
            $status
        );
    }

    $assigneeChanged = ($previousAssignee !== null ? (int) $previousAssignee : null) !== $assignedTo;
    $slaChanged = $previousSla !== $slaValue;
    if ($assigneeChanged || $slaChanged) {
        $assigneeLabel = 'Unassigned';
        if ($assignedTo !== null) {
            $assignee = portal_find_user($db, $assignedTo);
            $assigneeLabel = $assignee['full_name'] ?? ('user #' . $assignedTo);
        }
        $note = [];
        if ($slaValue !== null) {
            $note[] = sprintf('SLA due on %s', $slaValue);
        }
        if ($previousSla !== null && $slaValue === null) {
            $note[] = 'SLA cleared';
        }
        $details = empty($note) ? null : implode('; ', $note);
        portal_record_complaint_event($db, $complaintId, $actorId, 'assignment', 'Assigned to ' . $assigneeLabel, $details);
    }

    return portal_get_complaint($db, $reference);
}

function portal_admin_update_complaint_status(PDO $db, string $reference, string $status, int $actorId): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }

    $status = strtolower(trim($status));
    $valid = ['intake', 'triage', 'work', 'resolved', 'closed'];
    if (!in_array($status, $valid, true)) {
        throw new RuntimeException('Unsupported complaint status.');
    }

    $row = portal_fetch_complaint_row($db, $reference);

    if ($status === ($row['status'] ?? '')) {
        return portal_normalize_complaint_row($db, $row);
    }

    if ($status === 'closed') {
        $actor = $actorId > 0 ? portal_find_user($db, $actorId) : null;
        if (!$actor || canonical_role_name($actor['role_name'] ?? '') !== 'admin') {
            throw new RuntimeException('Only administrators can close complaints.');
        }
    }

    $stmt = $db->prepare('UPDATE complaints SET status = :status, updated_at = :updated_at, assigned_to = CASE WHEN :status = \'triage\' THEN NULL ELSE assigned_to END WHERE reference = :reference');
    $stmt->execute([
        ':status' => $status,
        ':updated_at' => now_ist(),
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'status_change', 'complaint', (int) $row['id'], 'Complaint status updated to ' . $status);
    portal_record_complaint_event(
        $db,
        (int) $row['id'],
        $actorId,
        'status',
        sprintf('Status updated to %s', strtoupper($status)),
        null,
        null,
        $status
    );

    if ($status === 'resolved') {
        approval_request_sync_by_target($db, 'complaint_resolved', 'complaint', (int) $row['id'], 'approved', $actorId, null);
    }

    return portal_get_complaint($db, $reference);
}

function admin_overview_counts(PDO $db): array
{
    $activeEmployees = (int) $db->query("SELECT COUNT(*) FROM users INNER JOIN roles ON users.role_id = roles.id WHERE roles.name = 'employee' AND users.status = 'active'")->fetchColumn();
    $newLeads = (int) $db->query("SELECT COUNT(*) FROM crm_leads WHERE status = 'new'")->fetchColumn();
    $activeReminders = (int) $db->query("SELECT COUNT(*) FROM reminders WHERE status IN ('proposed','active') AND deleted_at IS NULL")->fetchColumn();
    $activeReferrers = (int) $db->query("SELECT COUNT(*) FROM referrers WHERE status = 'active'")->fetchColumn();

    return [
        'employees' => $activeEmployees,
        'leads' => $newLeads,
        'reminders' => $activeReminders,
        'referrers' => $activeReferrers,
    ];
}

function installation_stage_keys(): array
{
    return ['structure', 'wiring', 'testing', 'meter', 'commissioned'];
}

function installation_stage_label(string $stage): string
{
    $map = [
        'structure' => 'Structure',
        'wiring' => 'Wiring',
        'testing' => 'Testing',
        'meter' => 'Meter',
        'commissioned' => 'Commissioned',
    ];

    $normalized = strtolower(trim($stage));

    return $map[$normalized] ?? ucfirst($normalized ?: 'Structure');
}

function installation_stage_index(string $stage): int
{
    $stages = installation_stage_keys();
    $normalized = strtolower(trim($stage));
    $index = array_search($normalized, $stages, true);

    return $index === false ? 0 : (int) $index;
}

function installation_stage_progress(string $stage): array
{
    $currentIndex = installation_stage_index($stage);
    $steps = [];

    foreach (installation_stage_keys() as $index => $key) {
        $steps[] = [
            'key' => $key,
            'label' => installation_stage_label($key),
            'state' => $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming'),
        ];
    }

    return $steps;
}

function installation_stage_tone(string $stage): string
{
    return match (strtolower(trim($stage))) {
        'commissioned' => 'success',
        'meter' => 'positive',
        'testing' => 'warning',
        'wiring' => 'info',
        default => 'progress',
    };
}

function installation_decode_stage_entries(?string $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $entries = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $stage = strtolower(trim((string) ($item['stage'] ?? 'structure')));
        $entries[] = [
            'id' => (string) ($item['id'] ?? ''),
            'stage' => $stage,
            'stageLabel' => installation_stage_label($stage),
            'type' => (string) ($item['type'] ?? 'note'),
            'remarks' => (string) ($item['remarks'] ?? ''),
            'photo' => (string) ($item['photo'] ?? ''),
            'actorId' => isset($item['actorId']) ? (int) $item['actorId'] : null,
            'actorName' => (string) ($item['actorName'] ?? ''),
            'actorRole' => (string) ($item['actorRole'] ?? ''),
            'timestamp' => (string) ($item['timestamp'] ?? ''),
        ];
    }

    usort($entries, static fn (array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp']));

    return $entries;
}

function installation_actor_details(PDO $db, int $actorId): array
{
    $user = portal_find_user($db, $actorId);
    if (!$user) {
        return [
            'id' => $actorId,
            'name' => 'User #' . $actorId,
            'role' => 'User',
        ];
    }

    $roleName = (string) ($user['role_name'] ?? '');

    return [
        'id' => (int) $user['id'],
        'name' => (string) ($user['full_name'] ?? ('User #' . $actorId)),
        'role' => portal_role_label($roleName),
    ];
}

function installation_append_stage_entry(PDO $db, array $row, array $entry): array
{
    $entries = installation_decode_stage_entries($row['stage_entries'] ?? '[]');
    $entries[] = $entry;

    $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
    $timestamp = now_ist();

    $db->prepare('UPDATE installations SET stage_entries = :entries, updated_at = :updated_at WHERE id = :id')->execute([
        ':entries' => $encoded,
        ':updated_at' => $timestamp,
        ':id' => (int) $row['id'],
    ]);

    $row['stage_entries'] = $encoded;
    $row['updated_at'] = $timestamp;

    return $row;
}

function installation_stage_max_for_role(string $role): string
{
    $canonical = strtolower(trim($role));

    return match ($canonical) {
        'admin' => 'commissioned',
        default => 'meter',
    };
}

function installation_role_allowed_stage_updates(string $role): array
{
    $canonical = strtolower(trim($role));

    return match ($canonical) {
        'installer' => ['structure', 'wiring', 'meter'],
        default => installation_stage_keys(),
    };
}

function installation_role_can_finalize(string $role): bool
{
    return strtolower(trim($role)) === 'admin';
}

function installation_fetch(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT installations.*, emp.full_name AS employee_name, inst.full_name AS installer_name, req.full_name AS requested_by_name FROM installations
        LEFT JOIN users emp ON installations.assigned_to = emp.id
        LEFT JOIN users inst ON installations.installer_id = inst.id
        LEFT JOIN users req ON installations.requested_by = req.id
        WHERE installations.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Installation not found.');
    }

    return $row;
}

function installation_stage_transition_allowed(string $role, string $currentStage, string $targetStage): bool
{
    $currentIndex = installation_stage_index($currentStage);
    $targetIndex = installation_stage_index($targetStage);
    $maxIndex = installation_stage_index(installation_stage_max_for_role($role));
    $allowedStages = installation_role_allowed_stage_updates($role);

    if ($targetIndex > $maxIndex) {
        return false;
    }

    if (!in_array($targetStage, $allowedStages, true) && $targetStage !== $currentStage) {
        return false;
    }

    if ($targetIndex < $currentIndex && strtolower(trim($role)) !== 'admin') {
        return false;
    }

    return true;
}

function enforce_installation_access(PDO $db, int $installationId, int $employeeId): void
{
    $installation = installation_fetch($db, $installationId);
    if (empty($installation)) {
        throw new RuntimeException('Installation not found.');
    }
    if ((int) ($installation['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this installation.');
    }
}

function enforce_task_access(PDO $db, int $taskId, int $employeeId): void
{
    $stmt = $db->prepare('SELECT assignee_id FROM portal_tasks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $assigneeId = $stmt->fetchColumn();
    if ($assigneeId === false) {
        throw new RuntimeException('Task not found.');
    }
    if ((int) $assigneeId !== $employeeId) {
        throw new RuntimeException('You are not assigned to this task.');
    }
}

function employee_upload_installation_photo(PDO $db, int $employeeId, array $input): array
{
    $installationId = (int) ($input['installation_id'] ?? 0);
    if ($installationId <= 0) {
        throw new RuntimeException('Installation reference is required.');
    }
    enforce_installation_access($db, $installationId, $employeeId);

    $photo = lead_extract_file_upload($input['photo'] ?? null, ['image/jpeg', 'image/png', 'image/gif'], 5 * 1024 * 1024);
    if (!$photo) {
        throw new RuntimeException('Photo is required.');
    }

    $actor = installation_actor_details($db, $employeeId);
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'stage' => strtolower(trim((string) ($input['stage'] ?? ''))),
        'type' => 'note',
        'remarks' => trim((string) ($input['remarks'] ?? '')),
        'photo' => $photo['name'],
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => now_ist(),
    ];

    $installation = installation_fetch($db, $installationId);
    return installation_append_stage_entry($db, $installation, $entry);
}

function employee_add_installation_note(PDO $db, int $installationId, string $note, int $employeeId): array
{
    enforce_installation_access($db, $installationId, $employeeId);
    $installation = installation_fetch($db, $installationId);
    $actor = installation_actor_details($db, $employeeId);
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'stage' => strtolower(trim((string) ($installation['stage'] ?? 'structure'))),
        'type' => 'note',
        'remarks' => $note,
        'photo' => '',
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => now_ist(),
    ];
    return installation_append_stage_entry($db, $installation, $entry);
}

function employee_generate_installation_completion_note(PDO $db, int $installationId, int $employeeId): array
{
    enforce_installation_access($db, $installationId, $employeeId);
    $installation = installation_fetch($db, $installationId);
    $installation_details = installation_normalize_row($db, $installation, 'employee');
    $actor = installation_actor_details($db, $employeeId);

    $completion_note = "Internal Installation Completion Note\n";
    $completion_note .= "------------------------------------\n";
    $completion_note .= "Project: " . $installation_details['project'] . "\n";
    $completion_note .= "Customer: " . $installation_details['customer'] . "\n";
    $completion_note .= "Capacity: " . $installation_details['capacity'] . " kWp\n";
    $completion_note .= "Handover Date: " . $installation_details['handover'] . "\n";
    $completion_note .= "Generated by: " . $actor['name'] . " on " . now_ist() . "\n";

    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'stage' => strtolower(trim((string) ($installation['stage'] ?? 'structure'))),
        'type' => 'note',
        'remarks' => $completion_note,
        'photo' => '',
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => now_ist(),
    ];
    return installation_append_stage_entry($db, $installation, $entry);
}

function installation_stage_requires_approval(string $role, string $targetStage): bool
{
    if (installation_role_can_finalize($role)) {
        return false;
    }

    return strtolower(trim($targetStage)) === 'commissioned';
}

function installation_update_stage(
    PDO $db,
    int $installationId,
    string $targetStage,
    int $actorId,
    string $actorRole,
    string $remarks = '',
    string $photoLabel = ''
): array {
    $row = installation_fetch($db, $installationId);
    $currentStage = strtolower(trim((string) ($row['stage'] ?? 'structure')));
    $targetStage = strtolower(trim($targetStage));

    if (!in_array($targetStage, installation_stage_keys(), true)) {
        throw new RuntimeException('Unsupported installation stage.');
    }

    $actor = installation_actor_details($db, $actorId);
    $now = now_ist();

    $logId = bin2hex(random_bytes(8));
    $entry = [
        'id' => $logId,
        'stage' => $targetStage,
        'type' => 'note',
        'remarks' => $remarks,
        'photo' => $photoLabel,
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => $now,
    ];

    $requiresApproval = installation_stage_requires_approval($actorRole, $targetStage);

    if ($requiresApproval) {
        $db->prepare('UPDATE installations SET requested_stage = :requested_stage, requested_by = :requested_by, requested_at = :requested_at, updated_at = :updated_at WHERE id = :id')->execute([
            ':requested_stage' => $targetStage,
            ':requested_by' => $actor['id'],
            ':requested_at' => $now,
            ':updated_at' => $now,
            ':id' => $installationId,
        ]);

        $entry['type'] = 'request';
        $row['requested_stage'] = $targetStage;
        $row['requested_by'] = $actor['id'];
        $row['requested_at'] = $now;

        $label = $row['project_reference'] ?: ($row['customer_name'] ?? ('Installation #' . $installationId));
        approval_request_register(
            $db,
            'installation_commissioning',
            $actor['id'],
            'Commissioning approval for ' . $label,
            [
                'installation_id' => $installationId,
                'requested_stage' => $targetStage,
            ],
            'installation',
            $installationId,
            $remarks
        );
    } elseif ($targetStage !== $currentStage) {
        if (!installation_stage_transition_allowed($actorRole, $currentStage, $targetStage)) {
            throw new RuntimeException('Stage change is not permitted.');
        }

        $status = $targetStage === 'commissioned' ? 'completed' : 'in_progress';

        $db->prepare('UPDATE installations SET stage = :stage, status = :status, requested_stage = NULL, requested_by = NULL, requested_at = NULL, updated_at = :updated_at WHERE id = :id')->execute([
            ':stage' => $targetStage,
            ':status' => $status,
            ':updated_at' => $now,
            ':id' => $installationId,
        ]);

        $row['stage'] = $targetStage;
        $row['status'] = $status;
        $row['requested_stage'] = null;
        $row['requested_by'] = null;
        $row['requested_at'] = null;
        $entry['type'] = 'stage';

        if ($targetStage === 'commissioned') {
            approval_request_sync_by_target($db, 'installation_commissioning', 'installation', $installationId, 'approved', $actor['id'], $remarks);
        }
    }

    if ($remarks !== '' || $photoLabel !== '' || $entry['type'] !== 'note') {
        $row = installation_append_stage_entry($db, $row, $entry);
    }

    return $row;
}

function installation_approve_commissioning(PDO $db, int $installationId, int $actorId, string $remarks = ''): array
{
    $row = installation_fetch($db, $installationId);
    $requestedStage = strtolower(trim((string) ($row['requested_stage'] ?? '')));
    if ($requestedStage !== 'commissioned') {
        throw new RuntimeException('No commissioning request is pending.');
    }

    return installation_update_stage($db, $installationId, 'commissioned', $actorId, 'admin', $remarks);
}

function installation_reject_commissioning_request(PDO $db, int $installationId, int $actorId, string $remarks = ''): array
{
    $row = installation_fetch($db, $installationId);
    $requestedStage = strtolower(trim((string) ($row['requested_stage'] ?? '')));
    if ($requestedStage !== 'commissioned') {
        throw new RuntimeException('No commissioning request is pending.');
    }

    $actor = installation_actor_details($db, $actorId);
    $now = now_ist();

    $db->prepare('UPDATE installations SET requested_stage = NULL, requested_by = NULL, requested_at = NULL, updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => $now,
        ':id' => $installationId,
    ]);

    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'stage' => strtolower(trim((string) ($row['stage'] ?? 'structure'))),
        'type' => 'note',
        'remarks' => $remarks !== '' ? $remarks : 'Commissioning request rejected by Admin.',
        'photo' => '',
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => $now,
    ];

    $row['requested_stage'] = null;
    $row['requested_by'] = null;
    $row['requested_at'] = null;
    $row = installation_append_stage_entry($db, $row, $entry);

    portal_log_action($db, $actorId, 'status_change', 'installation', $installationId, 'Commissioning request rejected');
    approval_request_sync_by_target($db, 'installation_commissioning', 'installation', $installationId, 'rejected', $actorId, $remarks);

    return $row;
}

function installation_toggle_amc(PDO $db, int $installationId, bool $committed, int $actorId): array
{
    $row = installation_fetch($db, $installationId);
    $current = (int) ($row['amc_committed'] ?? 0) === 1;
    if ($current === $committed) {
        return $row;
    }

    $db->prepare('UPDATE installations SET amc_committed = :amc, updated_at = :updated_at WHERE id = :id')->execute([
        ':amc' => $committed ? 1 : 0,
        ':updated_at' => now_ist(),
        ':id' => $installationId,
    ]);

    $row['amc_committed'] = $committed ? 1 : 0;

    $actor = installation_actor_details($db, $actorId);
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'stage' => strtolower(trim((string) ($row['stage'] ?? 'structure'))),
        'type' => 'amc',
        'remarks' => $committed ? 'AMC commitment confirmed.' : 'AMC commitment removed.',
        'photo' => '',
        'actorId' => $actor['id'],
        'actorName' => $actor['name'],
        'actorRole' => $actor['role'],
        'timestamp' => now_ist(),
    ];

    installation_append_stage_entry($db, $row, $entry);

    return $row;
}

function installation_normalize_row(PDO $db, array $row, string $role = 'admin'): array
{
    $stage = strtolower(trim((string) ($row['stage'] ?? 'structure')));
    $entries = installation_decode_stage_entries($row['stage_entries'] ?? '[]');
    $progress = installation_stage_progress($stage);
    $requestedStage = strtolower(trim((string) ($row['requested_stage'] ?? '')));

    $maxStage = installation_stage_max_for_role($role);
    $maxIndex = installation_stage_index($maxStage);
    $currentIndex = installation_stage_index($stage);

    $stageOptions = [];
    $hasCurrentStage = false;
    $hasCommissionedOption = false;
    $roleKey = strtolower(trim($role));
    $commissionIndex = installation_stage_index('commissioned');
    $allowedStageUpdates = installation_role_allowed_stage_updates($role);

    foreach (installation_stage_keys() as $key) {
        $index = installation_stage_index($key);
        if ($index < $currentIndex && $roleKey !== 'admin') {
            continue;
        }
        if ($index > $maxIndex && $key !== $stage) {
            continue;
        }
        if ($roleKey !== 'admin' && !in_array($key, $allowedStageUpdates, true) && $key !== $stage) {
            continue;
        }

        $isCurrent = $key === $stage;
        $isLocked = $requestedStage !== '' && $requestedStage !== $stage && $roleKey !== 'admin';

        $stageOptions[] = [
            'value' => $key,
            'label' => installation_stage_label($key),
            'disabled' => $isLocked && !$isCurrent,
        ];

        if ($isCurrent) {
            $hasCurrentStage = true;
        }

        if ($key === 'commissioned') {
            $hasCommissionedOption = true;
        }
    }

    if (!$hasCurrentStage) {
        $stageOptions[] = [
            'value' => $stage,
            'label' => installation_stage_label($stage),
            'disabled' => false,
        ];
    }

    $canRequestCommissioning = $roleKey !== 'admin'
        && $currentIndex < $commissionIndex
        && in_array('commissioned', $allowedStageUpdates, true)
        && ($requestedStage === '' || $requestedStage === $stage);

    if ($canRequestCommissioning && !$hasCommissionedOption) {
        $stageOptions[] = [
            'value' => 'commissioned',
            'label' => 'Request commissioning approval',
            'disabled' => false,
        ];
        $hasCommissionedOption = true;
    }

    return [
        'id' => (int) $row['id'],
        'customer' => (string) ($row['customer_name'] ?? ''),
        'project' => (string) ($row['project_reference'] ?? ''),
        'capacity' => isset($row['capacity_kw']) ? (float) $row['capacity_kw'] : null,
        'stage' => $stage,
        'stageLabel' => installation_stage_label($stage),
        'stageTone' => installation_stage_tone($stage),
        'progress' => $progress,
        'entries' => $entries,
        'amcCommitted' => (int) ($row['amc_committed'] ?? 0) === 1,
        'scheduled' => (string) ($row['scheduled_date'] ?? ''),
        'handover' => (string) ($row['handover_date'] ?? ''),
        'employeeName' => (string) ($row['employee_name'] ?? ''),
        'installerName' => (string) ($row['installer_name'] ?? ''),
        'requestedStage' => $requestedStage,
        'requestedByName' => (string) ($row['requested_by_name'] ?? ''),
        'stageOptions' => $stageOptions,
        'stageLocked' => $requestedStage !== '' && $requestedStage !== $stage,
        'updated' => (string) ($row['updated_at'] ?? ''),
        'created' => (string) ($row['created_at'] ?? ''),
    ];
}

function installation_list_for_role(PDO $db, string $role, ?int $userId = null): array
{
    $roleKey = strtolower(trim($role));
    $params = [];
    $conditions = [];

    if ($roleKey === 'employee' && $userId) {
        $conditions[] = 'installations.assigned_to = :user_id';
        $params[':user_id'] = $userId;
    } elseif ($roleKey === 'installer' && $userId) {
        $conditions[] = 'installations.installer_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $sql = 'SELECT installations.*, emp.full_name AS employee_name, inst.full_name AS installer_name, req.full_name AS requested_by_name FROM installations
        LEFT JOIN users emp ON installations.assigned_to = emp.id
        LEFT JOIN users inst ON installations.installer_id = inst.id
        LEFT JOIN users req ON installations.requested_by = req.id';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY CASE WHEN installations.stage = "commissioned" THEN 1 ELSE 0 END, COALESCE(installations.updated_at, installations.created_at) DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $rows[] = installation_normalize_row($db, $record, $roleKey);
    }

    return $rows;
}

function customer_portal_identifiers(array $user): array
{
    $references = [];
    $names = [];

    $note = trim((string) ($user['permissions_note'] ?? ''));
    if ($note !== '') {
        $tokens = preg_split('/[\n,;]+/', $note) ?: [];
        foreach ($tokens as $token) {
            $candidate = trim((string) $token);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, ':')) {
                [$prefix, $value] = array_map('trim', explode(':', $candidate, 2));
                if (strcasecmp($prefix, 'project') === 0 && $value !== '') {
                    $candidate = $value;
                }
            }
            if ($candidate !== '') {
                $references[] = strtoupper($candidate);
            }
        }
    }

    $username = trim((string) ($user['username'] ?? ''));
    if ($username !== '' && preg_match('/^[A-Za-z0-9._-]{4,}$/', $username) === 1) {
        $references[] = strtoupper($username);
    }

    $fullName = trim((string) ($user['full_name'] ?? ''));
    if ($fullName !== '') {
        $names[] = strtolower($fullName);
    }

    return [
        'references' => array_values(array_unique(array_filter($references, static fn ($value) => $value !== ''))),
        'names' => array_values(array_unique(array_filter($names, static fn ($value) => $value !== ''))),
    ];
}

function customer_portal_installations(PDO $db, array $user): array
{
    $identifiers = customer_portal_identifiers($user);
    $references = $identifiers['references'];
    $names = $identifiers['names'];

    if (empty($references) && empty($names)) {
        return [];
    }

    $conditions = [];
    $params = [];

    if ($references) {
        $placeholders = [];
        foreach ($references as $index => $reference) {
            $key = ':ref_' . $index;
            $placeholders[] = $key;
            $params[$key] = $reference;
        }
        $conditions[] = 'UPPER(installations.project_reference) IN (' . implode(',', $placeholders) . ')';
    }

    if ($names) {
        $nameConditions = [];
        foreach ($names as $index => $name) {
            $key = ':name_' . $index;
            $nameConditions[] = 'LOWER(installations.customer_name) = ' . $key;
            $params[$key] = $name;
        }
        if ($nameConditions) {
            $conditions[] = '(' . implode(' OR ', $nameConditions) . ')';
        }
    }

    if (empty($conditions)) {
        return [];
    }

    $sql = 'SELECT installations.*, emp.full_name AS employee_name, inst.full_name AS installer_name, req.full_name AS requested_by_name FROM installations'
        . ' LEFT JOIN users emp ON installations.assigned_to = emp.id'
        . ' LEFT JOIN users inst ON installations.installer_id = inst.id'
        . ' LEFT JOIN users req ON installations.requested_by = req.id'
        . ' WHERE ' . implode(' OR ', $conditions)
        . ' ORDER BY COALESCE(installations.updated_at, installations.created_at) DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $rows[] = installation_normalize_row($db, $record, 'customer');
    }

    return $rows;
}

function customer_portal_subsidy(PDO $db, array $installations): array
{
    if (empty($installations)) {
        return [];
    }

    $ids = [];
    foreach ($installations as $installation) {
        $id = (int) ($installation['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $key = ':inst_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $sql = 'SELECT installation_id, application_reference, stage, stage_date, notes, id FROM subsidy_tracker'
        . ' WHERE installation_id IN (' . implode(',', $placeholders) . ')'
        . ' ORDER BY stage_date DESC, id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $installationId = (int) ($row['installation_id'] ?? 0);
        if ($installationId <= 0) {
            continue;
        }

        $stage = strtolower((string) ($row['stage'] ?? 'applied'));
        $stageDate = (string) ($row['stage_date'] ?? '');
        $application = trim((string) ($row['application_reference'] ?? ''));

        if (!isset($summary[$installationId])) {
            $summary[$installationId] = [
                'stage' => $stage,
                'stageLabel' => subsidy_stage_label($stage),
                'stageDate' => $stageDate,
                'application' => $application,
                'notes' => (string) ($row['notes'] ?? ''),
            ];
            continue;
        }

        $existing = $summary[$installationId];
        $existingDate = (string) ($existing['stageDate'] ?? '');

        $replace = false;
        if ($stageDate !== '' && ($existingDate === '' || strcmp($stageDate, $existingDate) > 0)) {
            $replace = true;
        } elseif ($stageDate !== '' && $existingDate !== '' && strcmp($stageDate, $existingDate) === 0) {
            $replace = subsidy_stage_order($stage) >= subsidy_stage_order((string) ($existing['stage'] ?? ''));
        } elseif ($stageDate === '' && $existingDate === '') {
            $replace = subsidy_stage_order($stage) >= subsidy_stage_order((string) ($existing['stage'] ?? ''));
        }

        if ($replace) {
            $summary[$installationId] = [
                'stage' => $stage,
                'stageLabel' => subsidy_stage_label($stage),
                'stageDate' => $stageDate,
                'application' => $application,
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }
    }

    return $summary;
}

function installation_admin_filter(PDO $db, string $filter): array
{
    $filter = strtolower(trim($filter));
    $params = [];
    $conditions = [];

    switch ($filter) {
        case 'structure':
        case 'wiring':
        case 'testing':
        case 'meter':
        case 'commissioned':
            $conditions[] = 'installations.stage = :stage';
            $params[':stage'] = $filter;
            break;
        case 'on_hold':
        case 'cancelled':
            $conditions[] = 'installations.status = :status';
            $params[':status'] = $filter;
            break;
        case 'pending_commissioned':
            $conditions[] = "installations.requested_stage = 'commissioned'";
            break;
        case 'ongoing':
            $conditions[] = "installations.stage != 'commissioned'";
            $conditions[] = "installations.status NOT IN ('cancelled')";
            break;
        case 'all':
        default:
            break;
    }

    $sql = 'SELECT installations.*, emp.full_name AS employee_name, inst.full_name AS installer_name, req.full_name AS requested_by_name FROM installations
        LEFT JOIN users emp ON installations.assigned_to = emp.id
        LEFT JOIN users inst ON installations.installer_id = inst.id
        LEFT JOIN users req ON installations.requested_by = req.id';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY CASE WHEN installations.stage = "commissioned" THEN 1 ELSE 0 END, COALESCE(installations.updated_at, installations.created_at) DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $rows[] = installation_normalize_row($db, $record, 'admin');
    }

    return $rows;
}

function format_due_date(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $exception) {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable $inner) {
            return $value;
        }
    }

    return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M · h:i A');
}

function lead_status_label(string $status): string
{
    $map = [
        'new' => 'New',
        'visited' => 'Visited',
        'quotation' => 'Quotation',
        'converted' => 'Converted',
        'lost' => 'Lost',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? ucfirst($normalized ?: 'New');
}


function lead_stage_order(): array
{
    return [
        'new' => 0,
        'visited' => 1,
        'quotation' => 2,
        'converted' => 3,
        'lost' => 4,
    ];
}

function lead_stage_index(string $status): int
{
    $order = lead_stage_order();
    $normalized = strtolower(trim($status));

    return $order[$normalized] ?? 99;
}

function lead_next_stage(string $status): ?string
{
    $sequence = ['new', 'visited', 'quotation', 'converted'];
    $normalized = strtolower(trim($status));
    foreach ($sequence as $index => $stage) {
        if ($stage === $normalized) {
            return $sequence[$index + 1] ?? null;
        }
    }

    return null;
}

function lead_fetch(PDO $db, int $leadId): array
{
    $stmt = $db->prepare('SELECT l.*, r.name AS referrer_name FROM crm_leads l LEFT JOIN referrers r ON l.referrer_id = r.id WHERE l.id = :id LIMIT 1');
    $stmt->execute([':id' => $leadId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Lead not found.');
    }

    return $row;
}

function lead_record_stage_change(PDO $db, int $leadId, string $from, string $to, int $actorId, ?string $note = null): void
{
    $stmt = $db->prepare('INSERT INTO lead_stage_logs(lead_id, actor_id, from_status, to_status, note, created_at) VALUES(:lead_id, :actor_id, :from_status, :to_status, :note, :created_at)');
    $stmt->execute([
        ':lead_id' => $leadId,
        ':actor_id' => $actorId ?: null,
        ':from_status' => $from !== '' ? strtolower($from) : null,
        ':to_status' => strtolower($to),
        ':note' => $note !== null && $note !== '' ? $note : null,
        ':created_at' => now_ist(),
    ]);
}

function lead_has_pending_proposal(PDO $db, int $leadId): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM lead_proposals WHERE lead_id = :lead_id AND status = 'pending'");
    $stmt->execute([':lead_id' => $leadId]);

    return ((int) $stmt->fetchColumn()) > 0;
}

function lead_has_approved_proposal(PDO $db, int $leadId): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM lead_proposals WHERE lead_id = :lead_id AND status = 'approved'");
    $stmt->execute([':lead_id' => $leadId]);

    return ((int) $stmt->fetchColumn()) > 0;
}

function lead_change_stage(PDO $db, int $leadId, string $targetStage, int $actorId, string $actorRole, string $note = ''): array
{
    $allowedStages = array_keys(lead_stage_order());
    $target = strtolower(trim($targetStage));
    if (!in_array($target, $allowedStages, true)) {
        throw new RuntimeException('Unsupported lead stage.');
    }

    $lead = lead_fetch($db, $leadId);
    $current = strtolower((string) ($lead['status'] ?? 'new'));
    if ($current === $target) {
        return $lead;
    }

    if (in_array($current, ['converted', 'lost'], true)) {
        throw new RuntimeException('Finalized leads cannot change stages.');
    }

    $actorRole = strtolower(trim($actorRole));
    if ($actorRole === 'employee') {
        $next = lead_next_stage($current);
        if ($next === null || $next !== $target || $target === 'converted') {
            throw new RuntimeException('Employees can only advance leads to the next stage.');
        }
    } else {
        if ($target === 'converted' && !lead_has_approved_proposal($db, $leadId)) {
            throw new RuntimeException('Approve a proposal before marking the lead as converted.');
        }
        if (in_array($target, ['new', 'visited', 'quotation'], true) && lead_stage_index($target) < lead_stage_index($current)) {
            throw new RuntimeException('Leads cannot be moved backwards in this workflow.');
        }
    }

    $stmt = $db->prepare('UPDATE crm_leads SET status = :status, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':status' => $target,
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    lead_record_stage_change($db, $leadId, $current, $target, $actorId, $note);

    return lead_fetch($db, $leadId);
}

function lead_extract_file_upload(?array $file, array $allowedMime, int $maxBytes): ?array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Failed to upload the file.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($maxBytes > 0 && $size > $maxBytes) {
        throw new RuntimeException('The uploaded file is too large.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid file upload payload.');
    }

    $data = file_get_contents($tmp);
    if ($data === false) {
        throw new RuntimeException('Unable to read uploaded file.');
    }

    $mimeDetector = function_exists('finfo_open') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $mime = $mimeDetector ? ($mimeDetector->file($tmp) ?: '') : '';
    if ($mime === '' && isset($file['type']) && is_string($file['type'])) {
        $mime = $file['type'];
    }
    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('Unsupported file type.');
    }

    $name = trim((string) ($file['name'] ?? 'upload'));
    if ($name === '') {
        $name = 'upload';
    }

    return [
        'name' => $name,
        'mime' => $mime,
        'data' => base64_encode($data),
    ];
}

function lead_fetch_visits_grouped(PDO $db, array $leadIds): array
{
    if (empty($leadIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
    $stmt = $db->prepare(<<<'SQL'
SELECT v.*, u.full_name AS employee_name
FROM lead_visits v
LEFT JOIN users u ON v.employee_id = u.id
WHERE v.lead_id IN ($placeholders)
ORDER BY v.created_at DESC
SQL
    );
    $stmt->execute(array_map('intval', $leadIds));

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $leadId = (int) $row['lead_id'];
        $photoDataUrl = null;
        if (!empty($row['photo_data']) && !empty($row['photo_mime'])) {
            $photoDataUrl = 'data:' . $row['photo_mime'] . ';base64,' . $row['photo_data'];
        }
        $grouped[$leadId][] = [
            'id' => (int) $row['id'],
            'note' => (string) ($row['note'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'employeeId' => $row['employee_id'] !== null ? (int) $row['employee_id'] : null,
            'employeeName' => (string) ($row['employee_name'] ?? ''),
            'photoName' => (string) ($row['photo_name'] ?? ''),
            'photoMime' => (string) ($row['photo_mime'] ?? ''),
            'photoDataUrl' => $photoDataUrl,
        ];
    }

    return $grouped;
}

function lead_fetch_proposals_grouped(PDO $db, array $leadIds): array
{
    if (empty($leadIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
    $stmt = $db->prepare(<<<'SQL'
SELECT p.*, submitter.full_name AS employee_name, approver.full_name AS approved_name
FROM lead_proposals p
LEFT JOIN users submitter ON p.employee_id = submitter.id
LEFT JOIN users approver ON p.approved_by = approver.id
WHERE p.lead_id IN ($placeholders)
ORDER BY p.created_at DESC
SQL
    );
    $stmt->execute(array_map('intval', $leadIds));

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $leadId = (int) $row['lead_id'];
        $documentUrl = null;
        if (!empty($row['document_data']) && !empty($row['document_mime'])) {
            $documentUrl = 'data:' . $row['document_mime'] . ';base64,' . $row['document_data'];
        }
        $grouped[$leadId][] = [
            'id' => (int) $row['id'],
            'summary' => (string) ($row['summary'] ?? ''),
            'estimate' => isset($row['estimate_amount']) ? (float) $row['estimate_amount'] : null,
            'status' => strtolower((string) ($row['status'] ?? 'pending')),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'employeeId' => $row['employee_id'] !== null ? (int) $row['employee_id'] : null,
            'employeeName' => (string) ($row['employee_name'] ?? ''),
            'documentName' => (string) ($row['document_name'] ?? ''),
            'documentMime' => (string) ($row['document_mime'] ?? ''),
            'documentUrl' => $documentUrl,
            'reviewNote' => (string) ($row['review_note'] ?? ''),
            'approvedAt' => (string) ($row['approved_at'] ?? ''),
            'approvedById' => $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
            'approvedByName' => (string) ($row['approved_name'] ?? ''),
        ];
    }

    return $grouped;
}

function lead_hydrate_rows(PDO $db, array $rows): array
{
    if (empty($rows)) {
        return [];
    }

    $leadIds = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $rows);
    $visits = lead_fetch_visits_grouped($db, $leadIds);
    $proposals = lead_fetch_proposals_grouped($db, $leadIds);
    $order = lead_stage_order();

    $hydrated = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $status = strtolower((string) ($row['status'] ?? 'new'));
        $leadProposals = $proposals[$id] ?? [];
        $pending = null;
        $hasPending = false;
        $hasApproved = false;
        foreach ($leadProposals as $proposal) {
            if ($proposal['status'] === 'pending' && $pending === null) {
                $pending = $proposal;
                $hasPending = true;
            }
            if ($proposal['status'] === 'approved') {
                $hasApproved = true;
            }
        }

        $hydrated[] = [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'phone' => trim((string) ($row['phone'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'source' => trim((string) ($row['source'] ?? '')),
            'status' => $status,
            'statusLabel' => lead_status_label($status),
            'stageIndex' => $order[$status] ?? 99,
            'assignedId' => isset($row['assigned_to']) && $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
            'assignedName' => (string) ($row['assigned_name'] ?? ''),
            'createdById' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'createdName' => (string) ($row['created_name'] ?? ''),
            'referrerId' => isset($row['referrer_id']) && $row['referrer_id'] !== null ? (int) $row['referrer_id'] : null,
            'referrerName' => (string) ($row['referrer_name'] ?? ''),
            'siteLocation' => trim((string) ($row['site_location'] ?? '')),
            'siteDetails' => trim((string) ($row['site_details'] ?? '')),
            'notes' => (string) ($row['notes'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'visits' => $visits[$id] ?? [],
            'proposals' => $leadProposals,
            'hasPendingProposal' => $hasPending,
            'pendingProposal' => $pending,
            'hasApprovedProposal' => $hasApproved,
            'latestVisit' => ($visits[$id] ?? [])[0] ?? null,
        ];
    }

    return $hydrated;
}

function referrer_status_label(string $status): string
{
    $map = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'prospect' => 'Prospect',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? 'Unknown';
}

function admin_referrer_status_options(): array
{
    return [
        'active' => 'Active',
        'prospect' => 'Prospect',
        'inactive' => 'Inactive',
    ];
}

function referrer_normalize_payload(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Referrer name is required.');
    }

    $company = trim((string) ($input['company'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address for the referrer.');
    }

    $phone = trim((string) ($input['phone'] ?? ''));
    $status = strtolower(trim((string) ($input['status'] ?? 'active')));
    if (!array_key_exists($status, admin_referrer_status_options())) {
        $status = 'active';
    }
    $notes = trim((string) ($input['notes'] ?? ''));

    return [
        'name' => $name,
        'company' => $company !== '' ? $company : null,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'status' => $status,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function admin_create_referrer(PDO $db, array $input): array
{
    $payload = referrer_normalize_payload($input);
    $now = now_ist();
    $stmt = $db->prepare('INSERT INTO referrers(name, company, email, phone, status, notes, last_lead_at, created_at, updated_at) VALUES(:name, :company, :email, :phone, :status, :notes, NULL, :created_at, :updated_at)');
    $stmt->execute([
        ':name' => $payload['name'],
        ':company' => $payload['company'],
        ':email' => $payload['email'],
        ':phone' => $payload['phone'],
        ':status' => $payload['status'],
        ':notes' => $payload['notes'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $id = (int) $db->lastInsertId();

    return referrer_with_metrics($db, $id);
}

function admin_update_referrer(PDO $db, int $id, array $input): array
{
    referrer_with_metrics($db, $id); // Ensure the record exists before update.
    $payload = referrer_normalize_payload($input);
    $stmt = $db->prepare('UPDATE referrers SET name = :name, company = :company, email = :email, phone = :phone, status = :status, notes = :notes, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':name' => $payload['name'],
        ':company' => $payload['company'],
        ':email' => $payload['email'],
        ':phone' => $payload['phone'],
        ':status' => $payload['status'],
        ':notes' => $payload['notes'],
        ':updated_at' => now_ist(),
        ':id' => $id,
    ]);

    return referrer_with_metrics($db, $id);
}

function referrer_touch_lead(PDO $db, int $id): void
{
    $timestamp = now_ist();
    $stmt = $db->prepare('UPDATE referrers SET last_lead_at = :last_lead_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':last_lead_at' => $timestamp,
        ':updated_at' => $timestamp,
        ':id' => $id,
    ]);
}

function referrer_with_metrics(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM referrers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Referrer not found.');
    }

    $metricsStmt = $db->prepare(<<<'SQL'
SELECT
    COUNT(*) AS total_leads,
    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted_leads,
    SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_leads,
    SUM(CASE WHEN status IN ('new','visited','quotation') THEN 1 ELSE 0 END) AS pipeline_leads,
    MAX(updated_at) AS latest_lead_update
FROM crm_leads
WHERE referrer_id = :id
SQL
    );
    $metricsStmt->execute([':id' => $id]);
    $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int) ($metrics['total_leads'] ?? 0);
    $converted = (int) ($metrics['converted_leads'] ?? 0);
    $pipeline = (int) ($metrics['pipeline_leads'] ?? 0);
    $lost = (int) ($metrics['lost_leads'] ?? 0);

    $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : null;

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'company' => (string) ($row['company'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'statusLabel' => referrer_status_label((string) ($row['status'] ?? 'active')),
        'notes' => (string) ($row['notes'] ?? ''),
        'lastLeadAt' => (string) ($row['last_lead_at'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'metrics' => [
            'total' => $total,
            'converted' => $converted,
            'pipeline' => $pipeline,
            'lost' => $lost,
            'conversionRate' => $conversionRate,
            'latestLeadUpdate' => (string) ($metrics['latest_lead_update'] ?? ''),
        ],
    ];
}

function admin_list_referrers(PDO $db, string $status = 'all'): array
{
    $status = strtolower(trim($status));
    $valid = array_merge(['all'], array_keys(admin_referrer_status_options()));
    if (!in_array($status, $valid, true)) {
        $status = 'all';
    }

    $statusSortExpr = "CASE WHEN r.status = 'active' THEN 1 ELSE 0 END";

    $sql = <<<SQL
SELECT
    r.id,
    r.name,
    r.company,
    r.email,
    r.phone,
    r.status,
    r.notes,
    r.last_lead_at,
    r.created_at,
    r.updated_at,
    COUNT(l.id) AS total_leads,
    SUM(CASE WHEN l.status = 'converted' THEN 1 ELSE 0 END) AS converted_leads,
    SUM(CASE WHEN l.status = 'lost' THEN 1 ELSE 0 END) AS lost_leads,
    SUM(CASE WHEN l.status IN ('new','visited','quotation') THEN 1 ELSE 0 END) AS pipeline_leads,
    MAX(l.updated_at) AS latest_lead_update
FROM referrers r
LEFT JOIN crm_leads l ON l.referrer_id = r.id
%s
GROUP BY
    r.id,
    r.name,
    r.company,
    r.email,
    r.phone,
    r.status,
    r.notes,
    r.last_lead_at,
    r.created_at,
    r.updated_at
ORDER BY {$statusSortExpr} DESC, r.updated_at DESC, LOWER(r.name)
SQL;

    $where = '';
    $params = [];
    if ($status !== 'all') {
        $where = 'WHERE r.status = :status';
        $params[':status'] = $status;
    }

    $stmt = $db->prepare(sprintf($sql, $where));
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $list = [];
    foreach ($rows as $row) {
        $total = (int) ($row['total_leads'] ?? 0);
        $converted = (int) ($row['converted_leads'] ?? 0);
        $lost = (int) ($row['lost_leads'] ?? 0);
        $pipeline = (int) ($row['pipeline_leads'] ?? 0);
        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : null;
        $list[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'statusLabel' => referrer_status_label((string) ($row['status'] ?? 'active')),
            'notes' => (string) ($row['notes'] ?? ''),
            'lastLeadAt' => (string) ($row['last_lead_at'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'metrics' => [
                'total' => $total,
                'converted' => $converted,
                'lost' => $lost,
                'pipeline' => $pipeline,
                'conversionRate' => $conversionRate,
                'latestLeadUpdate' => (string) ($row['latest_lead_update'] ?? ''),
            ],
        ];
    }

    return $list;
}

function admin_referrer_leads(PDO $db, int $referrerId): array
{
    $orderExpr = "CASE l.status WHEN 'new' THEN 0 WHEN 'visited' THEN 1 WHEN 'quotation' THEN 2 WHEN 'converted' THEN 3 WHEN 'lost' THEN 4 ELSE 5 END";
    $stmt = $db->prepare("SELECT l.*, assignee.full_name AS assigned_name, creator.full_name AS created_name, r.name AS referrer_name FROM crm_leads l LEFT JOIN users assignee ON l.assigned_to = assignee.id LEFT JOIN users creator ON l.created_by = creator.id LEFT JOIN referrers r ON l.referrer_id = r.id WHERE l.referrer_id = :referrer_id ORDER BY $orderExpr, COALESCE(l.updated_at, l.created_at) DESC");
    $stmt->execute([':referrer_id' => $referrerId]);

    return lead_hydrate_rows($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function referrer_ensure_profile(PDO $db, array $user): array
{
    $email = trim((string) ($user['email'] ?? ''));
    $name = trim((string) ($user['full_name'] ?? ''));
    $note = trim((string) ($user['permissions_note'] ?? ''));

    $stmt = null;
    if ($email !== '') {
        $stmt = $db->prepare('SELECT * FROM referrers WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute([':email' => $email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record) {
            return referrer_with_metrics($db, (int) $record['id']);
        }
    }

    if ($name !== '') {
        $stmt = $db->prepare('SELECT * FROM referrers WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute([':name' => $name]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record) {
            return referrer_with_metrics($db, (int) $record['id']);
        }
    }

    if ($email === '' && $name === '') {
        throw new RuntimeException('Referrer profile could not be resolved. Please contact the administrator.');
    }

    $now = now_ist();
    $fallbackName = $name !== '' ? $name : 'Referrer #' . ((int) ($user['id'] ?? 0) ?: random_int(1000, 9999));
    $company = '';
    if ($note !== '') {
        $company = $note;
    }

    $insert = $db->prepare('INSERT INTO referrers(name, company, email, phone, status, notes, last_lead_at, created_at, updated_at) VALUES(:name, :company, :email, :phone, :status, :notes, NULL, :created_at, :updated_at)');
    $insert->execute([
        ':name' => $fallbackName,
        ':company' => $company !== '' ? $company : null,
        ':email' => $email !== '' ? $email : null,
        ':phone' => null,
        ':status' => 'active',
        ':notes' => 'Auto-created from referrer portal access on ' . $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $referrerId = (int) $db->lastInsertId();

    return referrer_with_metrics($db, $referrerId);
}

function referrer_submit_lead(PDO $db, array $input, int $referrerId, int $actorId): array
{
    referrer_with_metrics($db, $referrerId);

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Customer name is required.');
    }

    $phoneRaw = trim((string) ($input['phone'] ?? ''));
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    if ($digits !== null && $digits !== '' && strlen($digits) < 6) {
        throw new RuntimeException('Enter a valid contact number (at least 6 digits).');
    }
    $phone = $digits !== null && $digits !== '' ? $digits : ($phoneRaw !== '' ? $phoneRaw : null);

    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $location = trim((string) ($input['site_location'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    $now = now_ist();
    $stmt = $db->prepare('INSERT INTO crm_leads(name, phone, email, source, status, assigned_to, created_by, referrer_id, site_location, site_details, notes, created_at, updated_at) VALUES(:name, :phone, :email, :source, :status, NULL, NULL, :referrer_id, :site_location, NULL, :notes, :created_at, :updated_at)');
    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone !== null && $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':source' => 'Referrer Portal',
        ':status' => 'new',
        ':referrer_id' => $referrerId,
        ':site_location' => $location !== '' ? $location : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $leadId = (int) $db->lastInsertId();

    referrer_touch_lead($db, $referrerId);
    portal_log_action($db, $actorId, 'create', 'lead', $leadId, 'Lead submitted via referrer portal');

    return lead_fetch($db, $leadId);
}

function referrer_portal_leads(PDO $db, int $referrerId): array
{
    $stmt = $db->prepare('SELECT id, name, phone, email, status, created_at, updated_at FROM crm_leads WHERE referrer_id = :referrer_id ORDER BY COALESCE(updated_at, created_at) DESC');
    $stmt->execute([':referrer_id' => $referrerId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'new'));
        $category = match ($status) {
            'converted' => 'converted',
            'lost' => 'rejected',
            default => 'approved',
        };
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'phone' => trim((string) ($row['phone'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'status' => $status,
            'statusLabel' => lead_status_label($status),
            'category' => $category,
            'categoryLabel' => match ($category) {
                'converted' => 'Converted',
                'rejected' => 'Rejected',
                default => 'Approved',
            },
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function admin_unassigned_leads(PDO $db): array
{
    $stmt = $db->query("SELECT id, name FROM crm_leads WHERE referrer_id IS NULL ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 100");
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    return $results;
}

function admin_assign_referrer(PDO $db, int $leadId, ?int $referrerId, int $actorId): array
{
    lead_fetch($db, $leadId);

    if ($referrerId !== null && $referrerId > 0) {
        $referrer = referrer_with_metrics($db, $referrerId);
        if (!$referrer) {
            throw new RuntimeException('Select a valid referrer.');
        }
    }

    $stmt = $db->prepare('UPDATE crm_leads SET referrer_id = :referrer_id, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':referrer_id' => $referrerId ?: null,
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    if ($referrerId) {
        referrer_touch_lead($db, $referrerId);
    }

    portal_log_action($db, $actorId, 'assign', 'lead', $leadId, $referrerId ? 'Lead linked to referrer #' . $referrerId : 'Lead referrer cleared');

    return lead_fetch($db, $leadId);
}

function admin_active_referrers(PDO $db): array
{
    $stmt = $db->query("SELECT id, name FROM referrers WHERE status = 'active' ORDER BY LOWER(name)");
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    return $results;
}

function admin_active_employees(PDO $db): array
{
    $stmt = $db->query("SELECT users.id, users.full_name FROM users INNER JOIN roles ON users.role_id = roles.id WHERE roles.name = 'employee' AND users.status = 'active' ORDER BY users.full_name");
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['full_name'] ?? ''),
        ];
    }

    return $result;
}

function admin_create_lead(PDO $db, array $input, int $actorId): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Lead name is required.');
    }

    $phone = trim((string) ($input['phone'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $source = trim((string) ($input['source'] ?? ''));
    $siteLocation = trim((string) ($input['site_location'] ?? ''));
    $siteDetails = trim((string) ($input['site_details'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $assignedTo = isset($input['assigned_to']) && $input['assigned_to'] !== '' ? (int) $input['assigned_to'] : null;
    if ($assignedTo !== null && $assignedTo > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users INNER JOIN roles ON users.role_id = roles.id WHERE users.id = :id AND roles.name = 'employee' AND users.status = 'active'");
        $stmt->execute([':id' => $assignedTo]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected employee is not available.');
        }
    } else {
        $assignedTo = null;
    }

    $referrerId = isset($input['referrer_id']) && $input['referrer_id'] !== '' ? (int) $input['referrer_id'] : null;
    if ($referrerId !== null && $referrerId > 0) {
        referrer_with_metrics($db, $referrerId);
    } else {
        $referrerId = null;
    }

    $stmt = $db->prepare('INSERT INTO crm_leads(name, phone, email, source, status, assigned_to, created_by, referrer_id, site_location, site_details, notes, created_at, updated_at) VALUES(:name, :phone, :email, :source, :status, :assigned_to, :created_by, :referrer_id, :site_location, :site_details, :notes, :created_at, :updated_at)');
    $now = now_ist();
    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':source' => $source !== '' ? $source : null,
        ':status' => 'new',
        ':assigned_to' => $assignedTo,
        ':created_by' => $actorId ?: null,
        ':referrer_id' => $referrerId,
        ':site_location' => $siteLocation !== '' ? $siteLocation : null,
        ':site_details' => $siteDetails !== '' ? $siteDetails : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $leadId = (int) $db->lastInsertId();

    if ($referrerId) {
        referrer_touch_lead($db, $referrerId);
    }

    return lead_fetch($db, $leadId);
}

function admin_assign_lead(PDO $db, int $leadId, ?int $employeeId, int $actorId): array
{
    if ($employeeId !== null && $employeeId > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users INNER JOIN roles ON users.role_id = roles.id WHERE users.id = :id AND roles.name = 'employee' AND users.status = 'active'");
        $stmt->execute([':id' => $employeeId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected employee is not available.');
        }
    }

    $stmt = $db->prepare('UPDATE crm_leads SET assigned_to = :assigned_to, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':assigned_to' => $employeeId ?: null,
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    return lead_fetch($db, $leadId);
}

function admin_update_lead_stage(PDO $db, int $leadId, string $stage, int $actorId, string $note = ''): array
{
    return lead_change_stage($db, $leadId, $stage, $actorId, 'admin', $note);
}

function admin_fetch_lead_overview(PDO $db): array
{
    $orderExpr = "CASE l.status WHEN 'new' THEN 0 WHEN 'visited' THEN 1 WHEN 'quotation' THEN 2 WHEN 'converted' THEN 3 WHEN 'lost' THEN 4 ELSE 5 END";
    $stmt = $db->query("SELECT l.*, assignee.full_name AS assigned_name, creator.full_name AS created_name, r.name AS referrer_name FROM crm_leads l LEFT JOIN users assignee ON l.assigned_to = assignee.id LEFT JOIN users creator ON l.created_by = creator.id LEFT JOIN referrers r ON l.referrer_id = r.id ORDER BY $orderExpr, COALESCE(l.updated_at, l.created_at) DESC");

    return lead_hydrate_rows($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function employee_list_leads(PDO $db, int $employeeId): array
{
    $orderExpr = "CASE l.status WHEN 'new' THEN 0 WHEN 'visited' THEN 1 WHEN 'quotation' THEN 2 WHEN 'converted' THEN 3 WHEN 'lost' THEN 4 ELSE 5 END";
    $stmt = $db->prepare("SELECT l.*, assignee.full_name AS assigned_name, r.name AS referrer_name FROM crm_leads l LEFT JOIN users assignee ON l.assigned_to = assignee.id LEFT JOIN referrers r ON l.referrer_id = r.id WHERE l.assigned_to = :employee_id ORDER BY $orderExpr, COALESCE(l.updated_at, l.created_at) DESC");
    $stmt->execute([':employee_id' => $employeeId]);

    $leads = lead_hydrate_rows($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
    foreach ($leads as &$lead) {
        $lead['nextStage'] = lead_next_stage($lead['status']);
        $lead['canAdvance'] = in_array($lead['status'], ['new', 'visited'], true) && !in_array($lead['status'], ['converted', 'lost'], true);
        if ($lead['canAdvance']) {
            if ($lead['status'] === 'visited') {
                $lead['nextStage'] = 'quotation';
            } elseif ($lead['status'] === 'new') {
                $lead['nextStage'] = 'visited';
            }
        }
        $lead['canSubmitProposal'] = $lead['status'] === 'quotation' && !$lead['hasPendingProposal'];
    }
    unset($lead);

    return $leads;
}

function employee_create_lead(PDO $db, array $input, int $employeeId): array
{
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $iso = $now->format('Y-m-d H:i:s');
    $stmt = $db->prepare('INSERT INTO crm_leads (name, phone, address, district, system_type, kwp, status, created_by, assigned_to, created_at, updated_at) VALUES (:name, :phone, :address, :district, :system_type, :kwp, :status, :created_by, :assigned_to, :created_at, :updated_at)');
    $stmt->execute([
        ':name' => $input['name'],
        ':phone' => $input['phone'],
        ':address' => $input['address'],
        ':district' => $input['district'],
        ':system_type' => $input['system_type'],
        ':kwp' => $input['kwp'],
        ':status' => 'new',
        ':created_by' => $employeeId,
        ':assigned_to' => $employeeId,
        ':created_at' => $iso,
        ':updated_at' => $iso,
    ]);
    $leadId = (int) $db->lastInsertId();
    return lead_fetch($db, $leadId);
}

function employee_update_lead(PDO $db, int $leadId, array $input, int $employeeId): array
{
    enforce_lead_access($db, $leadId, $employeeId);
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $iso = $now->format('Y-m-d H:i:s');
    $stmt = $db->prepare('UPDATE crm_leads SET name = :name, phone = :phone, address = :address, district = :district, system_type = :system_type, kwp = :kwp, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':name' => $input['name'],
        ':phone' => $input['phone'],
        ':address' => $input['address'],
        ':district' => $input['district'],
        ':system_type' => $input['system_type'],
        ':kwp' => $input['kwp'],
        ':updated_at' => $iso,
        ':id' => $leadId,
    ]);
    return lead_fetch($db, $leadId);
}

function enforce_lead_access(PDO $db, int $leadId, int $employeeId): void
{
    $lead = lead_fetch($db, $leadId);
    if (empty($lead)) {
        throw new RuntimeException('Lead not found.');
    }
    if ((int) ($lead['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this lead.');
    }
}

function employee_add_lead_visit(PDO $db, array $input, int $employeeId): array
{
    $leadId = (int) ($input['lead_id'] ?? 0);
    if ($leadId <= 0) {
        throw new RuntimeException('Lead reference is required.');
    }

    $note = trim((string) ($input['note'] ?? ''));
    if ($note === '') {
        throw new RuntimeException('Visit note cannot be empty.');
    }

    $lead = lead_fetch($db, $leadId);
    if ((int) ($lead['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this lead.');
    }
    $status = strtolower((string) ($lead['status'] ?? 'new'));
    if (in_array($status, ['converted', 'lost'], true)) {
        throw new RuntimeException('Closed leads cannot accept new visits.');
    }

    $photo = lead_extract_file_upload($input['photo'] ?? null, ['image/jpeg', 'image/png', 'image/gif'], 5 * 1024 * 1024);
    $stmt = $db->prepare('INSERT INTO lead_visits(lead_id, employee_id, note, photo_name, photo_mime, photo_data, created_at) VALUES(:lead_id, :employee_id, :note, :photo_name, :photo_mime, :photo_data, :created_at)');
    $stmt->execute([
        ':lead_id' => $leadId,
        ':employee_id' => $employeeId,
        ':note' => $note,
        ':photo_name' => $photo['name'] ?? null,
        ':photo_mime' => $photo['mime'] ?? null,
        ':photo_data' => $photo['data'] ?? null,
        ':created_at' => now_ist(),
    ]);

    $db->prepare('UPDATE crm_leads SET updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    return lead_fetch($db, $leadId);
}

function employee_progress_lead(PDO $db, int $leadId, string $stage, int $employeeId): array
{
    $lead = lead_fetch($db, $leadId);
    if ((int) ($lead['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this lead.');
    }

    return lead_change_stage($db, $leadId, $stage, $employeeId, 'employee');
}

function employee_submit_lead_proposal(PDO $db, array $input, int $employeeId): array
{
    $leadId = (int) ($input['lead_id'] ?? 0);
    if ($leadId <= 0) {
        throw new RuntimeException('Lead reference is required.');
    }

    $summary = trim((string) ($input['summary'] ?? ''));
    if ($summary === '') {
        throw new RuntimeException('Proposal summary is required.');
    }

    $lead = lead_fetch($db, $leadId);
    if ((int) ($lead['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this lead.');
    }

    $status = strtolower((string) ($lead['status'] ?? 'new'));
    if ($status !== 'quotation') {
        throw new RuntimeException('Proposals can only be submitted at the quotation stage.');
    }

    if (lead_has_pending_proposal($db, $leadId)) {
        throw new RuntimeException('A proposal is already pending review.');
    }

    $estimateRaw = trim((string) ($input['estimate'] ?? ''));
    $estimate = $estimateRaw !== '' ? (float) $estimateRaw : null;
    $document = lead_extract_file_upload($input['document'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], 8 * 1024 * 1024);

    $stmt = $db->prepare('INSERT INTO lead_proposals(lead_id, employee_id, summary, estimate_amount, document_name, document_mime, document_data, status, created_at) VALUES(:lead_id, :employee_id, :summary, :estimate_amount, :document_name, :document_mime, :document_data, :status, :created_at)');
    $stmt->execute([
        ':lead_id' => $leadId,
        ':employee_id' => $employeeId,
        ':summary' => $summary,
        ':estimate_amount' => $estimate,
        ':document_name' => $document['name'] ?? null,
        ':document_mime' => $document['mime'] ?? null,
        ':document_data' => $document['data'] ?? null,
        ':status' => 'pending',
        ':created_at' => now_ist(),
    ]);

    $proposalId = (int) $db->lastInsertId();
    $db->prepare('UPDATE crm_leads SET updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    $leadName = $lead['name'] ?? ('Lead #' . $leadId);
    approval_request_register(
        $db,
        'lead_conversion',
        $employeeId,
        'Conversion approval for ' . $leadName,
        [
            'lead_id' => $leadId,
            'proposal_id' => $proposalId,
            'summary' => $summary,
            'estimate' => $estimate,
        ],
        'lead',
        $leadId,
        $summary
    );

    return lead_fetch($db, $leadId);
}

function admin_approve_lead_proposal(PDO $db, int $proposalId, int $actorId): array
{
    $stmt = $db->prepare('SELECT * FROM lead_proposals WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $proposalId]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proposal) {
        throw new RuntimeException('Proposal not found.');
    }
    if (($proposal['status'] ?? 'pending') !== 'pending') {
        throw new RuntimeException('Only pending proposals can be approved.');
    }

    $update = $db->prepare("UPDATE lead_proposals SET status = 'approved', approved_at = :approved_at, approved_by = :approved_by, review_note = :review_note WHERE id = :id");
    $update->execute([
        ':approved_at' => now_ist(),
        ':approved_by' => $actorId ?: null,
        ':review_note' => null,
        ':id' => $proposalId,
    ]);

    $leadId = (int) $proposal['lead_id'];
    lead_change_stage($db, $leadId, 'converted', $actorId, 'admin', 'Proposal #' . $proposalId . ' approved');

    approval_request_sync_by_target($db, 'lead_conversion', 'lead', $leadId, 'approved', $actorId, null);

    return lead_fetch($db, $leadId);
}

function admin_reject_lead_proposal(PDO $db, int $proposalId, int $actorId, string $note = ''): array
{
    $stmt = $db->prepare('SELECT * FROM lead_proposals WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $proposalId]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proposal) {
        throw new RuntimeException('Proposal not found.');
    }
    if (($proposal['status'] ?? 'pending') !== 'pending') {
        throw new RuntimeException('Only pending proposals can be reviewed.');
    }

    $db->prepare("UPDATE lead_proposals SET status = 'rejected', approved_at = :approved_at, approved_by = :approved_by, review_note = :review_note WHERE id = :id")->execute([
        ':approved_at' => now_ist(),
        ':approved_by' => $actorId ?: null,
        ':review_note' => $note !== '' ? $note : null,
        ':id' => $proposalId,
    ]);

    $leadId = (int) $proposal['lead_id'];
    $db->prepare('UPDATE crm_leads SET updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => now_ist(),
        ':id' => $leadId,
    ]);

    approval_request_sync_by_target($db, 'lead_conversion', 'lead', $leadId, 'rejected', $actorId, $note);

    return lead_fetch($db, $leadId);
}

function admin_mark_lead_lost(PDO $db, int $leadId, int $actorId, string $note = ''): array
{
    return lead_change_stage($db, $leadId, 'lost', $actorId, 'admin', $note);
}

function admin_delete_lead(PDO $db, int $leadId, int $actorId): void
{
    $lead = lead_fetch($db, $leadId);
    $leadName = (string) ($lead['name'] ?? ('Lead #' . $leadId));

    $db->beginTransaction();
    try {
        $idParam = [':id' => $leadId];

        $reminderStmt = $db->prepare("SELECT id FROM reminders WHERE module = 'lead' AND linked_id = :id");
        $reminderStmt->execute($idParam);
        $reminderIds = array_map('intval', $reminderStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($reminderIds)) {
            $placeholders = implode(',', array_fill(0, count($reminderIds), '?'));
            $deleteApprovals = $db->prepare("DELETE FROM approval_requests WHERE target_type = 'reminder' AND target_id IN ($placeholders)");
            foreach ($reminderIds as $index => $reminderId) {
                $deleteApprovals->bindValue($index + 1, $reminderId, PDO::PARAM_INT);
            }
            $deleteApprovals->execute();

            $deleteReminders = $db->prepare("DELETE FROM reminders WHERE id IN ($placeholders)");
            foreach ($reminderIds as $index => $reminderId) {
                $deleteReminders->bindValue($index + 1, $reminderId, PDO::PARAM_INT);
            }
            $deleteReminders->execute();
        }

        $db->prepare("DELETE FROM approval_requests WHERE target_type = 'lead' AND target_id = :id")->execute($idParam);
        $db->prepare('UPDATE subsidy_tracker SET lead_id = NULL WHERE lead_id = :id')->execute($idParam);

        $deleteLead = $db->prepare('DELETE FROM crm_leads WHERE id = :id');
        $deleteLead->execute($idParam);
        if ($deleteLead->rowCount() === 0) {
            throw new RuntimeException('Lead could not be deleted.');
        }

        portal_log_action($db, $actorId, 'delete', 'lead', $leadId, sprintf('Lead %s removed from CRM', $leadName));

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}


function installation_status_label(string $status): string
{
    $map = [
        'planning' => 'Planning',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'on_hold' => 'On Hold',
        'cancelled' => 'Cancelled',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? ucfirst(str_replace('_', ' ', $normalized ?: 'in progress'));
}

function complaint_status_label(string $status): string
{
    $map = [
        'intake' => 'Intake',
        'triage' => 'Triage',
        'work' => 'Work In Progress',
        'resolved' => 'Resolved (Pending Admin)',
        'closed' => 'Closed',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? ucfirst($normalized ?: 'open');
}

function subsidy_status_label(string $status): string
{
    $map = [
        'pending' => 'Pending',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'disbursed' => 'Disbursed',
        'applied' => 'Applied',
        'under_review' => 'Under Review',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? ucfirst($normalized ?: 'pending');
}

function subsidy_stage_label(string $stage): string
{
    $map = [
        'applied' => 'Applied',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'disbursed' => 'Disbursed',
    ];

    $normalized = strtolower(trim($stage));

    return $map[$normalized] ?? ucfirst(str_replace('_', ' ', $normalized ?: 'applied'));
}

function subsidy_stage_order(string $stage): int
{
    static $order = [
        'applied' => 1,
        'under_review' => 2,
        'approved' => 3,
        'disbursed' => 4,
    ];

    $normalized = strtolower(trim($stage));

    return $order[$normalized] ?? 99;
}

function subsidy_stage_options(): array
{
    return [
        'applied' => 'Applied',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'disbursed' => 'Disbursed',
    ];
}

function normalize_subsidy_stage_date(?string $value): string
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return now_ist();
    }

    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $candidate, new DateTimeZone('Asia/Kolkata'));
            if ($dt instanceof DateTimeImmutable) {
                return $dt->setTime(0, 0)->format('Y-m-d H:i:s');
            }
        }

        $dt = new DateTimeImmutable($candidate, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return now_ist();
    }
}

function admin_subsidy_tracker_record_stage(PDO $db, array $input): void
{
    $reference = trim((string) ($input['reference'] ?? ''));
    if ($reference === '') {
        throw new InvalidArgumentException('Application reference is required.');
    }

    $stage = strtolower(trim((string) ($input['stage'] ?? '')));
    $validStages = array_keys(subsidy_stage_options());
    if (!in_array($stage, $validStages, true)) {
        throw new InvalidArgumentException('Select a valid stage.');
    }

    $leadId = isset($input['lead_id']) && $input['lead_id'] !== '' ? (int) $input['lead_id'] : null;
    $installationId = isset($input['installation_id']) && $input['installation_id'] !== '' ? (int) $input['installation_id'] : null;

    if ($leadId === null && $installationId === null) {
        throw new InvalidArgumentException('Link the update to a lead or installation.');
    }

    if ($leadId !== null) {
        $leadStmt = $db->prepare('SELECT COUNT(*) FROM crm_leads WHERE id = :id');
        $leadStmt->execute([':id' => $leadId]);
        if ((int) $leadStmt->fetchColumn() === 0) {
            throw new InvalidArgumentException('Lead not found.');
        }
    }

    if ($installationId !== null) {
        $installationStmt = $db->prepare('SELECT COUNT(*) FROM installations WHERE id = :id');
        $installationStmt->execute([':id' => $installationId]);
        if ((int) $installationStmt->fetchColumn() === 0) {
            throw new InvalidArgumentException('Installation not found.');
        }
    }

    $stageDate = normalize_subsidy_stage_date($input['stage_date'] ?? '');
    $note = trim((string) ($input['note'] ?? ''));
    $now = now_ist();

    $stmt = $db->prepare('INSERT INTO subsidy_tracker (application_reference, lead_id, installation_id, stage, stage_date, notes, created_at, updated_at) VALUES (:reference, :lead_id, :installation_id, :stage, :stage_date, :notes, :now, :now)');
    $stmt->execute([
        ':reference' => $reference,
        ':lead_id' => $leadId,
        ':installation_id' => $installationId,
        ':stage' => $stage,
        ':stage_date' => $stageDate,
        ':notes' => $note === '' ? null : $note,
        ':now' => $now,
    ]);
}

function admin_subsidy_tracker_summary(PDO $db, ?string $stageFilter = null, ?string $fromDate = null, ?string $toDate = null): array
{
    $stmt = $db->query('SELECT st.id, st.application_reference, st.stage, st.stage_date, st.notes, st.lead_id, st.installation_id, leads.name AS lead_name, leads.phone AS lead_phone, inst.customer_name AS installation_customer, inst.project_reference FROM subsidy_tracker st LEFT JOIN crm_leads leads ON leads.id = st.lead_id LEFT JOIN installations inst ON inst.id = st.installation_id ORDER BY st.application_reference COLLATE NOCASE, st.stage_date ASC, st.id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $cases = [];
    foreach ($rows as $row) {
        $reference = (string) ($row['application_reference'] ?? '');
        if ($reference === '') {
            continue;
        }

        $stage = strtolower((string) ($row['stage'] ?? ''));
        if ($stage === '') {
            continue;
        }

        if (!isset($cases[$reference])) {
            $cases[$reference] = [
                'reference' => $reference,
                'lead' => [
                    'id' => $row['lead_id'] !== null ? (int) $row['lead_id'] : null,
                    'name' => $row['lead_name'] ?? '',
                    'phone' => $row['lead_phone'] ?? '',
                ],
                'installation' => [
                    'id' => $row['installation_id'] !== null ? (int) $row['installation_id'] : null,
                    'name' => $row['installation_customer'] ?: ($row['project_reference'] ?? ''),
                ],
                'stages' => [
                    'applied' => null,
                    'under_review' => null,
                    'approved' => null,
                    'disbursed' => null,
                ],
                'latest_stage' => $stage,
                'latest_date' => $row['stage_date'] ?? null,
                'latest_note' => $row['notes'] ?? '',
            ];
        }

        $stageData = [
            'date' => $row['stage_date'] ?? null,
            'note' => $row['notes'] ?? '',
        ];
        $existingStage = $cases[$reference]['stages'][$stage] ?? null;
        if ($existingStage === null || ($stageData['date'] !== null && ($existingStage['date'] === null || strcmp($stageData['date'], $existingStage['date']) >= 0))) {
            $cases[$reference]['stages'][$stage] = $stageData;
        }

        $currentLatestDate = $cases[$reference]['latest_date'];
        $newDate = $row['stage_date'] ?? null;
        $shouldUpdateLatest = false;

        if ($newDate === null) {
            $shouldUpdateLatest = $currentLatestDate === null;
        } elseif ($currentLatestDate === null) {
            $shouldUpdateLatest = true;
        } else {
            $comparison = strcmp($newDate, $currentLatestDate);
            if ($comparison > 0) {
                $shouldUpdateLatest = true;
            } elseif ($comparison === 0) {
                $shouldUpdateLatest = subsidy_stage_order($stage) >= subsidy_stage_order($cases[$reference]['latest_stage']);
            }
        }

        if ($shouldUpdateLatest) {
            $cases[$reference]['latest_stage'] = $stage;
            $cases[$reference]['latest_date'] = $newDate;
            $cases[$reference]['latest_note'] = $row['notes'] ?? '';
        }
    }

    $stageKeys = array_keys(subsidy_stage_options());
    $overallTotals = array_fill_keys($stageKeys, 0);
    $overallPending = 0;
    foreach ($cases as $case) {
        $latestStage = $case['latest_stage'] ?? 'applied';
        if (!isset($overallTotals[$latestStage])) {
            $overallTotals[$latestStage] = 0;
        }
        $overallTotals[$latestStage]++;
        if ($latestStage !== 'disbursed') {
            $overallPending++;
        }
    }

    $stageFilter = $stageFilter !== null ? strtolower(trim($stageFilter)) : null;
    $fromBound = null;
    if ($fromDate) {
        $fromDate = trim($fromDate);
        if ($fromDate !== '') {
            $fromBound = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) === 1 ? $fromDate . ' 00:00:00' : $fromDate;
        }
    }
    $toBound = null;
    if ($toDate) {
        $toDate = trim($toDate);
        if ($toDate !== '') {
            $toBound = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) === 1 ? $toDate . ' 23:59:59' : $toDate;
        }
    }

    $filtered = [];
    foreach ($cases as $case) {
        $latestStage = $case['latest_stage'] ?? 'applied';
        if ($stageFilter !== null && $stageFilter !== '' && $stageFilter !== 'all') {
            if ($stageFilter === 'pending') {
                if ($latestStage === 'disbursed') {
                    continue;
                }
            } elseif ($latestStage !== $stageFilter) {
                continue;
            }
        }

        $latestDate = $case['latest_date'];
        if ($fromBound !== null) {
            if ($latestDate === null || strcmp($latestDate, $fromBound) < 0) {
                continue;
            }
        }

        if ($toBound !== null) {
            if ($latestDate === null || strcmp($latestDate, $toBound) > 0) {
                continue;
            }
        }

        $filtered[] = $case;
    }

    usort($filtered, static function (array $left, array $right): int {
        $dateLeft = $left['latest_date'] ?? '';
        $dateRight = $right['latest_date'] ?? '';
        $compare = strcmp((string) $dateRight, (string) $dateLeft);
        if ($compare !== 0) {
            return $compare;
        }

        $stageCompare = subsidy_stage_order($right['latest_stage'] ?? '') <=> subsidy_stage_order($left['latest_stage'] ?? '');
        if ($stageCompare !== 0) {
            return $stageCompare;
        }

        return strcmp((string) ($left['reference'] ?? ''), (string) ($right['reference'] ?? ''));
    });

    $visibleTotals = array_fill_keys($stageKeys, 0);
    $visiblePending = 0;
    foreach ($filtered as $case) {
        $latestStage = $case['latest_stage'] ?? 'applied';
        if (!isset($visibleTotals[$latestStage])) {
            $visibleTotals[$latestStage] = 0;
        }
        $visibleTotals[$latestStage]++;
        if ($latestStage !== 'disbursed') {
            $visiblePending++;
        }
    }

    return [
        'cases' => $filtered,
        'totals' => $overallTotals,
        'pendingTotal' => $overallPending,
        'visibleTotals' => $visibleTotals,
        'visiblePending' => $visiblePending,
    ];
}

function reminder_status_label(string $status): string
{
    $map = [
        'proposed' => 'Proposed',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $normalized = strtolower(trim($status));

    return $map[$normalized] ?? ucfirst($normalized ?: 'Proposed');
}

function reminder_module_options(): array
{
    return [
        'lead' => 'Lead',
        'installation' => 'Installation',
        'complaint' => 'Complaint',
        'subsidy' => 'Subsidy',
        'amc' => 'AMC',
    ];
}

function reminder_module_label(string $module): string
{
    $options = reminder_module_options();
    $normalized = strtolower(trim($module));

    return $options[$normalized] ?? ucfirst($normalized ?: 'Item');
}

function reminder_due_counts(PDO $db, ?int $userId = null): array
{
    $conditions = ["status = 'active'", 'deleted_at IS NULL'];
    $params = [];
    if ($userId !== null) {
        $conditions[] = 'proposer_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $whereClause = implode(' AND ', $conditions);
    $tz = new DateTimeZone('Asia/Kolkata');
    $today = new DateTimeImmutable('now', $tz);
    $startToday = $today->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endToday = $today->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $dueTodaySql = 'SELECT COUNT(*) FROM reminders WHERE ' . $whereClause . ' AND due_at BETWEEN :start_today AND :end_today';
    $overdueSql = 'SELECT COUNT(*) FROM reminders WHERE ' . $whereClause . ' AND due_at < :start_today';
    $upcomingSql = 'SELECT COUNT(*) FROM reminders WHERE ' . $whereClause . ' AND due_at > :end_today';

    $dueTodayStmt = $db->prepare($dueTodaySql);
    $overdueStmt = $db->prepare($overdueSql);
    $upcomingStmt = $db->prepare($upcomingSql);

    $baseParams = $params;
    $dueTodayParams = $baseParams + [
        ':start_today' => $startToday,
        ':end_today' => $endToday,
    ];
    $overdueParams = $baseParams + [
        ':start_today' => $startToday,
    ];
    $upcomingParams = $baseParams + [
        ':end_today' => $endToday,
    ];

    $dueTodayStmt->execute($dueTodayParams);
    $overdueStmt->execute($overdueParams);
    $upcomingStmt->execute($upcomingParams);

    return [
        'due_today' => (int) $dueTodayStmt->fetchColumn(),
        'overdue' => (int) $overdueStmt->fetchColumn(),
        'upcoming' => (int) $upcomingStmt->fetchColumn(),
    ];
}

function portal_employee_reminders(PDO $db, int $employeeId, array $options = []): array
{
    $status = strtolower(trim((string) ($options['status'] ?? 'all')));
    $dueFilter = strtolower(trim((string) ($options['due'] ?? 'all')));
    $limit = isset($options['limit']) ? (int) $options['limit'] : null;

    $conditions = ['proposer_id = :employee_id', 'deleted_at IS NULL'];
    $params = [
        ':employee_id' => $employeeId,
    ];

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'status = :status';
        $params[':status'] = $status;
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $today = new DateTimeImmutable('now', $tz);
    $startToday = $today->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endToday = $today->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $forceActiveStatuses = ['due_today', 'overdue', 'upcoming'];
    $shouldForceActive = in_array($dueFilter, $forceActiveStatuses, true);

    if ($shouldForceActive && ($status === '' || $status === 'all')) {
        $conditions[] = "status = 'active'";
    }

    if ($dueFilter === 'due_today') {
        $conditions[] = 'due_at BETWEEN :start_today AND :end_today';
        $params[':start_today'] = $startToday;
        $params[':end_today'] = $endToday;
    } elseif ($dueFilter === 'overdue') {
        $conditions[] = 'due_at < :start_today';
        $params[':start_today'] = $startToday;
    } elseif ($dueFilter === 'upcoming') {
        $conditions[] = 'due_at > :end_today';
        $params[':end_today'] = $endToday;
    }

    $sql = 'SELECT * FROM reminders WHERE ' . implode(' AND ', $conditions) . ' ORDER BY due_at ASC, id ASC';
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT :limit';
    }

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    if ($limit !== null && $limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();

    $reminders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reminders[] = employee_normalize_reminder_row($db, $row);
    }

    return $reminders;
}

function employee_edit_reminder(PDO $db, int $reminderId, array $input, int $employeeId): array
{
    $reminder = employee_find_reminder($db, $reminderId, $employeeId);
    if (!$reminder) {
        throw new RuntimeException('Reminder not found.');
    }

    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Reminder title is required.');
    }

    $dueAt = employee_parse_reminder_due((string) ($input['due_at'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $now = now_ist();

    $stmt = $db->prepare('UPDATE reminders SET title = :title, due_at = :due_at, notes = :notes, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':title' => $title,
        ':due_at' => $dueAt,
        ':notes' => $notes !== '' ? $notes : null,
        ':updated_at' => $now,
        ':id' => $reminderId,
    ]);

    return employee_find_reminder($db, $reminderId, $employeeId);
}

function employee_update_profile(PDO $db, int $employeeId, array $input): array
{
    $user = portal_find_user($db, $employeeId);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    $photo = lead_extract_file_upload($input['photo'] ?? null, ['image/jpeg', 'image/png', 'image/gif'], 5 * 1024 * 1024);
    if ($photo) {
        $storage = new PortalFileStorage('profile_photos');
        $storage->store($employeeId . '.json', json_encode($photo));
    }

    $emergencyContact = trim((string) ($input['emergency_contact'] ?? ''));
    if ($emergencyContact !== '') {
        set_setting('user_emergency_contact_' . $employeeId, $emergencyContact, $db);
    }

    $workingDistrict = trim((string) ($input['working_district'] ?? ''));
    if ($workingDistrict !== '') {
        set_setting('user_working_district_' . $employeeId, $workingDistrict, $db);
    }

    $password = trim((string) ($input['password'] ?? ''));
    if ($password !== '') {
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':hash' => $hash,
            ':updated_at' => now_ist(),
            ':id' => $employeeId,
        ]);
    }

    return portal_find_user($db, $employeeId);
}

function employee_parse_reminder_due(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException('Due date and time is required.');
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz);
    if (!$parsed instanceof DateTimeImmutable) {
        try {
            $parsed = new DateTimeImmutable($value, $tz);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid due date and time.');
        }
    }

    $startOfToday = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    if ($parsed < $startOfToday) {
        throw new RuntimeException('Due date must be today or later.');
    }

    return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function employee_resolve_reminder_label(PDO $db, string $module, int $linkedId): string
{
    $normalized = strtolower(trim($module));

    try {
        if ($normalized === 'lead') {
            $lead = lead_fetch($db, $linkedId);
            $name = trim((string) ($lead['name'] ?? ''));
            return sprintf('Lead #%d%s', $linkedId, $name !== '' ? ' · ' . $name : '');
        }
        if ($normalized === 'installation') {
            $installation = installation_fetch($db, $linkedId);
            $labelParts = [];
            if (!empty($installation['customer_name'])) {
                $labelParts[] = $installation['customer_name'];
            }
            if (!empty($installation['project_reference'])) {
                $labelParts[] = $installation['project_reference'];
            }
            $suffix = $labelParts ? ' · ' . implode(' · ', $labelParts) : '';
            return sprintf('Installation #%d%s', $linkedId, $suffix);
        }
        if ($normalized === 'complaint') {
            $complaint = complaint_fetch($db, $linkedId);
            $reference = (string) ($complaint['reference'] ?? '');
            $title = trim((string) ($complaint['title'] ?? ''));
            $suffix = $title !== '' ? ' · ' . $title : '';
            if ($reference !== '') {
                return sprintf('Complaint %s%s', $reference, $suffix);
            }

            return sprintf('Complaint #%d%s', $linkedId, $suffix);
        }
    } catch (Throwable $exception) {
        // Ignore lookup errors and fall back to a generic label.
    }

    return sprintf('%s #%d', ucfirst($normalized ?: 'Item'), $linkedId);
}

function employee_normalize_reminder_row(PDO $db, array $row): array
{
    $dueValue = $row['due_at'] ?? null;
    $dueDisplay = '—';
    $dueIso = '';
    if ($dueValue) {
        $due = null;
        try {
            $due = new DateTimeImmutable((string) $dueValue, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            try {
                $due = new DateTimeImmutable((string) $dueValue);
            } catch (Throwable $inner) {
                $due = null;
            }
        }
        if ($due instanceof DateTimeImmutable) {
            $dueIst = $due->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $dueDisplay = $dueIst->format('d M Y · h:i A');
            $dueIso = $dueIst->format(DateTimeInterface::ATOM);
        } else {
            $dueDisplay = (string) $dueValue;
        }
    }

    $status = strtolower((string) ($row['status'] ?? 'proposed'));

    $completedValue = (string) ($row['completed_at'] ?? '');
    $completedDisplay = '';
    if ($completedValue !== '') {
        try {
            $completedAt = new DateTimeImmutable($completedValue, new DateTimeZone('UTC'));
            $completedDisplay = $completedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
        } catch (Throwable $exception) {
            $completedDisplay = $completedValue;
        }
    }

    $status = strtolower((string) ($row['status'] ?? 'proposed'));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'module' => (string) ($row['module'] ?? ''),
        'moduleLabel' => reminder_module_label($row['module'] ?? ''),
        'linkedId' => isset($row['linked_id']) ? (int) $row['linked_id'] : 0,
        'linkedLabel' => employee_resolve_reminder_label($db, (string) ($row['module'] ?? ''), (int) ($row['linked_id'] ?? 0)),
        'status' => $status,
        'statusLabel' => reminder_status_label($row['status'] ?? ''),
        'dueDisplay' => $dueDisplay,
        'dueIso' => $dueIso,
        'notes' => (string) ($row['notes'] ?? ''),
        'decisionNote' => (string) ($row['decision_note'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'canWithdraw' => $status === 'proposed',
        'canComplete' => $status === 'active',
        'completedAt' => $completedValue,
        'completedDisplay' => $completedDisplay,
    ];
}

function employee_find_reminder(PDO $db, int $id, int $employeeId): ?array
{
    $stmt = $db->prepare('SELECT * FROM reminders WHERE id = :id AND proposer_id = :proposer AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([
        ':id' => $id,
        ':proposer' => $employeeId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return employee_normalize_reminder_row($db, $row);
}

function employee_list_reminders(PDO $db, int $employeeId): array
{
    $stmt = $db->prepare('SELECT * FROM reminders WHERE proposer_id = :proposer AND deleted_at IS NULL ORDER BY due_at ASC, id ASC');
    $stmt->execute([':proposer' => $employeeId]);

    $reminders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reminders[] = employee_normalize_reminder_row($db, $row);
    }

    return $reminders;
}

function employee_validate_reminder_target(PDO $db, string $module, int $linkedId, int $employeeId): void
{
    $normalized = strtolower(trim($module));
    if (!in_array($normalized, ['lead', 'installation', 'complaint'], true)) {
        throw new RuntimeException('Unsupported reminder module.');
    }

    if ($linkedId <= 0) {
        throw new RuntimeException('Linked record is required.');
    }

    if ($normalized === 'lead') {
        $lead = lead_fetch($db, $linkedId);
        if ((int) ($lead['assigned_to'] ?? 0) !== $employeeId) {
            throw new RuntimeException('You are not assigned to this lead.');
        }
        return;
    }

    if ($normalized === 'installation') {
        $installation = installation_fetch($db, $linkedId);
        if ((int) ($installation['assigned_to'] ?? 0) !== $employeeId) {
            throw new RuntimeException('You are not assigned to this installation.');
        }
        return;
    }

    $complaint = complaint_fetch($db, $linkedId);
    if ((int) ($complaint['assigned_to'] ?? 0) !== $employeeId) {
        throw new RuntimeException('You are not assigned to this complaint.');
    }
}

function employee_upload_complaint_media(PDO $db, int $employeeId, array $input): array
{
    $reference = (string) ($input['reference'] ?? '');
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }
    enforce_complaint_access($db, $reference, $employeeId);

    $media = lead_extract_file_upload($input['media'] ?? null, ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/quicktime'], 10 * 1024 * 1024);
    if (!$media) {
        throw new RuntimeException('Media file is required.');
    }

    $complaint = portal_add_complaint_attachment(
        $db,
        $reference,
        [
            'filename' => $media['name'],
            'label' => $media['name'],
            'note' => trim((string) ($input['note'] ?? '')),
            'visibility' => 'both',
        ],
        $employeeId
    );

    return $complaint;
}

function employee_propose_reminder(PDO $db, array $input, int $employeeId): array
{
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Reminder title is required.');
    }

    $module = strtolower(trim((string) ($input['module'] ?? '')));
    $linkedId = (int) ($input['linked_id'] ?? 0);
    employee_validate_reminder_target($db, $module, $linkedId, $employeeId);

    $dueAt = employee_parse_reminder_due((string) ($input['due_at'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $now = now_ist();

    $stmt = $db->prepare('INSERT INTO reminders(title, module, linked_id, due_at, status, notes, decision_note, proposer_id, approver_id, completed_at, created_at, updated_at) VALUES(:title, :module, :linked_id, :due_at, :status, :notes, NULL, :proposer_id, NULL, NULL, :created_at, :updated_at)');
    $stmt->execute([
        ':title' => $title,
        ':module' => $module,
        ':linked_id' => $linkedId,
        ':due_at' => $dueAt,
        ':status' => 'proposed',
        ':notes' => $notes !== '' ? $notes : null,
        ':proposer_id' => $employeeId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $reminderId = (int) $db->lastInsertId();
    portal_log_action($db, $employeeId, 'create', 'reminder', $reminderId, 'Reminder proposed via employee portal');

    $created = employee_find_reminder($db, $reminderId, $employeeId);
    if ($created === null) {
        throw new RuntimeException('Reminder could not be created.');
    }

    $linkedLabel = employee_resolve_reminder_label($db, $module, $linkedId);
    approval_request_register(
        $db,
        'reminder_proposal',
        $employeeId,
        'Reminder approval for ' . $linkedLabel,
        [
            'reminder_id' => $reminderId,
            'module' => $module,
            'linked_id' => $linkedId,
            'title' => $title,
            'due_at' => $dueAt,
            'notes' => $notes,
        ],
        'reminder',
        $reminderId,
        $notes
    );

    return $created;
}

function employee_cancel_reminder(PDO $db, int $reminderId, int $employeeId): array
{
    $stmt = $db->prepare('SELECT * FROM reminders WHERE id = :id AND proposer_id = :proposer AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([
        ':id' => $reminderId,
        ':proposer' => $employeeId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Reminder not found.');
    }

    $status = strtolower((string) ($row['status'] ?? ''));
    if ($status !== 'proposed') {
        throw new RuntimeException('Reminder cannot be withdrawn after admin review.');
    }

    $now = now_ist();
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('d M Y · h:i A');
    $systemNote = sprintf('Withdrawn by proposer on %s', $timestamp);

    $update = $db->prepare('UPDATE reminders SET status = :status, decision_note = :decision_note, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':status' => 'cancelled',
        ':decision_note' => $systemNote,
        ':updated_at' => $now,
        ':id' => $reminderId,
    ]);

    portal_log_action($db, $employeeId, 'status_change', 'reminder', $reminderId, 'Reminder withdrawn by proposer');

    $updated = employee_find_reminder($db, $reminderId, $employeeId);
    if ($updated === null) {
        throw new RuntimeException('Reminder could not be updated.');
    }

    $fullContext = admin_find_reminder($db, $reminderId);
    if ($fullContext !== null) {
        portal_notify_reminder_status($db, $fullContext, 'cancelled');
    }

    approval_request_sync_by_target($db, 'reminder_proposal', 'reminder', $reminderId, 'rejected', $employeeId, 'Withdrawn by proposer');

    return $updated;
}

function employee_complete_reminder(PDO $db, int $reminderId, int $employeeId): array
{
    $stmt = $db->prepare('SELECT * FROM reminders WHERE id = :id AND proposer_id = :proposer AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([
        ':id' => $reminderId,
        ':proposer' => $employeeId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Reminder not found.');
    }

    $status = strtolower((string) ($row['status'] ?? ''));
    if ($status !== 'active') {
        throw new RuntimeException('Only active reminders can be completed.');
    }

    $now = now_ist();
    $update = $db->prepare('UPDATE reminders SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':status' => 'completed',
        ':completed_at' => $now,
        ':updated_at' => $now,
        ':id' => $reminderId,
    ]);

    portal_log_action($db, $employeeId, 'status_change', 'reminder', $reminderId, 'Reminder completed by proposer');

    $updated = employee_find_reminder($db, $reminderId, $employeeId);
    if ($updated === null) {
        throw new RuntimeException('Reminder could not be updated.');
    }

    $fullContext = admin_find_reminder($db, $reminderId);
    if ($fullContext !== null) {
        portal_notify_reminder_status($db, $fullContext, 'completed');
    }

    return $updated;
}

function admin_normalize_reminder_row(array $row): array
{
    $dueValue = $row['due_at'] ?? null;
    $dueDisplay = '—';
    $dueIso = '';
    if ($dueValue) {
        $due = null;
        try {
            $due = new DateTimeImmutable($dueValue, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            try {
                $due = new DateTimeImmutable((string) $dueValue);
            } catch (Throwable $inner) {
                $due = null;
            }
        }
        if ($due instanceof DateTimeImmutable) {
            $dueIst = $due->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $dueDisplay = $dueIst->format('d M Y · h:i A');
            $dueIso = $dueIst->format(DateTimeInterface::ATOM);
        } else {
            $dueDisplay = (string) $dueValue;
        }
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'module' => (string) ($row['module'] ?? ''),
        'moduleLabel' => reminder_module_label($row['module'] ?? ''),
        'linkedId' => isset($row['linked_id']) ? (int) $row['linked_id'] : 0,
        'status' => (string) ($row['status'] ?? ''),
        'statusLabel' => reminder_status_label($row['status'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'decisionNote' => (string) ($row['decision_note'] ?? ''),
        'proposerId' => isset($row['proposer_id']) && $row['proposer_id'] !== null ? (int) $row['proposer_id'] : null,
        'proposerName' => (string) ($row['proposer_name'] ?? ''),
        'approverId' => isset($row['approver_id']) && $row['approver_id'] !== null ? (int) $row['approver_id'] : null,
        'approverName' => (string) ($row['approver_name'] ?? ''),
        'dueAt' => $dueValue !== null ? (string) $dueValue : '',
        'dueDisplay' => $dueDisplay,
        'dueIso' => $dueIso,
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'completedAt' => (string) ($row['completed_at'] ?? ''),
    ];
}

function admin_list_reminders(PDO $db, array $filters): array
{
    $status = strtolower(trim((string) ($filters['status'] ?? 'active')));
    $module = strtolower(trim((string) ($filters['module'] ?? 'all')));
    $fromDate = trim((string) ($filters['from'] ?? ''));
    $toDate = trim((string) ($filters['to'] ?? ''));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = (int) ($filters['per_page'] ?? 20);
    if ($perPage <= 0) {
        $perPage = 20;
    }
    $perPage = min($perPage, 100);

    $conditions = ['reminders.deleted_at IS NULL'];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'reminders.status = :status';
        $params[':status'] = $status;
    }

    if ($module !== '' && $module !== 'all') {
        $conditions[] = 'reminders.module = :module';
        $params[':module'] = $module;
    }

    $tzIst = new DateTimeZone('Asia/Kolkata');
    if ($fromDate !== '') {
        $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate, $tzIst);
        if ($from instanceof DateTimeImmutable) {
            $conditions[] = 'reminders.due_at >= :from_date';
            $params[':from_date'] = $from->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }

    if ($toDate !== '') {
        $to = DateTimeImmutable::createFromFormat('Y-m-d', $toDate, $tzIst);
        if ($to instanceof DateTimeImmutable) {
            $conditions[] = 'reminders.due_at <= :to_date';
            $params[':to_date'] = $to->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }

    $whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

    $countSql = 'SELECT COUNT(*) FROM reminders' . $whereSql;
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pageCount = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    if ($pageCount < 1) {
        $pageCount = 1;
    }
    if ($page > $pageCount) {
        $page = $pageCount;
    }

    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT reminders.*, proposer.full_name AS proposer_name, approver.full_name AS approver_name FROM reminders '
        . 'LEFT JOIN users proposer ON reminders.proposer_id = proposer.id '
        . 'LEFT JOIN users approver ON reminders.approver_id = approver.id'
        . $whereSql
        . ' ORDER BY reminders.due_at ASC, reminders.id ASC LIMIT :limit OFFSET :offset';

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = admin_normalize_reminder_row($row);
    }

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pageCount,
        ],
    ];
}

function admin_list_reminder_requests(PDO $db): array
{
    $stmt = $db->prepare(
        'SELECT reminders.*, proposer.full_name AS proposer_name, approver.full_name AS approver_name '
        . 'FROM reminders '
        . 'LEFT JOIN users proposer ON reminders.proposer_id = proposer.id '
        . 'LEFT JOIN users approver ON reminders.approver_id = approver.id '
        . "WHERE reminders.status = 'proposed' AND reminders.deleted_at IS NULL "
        . 'ORDER BY reminders.due_at ASC, reminders.id ASC'
    );
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = admin_normalize_reminder_row($row);
    }

    return $items;
}

function admin_find_reminder(PDO $db, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT reminders.*, proposer.full_name AS proposer_name, approver.full_name AS approver_name '
        . 'FROM reminders '
        . 'LEFT JOIN users proposer ON reminders.proposer_id = proposer.id '
        . 'LEFT JOIN users approver ON reminders.approver_id = approver.id '
        . 'WHERE reminders.id = :id AND reminders.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return admin_normalize_reminder_row($row);
}

function admin_create_reminder(PDO $db, array $input, int $actorId): array
{
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }

    $module = strtolower(trim((string) ($input['module'] ?? '')));
    if (!array_key_exists($module, reminder_module_options())) {
        throw new RuntimeException('Linked item type is invalid.');
    }

    $linkedId = (int) ($input['linked_id'] ?? 0);
    if ($linkedId <= 0) {
        throw new RuntimeException('Linked item ID is required.');
    }

    $dueAt = trim((string) ($input['due_at'] ?? ''));
    if ($dueAt === '') {
        throw new RuntimeException('Due date and time is required.');
    }

    try {
        $dueDate = new DateTimeImmutable($dueAt, new DateTimeZone('UTC'));
    } catch (Throwable $exception) {
        throw new RuntimeException('Invalid due date and time.');
    }

    $todayStart = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))
        ->setTime(0, 0, 0)
        ->setTimezone(new DateTimeZone('UTC'));
    if ($dueDate < $todayStart) {
        throw new RuntimeException('Due date must be today or later.');
    }

    $notes = trim((string) ($input['notes'] ?? ''));

    $now = now_ist();
    $stmt = $db->prepare('INSERT INTO reminders(title, module, linked_id, due_at, status, notes, decision_note, proposer_id, approver_id, completed_at, created_at, updated_at) VALUES(:title, :module, :linked_id, :due_at, :status, :notes, NULL, :proposer_id, :approver_id, NULL, :created_at, :updated_at)');
    $stmt->execute([
        ':title' => $title,
        ':module' => $module,
        ':linked_id' => $linkedId,
        ':due_at' => $dueAt,
        ':status' => 'active',
        ':notes' => $notes !== '' ? $notes : null,
        ':proposer_id' => $actorId,
        ':approver_id' => $actorId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $reminderId = (int) $db->lastInsertId();
    portal_log_action($db, $actorId, 'create', 'reminder', $reminderId, 'Reminder created via admin portal');

    $created = admin_find_reminder($db, $reminderId);
    if ($created === null) {
        throw new RuntimeException('Reminder not found after save.');
    }

    return $created;
}

function admin_update_reminder_status(PDO $db, int $id, string $targetStatus, int $actorId, ?string $note = null): array
{
    $target = strtolower(trim($targetStatus));
    if (!in_array($target, ['active', 'cancelled', 'completed'], true)) {
        throw new RuntimeException('Unsupported reminder status change.');
    }

    $stmt = $db->prepare('SELECT * FROM reminders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Reminder not found.');
    }

    $currentStatus = strtolower((string) ($row['status'] ?? 'proposed'));
    $now = now_ist();

    if ($target === 'active') {
        if ($currentStatus !== 'proposed') {
            throw new RuntimeException('Only proposed reminders can be approved.');
        }

        $update = $db->prepare('UPDATE reminders SET status = :status, approver_id = :approver_id, decision_note = NULL, completed_at = NULL, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':status' => 'active',
            ':approver_id' => $actorId,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        portal_log_action($db, $actorId, 'status_change', 'reminder', $id, 'Reminder approved');
        approval_request_sync_by_target($db, 'reminder_proposal', 'reminder', $id, 'approved', $actorId, null);
    } elseif ($target === 'cancelled') {
        $reason = trim((string) $note);
        if ($currentStatus === 'proposed' && $reason === '') {
            throw new RuntimeException('Rejection reason is required.');
        }

        if (!in_array($currentStatus, ['proposed', 'active'], true)) {
            throw new RuntimeException('Only proposed or active reminders can be cancelled.');
        }

        $decisionNote = $reason !== '' ? $reason : null;

        $update = $db->prepare('UPDATE reminders SET status = :status, approver_id = :approver_id, decision_note = :decision_note, completed_at = NULL, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':status' => 'cancelled',
            ':approver_id' => $actorId,
            ':decision_note' => $decisionNote,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        $logMessage = $currentStatus === 'proposed' ? 'Reminder rejected' : 'Reminder cancelled';
        portal_log_action($db, $actorId, 'status_change', 'reminder', $id, $logMessage);
        approval_request_sync_by_target($db, 'reminder_proposal', 'reminder', $id, 'rejected', $actorId, $decisionNote ?? '');
    } else { // completed
        if ($currentStatus !== 'active') {
            throw new RuntimeException('Only active reminders can be completed.');
        }

        $update = $db->prepare('UPDATE reminders SET status = :status, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':status' => 'completed',
            ':completed_at' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        portal_log_action($db, $actorId, 'status_change', 'reminder', $id, 'Reminder marked completed');
    }

    $updated = admin_find_reminder($db, $id);
    if ($updated === null) {
        throw new RuntimeException('Reminder could not be reloaded.');
    }

    portal_notify_reminder_status($db, $updated, $target);

    return $updated;
}

function portal_notify_reminder_status(PDO $db, array $reminder, string $status): void
{
    try {
        $normalizedStatus = strtolower(trim($status));
        $proposerId = null;
        if (isset($reminder['proposerId'])) {
            $proposerId = $reminder['proposerId'];
        } elseif (isset($reminder['proposer_id'])) {
            $proposerId = $reminder['proposer_id'];
        }

        $proposerId = (int) ($proposerId ?? 0);
        if ($proposerId <= 0) {
            return;
        }

        $title = sprintf('Reminder %s', reminder_status_label($normalizedStatus));
        $moduleLabel = $reminder['moduleLabel'] ?? reminder_module_label($reminder['module'] ?? '');
        $linkedId = $reminder['linkedId'] ?? $reminder['linked_id'] ?? '';
        $reminderTitle = trim((string) ($reminder['title'] ?? 'Reminder'));
        if ($reminderTitle === '') {
            $reminderTitle = 'Reminder';
        }
        $statusLabel = strtolower(reminder_status_label($normalizedStatus));
        $linkText = $linkedId !== '' ? sprintf('%s #%s', $moduleLabel, $linkedId) : $moduleLabel;
        $message = sprintf('"%s" for %s is now %s.', $reminderTitle, $linkText, $statusLabel);

        $toneMap = [
            'completed' => 'success',
            'cancelled' => 'warning',
            'active' => 'info',
            'proposed' => 'info',
        ];
        $tone = $toneMap[$normalizedStatus] ?? 'info';

        portal_store_reminder_banner($db, $proposerId, $tone, $title, $message);
    } catch (Throwable $exception) {
        error_log('Failed to notify reminder status: ' . $exception->getMessage());
    }
}

function admin_list_employees(PDO $db, string $status = 'active'): array
{
    $status = strtolower(trim($status));
    $stmt = $db->prepare(<<<'SQL'
SELECT
    users.full_name,
    users.email,
    users.status,
    users.created_at,
    users.last_login_at
FROM users
INNER JOIN roles ON users.role_id = roles.id
WHERE roles.name = 'employee'
  AND (:status = 'all' OR users.status = :status)
ORDER BY users.full_name COLLATE NOCASE
SQL
    );
    $stmt->execute([
        ':status' => $status === 'all' ? 'all' : $status,
    ]);

    return $stmt->fetchAll();
}

function admin_list_leads(PDO $db, string $status = 'new'): array
{
    $status = strtolower(trim($status));
    $orderExpr = "CASE l.status WHEN 'new' THEN 0 WHEN 'visited' THEN 1 WHEN 'quotation' THEN 2 WHEN 'converted' THEN 3 WHEN 'lost' THEN 4 ELSE 5 END";
    if ($status === 'all') {
        $stmt = $db->query("SELECT l.name, l.phone, l.email, l.status, l.source, l.assigned_to, assignee.full_name AS assigned_name, l.created_at, l.updated_at, l.referrer_id, r.name AS referrer_name FROM crm_leads l LEFT JOIN users assignee ON l.assigned_to = assignee.id LEFT JOIN referrers r ON l.referrer_id = r.id ORDER BY $orderExpr, COALESCE(l.updated_at, l.created_at) DESC");
        return $stmt->fetchAll();
    }

    $stmt = $db->prepare("SELECT l.name, l.phone, l.email, l.status, l.source, l.assigned_to, assignee.full_name AS assigned_name, l.created_at, l.updated_at, l.referrer_id, r.name AS referrer_name FROM crm_leads l LEFT JOIN users assignee ON l.assigned_to = assignee.id LEFT JOIN referrers r ON l.referrer_id = r.id WHERE l.status = :status ORDER BY $orderExpr, COALESCE(l.updated_at, l.created_at) DESC");
    $stmt->execute([':status' => $status]);

    return $stmt->fetchAll();
}

function admin_list_installations(PDO $db, string $filter = 'ongoing'): array
{
    return installation_admin_filter($db, $filter);
}

function admin_list_complaints(PDO $db, string $status = 'open'): array
{
    $status = strtolower(trim($status));
    $baseQuery = 'SELECT reference, customer_name, title, status, priority, origin, created_at, updated_at FROM complaints';
    $orderClause = ' ORDER BY COALESCE(updated_at, created_at) DESC';

    if ($status === 'all') {
        $stmt = $db->query($baseQuery . $orderClause);
        return $stmt->fetchAll();
    }

    if ($status === 'closed') {
        $stmt = $db->prepare($baseQuery . " WHERE status = 'closed'" . $orderClause);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    if ($status === 'resolved') {
        $stmt = $db->prepare($baseQuery . " WHERE status = 'resolved'" . $orderClause);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $stmt = $db->prepare($baseQuery . " WHERE status != 'closed'" . $orderClause);
    $stmt->execute();

    return $stmt->fetchAll();
}

function admin_list_subsidy(PDO $db, string $status = 'pending'): array
{
    $status = strtolower(trim($status));

    if ($status === 'rejected') {
        return [];
    }

    $stageFilter = null;
    if ($status === 'pending') {
        $stageFilter = 'pending';
    } elseif ($status === 'all') {
        $stageFilter = null;
    } else {
        $stageFilter = $status;
    }

    $summary = admin_subsidy_tracker_summary($db, $stageFilter, null, null);

    $rows = [];
    foreach ($summary['cases'] as $case) {
        $stages = $case['stages'];
        $rows[] = [
            'customer_name' => $case['installation']['name'] ?: $case['lead']['name'],
            'application_number' => $case['reference'],
            'status' => $case['latest_stage'],
            'amount' => null,
            'submitted_on' => $stages['applied']['date'] ?? null,
            'created_at' => $stages['applied']['date'] ?? $case['latest_date'],
            'updated_at' => $case['latest_date'],
        ];
    }

    return $rows;
}

function portal_find_complaint_id(PDO $db, string $reference): ?int
{
    $notes = [];
    if (!empty($row['notes'])) {
        $decoded = json_decode((string) $row['notes'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $notes[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'body' => (string) ($item['body'] ?? ''),
                    'visibility' => (string) ($item['visibility'] ?? 'internal'),
                    'authorId' => $item['authorId'] ?? null,
                    'authorName' => (string) ($item['authorName'] ?? ''),
                    'createdAt' => (string) ($item['createdAt'] ?? ''),
                ];
            }
        }
    }

    $attachments = [];
    if (!empty($row['attachments'])) {
        $decoded = json_decode((string) $row['attachments'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $attachments[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'filename' => (string) ($item['filename'] ?? ''),
                    'label' => (string) ($item['label'] ?? ''),
                    'note' => (string) ($item['note'] ?? ''),
                    'sizeMb' => isset($item['sizeMb']) ? (float) $item['sizeMb'] : null,
                    'visibility' => (string) ($item['visibility'] ?? 'both'),
                    'uploadedBy' => (string) ($item['uploadedBy'] ?? ''),
                    'uploadedById' => $item['uploadedById'] ?? null,
                    'uploadedAt' => (string) ($item['uploadedAt'] ?? ''),
                    'downloadToken' => (string) ($item['downloadToken'] ?? ''),
                ];
            }
        }
    }

    return [
        'id' => (int) $row['id'],
        'reference' => $row['reference'],
        'title' => $row['title'],
        'description' => $row['description'] ?? '',
        'priority' => $row['priority'] ?? 'medium',
        'status' => $row['status'] ?? 'intake',
        'assignedTo' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
        'assigneeName' => $row['assigned_to_name'] ?? '',
        'assigneeRole' => $row['assigned_role'] ?? '',
        'slaDue' => $row['sla_due_at'] ?? '',
        'createdAt' => $row['created_at'] ?? '',
        'updatedAt' => $row['updated_at'] ?? '',
        'notes' => $notes,
        'attachments' => $attachments,
    ];
}

function portal_update_complaint_status(PDO $db, string $reference, string $statusKey, int $actorId): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }

    $row = portal_fetch_complaint_row($db, $reference);

    $statusMap = [
        'in_progress' => 'work',
        'awaiting_response' => 'resolved',
        'resolved' => 'resolved',
        'escalated' => 'triage',
    ];

    if (!isset($statusMap[$statusKey])) {
        throw new RuntimeException('Unsupported ticket status.');
    }

    $newStatus = $statusMap[$statusKey];

    if ($newStatus === 'resolved') {
        $complaintId = (int) ($row['id'] ?? 0);
        approval_request_register(
            $db,
            'complaint_resolved',
            $actorId,
            'Resolve complaint ' . $reference,
            [
                'reference' => $reference,
                'complaint_id' => $complaintId,
            ],
            'complaint',
            $complaintId,
            'Resolution requested by assignee'
        );

        portal_log_action($db, $actorId, 'status_change', 'complaint', $complaintId, 'Resolution requested for complaint');
        portal_record_complaint_event(
            $db,
            $complaintId,
            $actorId,
            'status',
            'Resolution requested',
            null,
            null,
            $newStatus
        );

        return portal_normalize_complaint_row($db, $row);
    }

    $stmtUpdate = $db->prepare('UPDATE complaints SET status = :status, updated_at = :updated_at, assigned_to = CASE WHEN :status = \'triage\' THEN NULL ELSE assigned_to END WHERE reference = :reference');
    $stmtUpdate->execute([
        ':status' => $newStatus,
        ':updated_at' => now_ist(),
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'status_change', 'complaint', (int) $row['id'], 'Complaint updated to ' . $newStatus);
    portal_record_complaint_event($db, (int) $row['id'], $actorId, 'status', 'Status updated to ' . strtoupper($newStatus), null, null, $newStatus);

    return portal_normalize_complaint_row($db, portal_fetch_complaint_row($db, $reference));
}

function portal_fetch_complaint_row(PDO $db, string $reference): array
{
    $stmt = $db->prepare('SELECT complaints.*, users.full_name AS assigned_to_name, roles.name AS assigned_role FROM complaints LEFT JOIN users ON complaints.assigned_to = users.id LEFT JOIN roles ON users.role_id = roles.id WHERE complaints.reference = :reference LIMIT 1');
    $stmt->execute([':reference' => $reference]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Complaint not found.');
    }

    return $row;
}

function complaint_fetch(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM complaints WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Complaint not found.');
    }

    return $row;
}

function portal_assign_complaint(PDO $db, string $reference, ?int $assigneeId, ?string $slaDue, int $actorId): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }

    $row = portal_fetch_complaint_row($db, $reference);
    $previousAssignee = $row['assigned_to'] ?? null;
    $previousSla = $row['sla_due_at'] ?? null;
    $assignee = null;

    if ($assigneeId !== null) {
        $assignee = portal_find_user($db, $assigneeId);
        if (!$assignee || ($assignee['role_name'] ?? '') !== 'employee') {
            throw new RuntimeException('Select a valid employee assignee.');
        }
    }

    $dueDate = null;
    if ($slaDue !== null && $slaDue !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slaDue)) {
            throw new RuntimeException('SLA due date must be in YYYY-MM-DD format.');
        }
        $dueDate = $slaDue;
    }

    $stmt = $db->prepare('UPDATE complaints SET assigned_to = :assignee_id, sla_due_at = :sla_due_at, updated_at = :updated_at WHERE reference = :reference');
    $stmt->execute([
        ':assignee_id' => $assigneeId,
        ':sla_due_at' => $dueDate,
        ':updated_at' => now_ist(),
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'assign', 'complaint', (int) $row['id'], sprintf('Complaint assigned to %s', $assigneeId ? ('user #' . $assigneeId) : 'unassigned'));

    $assigneeChanged = ($previousAssignee !== null ? (int) $previousAssignee : null) !== $assigneeId;
    $slaChanged = $previousSla !== $dueDate;
    if ($assigneeChanged || $slaChanged) {
        $assigneeLabel = $assigneeId !== null ? ($assignee['full_name'] ?? ('user #' . $assigneeId)) : 'Unassigned';
        $noteParts = [];
        if ($dueDate !== null) {
            $noteParts[] = sprintf('SLA due on %s', $dueDate);
        }
        if ($previousSla !== null && $dueDate === null) {
            $noteParts[] = 'SLA cleared';
        }
        $details = empty($noteParts) ? null : implode('; ', $noteParts);
        portal_record_complaint_event($db, (int) $row['id'], $actorId, 'assignment', 'Assigned to ' . $assigneeLabel, $details);
    }

    return portal_normalize_complaint_row($db, portal_fetch_complaint_row($db, $reference));
}

function portal_add_complaint_note(PDO $db, string $reference, string $noteBody, int $actorId, string $visibility = 'internal'): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }

    $noteBody = trim($noteBody);
    if ($noteBody === '') {
        throw new RuntimeException('Note cannot be empty.');
    }

    $row = portal_fetch_complaint_row($db, $reference);

    $notes = [];
    if (!empty($row['notes'])) {
        $decoded = json_decode((string) $row['notes'], true);
        if (is_array($decoded)) {
            $notes = $decoded;
        }
    }

    $author = $actorId > 0 ? portal_find_user($db, $actorId) : null;
    $record = [
        'id' => bin2hex(random_bytes(6)),
        'body' => $noteBody,
        'visibility' => $visibility,
        'authorId' => $actorId ?: null,
        'authorName' => $author['full_name'] ?? 'System',
        'createdAt' => now_ist(),
    ];
    $notes[] = $record;

    $stmt = $db->prepare('UPDATE complaints SET notes = :notes, updated_at = :updated_at WHERE reference = :reference');
    $stmt->execute([
        ':notes' => json_encode($notes, JSON_THROW_ON_ERROR),
        ':updated_at' => $record['createdAt'],
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'note_added', 'complaint', (int) $row['id'], 'Complaint note recorded');
    portal_record_complaint_event($db, (int) $row['id'], $actorId, 'note', 'Note added', $noteBody);

    return portal_normalize_complaint_row($db, portal_fetch_complaint_row($db, $reference));
}

function portal_add_complaint_attachment(PDO $db, string $reference, array $attachment, int $actorId): array
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new RuntimeException('Complaint reference is required.');
    }

    $row = portal_fetch_complaint_row($db, $reference);

    $filename = trim((string) ($attachment['filename'] ?? ''));
    $label = trim((string) ($attachment['label'] ?? 'Attachment'));
    if ($filename === '') {
        throw new RuntimeException('Attachment filename is required.');
    }

    $sizeMb = isset($attachment['sizeMb']) ? (float) $attachment['sizeMb'] : null;
    $note = trim((string) ($attachment['note'] ?? ''));
    $visibility = (string) ($attachment['visibility'] ?? 'both');
    if (!in_array($visibility, ['employee', 'admin', 'both'], true)) {
        $visibility = 'both';
    }

    $attachments = [];
    if (!empty($row['attachments'])) {
        $decoded = json_decode((string) $row['attachments'], true);
        if (is_array($decoded)) {
            $attachments = $decoded;
        }
    }

    $author = $actorId > 0 ? portal_find_user($db, $actorId) : null;
    $record = [
        'id' => bin2hex(random_bytes(6)),
        'filename' => $filename,
        'label' => $label,
        'note' => $note,
        'sizeMb' => $sizeMb,
        'visibility' => $visibility,
        'uploadedBy' => $author['full_name'] ?? 'System',
        'uploadedById' => $actorId ?: null,
        'uploadedAt' => now_ist(),
        'downloadToken' => bin2hex(random_bytes(16)),
    ];

    if (isset($attachment['documentId'])) {
        $record['documentId'] = (int) $attachment['documentId'];
    }

    $attachments[] = $record;

    $stmt = $db->prepare('UPDATE complaints SET attachments = :attachments, updated_at = :updated_at WHERE reference = :reference');
    $stmt->execute([
        ':attachments' => json_encode($attachments, JSON_THROW_ON_ERROR),
        ':updated_at' => $record['uploadedAt'],
        ':reference' => $reference,
    ]);

    portal_log_action($db, $actorId, 'attachment_added', 'complaint', (int) $row['id'], 'Complaint attachment logged');
    $documentId = isset($record['documentId']) ? (int) $record['documentId'] : null;
    $summary = sprintf('Attachment "%s" uploaded', $label);
    $details = $note !== '' ? $note : null;
    portal_record_complaint_event($db, (int) $row['id'], $actorId, 'document', $summary, $details, $documentId);

    return portal_normalize_complaint_row($db, portal_fetch_complaint_row($db, $reference));
}

function portal_recent_audit_logs(PDO $db, int $limit = 25): array
{
    $stmt = $db->prepare('SELECT audit_logs.id, audit_logs.action, audit_logs.entity_type, audit_logs.entity_id, audit_logs.description, audit_logs.created_at, users.full_name AS actor_name FROM audit_logs LEFT JOIN users ON audit_logs.actor_id = users.id ORDER BY audit_logs.created_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function portal_get_complaint(PDO $db, string $reference): array
{
    return portal_normalize_complaint_row($db, portal_fetch_complaint_row($db, $reference));
}

function portal_employee_submit_complaint_document(PDO $db, int $userId, string $reference, array $payload): array
{
    enforce_complaint_access($db, $reference, $userId);

    $type = trim((string) ($payload['type'] ?? ''));
    $filename = trim((string) ($payload['filename'] ?? ''));
    if ($type === '' || $filename === '') {
        throw new RuntimeException('Document type and file name are required.');
    }

    $note = trim((string) ($payload['note'] ?? ''));
    $sizeValue = trim((string) ($payload['file_size'] ?? ''));
    $sizeMb = $sizeValue !== '' ? (float) $sizeValue : null;

    $documentData = [
        'name' => $type,
        'linkedTo' => 'complaint:' . $reference,
        'reference' => $filename,
        'tags' => [$reference],
        'url' => '#',
        'visibility' => 'both',
        'notes' => $note,
    ];

    $document = portal_save_document($db, $documentData, $userId);

    $complaint = portal_add_complaint_attachment($db, $reference, [
        'filename' => $filename,
        'label' => $type,
        'note' => $note,
        'sizeMb' => $sizeMb,
        'visibility' => 'both',
        'documentId' => $document['id'] ?? null,
    ], $userId);

    return [
        'document' => $document,
        'complaint' => $complaint,
    ];
}

function enforce_complaint_access(PDO $db, string $reference, int $userId): void
{
    $complaint = portal_get_complaint($db, $reference);
    if (empty($complaint)) {
        throw new RuntimeException('Complaint not found.');
    }
    if ((int) ($complaint['assignedTo'] ?? 0) !== $userId) {
        throw new RuntimeException('You are not assigned to this complaint.');
    }
}
