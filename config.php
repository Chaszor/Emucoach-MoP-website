<?php
// ===== Database Connections =====
$host = "localhost";
$user = "root";
$pass = "ascent";

$auth_db = "auth";             // account database
$characters_db = "characters"; // characters database

$auth_conn = new mysqli($host, $user, $pass, $auth_db);
if ($auth_conn->connect_error) die("Auth DB connection failed: " . $auth_conn->connect_error);

$char_conn = new mysqli($host, $user, $pass, $characters_db);
if ($char_conn->connect_error) die("Characters DB connection failed: " . $char_conn->connect_error);

// (optional) set charset
@$auth_conn->set_charset("utf8mb4");
@$char_conn->set_charset("utf8mb4");
$world_conn = new mysqli($host, $user, $pass, "world");
if ($world_conn->connect_error) die("World DB connection failed: " . $world_conn->connect_error);
@$world_conn->set_charset("utf8mb4");


// ===== SOAP (for in-game delivery) =====
// Ensure SOAP is enabled in worldserver.conf and credentials have GM permission.
$soap_host   = "127.0.0.1";    // worldserver SOAP host
$soap_port   = 7878;           // worldserver SOAP port
$soap_user   = "";      // GM account username
$soap_pass   = "";   // GM account password

// Load SOAP enabled flag from site_settings
$soap_enabled = false; // default
$res = $auth_conn->prepare("SELECT value FROM site_settings WHERE `key`='soap_enabled' LIMIT 1");
if ($res && $res->execute()) {
    $res->bind_result($val);
    if ($res->fetch()) {
        $soap_enabled = ($val === "1" || strtolower($val) === "true");
    }
    $res->close();
}

function sendSoap($command) {
    global $soap_enabled, $soap_host, $soap_port, $soap_user, $soap_pass;
    if (!$soap_enabled) return [false, "SOAP disabled"];

    try {
        $client = new SoapClient(null, [
            'location'   => "http://{$soap_host}:{$soap_port}/",
            'uri'        => "urn:TC",
            'login'      => $soap_user,
            'password'   => $soap_pass,
            'trace'      => 1,
            'exceptions' => true,
        ]);

        $params = [ new SoapParam($command, "command") ];
        $result = $client->__soapCall("executeCommand", $params);

        // Debug logging
        error_log("SOAP sent: $command");
        error_log("SOAP result: " . print_r($result, true));

        if (is_array($result)) $result = implode("\n", $result);
        if (!is_string($result)) $result = "OK";

        return [true, $result];
    } catch (Exception $e) {
        error_log("SOAP error: " . $e->getMessage());
        return [false, $e->getMessage()];
    }
}


/**
 * Deliver via DB mail (no SOAP). Adjust column names if your schema differs:
 *   DESCRIBE mail;  DESCRIBE mail_items;
 */
function deliverViaDBMail($char_conn, $char_guid, $item_entry, $item_count, $subject, $body) {
    // Normalize inputs
    $char_guid  = (int)$char_guid;
    $item_entry = (int)$item_entry;
    $item_count = max(1, (int)$item_count);

    // Mail meta (matches your schema)
    $now          = time();
    $deliver_time = $now;
    $expire_time  = $now + (30 * 24 * 3600);
    $messageType  = 0;
    $stationery   = 41;
    $mailTemplateId = 0;
    $sender       = 0; // system
    $has_items    = 1;
    $money        = 0;
    $cod          = 0;
    $checked      = 0;

    // Required NOT NULL fields in item_instance without defaults
    $enchantments = ''; // text NOT NULL
    $pet_species  = 0;
    $pet_breed    = 0;
    $pet_quality  = 0;
    $pet_level    = 0;

    try {
        $char_conn->begin_transaction();

        // 1) Insert mail
        $stmt = $char_conn->prepare("
            INSERT INTO mail
            (messageType, stationery, mailTemplateId, sender, receiver, subject, body,
             has_items, expire_time, deliver_time, money, cod, checked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception("prepare mail failed: ".$char_conn->error);
        $stmt->bind_param(
            "iiiisssiiiiii",
            $messageType, $stationery, $mailTemplateId, $sender, $char_guid,
            $subject, $body, $has_items, $expire_time, $deliver_time, $money, $cod, $checked
        );
        if (!$stmt->execute()) throw new Exception("mail insert failed: ".$stmt->error);
        $mail_id = $stmt->insert_id;
        $stmt->close();

        // 2) Insert item_instance with GUID computed IN the statement (atomic, no race)
        //    SELECT IFNULL(MAX(guid),0)+1 FROM item_instance FOR UPDATE
        $stmt = $char_conn->prepare("
            INSERT INTO item_instance
                (guid, itemEntry, owner_guid, count, enchantments, pet_species, pet_breed, pet_quality, pet_level)
            SELECT
                IFNULL(MAX(guid), 0) + 1, ?, ?, ?, ?, ?, ?, ?, ?
            FROM item_instance
            FOR UPDATE
        ");
        if (!$stmt) throw new Exception("prepare item_instance failed: ".$char_conn->error);
        // types: i i i s i i i i   (itemEntry, owner_guid, count, enchantments, pet_species, pet_breed, pet_quality, pet_level)
        $stmt->bind_param("iiisiiii",
            $item_entry, $char_guid, $item_count,
            $enchantments, $pet_species, $pet_breed, $pet_quality, $pet_level
        );
        if (!$stmt->execute()) throw new Exception("item_instance insert failed: ".$stmt->error);
        $stmt->close();

        // Get the guid we just inserted (it’s the new MAX)
        $res = $char_conn->query("SELECT MAX(guid) AS new_guid FROM item_instance");
        if (!$res) throw new Exception("fetch new guid failed: ".$char_conn->error);
        $row = $res->fetch_assoc();
        $new_guid = (int)$row['new_guid'];
        $res->close();
        if ($new_guid <= 0) throw new Exception("computed invalid item guid");

        // 3) Attach item to mail
        // Your mail_items schema: (mail_id, item_guid, receiver)
        $stmt = $char_conn->prepare("
            INSERT INTO mail_items (mail_id, item_guid, receiver)
            VALUES (?, ?, ?)
        ");
        if (!$stmt) throw new Exception("prepare mail_items failed: ".$char_conn->error);
        $stmt->bind_param("iii", $mail_id, $new_guid, $char_guid);
        if (!$stmt->execute()) throw new Exception("mail_items insert failed: ".$stmt->error);
        $stmt->close();

        $char_conn->commit();
        return [true, "DB mail queued (mail_id={$mail_id}, item_guid={$new_guid})"];
    } catch (Throwable $e) {
        $char_conn->rollback();
        return [false, $e->getMessage()];
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $msg): void {
        $cls = $type === 'ok' ? 'ok' : 'err';
        echo "<div class='flash {$cls}'>" . htmlspecialchars($msg) . "</div>";
    }
}
$admin_user = $_SESSION['username'] ?? 'system';
$action     = $_POST['action'] ?? null;


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