<?php
//include("../config.php");
include("../includes/header.php");

$server = ""; // your WoW server IP
$port = 3724; // auth server port (usually 3724)

$online = @fsockopen($server, $port, $errno, $errstr, 2);

echo "<h2 style='text-align: center'>Server Status</h2>";

if ($online) {
    echo "<p style='color:green; text-align:center;' class='hero'>Server is Online!</p>";
    fclose($online);

    // Query characters DB for online players (excluding BOT*)
    if (isset($char_conn) && !$char_conn->connect_error) {
        $sql = "
            SELECT name 
            FROM characters 
            WHERE online = 1 
              AND name NOT LIKE 'BOT%'";
        $result = $char_conn->query($sql);

        if ($result && $result->num_rows > 0) {
            echo "<h3 style='text-align: center'>Online Players:</h3><ul>";
            while ($row = $result->fetch_assoc()) {
                echo "<li>" . htmlspecialchars($row['name']) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No players online right now.</p>";
        }
    } else {
        echo "<p style='color:red'>Could not connect to characters database.</p>";
    }
} else {
    echo "<p style='color:red' class='hero'>Server is Offline.</p>";
}

include("../includes/footer.php");
?>
