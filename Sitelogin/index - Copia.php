<?php
session_start();
require_once 'config.php';

if (!empty($_SESSION['user_email'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $old['email'] = htmlspecialchars($_POST['email'] ?? '');

    if (!$email) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if (empty($password)) {
        $errors[] = 'Informe a senha.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['senha'])) {
            session_regenerate_id(true);
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_url'] = $user['url'] ?? null;
            $_SESSION['user_nome'] = $user['nome'] ?? null;
            header('Location: dashboard.php');
            exit;
        } else {
            $errors[] = 'E-mail ou senha incorretos.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #000000ff; }
    .card { max-width: 420px; margin: 48px auto; }
  </style>
</head>
<body>

<nav class="navbar fixed-top bg-body-tertiary">
  <div class="container-fluid">
<img id="image-13-225" alt="" src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Logo.png" class="ct-image" style="cursor: pointer;">
</a>
<nav class="navbar bg-body-tertiary">
  <div class="container-fluid">
  </div>
</nav>

</div>
</nav>
<div class="container">
<img id="image-13-225" alt="" src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Logo.png" class="ct-image" style="cursor: pointer;">
</div>
<div class="card shadow-sm">
  <div class="card-body">
    <h4 class="card-title mb-3">Entrar</h4>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="mb-3">
        <label for="email" class="form-label">E-mail</label>
        <input type="email" class="form-control" id="email" name="email" required value="<?= $old['email'] ?>">
      </div>

      <div class="mb-3">
        <label for="password" class="form-label">Senha</label>
        <input type="password" class="form-control" id="password" name="password" required>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-primary">Entrar</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
