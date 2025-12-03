<?php
$tempo_vida = 86400;

ini_set('session.gc_maxlifetime', $tempo_vida);
session_set_cookie_params($tempo_vida);

session_start();

// Carrega as configurações e a conexão $pdo
require_once 'config.php'; 

$erro_login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario_post = trim($_POST['username'] ?? '');
    $senha_post   = trim($_POST['password'] ?? '');

    if (!empty($usuario_post) && !empty($senha_post)) {
        // Usa o $pdo que veio do config.php
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :user LIMIT 1");
        $stmt->execute([':user' => $usuario_post]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($senha_post, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_menus'] = $user['allowed_menus']; // Salva o JSON na sessão

            if ($user['role'] === 'admin') {
                $_SESSION['allowed_companies'] = []; 
            } else {
                $json_empresas = $user['allowed_companies'];
                if (!empty($json_empresas)) {
                    $_SESSION['allowed_companies'] = json_decode($json_empresas, true);
                } else {
                    $_SESSION['allowed_companies'] = []; 
                }
            }

            header("Location: index.php");
            exit;

        } else {
            $erro_login = "Usuário ou senha incorretos.";
        }
    } else {
        $erro_login = "Preencha todos os campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Viação Mimo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0b1f3a 0%, #1e3a8a 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .card-login {
            width: 100%;
            max-width: 400px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            overflow: hidden;
            border: none;
        }
        .card-header {
            background-color: #ffffff;
            border-bottom: none;
            padding-top: 30px;
            padding-bottom: 10px;
            text-align: center;
        }
        .btn-login {
            background-color: #0b1f3a;
            border: none;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .btn-login:hover {
            background-color: #163a6e;
        }
        .logo-img {
            max-width: 180px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<div class="card card-login">
    <div class="card-header">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Logo.png" alt="Logo" class="logo-img">
        <h5 class="text-secondary mt-2">Acesso ao Sistema</h5>
    </div>
    <div class="card-body p-4">
        <?php if(!empty($erro_login)): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div class="ms-2"><?php echo $erro_login; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label for="username" class="form-label fw-bold text-secondary small">USUÁRIO</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-person-fill text-secondary"></i></span>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Seu usuário" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label fw-bold text-secondary small">SENHA</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-secondary"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Sua senha" required>
                </div>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-login text-uppercase">Entrar</button>
            </div>
        </form>
    </div>
    <div class="card-footer text-center py-3 bg-light">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Viação Mimo</small><br>
         <small class="text-muted">Desenvolvido por: Lucas Bueno</small>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
