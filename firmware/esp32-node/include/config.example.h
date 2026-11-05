// Copy this file to config.h (same folder) and edit. config.h is git-ignored: it holds the Wi-Fi
// details and this node's API key. Generate DEVICE_ID / API_KEY with tools/make_device.py.
#pragma once

// ---- Wi-Fi ---------------------------------------------------------------
// The IoT SSID is open (registration by MAC only), so leave the password empty.
// To find this board's MAC for registration: flash once with DEBUG_SERIAL 1 and open the serial monitor.
#define WIFI_SSID      "YOUR-IOT-SSID"
#define WIFI_PASSWORD  ""

// ---- Server --------------------------------------------------------------
#define SERVER_URL     "https://YOURSITE.colgate.domains/climate/ingest.php"
#define DEVICE_ID      "stacks-2f"
#define API_KEY        "paste-the-key-from-make_device.py"

// TLS: 1 = accept any certificate (fine for a first test, NOT for deployment: anyone on the
// network could impersonate the server). 0 = verify against root_ca.h (see docs/FIRMWARE.md).
#define USE_INSECURE_TLS 1

// ---- Timing --------------------------------------------------------------
#define REPORT_INTERVAL_S  600   // seconds between readings (the server can override this remotely)
#define UPLOAD_EVERY_N     1     // upload every Nth reading; N>1 saves battery, readings are buffered meanwhile

// ---- Sensor --------------------------------------------------------------
// Pick exactly one.
#define SENSOR_BME280    // BME280: temperature, humidity, pressure (I2C 0x76 or 0x77, auto-detected)
// #define SENSOR_SHT31  // SHT31 / SHT3x: temperature + humidity (I2C 0x44 or 0x45)

#define I2C_SDA 21
#define I2C_SCL 22

// ---- Battery monitoring --------------------------------------------------
// ADC pin wired to the battery through a divider. Many ESP32 boards with a LiPo connector already
// have one (Lolin D32 and Adafruit Feather ESP32 V2: GPIO35, divider 2:1). Use an ADC1 pin
// (GPIO32-39): ADC2 does not work while Wi-Fi is on. Set BATT_ADC_PIN to -1 if you have none.
#define BATT_ADC_PIN     35
#define BATT_DIVIDER     2.0f    // battery volts = pin volts * this
#define BATT_CAL         1.00f   // trim after comparing against a multimeter

// ---- Debug ---------------------------------------------------------------
#define DEBUG_SERIAL 1           // 1 = print progress at 115200 baud; set 0 for deployment (saves a little power)
