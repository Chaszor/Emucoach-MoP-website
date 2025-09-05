<?php
// pages/account.php
// Admin section for viewing and managing accounts.

require_once __DIR__ . '/../../config.php';

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Database connection error.</div>";
    return;
}

/* ---------------- Handle Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['account_id'])) {
    $account_id = (int)$_POST['account_id'];
    $action     = $_POST['action'];
    $admin_user = $_SESSION['username'] ?? 'system';

    switch ($action) {
        case 'set_cash':
            if (isset($_POST['cash_value'])) {
                $cash_value = (int)$_POST['cash_value'];
                $stmt = $auth_conn->prepare("UPDATE account SET cash = ? WHERE id = ?");
                $stmt->bind_param("ii", $cash_value, $account_id);
                $stmt->execute();
                $stmt->close();
                flash('ok', "Cash updated to {$cash_value} for account #{$account_id}");
            }
            break;

        case 'toggle_ban':
            // Lookup username
            $stmt = $auth_conn->prepare("SELECT username FROM account WHERE id=?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->bind_result($accountName);
            $stmt->fetch();
            $stmt->close();

            if ($accountName) {
                // Check if currently banned
                $check = $auth_conn->prepare("SELECT COUNT(*) FROM account_banned WHERE id=? AND active=1");
                $check->bind_param("i", $account_id);
                $check->execute();
                $check->bind_result($isBanned);
                $check->fetch();
                $check->close();

                if ($isBanned) {
                    // Unban via SOAP
                    [$ok, $resp] = sendSoap("unban account {$accountName}");
                    if ($ok) {
                        flash('ok', "SOAP: Account {$accountName} unbanned.");
                    } else {
                        // Fallback DB cleanup
                        $stmt = $auth_conn->prepare("DELETE FROM account_banned WHERE id=?");
                        $stmt->bind_param("i", $account_id);
                        $stmt->execute();
                        $stmt->close();
                        flash('err', "SOAP failed, DB: Account {$accountName} unbanned. Error: {$resp}");
                    }
                    log_activity($auth_conn, $account_id, $admin_user, $action, "Unbanned account {$accountName}");
                } else {
                    // Ban via SOAP (30 days default)
                    [$ok, $resp] = sendSoap("ban account {$accountName} 30d WebsiteBan");
                    if ($ok) {
                        flash('ok', "SOAP: Account {$accountName} banned for 30 days.");
                    } else {
                        // Fallback DB insert
                        $stmt = $auth_conn->prepare("
                            INSERT INTO account_banned (id, bandate, unbandate, bannedby, banreason, active)
                            VALUES (?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + (30*24*60*60), ?, 'WebsiteBan', 1)
                        ");
                        $stmt->bind_param("is", $account_id, $admin_user);
                        $stmt->execute();
                        $stmt->close();
                        flash('err', "SOAP failed, DB: Account {$accountName} banned. Error: {$resp}");
                    }
                    log_activity($auth_conn, $account_id, $admin_user, $action, "Banned account {$accountName}");
                }
            }
            break;

        case 'delete':
            $stmt = $auth_conn->prepare("DELETE FROM account WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();
            flash('ok', "Account #$account_id deleted.");
            break;
    }
}

/* ---------------- Account List ---------------- */
$res = $auth_conn->query("
    SELECT a.id, a.username, a.email, a.last_login, a.cash,
           (SELECT COUNT(*) FROM account_banned b WHERE b.id=a.id AND b.active=1) AS is_banned
    FROM account a
    WHERE a.email IS NOT NULL AND a.email <> ''
    ORDER BY a.id ASC
");
?>

<section class="card">
  <h2 style="text-align: center;">Account Management</h2>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>User</th>
        <th>Email</th>
        <th>Last Login</th>
        <th>Cash</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($row = $res->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['id']) ?></td>
        <td><?= htmlspecialchars($row['username']) ?></td>
        <td><?= htmlspecialchars($row['email']) ?></td>
        <td><?= htmlspecialchars($row['last_login']) ?></td>
        <td>
          <form method="post" style="display:flex; gap:6px; align-items:center; margin:0;">
            <input type="hidden" name="account_id" value="<?= $row['id'] ?>">
            <input type="number" name="cash_value" value="<?= htmlspecialchars($row['cash']) ?>"
                   style="width:80px; text-align:right;">
        </td>
        <td><?= $row['is_banned'] ? 'Banned' : 'Active' ?></td>
        <td>
            <div style="display:flex; gap:6px;">
              <button type="submit" name="action" value="set_cash" class="btn">Set Cash</button>
              <?php if ($row['is_banned']): ?>
                <button type="submit" name="action" value="toggle_ban" class="btn">Unban</button>
              <?php else: ?>
                <button type="submit" name="action" value="toggle_ban" class="btn">Ban</button>
              <?php endif; ?>
              <button type="submit" name="action" value="delete" class="btn"
                      onclick="return confirm('Are you sure you want to DELETE this account? This cannot be undone.');" disabled>
                Delete
              </button>
            </div>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</section>
