<?php
// pages/login.php

// 1) Bootstrap (no output)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

// Small helper for redirects
function redirect(string $url): never {
    header("Location: {$url}", true, 302);
    exit;
}

$error = '';

// 2) Handle POST early (before including header.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtoupper(trim($_POST['username'] ?? ''));
    $password = strtoupper(trim($_POST['password'] ?? ''));
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($username !== '' && $password !== '') {
        // Verify against auth.account using Trinity/MaNGOS-style hash
        if ($stmt = $auth_conn->prepare("
            SELECT id
            FROM account
            WHERE username = ?
              AND sha_pass_hash = UPPER(SHA1(CONCAT(?, ':', ?)))
            LIMIT 1
        ")) {
            $stmt->bind_param('sss', $username, $username, $password);
            $stmt->execute();
            $stmt->bind_result($account_id);

            if ($stmt->fetch()) {
                // Auth OK
                $_SESSION['username']   = $username;
                $_SESSION['account_id'] = (int)$account_id;
            }
            $stmt->close();

            if (!empty($_SESSION['account_id'])) {
                // Log success (prepared)
                if ($log = $auth_conn->prepare("
                    INSERT INTO login_logs (account_id, username, ip, action, result)
                    VALUES (?, ?, ?, 'login', 'success')
                ")) {
                    $log->bind_param('iss', $_SESSION['account_id'], $username, $ip);
                    $log->execute();
                    $log->close();
                }

                // Optional: check admin to choose destination
                $is_admin = false;
                if ($check = $auth_conn->prepare("SELECT gmlevel FROM account_access WHERE id=? LIMIT 1")) {
                    $check->bind_param('i', $_SESSION['account_id']);
                    $check->execute();
                    $check->bind_result($gm);
                    $is_admin = ($check->fetch() && (int)$gm >= 3);
                    $check->close();
                }

                redirect($is_admin ? '/pages/admin.php' : '/pages/dashboard.php');
            } else {
                // Auth failed
                $error = 'Invalid username or password.';

                // Log failed attempt (prepared)
                if ($log = $auth_conn->prepare("
                    INSERT INTO login_logs (account_id, username, ip, action, result)
                    VALUES (0, ?, ?, 'login', 'failed')
                ")) {
                    $log->bind_param('ss', $username, $ip);
                    $log->execute();
                    $log->close();
                }
            }
        } else {
            $error = 'Login error. Please try again.';
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}

// 3) Render form (safe to output now)
require_once __DIR__ . '/../includes/header.php';
?>
<section class="container">
  <h1 style="text-align: center;">Login</h1>

  <?php if ($error): ?>
    <div class="flash err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="form-center" autocomplete="on">
    <label>
      Username
      <input type="text" name="username" autocomplete="username" required>
    </label>
    <label>
      Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn">Login</button>
  </form>
</section>

<style>
.form-center {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.form-center label {
  display: grid;
  gap: 6px;
  text-align: center;
}
.form-center input {
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid var(--border, #1f2937);
  background: var(--panel, #111827);
  color: var(--text, #e5e7eb);
  font-size: 16px; /* prevents iOS zoom */
}
.btn {
    padding: 8px 16px;
    margin-top: 10px;
    text-align: center;
}
.btn:hover { background: rgba(255,255,255,.08); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
