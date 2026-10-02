<?php
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/birthday.php';

function format_price($amount) {
    return '₱' . number_format((float) $amount, 2);
}

// ---------------------------------------------------------------
// Generates a random temporary password for admin-initiated resets
// (admin/user_view.php, basics/admin/member_view.php). Passwords are
// stored as bcrypt hashes only (see password_hash() in change_password.php
// / apply.php) — there is no way to recover a user's current password, so
// "send the user their password" is implemented as "reset to a new
// temporary one and send that", paired with must_change_password = 1 so
// the user is forced to pick their own on next login.
// Excludes visually ambiguous characters (0/O, 1/l/I) since this gets
// read off an email/SMS and typed back in by hand.
// ---------------------------------------------------------------
function generate_temp_password($length = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// ---------------------------------------------------------------
// Records every SMS/email send attempt (communication_log), called from
// inside send_sms()/send_email() so every trigger — automatic (member
// actions like placing an order) and admin-initiated (individual message,
// broadcast) — gets logged uniformly with no per-call-site wiring needed.
// module/admin attribution is inferred from whichever session is active
// at send time: an admin session (Wellness or Basics) means this was
// admin-initiated; otherwise falls back to whichever member session is
// active, since that's what an automatic trigger fires under.
// ---------------------------------------------------------------
function log_communication($channel, $recipient, $subject, $message, $status) {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    // Wellness and Basics admins are two separate sessions/tables — same
    // dual-lookup pattern as log_activity().
    $admin_id = null;
    $admin_type = null;
    $table = null;
    if (function_exists('is_admin_logged_in') && is_admin_logged_in()) {
        $admin_id = current_admin_id();
        $admin_type = 'wellness';
        $table = 'admins';
    } elseif (function_exists('basics_is_admin_logged_in') && basics_is_admin_logged_in()) {
        $admin_id = basics_current_admin_id();
        $admin_type = 'basics';
        $table = 'basics_admins';
    }

    $admin_name = null;
    if ($admin_id) {
        $stmt = $conn->prepare("SELECT name FROM $table WHERE id = ?");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $admin_name = $stmt->get_result()->fetch_assoc()['name'] ?? null;
        $stmt->close();
    }

    $module = $admin_type;
    if ($module === null) {
        if (function_exists('basics_is_logged_in') && basics_is_logged_in()) {
            $module = 'basics';
        } elseif (function_exists('is_logged_in') && is_logged_in()) {
            $module = 'wellness';
        }
    }

    // The log is a record, not a gate: it must never stop a message from being
    // sent or crash the page that sent it. (It once did — a bulk SMS to 90+
    // numbers overflowed the 190-char recipient column and threw a fatal
    // error right after Semaphore had already accepted the messages.)
    try {
        $recipient = function_exists("mb_substr") ? mb_substr((string) $recipient, 0, 190) : substr((string) $recipient, 0, 190);
        $subject = $subject === null ? null : (function_exists("mb_substr") ? mb_substr((string) $subject, 0, 255) : substr((string) $subject, 0, 255));
        $stmt = $conn->prepare("INSERT INTO communication_log (channel, module, recipient, subject, message, status, admin_id, admin_type, admin_name)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssiss', $channel, $module, $recipient, $subject, $message, $status, $admin_id, $admin_type, $admin_name);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('communication_log write failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------
// SMS notifications (Semaphore, semaphore.co). Used by both Wellness and
// Basics for every member-facing SMS trigger. Fails silently (returns false,
// logs nothing to the user-facing page) rather than blocking whatever action
// it's attached to — a failed/unsent SMS should never stop an application
// approval, a payment being recorded, etc. Skips entirely (no API call) if
// SEMAPHORE_API_KEY isn't configured yet, so the app works before SMS setup.
// ---------------------------------------------------------------
function send_sms($to, $message) {
    if (trim((string) $to) === '') {
        return false;
    }
    // A comma-separated list means a bulk send (announcements): log it as one
    // line like "92 recipients (bulk)" instead of dumping every number.
    $log_to = strpos($to, ',') !== false ? (substr_count($to, ',') + 1) . ' recipients (bulk)' : $to;
    if (SEMAPHORE_API_KEY === '') {
        log_communication('sms', $log_to, null, $message, 'failed');
        return false;
    }

    $params = [
        'apikey' => SEMAPHORE_API_KEY,
        'number' => $to,
        'message' => $message,
    ];
    if (SEMAPHORE_SENDER_NAME !== '') {
        $params['sendername'] = SEMAPHORE_SENDER_NAME;
    }

    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = $response !== false && $http_code >= 200 && $http_code < 300;
    log_communication('sms', $log_to, null, $message, $success ? 'sent' : 'failed');
    return $success;
}

// ---------------------------------------------------------------
// Email notifications, sent over Gmail SMTP (smtp.gmail.com:465, implicit
// TLS) via raw sockets — no PHPMailer/Composer in this codebase, so this
// mirrors send_sms()'s style (a plain PHP function wrapping the provider's
// wire protocol directly). Uses a Gmail App Password, not the account's
// real login password (see config/email.example.php for setup).
// Fails silently (returns false, logs nothing to the user-facing page)
// rather than blocking whatever action it's attached to, same contract as
// send_sms(). Skips entirely if GMAIL_SMTP_USERNAME isn't configured yet.
// ---------------------------------------------------------------
function smtp_read_response($socket) {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        // A response is done once a line has the code followed by a space
        // (not a dash) — a dash means more lines of this same response follow.
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function send_email($to, $subject, $body) {
    // Strip CR/LF so a recipient or subject can never smuggle extra SMTP
    // commands or mail headers (header injection), and refuse anything that
    // isn't a plain valid address.
    $to = trim(str_replace(["\r", "\n"], '', (string) $to));
    $subject = str_replace(["\r", "\n"], ' ', (string) $subject);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (GMAIL_SMTP_USERNAME === '') {
        log_communication('email', $to, $subject, $body, 'failed');
        return false;
    }

    $socket = smtp_open();
    if (!$socket) {
        log_communication('email', $to, $subject, $body, 'failed');
        return false;
    }
    $success = smtp_deliver($socket, $to, $subject, $body) === true;
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    log_communication('email', $to, $subject, $body, $success ? 'sent' : 'failed');
    return $success;
}

// Opens ONE authenticated SMTP session. Split out of send_email() so a bulk
// send can log in once and push every message through the same connection —
// logging in to Gmail again for each of 90+ recipients is slow and gets
// throttled ("too many login attempts"). SMTP_ENDPOINT can be defined to
// point at a different server (used for testing without sending real mail).
function smtp_open() {
    if (GMAIL_SMTP_USERNAME === '') {
        return false;
    }
    $endpoint = defined('SMTP_ENDPOINT') ? SMTP_ENDPOINT : 'ssl://smtp.gmail.com:465';
    $socket = @stream_socket_client($endpoint, $errno, $errstr, 10);
    if (!$socket) {
        return false;
    }
    stream_set_timeout($socket, 20);

    smtp_read_response($socket); // 220 greeting

    fwrite($socket, "EHLO localhost\r\n");
    smtp_read_response($socket);

    fwrite($socket, "AUTH LOGIN\r\n");
    smtp_read_response($socket);
    fwrite($socket, base64_encode(GMAIL_SMTP_USERNAME) . "\r\n");
    smtp_read_response($socket);
    fwrite($socket, base64_encode(GMAIL_SMTP_PASSWORD) . "\r\n");
    $auth_response = smtp_read_response($socket);
    if (substr($auth_response, 0, 3) !== '235') {
        fclose($socket);
        return false;
    }
    return $socket;
}

// Sends one message over an already-open session.
//   true  = server accepted it (250 after DATA)
//   false = server refused this message/recipient (e.g. 550 no such user) —
//           the session is still healthy and can be reused
//   null  = the connection itself is dead (no reply) — caller should reconnect
// $to/$subject must already be cleaned.
function smtp_deliver($socket, $to, $subject, $body) {
    // Appended to every notification email, so callers don't each need to
    // remember to add it.
    $full_body = $body . "\r\n\r\nFor questions and concerns please call +63 917 323 8153.";

    @fwrite($socket, 'MAIL FROM:<' . GMAIL_SMTP_USERNAME . ">\r\n");
    smtp_read_response($socket);
    @fwrite($socket, 'RCPT TO:<' . $to . ">\r\n");
    $rcpt_response = smtp_read_response($socket);
    if ($rcpt_response === '') {
        return null;
    }
    if (substr($rcpt_response, 0, 3) !== '250') {
        @fwrite($socket, "RSET\r\n");
        smtp_read_response($socket);
        return false;
    }

    @fwrite($socket, "DATA\r\n");
    $data_ready = smtp_read_response($socket);
    if ($data_ready === '') {
        return null;
    }
    if (substr($data_ready, 0, 3) !== '354') {
        @fwrite($socket, "RSET\r\n");
        smtp_read_response($socket);
        return false;
    }

    // A non-ASCII subject (e.g. an em dash or peso sign) must be MIME-encoded
    // or mail clients show it garbled.
    $encoded_subject = preg_match('/[^\x20-\x7E]/', $subject)
        ? '=?UTF-8?B?' . base64_encode($subject) . '?='
        : $subject;
    $headers = 'From: ' . GMAIL_SMTP_FROM_NAME . ' <' . GMAIL_SMTP_USERNAME . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . $encoded_subject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    // Per RFC 5321, a lone "." on a line marks end-of-data — escape any line
    // in the body that starts with one so it isn't mistaken for the terminator.
    $escaped_body = preg_replace('/^\./m', '..', $full_body);
    @fwrite($socket, $headers . "\r\n" . $escaped_body . "\r\n.\r\n");
    $data_response = smtp_read_response($socket);
    if ($data_response === '') {
        return null;
    }

    return substr($data_response, 0, 3) === '250';
}

// Retries a fresh SMTP login a few times with a short backoff before giving
// up. Gmail can briefly refuse a new login ("too many login attempts") after
// a burst of sends from the same account within one bulk run, even though
// the account/credentials are fine — a real case observed in production
// after ~200 messages (5 reconnects at the 40-message interval below).
// Without this, one such refusal used to be treated as fatal for the entire
// rest of the batch (send_email_bulk() would mark every remaining recipient
// "failed" instantly, with no further attempt) — this gives it a few chances
// to recover first.
function smtp_open_with_retry($attempts = 3, $backoff_seconds = 5) {
    for ($i = 1; $i <= $attempts; $i++) {
        $socket = smtp_open();
        if ($socket) {
            return $socket;
        }
        if ($i < $attempts) {
            sleep($backoff_seconds * $i);
        }
    }
    return false;
}

// Announcement / bulk email: every recipient gets their own individual
// message (nobody sees anyone else's address), all through one SMTP login.
// Duplicate and invalid addresses are skipped, the session is refreshed every
// 40 messages, and if a login/reconnect fails it's retried (see
// smtp_open_with_retry()) instead of failing the rest of the list outright.
// Each attempt is logged like a normal send. Returns ['sent' => n, 'failed' => n].
function send_email_bulk(array $addresses, $subject, $body) {
    $subject = str_replace(["\r", "\n"], ' ', (string) $subject);
    $seen = [];
    $queue = [];
    foreach ($addresses as $address) {
        $address = trim(str_replace(["\r", "\n"], '', (string) $address));
        $key = strtolower($address);
        if ($address === '' || isset($seen[$key]) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $seen[$key] = true;
        $queue[] = $address;
    }

    $sent = 0;
    $failed = 0;
    $socket = null;
    $on_this_session = 0;
    $connect_failed = false;

    foreach ($queue as $address) {
        if ($socket && $on_this_session >= 40) {
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            $socket = null;
        }
        if (!$socket && !$connect_failed) {
            $socket = smtp_open_with_retry();
            $on_this_session = 0;
            if (!$socket) {
                $connect_failed = true; // retries were already exhausted — give up for the rest of the batch
            }
        }

        $ok = false;
        if ($socket) {
            $result = smtp_deliver($socket, $address, $subject, $body);
            if ($result === null) {
                // The connection died (no reply). Reconnect (with retry) and
                // retry this recipient. A plain refusal (false, e.g. "no such
                // user") does NOT trigger this — the session is fine, just move on.
                @fclose($socket);
                $socket = smtp_open_with_retry();
                $on_this_session = 0;
                $result = $socket ? smtp_deliver($socket, $address, $subject, $body) : null;
            }
            $ok = $result === true;
            $on_this_session++;
        }

        log_communication('email', $address, $subject, $body, $ok ? 'sent' : 'failed');
        $ok ? $sent++ : $failed++;
    }

    if ($socket) {
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
    }
    return ['sent' => $sent, 'failed' => $failed];
}

// ---------------------------------------------------------------
// Resets a member's password to a fresh temporary one (must_change_password
// = 1) and sends it by email AND SMS, to whichever the account has on file.
// Shared by the self-service "Forgot Password" pages and the admin "Reset
// Password" buttons, for both Wellness ($table 'users') and Basics
// ($table 'basics_users'). If neither message could be delivered the old
// password is put back, so a failed send never locks the member out.
// Returns ['email' => bool, 'sms' => bool] — both false means nothing was
// delivered and the password is unchanged.
// ---------------------------------------------------------------
function password_reset_sms_prefix($module_name) {
    return $module_name . ': Your password has been reset.';
}

function reset_member_password($conn, $table, $user_id, $module_name, $full_name, $email, $contact_number, $self_service) {
    if (!in_array($table, ['users', 'basics_users'], true)) {
        throw new InvalidArgumentException('Unsupported account table.');
    }

    $stmt = $conn->prepare("SELECT password_hash, must_change_password FROM $table WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) {
        return ['email' => false, 'sms' => false];
    }

    $new_password = generate_temp_password();
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE $table SET password_hash = ?, must_change_password = 1 WHERE id = ?");
    $stmt->bind_param('si', $hash, $user_id);
    $stmt->execute();
    $stmt->close();

    $reason = $self_service ? 'A password reset was requested for your account.' : 'Your password has been reset by an administrator.';
    $warning = $self_service ? " If you didn't request this, please contact support immediately." : '';
    $email_body = "Hi {$full_name},\r\n\r\n{$reason}\r\n\r\nYour new temporary password is: {$new_password}\r\n\r\n"
        . "Please log in and change it right away.{$warning}\r\n\r\n— {$module_name} Team";
    $sms_body = password_reset_sms_prefix($module_name) . " Temporary password: {$new_password} - log in and change it right away."
        . ($self_service ? " Didn't request this? Contact support." : '');

    $sent = [
        'email' => send_email($email, "Your {$module_name} password has been reset", $email_body),
        'sms' => send_sms($contact_number, $sms_body),
    ];

    if (!$sent['email'] && !$sent['sms']) {
        $stmt = $conn->prepare("UPDATE $table SET password_hash = ?, must_change_password = ? WHERE id = ?");
        $stmt->bind_param('sii', $old['password_hash'], $old['must_change_password'], $user_id);
        $stmt->execute();
        $stmt->close();
    }
    return $sent;
}

function payment_method_label($method) {
    $labels = [
        'wallet' => 'JMC Wallet',
        'bank_transfer' => 'Bank Transfer - Eastwest QR',
        'cod' => 'Cash on Pick-up / Delivery',
    ];
    return $labels[$method] ?? $method;
}

function sanitize($value) {
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// For putting a value INSIDE a JavaScript string in an HTML attribute, e.g.
// onclick="return confirm( echo js_str('Delete ' . $name . '?') );"
// (never write the PHP closing tag inside a comment - it ends PHP mode).
// sanitize() is NOT enough there: it turns ' into &#039;, which the browser
// decodes back to ' before the JavaScript runs, so a member whose name is
// `');alert(1);//` would execute script in an admin's browser. This emits a
// properly escaped JS string literal (quotes included) that is also safe in
// an HTML attribute.
function js_str($value) {
    return htmlspecialchars(json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
}

// Exception text is only safe to show visitors when we threw it ourselves
// ("Your wallet balance is not enough..."). Database exceptions carry SQL,
// table and column names — log those, show a generic message.
function safe_error_message($e) {
    if ($e instanceof mysqli_sql_exception) {
        error_log('Database error: ' . $e->getMessage());
        return 'Something went wrong while saving. Please try again, and contact support if it keeps happening.';
    }
    return $e->getMessage();
}

function redirect($path) {
    header('Location: ' . BASE_URL . $path);
    exit;
}

// ---------------------------------------------------------------
// Editable business settings (admin/settings.php), stored as a simple
// key-value table instead of hardcoded constants so they can change
// without a code deploy. Cached per-request (one query no matter how many
// times individual keys are read on a page).
// ---------------------------------------------------------------
function setting($conn, $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $result->fetch_assoc()) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function save_setting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------
// Admin action audit trail (admin/activity_log.php). Called from both the
// Wellness and Basics admin panels at every meaningful mutation. Fails
// silently on session/DB issues rather than blocking the action it's
// logging — the log is a record, not a gate.
// ---------------------------------------------------------------
function log_activity($conn, $action, $description) {
    // Wellness and Basics admins are two separate sessions/tables — check
    // whichever one is actually active for this request.
    $admin_id = null;
    $admin_type = null;
    $table = null;
    if (function_exists('is_admin_logged_in') && is_admin_logged_in()) {
        $admin_id = current_admin_id();
        $admin_type = 'wellness';
        $table = 'admins';
    } elseif (function_exists('basics_is_admin_logged_in') && basics_is_admin_logged_in()) {
        $admin_id = basics_current_admin_id();
        $admin_type = 'basics';
        $table = 'basics_admins';
    }

    $admin_name = null;
    if ($admin_id) {
        $stmt = $conn->prepare("SELECT name FROM $table WHERE id = ?");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $admin_name = $stmt->get_result()->fetch_assoc()['name'] ?? null;
        $stmt->close();
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare("INSERT INTO activity_log (admin_id, admin_type, admin_name, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssss', $admin_id, $admin_type, $admin_name, $action, $description, $ip);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------
// Full-database SQL dump, generated in pure PHP rather than shelling out to
// mysqldump — InfinityFree's shared hosting gives no SSH/shell access, so
// this is the only way to script a backup there. Used by the automated
// backup below (write_database_backup()). Every table's schema (SHOW
// CREATE TABLE) and data (as INSERT statements) is included, so restoring
// is a single paste into phpMyAdmin's SQL tab.
// ---------------------------------------------------------------
function generate_database_sql_dump(mysqli $conn) {
    $sql = "-- JMC Digital database backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = [];
    $result = $conn->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $sql .= "-- ----------------------------\n-- Table: `{$table}`\n-- ----------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $create_row = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch_assoc();
        $sql .= $create_row['Create Table'] . ";\n\n";

        $data = $conn->query("SELECT * FROM `{$table}`");
        if ($data && $data->num_rows > 0) {
            $columns = array_map(function ($field) { return "`{$field->name}`"; }, $data->fetch_fields());
            $sql .= "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES\n";
            $value_rows = [];
            while ($row = $data->fetch_row()) {
                $escaped = array_map(function ($value) use ($conn) {
                    return $value === null ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                }, $row);
                $value_rows[] = '(' . implode(', ', $escaped) . ')';
            }
            $sql .= implode(",\n", $value_rows) . ";\n\n";
        } else {
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

// ---------------------------------------------------------------
// Writes a fresh database backup to database/backups/latest.sql,
// overwriting whatever was there each time (never accumulates), and
// records the outcome in database/backups/last_run.txt. Shared by the
// traffic-driven scheduler (maybe_run_scheduled_backup(), just below) and
// the "Run Backup Now" button on admin/db_backup.php.
// ---------------------------------------------------------------
function write_database_backup($conn) {
    $backup_dir = __DIR__ . '/../database/backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    $log_path = $backup_dir . '/last_run.txt';

    try {
        $sql = generate_database_sql_dump($conn);
        $tmp_path = $backup_dir . '/latest.sql.tmp';
        $final_path = $backup_dir . '/latest.sql';
        if (file_put_contents($tmp_path, $sql, LOCK_EX) === false) {
            throw new Exception('Could not write backup file — check folder permissions.');
        }
        // Write-then-rename so a concurrent download never reads a half-written file.
        rename($tmp_path, $final_path);
        file_put_contents($log_path, date('Y-m-d H:i:s') . ' OK (' . strlen($sql) . " bytes)\n", LOCK_EX);
        return true;
    } catch (Throwable $e) {
        file_put_contents($log_path, date('Y-m-d H:i:s') . ' FAILED: ' . $e->getMessage() . "\n", LOCK_EX);
        return false;
    }
}

// ---------------------------------------------------------------
// "Poor man's cron" for the DB backup: InfinityFree's free plan has no
// cron/SSH access, so there's no way to run this on a real timer. Instead
// it piggybacks on ordinary site traffic — this file is required by every
// single page right after config/database.php, so it's guaranteed to run
// often enough to catch the 1-hour mark on whichever page loads next.
// The "claim the slot" save_setting() happens before the (slower) dump
// itself so two requests landing in the same moment don't both trigger a
// redundant backup.
// ---------------------------------------------------------------
function maybe_run_scheduled_backup($conn) {
    $interval_seconds = 1 * 3600;
    $last_run = setting($conn, 'db_backup_last_run_at');
    if ($last_run !== null && (time() - strtotime($last_run)) < $interval_seconds) {
        return;
    }
    save_setting($conn, 'db_backup_last_run_at', date('Y-m-d H:i:s'));
    write_database_backup($conn);
}

// ---------------------------------------------------------------
// Validates and saves an uploaded image to uploads/products/, deleting the
// old file if one is replaced. Returns [filename_to_store, error_or_null].
// $existing_filename is returned unchanged if no new file was uploaded.
// ---------------------------------------------------------------
function handle_product_image_upload($file_key, $existing_filename, $subfolder = 'products') {
    if (empty($_FILES[$file_key]['name'])) {
        return [$existing_filename, null];
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
    finfo_close($finfo);

    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return [$existing_filename, 'Image upload failed.'];
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        return [$existing_filename, 'Image must be smaller than 5MB.'];
    }
    if (!isset($allowed_types[$mime])) {
        return [$existing_filename, 'Image must be a JPG, PNG, or WEBP file.'];
    }

    $new_filename = bin2hex(random_bytes(8)) . '.' . $allowed_types[$mime];
    $dest = UPLOAD_PATH . $subfolder . '/' . $new_filename;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return [$existing_filename, 'Failed to save uploaded image.'];
    }

    if ($existing_filename && is_file(UPLOAD_PATH . $subfolder . '/' . $existing_filename)) {
        unlink(UPLOAD_PATH . $subfolder . '/' . $existing_filename);
    }
    return [$new_filename, null];
}

// ---------------------------------------------------------------
// Downscales an uploaded photo in place if it's larger than $max_dimension
// on its longest side. Phone-camera photos (KYC IDs, payment proof
// screenshots) are routinely 3000px+ and several MB despite passing the
// upload size cap, which makes them slow to load when an admin views one —
// this brings them down to a web-viewable size while staying legible.
// No-op (fails silently, keeps the original file) if GD isn't available or
// the mime type isn't a resizable image (e.g. PDF) — a missing GD extension
// should never block an upload that already succeeded.
// ---------------------------------------------------------------
function resize_image_if_needed($path, $mime, $max_dimension = 1600, $quality = 82) {
    $creators = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    if (!isset($creators[$mime]) || !function_exists($creators[$mime]) || !function_exists('imagecreatetruecolor')) {
        return;
    }

    $size = @getimagesize($path);
    if (!$size || max($size[0], $size[1]) <= $max_dimension) {
        return;
    }

    $src = @$creators[$mime]($path);
    if (!$src) {
        return;
    }

    $ratio = $max_dimension / max($size[0], $size[1]);
    $new_width = max(1, (int) round($size[0] * $ratio));
    $new_height = max(1, (int) round($size[1] * $ratio));

    $dst = imagecreatetruecolor($new_width, $new_height);
    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $size[0], $size[1]);

    if ($mime === 'image/jpeg') {
        imagejpeg($dst, $path, $quality);
    } elseif ($mime === 'image/png') {
        imagepng($dst, $path, 6);
    } elseif ($mime === 'image/webp') {
        imagewebp($dst, $path, $quality);
    }

    imagedestroy($src);
    imagedestroy($dst);
}

// ---------------------------------------------------------------
// Sets far-future browser caching for a protected attachment served through
// a PHP viewer script (kyc_view.php, payment_proof_view.php,
// benefit_document_view.php) and short-circuits with 304 if the browser
// already has this exact file cached. The file itself never changes once
// uploaded (KYC docs/payment proofs aren't re-uploaded in place), so it's
// safe to cache aggressively even though it's a private, login-gated file.
// ---------------------------------------------------------------
function send_attachment_cache_headers($path) {
    $etag = '"' . md5_file($path) . '"';
    $last_modified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';

    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $last_modified);

    $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($if_none_match === $etag || $if_modified_since === $last_modified) {
        http_response_code(304);
        exit;
    }
}

// ---------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------
// Sent when an admin approves a pending registration, via send_email()
// (Gmail SMTP — see config/email.example.php for setup). No password is
// included — the member logs in with whatever they chose at registration.
function send_account_approved_email($to_email, $full_name, $username) {
    if (empty($to_email)) {
        return false;
    }
    $module_name = 'JMC Foodies Wellness'; // runs outside page-render context, no $module_name variable available
    $subject = 'Your ' . $module_name . ' account has been confirmed';
    $message = "Hi {$full_name},\r\n\r\n"
        . 'Good news! Your ' . $module_name . " account has been reviewed and confirmed by our team.\r\n"
        . "You can now log in with the username and password you set at registration:\r\n\r\n"
        . "Username: {$username}\r\n\r\n"
        . 'Log in here: ' . BASE_URL . "/login.php\r\n\r\n"
        . '— ' . $module_name . ' Team';
    return send_email($to_email, $subject, $message);
}

// Used by both admin/users.php and admin/user_view.php. Emails the user
// only on a pending -> active transition (i.e. an actual approval), not on
// suspend/reinstate of an already-active account.
function update_user_status($conn, $id, $new_status) {
    $allowed_statuses = ['pending', 'active', 'suspended'];
    if (!in_array($new_status, $allowed_statuses, true)) {
        $new_status = 'active';
    }

    $stmt = $conn->prepare("SELECT status, email, full_name, username FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return;
    }

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $new_status, $id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'update_user_status', 'Set Wellness user "' . $user['full_name'] . '" status to ' . $new_status);

    if ($user['status'] === 'pending' && $new_status === 'active') {
        // Approval no longer resets the password — the member already chose
        // their own at registration, so they log in with that directly.
        send_account_approved_email($user['email'], $user['full_name'], $user['username']);
    }
}

// ---------------------------------------------------------------
// Referral codes
// ---------------------------------------------------------------
function generate_referral_code($conn) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    do {
        $code = 'JMC-';
        for ($i = 0; $i < 9; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $conn->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);
    return $code;
}

function referral_link($code) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . WELLNESS_URL . '/register.php?ref=' . urlencode($code);
}

// ---------------------------------------------------------------
// Wallet ledger
// ---------------------------------------------------------------
function wallet_balance($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS balance FROM wallet_transactions WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();
    return (float) $balance;
}

function wallet_sum_by_type($conn, $user_id, $type) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE user_id = ? AND type = ?");
    $stmt->bind_param('is', $user_id, $type);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return (float) $total;
}

function wallet_credit($conn, $user_id, $type, $amount, $order_id = null, $cashout_id = null, $description = null) {
    $stmt = $conn->prepare("INSERT INTO wallet_transactions (user_id, type, amount, reference_order_id, reference_cashout_id, description)
                             VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isdiis', $user_id, $type, $amount, $order_id, $cashout_id, $description);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------
// Order lifecycle: pending -> processing -> completed (or cancelled from either).
// Rewards only credit at "delivered" (completed) — matches the program manual's
// Payment Confirmation -> Order Processing -> Successful Delivery -> Reward Crediting flow.
// ---------------------------------------------------------------

// GCash orders only: admin confirms payment was received. No wallet effect yet.
function confirm_order_payment($conn, $order_id) {
    $stmt = $conn->prepare("UPDATE orders SET status = 'processing' WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $confirmed = $stmt->affected_rows > 0;
    $stmt->close();

    if ($confirmed) {
        log_activity($conn, 'confirm_order', 'Confirmed payment for Wellness order #' . $order_id);
    }
}

// Admin marks the order delivered. This is the only place rewards are credited.
function mark_order_delivered($conn, $order_id, $admin_id) {
    $stmt = $conn->prepare("SELECT o.*, u.referred_by FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? FOR UPDATE");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || $order['status'] !== 'processing') {
        return;
    }

    $rebate_rate = (float) setting($conn, 'personal_rebate_rate', 0.20);
    $override_rate = (float) setting($conn, 'referral_override_rate', 0.10);

    $rebate = round($order['total_amount'] * $rebate_rate, 2);
    wallet_credit($conn, $order['user_id'], 'personal_rebate', $rebate, $order_id, null,
        'Personal rebate (' . (int) ($rebate_rate * 100) . '%) on order #' . $order_id);

    if (!empty($order['referred_by'])) {
        $override = round($order['total_amount'] * $override_rate, 2);
        wallet_credit($conn, $order['referred_by'], 'referral_override', $override, $order_id, null,
            'Referral override (' . (int) ($override_rate * 100) . '%) on order #' . $order_id . ' by your direct referral');
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'completed', confirmed_at = NOW(), confirmed_by = ? WHERE id = ?");
    $stmt->bind_param('ii', $admin_id, $order_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'deliver_order', 'Marked Wellness order #' . $order_id . ' as delivered');
}

// Cancels a pending/processing order. Refunds the wallet debit if it was a
// wallet-paid order (money already left the wallet before delivery).
function cancel_order($conn, $order_id) {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND status IN ('pending', 'processing') FOR UPDATE");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return;
    }

    if ($order['payment_method'] === 'wallet') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM wallet_transactions WHERE reference_order_id = ? AND type = 'purchase_refund'");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $already_refunded = (int) $stmt->get_result()->fetch_assoc()['c'] > 0;
        $stmt->close();

        if (!$already_refunded) {
            wallet_credit($conn, $order['user_id'], 'purchase_refund', $order['total_amount'], $order_id, null,
                'Refund for cancelled order #' . $order_id);
        }
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'cancel_order', 'Cancelled Wellness order #' . $order_id);
}

// ---------------------------------------------------------------
// Google Gemini (free tier, key in config/gemini.php). One shared call used
// by every AI helper — the admin-side writing aids below and the Basics
// document/loan reviews in basics/includes/functions.php. Everything AI in
// the app is an aid for an admin, never a decision-maker.
// ---------------------------------------------------------------

// Sends a prompt (plus optionally one image/PDF) and asks for a JSON object
// back. Returns ['success' => true, 'data' => array] or
// ['success' => false, 'error' => string].
function gemini_generate_json($prompt, $file_path = null) {
    if (GEMINI_API_KEY === '') {
        return ['success' => false, 'error' => 'AI is not configured (no Gemini API key set).'];
    }

    $parts = [];
    if ($file_path !== null) {
        if (!is_file($file_path)) {
            return ['success' => false, 'error' => 'File is missing on disk.'];
        }
        if (filesize($file_path) > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'File is too large to analyze.'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $media_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        if (!in_array($media_type, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            return ['success' => false, 'error' => 'Unsupported file type for AI review.'];
        }
        $parts[] = ['inline_data' => ['mime_type' => $media_type, 'data' => base64_encode(file_get_contents($file_path))]];
    }
    $parts[] = ['text' => $prompt];

    $payload = json_encode([
        'contents' => [['parts' => $parts]],
        // No "thinking" — none of these tasks need it, and it keeps each
        // call well inside shared hosting's PHP time limit.
        'generationConfig' => ['response_mime_type' => 'application/json', 'temperature' => 0.2, 'thinkingConfig' => ['thinkingBudget' => 0]],
    ]);

    $model = 'gemini-2.5-flash';
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode(GEMINI_API_KEY));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['content-type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 429 || $http_code === 503) {
        return ['success' => false, 'busy' => true, 'error' => 'The AI service is busy right now. Please try again in a minute.'];
    }
    if ($response === false || $http_code < 200 || $http_code >= 300) {
        return ['success' => false, 'error' => 'AI request failed (HTTP ' . $http_code . ').'];
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['success' => false, 'error' => 'AI returned an unexpected response.'];
    }

    // response_mime_type: application/json should guarantee clean JSON, but
    // strip a ```json fence defensively in case the model adds one anyway.
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Could not parse the AI\'s response.'];
    }
    return ['success' => true, 'data' => $data];
}

// Broadcast pages' "Draft with AI": turns a rough instruction ("remind
// members payment is due Friday") into a ready-to-edit announcement. Only
// fills the form — the admin still reads it and clicks Send themselves.
// $language is 'english' or 'taglish'. Returns ['success', 'message',
// 'subject'] or ['success' => false, 'error'].
function ai_draft_broadcast($instruction, $language, $for_sms, $program_name) {
    $language_rule = $language === 'taglish'
        ? 'Write in natural, friendly Taglish (a casual mix of Tagalog and English, the way Filipinos text).'
        : 'Write in simple, friendly English.';
    $length_rule = $for_sms
        ? 'It will be sent as an SMS: keep the message under 300 characters, plain text, no emojis, no links unless the instruction includes one.'
        : 'It will be sent by email: keep it to one to three short paragraphs of plain text, no emojis.';

    $prompt = "Write an announcement from {$program_name} (a Philippine member program) to its members.\n"
        . "What the admin wants to say: {$instruction}\n\n"
        . "{$language_rule} {$length_rule} Keep every fact (dates, times, amounts) exactly as given and don't invent new ones. "
        . "End the message with \" - {$program_name}\".\n\n"
        . 'Respond with ONLY a JSON object (no markdown, no other text) in this exact shape: '
        . '{"message": "<the announcement>", "subject": "<a short email subject line, under 60 characters>"}';

    $result = gemini_generate_json($prompt);
    if (!$result['success']) {
        return $result;
    }
    $message = trim((string) ($result['data']['message'] ?? ''));
    if ($message === '') {
        return ['success' => false, 'error' => 'The AI returned an empty message. Try rewording your instruction.'];
    }
    return ['success' => true, 'message' => $message, 'subject' => trim((string) ($result['data']['subject'] ?? ''))];
}

// Wellness product page's "Write with AI": a short catalog description from
// the product name and/or photo. Returns ['success', 'description'] or
// ['success' => false, 'error'].
function ai_write_product_description($name, $image_path = null) {
    $prompt = "Write a short product description for a Philippine online catalog of wellness food and drink products (JMC Foodies Wellness).\n"
        . ($name !== '' ? "Product name: {$name}\n" : '')
        . ($image_path ? "The attached image is the product photo — use what's printed on the packaging (flavor, size, key ingredients).\n" : '')
        . "\nKeep it to two or three sentences of plain, friendly English. Describe what it is and who it's for. "
        . "Don't make any health or medical claims — no symptom relief, detox, healing, or \"supports/helps/improves\" a body function — even if the packaging prints them. "
        . "Don't invent ingredients, certifications or prices you can't see.\n\n"
        . 'Respond with ONLY a JSON object (no markdown, no other text) in this exact shape: {"description": "<the description>"}';

    $result = gemini_generate_json($prompt, $image_path);
    if (!$result['success']) {
        return $result;
    }
    $description = trim((string) ($result['data']['description'] ?? ''));
    if ($description === '') {
        return ['success' => false, 'error' => 'The AI returned an empty description.'];
    }
    return ['success' => true, 'description' => $description];
}

// ---------------------------------------------------------------
// Every page in the app requires this file right after config/database.php
// (which is where $conn comes from), so this is the one place guaranteed
// to run on every request — see maybe_run_scheduled_backup() above for why
// that's exactly what the traffic-driven backup scheduler needs.
// ---------------------------------------------------------------
if (isset($conn) && $conn instanceof mysqli) {
    maybe_run_scheduled_backup($conn);
    maybe_run_birthday_greetings($conn);
}
