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