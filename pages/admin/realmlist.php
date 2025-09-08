<?php
// pages/realmlist.php
// Admin section for managing auth.realmlist (include from admin.php).

/** ---------- Bootstrap & Guards ---------- */
require_once __DIR__ . '/../../config.php';

// Optional: light wrapper so this section can show messages even if flash() isn't defined globally.
if (!function_exists('flash')) {
    function flash(string $type, string $msg): void {
        $cls = $type === 'ok' ? 'ok' : 'err';
        echo "<div class='flash {$cls}'>" . htmlspecialchars($msg) . "</div>";
    }
}

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Database connection error.</div>";
    return;
}

// Ensure we can read available columns to build robust updates
$presentCols = [];
if ($res = $auth_conn->query("SHOW COLUMNS FROM `realmlist`")) {
    while ($row = $res->fetch_assoc()) {
        $presentCols[strtolower($row['Field'])] = true;
    }
    $res->close();
} else {
    echo "<div class='flash err'>Cannot read realmlist columns: " . htmlspecialchars($auth_conn->error) . "</div>";
    return;
}

// Helper: fetch all realms (id + name)
function rl_get_realms(mysqli $conn): array {
    $rows = [];
    if ($res = $conn->query("SELECT id, name FROM realmlist ORDER BY id ASC")) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $res->close();
    }
    return $rows;
}

// Helper: fetch a realm by id (all columns)
function rl_get_realm(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM realmlist WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** ---------- Determine selected realm ---------- */
$realms = rl_get_realms($auth_conn);
if (!$realms) {
    echo "<div class='flash err'>No realms found in <code>auth.realmlist</code>.</div>";
    return;
}

$realm_id = isset($_GET['realm_id']) ? (int)$_GET['realm_id'] : (int)$realms[0]['id'];

/** ---------- Handle Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'save_realmlist') {
    $realm_id = (int)($_POST['realm_id'] ?? 0);
    if ($realm_id <= 0) {
        flash('err', 'Invalid Realm ID.');
    } else {
        // Candidate fields we’re willing to update (will be filtered by present columns)
        $inputMap = [
            'name'        => ['type' => 's', 'val' => trim((string)($_POST['realm_name'] ?? ''))],
            'shortname'   => ['type' => 's', 'val' => trim((string)($_POST['realm_shortname'] ?? ''))],
            'address'     => ['type' => 's', 'val' => trim((string)($_POST['realm_address'] ?? ''))],
            'port'        => ['type' => 'i', 'val' => (int)($_POST['realm_port'] ?? 0)],
            'icon'        => ['type' => 'i', 'val' => (int)($_POST['realm_icon'] ?? 0)],
            'realmflags'  => ['type' => 'i', 'val' => (int)($_POST['realmflags'] ?? 0)],
            'timezone'    => ['type' => 'i', 'val' => (int)($_POST['timezone'] ?? 0)],
            // Add more here if your schema includes them, e.g. allowedSecurityLevel, population, gamebuild, region, battlegroup
        ];

        // Filter to columns that truly exist
        $setParts = [];
        $bindTypes = '';
        $bindValues = [];
        foreach ($inputMap as $col => $meta) {
            if (isset($presentCols[$col]) && isset($_POST)) {
                // Only include if user actually provided the field (so we don't overwrite unintentionally)
                $postKey = match ($col) {
                    'name'       => 'realm_name',
                    'shortname'  => 'realm_shortname',
                    'address'    => 'realm_address',
                    'port'       => 'realm_port',
                    'icon'       => 'realm_icon',
                    default      => $col, // realmflags, timezone map 1:1
                };
                if (array_key_exists($postKey, $_POST)) {
                    $setParts[]  = "`$col` = ?";
                    $bindTypes   .= $meta['type'];
                    $bindValues[] = $meta['val'];
                }
            }
        }

        if ($setParts) {
            $sql = "UPDATE realmlist SET " . implode(', ', $setParts) . " WHERE id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $realm_id;

            $stmt = $auth_conn->prepare($sql);
            // dynamic bind
            $stmt->bind_param($bindTypes, ...$bindValues);
            if ($stmt->execute()) {
                flash('ok', "Realm #{$realm_id} updated successfully.");
            } else {
                flash('err', "Failed to update realm: " . htmlspecialchars($stmt->error));
            }
            $stmt->close();
        } else {
            flash('err', 'No editable fields matched your table schema.');
        }
    }
}

/** ---------- Load current realm after potential update ---------- */
$current = rl_get_realm($auth_conn, $realm_id);
if (!$current) {
    echo "<div class='flash err'>Realm not found.</div>";
    return;
}

// Helper accessors with sensible defaults if a column is missing
$realm_name    = isset($current['name']) ? $current['name'] : '';
$realm_shortname = isset($current['shortname']) ? $current['shortname'] : '';
$realm_address = isset($current['address']) ? $current['address'] : '';
$realm_port    = isset($current['port']) ? (int)$current['port'] : 8085;
$realm_icon    = isset($current['icon']) ? (int)$current['icon'] : 0;
$realmflags    = isset($current['realmflags']) ? (int)$current['realmflags'] : 0;
$timezone      = isset($current['timezone']) ? (int)$current['timezone'] : 0;
?>

<section class="card" style="max-width:600px; margin:auto;">
  <h2 style="text-align:center;">Realm Configuration</h2>

  <!-- Realm selector -->
  <form method="GET" style="margin-bottom:1rem;">
    <label>Select Realm:
      <select name="realm_id" onchange="this.form.submit()">
        <?php foreach ($realms as $r): ?>
          <option value="<?= (int)$r['id'] ?>" <?= ((int)$r['id'] === (int)$realm_id) ? 'selected' : '' ?>>
            #<?= (int)$r['id'] ?> — <?= htmlspecialchars($r['name'] ?? 'Unnamed') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button type="submit" class="btn">Load</button></noscript>
  </form>

  <!-- Edit form -->
  <form method="POST">
    <input type="hidden" name="settings_action" value="save_realmlist">
    <input type="hidden" name="realm_id" value="<?= (int)$realm_id ?>">

    <label>Realm ID:
      <input type="number" value="<?= (int)$realm_id ?>" disabled>
    </label>

    <label>Realm Name:
      <input type="text" name="realm_name" value="<?= htmlspecialchars($realm_name) ?>" required>
    </label>

    <label>Short Name:
      <input type="text" name="realm_shortname" value="<?= htmlspecialchars($realm_shortname) ?>" required>
      <small>(No spaces; used in realmlist.wtf)</small>
    </label>
    
    <label>Address / Host:
      <input type="text" name="realm_address" value="<?= htmlspecialchars($realm_address) ?>" required>
    </label>

    <label>Port:
      <input type="number" name="realm_port" min="1" value="<?= (int)$realm_port ?>" required>
    </label>

    <?php if (isset($presentCols['icon'])): ?>
    <label>Icon:
      <input type="number" name="realm_icon" min="0" max="8" value="<?= (int)$realm_icon ?>">
      <small>(0=Normal, 1=PVP, 6=RP, etc.)</small>
    </label>
    <?php endif; ?>

    <?php if (isset($presentCols['realmflags'])): ?>
    <label>Realm Flags:
      <input type="number" name="realmflags" min="0" value="<?= (int)$realmflags ?>">
      <small>(Bitmask; e.g., 0=none)</small>
    </label>
    <?php endif; ?>

    <?php if (isset($presentCols['timezone'])): ?>
    <label>Timezone:
      <input type="number" name="timezone" min="0" value="<?= (int)$timezone ?>">
      <small>(0=default; some cores use enum values)</small>
    </label>
    <?php endif; ?>

    <br><br>
    <button type="submit" class="btn">Save Realm</button>
  </form>
</section>

<section class="card" style="max-width:600px; margin:auto;">
  <h3>Notes</h3>
  <ul>
    <li>Edits apply directly to <code>auth.realmlist</code>.</li>
    <li>Set <strong>Address</strong> to your public IP/DNS for internet players, or LAN IP for local testing.</li>
    <li>Most cores require a worldserver/authserver restart for changes to take effect.</li>
  </ul>
</section>
