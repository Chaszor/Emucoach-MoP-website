<?php
include("../config.php");
include("../includes/header.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtoupper(trim($_POST["username"]));
    $password = strtoupper(trim($_POST["password"]));
    $ip = $_SERVER['REMOTE_ADDR']; // capture IP address

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

                $stmt->close();

                // ✅ Log success
                $auth_conn->query("
                    INSERT INTO login_logs (account_id, username, ip, action, result)
                    VALUES ({$account_id}, '{$username}', '{$ip}', 'login', 'success')
                ");

                // Check admin quickly to decide redirect (optional)
                $is_admin = false;
                if ($check = $auth_conn->prepare("SELECT gmlevel FROM account_access WHERE id=? LIMIT 1")) {
                    $check->bind_param("i", $account_id);
                    $check->execute();
                    $check->bind_result($gm);
                    $is_admin = ($check->fetch() && (int)$gm >= 3);
                    $check->close();
                }

                header("Location: " . ($is_admin ? "/pages/admin.php" : "/pages/dashboard.php"));
                exit;

            } else {
                echo "<p style='color:red'>Invalid username or password.</p>";

                // ✅ Log failed attempt
                $auth_conn->query("
                    INSERT INTO login_logs (account_id, username, ip, action, result)
                    VALUES (0, '{$username}', '{$ip}', 'login', 'failed')
                ");
            }
            $stmt->close();
        } else {
            echo "<p style='color:red'>Login error. Please try again.</p>";
        }
    }
}
?>

<h2 align="center">Login</h2>
<form method="POST" class="form-center">
    Username: <input type="text" name="username" autocomplete="username" required><br>
    Password: <input type="password" name="password" autocomplete="current-password" required><br>
    <button type="submit" class="btn">Login</button>
</form>

<style>
.form-center {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.btn {
    padding: 8px 16px;
    margin-top: 10px;
}
</style>

<?php include("../includes/footer.php"); ?>
