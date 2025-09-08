<?php
include("../config.php");
include("../includes/header.php"); ?>
<section class="container">
<?php

$server = "wow.extremisgaming.com"; // your WoW server IP
$port = 3724;              // auth server port (usually 3724)

$online = @fsockopen($server, $port, $errno, $errstr, 2);

echo "<h2 style='text-align: center'>Server Status</h2>";

if ($online) {
    echo "<p style='color:green; text-align:center;' class='hero'>Server is Online!</p>";
    fclose($online);

    // Query characters DB for online players (excluding BOT*)
    if (isset($char_conn) && $char_conn instanceof mysqli) {
        $sql = "SELECT name FROM characters WHERE online = 1 AND name NOT LIKE 'BOT%'";
        if ($res = $char_conn->query($sql)) {
            if ($res->num_rows > 0) {
                echo "<h3>Players Online</h3><ul>";
                while ($row = $res->fetch_assoc()) {
                    echo "<li>" . htmlspecialchars($row['name']) . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<p>No players online right now.</p>";
            }
        } else {
            echo "<p>Could not query online players.</p>";
        }
    } else {
        echo "<p style='color:red'>Could not connect to characters database.</p>";
    }
} else {
    echo "<p style='color:red' class='hero'>Server is Offline.</p>";
}
?>
</section>
<?php include("../includes/footer.php"); ?>
