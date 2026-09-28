<?php
// ---------------------------------------------------------------
// Session hardening: cookie can't be read by JavaScript (HttpOnly), isn't
// sent on cross-site sub-requests (SameSite=Lax), is HTTPS-only when the
// site is served over HTTPS, and the server refuses session IDs it didn't
// issue (use_strict_mode). Each login also calls session_regenerate_id()
// so a pre-login session ID can't be planted on a victim (session fixation).
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($is_https),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------
// CSRF protection for every form in the app, without touching each form:
//  1. every POST must carry the session's token, or is rejected (403);
//  2. the token is injected into every <form method="post"> in the page
//     output automatically (HTML responses only — file/JSON responses that
//     set their own Content-Type are left untouched).
// AJAX calls that send "X-Requested-With: XMLHttpRequest" (basics cart and
// catalog) are exempt: browsers won't let another site add that header
// without a CORS preflight, which this app never allows.
// ---------------------------------------------------------------
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$csrf_page_token = csrf_token();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf_is_xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $csrf_sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$csrf_is_xhr && !(is_string($csrf_sent) && hash_equals($csrf_page_token, $csrf_sent))) {
        http_response_code(403);
        exit('Your session expired or this request could not be verified. Please go back, refresh the page and try again.');
    }
}

ob_start(function ($html) use ($csrf_page_token) {
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return $html;
        }
    }
    $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_page_token, ENT_QUOTES) . '">';
    return preg_replace('/(<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>)/i', '$1' . $field, $html);
});

// ---------------------------------------------------------------
// Login throttling: after N failed attempts for one username (or 60 from
// one IP) within 15 minutes, further attempts are refused until the window
// passes. N is 3 for the admin scopes (wellness_admin, basics_admin) and 8
// for regular member scopes (wellness, basics) — admin accounts guard more
// sensitive data, so they get a stricter lockout. Tracked in login_attempts
// (database/live_add_login_attempts.sql). Fails open if that table doesn't
// exist yet, so a missing migration can never lock everyone out of the site.
// ---------------------------------------------------------------
function login_throttle_blocked($conn, $scope, $username) {
    try {
        $id = strtolower(substr(trim((string) $username), 0, 150));
        $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $stmt = $conn->prepare("SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)) AS by_user,
                (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)) AS by_ip");
        $stmt->bind_param('sss', $scope, $id, $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $user_limit = substr($scope, -6) === '_admin' ? 3 : 8;
        return (int) $row['by_user'] >= $user_limit || (int) $row['by_ip'] >= 60;
    } catch (Throwable $e) {
        return false;
    }
}

function login_throttle_fail($conn, $scope, $username) {
    try {
        $id = strtolower(substr(trim((string) $username), 0, 150));
        $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $stmt = $conn->prepare("INSERT INTO login_attempts (scope, identifier, ip) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $scope, $id, $ip);
        $stmt->execute();
        $stmt->close();
        if (random_int(1, 50) === 1) {
            $conn->query("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Throwable $e) {
    }
}

function login_throttle_clear($conn, $scope, $username) {
    try {
        $id = strtolower(substr(trim((string) $username), 0, 150));
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE scope = ? AND identifier = ?");
        $stmt->bind_param('ss', $scope, $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
    }
}

const LOGIN_THROTTLE_MESSAGE = 'Too many failed login attempts. Please wait 15 minutes and try again.';

// Admin sessions expire after 3 hours without activity.
function admin_session_expired($activity_key) {
    $now = time();
    $expired = isset($_SESSION[$activity_key]) && ($now - $_SESSION[$activity_key]) > 10800;
    $_SESSION[$activity_key] = $now;
    return $expired;
}

// ---------------------------------------------------------------
// JMC Foodies Wellness — member auth (users table)
// Wellness and Basics are fully separate account systems: separate tables,
// separate login pages, separate sessions. See the "Basics — member auth"
// section below for its parallel. There is no shared login between them.
// ---------------------------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

// Use on every Wellness user-facing page except change_password.php and logout.php.
// Also enforces the forced-password-change gate.
function require_login($conn) {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
    require_valid_session_user($conn);
    if (!empty($_SESSION['must_change_password'])) {
        redirect('/change_password.php');
    }
}

// Use only on change_password.php: requires login but does not loop back into itself.
function require_login_only($conn) {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
    require_valid_session_user($conn);
}

// A session can outlive its user row (e.g. a database restore, or an admin
// deleting the account) — without this, pages crash on null-array warnings
// instead of just sending the visitor back to login.
function require_valid_session_user($conn) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }
}

// Kept as the name every wellness/*.php page already calls — Wellness no
// longer shares its account table with Basics, so this is now just
// require_login() under its established name (avoids touching every caller).
function require_wellness_access($conn) {
    require_login($conn);
}

// Every Wellness login now goes straight to the Wellness dashboard — kept as
// a named function (rather than inlining the path at each call site) since
// login.php/register.php/change_password.php all call it.
function route_after_login($conn, $user_id) {
    return '/wellness/dashboard.php';
}

// ---------------------------------------------------------------
// JMC Foodies Wellness — admin auth (admins table)
// ---------------------------------------------------------------
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']);
}

function current_admin_id() {
    return $_SESSION['admin_id'] ?? null;
}

function require_admin_login() {
    if (!is_admin_logged_in()) {
        redirect('/admin/login.php');
    }
    if (admin_session_expired('admin_last_activity')) {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_last_activity']);
        redirect('/admin/login.php');
    }
}

// ---------------------------------------------------------------
// JMC Foodies Basics — member auth (basics_users table). Independent
// session keys from Wellness's, so the two can never collide.
// ---------------------------------------------------------------
function basics_is_logged_in() {
    return isset($_SESSION['basics_user_id']);
}

function basics_current_user_id() {
    return $_SESSION['basics_user_id'] ?? null;
}

function require_basics_login($conn) {
    if (!basics_is_logged_in()) {
        redirect('/basics/login.php');
    }
    require_valid_basics_session_user($conn);
    if (!empty($_SESSION['basics_must_change_password'])) {
        redirect('/basics/change_password.php');
    }
}

function require_basics_login_only($conn) {
    if (!basics_is_logged_in()) {
        redirect('/basics/login.php');
    }
    require_valid_basics_session_user($conn);
}

function require_valid_basics_session_user($conn) {
    $stmt = $conn->prepare("SELECT id FROM basics_users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['basics_user_id']);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        session_unset();
        session_destroy();
        redirect('/basics/login.php');
    }
}

// Use on every Basics member page instead of require_basics_login() alone —
// also redirects to the holding page while the membership application is
// still pending, denied, suspended, or terminated.
function require_basics_access($conn) {
    require_basics_login($conn);
    $member = basics_get_member($conn, basics_current_user_id());
    if (!$member) {
        redirect('/basics/apply.php');
    }
    if ($member['application_status'] !== 'approved' || !in_array($member['membership_status'], ['active', 'dormant'], true)) {
        redirect('/basics/pending.php');
    }
}

// ---------------------------------------------------------------
// JMC Foodies Basics — admin auth (basics_admins table), fully separate
// from the Wellness admin login/session above.
// ---------------------------------------------------------------
function basics_is_admin_logged_in() {
    return isset($_SESSION['basics_admin_id']);
}

function basics_current_admin_id() {
    return $_SESSION['basics_admin_id'] ?? null;
}

// While Basics maintenance mode is on (basics/admin/maintenance.php), only
// super_admin may use the admin panel — every other role is locked out at
// login and, via require_basics_admin_login() below, kicked from any
// session that was already open when maintenance was switched on.
function basics_maintenance_blocks_role($role) {
    global $conn;
    return $role !== 'super_admin'
        && isset($conn) && $conn instanceof mysqli
        && function_exists('setting')
        && setting($conn, 'basics_maintenance_enabled', '0') === '1';
}

function require_basics_admin_login() {
    if (!basics_is_admin_logged_in()) {
        redirect('/basics/admin/login.php');
    }
    if (admin_session_expired('basics_admin_last_activity')) {
        unset($_SESSION['basics_admin_id'], $_SESSION['basics_admin_name'], $_SESSION['basics_admin_role'], $_SESSION['basics_admin_last_activity']);
        redirect('/basics/admin/login.php');
    }
    if (basics_maintenance_blocks_role(basics_admin_role())) {
        unset($_SESSION['basics_admin_id'], $_SESSION['basics_admin_name'], $_SESSION['basics_admin_role']);
        redirect('/basics/admin/login.php?maintenance=1');
    }
}

function basics_admin_role() {
    return $_SESSION['basics_admin_role'] ?? 'super_admin';
}

// Where each role lands after login, and where a permission-denied redirect
// sends them — must be a page that role can actually open, or a denied
// staff_payments admin bouncing off a staff_orders-only page (or vice versa)
// would redirect-loop forever.
function basics_admin_landing_url() {
    switch (basics_admin_role()) {
        case 'staff_orders':
            return '/basics/admin/applications.php';
        case 'staff_payments':
            return '/basics/admin/payments.php';
        case 'staff_registration':
            return '/basics/admin/users.php';
        default:
            return '/basics/admin/index.php';
    }
}

// staff_orders, staff_payments and staff_registration are restricted roles,
// each scoped to its own slice of the admin (orders/applications vs
// payments/benefits vs registering and viewing users).
// Everything else (members, settings, etc.) is super_admin-only. Pages call
// this instead of require_basics_admin_login() when they should be
// off-limits to one or both staff roles.
function require_basics_admin_role(array $allowed_roles) {
    require_basics_admin_login();
    if (!in_array(basics_admin_role(), $allowed_roles, true)) {
        redirect(basics_admin_landing_url());
    }
}
