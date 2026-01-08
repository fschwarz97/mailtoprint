<?php
require __DIR__ . '/lib.php';
ensure_schema();
require __DIR__ . '/_layout.php';
if(!is_installed()) redirect_to('install.php');

$queues=cups_queues();
$choices=discover_printer_choices();
$errors=[]; $saved=false; $msg='';

function postbtn(string $n): bool { return $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST[$n]); }

function curl_imap(string $host,string $user,string $pass,string $mailbox,string $cmd,string &$err=''): string {
  $url="imaps://$host/$mailbox";
  $full="curl -sS --fail --ssl-reqd --user ".escapeshellarg("$user:$pass")." ".escapeshellarg($url)." -X ".escapeshellarg($cmd)." 2>&1";
  $out=shell_exec($full);
  if($out===null){ $err="curl returned null"; return ''; }
  if(stripos($out,'curl:')!==false) $err=trim($out);
  return (string)$out;
}

function smtp_test_send(string $to,string &$err=''): bool {
  $smtp_host=get_setting('smtp_host'); $smtp_port=get_setting('smtp_port','587');
  $smtp_user=get_setting('smtp_user'); $smtp_pass=get_setting('smtp_pass'); $smtp_from=get_setting('smtp_from');
  if(!$smtp_host||!$smtp_user||!$smtp_pass||!$smtp_from){ $err="SMTP config incomplete"; return false; }
  $tmp=tempnam(sys_get_temp_dir(),'msmtp_'); $cfg=$tmp.".conf"; rename($tmp,$cfg);
  file_put_contents($cfg,"defaults
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
  chmod($cfg,0600);
  $subj="MailToPrint SMTP Test ".date('c');
  $body="SMTP Test erfolgreich.
Host: $smtp_host
User: $smtp_user
Zeit: ".date('c')."
";
  $mail="From: $smtp_from
To: $to
Subject: $subj
Content-Type: text/plain; charset=utf-8

$body
";
  $proc=proc_open("msmtp --file=".escapeshellarg($cfg)." -t",[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$p);
  if(!is_resource($proc)){ $err="proc_open failed"; @unlink($cfg); return false; }
  fwrite($p[0],$mail); fclose($p[0]);
  $o=stream_get_contents($p[1]); fclose($p[1]);
  $e=stream_get_contents($p[2]); fclose($p[2]);
  $rc=proc_close($proc); @unlink($cfg);
  if($rc!==0){ $err=trim($e?:$o?:"msmtp rc=$rc"); return false; }
  return true;
}

if(postbtn('save')){
  $imap_host=trim($_POST['imap_host']??''); $imap_user=trim($_POST['imap_user']??''); $imap_pass=trim($_POST['imap_pass']??'');
  $imap_inbox=trim($_POST['imap_inbox']??'INBOX'); $imap_done=trim($_POST['imap_done']??'INBOX.Printed'); $imap_blocked=trim($_POST['imap_blocked']??'INBOX.Blocked');
  $smtp_host=trim($_POST['smtp_host']??''); $smtp_port=trim($_POST['smtp_port']??'587'); $smtp_user=trim($_POST['smtp_user']??''); $smtp_pass=trim($_POST['smtp_pass']??''); $smtp_from=trim($_POST['smtp_from']??'');
  $dry=isset($_POST['dry_run'])?'1':'0'; $auto=isset($_POST['automation_enabled'])?'1':'0'; $wl=isset($_POST['whitelist_enabled'])?'1':'0';
  $printer_mode=$_POST['printer_mode']??'queue'; $printer_queue=trim($_POST['printer_queue']??''); $printer_uri=trim($_POST['printer_uri']??'');

  if($imap_host===''||$imap_user===''||$imap_pass==='') $errors[]="IMAP unvollständig.";
  if($smtp_host===''||$smtp_user===''||$smtp_pass===''||$smtp_from==='') $errors[]="SMTP unvollständig.";
  if(!can_enable_automation()){ $auto='0'; $errors[]="Kein Drucker gefunden -> Automatik AUS."; }
  else {
    if($printer_mode==='queue' && $printer_queue==='') $errors[]="Bitte Queue wählen.";
    if($printer_mode==='uri' && $printer_uri==='') $errors[]="Bitte URI wählen.";
  }

  if(!$errors){
    $printer_name=$printer_queue;
    if($printer_mode==='uri'){
      $printer_name='mailtoprint';
      if(!ensure_queue($printer_name,$printer_uri)){ $errors[]="Queue-Anlage fehlgeschlagen. Automatik AUS."; $auto='0'; }
    }
    if(!$errors){
      set_setting('imap_host',$imap_host); set_setting('imap_user',$imap_user); set_setting('imap_pass',$imap_pass);
      set_setting('imap_inbox',$imap_inbox); set_setting('imap_done',$imap_done); set_setting('imap_blocked',$imap_blocked);
      set_setting('smtp_host',$smtp_host); set_setting('smtp_port',$smtp_port); set_setting('smtp_user',$smtp_user); set_setting('smtp_pass',$smtp_pass); set_setting('smtp_from',$smtp_from);
      set_setting('printer_name',$printer_name); set_setting('dry_run',$dry); set_setting('automation_enabled',$auto); set_setting('whitelist_enabled',$wl);
      $saved=true; log_msg("Config saved automation=$auto printer=$printer_name dry=$dry wl=$wl");
    }
  }
}

if(postbtn('test_imap')){
  $err=''; $out=curl_imap(get_setting('imap_host'),get_setting('imap_user'),get_setting('imap_pass'),get_setting('imap_inbox','INBOX'),"UID SEARCH UNSEEN",$err);
  if($err) $msg="IMAP Test fehlgeschlagen: $err";
  else {
    $cnt=0; if(preg_match('/\* SEARCH(.*)\r?\n/',$out,$m)){ $uids=preg_split('/\s+/',trim($m[1])); $uids=array_filter($uids,fn($x)=>$x!==''); $cnt=count($uids); }
    $msg="IMAP Test OK. UNSEEN in INBOX: $cnt";
  }
}
if(postbtn('test_smtp')){
  $err=''; $to=get_setting('smtp_from');
  if(smtp_test_send($to,$err)) $msg="SMTP Test OK. Testmail gesendet an: $to";
  else $msg="SMTP Test fehlgeschlagen: $err";
}
if(postbtn('test_printer')){
  $printer=get_setting('printer_name','');
  if($printer==='') $msg="Printer Test: Keine Queue gesetzt.";
  else {
    $rc=0; $l=exec_lines("lpstat -p ".escapeshellarg($printer)." 2>&1",$rc);
    if($rc!==0) $msg="Printer Test fehlgeschlagen: ".implode("\n",$l);
    else {
      $text="MailToPrint Printer Test\nZeit: ".date('c')."\nHost: ".gethostname()."\nQueue: $printer\n";
      $tmp=tempnam(sys_get_temp_dir(),'mtp_').".txt"; file_put_contents($tmp,$text);
      $o=[]; exec("lp -d ".escapeshellarg($printer)." ".escapeshellarg($tmp)." 2>&1",$o,$rc2); @unlink($tmp);
      $msg=$rc2!==0 ? "Printer Test Druck fehlgeschlagen: ".implode("\n",$o) : "Printer Test OK. Testseite gesendet (Queue: $printer)";
    }
  }
}
if(postbtn('run_now')){
  $o=[];$rc=0; exec("sudo /usr/local/sbin/mailtoprint-run-once 2>&1",$o,$rc);
  $msg="Manueller Lauf: rc=$rc\n".implode("\n",$o);
  log_msg("Manual run invoked rc=$rc");
}

page_header('YConfig');
?>
<h1 class="mb-3">YConfig</h1>

<?php if($saved): ?><div class="alert alert-success">Gespeichert.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div><?php endif; ?>
<?php if($msg): ?><div class="alert alert-info"><pre class="mb-0"><?= h($msg) ?></pre></div><?php endif; ?>

<form method="post" class="card shadow-sm">
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <h6>IMAP</h6>
        <label class="form-label">Host</label><input class="form-control mb-2" name="imap_host" value="<?= h(get_setting('imap_host')) ?>" required>
        <label class="form-label">User</label><input class="form-control mb-2" name="imap_user" value="<?= h(get_setting('imap_user')) ?>" required>
        <label class="form-label">Passwort</label><input type="password" class="form-control mb-2" name="imap_pass" value="<?= h(get_setting('imap_pass')) ?>" required>
        <label class="form-label">INBOX</label><input class="form-control mb-2" name="imap_inbox" value="<?= h(get_setting('imap_inbox','INBOX')) ?>">
        <label class="form-label">DONE</label><input class="form-control mb-2" name="imap_done" value="<?= h(get_setting('imap_done','INBOX.Printed')) ?>">
        <label class="form-label">BLOCKED</label><input class="form-control" name="imap_blocked" value="<?= h(get_setting('imap_blocked','INBOX.Blocked')) ?>">
      </div>

      <div class="col-md-6">
        <h6>SMTP</h6>
        <label class="form-label">Host</label><input class="form-control mb-2" name="smtp_host" value="<?= h(get_setting('smtp_host')) ?>" required>
        <div class="row g-2">
          <div class="col-4"><label class="form-label">Port</label><input class="form-control mb-2" name="smtp_port" value="<?= h(get_setting('smtp_port','587')) ?>" required></div>
          <div class="col-8"><label class="form-label">From</label><input class="form-control mb-2" name="smtp_from" value="<?= h(get_setting('smtp_from')) ?>" required></div>
        </div>
        <label class="form-label">User</label><input class="form-control mb-2" name="smtp_user" value="<?= h(get_setting('smtp_user')) ?>" required>
        <label class="form-label">Passwort</label><input type="password" class="form-control" name="smtp_pass" value="<?= h(get_setting('smtp_pass')) ?>" required>

        <hr class="my-3">
        <h6>Drucker</h6>
        <label class="form-label">Modus</label>
        <select class="form-select mb-2" name="printer_mode">
          <option value="queue">CUPS Queue wählen</option>
          <option value="uri">Netzwerkgerät (URI) wählen + Queue "mailtoprint"</option>
        </select>
        <label class="form-label">Queue</label>
        <select class="form-select mb-2" name="printer_queue">
          <option value="">— bitte wählen —</option>
          <?php foreach($queues as $q): ?>
            <option value="<?=h($q)?>" <?= get_setting('printer_name','')===$q?'selected':'' ?>><?=h($q)?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label">Netzwerkgerät</label>
        <select class="form-select" name="printer_uri">
          <option value="">— bitte wählen —</option>
          <?php foreach($choices as $c) echo '<option value="'.h($c['uri']).'">'.h($c['label']).'</option>'; ?>
        </select>

        <hr class="my-3">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="dry_run" id="dry" <?= get_setting('dry_run','0')==='1'?'checked':'' ?>><label class="form-check-label" for="dry">Dry-Run</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="automation_enabled" id="auto" <?= get_setting('automation_enabled','0')==='1'?'checked':'' ?>><label class="form-check-label" for="auto">Automatik aktiv</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="whitelist_enabled" id="wl" <?= get_setting('whitelist_enabled','0')==='1'?'checked':'' ?>><label class="form-check-label" for="wl">Whitelist aktiv</label></div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button class="btn btn-primary" name="save" value="1">Speichern</button>
      <button class="btn btn-outline-secondary" name="test_imap" value="1">IMAP testen</button>
      <button class="btn btn-outline-secondary" name="test_smtp" value="1">SMTP testen</button>
      <button class="btn btn-outline-secondary" name="test_printer" value="1">Drucker testen</button>
      <button class="btn btn-warning" name="run_now" value="1">Jetzt prüfen</button>
    </div>
  </div>
</form>

<?php page_footer(); ?>
