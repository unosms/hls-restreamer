<?php
// /var/www/html/download.php

$f = $_GET['f'] ?? '';
if (!$f) { http_response_code(400); exit("Missing file"); }

$real = realpath($f);
if ($real === false) { http_response_code(404); exit("Not found"); }

// Allow ONLY backups created by our script in /tmp
if (!preg_match('#^/tmp/hls_backup_[0-9]{8}_[0-9]{6}\.tar\.gz$#', $real)) {
  http_response_code(403); exit("Forbidden");
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="'.basename($real).'"');
header('Content-Length: ' . filesize($real));
header('Cache-Control: no-store');

readfile($real);
