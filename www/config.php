<?php
require __DIR__ . '/lib.php';
ensure_schema();
require __DIR__ . '/_layout.php';
if (!is_installed()) redirect_to('install.php');

$queues = cups_queues();
$choices = discover_printer_choices();
$errors=[]; $saved=false; $info='';

function postbtn(string $n): bool { return $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST[$n]); }

function curl_imap(string $host,string $port,string $tls,string $user,string $pass,string $mbox,string $cmd,string &$err=''): string {
  $scheme='imaps'; $extra="--ssl-reqd";
  if($tls==='starttls'){ $scheme='imap'; $extra="--ssl-reqd"; }
  if($tls==='none'){ $scheme='imap'; $extra=""; }
  $url="{$scheme}://{$host}:{$port}/{$mbox}";
  $full="curl -sS --fail {$extra} --user ".escapeshellarg("$user:$pass")." ".escapeshellarg($url)." -X ".escapeshellarg($cmd)." 2>&1";
  $out=shell_exec($full);
  if($out===null){ $err="curl returned null"; return ''; }
  if(stripos($out,'curl:')!==false) $err=trim($out);
  return (string)$out;
}

function msmtp_cfg(string $path): void {
  $smtp_host=get_setting('smtp_host');
  $smtp_port=get_setting('smtp_port','587');
  $smtp_tls=get_setting('smtp_tls','starttls');
  $smtp_user=get_setting('smtp_user');
  $smtp_pass=get_setting('smtp_pass');
  $smtp_from=get_setting('smtp_from');

  $tls_block="";
  if($smtp_tls==='starttls') $tls_block="tls on\ntls_starttls on\n";
  elseif($smtp_tls==='ssl') $tls_block="tls on\ntls_starttls off\n";
  else $tls_block="tls off\n";

  file_put_contents($path,
    "defaults\n".
    "auth on\n".
    $tls_block."\n".
    "account a\n".
    "host $smtp_host\n".
    "port $smtp_port\n".
    "user $smtp_user\n".
    "password $smtp_pass\n".
    "from $smtp_from\n\n".
    "account default : a\n"
  );
  chmod($path,0600);
}

function smtp_test(string $to,string &$err=''): bool {
  $smtp_host=get_setting('smtp_host');
  $smtp_user=get_setting('smtp_user');
  $smtp_pass=get_setting('smtp_pass');
  $smtp_from=get_setting('smtp_from');
  if(!$smtp_host||!$smtp_user||!$smtp_pass||!$smtp_from){ $err="SMTP config incomplete"; return false; }

  $tmp=tempnam(sys_get_temp_dir(),'msmtp_'); $cfg=$tmp.".conf"; rename($tmp,$cfg);
  msmtp_cfg($cfg);

  $subj="MailToPrint SMTP Test ".date('c');
  $body="SMTP Test erfolgreich.\nZeit: ".date('c')."\n";
  $msg="From: $smtp_from\nTo: $to\nSubject: $subj\nContent-Type: text/plain; charset=utf-8\n\n$body\n";

  $p=proc_open("msmtp --file=".escapeshellarg($cfg)." -t",[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);
  if(!is_resource($p)){ $err="proc_open failed"; @unlink($cfg); return false; }
  fwrite($pipes[0],$msg); fclose($pipes[0]);
  $o=stream_get_contents($pipes[1]); fclose($pipes[1]);
  $e=stream_get_contents($pipes[2]); fclose($pipes[2]);
  $rc=proc_close($p);
  @unlink($cfg);
  if($rc!==0){ $err=trim($e?:$o?:"msmtp rc=$rc"); return false; }
  return true;
}

if(postbtn('save')){
  $imap_host=trim($_POST['imap_host']??'');
  $imap_port=trim($_POST['imap_port']??'993');
  $imap_tls=trim($_POST['imap_tls']??'imaps');
  $imap_user=trim($_POST['imap_user']??'');
  $imap_pass=trim($_POST['imap_pass']??'');
  $imap_inbox=trim($_POST['imap_inbox']??'INBOX');
  $imap_done=trim($_POST['imap_done']??'INBOX.Printed');
  $imap_blocked=trim($_POST['imap_blocked']??'INBOX.Blocked');

  $smtp_host=trim($_POST['smtp_host']??'');
  $smtp_port=trim($_POST['smtp_port']??'587');
  $smtp_tls=trim($_POST['smtp_tls']??'starttls');
  $smtp_user=trim($_POST['smtp_user']??'');
  $smtp_pass=trim($_POST['smtp_pass']??'');
  $smtp_from=trim($_POST['smtp_from']??'');

  $dry=isset($_POST['dry_run'])?'1':'0';
  $auto=isset($_POST['automation_enabled'])?'1':'0';
  $wl=isset($_POST['whitelist_enabled'])?'1':'0';

  $printer_mode=$_POST['printer_mode']??'queue';
  $printer_queue=trim($_POST['printer_queue']??'');
  $printer_uri=trim($_POST['printer_uri']??'');

  if($imap_host===''||$imap_user===''||$imap_pass==='') $errors[]="IMAP unvollständig.";
  if(!preg_match('/^\d+$/',$imap_port) || (int)$imap_port<1 || (int)$imap_port>65535) $errors[]="IMAP Port ungültig.";
  if(!in_array($imap_tls,['imaps','starttls','none'],true)) $errors[]="IMAP TLS Modus ungültig.";

  if($smtp_host===''||$smtp_user===''||$smtp_pass===''||$smtp_from==='') $errors[]="SMTP unvollständig.";
  if(!preg_match('/^\d+$/',$smtp_port) || (int)$smtp_port<1 || (int)$smtp_port>65535) $errors[]="SMTP Port ungültig.";
  if(!in_array($smtp_tls,['starttls','ssl','none'],true)) $errors[]="SMTP TLS Modus ungültig.";

  if(!can_enable_automation()){ $auto='0'; $errors[]="Kein Drucker gefunden -> Automatik AUS."; }
  else {
    if($printer_mode==='queue' && $printer_queue==='') $errors[]="Bitte Queue wählen.";
    if($printer_mode==='uri' && $printer_uri==='') $errors[]="Bitte URI wählen.";
  }

  if(!$errors){
    $printer_name=$printer_queue;
    if($printer_mode==='uri'){
      $printer_name='mailtoprint';
      if(!ensure_queue($printer_name,$printer_uri)){ $errors[]="Queue-Anlage fehlgeschlagen."; $auto='0'; }
    }
  }

  if(!$errors){
    set_setting('imap_host',$imap_host); set_setting('imap_port',$imap_port); set_setting('imap_tls',$imap_tls);
    set_setting('imap_user',$imap_user); set_setting('imap_pass',$imap_pass);
    set_setting('imap_inbox',$imap_inbox); set_setting('imap_done',$imap_done); set_setting('imap_blocked',$imap_blocked);

    set_setting('smtp_host',$smtp_host); set_setting('smtp_port',$smtp_port); set_setting('smtp_tls',$smtp_tls);
    set_setting('smtp_user',$smtp_user); set_setting('smtp_pass',$smtp_pass); set_setting('smtp_from',$smtp_from);

    set_setting('printer_name',$printer_name);
    set_setting('dry_run',$dry); set_setting('automation_enabled',$auto); set_setting('whitelist_enabled',$wl);
    $saved=true;
    log_msg("Config saved automation=$auto printer=$printer_name dry=$dry wl=$wl");
  }
}

if(postbtn('test_imap')){
  $err='';
  $out=curl_imap(get_setting('imap_host'),get_setting('imap_port','993'),get_setting('imap_tls','imaps'),
    get_setting('imap_user'),get_setting('imap_pass'),get_setting('imap_inbox','INBOX'),
    "UID SEARCH UNSEEN",$err);
  $info = $err ? "IMAP Test fehlgeschlagen: $err" : "IMAP Test OK.";
}

if(postbtn('test_smtp')){
  $err=''; $to=get_setting('smtp_from');
  $info = smtp_test($to,$err) ? "SMTP Test OK (gesendet an $to)" : "SMTP Test fehlgeschlagen: $err";
}

if(postbtn('test_printer')){
  $p=get_setting('printer_name','');
  if($p==='') $info="Printer Test: Keine Queue gesetzt.";
  else {
    $tmp=tempnam(sys_get_temp_dir(),'mtp_').".txt";
    file_put_contents($tmp,"MailToPrint Printer Test\nZeit: ".date('c')."\nQueue: $p\n");
    $out=[]; $rc=0; exec("lp -d ".escapeshellarg($p)." ".escapeshellarg($tmp)." 2>&1",$out,$rc);
    @unlink($tmp);
    $info = $rc===0 ? "Printer Test OK (Job gesendet)" : "Printer Test fehlgeschlagen: ".implode("\n",$out);
  }
}

if(postbtn('run_now')){
  $out=[]; $rc=0; exec("sudo /usr/local/sbin/mailtoprint-run-once 2>&1",$out,$rc);
  $info="Manueller Lauf rc=$rc\n".implode("\n",$out);
}

page_header('YConfig');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="mb-0">YConfig</h1>
  <div class="small text-muted"><a href="logs.php" class="link-secondary">Logs ansehen</a></div>
</div>

<?php if($saved): ?><div class="alert alert-success">Gespeichert.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div><?php endif; ?>
<?php if($info): ?><div class="alert alert-info"><pre class="mb-0"><?= h($info) ?></pre></div><?php endif; ?>

<form method="post" class="card shadow-sm">
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <h6>IMAP</h6>
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Host</label><input class="form-control" name="imap_host" value="<?=h(get_setting('imap_host'))?>" required></div>
          <div class="col-3"><label class="form-label">Port</label><input class="form-control" name="imap_port" value="<?=h(get_setting('imap_port','993'))?>" required></div>
          <div class="col-3"><label class="form-label">TLS</label>
            <?php $v=get_setting('imap_tls','imaps'); ?>
            <select class="form-select" name="imap_tls">
              <option value="imaps" <?=$v==='imaps'?'selected':''?>>IMAPS</option>
              <option value="starttls" <?=$v==='starttls'?'selected':''?>>STARTTLS</option>
              <option value="none" <?=$v==='none'?'selected':''?>>None</option>
            </select>
          </div>
        </div>
        <label class="form-label mt-2">User</label><input class="form-control" name="imap_user" value="<?=h(get_setting('imap_user'))?>" required>
        <label class="form-label mt-2">Passwort</label><input class="form-control" type="password" name="imap_pass" value="<?=h(get_setting('imap_pass'))?>" required>
        <label class="form-label mt-2">INBOX</label><input class="form-control" name="imap_inbox" value="<?=h(get_setting('imap_inbox','INBOX'))?>">
        <label class="form-label mt-2">DONE</label><input class="form-control" name="imap_done" value="<?=h(get_setting('imap_done','INBOX.Printed'))?>">
        <label class="form-label mt-2">BLOCKED</label><input class="form-control" name="imap_blocked" value="<?=h(get_setting('imap_blocked','INBOX.Blocked'))?>">
      </div>

      <div class="col-md-6">
        <h6>SMTP</h6>
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Host</label><input class="form-control" name="smtp_host" value="<?=h(get_setting('smtp_host'))?>" required></div>
          <div class="col-3"><label class="form-label">Port</label><input class="form-control" name="smtp_port" value="<?=h(get_setting('smtp_port','587'))?>" required></div>
          <div class="col-3"><label class="form-label">TLS</label>
            <?php $sv=get_setting('smtp_tls','starttls'); ?>
            <select class="form-select" name="smtp_tls">
              <option value="starttls" <?=$sv==='starttls'?'selected':''?>>STARTTLS</option>
              <option value="ssl" <?=$sv==='ssl'?'selected':''?>>SSL/TLS</option>
              <option value="none" <?=$sv==='none'?'selected':''?>>None</option>
            </select>
          </div>
        </div>
        <label class="form-label mt-2">User</label><input class="form-control" name="smtp_user" value="<?=h(get_setting('smtp_user'))?>" required>
        <label class="form-label mt-2">Passwort</label><input class="form-control" type="password" name="smtp_pass" value="<?=h(get_setting('smtp_pass'))?>" required>
        <label class="form-label mt-2">From</label><input class="form-control" name="smtp_from" value="<?=h(get_setting('smtp_from'))?>" required>

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
            <option value="<?=h($q)?>" <?=get_setting('printer_name','')===$q?'selected':''?>><?=h($q)?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label">Netzwerkgerät</label>
        <select class="form-select" name="printer_uri">
          <option value="">— bitte wählen —</option>
          <?php foreach($choices as $c) echo '<option value="'.h($c['uri']).'">'.h($c['label']).'</option>'; ?>
        </select>

        <hr class="my-3">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="dry_run" id="dry" <?=get_setting('dry_run','0')==='1'?'checked':''?>><label class="form-check-label" for="dry">Dry-Run</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="automation_enabled" id="auto" <?=get_setting('automation_enabled','0')==='1'?'checked':''?>><label class="form-check-label" for="auto">Automatik aktiv</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="whitelist_enabled" id="wl" <?=get_setting('whitelist_enabled','0')==='1'?'checked':''?>><label class="form-check-label" for="wl">Whitelist aktiv</label></div>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button class="btn btn-primary" name="save" value="1" type="submit">Speichern</button>
      <button class="btn btn-outline-secondary" name="test_imap" value="1" type="submit">IMAP testen</button>
      <button class="btn btn-outline-secondary" name="test_smtp" value="1" type="submit">SMTP testen</button>
      <button class="btn btn-outline-secondary" name="test_printer" value="1" type="submit">Drucker testen</button>
      <button class="btn btn-warning" name="run_now" value="1" type="submit">Jetzt prüfen</button>
    </div>
  </div>
</form>
<?php page_footer(); ?>
