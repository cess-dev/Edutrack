<?php
/**
 * EduTrack — Profile: Update Contact Details
 * Any authenticated user can update their own email and phone.
 *
 * Method: POST · URL: /api/profile/update_contact.php
 * Access: Any logged-in role
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireLogin(true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = $body['email'] ?? null;
$phone = $body['phone'] ?? null;

$result = UserModel::updateContact(Auth::id(), $email ?? '', $phone ?? '');

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);