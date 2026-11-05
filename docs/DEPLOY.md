# Deploying the server to Domains (cPanel)

Everything lives in one folder you upload, plus a MySQL database. Requires PHP 8.1 or newer with `pdo_mysql`
(cPanel → **MultiPHP Manager**).

## 1. Create the database

cPanel → **MySQL® Databases**:

1. Create a database, e.g. `climate` (cPanel will name it `youraccount_climate`).
2. Create a user with a strong password, e.g. `climate`.
3. **Add the user to the database** with *All Privileges*.

cPanel → **phpMyAdmin** → select the new database → **Import** → choose `server/schema.mysql.sql`.

## 2. Upload the app

Upload the contents of `server/public/` to `public_html/climate/` (File Manager, SFTP, or cPanel's Git Version
Control). The result should be `https://YOURSITE.colgate.domains/climate/` serving the dashboard.

Do **not** upload `server/schema.*.sql`, `tools/`, or `firmware/`.

## 3. Configure

Copy `config.sample.php` to **`~/climate-config.php`** — your home directory, *above* `public_html`, so it can never be served.
(If you can't do that, `public_html/climate/config.php` also works; the included `.htaccess` blocks direct access.)
Fill in the database name, user and password.

## 4. Register each sensor node

On your own computer:

```bash
python3 tools/make_device.py --id stacks-2f --name "Stacks, 2nd floor" --location "Aisle 4, shelf end" --order 10
```

It prints (a) an `INSERT` statement and (b) the `DEVICE_ID` / `API_KEY` lines for that node's `config.h`.
Run the `INSERT` in phpMyAdmin → **SQL**. The key is shown once; only its SHA-256 hash is stored.
`--order` sets the sort order *and* the chart colour, so give new rooms higher numbers than existing ones.

## 5. Test it

```bash
curl -i https://YOURSITE.colgate.domains/climate/ingest.php \
  -H "X-Api-Key: <API_KEY>" \
  -d '{"device_id":"stacks-2f","boot_id":1,"readings":[{"seq":1,"age_s":0,"temp_c":21.5,"rh":40.1}]}'
# expect:  {"ok":true,"stored":1,...}
```

Then reload the dashboard. To see it populated before hardware is ready, run `tools/simulate_device.py` with
`--backfill-hours 72`. (Delete the simulated device's rows afterwards, or use a throwaway device id.)

## 6. Keep the dashboard private (optional)

`api.php` has no login of its own. If readings shouldn't be public, protect the folder:
cPanel → **Directory Privacy** on `public_html/climate`. That also locks out the sensors, which authenticate with an API key
and cannot answer a password prompt, so exempt `ingest.php` by adding this to `public_html/climate/.htaccess`:

```apache
<Files "ingest.php">
    Require all granted
</Files>
```

**Test it after enabling:** the `curl` above should still return JSON (not a Basic-auth 401 page) while
loading `/climate/` in a private window should prompt for a password.

## 7. Day-to-day

| Task | How |
|---|---|
| Change how often a node reports | `UPDATE devices SET report_interval_s = 300 WHERE id = 'stacks-2f';` — applied at the node's next upload |
| Rename / reorder a room | `UPDATE devices SET name = '...', sort_order = 15 WHERE id = '...';` |
| Retire a node | `UPDATE devices SET active = 0 WHERE id = '...';` (history is kept; it disappears from the dashboard) |
| Rotate a key | Run `make_device.py` again with the same `--id`, then `UPDATE devices SET api_key_hash = '<new hash>' WHERE id = '...';` and reflash the node |
| Export data | Dashboard → **Download CSV** (uses the selected range), or `api.php?action=csv&hours=8760` |
| Backups | cPanel → **Backup** includes MySQL; or export `readings` from phpMyAdmin periodically |

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `server not configured` (HTTP 500) | No config file found at `~/climate-config.php` or `climate/config.php` |
| `server error` (HTTP 500) | Check the account's PHP error log in cPanel → **Errors**; usually wrong DB credentials or the schema not imported |
| `HTTPS required` (HTTP 400) | Request came in over plain HTTP, or your host doesn't pass the HTTPS flag to PHP (set `'require_https' => false` only if you've confirmed TLS is in front of it) |
| `unauthorized` (HTTP 401) | Wrong `device_id` or API key, device inactive, or Directory Privacy is intercepting `ingest.php` (step 6) |
| Dashboard shows a device as *Late* or *Offline* | Late = silent for 2.5–6 expected upload intervals, offline = longer. Check power and Wi-Fi |
