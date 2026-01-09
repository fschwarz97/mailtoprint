<?php
require __DIR__ . '/../www/lib.php';
ensure_schema();

// Silent when nothing happens; log only on actions/errors.
if (!is_installed()) exit(0);
if (get_setting('automation_enabled','0') !== '1') exit(0);

$printer = get_setting('printer_name','');
if ($printer === '') { log_msg("Worker error: no printer configured"); exit(1); }

$dry = get_setting('dry_run','0') === '1';

$imap_host = get_setting('imap_host');
$imap_port = get_setting('imap_port','993');
$imap_tls  = get_setting('imap_tls','imaps'); // imaps | starttls | none
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

function msmtp_make_cfg(): string {
  $smtp_host = get_setting('smtp_host');
  $smtp_port = get_setting('smtp_port','587');
  $smtp_tls  = get_setting('smtp_tls','starttls'); // starttls | ssl | none
  $smtp_user = get_setting('smtp_user');
  $smtp_pass = get_setting('smtp_pass');
  $smtp_from = get_setting('smtp_from');

  $tls_block = "";
  if ($smtp_tls === 'starttls') $tls_block = "tls on\ntls_starttls on\n";
  elseif ($smtp_tls === 'ssl')  $tls_block = "tls on\ntls_starttls off\n";
  else                          $tls_block = "tls off\n";

  $tmp = tempnam(sys_get_temp_dir(), 'msmtp_');
  $cfg = $tmp . ".conf";
  rename($tmp, $cfg);
  file_put_contents($cfg,
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
  chmod($cfg, 0600);
  return $cfg;
}

function send_mail_msmtp(string $to, string $subj, string $body): void {
  $to = trim($to);
  if ($to === '') return;

  $smtp_host = get_setting('smtp_host');
  $smtp_user = get_setting('smtp_user');
  $smtp_pass = get_setting('smtp_pass');
  $smtp_from = get_setting('smtp_from');
  if (!$smtp_host || !$smtp_user || !$smtp_pass || !$smtp_from) return;

  $cfg = msmtp_make_cfg();
  $msg = "From: $smtp_from\nTo: $to\nSubject: $subj\nContent-Type: text/plain; charset=utf-8\n\n$body\n";

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

function parse_subject_opts(string $subject): array {
  $s = strtolower($subject);
  $color='sw'; $sides='1';
  if (preg_match('/(sw|bunt)\s*-\s*(1|2)/',$s,$mm)){ $color=$mm[1]; $sides=$mm[2]; }
  $copies=1;
  if (preg_match('/(^|[^a-z0-9])x([0-9]{1,3})([^0-9]|$)/',$s,$mm)) $copies=(int)$mm[2];
  if (preg_match('/(kopien|copies)\s*=\s*([0-9]{1,3})/',$s,$mm)) $copies=(int)$mm[2];
  $copies=max(1,min(50,$copies));

  $opts=[];
  $opts[] = ($color==='bunt') ? "-o ColorModel=RGB" : "-o ColorModel=Gray";
  $opts[] = ($sides==='2') ? "-o sides=two-sided-long-edge" : "-o sides=one-sided";
  return [$opts, $copies, $color, $sides];
}

function imap_mailbox_string(string $host,string $port,string $tls,string $folder): string {
  $flags = "/imap";
  if ($tls === 'imaps') $flags .= "/ssl";
  elseif ($tls === 'starttls') $flags .= "/tls";
  // tls=none => plain imap
  return "{" . $host . ":" . $port . $flags . "}" . $folder;
}

function decode_part($stream, int $msgno, $part, string $partno): string {
  $data = imap_fetchbody($stream, $msgno, $partno);
  if ($part->encoding == ENCBASE64) return base64_decode($data);
  if ($part->encoding == ENCQUOTEDPRINTABLE) return quoted_printable_decode($data);
  return $data;
}

function collect_pdfs($stream, int $msgno, $part, string $partno, array &$out): void {
  $is_pdf = false;

  $subtype = strtoupper($part->subtype ?? '');
  if ($subtype === 'PDF') $is_pdf = true;

  // Check parameters / dparameters for filename
  $filename = '';
  if (!empty($part->dparameters)) {
    foreach ($part->dparameters as $p) {
      if (strtolower($p->attribute) === 'filename') $filename = (string)$p->value;
    }
  }
  if ($filename === '' && !empty($part->parameters)) {
    foreach ($part->parameters as $p) {
      if (strtolower($p->attribute) === 'name') $filename = (string)$p->value;
    }
  }
  if ($filename !== '' && preg_match('/\.pdf$/i', $filename)) $is_pdf = true;

  if ($is_pdf && ($part->type ?? -1) != TYPEMULTIPART) {
    $blob = decode_part($stream, $msgno, $part, $partno);
    // Magic header fallback
    if (str_starts_with($blob, "%PDF-") || $subtype === 'PDF') {
      $out[] = ['name'=>$filename ?: ("attachment-" . $partno . ".pdf"), 'data'=>$blob];
    }
    return;
  }

  if (!empty($part->parts)) {
    $idx = 1;
    foreach ($part->parts as $p) {
      $pn = $partno === '' ? (string)$idx : ($partno . "." . $idx);
      collect_pdfs($stream, $msgno, $p, $pn, $out);
      $idx++;
    }
  }
}

$mbox = @imap_open(imap_mailbox_string($imap_host,$imap_port,$imap_tls,$imap_inbox), $imap_user, $imap_pass);
if (!$mbox) {
  log_msg("Worker error: imap_open failed: " . imap_last_error());
  exit(1);
}

$uids = imap_search($mbox, 'UNSEEN', SE_UID);
if (!$uids || count($uids) === 0) {
  imap_close($mbox);
  exit(0); // no noise
}

$workbase = base_dir()."/data/work";
@mkdir($workbase,0770,true);

foreach($uids as $uid){
  $msgno = imap_msgno($mbox, $uid);
  if ($msgno <= 0) { log_msg("Worker error: cannot resolve msgno for uid=$uid"); continue; }

  $header = imap_headerinfo($mbox, $msgno);
  $subject = isset($header->subject) ? (string)imap_utf8($header->subject) : '';
  $from = '';
  if (!empty($header->from) && isset($header->from[0])) {
    $f = $header->from[0];
    $mailbox = $f->mailbox ?? '';
    $host = $f->host ?? '';
    if ($mailbox && $host) $from = strtolower($mailbox . '@' . $host);
  }

  // Whitelist
  if ($whitelist_enabled) {
    if ($from==='' || !in_array(strtolower($from), $wl, true)) {
      log_msg("Worker: uid=$uid BLOCKED from=$from subj=".$subject." files=".(isset($printed_names)?implode(",",$printed_names):"-"));
      if ($dry) {
        send_mail_msmtp($from, "Druckauftrag abgelehnt (Dry-Run): ".($subject?:'ohne Betreff'),
          "Ihr Druckauftrag wurde abgelehnt.");
      } else {
        imap_setflag_full($mbox, (string)$msgno, "\\Seen");
        imap_mail_move($mbox, (string)$msgno, $imap_blocked);
        imap_expunge($mbox);
        send_mail_msmtp($from, "Druckauftrag abgelehnt: ".($subject?:'ohne Betreff'),
          "Ihr Druckauftrag wurde abgelehnt.");
      }
      continue;
    }
  }

  $structure = imap_fetchstructure($mbox, $msgno);
  $pdfs = [];
  if ($structure) collect_pdfs($mbox, $msgno, $structure, '', $pdfs);

  if (count($pdfs) === 0) {
    log_msg("Worker: uid=$uid NO_PDF from=$from subj=".$subject." files=-");
    if (!$dry) send_mail_msmtp($from, "FEHLER: Kein PDF-Anhang", "Kein PDF-Anhang gefunden. Bitte PDF anhängen und erneut senden.");
    continue;
  }

  [$opts, $copies] = parse_subject_opts($subject);

  $dir=$workbase."/uid-$uid-".time();
  @mkdir($dir,0770,true);
  $attach=$dir."/attach";
  @mkdir($attach,0770,true);

  $printed=0; $failed=0;
  $printed_names=[];
  foreach($pdfs as $p){
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', $p['name']);
    if ($fn === '') $fn = "attachment.pdf";
    if (!preg_match('/\.pdf$/i', $fn)) $fn .= ".pdf";
    $path = $attach . "/" . $fn;
    file_put_contents($path, $p['data']);

    if ($dry) {
      log_msg("DRY uid=$uid would print $fn copies=$copies opts=".implode(' ',$opts));
      $printed_names[]=$fn;
      $printed++;
      continue;
    }

    $cmd="lp -d ".escapeshellarg($printer)." -n ".escapeshellarg((string)$copies)." ".implode(' ',$opts)." ".escapeshellarg($path)." 2>&1";
    $out=shell_exec($cmd);
    if($out===null){ $failed++; log_msg("lp failed null uid=$uid file=$fn"); }
    else {
      if(stripos($out,'error')!==false){ $failed++; log_msg("lp error uid=$uid: ".trim($out)); }
      else { $printed++; $printed_names[]=$fn; }
    }
  }

  if ($dry) {
    send_mail_msmtp($from, "Druckauftrag (Dry-Run): ".($subject?:'ohne Betreff'),
      "Dry-Run aktiv: NICHT gedruckt und NICHT verschoben.\nPDFs: $printed\nOptionen: ".implode(' ',$opts)."\nKopien: $copies");
    continue;
  }

  if ($failed > 0) {
    log_msg("Worker: uid=$uid print failed=$failed keep in inbox");
    send_mail_msmtp($from, "FEHLER beim Druck: ".($subject?:'ohne Betreff'),
      "Fehler beim Drucken.\nErfolgreich: $printed\nFehlgeschlagen: $failed\nMail bleibt im INBOX und wird erneut versucht.");
    continue;
  }

  imap_setflag_full($mbox, (string)$msgno, "\\Seen");
  imap_mail_move($mbox, (string)$msgno, $imap_done);
  imap_expunge($mbox);

  log_msg("Worker: uid=$uid SUCCESS from=$from subj=".$subject." files=".implode(",", $printed_names));
  send_mail_msmtp($from, "Druckauftrag erfolgreich: ".($subject?:'ohne Betreff'),
    "Erfolgreich gedruckt.\nPDFs:\n" . implode("\n", array_map(fn($x)=>$x, $printed_names))");
}

imap_close($mbox);
