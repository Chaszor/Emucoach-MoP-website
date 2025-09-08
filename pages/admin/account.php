<?php
// pages/account.php
// Admin section for viewing and managing accounts.

require_once __DIR__ . '/../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Database connection error (auth).</div>";
    return;
}
if (!isset($char_conn) || $char_conn->connect_error) {
    echo "<div class='flash err'>Database connection error (characters).</div>";
    return;
}

/* ---------------- Utilities ---------------- */

if (!function_exists('flash')) {
    function flash(string $type, string $msg): void {
        $cls = $type === 'ok' ? 'ok' : 'err';
        echo "<div class='flash {$cls}'>" . htmlspecialchars($msg) . "</div>";
    }
}

function exec_stmt(mysqli $db, string $sql, string $types = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) return [false, "Prepare failed: {$db->error}", 0];
    if ($types !== '' && $params) $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $err = $ok ? '' : $stmt->error;
    $aff = $stmt->affected_rows;
    $stmt->close();
    return [$ok, $err, $aff];
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
                [$ok, $err] = exec_stmt($auth_conn,
                    "UPDATE account SET cash = ? WHERE id = ?", "ii",
                    [$cash_value, $account_id]
                );
                if ($ok) {
                    flash('ok', "Cash updated to {$cash_value} for account #{$account_id}");
                } else {
                    flash('err', "Cash update failed: {$err}");
                }
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
                    // Try SOAP unban; fallback DB cleanup
                    if (function_exists('sendSoap')) {
                        [$ok, $resp] = sendSoap("unban account {$accountName}");
                    } else {
                        $ok = false; $resp = 'SOAP unavailable';
                    }

                    if ($ok) {
                        flash('ok', "SOAP: Account {$accountName} unbanned.");
                    } else {
                        [$ok2, $err2] = exec_stmt($auth_conn,
                            "DELETE FROM account_banned WHERE id=?", "i",
                            [$account_id]
                        );
                        $msg = $ok2 ? "DB: unbanned {$accountName} (SOAP error: {$resp})"
                                    : "SOAP+DB unban failed: {$resp}; {$err2}";
                        flash($ok2 ? 'ok' : 'err', $msg);
                    }
                } else {
                    // Try SOAP ban; fallback DB insert (30 days)
                    if (function_exists('sendSoap')) {
                        [$ok, $resp] = sendSoap("ban account {$accountName} 30d WebsiteBan");
                    } else {
                        $ok = false; $resp = 'SOAP unavailable';
                    }

                    if ($ok) {
                        flash('ok', "SOAP: Account {$accountName} banned for 30 days.");
                    } else {
                        [$ok2, $err2] = exec_stmt($auth_conn, "
                            INSERT INTO account_banned
                              (id, bandate, unbandate, bannedby, banreason, active)
                            VALUES (?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + (30*24*60*60), ?, 'WebsiteBan', 1)
                        ", "is", [$account_id, $admin_user]);
                        $msg = $ok2 ? "DB: banned {$accountName} (SOAP error: {$resp})"
                                    : "SOAP+DB ban failed: {$resp}; {$err2}";
                        flash($ok2 ? 'ok' : 'err', $msg);
                    }
                }
            } else {
                flash('err', "Account #{$account_id} not found.");
            }
            break;

        case 'delete':
            // Robust deletion with basic dependency cleanup
            $auth_conn->begin_transaction();
            $char_conn->begin_transaction();
            try {
                // Characters DB: delete characters for this account (child rows should cascade if FKs exist)
                [$okC, $errC, $affC] = exec_stmt($char_conn,
                    "DELETE FROM characters WHERE account = ?", "i", [$account_id]
                );
                if (!$okC) throw new Exception("characters delete failed: {$errC}");

                // AUTH DB: remove dependent rows that can block account delete
                exec_stmt($auth_conn, "DELETE FROM realmcharacters WHERE acctid = ?", "i", [$account_id]); // optional
                exec_stmt($auth_conn, "DELETE FROM account_access   WHERE id = ?",     "i", [$account_id]);
                exec_stmt($auth_conn, "DELETE FROM account_banned   WHERE id = ?",     "i", [$account_id]);

                // AUTH DB: delete account
                [$okA, $errA, $affA] = exec_stmt($auth_conn,
                    "DELETE FROM account WHERE id = ?", "i", [$account_id]
                );
                if (!$okA) throw new Exception("account delete failed: {$errA}");

                $char_conn->commit();
                $auth_conn->commit();

                $msg = "Account #{$account_id} deleted. Removed {$affC} character(s).";
                flash('ok', $msg);
            } catch (Throwable $e) {
                $char_conn->rollback();
                $auth_conn->rollback();
                flash('err', "Delete failed: " . $e->getMessage());
            }
            break;
    }
}

/* ---------------- Account List ---------------- */
$res = $auth_conn->query("
    SELECT a.id, a.username, a.email, a.last_login, IFNULL(a.cash,0) AS cash,
           (SELECT COUNT(*) FROM account_banned b WHERE b.id=a.id AND b.active=1) AS is_banned
    FROM account a
    WHERE a.email IS NOT NULL AND a.email <> ''
    ORDER BY a.id ASC
");
?>

<section class="card" style="max-width:100%; margin:auto;">
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
        <td><?= htmlspecialchars($row['cash']) ?></td>
        <td><?= $row['is_banned'] ? 'Banned' : 'Active' ?></td>
        <td>
          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">

            <!-- Form A: Set Cash -->
            <form method="post" style="display:flex; gap:6px; align-items:center; margin:0;">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <input type="number" name="cash_value" value="<?= htmlspecialchars($row['cash']) ?>"
                     style="width:90px; text-align:right;">
              <button type="submit" name="action" value="set_cash" class="btn">Set Cash</button>
            </form>

            <!-- Form B: Ban / Unban -->
            <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <?php if ($row['is_banned']): ?>
                <button type="submit" name="action" value="toggle_ban" class="btn">Unban</button>
              <?php else: ?>
                <button type="submit" name="action" value="toggle_ban" class="btn">Ban</button>
              <?php endif; ?>
            </form>

            <!-- Form C: Delete -->
            <form method="post" onsubmit="return confirm('Are you sure you want to DELETE this account and related data?');" style="margin:0;">
              <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
              <button type="submit" name="action" value="delete" class="btn btn-danger">Delete</button>
            </form>

          </div>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</section>
