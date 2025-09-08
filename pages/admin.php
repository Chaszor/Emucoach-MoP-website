<?php
// pages/admin.php
// Admin panel with tab navigation for settings, shop items, tools, accounts, characters, and logs.

require_once("../config.php");
require_once("../includes/header.php");

if (!isset($_SESSION["username"]) || !isset($_SESSION["account_id"])) {
    echo "<p>You must be logged in.</p>";
    require_once("../includes/footer.php");
    exit;
}

$account_id = (int)$_SESSION["account_id"];
$username   = $_SESSION["username"] ?? "unknown";

if (!is_admin($auth_conn, $account_id)) {
    echo "<p>Access denied. Admins only.</p>";
    require_once("../includes/footer.php");
    exit;
}

/* ---------- Ensure tables exist ---------- */
$auth_conn->query("
CREATE TABLE IF NOT EXISTS site_settings (
    `key`        VARCHAR(64) PRIMARY KEY,
    `value`      VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$auth_conn->query("
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    action VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(account_id),
    INDEX(username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Helpers ---------- */
function get_setting(mysqli $auth_conn, string $key, $default = null) {
    $stmt = $auth_conn->prepare("SELECT value FROM site_settings WHERE `key`=?");
    if (!$stmt) return $default;
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($v);
    $has = $stmt->fetch();
    $stmt->close();
    return $has ? $v : $default;
}

function set_setting(mysqli $auth_conn, string $key, $value): void {
    $stmt = $auth_conn->prepare("
        INSERT INTO site_settings (`key`,`value`)
        VALUES (?,?)
        ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)
    ");
    $v = (string)$value;
    $stmt->bind_param("ss", $key, $v);
    $stmt->execute();
    $stmt->close();
}

function log_activity(mysqli $auth_conn, int $account_id, string $username, string $action): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $auth_conn->prepare("
        INSERT INTO activity_log (account_id, username, action, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $account_id, $username, $action, $ip);
    $stmt->execute();
    $stmt->close();
}

/* ---------- Defaults (aligned with award_playtime.php) ---------- */
$defaults = [
    "interval_minutes"       => "10",
    "coins_per_interval"     => "1",
    "min_minutes"            => "1",
    "online_per_run_cap"     => "5",
    "require_activity"       => "0",
    "min_seconds_per_char"   => "900",
    // New: per-feature toggle for playtime award SOAP mail only
    "playtime_mail_enabled"  => "0",
    // Global SOAP availability (read-only here; shown if needed)
    "soap_enabled"           => "0",
];

/* ---------- Handle POST (settings form only) ---------- */
$msg = "";
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['settings_action']) &&
    $_POST['settings_action'] === 'save_settings'
) {
    $interval   = max(1, (int)($_POST["interval_minutes"] ?? $defaults["interval_minutes"]));
    $coins      = max(0, (int)($_POST["coins_per_interval"] ?? $defaults["coins_per_interval"]));
    $minmins    = max(1, (int)($_POST["min_minutes"] ?? $defaults["min_minutes"]));
    $cap        = max(1, (int)($_POST["online_per_run_cap"] ?? $defaults["online_per_run_cap"]));
    $require    = isset($_POST["require_activity"]) ? 1 : 0;
    $minsecs    = max(0, (int)($_POST["min_seconds_per_char"] ?? $defaults["min_seconds_per_char"]));
    // Per-feature toggle (do NOT write soap_enabled here)
    $playtimeMail = isset($_POST["playtime_mail_enabled"]) ? 1 : 0;
    // Global SOAP availability is read-only here; do not change it
    $soapEnabled = isset($_POST["soap_enabled"]) ? 1 : 0; // read-only
    set_setting($auth_conn, "interval_minutes",       $interval);
    set_setting($auth_conn, "coins_per_interval",     $coins);
    set_setting($auth_conn, "min_minutes",            $minmins);
    set_setting($auth_conn, "online_per_run_cap",     $cap);
    set_setting($auth_conn, "require_activity",       $require);
    set_setting($auth_conn, "min_seconds_per_char",   $minsecs);
    set_setting($auth_conn, "playtime_mail_enabled",  $playtimeMail);
    set_setting($auth_conn, "soap_enabled",           $soapEnabled); // read-only
    $msg = "Settings saved.";
    log_activity($auth_conn, $account_id, $username, "Updated site settings");
}

/* ---------- Read current values ---------- */
$interval_minutes       = (int)get_setting($auth_conn, "interval_minutes",       $defaults["interval_minutes"]);
$coins_per_interval     = (int)get_setting($auth_conn, "coins_per_interval",     $defaults["coins_per_interval"]);
$min_minutes            = (int)get_setting($auth_conn, "min_minutes",            $defaults["min_minutes"]);
$online_per_run_cap     = (int)get_setting($auth_conn, "online_per_run_cap",     $defaults["online_per_run_cap"]);
$require_activity       = (int)get_setting($auth_conn, "require_activity",       $defaults["require_activity"]);
$min_seconds_per_char   = (int)get_setting($auth_conn, "min_seconds_per_char",   $defaults["min_seconds_per_char"]);
$playtime_mail_enabled  = (int)get_setting($auth_conn, "playtime_mail_enabled",  $defaults["playtime_mail_enabled"]);
$soap_enabled           = (int)get_setting($auth_conn, "soap_enabled",           $defaults["soap_enabled"]); // read-only

// Derived rates
$coins_per_minute = $interval_minutes > 0 ? ($coins_per_interval / $interval_minutes) : 0.0;
$coins_per_hour   = $interval_minutes > 0 ? ($coins_per_interval * (60 / $interval_minutes)) : 0.0;

/* ---------- Active tab ---------- */
$tab = $_GET['tab'] ?? 'realmlist';
?>
<h2 style="text-align: center">Admin Panel</h2>
<?php if ($msg): ?><p style="color:green"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
<div style="margin:10px 0; display:flex; gap:8px; flex-wrap:wrap; justify-content:center;">
  <a href="admin.php?tab=realmlist"  class="tab-link<?php echo $tab === 'realmlist' ? ' active' : ''; ?>">Realmlist</a>
  <a href="admin.php?tab=Coin Rewards" class="tab-link<?php echo $tab === 'Coin Rewards' ? ' active' : ''; ?>">Coin Rewards</a>
  <a href="admin.php?tab=news"       class="tab-link<?php echo $tab === 'news' ? ' active' : ''; ?>">News</a>
  <a href="admin.php?tab=shop"       class="tab-link<?php echo $tab === 'shop' ? ' active' : ''; ?>">Shop Items</a>
  <a href="admin.php?tab=tools"      class="tab-link<?php echo $tab === 'tools' ? ' active' : ''; ?>">Tools</a>
  <a href="admin.php?tab=account"    class="tab-link<?php echo $tab === 'account' ? ' active' : ''; ?>">Account</a>
  <a href="admin.php?tab=characters" class="tab-link<?php echo $tab === 'characters' ? ' active' : ''; ?>">Characters</a>
  <a href="admin.php?tab=logs"       class="tab-link<?php echo $tab === 'logs' ? ' active' : ''; ?>">Logs</a>
</div>


<?php if ($tab === 'Coin Rewards'): ?>
  <?php
    // The included file can use:
    // $interval_minutes, $coins_per_interval, $min_minutes,
    // $online_per_run_cap, $require_activity, $min_seconds_per_char,
    // $playtime_mail_enabled, $soap_enabled (read-only),
    // $coins_per_minute, $coins_per_hour
    include("admin/playtime_awards.php");
  ?>
<?php elseif ($tab === 'realmlist'): ?>
  <?php include("admin/realmlist.php"); ?>

<?php elseif ($tab === 'shop'): ?>
  <?php include("admin/shop_items.php"); ?>

<?php elseif ($tab === 'tools'): ?>
  <?php include("admin/tools.php"); ?>

<?php elseif ($tab === 'news'): ?>
  <?php include("admin/news.php"); ?>

<?php elseif ($tab === 'account'): ?>
  <?php include("admin/account.php"); ?>

<?php elseif ($tab === 'characters'): ?>
  <?php include("admin/characters.php"); ?>

<?php elseif ($tab === 'logs'): ?>
  <?php include("admin/logs.php"); ?>
<?php endif; ?>

<style>
.card { border:1px solid #ddd; padding:1rem; margin:1rem 0; border-radius:12px; }
label { display:block; margin:.3rem 0; }

/* Match shop.php tab styling */
.tab-link {
  text-decoration: none;
  transition: all 0.2s ease;
  padding: .4rem .7rem;
  border-radius: 10px;
  border: 1px solid var(--border, #3a3a3a);
  background: rgba(255,255,255,.04);
  cursor: pointer;
  color: #2e8b57;   /* sea green text */
  display: inline-block;
}
.tab-link:hover {
  background: #f5f5f5;
  color: #226644;   /* darker green on hover */
}
.tab-link.active {
  font-weight: bold;
  border-color: #2e6da4;
  box-shadow: 0 2px 6px rgba(0,0,0,0.25);
  background: #2e8b57;
  color: #000;
}
</style>

<?php require_once("../includes/footer.php"); ?>
