<section class="card" style="max-width:800px; margin:auto;">
  <h2 style="text-align: center;">Logs</h2>
  <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <input type="hidden" name="admin_action" value="logs">

    <label for="log_type">Select Log</label>
    <select name="log_type" id="log_type">
      <option value="">— Choose a log —</option>
      <option value="login">Login Logs</option>
      <option value="transactions">Transaction Logs</option>
      <option value="activity">Activity Logs</option>
    </select>

    <label for="limit">Entries to Display</label>
    <input type="number" name="limit" id="limit" value="50" min="10" max="500">

    <button type="submit">View Logs</button>
  </form>

  <?php
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['admin_action'] === 'logs') {
      $log_type = $_POST['log_type'] ?? '';
      $limit = (int)($_POST['limit'] ?? 50);

      if ($log_type) {
          echo "<h3>Showing " . htmlspecialchars($log_type) . " (max {$limit})</h3>";

          // Example: adjust queries based on type
          switch ($log_type) {
              case 'login':
                  $res = $auth_conn->query("SELECT account_id, username, ip, action, result, timestamp FROM login_logs ORDER BY timestamp DESC LIMIT {$limit}");
                  break;
              case 'transactions':
                  $res = $auth_conn->query("SELECT id, account_id, orderNo, synType, status, price, time, cpparam FROM pay_history ORDER BY time DESC LIMIT {$limit}");
                  break;
              case 'activity':
                  $res = $auth_conn->query("SELECT account_id, username, action, created_at FROM activity_log ORDER BY created_at DESC LIMIT {$limit}");
                  break;
          }

          if (isset($res) && $res->num_rows > 0) {
              echo "<table class='log-table'><tr>";
              while ($field = $res->fetch_field()) {
                  echo "<th>" . htmlspecialchars($field->name) . "</th>";
              }
              echo "</tr>";
              while ($row = $res->fetch_assoc()) {
                  echo "<tr>";
                  foreach ($row as $val) {
                      echo "<td>" . htmlspecialchars((string)$val) . "</td>";
                  }
                  echo "</tr>";
              }
              echo "</table>";
          } else {
              echo "<p>No entries found for this log.</p>";
          }
      } else {
          echo "<p>Please select a log type.</p>";
      }
  }
  ?>
</section>
