<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
function page_header(string $title): void { ?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
</head><body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">MailToPrint</a>
    <div class="navbar-nav">
      <a class="nav-link" href="config.php">YConfig</a>
      <a class="nav-link" href="whitelist.php">Whitelist</a>
    </div>
  </div>
</nav>
<div class="container my-4">
<?php }
function page_footer(): void { ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body></html>
<?php }
