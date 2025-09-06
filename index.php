<?php 
include(__DIR__ . "/config.php");
include("includes/header.php"); ?>
<section class="card">
<?php
if ($stmt = $auth_conn->prepare("SELECT id, title, content, created_at 
                                FROM news 
                                ORDER BY created_at DESC 
                                LIMIT 5")) {
    $stmt->execute();
    $result = $stmt->get_result();

    echo "<div class='news-section'>";
    echo "<h1 style='text-align: center; text-decoration: underline;'>Latest News</h1>";

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<div class='news-item'>";
            echo "<h3>" . htmlspecialchars($row['title']) . "</h3>";
            echo "<p>" . nl2br(htmlspecialchars($row['content'])) . "</p>";
            echo "<small>Posted on " . date("F j, Y, g:i a", strtotime($row['created_at'])) . "</small>";
            echo "<hr>";
            echo "</div>";
        }
    } else {
        echo "<p>No news available at the moment.</p>";
    }

    echo "</div>";
    $stmt->close();
}
?>
</section>
<?php include("includes/footer.php"); ?>



<?php
//require_once __DIR__ . '/config.php'; // database connection ($auth_conn)


?>
