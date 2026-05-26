<?php
/**
 * EduTrack — User Model
 *
 * All database reads and writes related to user accounts live here.
 * Covers:
 *   - Creating accounts for all roles (admin, lecturer, student, parent)
 *   - Fetching user profiles and lists
 *   - Password changes and resets
 *   - Parent-student link management
 *   - Account activation / deactivation
 *   - Enrollment management (assign students to units)
 *   - Role-specific dashboard summary data
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class UserModel
{
    // ─────────────────────────────────────────────────────────────────────────
    // Account creation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new user account.
     *
     * Validates:
     *  - reg_number is unique across all users
     *  - email is unique if provided
     *  - password meets minimum strength requirements
     *  - role is one of the four valid values
     *
     * Hashes the password with bcrypt + PASSWORD_PEPPER before storing.
     * Never stores plain text.
     *
     * @param  array $data {
     *   reg_number, full_name, email (optional), phone (optional),
     *   password, role, created_by (admin user ID)
     * }
     * @return array { success: bool, id: int|null, message: string }
     */
    public static function create(array $data): array
    {
        $validRoles = ['admin', 'lecturer', 'student', 'parent'];

        // Sanitise inputs
        $regNumber = strtoupper(trim($data['reg_number'] ?? ''));
        $fullName  = trim($data['full_name'] ?? '');
        $email     = trim($data['email'] ?? '') ?: null;
        $phone     = trim($data['phone'] ?? '') ?: null;
        $password  = $data['password'] ?? '';
        $role      = $data['role'] ?? '';
        $createdBy = $data['created_by'] ?? null;

        // Required field checks
        if (empty($regNumber)) {
            return ['success' => false, 'id' => null, 'message' => 'Registration number is required.'];
        }

        if (empty($fullName)) {
            return ['success' => false, 'id' => null, 'message' => 'Full name is required.'];
        }

        if (!in_array($role, $validRoles, true)) {
            return ['success' => false, 'id' => null, 'message' => 'Invalid role specified.'];
        }

        // Password strength
        $passwordCheck = self::validatePasswordStrength($password);
        if (!$passwordCheck['valid']) {
            return ['success' => false, 'id' => null, 'message' => $passwordCheck['message']];
        }

        // Unique reg_number check
        $existing = DB::row(
            "SELECT id FROM users WHERE reg_number = ?",
            [$regNumber]
        );
        if ($existing) {
            return ['success' => false, 'id' => null, 'message' => "Registration number '{$regNumber}' is already in use."];
        }

        // Unique email check (only if email provided)
        if ($email) {
            $emailExists = DB::row(
                "SELECT id FROM users WHERE email = ?",
                [$email]
            );
            if ($emailExists) {
                return ['success' => false, 'id' => null, 'message' => "Email '{$email}' is already registered."];
            }
        }

        // Hash password
        $hash              = password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);
        $mustChangePw      = !empty($data['must_change_password']) ? 1 : 0;

        $id = DB::insert(
            "INSERT INTO users
                (reg_number, full_name, email, phone, password_hash, role, created_by, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$regNumber, $fullName, $email, $phone, $hash, $role, $createdBy, $mustChangePw]
        );

        return [
            'success' => true,
            'id'      => (int) $id,
            'message' => ucfirst($role) . " account created successfully.",
        ];
    }

    /**
     * Bulk-create student accounts from a CSV array.
     * Each row must have: reg_number, full_name, email (optional), phone (optional).
     * A default password is generated for each student.
     *
     * @param  array  $rows        Array of associative arrays from CSV parse
     * @param  int    $createdBy   Admin user ID
     * @param  string $defaultPass Default password for all new students
     * @return array { saved: int, skipped: int, errors: array }
     */
    public static function bulkCreateStudents(
        array  $rows,
        int    $createdBy,
        string $defaultPass = 'Student@1'
    ): array {
        $result = ['saved' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // Account for header row

            $res = self::create([
                'reg_number' => $row['reg_number']  ?? '',
                'full_name'  => $row['full_name']   ?? '',
                'email'      => $row['email']        ?? '',
                'phone'      => $row['phone']        ?? '',
                'password'   => $defaultPass,
                'role'       => 'student',
                'created_by' => $createdBy,
            ]);

            if ($res['success']) {
                $result['saved']++;
            } else {
                $result['skipped']++;
                $result['errors'][] = [
                    'row'    => $rowNum,
                    'reg'    => $row['reg_number'] ?? '(empty)',
                    'reason' => $res['message'],
                ];
            }
        }

        return $result;
    }

    /**
     * Bulk-create lecturer accounts from a parsed CSV array.
     *
     * Each row: full_name (required), email (optional), phone (optional).
     * Reg numbers are auto-generated (LEC001, LEC002, …).
     * All accounts are created with must_change_password = 1.
     *
     * @param  array  $rows        Array of associative arrays
     * @param  int    $createdBy   Admin user ID
     * @param  string $defaultPass Default password (must pass strength rules)
     * @return array {
     *   created: int,
     *   skipped: int,
     *   errors:  array,
     *   accounts: array  — newly created accounts for the credentials report
     * }
     */
    public static function bulkCreateLecturers(
        array  $rows,
        int    $createdBy,
        string $defaultPass = 'Lecturer@1'
    ): array {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'accounts' => []];

        foreach ($rows as $i => $row) {
            $rowNum   = $i + 2;
            $fullName = trim($row['full_name'] ?? '');
            $email    = trim($row['email']     ?? '') ?: null;
            $phone    = trim($row['phone']     ?? '') ?: null;

            if (empty($fullName)) {
                $result['errors'][] = ['row' => $rowNum, 'name' => '(blank)', 'reason' => 'full_name is required.'];
                $result['skipped']++;
                continue;
            }

            $regNumber = self::generateRegNumber('lecturer');

            $res = self::create([
                'reg_number'          => $regNumber,
                'full_name'           => $fullName,
                'email'               => $email,
                'phone'               => $phone,
                'password'            => $defaultPass,
                'role'                => 'lecturer',
                'created_by'          => $createdBy,
                'must_change_password'=> 1,
            ]);

            if ($res['success']) {
                $result['created']++;
                $result['accounts'][] = [
                    'name'       => $fullName,
                    'reg_number' => $regNumber,
                    'email'      => $email,
                    'phone'      => $phone,
                    'temp_pass'  => $defaultPass,
                ];
            } else {
                $result['skipped']++;
                $result['errors'][] = ['row' => $rowNum, 'name' => $fullName, 'reason' => $res['message']];
            }
        }

        return $result;
    }

    /**
     * Bulk-create parent accounts from a parsed CSV array, with optional
     * immediate student linking.
     *
     * Each row: full_name, email (optional), phone (optional),
     *           student_reg_number (optional), relationship (optional).
     *
     * A parent who appears on multiple rows (two children) creates ONE account
     * and is linked to each child.  Detection uses email (if provided) or
     * full_name + phone as a fallback key.
     *
     * Reg numbers are auto-generated (PAR001, PAR002, …).
     * All new accounts are created with must_change_password = 1.
     *
     * @param  array  $rows
     * @param  int    $createdBy
     * @param  string $defaultPass
     * @return array {
     *   created: int,
     *   linked:  int,
     *   skipped: int,
     *   errors:  array,
     *   accounts: array
     * }
     */
    public static function bulkCreateParents(
        array  $rows,
        int    $createdBy,
        string $defaultPass = 'Parent@1'
    ): array {
        $result = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => [], 'accounts' => []];

        // Cache of email/key → parent_id for parents created in this batch
        // Prevents creating duplicate accounts when same parent has multiple children in CSV
        $batchCache = [];  // key => parent_id

        foreach ($rows as $i => $row) {
            $rowNum       = $i + 2;
            $fullName     = trim($row['full_name']        ?? '');
            $email        = trim($row['email']            ?? '') ?: null;
            $phone        = trim($row['phone']            ?? '') ?: null;
            $studentReg   = strtoupper(trim($row['student_reg_number'] ?? ''));
            $relationship = trim($row['relationship']     ?? '') ?: 'Parent';

            if (empty($fullName)) {
                $result['errors'][] = ['row' => $rowNum, 'name' => '(blank)', 'reason' => 'full_name is required.'];
                $result['skipped']++;
                continue;
            }

            // Build a de-duplication key for this batch
            $dedupKey = $email
                ? 'email:' . strtolower($email)
                : 'name_phone:' . strtolower($fullName) . '|' . ($phone ?? '');

            // ── Resolve or create the parent account ──────────────────────────
            $parentId = null;
            $isNew    = false;

            if (isset($batchCache[$dedupKey])) {
                // Already created in this CSV run
                $parentId = $batchCache[$dedupKey];
            } elseif ($email) {
                // Check if a parent with this email already exists in the DB
                $existing = DB::row(
                    "SELECT id, role FROM users WHERE email = ? LIMIT 1",
                    [$email]
                );
                if ($existing) {
                    if ($existing['role'] !== 'parent') {
                        $result['errors'][] = [
                            'row'    => $rowNum,
                            'name'   => $fullName,
                            'reason' => "Email '{$email}' belongs to a non-parent account.",
                        ];
                        $result['skipped']++;
                        continue;
                    }
                    $parentId = (int) $existing['id'];
                    $batchCache[$dedupKey] = $parentId;
                }
            }

            if ($parentId === null) {
                // Create new parent account
                $regNumber = self::generateRegNumber('parent');

                $res = self::create([
                    'reg_number'          => $regNumber,
                    'full_name'           => $fullName,
                    'email'               => $email,
                    'phone'               => $phone,
                    'password'            => $defaultPass,
                    'role'                => 'parent',
                    'created_by'          => $createdBy,
                    'must_change_password'=> 1,
                ]);

                if (!$res['success']) {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNum, 'name' => $fullName, 'reason' => $res['message']];
                    continue;
                }

                $parentId = $res['id'];
                $isNew    = true;
                $result['created']++;
                $batchCache[$dedupKey] = $parentId;

                $result['accounts'][] = [
                    'name'        => $fullName,
                    'reg_number'  => $regNumber,
                    'email'       => $email,
                    'phone'       => $phone,
                    'temp_pass'   => $defaultPass,
                    'linked_to'   => [],   // filled below
                ];
            }

            // ── Link to student (if provided) ─────────────────────────────────
            if ($studentReg !== '') {
                $student = self::findByRegNumber($studentReg);
                if (!$student || $student['role'] !== 'student') {
                    $result['errors'][] = [
                        'row'    => $rowNum,
                        'name'   => $fullName,
                        'reason' => "Student reg '{$studentReg}' not found or not a student account.",
                    ];
                } else {
                    $link = self::linkParentToStudent($parentId, $student['id'], $relationship);
                    if ($link['success']) {
                        $result['linked']++;
                        // Append to this parent's linked_to list in accounts array
                        foreach ($result['accounts'] as &$acc) {
                            if ($acc['reg_number'] === ($batchCache[$dedupKey] > 0
                                ? self::findById($parentId)['reg_number'] ?? ''
                                : '')) {
                                $acc['linked_to'][] = $studentReg;
                                break;
                            }
                        }
                        unset($acc);
                        // Simpler: just tag the last-created account if isNew
                        if ($isNew && !empty($result['accounts'])) {
                            $result['accounts'][count($result['accounts']) - 1]['linked_to'][] = $studentReg;
                        }
                    }
                    // Already linked → not an error, just skip silently
                }
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Profile reads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a single user by ID.
     * Never returns the password_hash — stripped before returning.
     *
     * @param  int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        $row = DB::row(
            "SELECT id, reg_number, full_name, email, phone, role, is_active,
                    last_login, created_at
             FROM users
             WHERE id = ?",
            [$id]
        );

        return $row ?: null;
    }

    /**
     * Fetch a single user by registration number.
     *
     * @param  string $regNumber
     * @return array|null
     */
    public static function findByRegNumber(string $regNumber): ?array
    {
        $row = DB::row(
            "SELECT id, reg_number, full_name, email, phone, role, is_active, last_login
             FROM users
             WHERE reg_number = ?",
            [strtoupper(trim($regNumber))]
        );

        return $row ?: null;
    }

    /**
     * Paginated list of users — used by the admin panel.
     * Supports filtering by role and searching by name or reg_number.
     *
     * @param  string $role    'all' or one of the four roles
     * @param  string $search  Search term (name or reg_number)
     * @param  int    $page
     * @param  int    $perPage
     * @return array { rows: array, total: int, pages: int }
     */
    public static function listUsers(
        string $role    = 'all',
        string $search  = '',
        int    $page    = 1,
        int    $perPage = ROWS_PER_PAGE
    ): array {
        $params      = [];
        $conditions  = [];

        if ($role !== 'all') {
            $conditions[] = 'role = ?';
            $params[]     = $role;
        }

        if (!empty(trim($search))) {
            $conditions[] = '(full_name LIKE ? OR reg_number LIKE ?)';
            $like         = '%' . trim($search) . '%';
            $params[]     = $like;
            $params[]     = $like;
        }

        $where  = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $offset = ($page - 1) * $perPage;

        $total = DB::row(
            "SELECT COUNT(*) AS cnt FROM users {$where}",
            $params
        )['cnt'] ?? 0;

        $rows = DB::rows(
            "SELECT id, reg_number, full_name, email, phone, role, is_active, last_login, created_at
             FROM users
             {$where}
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        return [
            'rows'  => $rows,
            'total' => (int) $total,
            'pages' => (int) ceil($total / $perPage),
            'page'  => $page,
        ];
    }

    /**
     * Get all lecturers as a simple id → name array.
     * Used to populate unit assignment dropdowns.
     *
     * @return array
     */
    public static function getLecturerOptions(): array
    {
        return DB::rows(
            "SELECT id, reg_number, full_name
             FROM users
             WHERE role = 'lecturer' AND is_active = 1
             ORDER BY full_name ASC"
        );
    }

    /**
     * Get all students as a simple list.
     * Used by the admin enrollment page.
     *
     * @param  string $search  Optional name/reg filter
     * @return array
     */
    public static function getStudentOptions(string $search = ''): array
    {
        $params = [];
        $filter = '';

        if (!empty(trim($search))) {
            $filter   = 'AND (full_name LIKE ? OR reg_number LIKE ?)';
            $like     = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        return DB::rows(
            "SELECT id, reg_number, full_name
             FROM users
             WHERE role = 'student' AND is_active = 1
             {$filter}
             ORDER BY full_name ASC
             LIMIT 100",
            $params
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Profile updates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update a user's contact details (email and phone).
     * reg_number and role cannot be changed here — use admin tools for that.
     *
     * @param  int    $userId
     * @param  string $email
     * @param  string $phone
     * @return array { success: bool, message: string }
     */
    public static function updateContact(int $userId, string $email, string $phone): array
    {
        $email = trim($email) ?: null;
        $phone = trim($phone) ?: null;

        // Check email uniqueness (excluding own account)
        if ($email) {
            $conflict = DB::row(
                "SELECT id FROM users WHERE email = ? AND id != ?",
                [$email, $userId]
            );
            if ($conflict) {
                return ['success' => false, 'message' => 'That email address is already in use by another account.'];
            }
        }

        DB::execute(
            "UPDATE users SET email = ?, phone = ? WHERE id = ?",
            [$email, $phone, $userId]
        );

        return ['success' => true, 'message' => 'Contact details updated.'];
    }

    /**
     * Change a user's password after verifying their current password.
     * Used on the profile page — any role can change their own password.
     *
     * @param  int    $userId
     * @param  string $currentPassword  Plain text current password
     * @param  string $newPassword      Plain text new password
     * @return array { success: bool, message: string }
     */
    public static function changePassword(
        int    $userId,
        string $currentPassword,
        string $newPassword
    ): array {
        // Fetch the stored hash
        $row = DB::row(
            "SELECT password_hash FROM users WHERE id = ?",
            [$userId]
        );

        if (!$row) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        // Verify current password
        if (!password_verify($currentPassword . PASSWORD_PEPPER, $row['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }

        // Validate new password strength
        $check = self::validatePasswordStrength($newPassword);
        if (!$check['valid']) {
            return ['success' => false, 'message' => $check['message']];
        }

        // Prevent re-use of the same password
        if (password_verify($newPassword . PASSWORD_PEPPER, $row['password_hash'])) {
            return ['success' => false, 'message' => 'New password must be different from your current password.'];
        }

        $hash = password_hash($newPassword . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);

        DB::execute(
            "UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?",
            [$hash, $userId]
        );

        // Clear the session flag so the sidebar notice disappears immediately
        Auth::clearMustChangePassword();

        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    /**
     * Admin-only: reset a user's password to a new value without
     * requiring the current password.
     *
     * @param  int    $targetUserId
     * @param  string $newPassword
     * @param  int    $adminId       ID of the admin performing the reset
     * @return array { success: bool, message: string }
     */
    public static function adminResetPassword(
        int    $targetUserId,
        string $newPassword,
        int    $adminId
    ): array {
        $check = self::validatePasswordStrength($newPassword);
        if (!$check['valid']) {
            return ['success' => false, 'message' => $check['message']];
        }

        $hash = password_hash($newPassword . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);

        $affected = DB::execute(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$hash, $targetUserId]
        );

        if (!$affected) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        Auth::audit('password_reset', 'users', $targetUserId, ['reset_by' => $adminId]);

        return ['success' => true, 'message' => 'Password reset successfully.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Account status
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Activate or deactivate a user account.
     * Deactivated users cannot log in (Auth::attempt() checks is_active).
     * An admin cannot deactivate their own account.
     *
     * @param  int  $targetUserId
     * @param  bool $active
     * @param  int  $adminId
     * @return array { success: bool, message: string }
     */
    public static function setActive(int $targetUserId, bool $active, int $adminId): array
    {
        if ($targetUserId === $adminId && !$active) {
            return ['success' => false, 'message' => 'You cannot deactivate your own account.'];
        }

        $affected = DB::execute(
            "UPDATE users SET is_active = ? WHERE id = ?",
            [(int) $active, $targetUserId]
        );

        if (!$affected) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $label = $active ? 'activated' : 'deactivated';
        Auth::audit("user_{$label}", 'users', $targetUserId, ['by_admin' => $adminId]);

        return ['success' => true, 'message' => "Account {$label} successfully."];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Parent–student links
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Link a parent account to a student account.
     *
     * @param  int    $parentId
     * @param  int    $studentId
     * @param  string $relationship  e.g. 'Mother', 'Father', 'Guardian'
     * @return array { success: bool, message: string }
     */
    public static function linkParentToStudent(
        int    $parentId,
        int    $studentId,
        string $relationship = 'Parent'
    ): array {
        // Verify both accounts exist and have correct roles
        $parent  = self::findById($parentId);
        $student = self::findById($studentId);

        if (!$parent || $parent['role'] !== 'parent') {
            return ['success' => false, 'message' => 'Parent account not found.'];
        }

        if (!$student || $student['role'] !== 'student') {
            return ['success' => false, 'message' => 'Student account not found.'];
        }

        // Check if already linked
        $exists = DB::row(
            "SELECT parent_id FROM parent_student_links
             WHERE parent_id = ? AND student_id = ?",
            [$parentId, $studentId]
        );

        if ($exists) {
            return ['success' => false, 'message' => 'This parent is already linked to this student.'];
        }

        DB::insert(
            "INSERT INTO parent_student_links (parent_id, student_id, relationship)
             VALUES (?, ?, ?)",
            [$parentId, $studentId, trim($relationship)]
        );

        return [
            'success' => true,
            'message' => "{$parent['full_name']} linked to {$student['full_name']} as {$relationship}.",
        ];
    }

    /**
     * Remove a parent-student link.
     *
     * @param  int $parentId
     * @param  int $studentId
     * @return array { success: bool, message: string }
     */
    public static function unlinkParentFromStudent(int $parentId, int $studentId): array
    {
        $affected = DB::execute(
            "DELETE FROM parent_student_links WHERE parent_id = ? AND student_id = ?",
            [$parentId, $studentId]
        );

        if (!$affected) {
            return ['success' => false, 'message' => 'Link not found.'];
        }

        return ['success' => true, 'message' => 'Parent-student link removed.'];
    }

    /**
     * Get all students linked to a parent.
     *
     * @param  int $parentId
     * @return array
     */
    public static function getLinkedStudents(int $parentId): array
    {
        return DB::rows(
            "SELECT
                u.id,
                u.reg_number,
                u.full_name,
                u.email,
                u.phone,
                psl.relationship,
                psl.linked_at
             FROM parent_student_links psl
             JOIN users u ON u.id = psl.student_id
             WHERE psl.parent_id = ?
             ORDER BY u.full_name ASC",
            [$parentId]
        );
    }

    /**
     * Get all parents linked to a student.
     *
     * @param  int $studentId
     * @return array
     */
    public static function getLinkedParents(int $studentId): array
    {
        return DB::rows(
            "SELECT
                u.id,
                u.full_name,
                u.email,
                u.phone,
                psl.relationship,
                psl.linked_at
             FROM parent_student_links psl
             JOIN users u ON u.id = psl.parent_id
             WHERE psl.student_id = ?
             ORDER BY u.full_name ASC",
            [$studentId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enrollment management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enroll a student in a unit for the current academic year.
     *
     * @param  int    $studentId
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array { success: bool, message: string }
     */
    public static function enrollStudent(
        int    $studentId,
        int    $unitId,
        string $academicYear,
        int    $semester
    ): array {
        $student = self::findById($studentId);
        if (!$student || $student['role'] !== 'student') {
            return ['success' => false, 'message' => 'Student not found.'];
        }

        $unit = DB::row("SELECT id, code, name FROM units WHERE id = ?", [$unitId]);
        if (!$unit) {
            return ['success' => false, 'message' => 'Unit not found.'];
        }

        // Check for existing enrollment
        $exists = DB::row(
            "SELECT id FROM enrollments
             WHERE student_id = ? AND unit_id = ? AND academic_year = ? AND semester = ?",
            [$studentId, $unitId, $academicYear, $semester]
        );

        if ($exists) {
            return ['success' => false, 'message' => "{$student['full_name']} is already enrolled in {$unit['code']}."];
        }

        DB::insert(
            "INSERT INTO enrollments (student_id, unit_id, academic_year, semester)
             VALUES (?, ?, ?, ?)",
            [$studentId, $unitId, $academicYear, $semester]
        );

        return [
            'success' => true,
            'message' => "{$student['full_name']} enrolled in {$unit['code']} — {$unit['name']}.",
        ];
    }

    /**
     * Remove a student from a unit.
     *
     * @param  int    $studentId
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array { success: bool, message: string }
     */
    public static function unenrollStudent(
        int    $studentId,
        int    $unitId,
        string $academicYear,
        int    $semester
    ): array {
        $affected = DB::execute(
            "DELETE FROM enrollments
             WHERE student_id = ? AND unit_id = ? AND academic_year = ? AND semester = ?",
            [$studentId, $unitId, $academicYear, $semester]
        );

        if (!$affected) {
            return ['success' => false, 'message' => 'Enrollment not found.'];
        }

        return ['success' => true, 'message' => 'Student unenrolled successfully.'];
    }

    /**
     * Get all units a student is enrolled in for a given year/semester.
     *
     * @param  int    $studentId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array
     */
    public static function getStudentEnrollments(
        int    $studentId,
        string $academicYear,
        int    $semester
    ): array {
        return DB::rows(
            "SELECT
                u.id            AS unit_id,
                u.code          AS unit_code,
                u.name          AS unit_name,
                u.credit_hours,
                lec.full_name   AS lecturer_name,
                e.enrolled_at
             FROM enrollments e
             JOIN units u    ON u.id   = e.unit_id
             LEFT JOIN users lec ON lec.id = u.lecturer_id
             WHERE e.student_id   = ?
               AND e.academic_year = ?
               AND e.semester      = ?
             ORDER BY u.name ASC",
            [$studentId, $academicYear, $semester]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Course-level enrollment (master records)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enroll a student in a course for a given academic period.
     *
     * Creates one row in student_course_enrollments, then derives unit-level
     * enrollment rows for every active unit matching the course + year + semester
     * combination.  Both operations are idempotent (duplicate rows silently skipped).
     *
     * @param  int    $studentId
     * @param  int    $courseId
     * @param  int    $yearOfStudy    1–6
     * @param  string $academicYear   e.g. "2025/2026"
     * @param  int    $semester       1 or 2
     * @param  string $source         "manual" | "csv"
     * @param  int    $enrolledBy     Admin user ID
     * @return array {
     *   success: bool,
     *   message: string,
     *   units_enrolled: int,
     *   zero_units: bool    — true if no units exist yet (enroll succeeds with warning)
     * }
     */
    public static function enrollStudentInCourse(
        int    $studentId,
        int    $courseId,
        int    $yearOfStudy,
        string $academicYear,
        int    $semester,
        string $source     = 'manual',
        int    $enrolledBy = 0
    ): array {
        // Verify student
        $student = self::findById($studentId);
        if (!$student || $student['role'] !== 'student') {
            return ['success' => false, 'message' => 'Student not found.', 'units_enrolled' => 0, 'zero_units' => false];
        }

        // Verify course
        $course = DB::row("SELECT id, name, code FROM courses WHERE id = ?", [$courseId]);
        if (!$course) {
            return ['success' => false, 'message' => 'Course not found.', 'units_enrolled' => 0, 'zero_units' => false];
        }

        // Upsert the master record (idempotent)
        $existing = DB::row(
            "SELECT id FROM student_course_enrollments
             WHERE student_id = ? AND course_id = ? AND academic_year = ? AND semester = ?",
            [$studentId, $courseId, $academicYear, $semester]
        );

        if (!$existing) {
            DB::insert(
                "INSERT INTO student_course_enrollments
                    (student_id, course_id, year_of_study, academic_year, semester, source, enrolled_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $studentId,
                    $courseId,
                    $yearOfStudy,
                    $academicYear,
                    $semester,
                    $source,
                    $enrolledBy ?: null,
                ]
            );
        }

        // Derive unit enrollments
        $unitsEnrolled = self::deriveCourseUnitEnrollments(
            $studentId, $courseId, $yearOfStudy, $academicYear, $semester
        );

        $zeroUnits = ($unitsEnrolled === 0);
        $message   = $existing
            ? "{$student['full_name']} is already enrolled in {$course['code']} for {$academicYear} Semester {$semester}."
            : "{$student['full_name']} enrolled in {$course['code']} ({$academicYear} S{$semester}).";

        if (!$existing) {
            if ($zeroUnits) {
                $message .= " No units set up yet — the student will be auto-enrolled when units are added.";
            } else {
                $message .= " Auto-enrolled in {$unitsEnrolled} unit(s).";
            }
        }

        return [
            'success'       => true,
            'message'       => $message,
            'units_enrolled' => $unitsEnrolled,
            'zero_units'    => $zeroUnits,
            'already_existed' => (bool) $existing,
        ];
    }

    /**
     * Derive unit-level enrollment rows from an existing course enrollment.
     *
     * Looks up every active unit for the given course + year + semester,
     * then inserts an enrollment row for each one (skipping duplicates).
     *
     * @param  int    $studentId
     * @param  int    $courseId
     * @param  int    $yearOfStudy
     * @param  string $academicYear
     * @param  int    $semester
     * @return int    Number of NEW unit enrollments created
     */
    public static function deriveCourseUnitEnrollments(
        int    $studentId,
        int    $courseId,
        int    $yearOfStudy,
        string $academicYear,
        int    $semester
    ): int {
        $units = DB::rows(
            "SELECT id FROM units
             WHERE course_id = ? AND year_of_study = ? AND semester = ? AND is_active = 1",
            [$courseId, $yearOfStudy, $semester]
        );

        $created = 0;
        foreach ($units as $unit) {
            $dup = DB::row(
                "SELECT id FROM enrollments
                 WHERE student_id = ? AND unit_id = ? AND academic_year = ? AND semester = ?",
                [$studentId, $unit['id'], $academicYear, $semester]
            );
            if (!$dup) {
                DB::insert(
                    "INSERT INTO enrollments (student_id, unit_id, academic_year, semester)
                     VALUES (?, ?, ?, ?)",
                    [$studentId, $unit['id'], $academicYear, $semester]
                );
                $created++;
            }
        }

        return $created;
    }

    /**
     * Remove a student's course enrollment (and all derived unit enrollments)
     * for a given academic period.
     *
     * @param  int    $studentId
     * @param  int    $courseId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array { success: bool, message: string, units_removed: int }
     */
    public static function unenrollStudentFromCourse(
        int    $studentId,
        int    $courseId,
        string $academicYear,
        int    $semester
    ): array {
        // Find the master record
        $sce = DB::row(
            "SELECT sce.id, sce.year_of_study
             FROM student_course_enrollments sce
             WHERE sce.student_id = ? AND sce.course_id = ?
               AND sce.academic_year = ? AND sce.semester = ?",
            [$studentId, $courseId, $academicYear, $semester]
        );

        if (!$sce) {
            return ['success' => false, 'message' => 'Course enrollment not found.', 'units_removed' => 0];
        }

        // Count unit enrollments that will be removed
        $unitCount = (int) DB::row(
            "SELECT COUNT(*) AS cnt
             FROM enrollments e
             JOIN units u ON u.id = e.unit_id
             WHERE e.student_id = ? AND u.course_id = ?
               AND e.academic_year = ? AND e.semester = ?",
            [$studentId, $courseId, $academicYear, $semester]
        )['cnt'];

        // Delete unit enrollments (cascade would handle this, but be explicit)
        DB::execute(
            "DELETE e FROM enrollments e
             JOIN units u ON u.id = e.unit_id
             WHERE e.student_id = ? AND u.course_id = ?
               AND e.academic_year = ? AND e.semester = ?",
            [$studentId, $courseId, $academicYear, $semester]
        );

        // Delete master record
        DB::execute(
            "DELETE FROM student_course_enrollments WHERE id = ?",
            [$sce['id']]
        );

        $student = self::findById($studentId);
        $course  = DB::row("SELECT code FROM courses WHERE id = ?", [$courseId]);

        return [
            'success'       => true,
            'message'       => ($student['full_name'] ?? 'Student') . " unenrolled from " .
                               ($course['code'] ?? 'course') . " ({$academicYear} S{$semester}).",
            'units_removed' => $unitCount,
        ];
    }

    /**
     * Get the current course enrollment for a student (the active academic period).
     *
     * @param  int    $studentId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array|null  The SCE row with course details, or null if not enrolled
     */
    public static function getStudentCourseEnrollment(
        int    $studentId,
        string $academicYear,
        int    $semester
    ): ?array {
        return DB::row(
            "SELECT sce.*, c.code AS course_code, c.name AS course_name
             FROM student_course_enrollments sce
             JOIN courses c ON c.id = sce.course_id
             WHERE sce.student_id = ? AND sce.academic_year = ? AND sce.semester = ?",
            [$studentId, $academicYear, $semester]
        ) ?: null;
    }

    /**
     * Get the full enrollment history for a student, grouped by academic period.
     *
     * Each entry contains the course enrollment details plus a nested list of
     * unit enrollments for that period.
     *
     * @param  int $studentId
     * @return array  Ordered newest-first
     */
    public static function getStudentCourseHistory(int $studentId): array
    {
        $periods = DB::rows(
            "SELECT
                sce.id,
                sce.academic_year,
                sce.semester,
                sce.year_of_study,
                sce.source,
                sce.enrolled_at,
                c.id   AS course_id,
                c.code AS course_code,
                c.name AS course_name
             FROM student_course_enrollments sce
             JOIN courses c ON c.id = sce.course_id
             WHERE sce.student_id = ?
             ORDER BY sce.academic_year DESC, sce.semester DESC",
            [$studentId]
        );

        foreach ($periods as &$p) {
            $p['units'] = DB::rows(
                "SELECT
                    u.id        AS unit_id,
                    u.code      AS unit_code,
                    u.name      AS unit_name,
                    u.credit_hours,
                    lec.full_name AS lecturer_name,
                    e.enrolled_at
                 FROM enrollments e
                 JOIN units u    ON u.id = e.unit_id
                 LEFT JOIN users lec ON lec.id = u.lecturer_id
                 WHERE e.student_id   = ?
                   AND e.academic_year = ?
                   AND e.semester      = ?
                   AND u.course_id     = ?
                 ORDER BY u.name ASC",
                [$studentId, $p['academic_year'], $p['semester'], $p['course_id']]
            );
        }
        unset($p);

        return $periods;
    }

    /**
     * Get all course enrollments for the admin enrollment page,
     * with counts for a given academic period.
     *
     * @param  string $academicYear
     * @param  int    $semester
     * @param  string $search       Optional name/reg filter
     * @return array
     */
    public static function getCourseEnrollmentList(
        string $academicYear,
        int    $semester,
        string $search = ''
    ): array {
        $params = [$academicYear, $semester];
        $where  = '';

        if ($search !== '') {
            $like   = '%' . $search . '%';
            $where  = " AND (u.full_name LIKE ? OR u.reg_number LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }

        return DB::rows(
            "SELECT
                sce.id,
                sce.student_id,
                sce.year_of_study,
                sce.source,
                sce.enrolled_at,
                u.reg_number,
                u.full_name,
                c.id   AS course_id,
                c.code AS course_code,
                c.name AS course_name,
                (SELECT COUNT(*) FROM enrollments e
                 JOIN units un ON un.id = e.unit_id
                 WHERE e.student_id   = sce.student_id
                   AND e.academic_year = sce.academic_year
                   AND e.semester      = sce.semester
                   AND un.course_id    = sce.course_id) AS unit_count
             FROM student_course_enrollments sce
             JOIN users   u ON u.id   = sce.student_id
             JOIN courses c ON c.id   = sce.course_id
             WHERE sce.academic_year = ? AND sce.semester = ?{$where}
             ORDER BY u.full_name ASC",
            $params
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dashboard summaries
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Quick stats for the admin dashboard.
     * Returns counts of each role, active sessions, and recent registrations.
     *
     * @return array
     */
    public static function getAdminStats(): array
    {
        $counts = DB::rows(
            "SELECT role, COUNT(*) AS total, SUM(is_active) AS active
             FROM users
             GROUP BY role"
        );

        $stats = ['admin' => 0, 'lecturer' => 0, 'student' => 0, 'parent' => 0];
        foreach ($counts as $row) {
            $stats[$row['role']] = (int) $row['active'];
        }

        $stats['active_sessions'] = (int) DB::row(
            "SELECT COUNT(*) AS cnt FROM attendance_sessions WHERE is_active = 1"
        )['cnt'];

        $stats['pending_disputes'] = (int) DB::row(
            "SELECT COUNT(*) AS cnt FROM disputes WHERE status = 'pending'"
        )['cnt'];

        $stats['recent_users'] = DB::rows(
            "SELECT reg_number, full_name, role, created_at
             FROM users
             ORDER BY created_at DESC
             LIMIT 5"
        );

        return $stats;
    }

    /**
     * Quick stats for the lecturer dashboard.
     * Returns units taught, total students, sessions run this semester,
     * and pending disputes.
     *
     * @param  int    $lecturerId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array
     */
    public static function getLecturerStats(
        int    $lecturerId,
        string $academicYear,
        int    $semester
    ): array {
        $units = DB::row(
            "SELECT COUNT(*) AS cnt FROM units
             WHERE lecturer_id = ? AND is_active = 1",
            [$lecturerId]
        )['cnt'] ?? 0;

        $students = DB::row(
            "SELECT COUNT(DISTINCT e.student_id) AS cnt
             FROM enrollments e
             JOIN units u ON u.id = e.unit_id
             WHERE u.lecturer_id  = ?
               AND e.academic_year = ?
               AND e.semester      = ?",
            [$lecturerId, $academicYear, $semester]
        )['cnt'] ?? 0;

        $sessions = DB::row(
            "SELECT COUNT(*) AS cnt
             FROM attendance_sessions
             WHERE lecturer_id  = ?
               AND academic_year = ?
               AND semester      = ?",
            [$lecturerId, $academicYear, $semester]
        )['cnt'] ?? 0;

        $disputes = DB::row(
            "SELECT COUNT(*) AS cnt
             FROM disputes d
             JOIN attendance_sessions s ON s.id = d.session_id
             WHERE s.lecturer_id = ? AND d.status = 'pending'",
            [$lecturerId]
        )['cnt'] ?? 0;

        return [
            'units'            => (int) $units,
            'students'         => (int) $students,
            'sessions'         => (int) $sessions,
            'pending_disputes' => (int) $disputes,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registration number generation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Suggest the next available registration number for a given role.
     *
     * Formats:
     *   student  → STU{YYYY}{NNN}   e.g. STU2025003
     *   lecturer → LEC{NNN}          e.g. LEC004
     *   parent   → PAR{NNN}          e.g. PAR007
     *   admin    → ADMIN{NNN}        e.g. ADMIN002
     *
     * The method finds the highest existing numeric suffix that matches the
     * role's pattern and returns prefix + (max + 1) zero-padded to 3 digits.
     * It is safe to call concurrently — the uniqueness constraint on reg_number
     * will reject any true duplicate; this only provides a sensible default.
     *
     * @param  string $role  One of: admin, lecturer, student, parent
     * @return string        Next suggested reg number, or '' on invalid role
     */
    public static function generateRegNumber(string $role): string
    {
        switch (strtolower(trim($role))) {
            case 'student':
                $year      = (int) date('Y');
                $prefix    = 'STU' . $year;
                $prefixLen = strlen($prefix);          // e.g. 7 for "STU2025"
                $pattern   = '^STU' . $year . '[0-9]+$';
                $pad       = 3;
                break;

            case 'lecturer':
                $prefix    = 'LEC';
                $prefixLen = 3;
                $pattern   = '^LEC[0-9]+$';
                $pad       = 3;
                break;

            case 'parent':
                $prefix    = 'PAR';
                $prefixLen = 3;
                $pattern   = '^PAR[0-9]+$';
                $pad       = 3;
                break;

            case 'admin':
                $prefix    = 'ADMIN';
                $prefixLen = 5;
                $pattern   = '^ADMIN[0-9]+$';
                $pad       = 3;
                break;

            default:
                return '';
        }

        // SUBSTRING is 1-based in MySQL; the numeric part starts at prefixLen + 1
        $row = DB::row(
            "SELECT MAX(CAST(SUBSTRING(reg_number, ?) AS UNSIGNED)) AS max_num
             FROM users
             WHERE reg_number REGEXP ?",
            [$prefixLen + 1, $pattern]
        );

        $next = ((int) ($row['max_num'] ?? 0)) + 1;
        return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Password validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate password strength.
     * Rules: min 8 chars, at least one uppercase, one lowercase,
     * one digit, one special character.
     *
     * @param  string $password
     * @return array { valid: bool, message: string }
     */
    public static function validatePasswordStrength(string $password): array
    {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters long.'];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter.'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter.'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number.'];
        }

        if (!preg_match('/[\W_]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character (e.g. @, #, !).'];
        }

        return ['valid' => true, 'message' => 'Password is strong.'];
    }

    /** Prevent instantiation */
    private function __construct() {}
}