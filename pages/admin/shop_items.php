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

/* --- Subcategories support (tables + relationships) --- */
@$auth_conn->query("
    CREATE TABLE IF NOT EXISTS shop_subcategories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        CONSTRAINT fk_shop_subcategories_category
            FOREIGN KEY (category_id) REFERENCES shop_categories(id)
            ON DELETE CASCADE,
        UNIQUE KEY uniq_cat_name (category_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Add subcategory_id to shop_items if missing (idempotent) */
$hasSubCol = false;
if ($res = $auth_conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'shop_items'
                                AND COLUMN_NAME = 'subcategory_id' LIMIT 1")) {
    $hasSubCol = (bool)$res->fetch_row();
    $res->close();
}
if (!$hasSubCol) {
    @$auth_conn->query("ALTER TABLE shop_items
        ADD COLUMN subcategory_id INT NULL,
        ADD KEY idx_shop_items_subcategory (subcategory_id),
        ADD CONSTRAINT fk_shop_items_subcategory
            FOREIGN KEY (subcategory_id) REFERENCES shop_subcategories(id)
            ON DELETE SET NULL
    ");
}

$shop_msg = "";

// Handle shop-specific actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shop_action'])) {
    $action = $_POST['shop_action'];

    if ($action === 'create' || $action === 'update') {
        $id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $item_entry     = (int)($_POST['item_entry'] ?? 0);
        $name           = trim($_POST['name'] ?? '');
        $price          = (float)($_POST['price'] ?? 0);
        $stack          = max(1, (int)($_POST['stack'] ?? 1));
        $category_id    = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
        $subcategory_id = isset($_POST['subcategory_id']) && $_POST['subcategory_id'] !== '' ? (int)$_POST['subcategory_id'] : null;

        // Ensure subcategory belongs to selected category (or null out)
        if ($subcategory_id && $category_id) {
            $chk = $auth_conn->prepare("SELECT 1 FROM shop_subcategories WHERE id = ? AND category_id = ? LIMIT 1");
            $chk->bind_param('ii', $subcategory_id, $category_id);
            $chk->execute();
            $ok = (bool)$chk->get_result()->fetch_row();
            $chk->close();
            if (!$ok) $subcategory_id = null;
        } elseif ($subcategory_id && !$category_id) {
            $subcategory_id = null;
        }

        if ($name === '' || $item_entry <= 0) {
            $shop_msg = "Item name and a valid item_entry are required.";
        } else {
            if ($action === 'create') {
                $stmt = $auth_conn->prepare("
                    INSERT INTO shop_items (item_entry, name, price, stack, category_id, subcategory_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('isdiii', $item_entry, $name, $price, $stack, $category_id, $subcategory_id);
                if ($stmt->execute()) {
                    $shop_msg = "Created item #" . $stmt->insert_id;
                } else {
                    $shop_msg = "Create failed: " . $stmt->error;
                }
                $stmt->close();
            } else { // update
                $stmt = $auth_conn->prepare("
                    UPDATE shop_items
                    SET item_entry = ?, name = ?, price = ?, stack = ?, category_id = ?, subcategory_id = ?
                    WHERE id = ? LIMIT 1
                ");
                $stmt->bind_param('isdiiii', $item_entry, $name, $price, $stack, $category_id, $subcategory_id, $id);
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
            // Unlink items from this category and any of its subcategories
            $auth_conn->query("UPDATE shop_items SET subcategory_id = NULL WHERE category_id = " . (int)$cat_id);
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
    } elseif ($action === 'create_subcat') {
        $parent_category_id = (int)($_POST['parent_category_id'] ?? 0);
        $subcat_name        = trim($_POST['subcat_name'] ?? '');
        if ($parent_category_id > 0 && $subcat_name !== '') {
            $stmt = $auth_conn->prepare("INSERT INTO shop_subcategories (category_id, name) VALUES (?, ?)");
            $stmt->bind_param('is', $parent_category_id, $subcat_name);
            if ($stmt->execute()) {
                $shop_msg = "Created subcategory #" . $stmt->insert_id;
            } else {
                $shop_msg = "Create subcategory failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'rename_subcat') {
        $subcat_id   = (int)($_POST['subcat_id'] ?? 0);
        $subcat_name = trim($_POST['subcat_name'] ?? '');
        if ($subcat_id > 0 && $subcat_name !== '') {
            $stmt = $auth_conn->prepare("UPDATE shop_subcategories SET name = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('si', $subcat_name, $subcat_id);
            if ($stmt->execute()) {
                $shop_msg = "Renamed subcategory";
            } else {
                $shop_msg = "Rename subcategory failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'delete_subcat') {
        $subcat_id = (int)($_POST['subcat_id'] ?? 0);
        if ($subcat_id > 0) {
            $auth_conn->query("UPDATE shop_items SET subcategory_id = NULL WHERE subcategory_id = " . (int)$subcat_id);
            $stmt = $auth_conn->prepare("DELETE FROM shop_subcategories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $subcat_id);
            if ($stmt->execute()) {
                $shop_msg = "Deleted subcategory";
            } else {
                $shop_msg = "Delete subcategory failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch data for rendering
$cats = [];
$res = $auth_conn->query("SELECT id, name FROM shop_categories ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $cats[] = $r; $res->close(); }

$subcats_by_cat = [];
$res = $auth_conn->query("SELECT id, category_id, name FROM shop_subcategories ORDER BY category_id ASC, name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $cid = (int)$r['category_id'];
        if (!isset($subcats_by_cat[$cid])) $subcats_by_cat[$cid] = [];
        $subcats_by_cat[$cid][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    }
    $res->close();
}

$items = [];
$res = $auth_conn->query("
    SELECT i.id, i.item_entry, i.name, i.price, IFNULL(i.stack,1) AS stack,
           i.category_id, c.name AS category,
           i.subcategory_id, s.name AS subcategory
    FROM shop_items i
    LEFT JOIN shop_categories c   ON c.id = i.category_id
    LEFT JOIN shop_subcategories s ON s.id = i.subcategory_id
    ORDER BY c.name ASC, s.name ASC, i.name ASC, i.id ASC
");
if ($res) { while ($r = $res->fetch_assoc()) $items[] = $r; $res->close(); }
?>

<section class="card" style="max-width:600px; margin:auto;">
  <h2 style="text-align: center">Shop Items</h2>
  <?php if (!empty($shop_msg)): ?>
    <p style="color:<?php echo strpos($shop_msg, 'fail') !== false ? 'crimson' : 'green'; ?>;">
      <?php echo htmlspecialchars($shop_msg); ?>
    </p>
  <?php endif; ?>

  <!-- Add New Item -->
  <details open>
    <summary><strong>Add a new item</strong></summary>
    <form method="POST" class="table-cards" >
      <input type="hidden" name="shop_action" value="create">
      <label>Item entry
        <input type="number" name="item_entry" min="1" required>
      </label><br>
      <label>Name
        <input type="text" name="name" maxlength="120" required>
      </label><br>
      <label>Price
        <input type="number" step="0.01" min="0" name="price" required>
      </label><br>
      <label>Stack
        <input type="number" min="1" name="stack" value="1" required>
      </label><br>
      <label>Category
        <select name="category_id" id="new_category">
          <option value="">— None —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Subcategory
        <select name="subcategory_id" id="new_subcategory">
          <option value="">— None —</option>
        </select>
      </label>
      <button type="submit" style="grid-column: span 6;">Create</button>
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

  <!-- Manage Subcategories -->
  <details style="margin-top:1rem;">
    <summary><strong>Subcategories</strong></summary>
    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin:.5rem 0;">
      <!-- Create subcategory -->
      <form method="POST">
        <input type="hidden" name="shop_action" value="create_subcat">
        <select name="parent_category_id" required>
          <option value="">— Pick category —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="subcat_name" placeholder="New subcategory name" required>
        <button type="submit">Add Subcategory</button>
      </form>

      <!-- Rename subcategory -->
      <form method="POST">
        <input type="hidden" name="shop_action" value="rename_subcat">
        <select name="subcat_id" required>
          <?php foreach ($cats as $c): ?>
            <?php $cid = (int)$c['id']; ?>
            <?php if (!empty($subcats_by_cat[$cid])): ?>
              <optgroup label="<?php echo htmlspecialchars($c['name']); ?>">
                <?php foreach ($subcats_by_cat[$cid] as $sc): ?>
                  <option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <input type="text" name="subcat_name" placeholder="New name" required>
        <button type="submit">Rename</button>
      </form>

      <!-- Delete subcategory -->
      <form method="POST" onsubmit="return confirm('Delete this subcategory? Items linked to it will be unassigned.');">
        <input type="hidden" name="shop_action" value="delete_subcat">
        <select name="subcat_id" required>
          <?php foreach ($cats as $c): ?>
            <?php $cid = (int)$c['id']; ?>
            <?php if (!empty($subcats_by_cat[$cid])): ?>
              <optgroup label="<?php echo htmlspecialchars($c['name']); ?>">
                <?php foreach ($subcats_by_cat[$cid] as $sc): ?>
                  <option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
        <button type="submit">Delete</button>
      </form>
    </div>
  </details>

  <!-- Existing Items -->
<h3 style="margin-top:1rem; text-align: center">Existing Items</h3>

<form method="POST" id="itemForm" class="table-cards">
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
          data-cat="<?php echo (int)$row['category_id']; ?>"
          data-subcat="<?php echo (int)$row['subcategory_id']; ?>">
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
  <label>Subcategory
    <select name="subcategory_id" id="item_subcategory">
      <option value="">— None —</option>
    </select>
  </label>

  <div style="grid-column: span 6; text-align:center;">
    <button type="submit" class="btn btn-save">Save</button>
    <button type="submit" name="shop_action" value="delete" class="btn btn-delete"
      onclick="return confirm('Delete this item?');">Delete</button>
  </div>
</form>

<script>
const SUBCATS_BY_CAT = <?php echo json_encode($subcats_by_cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function populateItem(id) {
  const sel = document.getElementById('itemSelect');
  const opt = sel.querySelector(`option[value="${id}"]`);
  if (!opt) return;

  document.getElementById('item_id').value = id;
  document.getElementById('item_entry').value = opt.dataset.entry || '';
  document.getElementById('item_name').value = opt.dataset.name || '';
  document.getElementById('item_price').value = opt.dataset.price || '';
  document.getElementById('item_stack').value = opt.dataset.stack || '';

  const catSel = document.getElementById('item_category');
  const subSel = document.getElementById('item_subcategory');
  const catId  = opt.dataset.cat || '';
  const subId  = opt.dataset.subcat || '';

  catSel.value = catId || '';

  // Repopulate subcats for the chosen category
  repopulateSubcats(catSel, subSel, subId);
}

function repopulateSubcats(catSelect, subSelect, selectedId) {
  const catId = parseInt(catSelect.value || "0", 10);
  subSelect.innerHTML = '<option value="">— None —</option>';
  if (catId && SUBCATS_BY_CAT[catId]) {
    for (const sc of SUBCATS_BY_CAT[catId]) {
      const opt = document.createElement('option');
      opt.value = sc.id;
      opt.textContent = sc.name;
      subSelect.appendChild(opt);
    }
  }
  if (selectedId) subSelect.value = String(selectedId);
}

document.addEventListener('DOMContentLoaded', () => {
  const newCat = document.getElementById('new_category');
  const newSub = document.getElementById('new_subcategory');
  const editCat = document.getElementById('item_category');
  const editSub = document.getElementById('item_subcategory');

  if (newCat && newSub) {
    newCat.addEventListener('change', () => repopulateSubcats(newCat, newSub, null));
  }
  if (editCat && editSub) {
    editCat.addEventListener('change', () => repopulateSubcats(editCat, editSub, null));
  }
});
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

<?php include("battlepay_shop.php"); ?>


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
