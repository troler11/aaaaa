<?php
session_start();

if (empty($_SESSION['user_email'])) {
    header('Location: index.php');
    exit;
}

$email = $_SESSION['user_email'];
$url = $_SESSION['user_url'] ?? '#';
$url = $_SESSION['user_nome'] ?? '#';
?>

<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #000000ff; }
    .card { max-width: 420px; margin: 48px auto; }
  </style>
</head>
<nav class="navbar fixed-top bg-body-tertiary">
  <div class="container-fluid">
<img id="image-13-225" alt="" src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Logo.png" class="ct-image" style="cursor: pointer;">
 
<b>Dashboard <?=$_SESSION['user_nome']?></b>
<nav class="navbar bg-body-tertiary">
  <div class="container-fluid">
    <a class="navbar-brand" href="logout.php">
        Sair 
    </a>
  </div>
</nav>
</div>
</nav>

<body class="p-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h4">Dashboard</h1>
      <a href="logout.php" class="btn btn-outline-secondary btn-sm">Sair</a>
    </div>

    <!-- Este é um parágrafo de exemplo que será oculto -->
       <div ckass="container">
            <iframe title="Dashboard Viação Mimo" width="100%" height="800" src="<?=$_SESSION['user_url']?>" frameborder="0" allowFullScreen="true"></iframe>
        </div>
  </div>
</body>
</html>