<?php
// Aldan Guru - Cron Reminder v2
// Hostinger cPanel > Advanced > Cron Jobs:
//   0 2 * * *   curl -s "https://guru.aldancare.com/cron/remind.php?type=morning"
//   0 12 * * *  curl -s "https://guru.aldancare.com/cron/remind.php?type=evening"
//   0 13 * * *  curl -s "https://guru.aldancare.com/cron/remind.php?type=quiz"
//   30 13 * * * curl -s "https://guru.aldancare.com/cron/remind.php?type=streak"
// (UTC times: 2AM=7:30IST morning, 12PM=5:30IST evening, 1PM=6:30IST quiz, 1:30PM=7IST streak)

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'morning';
$FB   = 'https://fintech-nd-default-rtdb.asia-southeast1.firebasedatabase.app/guru/v1';
$today     = date('d-m-Y');
$todayISO  = date('Y-m-d');
$month     = date('Y-m');
$ts        = date('c');
$queued    = 0;

// Helpers
function fb_get($url) {
    $r = file_get_contents($url);
    return $r ? json_decode($r, true) : [];
}
function fb_put($url, $data) {
    $ctx = stream_context_create(['http'=>['method'=>'PUT','header'=>'Content-Type: application/json','content'=>json_encode($data)]]);
    return file_get_contents($url, false, $ctx);
}
function queue_msg($FB, $mobile, $name, $msg, $type, $today, $ts, &$queued) {
    $key = $mobile . '_' . $type . '_' . date('Ymd');
    fb_put("$FB/alerts/queue/$key.json", [
        'mobile'=>$mobile, 'name'=>$name, 'msg'=>$msg,
        'type'=>$type, 'date'=>$today, 'status'=>'pending', 'ts'=>$ts
    ]);
    // Push notification queue
    $sub = fb_get("$FB/push_subs/$mobile.json");
    if ($sub && ($sub['granted'] ?? false)) {
        $title = strpos($type,'quiz')!==false ? 'Quiz Baaki Hai!' :
                 strpos($type,'streak')!==false ? 'Streak Khatam Hone Wala Hai!' :
                 strpos($type,'morning')!==false ? 'Beat Shuru Karo' : 'Visit Update Karo';
        fb_put("$FB/push_queue/{$mobile}_{$type}_" . date('Ymd') . ".json", [
            'mobile'=>$mobile, 'title'=>$title, 'body'=>$msg,
            'ts'=>$ts, 'read'=>false
        ]);
    }
    $queued++;
}

// Fetch data
$users    = fb_get("$FB/users.json") ?: [];
$beat_day = fb_get("$FB/beat_log/$today.json") ?: [];
$tp_all   = fb_get("$FB/tp/$month.json") ?: [];
$quiz_all = fb_get("$FB/quiz.json?shallow=true") ?: [];

// ── MORNING: Beat selection + Quiz reminder ──────────────────────────
if ($type === 'morning') {
    foreach ($users as $mobile => $user) {
        if (!in_array($user['role']??'', ['mbe','asm','rsm','zsm'])) continue;
        $name = $user['name'] ?? 'Team';
        $isMbe = ($user['role'] === 'mbe');
        $beat_log = $beat_day[$mobile] ?? null;
        $tp_user  = $tp_all[$mobile] ?? [];
        $today_tp = $tp_user[$today] ?? null;

        if ($isMbe) {
            // Beat reminder
            $selected = $beat_log ? ($beat_log['selectedDocs'] ?? []) : [];
            if (empty($selected)) {
                $beat = $today_tp ? ($today_tp['area'] ?? 'Field') : 'Field';
                $msg = "Namaskar $name ji! \uD83C\uDF05\n\nAaj ka beat ($beat) shuru karne se pehle apne doctors select karo.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                queue_msg($FB, $mobile, $name, $msg, 'morning_beat', $today, $ts, $queued);
            }
            // Quiz reminder
            $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
            if (!$quiz_done) {
                $streak = $user['streak'] ?? 0;
                $streak_txt = $streak > 0 ? " Streak \uD83D\uDD25$streak bachao!" : "";
                $msg = "Namaskar $name ji! \uD83E\uDDE0\n\nAaj ka quiz abhi baaki hai — 5 questions, 2 minutes.$streak_txt\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                queue_msg($FB, $mobile, $name, $msg, 'morning_quiz', $today, $ts, $queued);
            }
        }
    }
}

// ── EVENING: Visit update + Manager team summary ─────────────────────
if ($type === 'evening') {
    // Build MBE status map for manager summaries
    $mbe_status = [];
    foreach ($users as $mobile => $user) {
        if (($user['role']??'') !== 'mbe') continue;
        $beat_log = $beat_day[$mobile] ?? null;
        $selected = $beat_log ? count($beat_log['selectedDocs'] ?? []) : 0;
        $visits   = $beat_log ? count($beat_log['visits'] ?? []) : 0;
        $mbe_status[$mobile] = [
            'name'     => $user['name'] ?? '',
            'hq'       => $user['hq'] ?? '',
            'asm'      => $user['asm'] ?? '',
            'selected' => $selected,
            'visited'  => $visits,
            'pct'      => $selected > 0 ? round($visits/$selected*100) : 0,
        ];
    }

    foreach ($users as $mobile => $user) {
        $name = $user['name'] ?? 'Team';
        $role = $user['role'] ?? '';

        if ($role === 'mbe') {
            // Visit pending reminder
            $beat_log = $beat_day[$mobile] ?? null;
            $selected = $beat_log ? ($beat_log['selectedDocs'] ?? []) : [];
            $visits   = $beat_log ? ($beat_log['visits'] ?? []) : [];
            if (!empty($selected)) {
                $pending = count($selected) - count($visits);
                if ($pending > 0) {
                    $msg = "Sham ho gayi $name ji! \uD83C\uDF07\n\nAaj ke $pending doctors ka visit update karna baaki hai.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
                    queue_msg($FB, $mobile, $name, $msg, 'evening_visit', $today, $ts, $queued);
                }
            }

        } elseif (in_array($role, ['asm','rsm','zsm'])) {
            // Manager team summary
            // Find MBEs under this manager
            $my_mbes = array_filter($mbe_status, function($m) use ($mobile, $role) {
                if ($role === 'asm') return ($m['asm'] === $mobile);
                return true; // RSM/ZSM see all
            });
            if (empty($my_mbes)) continue;

            $total_mbes = count($my_mbes);
            $active     = count(array_filter($my_mbes, fn($m) => $m['selected'] > 0));
            $full_done  = count(array_filter($my_mbes, fn($m) => $m['selected'] > 0 && $m['visited'] >= $m['selected']));
            $total_sel  = array_sum(array_column($my_mbes, 'selected'));
            $total_vis  = array_sum(array_column($my_mbes, 'visited'));
            $pct        = $total_sel > 0 ? round($total_vis/$total_sel*100) : 0;

            // Find laggards
            $pending_mbes = array_filter($my_mbes, fn($m) => $m['selected'] > 0 && $m['visited'] < $m['selected']);
            $pending_names = implode(', ', array_map(fn($m) => explode(' ',$m['name'])[0], $pending_mbes));

            $msg = "Team Summary - $name ji \uD83D\uDCCA\n\n";
            $msg .= "Aaj ka coverage: $total_vis/$total_sel doctors ($pct%)\n";
            $msg .= "Active MBEs: $active/$total_mbes\n";
            $msg .= "Full beat done: $full_done/$total_mbes\n";
            if ($pending_names) $msg .= "\nPending: $pending_names\n";
            $msg .= "\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";

            queue_msg($FB, $mobile, $name, $msg, 'evening_summary', $today, $ts, $queued);
        }
    }
}

// ── QUIZ: Evening quiz reminder (separate job at 6:30 PM IST) ────────
if ($type === 'quiz') {
    foreach ($users as $mobile => $user) {
        if (($user['role']??'') !== 'mbe') continue;
        $name = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $streak = $user['streak'] ?? 0;
            $streak_txt = $streak > 0 ? "\n\n\u26A0\uFE0F Streak \uD83D\uDD25$streak aaj toot jayega!" : "";
            $msg = "$name ji, aaj ka quiz abhi bhi baaki hai! \uD83E\uDDE0\n\n5 questions, 2 minutes. Abhi karo.$streak_txt\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
            queue_msg($FB, $mobile, $name, $msg, 'quiz_reminder', $today, $ts, $queued);
        }
    }
}

// ── STREAK: Late evening streak protection (7 PM IST) ────────────────
if ($type === 'streak') {
    foreach ($users as $mobile => $user) {
        if (($user['role']??'') !== 'mbe') continue;
        $streak = $user['streak'] ?? 0;
        if ($streak < 1) continue; // Only warn if they have a streak to lose
        $name = $user['name'] ?? 'Team';
        $quiz_done = fb_get("$FB/quiz/$mobile/$todayISO.json");
        if (!$quiz_done) {
            $msg = "\u26A0\uFE0F $name ji!\n\nAapka \uD83D\uDD25$streak din ka streak aaj raat khatam ho jayega agar abhi quiz nahi kiya!\n\nSirf 2 minute — abhi karo!\nhttps://guru.aldancare.com\n\n_Aldan Guru_";
            queue_msg($FB, $mobile, $name, $msg, 'streak_alert', $today, $ts, $queued);
        }
    }
}

echo json_encode(['status'=>'ok','type'=>$type,'date'=>$today,'queued'=>$queued,'ts'=>$ts]);
