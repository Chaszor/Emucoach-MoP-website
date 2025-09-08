<?php
// pages/characters.php
// Admin section for managing characters (included from admin.php?tab=characters).

require_once __DIR__ . '/../../config.php';

if (!isset($char_conn) || $char_conn->connect_error) {
    echo "<div class='flash err'>Characters DB connection error.</div>";
    return;
}

/* ---------------- Helpers ---------------- */
function get_character(mysqli $conn, int $guid): ?array {
    $stmt = $conn->prepare("SELECT name, online FROM characters WHERE guid=?");
    $stmt->bind_param("i", $guid);
    $stmt->execute();
    $stmt->bind_result($name, $online);
    if ($stmt->fetch()) {
        $stmt->close();
        return ['name' => $name, 'online' => $online];
    }
    $stmt->close();
    return null;
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
    $guid       = (int)$_POST['guid'];
    $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    $admin_user = $_SESSION['username'] ?? 'system';
    $action     = $_POST['action'];

    if ($char = get_character($char_conn, $guid)) {
        switch ($action) {
            case 'set_level':
                if (isset($_POST['level'])) {
                    $level = (int)$_POST['level'];
                    [$ok, $resp] = sendSoap("character level {$char['name']} {$level}");
                    if ($ok) {
                        flash('ok', "SOAP: Level set to {$level} for {$char['name']}.");
                    } else {
                        $upd = $char_conn->prepare("UPDATE characters SET level=? WHERE guid=?");
                        $upd->bind_param("ii", $level, $guid);
                        $upd->execute();
                        $upd->close();
                        flash('err', "SOAP failed, DB: Level updated to {$level} for character #{$guid}. Error: {$resp}");
                    }
                    log_activity($auth_conn, $account_id, $admin_user, $action, "Set level {$level} for {$char['name']}");
                }
                break;

case 'set_gold':
    if (isset($_POST['gold'])) {
        $gold  = (int)$_POST['gold'];
        $money = $gold * 10000;

        // Check if character is offline
        $stmt = $char_conn->prepare("SELECT online FROM characters WHERE guid=? LIMIT 1");
        $stmt->bind_param("i", $guid);
        $stmt->execute();
        $stmt->bind_result($isOnline);
        $stmt->fetch();
        $stmt->close();

        if ($isOnline) {
            flash('err', "Cannot update gold: character {$char['name']} is online.");
        } else {
            $upd = $char_conn->prepare("UPDATE characters SET money=? WHERE guid=?");
            $upd->bind_param("ii", $money, $guid);
            $upd->execute();
            $upd->close();

            flash('ok', "Gold set to {$gold} for {$char['name']}.");
            log_activity($auth_conn, $account_id, $admin_user, $action, "Set gold {$gold} for {$char['name']}");
        }
    }
    break;


            case 'send_gold':
              if (isset($_POST['confirm_gold']) && is_numeric($_POST['confirm_gold'])) {
        $gold  = (int)$_POST['confirm_gold'];
        $money = $gold * 10000;

        [$ok, $resp] = sendSoap("send money {$char['name']} \"Gold\" \"Enjoy\" {$money}");
        if ($ok) {
            flash('ok', "SOAP: Sent {$gold} gold to {$char['name']}.");
        } else {
            flash('err', "SOAP failed for {$char['name']}. Error: {$resp}");
        }

        log_activity($auth_conn, $account_id, $admin_user, $action, "Sent {$gold} gold to {$char['name']}");
    }
    break;
            case 'delete':
                [$ok, $resp] = sendSoap("character erase {$char['name']}");
                if ($ok) {
                    flash('ok', "SOAP: Character {$char['name']} erased.");
                } else {
                    sendSoap("kick {$char['name']}");
                    sleep(1);
                    flash('err', "SOAP failed, DB: Character {$char['name']} (#{$guid}) erased. Error: {$resp}");
                }
                log_activity($auth_conn, $account_id, $admin_user, $action, "Erased {$char['name']}");
                break;

            case 'tele_gm':
                [$ok, $resp] = sendSoap("tele name {$char['name']} GMIsland");
                $ok ? flash('ok', "Teleported {$char['name']} to GM Island.")
                    : flash('err', "Failed to teleport {$char['name']} (Error: {$resp})");
                log_activity($auth_conn, $account_id, $admin_user, $action, "Teleported {$char['name']} to GM Island");
                break;

            case 'tele_hearth':
                [$ok, $resp] = sendSoap("unstuck {$char['name']}");
                $ok ? flash('ok', "Teleported {$char['name']} to hearth location.")
                    : flash('err', "Failed to teleport {$char['name']} (Error: {$resp})");
                log_activity($auth_conn, $account_id, $admin_user, $action, "Teleported {$char['name']} to hearth location");
                break;

            case 'kick':
                [$ok, $resp] = sendSoap("kick {$char['name']}");
                $ok ? flash('ok', "Kicked {$char['name']} from the server.")
                    : flash('err', "Failed to kick {$char['name']} (Error: {$resp})");
                log_activity($auth_conn, $account_id, $admin_user, $action, "Kicked {$char['name']}");
                break;

            case 'revive':
                [$ok, $resp] = sendSoap("revive {$char['name']}");
                $ok ? flash('ok', "Revived {$char['name']}.")
                    : flash('err', "Failed to revive {$char['name']} (Error: {$resp})");
                log_activity($auth_conn, $account_id, $admin_user, $action, "Revived {$char['name']}");
                break;
        }
    }
}

/* ---------------- Fetch Characters ---------------- */
if ($selected_account > 0) {
    $char_stmt = $char_conn->prepare("
        SELECT c.guid, c.name, c.level, c.race, c.class, c.gender, c.money, c.online, a.username
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

<section class="card" style="max-width:100%; margin:auto; text-align:center;">
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
  <table style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th>GUID</th>
        <th>Name</th>
        <th>Account</th>
        <th>Race</th>
        <th>Class</th>
        <th>Gender</th>
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
        <td>
          <?php
            $raceName = $races[$row['race']] ?? "Unknown";
            $raceIcon = "images/icons/race/{$row['race']}.png";
            if (file_exists(__DIR__ . "/../../" . $raceIcon)) {
                echo "<img src='{$raceIcon}' alt='{$raceName}' title='{$raceName}' style='height:20px;'> ";
            }
            echo htmlspecialchars($raceName);
          ?>
        </td>
        <td>
          <?php
            $className = $classes[$row['class']] ?? "Unknown";
            $classIcon = "images/icons/class/{$row['class']}.png";
            if (file_exists(__DIR__ . "/../../" . $classIcon)) {
                echo "<img src='{$classIcon}' alt='{$className}' title='{$className}' style='height:20px;'> ";
            }
            echo htmlspecialchars($className);
          ?>
        </td>
        <td>
          <?php
            $genderName = $genders[$row['gender']] ?? "Unknown";
            $genderIcon = "images/icons/gender/{$row['gender']}.png";
            if (file_exists(__DIR__ . "/../../" . $genderIcon)) {
                echo "<img src='{$genderIcon}' alt='{$genderName}' title='{$genderName}' style='height:20px;'> ";
            }
            echo htmlspecialchars($genderName);
          ?>
        </td>
        <td>
          <form method="post" action="admin.php?tab=characters&account_id=<?= $selected_account ?>" 
                style="display:flex; gap:6px; align-items:center; margin:0;">
            <input type="hidden" name="guid" value="<?= $row['guid'] ?>">
            <input type="hidden" name="account_id" value="<?= $selected_account ?>">
            <input type="number" name="level" value="<?= htmlspecialchars($row['level']) ?>" style="width:70px; text-align:right;">
        </td>
        <td>
            <input type="number" name="gold" value="<?= floor($row['money'] / 10000) ?>" style="width:90px; text-align:right;">
        </td>
        <td><?= $row['online'] ? '<span style="color:green">Online</span>' : 'Offline' ?></td>
        <td>
            <button type="submit" name="action" value="set_level" class="btn">Set Level</button>
            <button type="submit" name="action" value="set_gold" class="btn">Set Gold</button>
            <button type="submit" name="action" value="send_gold" class="btn"
                onclick="
                    var gold = prompt('Enter amount of gold to send:');
                    if (gold === null || gold.trim() === '' || isNaN(gold) || gold <= 0) {
                        return false; // cancel if no valid input
                    }
                    this.form.confirm_gold.value = parseInt(gold, 10);
                    return true;
                ">
                Send Gold
            </button>
            <input type="hidden" name="confirm_gold" value="">

            <button type="submit" name="action" value="kick" class="btn">Kick</button>
            <button type="submit" name="action" value="revive" class="btn">Revive</button>
            <button type="submit" name="action" value="tele_gm" class="btn">Tele GM</button>
            <button type="submit" name="action" value="tele_hearth" class="btn">Tele Hearth</button>
            <button type="submit" name="action" value="delete" class="btn" onclick="return confirm('Delete this character?');">Delete</button>
          </form>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
