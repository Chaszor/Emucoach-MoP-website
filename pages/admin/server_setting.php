<?php
// pages/server_setting.php
// Admin section for managing core server settings stored in auth.site_settings

require_once __DIR__ . '/../../config.php';

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Auth DB connection error.</div>";
    return;
}

/* ---------------- Helpers ---------------- */
function flash(string $type, string $msg): void {
    $cls = $type === 'ok' ? 'ok' : 'err';
    echo "<div class='flash {$cls}'>" . htmlspecialchars($msg) . "</div>";
}

/* ---------------- Handle Updates ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_action'])) {
    $key   = $_POST['key'] ?? '';
    $value = $_POST['value'] ?? '';

    if ($key !== '') {
        $stmt = $auth_conn->prepare("
            INSERT INTO site_settings (`key`, `value`)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        $stmt->bind_param("ss", $key, $value);
        if ($stmt->execute()) {
            flash('ok', "Setting <b>{$key}</b> updated.");
        } else {
            flash('err', "Error updating setting: " . $stmt->error);
        }
        $stmt->close();
    }
}

/* ---------------- Ensure Table Exists ---------------- */
$auth_conn->query("
    CREATE TABLE IF NOT EXISTS site_settings (
      `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
      `value` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ---------------- Load Settings ---------------- */
$res = $auth_conn->query("SELECT `key`, `value` FROM site_settings ORDER BY `key` ASC");
$settings = [];
while ($row = $res->fetch_assoc()) {
    $settings[] = $row;
}
$res->close();
?>

<section class="card">
  <h2>Server Settings</h2>
  <form method="POST" style="margin-bottom:1rem;">
    <input type="hidden" name="server_action" value="update">

    <label>Setting Key
      <input type="text" name="key" required>
    </label>
    <label>Setting Value
      <input type="text" name="value" required>
    </label>
    <button type="submit">Save Setting</button>
  </form>

  <?php if (!empty($settings)): ?>
    <table class="listing">
      <thead>
        <tr>
          <th>Key</th>
          <th>Value</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($settings as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['key']); ?></td>
            <td><?php echo htmlspecialchars($row['value']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>No server settings found.</p>
  <?php endif; ?>
</section>
