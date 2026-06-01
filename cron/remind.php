<?php
// Hostinger cron job - runs at 8 AM and 6 PM daily IST
// Setup in cPanel > Advanced > Cron Jobs:
//   0 2 * * *  curl -s "https://guru.aldancare.com/cron/remind.php?type=morning"
//   0 12 * * * curl -s "https://guru.aldancare.com/cron/remind.php?type=evening"
// (Note: Hostinger server time is UTC, IST = UTC+5:30, so 8AM IST = 2:30AM UTC ~ 2AM, 6PM IST = 12:30PM UTC ~ 12PM)

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'morning';
$FB_BASE = 'https://fintech-nd-default-rtdb.asia-southeast1.firebasedatabase.app/guru/v1';
$today = date('d-m-Y');
$month = date('Y-m');

// Fetch all users
$users_raw = file_get_contents("$FB_BASE/users.json");
$users = json_decode($users_raw, true) ?: [];

// Fetch today's beat logs
$beat_raw = file_get_contents("$FB_BASE/beat_log/$today.json");
$beat_logs = json_decode($beat_raw, true) ?: [];

// Fetch TP data for this month
$tp_raw = file_get_contents("$FB_BASE/tp/$month.json");
$tp_all = json_decode($tp_raw, true) ?: [];

$queued = 0;
$ts = date('c');

foreach ($users as $mobile => $user) {
    // Only send to MBEs
    if (!in_array($user['role'] ?? '', ['mbe'])) continue;

    $name = $user['name'] ?? 'Team';
    $beat_log = $beat_logs[$mobile] ?? null;
    $msg = null;

    // Check today's TP (optional - send reminder even if no TP)
    $tp_user = $tp_all[$mobile] ?? [];
    $today_tp = $tp_user[$today] ?? null;
    $is_field = !$today_tp || ($today_tp['activity'] ?? '') === 'Field';

    if ($type === 'morning') {
        $selected = $beat_log ? ($beat_log['selectedDocs'] ?? []) : [];
        if (empty($selected)) {
            $beat = $today_tp ? ($today_tp['area'] ?? 'Field') : 'Field';
            $msg = "Namaskar $name ji! \uD83C\uDF05\n\nAaj ka beat ($beat) shuru karne se pehle apne doctors select karo.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
        }
    } elseif ($type === 'evening') {
        $selected = $beat_log ? toArr($beat_log['selectedDocs'] ?? []) : [];
        $visits = $beat_log ? ($beat_log['visits'] ?? []) : [];
        if (!empty($selected)) {
            $pending = count($selected) - count($visits);
            if ($pending > 0) {
                $msg = "Sham ho gayi $name ji! \uD83C\uDF07\n\nAaj ke $pending doctors ka visit update karna baaki hai.\n\nGuru app: https://guru.aldancare.com\n\n_Aldan Guru_";
            }
        }
    }

    if ($msg) {
        $queue_data = json_encode([
            'mobile' => $mobile,
            'name' => $name,
            'msg' => $msg,
            'type' => $type,
            'date' => $today,
            'status' => 'pending',
            'ts' => $ts
        ]);

        $key = $mobile . '_' . $type . '_' . date('Ymd');
        $put_url = "$FB_BASE/alerts/queue/$key.json";

        $ctx = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => 'Content-Type: application/json',
                'content' => $queue_data
            ]
        ]);
        file_get_contents($put_url, false, $ctx);

        // Push notification queue
        $sub_raw = file_get_contents("$FB_BASE/push_subs/$mobile.json");
        $sub = json_decode($sub_raw, true);
        if ($sub && ($sub['granted'] ?? false)) {
            $push_data = json_encode([
                'mobile' => $mobile,
                'title' => $type === 'morning' ? 'Aaj ka Beat Shuru Karo' : 'Visit Log Update Karo',
                'body' => $msg,
                'ts' => $ts,
                'read' => false
            ]);
            $push_url = "$FB_BASE/push_queue/{$mobile}_{$type}_" . date('Ymd') . ".json";
            $push_ctx = stream_context_create([
                'http' => ['method'=>'PUT','header'=>'Content-Type: application/json','content'=>$push_data]
            ]);
            file_get_contents($push_url, false, $push_ctx);
        }
        $queued++;
    }
}

function toArr($val) {
    if (is_array($val)) return array_values($val);
    return [];
}

echo json_encode([
    'status' => 'ok',
    'type' => $type,
    'date' => $today,
    'queued' => $queued,
    'ts' => $ts
]);
