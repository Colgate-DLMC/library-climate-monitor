-- Library Climate Monitor: MySQL / MariaDB schema (cPanel -> phpMyAdmin -> Import)
-- All times are stored as UTC unix epoch seconds (INT) so the app works the same
-- on MySQL and SQLite and never depends on the server's time zone.

CREATE TABLE IF NOT EXISTS devices (
  id                 VARCHAR(32)  NOT NULL,            -- e.g. 'stacks-2f' (matches DEVICE_ID in firmware)
  name               VARCHAR(100) NOT NULL,            -- display name, e.g. 'Stacks, 2nd floor'
  location           VARCHAR(200) NOT NULL DEFAULT '', -- free text notes
  api_key_hash       CHAR(64)     NOT NULL,            -- sha256 hex of the device's API key
  sort_order         INT          NOT NULL DEFAULT 0,  -- also fixes the chart colour slot
  active             TINYINT(1)   NOT NULL DEFAULT 1,
  report_interval_s  INT          NOT NULL DEFAULT 600,-- sent back to the device on every upload
  expected_upload_s  INT          NOT NULL DEFAULT 600,-- reported by the device; drives online/late/offline
  created_at         INT          NOT NULL,
  last_seen_at       INT          NULL,
  last_rssi          SMALLINT     NULL,
  fw_version         VARCHAR(16)  NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS readings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id     VARCHAR(32)  NOT NULL,
  ts            INT UNSIGNED NOT NULL,   -- unix epoch seconds, UTC (server time minus the reading's age)
  boot_id       INT UNSIGNED NULL,       -- random per power-on; with seq it de-duplicates retried uploads
  seq           INT UNSIGNED NULL,
  temp_c        FLOAT        NULL,
  rh            FLOAT        NULL,       -- relative humidity, %
  pressure_hpa  FLOAT        NULL,
  batt_v        FLOAT        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dedupe (device_id, boot_id, seq),
  KEY idx_device_ts (device_id, ts),
  KEY idx_ts (ts),
  CONSTRAINT fk_readings_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
