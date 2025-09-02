<?php
// pages/characters.php
// Admin section for managing characters.

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

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['guid'])) {
    $guid = (int)$_POST['guid'];

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
            $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid=?");
            $stmt->bind_param("i", $guid);
            $stmt->execute();
            $stmt->bind_result($charName);
            if ($stmt->fetch()) {
                $cmd = "character teleport {$charName} GMIsland";
                send_soap_command($cmd);
                echo "<div class='flash ok'>Teleported {$charName} to GM Island.</div>";
            }
            $stmt->close();
            break;

        case 'tele_hearth':
            $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid=?");
            $stmt->bind_param("i", $guid);
            $stmt->execute();
            $stmt->bind_result($charName);
            if ($stmt->fetch()) {
                $cmd = "character unstuck {$charName}";
                send_soap_command($cmd);
                echo "<div class='flash ok'>Teleported {$charName} to Hearthstone location.</div>";
            }
            $stmt->close();
            break;
        
        case 'kick':
    $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid=?");
    $stmt->bind_param("i", $guid);
    $stmt->execute();
    $stmt->bind_result($charName);
    if ($stmt->fetch()) {
        $cmd = "kick {$charName}";
        send_soap_command($cmd);
        echo "<div class='flash ok'>Kicked {$charName} from the server.</div>";
    }
    $stmt->close();
    break;

case 'revive':
    $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid=?");
    $stmt->bind_param("i", $guid);
    $stmt->execute();
    $stmt->bind_result($charName);
    if ($stmt->fetch()) {
        $cmd = "revive {$charName}";
        send_soap_command($cmd);
        echo "<div class='flash ok'>Revived {$charName}.</div>";
    }
    $stmt->close();
    break;

    }
}

/* ---------------- Fetch Characters ---------------- */
$res = $char_conn->query("
SELECT c.guid, c.name, c.level, c.race, c.class, c.money, c.online,
        a.username
        FROM characters c
        JOIN auth.account a ON c.account = a.id
        WHERE a.email is not null and a.email <> ''
        ORDER BY c.guid ASC
");
?>

<section class="card">
  <h2>Character Management</h2>

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
          <form method="post" style="display:flex; gap:6px; align-items:center; margin:0;">
            <input type="hidden" name="guid" value="<?= $row['guid'] ?>">
            <input type="number" name="level" value="<?= htmlspecialchars($row['level']) ?>" 
                   style="width:70px; text-align:right;">
        </td>
        <td>
            <input type="number" name="gold" value="<?= floor($row['money'] / 10000) ?>"
                   style="width:90px; text-align:right;">
        </td>
        <td><?= $row['online'] ? 'Online' : 'Offline' ?></td>
        <td>
            <div style="display:flex; gap:6px;">
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
</section>
