<section class="card">
  <h2 style="text-align: center;">Playtime Rewards</h2>
  <form method="POST">
    <input type="hidden" name="settings_action" value="save_settings">

    <label>Coins per interval:
      <input type="number" name="coins_per_interval" min="0" value="<?php echo (int)$coins_per_interval; ?>" required>
    </label>
    <label>Interval (minutes):
      <input type="number" name="interval_minutes" min="1" value="<?php echo (int)$interval_minutes; ?>" required>
    </label>
    <label>Minimum minutes required (per award):
      <input type="number" name="min_minutes" min="1" value="<?php echo (int)$min_minutes; ?>" required>
    </label>
    <label>Online per-run cap (minutes):
      <input type="number" name="online_per_run_cap" min="1" value="<?php echo (int)$online_per_run_cap; ?>" required>
    </label>

    <p>
      <em>Derived rate:</em>
      <strong><?php echo number_format($coins_per_minute, 3); ?> coin/min</strong>
      (≈ <strong><?php echo number_format($coins_per_hour, 2); ?> coin/hour</strong>)
    </p>

    <hr>

    <label>
      <input type="checkbox" name="require_activity" <?php echo $require_activity ? 'checked' : ''; ?>>
      Require anti-AFK activity (map/xp/level) to grant coins
    </label>
    <label>Min seconds per character:
      <input type="number" name="min_seconds_per_char" min="0" value="<?php echo (int)$min_seconds_per_char; ?>">
    </label>

    <hr>

    <label>
      <input type="checkbox" name="soap_enabled" <?php echo $soap_enabled ? 'checked' : ''; ?>>
      Enable SOAP in-game mail on award
    </label>

    <br><br>
    <button type="submit" class="btn">Save Settings</button>
  </form>
</section>

  <section class="card">
    <h3>Notes</h3>
    <ul>
      <li>Settings are stored in <code>auth.site_settings</code> and read by <code>cron/award_playtime.php</code>.</li>
      <li><strong>Tip:</strong> Schedule the cron via Windows Task Scheduler (e.g. every 5 minutes).</li>
      <li>Use a GM account for SOAP if you enable in-game mail.</li>
    </ul>
  </section>