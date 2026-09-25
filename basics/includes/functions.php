<?php
// JMC Foodies Basics business logic: continuous ordering, revolving
// credit-line checks, and the tiered late-payment penalty engine.

// Order fulfillment pipeline is admin-driven and fully decoupled from
// payment: Checking (pending) -> Preparing (confirmed) -> In Transit
// (out_for_delivery) -> Delivered. Cancelled is the only exit branch, only
// reachable from Checking/Preparing. There is no "paid" stage — payment
// completion is tracked separately via basics_payments regardless of where
// an order sits in this pipeline.
function basics_order_status_label($status) {
    $labels = [
        'draft' => 'Draft',
        'pending' => 'Checking',
        'confirmed' => 'Preparing',
        'out_for_delivery' => 'In Transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

// Maps a basics_orders.status to one of the shared .pill-* CSS classes
// (assets/css/theme.css) used across both admin panels.
function basics_order_status_pill($status) {
    $pills = [
        'draft' => 'pending',
        'pending' => 'processing',
        'confirmed' => 'approved',
        'out_for_delivery' => 'approved',
        'delivered' => 'completed',
        'cancelled' => 'cancelled',
    ];
    return $pills[$status] ?? 'pending';
}

// ---------------------------------------------------------------
// Admin account management (basics/admin/admins.php). Guards against
// locking the panel out of super_admin access entirely — role edits and
// deletes both check this before removing the last one.
// ---------------------------------------------------------------
function basics_super_admin_count($conn) {
    return (int) $conn->query("SELECT COUNT(*) AS c FROM basics_admins WHERE role = 'super_admin'")->fetch_assoc()['c'];
}

function basics_admin_role_label($role) {
    $labels = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'staff_orders' => 'Staff (Orders)',
        'staff_payments' => 'Staff (Payments)',
        'staff_registration' => 'Staff (Registration)',
    ];
    return $labels[$role] ?? ucfirst($role);
}

// The three restricted staff roles that admin/super_admin manage from
// basics/admin/staff.php. Admin and super_admin accounts themselves are only
// ever managed from admins.php (super_admin-only), so an admin can't create
// or edit anyone at or above their own level.
function basics_staff_roles() {
    return [
        'staff_orders' => 'Handles orders, applications, products and the supplier summary.',
        'staff_payments' => 'Handles payments, reminders, payment submissions, emergency credit, benefits, the dormancy report, and the communication log.',
        'staff_registration' => 'Registers new members and views users only — cannot approve, edit credit, or see orders and payments.',
    ];
}

// Whether an order is fully settled, shown as a "Paid" pill alongside (not
// instead of) the status pill above — deliberately kept out of the status
// enum itself after 7eab20d/309635d showed a single 'paid' status value
// can't represent an order that's e.g. delivered-but-unpaid or
// paid-before-delivery.
function basics_order_is_paid($total_amount, $amount_paid) {
    return (float) $total_amount > 0 && (float) $amount_paid >= (float) $total_amount;
}

// Gift orders (basics_orders.is_gift=1) are pinned at total_amount=0 and
// never require payment. basics_order_is_paid(0,0) returns false, and once
// a gift order is delivered, basics_payment_due_date()/basics_projected_penalty()
// would still compute a real due date and a nonsensical "₱0.00 penalty" once
// it passes — every payment-status display site must check is_gift first and
// use this instead of the normal paid/unpaid/overdue markup.
function basics_gift_pill() {
    return '<span class="pill pill-approved"><i class="fas fa-gift"></i> Gift</span>';
}

// Thin wrapper around send_sms() (includes/functions.php) — every Basics
// SMS trigger has a $member array (from basics_get_member() or a JOIN
// selecting u.contact_number) on hand already, so this saves repeating the
// column lookup at every call site. Gated by the admin-editable
// basics_sms_notifications_enabled setting (basics/admin/settings.php) —
// unlike an explicit admin broadcast, these are automatic triggers, so the
// admin gets a master off-switch for them.
function basics_notify($conn, $member, $message) {
    if (setting($conn, 'basics_sms_notifications_enabled', '1') !== '1') {
        return false;
    }
    return send_sms($member['contact_number'] ?? '', $message);
}

// Sent alongside the existing approval SMS (basics_notify() in
// basics/admin/application_view.php) when an admin approves a pending
// membership application — the SMS is a quick heads-up, this carries the
// actual login instructions. Via send_email() (includes/functions.php,
// Gmail SMTP). No password reset happens on approval — the applicant logs
// in with whatever they chose at signup.
function send_basics_account_approved_email($to_email, $full_name, $username, $weekly_limit) {
    if (empty($to_email)) {
        return false;
    }
    $subject = 'Your JMC Foodies Basics membership has been approved';
    $message = "Hi {$full_name},\r\n\r\n"
        . "Good news! Your JMC Foodies Basics membership application has been reviewed and approved.\r\n\r\n"
        . 'Weekly Credit Limit: ' . format_price($weekly_limit) . "\r\n\r\n"
        . "You can now log in and start ordering with the username and password you set at signup:\r\n\r\n"
        . "Username: {$username}\r\n\r\n"
        . 'Log in here: ' . BASICS_URL . "/login.php\r\n\r\n"
        . '— JMC Foodies Basics Team';
    return send_email($to_email, $subject, $message);
}

// Sent alongside the existing denial SMS (basics_notify() in
// basics/admin/application_view.php) when an admin denies a pending
// membership application. The phone number reminder isn't repeated here —
// send_email() (includes/functions.php) already appends it to every email.
function send_basics_account_denied_email($to_email, $full_name) {
    if (empty($to_email)) {
        return false;
    }
    $subject = 'Your JMC Foodies Basics application status';
    $message = "Hi {$full_name},\r\n\r\n"
        . "Thank you for choosing to apply for the JMC Foodies Basics Program.\r\n\r\n"
        . "Unfortunately, we are unable to approve your application at this time, based on your available credit and financial information. However, we would like you to consider applying again after 30 days.\r\n\r\n"
        . '— JMC Foodies Basics Team';
    return send_email($to_email, $subject, $message);
}

// Looks up the full member row (for basics_notify()) from a basics_orders.id
// — several admin actions only have the order id on hand, not the member.
function basics_member_by_order_id($conn, $order_id) {
    $stmt = $conn->prepare("SELECT bm.user_id FROM basics_orders o JOIN basics_members bm ON bm.id = o.member_id WHERE o.id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? basics_get_member($conn, $row['user_id']) : null;
}

// Looks up the full member row (for basics_notify()) from a basics_members.id.
function basics_member_by_id($conn, $member_id) {
    $stmt = $conn->prepare("SELECT user_id FROM basics_members WHERE id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? basics_get_member($conn, $row['user_id']) : null;
}


// The one designated developer/test account (username 'testbasics') — kept
// on both local and live DBs for safe end-to-end feature testing without
// ever touching a real member's data. Its activity is excluded from
// dashboard stats/charts and sidebar pending-count badges (see
// basics/admin/index.php and basics/admin/includes/admin_sidebar.php) so
// testing never skews what admins see. Matched by username rather than a
// hardcoded id, since the two databases don't share auto-increment ids.
// Returns 0 (matches no real member_id) if the account doesn't exist here.
function basics_test_member_id($conn) {
    static $id = null;
    if ($id === null) {
        $row = $conn->query("SELECT bm.id FROM basics_members bm
            JOIN basics_users u ON u.id = bm.user_id
            WHERE u.username = 'testbasics'")->fetch_assoc();
        $id = $row ? (int) $row['id'] : 0;
    }
    return $id;
}

function basics_get_member($conn, $user_id) {
    $stmt = $conn->prepare("SELECT bm.*, u.full_name, u.first_name, u.middle_name, u.last_name,
                                    u.username, u.email, u.contact_number, u.birthdate,
                                    u.address, u.address_line, u.barangay, u.city, u.province
                             FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id
                             WHERE bm.user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $member ?: null;
}

// full_name/address stay the authoritative columns every existing display,
// SMS, email, and receipt already reads — these just keep them in sync
// whenever the structured parts (first/middle/last, address_line/barangay/
// city/province) are entered or edited, so nothing else in the app needs
// to change. Empty parts are simply skipped rather than leaving stray
// double-spaces/commas.
function basics_compose_full_name($first, $middle, $last) {
    $parts = array_filter([trim((string) $first), trim((string) $middle), trim((string) $last)], fn($p) => $p !== '');
    return implode(' ', $parts);
}
function basics_compose_address($line, $barangay, $city, $province) {
    $parts = array_filter([trim((string) $line), trim((string) $barangay), trim((string) $city), trim((string) $province)], fn($p) => $p !== '');
    return implode(', ', $parts);
}

// Number of distinct products (not summed quantity) in the member's current
// draft cart — powers the badge on the navbar Cart link (includes/navbar.php).
function basics_cart_item_count($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c
                             FROM basics_orders o
                             JOIN basics_order_items oi ON oi.order_id = o.id
                             JOIN basics_members bm ON bm.id = o.member_id
                             WHERE bm.user_id = ? AND o.status = 'draft'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['c'];
}

// Unpaid balance across every order still in the fulfillment pipeline
// (Checking/Preparing/In Transit/Delivered) — total_amount minus whatever's
// already been paid on each. A revolving ceiling, not a per-cycle reset: an
// order keeps counting against the limit until it's actually paid off,
// regardless of how far along delivery it is — approving or delivering an
// order does not free up credit, only payment does.
function basics_outstanding_balance($conn, $member_id) {
    // GREATEST(...,0) per order — an overpaid order (e.g. a payment recorded
    // twice by mistake) must never create "negative debt" that inflates a
    // member's available credit above their actual limit.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(GREATEST(o.total_amount - IFNULL((SELECT SUM(amount_paid) FROM basics_payments p WHERE p.order_id = o.id), 0), 0)), 0) AS outstanding
                             FROM basics_orders o
                             WHERE o.member_id = ? AND o.status IN ('pending', 'confirmed', 'out_for_delivery', 'delivered')");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float) $row['outstanding'];
}

function basics_credit_available($conn, $member) {
    $outstanding = basics_outstanding_balance($conn, $member['id']);
    return max(0, (float) $member['weekly_credit_limit'] - $outstanding);
}

// Total released across all of this member's approved Emergency Cash Credit
// requests, minus confirmed repayments against them. Derived, not stored —
// a repayment submission just needs confirming for this to update itself.
function basics_emergency_credit_outstanding($conn, $member_id) {
    $stmt = $conn->prepare("SELECT
        COALESCE((SELECT SUM(amount_released) FROM basics_emergency_credit_requests WHERE member_id = ? AND status = 'approved'), 0)
        -
        COALESCE((SELECT SUM(s.amount) FROM basics_payment_submissions s
                  JOIN basics_emergency_credit_requests r ON r.id = s.loan_request_id
                  WHERE r.member_id = ? AND s.status = 'confirmed'), 0) AS outstanding");
    $stmt->bind_param('ii', $member_id, $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float) $row['outstanding'];
}

function basics_emergency_credit_available($conn, $member) {
    $outstanding = basics_emergency_credit_outstanding($conn, $member['id']);
    return max(0, (float) $member['emergency_credit_limit'] - $outstanding);
}

// This member's approved requests that still have a balance owed —
// populates the "which loan is this repaying" choice on the Pay! form.
function basics_member_outstanding_loans($conn, $member_id) {
    $stmt = $conn->prepare("SELECT r.*,
                                (r.amount_released - COALESCE((SELECT SUM(s.amount) FROM basics_payment_submissions s WHERE s.loan_request_id = r.id AND s.status = 'confirmed'), 0)) AS remaining
                             FROM basics_emergency_credit_requests r
                             WHERE r.member_id = ? AND r.status = 'approved'
                             HAVING remaining > 0
                             ORDER BY r.released_at ASC");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Government ID / clearance uploads. Deviates from handle_product_image_upload():
// accepts PDF too, and always saves under uploads/basics_kyc/, which is not
// publicly servable (see uploads/basics_kyc/.htaccess) — only ever read back
// through basics/admin/kyc_view.php.
function handle_kyc_document_upload($file_key) {
    if (empty($_FILES[$file_key]['name'])) {
        return [null, 'A file is required.'];
    }
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed.'];
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        return [null, 'File must be smaller than 5MB.'];
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$mime])) {
        return [null, 'File must be a JPG, PNG, WEBP, or PDF.'];
    }

    $new_filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$mime];
    $dest = UPLOAD_PATH . 'basics_kyc/' . $new_filename;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return [null, 'Failed to save uploaded file.'];
    }
    resize_image_if_needed($dest, $mime);
    return [$new_filename, null];
}

// Receipt/screenshot attached to a member's payment submission — required.
function handle_payment_proof_upload($file_key) {
    if (empty($_FILES[$file_key]['name'])) {
        return [null, 'Proof of payment is required.'];
    }
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed.'];
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        return [null, 'File must be smaller than 5MB.'];
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$mime])) {
        return [null, 'Proof file must be a JPG, PNG, WEBP, or PDF.'];
    }

    $new_filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$mime];
    $dest = UPLOAD_PATH . 'basics_payment_proofs/' . $new_filename;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return [null, 'Failed to save the proof file.'];
    }
    resize_image_if_needed($dest, $mime);
    return [$new_filename, null];
}

// This member's pending orders that aren't fully paid yet — populates the
// "which order is this for" choice on the Grocery payment submission form.
function basics_member_awaiting_orders($conn, $member_id) {
    $stmt = $conn->prepare("SELECT o.*,
                                    (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                             FROM basics_orders o
                             WHERE o.member_id = ? AND o.status IN ('confirmed', 'out_for_delivery', 'delivered')
                             HAVING amount_paid < o.total_amount
                             ORDER BY o.created_at DESC");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Payment due date is per-order, not per-cycle: 7 days after the order was
// actually marked delivered. An order that hasn't been delivered yet has no
// due date at all — there is nothing to be late on until it arrives.
function basics_payment_due_date($order) {
    if (empty($order['delivered_at'])) {
        return null;
    }
    return date('Y-m-d', strtotime($order['delivered_at'] . ' +7 days'));
}

// Shared by basics_record_payment() (actual penalty) and the Record Payment
// list (a "what would this cost right now" projection for overdue orders,
// shown before anyone clicks Record Payment) — one place for the tier rates
// so the two can never drift apart.
function basics_late_penalty_rate($conn, $offense_number) {
    $tier = min($offense_number, 3);
    return (float) setting($conn, 'basics_late_penalty_tier' . $tier, $tier === 1 ? 0.03 : 0.05);
}

// "What would recording this payment cost right now" for one order awaiting
// payment — used by the Record Payment list (basics/admin/payments.php) to
// show the real total (order + penalty) before anyone clicks in, and to
// prefill that total in the Record Payment modal. Returns null when the
// order isn't overdue yet (nothing projected — this mirrors is_late in
// basics_record_payment(), which also only applies a penalty once overdue).
function basics_projected_penalty($conn, $order, $offense_count) {
    $due_date = basics_payment_due_date($order);
    if ($due_date === null || date('Y-m-d') <= $due_date) {
        return null;
    }
    $offense_number = (int) $offense_count + 1;
    $rate = basics_late_penalty_rate($conn, $offense_number);
    $amount = round((float) $order['total_amount'] * $rate, 2);
    $impact = $offense_number === 1 ? 'credit freeze' : ($offense_number === 2 ? '1-month suspension' : 'termination');
    return ['rate' => $rate, 'amount' => $amount, 'impact' => $impact];
}

// Records a payment against a pending order, applying the late-payment
// penalty tier + credit-line/suspension escalation in one transaction.
// Returns ['is_late' => bool, 'penalty_amount' => float, 'membership_status' => string].
function basics_record_payment($conn, $order_id, $amount_paid, $paid_at, $admin_id, $notes = null) {
    $stmt = $conn->prepare("SELECT * FROM basics_orders WHERE id = ? AND status IN ('confirmed', 'out_for_delivery', 'delivered') FOR UPDATE");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception('Order not found or not awaiting payment.');
    }

    $stmt = $conn->prepare("SELECT * FROM basics_members WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $order['member_id']);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $due_date = basics_payment_due_date($order);
    $paid_date = date('Y-m-d', strtotime($paid_at));
    $is_late = $due_date !== null && $paid_date > $due_date;
    $amount_due = (float) $order['total_amount'];

    $penalty_rate = 0.0;
    $penalty_amount = 0.0;
    $offense_number = null;
    $new_offense_count = (int) $member['offense_count'];
    $new_on_time = (int) $member['consecutive_on_time_payments'];
    $new_frozen = (int) $member['credit_limit_frozen'];
    $new_status = $member['membership_status'];
    $new_suspended_until = $member['suspended_until'];

    if ($is_late) {
        $offense_number = (int) $member['offense_count'] + 1;
        $penalty_rate = basics_late_penalty_rate($conn, $offense_number);
        $penalty_amount = round($amount_due * $penalty_rate, 2);

        $new_offense_count = $offense_number;
        $new_on_time = 0;
        if ($offense_number === 1) {
            $new_frozen = 1;
        } elseif ($offense_number === 2) {
            $new_status = 'suspended';
            $new_suspended_until = date('Y-m-d', strtotime('+1 month'));
        } elseif ($offense_number >= 3) {
            $new_status = 'terminated';
        }
    } else {
        $new_on_time += 1;
    }

    $stmt = $conn->prepare("INSERT INTO basics_payments
        (order_id, member_id, amount_due, penalty_rate, penalty_amount, amount_paid, offense_number, is_late, paid_at, recorded_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $is_late_int = $is_late ? 1 : 0;
    $stmt->bind_param('iiddddiisis',
        $order_id, $order['member_id'], $amount_due, $penalty_rate, $penalty_amount, $amount_paid,
        $offense_number, $is_late_int, $paid_at, $admin_id, $notes);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE basics_members SET
        offense_count = ?, consecutive_on_time_payments = ?, credit_limit_frozen = ?,
        membership_status = ?, suspended_until = ?, last_activity_at = NOW()
        WHERE id = ?");
    $stmt->bind_param('iiissi', $new_offense_count, $new_on_time, $new_frozen, $new_status, $new_suspended_until, $member['id']);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'record_basics_payment', 'Recorded ' . ($is_late ? 'late' : 'on-time') . ' payment of ' . format_price($amount_paid) . ' for Basics order #' . $order_id);

    $notify_member = basics_get_member($conn, $member['user_id']);
    if ($notify_member) {
        if ($new_status === 'active') {
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Your credit limit has been restored - you may now place new orders. - JMC Foodies Basics");
        } elseif ($new_status === 'suspended') {
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Due to repeated late payment, your membership has been suspended until " . date('M j, Y', strtotime($new_suspended_until)) . ". - JMC Foodies Basics");
        } elseif ($new_status === 'terminated') {
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Due to repeated late payment, your JMC Foodies Basics membership has been terminated. - JMC Foodies Basics");
        }
    }

    return ['is_late' => $is_late, 'penalty_amount' => $penalty_amount, 'membership_status' => $new_status];
}

// ---------------------------------------------------------------
// Phase 2 benefit programs (program manual section 10): Electric Bill Cash
// Subsidy, Hospital Financial Assistance, Burial Financial Assistance, Baon
// Eskwela Subsidy. One shared request table + doc table for all four — see
// basics/benefits.php (member) and basics/admin/benefit_requests.php (admin).
// ---------------------------------------------------------------

function basics_benefit_type_labels() {
    return [
        'electric_subsidy' => 'Electric Bill Cash Subsidy',
        'hospital_assistance' => 'Hospital Financial Assistance',
        'burial_assistance' => 'Burial Financial Assistance',
        'baon_eskwela' => 'Baon Eskwela Subsidy',
    ];
}

// Single source of truth for which documents each benefit type requires —
// drives both the member submission form's required fields and the admin
// review page's document labels, so the two can never drift apart.
function basics_benefit_doc_requirements($benefit_type) {
    $requirements = [
        'electric_subsidy' => ['electric_bill' => 'Electric Bill'],
        'hospital_assistance' => [
            'medical_abstract' => 'Medical Abstract / Certificate',
            'hospital_bill' => 'Hospital Bill',
            'prescription' => 'Prescription(s)',
        ],
        'burial_assistance' => ['death_certificate' => 'Death Certificate'],
        'baon_eskwela' => [
            'enrollment_form' => 'Current Enrollment Form',
            'child_id' => "Child's ID",
            'birth_certificate' => "Child's Birth Certificate",
        ],
    ];
    return $requirements[$benefit_type] ?? [];
}

// Same upload rules as handle_kyc_document_upload() (JPG/PNG/WEBP/PDF, 5MB),
// but saved under uploads/basics_benefit_docs/ — also not publicly servable,
// these are medical records / death certificates. Missing file is an error
// here (unlike the optional payment proof) since each doc_type is required
// per basics_benefit_doc_requirements().
function handle_benefit_document_upload($file_key) {
    if (empty($_FILES[$file_key]['name'])) {
        return [null, 'A file is required.'];
    }
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed.'];
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        return [null, 'File must be smaller than 5MB.'];
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$mime])) {
        return [null, 'File must be a JPG, PNG, WEBP, or PDF.'];
    }

    $new_filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$mime];
    $dest = UPLOAD_PATH . 'basics_benefit_docs/' . $new_filename;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return [null, 'Failed to save the uploaded file.'];
    }
    resize_image_if_needed($dest, $mime);
    return [$new_filename, null];
}

// Reads the amount and reference number off a submitted payment proof image
// via the Claude API and compares them against what the member typed in —
// stores the result (proof_check_status/proof_extracted_*) for the admin to
// see on basics/admin/payment_submissions.php. Purely advisory: this never
// confirms or rejects a submission itself, only flags a possible mismatch
// for a human to look at. Called once, right after a submission is
// inserted (basics/payments.php) — wrap the call site in try/catch so a
// network hiccup here never blocks the member's actual submission.
//
// Silently marks the submission 'error' (not checked) if ANTHROPIC_API_KEY
// isn't configured, same "degrade quietly" contract as
// send_sms()/send_email() when their own keys are blank.
function basics_check_payment_proof($conn, $submission_id) {
    $stmt = $conn->prepare("SELECT proof_image, amount, reference_number FROM basics_payment_submissions WHERE id = ?");
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission) {
        return;
    }

    $set_result = function ($status, $extracted_amount = null, $extracted_reference = null, $notes = null) use ($conn, $submission_id) {
        $stmt = $conn->prepare("UPDATE basics_payment_submissions SET proof_check_status = ?, proof_extracted_amount = ?, proof_extracted_reference = ?, proof_check_notes = ? WHERE id = ?");
        $stmt->bind_param('sdssi', $status, $extracted_amount, $extracted_reference, $notes, $submission_id);
        $stmt->execute();
        $stmt->close();
    };

    if (empty($submission['proof_image'])) {
        $set_result('no_proof');
        return;
    }

    $path = UPLOAD_PATH . 'basics_payment_proofs/' . $submission['proof_image'];
    if (!is_file($path)) {
        $set_result('error', null, null, 'Proof file missing on disk.');
        return;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $media_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($media_types[$ext])) {
        $set_result('unsupported', null, null, "PDF proofs aren't auto-checked yet — please review the file manually.");
        return;
    }

    if (ANTHROPIC_API_KEY === '') {
        $set_result('error', null, null, 'Claude API key not configured (config/claude.php) — automatic check skipped.');
        return;
    }

    $image_data = base64_encode(file_get_contents($path));

    $prompt = 'This is a screenshot of a GCash or bank transfer receipt. '
        . 'Read the total amount paid/sent and the transaction reference number '
        . '(sometimes labeled "Ref No.", "Reference No.", "Ref #", or similar) exactly as printed. '
        . 'Respond with ONLY a JSON object, no other text, in this exact shape: '
        . '{"amount": "<numeric amount with no currency symbol or commas, e.g. 250.00, or null if unreadable>", '
        . '"reference_number": "<exact reference number as printed, or null if unreadable>"}';

    $payload = json_encode([
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => 300,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $media_types[$ext], 'data' => $image_data]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code < 200 || $http_code >= 300) {
        $detail = $curl_error ?: ('HTTP ' . $http_code . ': ' . mb_strimwidth((string) $response, 0, 150, '…'));
        $set_result('error', null, null, 'API request failed (' . $detail . ').');
        return;
    }

    $decoded = json_decode($response, true);
    $text = $decoded['content'][0]['text'] ?? null;
    if ($text === null) {
        $set_result('error', null, null, 'Unexpected API response shape.');
        return;
    }

    // Strip a markdown code fence in case the model wrapped the JSON in one.
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        $set_result('error', null, null, "Couldn't parse the extracted data: " . mb_strimwidth($text, 0, 150, '…'));
        return;
    }

    $extracted_amount_raw = $parsed['amount'] ?? null;
    $extracted_reference_raw = $parsed['reference_number'] ?? null;
    $extracted_amount = ($extracted_amount_raw !== null && $extracted_amount_raw !== '')
        ? round((float) preg_replace('/[^\d.]/', '', (string) $extracted_amount_raw), 2) : null;
    $extracted_reference = ($extracted_reference_raw !== null && $extracted_reference_raw !== '')
        ? trim((string) $extracted_reference_raw) : null;

    if ($extracted_amount === null && $extracted_reference === null) {
        $set_result('error', null, null, "Couldn't read an amount or reference number off this image.");
        return;
    }

    $amount_matches = $extracted_amount !== null && abs($extracted_amount - (float) $submission['amount']) < 0.01;
    // Ignore case/spaces/dashes — receipts format the same reference number
    // with different separators depending on the app/bank.
    $normalize_ref = fn($r) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $r));
    $reference_matches = $extracted_reference !== null
        && $normalize_ref($extracted_reference) === $normalize_ref($submission['reference_number']);

    if ($amount_matches && $reference_matches) {
        $set_result('match', $extracted_amount, $extracted_reference);
        return;
    }

    $notes = [];
    if (!$amount_matches) {
        $notes[] = 'Amount on proof: ' . ($extracted_amount !== null ? format_price($extracted_amount) : 'unreadable')
            . ' vs submitted ' . format_price($submission['amount']);
    }
    if (!$reference_matches) {
        $notes[] = 'Reference on proof: ' . ($extracted_reference ?? 'unreadable')
            . ' vs submitted ' . $submission['reference_number'];
    }
    $set_result('mismatch', $extracted_amount, $extracted_reference, implode(' | ', $notes));
}
