<?php
// Aldan Guru Cron v2 - Phase 1
// Cron Jobs (UTC times - IST = UTC+5:30):
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

function queue_msg($FB, $mobile, $name, $msg, $type, $today, $ts, &$queued) {
    $key = $mobile . '_' . $type . '_' . date('Ymd');
    fb_put("$FB/alerts/queue/$key.json", [
        'mobile' => $mobile, 'name' => $name, 'msg' => $msg,
        'type'   => $type,   'date' => $today, 'status' => 'pending', 'ts' => $ts
    ]);
    $sub = fb_get("$FB/push_subs/$mobile.json");
    if ($sub && !empty($sub['granted'])) {
        $title = (strpos($type,'quiz') !== false || strpos($type,'streak') !== false)
            ? 'Quiz Baaki Hai!' : (strpos($type,'morning') !== false ? 'Beat Shuru Karo' : 'Visit Update Karo');
        fb_put("$FB/push_queue/{$mobile}_{$type}_" . date('Ymd') . ".json", [
            'mobile' => $mobile, 'title' => $title, 'body' => $msg, 'ts' => $ts, 'read' => false
        ]);
    }
    $queued++;
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
                $msg  = "Namaskar $name ji!\n\nAaj ka beat ($beat) shuru karne se pehle apne doctors select karo.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                queue_msg($FB, $mobile, $name, $msg, 'morning_beat', $today, $ts, $queued);
            }
            $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
            if (!$quiz_done) {
                $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
                $st_txt = $streak > 0 ? " Streak {$streak} din bachao!" : "";
                $msg    = "Namaskar $name ji!\n\nAaj ka quiz abhi baaki hai - 5 questions, 2 minutes.$st_txt\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                queue_msg($FB, $mobile, $name, $msg, 'morning_quiz', $today, $ts, $queued);
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
            'pct'      => $selected > 0 ? round($visits / $selected * 100) : 0,
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
                    $msg = "Sham ho gayi $name ji!\n\nAaj ke $pending doctors ka visit update karna baaki hai.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                    queue_msg($FB, $mobile, $name, $msg, 'evening_visit', $today, $ts, $queued);
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
            $active = 0; $full_done = 0; $total_sel = 0; $total_vis = 0; $pending_names = [];
            foreach ($my_mbes as $m) {
                if ($m['selected'] > 0) $active++;
                if ($m['selected'] > 0 && $m['visited'] >= $m['selected']) $full_done++;
                $total_sel += $m['selected'];
                $total_vis += $m['visited'];
                if ($m['selected'] > 0 && $m['visited'] < $m['selected']) {
                    $parts = explode(' ', $m['name']);
                    $pending_names[] = $parts[0];
                }
            }
            $pct = $total_sel > 0 ? round($total_vis / $total_sel * 100) : 0;
            $msg  = "Team Summary - $name ji\n\n";
            $msg .= "Aaj coverage: $total_vis/$total_sel doctors ($pct%)\n";
            $msg .= "Active MBEs: $active/$total_mbes\n";
            $msg .= "Beat complete: $full_done/$total_mbes\n";
            if (!empty($pending_names)) $msg .= "\nPending: " . implode(', ', $pending_names) . "\n";
            $msg .= "\nhttps://guru.aldancare.com\n\n_Aldan Guru_";
            queue_msg($FB, $mobile, $name, $msg, 'evening_summary', $today, $ts, $queued);
        }
    }
}

// QUIZ REMINDER (6:30 PM IST)
if ($type === 'quiz') {
    foreach ($users as $mobile => $user) {
        if (($user['role'] ?? '') !== 'mbe') continue;
        $name      = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
            $st_txt = $streak > 0 ? "\n\nStreak {$streak} din aaj toot jayega!" : "";
            $msg    = "$name ji, aaj ka quiz abhi bhi baaki hai!\n\n5 questions, 2 minutes. Abhi karo.$st_txt\n\nhttps://guru.aldancare.com\n\n_Aldan Guru_";
            queue_msg($FB, $mobile, $name, $msg, 'quiz_reminder', $today, $ts, $queued);
        }
    }
}

// STREAK ALERT (7 PM IST)
if ($type === 'streak') {
    foreach ($users as $mobile => $user) {
        if (($user['role'] ?? '') !== 'mbe') continue;
        $streak = isset($user['streak']) ? (int)$user['streak'] : 0;
        if ($streak < 1) continue;
        $name      = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $msg = "WARNING $name ji!\n\nAapka $streak din ka streak aaj raat khatam ho jayega agar abhi quiz nahi kiya!\n\nSirf 2 minute - abhi karo!\nhttps://guru.aldancare.com\n\n_Aldan Guru_";
            queue_msg($FB, $mobile, $name, $msg, 'streak_alert', $today, $ts, $queued);
        }
    }
}

echo json_encode(['status' => 'ok', 'type' => $type, 'date' => $today, 'queued' => $queued, 'ts' => $ts]);
