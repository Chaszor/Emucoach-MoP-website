<?php
// ---- Shop Items Management (admin) ----
// Ensure tables exist (safe to run repeatedly)
@$auth_conn->query("
    CREATE TABLE IF NOT EXISTS shop_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
@$auth_conn->query("
    CREATE TABLE IF NOT EXISTS shop_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_entry INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        stack INT NOT NULL DEFAULT 1,
        category_id INT NULL,
        INDEX (item_entry),
        INDEX (category_id),
        CONSTRAINT fk_shop_items_category
            FOREIGN KEY (category_id) REFERENCES shop_categories(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$shop_msg = "";

// Handle shop-specific actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shop_action'])) {
    $action = $_POST['shop_action'];

    if ($action === 'create' || $action === 'update') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $item_entry  = (int)($_POST['item_entry'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $stack       = max(1, (int)($_POST['stack'] ?? 1));
        $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;

        if ($name === '' || $item_entry <= 0) {
            $shop_msg = "Item name and a valid item_entry are required.";
        } else {
            if ($action === 'create') {
                $stmt = $auth_conn->prepare("
                    INSERT INTO shop_items (item_entry, name, price, stack, category_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('isdii', $item_entry, $name, $price, $stack, $category_id);
                if ($stmt->execute()) {
                    $shop_msg = "Created item #" . $stmt->insert_id;
                } else {
                    $shop_msg = "Create failed: " . $stmt->error;
                }
                $stmt->close();
            } else { // update
                $stmt = $auth_conn->prepare("
                    UPDATE shop_items
                    SET item_entry = ?, name = ?, price = ?, stack = ?, category_id = ?
                    WHERE id = ? LIMIT 1
                ");
                $stmt->bind_param('isdiii', $item_entry, $name, $price, $stack, $category_id, $id);
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $shop_msg = "Updated item #" . $id;
                } else {
                    $shop_msg = "Update failed: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $auth_conn->prepare("DELETE FROM shop_items WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            if ($stmt->execute() && $stmt->affected_rows === 1) {
                $shop_msg = "Deleted item #" . $id;
            } else {
                $shop_msg = "Delete failed or not found.";
            }
            $stmt->close();
        }
    } elseif ($action === 'create_cat') {
        $cat_name = trim($_POST['cat_name'] ?? '');
        if ($cat_name !== '') {
            $stmt = $auth_conn->prepare("INSERT INTO shop_categories (name) VALUES (?)");
            $stmt->bind_param('s', $cat_name);
            if ($stmt->execute()) {
                $shop_msg = "Created category #" . $stmt->insert_id;
            } else {
                $shop_msg = "Create category failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'rename_cat') {
        $cat_id   = (int)($_POST['cat_id'] ?? 0);
        $cat_name = trim($_POST['cat_name'] ?? '');
        if ($cat_id > 0 && $cat_name !== '') {
            $stmt = $auth_conn->prepare("UPDATE shop_categories SET name = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('si', $cat_name, $cat_id);
            if ($stmt->execute()) {
                $shop_msg = "Renamed category";
            } else {
                $shop_msg = "Rename failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'delete_cat') {
        $cat_id = (int)($_POST['cat_id'] ?? 0);
        if ($cat_id > 0) {
            $auth_conn->query("UPDATE shop_items SET category_id = NULL WHERE category_id = " . (int)$cat_id);
            $stmt = $auth_conn->prepare("DELETE FROM shop_categories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $cat_id);
            if ($stmt->execute()) {
                $shop_msg = "Deleted category";
            } else {
                $shop_msg = "Delete category failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch data for rendering
$cats = [];
$res = $auth_conn->query("SELECT id, name FROM shop_categories ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $cats[] = $r; $res->close(); }

$items = [];
$res = $auth_conn->query("
    SELECT i.id, i.item_entry, i.name, i.price, IFNULL(i.stack,1) AS stack, i.category_id, c.name AS category
    FROM shop_items i
    LEFT JOIN shop_categories c ON c.id = i.category_id
    ORDER BY c.name ASC, i.name ASC, i.id ASC
");
if ($res) { while ($r = $res->fetch_assoc()) $items[] = $r; $res->close(); }
?>

<section class="card">
  <h2 style="text-align: center">Shop Items</h2>
  <?php if (!empty($shop_msg)): ?>
    <p style="color:<?php echo strpos($shop_msg, 'fail') !== false ? 'crimson' : 'green'; ?>;">
      <?php echo htmlspecialchars($shop_msg); ?>
    </p>
  <?php endif; ?>

  <!-- Add New Item -->
  <details open>
    <summary><strong>Add a new item</strong></summary>
    <form method="POST" style="margin:.5rem 0; display:grid; grid-template-columns: repeat(6, minmax(120px, 1fr)); gap:.5rem; align-items:end;">
      <input type="hidden" name="shop_action" value="create">
      <label>Item entry
        <input type="number" name="item_entry" min="1" required>
      </label>
      <label>Name
        <input type="text" name="name" maxlength="120" required>
      </label>
      <label>Price
        <input type="number" step="0.01" min="0" name="price" required>
      </label>
      <label>Stack
        <input type="number" min="1" name="stack" value="1" required>
      </label>
      <label>Category
        <select name="category_id">
          <option value="">— None —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Create</button>
    </form>
  </details>

  <!-- Manage Categories -->
  <details style="margin-top:1rem;">
    <summary><strong>Categories</strong></summary>
    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin:.5rem 0;">
      <form method="POST">
        <input type="hidden" name="shop_action" value="create_cat">
        <input type="text" name="cat_name" placeholder="New category name" required>
        <button type="submit">Add Category</button>
      </form>
      <form method="POST">
        <input type="hidden" name="shop_action" value="rename_cat">
        <select name="cat_id" required>
          <?php foreach ($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="cat_name" placeholder="New name" required>
        <button type="submit">Rename</button>
      </form>
      <form method="POST" onsubmit="return confirm('Delete this category? Items will be uncategorized.');">
        <input type="hidden" name="shop_action" value="delete_cat">
        <select name="cat_id" required>
          <?php foreach ($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Delete</button>
      </form>
    </div>
  </details>

  <!-- Existing Items -->
<h3 style="margin-top:1rem; text-align: center">Existing Items</h3>

<form method="POST" id="itemForm" style="margin:.5rem 0; display:grid; grid-template-columns: repeat(6, minmax(120px, 1fr)); gap:.5rem; align-items:end;">
  <input type="hidden" name="shop_action" value="update">
  <input type="hidden" name="id" id="item_id">

  <label>Select Item
    <select id="itemSelect" onchange="populateItem(this.value)" style="width:100%;">
      <option value="" >— Choose an item —</option>
      <?php foreach ($items as $row): ?>
        <option value="<?php echo (int)$row['id']; ?>"
          data-entry="<?php echo (int)$row['item_entry']; ?>"
          data-name="<?php echo htmlspecialchars($row['name']); ?>"
          data-price="<?php echo htmlspecialchars((string)$row['price']); ?>"
          data-stack="<?php echo (int)$row['stack']; ?>"
          data-cat="<?php echo (int)$row['category_id']; ?>">
          [<?php echo (int)$row['item_entry']; ?>] <?php echo htmlspecialchars($row['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label>Item Entry
    <input type="number" name="item_entry" id="item_entry" min="1" required>
  </label>
  <label>Name
    <input type="text" name="name" id="item_name" maxlength="120" required>
  </label>
  <label>Price
    <input type="number" step="0.01" min="0" name="price" id="item_price" required>
  </label>
  <label>Stack
    <input type="number" min="1" name="stack" id="item_stack" required>
  </label>
  <label>Category
    <select name="category_id" id="item_category">
      <option value="">— None —</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <div style="grid-column: span 6; text-align:center;">
    <button type="submit" class="btn btn-save">Save</button>
    <button type="submit" name="shop_action" value="delete" class="btn btn-delete"
      onclick="return confirm('Delete this item?');">Delete</button>
  </div>
</form>

<script>
function populateItem(id) {
  const sel = document.getElementById('itemSelect');
  const opt = sel.querySelector(`option[value="${id}"]`);
  if (!opt) return;

  document.getElementById('item_id').value = id;
  document.getElementById('item_entry').value = opt.dataset.entry || '';
  document.getElementById('item_name').value = opt.dataset.name || '';
  document.getElementById('item_price').value = opt.dataset.price || '';
  document.getElementById('item_stack').value = opt.dataset.stack || '';
  document.getElementById('item_category').value = opt.dataset.cat || '';
}
</script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const getCellValue = (row, index) => {
    const cell = row.children[index];
    if (!cell) return "";
    const input = cell.querySelector("input, select");
    if (input) {
      if (input.tagName === "SELECT") {
        return input.options[input.selectedIndex].text.trim();
      }
      return input.value.trim();
    }
    return cell.textContent.trim();
  };

  const comparer = (index, asc) => (a, b) => {
    const v1 = getCellValue(asc ? a : b, index);
    const v2 = getCellValue(asc ? b : a, index);

    const n1 = parseFloat(v1);
    const n2 = parseFloat(v2);
    if (!isNaN(n1) && !isNaN(n2)) return n1 - n2;

    return v1.localeCompare(v2);
  };

  document.querySelectorAll("table th.sortable").forEach((th, idx) =>
    th.addEventListener("click", () => {
      const table = th.closest("table");
      const rows = Array.from(table.querySelectorAll("tbody tr"));
      const asc = (th.asc = !th.asc);

      rows.sort(comparer(idx, asc)).forEach(tr =>
        table.querySelector("tbody").appendChild(tr)
      );

      // reset arrows
      document.querySelectorAll("table th.sortable .arrow").forEach(el => el.textContent = "");
      th.querySelector(".arrow").textContent = asc ? "↑" : "↓";
    })
  );
});
</script>

</section>

<hr>
<section>
  <h2>BattlePay Shop (Admin CRUD) *UNDER DEVELOPMENT*</h2>
  <h3>** WARNING: Directly modifies BattlePay tables, use with caution! **</h3>
  <p>This section allows you to create, view, and delete BattlePay shop entries.
  <p>Note: BattlePay is currently buggy and items can be gotten for free due to a known exploit. Use at your own risk.</p>
<?php
/* ---------------- Handle Add Full Entry ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bp_action'] ?? '') === 'add_full_entry') {
    $groupId     = (int)($_POST['groupId'] ?? 0);

    $title       = trim($_POST['title'] ?? '');
    $desc        = trim($_POST['description'] ?? '');
    $price       = (int)($_POST['price'] ?? 0);
    $discount    = (int)($_POST['discount'] ?? 0);
    $prodIcon    = (int)($_POST['prod_icon'] ?? 0);
    $prodDispId  = (int)($_POST['prod_displayId'] ?? 0);
    $prodType    = (int)($_POST['prod_type'] ?? 0);
    $choiceType  = (int)($_POST['choiceType'] ?? 0);
    $prodFlags   = (int)($_POST['prod_flags'] ?? 0);
    $flagsInfo   = (int)($_POST['flagsInfo'] ?? 0);

    $itemId      = (int)($_POST['itemId'] ?? 0);
    $itemCount   = (int)($_POST['itemCount'] ?? 1);

    $entryBanner = (int)($_POST['entry_banner'] ?? 0);
    $entryFlags  = (int)($_POST['entry_flags'] ?? 0);

    if ($groupId && $title && $price > 0 && $itemId) {
        $world_conn->begin_transaction();
        try {
            // 1. Product
            $stmt = $world_conn->prepare("
                INSERT INTO battle_pay_product
                    (title, description, icon, price, discount, displayId, type, choiceType, flags, flagsInfo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssiiiiiiii',
                $title, $desc, $prodIcon, $price, $discount,
                $prodDispId, $prodType, $choiceType, $prodFlags, $flagsInfo
            );
            $stmt->execute();
            $productId = $stmt->insert_id;
            $stmt->close();

            // 2. Product Item
            $stmt = $world_conn->prepare("
                INSERT INTO battle_pay_product_items (itemId, count, productId)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iii', $itemId, $itemCount, $productId);
            $stmt->execute();
            $stmt->close();

            // 3. Entry (auto-use product icon + displayId)
            $stmt = $world_conn->prepare("
    INSERT INTO battle_pay_entry
        (productId, groupId, idx, title, description, icon, displayId, banner, flags)
    VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param('iissiiii',
    $productId, $groupId, $title, $desc,
    $prodIcon, $prodDispId, $entryBanner, $entryFlags
);

            $stmt->execute();
            $stmt->close();

            $world_conn->commit();
            echo "<div class='flash ok'>BattlePay entry created successfully!</div>";
        } catch (Throwable $e) {
            $world_conn->rollback();
            echo "<div class='flash err'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='flash err'>Missing required fields.</div>";
    }
}

/* ---------------- Handle Delete ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bp_action'] ?? '') === 'delete_entry') {
    $eid = (int)($_POST['entry_id'] ?? 0);
    if ($eid) {
        $world_conn->query("DELETE FROM battle_pay_entry WHERE id={$eid}");
        echo "<div class='flash ok'>Entry deleted.</div>";
    }
}

/* ---------------- Display Existing Entries ---------------- */
echo "<h3>Current Entries</h3>";
$res = $world_conn->query("
    SELECT e.id AS entryId, e.title AS entryTitle, e.description AS entryDesc,
           e.icon AS entryIcon, e.displayId AS entryDisplayId, e.banner, e.flags AS entryFlags,
           g.name AS groupName, g.id AS groupId,
           p.id AS productId, p.title AS productTitle, p.description AS productDesc,
           p.icon AS prodIcon, p.displayId AS prodDisplayId,
           p.price, p.discount, p.type, p.choiceType, p.flags AS prodFlags, p.flagsInfo,
           i.itemId, i.count
    FROM battle_pay_entry e
    JOIN battle_pay_group g ON g.id = e.groupId
    JOIN battle_pay_product p ON p.id = e.productId
    JOIN battle_pay_product_items i ON i.productId = p.id
    ORDER BY e.id ASC
");
if ($res && $res->num_rows > 0) {
    echo "<div style='overflow:auto; max-height:400px;'>";
    echo "<table border='1' cellpadding='4' cellspacing='0'>";
    echo "<tr>
            <th>Entry ID</th><th>Group</th><th>Entry Title</th><th>Entry Desc</th>
            <th>Entry Icon</th><th>Entry DisplayId</th><th>Banner</th><th>Entry Flags</th>
            <th>Product ID</th><th>Product Title</th><th>Prod Desc</th>
            <th>Prod Icon</th><th>Prod DisplayId</th><th>Price</th><th>Discount</th>
            <th>Type</th><th>ChoiceType</th><th>Prod Flags</th><th>FlagsInfo</th>
            <th>Item ID</th><th>Count</th><th>Action</th>
          </tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>
            <td>{$row['entryId']}</td>
            <td>".htmlspecialchars($row['groupName'])." (#{$row['groupId']})</td>
            <td>".htmlspecialchars($row['entryTitle'])."</td>
            <td>".htmlspecialchars($row['entryDesc'])."</td>
            <td>{$row['entryIcon']}</td>
            <td>{$row['entryDisplayId']}</td>
            <td>{$row['banner']}</td>
            <td>{$row['entryFlags']}</td>
            <td>{$row['productId']}</td>
            <td>".htmlspecialchars($row['productTitle'])."</td>
            <td>".htmlspecialchars($row['productDesc'])."</td>
            <td>{$row['prodIcon']}</td>
            <td>{$row['prodDisplayId']}</td>
            <td>{$row['price']}</td>
            <td>{$row['discount']}</td>
            <td>{$row['type']}</td>
            <td>{$row['choiceType']}</td>
            <td>{$row['prodFlags']}</td>
            <td>{$row['flagsInfo']}</td>
            <td>{$row['itemId']}</td>
            <td>{$row['count']}</td>
            <td>
              <form method='post'>
                <input type='hidden' name='bp_action' value='delete_entry'>
                <input type='hidden' name='entry_id' value='{$row['entryId']}'>
                <button type='submit' onclick=\"return confirm('Delete this entry?');\">Delete</button>
              </form>
            </td>
        </tr>";
    }
    echo "</table></div>";
}
$res->close();

/* ---------------- Group Dropdown ---------------- */
$groups = [];
$res = $world_conn->query("SELECT id, name FROM battle_pay_group ORDER BY idx ASC");
while ($g = $res->fetch_assoc()) $groups[] = $g;
$res->close();
?>

<h3>Add New Entry</h3>
<form method="post">
  <input type="hidden" name="bp_action" value="add_full_entry">

  <fieldset>
    <legend>Group</legend>
    <label for="groupId">Select Group:</label>
    <select name="groupId" id="groupId" required>
      <option value="">-- Select --</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?> (#<?= $g['id'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <small>Determines which shop category (Mounts, Pets, etc.) this entry appears in.</small>
  </fieldset>

  <fieldset>
    <legend>Product</legend>
    <label>Title:
      <input type="text" name="title" required>
    </label><br>
    <small>The product name shown to players in the shop.</small><br><br>

    <label>Description:
      <input type="text" name="description">
    </label><br>
    <small>Additional text that appears under the product name.</small><br><br>

    <label>Price:
      <input type="number" name="price" required>
    </label><br>
    <small>Cost in shop currency/points.</small><br><br>

    <label>Discount:
      <input type="number" name="discount" value="0">
    </label><br>
    <small>Optional discount percentage or flat reduction (server-specific usage).</small><br><br>

    <label>Icon ID:
      <input type="number" name="prod_icon" value="0">
    </label><br>
    <small>UI icon file ID (from WoW client, used to display product image).</small><br><br>

    <label>Display ID:
      <input type="number" name="prod_displayId" value="0">
    </label><br>
    <small>3D model display ID for mounts, pets, or items.</small><br><br>

    <label>Type:
      <input type="number" name="prod_type" value="0">
    </label><br>
    <small>Product type flag (controls how the shop treats this product).</small><br><br>

    <label>Choice Type:
      <input type="number" name="choiceType" value="0">
    </label><br>
    <small>Defines if players can choose options (usually leave at 0).</small><br><br>

    <label>Flags:
      <input type="number" name="prod_flags" value="0">
    </label><br>
    <small>Special product flags (bitmask; controls visibility/behavior).</small><br><br>

    <label>Flags Info:
      <input type="number" name="flagsInfo" value="0">
    </label><br>
    <small>Extra info flags, often unused (leave 0 unless needed).</small>
  </fieldset>

  <fieldset>
    <legend>Product Item</legend>
    <label>Item ID:
      <input type="number" name="itemId" required>
    </label><br>
    <small>The in-game item ID to deliver (check on Wowhead).</small><br><br>

    <label>Count:
      <input type="number" name="itemCount" value="1" min="1">
    </label><br>
    <small>Quantity of the item to deliver.</small>
  </fieldset>

  <fieldset>
    <legend>Entry Settings (Shop Placement)</legend>
    <label>Banner:
      <input type="number" name="entry_banner" value="0">
    </label><br>
    <small>Banner style/slot (0 = none, 1 = featured, 2 = large promo, etc.).</small><br><br>

    <label>Entry Flags:
      <input type="number" name="entry_flags" value="0">
    </label><br>
    <small>Special entry flags for display control (rarely used, leave 0 if unsure).</small>
  </fieldset>

  <button type="submit">Add Entry</button>
</form>

</section>


<style>
th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
th.sortable .arrow { font-size: 0.8em; opacity: 0.6; margin-left: 4px; }
section {
  max-width: 1200px;   /* keeps it from stretching too far */
  margin: 0 auto;      /* centers horizontally */
  padding: 1rem;       /* breathing room so content doesn’t touch edges */
  box-sizing: border-box;
}
</style>
