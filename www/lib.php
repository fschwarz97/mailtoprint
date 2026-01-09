<?php
declare(strict_types=1);

function base_dir(): string { return realpath(__DIR__ . '/..'); }
function db_path(): string { return base_dir() . '/data/app.db'; }
function log_path(): string { return base_dir() . '/logs/app.log'; }

function log_msg(string $msg): void {
  $line = sprintf("[%s] %s\n", date('c'), $msg);
  file_put_contents(log_path(), $line, FILE_APPEND);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO("sqlite:" . db_path());
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

function ensure_schema(): void {
  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS whitelist (email TEXT PRIMARY KEY)");
}

function get_setting(string $k, string $default=''): string {
  $stmt = db()->prepare("SELECT value FROM settings WHERE key=:k LIMIT 1");
  $stmt->execute([':k'=>$k]);
  $v = $stmt->fetchColumn();
  return $v===false ? $default : (string)$v;
}

function set_setting(string $k, string $v): void {
  $stmt = db()->prepare("INSERT INTO settings(key,value) VALUES(:k,:v)
    ON CONFLICT(key) DO UPDATE SET value=excluded.value");
  $stmt->execute([':k'=>$k, ':v'=>$v]);
}

function is_installed(): bool {
  if (!file_exists(db_path())) return false;
  try { ensure_schema(); return get_setting('installed','0') === '1'; }
  catch (Throwable $e) { return false; }
}

function redirect_to(string $path): void { header("Location: $path"); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function exec_lines(string $cmd, int &$rc=null): array { $out=[]; $rc=0; exec($cmd,$out,$rc); return $out; }

function cups_queues(): array {
  $rc=0; $lines=exec_lines("lpstat -p 2>/dev/null", $rc);
  if ($rc!==0) return [];
  $qs=[];
  foreach ($lines as $ln) if (preg_match('/^printer\s+(\S+)\s+/', $ln, $m)) $qs[]=$m[1];
  $qs=array_values(array_unique($qs)); sort($qs); return $qs;
}

function lpinfo_uris(): array {
  $rc=0; $lines=exec_lines("lpinfo -v 2>/dev/null", $rc);
  if ($rc!==0) return [];
  $uris=[];
  foreach ($lines as $ln) {
    $parts=preg_split('/\s+/', trim($ln));
    $uri=$parts[count($parts)-1] ?? '';
    if (preg_match('#^(dnssd|ipp|ipps|socket|lpd)://#', $uri)) $uris[]=$uri;
  }
  $uris=array_values(array_unique($uris)); sort($uris); return $uris;
}

function avahi_printers(): array {
  $services = [];
  foreach (['_ipp._tcp','_ipps._tcp'] as $svc) {
    $rc=0; $lines = exec_lines("avahi-browse -rt {$svc} 2>/dev/null", $rc);
    if ($rc!==0) continue;
    foreach ($lines as $ln) {
      if (!str_starts_with($ln, '=')) continue;
      $parts = explode(';', $ln);
      if (count($parts) < 9) continue;
      $name = $parts[3] ?? ''; $type = $parts[4] ?? ''; $host = $parts[6] ?? '';
      $addr = $parts[7] ?? ''; $port = $parts[8] ?? '';
      if ($name==='' || $host==='' || $addr==='' || $port==='') continue;
      $services[] = ['name'=>$name,'type'=>$type,'host'=>$host,'addr'=>$addr,'port'=>$port];
    }
  }
  $uniq=[]; foreach ($services as $s) { $k = strtolower($s['name'].'|'.$s['addr'].'|'.$s['port'].'|'.$s['type']); $uniq[$k]=$s; }
  return array_values($uniq);
}

function discover_printer_choices(): array {
  $uris = lpinfo_uris();
  $avahi = avahi_printers();
  $lookup=[]; foreach ($avahi as $a) $lookup[$a['addr'].':'.$a['port']][] = $a;

  $choices=[];
  foreach ($uris as $u) {
    $label = $u;
    if (preg_match('#^ipps?://([^/:]+)(?::(\d+))?#', $u, $m)) {
      $host = $m[1]; $port = $m[2] ?? '631';
      $ip = $host;
      if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) $ip = gethostbyname($host);
      $key = $ip.':'.$port;
      if (isset($lookup[$key])) {
        $a = $lookup[$key][0];
        $proto = str_contains($a['type'], 'ipps') ? 'ipps' : 'ipp';
        $label = sprintf("%s (%s) @ %s", $a['name'], $proto, $a['host']);
      } else $label = sprintf("%s @ %s:%s", $u, $host, $port);
    }
    $choices[] = ['uri'=>$u, 'label'=>$label];
  }
  usort($choices, fn($a,$b)=>strcmp($a['label'],$b['label']));
  return $choices;
}

function ensure_queue(string $queue, string $uri): bool {
  $q = escapeshellarg($queue); $u = escapeshellarg($uri);
  exec("lpadmin -x $q >/dev/null 2>&1");
  $out=[]; $rc=0;
  exec("lpadmin -p $q -E -v $u -m everywhere 2>&1", $out, $rc);
  if ($rc!==0) { log_msg("lpadmin failed rc=$rc: ".implode("\n",$out)); return false; }
  exec("lpadmin -d $q >/dev/null 2>&1");
  return true;
}

function can_enable_automation(): bool { return count(cups_queues()) > 0 || count(lpinfo_uris()) > 0; }
