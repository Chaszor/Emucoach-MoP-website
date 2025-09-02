# New Server Install Guide — WoW Site + Playtime Rewards (Windows + XAMPP)
_Last updated: 2025-09-01_

This guide walks you through bringing a **fresh Windows host** online with:
- Apache/PHP/MySQL (XAMPP)
- Your PHP website from `wowsite.zip`
- Playtime coin awards via `cron/award_playtime.php`
- Optional Admin Panel + SOAP delivery
- Download page wiring (for distributing clients)

If you're deploying on Linux, the same concepts apply—swap XAMPP for **Apache/Nginx + PHP + MariaDB** and adjust paths.

---

## 1) Prerequisites

**OS:** Windows 10/11 (Run installers _as Administrator_).  
**Downloads:**  
- XAMPP (PHP 8.x is fine)  
- `wowsite.zip` (the site bundle you provided)  
- Your MoP/Trinity/EmuCoach server binaries & databases

**Open ports on the firewall/router (adjust to your core):**
- **80/443 TCP** — website (HTTP/HTTPS)
- **3724 TCP** — authserver/logon (typical)
- **8085 TCP** — worldserver (typical)
- **7878 TCP** — SOAP (only if you enable in-game delivery)

> Exact game ports can vary by repack/core—use the ones your world/auth server config specifies.

---

## 2) Install XAMPP

1. Install XAMPP to `C:\xampp`.
2. Launch **XAMPP Control Panel** → start **Apache** and **MySQL**.
3. Open **`C:\xampp\php\php.ini`** and ensure these extensions are **enabled** (remove `;` if present):
   ```ini
   extension=mysqli
   extension=soap
   extension=curl
   extension=openssl
   ```
4. Restart **Apache** from the XAMPP Control Panel.

> PHP `soap` is required for the optional in‑game mail delivery.

---

## 3) Database Setup

You can reuse the **auth** and **characters** databases from your core/repack, or create empty ones and point at the existing MySQL on the game host.

### 3.1 Create databases (if needed)

Open **phpMyAdmin** at http://localhost/phpmyadmin and create:
- `auth`
- `characters`

Create a MySQL user (optional but recommended):
- user: `webuser`
- password: strong unique password
- grant needed rights on `auth` and `characters`

### 3.2 Import Admin Panel schema

Import this file into **`auth`**:
- `/admin/schema.sql`

This creates the `admin_settings` table used by the admin panel.

### 3.3 Create the `site_settings` table (for realmlist & toggles)

Your site expects a simple key/value table named `site_settings` in the **`auth`** DB. Create it once:

```sql
CREATE TABLE IF NOT EXISTS site_settings (
  `key`   VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recommended defaults
INSERT INTO site_settings (`key`,`value`) VALUES
('realmlist', 'set realmlist YOUR.IP.OR.DOMAIN'),
('interval_minutes', '10'),          -- 1 coin / 10 minutes
('coins_per_interval', '1'),
('require_activity', '0'),
('min_seconds_per_char', '900'),     -- ignore < 15m characters
('soap_enabled', '1')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);
```

> You can edit these from **`/pages/admin.php`** once logged in.

---

## 4) Deploy the Website

1. Extract `wowsite.zip` to **`C:\xampp\htdocs\wowsite\`**.
   Your tree should look roughly like:
   ```text
   C:\xampp\htdocs\wowsite\
     config.php
     index.php
     includes\
     pages\
     cron\
     admin\
     assets\
   ```

2. Edit **`C:\xampp\htdocs\wowsite\config.php`**:
   ```php
   // DBs
   $host = "localhost";
   $user = "webuser";       // or root for local dev
   $pass = "YOUR_DB_PASS";
   $auth_db = "auth";
   $characters_db = "characters";

   // SOAP (optional in‑game mail delivery)
   $soap_enabled = true;
   $soap_host   = "127.0.0.1";
   $soap_port   = 7878;
   $soap_user   = "GM_ACCOUNT_NAME";
   $soap_pass   = "GM_ACCOUNT_PASSWORD";
   ```

3. Browse to **http://localhost/wowsite/** and verify the home page renders.

---

## 5) Download Page Wiring

The **Connect/Downloads** page lives at `/pages/download_and_connect.php` and automatically reads
`realmlist` from `site_settings`. To surface downloads:

1. Create a folder: **`C:\xampp\htdocs\wowsite\downloads\`**
2. Drop your files there (e.g., `wotlk_335a_win.zip`, `Config.wtf`, etc.).
3. Optionally add checksum files next to each artifact:
   - `file.zip.sha256` or `file.zip.sha1`

The page is already pointed at **`../downloads`** relative to `/pages/` and will show a friendly
warning if the folder is missing.

---

## 6) Admin Panel (optional)

- URL: `http://localhost/wowsite/pages/admin.php`  
- Auth: The panel piggybacks on your site’s session and uses the game DB to confirm GM access
  (`account_access`). Make sure your logged‑in account has GM rights if the panel enforces admin checks.
- From the panel you can adjust:
  - **Coins per interval** and **interval minutes**
  - **Anti‑AFK** requirement
  - **Min seconds per character**
  - **SOAP enabled**

> The panel uses simple tables: `site_settings` (guide above) and `admin_settings` (from `/admin/schema.sql`).

---

## 7) Playtime Awards Cron

The script is `C:\xampp\htdocs\wowsite\cron\award_playtime.php`. It:
- Tallies `characters.totaltime` per account
- Tracks **last_totaltime** and **last_seen_online_at** (in `auth.playtime_rewards`, auto‑created)
- Adds coins to `auth.account.cash`
- Optional **online credit** (per-run cap) and AFK detection

### 7.1 Test run manually

Open **Command Prompt** (Run as Administrator) and run:

```bat
"C:\xampp\php\php.exe" "C:\xampp\htdocs\wowsite\cron\award_playtime.php" --verbose --dry-run
```

Common flags:
- `--verbose`         show details
- `--dry-run`         compute without writing
- `--cap=10`          cap online minutes credited per run
- `--minutes=10`      treat delta as 10 minutes (for testing)

If you see DB auth/characters connection errors, recheck `config.php`.  
If you see **“This app can’t run on your PC”** or **Access Denied**, verify the path to `php.exe`
and run the shell **as Administrator**.

### 7.2 Schedule it (Task Scheduler)

1. Open **Task Scheduler** → **Create Task…**
2. **General**: Run whether user is logged on or not; Run with highest privileges.
3. **Triggers**: New → Begin the task: On a schedule → **Daily, repeat every 5 minutes** for a day (or your cadence).
4. **Actions**: Start a program:
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `"C:\xampp\htdocs\wowsite\cron\award_playtime.php" --verbose`
5. Save; enter credentials if prompted.

### 7.3 Resetting/Reseeding

To wipe coins and playtime state cleanly (use with care):

```sql
-- Reset coin balances
UPDATE auth.account SET cash = 0 WHERE id > 0;

-- Reset the tracker the script uses
TRUNCATE TABLE auth.playtime_rewards;
```

> If phpMyAdmin blocks the update with **Error 1175 (safe update mode)**, either add a key‑based WHERE
> (`WHERE id BETWEEN 1 AND 999999`) or temporarily disable safe updates in phpMyAdmin preferences.

---

## 8) Enable SOAP on the Game Server (optional)

In your `worldserver.conf` (or equivalent):
- Enable SOAP
- Bind to the host that the web server can reach (often `127.0.0.1:7878` on the same box)
- Create a GM account with SOAP permission and set those creds in `config.php`

Test connectivity from PHP:

```php
$client = new SoapClient(null, [
  'location' => "http://127.0.0.1:7878/",
  'uri'      => "urn:TC",
  'login'    => "GM_ACCOUNT_NAME",
  'password' => "GM_ACCOUNT_PASSWORD",
]);
```

If this fails, check firewall rules and that the worldserver SOAP listener is up.

---

## 9) Production Hardening

- Change **all default passwords** (MySQL, admin logins, SOAP).
- In `php.ini`: `display_errors=Off`, `expose_php=Off`.
- Force HTTPS on your domain; if fronted by **Nginx Proxy Manager**:
  - New Proxy Host → Domain → Forward to your web host (e.g., `http://LAN_IP:80`)
  - Enable **Websockets** (if needed) and **Force SSL**; request a Let’s Encrypt certificate.
- Lock down **/pages/admin.php** to trusted IPs or require login.
- Keep regular **database backups** (see below).

---

## 10) Backups

**Database (daily rotating 7 copies):** create `backup-db.bat`:
```bat
@echo off
set DATESTAMP=%DATE:~-4%%DATE:~4,2%%DATE:~7,2%
"C:\xampp\mysql\bin\mysqldump.exe" -u webuser -pYOUR_DB_PASS auth > C:\backups\auth_%DATESTAMP%.sql
"C:\xampp\mysql\bin\mysqldump.exe" -u webuser -pYOUR_DB_PASS characters > C:\backups\characters_%DATESTAMP%.sql
```

Schedule it daily in Task Scheduler. Use an encrypted drive or offsite sync for safety.

---

## 11) Troubleshooting Quick Hits

- **Cannot update `auth.account.cash`:**
  - Ensure the column exists in your core and your MySQL user has `UPDATE` on `auth.account`.
  - Error 1175 → disable safe updates or use a key-based WHERE.

- **“Unknown column 'item_entry'” (shop or custom scripts):**
  - Align your shop schema with your core’s item table (`item_template` vs `item_instance` etc.).

- **Admin page says “You must be logged in.”**
  - Confirm your site’s session/login is working and the account is GM if required.

- **Downloads page shows missing folder warning:**
  - Create `C:\xampp\htdocs\wowsite\downloads\` and place files. Optionally include `.sha256` files.

---

## 12) Go‑Live Checklist

- [ ] `config.php` has correct DB + SOAP credentials
- [ ] `site_settings` seeded (realmlist, interval/coins)
- [ ] `/downloads` populated
- [ ] `admin/schema.sql` imported to `auth`
- [ ] Cron scheduled and test run successful
- [ ] SSL + reverse proxy (if applicable)
- [ ] Backups scheduled

---

### Appendix A — Minimal SQL You May Need Again

```sql
-- site_settings (key/value)
CREATE TABLE IF NOT EXISTS site_settings (
  `key`   VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- admin_settings (from /admin/schema.sql)
CREATE TABLE IF NOT EXISTS admin_settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- playtime tracker (auto-created by the cron in auth DB, included here for reference)
CREATE TABLE IF NOT EXISTS playtime_rewards (
  account_id            INT UNSIGNED PRIMARY KEY,
  last_totaltime        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_award_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_online_at   TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Good luck, and enjoy the smooth spin‑up!
