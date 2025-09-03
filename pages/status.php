<?php
include("../config.php");
include("../includes/header.php");

$server = "97.85.160.111"; // your WoW server IP
$port = 3724;          // auth server port (usually 3724)

$online = @fsockopen($server, $port, $errno, $errstr, 2);

if ($online) {
    echo "<h2 style='text-align: center'>Server Status</h2>";
    echo "<p style='color:green' class='hero'>Server is Online!</p>";
    fclose($online);
} else {
    echo "<p style='color:red' class='hero'>Server is Offline.</p>";
}

include("../includes/footer.php");
?>