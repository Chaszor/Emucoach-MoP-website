<?php
// pages/download-and-connect.php
// Downloads + Connect guide with dynamic file detection and optional torrents/mirrors.

// -------------------- REALMLIST (kept from your version) --------------------
$REALMLIST = $REALMLIST ?? null;
try {
    $configPath = __DIR__ . '/../config.php';
    if (file_exists($configPath)) {
        include_once $configPath; // may define $auth_conn or site settings
    }

    if (empty($REALMLIST) && isset($auth_conn) && $auth_conn instanceof mysqli) {
        if ($res = @$auth_conn->query("SELECT `address` FROM `realmlist` LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $REALMLIST = trim($row['address']);
            }
        }
    }
} catch (Throwable $e) {
    // optional: log $e->getMessage() if debugging
}

if (empty($REALMLIST)) {
    $REALMLIST = '127.0.0.1'; // fallback; update to your host/IP
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// -------------------- PATHS --------------------
// Server-side files live in ../downloads relative to this page.
$downloadsDirAbs = realpath(__DIR__ . '/../downloads') ?: (__DIR__ . '/../downloads');
// Public URL (from this /pages/ file) to the downloads folder:
$downloadsUrlRel = '../downloads';

// Optional safe handler at site root: ../download.php?f=<file>
// If present, links will use it; otherwise direct links are used.
$downloadHandlerRel = (file_exists(__DIR__ . '/../download.php')) ? '../download.php?f=' : null;

// -------------------- HELPERS --------------------
function human_filesize($bytes){
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return sprintf('%.2f %s', $bytes, $units[$i]);
}
function get_checksum($absPath){
    $sha256 = $absPath . '.sha256';
    $sha1   = $absPath . '.sha1';
    if (is_file($sha256)) return ['SHA-256', trim(@file_get_contents($sha256))];
    if (is_file($sha1))   return ['SHA-1',   trim(@file_get_contents($sha1))];
    return [null, null];
}
function file_meta_row($absPath){
    if (!is_file($absPath)) return [false, '—', [null, null]];
    $size = human_filesize(filesize($absPath));
    $chk  = get_checksum($absPath);
    return [true, $size, $chk];
}
function dl_href($filename, $downloadsUrlRel, $downloadHandlerRel){
    $baseName = basename($filename);
    if ($downloadHandlerRel) return $downloadHandlerRel . rawurlencode($baseName);
    return $downloadsUrlRel . '/' . rawurlencode($baseName);
}

// -------------------- DOWNLOAD CATALOG --------------------
// Edit filenames/urls below to match what you provide in ../downloads.
// You can also supply an external 'mirror' or 'torrent' URL.
$catalog = [
    [
        'title' => 'WotLK 3.3.5a (Windows)',
        'desc'  => 'Direct download. Portable—no Blizzard app required.',
        'file'  => 'wotlk_335a_win.zip',   // ../downloads/wotlk_335a_win.zip
        'torrent' => null,                 // e.g. 'https://your.torrent.link'
        'note'  => null                    // overrides checksum line if set
    ],
    [
        'title' => 'MoP 5.4.8 (Windows)',
        'desc'  => 'Ready-to-play. Includes the Pandaria client.',
        'file'  => 'World of Warcraft 5.4.8.rar',
        'torrent' => null,
        'note'  => null
    ],
    [
        'title' => 'WotLK 3.3.5a (macOS, Intel)',
        'desc'  => 'Legacy client. Gatekeeper may require Control+Click → Open.',
        'file'  => 'wotlk_335a_mac_intel.zip',
        'torrent' => null,
        'note'  => 'Works best on macOS 10.13–10.15.'
    ],
    [
        'title' => 'Config.wtf Only',
        'desc'  => 'Already have the client? Use this to update your Config.wtf automatically.',
        'file'  => 'Config.wtf',   // or .mpq if that’s what you ship
        'torrent' => null,
        'note'  => 'Place in your game\'s WTF folder.'
    ],
    // Example with external mirror only (no local file):
    // [
    //   'title' => 'Full Client (Mirror - MEGA)',
    //   'desc'  => 'External mirror for faster regional downloads.',
    //   'file'  => null,
    //   'mirror'=> 'https://mega.nz/your-link',
    //   'torrent'=> null,
    //   'note'  => null
    // ],
];

// -------------------- HEADER/FOOTER HANDLING --------------------
$headerPath = __DIR__ . '/../includes/header.php';
$footerPath = __DIR__ . '/../includes/footer.php';
$hasHeader  = file_exists($headerPath);
$hasFooter  = file_exists($footerPath);

if ($hasHeader) include $headerPath;
if (!$hasHeader): ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Download Client & Connect</title>
</head>
<body>
<?php endif; ?>

  <div class="wrap">
    <section>
      <h1 style="text-align: center">Download the Client & Connect</h1>
      <p class="lead">
        Grab a game client below and point your <span class="mono">realmlist</span> to
        <strong class="hl"><?=h($REALMLIST)?></strong>.
        Full steps for WotLK (3.3.5a) and MoP (5.4.8) are included.
      </p>
    </section>

    <h2 style="margin-top:22px">1) Choose Your Client</h2>

    <?php if (!is_dir($downloadsDirAbs)): ?>
      <div class="notice" style="margin:12px 0;padding:12px;border:1px solid var(--border, #444);border-radius:8px">
        <strong>Heads up:</strong> The <code>downloads/</code> folder was not found at
        <code><?= h($downloadsDirAbs) ?></code>. Create it and drop files in there.
      </div>
    <?php endif; ?>

    <div class="grid">
      <?php foreach ($catalog as $item): ?>
        <?php
          $title   = $item['title'] ?? 'Download';
          $desc    = $item['desc']  ?? '';
          $file    = $item['file']  ?? null;               // local file name in ../downloads
          $mirror  = $item['mirror'] ?? null;              // external link (optional)
          $torrent = $item['torrent'] ?? null;             // torrent link (optional)
          $note    = $item['note'] ?? null;

          $hasLocal = false;
          $sizeText = '—';
          $hashAlg  = null;
          $hashVal  = null;
          $downloadHref = '#';

          if ($file) {
              $abs = $downloadsDirAbs . DIRECTORY_SEPARATOR . $file;
              [$hasLocal, $sizeText, [$hashAlg, $hashVal]] = file_meta_row($abs);
              if ($hasLocal) $downloadHref = dl_href($file, $downloadsUrlRel, $downloadHandlerRel);
          }
        ?>
        <div class="card">
          <h3><?= h($title) ?></h3>
          <p class="muted"><?= h($desc) ?></p>

          <div class="inline" style="margin-top:10px">
            <?php if ($hasLocal): ?>
              <a class="btn" href="<?= h($downloadHref) ?>" download>Download<?= $sizeText !== '—' ? ' ('.$sizeText.')' : '' ?></a>
            <?php else: ?>
              <?php if ($mirror): ?>
                <a class="btn" href="<?= h($mirror) ?>" target="_blank" rel="noopener">Open Mirror</a>
              <?php else: ?>
                <span class="btn disabled" aria-disabled="true" style="opacity:.6;pointer-events:none">Coming Soon</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($torrent): ?>
              <a class="btn secondary" href="<?= h($torrent) ?>" target="_blank" rel="noopener">Torrent</a>
            <?php endif; ?>
          </div>

          <?php if ($note): ?>
            <p class="small" style="margin-top:8px"><?= h($note) ?></p>
          <?php else: ?>
            <?php if ($hashAlg && $hashVal): ?>
              <p class="small" style="margin-top:8px">
                Checksum (<?= h($hashAlg) ?>): <span class="hash mono"><?= h($hashVal) ?></span>
              </p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="margin-top:24px">2) Set Your Realmlist</h2>
    <div class="card">
      <h3 style="margin-top:0">WotLK (3.3.5a)</h3>
      <ol class="steps">
        <li>Close the game completely.</li>
        <li>Open your game folder. Example: <span class="mono">C:\Games\World of Warcraft 3.3.5a</span></li>
        <li>Go to <span class="mono">Data\enUS</span> (or your locale, e.g., <span class="mono">enGB</span>).</li>
        <li>Open <span class="mono">realmlist.wtf</span> with Notepad.</li>
        <li>Replace the contents with:<br>
          <div class="copy" style="margin-top:8px"><code id="wotlkText">set realmlist <?=h($REALMLIST)?></code>
            <button class="btn secondary" type="button" onclick="copyText('wotlkText', this)">Copy</button>
          </div>
        </li>
        <li>Save the file. Launch <span class="mono">wow.exe</span> (not the Blizzard Launcher).</li>
      </ol>
      <div class="notice small">Locale path tip: Many clients use <span class="mono">Data\enUS</span> or <span class="mono">Data\enGB</span>. Adjust accordingly.</div>
    </div>

    <div class="card" style="margin-top:12px">
      <h3 style="margin-top:0">MoP (5.4.8)</h3>
      <ol class="steps">
        <li>Close the game completely.</li>
        <li>Open your game folder. Example: <span class="mono">C:\Games\World of Warcraft 5.4.8</span></li>
        <li>Open <span class="mono">WTF\Config.wtf</span> with Notepad.</li>
        <li>Add or update these lines (anywhere in the file):<br>
          <div class="copy" style="margin-top:8px"><code id="mopText">SET portal "<?=h($REALMLIST)?>"
SET realmlist "<?=h($REALMLIST)?>"</code>
            <button class="btn secondary" type="button" onclick="copyText('mopText', this)">Copy</button>
          </div>
        </li>
        <li>Save the file. Launch <span class="mono">Wow.exe</span>. Log in with your server account.</li>
      </ol>
      <div class="notice small">If your client still tries connecting to Blizzard, delete the <span class="mono">Cache</span> folder and try again.</div>
    </div>

    <h2 style="margin-top:24px">3) Optional Tweaks</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Setting</th>
          <th>Where</th>
          <th>Value</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Windowed Mode</td>
          <td><span class="mono">WTF\Config.wtf</span></td>
          <td><span class="mono">SET gxWindow "1"</span></td>
        </tr>
        <tr>
          <td>Max Foreground FPS</td>
          <td><span class="mono">WTF\Config.wtf</span></td>
          <td><span class="mono">SET maxFPS "144"</span></td>
        </tr>
        <tr>
          <td>Disable Launcher</td>
          <td>Shortcut</td>
          <td>Launch <span class="mono">wow.exe</span> directly</td>
        </tr>
      </tbody>
    </table>

    <h2 style="margin-top:24px">Troubleshooting</h2>
    <details>
      <summary>It still says "Connecting" forever</summary>
      <ul>
        <li>Confirm your realmlist: <strong class="mono"><?=h($REALMLIST)?></strong></li>
        <li>Delete the <span class="mono">Cache</span> folder, then relaunch.</li>
        <li>Ensure your firewall/AV allows <span class="mono">wow.exe</span>.</li>
        <li>If using an IP realmlist, verify your server is reachable (ping/port open).</li>
      </ul>
    </details>
    <details>
      <summary>Login works but no realms are listed</summary>
      <ul>
        <li>Your <span class="mono">realmlist</span> may be correct, but the realm is offline or misconfigured.</li>
        <li>Check your worldserver auth settings (realmlist table in <span class="mono">auth</span> DB) and firewall/NAT rules.</li>
      </ul>
    </details>
    <details>
      <summary>macOS says the app is from an unidentified developer</summary>
      <ul>
        <li><span class="mono">Control+Click → Open</span> once to whitelist.</li>
        <li>Or allow in <span class="mono">System Settings → Privacy & Security</span>.</li>
      </ul>
    </details>

    <p class="small" style="margin-top:24px">
      Need help? Join our Discord or open a support ticket. Replace the placeholders above with your real links and invite.
    </p>
  </div>

  <script>
    function copyText(id, btn){
      const el = document.getElementById(id);
      if(!el) return;
      const text = el.innerText || el.textContent;
      navigator.clipboard.writeText(text).then(()=>{
        const old = btn.textContent; btn.textContent = 'Copied!';
        setTimeout(()=> btn.textContent = old, 1200);
      });
    }
  </script>

<?php if (!$hasHeader): ?>
</body>
</html>
<?php endif; ?>
<?php if ($hasFooter && $hasHeader) include $footerPath; ?>
