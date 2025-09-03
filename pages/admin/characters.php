<?php
// pages/characters.php
// Admin section for managing characters (included from admin.php?tab=characters).

require_once __DIR__ . '/../../config.php';

if (!isset($char_conn) || $char_conn->connect_error) {
    echo "<div class='flash err'>Characters DB connection error.</div>";
    return;
}

/* ---------------- SOAP Helper ---------------- */
function send_soap_command(string $command): bool {
    global $soap_enabled, $soap_host, $soap_port, $soap_user, $soap_pass;

    if (!$soap_enabled) return false;

    try {
        $client = new SoapClient(NULL, array(
            "location" => "http://{$soap_host}:{$soap_port}/",
            "uri"      => "urn:TC",
            "style"    => SOAP_RPC,
            "login"    => $soap_user,
            "password" => $soap_pass,
        ));
        $client->__soapCall("executeCommand", array("command" => $command));
        return true;
    } catch (Exception $e) {
        echo "<div class='flash err'>SOAP Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        return false;
    }
}

/* ---------------- Account Selector ---------------- */
$accounts = $auth_conn->query("
    SELECT id, username 
    FROM account 
    WHERE email IS NOT NULL AND email <> '' 
    ORDER BY username ASC
");

$selected_account = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['guid'])) {
    $guid = (int)$_POST['guid'];
    $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;

    switch ($_POST['action']) {
        case 'set_level':
            if (isset($_POST['level'])) {
                $level = (int)$_POST['level'];
                $stmt = $char_conn->prepare("UPDATE characters SET level = ? WHERE guid = ?");
                $stmt->bind_param("ii", $level, $guid);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>Level updated to {$level} for character #{$guid}</div>";
            }
            break;

        case 'set_gold':
            if (isset($_POST['gold'])) {
                $gold = (int)$_POST['gold'];
                $money = $gold * 10000; // TrinityCore stores copper
                $stmt = $char_conn->prepare("UPDATE characters SET money = ? WHERE guid = ?");
                $stmt->bind_param("ii", $money, $guid);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>Gold updated to {$gold} for character #{$guid}</div>";
            }
            break;

        case 'delete':
            $stmt = $char_conn->prepare("DELETE FROM characters WHERE guid = ?");
            $stmt->bind_param("i", $guid);
            $stmt->execute();
            $stmt->close();
            echo "<div class='flash ok'>Character #{$guid} deleted.</div>";
            break;

        case 'tele_gm':
        case 'tele_hearth':
        case 'kick':
        case 'revive':
            $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid=?");
            $stmt->bind_param("i", $guid);
            $stmt->execute();
            $stmt->bind_result($charName);
            if ($stmt->fetch()) {
                switch ($_POST['action']) {
                    case 'tele_gm':     $cmd = "character teleport {$charName} GMIsland"; break;
                    case 'tele_hearth': $cmd = "character unstuck {$charName}"; break;
                    case 'kick':        $cmd = "kick {$charName}"; break;
                    case 'revive':      $cmd = "revive {$charName}"; break;
                }
                send_soap_command($cmd);
                echo "<div class='flash ok'>{$_POST['action']} executed for {$charName}.</div>";
            }
            $stmt->close();
            break;
    }

    // redirect back to admin.php characters tab with account preserved
    header("Location: admin.php?tab=characters&account_id=" . $account_id);
    exit;
}

/* ---------------- Fetch Characters ---------------- */
if ($selected_account > 0) {
    $char_stmt = $char_conn->prepare("
        SELECT c.guid, c.name, c.level, c.race, c.class, c.money, c.online, a.username
        FROM characters c
        JOIN auth.account a ON c.account = a.id
        WHERE a.id = ?
        ORDER BY c.guid ASC
    ");
    $char_stmt->bind_param("i", $selected_account);
    $char_stmt->execute();
    $res = $char_stmt->get_result();
}
?>

<section class="card" style="text-align: center;">
  <h2>Character Management</h2>

  <form method="get" action="admin.php" style="margin-bottom:15px;">
    <input type="hidden" name="tab" value="characters">
    <label for="account_id">Select Account:</label>
    <select name="account_id" id="account_id" onchange="this.form.submit()">
      <option value="0">-- Choose Account --</option>
      <?php while ($acc = $accounts->fetch_assoc()): ?>
        <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $selected_account ? 'selected' : '' ?>>
          <?= htmlspecialchars($acc['username']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </form>

  <?php if ($selected_account > 0 && isset($res)): ?>
  <table>
    <thead>
      <tr>
        <th>GUID</th>
        <th>Name</th>
        <th>Account</th>
        <th>Race</th>
        <th>Class</th>
        <th>Level</th>
        <th>Gold</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($row = $res->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['guid']) ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['username']) ?></td>
        <td><?= htmlspecialchars($row['race']) ?></td>
        <td><?= htmlspecialchars($row['class']) ?></td>
        <td>
          <form method="post" action="admin.php?tab=characters&account_id=<?= $selected_account ?>" 
                style="display:flex; gap:6px; align-items:center; margin:0;">
            <input type="hidden" name="guid" value="<?= $row['guid'] ?>">
            <input type="hidden" name="account_id" value="<?= $selected_account ?>">
            <input type="number" name="level" value="<?= htmlspecialchars($row['level']) ?>" 
                   style="width:70px; text-align:right;">
        </td>
        <td>
            <input type="number" name="gold" value="<?= floor($row['money'] / 10000) ?>"
                   style="width:90px; text-align:right;">
        </td>
        <td><?= $row['online'] ? 'Online' : 'Offline' ?></td>
        <td>
            <div style="display:flex; gap:6px; flex-wrap: wrap;">
                <button type="submit" name="action" value="set_level">Set Level</button>
                <button type="submit" name="action" value="set_gold">Set Gold</button>
                <button type="submit" name="action" value="tele_gm">GM Island</button>
                <button type="submit" name="action" value="tele_hearth">Hearthstone</button>
                <button type="submit" name="action" value="kick">Kick</button>
                <button type="submit" name="action" value="revive">Revive</button>
                <button type="submit" name="action" value="delete"
                      onclick="return confirm('Are you sure you want to DELETE this character? This cannot be undone.');">
                Delete
              </button>
            </div>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php elseif ($selected_account > 0): ?>
    <p>No characters found for this account.</p>
  <?php endif; ?>
</section>
