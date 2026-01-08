<?php
declare(strict_types=1);
function base_dir(): string { return realpath(__DIR__ . '/..'); }
function db_path(): string { return base_dir() . '/data/app.db'; }
function log_path(): string { return base_dir() . '/logs/app.log'; }
function log_msg(string $msg): void { file_put_contents(log_path(), sprintf("[%s] %s\n", date('c'), $msg), FILE_APPEND); }
function db(): PDO { static $pdo=null; if($pdo) return $pdo; $pdo=new PDO("sqlite:".db_path()); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }
function ensure_schema(): void { $pdo=db(); $pdo->exec("CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL)"); $pdo->exec("CREATE TABLE IF NOT EXISTS whitelist(email TEXT PRIMARY KEY)"); }
function get_setting(string $k, string $d=''): string { $st=db()->prepare("SELECT value FROM settings WHERE key=:k"); $st->execute([':k'=>$k]); $v=$st->fetchColumn(); return $v===false?$d:(string)$v; }
function set_setting(string $k, string $v): void { $st=db()->prepare("INSERT INTO settings(key,value) VALUES(:k,:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value"); $st->execute([':k'=>$k,':v'=>$v]); }
function is_installed(): bool { if(!file_exists(db_path())) return false; try{ ensure_schema(); return get_setting('installed','0')==='1'; } catch(Throwable $e){ return false; } }
function redirect_to(string $p): void { header("Location: $p"); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function exec_lines(string $cmd, int &$rc=null): array { $out=[]; $rc=0; exec($cmd,$out,$rc); return $out; }
function cups_queues(): array { $rc=0; $l=exec_lines("lpstat -p 2>/dev/null",$rc); if($rc!==0) return []; $q=[]; foreach($l as $ln){ if(preg_match('/^printer\s+(\S+)/',$ln,$m)) $q[]=$m[1]; } $q=array_values(array_unique($q)); sort($q); return $q; }
function lpinfo_uris(): array { $rc=0; $l=exec_lines("lpinfo -v 2>/dev/null",$rc); if($rc!==0) return []; $u=[]; foreach($l as $ln){ $p=preg_split('/\s+/',trim($ln)); $uri=$p[count($p)-1]??''; if(preg_match('#^(dnssd|ipp|ipps|socket|lpd)://#',$uri)) $u[]=$uri; } $u=array_values(array_unique($u)); sort($u); return $u; }
function avahi_printers(): array { $s=[]; foreach(['_ipp._tcp','_ipps._tcp'] as $svc){ $rc=0; $l=exec_lines("avahi-browse -rt {$svc} 2>/dev/null",$rc); if($rc!==0) continue; foreach($l as $ln){ if(!str_starts_with($ln,'=')) continue; $p=explode(';',$ln); if(count($p)<9) continue; $name=$p[3]??''; $type=$p[4]??''; $host=$p[6]??''; $addr=$p[7]??''; $port=$p[8]??''; if(!$name||!$host||!$addr||!$port) continue; $s[]=['name'=>$name,'type'=>$type,'host'=>$host,'addr'=>$addr,'port'=>$port]; } } $uniq=[]; foreach($s as $x){ $k=strtolower($x['name'].'|'.$x['addr'].'|'.$x['port'].'|'.$x['type']); $uniq[$k]=$x; } return array_values($uniq); }
function discover_printer_choices(): array { $uris=lpinfo_uris(); $av=avahi_printers(); $lookup=[]; foreach($av as $a){ $lookup[$a['addr'].':'.$a['port']][]=$a; } $c=[]; foreach($uris as $u){ $label=$u; if(preg_match('#^ipps?://([^/:]+)(?::(\d+))?#',$u,$m)){ $host=$m[1]; $port=$m[2]??'631'; $ip=$host; if(!preg_match('/^\d+\.\d+\.\d+\.\d+$/',$host)) $ip=gethostbyname($host); $key=$ip.':'.$port; if(isset($lookup[$key])){ $a=$lookup[$key][0]; $proto=str_contains($a['type'],'ipps')?'ipps':'ipp'; $label=sprintf("%s (%s) @ %s",$a['name'],$proto,$a['host']); } } $c[]=['uri'=>$u,'label'=>$label]; } usort($c, fn($a,$b)=>strcmp($a['label'],$b['label'])); return $c; }
function ensure_queue(string $queue, string $uri): bool { $q=escapeshellarg($queue); $u=escapeshellarg($uri); exec("lpadmin -x $q >/dev/null 2>&1"); $o=[];$rc=0; exec("lpadmin -p $q -E -v $u -m everywhere 2>&1",$o,$rc); if($rc!==0){ log_msg("lpadmin failed rc=$rc: ".implode("\n",$o)); return false; } exec("lpadmin -d $q >/dev/null 2>&1"); return true; }
function can_enable_automation(): bool { return count(cups_queues())>0 || count(lpinfo_uris())>0; }
