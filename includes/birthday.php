<?php
// ---------------------------------------------------------------
// JMC Basics — Birthday Grocery Gift.
//   * Members (approved + active) get an email + SMS greeting at 6AM on
//     their birthday, and a dashboard greeting with a "claim" button.
//   * The claim stays open for basics_birthday_claim_days (default 7) days
//     starting on the birthday, once per birthday year.
//   * Admins see today's / recent / upcoming celebrants and claim status
//     at basics/admin/birthdays.php.
// Lives in the shared includes (not basics/includes) because the 6AM job is
// driven by ordinary site traffic from any page — see maybe_run_birthday_greetings().
// Feb 29 birthdays are celebrated on Feb 28 in non-leap years.
// ---------------------------------------------------------------

function basics_birthday_occurrence($birthdate, $year) {
    [, $month, $day] = array_map('intval', explode('-', substr($birthdate, 0, 10)));
    if ($month === 2 && $day === 29 && !checkdate(2, 29, (int) $year)) {
        $day = 28;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function basics_birthday_claim_days($conn) {
    return max(1, (int) setting($conn, 'basics_birthday_claim_days', 7));
}

function basics_birthday_first_name($row) {
    $first = trim((string) ($row['first_name'] ?? ''));
    if ($first !== '') {
        return $first;
    }
    return explode(' ', trim((string) $row['full_name']))[0];
}

// Eligible celebrants: approved + active members with a real birthdate.
function basics_birthday_members($conn, $only_member_ids = null) {
    $sql = "SELECT bm.id AS member_id, u.full_name, u.first_name, u.username, u.email, u.contact_number, u.birthdate
            FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id
            WHERE bm.application_status = 'approved' AND bm.membership_status = 'active'
              AND u.birthdate >= '1900-01-01'";
    if ($only_member_ids !== null) {
        $ids = implode(',', array_map('intval', $only_member_ids));
        if ($ids === '') {
            return [];
        }
        $sql .= " AND bm.id IN ($ids)";
    }
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Every birthday occurrence falling within [today - lookback, today + lookahead],
// each with its gift row (greeted_at / claimed_at) attached. days_away is
// negative for birthdays already past. Sorted by date, then name.
function basics_birthday_entries($conn, $lookback_days, $lookahead_days, $only_member_ids = null) {
    $today = new DateTimeImmutable('today');
    $start = $today->modify("-{$lookback_days} days")->format('Y-m-d');
    $end = $today->modify("+{$lookahead_days} days")->format('Y-m-d');
    $this_year = (int) $today->format('Y');

    $entries = [];
    foreach (basics_birthday_members($conn, $only_member_ids) as $m) {
        $birth_year = (int) substr($m['birthdate'], 0, 4);
        foreach ([$this_year - 1, $this_year, $this_year + 1] as $year) {
            $date = basics_birthday_occurrence($m['birthdate'], $year);
            $age = $year - $birth_year;
            if ($date < $start || $date > $end || $age < 1) {
                continue;
            }
            $entries[] = $m + [
                'date' => $date,
                'year' => $year,
                'age' => $age,
                'days_away' => (int) $today->diff(new DateTimeImmutable($date))->format('%r%a'),
                'greeted_at' => null,
                'claimed_at' => null,
            ];
        }
    }

    if ($entries) {
        $gifts = [];
        $result = $conn->query("SELECT member_id, birthday_year, greeted_at, claimed_at FROM basics_birthday_gifts
                                 WHERE birthday_year BETWEEN " . ($this_year - 1) . " AND " . ($this_year + 1));
        while ($g = $result->fetch_assoc()) {
            $gifts[$g['member_id'] . '-' . $g['birthday_year']] = $g;
        }
        foreach ($entries as &$e) {
            $g = $gifts[$e['member_id'] . '-' . $e['year']] ?? null;
            if ($g) {
                $e['greeted_at'] = $g['greeted_at'];
                $e['claimed_at'] = $g['claimed_at'];
            }
        }
        unset($e);
    }

    usort($entries, function ($a, $b) {
        return [$a['date'], $a['full_name']] <=> [$b['date'], $b['full_name']];
    });
    return $entries;
}

// The member's currently-open birthday gift (birthday within the claim
// window), or null. $member needs membership_status + birthdate (from
// basics_get_member()).
function basics_birthday_gift_status($conn, $member) {
    if (($member['membership_status'] ?? '') !== 'active' || empty($member['birthdate'])) {
        return null;
    }
    $claim_days = basics_birthday_claim_days($conn);
    $today = strtotime(date('Y-m-d'));
    $this_year = (int) date('Y');
    $birth_year = (int) substr($member['birthdate'], 0, 4);

    foreach ([$this_year, $this_year - 1] as $year) {
        $date = basics_birthday_occurrence($member['birthdate'], $year);
        $elapsed = (int) round(($today - strtotime($date)) / 86400);
        if ($year - $birth_year < 1 || $elapsed < 0 || $elapsed >= $claim_days) {
            continue;
        }
        $stmt = $conn->prepare("SELECT claimed_at FROM basics_birthday_gifts WHERE member_id = ? AND birthday_year = ?");
        $stmt->bind_param('ii', $member['id'], $year);
        $stmt->execute();
        $gift = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'year' => $year,
            'date' => $date,
            'age' => $year - $birth_year,
            'is_today' => $elapsed === 0,
            'claim_by' => date('Y-m-d', strtotime($date . ' +' . ($claim_days - 1) . ' days')),
            'claimed_at' => $gift['claimed_at'] ?? null,
        ];
    }
    return null;
}

// Returns true if the gift is (now or already) claimed, false if there's no
// open birthday gift to claim.
function basics_claim_birthday_gift($conn, $member) {
    $status = basics_birthday_gift_status($conn, $member);
    if (!$status) {
        return false;
    }
    if ($status['claimed_at']) {
        return true;
    }
    $stmt = $conn->prepare("INSERT INTO basics_birthday_gifts (member_id, birthday_year, claimed_at) VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE claimed_at = IF(claimed_at IS NULL, NOW(), claimed_at)");
    $stmt->bind_param('ii', $member['id'], $status['year']);
    $stmt->execute();
    $stmt->close();
    log_activity($conn, 'claim_birthday_gift', 'Member #' . $member['id'] . ' claimed their Birthday Grocery Gift (' . $status['year'] . ')');
    return true;
}

function basics_birthday_messages($first_name, $claim_days) {
    return [
        'subject' => 'Happy Birthday from JMC Foodies Basics!',
        'email' => "Hi {$first_name},\r\n\r\n"
            . "Happy Birthday from all of us at JMC Foodies Basics!\r\n\r\n"
            . "As a valued member, you have a Birthday Grocery Gift waiting for you. "
            . "Log in to your dashboard and press \"Claim My Birthday Gift\" within the next {$claim_days} days.\r\n\r\n"
            . '— JMC Foodies Basics Team',
        'sms' => "Happy Birthday, {$first_name}! Your JMC Foodies Basics Birthday Grocery Gift is waiting - log in to your dashboard and claim it within {$claim_days} days. - JMC Foodies Basics",
    ];
}

// Sends greetings for every celebrant whose birthday is today. Safe to call
// repeatedly: each member's greeting is claimed with an atomic UPDATE on
// greeted_at IS NULL, so concurrent/repeat runs can never double-send.
// $only_member_ids restricts the run (used by tests). Returns members greeted.
function basics_run_birthday_greetings($conn, $only_member_ids = null) {
    $claim_days = basics_birthday_claim_days($conn);
    $sms_enabled = setting($conn, 'basics_sms_notifications_enabled', '1') === '1';
    $greeted = 0;

    foreach (basics_birthday_entries($conn, 0, 0, $only_member_ids) as $c) {
        $stmt = $conn->prepare("INSERT IGNORE INTO basics_birthday_gifts (member_id, birthday_year) VALUES (?, ?)");
        $stmt->bind_param('ii', $c['member_id'], $c['year']);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE basics_birthday_gifts SET greeted_at = NOW()
                                 WHERE member_id = ? AND birthday_year = ? AND greeted_at IS NULL");
        $stmt->bind_param('ii', $c['member_id'], $c['year']);
        $stmt->execute();
        $won = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$won) {
            continue;
        }

        $msg = basics_birthday_messages(basics_birthday_first_name($c), $claim_days);
        send_email($c['email'] ?? '', $msg['subject'], $msg['email']);
        if ($sms_enabled) {
            send_sms($c['contact_number'] ?? '', $msg['sms']);
        }
        $greeted++;
    }
    return $greeted;
}

// Traffic-driven "6AM job" — same idea as maybe_run_scheduled_backup(): no
// cron on this host, so the first page load at/after 6:00 AM each day runs
// today's greetings (so actual send time is 6AM or the first visit after it).
// Never runs on a local machine: the local DB is a copy of real member data,
// and this would email/text real people. Failures are swallowed so a problem
// here (e.g. table not created yet) can never break the page being viewed.
function maybe_run_birthday_greetings($conn) {
    global $is_local;
    if (!isset($is_local) || $is_local || (int) date('G') < 6) {
        return;
    }
    $today = date('Y-m-d');
    if (setting($conn, 'basics_birthday_greetings_last_run') === $today) {
        return;
    }
    try {
        basics_run_birthday_greetings($conn);
        save_setting($conn, 'basics_birthday_greetings_last_run', $today);
    } catch (Throwable $e) {
        // retried on the next request
    }
}
