# Firmware

PlatformIO project in `firmware/esp32-node/` (VS Code with the PlatformIO extension, or the `pio` command line).
Arduino-framework C++; if you prefer the Arduino IDE, rename `src/main.cpp` to a sketch, put `config.h` next to it, and install the
libraries listed in `platformio.ini` — it should build unchanged, though it's untested that way.

> **Status:** syntax-checked, not yet built with the ESP32 toolchain or run on a board. If the first build errors, the likely suspects
> are library version differences — the error message will point at the line.

## 1. Configure

```bash
cd firmware/esp32-node/include
cp config.example.h config.h
```

Edit `config.h`:

| Setting | Notes |
|---|---|
| `WIFI_SSID`, `WIFI_PASSWORD` | The IoT SSID is open (registration only), so the password stays `""`. |
| `SERVER_URL` | `https://YOURSITE.colgate.domains/climate/ingest.php` |
| `DEVICE_ID`, `API_KEY` | Printed by `tools/make_device.py` (one per node). |
| `SENSOR_BME280` / `SENSOR_SHT31` | Pick one. |
| `REPORT_INTERVAL_S`, `UPLOAD_EVERY_N` | Cadence and batching. The server can override the interval later. |
| `BATT_ADC_PIN`, `BATT_DIVIDER`, `BATT_CAL` | Battery monitoring; `-1` disables it. |
| `USE_INSECURE_TLS` | `1` for first tests; `0` for deployment (see below). |

`config.h` is git-ignored. Don't commit it: it holds the node's API key.

## 2. Find the MAC address to register

Flash with `DEBUG_SERIAL 1`, open the serial monitor (`pio device monitor`, 115200 baud) and press reset. On every power-on the node prints:

```
Library Climate node stacks-2f  fw 0.1.0
MAC address (register this): AA:BB:CC:DD:EE:FF
```

Add that MAC to the network's registration list, then reset the board. A normal cycle looks like:

```
T 21.43 C  RH 38.2 %  P 1001.2 hPa  batt 3.91 V
Wi-Fi up: 10.1.2.3  RSSI -61 dBm  (1480 ms)
Upload OK: stored 1, duplicates 0, rejected 0, interval 600s
Sleeping 596 s
```

## 3. Build and flash

```bash
cd firmware/esp32-node
pio run -t upload
pio device monitor
```

Change `board =` in `platformio.ini` if you're not on a generic `esp32dev`.

## Verifying the server's certificate (do this before deploying)

With `USE_INSECURE_TLS 1` the node accepts *any* certificate, so anyone on the network could impersonate your server and feed it (or harvest) the API key.
To verify properly:

```bash
openssl s_client -showcerts -servername YOURSITE.colgate.domains -connect YOURSITE.colgate.domains:443 </dev/null
```

The output lists the chain (your certificate, then intermediates, sometimes the root). Identify the CA that issued the chain (the `i:` line of the last certificate) and
download its **root** certificate in PEM format from that CA's website. Roots outlive intermediates, so a root is the right thing to pin.
Then:

```bash
cp include/root_ca.example.h include/root_ca.h     # paste the PEM between the BEGIN/END lines
# in config.h:  #define USE_INSECURE_TLS 0
```

If you later change hosts or the CA changes, the nodes will fail to connect until reflashed with the new root. (Over-the-air updates are on the to-do list.)

## How it behaves

- **Wake cycle:** read sensor and battery → buffer the reading → upload if due → deep sleep for `interval − time awake`.
- **Offline buffering:** up to 48 readings are kept in RTC memory (lost on power loss or reset). They're uploaded, with their ages, on the next success.
  After 3 failed uploads the node backs off to trying every 6th wake to save battery.
- **Duplicate-safe:** each reading has a sequence number and the node has a random boot id; if a response is lost and the node re-sends, the server drops the repeats.
- **Clock accuracy:** the ESP32's sleep timer is an internal RC oscillator (a few percent error). Normal uploads happen seconds after the reading, so the effect is negligible;
  for readings buffered for hours, timestamps can be off by minutes.
- **Battery protection:** below 3.30 V (`BATT_CUTOFF_V`) the node stops using Wi-Fi and sleeps for an hour at a time.
- **Remote interval:** `UPDATE devices SET report_interval_s = 300 WHERE id = '…'` takes effect at the node's next upload.

## Troubleshooting

| Serial output | Meaning |
|---|---|
| `Sensor not found on I2C` | Wiring, wrong pins in `config.h`, or a BMP280 sold as a BME280 (no humidity) |
| `Upload failed: HTTP -1 (connection refused)` | No route to the server: Wi-Fi up but DNS/TLS failing, or the MAC isn't registered yet |
| `HTTP -1` with `USE_INSECURE_TLS 0` | Wrong or missing root CA |
| `HTTP 401` | Wrong `DEVICE_ID`/`API_KEY`, device inactive, or Directory Privacy blocking `ingest.php` (see DEPLOY.md) |
| `HTTP 400 HTTPS required` | URL starts with `http://` |
| Reboots every few seconds | Brown-out from a weak supply; try a different cable or fresh cell |
