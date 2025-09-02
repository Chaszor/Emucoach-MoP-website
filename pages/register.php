<?php
include("../config.php");
include("../includes/header.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtoupper(trim($_POST["username"]));
    $password = strtoupper(trim($_POST["password"]));
    $email = trim($_POST["email"]);

    if ($username && $password && $email) {
        // Let MySQL generate the correct hash
        $stmt = $auth_conn->prepare("
            INSERT INTO account (username, sha_pass_hash, email) 
            VALUES (?, UPPER(SHA1(CONCAT(?, ':', ?))), ?)
        ");
        $stmt->bind_param("ssss", $username, $username, $password, $email);

        if ($stmt->execute()) {
            echo "<p style='color:green'>Account created successfully!</p>";
        } else {
            echo "<p style='color:red'>Error: " . $conn->error . "</p>";
        }
        $stmt->close();
    } else {
        echo "<p style='color:red'>All fields required.</p>";
    }
}
?>

<h2 align="center">Register</h2>
<form method="POST">
    Username: <input type="text" name="username"><br>
    Password: <input type="password" name="password"><br>
    Email: <input type="email" name="email"><br>
    <button type="submit">Register</button>
</form>

<?php include("../includes/footer.php"); ?>
