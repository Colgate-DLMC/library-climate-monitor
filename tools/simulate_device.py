#!/usr/bin/env python3
"""Pretend to be a sensor node: POST fake readings so you can test the server and dashboard
without hardware. Standard library only.

    # backfill 3 days of history, then send one live reading every 10 minutes
    python3 tools/simulate_device.py --url http://localhost:8080/ingest.php \
        --id stacks-2f --key <API_KEY> --backfill-hours 72 --live

Uses the same protocol as the firmware (age_s offsets, boot_id + seq for de-duplication).
"""
import argparse
import json
import math
import random
import time
import urllib.error
import urllib.request


def post(url: str, device_id: str, key: str, payload: dict) -> dict:
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "X-Api-Key": key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.load(resp)
    except urllib.error.HTTPError as e:
        return {"ok": False, "http": e.code, "body": e.read().decode(errors="replace")}


def fake(ts: float, base_t: float, base_rh: float, rng: random.Random) -> tuple[float, float]:
    day = (ts % 86400) / 86400
    temp = base_t + 1.8 * math.sin((day - 0.30) * 2 * math.pi) + rng.gauss(0, 0.12)
    rh = base_rh - 4.0 * math.sin((day - 0.30) * 2 * math.pi) + rng.gauss(0, 0.5)
    return round(temp, 2), round(max(5.0, min(95.0, rh)), 1)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", required=True)
    ap.add_argument("--id", required=True)
    ap.add_argument("--key", required=True)
    ap.add_argument("--interval", type=int, default=600, help="seconds between readings")
    ap.add_argument("--backfill-hours", type=float, default=0)
    ap.add_argument("--base-temp", type=float, default=21.5, help="mean temperature in C")
    ap.add_argument("--base-rh", type=float, default=40.0)
    ap.add_argument("--live", action="store_true", help="keep sending a reading every --interval seconds")
    args = ap.parse_args()

    rng = random.Random(args.id)
    boot_id = random.getrandbits(31)
    seq = 0
    now = time.time()

    n = int(args.backfill_hours * 3600 // args.interval)
    batch = []
    for i in range(n, -1, -1):
        t = now - i * args.interval
        temp, rh = fake(t, args.base_temp, args.base_rh, rng)
        batch.append({"seq": seq, "age_s": int(now - t), "temp_c": temp, "rh": rh,
                      "batt_v": round(4.05 - 0.0001 * (n - i), 2)})
        seq += 1
        if len(batch) == 100:
            print(post(args.url, args.id, args.key, {"device_id": args.id, "boot_id": boot_id, "fw": "sim",
                                                     "rssi": -60, "upload_interval_s": args.interval, "readings": batch}))
            batch = []
    if batch:
        print(post(args.url, args.id, args.key, {"device_id": args.id, "boot_id": boot_id, "fw": "sim",
                                                 "rssi": -60, "upload_interval_s": args.interval, "readings": batch}))

    while args.live:
        time.sleep(args.interval)
        t = time.time()
        temp, rh = fake(t, args.base_temp, args.base_rh, rng)
        print(post(args.url, args.id, args.key, {
            "device_id": args.id, "boot_id": boot_id, "fw": "sim", "rssi": -60,
            "upload_interval_s": args.interval,
            "readings": [{"seq": seq, "age_s": 0, "temp_c": temp, "rh": rh, "batt_v": 3.95}]}))
        seq += 1


if __name__ == "__main__":
    main()
