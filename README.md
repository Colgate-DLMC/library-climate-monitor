# Library Climate Monitor

Low-cost temperature and humidity logging for library spaces: battery-powered ESP32 sensor nodes
POST readings over HTTPS to a small PHP + MySQL app on shared hosting (Reclaim Hosting "Domains" /
cPanel), and a static web dashboard charts them.

```
 ┌──────────────┐  Wi-Fi (IoT SSID, MAC-registered)   ┌──────────────────────────────┐
 │ ESP32 + BME280│ ── HTTPS POST JSON, X-Api-Key ───▶ │ ingest.php ─▶ MySQL          │
 │  (deep sleep) │                                     │ api.php  ◀─ index.html (Chart.js)
 └──────────────┘                                      └──────────────────────────────┘
        ×N rooms                                            runs on Domains / cPanel
```

## Status

| Part | State |
|---|---|
| Server (`ingest.php`, `api.php`, schema) | Tested end to end locally against SQLite: auth, validation, de-duplication, bucketing, CSV. **Not yet run against MySQL on Domains.** |
| Dashboard | Rendered and exercised in Chromium (light/dark/mobile, hover, keyboard, unit toggle). |
| ESP32 firmware | Written and syntax-checked against stub headers and the real ArduinoJson. **Not yet compiled with the ESP32 toolchain or run on hardware** — expect to fix small things on first build. |

## What it does

- **Nodes** wake every 10 minutes (configurable), read a BME280 or SHT31, read battery voltage, upload, and go back
  to deep sleep. If Wi-Fi or the server is down, readings are held in RTC memory (about 8 hours) and sent later.
- **No clock needed on the node.** Each reading carries its age in seconds; the server computes the
  timestamp, so there is no NTP dependency and no time-zone logic on the device.
- **Per-device API keys**, stored hashed. Out-of-range values are rejected. Retried uploads are de-duplicated.
- **Remote interval control:** change `report_interval_s` in the `devices` table and the node picks it up on its next upload.
- **Dashboard:** current values per location, status (reporting / late / offline), low-battery flag, temperature and
  humidity charts with a time-range filter, °F/°C toggle, table view, and CSV export for analysis.

## Repository layout

```
firmware/esp32-node/     PlatformIO project (copy include/config.example.h -> config.h)
server/public/           Upload to public_html/climate/ : ingest.php, api.php, index.html, assets/
server/schema.*.sql      MySQL (production) and SQLite (local dev) schemas
server/tools/init_db.php Create tables from the command line
tools/make_device.py     Generate an API key + the SQL that registers a device
tools/simulate_device.py Fake a node (with backfill) to test without hardware
docs/                    DEPLOY.md, HARDWARE.md, FIRMWARE.md
```

## Try it locally in two minutes (no hardware)

Needs PHP 8.1+ with `pdo_sqlite`, and Python 3.

```bash
cat > /tmp/climate-dev.php <<'EOF'
<?php return ['db' => ['driver' => 'sqlite', 'path' => '/tmp/climate-dev.sqlite'], 'require_https' => false];
EOF
export CLIMATE_CONFIG=/tmp/climate-dev.php

php server/tools/init_db.php
python3 tools/make_device.py --id stacks-2f --name "Stacks, 2nd floor" --order 10 --apply-sqlite /tmp/climate-dev.sqlite
#   -> prints the API_KEY line to use below
php -S 127.0.0.1:8080 -t server/public &
python3 tools/simulate_device.py --url http://127.0.0.1:8080/ingest.php --id stacks-2f --key <API_KEY> --backfill-hours 72
#   then visit http://127.0.0.1:8080/ in a browser
```

## Deploying

1. **Server:** follow [docs/DEPLOY.md](docs/DEPLOY.md) (cPanel MySQL, upload, config, register devices).
2. **Hardware:** [docs/HARDWARE.md](docs/HARDWARE.md) (wiring, 18650 power, enclosure and sensor placement).
3. **Firmware:** [docs/FIRMWARE.md](docs/FIRMWARE.md) (configure, find the MAC to register, flash, verify TLS).

## Before you trust the numbers

Hobby sensors are typically good to about ±0.5–1 °C and ±3 % RH out of the box. Before comparing rooms, put
all nodes side by side in one spot for a day and compare them; note any consistent offsets. Keep sensors out of direct
sun and away from vents and heat sources (including the ESP32 and battery themselves).

## Ideas for later

- Per-device calibration offsets (a column on `devices`, applied in `api.php`).
- Email or Teams alert when a node goes offline or a space leaves a target band (e.g. 65–75 °F / 30–50 % RH).
- Over-the-air firmware updates.
- Nightly roll-up of old readings to hourly averages (not needed for years at this volume: ~52,000 rows per node per year).

## License

MIT — see [LICENSE](LICENSE).
