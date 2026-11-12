# Paxcounter HTTP backend and dashboard

PHP/MySQL backend for the WiFi/HTTP upload of this firmware (`src/paxhttp.cpp`), plus a password-protected web dashboard.

| File | Purpose |
|---|---|
| `ingest.php` | Receives counts, seen devices and nearby WiFi networks from the board (POST, `X-Api-Key`) |
| `index.php` | Dashboard (login, charts, device groups, networks) |
| `api.php` | Read-only JSON API for the dashboard (requires login) |
| `lib.php` | Shared session and DB helpers |
| `schema.sql` | MySQL/MariaDB tables |
| `config.sample.php` | Template for `config.php` |
| `test.html` | Static page to check that the host is reachable |

## Setup

1. Create the tables: import `schema.sql` into your database.
2. Copy `config.sample.php` to `config.php` and fill in the DB credentials, `API_KEY` and `DASHBOARD_PASSWORD_HASH`:
   ```sh
   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
   ```
   `config.php` is in `.gitignore`. Never commit it.
3. Upload all files to a directory on a PHP 8 + Apache host. `.htaccess` blocks web access to `config.php`, `lib.php` and `schema.sql`.
4. Firmware: copy `shared/paxcounter_orig.conf` to `shared/paxcounter.conf` (also ignored by Git), then set `HTTP_WIFI_SSID`, `HTTP_WIFI_PASS`, `HTTP_URL` and `HTTP_APIKEY` (same value as `API_KEY`). Enable `HAS_HTTP` in your board's HAL file. After changing `paxcounter.conf`, run `pio run -t clean` before building.

## Privacy

With `HTTP_DEVICES`, every MAC address the paxcounter sees is stored. That is personal data (GDPR / Swiss DSG). Set `DEVICE_RETENTION_DAYS` in `config.php`, and only operate the device where you are allowed to. `HTTP_NETWORKS` records only access point names from beacons, not the networks phones are searching for.
