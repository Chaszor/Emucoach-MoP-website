<?php
/**
 * Shop page — mysqli + SOAP delivery + categories (Approach B)
 * No ID column in the table output.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

if (empty($_SESSION['username'])) {
    echo "<div class='flash err'>You must be logged in to access the shop.</div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$username = $_SESSION['username'];
$account  = get_account($auth_conn, $username);
if (!$account) {
    flash('err', 'Account not found.');
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$account_id = (int)$account['id'];
$cash       = (float)$account['cash'];
// Force SOAP always enabled on the shop page
$soap_enabled = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    $item_id   = (int)($_POST['item_id'] ?? 0);
    $char_guid = (int)($_POST['char_guid'] ?? 0);
    if ($item_id && $char_guid) {
        $item = get_shop_item_by_id($auth_conn, $item_id);
        if ($item) {
            $stmt = $char_conn->prepare("SELECT name FROM characters WHERE guid = ? AND account = ? LIMIT 1");
            $stmt->bind_param('ii', $char_guid, $account_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            if ($row) {
                $char_name = $row['name'];
                $price     = (float)$item['price'];
                $entry     = (int)$item['item_entry'];
                $stack     = max(1, (int)$item['stack']);
                if ($cash >= $price) {
                    $auth_conn->begin_transaction();
                    $ok = true; $msg = '';
                    $orderNo = 'ORD-' . time() . '-' . random_int(1000, 9999);
                    $stmt = $auth_conn->prepare("UPDATE account SET cash = cash - ? WHERE id = ? AND cash >= ?");
                    $stmt->bind_param('dii', $price, $account_id, $price);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) { $ok = false; $msg = 'Balance update failed.'; }
                    $stmt->close();
                    if ($ok) {
                        [$d_ok, $d_msg] = deliver_item($char_guid, $char_name, $entry, $stack);
                        if (!$d_ok) { $ok = false; $msg = 'Delivery failed: ' . $d_msg; }
                    }
                    if ($ok) {
                        $auth_conn->commit();
                        log_pay_history($auth_conn, $account_id, $username, $orderNo, $price, 'SUCCESS', 'item=' . $entry);
                        flash('ok', "Delivered {$item['name']} x{$stack} to {$char_name}. Deducted {$price} Coins.");
                        $account = get_account($auth_conn, $username);
                        $cash    = (float)$account['cash'];
                    } else {
                        $auth_conn->rollback();
                        log_pay_history($auth_conn, $account_id, $username, $orderNo, $price, 'FAILED', $msg);
                        flash('err', $msg);
                    }
                } else {
                    flash('err', 'Not enough Coins.');
                }
            } else {
                flash('err', 'Invalid character selection.');
            }
        } else {
            flash('err', 'Item not found.');
        }
    } else {
        flash('err', 'Missing item or character.');
    }
}

/* ----------------------------- UI ---------------------------------------- */
echo "<section>";
echo "<h2 style=\"text-align: center\">Premier Shop</h2>";
echo "</section>";
$currentCatId = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$cats = get_categories($auth_conn);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
echo "<div style='margin:10px 0; display:flex; gap:8px; flex-wrap:wrap; justify-content:center;'>";

// All tab
$active = ($currentCatId === 0);
echo "<a href='{$baseUrl}' class='tab-link" . ($active ? " active" : "") . "'>All</a>";

// Category tabs
foreach ($cats as $c) {
    if ((int)$c['cnt'] === 0) continue;
    $active = ($currentCatId === (int)$c['id']);
    $name = htmlspecialchars($c['name']);
    $cnt  = (int)$c['cnt'];
    echo "<a href='{$baseUrl}?cat={$c['id']}' class='tab-link" . ($active ? " active" : "") . "'>"
       . "{$name} <span style='opacity:.6'>({$cnt})</span></a>";
}
echo "</div>";

echo "<br>";
echo "<div style='margin-bottom:8px; font-size: 1.6em;'>Coins: <b>" . number_format($cash, 0) . "</b></div>";

$items = list_shop_items($auth_conn, $currentCatId ?: null);

if (!$items) {
    echo "<p>No items configured yet.</p>";
} else {
    echo "<table id='shopTable' border='1' cellpadding='6' cellspacing='0' style='margin-top:10px'>";
    echo "<tr>
            <th class='sortable'>Item <span class='arrow'></span></th>
            <th class='sortable'>Price <span class='arrow'></span></th>
            <th>Stack <span class='arrow'></span></th>
            <th>To Character</th>
          </tr>";
    foreach ($items as $row) {
        echo "<tr>";

        // NAME
        echo "  <td>"
           . "    <a href='https://www.wowhead.com/mop-classic/item=".(int)$row['item_entry']."' rel='wowhead'>"
           .          htmlspecialchars($row['name'])
           . "    </a> "
           . "    <span style='opacity:.6'>(Entry " . (int)$row['item_entry'] . ")</span>"
           . "  </td>";

        // PRICE & STACK
        echo "  <td>" . htmlspecialchars((string)$row['price']) . "</td>";
        echo "  <td>" . (int)$row['stack'] . "</td>";

        // CHARACTER SELECT + Buy
        echo "  <td>";
        echo "    <form method='post' style='margin:0; display:flex; gap:6px; align-items:center'>";
        echo "      <select name='char_guid' required>";
        $characters = user_characters($char_conn, $account_id);
        foreach ($characters as $c) {
            echo "<option value='".(int)$c['guid']."'>".htmlspecialchars($c['name'])."</option>";
        }
        echo "      </select>";
        echo "      <input type='hidden' name='item_id' value='".(int)$row['id']."'>";
        echo "      <button class='btn' type='submit' name='buy' value='1' onclick=\"return confirm('Are you sure you want to BUY this item? This cannot be undone.');\">Buy</button>";
        echo "    </form>";
        echo "  </td>";

        echo "</tr>";
    }
    echo "</table>";
}
?>

<style>
th.sortable { cursor: pointer; user-select: none; }
th.sortable .arrow { font-size: 0.8em; opacity: 0.6; }
.tab-link {
  text-decoration: none;
  transition: all 0.2s ease;
  padding: .4rem .7rem;
  border-radius: 10px;
  border: 1px solid var(--border, #3a3a3a);
  background: rgba(255,255,255,.04);
  cursor: pointer;
  color: #2e8b57;   /* sea green text */
  display: inline-block;
}

.tab-link:hover {
  background: #f5f5f5;
  color: #226644;   /* darker green on hover */
}

.tab-link.active {
  font-weight: bold;
  border-color: #2e6da4;
  box-shadow: 0 2px 6px rgba(0,0,0,0.25);
  background: #2e8b57;
  color: #000;
}

</style>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const getCellValue = (row, index) =>
    row.children[index].innerText.trim();

  const comparer = (index, asc) => (a, b) => {
    const v1 = getCellValue(asc ? a : b, index);
    const v2 = getCellValue(asc ? b : a, index);
    return !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.localeCompare(v2);
  };

  document.querySelectorAll("#shopTable th.sortable").forEach((th, idx) =>
    th.addEventListener("click", () => {
      const table = th.closest("table");
      const rows = Array.from(table.querySelectorAll("tr:nth-child(n+2)"));
      const asc = (th.asc = !th.asc);

      rows.sort(comparer(idx, asc)).forEach(tr => table.appendChild(tr));

      // reset arrows
      document.querySelectorAll("#shopTable th.sortable .arrow").forEach(el => el.textContent = "");
      th.querySelector(".arrow").textContent = asc ? "↑" : "↓";
    })
  );
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
