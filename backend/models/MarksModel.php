<?php
/**
 * EduTrack — Marks Model
 *
 * All database reads and writes related to grades live here.
 * Covers:
 *   - Assessment (CAT/exam/assignment) creation and management
 *   - Single mark entry and update
 *   - Bulk CSV upload with validation
 *   - Computed weighted averages and grade letters
 *   - Published/unpublished visibility control
 *   - Student, lecturer, and parent grade views
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class MarksModel
{
    /**
     * Grade boundaries used to convert a weighted total (0–100) to a letter grade.
     * Adjust the boundaries to match your institution's grading policy.
     */
    private static array $gradeBoundaries = [
        ['min' => 70, 'grade' => 'A',  'points' => 4.0, 'remark' => 'Distinction'],
        ['min' => 60, 'grade' => 'B',  'points' => 3.0, 'remark' => 'Credit'],
        ['min' => 50, 'grade' => 'C',  'points' => 2.0, 'remark' => 'Pass'],
        ['min' => 40, 'grade' => 'D',  'points' => 1.0, 'remark' => 'Marginal Fail'],
        ['min' =>  0, 'grade' => 'E',  'points' => 0.0, 'remark' => 'Fail'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Assessments
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new assessment component for a unit.
     *
     * Validates that adding this assessment will not push the unit's total
     * weight past 100%. Returns the new assessment ID on success.
     *
     * @param  array $data {
     *   unit_id, name, type, max_score, weight_percent,
     *   assessment_date (optional), created_by
     * }
     * @return array { success: bool, id: int|null, message: string }
     */
    public static function createAssessment(array $data): array
    {
        // Validate weight will not exceed 100 for the unit
        $currentWeight = self::getTotalWeight((int) $data['unit_id']);
        $newWeight     = (float) $data['weight_percent'];

        if ($currentWeight + $newWeight > 100) {
            $remaining = 100 - $currentWeight;
            return [
                'success' => false,
                'id'      => null,
                'message' => "Adding {$newWeight}% would exceed 100% for this unit. "
                           . "You have {$remaining}% remaining.",
            ];
        }

        $id = DB::insert(
            "INSERT INTO assessments
                (unit_id, name, type, max_score, weight_percent, assessment_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['unit_id'],
                trim($data['name']),
                $data['type'],
                (float) $data['max_score'],
                $newWeight,
                $data['assessment_date'] ?? null,
                $data['created_by'],
            ]
        );

        return [
            'success' => true,
            'id'      => (int) $id,
            'message' => 'Assessment created successfully.',
        ];
    }

    /**
     * Get all assessments for a unit, with total marks uploaded per assessment.
     *
     * @param  int  $unitId
     * @param  bool $publishedOnly  True for student/parent views
     * @return array
     */
    public static function getUnitAssessments(int $unitId, bool $publishedOnly = false): array
    {
        $filter = $publishedOnly ? ' AND a.is_published = 1' : '';

        return DB::rows(
            "SELECT
                a.id,
                a.name,
                a.type,
                a.max_score,
                a.weight_percent,
                a.assessment_date,
                a.is_published,
                COUNT(m.id)  AS marks_uploaded,
                AVG(m.score) AS class_average
             FROM assessments a
             LEFT JOIN marks m ON m.assessment_id = a.id
             WHERE a.unit_id = ?
               {$filter}
             GROUP BY a.id, a.name, a.type, a.max_score,
                      a.weight_percent, a.assessment_date, a.is_published
             ORDER BY a.assessment_date ASC, a.id ASC",
            [$unitId]
        );
    }

    /**
     * Toggle the is_published flag for an assessment.
     * When published = 1, students and parents can see their marks.
     *
     * @param  int $assessmentId
     * @param  int $lecturerId    Verifies ownership via unit.lecturer_id
     * @return array { success: bool, published: bool, message: string }
     */
    public static function togglePublish(int $assessmentId, int $lecturerId): array
    {
        $assessment = DB::row(
            "SELECT a.id, a.is_published, u.lecturer_id
             FROM assessments a
             JOIN units u ON u.id = a.unit_id
             WHERE a.id = ?",
            [$assessmentId]
        );

        if (!$assessment) {
            return ['success' => false, 'published' => false, 'message' => 'Assessment not found.'];
        }

        if ((int) $assessment['lecturer_id'] !== $lecturerId) {
            return ['success' => false, 'published' => false, 'message' => 'Unauthorised.'];
        }

        $newState = $assessment['is_published'] ? 0 : 1;

        DB::execute(
            "UPDATE assessments SET is_published = ? WHERE id = ?",
            [$newState, $assessmentId]
        );

        $label = $newState ? 'published' : 'unpublished';
        return [
            'success'   => true,
            'published' => (bool) $newState,
            'message'   => "Assessment {$label}. Students can " . ($newState ? 'now' : 'no longer') . ' see their marks.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Single mark entry
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Save or update a single student's mark for an assessment.
     * Uses ON DUPLICATE KEY UPDATE so calling this once is enough
     * for both new entries and corrections.
     *
     * Validates:
     *  - Student is enrolled in the unit
     *  - Score does not exceed max_score for the assessment
     *  - Score is not negative
     *
     * @param  int   $studentId
     * @param  int   $assessmentId
     * @param  float $score
     * @param  int   $uploadedBy
     * @return array { success: bool, message: string }
     */
    public static function saveMark(
        int   $studentId,
        int   $assessmentId,
        float $score,
        int   $uploadedBy
    ): array {
        $assessment = DB::row(
            "SELECT a.id, a.max_score, a.unit_id, u.lecturer_id
             FROM assessments a
             JOIN units u ON u.id = a.unit_id
             WHERE a.id = ?",
            [$assessmentId]
        );

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        if ($score < 0) {
            return ['success' => false, 'message' => 'Score cannot be negative.'];
        }

        if ($score > (float) $assessment['max_score']) {
            return [
                'success' => false,
                'message' => "Score {$score} exceeds maximum of {$assessment['max_score']} for this assessment.",
            ];
        }

        // Verify student is enrolled in this unit
        $enrolled = DB::row(
            "SELECT id FROM enrollments WHERE student_id = ? AND unit_id = ?",
            [$studentId, $assessment['unit_id']]
        );

        if (!$enrolled) {
            return ['success' => false, 'message' => 'Student is not enrolled in this unit.'];
        }

        DB::execute(
            "INSERT INTO marks (student_id, assessment_id, score, uploaded_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                score       = VALUES(score),
                uploaded_by = VALUES(uploaded_by),
                updated_at  = NOW()",
            [$studentId, $assessmentId, $score, $uploadedBy]
        );

        return ['success' => true, 'message' => 'Mark saved.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bulk CSV upload
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process a CSV file upload for a specific assessment.
     *
     * Expected CSV format (header row required):
     *   reg_number, score
     *
     * The method:
     *  1. Validates the uploaded file (size, MIME type)
     *  2. Parses the CSV rows
     *  3. Validates each row (reg number exists, is enrolled, score in range)
     *  4. Saves valid rows in a single transaction
     *  5. Returns a detailed result with per-row errors
     *
     * @param  array $file          $_FILES['csv'] array from the upload form
     * @param  int   $assessmentId
     * @param  int   $uploadedBy
     * @return array {
     *   success:       bool,
     *   saved:         int,   number of marks successfully saved
     *   skipped:       int,   rows with errors that were skipped
     *   errors:        array, list of { row, reg_number, reason }
     *   message:       string
     * }
     */
    public static function bulkUploadFromCsv(
        array $file,
        int   $assessmentId,
        int   $uploadedBy
    ): array {
        $result = [
            'success' => false,
            'saved'   => 0,
            'skipped' => 0,
            'errors'  => [],
            'message' => '',
        ];

        // 1. File validation
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['message'] = 'File upload failed. Error code: ' . $file['error'];
            return $result;
        }

        if ($file['size'] > MAX_CSV_SIZE_BYTES) {
            $result['message'] = 'File exceeds maximum size of ' . (MAX_CSV_SIZE_BYTES / 1024 / 1024) . ' MB.';
            return $result;
        }

        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, ALLOWED_CSV_MIMES, true)) {
            $result['message'] = 'Invalid file type. Please upload a CSV file.';
            return $result;
        }

        // 2. Parse CSV
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $result['message'] = 'Could not read the uploaded file.';
            return $result;
        }

        // Skip header row — detect and normalise column positions
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            $result['message'] = 'The CSV file is empty or has no header row.';
            return $result;
        }

        // Normalise header keys: lowercase + trim
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $regCol   = array_search('reg_number', $header, true);
        $scoreCol = array_search('score', $header, true);

        if ($regCol === false || $scoreCol === false) {
            fclose($handle);
            $result['message'] = 'CSV must have columns: reg_number, score';
            return $result;
        }

        // Fetch assessment details once
        $assessment = DB::row(
            "SELECT id, unit_id, max_score FROM assessments WHERE id = ?",
            [$assessmentId]
        );

        if (!$assessment) {
            fclose($handle);
            $result['message'] = 'Assessment not found.';
            return $result;
        }

        // 3. Validate each row and collect valid marks
        $validMarks = [];
        $rowNumber  = 1; // Starts at 1 after header

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip completely empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            $regNumber = trim($row[$regCol]  ?? '');
            $scoreRaw  = trim($row[$scoreCol] ?? '');

            // Validate reg_number present
            if (empty($regNumber)) {
                $result['errors'][] = ['row' => $rowNumber, 'reg_number' => '(empty)', 'reason' => 'Missing reg_number'];
                $result['skipped']++;
                continue;
            }

            // Validate score is numeric
            if (!is_numeric($scoreRaw)) {
                $result['errors'][] = ['row' => $rowNumber, 'reg_number' => $regNumber, 'reason' => 'Score is not a number'];
                $result['skipped']++;
                continue;
            }

            $score = (float) $scoreRaw;

            if ($score < 0) {
                $result['errors'][] = ['row' => $rowNumber, 'reg_number' => $regNumber, 'reason' => 'Score cannot be negative'];
                $result['skipped']++;
                continue;
            }

            if ($score > (float) $assessment['max_score']) {
                $result['errors'][] = [
                    'row'        => $rowNumber,
                    'reg_number' => $regNumber,
                    'reason'     => "Score {$score} exceeds max of {$assessment['max_score']}",
                ];
                $result['skipped']++;
                continue;
            }

            // Look up student by reg_number
            $student = DB::row(
                "SELECT id FROM users WHERE reg_number = ? AND role = 'student' AND is_active = 1",
                [$regNumber]
            );

            if (!$student) {
                $result['errors'][] = ['row' => $rowNumber, 'reg_number' => $regNumber, 'reason' => 'Student not found or inactive'];
                $result['skipped']++;
                continue;
            }

            // Check enrollment
            $enrolled = DB::row(
                "SELECT id FROM enrollments WHERE student_id = ? AND unit_id = ?",
                [$student['id'], $assessment['unit_id']]
            );

            if (!$enrolled) {
                $result['errors'][] = ['row' => $rowNumber, 'reg_number' => $regNumber, 'reason' => 'Student not enrolled in this unit'];
                $result['skipped']++;
                continue;
            }

            $validMarks[] = ['student_id' => $student['id'], 'score' => $score];
        }

        fclose($handle);

        // 4. Save all valid marks in a transaction
        if (!empty($validMarks)) {
            DB::beginTransaction();
            try {
                foreach ($validMarks as $mark) {
                    DB::execute(
                        "INSERT INTO marks (student_id, assessment_id, score, uploaded_by)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            score       = VALUES(score),
                            uploaded_by = VALUES(uploaded_by),
                            updated_at  = NOW()",
                        [$mark['student_id'], $assessmentId, $mark['score'], $uploadedBy]
                    );
                    $result['saved']++;
                }
                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
                $result['message'] = 'Database error while saving marks. No marks were saved.';
                $result['saved']   = 0;
                return $result;
            }
        }

        // 5. Return summary
        $result['success'] = true;
        $result['message'] = "{$result['saved']} mark(s) saved, {$result['skipped']} row(s) skipped.";
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grade views
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All marks for a student across all their enrolled units.
     * Only returns published assessments (is_published = 1).
     * Used by the student and parent portals.
     *
     * @param  int    $studentId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array  Grouped by unit_id for easy rendering
     */
    public static function getStudentMarks(
        int    $studentId,
        string $academicYear,
        int    $semester
    ): array {
        $rows = DB::rows(
            "SELECT
                a.id            AS assessment_id,
                a.name          AS assessment_name,
                a.type,
                a.max_score,
                a.weight_percent,
                a.assessment_date,
                m.score,
                u.id            AS unit_id,
                u.code          AS unit_code,
                u.name          AS unit_name
             FROM enrollments e
             JOIN units u        ON u.id  = e.unit_id
             JOIN assessments a  ON a.unit_id = u.id AND a.is_published = 1
             LEFT JOIN marks m   ON m.assessment_id = a.id AND m.student_id = e.student_id
             WHERE e.student_id   = ?
               AND e.academic_year = ?
               AND e.semester      = ?
             ORDER BY u.name ASC, a.assessment_date ASC",
            [$studentId, $academicYear, $semester]
        );

        // Group rows by unit and compute weighted total per unit
        $grouped = [];
        foreach ($rows as $row) {
            $uid = $row['unit_id'];

            if (!isset($grouped[$uid])) {
                $grouped[$uid] = [
                    'unit_id'       => $uid,
                    'unit_code'     => $row['unit_code'],
                    'unit_name'     => $row['unit_name'],
                    'assessments'   => [],
                    'weighted_total'=> 0.0,
                    'weight_earned' => 0.0,
                    'grade'         => null,
                    'grade_points'  => null,
                    'remark'        => null,
                ];
            }

            $weightedScore = null;
            if ($row['score'] !== null) {
                $weightedScore = round(
                    ($row['score'] / $row['max_score']) * $row['weight_percent'],
                    2
                );
                $grouped[$uid]['weighted_total'] += $weightedScore;
                $grouped[$uid]['weight_earned']  += (float) $row['weight_percent'];
            }

            $grouped[$uid]['assessments'][] = [
                'assessment_id'   => $row['assessment_id'],
                'name'            => $row['assessment_name'],
                'type'            => $row['type'],
                'max_score'       => $row['max_score'],
                'weight_percent'  => $row['weight_percent'],
                'assessment_date' => $row['assessment_date'],
                'score'           => $row['score'],
                'weighted_score'  => $weightedScore,
            ];
        }

        // Compute grade letter for each unit where all assessments are in
        foreach ($grouped as &$unit) {
            if ($unit['weight_earned'] >= 100) {
                $gradeInfo              = self::computeGrade($unit['weighted_total']);
                $unit['grade']          = $gradeInfo['grade'];
                $unit['grade_points']   = $gradeInfo['points'];
                $unit['remark']         = $gradeInfo['remark'];
            }
        }
        unset($unit);

        return array_values($grouped);
    }

    /**
     * All marks for all students in a unit — lecturer's full marks sheet.
     * Returns both published and unpublished assessments.
     *
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array
     */
    public static function getUnitMarksSheet(
        int    $unitId,
        string $academicYear,
        int    $semester
    ): array {
        // Get all assessments for this unit
        $assessments = DB::rows(
            "SELECT id, name, type, max_score, weight_percent, is_published
             FROM assessments
             WHERE unit_id = ?
             ORDER BY assessment_date ASC, id ASC",
            [$unitId]
        );

        // Get all enrolled students
        $students = DB::rows(
            "SELECT u.id, u.reg_number, u.full_name
             FROM enrollments e
             JOIN users u ON u.id = e.student_id
             WHERE e.unit_id      = ?
               AND e.academic_year = ?
               AND e.semester      = ?
             ORDER BY u.full_name ASC",
            [$unitId, $academicYear, $semester]
        );

        if (empty($assessments) || empty($students)) {
            return [
                'assessments' => $assessments,
                'students'    => [],
            ];
        }

        // Fetch all marks for this unit in one query
        $allMarks = DB::rows(
            "SELECT m.student_id, m.assessment_id, m.score
             FROM marks m
             JOIN assessments a ON a.id = m.assessment_id
             WHERE a.unit_id = ?",
            [$unitId]
        );

        // Index marks by [student_id][assessment_id] for O(1) lookup
        $marksIndex = [];
        foreach ($allMarks as $m) {
            $marksIndex[$m['student_id']][$m['assessment_id']] = $m['score'];
        }

        // Build student rows with their scores and weighted total
        foreach ($students as &$student) {
            $student['scores']          = [];
            $student['weighted_total']  = 0.0;
            $student['grade']           = null;

            $totalWeightAvailable = 0.0;
            $totalWeightScored    = 0.0;

            foreach ($assessments as $a) {
                $score = $marksIndex[$student['id']][$a['id']] ?? null;
                $student['scores'][$a['id']] = $score;

                $totalWeightAvailable += (float) $a['weight_percent'];

                if ($score !== null) {
                    $weighted = ($score / $a['max_score']) * $a['weight_percent'];
                    $student['weighted_total'] += $weighted;
                    $totalWeightScored += (float) $a['weight_percent'];
                }
            }

            // Only assign a grade when all published assessment marks are in
            if ($totalWeightScored >= $totalWeightAvailable && $totalWeightAvailable > 0) {
                $gradeInfo            = self::computeGrade(round($student['weighted_total'], 2));
                $student['grade']     = $gradeInfo['grade'];
                $student['remark']    = $gradeInfo['remark'];
            }

            $student['weighted_total'] = round($student['weighted_total'], 2);
        }
        unset($student);

        return [
            'assessments' => $assessments,
            'students'    => $students,
        ];
    }

    /**
     * Grade summary for all units a student is enrolled in.
     * Reads from vw_unit_grades. Used for the student's transcript view.
     *
     * @param  int $studentId
     * @return array
     */
    public static function getStudentTranscript(int $studentId): array
    {
        $rows = DB::rows(
            "SELECT
                unit_code,
                unit_name,
                weighted_total,
                assessments_submitted,
                assessments_total
             FROM vw_unit_grades
             WHERE student_id = ?
             ORDER BY unit_name ASC",
            [$studentId]
        );

        // Attach grade letter and compute GPA
        $totalPoints  = 0.0;
        $unitCount    = 0;

        foreach ($rows as &$row) {
            $gradeInfo           = self::computeGrade((float) $row['weighted_total']);
            $row['grade']        = $gradeInfo['grade'];
            $row['grade_points'] = $gradeInfo['points'];
            $row['remark']       = $gradeInfo['remark'];

            $totalPoints += $gradeInfo['points'];
            $unitCount++;
        }
        unset($row);

        $gpa = $unitCount > 0 ? round($totalPoints / $unitCount, 2) : null;

        return [
            'units' => $rows,
            'gpa'   => $gpa,
            'total_units' => $unitCount,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute grade letter, grade points, and remark from a weighted total.
     *
     * @param  float $weightedTotal  Score out of 100
     * @return array { grade, points, remark }
     */
    public static function computeGrade(float $weightedTotal): array
    {
        foreach (self::$gradeBoundaries as $boundary) {
            if ($weightedTotal >= $boundary['min']) {
                return [
                    'grade'  => $boundary['grade'],
                    'points' => $boundary['points'],
                    'remark' => $boundary['remark'],
                ];
            }
        }

        // Fallback — should never reach here given boundaries cover 0–100
        return ['grade' => 'E', 'points' => 0.0, 'remark' => 'Fail'];
    }

    /**
     * Get the sum of all assessment weights already defined for a unit.
     * Used to prevent the total going over 100%.
     *
     * @param  int $unitId
     * @return float
     */
    private static function getTotalWeight(int $unitId): float
    {
        $row = DB::row(
            "SELECT COALESCE(SUM(weight_percent), 0) AS total
             FROM assessments
             WHERE unit_id = ?",
            [$unitId]
        );

        return (float) ($row['total'] ?? 0);
    }

    /** Prevent instantiation */
    private function __construct() {}
}