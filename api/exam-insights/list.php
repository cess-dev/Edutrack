<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');
header('Content-Type: application/json');

$unitId = (int)($_GET['unit_id'] ?? 0);
if ($unitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unit_id is required.']);
    exit;
}

$unit = DB::row("SELECT id FROM units WHERE id = ? AND lecturer_id = ? AND is_active = 1", [$unitId, Auth::id()]);
if (!$unit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unit not found or not assigned to you.']);
    exit;
}

$uploads = DB::rows(
    "SELECT eu.id, eu.exam_title, eu.avg_score, eu.most_failed, eu.observations,
            eu.analysis_status, eu.uploaded_at, eu.analyzed_at, eu.original_filename
     FROM exam_uploads eu
     WHERE eu.unit_id = ? AND eu.lecturer_id = ?
     ORDER BY eu.uploaded_at DESC",
    [$unitId, Auth::id()]
);

foreach ($uploads as &$u) {
    $insight = DB::row(
        "SELECT id, ai_summary, ai_comparisons FROM exam_insights WHERE upload_id = ?",
        [$u['id']]
    );
    $u['insight'] = $insight;
    $u['suggestions'] = $insight
        ? DB::rows("SELECT id, suggestion_text, category, status, follow_up_notes, executed_at FROM exam_suggestions WHERE insight_id = ? ORDER BY id", [$insight['id']])
        : [];
}
unset($u);

echo json_encode(['success' => true, 'uploads' => $uploads]);
