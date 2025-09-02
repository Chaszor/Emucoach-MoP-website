<?php
/**********************************************************
 * Award playtime coins based on Characters.totaltime
 * - 1 coin per minute (configurable via auth.site_settings)
 * - Tracks while online without backpay bursts
 * - Anti-AFK (optional) using map/xp/level + per-char time
 * - Idempotent: tracks last_totaltime and last_seen_online_at
 * - Atomic updates to auth.account.cash
 **********************************************************/

/** ====== CONFIG (defaults; site_settings can override) ====== */
$db = [
  'host' => 'localhost',
  'user' => 'root',
  'pass' => 'ascent',
  'auth' => 'auth',           // accounts DB
  'chars'=> 'characters',     // characters DB
];

$award = [
  // Minute-based rules (coins_per_minute may be recomputed from site_settings)
  'coins_per_minute' => 1.0,   // fallback: 1 coin per minute
  'min_minutes'      => 1,     // minimum whole minutes required to award

  // Online tracking (safe; no backpay)
  'online_credit' => [
    'enabled'      => true,    // also credit while online
    'per_run_cap'  => 5,       // max online minutes credited per run (prevents spikes)
  ],

  // Anti-AFK (optional — defaults; can be overridden by site_settings)
  'anti_afk' => [
    'enabled'             => true,
    'require_activity'    => false,   // site_settings.require_activity
    'min_map_changes'     => 1,
    'min_xp_delta'        => 50,
    'min_level_delta'     => 1,
    'min_seconds_per_char'=> 60,      // site_settings.min_seconds_per_char
  ],
];

$soap = [
  'enabled'   => false,          // site_settings.soap_enabled may toggle this
  'host'      => '127.0.0.1',    // optional overrides via site_settings
  'port'      => 7878,
  'user'      => '',      // GM account with SOAP permissions
  'pass'      => '',   // <-- change for production
  'from'      => 'Server',
  'subject'   => 'Thanks for playing!',
  'body_tpl'  => 'You earned %d coin(s) for active playtime. Enjoy!',
];

// CLI flags
$argv = $argv ?? [];
$DRY_RUN = in_array('--dry-run', $argv, true);
$VERBOSE = in_array('--verbose', $argv, true);

/** ====== DB CONNECTIONS ====== */
$auth = new mysqli($db['host'], $db['user'], $db['pass'], $db['auth']);
if ($auth->connect_error) die("Auth DB connection failed: " . $auth->connect_error);
$auth->set_charset('utf8mb4');

$chars = new mysqli($db['host'], $db['user'], $db['pass'], $db['chars']);
if ($chars->connect_error) die("Characters DB connection failed: " . $chars->connect_error);
$chars->set_charset('utf8mb4');

/** ====== SUPPORTING TABLES ====== */
$auth->query("
  CREATE TABLE IF NOT EXISTS playtime_rewards (
    account_id            INT UNSIGNED PRIMARY KEY,
    last_totaltime        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_award_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_online_at   TIMESTAMP NULL DEFAULT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$colCheck = $auth->query("SHOW COLUMNS FROM playtime_rewards LIKE 'last_seen_online_at'");
if ($colCheck && $colCheck->num_rows === 0) {
  @$auth->query("ALTER TABLE playtime_rewards ADD COLUMN last_seen_online_at TIMESTAMP NULL DEFAULT NULL");
}

/** ====== SETTINGS FROM auth.site_settings (safe fallbacks) ====== */
function get_setting($mysqli, $key, $default = null) {
  if (!$mysqli) return $default;
  $stmt = @$mysqli->prepare("SELECT `value` FROM site_settings WHERE `key`=? LIMIT 1");
  if (!$stmt) return $default; // table may not exist; fall back
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

// Pull minute/coin model (preferred: coins_per_interval over interval_minutes)
$interval_minutes    = max(1, get_intish($auth, 'interval_minutes', 10)); // default 10 min
$coins_per_interval  = max(0.0, get_floatish($auth, 'coins_per_interval', 1.0)); // default 1 coin
$computed_cpm        = $coins_per_interval / max(1, $interval_minutes); // coins per MINUTE
$award['coins_per_minute'] = $computed_cpm > 0 ? $computed_cpm : $award['coins_per_minute'];

// Optional overrides
$award['min_minutes'] = max(1, get_intish($auth, 'min_minutes', $award['min_minutes']));
$award['online_credit']['per_run_cap'] = max(
  1,
  get_intish($auth, 'online_per_run_cap', $award['online_credit']['per_run_cap'])
);

// AFK toggles
$award['anti_afk']['require_activity']     = get_boolish($auth, 'require_activity', $award['anti_afk']['require_activity']);
$award['anti_afk']['min_seconds_per_char'] = max(0, get_intish($auth, 'min_seconds_per_char', $award['anti_afk']['min_seconds_per_char']));

// SOAP toggles/overrides
$soap['enabled'] = get_boolish($auth, 'soap_enabled', $soap['enabled']);
$soapHost = get_setting($auth, 'soap_host', null);    if ($soapHost !== null) $soap['host'] = $soapHost;
$soapPort = get_setting($auth, 'soap_port', null);    if ($soapPort !== null) $soap['port'] = (int)$soapPort;
$soapUser = get_setting($auth, 'soap_user', null);    if ($soapUser !== null) $soap['user'] = $soapUser;
$soapPass = get_setting($auth, 'soap_pass', null);    if ($soapPass !== null) $soap['pass'] = $soapPass;
$soapFrom = get_setting($auth, 'soap_from', null);    if ($soapFrom !== null) $soap['from'] = $soapFrom;
$soapSubj = get_setting($auth, 'soap_subject', null); if ($soapSubj !== null) $soap['subject'] = $soapSubj;
$soapBody = get_setting($auth, 'soap_body_tpl', null);if ($soapBody !== null) $soap['body_tpl'] = $soapBody;

/** ====== HELPERS ====== */
function minutes_from_seconds($s) { return intdiv(max(0, (int)$s), 60); }
function log_line($msg, $VERBOSE) { if ($VERBOSE) echo $msg . PHP_EOL; }

function sendSoapMail($soap, $playerName, $subject, $body) {
  if (!$soap['enabled']) return true;
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

/** ====== MAIN ====== */
$sumSql = "SELECT c.account AS account_id, SUM(c.totaltime) AS total_seconds FROM characters c GROUP BY c.account";
$sumRes = $chars->query($sumSql);
if (!$sumRes) die("Query failed (characters sum): " . $chars->error);

$getTrack = $auth->prepare("SELECT last_totaltime, last_award_at, last_seen_online_at FROM playtime_rewards WHERE account_id=?");
$upsertTrack = $auth->prepare("
  INSERT INTO playtime_rewards (account_id, last_totaltime, last_award_at, last_seen_online_at)
  VALUES (?, ?, NOW(), ?)
  ON DUPLICATE KEY UPDATE
    last_totaltime=VALUES(last_totaltime),
    last_award_at=NOW(),
    last_seen_online_at=VALUES(last_seen_online_at)
");
$updateSeen = $auth->prepare("
  INSERT INTO playtime_rewards (account_id, last_totaltime, last_award_at, last_seen_online_at)
  VALUES (?, 0, NOW(), ?)
  ON DUPLICATE KEY UPDATE last_seen_online_at=VALUES(last_seen_online_at)
");

$updCash    = $auth->prepare("UPDATE account SET cash = cash + ? WHERE id = ?");
$getAnyChar = $chars->prepare("SELECT name FROM characters WHERE account=? ORDER BY totaltime DESC LIMIT 1");

$acctHasOnline = false;
$cols = $auth->query("SHOW COLUMNS FROM account LIKE 'online'");
if ($cols && $cols->num_rows > 0) $acctHasOnline = true;
$getAcctOnline = $acctHasOnline ? $auth->prepare("SELECT online FROM account WHERE id=? LIMIT 1") : null;

$awarded_total_accounts = 0;
$awarded_total_coins    = 0;

while ($row = $sumRes->fetch_assoc()) {
  $accountId    = (int)$row['account_id'];
  $totalSeconds = (int)$row['total_seconds'];

  $lastTotal = 0; $lastAwardAtTs = 0; $lastSeenOnlineTs = null; $lastSeenOnlineStr = null;
  $getTrack->bind_param('i', $accountId);
  $getTrack->execute();
  $getTrack->bind_result($lastTotalTmp, $lastAwardAtStr, $lastSeenOnlineStrTmp);
  if ($getTrack->fetch()) {
    $lastTotal = (int)$lastTotalTmp;
    if ($lastAwardAtStr) { $t = strtotime($lastAwardAtStr); if ($t !== false) $lastAwardAtTs = $t; }
    if ($lastSeenOnlineStrTmp) { $lastSeenOnlineStr = $lastSeenOnlineStrTmp; $t2 = strtotime($lastSeenOnlineStrTmp); if ($t2 !== false) $lastSeenOnlineTs = $t2; }
  }
  $getTrack->free_result();

  if ($totalSeconds < $lastTotal) {
    log_line("[acct $accountId] regression: last=$lastTotal > current=$totalSeconds -> reseed", $VERBOSE);
    if (!$DRY_RUN) {
      $seenStr = $lastSeenOnlineStr;
      $upsertTrack->bind_param('iis', $accountId, $totalSeconds, $seenStr);
      $upsertTrack->execute();
    }
    continue;
  }

  $deltaSeconds = max(0, $totalSeconds - $lastTotal);
  $savedMinutes = minutes_from_seconds($deltaSeconds);

  $onlineMinutes = 0;
  $isOnline = 0;
  if ($acctHasOnline && !empty($award['online_credit']['enabled'])) {
    $getAcctOnline->bind_param('i', $accountId);
    if ($getAcctOnline->execute()) {
      $getAcctOnline->bind_result($isOnlineTmp);
      if ($getAcctOnline->fetch()) $isOnline = (int)$isOnlineTmp;
      $getAcctOnline->free_result();
    }

    if ($isOnline) {
      $now = time();
      if ($lastSeenOnlineTs === null) {
        if ($VERBOSE) log_line(" - seeding last_seen_online_at for acct $accountId", $VERBOSE);
        if (!$DRY_RUN) {
          $nowStr = date('Y-m-d H:i:s', $now);
          $updateSeen->bind_param('is', $accountId, $nowStr);
          $updateSeen->execute();
          $lastSeenOnlineTs = $now;
          $lastSeenOnlineStr = $nowStr;
        }
      } else {
        $elapsed = max(0, (int)floor(($now - $lastSeenOnlineTs) / 60));
        $cap = max(1, (int)$award['online_credit']['per_run_cap']);
        $onlineMinutes = min($elapsed, $cap);
        if ($VERBOSE) log_line(" - online credit: elapsed=$elapsed min, cap=$cap -> +$onlineMinutes", $VERBOSE);
        if (!$DRY_RUN) {
          $nowStr = date('Y-m-d H:i:s', $now);
          $updateSeen->bind_param('is', $accountId, $nowStr);
          $updateSeen->execute();
          $lastSeenOnlineTs = $now;
          $lastSeenOnlineStr = $nowStr;
        }
      }
    }
  }

  $newMinutes = max($savedMinutes, $onlineMinutes);

  if ($VERBOSE) {
    echo "[acct $accountId] totalSeconds=$totalSeconds lastTotal=$lastTotal "
       . "delta=$deltaSeconds savedMinutes=$savedMinutes onlineMinutes=$onlineMinutes newMinutes=$newMinutes" . PHP_EOL;
  }

  if ($lastTotal === 0) {
    if (!$DRY_RUN) {
      $seenStr = $isOnline ? date('Y-m-d H:i:s') : null;
      $upsertTrack->bind_param('iis', $accountId, $totalSeconds, $seenStr);
      $upsertTrack->execute();
      if ($seenStr !== null) { $lastSeenOnlineStr = $seenStr; $lastSeenOnlineTs = strtotime($seenStr); }
    }
    log_line(" - first run seed only (no awards this run)", $VERBOSE);
    continue;
  }

  if ($newMinutes < (int)$award['min_minutes']) {
    log_line(" - skipped: insufficient minutes (need {$award['min_minutes']})", $VERBOSE);
    continue;
  }

  $coins = (int) floor($newMinutes * (float)$award['coins_per_minute']);
  if ($coins <= 0) {
    log_line(" - computed 0 coins; skipping", $VERBOSE);
    continue;
  }

  if ($DRY_RUN) {
    log_line("DRY_RUN: would award $coins coin(s) to account $accountId", $VERBOSE);
  } else {
    $auth->begin_transaction();
    try {
      $updCash->bind_param('ii', $coins, $accountId);
      if (!$updCash->execute()) throw new Exception("Failed to update cash");

      $seenStr = $lastSeenOnlineStr;
      $upsertTrack->bind_param('iis', $accountId, $totalSeconds, $seenStr);
      if (!$upsertTrack->execute()) throw new Exception("Failed to upsert playtime rewards");

      $auth->commit();
    } catch (Throwable $e) {
      $auth->rollback();
      error_log("[award_playtime] " . $e->getMessage());
      continue;
    }

    $playerName = null;
    $getAnyChar->bind_param('i', $accountId);
    if ($getAnyChar->execute()) {
      $getAnyChar->bind_result($playerNameTmp);
      if ($getAnyChar->fetch()) $playerName = $playerNameTmp;
      $getAnyChar->free_result();
    }
    if ($playerName && $soap['enabled']) {
      $body = sprintf($soap['body_tpl'], $coins);
      @sendSoapMail($soap, $playerName, $soap['subject'], $body);
    }
  }

  $awarded_total_accounts++;
  $awarded_total_coins += $coins;
}

echo "Awarded {$awarded_total_coins} coin(s) across {$awarded_total_accounts} account(s)." . PHP_EOL;

$getTrack->close();
$upsertTrack->close();
$updateSeen->close();
if ($acctHasOnline && $getAcctOnline) $getAcctOnline->close();
$updCash->close();
$getAnyChar->close();
$sumRes->free();
$auth->close();
$chars->close();
