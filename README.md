Emucoach MoP Website — README & Fresh Install Guide

This README explains how to deploy the website on a fresh Emucoach Mists of Pandaria (5.4.8) server by copying it directly into your web root (htdocs/), replacing the default XAMPP content. It covers prerequisites, database prep, configuration, optional features (playtime rewards), and troubleshooting.

Check out my project website
 while it is online.

What’s included (high level)
htdocs/
  assets/                  CSS
  config.php               Main config (DB + SOAP + helpers)
  cron/                    award_playtime.php + log
  downloads/               (you place client files here)
  images/                  icons (races/classes/genders; optional)
  includes/                header/footer
  index.php                News feed (from auth.news)
  pages/
    admin.php + admin/*    Admin UI (settings, shop, tools, logs)
    login.php, register.php, logout.php
    dashboard.php, status.php
    download_and_connect.php
    shop.php               Web shop (uses auth.shop_* / account.cash)


Important: The code expects Trinity/Emucoach-style databases named auth, characters, and world. If your repack uses different names (e.g., mop_auth, mop_characters, mop_world), adjust config.php accordingly.

1) Prerequisites

A working Emucoach MoP 5.4.8 core (worldserver + authserver) with MySQL/MariaDB.

PHP 7.4+ with extensions: mysqli (default), soap (enable in php.ini if you plan to use SOAP delivery/mail), and openssl.

A web server (recommended: XAMPP on Windows; or Apache/Nginx on Linux).

Firewall allows where applicable:

3724 (auth) and your worldserver realm port(s)

7878 (SOAP), if you enable SOAP

2) Deploy the website (direct-to-root)

Open your web root:

Windows (XAMPP): C:\xampp\htdocs\

Linux (Apache): /var/www/html/

Remove or archive the default files in htdocs/ (XAMPP splash page, dashboard, etc.).

Extract the contents of the ZIP directly into htdocs/.
After extraction, you should have C:\xampp\htdocs\index.php, not C:\xampp\htdocs\wowsite\index.php.

Ensure the webserver user can read the files, and that htdocs/cron/award_debug.log is writable (the cron script overwrites the file per run).

(Optional) Put client files in htdocs/downloads/ (used by Download & Install page).

3) Configure databases & SOAP

Open htdocs/config.php and set:

$host = "localhost";    // DB host
$user = "root";         // DB user
$pass = "ascent";       // DB password
$auth_db = "auth";      // or your auth DB name
$characters_db = "characters"; // characters DB name
// world DB defaults to "world"; change if needed


If you will use SOAP (in-game deliveries/admin tools):

$soap_host = "127.0.0.1"; // worldserver SOAP host
$soap_port = 7878;        // matches worldserver.conf
$soap_user = "GMUSER";    // GM account username
$soap_pass = "GMPASS";    // GM account password


In worldserver.conf enable SOAP (examples):

SOAP.Enabled = 1
SOAP.IP = 0.0.0.0
SOAP.Port = 7878


Grant GM permissions (Trinity-style):
account set gmlevel GMUSER 3 -1

4) Database preparation (SQL you’ll run once)

The site creates some tables automatically, but a few must be created or altered first. Run these against your auth DB unless stated otherwise.

4.1 Currency column on accounts (required)

The shop uses an account balance called cash.

ALTER TABLE `account`
  ADD COLUMN `cash` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `email`;

4.2 Settings & logs

The Admin UI will create site_settings and activity_log if they don’t exist, but it’s safe to create them up front:

CREATE TABLE IF NOT EXISTS `site_settings` (
  `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(32) NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `details` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

4.3 Login logs (used by pages/login.php)
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(32) NOT NULL,
  `ip` VARCHAR(64) NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `result` VARCHAR(16) NOT NULL,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

4.4 Transaction history (used by shop purchase logs)
CREATE TABLE IF NOT EXISTS `pay_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT UNSIGNED NOT NULL,
  `orderNo` VARCHAR(64) NOT NULL,
  `synType` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cpparam` VARCHAR(255) DEFAULT NULL,
  `username` VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

4.5 Web shop tables (created on demand by Admin ▸ Shop Items)
CREATE TABLE IF NOT EXISTS `shop_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shop_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_entry` INT NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stack` INT NOT NULL DEFAULT 1,
  `category_id` INT DEFAULT NULL,
  CONSTRAINT `fk_shop_category` FOREIGN KEY (`category_id`)
    REFERENCES `shop_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

4.6 Playtime tracker (auto-created by cron; safe to create now)
CREATE TABLE IF NOT EXISTS `playtime_rewards` (
  `account_id` INT UNSIGNED PRIMARY KEY,
  `last_seen_online_at` TIMESTAMP NULL DEFAULT NULL,
  `last_xp` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_level` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_map` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

4.7 Realm address (used by Download & Connect page)

Make sure the auth DB has your realm address so the site can auto-detect it:

UPDATE `realmlist` SET `address` = 'YOUR.SERVER.IP.OR.HOST' LIMIT 1;


Characters/world schemas: Standard Emucoach/Trinity tables are expected (e.g., characters.characters with columns guid, account, name, online, level, totalXP, map; characters.mail, characters.item_instance, characters.mail_items, etc.).

5) First run

Browse to http://localhost/.

Use Register to create a player account, or use an existing one from your DB.

Login. If your account has GM rights (account_access gmlevel ≥ 3), you’ll see the Admin link.

In Admin ▸ Server Settings, add or edit keys used by the site/cron (examples):

soap_enabled → 1 (if you want SOAP for in-game mail)

interval_minutes → 10

coins_per_interval → 1

min_minutes → 1

online_per_run_cap → 5

require_activity → 0 or 1

Optional SOAP keys used by the cron/email: soap_host, soap_port, soap_user, soap_pass, soap_from, soap_subject, soap_body_tpl

In Admin ▸ Shop Items, create categories and items (item IDs are MoP itemEntry IDs). Prices deduct from account.cash.

Test delivery via Admin ▸ Tools ▸ SOAP Tester. Example commands:

server info

account list

send mail <CharName> "Subject" "Body" 6948:1 (Hearthstone example)

6) Playtime rewards (cron)

The cron awards coins for time online using characters.online, configurable in Admin ▸ Playtime Rewards (settings stored in auth.site_settings).

Windows (Task Scheduler)

Program: C:\xampp\php\php.exe

Args: C:\xampp\htdocs\cron\award_playtime.php --verbose

Start in: C:\xampp\htdocs\cron

Trigger: Every 5 minutes (recommended)

Linux (crontab)
*/5 * * * * php /var/www/html/cron/award_playtime.php --verbose >> /var/log/wowsite_awards.log 2>&1


The script writes a fresh cron/award_debug.log each run. It also auto-creates auth.playtime_rewards if missing.

Anti-AFK (optional): enable require_activity in settings. The script uses totalXP, level, and map deltas to detect activity.

SOAP vs DB-mail: If SOAP is enabled, awards are optionally mailed in-game. If SOAP is disabled or fails, the delivery helper can fall back to writing a mail directly into the characters DB (mail, item_instance, mail_items)—character must be offline.

7) Downloads & checksums (optional)

Place client archives in htdocs/downloads/. The Download & Install page auto-detects file size and an optional SHA-256 sidecar file named like <filename>.sha256.

Create a checksum

Windows (PowerShell or CMD):

CertUtil -hashfile "C:\xampp\htdocs\downloads\World of Warcraft 5.4.8.rar" SHA256 > "C:\xampp\htdocs\downloads\World of Warcraft 5.4.8.rar.sha256"


Linux/macOS:

sha256sum "/var/www/html/downloads/World of Warcraft 5.4.8.rar" > \
  "/var/www/html/downloads/World of Warcraft 5.4.8.rar.sha256"


Filenames with spaces are fine—just quote the paths. The sidecar file must end with .rar.sha256 (match your exact archive name + .sha256).

8) Troubleshooting

DB connection error (target machine actively refused it)

MySQL/MariaDB not running, wrong host/port, or wrong credentials in config.php.

Admin link doesn’t appear

Your account needs gmlevel ≥ 3 in auth.account_access.

SOAP: “disabled” or timeouts

Set soap_enabled = 1 in Server Settings; verify worldserver SOAP settings; make sure php_soap is enabled; firewall permits port 7878.

Deliveries not arriving

If using SOAP, test with Admin ▸ Tools ▸ SOAP Tester. For DB-mail fallback, ensure the standard Trinity tables exist and the character is offline when writing mail.

Download & Connect shows 127.0.0.1

Update auth.realmlist.address with your public IP or hostname.

9) Security & hardening (quick notes)

Keep config.php outside public git or secure it properly.

Restrict Admin access and rely on in-app GM check (account_access).

If you expose SOAP publicly, IP-restrict access in firewall/reverse proxy.

Consider HTTPS on your web host.

10) File-by-file pointers

config.php — DB connections, SOAP config, delivery helpers, race/class/gender maps, shop helpers.

pages/admin.php — Admin shell; ensures site_settings and activity_log exist.

pages/admin/server_setting.php — UI to add/update keys in site_settings.

pages/admin/shop_items.php — Manages shop_categories/shop_items (creates tables if missing).

pages/admin/tools.php — Links for SOAP tester and the playtime cron.

pages/admin/soap.php — SOAP test page.

cron/award_playtime.php — Awards coins; uses site_settings; creates playtime_rewards if missing.

pages/shop.php — Web shop, deducts from account.cash, delivers via SOAP or DB-mail.

pages/download_and_connect.php — Lists files in downloads/ and shows checksum; auto-reads realm IP from auth.realmlist.

11) Quick sanity checklist

 Default htdocs/ replaced with the site files

 config.php DB creds correct

 auth.account.cash exists and non-negative

 site_settings, activity_log, login_logs, pay_history present

 SOAP enabled and tested (optional)

 Playtime cron scheduled (optional)

 downloads/ populated and checksums added (optional)
