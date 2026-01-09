<?php
require __DIR__ . '/lib.php';
ensure_schema();
require __DIR__ . '/_layout.php';

$errors = [];
$saved = false;

$queues = cups_queues();
$choices = discover_printer_choices();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $imap_host = trim($_POST['imap_host'] ?? '');
  $imap_port = trim($_POST['imap_port'] ?? '993');
  $imap_tls  = trim($_POST['imap_tls'] ?? 'imaps');
  $imap_user = trim($_POST['imap_user'] ?? '');
  $imap_pass = trim($_POST['imap_pass'] ?? '');
  $imap_inbox = trim($_POST['imap_inbox'] ?? 'INBOX');
  $imap_done = trim($_POST['imap_done'] ?? 'INBOX.Printed');
  $imap_blocked = trim($_POST['imap_blocked'] ?? 'INBOX.Blocked');

  $smtp_host = trim($_POST['smtp_host'] ?? '');
  $smtp_port = trim($_POST['smtp_port'] ?? '587');
  $smtp_tls  = trim($_POST['smtp_tls'] ?? 'starttls');
  $smtp_user = trim($_POST['smtp_user'] ?? '');
  $smtp_pass = trim($_POST['smtp_pass'] ?? '');
  $smtp_from = trim($_POST['smtp_from'] ?? $smtp_user);

  $dry_run = isset($_POST['dry_run']) ? '1' : '0';
  $automation = isset($_POST['automation_enabled']) ? '1' : '0';
  $whitelist_enabled = isset($_POST['whitelist_enabled']) ? '1' : '0';

  $printer_mode = $_POST['printer_mode'] ?? 'queue';
  $printer_queue = trim($_POST['printer_queue'] ?? '');
  $printer_uri = trim($_POST['printer_uri'] ?? '');

  if ($imap_host==='' || $imap_user==='' || $imap_pass==='') $errors[]="IMAP Daten fehlen.";
  if (!preg_match('/^\d+$/', $imap_port) || (int)$imap_port<1 || (int)$imap_port>65535) $errors[]="IMAP Port ungültig.";
  if (!in_array($imap_tls, ['imaps','starttls','none'], true)) $errors[]="IMAP TLS Modus ungültig.";

  if ($smtp_host==='' || $smtp_user==='' || $smtp_pass==='' || $smtp_from==='') $errors[]="SMTP Daten fehlen.";
  if (!preg_match('/^\d+$/', $smtp_port) || (int)$smtp_port<1 || (int)$smtp_port>65535) $errors[]="SMTP Port ungültig.";
  if (!in_array($smtp_tls, ['starttls','ssl','none'], true)) $errors[]="SMTP TLS Modus ungültig.";

  if (!can_enable_automation()) {
    $automation = '0';
    $errors[] = "Kein Drucker gefunden. Automatik kann nicht aktiviert werden.";
  } else {
    if ($printer_mode === 'queue') {
      if ($printer_queue==='') $errors[]="Bitte eine CUPS Queue wählen.";
    } else {
      if ($printer_uri==='') $errors[]="Bitte eine Drucker-URI wählen.";
    }
  }

  if (!$errors) {
    $printer_name = $printer_queue;
    if ($printer_mode === 'uri') {
      $printer_name = 'mailtoprint';
      if (!ensure_queue($printer_name, $printer_uri)) {
        $errors[]="Konnte Queue nicht anlegen (driverless).";
        $automation = '0';
      }
    }

    if (!$errors) {
      set_setting('imap_host',$imap_host);
      set_setting('imap_port',$imap_port);
      set_setting('imap_tls',$imap_tls);
      set_setting('imap_user',$imap_user);
      set_setting('imap_pass',$imap_pass);
      set_setting('imap_inbox',$imap_inbox);
      set_setting('imap_done',$imap_done);
      set_setting('imap_blocked',$imap_blocked);

      set_setting('smtp_host',$smtp_host);
      set_setting('smtp_port',$smtp_port);
      set_setting('smtp_tls',$smtp_tls);
      set_setting('smtp_user',$smtp_user);
      set_setting('smtp_pass',$smtp_pass);
      set_setting('smtp_from',$smtp_from);

      set_setting('printer_name',$printer_name);
      set_setting('dry_run',$dry_run);
      set_setting('automation_enabled',$automation);
      set_setting('whitelist_enabled',$whitelist_enabled);

      set_setting('installed','1');
      $saved=true;
      log_msg("Installed. automation=$automation printer=$printer_name dry=$dry_run wl=$whitelist_enabled");
    }
  }
}

page_header('Installation');
?>

<div class="row">
  <div class="col-lg-9">
    <h1 class="mb-3">Installation</h1>

    <?php if ($saved): ?>
      <div class="alert alert-success">Installation abgeschlossen. <a href="config.php" class="alert-link">Zur YConfig</a></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Fehler:</strong>
        <ul class="mb-0">
          <?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Mail</h5>

        <div class="row g-3">
          <div class="col-md-6">
            <h6 class="mt-2">IMAP</h6>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">IMAP Host</label>
                <input class="form-control" name="imap_host" required>
              </div>
              <div class="col-3">
                <label class="form-label">Port</label>
                <input class="form-control" name="imap_port" value="993" required>
              </div>
              <div class="col-3">
                <label class="form-label">TLS</label>
                <select class="form-select" name="imap_tls">
                  <option value="imaps" selected>IMAPS</option>
                  <option value="starttls">STARTTLS</option>
                  <option value="none">None</option>
                </select>
              </div>
            </div>
            <div class="mb-2 mt-2">
              <label class="form-label">IMAP User</label>
              <input class="form-control" name="imap_user" required>
            </div>
            <div class="mb-2">
              <label class="form-label">IMAP Passwort</label>
              <input class="form-control" type="password" name="imap_pass" required>
            </div>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label">INBOX</label>
                <input class="form-control" name="imap_inbox" value="INBOX">
              </div>
              <div class="col-4">
                <label class="form-label">DONE</label>
                <input class="form-control" name="imap_done" value="INBOX.Printed">
              </div>
              <div class="col-4">
                <label class="form-label">BLOCKED</label>
                <input class="form-control" name="imap_blocked" value="INBOX.Blocked">
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <h6 class="mt-2">SMTP</h6>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">SMTP Host</label>
                <input class="form-control" name="smtp_host" required>
              </div>
              <div class="col-3">
                <label class="form-label">Port</label>
                <input class="form-control" name="smtp_port" value="587" required>
              </div>
              <div class="col-3">
                <label class="form-label">TLS</label>
                <select class="form-select" name="smtp_tls">
                  <option value="starttls" selected>STARTTLS</option>
                  <option value="ssl">SSL/TLS</option>
                  <option value="none">None</option>
                </select>
              </div>
            </div>
            <div class="mb-2 mt-2">
              <label class="form-label">SMTP User</label>
              <input class="form-control" name="smtp_user" required>
            </div>
            <div class="mb-2">
              <label class="form-label">SMTP Passwort</label>
              <input class="form-control" type="password" name="smtp_pass" required>
            </div>
            <div class="mb-2">
              <label class="form-label">SMTP From</label>
              <input class="form-control" name="smtp_from" placeholder="druckbot@example.com">
            </div>
          </div>
        </div>

        <hr class="my-4">

        <h5 class="card-title">Drucker</h5>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Modus</label>
            <select class="form-select" name="printer_mode">
              <option value="queue">CUPS Queue wählen</option>
              <option value="uri">Netzwerkgerät wählen (URI) + Queue anlegen</option>
            </select>
          </div>
          <div class="col-md-8">
            <div class="alert alert-secondary mb-0">
              <div><strong>Queues:</strong> <?= h(implode(', ', $queues) ?: '—') ?></div>
              <div><strong>Netzwerkgeräte:</strong> <?= h((string)count($choices)) ?></div>
              <div class="small">Wenn kein Drucker gefunden wird, bleibt die Automatik AUS.</div>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">CUPS Queue</label>
            <select class="form-select" name="printer_queue">
              <option value="">— bitte wählen —</option>
              <?php foreach($queues as $q) echo '<option value="'.h($q).'">'.h($q).'</option>'; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Netzwerkgerät (Friendly Name)</label>
            <select class="form-select" name="printer_uri">
              <option value="">— bitte wählen —</option>
              <?php foreach($choices as $c): ?>
                <option value="<?=h($c['uri'])?>"><?=h($c['label'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <hr class="my-4">

        <h5 class="card-title">Optionen</h5>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="dry_run" id="dry">
          <label class="form-check-label" for="dry">Dry-Run</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="automation_enabled" id="auto" checked>
          <label class="form-check-label" for="auto">Automatik aktiv</label>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="whitelist_enabled" id="wl">
          <label class="form-check-label" for="wl">Whitelist aktiv</label>
        </div>

        <button class="btn btn-primary" type="submit">Installation abschließen</button>
      </div>
    </form>
  </div>

  <div class="col-lg-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Betreff</h5>
        <ul class="mb-0 small">
          <li><code>sw-2</code> = s/w, duplex</li>
          <li><code>bunt-1</code> = Farbe, simplex</li>
          <li><code>x3</code> oder <code>kopien=3</code></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php page_footer(); ?>
