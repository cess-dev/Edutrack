<?php
/**
 * EduTrack — Login API Endpoint
 *
 * Handles POST login requests from all four portals.
 * On success, writes a PHP session and returns the user's role
 * so the frontend JavaScript can redirect to the correct dashboard.
 *
 * Method:  POST
 * URL:     /api/auth/login.php
 * Access:  Public (no auth required — this IS the auth)
 *
 * Request body (JSON or form-encoded):
 *   reg_number  string  required
 *   password    string  required
 *
 * Success response (200):
 *   {
 *     "success": true,
 *     "message": "Login successful.",
 *     "user": {
 *       "id":        int,
 *       "full_name": string,
 *       "role":      string,
 *       "reg_number":string
 *     },
 *     "redirect": string   (absolute URL of the role dashboard)
 *   }
 *
 * Error responses:
 *   400 — missing fields
 *   401 — invalid credentials or inactive account
 *   405 — wrong HTTP method
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

// If already logged in send straight to dashboard — no work needed
if (Auth::isLoggedIn()) {
    $role     = Auth::role();
    $redirect = BASE_URL . '/' . $role . '/dashboard';
    echo json_encode([
        'success'  => true,
        'message'  => 'Already logged in.',
        'user'     => Auth::user(),
        'redirect' => $redirect,
    ]);
    exit;
}

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

// ── Parse request body ────────────────────────────────────────────────────────
// Support both JSON body (fetch API) and form-encoded (HTML form submit)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $regNumber = trim($body['reg_number'] ?? '');
    $password  = $body['password'] ?? '';
} else {
    $regNumber = trim($_POST['reg_number'] ?? '');
    $password  = $_POST['password'] ?? '';
}

// ── Input validation ──────────────────────────────────────────────────────────
$errors = [];

if (empty($regNumber)) {
    $errors[] = 'Registration number is required.';
}

if (empty($password)) {
    $errors[] = 'Password is required.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors),
        'errors'  => $errors,
    ]);
    exit;
}

// ── Rate limiting — simple session-based attempt counter ──────────────────────
// Tracks failed attempts per IP to slow down brute-force without extra infra.
// Resets on successful login or after the lockout window expires.
$ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$attemptKey  = 'login_attempts_' . md5($ip);
$lockoutKey  = 'login_lockout_'  . md5($ip);

// Check if the IP is currently locked out
if (isset($_SESSION[$lockoutKey]) && $_SESSION[$lockoutKey] > time()) {
    $wait = ceil(($_SESSION[$lockoutKey] - time()) / 60);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => "Too many failed attempts. Please wait {$wait} minute(s) before trying again.",
    ]);
    exit;
}

// ── Attempt authentication ────────────────────────────────────────────────────
$success = Auth::attempt($regNumber, $password);

if (!$success) {
    // Increment attempt counter
    $_SESSION[$attemptKey] = ($_SESSION[$attemptKey] ?? 0) + 1;

    // Lock out after 5 consecutive failures for 15 minutes
    if ($_SESSION[$attemptKey] >= 5) {
        $_SESSION[$lockoutKey]  = time() + (15 * 60);
        $_SESSION[$attemptKey]  = 0;

        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed attempts. Your IP has been locked out for 15 minutes.',
        ]);
        exit;
    }

    $remaining = 5 - $_SESSION[$attemptKey];

    http_response_code(401);
    echo json_encode([
        'success'           => false,
        'message'           => 'Invalid registration number or password.',
        'attempts_remaining'=> $remaining,
    ]);
    exit;
}

// ── Login successful ──────────────────────────────────────────────────────────
// Clear attempt counters
unset($_SESSION[$attemptKey], $_SESSION[$lockoutKey]);

$user     = Auth::user();
$role     = $user['role'];
$redirect = BASE_URL . '/' . $role . '/dashboard';

http_response_code(200);
echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'user'     => $user,
    'redirect' => $redirect,
]);