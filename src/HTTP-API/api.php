<?php
// Read-only JSON API for the dashboard (index.php). Requires a dashboard
// session. All timestamps are returned as unix epoch seconds (UTC).
//
// GET api.php?q=devices
// GET api.php?q=series&device=..&range=24h|7d|30d|90d
// GET api.php?q=heatmap&device=..        (hourly averages, last 28 days)
// GET api.php?q=macs&device=..&range=..  (top seen MACs)
// GET api.php?q=groups&device=..&range=.. (MACs grouped by presence pattern)
// GET api.php?q=networks&device=..        (nearby WiFi access points)

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

session_begin();
if (!logged_in()) {
    out(['error' => 'not logged in'], 401);
}
session_write_close(); // don't block parallel requests

// range => [seconds, bucket seconds]
const RANGES = [
    '24h' => [86400, 300],
    '7d'  => [7 * 86400, 3600],
    '30d' => [30 * 86400, 4 * 3600],
    '90d' => [90 * 86400, 86400],
];

function param_device(): string {
    $d = $_GET['device'] ?? '';
    if (!is_string($d) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $d)) {
        out(['error' => 'invalid device'], 400);
    }
    return $d;
}

function param_range(): array {
    $r = $_GET['range'] ?? '24h';
    if (!is_string($r) || !isset(RANGES[$r])) {
        out(['error' => 'invalid range'], 400);
    }
    return RANGES[$r];
}

try {
    $pdo = db();
    $now = time();

    switch ($_GET['q'] ?? '') {

    case 'devices':
        // latest record per device
        $rows = $pdo->query(
            'SELECT c.device, UNIX_TIMESTAMP(c.measured_at) AS t,
                    c.pax, c.wifi, c.ble, m.n
               FROM (SELECT device, MAX(id) AS id, COUNT(*) AS n
                       FROM pax_counts GROUP BY device) m
               JOIN pax_counts c ON c.id = m.id
              ORDER BY c.device'
        )->fetchAll();
        out(['now' => $now, 'devices' => array_map(fn($r) => [
            'device' => $r['device'],
            't'      => (int)$r['t'],
            'pax'    => (int)$r['pax'],
            'wifi'   => (int)$r['wifi'],
            'ble'    => (int)$r['ble'],
            'records'=> (int)$r['n'],
        ], $rows)]);

    case 'series':
        $device = param_device();
        [$span, $bucket] = param_range();
        // align buckets (e.g. days) to Swiss local time
        $off = (new DateTime('now', new DateTimeZone('Europe/Zurich')))->getOffset();
        $stmt = $pdo->prepare(
            'SELECT FLOOR((UNIX_TIMESTAMP(measured_at) + :o) / :b) * :b2 - :o2 AS t,
                    AVG(pax) AS pax, AVG(wifi) AS wifi, AVG(ble) AS ble,
                    MAX(pax) AS pax_max, COUNT(*) AS n
               FROM pax_counts
              WHERE device = :d
                AND measured_at >= UTC_TIMESTAMP() - INTERVAL :s SECOND
              GROUP BY 1 ORDER BY 1'
        );
        $stmt->execute([':o' => $off, ':o2' => $off, ':b' => $bucket, ':b2' => $bucket, ':d' => $device,
                        ':s' => $span]);
        $points = array_map(fn($r) => [
            (int)$r['t'],
            round((float)$r['pax'], 1),
            round((float)$r['wifi'], 1),
            round((float)$r['ble'], 1),
            (int)$r['pax_max'],
        ], $stmt->fetchAll());

        $stmt = $pdo->prepare(
            'SELECT UNIX_TIMESTAMP(measured_at) AS t, pax, wifi, ble
               FROM pax_counts WHERE device = ?
                AND measured_at >= UTC_TIMESTAMP() - INTERVAL ? SECOND
              ORDER BY pax DESC, measured_at DESC LIMIT 1'
        );
        $stmt->execute([$device, $span]);
        $peak = $stmt->fetch() ?: null;

        out([
            'now'    => $now,
            'from'   => $now - $span,
            'bucket' => $bucket,
            'cols'   => ['t', 'pax', 'wifi', 'ble', 'pax_max'],
            'points' => $points,
            'peak'   => $peak ? array_map('intval', $peak) : null,
        ]);

    case 'heatmap':
        $device = param_device();
        // hourly UTC buckets; the browser maps them to local weekday/hour
        $stmt = $pdo->prepare(
            'SELECT FLOOR(UNIX_TIMESTAMP(measured_at) / 3600) * 3600 AS t,
                    AVG(pax) AS pax
               FROM pax_counts
              WHERE device = ?
                AND measured_at >= UTC_TIMESTAMP() - INTERVAL 28 DAY
              GROUP BY 1 ORDER BY 1'
        );
        $stmt->execute([$device]);
        out(['hours' => array_map(fn($r) => [(int)$r['t'],
                                             round((float)$r['pax'], 1)],
                                  $stmt->fetchAll())]);

    case 'macs':
        $device = param_device();
        [$span] = param_range();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT mac, type) AS total,
                    COUNT(DISTINCT CASE WHEN random_mac = 1 THEN mac END) AS random,
                    COUNT(DISTINCT CASE WHEN type = "wifi" THEN mac END) AS wifi,
                    COUNT(DISTINCT CASE WHEN type = "ble" THEN mac END) AS ble
               FROM pax_devices
              WHERE device = ? AND first_seen >= UTC_TIMESTAMP() - INTERVAL ? SECOND'
        );
        $stmt->execute([$device, $span]);
        $summary = array_map('intval', $stmt->fetch());

        $stmt = $pdo->prepare(
            'SELECT mac, type, MAX(random_mac) AS random_mac,
                    MAX(addr_kind) AS addr_kind, COUNT(*) AS batches,
                    SUM(packets) AS packets,
                    UNIX_TIMESTAMP(MIN(first_seen)) AS first,
                    UNIX_TIMESTAMP(MAX(last_seen)) AS last
               FROM pax_devices
              WHERE device = ? AND first_seen >= UTC_TIMESTAMP() - INTERVAL ? SECOND
              GROUP BY mac, type
              ORDER BY batches DESC, packets DESC
              LIMIT 100'
        );
        $stmt->execute([$device, $span]);
        out(['summary' => $summary, 'macs' => array_map(fn($r) => [
            'mac'     => $r['mac'],
            'type'    => $r['type'],
            'random'  => (bool)$r['random_mac'],
            'kind'    => $r['addr_kind'],
            'batches' => (int)$r['batches'],
            'packets' => (int)$r['packets'],
            'first'   => (int)$r['first'],
            'last'    => (int)$r['last'],
        ], $stmt->fetchAll())]);

    case 'groups':
        // Classifies every MAC by its presence pattern, based on the hourly
        // rollup pax_mac_hours, over max(28 days, range):
        //   stationary: present in >= 75% of the hours the paxcounter saw anything
        //   recurring:  >= 2 separate visits (visit = hours with gaps of <= 2 h)
        //   once:       a single visit
        $device = param_device();
        [$span, $bucket] = param_range();
        $b = max($bucket, 3600);
        $off = (new DateTime('now', new DateTimeZone('Europe/Zurich')))->getOffset();
        $hnow = intdiv($now, 3600);
        $classDays = max(28, intdiv($span, 86400));
        $hcls = $hnow - $classDays * 24;
        $hfrom = intdiv($now - $span, 3600);

        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT hour) FROM pax_mac_hours
                                WHERE device = ? AND hour >= ?');
        $stmt->execute([$device, $hcls]);
        $active = (int)$stmt->fetchColumn();
        $minHours = max(6, (int)ceil($active * 0.75));

        $stmt = $pdo->prepare(
            "CREATE TEMPORARY TABLE cls (PRIMARY KEY (mac, type)) ENGINE=MEMORY
             SELECT mac, type, MAX(random_mac) AS random_mac,
                    MAX(addr_kind) AS addr_kind,
                    COUNT(*) AS hours, SUM(newvisit) AS visits,
                    CASE WHEN COUNT(*) >= ? THEN 'stationary'
                         WHEN SUM(newvisit) >= 2 THEN 'recurring'
                         ELSE 'once' END AS grp
               FROM (SELECT mac, type, random_mac, addr_kind,
                            COALESCE(CAST(hour AS SIGNED) - LAG(hour)
                              OVER (PARTITION BY mac, type ORDER BY hour), 999) > 2
                              AS newvisit
                       FROM pax_mac_hours
                      WHERE device = ? AND hour >= ?) x
              GROUP BY mac, type"
        );
        $stmt->execute([$minHours, $device, $hcls]);

        $summary = [];
        foreach (['stationary', 'recurring', 'once'] as $g) {
            $summary[$g] = ['total' => 0, 'random' => 0];
        }
        foreach ($pdo->query('SELECT grp, COUNT(*) AS n, SUM(random_mac) AS r
                                FROM cls GROUP BY grp') as $r) {
            $summary[$r['grp']] = ['total' => (int)$r['n'], 'random' => (int)$r['r']];
        }

        // distinct devices per group and time bucket within the range
        $stmt = $pdo->prepare(
            'SELECT FLOOR((h.hour * 3600 + ?) / ?) * ? - ? AS t, c.grp,
                    COUNT(DISTINCT h.mac, h.type) AS n
               FROM pax_mac_hours h JOIN cls c USING (mac, type)
              WHERE h.device = ? AND h.hour >= ?
              GROUP BY t, c.grp ORDER BY t'
        );
        $stmt->execute([$off, $b, $b, $off, $device, $hfrom]);
        $col = ['stationary' => 1, 'recurring' => 2, 'once' => 3];
        $points = [];
        foreach ($stmt as $r) {
            $t = (int)$r['t'];
            $points[$t] ??= [$t, 0, 0, 0];
            $points[$t][$col[$r['grp']]] = (int)$r['n'];
        }

        // most present devices in the range + their presence per bucket
        $stmt = $pdo->prepare(
            'SELECT h.mac, h.type, c.random_mac, c.addr_kind, c.grp, c.visits,
                    COUNT(*) AS hours, SUM(h.batches) AS batches,
                    MIN(h.hour) AS first, MAX(h.hour) AS last
               FROM pax_mac_hours h JOIN cls c USING (mac, type)
              WHERE h.device = ? AND h.hour >= ?
              GROUP BY h.mac, h.type
              ORDER BY hours DESC, batches DESC LIMIT 30'
        );
        $stmt->execute([$device, $hfrom]);
        $top = [];
        foreach ($stmt as $r) {
            $top[$r['mac'] . '|' . $r['type']] = [
                'mac'     => $r['mac'],
                'type'    => $r['type'],
                'random'  => (bool)$r['random_mac'],
                'kind'    => $r['addr_kind'],
                'group'   => $r['grp'],
                'visits'  => (int)$r['visits'],
                'hours'   => (int)$r['hours'],
                'batches' => (int)$r['batches'],
                'first'   => (int)$r['first'] * 3600,
                'last'    => (int)$r['last'] * 3600 + 3599,
                'seen'    => [],
            ];
        }
        if ($top) {
            $in = implode(',', array_fill(0, count($top), '(?, ?)'));
            $args = [$off, $b, $b, $off, $device, $hfrom];
            foreach ($top as $d) {
                array_push($args, $d['mac'], $d['type']);
            }
            $stmt = $pdo->prepare(
                "SELECT mac, type, FLOOR((hour * 3600 + ?) / ?) * ? - ? AS t,
                        SUM(batches) AS n
                   FROM pax_mac_hours
                  WHERE device = ? AND hour >= ? AND (mac, type) IN ($in)
                  GROUP BY mac, type, t ORDER BY t"
            );
            $stmt->execute($args);
            foreach ($stmt as $r) {
                $top[$r['mac'] . '|' . $r['type']]['seen'][] = [(int)$r['t'], (int)$r['n']];
            }
        }

        out([
            'now'        => $now,
            'from'       => $now - $span,
            'bucket'     => $b,
            'class_days' => $classDays,
            'active_hours' => $active,
            'min_hours'  => $minHours,
            'summary'    => $summary,
            'points'     => array_values($points),
            'top'        => array_values($top),
        ]);

    case 'networks':
        $device = param_device();
        $stmt = $pdo->prepare(
            'SELECT bssid, ssid, hidden, channel, rssi_last, rssi_max, beacons,
                    UNIX_TIMESTAMP(first_seen) AS first,
                    UNIX_TIMESTAMP(last_seen) AS last
               FROM pax_networks WHERE device = ?
              ORDER BY last_seen DESC, rssi_max DESC LIMIT 500'
        );
        $stmt->execute([$device]);
        out(['now' => $now, 'networks' => array_map(fn($r) => [
            'bssid'   => $r['bssid'],
            'ssid'    => $r['ssid'],
            'hidden'  => (bool)$r['hidden'],
            'channel' => (int)$r['channel'],
            'rssi'    => (int)$r['rssi_last'],
            'rssi_max'=> (int)$r['rssi_max'],
            'beacons' => (int)$r['beacons'],
            'first'   => (int)$r['first'],
            'last'    => (int)$r['last'],
        ], $stmt->fetchAll())]);

    default:
        out(['error' => 'unknown query'], 400);
    }
} catch (PDOException $e) {
    error_log('paxcounter api: ' . $e->getMessage());
    out(['error' => 'database error'], 500);
}
