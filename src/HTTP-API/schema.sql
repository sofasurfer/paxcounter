-- MySQL schema for Paxcounter HTTP upload (src/paxhttp.cpp)
-- All timestamps are stored in UTC.

CREATE TABLE IF NOT EXISTS pax_counts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device       VARCHAR(32)     NOT NULL,          -- e.g. paxcounter_1a2b3c4d
  boot_id      INT UNSIGNED    NOT NULL,          -- random per device boot
  seq          INT UNSIGNED    NOT NULL,          -- running number since boot
  measured_at  DATETIME        NOT NULL,          -- UTC, server time minus age
  received_at  DATETIME        NOT NULL,          -- UTC
  pax          INT UNSIGNED    NOT NULL,          -- wifi + ble
  wifi         INT UNSIGNED    NOT NULL,
  ble          INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_record (device, boot_id, seq),     -- makes retries idempotent
  KEY idx_device_time (device, measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per detected device (MAC + type) per upload batch (~ per send cycle).
CREATE TABLE IF NOT EXISTS pax_devices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device       VARCHAR(32)     NOT NULL,          -- paxcounter that saw it
  boot_id      INT UNSIGNED    NOT NULL,
  batch        INT UNSIGNED    NOT NULL,          -- upload batch since boot
  mac          CHAR(17)        NOT NULL,          -- aa:bb:cc:dd:ee:ff
  type         ENUM('wifi','ble') NOT NULL,
  random_mac   TINYINT(1)      NOT NULL,          -- rotating / randomized address
  addr_kind    CHAR(1)         NULL,              -- BLE: p public, s random static,
                                                  -- r resolvable, n non-resolvable
  packets      INT UNSIGNED    NOT NULL,          -- frames seen in batch
  first_seen   DATETIME        NOT NULL,          -- UTC
  last_seen    DATETIME        NOT NULL,          -- UTC
  received_at  DATETIME        NOT NULL,          -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_sighting (device, boot_id, batch, mac, type), -- idempotent retries
  KEY idx_mac_time (mac, first_seen),
  KEY idx_time (first_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hourly rollup of pax_devices (one row per MAC + type + hour), maintained by
-- ingest.php. Used by the dashboard to group devices by presence.
CREATE TABLE IF NOT EXISTS pax_mac_hours (
  device       VARCHAR(32)     NOT NULL,
  hour         INT UNSIGNED    NOT NULL,          -- unix time / 3600 (UTC)
  mac          CHAR(17)        NOT NULL,
  type         ENUM('wifi','ble') NOT NULL,
  random_mac   TINYINT(1)      NOT NULL,
  addr_kind    CHAR(1)         NULL,
  batches      INT UNSIGNED    NOT NULL,          -- send cycles seen in this hour
  packets      INT UNSIGNED    NOT NULL,
  PRIMARY KEY (device, hour, mac, type),
  KEY idx_device_mac (device, mac, type, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time backfill from existing raw data (safe to re-run, recomputes totals):
-- SET time_zone = '+00:00';
-- INSERT INTO pax_mac_hours (device, hour, mac, type, random_mac, batches, packets)
--   SELECT device, FLOOR(UNIX_TIMESTAMP(first_seen) / 3600), mac, type,
--          MAX(random_mac), COUNT(*), SUM(packets)
--     FROM pax_devices GROUP BY 1, 2, 3, 4
--   ON DUPLICATE KEY UPDATE batches = VALUES(batches), packets = VALUES(packets);

-- Nearby WiFi access points (from beacon frames), one row per BSSID and
-- paxcounter. Uploaded every HTTP_NET_INTERVAL seconds (src/netlog.cpp).
CREATE TABLE IF NOT EXISTS pax_networks (
  device       VARCHAR(32)     NOT NULL,
  bssid        CHAR(17)        NOT NULL,          -- access point MAC
  ssid         VARCHAR(32)     NOT NULL,          -- network name, '' if hidden
  hidden       TINYINT(1)      NOT NULL,
  channel      TINYINT UNSIGNED NOT NULL,
  rssi_last    SMALLINT        NOT NULL,          -- dBm, last beacon
  rssi_max     SMALLINT        NOT NULL,          -- dBm, best in last report
  beacons      BIGINT UNSIGNED NOT NULL,          -- total beacons received
  first_seen   DATETIME        NOT NULL,          -- UTC
  last_seen    DATETIME        NOT NULL,          -- UTC
  PRIMARY KEY (device, bssid),
  KEY idx_device_last (device, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration for databases created before addr_kind existed:
-- ALTER TABLE pax_devices   ADD COLUMN addr_kind CHAR(1) NULL AFTER random_mac;
-- ALTER TABLE pax_mac_hours ADD COLUMN addr_kind CHAR(1) NULL AFTER random_mac;
