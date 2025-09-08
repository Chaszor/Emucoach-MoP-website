<?php
// pages/news.php
// Admin section for managing News entries.

require_once __DIR__ . '/../../config.php';

if (!isset($auth_conn) || $auth_conn->connect_error) {
    echo "<div class='flash err'>Database connection error.</div>";
    return;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            if (!empty($_POST['title']) && !empty($_POST['content'])) {
                $stmt = $auth_conn->prepare("INSERT INTO news (title, content) VALUES (?, ?)");
                $stmt->bind_param("ss", $_POST['title'], $_POST['content']);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>News entry added.</div>";
            }
            break;

        case 'edit':
            if (isset($_POST['id'], $_POST['title'], $_POST['content'])) {
                $id = (int)$_POST['id'];
                $stmt = $auth_conn->prepare("UPDATE news SET title=?, content=? WHERE id=?");
                $stmt->bind_param("ssi", $_POST['title'], $_POST['content'], $id);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>News entry #$id updated.</div>";
            }
            break;

        case 'delete':
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
                $stmt = $auth_conn->prepare("DELETE FROM news WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                echo "<div class='flash ok'>News entry #$id deleted.</div>";
            }
            break;
    }
}

// Fetch list
$res = $auth_conn->query("SELECT id, title, content, created_at FROM news ORDER BY created_at DESC");
?>

<section class="card" style="max-width:600px; margin:auto;">
  <h2 style="text-align: center;">News Management</h2>

  <!-- Add form -->
  <form method="post" style="margin-bottom:1rem;">
    <input type="hidden" name="action" value="add">
    <label>Title:<br>
      <input type="text" name="title" style="width:100%;" required>
    </label><br><br>
    <label>Content:<br>
      <textarea name="content" rows="4" style="width:100%;" required></textarea>
    </label><br><br>
    <button type="submit">Add News</button>
  </form>

  <table class="table-cards" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Content</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($row = $res->fetch_assoc()): ?>
      <tr>
        <td><?= (int)$row['id'] ?></td>
        <td>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <input type="text" name="title" value="<?= htmlspecialchars($row['title']) ?>" style="width:200px;">
        </td>
        <td>
            <textarea name="content" rows="3" style="width:100%;"><?= htmlspecialchars($row['content']) ?></textarea>
        </td>
        <td><?= htmlspecialchars($row['created_at']) ?></td>
        <td>
            <div style="display:flex; gap:6px;">
              <button type="submit" style="background:green; color:white;">Save</button>
          </form>
              <form method="post" style="margin:0;"
                    onsubmit="return confirm('Delete this news entry?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" style="background:red; color:white;">Delete</button>
              </form>
            </div>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</section>
