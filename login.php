<?php
require_once __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['is_admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Incorrect password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login &mdash; <?= htmlspecialchars(SITE_TITLE) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1><?= htmlspecialchars(SITE_TITLE) ?></h1>
  <nav>
    <a href="index.php">Submit a Request</a>
  </nav>
</header>
<main>
  <div class="card login-box">
    <h2>Admin Login</h2>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autofocus>
      <button type="submit">Log In</button>
    </form>
  </div>
</main>
</body>
</html>
