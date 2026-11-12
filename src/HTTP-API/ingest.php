<?php
// Receives data from ESP32-Paxcounter (src/paxhttp.cpp) and stores it in
// MySQL. POST, header X-Api-Key: <API_KEY>, Content-Type: application/json
//
// Counts:  {"device":"paxcounter_1a2b3c4d","boot":123456,
//           "records":[{"seq":1,"age":12,"pax":5,"wifi":3,"ble":2}, ...]}
// Devices: {"device":"...","boot":123456,"batch":7,"dropped":0,
//           "devices":[{"mac":"aa:bb:cc:dd:ee:ff","t":"w","n":12,
//                       "first":55,"last":3}, ...]}
//          BLE entries may carry "a": address kind p/s/r/n (see bleaddr.h)
// Networks: {"device":"...","boot":123456,"dropped_nets":0,
//            "networks":[{"bssid":"aa:bb:..","ssid":"<hex>","ch":6,
//                         "rssi":-71,"max":-65,"n":5400,"last":2}, ...]}
//
// "age"/"first"/"last" = seconds before upload (device has no clock).

declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function int_in($v, int $min, int $max): int {
    if (!is_int($v) || $v < $min || $v > $max) {
        fail(400, 'invalid number');
    }
    return $v;
}

// SSIDs are raw bytes; usually UTF-8, otherwise assume Latin-1
function ssid_text(string $raw): string {
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
    return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $raw), 0, 32);
}

function uint($v, int $max): int {
    if (!is_int($v) || $v < 0 || $v > $max) {
        fail(400, 'invalid number');
    }
    return $v;
}

const MAX_AGE = 30 * 86400;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST only');
}
if (!hash_equals(API_KEY, $_SERVER['HTTP_X_API_KEY'] ?? '')) {
    fail(401, 'invalid api key');
}

$body = file_get_contents('php://input', false, null, 0, 65536);
$data = json_decode((string)$body, true);
if (!is_array($data) || !isset($data['device'], $data['boot'])) {
    fail(400, 'invalid json');
}

$device = $data['device'];
if (!is_string($device) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $device)) {
    fail(400, 'invalid device');
}
$boot = uint($data['boot'], 0xFFFFFFFF);
$records = $data['records'] ?? [];
$devices = $data['devices'] ?? [];
$networks = $data['networks'] ?? [];
if (!is_array($records) || !is_array($devices) || !is_array($networks)) {
    fail(400, 'invalid json');
}
if (count($records) > 200 || count($devices) > 200 || count($networks) > 100) {
    fail(413, 'too many entries');
}

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $result = ['ok' => true];
    $pdo->beginTransaction();

    if ($records) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO pax_counts
               (device, boot_id, seq, measured_at, received_at, pax, wifi, ble)
             VALUES (?, ?, ?, UTC_TIMESTAMP() - INTERVAL ? SECOND,
                     UTC_TIMESTAMP(), ?, ?, ?)'
        );
        $n = 0;
        foreach ($records as $r) {
            if (!is_array($r)) {
                fail(400, 'invalid record');
            }
            $stmt->execute([
                $device,
                $boot,
                uint($r['seq'] ?? null, 0xFFFFFFFF),
                uint($r['age'] ?? null, MAX_AGE),
                uint($r['pax'] ?? null, 1000000),
                uint($r['wifi'] ?? null, 1000000),
                uint($r['ble'] ?? null, 1000000),
            ]);
            $n += $stmt->rowCount();
        }
        $result['inserted'] = $n;
    }

    if ($devices) {
        $batch = uint($data['batch'] ?? null, 0xFFFFFFFF);
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO pax_devices
               (device, boot_id, batch, mac, type, random_mac, addr_kind,
                packets, first_seen, last_seen, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                     UTC_TIMESTAMP() - INTERVAL ? SECOND,
                     UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP())'
        );
        // hourly rollup, only for rows that were actually new (retries!)
        $roll = $pdo->prepare(
            'INSERT INTO pax_mac_hours
               (device, hour, mac, type, random_mac, addr_kind, batches, packets)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE batches = batches + 1,
                                     packets = packets + VALUES(packets),
                                     random_mac = IF(VALUES(addr_kind) IS NULL,
                                                     random_mac, VALUES(random_mac)),
                                     addr_kind = COALESCE(VALUES(addr_kind), addr_kind)'
        );
        $now = time();
        $n = 0;
        foreach ($devices as $d) {
            if (!is_array($d)) {
                fail(400, 'invalid device entry');
            }
            $mac = $d['mac'] ?? null;
            if (!is_string($mac)
                || !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                fail(400, 'invalid mac');
            }
            $type = ['w' => 'wifi', 'b' => 'ble'][$d['t'] ?? ''] ?? null;
            if ($type === null) {
                fail(400, 'invalid type');
            }
            // BLE: kind reported by the firmware decides; WiFi (and old
            // firmware): locally administered bit of the MAC
            $kind = $d['a'] ?? null;
            if ($kind !== null && !in_array($kind, ['p', 's', 'r', 'n'], true)) {
                fail(400, 'invalid address kind');
            }
            if ($type !== 'ble') {
                $kind = null;
            }
            $random = $kind !== null
                ? (int)in_array($kind, ['r', 'n'], true)
                : ((hexdec(substr($mac, 0, 2)) & 0x02) ? 1 : 0);
            $packets = uint($d['n'] ?? null, 0xFFFFFFFF);
            $first = uint($d['first'] ?? null, MAX_AGE);
            $stmt->execute([
                $device,
                $boot,
                $batch,
                $mac,
                $type,
                $random,
                $kind,
                $packets,
                $first,
                uint($d['last'] ?? null, MAX_AGE),
            ]);
            if ($stmt->rowCount() === 1) {
                $roll->execute([$device, intdiv($now - $first, 3600), $mac,
                                $type, $random, $kind, $packets]);
                $n++;
            }
        }
        $result['devices'] = $n;
    }

    if ($networks) {
        $stmt = $pdo->prepare(
            'INSERT INTO pax_networks
               (device, bssid, ssid, hidden, channel, rssi_last, rssi_max,
                beacons, first_seen, last_seen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                     UTC_TIMESTAMP() - INTERVAL ? SECOND,
                     UTC_TIMESTAMP() - INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
               ssid      = IF(VALUES(hidden), ssid, VALUES(ssid)),
               hidden    = hidden AND VALUES(hidden),
               channel   = VALUES(channel),
               rssi_last = VALUES(rssi_last),
               rssi_max  = VALUES(rssi_max),
               beacons   = beacons + VALUES(beacons),
               last_seen = GREATEST(last_seen, VALUES(last_seen))'
        );
        foreach ($networks as $w) {
            if (!is_array($w)) {
                fail(400, 'invalid network entry');
            }
            $bssid = $w['bssid'] ?? null;
            if (!is_string($bssid)
                || !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $bssid)) {
                fail(400, 'invalid bssid');
            }
            $hex = $w['ssid'] ?? null;
            if (!is_string($hex) || !preg_match('/^([0-9a-f]{2}){0,32}$/', $hex)) {
                fail(400, 'invalid ssid');
            }
            $ssid = ssid_text(hex2bin($hex));
            $last = uint($w['last'] ?? null, MAX_AGE);
            $stmt->execute([
                $device,
                $bssid,
                $ssid,
                $ssid === '' ? 1 : 0,
                int_in($w['ch'] ?? null, 0, 14),
                int_in($w['rssi'] ?? null, -128, 0),
                int_in($w['max'] ?? null, -128, 0),
                uint($w['n'] ?? null, 0xFFFFFFFF),
                $last,
                $last,
            ]);
        }
        $result['networks'] = count($networks);
    }

    $pdo->commit();

    // occasional cleanup of old device rows
    $days = defined('DEVICE_RETENTION_DAYS') ? (int)DEVICE_RETENTION_DAYS : 0;
    if ($days > 0 && random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM pax_devices
                       WHERE first_seen < UTC_TIMESTAMP() - INTERVAL ? DAY
                       LIMIT 50000')->execute([$days]);
        $pdo->prepare('DELETE FROM pax_mac_hours WHERE hour < ?
                       LIMIT 50000')->execute([intdiv(time(), 3600) - $days * 24]);
    }
} catch (PDOException $e) {
    error_log('paxcounter ingest: ' . $e->getMessage());
    fail(500, 'database error');
}

echo json_encode($result);
