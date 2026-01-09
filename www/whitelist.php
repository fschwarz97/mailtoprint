<?php
require __DIR__ . '/lib.php';
ensure_schema();
require __DIR__ . '/_layout.php';
if (!is_installed()) redirect_to('install.php');

$errors=[]; $saved=false; $pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
  $enabled = isset($_POST['whitelist_enabled']) ? '1' : '0';
  set_setting('whitelist_enabled',$enabled);
  $raw=trim($_POST['emails']??'');
  $pdo->exec("DELETE FROM whitelist");
  if($raw!==''){
    $lines=preg_split('/\R/',$raw);
    $ins=$pdo->prepare("INSERT OR IGNORE INTO whitelist(email) VALUES(:e)");
    foreach($lines as $ln){
      $e=trim($ln);
      if($e===''||str_starts_with($e,'#')) continue;
      if(!filter_var($e,FILTER_VALIDATE_EMAIL)){ $errors[]="Ungültig: $e"; continue; }
      $ins->execute([':e'=>$e]);
    }
  }
  if(!$errors){ $saved=true; log_msg("Whitelist updated enabled=$enabled"); }
}

$enabled=get_setting('whitelist_enabled','0');
$emails=$pdo->query("SELECT email FROM whitelist ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

page_header('Whitelist');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="mb-0">Whitelist</h1>
  <a class="btn btn-outline-secondary btn-sm" href="config.php">Zurück</a>
</div>
<?php if($saved): ?><div class="alert alert-success">Gespeichert.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div><?php endif; ?>

<form method="post" class="card shadow-sm">
  <div class="card-body">
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="whitelist_enabled" id="wl" <?=$enabled==='1'?'checked':''?>>
      <label class="form-check-label" for="wl">Whitelist aktiv</label>
    </div>
    <label class="form-label">Eine E-Mail pro Zeile (Kommentare mit #)</label>
    <textarea class="form-control" name="emails" rows="14"><?=h(implode("\n",$emails))?></textarea>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </div>
</form>
<?php page_footer(); ?>
