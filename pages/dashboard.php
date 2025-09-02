<?php
include("../config.php");
include("../includes/header.php");

// Map race IDs to names
// Races
$races = [
    1  => "Human",
    2  => "Orc",
    3  => "Dwarf",
    4  => "Night Elf",
    5  => "Undead",
    6  => "Tauren",
    7  => "Gnome",
    8  => "Troll",
    10 => "Blood Elf",
    11 => "Draenei",
    22 => "Worgen",
    26 => "Pandaren",
    9 => "Goblin"
];

// Classes
$classes = [
    1  => "Warrior",
    2  => "Paladin",
    3  => "Hunter",
    4  => "Rogue",
    5  => "Priest",
    6  => "Death Knight",
    7  => "Shaman",
    8  => "Mage",
    9  => "Warlock",
    11 => "Druid",
    10 => "Monk"
];

// Gender
$genders = [
    0 => "Male",
    1 => "Female"
];



if (!isset($_SESSION["username"])) {
    echo "<p style='color:red'>You must be logged in to view this page.</p>";
    include("../includes/footer.php");
    exit;
}

// Get account ID from account table
$stmt = $auth_conn->prepare("SELECT id FROM account WHERE username=?");
$stmt->bind_param("s", $_SESSION["username"]);
$stmt->execute();
$stmt->bind_result($account_id);
$stmt->fetch();
$stmt->close();

if ($account_id) {
    // Query characters tied to this account
    $stmt = $char_conn->prepare("
        SELECT name, level, race, class, gender 
        FROM characters 
        WHERE account=? 
        ORDER BY level DESC
    ");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    echo "<section class=\"panel\">";
    echo "<h2>Welcome, " . htmlspecialchars($_SESSION["username"]) . "!</h2>";
    echo "</section>";
    echo "<h3>Your Characters:</h3>";

    if ($result->num_rows > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>
                <tr>
                    <th>Name</th>
                    <th>Level</th>
                    <th>Race</th>
                    <th>Class</th>
                    <th>Gender</th>
                </tr>";
        while ($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>" . htmlspecialchars($row['name']) . "</td>
                <td>" . $row['level'] . "</td>
                <td>" . ($races[$row['race']] ?? "Unknown") . "</td>
                <td>" . ($classes[$row['class']] ?? "Unknown") . "</td>
                <td>" . ($genders[$row['gender']] ?? "Unknown") . "</td>
            </tr>";
    }

        echo "</table>";
    } else {
        echo "<p>You have no characters yet.</p>";
    }
    $stmt->close();
} else {
    echo "<p>Account not found.</p>";
}

include("../includes/footer.php");
?>
