<?php
require __DIR__ . '/../www/lib.php';
ensure_schema();
log_msg("Worker start");

if (!is_installed()) { log_msg("Worker exit: not installed"); exit(0); }
if (get_setting('automation_enabled','0') !== '1') { log_msg("Worker exit: automation disabled"); exit(0); }

$printer = get_setting('printer_name','');
if ($printer === '') { log_msg("Worker exit: no printer configured"); exit(0); }

$dry = get_setting('dry_run','0') === '1';

$imap_host = get_setting('imap_host');
$imap_user = get_setting('imap_user');
$imap_pass = get_setting('imap_pass');
$imap_inbox = get_setting('imap_inbox','INBOX');
$imap_done = get_setting('imap_done','INBOX.Printed');
$imap_blocked = get_setting('imap_blocked','INBOX.Blocked');

$whitelist_enabled = get_setting('whitelist_enabled','0') === '1';
$wl = [];
if ($whitelist_enabled) {
  $wl = db()->query("SELECT email FROM whitelist")->fetchAll(PDO::FETCH_COLUMN);
  $wl = array_map('strtolower', $wl);
}

function send_mail_msmtp(string $to, string $subj, string $body): void {
  $to = trim($to);
  if ($to === '') return;

  $smtp_host = get_setting('smtp_host');
  $smtp_port = get_setting('smtp_port','587');
  $smtp_user = get_setting('smtp_user');
  $smtp_pass = get_setting('smtp_pass');
  $smtp_from = get_setting('smtp_from');
  if (!$smtp_host || !$smtp_user || !$smtp_pass || !$smtp_from) return;

  $tmp = tempnam(sys_get_temp_dir(), 'msmtp_');
  $cfg = $tmp . ".conf";
  rename($tmp, $cfg);
  file_put_contents($cfg, "defaults
auth on
tls on
tls_starttls on

account a
host $smtp_host
port $smtp_port
user $smtp_user
password $smtp_pass
from $smtp_from

account default : a
");
  chmod($cfg, 0600);

  $msg = "From: $smtp_from
To: $to
Subject: $subj
Content-Type: text/plain; charset=utf-8

$body
";
  $proc = proc_open("msmtp --file=" . escapeshellarg($cfg) . " -t", [
    0 => ["pipe","r"], 1 => ["pipe","w"], 2 => ["pipe","w"],
  ], $pipes);

  if (is_resource($proc)) {
    fwrite($pipes[0], $msg);
    fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $e = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) log_msg("msmtp error: " . trim($e ?: $o ?: "rc=$rc"));
  }
  @unlink($cfg);
}

function extract_first_email(string $line): string {
  if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $line, $m)) return $m[1];
  return '';
}

function imap_cmd(string $host, string $user, string $pass, string $mbox, string $cmd): string {
  $url = "imaps://$host/$mbox";
  $full = "curl -sS --fail --ssl-reqd --user " . escapeshellarg("$user:$pass") . " " . escapeshellarg($url) . " -X " . escapeshellarg($cmd) . " 2>&1";
  $out = shell_exec($full);
  return (string)$out;
}

function imap_move_seen(string $host, string $user, string $pass, string $src, string $dst, string $uid): void {
  imap_cmd($host,$user,$pass,$src, "UID STORE $uid +FLAGS (\Seen)");
  imap_cmd($host,$user,$pass,$src, "UID COPY $uid "$dst"");
  imap_cmd($host,$user,$pass,$src, "UID STORE $uid +FLAGS (\Deleted)");
  imap_cmd($host,$user,$pass,$src, "EXPUNGE");
}

$search = imap_cmd($imap_host,$imap_user,$imap_pass,$imap_inbox,"UID SEARCH UNSEEN");
$uids = [];
if (preg_match('/\* SEARCH(.*)?
/', $search, $m)) {
  $uids = preg_split('/\s+/', trim($m[1]));
  $uids = array_values(array_filter($uids, fn($x)=>$x!==''));
}
if (!$uids) { log_msg("Worker: no unread mails"); exit(0); }

$workbase = base_dir() . "/data/work";
@mkdir($workbase, 0770, true);

foreach ($uids as $uid) {
  $dir = $workbase . "/uid-$uid-" . time();
  @mkdir($dir, 0770, true);
  $eml = $dir . "/mail.eml";

  $raw = imap_cmd($imap_host,$imap_user,$imap_pass,$imap_inbox,"UID FETCH $uid (RFC822)");
  file_put_contents($eml, str_replace("","",$raw));

  $lines = file($eml, FILE_IGNORE_NEW_LINES) ?: [];
  $subject=''; $from='';
  foreach ($lines as $ln) {
    if ($subject==='' && stripos($ln,'Subject:')===0) $subject = trim(substr($ln,8));
    if ($from==='' && stripos($ln,'From:')===0) $from = extract_first_email($ln);
    if ($subject!=='' && $from!=='') break;
  }

  if ($whitelist_enabled) {
    if ($from==='' || !in_array(strtolower($from), $wl, true)) {
      log_msg("Worker: uid=$uid sender not allowed -> blocked");
      if ($dry) {
        send_mail_msmtp($from, "Druckauftrag abgelehnt (Dry-Run): " . ($subject?:'ohne Betreff'),
          "Dry-Run aktiv. Absender nicht auf Whitelist. Mail würde nach '$imap_blocked' verschoben.");
      } else {
        imap_move_seen($imap_host,$imap_user,$imap_pass,$imap_inbox,$imap_blocked,$uid);
        send_mail_msmtp($from, "Druckauftrag abgelehnt: " . ($subject?:'ohne Betreff'),
          "Absender nicht auf Whitelist. Mail wurde nach '$imap_blocked' verschoben.");
      }
      continue;
    }
  }

  $s = strtolower($subject);
  $color='sw'; $sides='1';
  if (preg_match('/(sw|bunt)\s*-\s*(1|2)/', $s, $mm)) { $color=$mm[1]; $sides=$mm[2]; }
  $copies=1;
  if (preg_match('/(^|[^a-z0-9])x([0-9]{1,3})([^0-9]|$)/', $s, $mm)) $copies=(int)$mm[2];
  if (preg_match('/(kopien|copies)\s*=\s*([0-9]{1,3})/', $s, $mm)) $copies=(int)$mm[2];
  $copies=max(1,min(50,$copies));

  $opts=[];
  $opts[] = ($color==='bunt') ? "-o ColorModel=RGB" : "-o ColorModel=Gray";
  $opts[] = ($sides==='2') ? "-o sides=two-sided-long-edge" : "-o sides=one-sided";

  $attach=$dir."/attach";
  @mkdir($attach, 0770, true);
  shell_exec("ripmime -i " . escapeshellarg($eml) . " -d " . escapeshellarg($attach) . " >/dev/null 2>&1");

  $pdfs = array_merge(glob($attach."/*.pdf") ?: [], glob($attach."/*.PDF") ?: []);
  if (!$pdfs) { log_msg("Worker: uid=$uid no pdf -> skip"); continue; }

  $failed=0; $printed=0;
  foreach ($pdfs as $pdf) {
    if ($dry) {
      log_msg("DRY uid=$uid would print $pdf copies=$copies opts=" . implode(' ',$opts));
      $printed++;
      continue;
    }
    $cmd = "lp -d " . escapeshellarg($printer) . " -n " . escapeshellarg((string)$copies) . " " . implode(' ',$opts) . " " . escapeshellarg($pdf) . " 2>&1";
    $out = shell_exec($cmd);
    if ($out === null) { $failed++; log_msg("lp failed (null) uid=$uid pdf=$pdf"); }
    else {
      if (stripos($out,'error')!==false) { $failed++; log_msg("lp output indicates error: ".trim($out)); }
      else { $printed++; }
    }
  }

  if ($dry) {
    send_mail_msmtp($from, "Druckauftrag (Dry-Run): " . ($subject?:'ohne Betreff'),
      "Dry-Run aktiv: NICHT gedruckt und NICHT verschoben.
PDFs: $printed
Optionen: ".implode(' ',$opts)."
Kopien: $copies");
    continue;
  }

  if ($failed>0) {
    log_msg("Worker: uid=$uid print failed=$failed -> keep in inbox");
    send_mail_msmtp($from, "FEHLER beim Druck: " . ($subject?:'ohne Betreff'),
      "Fehler beim Drucken.
Erfolgreich: $printed
Fehlgeschlagen: $failed
Mail bleibt im INBOX und wird erneut versucht.");
    continue;
  }

  imap_move_seen($imap_host,$imap_user,$imap_pass,$imap_inbox,$imap_done,$uid);
  log_msg("Worker: uid=$uid printed=$printed -> moved to done");
  send_mail_msmtp($from, "Druckauftrag erfolgreich: " . ($subject?:'ohne Betreff'),
    "Erfolgreich gedruckt.
PDFs: $printed
Mail wurde nach '$imap_done' verschoben.");
}

log_msg("Worker done");
