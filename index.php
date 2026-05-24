<?php
/**
 * EduTrack — Root Entry Point & Front Controller
 *
 * Handles ALL requests to the application via mod_rewrite.
 * Logic:
 *   1. If a route is matched → include the corresponding file
 *   2. If user is already logged in on root URL → redirect to role dashboard
 *   3. If not logged in on root URL → show portal selector
 *   4. No route matched → 404
 *
 * URL: /edutrack/ or /edutrack/{route}
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/backend/middleware/auth.php';

Auth::startSession();
// ── Route map ──────────────────────────────────────────────────────────────
// Format: 'clean-url' => 'public/role/file.php'
// Add new pages here — no .htaccess changes needed
$routes = [
    // Student
    'login'                  => 'student/login.php',
    'student/login'          => 'student/login.php',
    'student/dashboard'      => 'student/dashboard.php',
    'student/marks'          => 'student/marks.php',
    'student/attendance'     => 'student/attendance.php',
    'student/disputes'       => 'student/disputes.php',
    'student/transcript'     => 'student/transcript.php',
    'student/scan'           => 'student/scan.php',
    'student/profile'        => 'student/profile.php',

    // Admin
    'admin/login'            => 'admin/login.php',
    'admin/dashboard'        => 'admin/dashboard.php',
    'admin/users'            => 'admin/users.php',
    'admin/courses'          => 'admin/courses.php',
    'admin/enrollments'      => 'admin/enrollments.php',
    'admin/reports'          => 'admin/reports.php',
    'admin/audit'            => 'admin/audit.php',
    'admin/settings'         => 'admin/settings.php',
    'admin/profile'          => 'admin/profile.php',

    // Lecturer
    'lecturer/login'         => 'lecturer/login.php',
    'lecturer/dashboard'     => 'lecturer/dashboard.php',
    'lecturer/marks'         => 'lecturer/marks.php',
    'lecturer/marksheet'     => 'lecturer/marksheet.php',
    'lecturer/sessions'      => 'lecturer/sessions.php',
    'lecturer/session/live'  => 'lecturer/session_live.php',
    'lecturer/session/start' => 'lecturer/session_start.php',
    'lecturer/disputes'      => 'lecturer/disputes.php',
    'lecturer/analytics'     => 'lecturer/analytics.php',
    'lecturer/profile'       => 'lecturer/profile.php',

    // Parent
    'parent/login'           => 'parent/login.php',
    'parent/dashboard'       => 'parent/dashboard.php',
    'parent/marks'           => 'parent/marks.php',
    'parent/transcript'      => 'parent/transcript.php',
    'parent/attendance'      => 'parent/attendance.php',
    'parent/profile'         => 'parent/profile.php',

    'error/403'             => 'errors/403.php',
    'error/404'             => 'errors/404.php',
    'error/500'             => 'errors/500.php',

    'api/auth/logout' => '../api/auth/logout.php',
];

// ── Resolve current request to a clean route ───────────────────────────────
$request = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Strip the base prefix (edutrack/) so routes above stay short
$request = preg_replace('#^edutrack/?#', '', $request);
$request = trim($request, '/');

// ── If a route matches → include that file and stop ────────────────────────
if (array_key_exists($request, $routes)) {
    $file = __DIR__ . '/public/' . $routes[$request];
    if (file_exists($file)) {
        require $file;
    } else {
        http_response_code(500);
        require __DIR__ . '/public/errors/500.php';
    }
    exit;
}

// ── Root URL (empty route) → portal selector or dashboard ─────────────────
if ($request === '') {

    // Already logged in → go straight to role dashboard
    if (Auth::isLoggedIn()) {
        $role = Auth::role();
        $destinations = [
            'admin'    => BASE_URL . '/admin/dashboard',
            'lecturer' => BASE_URL . '/lecturer/dashboard',
            'student'  => BASE_URL . '/student/dashboard',
            'parent'   => BASE_URL . '/parent/dashboard',
        ];
        header('Location: ' . ($destinations[$role] ?? BASE_URL));
        exit;
    }

    // Not logged in → show portal selector (below)
    $schoolName = DB::row(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
    )['setting_value'] ?? SCHOOL_NAME;

    $academicYear = DB::row(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
    )['setting_value'] ?? ACADEMIC_YEAR;

    // Portal selector HTML starts here
    ?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(APP_NAME) ?> — <?= htmlspecialchars($schoolName) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <style>
    /* ── Portal selector page — standalone styles ─────────────────────── */
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      font-family: var(--font-body);
      background: var(--color-primary);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: var(--space-8) var(--space-4);
      position: relative;
      overflow-x: hidden;
      overflow-y: auto;
    }

    body::before {
      content: '';
      position: fixed;
      width: 600px;
      height: 600px;
      background: rgba(255,255,255,0.04);
      border-radius: 50%;
      top: -200px;
      right: -200px;
      pointer-events: none;
    }

    body::after {
      content: '';
      position: fixed;
      width: 400px;
      height: 400px;
      background: rgba(15,123,108,0.12);
      border-radius: 50%;
      bottom: -150px;
      left: -100px;
      pointer-events: none;
    }

    .selector-header {
      text-align: center;
      margin-bottom: var(--space-10);
      position: relative;
      z-index: 1;
      animation: fadeIn 0.4s ease both;
    }

    .app-logo {
      font-size: 3.5rem;
      display: block;
      margin-bottom: var(--space-4);
    }

    .app-name {
      font-family: var(--font-heading);
      font-size: var(--text-4xl);
      color: white;
      margin-bottom: var(--space-2);
      font-weight: var(--weight-regular);
    }

    .app-tagline {
      font-size: var(--text-base);
      color: rgba(255,255,255,0.6);
      margin-bottom: var(--space-3);
    }

    .school-name-display {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: var(--radius-full);
      padding: var(--space-2) var(--space-5);
      font-size: var(--text-sm);
      color: rgba(255,255,255,0.8);
      font-weight: var(--weight-medium);
    }

    .portal-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: var(--space-5);
      max-width: 640px;
      width: 100%;
      position: relative;
      z-index: 1;
    }

    .portal-card {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: var(--radius-xl);
      padding: var(--space-8) var(--space-6);
      text-align: center;
      text-decoration: none;
      cursor: pointer;
      transition: background  var(--transition-base),
                  border-color var(--transition-base),
                  transform    var(--transition-spring),
                  box-shadow   var(--transition-base);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: var(--space-3);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    .portal-card:hover {
      background: rgba(255,255,255,0.13);
      border-color: rgba(255,255,255,0.25);
      transform: translateY(-4px);
      box-shadow: 0 16px 40px rgba(0,0,0,0.25);
      text-decoration: none;
    }

    .portal-card:active { transform: translateY(-1px); }

    .portal-card:nth-child(1) { animation: fadeIn 0.4s ease 0.1s both; }
    .portal-card:nth-child(2) { animation: fadeIn 0.4s ease 0.15s both; }
    .portal-card:nth-child(3) { animation: fadeIn 0.4s ease 0.2s both; }
    .portal-card:nth-child(4) { animation: fadeIn 0.4s ease 0.25s both; }

    .portal-icon-wrap {
      width: 68px;
      height: 68px;
      border-radius: var(--radius-xl);
      display: grid;
      place-items: center;
      font-size: 2rem;
      margin-bottom: var(--space-2);
      transition: transform var(--transition-spring);
    }

    .portal-card:hover .portal-icon-wrap { transform: scale(1.08); }

    .portal-icon-lecturer { background: rgba(15,123,108,0.3);  }
    .portal-icon-student  { background: rgba(83,74,183,0.3);   }
    .portal-icon-parent   { background: rgba(196,123,18,0.3);  }
    .portal-icon-admin    { background: rgba(216,90,48,0.3);   }

    .portal-card-title {
      font-size: var(--text-lg);
      font-weight: var(--weight-semibold);
      color: white;
      font-family: var(--font-body);
    }

    .portal-card-desc {
      font-size: var(--text-sm);
      color: rgba(255,255,255,0.55);
      line-height: var(--leading-relaxed);
    }

    .portal-card-arrow {
      margin-top: var(--space-2);
      font-size: var(--text-sm);
      color: rgba(255,255,255,0.35);
      transition: color var(--transition-fast), transform var(--transition-fast);
    }

    .portal-card:hover .portal-card-arrow {
      color: rgba(255,255,255,0.7);
      transform: translateX(4px);
    }

    .selector-footer {
      margin-top: var(--space-10);
      text-align: center;
      font-size: var(--text-xs);
      color: rgba(255,255,255,0.3);
      position: relative;
      z-index: 1;
      animation: fadeIn 0.4s ease 0.35s both;
    }

    .selector-footer a {
      color: rgba(255,255,255,0.45);
      text-decoration: none;
      transition: color var(--transition-fast);
    }

    .selector-footer a:hover { color: rgba(255,255,255,0.7); }

    @media (max-width: 480px) {
      .portal-grid {
        grid-template-columns: 1fr;
        max-width: 320px;
      }

      .portal-card {
        flex-direction: row;
        text-align: left;
        padding: var(--space-5) var(--space-5);
        gap: var(--space-4);
      }

      .portal-icon-wrap {
        width: 52px;
        height: 52px;
        font-size: 1.5rem;
        flex-shrink: 0;
        margin-bottom: 0;
      }

      .portal-card-content { flex: 1; }
      .portal-card-arrow   { display: none; }
      .app-name { font-size: var(--text-3xl); }
    }
  </style>
</head>
<body>

  <header class="selector-header">
    <span class="app-logo">🎓</span>
    <h1 class="app-name"><?= htmlspecialchars(APP_NAME) ?></h1>
    <p class="app-tagline">Student Monitoring System</p>
    <div class="school-name-display">
      🏫 <?= htmlspecialchars($schoolName) ?>
      &nbsp;·&nbsp;
      <?= htmlspecialchars($academicYear) ?>
    </div>
  </header>

  <main class="portal-grid" aria-label="Portal selection">

    <!-- ── Card hrefs now use clean masked URLs ── -->

    <a href="<?= BASE_URL ?>/lecturer/login"
       class="portal-card"
       aria-label="Lecturer Portal — Manage attendance and marks">
      <div class="portal-icon-wrap portal-icon-lecturer">👨‍🏫</div>
      <div class="portal-card-content">
        <div class="portal-card-title">Lecturer</div>
        <div class="portal-card-desc">
          Start QR sessions,<br>upload marks, view reports
        </div>
      </div>
      <div class="portal-card-arrow">→</div>
    </a>

    <a href="<?= BASE_URL ?>/student/login"
       class="portal-card"
       aria-label="Student Portal — Scan QR and view grades">
      <div class="portal-icon-wrap portal-icon-student">🎓</div>
      <div class="portal-card-content">
        <div class="portal-card-title">Student</div>
        <div class="portal-card-desc">
          Scan QR codes,<br>view attendance &amp; marks
        </div>
      </div>
      <div class="portal-card-arrow">→</div>
    </a>

    <a href="<?= BASE_URL ?>/parent/login"
       class="portal-card"
       aria-label="Parent Portal — Monitor your child">
      <div class="portal-icon-wrap portal-icon-parent">👨‍👩‍👧</div>
      <div class="portal-card-content">
        <div class="portal-card-title">Parent</div>
        <div class="portal-card-desc">
          Monitor attendance,<br>view academic progress
        </div>
      </div>
      <div class="portal-card-arrow">→</div>
    </a>

    <a href="<?= BASE_URL ?>/admin/login"
       class="portal-card"
       aria-label="Admin Portal — System administration">
      <div class="portal-icon-wrap portal-icon-admin">⚙️</div>
      <div class="portal-card-content">
        <div class="portal-card-title">Admin</div>
        <div class="portal-card-desc">
          Manage users,<br>courses &amp; system settings
        </div>
      </div>
      <div class="portal-card-arrow">→</div>
    </a>

  </main>

  <footer class="selector-footer">
    <p>
      <?= htmlspecialchars(APP_NAME) ?> v1.0
      &nbsp;·&nbsp;
      Running on <a href="https://www.apachefriends.org/" target="_blank" rel="noopener">XAMPP</a>
      &nbsp;·&nbsp;
      Local network only
    </p>
  </footer>

</body>
</html>
    <?php
    exit;
}

// ── No route matched → 404 ─────────────────────────────────────────────────
http_response_code(404);
require __DIR__ . '/public/errors/404.php';