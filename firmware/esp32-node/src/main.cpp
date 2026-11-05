// Library Climate Monitor - ESP32 sensor node
//
// Wake -> read sensor + battery -> (every Nth wake) connect to Wi-Fi and POST the buffered readings
// -> deep sleep. Readings that cannot be uploaded stay in RTC memory and go out on the next successful
// upload. The node has no real-time clock: each reading carries its age in seconds and the server
// works out the timestamp (see server/public/ingest.php).
//
// Edit include/config.h (copied from config.example.h), not this file, for normal setup.

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <ArduinoJson.h>
#include <esp_sleep.h>
#include <esp_system.h>
#include <math.h>

#if __has_include("config.h")
#include "config.h"
#else
#error "Copy include/config.example.h to include/config.h and edit it."
#endif

#if !USE_INSECURE_TLS
#include "root_ca.h"
#endif

#if defined(SENSOR_BME280)
#include <Adafruit_BME280.h>
static Adafruit_BME280 bme;
#elif defined(SENSOR_SHT31)
#include <Adafruit_SHT31.h>
static Adafruit_SHT31 sht;
#else
#error "Define exactly one of SENSOR_BME280 or SENSOR_SHT31 in config.h"
#endif

#define FW_VERSION "0.1.0"

#ifndef BUFFER_CAP
#define BUFFER_CAP 48            // readings kept in RTC memory while the server is unreachable (8 h at 10 min)
#endif
#ifndef BATT_CUTOFF_V
#define BATT_CUTOFF_V 3.30f      // below this, stop using Wi-Fi and just sleep, to protect the cell
#endif
#ifndef BATT_CUTOFF_SLEEP_S
#define BATT_CUTOFF_SLEEP_S 3600
#endif

#if DEBUG_SERIAL
#define LOG(...) Serial.printf(__VA_ARGS__)
#else
#define LOG(...) do {} while (0)
#endif

#define RTC_MAGIC 0xC11A7E01u

struct Reading {
  uint32_t seq;
  uint32_t t_s;           // node clock (seconds since power-on) when the reading was taken
  float temp_c;           // NAN = not measured
  float rh;
  float pressure_hpa;
  float batt_v;
};

// Survives deep sleep (not power loss). Roughly 1.3 KB of the 8 KB RTC slow memory.
RTC_DATA_ATTR static struct {
  uint32_t magic;
  uint32_t boot_id;       // random per power-on; with seq it lets the server drop duplicate uploads
  uint32_t next_seq;
  uint32_t clock_s;       // node clock at the start of the current wake
  uint32_t interval_s;    // seconds between readings (server-controllable)
  uint32_t wakes_since_upload;
  uint32_t wake_count;
  uint8_t  fail_count;    // consecutive failed uploads (drives backoff)
  uint8_t  bssid[6];      // last access point, for fast reconnects
  int32_t  channel;
  uint8_t  count;         // readings currently buffered
  Reading  buf[BUFFER_CAP];
} rtc;

static uint32_t now_s() { return rtc.clock_s + millis() / 1000; }

// ---------------------------------------------------------------- sensor
static bool sensor_begin() {
  Wire.begin(I2C_SDA, I2C_SCL);
#if defined(SENSOR_BME280)
  if (!bme.begin(0x76, &Wire) && !bme.begin(0x77, &Wire)) return false;
  // Forced mode: one measurement per wake, then the sensor idles. Minimal self-heating.
  bme.setSampling(Adafruit_BME280::MODE_FORCED, Adafruit_BME280::SAMPLING_X1, Adafruit_BME280::SAMPLING_X1,
                  Adafruit_BME280::SAMPLING_X1, Adafruit_BME280::FILTER_OFF);
  return true;
#else
  return sht.begin(0x44) || sht.begin(0x45);
#endif
}

static bool sensor_read(Reading &r) {
  r.temp_c = r.rh = r.pressure_hpa = NAN;
#if defined(SENSOR_BME280)
  if (!bme.takeForcedMeasurement()) return false;
  r.temp_c = bme.readTemperature();
  r.rh = bme.readHumidity();
  r.pressure_hpa = bme.readPressure() / 100.0f;
#else
  r.temp_c = sht.readTemperature();
  r.rh = sht.readHumidity();
#endif
  return !isnan(r.temp_c) || !isnan(r.rh);
}

static float read_batt_v() {
#if BATT_ADC_PIN >= 0
  analogSetPinAttenuation(BATT_ADC_PIN, ADC_11db);
  uint32_t mv = 0;
  for (int i = 0; i < 16; i++) { mv += analogReadMilliVolts(BATT_ADC_PIN); delay(2); }
  return (mv / 16.0f) / 1000.0f * BATT_DIVIDER * BATT_CAL;
#else
  return NAN;
#endif
}

// ---------------------------------------------------------------- buffer
static void buffer_push(const Reading &r) {
  if (rtc.count >= BUFFER_CAP) {                       // full: drop the oldest
    memmove(&rtc.buf[0], &rtc.buf[1], sizeof(Reading) * (BUFFER_CAP - 1));
    rtc.count = BUFFER_CAP - 1;
  }
  rtc.buf[rtc.count++] = r;
}

// ---------------------------------------------------------------- network
static bool wifi_connect() {
  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  const char *pass = strlen(WIFI_PASSWORD) ? WIFI_PASSWORD : nullptr;   // open network: no password

  const int attempts = rtc.channel > 0 ? 2 : 1;
  for (int a = 0; a < attempts; a++) {
    if (a == 0 && rtc.channel > 0) WiFi.begin(WIFI_SSID, pass, rtc.channel, rtc.bssid);   // fast path
    else WiFi.begin(WIFI_SSID, pass);                                                   // full scan
    const uint32_t t0 = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - t0 < 10000) delay(50);
    if (WiFi.status() == WL_CONNECTED) {
      memcpy(rtc.bssid, WiFi.BSSID(), 6);
      rtc.channel = WiFi.channel();
      LOG("Wi-Fi up: %s  RSSI %d dBm  (%lu ms)\n", WiFi.localIP().toString().c_str(), WiFi.RSSI(), (unsigned long)(millis() - t0));
      return true;
    }
    WiFi.disconnect();
    delay(100);
    rtc.channel = 0;                                                                     // stale hint: forget it
  }
  return false;
}

static bool upload() {
  JsonDocument doc;
  doc["device_id"] = DEVICE_ID;
  doc["fw"] = FW_VERSION;
  doc["boot_id"] = rtc.boot_id;
  doc["rssi"] = WiFi.RSSI();
  doc["upload_interval_s"] = rtc.interval_s * UPLOAD_EVERY_N;
  JsonArray arr = doc["readings"].to<JsonArray>();

  const uint32_t now = now_s();
  for (uint8_t i = 0; i < rtc.count; i++) {
    const Reading &r = rtc.buf[i];
    JsonObject o = arr.add<JsonObject>();
    o["seq"] = r.seq;
    o["age_s"] = now - r.t_s;
    if (!isnan(r.temp_c)) o["temp_c"] = roundf(r.temp_c * 100.0f) / 100.0f;
    if (!isnan(r.rh)) o["rh"] = roundf(r.rh * 10.0f) / 10.0f;
    if (!isnan(r.pressure_hpa)) o["pressure_hpa"] = roundf(r.pressure_hpa * 10.0f) / 10.0f;
    if (!isnan(r.batt_v)) o["batt_v"] = roundf(r.batt_v * 100.0f) / 100.0f;
  }
  String body;
  serializeJson(doc, body);

  WiFiClientSecure client;
#if USE_INSECURE_TLS
  client.setInsecure();
#else
  client.setCACert(ROOT_CA);
#endif
  HTTPClient http;
  http.setTimeout(10000);
  if (!http.begin(client, SERVER_URL)) return false;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);

  const int code = http.POST(body);
  bool ok = false;
  if (code == 200) {
    JsonDocument resp;
    if (!deserializeJson(resp, http.getString()) && resp["ok"] == true) {
      ok = true;
      const uint32_t iv = resp["interval_s"] | 0;       // remote config from the devices table
      if (iv >= 60 && iv <= 86400) rtc.interval_s = iv;
      LOG("Upload OK: stored %d, duplicates %d, rejected %d, interval %lus\n", (int)resp["stored"], (int)resp["duplicates"],
          (int)resp["rejected"], (unsigned long)rtc.interval_s);
    }
  } else {
    LOG("Upload failed: HTTP %d (%s)\n", code, HTTPClient::errorToString(code).c_str());
  }
  http.end();
  return ok;
}

// ---------------------------------------------------------------- sleep
static void sleep_now(uint32_t interval_s) {
  const uint64_t awake_us = (uint64_t)millis() * 1000ULL;
  uint64_t sleep_us = (uint64_t)interval_s * 1000000ULL;
  sleep_us = sleep_us > awake_us + 1000000ULL ? sleep_us - awake_us : 1000000ULL;   // keep the cadence steady
  rtc.clock_s += (uint32_t)((awake_us + sleep_us + 500000ULL) / 1000000ULL);
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
  LOG("Sleeping %llu s\n", (unsigned long long)(sleep_us / 1000000ULL));
#if DEBUG_SERIAL
  Serial.flush();
#endif
  esp_sleep_enable_timer_wakeup(sleep_us);
  esp_deep_sleep_start();
}

// ---------------------------------------------------------------- main
void setup() {
#if DEBUG_SERIAL
  Serial.begin(115200);
  delay(100);
#endif

  const bool timer_wake = esp_sleep_get_wakeup_cause() == ESP_SLEEP_WAKEUP_TIMER;
  bool first_boot = false;
  if (!(timer_wake && rtc.magic == RTC_MAGIC)) {                 // power-on, reset, or corrupted RTC memory
    memset(&rtc, 0, sizeof(rtc));
    rtc.magic = RTC_MAGIC;
    rtc.boot_id = esp_random() & 0x7FFFFFFF;
    rtc.interval_s = REPORT_INTERVAL_S;
    first_boot = true;
    WiFi.mode(WIFI_STA);                                         // the MAC is only valid once Wi-Fi is initialised
    LOG("\nLibrary Climate node %s  fw %s\nMAC address (register this): %s\n", DEVICE_ID, FW_VERSION, WiFi.macAddress().c_str());
  }
  rtc.wake_count++;

  // --- measure ---
  Reading r{};
  r.seq = rtc.next_seq++;
  r.batt_v = read_batt_v();                                      // read before Wi-Fi starts drawing current
  bool measured = false;
  if (sensor_begin()) {
    measured = sensor_read(r);
  } else {
    LOG("Sensor not found on I2C - check wiring/address\n");
  }
  r.t_s = now_s();
  if (measured) {
    LOG("T %.2f C  RH %.1f %%  P %.1f hPa  batt %.2f V\n", r.temp_c, r.rh, r.pressure_hpa, r.batt_v);
    buffer_push(r);
    rtc.wakes_since_upload++;
  } else {
    LOG("No valid reading this wake\n");
  }

  // --- protect the battery ---
  if (!isnan(r.batt_v) && r.batt_v > 1.0f && r.batt_v < BATT_CUTOFF_V) {
    LOG("Battery %.2f V below cutoff - skipping Wi-Fi\n", r.batt_v);
    sleep_now(BATT_CUTOFF_SLEEP_S);
  }

  // --- upload when due (and back off if the network keeps failing) ---
  bool due = first_boot || rtc.wakes_since_upload >= UPLOAD_EVERY_N || rtc.count >= BUFFER_CAP - 2;
  if (rtc.fail_count >= 3 && (rtc.wake_count % 6) != 0) due = false;     // after 3 failures, try every 6th wake
  if (due) {
    if (wifi_connect() && upload()) {
      rtc.count = 0;
      rtc.wakes_since_upload = 0;
      rtc.fail_count = 0;
    } else if (rtc.fail_count < 255) {
      rtc.fail_count++;
    }
  }

  sleep_now(rtc.interval_s);
}

void loop() {}   // never reached: every wake ends in deep sleep
