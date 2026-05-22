<?php
/**
 * EduTrack — Admin: Update System Settings
 *
 * Accepts an array of {key, value} pairs and upserts each one
 * into system_settings in a single transaction.
 *
 * Method:  POST
 * URL:     /api/admin/settings_update.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   settings: [ { key: string, value: string }, ... ]
 *
 * Success response (200):
 *   { "success": true, "message": "X setting(s) saved." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$incoming = $body['settings'] ?? [];

if (empty($incoming) || !is_array($incoming)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No settings provided.']);
    exit;
}

// ── Allowed setting keys (whitelist — never allow arbitrary key injection) ────
$allowedKeys = [
    'school_name',
    'academic_year',
    'active_semester',
    'attendance_threshold',
    'attendance_window',
    'dispute_window_hours',
    'rows_per_page',
    'smtp_enabled',
    'allow_student_register',
    'maintenance_mode',
];

// ── Validate all incoming keys before touching the DB ─────────────────────────
$toSave = [];
foreach ($incoming as $item) {
    $key   = trim($item['key']   ?? '');
    $value = trim($item['value'] ?? '');

    if (!in_array($key, $allowedKeys, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Unknown setting key: '{$key}'.",
        ]);
        exit;
    }

    // Per-key value validation
    switch ($key) {
        case 'attendance_threshold':
            $v = (int) $value;
            if ($v < 1 || $v > 100) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Attendance threshold must be 1–100%.']);
                exit;
            }
            $value = (string) $v;
            break;

        case 'attendance_window':
            $v = (int) $value;
            if ($v < 1 || $v > 120) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'QR window must be 1–120 minutes.']);
                exit;
            }
            $value = (string) $v;
            break;

        case 'dispute_window_hours':
            $v = (int) $value;
            if ($v < 1 || $v > 168) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Dispute window must be 1–168 hours.']);
                exit;
            }
            $value = (string) $v;
            break;

        case 'rows_per_page':
            $v = (int) $value;
            if ($v < 5 || $v > 100) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Rows per page must be 5–100.']);
                exit;
            }
            $value = (string) $v;
            break;

        case 'active_semester':
            if (!in_array($value, ['1', '2'], true)) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Semester must be 1 or 2.']);
                exit;
            }
            break;

        case 'smtp_enabled':
        case 'allow_student_register':
        case 'maintenance_mode':
            $value = in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
            break;

        case 'school_name':
            if (empty($value)) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'School name cannot be empty.']);
                exit;
            }
            $value = substr($value, 0, 150);
            break;

        case 'academic_year':
            if (!preg_match('/^\d{4}\/\d{4}$/', $value)) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>'Academic year must be in format YYYY/YYYY (e.g. 2024/2025).']);
                exit;
            }
            break;
    }

    $toSave[] = ['key' => $key, 'value' => $value];
}

// ── Upsert all validated settings in one transaction ──────────────────────────
DB::beginTransaction();
try {
    foreach ($toSave as $s) {
        DB::execute(
            "INSERT INTO system_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by    = VALUES(updated_by)",
            [$s['key'], $s['value'], Auth::id()]
        );
    }
    DB::commit();
} catch (Exception $e) {
    DB::rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save settings. Please try again.']);
    exit;
}

$count = count($toSave);
Auth::audit('settings_updated', 'system_settings', null, [
    'keys' => array_column($toSave, 'key'),
]);

echo json_encode([
    'success' => true,
    'message' => "{$count} setting" . ($count !== 1 ? 's' : '') . " saved successfully.",
]);