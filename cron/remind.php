<?php
// Hostinger cron job - runs at 8 AM and 6 PM daily
// Add to cPanel Cron Jobs:
//   0 8 * * * curl -s https://guru.aldancare.com/cron/remind.php?type=morning
//   0 18 * * * curl -s https://guru.aldancare.com/cron/remind.php?type=evening

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

// Fetch TP data
$tp_raw = file_get_contents("$FB_BASE/tp/$month.json");
$tp_all = json_decode($tp_raw, true) ?: [];

$queued = 0;
$ts = date('c');

foreach ($users as $mobile => $user) {
    if (!in_array($user['role'] ?? '', ['mbe'])) continue;

    // Check today's TP
    $tp_user = $tp_all[$mobile] ?? [];
    $today_tp = $tp_user[$today] ?? null;
    if (!$today_tp || ($today_tp['activity'] ?? '') !== 'Field') continue;

    $beat_log = $beat_logs[$mobile] ?? null;
    $msg = null;

    if ($type === 'morning') {
        // Check if doctors selected
        $selected = $beat_log['selectedDocs'] ?? [];
        if (empty($selected)) {
            $beat = $today_tp['area'] ?? 'Field';
            $msg = "Namaskar {$user['name']} ji! Aaj ka beat ({$beat}) shuru karne se pehle apne doctors select karo. Guru app pe jaake Today tab open karo. 💊";
        }
    } elseif ($type === 'evening') {
        // Check if visits incomplete
        $selected = $beat_log['selectedDocs'] ?? [];
        $visits = $beat_log['visits'] ?? [];
        if (!empty($selected)) {
            $pending = array_diff($selected, array_keys($visits));
            $count = count($pending);
            if ($count > 0) {
                $msg = "Sham ho gayi {$user['name']} ji! Aaj ke $count doctors ka visit update karna baaki hai. Guru app pe Today tab mein visit log complete karo. ✅";
            }
        }
    }

    if ($msg) {
        // Queue WA message
        $queue_data = json_encode([
            'mobile' => $mobile,
            'name' => $user['name'],
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

        // Also send push notification if subscribed
        $sub_raw = file_get_contents("$FB_BASE/push_subs/$mobile.json");
        $sub = json_decode($sub_raw, true);
        if ($sub && ($sub['granted'] ?? false)) {
            // Queue for push (app will poll on open)
            $push_data = json_encode([
                'mobile' => $mobile,
                'title' => $type === 'morning' ? 'Aaj ka Beat Start Karo' : 'Visit Log Update Karo',
                'body' => $msg,
                'ts' => $ts,
                'read' => false
            ]);
            $push_key = $mobile . '_' . $type . '_' . date('Ymd');
            $push_url = "$FB_BASE/push_queue/$push_key.json";
            $push_ctx = stream_context_create([
                'http' => ['method'=>'PUT','header'=>'Content-Type: application/json','content'=>$push_data]
            ]);
            file_get_contents($push_url, false, $push_ctx);
        }
        $queued++;
    }
}

echo json_encode(['status'=>'ok','type'=>$type,'date'=>$today,'queued'=>$queued,'ts'=>$ts]);
