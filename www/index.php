<?php
require __DIR__ . '/lib.php';
ensure_schema();
if (!is_installed()) redirect_to('install.php');
redirect_to('config.php');
