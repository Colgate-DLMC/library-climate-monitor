#!/usr/bin/env python3
"""Generate an API key for a new sensor node and print the SQL to register it.

The key is shown ONCE. Put it in the node's config.h; only its SHA-256 hash goes
into the database, so a leaked database does not leak working keys.

    python3 tools/make_device.py --id stacks-2f --name "Stacks, 2nd floor" --order 10
    python3 tools/make_device.py --id reading-room --name "Reading room" --order 20 --apply-sqlite /tmp/dev.sqlite
"""
import argparse
import hashlib
import re
import secrets
import sqlite3
import time


def sql_str(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--id", required=True, help="device id: letters, digits, - or _ (max 32), e.g. stacks-2f")
    ap.add_argument("--name", required=True, help="display name shown on the dashboard")
    ap.add_argument("--location", default="", help="free-text notes (shelf, wall, height...)")
    ap.add_argument("--order", type=int, default=0, help="sort order; also fixes the chart colour slot")
    ap.add_argument("--interval", type=int, default=600, help="seconds between readings (default 600)")
    ap.add_argument("--apply-sqlite", metavar="DB_FILE", help="local development: run the INSERT against this SQLite file instead of printing it")
    args = ap.parse_args()

    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,31}", args.id):
        ap.error("--id must be 1-32 chars: letters, digits, '-' or '_', starting with a letter or digit")

    key = secrets.token_urlsafe(24)
    key_hash = hashlib.sha256(key.encode()).hexdigest()

    insert = (
        "INSERT INTO devices (id, name, location, api_key_hash, sort_order, report_interval_s, expected_upload_s, created_at)\n"
        f"VALUES ({sql_str(args.id)}, {sql_str(args.name)}, {sql_str(args.location)}, '{key_hash}', "
        f"{args.order}, {args.interval}, {args.interval}, {int(time.time())});"
    )
    if args.apply_sqlite:
        with sqlite3.connect(args.apply_sqlite) as db:
            db.execute(
                "INSERT INTO devices (id, name, location, api_key_hash, sort_order, report_interval_s, expected_upload_s, created_at) "
                "VALUES (?,?,?,?,?,?,?,?)",
                (args.id, args.name, args.location, key_hash, args.order, args.interval, args.interval, int(time.time())),
            )
        print(f"-- Registered {args.id} in {args.apply_sqlite}")
    else:
        print("-- Run this in phpMyAdmin (SQL tab):")
        print(insert)
    print()
    print("// Put this in firmware/esp32-node/include/config.h for this node:")
    print(f'#define DEVICE_ID "{args.id}"')
    print(f'#define API_KEY   "{key}"')


if __name__ == "__main__":
    main()
