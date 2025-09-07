<?php
/**********************************************************
 * Award playtime coins in real time
 * - Uses characters.online instead of totaltime
 * - Coins per interval/minute from auth.site_settings
 * - Tracks elapsed time between cron runs
 * - No awards if no character is online
 * - Optional SOAP mail notification
 * - Optional Anti-AFK (xp/level/map checks)
 * - Writes debug output to award_debug.log
 **********************************************************/

/** ====== CONFIG (defaults; overridden by site_settings) ====== */
$db = [
  'host' => 'localhost',
  'user' => 'root',
  'pass' => 'ascent',
  'auth' => 'auth',
  'chars'=> 'characters',
];

$award = [
  'coins_per_minute' => 1.0,
  'min_minutes'      => 1,
  'online_credit' => [
    'enabled'      => true,
    'per_run_cap'  => 5,
  ],
  'anti_afk' => [
    'enabled' => false,
    'min_xp_delta' => 50,
    'min_level_delta' => 1,
    'min_map_changes' => 1,
  ],
];

$soap = [
  'enabled'   => false,
  'host'      => '127.0.0.1',
  'port'      => 7878,
  'user'      => '',
  'pass'      => '',
  'from'      => 'Server',
  'subject'   => 'Thanks for playing!',
  'body_tpl'  => 'You earned %d coin(s) for active playtime. Enjoy!',
];

// CLI flags
$argv = $argv ?? [];
$DRY_RUN = in_array('--dry-run', $argv, true);
$VERBOSE = in_array('--verbose', $argv, true);

/** ====== DEBUG LOG ====== */
$debugLog = __DIR__ . "/award_debug.log";
file_put_contents($debugLog, ""); // overwrite each run
function log_line($msg, $VERBOSE) {
  global $debugLog;
  if ($VERBOSE) echo $msg . PHP_EOL;
  file_put_contents($debugLog, $msg . PHP_EOL, FILE_APPEND);
}

/** ====== DB CONNECTIONS ====== */
$auth = new mysqli($db['host'], $db['user'], $db['pass'], $db['auth']);
if ($auth->connect_error) die("Auth DB connection failed: " . $auth->connect_error);
$auth->set_charset('utf8mb4');

$chars = new mysqli($db['host'], $db['user'], $db['pass'], $db['chars']);
if ($chars->connect_error) die("Characters DB connection failed: " . $chars->connect_error);
$chars->set_charset('utf8mb4');

/** ====== SUPPORTING TABLE ====== */
$auth->query("
  CREATE TABLE IF NOT EXISTS playtime_rewards (
    account_id          INT UNSIGNED PRIMARY KEY,
    last_seen_online_at TIMESTAMP NULL DEFAULT NULL,
    last_xp             BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_level          INT UNSIGNED NOT NULL DEFAULT 0,
    last_map            INT UNSIGNED NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/** ====== SETTINGS FROM auth.site_settings ====== */
function get_setting($mysqli, $key, $default = null) {
  $stmt = @$mysqli->prepare("SELECT `value` FROM site_settings WHERE `key`=? LIMIT 1");
  if (!$stmt) return $default;
  $stmt->bind_param("s", $key);
  if (!$stmt->execute()) { $stmt->close(); return $default; }
  $stmt->bind_result($val);
  $has = $stmt->fetch();
  $stmt->close();
  return $has ? $val : $default;
}
function get_boolish($mysqli, $key, $default) {
  $v = get_setting($mysqli, $key, null);
  if ($v === null) return (bool)$default;
  $s = strtolower(trim((string)$v));
  return in_array($s, ['1','true','on','yes','y'], true);
}
function get_intish($mysqli, $key, $default) {
  $v = get_setting($mysqli, $key, null);
  return ($v === null || !is_numeric($v)) ? (int)$default : (int)$v;
}
function get_floatish($mysqli, $key, $default) {
  $v = get_setting($mysqli, $key, null);
  return ($v === null || !is_numeric($v)) ? (float)$default : (float)$v;
}

// Coins per minute
$interval_minutes    = max(1, get_intish($auth, 'interval_minutes', 10));
$coins_per_interval  = max(0.0, get_floatish($auth, 'coins_per_interval', 1.0));
$award['coins_per_minute'] = $coins_per_interval / $interval_minutes;

// Overrides
$award['min_minutes'] = max(1, get_intish($auth, 'min_minutes', $award['min_minutes']));
$award['online_credit']['per_run_cap'] = max(
  1,
  get_intish($auth, 'online_per_run_cap', $award['online_credit']['per_run_cap'])
);

// Anti-AFK toggle
$award['anti_afk']['enabled'] = get_boolish($auth, 'require_activity', $award['anti_afk']['enabled']);

// SOAP
// Keep global SOAP connectivity values as-is (host/port/user/pass)
$soap['enabled'] = get_boolish($auth, 'soap_enabled', $soap['enabled']); // global on/off for SOAP availability
$soap['mail_enabled'] = get_boolish($auth, 'playtime_mail_enabled', true); 
// NEW: per-feature toggle for playtime mail only (default true or false as you prefer)
$soap['mail_enabled'] = get_boolish($auth, 'playtime_mail_enabled', true);
$soapHost = get_setting($auth, 'soap_host', null);    if ($soapHost !== null) $soap['host'] = $soapHost;
$soapPort = get_setting($auth, 'soap_port', null);    if ($soapPort !== null) $soap['port'] = (int)$soapPort;
$soapUser = get_setting($auth, 'soap_user', null);    if ($soapUser !== null) $soap['user'] = $soapUser;
$soapPass = get_setting($auth, 'soap_pass', null);    if ($soapPass !== null) $soap['pass'] = $soapPass;
$soapFrom = get_setting($auth, 'soap_from', null);    if ($soapFrom !== null) $soap['from'] = $soapFrom;
$soapSubj = get_setting($auth, 'soap_subject', null); if ($soapSubj !== null) $soap['subject'] = $soapSubj;
$soapBody = get_setting($auth, 'soap_body_tpl', null);if ($soapBody !== null) $soap['body_tpl'] = $soapBody;

/** ====== HELPERS ====== */
function sendSoapMail($soap, $playerName, $subject, $body) {
  if (!$soap['enabled'] && !$soap['mail_enabled']) return true;
  $xml = sprintf(
    '<?xml version="1.0" encoding="utf-8"?>' .
    '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">' .
    '<SOAP-ENV:Body><ns1:executeCommand xmlns:ns1="urn:TC">' .
    '<command>send mail %s "%s" "%s"</command>' .
    '</ns1:executeCommand></SOAP-ENV:Body></SOAP-ENV:Envelope>',
    htmlspecialchars($playerName, ENT_QUOTES),
    htmlspecialchars($subject, ENT_QUOTES),
    htmlspecialchars($body, ENT_QUOTES)
  );
  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => [
        "Content-Type: text/xml; charset=utf-8",
        "Authorization: Basic " . base64_encode($soap['user'] . ":" . $soap['pass']),
        "Content-Length: " . strlen($xml),
      ],
      'content' => $xml,
      'timeout' => 5,
    ]
  ]);
  $url = "http://{$soap['host']}:{$soap['port']}/";
  $result = @file_get_contents($url, false, $ctx);
  return $result !== false;
}

/** ====== PREPARED ====== */
$getOnlineChars = $chars->prepare(
  "SELECT account, name, xp, level, map FROM characters WHERE online=1"
);
$updCash    = $auth->prepare("UPDATE account SET cash = cash + ? WHERE id = ?");
$getTrack   = $auth->prepare("SELECT last_seen_online_at, last_xp, last_level, last_map FROM playtime_rewards WHERE account_id=?");
$upsertSeen = $auth->prepare("
  INSERT INTO playtime_rewards (account_id, last_seen_online_at, last_xp, last_level, last_map)
  VALUES (?, NOW(), ?, ?, ?)
  ON DUPLICATE KEY UPDATE 
    last_seen_online_at=NOW(),
    last_xp=VALUES(last_xp),
    last_level=VALUES(last_level),
    last_map=VALUES(last_map)
");

/** ====== MAIN ====== */
$awarded_total_accounts = 0;
$awarded_total_coins    = 0;
$afk_blocked            = 0;

if ($getOnlineChars->execute()) {
  $getOnlineChars->bind_result($accountId, $charName, $curXp, $curLevel, $curMap);
  while ($getOnlineChars->fetch()) {
    $lastSeen = null; $lastSeenTs = null;
    $lastXp = 0; $lastLevel = 0; $lastMap = 0;

    $getTrack->bind_param('i', $accountId);
    $getTrack->execute();
    $getTrack->bind_result($lastSeenStr, $lastXp, $lastLevel, $lastMap);
    if ($getTrack->fetch()) {
      if ($lastSeenStr) $lastSeenTs = strtotime($lastSeenStr);
    }
    $getTrack->free_result();

    $now = time();
    $elapsed = $lastSeenTs ? floor(($now - $lastSeenTs) / 60) : 0;

    if ($elapsed <= 0) {
      log_line("[acct $accountId] Online but no elapsed minutes", $VERBOSE);
      $aid = (int)$accountId;
      $xp  = (int)$curXp;
      $lvl = (int)$curLevel;
      $map = (int)$curMap;

      $upsertSeen->bind_param('iiii', $aid, $xp, $lvl, $map);
      $upsertSeen->execute();
      continue;
    }

    // Anti-AFK check
    if ($award['anti_afk']['enabled']) {
      $xpDelta    = $curXp - $lastXp;
      $levelDelta = $curLevel - $lastLevel;
      $mapDelta   = ($curMap !== $lastMap) ? 1 : 0;

      if ($xpDelta < $award['anti_afk']['min_xp_delta']
          && $levelDelta < $award['anti_afk']['min_level_delta']
          && $mapDelta < $award['anti_afk']['min_map_changes']) {
        log_line("[acct $accountId] Skipped: AFK (xpΔ=$xpDelta lvlΔ=$levelDelta mapΔ=$mapDelta)", $VERBOSE);
        $aid = (int)$accountId;
        $xp  = (int)$curXp;
        $lvl = (int)$curLevel;
        $map = (int)$curMap;

        $upsertSeen->bind_param('iiii', $aid, $xp, $lvl, $map);
        $upsertSeen->execute();
        $afk_blocked++;
        continue;
      }
    }

    $cap = $award['online_credit']['per_run_cap'];
    $minutes = min($elapsed, $cap);

    if ($minutes < $award['min_minutes']) {
      log_line("[acct $accountId] Skipped: insufficient minutes (have $minutes, need {$award['min_minutes']})", $VERBOSE);
            $aid = (int)$accountId;
      $xp  = (int)$curXp;
      $lvl = (int)$curLevel;
      $map = (int)$curMap;

      $upsertSeen->bind_param('iiii', $aid, $xp, $lvl, $map);
      $upsertSeen->execute();
      continue;
    }

    $coins = (int) floor($minutes * $award['coins_per_minute']);
    if ($coins <= 0) {
      log_line("[acct $accountId] Skipped: 0 coins computed", $VERBOSE);
            $aid = (int)$accountId;
      $xp  = (int)$curXp;
      $lvl = (int)$curLevel;
      $map = (int)$curMap;

      $upsertSeen->bind_param('iiii', $aid, $xp, $lvl, $map);
      $upsertSeen->execute();
      continue;
    }

    if ($DRY_RUN) {
      log_line("DRY_RUN: would award $coins coin(s) to account $accountId ($charName)", $VERBOSE);
    } else {
      $auth->begin_transaction();
      try {
        $updCash->bind_param('ii', $coins, $accountId);
        $updCash->execute();

        $aid = (int)$accountId;
        $xp  = (int)$curXp;
        $lvl = (int)$curLevel;
        $map = (int)$curMap;

        $upsertSeen->bind_param('iiii', $aid, $xp, $lvl, $map);
        $upsertSeen->execute();

        $auth->commit();
      } catch (Throwable $e) {
        $auth->rollback();
        error_log("[award_playtime] " . $e->getMessage());
        continue;
      }
      if ($soap['mail_enabled']) {
          $body = sprintf($soap['body_tpl'], $coins);
          $ok = sendSoapMail($soap, $charName, $soap['subject'], $body);
          log_line("SOAP mail to {$charName} " . ($ok ? "succeeded" : "FAILED"), $VERBOSE);
      }
    }
    log_line("[acct $accountId] Awarded $coins coin(s) for $minutes minutes", $VERBOSE);
    $awarded_total_accounts++;
    $awarded_total_coins += $coins;
  }
}

echo "Awarded {$awarded_total_coins} coin(s) across {$awarded_total_accounts} account(s). AFK blocked={$afk_blocked}" . PHP_EOL;

$getOnlineChars->close();
$updCash->close();
$upsertSeen->close();
$auth->close();
$chars->close();
$getTrack->close();
