-- =============================================================================
-- EduTrack — Seed Data
-- =============================================================================
-- Import this file AFTER schema.sql via phpMyAdmin:
--   phpMyAdmin → edutrack_db → Import → choose this file → Go
--
-- What this creates:
--   1. Default admin account         (change password immediately after first login)
--   2. Two sample lecturers
--   3. Two sample courses
--   4. Six sample units
--   5. Four sample students
--   6. Two sample parent accounts linked to students
--   7. Sample enrollments
--   8. Sample assessments
--   9. Default system settings
--
-- ALL PASSWORDS below are bcrypt hashes of the plaintext shown in the comment.
-- Generate a new hash in PHP:
--   php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT);"
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. USERS
-- =============================================================================

INSERT INTO `users`
    (`id`, `reg_number`, `full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`)
VALUES

-- ── Admin ─────────────────────────────────────────────────────────────────────
-- Plaintext password: Admin@1234   ← CHANGE THIS IMMEDIATELY
(1,  'ADMIN001',   'System Administrator',
     'admin@school.local',    '0700000000',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'admin',    1),

-- ── Lecturers ────────────────────────────────────────────────────────────────
-- Plaintext password: Lecturer@1   ← advise users to change on first login
(2,  'LEC001',     'Dr. Alice Mwangi',
     'a.mwangi@school.local',  '0711000001',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'lecturer', 1),

(3,  'LEC002',     'Mr. Brian Otieno',
     'b.otieno@school.local',  '0711000002',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'lecturer', 1),

-- ── Students ─────────────────────────────────────────────────────────────────
-- Plaintext password: Student@1
(4,  'STU2024001',  'James Kariuki',
     'j.kariuki@student.school.local', '0722000001',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'student',  1),

(5,  'STU2024002',  'Faith Njeri',
     'f.njeri@student.school.local',   '0722000002',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'student',  1),

(6,  'STU2024003',  'Kevin Odhiambo',
     'k.odhiambo@student.school.local','0722000003',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'student',  1),

(7,  'STU2024004',  'Grace Wanjiku',
     'g.wanjiku@student.school.local', '0722000004',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'student',  1),

-- ── Parents ──────────────────────────────────────────────────────────────────
-- Plaintext password: Parent@1
(8,  'PAR001',     'Mrs. Mary Kariuki',
     'mary.kariuki@gmail.com',  '0733000001',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'parent',   1),

(9,  'PAR002',     'Mr. Joseph Njeri',
     'joseph.njeri@gmail.com',  '0733000002',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.usl/Xa2.K',
     'parent',   1);


-- =============================================================================
-- 2. COURSES
-- =============================================================================

INSERT INTO `courses`
    (`id`, `code`, `name`, `department`, `duration_years`, `is_active`)
VALUES
(1, 'BCS',  'Bachelor of Computer Science',     'School of Computing',   4, 1),
(2, 'BCOM', 'Bachelor of Commerce',             'School of Business',    4, 1);


-- =============================================================================
-- 3. UNITS
-- BCS Year 1 Semester 1: two units taught by Dr. Mwangi
-- BCOM Year 1 Semester 1: two units taught by Mr. Otieno
-- BCS Year 1 Semester 1: one shared unit taught by Dr. Mwangi
-- =============================================================================

INSERT INTO `units`
    (`id`, `course_id`, `lecturer_id`, `code`, `name`,
     `semester`, `year_of_study`, `credit_hours`, `is_active`)
VALUES
(1, 1, 2, 'BCS101', 'Introduction to Programming',      1, 1, 3, 1),
(2, 1, 2, 'BCS102', 'Mathematics for Computing',        1, 1, 3, 1),
(3, 1, 3, 'BCS103', 'Computer Organisation',            1, 1, 3, 1),
(4, 2, 3, 'BCOM101','Principles of Management',         1, 1, 3, 1),
(5, 2, 3, 'BCOM102','Business Communication',           1, 1, 3, 1),
(6, 2, 2, 'BCOM103','Introduction to Accounting',       1, 1, 3, 1);


-- =============================================================================
-- 4. ENROLLMENTS
-- Students 4 & 5 enrolled in BCS units
-- Students 6 & 7 enrolled in BCOM units
-- =============================================================================

INSERT INTO `enrollments`
    (`student_id`, `unit_id`, `academic_year`, `semester`)
VALUES
-- James Kariuki (BCS)
(4, 1, '2024/2025', 1),
(4, 2, '2024/2025', 1),
(4, 3, '2024/2025', 1),
-- Faith Njeri (BCS)
(5, 1, '2024/2025', 1),
(5, 2, '2024/2025', 1),
(5, 3, '2024/2025', 1),
-- Kevin Odhiambo (BCOM)
(6, 4, '2024/2025', 1),
(6, 5, '2024/2025', 1),
(6, 6, '2024/2025', 1),
-- Grace Wanjiku (BCOM)
(7, 4, '2024/2025', 1),
(7, 5, '2024/2025', 1),
(7, 6, '2024/2025', 1);


-- =============================================================================
-- 5. PARENT–STUDENT LINKS
-- Mary Kariuki monitors James (her son)
-- Joseph Njeri monitors Faith (his daughter)
-- =============================================================================

INSERT INTO `parent_student_links`
    (`parent_id`, `student_id`, `relationship`)
VALUES
(8, 4, 'Mother'),
(9, 5, 'Father');


-- =============================================================================
-- 6. ASSESSMENTS
-- Define graded components for BCS101 and BCOM101 as examples.
-- Weights must total 100 per unit.
-- is_published = 0 means students cannot yet see marks.
-- =============================================================================

INSERT INTO `assessments`
    (`id`, `unit_id`, `name`, `type`, `max_score`, `weight_percent`,
     `assessment_date`, `is_published`, `created_by`)
VALUES
-- BCS101 — Introduction to Programming
(1,  1, 'CAT 1',        'cat',        30.00, 15.00, '2024-09-20', 1, 2),
(2,  1, 'CAT 2',        'cat',        30.00, 15.00, '2024-10-18', 0, 2),
(3,  1, 'Assignment 1', 'assignment', 20.00, 10.00, '2024-10-04', 1, 2),
(4,  1, 'Final Exam',   'final_exam', 70.00, 60.00, '2024-11-15', 0, 2),

-- BCS102 — Mathematics for Computing
(5,  2, 'CAT 1',        'cat',        30.00, 20.00, '2024-09-22', 1, 2),
(6,  2, 'CAT 2',        'cat',        30.00, 20.00, '2024-10-20', 0, 2),
(7,  2, 'Final Exam',   'final_exam', 70.00, 60.00, '2024-11-16', 0, 2),

-- BCOM101 — Principles of Management
(8,  4, 'CAT 1',        'cat',        30.00, 15.00, '2024-09-21', 1, 3),
(9,  4, 'Group Project','project',    40.00, 25.00, '2024-10-25', 0, 3),
(10, 4, 'Final Exam',   'final_exam', 70.00, 60.00, '2024-11-14', 0, 3);


-- =============================================================================
-- 7. SAMPLE MARKS (published assessments only)
-- =============================================================================

INSERT INTO `marks`
    (`student_id`, `assessment_id`, `score`, `uploaded_by`)
VALUES
-- BCS101 CAT 1 (max 30, published)
(4, 1, 24.00, 2),
(5, 1, 27.50, 2),
-- BCS101 Assignment 1 (max 20, published)
(4, 3, 17.00, 2),
(5, 3, 19.00, 2),
-- BCS102 CAT 1 (max 30, published)
(4, 5, 22.00, 2),
(5, 5, 25.00, 2),
-- BCOM101 CAT 1 (max 30, published)
(6, 8, 21.00, 3),
(7, 8, 26.00, 3);


-- =============================================================================
-- 8. DEFAULT SYSTEM SETTINGS
-- These can be updated from the Admin panel without touching config.php.
-- config.php values serve as fallback if a key is missing from this table.
-- =============================================================================

INSERT INTO `system_settings`
    (`setting_key`, `setting_value`, `description`)
VALUES
('school_name',             'Your School Name',
    'Displayed on reports, portals, and email headers'),

('academic_year',           '2026/2027',
    'Currently active academic year'),

('active_semester',         '1',
    'Currently active semester (1 or 2)'),

('attendance_threshold',    '75',
    'Attendance % below which parent alerts are triggered'),

('attendance_window',       '10',
    'Minutes a QR code remains valid after generation'),

('dispute_window_hours',    '24',
    'Hours after a session closes that students can raise a dispute'),

('rows_per_page',           '25',
    'Number of table rows shown per page across all portals'),

('allow_student_register',  '0',
    '1 = students can self-register; 0 = admin creates all accounts'),

('smtp_enabled',            '0',
    '1 = send email notifications; 0 = disabled'),

('maintenance_mode',        '0',
    '1 = show maintenance page to all non-admin users');


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Default login credentials (CHANGE ALL PASSWORDS AFTER FIRST LOGIN)
-- ─────────────────────────────────────────────────────────────────
-- Role      | Username  | Password
-- ----------|-----------|------------
-- Admin     | ADMIN001  | Admin@1234
-- Lecturer  | LEC001    | Lecturer@1
-- Lecturer  | LEC002    | Lecturer@1
-- Student   | STU2024001| Student@1
-- Student   | STU2024002| Student@1
-- Student   | STU2024003| Student@1
-- Student   | STU2024004| Student@1
-- Parent    | PAR001    | Parent@1
-- Parent    | PAR002    | Parent@1

/*
Fixed: [admin] ADMIN001 → password: Admin@1234
Fixed: [lecturer] LEC001 → password: Lecturer@1234
Fixed: [lecturer] LEC002 → password: Lecturer@1234
Fixed: [student] STU2024001 → password: Student@1234
Fixed: [student] STU2024002 → password: Student@1234
Fixed: [student] STU2024003 → password: Student@1234
Fixed: [student] STU2024004 → password: Student@1234
Fixed: [parent] PAR001 → password: Parent@1234
Fixed: [parent] PAR002 → password: Parent@1234
*/
-- =============================================================================