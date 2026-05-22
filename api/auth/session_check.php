<?php
/**
 * EduTrack — Session Check Endpoint
 *
 * Lightweight JSON endpoint polled by frontend JavaScript to confirm
 * the session is still alive. Used to:
 *   - Auto-redirect to login if the session has expired
 *   - Show a "session expiring soon" warning before timeout
 *   - Return a fresh CSRF token when needed by the frontend
 *
 * Method:  GET
 * URL:     /api/auth/session_check.php
 * Access:  Any user (returns logged_in: false if not authenticated)
 *
 * Response:
 *   {
 *     "logged_in":      bool,
 *     "user":           object|null,
 *     "csrf_token":     string|null,
 *     "session_age":    int,         seconds since session was created
 *     "session_max":    int,         max session lifetime in seconds
 *     "expires_in":     int          seconds until session expires
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$loggedIn  = Auth::isLoggedIn();
$sessionAge = isset($_SESSION['_created']) ? (time() - $_SESSION['_created']) : 0;
$expiresIn  = max(0, SESSION_LIFETIME - $sessionAge);

echo json_encode([
    'logged_in'   => $loggedIn,
    'user'        => $loggedIn ? Auth::user() : null,
    'csrf_token'  => $loggedIn ? Auth::csrfToken() : null,
    'session_age' => $sessionAge,
    'session_max' => SESSION_LIFETIME,
    'expires_in'  => $expiresIn,
]);