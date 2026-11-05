# Hardware

## Which board for what

| Board | Verdict |
|---|---|
| **ESP32** | The node. Built-in Wi-Fi, cheap, and deep sleep makes battery operation practical. |
| Arduino (classic) | No networking without a shield or a tether to something else. Not used here. |
| Raspberry Pi B+ | No Wi-Fi, slow, and draws far too much for battery use. Keep it for something else, or use it as a wired logger if a spot has Ethernet. |

## Per-node parts

- ESP32 board with a **LiPo/Li-ion connector and onboard charger** (so the 18650 plugs in and charges over USB).
  For battery life, pick a board designed for it — look at the board's *published deep-sleep current*, e.g. DFRobot FireBeetle 2 ESP32-E,
  Adafruit Feather ESP32 V2, LOLIN D32. Generic "devkit" boards with a USB-serial chip and an always-on linear regulator
  often burn several milliamps even in deep sleep, which turns months into weeks (see the arithmetic below).
- **BME280** (temperature / humidity / pressure) or **SHT31 / SHT4x** (temperature / humidity, better humidity accuracy).
  Many cheap modules sold as "BME280" are actually **BMP280** (no humidity). The BME280 chip ID is 0x60; the firmware will report *Sensor not found* for a BMP280.
- 18650 cell + holder, or just a USB wall adapter (see below).
- Optional: 100 nF capacitor across the sensor's power pins if you see noisy readings.

## Wiring (BME280 or SHT31 over I²C)

| Sensor pin | ESP32 pin |
|---|---|
| VIN / VCC | 3V3 |
| GND | GND |
| SDA | GPIO 21 (`I2C_SDA`) |
| SCL | GPIO 22 (`I2C_SCL`) |

Keep I²C leads short (under ~30 cm). The BME280 answers at 0x76 or 0x77, the SHT31 at 0x44 or 0x45; the firmware tries both.
Pins are set in `config.h`.

**Battery voltage:** the ADC can't read 4.2 V directly. Boards with a battery connector usually have a 2:1 divider already
wired to an ADC1 pin (GPIO35 on several; check your board's schematic) and `BATT_DIVIDER 2.0` matches that. If your board
has none, add two equal resistors (100 kΩ + 100 kΩ) from battery+ to GND with the midpoint to GPIO35, plus a 100 nF capacitor from
the midpoint to GND. Use ADC1 pins (GPIO32–39); ADC2 stops working when Wi-Fi is on. Calibrate `BATT_CAL` against a multimeter.

## Power: 18650 vs. USB

USB from a wall adapter is the simplest and safest option wherever an outlet is within reach, and it removes battery maintenance entirely.
An 18650 makes sense where there is no outlet.

If you use 18650s:

- Use **protected** cells from a reputable supplier (a protection circuit is built in or sits on the charger board) — never salvaged or unmarked cells.
- Use a charger/board with proper Li-ion charge control (the ESP32 board's onboard charger, or a TP4056 module *with* the DW01 protection chip) and charge at a sensible
  current for the cell (at or below 0.5C is gentle).
- Don't connect a 4.2 V cell straight to the ESP32's 3V3 pin. Go through the board's battery connector/regulator.
- Give the enclosure a little ventilation and keep the cell away from the sensor (see placement).
- Check whether your campus has rules for lithium batteries in public buildings before deploying; some do.

### Battery-life arithmetic (illustrative, not measured)

Average current = (sleep current × sleep time + active current × active time) ÷ cycle time. With a 10-minute cycle,
about 4 s awake at roughly 120 mA (Wi-Fi connect + HTTPS POST), and a 3000 mAh cell derated to ~70 % usable:

| Board's deep-sleep current | Average draw | Rough runtime |
|---|---|---|
| 20 µA (battery-oriented board) | ≈ 0.8 mA | ≈ 100 days |
| 5 mA (generic devkit) | ≈ 5.8 mA | ≈ 15 days |

Ways to stretch it: report every 15–30 minutes, set `UPLOAD_EVERY_N` to 3 (readings are buffered and sent in batches), and turn `DEBUG_SERIAL` off.
Real numbers depend on Wi-Fi conditions — read the `batt_v` trend on the dashboard for the first couple of weeks and extrapolate.

## Enclosure and sensor placement (3D printing)

Temperature accuracy depends more on placement than on the sensor:

- **Isolate the sensor thermally** from the ESP32, charger and battery: a separate compartment or a short lead out to a vented "nose".
  The ESP32 warms up while on Wi-Fi, and a charging cell is warm too.
- **Let air in.** Vent slots on the sides and bottom, baffled so there's no direct light. Heat rises: put the sensor low and the electronics high.
- **Print in a light colour** (white/light grey) and keep the box out of direct sun and away from vents, radiators, windows and equipment.
- Mount at a consistent height across rooms (say 1.5 m) so readings are comparable, and write the mounting spot in `devices.location`.
- Leave a USB-C opening and a way to get at the battery. Label each unit with its `DEVICE_ID`.
- PLA is fine indoors at library temperatures. Use PETG if a node might sit somewhere hot.
