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
        'pending' => 'checking',
        'confirmed' => 'preparing',
        'out_for_delivery' => 'intransit',
        'delivered' => 'delivered',
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
        'staff_payments' => 'Handles payments, reminders, payment submissions, emergency cash loans, benefits, the dormancy report, and the communication log.',
        'staff_registration' => 'Registers new members and views users only — cannot approve, edit purchase limits, or see orders and payments.',
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
        . 'Weekly Purchase Limit: ' . format_price($weekly_limit) . "\r\n\r\n"
        . "You can now log in and start ordering with the username and password you set at signup:\r\n\r\n"
        . "Username: {$username}\r\n\r\n"
        . 'Log in here: ' . absolute_url(BASICS_URL . '/login.php') . "\r\n\r\n"
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
        . "Unfortunately, we are unable to approve your application at this time, based on your available purchase capacity and financial information. However, we would like you to consider applying again after 30 days.\r\n\r\n"
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

// ---------------------------------------------------------------
// Community Partner Account — a regular member (same benefits/privileges,
// can order for themselves) who also earns a % override on orders placed by
// other members tagged under them via referral code at signup
// (basics/apply.php). Mirrors the Wellness referral/wallet system
// (generate_referral_code()/wallet_*() in includes/functions.php) but kept
// entirely separate: this wallet is only an override-earnings ledger, never
// used to pay for groceries (Basics purchases stay on the credit-line/
// payment-due system).
// ---------------------------------------------------------------
function basics_generate_referral_code($conn) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    do {
        $code = 'JMCB-';
        for ($i = 0; $i < 9; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $conn->prepare("SELECT id FROM basics_members WHERE referral_code = ?");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);
    return $code;
}

function basics_referral_link($code) {
    return absolute_url(BASICS_URL . '/apply.php?ref=' . urlencode($code));
}

function basics_wallet_balance($conn, $member_id) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS balance FROM basics_wallet_transactions WHERE member_id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();
    return (float) $balance;
}

function basics_wallet_sum_by_type($conn, $member_id, $type) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM basics_wallet_transactions WHERE member_id = ? AND type = ?");
    $stmt->bind_param('is', $member_id, $type);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return (float) $total;
}

function basics_wallet_credit($conn, $member_id, $type, $amount, $order_id = null, $cashout_id = null, $description = null) {
    $stmt = $conn->prepare("INSERT INTO basics_wallet_transactions (member_id, type, amount, reference_order_id, reference_cashout_id, description)
                             VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isdiis', $member_id, $type, $amount, $order_id, $cashout_id, $description);
    $stmt->execute();
    $stmt->close();
}

// The single place a Basics order transitions to Delivered — both
// basics/admin/order_view.php and basics/admin/orders.php call this instead
// of each running their own copy of the UPDATE/notify logic, so the
// referral-override credit below can't be missed from either entry point.
// Returns false (no-op) if the order isn't actually out_for_delivery.
function basics_deliver_order($conn, $order_id, $admin_id) {
    $stmt = $conn->prepare("SELECT o.*, bm.referred_by FROM basics_orders o
                             JOIN basics_members bm ON bm.id = o.member_id
                             WHERE o.id = ? AND o.status = 'out_for_delivery' FOR UPDATE");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        return false;
    }

    // Delivery no longer waits on payment — members get their groceries on
    // schedule regardless, and settle by the (much later) payment due date.
    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    // Gift orders are pinned at total_amount=0 — nothing to override on.
    // Re-checking is_community_partner here (not just "referred_by is set")
    // guards against the referrer's partner status having been revoked since
    // the referred member signed up.
    if (!empty($order['referred_by']) && (float) $order['total_amount'] > 0) {
        $stmt = $conn->prepare("SELECT is_community_partner FROM basics_members WHERE id = ?");
        $stmt->bind_param('i', $order['referred_by']);
        $stmt->execute();
        $partner = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($partner && $partner['is_community_partner']) {
            $rate = (float) setting($conn, 'basics_partner_override_rate', 0.02);
            $override = round($order['total_amount'] * $rate, 2);
            basics_wallet_credit($conn, $order['referred_by'], 'referral_override', $override, $order_id, null,
                'Referral override (' . (int) ($rate * 100) . '%) on order #' . $order_id);
        }
    }

    log_activity($conn, 'deliver_basics_order', 'Marked Basics order #' . $order_id . ' as delivered');
    basics_record_order_status($conn, $order_id, 'delivered', basics_admin_name_by_id($conn, $admin_id));
    $member = basics_member_by_order_id($conn, $order_id);
    if ($member) {
        $due_date = date('Y-m-d', strtotime('+7 days'));
        basics_notify($conn, $member, "Hi {$member['full_name']}, your order has been delivered! Please settle your balance by " . date('M j, Y', strtotime($due_date)) . ". - JMC Foodies Basics");
    }
    return true;
}

function basics_current_admin_name() {
    return $_SESSION['basics_admin_name'] ?? 'Admin';
}

function basics_admin_name_by_id($conn, $admin_id) {
    if (!$admin_id) {
        return 'Admin';
    }
    $stmt = $conn->prepare("SELECT name FROM basics_admins WHERE id = ?");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $name = $stmt->get_result()->fetch_assoc()['name'] ?? null;
    $stmt->close();
    return $name ?: 'Admin';
}

// Appends one row to the order's status trail (basics_order_status_history)
// — shown as the "Order Trail" card on basics/admin/order_view.php.
// $actor_label is a precomputed human string (admin name, or a member's
// full name for self-service actions like placing/cancelling their own
// order) rather than an id, since the two kinds of actor live in entirely
// separate tables/sessions.
function basics_record_order_status($conn, $order_id, $status, $actor_label, $note = null) {
    $stmt = $conn->prepare("INSERT INTO basics_order_status_history (order_id, status, actor_label, note) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $order_id, $status, $actor_label, $note);
    $stmt->execute();
    $stmt->close();
}

// Shared with basics/admin/order_view.php and basics/admin/orders.php so the
// empty-gift safeguard and status-trail recording can't be missed from
// either entry point (previously only order_view.php checked for an empty
// gift order before approving it).
// Returns 'confirmed', 'empty_gift', or 'not_found'.
function basics_confirm_order($conn, $order_id, $actor_label) {
    $stmt = $conn->prepare("SELECT is_gift, (SELECT COUNT(*) FROM basics_order_items WHERE order_id = basics_orders.id) AS item_count FROM basics_orders WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$check) {
        return 'not_found';
    }
    if ($check['is_gift'] && (int) $check['item_count'] === 0) {
        return 'empty_gift';
    }

    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $confirmed = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$confirmed) {
        return 'not_found';
    }

    basics_record_order_status($conn, $order_id, 'confirmed', $actor_label);
    log_activity($conn, 'confirm_basics_order', 'Approved Basics order #' . $order_id);
    $member = basics_member_by_order_id($conn, $order_id);
    if ($member) {
        basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$order_id} has been approved and is being prepared. - JMC Foodies Basics");
    }
    return 'confirmed';
}

// Shared with basics/admin/order_view.php and basics/admin/orders.php.
function basics_send_order_out_for_delivery($conn, $order_id, $actor_label) {
    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'out_for_delivery', out_for_delivery_at = NOW() WHERE id = ? AND status = 'confirmed'");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $moved = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$moved) {
        return false;
    }

    basics_record_order_status($conn, $order_id, 'out_for_delivery', $actor_label);
    log_activity($conn, 'basics_order_out_for_delivery', 'Marked Basics order #' . $order_id . ' as out for delivery');
    $member = basics_member_by_order_id($conn, $order_id);
    if ($member) {
        basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$order_id} is out for delivery! - JMC Foodies Basics");
    }
    return true;
}

// Shared with basics/admin/order_view.php, basics/admin/orders.php, and the
// member-facing basics/order_view.php (self-cancel while still Checking).
// $cancel_reason is optional — orders.php's list-page Cancel button and the
// member's self-cancel don't collect one, only order_view.php's modal does.
function basics_cancel_order($conn, $order_id, $actor_label, $cancel_reason = null) {
    if ($cancel_reason !== null) {
        $stmt = $conn->prepare("UPDATE basics_orders SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND status IN ('pending', 'confirmed')");
        $stmt->bind_param('si', $cancel_reason, $order_id);
    } else {
        $stmt = $conn->prepare("UPDATE basics_orders SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'confirmed')");
        $stmt->bind_param('i', $order_id);
    }
    $stmt->execute();
    $cancelled = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$cancelled) {
        return false;
    }

    basics_record_order_status($conn, $order_id, 'cancelled', $actor_label, $cancel_reason);
    log_activity($conn, 'cancel_basics_order', 'Cancelled Basics order #' . $order_id . ($cancel_reason ? ': ' . $cancel_reason : ''));
    $member = basics_member_by_order_id($conn, $order_id);
    if ($member) {
        $reason_note = $cancel_reason ? " Reason: {$cancel_reason}" : '';
        basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$order_id} has been cancelled.{$reason_note} - JMC Foodies Basics");
    }
    return true;
}

// Directly sets an order's LIVE status — used only when an admin edits the
// current (last) row of the Order Trail on order_view.php, since that's the
// only row that actually represents "what state is this order in right
// now". Unlike basics_confirm_order()/basics_send_order_out_for_delivery()/
// basics_cancel_order()/basics_deliver_order(), this has no
// WHERE status = '<prior stage>' guard and can jump to any status in either
// direction — the admin is asserting a correction, not performing a fresh
// pipeline action. For the same reason it never notifies the member (this
// is a data fix, not a new event happening to them right now).
// Still credits the referral override exactly once if newly set to
// Delivered — guarded by checking no override row already exists for this
// order, so toggling the status back and forth can't double-credit.
function basics_force_order_status($conn, $order_id, $new_status, $actor_label, $note = null) {
    $stmt = $conn->prepare("SELECT * FROM basics_orders WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        return false;
    }

    $timestamp_columns = ['confirmed' => 'confirmed_at', 'out_for_delivery' => 'out_for_delivery_at', 'delivered' => 'delivered_at'];
    $timestamp_column = $timestamp_columns[$new_status] ?? null;

    if ($new_status === 'cancelled') {
        $stmt = $conn->prepare("UPDATE basics_orders SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $order_id);
    } elseif ($timestamp_column && empty($order[$timestamp_column])) {
        // Only fills the timestamp if it was never set — a correction
        // shouldn't overwrite a real historical date with "now".
        $stmt = $conn->prepare("UPDATE basics_orders SET status = ?, $timestamp_column = NOW() WHERE id = ?");
        $stmt->bind_param('si', $new_status, $order_id);
    } else {
        $stmt = $conn->prepare("UPDATE basics_orders SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $new_status, $order_id);
    }
    $stmt->execute();
    $stmt->close();

    if ($new_status === 'delivered' && (float) $order['total_amount'] > 0) {
        $stmt = $conn->prepare("SELECT id FROM basics_wallet_transactions WHERE type = 'referral_override' AND reference_order_id = ?");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $already_credited = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$already_credited) {
            $stmt = $conn->prepare("SELECT referred_by FROM basics_members WHERE id = ?");
            $stmt->bind_param('i', $order['member_id']);
            $stmt->execute();
            $referred_by = $stmt->get_result()->fetch_assoc()['referred_by'] ?? null;
            $stmt->close();

            if ($referred_by) {
                $stmt = $conn->prepare("SELECT is_community_partner FROM basics_members WHERE id = ?");
                $stmt->bind_param('i', $referred_by);
                $stmt->execute();
                $partner = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($partner && $partner['is_community_partner']) {
                    $rate = (float) setting($conn, 'basics_partner_override_rate', 0.02);
                    $override = round($order['total_amount'] * $rate, 2);
                    basics_wallet_credit($conn, $referred_by, 'referral_override', $override, $order_id, null,
                        'Referral override (' . (int) ($rate * 100) . '%) on order #' . $order_id . ' (status corrected)');
                }
            }
        }
    }

    log_activity($conn, 'force_basics_order_status', 'Corrected the live status of Basics order #' . $order_id . ' to ' . $new_status . ' (' . $actor_label . ')');
    return true;
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
    $impact = $offense_number === 1 ? 'purchase freeze' : ($offense_number === 2 ? '1-month suspension' : 'termination');
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
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Your purchase limit has been restored - you may now place new orders. - JMC Foodies Basics");
        } elseif ($new_status === 'suspended') {
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Due to repeated late payment, your membership has been suspended until " . date('M j, Y', strtotime($new_suspended_until)) . ". - JMC Foodies Basics");
        } elseif ($new_status === 'terminated') {
            basics_notify($conn, $notify_member, "Hi {$notify_member['full_name']}, we've received your payment of " . format_price($amount_paid) . ". Due to repeated late payment, your JMC Foodies Basics membership has been terminated. - JMC Foodies Basics");
        }
    }

    return ['is_late' => $is_late, 'penalty_amount' => $penalty_amount, 'membership_status' => $new_status];
}

// ---------------------------------------------------------------
// AI-assisted review — Google Gemini (via gemini_generate_json() in
// includes/functions.php) reads an uploaded screenshot/PDF, or summarizes
// a member's records, and the admin pages compare that against what the
// member entered. Advisory only: nothing here ever approves, confirms,
// denies or rejects anything, and it only runs when an admin explicitly
// clicks for one item (never automatically, never in bulk). Each result
// is saved on its row so pages don't re-run it on every reload.
//
//   basics_analyze_payment_proof()         — basics/admin/payment_submissions.php
//   basics_analyze_kyc_document()          — basics/admin/application_view.php
//   basics_analyze_benefit_document()      — basics/admin/benefit_requests.php
//   basics_prescreen_emergency_request()   — basics/admin/emergency_credit.php
// ---------------------------------------------------------------

// AI review is super-admin only: other roles never see the Analyze buttons
// or results, and the analyze actions refuse them server-side too.
function basics_ai_review_allowed() {
    return basics_admin_role() === 'super_admin';
}

// Gemini returns dates as YYYY-MM-DD; anything else is treated as unread.
function basics_ai_date($value) {
    $value = trim((string) ($value ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
}

function basics_analyze_payment_proof($conn, $submission_id) {
    $stmt = $conn->prepare("SELECT * FROM basics_payment_submissions WHERE id = ?");
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission || !$submission['proof_image']) {
        return ['success' => false, 'error' => 'No proof image on file for this submission.'];
    }

    $prompt = "This is a screenshot or PDF of a GCash/bank payment receipt. "
        . "Read the amount paid and the transaction/reference number exactly as shown. "
        . "Respond with ONLY a JSON object (no markdown, no other text) in this exact shape: "
        . '{"amount": <number or null>, "reference_number": "<string or null>", "notes": "<one short sentence, e.g. image is blurry, or amount not visible, or blank if nothing to flag>"}';

    $result = gemini_generate_json($prompt, UPLOAD_PATH . 'basics_payment_proofs/' . $submission['proof_image']);
    if (!$result['success']) {
        return $result;
    }
    $extracted = $result['data'];

    $extracted_amount = isset($extracted['amount']) && is_numeric($extracted['amount']) ? round((float) $extracted['amount'], 2) : null;
    $extracted_reference = !empty($extracted['reference_number']) ? trim((string) $extracted['reference_number']) : null;
    $notes = !empty($extracted['notes']) ? trim((string) $extracted['notes']) : null;

    $stmt = $conn->prepare("UPDATE basics_payment_submissions SET ai_analyzed_at = NOW(), ai_extracted_amount = ?, ai_extracted_reference = ?, ai_notes = ? WHERE id = ?");
    $stmt->bind_param('dssi', $extracted_amount, $extracted_reference, $notes, $submission_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'ai_analyze_payment_proof', 'Ran AI review on Basics payment submission #' . $submission_id);

    return ['success' => true, 'amount' => $extracted_amount, 'reference_number' => $extracted_reference, 'notes' => $notes];
}

// KYC and benefit documents both store their result as one JSON blob in
// ai_result, in this shape, so basics_ai_doc_result_html() can render
// either:
//   verdict  — 'ok' or 'check' ('check' whenever there's any concern)
//   detected — what the AI thinks the document actually is
//   fields   — [label => already-formatted value] of what it read
//   concerns — short strings, each one a reason for 'check'
function basics_ai_doc_result($detected, $fields, $concerns) {
    $concerns = array_values(array_unique(array_filter(array_map('trim', $concerns))));
    return [
        'verdict' => $concerns ? 'check' : 'ok',
        'detected' => $detected,
        'fields' => array_filter($fields, fn($value) => $value !== null && $value !== ''),
        'concerns' => $concerns,
    ];
}

// What each KYC doc_type is supposed to be, phrased for the AI prompt.
function basics_kyc_doc_expectations() {
    $valid_id = "a Philippine government-issued ID card (e.g. PhilSys National ID, driver's license, passport, UMID/SSS, PRC, postal ID, voter's ID)";
    return [
        'valid_id_1' => $valid_id,
        'valid_id_2' => $valid_id,
        'barangay_clearance' => 'a barangay clearance certificate',
        'membership_application_form' => 'the FRONT page of a filled-in, signed JMC Foodies Basics membership application form',
        'membership_application_form_back' => 'the BACK page of a filled-in, signed JMC Foodies Basics membership application form',
        'certificate_of_employment' => 'a certificate of employment or work clearance issued by an employer',
    ];
}

function basics_analyze_kyc_document($conn, $doc_id) {
    $stmt = $conn->prepare("SELECT d.*, u.full_name, u.birthdate, bm.employer_name
                             FROM basics_kyc_documents d
                             JOIN basics_members bm ON bm.id = d.member_id
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE d.id = ?");
    $stmt->bind_param('i', $doc_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found.'];
    }

    $expected = basics_kyc_doc_expectations()[$doc['doc_type']] ?? $doc['doc_type'];
    $prompt = "You are helping an admin check a membership applicant's KYC document. "
        . "The applicant uploaded this as: {$expected}.\n"
        . "Applicant's name: {$doc['full_name']}\n"
        . "Applicant's birthdate: {$doc['birthdate']}\n"
        . "Applicant's employer: {$doc['employer_name']}\n\n"
        . "Read the document and respond with ONLY a JSON object (no markdown, no other text) in this exact shape:\n"
        . '{"detected_document": "<what this document actually is, a few words>", '
        . '"is_expected_document": <true if it is the kind of document described above, else false>, '
        . '"legible": <true or false>, '
        . '"name_on_document": "<the person\'s name as printed, or null>", '
        . '"name_matches_applicant": <true, false, or null if no name is visible — allow for middle names, initials, suffixes and different name order>, '
        . '"birthdate": "<YYYY-MM-DD as printed, or null>", '
        . '"expiry_date": "<YYYY-MM-DD, or null if none>", '
        . '"employer_on_document": "<employer/company name, or null>", '
        . '"employer_matches": <true, false, or null if no employer is shown>, '
        . '"signed": <true if a handwritten signature is visible, false if there is a signature line left blank, null if not applicable>, '
        . '"concerns": [<short strings for anything else an admin should double-check: signs of editing or tampering, cropped or cut-off, photo of a screen, blurry — empty array if none. Don\'t repeat name, birthdate, expiry, employer or signature mismatches here; those are compared separately>]}';

    $result = gemini_generate_json($prompt, UPLOAD_PATH . 'basics_kyc/' . $doc['file_path']);
    if (!$result['success']) {
        return $result;
    }
    $ai = $result['data'];

    $birthdate = basics_ai_date($ai['birthdate'] ?? null);
    $expiry = basics_ai_date($ai['expiry_date'] ?? null);
    $concerns = is_array($ai['concerns'] ?? null) ? array_map('strval', $ai['concerns']) : [];

    if (($ai['is_expected_document'] ?? true) === false) {
        $concerns[] = 'Not the expected document';
    }
    if (($ai['legible'] ?? true) === false) {
        $concerns[] = 'Hard to read';
    }
    if (($ai['name_matches_applicant'] ?? null) === false) {
        $concerns[] = "Name doesn't match the applicant";
    }
    if ($birthdate && $doc['birthdate'] && $birthdate !== $doc['birthdate']) {
        $concerns[] = "Birthdate doesn't match the applicant's (" . date('M j, Y', strtotime($doc['birthdate'])) . ')';
    }
    if ($expiry && $expiry < date('Y-m-d')) {
        $concerns[] = 'Expired';
    }
    if ($doc['doc_type'] === 'certificate_of_employment' && ($ai['employer_matches'] ?? null) === false) {
        $concerns[] = "Employer doesn't match the application";
    }
    if (($ai['signed'] ?? null) === false) {
        $concerns[] = 'No signature visible';
    }

    $stored = basics_ai_doc_result(
        trim((string) ($ai['detected_document'] ?? '')),
        [
            'Name' => trim((string) ($ai['name_on_document'] ?? '')),
            'Birthdate' => $birthdate ? date('M j, Y', strtotime($birthdate)) : null,
            'Expires' => $expiry ? date('M j, Y', strtotime($expiry)) : null,
            'Employer' => trim((string) ($ai['employer_on_document'] ?? '')),
        ],
        $concerns
    );

    $json = json_encode($stored);
    $stmt = $conn->prepare("UPDATE basics_kyc_documents SET ai_analyzed_at = NOW(), ai_result = ? WHERE id = ?");
    $stmt->bind_param('si', $json, $doc_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'ai_analyze_kyc_document', 'Ran AI review on KYC document #' . $doc_id . ' (member #' . $doc['member_id'] . ')');

    return ['success' => true] + $stored;
}

function basics_analyze_benefit_document($conn, $doc_id) {
    $stmt = $conn->prepare("SELECT d.*, r.member_id, r.benefit_type, r.relationship_to_deceased, r.deceased_address, u.full_name
                             FROM basics_benefit_documents d
                             JOIN basics_benefit_requests r ON r.id = d.request_id
                             JOIN basics_members bm ON bm.id = r.member_id
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE d.id = ?");
    $stmt->bind_param('i', $doc_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found.'];
    }

    $program = basics_benefit_type_labels()[$doc['benefit_type']] ?? $doc['benefit_type'];
    $expected = basics_benefit_doc_requirements($doc['benefit_type'])[$doc['doc_type']] ?? $doc['doc_type'];
    $context = "Member's name: {$doc['full_name']}\n";
    if ($doc['benefit_type'] === 'burial_assistance') {
        $context .= "Member's stated relationship to the deceased: {$doc['relationship_to_deceased']}\n"
            . "Stated address of the deceased: {$doc['deceased_address']}\n";
    }

    $prompt = "You are helping an admin check a document a member submitted for a \"{$program}\" benefit request "
        . "(a Philippine member assistance program). The member uploaded this as: {$expected}.\n"
        . $context . "\n"
        . "Read the document and respond with ONLY a JSON object (no markdown, no other text) in this exact shape:\n"
        . '{"detected_document": "<what this document actually is, a few words>", '
        . '"is_expected_document": <true if it is the kind of document described above, else false>, '
        . '"legible": <true or false>, '
        . '"name_on_document": "<the main person the document is about — account holder, patient, deceased or student — or null>", '
        . '"member_name_appears": <true if the member\'s name appears anywhere on it (e.g. as account holder, patient, parent, guardian or informant), else false>, '
        . '"amount": <the total amount billed/due as a number, or null if none>, '
        . '"document_date": "<YYYY-MM-DD of the bill, issue, death or enrollment, or null>", '
        . '"concerns": [<short strings for anything an admin should double-check: signs of editing or tampering, cropped or cut-off, blurry, an old or outdated document, the member\'s name missing where it would be expected (e.g. as a parent on a birth certificate), details that contradict the member\'s stated relationship or address — empty array if none>]}';

    $result = gemini_generate_json($prompt, UPLOAD_PATH . 'basics_benefit_docs/' . $doc['file_path']);
    if (!$result['success']) {
        return $result;
    }
    $ai = $result['data'];

    $document_date = basics_ai_date($ai['document_date'] ?? null);
    $amount = isset($ai['amount']) && is_numeric($ai['amount']) ? round((float) $ai['amount'], 2) : null;
    $concerns = is_array($ai['concerns'] ?? null) ? array_map('strval', $ai['concerns']) : [];

    if (($ai['is_expected_document'] ?? true) === false) {
        $concerns[] = 'Not the expected document';
    }
    if (($ai['legible'] ?? true) === false) {
        $concerns[] = 'Hard to read';
    }

    $member_appears = $ai['member_name_appears'] ?? null;
    $stored = basics_ai_doc_result(
        trim((string) ($ai['detected_document'] ?? '')),
        [
            'Name' => trim((string) ($ai['name_on_document'] ?? '')),
            'Member named' => is_bool($member_appears) ? ($member_appears ? 'Yes' : 'No') : null,
            'Amount' => $amount !== null ? format_price($amount) : null,
            'Date' => $document_date ? date('M j, Y', strtotime($document_date)) : null,
        ],
        $concerns
    );

    $json = json_encode($stored);
    $stmt = $conn->prepare("UPDATE basics_benefit_documents SET ai_analyzed_at = NOW(), ai_result = ? WHERE id = ?");
    $stmt->bind_param('si', $json, $doc_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'ai_analyze_benefit_document', 'Ran AI review on benefit document #' . $doc_id . ' (request #' . $doc['request_id'] . ')');

    return ['success' => true] + $stored;
}

// Emergency Cash Loan pre-screen (basics/admin/emergency_credit.php). The
// facts are all computed here from the database — the AI only turns them
// into a short read-out of strengths and risks, so it can't invent payment
// history, and it's told not to recommend approve/deny. Saved on the
// request row as ai_result JSON: ['facts' => [label => value], 'summary',
// 'positives' => [...], 'risks' => [...]].
function basics_prescreen_emergency_request($conn, $request_id) {
    $stmt = $conn->prepare("SELECT r.*, m.weekly_credit_limit, m.emergency_credit_limit, m.membership_status, m.offense_count,
                                   m.consecutive_on_time_payments, m.credit_limit_frozen, m.reviewed_at AS approved_at, m.applied_at
                             FROM basics_emergency_credit_requests r
                             JOIN basics_members m ON m.id = r.member_id
                             WHERE r.id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        return ['success' => false, 'error' => 'Request not found.'];
    }
    $member_id = (int) $request['member_id'];

    $stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_late), 0) AS late, COALESCE(SUM(penalty_amount), 0) AS penalties, MAX(paid_at) AS last_paid
                             FROM basics_payments WHERE member_id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT paid_at, amount_paid, is_late FROM basics_payments WHERE member_id = ? ORDER BY paid_at DESC LIMIT 6");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $recent = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
        $recent[] = date('M j', strtotime($p['paid_at'])) . ' ' . format_price($p['amount_paid']) . ($p['is_late'] ? ' (late)' : ' (on time)');
    }
    $stmt->close();

    // Same due rule as basics_payment_due_date(): 7 days after delivery.
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM basics_orders o
                             WHERE o.member_id = ? AND o.status = 'delivered'
                               AND DATE_ADD(DATE(o.delivered_at), INTERVAL 7 DAY) < CURDATE()
                               AND o.total_amount - IFNULL((SELECT SUM(amount_paid) FROM basics_payments p WHERE p.order_id = o.id), 0) > 0");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $overdue_orders = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM basics_emergency_credit_requests WHERE member_id = ? AND status = 'approved' AND id != ?");
    $stmt->bind_param('ii', $member_id, $request_id);
    $stmt->execute();
    $previous_loans = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $member_since = $request['approved_at'] ?: $request['applied_at'];
    $loan_outstanding = basics_emergency_credit_outstanding($conn, $member_id);
    $loan_available = basics_emergency_credit_available($conn, ['id' => $member_id, 'emergency_credit_limit' => $request['emergency_credit_limit']]);

    $facts = [
        'Requested' => format_price($request['amount_requested']) . ($request['reason'] ? ' — "' . $request['reason'] . '"' : ''),
        'Member since' => date('M j, Y', strtotime($member_since)) . ' (' . max(0, (int) floor((time() - strtotime($member_since)) / 86400)) . ' days)',
        'Membership status' => ucfirst($request['membership_status']) . ($request['credit_limit_frozen'] ? ', purchase limit frozen' : ''),
        'Grocery payments' => (int) $payments['total'] . ' recorded, ' . (int) $payments['late'] . ' late',
        'Late offenses' => (int) $request['offense_count'] . ' (penalties paid ' . format_price($payments['penalties']) . ')',
        'On-time streak' => (int) $request['consecutive_on_time_payments'] . ' payment(s) in a row',
        'Last payment' => $payments['last_paid'] ? date('M j, Y', strtotime($payments['last_paid'])) : 'None yet',
        'Recent payments' => $recent ? implode('; ', $recent) : 'None yet',
        'Unpaid grocery balance' => format_price(basics_outstanding_balance($conn, $member_id)) . ' (' . $overdue_orders . ' order(s) overdue)',
        'Previous loans' => $previous_loans . ' approved, ' . format_price($loan_outstanding) . ' still owed',
        'Loan limit available' => format_price($loan_available) . ' of ' . format_price($request['emergency_credit_limit']),
    ];

    $fact_lines = '';
    foreach ($facts as $label => $value) {
        $fact_lines .= "- {$label}: {$value}\n";
    }
    $prompt = "You are helping an admin of a Philippine grocery-credit member program review an Emergency Cash Loan request. "
        . "Today is " . date('M j, Y') . ". Here are the facts about this member, taken from the program's records:\n{$fact_lines}\n"
        . "Write a short, neutral pre-screen for the admin using ONLY these facts — don't assume anything that isn't listed. "
        . "Do NOT recommend approving or denying; the admin decides. "
        . "Respond with ONLY a JSON object (no markdown, no other text) in this exact shape: "
        . '{"summary": "<two or three plain-English sentences on the member\'s repayment track record>", '
        . '"positives": [<short strings — points in the member\'s favor>], '
        . '"risks": [<short strings — things the admin should weigh, e.g. late payments, overdue orders, an unpaid previous loan, a very new account>]}';

    $result = gemini_generate_json($prompt);
    if (!$result['success']) {
        return $result;
    }
    $ai = $result['data'];
    $strings = fn($list) => is_array($list) ? array_values(array_filter(array_map(fn($s) => trim((string) $s), $list))) : [];

    $stored = [
        'facts' => $facts,
        'summary' => trim((string) ($ai['summary'] ?? '')),
        'positives' => $strings($ai['positives'] ?? null),
        'risks' => $strings($ai['risks'] ?? null),
    ];

    $json = json_encode($stored);
    $stmt = $conn->prepare("UPDATE basics_emergency_credit_requests SET ai_analyzed_at = NOW(), ai_result = ? WHERE id = ?");
    $stmt->bind_param('si', $json, $request_id);
    $stmt->execute();
    $stmt->close();

    log_activity($conn, 'ai_prescreen_emergency_credit', 'Ran AI pre-screen on Emergency Cash Loan request #' . $request_id);

    return ['success' => true] + $stored;
}

// Renders a saved ai_result (see basics_ai_doc_result()) as a compact block
// under a document link: a Looks OK / Check pill, what was read, and any
// concerns.
function basics_ai_doc_result_html($ai_result_json) {
    $result = json_decode((string) $ai_result_json, true);
    if (!is_array($result)) {
        return '';
    }

    $ok = ($result['verdict'] ?? '') === 'ok';
    $html = '<div class="small mt-1">'
        . '<span class="pill pill-' . ($ok ? 'approved' : 'rejected') . '"><i class="fas fa-wand-magic-sparkles"></i> ' . ($ok ? 'Looks OK' : 'Check') . '</span>';
    if (!empty($result['detected'])) {
        $html .= ' <span class="text-muted">' . sanitize($result['detected']) . '</span>';
    }
    $fields = [];
    foreach ($result['fields'] ?? [] as $label => $value) {
        $fields[] = sanitize($label) . ': ' . sanitize($value);
    }
    if ($fields) {
        $html .= '<div>' . implode(' &middot; ', $fields) . '</div>';
    }
    foreach ($result['concerns'] ?? [] as $concern) {
        $html .= '<div class="text-danger"><i class="fas fa-triangle-exclamation"></i> ' . sanitize($concern) . '</div>';
    }
    return $html . '</div>';
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

// Optional QR code for a payment bank (basics/admin/payment_banks.php) —
// unlike KYC/benefit docs, no file is a valid choice, not every bank shows
// a QR to members. Publicly servable, unlike the private upload dirs above.
function handle_payment_bank_qr_upload($file_key) {
    if (empty($_FILES[$file_key]['name'])) {
        return [null, null];
    }
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed.'];
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        return [null, 'QR image must be smaller than 5MB.'];
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$mime])) {
        return [null, 'QR image must be a JPG, PNG, or WEBP.'];
    }

    $new_filename = bin2hex(random_bytes(16)) . '.' . $allowed_types[$mime];
    $dest = UPLOAD_PATH . 'basics_payment_bank_qrs/' . $new_filename;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest)) {
        return [null, 'Failed to save the uploaded QR image.'];
    }
    resize_image_if_needed($dest, $mime);
    return [$new_filename, null];
}
