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

// --- helper: check GM/admin status via account_access (Trinity/MaNGOS-like) ---
function is_admin($auth_conn, $account_id) {
    if (!$stmt = $auth_conn->prepare("SELECT gmlevel FROM account_access WHERE id=? LIMIT 1")) {
        return false;
    }
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $stmt->bind_result($gm);
    $ok = ($stmt->fetch() && (int)$gm >= 3);
    $stmt->close();
    return $ok;
}

$account_id = (int)$_SESSION["account_id"];
$username   = $_SESSION["username"] ?? "unknown";

if (!is_admin($auth_conn, $account_id)) {
    echo "<p>Access denied. Admins only.</p>";
    require_once("../includes/footer.php");
    exit;
}

// --- ensure site_settings table exists ---
$auth_conn->query("
CREATE TABLE IF NOT EXISTS site_settings (
    `key`       VARCHAR(64) PRIMARY KEY,
    `value`     VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- ensure activity_log table exists ---
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

// --- helpers for site settings ---
function get_setting($auth_conn, $key, $default=null) {
    $stmt = $auth_conn->prepare("SELECT value FROM site_settings WHERE `key`=?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($v);
    $has = $stmt->fetch();
    $stmt->close();
    return $has ? $v : $default;
}
function set_setting($auth_conn, $key, $value) {
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

// --- helper for activity logging ---
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

// defaults aligned with cron/award_playtime.php
$defaults = [
  "interval_minutes"      => "10",
  "coins_per_interval"    => "1",
  "min_minutes"           => "1",
  "online_per_run_cap"    => "5",
  "require_activity"      => "0",
  "min_seconds_per_char"  => "900",
  "soap_enabled"          => "1",
];

// --- handle POST only for settings form ---
$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" 
    && isset($_POST['settings_action']) 
    && $_POST['settings_action'] === 'save_settings') {

    $interval   = max(1, (int)($_POST["interval_minutes"] ?? $defaults["interval_minutes"]));
    $coins      = max(0, (int)($_POST["coins_per_interval"] ?? $defaults["coins_per_interval"]));
    $minmins    = max(1, (int)($_POST["min_minutes"] ?? $defaults["min_minutes"]));
    $cap        = max(1, (int)($_POST["online_per_run_cap"] ?? $defaults["online_per_run_cap"]));
    $require    = isset($_POST["require_activity"]) ? 1 : 0;
    $minsecs    = max(0, (int)($_POST["min_seconds_per_char"] ?? $defaults["min_seconds_per_char"]));
    $soap       = isset($_POST["soap_enabled"]) ? 1 : 0;

    set_setting($auth_conn, "interval_minutes",      $interval);
    set_setting($auth_conn, "coins_per_interval",    $coins);
    set_setting($auth_conn, "min_minutes",           $minmins);
    set_setting($auth_conn, "online_per_run_cap",    $cap);
    set_setting($auth_conn, "require_activity",      $require);
    set_setting($auth_conn, "min_seconds_per_char",  $minsecs);
    set_setting($auth_conn, "soap_enabled",          $soap);

    $msg = "Settings saved.";
    log_activity($auth_conn, $account_id, $username, "Updated site settings");
}

// --- read current ---
$interval_minutes       = (int)get_setting($auth_conn, "interval_minutes",      $defaults["interval_minutes"]);
$coins_per_interval     = (int)get_setting($auth_conn, "coins_per_interval",    $defaults["coins_per_interval"]);
$min_minutes            = (int)get_setting($auth_conn, "min_minutes",           $defaults["min_minutes"]);
$online_per_run_cap     = (int)get_setting($auth_conn, "online_per_run_cap",    $defaults["online_per_run_cap"]);
$require_activity       = (int)get_setting($auth_conn, "require_activity",      $defaults["require_activity"]);
$min_seconds_per_char   = (int)get_setting($auth_conn, "min_seconds_per_char",  $defaults["min_seconds_per_char"]);
$soap_enabled           = (int)get_setting($auth_conn, "soap_enabled",          $defaults["soap_enabled"]);

// derived rates
$coins_per_minute = $interval_minutes > 0 ? ($coins_per_interval / $interval_minutes) : 0.0;
$coins_per_hour   = $interval_minutes > 0 ? ($coins_per_interval * (60 / $interval_minutes)) : 0.0;

// --- detect active tab (default = settings) ---
$tab = $_GET['tab'] ?? 'settings';
?>
<h2 style="text-align: center">Admin Panel</h2>
<?php if ($msg): ?><p style="color:green"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>

<nav class="admin-tabs">
  <a href="admin.php?tab=settings"   class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">Settings</a>
  <a href="admin.php?tab=shop"       class="<?php echo $tab === 'shop' ? 'active' : ''; ?>">Shop Items</a>
  <a href="admin.php?tab=tools"      class="<?php echo $tab === 'tools' ? 'active' : ''; ?>">Tools</a>
  <a href="admin.php?tab=account"    class="<?php echo $tab === 'account' ? 'active' : ''; ?>">Account</a>
  <a href="admin.php?tab=characters" class="<?php echo $tab === 'characters' ? 'active' : ''; ?>">Characters</a>
  <a href="admin.php?tab=logs"       class="<?php echo $tab === 'logs' ? 'active' : ''; ?>">Logs</a>
</nav>

<?php if ($tab === 'settings'): ?>
  <?php include("admin/playtime_awards.php"); ?>

  <section class="card">
    <h3>Notes</h3>
    <ul>
      <li>Settings are stored in <code>auth.site_settings</code> and read by <code>cron/award_playtime.php</code>.</li>
      <li><strong>Tip:</strong> Schedule the cron via Windows Task Scheduler (e.g. every 5 minutes).</li>
      <li>Use a GM account for SOAP if you enable in-game mail.</li>
    </ul>
  </section>

<?php elseif ($tab === 'shop'): ?>
  <?php include("admin/shop_items.php"); ?>

<?php elseif ($tab === 'tools'): ?>
  <section class="card">
    <h3>Quick Tools</h3>
    <ul>
      <li><a href="/wowsite/cron/award_playtime.php" target="_blank" rel="noopener">Run award script in browser</a></li>
      <li>CLI dry-run: <code>"C:\xampp\php\php.exe" "C:\xampp\htdocs\wowsite\cron\award_playtime.php" --dry-run --verbose</code></li>
    </ul>
  </section>

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

.admin-tabs {
  display: flex;
  justify-content: center;
  gap: 1rem;
  border-bottom: 2px solid #ccc;
  margin: 1rem 0;
  padding-bottom: .5rem;
}
.admin-tabs a {
  text-decoration: none;
  padding: .5rem 1rem;
  border-radius: 6px 6px 0 0;
  background: rgba(255,255,255,.04);
  color: #2e8b57;
  font-weight: bold;
}
.admin-tabs a:hover {
  background: rgba(255,255,255,.1);
}
.admin-tabs a.active {
  background: #2e8b57;
  border: 1px solid #ccc;
  border-bottom: 2px solid #fff;
  color: #000;
}
</style>

<?php require_once("../includes/footer.php"); ?>
