<?php
// Aldan Guru Cron v3 - WA queuing disabled until Meta API ready
// Push notifications active for Android users
// Cron Jobs (UTC - IST = UTC+5:30):
//   0 2 * * *   curl -s "https://guru.aldancare.com/cron/remind.php?type=morning"
//   0 12 * * *  curl -s "https://guru.aldancare.com/cron/remind.php?type=evening"
//   0 13 * * *  curl -s "https://guru.aldancare.com/cron/remind.php?type=quiz"
//   30 13 * * * curl -s "https://guru.aldancare.com/cron/remind.php?type=streak"

header('Content-Type: application/json');

$type     = $_GET['type'] ?? 'morning';
$FB       = 'https://fintech-nd-default-rtdb.asia-southeast1.firebasedatabase.app/guru/v1';
$today    = date('d-m-Y');
$todayISO = date('Y-m-d');
$month    = date('Y-m');
$ts       = date('c');
$queued   = 0;

// WA queuing disabled - set to true when Meta API is ready
$WA_ENABLED = false;

function fb_get($url) {
    $r = @file_get_contents($url);
    return $r ? json_decode($r, true) : null;
}

function fb_put($url, $data) {
    $ctx = stream_context_create(['http' => [
        'method'  => 'PUT',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode($data)
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function send_push($FB, $mobile, $type, $title, $msg, $ts, &$queued) {
    $sub = fb_get("$FB/push_subs/$mobile.json");
    if ($sub && !empty($sub['granted'])) {
        fb_put("$FB/push_queue/{$mobile}_{$type}_" . date('Ymd') . ".json", [
            'mobile' => $mobile,
            'title'  => $title,
            'body'   => $msg,
            'ts'     => $ts,
            'read'   => false
        ]);
        $queued++;
    }
}

function queue_wa($FB, $mobile, $name, $msg, $type, $today, $ts, &$queued) {
    // WA queuing disabled - no-op until Meta API ready
    return;
}

$users    = fb_get("$FB/users.json") ?: [];
$beat_day = fb_get("$FB/beat_log/$today.json") ?: [];
$tp_all   = fb_get("$FB/tp/$month.json") ?: [];

// MORNING
if ($type === 'morning') {
    foreach ($users as $mobile => $user) {
        $role = $user['role'] ?? '';
        if (!in_array($role, ['mbe','asm','rsm','zsm'])) continue;
        $name     = $user['name'] ?? 'Team';
        $beat_log = isset($beat_day[$mobile]) ? $beat_day[$mobile] : null;
        $tp_user  = isset($tp_all[$mobile]) ? $tp_all[$mobile] : [];
        $today_tp = isset($tp_user[$today]) ? $tp_user[$today] : null;

        if ($role === 'mbe') {
            $selected = ($beat_log && isset($beat_log['selectedDocs'])) ? $beat_log['selectedDocs'] : [];
            if (empty($selected)) {
                $beat = ($today_tp && isset($today_tp['area'])) ? $today_tp['area'] : 'Field';
                $msg  = "Namaskar $name ji! Aaj ka beat ($beat) shuru karne se pehle apne doctors select karo. Guru: https://guru.aldancare.com";
                send_push($FB, $mobile, 'morning_beat', 'Beat Shuru Karo', $msg, $ts, $queued);
            }
            $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
            if (!$quiz_done) {
                $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
                $st_txt = $streak > 0 ? " Streak {$streak} din bachao!" : "";
                $msg    = "Namaskar $name ji! Aaj ka quiz baaki hai - 5 questions, 2 min.$st_txt https://guru.aldancare.com";
                send_push($FB, $mobile, 'morning_quiz', 'Quiz Baaki Hai!', $msg, $ts, $queued);
            }
        }
    }
}

// EVENING
if ($type === 'evening') {
    $mbe_status = [];
    foreach ($users as $mobile => $user) {
        if (($user['role'] ?? '') !== 'mbe') continue;
        $beat_log = isset($beat_day[$mobile]) ? $beat_day[$mobile] : null;
        $selected = ($beat_log && isset($beat_log['selectedDocs'])) ? count($beat_log['selectedDocs']) : 0;
        $visits   = ($beat_log && isset($beat_log['visits'])) ? count($beat_log['visits']) : 0;
        $mbe_status[$mobile] = [
            'name'     => $user['name'] ?? '',
            'asm'      => isset($user['asm']) ? $user['asm'] : '',
            'selected' => $selected,
            'visited'  => $visits,
        ];
    }

    foreach ($users as $mobile => $user) {
        $role = $user['role'] ?? '';
        $name = $user['name'] ?? 'Team';

        if ($role === 'mbe') {
            $beat_log = isset($beat_day[$mobile]) ? $beat_day[$mobile] : null;
            $selected = ($beat_log && isset($beat_log['selectedDocs'])) ? $beat_log['selectedDocs'] : [];
            $visits   = ($beat_log && isset($beat_log['visits'])) ? $beat_log['visits'] : [];
            if (!empty($selected)) {
                $pending = count($selected) - count($visits);
                if ($pending > 0) {
                    $msg = "Sham ho gayi $name ji! Aaj ke $pending doctors ka visit update baaki hai. https://guru.aldancare.com";
                    send_push($FB, $mobile, 'evening_visit', 'Visit Update Karo', $msg, $ts, $queued);
                }
            }

        } elseif (in_array($role, ['asm','rsm','zsm'])) {
            $my_mbes = [];
            foreach ($mbe_status as $mbeMob => $m) {
                if ($role === 'asm' && $m['asm'] !== $mobile) continue;
                $my_mbes[$mbeMob] = $m;
            }
            if (empty($my_mbes)) continue;

            $total_mbes = count($my_mbes);
            $active = 0; $total_sel = 0; $total_vis = 0; $pending_names = [];
            foreach ($my_mbes as $m) {
                if ($m['selected'] > 0) $active++;
                $total_sel += $m['selected'];
                $total_vis += $m['visited'];
                if ($m['selected'] > 0 && $m['visited'] < $m['selected']) {
                    $parts = explode(' ', $m['name']);
                    $pending_names[] = $parts[0];
                }
            }
            $pct = $total_sel > 0 ? round($total_vis / $total_sel * 100) : 0;
            $msg  = "Team: $total_vis/$total_sel doctors ($pct%). Active: $active/$total_mbes.";
            if (!empty($pending_names)) $msg .= " Pending: " . implode(', ', $pending_names);
            send_push($FB, $mobile, 'evening_summary', 'Team Coverage Summary', $msg, $ts, $queued);
        }
    }
}

// QUIZ REMINDER
if ($type === 'quiz') {
    foreach ($users as $mobile => $user) {
        if (($user['role'] ?? '') !== 'mbe') continue;
        $name      = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
            $st_txt = $streak > 0 ? " Streak {$streak} toot jayega!" : "";
            $msg    = "$name ji, aaj ka quiz abhi bhi baaki hai! 5 min.$st_txt https://guru.aldancare.com";
            send_push($FB, $mobile, 'quiz_reminder', 'Quiz Baaki Hai!', $msg, $ts, $queued);
        }
    }
}

// STREAK ALERT
if ($type === 'streak') {
    foreach ($users as $mobile => $user) {
        if (($user['role'] ?? '') !== 'mbe') continue;
        $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
        if ($streak < 1) continue;
        $name      = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $msg = "WARNING $name ji! Aapka $streak din ka streak aaj khatam ho jayega. Abhi quiz karo! https://guru.aldancare.com";
            send_push($FB, $mobile, 'streak_alert', 'Streak Khatam Hone Wala!', $msg, $ts, $queued);
        }
    }
}

echo json_encode([
    'status'     => 'ok',
    'type'       => $type,
    'date'       => $today,
    'queued'     => $queued,
    'wa_enabled' => $WA_ENABLED,
    'ts'         => $ts
]);
