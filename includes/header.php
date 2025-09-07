<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Ensure we have a DB connection for the GM check.
 * If header.php is included before config.php somewhere, this makes it robust.
 */
if (!isset($auth_conn) || !($auth_conn instanceof mysqli)) {
    $cfg = __DIR__ . '/config.php';
    if (is_file($cfg)) require_once $cfg;
}

/** ---------- Load Server Name from realmlist ---------- */
$server_name = "Epic WoW"; // fallback if DB fails
if (isset($auth_conn) && $auth_conn instanceof mysqli) {
    if ($res = $auth_conn->query("SELECT name FROM realmlist WHERE id=1 LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            $server_name = $row['name'];
        }
        $res->close();
    }
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
    <title><?php echo htmlspecialchars($server_name); ?></title>
    <link rel="stylesheet" href="/assets/style.css">
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
        <h1><?php echo htmlspecialchars($server_name); ?></h1>
        <nav>
            <a href="/index.php">Home</a>
            <?php if (!isset($_SESSION["username"])): ?>
                <a href="/pages/register.php">Register</a>
                <a href="/pages/login.php">Login</a>
            <?php else: ?>
                <a href="/pages/dashboard.php">Dashboard</a>
                <a href="/pages/download_and_connect.php">Download & Install</a>
                <a href="/pages/shop.php">Shop</a>

                <?php if ($isGM): ?>
                  <!-- Visible only to GM accounts -->
                  <a href="/pages/admin.php">Admin</a>
                <?php endif; ?>
                <a href="/pages/status.php">Server Status</a>
                <a href="/pages/logout.php">Logout</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>
