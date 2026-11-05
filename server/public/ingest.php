<?php
// POST endpoint the ESP32 nodes call.
//
//   POST /climate/ingest.php
//   X-Api-Key: <device key>
//   {"device_id":"stacks-2f","fw":"0.1.0","rssi":-61,"boot_id":123456,
//    "upload_interval_s":600,
//    "readings":[{"seq":12,"age_s":0,"temp_c":21.43,"rh":38.2,"pressure_hpa":1001.2,"batt_v":3.91}]}
//
// "age_s" is how many seconds ago the reading was taken, so devices without a
// real-time clock still get correct timestamps: ts = server_now - age_s.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'POST only');
}
if ((cfg()['require_https'] ?? true) && !is_https()) {
    fail(400, 'HTTPS required');
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) {
    fail(413, 'payload too large');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail(400, 'invalid JSON');
}

// ---- authenticate ---------------------------------------------------------
$deviceId = (string)($body['device_id'] ?? '');
$apiKey   = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!valid_device_id($deviceId) || $apiKey === '') {
    fail(401, 'unauthorized');
}
$pdo  = db();
$stmt = $pdo->prepare('SELECT id, api_key_hash, active, report_interval_s FROM devices WHERE id = ?');
$stmt->execute([$deviceId]);
$device = $stmt->fetch();

$expected = $device['api_key_hash'] ?? str_repeat('0', 64);   // compare anyway: no timing difference for unknown ids
$keyOk    = hash_equals($expected, hash('sha256', $apiKey));
if (!$device || !$keyOk || !(int)$device['active']) {
    fail(401, 'unauthorized');
}

// ---- validate + store readings -------------------------------------------
$readings = $body['readings'] ?? null;
if (!is_array($readings) || count($readings) > 100) {
    fail(400, 'readings must be an array of at most 100 items');
}

$lim = cfg()['limits'] ?? [];
$now = time();

$insert = $pdo->prepare(
    insert_ignore() . ' INTO readings (device_id, ts, boot_id, seq, temp_c, rh, pressure_hpa, batt_v) VALUES (?,?,?,?,?,?,?,?)'
);

$bootId = isset($body['boot_id']) && is_int($body['boot_id']) && $body['boot_id'] >= 0 ? $body['boot_id'] : null;
$stored = $duplicates = $rejected = 0;

$pdo->beginTransaction();
foreach ($readings as $r) {
    if (!is_array($r)) {
        $rejected++;
        continue;
    }
    $age  = num_in_range($r['age_s'] ?? 0, 0, 90 * 86400);
    $temp = num_in_range($r['temp_c'] ?? null, $lim['temp_c_min'] ?? -40, $lim['temp_c_max'] ?? 85);
    $rh   = num_in_range($r['rh'] ?? null, $lim['rh_min'] ?? 0, $lim['rh_max'] ?? 100);
    $pres = num_in_range($r['pressure_hpa'] ?? null, 300, 1100);
    $batt = num_in_range($r['batt_v'] ?? null, $lim['batt_v_min'] ?? 0, $lim['batt_v_max'] ?? 6);

    // A reading must carry at least one usable measurement and a valid age. A value that is
    // present but out of range (a sensor glitch) rejects the whole reading rather than being
    // silently dropped while its neighbours are kept.
    $glitch = ($temp === null && ($r['temp_c'] ?? null) !== null)
           || ($rh === null && ($r['rh'] ?? null) !== null)
           || ($pres === null && ($r['pressure_hpa'] ?? null) !== null)
           || ($batt === null && ($r['batt_v'] ?? null) !== null);
    if ($age === null || $glitch || ($temp === null && $rh === null)) {
        $rejected++;
        continue;
    }
    $seq = isset($r['seq']) && is_int($r['seq']) && $r['seq'] >= 0 ? $r['seq'] : null;

    $insert->execute([$deviceId, $now - (int)round($age), $bootId, $seq, $temp, $rh, $pres, $batt]);
    if ($insert->rowCount() > 0) {
        $stored++;
    } else {
        $duplicates++;
    }
}

$rssi     = isset($body['rssi']) && is_int($body['rssi']) && $body['rssi'] < 0 && $body['rssi'] > -128 ? $body['rssi'] : null;
$fw       = isset($body['fw']) ? substr(preg_replace('/[^0-9A-Za-z._-]/', '', (string)$body['fw']), 0, 16) : null;
$upload   = num_in_range($body['upload_interval_s'] ?? null, 30, 7 * 86400);
$upload   = $upload !== null ? (int)$upload : (int)$device['report_interval_s'];

$pdo->prepare('UPDATE devices SET last_seen_at = ?, last_rssi = ?, fw_version = ?, expected_upload_s = ? WHERE id = ?')
    ->execute([$now, $rssi, $fw, $upload, $deviceId]);
$pdo->commit();

json_out([
    'ok'         => true,
    'stored'     => $stored,
    'duplicates' => $duplicates,
    'rejected'   => $rejected,
    'server_time'=> $now,
    // Remote config: change devices.report_interval_s in the database and the node picks it up on its next upload.
    'interval_s' => (int)$device['report_interval_s'],
]);
