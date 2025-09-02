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
  <h3>Shop Items</h3>
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
  <h4 style="margin-top:1rem;">Existing items</h4>
  <div style="max-width:100%; overflow:auto;">
    <table>
      <thead>
        <tr>
          <th class="sortable">ID <span class="arrow"></span></th>
          <th class="sortable">Item Entry <span class="arrow"></span></th>
          <th class="sortable">Name <span class="arrow"></span></th>
          <th class="sortable">Price <span class="arrow"></span></th>
          <th class="sortable">Stack <span class="arrow"></span></th>
          <th class="sortable">Category <span class="arrow"></span></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <form method="POST">
              <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
              <td><?php echo (int)$row['id']; ?></td>
              <td><input type="number" name="item_entry" min="1" value="<?php echo (int)$row['item_entry']; ?>" required></td>
              <td><input type="text" name="name" value="<?php echo htmlspecialchars($row['name']); ?>" maxlength="120" required></td>
              <td><input type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars((string)$row['price']); ?>" required></td>
              <td><input type="number" min="1" name="stack" value="<?php echo (int)$row['stack']; ?>" required></td>
              <td>
                <select name="category_id">
                  <option value="" <?php echo $row['category_id'] ? '' : 'selected'; ?>>— None —</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$row['category_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="actions">
                <button type="submit" name="shop_action" value="update" class="btn btn-save">Save</button>
                <button type="submit" name="shop_action" value="delete" class="btn btn-delete" formnovalidate onclick="return confirm('Delete item #<?php echo (int)$row['id']; ?>?');">Delete</button>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
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
<style>
th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
th.sortable .arrow { font-size: 0.8em; opacity: 0.6; margin-left: 4px; }
</style>
