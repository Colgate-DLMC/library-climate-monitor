<?php
// Read-only JSON/CSV API used by the dashboard.
//
//   api.php?action=devices                         latest values + status per device
//   api.php?action=readings&hours=24[&device=id]   bucketed series + min/avg/max stats
//   api.php?action=csv&hours=720[&device=id]       raw readings as a CSV download
//
// There is no login here. If the dashboard should not be public, protect this
// directory with Basic Auth (see docs/DEPLOY.md) - and exempt ingest.php.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    fail(405, 'GET only');
}

$action = (string)($_GET['action'] ?? 'devices');
$pdo    = db();
$now    = time();

function clamp_hours(): int
{
    $h = (int)($_GET['hours'] ?? 24);
    return max(1, min(8760, $h));
}

function device_filter(): ?string
{
    $d = (string)($_GET['device'] ?? '');
    if ($d === '') {
        return null;
    }
    return valid_device_id($d) ? $d : null;
}

/** Smallest "nice" bucket (seconds) that keeps a range under ~400 points. */
function bucket_for(int $rangeSeconds): int
{
    foreach ([300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400] as $b) {
        if ($rangeSeconds / $b <= 400) {
            return $b;
        }
    }
    return 86400;
}

switch ($action) {
    case 'devices':
        $sql = 'SELECT d.id, d.name, d.location, d.sort_order, d.report_interval_s, d.expected_upload_s,
                       d.last_seen_at, d.last_rssi, d.fw_version,
                       r.ts, r.temp_c, r.rh, r.pressure_hpa, r.batt_v
                  FROM devices d
             LEFT JOIN (SELECT r1.* FROM readings r1
                          JOIN (SELECT device_id, MAX(ts) AS mts FROM readings GROUP BY device_id) m
                            ON m.device_id = r1.device_id AND m.mts = r1.ts) r
                    ON r.device_id = d.id
                 WHERE d.active = 1
              ORDER BY d.sort_order, d.name';
        $out = [];
        foreach ($pdo->query($sql) as $row) {
            if (isset($out[$row['id']])) {
                continue;   // two readings with an identical newest timestamp: keep the first
            }
            $lastSeen = $row['last_seen_at'] !== null ? (int)$row['last_seen_at'] : null;
            $expect   = max(60, (int)$row['expected_upload_s']);
            $silence  = $lastSeen === null ? PHP_INT_MAX : $now - $lastSeen;
            // ok: within 2.5 expected uploads. late: within 6. offline: beyond that or never seen.
            $status   = $silence <= 2.5 * $expect ? 'ok' : ($silence <= 6 * $expect ? 'late' : 'offline');
            $out[$row['id']] = [
                'id'          => $row['id'],
                'name'        => $row['name'],
                'location'    => $row['location'],
                'interval_s'  => (int)$row['report_interval_s'],
                'last_seen'   => $lastSeen,
                'status'      => $status,
                'rssi'        => $row['last_rssi'] !== null ? (int)$row['last_rssi'] : null,
                'fw'          => $row['fw_version'],
                'ts'          => $row['ts'] !== null ? (int)$row['ts'] : null,
                'temp_c'      => $row['temp_c'] !== null ? round((float)$row['temp_c'], 2) : null,
                'rh'          => $row['rh'] !== null ? round((float)$row['rh'], 1) : null,
                'pressure_hpa'=> $row['pressure_hpa'] !== null ? round((float)$row['pressure_hpa'], 1) : null,
                'batt_v'      => $row['batt_v'] !== null ? round((float)$row['batt_v'], 2) : null,
            ];
        }
        json_out(['ok' => true, 'server_time' => $now, 'devices' => array_values($out)]);

    case 'readings':
        $hours  = clamp_hours();
        $from   = $now - $hours * 3600;
        $bucket = bucket_for($hours * 3600);
        $device = device_filter();

        $sql  = 'SELECT device_id, ts, temp_c, rh, batt_v FROM readings WHERE ts >= ?';
        $args = [$from];
        if ($device !== null) {
            $sql .= ' AND device_id = ?';
            $args[] = $device;
        }
        $sql .= ' ORDER BY device_id, ts LIMIT 500000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        // Average into fixed, time-aligned buckets so every device lands on the same x positions.
        $acc = [];
        foreach ($stmt as $r) {
            $b = intdiv((int)$r['ts'], $bucket) * $bucket;
            $a = &$acc[$r['device_id']][$b];
            $a ??= ['n' => 0, 'tn' => 0, 't' => 0.0, 'rn' => 0, 'r' => 0.0, 'bn' => 0, 'b' => 0.0];
            if ($r['temp_c'] !== null) { $a['t'] += (float)$r['temp_c']; $a['tn']++; }
            if ($r['rh']     !== null) { $a['r'] += (float)$r['rh'];     $a['rn']++; }
            if ($r['batt_v'] !== null) { $a['b'] += (float)$r['batt_v']; $a['bn']++; }
            unset($a);
        }
        $series = [];
        foreach ($acc as $id => $buckets) {
            ksort($buckets);
            $s = ['t' => [], 'temp_c' => [], 'rh' => [], 'batt_v' => []];
            foreach ($buckets as $b => $a) {
                $s['t'][]      = $b;
                $s['temp_c'][] = $a['tn'] ? round($a['t'] / $a['tn'], 2) : null;
                $s['rh'][]     = $a['rn'] ? round($a['r'] / $a['rn'], 1) : null;
                $s['batt_v'][] = $a['bn'] ? round($a['b'] / $a['bn'], 2) : null;
            }
            $series[$id] = $s;
        }

        // Min / avg / max come from the raw rows, not the bucket averages.
        $sql  = 'SELECT device_id, COUNT(*) AS n, MIN(temp_c) AS tmin, MAX(temp_c) AS tmax, AVG(temp_c) AS tavg,
                        MIN(rh) AS rmin, MAX(rh) AS rmax, AVG(rh) AS ravg
                   FROM readings WHERE ts >= ?' . ($device !== null ? ' AND device_id = ?' : '') . ' GROUP BY device_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $stats = [];
        foreach ($stmt as $r) {
            $f = static fn($v, int $p) => $v === null ? null : round((float)$v, $p);
            $stats[$r['device_id']] = [
                'n' => (int)$r['n'],
                'temp_c' => ['min' => $f($r['tmin'], 2), 'avg' => $f($r['tavg'], 2), 'max' => $f($r['tmax'], 2)],
                'rh'     => ['min' => $f($r['rmin'], 1), 'avg' => $f($r['ravg'], 1), 'max' => $f($r['rmax'], 1)],
            ];
        }
        json_out([
            'ok' => true, 'server_time' => $now, 'from' => $from, 'to' => $now,
            'bucket_s' => $bucket, 'series' => (object)$series, 'stats' => (object)$stats,
        ]);

    case 'csv':
        $hours  = clamp_hours();
        $from   = $now - $hours * 3600;
        $device = device_filter();
        $sql  = 'SELECT r.device_id, d.name, r.ts, r.temp_c, r.rh, r.pressure_hpa, r.batt_v
                   FROM readings r JOIN devices d ON d.id = r.device_id WHERE r.ts >= ?';
        $args = [$from];
        if ($device !== null) {
            $sql .= ' AND r.device_id = ?';
            $args[] = $device;
        }
        $sql .= ' ORDER BY r.ts, r.device_id LIMIT 1000000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="library-climate-' . gmdate('Ymd-His', $now) . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['device_id', 'name', 'time_utc', 'temp_c', 'temp_f', 'rh_percent', 'pressure_hpa', 'batt_v'], ',', '"', '');
        foreach ($stmt as $r) {
            $tc = $r['temp_c'];
            fputcsv($out, [
                $r['device_id'],
                // Neutralise spreadsheet formula injection in the free-text name.
                preg_match('/^[=+\-@\t\r]/', (string)$r['name']) ? "'" . $r['name'] : $r['name'],
                gmdate('Y-m-d\TH:i:s\Z', (int)$r['ts']),
                $tc, $tc === null ? '' : round($tc * 9 / 5 + 32, 2),
                $r['rh'], $r['pressure_hpa'], $r['batt_v'],
            ], ',', '"', '');
        }
        fclose($out);
        exit;

    default:
        fail(400, 'unknown action');
}
