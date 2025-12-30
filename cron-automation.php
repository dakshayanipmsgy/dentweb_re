<?php
declare(strict_types=1);

/**
 * CRON AUTOMATION SCRIPT
 * ----------------------
 * This script is designed to be run by the system's Cron Job scheduler.
 * It triggers the AI "Auto-Draft" process without requiring a user login.
 *
 * USAGE:
 * curl "https://your-domain.com/cron-automation.php?key=YOUR_SECRET_KEY"
 */

// 1. SECURITY CONFIGURATION
// -------------------------
// This key prevents unauthorized access.
// You should change this to a random string of your choice.
define('CRON_SECRET', 'DENTWEB_AI_AUTO_SECRET_KEY_2025');

// 2. AUTHENTICATION CHECK
// -----------------------
$key = $_GET['key'] ?? '';
if (!hash_equals(CRON_SECRET, $key)) {
    http_response_code(403);
    die('Access Denied: Invalid Secret Key');
}

// 3. BOOTSTRAP APPLICATION
// ------------------------
// We load the necessary includes to access the file-system database and AI logic.
// No Node.js or external services are required.
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_gemini.php';

// Set headers for JSON output
header('Content-Type: application/json');
header('X-Robots-Tag: noindex');

try {
    // 4. LOAD SETTINGS
    // ----------------
    // Load AI settings directly from storage/ai/settings.json
    $settings = ai_settings_load();

    // Check if AI is globally enabled
    if (!($settings['enabled'] ?? false)) {
        echo json_encode([
            'status' => 'skipped',
            'reason' => 'AI features are disabled in the admin settings.'
        ]);
        exit;
    }

    // 5. EXECUTE AUTOMATION
    // ---------------------
    // Run the auto-draft logic. We use ID 0 to represent "System Automation".
    // This function checks for upcoming festivals and generates content if needed.
    $newDrafts = ai_greetings_run_autodraft($settings, 0);

    // 6. REPORT RESULTS
    // -----------------
    // Calculate how many *new* items were created (if the function returns the full list)
    // Note: ai_greetings_run_autodraft returns the full list of greetings.
    // For a simple status, we just report success.
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Automation executed successfully.',
        'timestamp' => date('c'),
        'total_greetings_count' => count($newDrafts)
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    // Handle any errors gracefully
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred during automation.',
        'error_details' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
