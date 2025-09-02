<?php
include("../config.php");
include("../includes/header.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtoupper(trim($_POST["username"]));
    $password = strtoupper(trim($_POST["password"]));

    if ($username && $password) {
        // Verify against auth.account using Trinity/MaNGOS-style hash
        if ($stmt = $auth_conn->prepare("
            SELECT id 
            FROM account 
            WHERE username=? 
              AND sha_pass_hash=UPPER(SHA1(CONCAT(?, ':', ?)))
            LIMIT 1
        ")) {
            $stmt->bind_param("sss", $username, $username, $password);
            $stmt->execute();
            $stmt->bind_result($account_id);

            if ($stmt->fetch()) {
                // SUCCESS: set both required session values
                $_SESSION["username"] = $username;
                $_SESSION["account_id"] = (int)$account_id;

                // optional: go straight to the admin page if you're an admin
                // otherwise to dashboard
                $stmt->close();

                // Check admin quickly to decide redirect (optional)
                $is_admin = false;
                if ($check = $auth_conn->prepare("SELECT gmlevel FROM account_access WHERE id=? LIMIT 1")) {
                    $check->bind_param("i", $account_id);
                    $check->execute();
                    $check->bind_result($gm);
                    $is_admin = ($check->fetch() && (int)$gm >= 3);
                    $check->close();
                }

                header("Location: " . ($is_admin ? "/wowsite/pages/admin.php" : "/wowsite/pages/dashboard.php"));
                exit;
            } else {
                echo "<p style='color:red'>Invalid username or password.</p>";
            }
            $stmt->close();
        } else {
            echo "<p style='color:red'>Login error. Please try again.</p>";
        }
    }
}
?>

<h2 align="center">Login</h2>
<form method="POST">
    
    Username: <input type="text" name="username" autocomplete="username"><br>
    Password: <input type="password" name="password" autocomplete="current-password"><br>
    <button type="submit">Login</button>
</form>

<?php include("../includes/footer.php"); ?>
