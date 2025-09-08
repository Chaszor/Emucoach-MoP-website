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
    if ($res = @$auth_conn->query("SELECT name FROM realmlist WHERE id=1 LIMIT 1")) {
        if ($row = $res->fetch_assoc()) $server_name = $row['name'];
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

/** ---------- Active link helper ---------- */
function nav_active(string $path): string {
    $req = $_SERVER['REQUEST_URI'] ?? '';
    if ($path === '/index.php' && ($req === '/' || $req === '/index.php')) return ' active';
    return str_starts_with($req, $path) ? ' active' : '';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Mobile browser theming -->
    <meta name="theme-color" content="#0f1118">
    <meta name="color-scheme" content="dark light">

    <!-- Preconnect for Wowhead icons (faster mobile load) -->
    <link rel="preconnect" href="https://wow.zamimg.com" crossorigin>

    <!-- Site-wide responsive helpers -->
    <link rel="stylesheet" href="/assets/responsive.css">

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
      /* (kept) give the icon-only link a box so it’s visible even with empty text */
      .wh-iconbox { display:inline-block; width:36px; height:36px; line-height:36px; text-align:center; }
    </style>
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <!-- Brand / Server name -->
      <a class="brand" href="/index.php" aria-label="Home">
        <span class="brand-title"><?php echo htmlspecialchars($server_name); ?></span>
      </a>

      <!-- Mobile menu toggle -->
      <button class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="siteNav" aria-label="Toggle menu">
        <span class="nav-toggle-bar"></span>
        <span class="nav-toggle-bar"></span>
        <span class="nav-toggle-bar"></span>
      </button>

      <!-- Primary navigation -->
      <nav class="site-nav" id="siteNav" role="navigation">
        <ul class="nav-list">
          <li><a href="/index.php" class="nav-link<?php echo nav_active('/index.php'); ?>">Home</a></li>

          <?php if (!$isLoggedIn): ?>
            <li><a href="/pages/register.php" class="nav-link<?php echo nav_active('/pages/register.php'); ?>">Register</a></li>
            <li><a href="/pages/login.php" class="nav-link<?php echo nav_active('/pages/login.php'); ?>">Login</a></li>
          <?php else: ?>
            <li><a href="/pages/dashboard.php" class="nav-link<?php echo nav_active('/pages/dashboard.php'); ?>">Dashboard</a></li>
            <li><a href="/pages/download_and_connect.php" class="nav-link<?php echo nav_active('/pages/download_and_connect.php'); ?>">Download &amp; Install</a></li>
            <li><a href="/pages/shop.php" class="nav-link<?php echo nav_active('/pages/shop.php'); ?>">Shop</a></li>
            <?php if ($isGM): ?>
              <li><a href="/pages/admin.php" class="nav-link nav-gm<?php echo nav_active('/pages/admin.php'); ?>">Admin</a></li>
            <?php endif; ?>
            <li><a href="/pages/status.php" class="nav-link<?php echo nav_active('/pages/status.php'); ?>">Server Status</a></li>
            <li class="nav-divider" aria-hidden="true"></li>
            <li><a href="/pages/logout.php" class="nav-link">Logout</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
    
  </header>

  <main class="container">

  <!-- Small inline script to handle mobile menu; lives in header.php so it's enforced everywhere -->
  <script>
    (function () {
      var btn = document.getElementById('navToggle');
      var nav = document.getElementById('siteNav');
      if (!btn || !nav) return;

      function closeOnEsc(e){
        if (e.key === 'Escape'){
          nav.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
        }
      }

      btn.addEventListener('click', function(){
        var open = nav.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) document.addEventListener('keydown', closeOnEsc, { once: true });
      });
    })();
  </script>
<style>
:root{
  --hdr-bg: rgba(15, 17, 24, .85);
  --hdr-border: rgba(255,255,255,.08);
  --text: #e8ebf1;
  --muted: #a9b1c3;
  --accent: #4aa3ff;
  --accent-2: #84ffb5;
  --shadow: 0 6px 24px rgba(0,0,0,.25);
}

* { box-sizing: border-box; }

.site-header{
  position: sticky;
  top: 0;
  z-index: 1000;
  backdrop-filter: blur(10px);
  background: var(--hdr-bg);
  border-bottom: 1px solid var(--hdr-border);
}

.header-inner{
  max-width: 1100px;
  margin: 0 auto;
  padding: .75rem 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
}

.brand{
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  text-decoration: none;
}

.brand-title{
  color: var(--text);
  font-weight: 700;
  letter-spacing: .2px;
  font-size: clamp(1.05rem, 2.2vw, 1.25rem);
  line-height: 1;
  white-space: nowrap;
}

/* Primary nav */
.site-nav{
  display: flex;
  align-items: center;
}

.nav-list{
  list-style: none;
  display: flex;
  align-items: center;
  gap: .25rem;
  margin: 0;
  padding: 0;
}

.nav-link{
  display: inline-flex;
  align-items: center;
  padding: .55rem .75rem;
  border-radius: .6rem;
  color: var(--muted);
  text-decoration: none;
  line-height: 1;
  transition: color .2s ease, background-color .2s ease, transform .08s ease;
  outline: none;
}

.nav-link:hover,
.nav-link:focus{
  color: var(--text);
  background: rgba(255,255,255,.06)
}
</style>