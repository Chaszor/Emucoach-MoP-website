Emucoach-MoP-website — README & Installation Guide

This README explains how to deploy the wowsite website for an Emucoach Mists of Pandaria (5.4.8) server.
The site is designed to be dropped directly into your web root (htdocs/), replacing the default XAMPP files.

1. What’s Included
htdocs/
  assets/                  CSS + UI assets
  config.php               Main config (DB + SOAP + helpers)
  cron/                    award_playtime.php + logs
  downloads/               (place client files here)
  images/                  icons (races, classes, genders, etc.)
  includes/                header/footer templates
  index.php                News feed (from auth.news)
  pages/
    admin.php + admin/*    Admin UI (settings, shop, tools, logs, SOAP tester)
    account.php            Account admin (ban, cash, gold, etc.)
    characters.php         Character management (offline-only edits)
    login.php, register.php, logout.php
    dashboard.php, status.php
    download_and_connect.php
    shop.php               Web shop (uses account.cash, SOAP or DB delivery)


Note: The site expects Trinity/Emucoach-style databases named auth, characters, and world. Update config.php if yours differ.

2. Prerequisites

A working Emucoach MoP 5.4.8 server (worldserver + authserver).

XAMPP on Windows (recommended) or Apache/Nginx on Linux.

PHP 7.4+ with:

mysqli (default)

soap (for SOAP delivery/admin tools)

openssl

MySQL/MariaDB running and accessible.

3. Installation (Drop-In Style)

Navigate to your XAMPP installation folder:

C:\xampp\htdocs\


Delete or move the default htdocs/ files (index.php, dashboard, etc.).

Extract the contents of the website ZIP directly into htdocs/.

After extraction, you should see:

C:\xampp\htdocs\index.php
C:\xampp\htdocs\config.php
C:\xampp\htdocs\pages\


Not: C:\xampp\htdocs\wowsite\index.php

Ensure the webserver user can read these files, and that cron/award_debug.log is writable.

Open a browser and go to:

http://localhost/

4. Configuration (config.php)
Database
$host = "localhost";
$user = "root";
$pass = "ascent";
$auth_db = "auth";
$characters_db = "characters";
$world_db = "world";

SOAP (optional)
$soap_host = "127.0.0.1";
$soap_port = 7878;
$soap_user = "GMUSER";
$soap_pass = "GMPASS";


Enable SOAP in worldserver.conf:

SOAP.Enabled = 1
SOAP.IP = 0.0.0.0
SOAP.Port = 7878


Grant GM access:

account set gmlevel GMUSER 3 -1

5. Database Preparation

Before using the site, apply these changes to your auth DB:

Add cash column to accounts:

ALTER TABLE `account`
  ADD COLUMN `cash` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `email`;


Create required tables:

site_settings

activity_log

login_logs

pay_history

shop_categories, shop_items

playtime_rewards

(See full SQL schemas in documentation if needed.)

Update your realm address:

UPDATE `realmlist` SET address = 'YOUR.SERVER.IP' LIMIT 1;

6. Features

Admin Panel – manage settings, shop, accounts, characters, and logs.

Shop System – uses account.cash and delivers via SOAP or DB mail.

Playtime Rewards – cron-based system that awards coins for time online.

Download & Connect – client download links + checksum verification.

Server Status – real-time realm status and auto-detection from DB.

7. Cron Setup
Windows

Task Scheduler example:

Program: C:\xampp\php\php.exe

Args: C:\xampp\htdocs\cron\award_playtime.php --verbose

Run every 5 minutes.

Linux
*/5 * * * * php /var/www/html/cron/award_playtime.php --verbose >> /var/log/wowsite_awards.log 2>&1

8. Troubleshooting

DB connection error → Check MySQL is running and config.php credentials are correct.

Admin link missing → Account needs GM level ≥ 3 (account_access).

SOAP not working → Enable in worldserver.conf and make sure php_soap is active.

Shop delivery fails → Character must be offline if DB delivery is used.

Status page shows 127.0.0.1 → Update realmlist.address to your public IP.

9. Security Notes

Keep config.php private.

Restrict Admin access to GM accounts.

Use HTTPS if public-facing.

Limit SOAP access by IP if exposed.

10. Quick Checklist

 Default XAMPP htdocs/ replaced with site files

 Database credentials in config.php updated

 auth.account.cash column exists

 Required tables created (site_settings, shop_*, etc.)

 Realm address updated in realmlist

 Cron job set up for playtime rewards

Do you want me to also write out all the SQL table creation statements inline here so you have a single drop-in README (no need to cross-reference another file), or should I keep them summarized like this?