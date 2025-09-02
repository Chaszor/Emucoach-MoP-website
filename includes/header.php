<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Ensure we have a DB connection for the GM check.
 * If header.php is included before config.php somewhere, this makes it robust.
 */
if (!isset($auth_conn) || !($auth_conn instanceof mysqli)) {
    $cfg = __DIR__ . '/../config.php';
    if (is_file($cfg)) require_once $cfg;
}

/** GM helper (Trinity/MaNGOS-like using account_access). Returns true if gmlevel >= $threshold */
function user_is_gm(?mysqli $auth_conn, int $account_id, int $threshold = 3): bool {
    if (!$auth_conn) return false;
    if (!$stmt = $auth_conn->prepare("SELECT COALESCE(MAX(gmlevel),0) FROM account_access WHERE id=?")) {
        return false;
    }
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $stmt->bind_result($gm);
    $stmt->fetch();
    $stmt->close();
    return ((int)$gm) >= $threshold;
}

$isLoggedIn = isset($_SESSION["username"], $_SESSION["account_id"]);
$isGM = $isLoggedIn ? user_is_gm($auth_conn ?? null, (int)$_SESSION["account_id"], 3) : false;
?>
<!DOCTYPE html>
<html>
<head>
    <title>My WoW Server</title>
    <link rel="stylesheet" href="/wowsite/assets/style.css">
<script>
  // Enable icons + tooltips and target the MoP Classic database
  var whTooltips = {
    colorLinks: true,
    iconizeLinks: true,
    renameLinks: true,
    domain: 'mop-classic',
    iconSize: 'medium'
  };
</script>
<script src="https://wow.zamimg.com/widgets/power.js"></script>
<style>
  /* give the icon-only link a box so it’s visible even with empty text */
  .wh-iconbox { display:inline-block; width:36px; height:36px; line-height:36px; text-align:center; }
</style>

</head>
<body>
    <header>
        <h1>Welcome to My WoW Private Server</h1>
        <nav>
        <a href="/wowsite/index.php">Home</a>
        <?php if (!isset($_SESSION["username"])): ?>
            <a href="/wowsite/pages/register.php">Register</a>
            <a href="/wowsite/pages/login.php">Login</a>
        <?php else: ?>
            <a href="/wowsite/pages/dashboard.php">Dashboard</a>
            <a href="/wowsite/pages/download_and_connect.php">Download & Install</a>
            <a href="/wowsite/pages/shop.php">Shop</a>

            <?php if ($isGM): ?>
              <!-- Visible only to GM accounts -->
              <a href="/wowsite/pages/admin.php">Admin</a>
            <?php endif; ?>

            <a href="/wowsite/pages/logout.php">Logout</a>
        <?php endif; ?>
        <a href="/wowsite/pages/status.php">Server Status</a>
    </nav>
    </header>
    <main>
