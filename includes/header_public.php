<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION["username"], $_SESSION["account_id"]);
?>
<!DOCTYPE html>
<html>
<head>
    <title>My WoW Server</title>
    <link rel="stylesheet" href="/wowsite/assets/style.css">
</head>
<body>
    <header>
        <h1>Epic WoW</h1>
        <nav>
        <a href="/wowsite/index.php">Home</a>
        <?php if (!$isLoggedIn): ?>
            <a href="/wowsite/pages/register.php">Register</a>
            <a href="/wowsite/pages/login.php">Login</a>
            <a href="/wowsite/pages/status.php">Server Status</a>
        <?php else: ?>
            <a href="/wowsite/news.php">News</a>
            <a href="/wowsite/pages/dashboard.php">Dashboard</a>
            <a href="/wowsite/pages/download_and_connect.php">Download & Install</a>
            <a href="/wowsite/pages/shop.php">Shop</a>
            <a href="/wowsite/pages/status.php">Server Status</a>
            <a href="/wowsite/pages/logout.php">Logout</a>
        <?php endif; ?>
        </nav>
    </header>
    <main>
