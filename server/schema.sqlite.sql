-- Library Climate Monitor: SQLite schema (local development / tests only)
-- Same shape as schema.mysql.sql.

CREATE TABLE IF NOT EXISTS devices (
  id                 TEXT PRIMARY KEY,
  name               TEXT NOT NULL,
  location           TEXT NOT NULL DEFAULT '',
  api_key_hash       TEXT NOT NULL,
  sort_order         INTEGER NOT NULL DEFAULT 0,
  active             INTEGER NOT NULL DEFAULT 1,
  report_interval_s  INTEGER NOT NULL DEFAULT 600,
  expected_upload_s  INTEGER NOT NULL DEFAULT 600,
  created_at         INTEGER NOT NULL,
  last_seen_at       INTEGER,
  last_rssi          INTEGER,
  fw_version         TEXT
);

CREATE TABLE IF NOT EXISTS readings (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  device_id     TEXT NOT NULL REFERENCES devices (id) ON DELETE CASCADE,
  ts            INTEGER NOT NULL,
  boot_id       INTEGER,
  seq           INTEGER,
  temp_c        REAL,
  rh            REAL,
  pressure_hpa  REAL,
  batt_v        REAL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_dedupe ON readings (device_id, boot_id, seq);
CREATE INDEX IF NOT EXISTS idx_device_ts ON readings (device_id, ts);
CREATE INDEX IF NOT EXISTS idx_ts ON readings (ts);
