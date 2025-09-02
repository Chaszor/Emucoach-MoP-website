<?php
// pages/account.php
// Admin section for viewing and managing accounts.
// Structured similarly to shop_items.php and playtime_awards.php.

require_once __DIR__ . '/../../config.php';

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Database connection error.</div>";
    return;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['account_id'])) {
    $account_id = (int)$_POST['account_id'];

    switch ($_POST['action']) {
        case 'set_cash':
            if (isset($_POST['cash_value'])) {
                $cash_value = (int)$_POST['cash_value'];
                $stmt = $auth_conn->prepare("UPDATE account SET cash = ? WHERE id = ?");
                $stmt->bind_param("ii", $cash_value, $account_id);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>Cash updated to {$cash_value} for account #{$account_id}</div>";
            }
            break;

        case 'ban':
            $stmt = $auth_conn->prepare("UPDATE account SET locked = 1 WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();
            echo "<div class='flash ok'>Account #$account_id banned.</div>";
            break;

        case 'unban':
            $stmt = $auth_conn->prepare("UPDATE account SET locked = 0 WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();
            echo "<div class='flash ok'>Account #$account_id unbanned.</div>";
            break;

        case 'delete':
            $stmt = $auth_conn->prepare("DELETE FROM account WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $stmt->close();
            echo "<div class='flash ok'>Account #$account_id deleted.</div>";
            break;
    }
}

// Fetch account list, excluding BOT accounts
$res = $auth_conn->query("
SELECT id, username, email, last_login, cash, locked
FROM account
WHERE email IS NOT NULL AND email <> ''
ORDER BY id ASC
");
?>

<section class="card">
  <h2>Account Management</h2>

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
        <td><?= $row['locked'] ? 'Banned' : 'Active' ?></td>
        <td>
            <div style="display:flex; gap:6px;">
              <button type="submit" name="action" value="set_cash">Set Cash</button>
              <?php if ($row['locked']): ?>
                <button type="submit" name="action" value="unban">Unban</button>
              <?php else: ?>
                <button type="submit" name="action" value="ban">Ban</button>
              <?php endif; ?>
              <button type="submit" name="action" value="delete"
                      onclick="return confirm('Are you sure you want to DELETE this account? This cannot be undone.');">
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
