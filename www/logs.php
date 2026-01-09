<?php
require __DIR__ . '/lib.php';
ensure_schema();
require __DIR__ . '/_layout.php';
if (!is_installed()) redirect_to('install.php');

$logfile=log_path();
$q=trim($_GET['q']??'');
$limit=(int)($_GET['limit']??500);
if($limit<50)$limit=50; if($limit>5000)$limit=5000;

$rows=[];
if(is_readable($logfile)){
  $lines=@file($logfile, FILE_IGNORE_NEW_LINES) ?: [];
  $lines=array_slice($lines,-$limit);
  foreach($lines as $ln){
    if(preg_match('/^\[(.+?)\]\s+(.*)$/',$ln,$m)){ $ts=$m[1]; $msg=$m[2]; }
    else { $ts=''; $msg=$ln; }
    if($q!=='' && stripos($ln,$q)===false) continue;
    $rows[]=['ts'=>$ts,'msg'=>$msg];
  }
  $rows=array_reverse($rows);
}

page_header('Logs');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="mb-0">Logs</h1>
  <div class="small text-muted"><code><?=h($logfile)?></code></div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-6"><input class="form-control" name="q" placeholder="Filter…" value="<?=h($q)?>" id="q"></div>
  <div class="col-md-2"><input class="form-control" type="number" name="limit" min="50" max="5000" value="<?=h((string)$limit)?>"></div>
  <div class="col-md-4 d-flex gap-2">
    <button class="btn btn-primary" type="submit">Anwenden</button>
    <a class="btn btn-outline-secondary" href="logs.php">Reset</a>
  </div>
</form>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle" id="logtable">
        <thead><tr><th style="width:240px;">Zeit</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="text-muted"><code><?=h($r['ts'])?></code></td>
            <td><pre class="mb-0" style="white-space:pre-wrap;"><?=h($r['msg'])?></pre></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if(!is_readable($logfile)): ?><div class="alert alert-warning mb-0">Logdatei nicht lesbar.</div><?php endif; ?>
  </div>
</div>

<script>
(function(){
  const i=document.getElementById('q'); const t=document.getElementById('logtable');
  if(!i||!t) return;
  i.addEventListener('input', ()=>{
    const n=i.value.toLowerCase();
    t.querySelectorAll('tbody tr').forEach(tr=>{
      tr.style.display=(n===''||tr.innerText.toLowerCase().includes(n))?'':'none';
    });
  });
})();
</script>
<?php page_footer(); ?>
