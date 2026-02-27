<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// ✅ Set your admin credentials here
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'BiSSmillah#$%543';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = (string)($_POST['name'] ?? '');
  $p = (string)($_POST['password'] ?? '');

  if (hash_equals($ADMIN_USER, $u) && hash_equals($ADMIN_PASS, $p)) {
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_user'] = $u;
    header('Location: /streams.php');
    exit;
  } else {
    $msg = 'Wrong admin or password.';
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Login</title>
  <style>
    body{font-family:Arial,sans-serif; max-width:420px; margin:60px auto; padding:0 12px;}
    .card{border:1px solid #ddd; padding:16px; border-radius:12px;}
    input,button{width:100%; padding:12px; margin:8px 0;}
    .bad{background:#ffecec; padding:10px; border-radius:8px;}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin-top:0;">Admin Login</h2>
    <?php if ($msg): ?><div class="bad"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input name="name" placeholder="Admin" value="admin" required>
      <input name="password" type="password" placeholder="Password" required>
      <button type="submit">Login</button>
    </form>
  </div>
</body>
</html>
