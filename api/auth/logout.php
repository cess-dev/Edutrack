<?php
/**
 * EduTrack — Logout API Endpoint
 *
 * Destroys the current user session and redirects to the
 * role-appropriate login page.
 *
 * Accepts both GET (direct link) and POST (form/AJAX submit).
 * POST requests must include a valid CSRF token.
 *
 * Method:  GET or POST
 * URL:     /api/auth/logout.php
 * Access:  Any logged-in user
 *
 * GET  — redirects directly to login page (safe for nav links)
 * POST — validates CSRF token, then returns JSON + redirect URL
 *        (used by the AJAX logout button)
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();

// Store role before session is destroyed (needed for redirect)
$role = Auth::role() ?? 'student';

$loginPages = [
    'admin'    => BASE_URL . '/public/admin/login.php',
    'lecturer' => BASE_URL . '/public/lecturer/login.php',
    'student'  => BASE_URL . '/public/student/login.php',
    'parent'   => BASE_URL . '/public/parent/login.php',
];

$loginPage = $loginPages[$role] ?? BASE_URL . '/index.php';

// ── POST — AJAX logout with CSRF check ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    Auth::verifyCsrf(true);
    Auth::logout();  // Destroys session internally, but we already captured $loginPage
    echo json_encode([
        'success'  => true,
        'message'  => 'Logged out successfully.',
        'redirect' => $loginPage,
    ]);
    exit;
}

// ── GET — direct link logout ──────────────────────────────────────────────────
Auth::logout();
header('Location: ' . $loginPage);
exit;