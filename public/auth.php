<?php
// /var/www/html/auth.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  // Secure session cookie (works when using HTTPS; if HTTP it still works but Secure won't apply)
  session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  ]);
  session_start();
}

function require_login(): void {
  if (empty($_SESSION['is_admin'])) {
    header('Location: /login.php');
    exit;
  }
}
