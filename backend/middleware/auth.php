<?php
/**
 * EduTrack — Authentication & Role Guard Middleware
 *
 * Every portal page and API endpoint includes this file at the top
 * and calls one of the guard functions before doing any real work.
 *
 * Usage examples:
 *
 *   // Require any logged-in user
 *   Auth::requireLogin();
 *
 *   // Require a specific role — redirects or returns 403 if wrong
 *   Auth::requireRole('lecturer');
 *
 *   // Require one of several roles
 *   Auth::requireAnyRole(['admin', 'lecturer']);
 *
 *   // Get the currently logged-in user's data
 *   $user = Auth::user();
 *
 *   // Check role without blocking
 *   if (Auth::is('admin')) { ... }
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class Auth
{
    /**
     * Role → login page mapping.
     * When a guard fails, the user is sent to the correct login page.
     */
    private static array $loginPages = [
        'admin'    => BASE_URL . '/admin/login',
        'lecturer' => BASE_URL . '/lecturer/login',
        'student'  => BASE_URL . '/student/login',
        'parent'   => BASE_URL . '/parent/login',
    ];

    /**
     * Role → dashboard mapping.
     * After login, users land on their role-specific dashboard.
     */
    private static array $dashboards = [
        'admin'    => BASE_URL . '/admin/dashboard',
        'lecturer' => BASE_URL . '/lecturer/dashboard',
        'student'  => BASE_URL . '/student/dashboard',
        'parent'   => BASE_URL . '/parent/dashboard',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Session bootstrap
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start the session with secure settings.
     * Call this once at the top of index.php or bootstrap.php.
     * Safe to call multiple times — checks session_status() first.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => defined('SESSION_COOKIE_SECURE') ? SESSION_COOKIE_SECURE : false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::sendSecurityHeaders();

        // Regenerate the session ID periodically to prevent fixation attacks
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) {
            // Regenerate every 30 minutes
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 1; mode=block');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), fullscreen=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard functions — call these at the top of any protected file
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Block access if the user is not logged in.
     *
     * For HTML pages: redirects to the generic login selector.
     * For API endpoints (JSON): returns HTTP 401 and exits.
     *
     * @param bool $isApi  Pass true from API files to get JSON errors instead of redirects
     */
    public static function requireLogin(bool $isApi = false): void
    {
        if (!self::isLoggedIn()) {
            if ($isApi) {
                self::jsonUnauthorised('You must be logged in to access this resource.');
            }
            self::redirectToLogin();
        }

        // Refresh last_login timestamp once per session to avoid hammering the DB
        if (!isset($_SESSION['_login_recorded'])) {
            DB::execute(
                "UPDATE users SET last_login = NOW() WHERE id = ?",
                [$_SESSION['user_id']]
            );
            $_SESSION['_login_recorded'] = true;
        }
    }

    /**
     * Block access unless the user has exactly the given role.
     *
     * @param string $role    One of: admin, lecturer, student, parent
     * @param bool   $isApi
     */
    public static function requireRole(string $role, bool $isApi = false): void
    {
        self::requireLogin($isApi);

        if (self::role() !== $role) {
            if ($isApi) {
                self::jsonForbidden("Access denied. Required role: {$role}.");
            }
            self::redirectForbidden();
        }
    }

    /**
     * Block access unless the user has one of the given roles.
     *
     * @param array $roles  e.g. ['admin', 'lecturer']
     * @param bool  $isApi
     */
    public static function requireAnyRole(array $roles, bool $isApi = false): void
    {
        self::requireLogin($isApi);

        if (!in_array(self::role(), $roles, true)) {
            if ($isApi) {
                self::jsonForbidden('Access denied. Insufficient permissions.');
            }
            self::redirectForbidden();
        }
    }

    /**
     * If the user IS already logged in, send them to their dashboard.
     * Use on login pages so logged-in users do not see the login form again.
     */
    public static function redirectIfLoggedIn(): void
    {
        if (self::isLoggedIn()) {
            $role = self::role();
            $dest = self::$dashboards[$role] ?? BASE_URL;
            header('Location: ' . $dest);
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Login / logout
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Authenticate a user by reg_number + password.
     * On success, writes user data into the session and returns true.
     * On failure, returns false — never reveals which field was wrong.
     *
     * @param  string $regNumber
     * @param  string $plainPassword
     * @return bool
     */
    public static function attempt(string $regNumber, string $plainPassword): bool
    {
        $user = DB::row(
            "SELECT id, reg_number, full_name, email, phone, password_hash, role, is_active
             FROM users
             WHERE reg_number = ?
             LIMIT 1",
            [trim($regNumber)]
        );

        if (!$user) {
            // Timing-safe: still run verify so timing is consistent
            password_verify($plainPassword, '$2y$12$invalidhashpadding000000000000000000000000000000000000');
            return false;
        }

        if (!$user['is_active']) {
            return false;
        }

        if (!password_verify($plainPassword . PASSWORD_PEPPER, $user['password_hash'])) {
            return false;
        }

        // Regenerate session ID on successful login to prevent session fixation
        session_regenerate_id(true);

        // Store only what is needed — never store the password hash in the session
        $_SESSION['user_id']     = $user['id'];
        $_SESSION['reg_number']  = $user['reg_number'];
        $_SESSION['full_name']   = $user['full_name'];
        $_SESSION['email']       = $user['email'];
        $_SESSION['role']        = $user['role'];
        $_SESSION['_created']    = time();

        // Log the login action to the audit trail
        self::audit('user_login', 'users', $user['id']);

        return true;
    }

    /**
     * Destroy the session and send the user to the login page for their role.
     */
    public static function logout(): void
    {
        self::audit('user_logout', 'users', self::id());

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();

	$role = self::role() ?? 'student';
	$dest = self::$loginPages[$role] ?? BASE_URL;
        header('Location: ' . $dest);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Session accessors
    // ─────────────────────────────────────────────────────────────────────────

    /** @return bool True if a user session exists */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id'], $_SESSION['role']);
    }

    /** @return int|null Current user's database ID */
    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /** @return string|null Current user's role */
    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /** @return string|null Current user's full name */
    public static function name(): ?string
    {
        return $_SESSION['full_name'] ?? null;
    }

    /**
     * Return all session user data as an associative array.
     * Useful for passing to views: extract(Auth::user())
     *
     * @return array
     */
    public static function user(): array
    {
        return [
            'id'         => self::id(),
            'reg_number' => $_SESSION['reg_number'] ?? null,
            'full_name'  => $_SESSION['full_name']  ?? null,
            'email'      => $_SESSION['email']       ?? null,
            'role'       => $_SESSION['role']        ?? null,
        ];
    }

    /**
     * Check if the current user has a specific role.
     *
     * @param  string $role
     * @return bool
     */
    public static function is(string $role): bool
    {
        return self::role() === $role;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSRF protection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a CSRF token and store it in the session.
     * Call this in any page that renders a form, then embed the token:
     *   <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
     *
     * @return string
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify the CSRF token submitted with a POST request.
     * Call at the top of every POST handler.
     *
     * @param  bool $isApi  Pass true to get JSON error instead of redirect
     * @return void  Dies on failure
     */
    public static function verifyCsrf(bool $isApi = false): void
    {
        // 1. Form POST field (multipart / form-encoded)
        // 2. Custom request header  (X-CSRF-Token, sent by Ajax.js)
        // 3. JSON body field        (csrf_token key injected by Ajax.js — reliable
        //                            fallback when the custom header is stripped by
        //                            a proxy or server config)
        $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (empty($submitted)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $json      = json_decode(file_get_contents('php://input'), true);
                $submitted = is_array($json) ? ($json['csrf_token'] ?? '') : '';
            }
        }

        $stored = $_SESSION['csrf_token'] ?? '';

        if (!$stored || !hash_equals($stored, $submitted)) {
            if ($isApi) {
                http_response_code(419);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid or expired CSRF token. Please refresh the page.'
                ]);
                exit;
            }

            http_response_code(419);
            exit('Invalid CSRF token. Please go back and try again.');
        }

        // Rotate the token after each verified POST to prevent re-use
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Audit logging
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a row to the audit_logs table.
     * Silently fails so a logging error never breaks a real operation.
     *
     * @param string   $action      e.g. 'user_login', 'marks_uploaded'
     * @param string   $targetType  Table name e.g. 'users', 'marks'
     * @param int|null $targetId    ID of the affected record
     * @param array    $detail      Extra context to store as JSON
     */
    public static function audit(
        string $action,
        string $targetType = '',
        ?int   $targetId   = null,
        array  $detail     = []
    ): void {
        try {
            DB::insert(
                "INSERT INTO audit_logs
                    (user_id, action, target_type, target_id, detail, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    self::id(),
                    $action,
                    $targetType  ?: null,
                    $targetId,
                    !empty($detail) ? json_encode($detail) : null,
                    self::clientIp(),
                ]
            );
        } catch (Exception $e) {
            // Never let audit failure break the request
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function redirectToLogin(): void
    {
        header('Location: ' . BASE_URL);
        exit;
    }

    private static function redirectForbidden(): void
    {
        header('Location: ' . BASE_URL . '/error/403');
        exit;
    }

    private static function jsonUnauthorised(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    private static function jsonForbidden(string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    /**
     * Get the client IP address, checking common proxy headers.
     * On a closed LAN this will almost always be the device's LAN IP.
     */
    private static function clientIp(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /** Prevent instantiation */
    private function __construct() {}
}