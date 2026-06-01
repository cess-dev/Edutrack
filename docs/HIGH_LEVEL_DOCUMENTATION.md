# EduTrack — High-Level Documentation

> A plain-language explanation of every operation, user journey, and business rule in the system. Written for administrators, project stakeholders, and anyone who needs to understand what EduTrack does and why — without reading code.

---

## Table of Contents

1. [What is EduTrack?](#1-what-is-edutrack)
2. [User Roles](#2-user-roles)
3. [Signing In — Two-Step Login & OTP](#3-signing-in--two-step-login--otp)
4. [Password Management](#4-password-management)
5. [Student Operations](#5-student-operations)
6. [Lecturer Operations](#6-lecturer-operations)
7. [Parent Operations](#7-parent-operations)
8. [Administrator Operations](#8-administrator-operations)
9. [QR Attendance System — End to End](#9-qr-attendance-system--end-to-end)
10. [Marks & Grading System](#10-marks--grading-system)
11. [Dispute System](#11-dispute-system)
12. [Enrollment System](#12-enrollment-system)
13. [Notification & Email System](#13-notification--email-system)
14. [Reports & PDF Exports](#14-reports--pdf-exports)
15. [Security & Access Control](#15-security--access-control)
16. [Geofencing](#16-geofencing)
17. [System Settings](#17-system-settings)
18. [Audit Trail](#18-audit-trail)
19. [How the App is Served](#19-how-the-app-is-served)
20. [Key Business Rules Summary](#20-key-business-rules-summary)

---

## 1. What is EduTrack?

EduTrack is a school attendance and academic records management system. It serves four types of users — students, lecturers, parents, and administrators — each through their own dedicated portal.

**Core functions:**
- Students scan a QR code displayed by the lecturer to record their attendance in real time.
- Lecturers generate QR codes, review who attended, upload marks, and manage assessments.
- Parents monitor their children's attendance and academic performance.
- Administrators manage all users, courses, enrollments, and system settings.

The system is designed for a school on a local network (LAN). All data is stored in the school's own database. There is no cloud dependency — the school controls all data.

---

## 2. User Roles

There are four roles. Every account belongs to exactly one role.

### Student
A learner enrolled in one or more units. Students can:
- Scan QR codes to mark attendance
- View their own attendance per unit
- See their published marks and grades
- Submit disputes when they believe attendance was wrongly recorded
- Download their transcript as a PDF

### Lecturer
A teaching staff member assigned to one or more units. Lecturers can:
- Start a QR session for their class
- Watch live as students scan in
- Manually override a student's attendance
- Upload marks (individually or in bulk via CSV)
- Publish or unpublish assessments
- Review and approve/reject student attendance disputes
- Generate PDF reports

### Parent / Guardian
A parent or guardian linked to one or more students. Parents can:
- See attendance summaries for each of their linked children
- View published marks and grades
- Download transcripts
- Access a full attendance history log

### Administrator
A system administrator with full access. Administrators can:
- Create, edit, activate, and deactivate all user accounts
- Manage courses, units, and lecturers assigned to units
- Enroll students (individually or in bulk via CSV)
- Link parents to students
- View school-wide attendance and at-risk student reports
- Manage all disputes
- View the full immutable audit log
- Reset any user's password
- Approve password reset requests
- Edit system-wide settings
- Close all active QR sessions in an emergency

---

## 3. Signing In — Two-Step Login & OTP

### Login Flow

1. User goes to their portal's login page (`/student/login`, `/lecturer/login`, `/parent/login`, `/admin/login`).
2. They enter their registration number (or email) and password.
3. The system checks credentials.

**If email sending is configured and the user has a verified email address:**
- A 6-digit one-time code is sent to their email.
- The login form shows an OTP entry field.
- The user enters the code (valid for 10 minutes, maximum 5 wrong attempts).
- On correct entry, they are logged in.

**If the user has no email, or email delivery is off:**
- The system logs them in directly after password verification — no OTP step.

### Brute-Force Protection

After 5 failed login attempts from the same device, that device is blocked for 5 minutes. The block is automatically lifted after the timeout.

### Wrong Portal

If a user logs in at the wrong portal (e.g., a student tries to sign in at `/lecturer/login`), the system immediately logs them out and tells them to use the correct portal link.

### Session Duration

Sessions last 8 hours by default. After this time, the user is automatically signed out and redirected to their login page with a "session expired" message.

### Session Keep-Alive

While a user is active on any page, the browser quietly checks in with the server every 5 minutes to keep the session alive. A warning is shown 10 minutes before the session would expire.

---

## 4. Password Management

### First Login — Temporary Password

When accounts are created in bulk (via CSV import), a default temporary password is assigned:
- Students: `Student@1`
- Lecturers: `Lecturer@1`
- Parents: `Parent@1`

The system forces these users to change their password on their next login. A yellow banner appears at the top of every page until they do. Until changed, the account works normally — only the banner is shown.

### Changing Your Own Password

Any logged-in user can go to their Profile page and change their password. They must know their current password. The new password must:
- Be at least 8 characters long
- Contain at least one uppercase letter, one lowercase letter, one number, and one special character

### Admin Resetting a User's Password

Administrators can reset any user's password from the Users management page. No knowledge of the current password is needed. The new password is set immediately.

### Forgot Password

The forgot-password page is accessible from every login page. The user enters their registration number or email.

**If email is working and the user has an email address:**
- A secure one-time reset link is sent to their email.
- The link expires after 24 hours.
- The user can only request email resets up to 3 times before being sent to the admin queue.

**If email is not configured, or the reset limit is reached, or there is no email on file:**
- A request is queued for the administrator to approve manually.
- The administrator sees the pending request on the Users page (with a notification badge in the sidebar).
- The administrator can approve (generating a temporary password) or reject the request.
- If email is working, the approved temporary password is emailed to the user automatically.

---

## 5. Student Operations

### Scanning QR Code for Attendance

This is the primary student action. When a lecturer starts a session, a QR code is displayed on the projector or screen. Students:

1. Open the **Scan QR Code** page on their phone or device.
2. Click **Start Scanner** — the browser asks for camera permission (and location permission if geofencing is active).
3. Point their camera at the QR code.
4. The system decodes the QR automatically (no button press needed).
5. Attendance is recorded instantly. A success message appears.

The QR code is valid for a limited window (default: 10 minutes). If a student scans after this window closes, the scan is rejected. If a student scans a valid code but is not enrolled in that unit, the scan is rejected.

### Viewing Attendance

Students can see their attendance on the **My Attendance** page:
- One row per unit.
- Shows: total sessions held, sessions attended, absences, excused sessions, and attendance percentage.
- Colour indicators warn if attendance is below the school's threshold.

### Viewing Marks

The **My Marks** page shows all published assessments grouped by unit:
- Each assessment: name, score, maximum score, contribution to final grade.
- Unit total: weighted final grade and letter grade.
- Only marks that the lecturer has published are visible; unpublished marks are hidden from students.

### Transcript

The **Transcript** page shows the student's full academic summary:
- Grade per unit (A, B, C, D, or E), grade points, remark.
- Overall GPA (Grade Point Average).
- Can be downloaded as a formatted PDF.

### Submitting a Dispute

If a student believes they were wrongly marked absent for a session, they can submit a dispute from the **Disputes** page:
- They must do this within the dispute window (default: 24 hours after the session was closed).
- They provide a written reason.
- The system prevents duplicate disputes for the same session.
- The lecturer reviews it. If approved, the attendance record is corrected to **present**.

### Attendance History

The **Semester History** page shows a chronological log of every session the student has attended or been recorded absent from, including which unit, the lecturer, the date, the method (QR scan, manual, or auto-absent), and the time scanned.

---

## 6. Lecturer Operations

### Starting a QR Attendance Session

From the **Start Session** page:
1. Lecturer selects the unit from a dropdown (only their assigned units appear).
2. Optionally adds a note (e.g., "Week 5 lecture").
3. Clicks Start — a QR code appears on screen.
4. Lecturer displays this QR code for students to scan.
5. The QR code refreshes internally but the displayed code remains valid for the session window.

### Watching Live Attendance

The **Session Live** page shows, in real time:
- A running list of students who have scanned, with their names and times.
- A counter: X students scanned out of Y enrolled.
- A countdown showing how long the session window has remaining.

The page automatically refreshes every 5 seconds.

### Closing a Session

When the lecturer ends the class or the QR window expires, they close the session. The system:
1. Marks the session as closed (no more scans accepted).
2. Checks every enrolled student who did **not** scan.
3. Automatically inserts an "absent" record for each of those students.

This means every enrolled student ends up with a record for every session — either present (scanned), absent (missed), or excused (manually set).

### Manually Overriding Attendance

After a session (open or closed), the lecturer can manually adjust any student's attendance status. This might be used if a student's phone failed during scanning but they were clearly present. The system records that the mark was made manually.

### Session History

The **Session History** page lists every session the lecturer has run, with counts of who attended and who was absent, and allows downloading a PDF attendance register for any session.

### Creating Assessments

On the **Upload Marks** page, lecturers create assessments for their units:
- Assessment types: CAT (Continuous Assessment Test), Assignment, Practical, Project, Final Exam.
- Each assessment has a maximum score and a weight percentage (its contribution to the final unit grade).
- The system prevents the total weights across all assessments for a unit from exceeding 100%.

### Uploading Marks

Marks can be entered in two ways:
1. **One at a time:** Type a student's score into a form on the marks page.
2. **CSV bulk upload:** Prepare a spreadsheet with `reg_number` and `score` columns, upload it. The system validates each row and saves all valid marks in one operation. Rows with errors are listed for correction.

### Publishing / Unpublishing Assessments

By default, assessment marks are hidden from students. The lecturer toggles **Publish** to make marks visible. Unpublishing hides them again. This gives lecturers control over when students see their results.

### Reviewing Disputes

The **Disputes** page lists all pending disputes for the lecturer's sessions. For each:
- Shows the student's name, the session, and their written reason.
- The lecturer can **Approve** (attendance is changed to present) or **Reject** (attendance stays as-is).
- Either decision can include a reviewer note.

### Analytics

The **Analytics** page provides:
- A line chart of attendance percentage over time for each unit.
- A table of at-risk students (those below the attendance threshold) with their contact details.
- Unit filter dropdown.

### Generating PDF Reports

The lecturer can generate:
- **Attendance register** for a specific session (lists every student with their status).
- **Class marks sheet** for a unit (grid of all students vs. all assessments with scores and final grades).

---

## 7. Parent Operations

### Linking to Students

Parents are linked to one or more students by the administrator. This link includes a relationship label (Parent, Mother, Father, Guardian, Sibling). The parent cannot link themselves — only the admin does this.

### Monitoring Children

When a parent logs in, their dashboard shows summary cards for each linked child — attendance percentage and most recent marks.

### Attendance View

The parent can select any of their linked children and see:
- A unit-by-unit breakdown of attendance (sessions attended, absent, excused, percentage).
- A full chronological attendance log.

### Marks & Transcript

Parents see the same published marks and transcript as the student — the same grade per unit, GPA, and grade remarks. Unpublished assessments are not shown.

---

## 8. Administrator Operations

### User Management

The **Users** page is the central hub for all accounts. Features:
- Filter by role (All, Students, Lecturers, Parents, Admins).
- Search by name or registration number.
- Create individual accounts (any role) with a form.
- Bulk create students, lecturers, or parents via CSV file upload.
- Edit a user's contact details (email and phone).
- Reset any user's password.
- Activate or deactivate accounts (deactivated accounts cannot log in).
- Link a parent to a student.
- Bulk link parents to students via CSV.
- View and act on pending password reset requests.
- View and relay active login OTP codes when email delivery fails.

### Bulk User Creation

Three types of bulk import are available:

**Bulk Add Students** — CSV with: `reg_number, full_name, email, phone`
- Admin provides the registration numbers explicitly.
- All accounts get password `Student@1` and are flagged to change it on first login.
- After upload, a credentials table is shown with names, IDs, and temp passwords that can be printed and distributed.

**Bulk Add Lecturers** — CSV with: `full_name, email, phone`
- Registration numbers (LEC001, LEC002...) are generated automatically.
- Password: `Lecturer@1`, flagged for change.

**Bulk Add Parents** — CSV with: `full_name, email, phone, student_reg_number, relationship`
- Registration numbers (PAR001, PAR002...) auto-generated.
- If the same parent appears on multiple rows (two children), only one account is created and both children are linked.
- Password: `Parent@1`, flagged for change.

**Bulk Link Parents** — CSV with: `parent_reg_number, student_reg_number, relationship`
- Links existing parent accounts to existing student accounts.
- Used when parent accounts already exist but links haven't been created.

### Course & Unit Management

The **Courses** page manages the academic structure:
- Create courses (BCS, BCOM, BED, etc.) with department and duration.
- Create units within courses — each unit has a code, name, semester, year of study, credit hours, and assigned lecturer.
- When a new unit is added to a course, all students already enrolled in that course/year/semester are **automatically enrolled in the new unit**.

### Enrollment Management

The **Enrollments** page manages which students are in which courses:
- Enroll a single student manually by selecting their name and a course.
- Bulk-enroll via CSV: `reg_number, full_name, course_code, year_of_study, semester`.
- Remove a student from a course (this also removes all their unit-level enrollments for that period).
- See a table of all current enrollments with how many units each student is in.

### Dispute Management

The **Disputes** page shows all disputes across all units and lecturers. Admin can filter by status (pending, approved, rejected) and can resolve any dispute directly — useful when a lecturer is unavailable.

### Audit Log

The **Audit Log** page shows a complete, uneditable history of every action taken in the system: logins, password changes, account creations, mark uploads, session starts, dispute reviews, and setting changes. Each entry shows who did it, what they did, and the exact timestamp and IP address. This log cannot be deleted or modified.

### System Settings

From the **Settings** page, the administrator can change:
- School name (shown across the portal and on PDFs)
- Academic year and active semester
- Attendance threshold (the percentage below which a student is considered at-risk)
- Dispute window (hours after a session closes during which disputes can be submitted)
- QR session window (how long a QR code remains scannable after a session starts)

### Emergency: Close All Sessions

A single admin action can immediately close every open QR session across the school. This is available for emergencies (e.g., the school session ended but lecturers forgot to close their sessions).

---

## 9. QR Attendance System — End to End

This is the most important operation in EduTrack. Here is the complete flow:

```
Lecturer arrives in class
    ↓
Opens Lecturer Portal → Session Start
    ↓
Selects unit → Clicks Start
    ↓
System generates QR code (valid for 10 minutes)
    ↓
Lecturer displays QR on projector / phone screen
    ↓
Each student opens Scan QR page on their phone
    ↓
Phone requests camera permission (and GPS if geofencing is on)
    ↓
Camera detects QR code automatically
    ↓
Student's phone sends the QR data to the server (with GPS if geofencing is on)
    ↓
Server validates:
  ✓ QR payload is genuine (HMAC signature matches)
  ✓ Session is still open (not expired, not closed)
  ✓ Student is enrolled in this unit
  ✓ Student has not already scanned today
  ✓ Student is on campus (if geofencing is on)
    ↓
"Attendance recorded" message shown to student
    ↓
Lecturer's live feed updates in real time
    ↓
Class ends → Lecturer clicks Close Session
    ↓
System marks every absent student automatically
    ↓
Attendance data is now permanent and reportable
```

### What Happens if a Student Scans Twice?

The system rejects the second scan silently and shows "already recorded" — it does not create a duplicate record.

### What Happens if the QR Window Expires While Students are Still Scanning?

The QR code becomes invalid after the configured window (default: 10 minutes). Students who haven't scanned yet will see an "expired" error. The lecturer can keep the session open (for manual marking) without generating a new QR. Or they can close it and manually mark latecomers as present.

### What Happens if a Lecturer Forgets to Close a Session?

The session stays open indefinitely. Students could theoretically scan later. The administrator can close it manually from the Disputes or Admin panel, or use the "Close All Sessions" emergency action.

---

## 10. Marks & Grading System

### How Marks Work

A unit has one or more assessments. Each assessment has:
- A **maximum score** (e.g., 30 marks for a CAT)
- A **weight percentage** (how much it contributes to the final grade — e.g., 30%)

A student's final unit grade is calculated as:
```
Weighted Score = (Student Score / Max Score) × Weight Percentage
Final Grade    = Sum of Weighted Scores across all assessments in the unit
```

Example:
| Assessment | Max | Student | Weight | Contribution |
|---|---|---|---|---|
| CAT 1 | 30 | 24 | 30% | (24/30) × 30 = 24% |
| CAT 2 | 30 | 27 | 30% | (27/30) × 30 = 27% |
| Final Exam | 100 | 65 | 40% | (65/100) × 40 = 26% |
| **Total** | | | **100%** | **77% → Grade A** |

A grade letter is only assigned once the assessments together account for 100% of the weight (i.e., all weighted assessments have been completed and marked).

### Grade Scale

| Grade | Percentage | Points | Remark |
|---|---|---|---|
| A | 70 – 100% | 4.0 | Distinction |
| B | 60 – 69% | 3.0 | Credit |
| C | 50 – 59% | 2.0 | Pass |
| D | 40 – 49% | 1.0 | Marginal Fail |
| E | 0 – 39% | 0.0 | Fail |

### GPA Calculation

GPA = Average grade points across all units with an assigned grade:
```
GPA = (Sum of all unit grade points) / (Number of units with a grade)
```

### What Students See vs. What Lecturers See

- **Students and parents** only see marks for assessments the lecturer has **published**.
- **Lecturers** see all assessments including unpublished ones.
- **Administrators** can see all marks via reports.

---

## 11. Dispute System

### Purpose

The dispute system allows students to formally challenge an attendance record that they believe is wrong. This provides an accountable, traceable process for attendance corrections.

### Submitting a Dispute

A student can submit one dispute per session. They must:
1. Have been marked **absent or excused** (not present — there is nothing to dispute if already present).
2. Be within the **dispute window** (default: 24 hours after the session was closed).
3. Provide a written explanation.

### Reviewing a Dispute

The lecturer responsible for the session reviews the dispute:
- **Approve**: The attendance record is changed to **present**. The student's attendance percentage improves.
- **Reject**: The attendance record is unchanged. The lecturer can add a note explaining why.

The student can see the status of all their disputes (pending, approved, rejected) and any reviewer note.

### Who Can See Disputes?

- **Students** see only their own disputes.
- **Lecturers** see disputes for their own sessions.
- **Administrators** see all disputes across the school.
- **Parents** do not see disputes directly (but see the resulting attendance changes).

---

## 12. Enrollment System

### How Enrollment is Structured

EduTrack uses a two-level enrollment system:

**Level 1 — Course Enrollment** (the master record):
A student is enrolled in a **course** for a specific academic year, semester, and year-of-study. Example: "John Doe enrolled in BCS, Year 2, Semester 1, 2025/2026."

**Level 2 — Unit Enrollment** (derived automatically):
When a student is enrolled in a course, the system automatically creates enrollment records for every active unit in that course at the matching year and semester. If a new unit is later added to the course, students already enrolled are automatically enrolled in the new unit too.

### Enrollment via CSV

The enrollment CSV uses course-level data:
```
reg_number, full_name, course_code, year_of_study, semester
STU2025001, Jane Doe, BCS, 2, 1
```
- If the student's registration number doesn't exist, the row is skipped with an error.
- If the course has no units for the given year/semester, the enrollment still succeeds with a warning.
- Already-enrolled students are silently skipped (idempotent — safe to re-run the same CSV).

### Unenrolling a Student

Removing a course enrollment also removes all the derived unit enrollments for that student in that period. Attendance records from sessions already run are not deleted.

---

## 13. Notification & Email System

EduTrack sends emails for three purposes:

| Trigger | Who receives it | Content |
|---|---|---|
| Login with OTP | The user logging in | 6-digit code, 10-min expiry |
| Forgot password (email route) | The user who requested reset | One-time secure reset link |
| Admin approves password reset | The user whose reset was approved | Temporary password |

### When Email Cannot Be Delivered

If the configured email account (Gmail) fails to send:
- For **login OTP**: The user sees "Email delivery failed — please try again later." The OTP code is stored in the database. The administrator can see all active OTP codes on the Users page and forward them manually (by phone, WhatsApp, or another email).
- For **forgot password**: The request goes to the admin approval queue automatically.

Gmail may send the administrator a delivery failure notification to their inbox. The administrator can then update the email credentials in the `.env` file and restart the server. On the next attempt, the new credentials are used automatically.

### In-App OTP Fallback Panel

On the Admin → Users page, a panel appears automatically showing any users who have active (not yet expired or used) OTP codes stored in the database. This is specifically for the case where email delivery failed — the admin can see the code and relay it to the user directly.

---

## 14. Reports & PDF Exports

All PDF reports are generated on demand (not stored permanently). They are formatted with the school's name and the app name.

### Available Reports

**Student Transcript** (generated by student, parent, or admin)
- Lists all units with grade, grade points, and remark.
- Includes overall GPA.
- Only includes published assessments.

**Session Attendance Register** (generated by lecturer or admin)
- Complete list of all enrolled students for a specific session.
- Shows: status (present/absent/excused), method (QR scan/manual/auto-absent), and time of scan.
- Summary: total present, total enrolled, percentage.

**Class Marks Sheet** (generated by lecturer or admin)
- Full class grid: rows are students, columns are assessments.
- Shows each student's score per assessment and their final weighted total and grade.
- Landscape orientation.
- Includes both published and unpublished assessments (for lecturer use).

**Unit Attendance Summary** (generated by admin)
- All students in a unit with their attendance percentage across the semester.
- Students below the threshold are highlighted in red.

---

## 15. Security & Access Control

### Role Isolation

Each portal (student, lecturer, parent, admin) is completely separate. A user logged in as a student cannot access any lecturer or admin page — the system checks the role on every single page and API call, not just at login.

### What Each Role Cannot Do

| Action | Student | Lecturer | Parent | Admin |
|---|---|---|---|---|
| See another student's marks | ✗ | ✓ (own units) | ✗ (own children only) | ✓ |
| Start a QR session | ✗ | ✓ (own units) | ✗ | ✗ |
| Upload marks | ✗ | ✓ (own units) | ✗ | ✗ |
| Create user accounts | ✗ | ✗ | ✗ | ✓ |
| Reset another user's password | ✗ | ✗ | ✗ | ✓ |
| View audit log | ✗ | ✗ | ✗ | ✓ |
| Edit system settings | ✗ | ✗ | ✗ | ✓ |

### CSRF Protection

Every action that changes data (submitting a form, uploading marks, resetting a password) is protected by a security token. This prevents another website from tricking a logged-in user into making unintended actions. The token changes after each use.

### Password Security

Passwords are stored using a one-way algorithm (bcrypt) combined with a secret key that exists only on the server. Even if someone accessed the database directly, they could not recover original passwords.

### Audit Trail

Every significant action is permanently recorded: who did it, what they did, and when. This cannot be edited or deleted — even by administrators.

---

## 16. Geofencing

When geofencing is enabled, students must be physically on campus for their QR scan to be accepted.

### How It Works

1. When the student taps "Start Scanner," the phone asks for location permission.
2. The phone's GPS coordinates are sent along with the QR scan data.
3. The server calculates the distance from the student's phone to the school's GPS coordinates (set in the `.env` file).
4. If the distance exceeds the configured radius (e.g., 200 metres), the scan is rejected with a clear message showing how far away the student is.

### Enabling Geofencing

In the `.env` file, set:
```
GEOFENCE_ENABLED=true
SCHOOL_LAT=-1.286389       (latitude from Google Maps)
SCHOOL_LNG=36.817223       (longitude from Google Maps)
SCHOOL_RADIUS_METERS=200   (allowed distance from the school pin)
```

Getting school coordinates: Open Google Maps → find school building → right-click centre → "What's here?" → copy the decimal coordinates shown.

### Limitations

GPS on mobile phones has an accuracy range (typically ±10–50 metres). Set the radius large enough to account for this drift. A radius of 150–300 metres works well for a single-campus school. Students on rooftops or near the perimeter may occasionally be rejected if the GPS reads slightly outside the radius.

GPS coordinates can technically be spoofed on rooted/jailbroken devices. This system is a deterrent and works correctly for the vast majority of students using unmodified phones.

---

## 17. System Settings

The **Admin → Settings** page controls the following values. Changes take effect immediately.

| Setting | Default | Effect |
|---|---|---|
| School name | "Your School Name" | Shown in headers, portal branding, and on PDFs |
| Academic year | "2025/2026" | Used in attendance and marks queries; determines which enrollment records are active |
| Active semester | 2 | Current semester (1 or 2); determines which units are being taught now |
| Attendance threshold | 75% | Students below this percentage are flagged as "at-risk" for lecturers, parents, and admin |
| Dispute window | 24 hours | How many hours after a session closes a student can still submit a dispute |
| QR session window | 10 minutes | How long a generated QR code remains valid for scanning |

---

## 18. Audit Trail

Every significant event in EduTrack is permanently recorded in the audit log. It is **append-only** — no record can be edited or deleted, not even by administrators.

### What is Logged

| Category | Events logged |
|---|---|
| Authentication | Login, logout |
| User management | Create user, bulk import, edit contact, activate, deactivate |
| Password | Change password, admin reset, forgot password approve/reject |
| Attendance | Start session, close session, QR scan (with GPS coordinates if geofencing enabled), manual mark |
| Marks | Create assessment, publish/unpublish, upload marks |
| Disputes | Submit dispute, review dispute (approve/reject) |
| Enrollment | Bulk enrollment, single enrollment |
| Settings | Update any system setting |
| System | Emergency close all sessions |

### Each Audit Record Contains

- **Who** did it (user name and ID; or "system" for automated actions)
- **What** the action was
- **Which record** was affected (e.g., user ID 42, session ID 7)
- **Extra detail** (e.g., how many marks were uploaded, GPS coordinates of a scan)
- **IP address** of the device
- **Timestamp** (server time)

---

## 19. How the App is Served

### Running the App

EduTrack runs on PHP 8.2 and requires a MySQL database.

**Option 1: PHP built-in server (recommended for local use)**
```bash
cd c:/xampp/htdocs/edutrack
php -S 0.0.0.0:8080 serve.php
```
Then open `http://localhost:8080` in a browser.
MySQL must be running separately (XAMPP MySQL service, or standalone MySQL).

**Option 2: XAMPP with Apache (traditional)**
Place the project in `C:\xampp\htdocs\edutrack\` and start Apache and MySQL from the XAMPP control panel.

### Accessing from Student Phones

For students to scan QR codes using their phones on the school network, the app must be accessible via the school's network IP address.

**Camera requirement:** Browsers only allow camera access on HTTPS connections or `localhost`. For network access (phones), HTTPS is mandatory.

**Setting up HTTPS on XAMPP:**
1. Enable SSL in Apache config (`LoadModule ssl_module` and `Include conf/extra/httpd-ssl.conf`).
2. Generate a certificate that matches your server's IP address.
3. Students visit `https://192.168.x.x/edutrack` and accept the self-signed certificate warning once.

**After accepting the certificate**, camera works on every phone without further action.

### Environment Configuration

All environment-specific settings (database credentials, SMTP credentials, school GPS coordinates, BASE_URL) live in a single `.env` file at the root of the project. Changing a value in this file takes effect after the next page load (no server restart needed for most settings; Apache/PHP restart needed for PHP-level constants).

---

## 20. Key Business Rules Summary

1. **A student can only scan a QR code once per session.** Duplicate scans are silently rejected.

2. **A QR code is linked to exactly one unit.** Scanning a QR for the wrong unit (or an old code from a different session) is rejected.

3. **When a session is closed, all non-present enrolled students are automatically marked absent.** There are no "unrecorded" students — every student has a status for every session.

4. **Only the lecturer who created a session can close it.** Administrators can close sessions via the emergency "Close All" action.

5. **Assessment weights per unit cannot exceed 100%.** The system prevents this at data entry.

6. **Marks cannot be assigned above the assessment's maximum score.** Validated server-side on every upload.

7. **A grade is only shown when all weighted assessments add up to 100%** (fully graded unit).

8. **Only published assessments are visible to students and parents.** Lecturers see all assessments regardless.

9. **Disputes can only be submitted if the student was marked absent or excused** — not if they were already marked present.

10. **Disputes can only be submitted within the dispute window** (default: 24 hours after session close).

11. **Approving a dispute changes the attendance record to present.** Rejecting it leaves the record unchanged.

12. **Deactivating an account blocks login immediately.** All existing session data, marks, and attendance records are preserved.

13. **Bulk-created accounts must change their password on first login.** A banner reminds them on every page until done.

14. **Password resets via email are limited to 3 times.** Further resets require administrator approval.

15. **Lecturers can only manage units assigned to them.** They cannot access or modify data for units assigned to other lecturers.

16. **Parents can only see data for their linked children.** The link must be created by an administrator.

17. **The audit log cannot be modified or deleted** — it is an immutable record of all actions.

18. **When geofencing is enabled, GPS must be provided with every scan.** No location = scan rejected, regardless of other validity.

19. **A new unit added to a course automatically enrolls all currently enrolled students in that course/year/semester.** No manual re-enrollment needed.

20. **Removing a student from a course removes all their unit-level enrollments for that period.** Past attendance and marks records are preserved.
