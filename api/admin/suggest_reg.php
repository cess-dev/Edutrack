<?php
/**
 * EduTrack — Suggest Next Registration Number
 *
 * Returns a suggested (next-available) registration number for a given role,
 * plus a hint telling the admin whether to override it with an institutional ID.
 *
 * Method:  GET
 * URL:     /api/admin/suggest_reg.php?role={role}
 * Access:  Admin only
 *
 * Response (200):
 *   {
 *     "success":   true,
 *     "suggested": "STU2025003",
 *     "auto_only": false,        // true = admin/parent (no institutional ID exists)
 *     "hint":      string        // human-readable guidance for the field
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$role       = strtolower(trim($_GET['role'] ?? ''));
$validRoles = ['admin', 'lecturer', 'student', 'parent'];

if (!in_array($role, $validRoles, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role.']);
    exit;
}

$suggested = UserModel::generateRegNumber($role);

// Parents and admins have no pre-existing institutional ID — the system owns it.
// Students and lecturers have IDs assigned by the institution's registry/HR.
$autoOnly = in_array($role, ['parent', 'admin'], true);

$hints = [
    'student'  => 'Enter the official student registration number from the institution. ' .
                  $suggested . ' is the next auto-generated value if you need a placeholder.',
    'lecturer' => 'Enter the staff number from HR. ' .
                  $suggested . ' is the next auto-generated value if you need a placeholder.',
    'parent'   => 'Auto-generated — parents have no institutional ID. You may edit if needed.',
    'admin'    => 'Auto-generated for internal use. You may edit if needed.',
];

echo json_encode([
    'success'   => true,
    'suggested' => $suggested,
    'auto_only' => $autoOnly,
    'hint'      => $hints[$role] ?? '',
]);
